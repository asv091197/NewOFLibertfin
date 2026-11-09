<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\EmidaRepo;

/**
 * Recargas telefónicas · Emida.
 *
 * Es SOAP sobre un WSDL, no REST. Los datos y los códigos de respuesta
 * salen del archivo de configuración del sistema anterior, que sí estaba
 * probado contra la cuenta real.
 *
 * DOS COSAS QUE NO SE COPIAN DEL SISTEMA ANTERIOR:
 *
 *  · El WSDL apunta a `http://104.248.179.142` SIN cifrar. Por ahí viajan
 *    el usuario, la clave y el número del cliente en claro. Aquí se avisa
 *    en pantalla y se deja pasar solo porque es el único endpoint que hay;
 *    en cuanto Emida dé uno con HTTPS, se cambia en la configuración.
 *
 *  · `verify_peer => false`. Eso convierte HTTPS en decoración: cualquiera
 *    en medio puede presentar su propio certificado. Aquí se verifica, y
 *    si el certificado falla la recarga no sale.
 *
 * SOBRE LA IMAGEN DEL PRODUCTO
 *
 * Emida manda la URL de la imagen dentro de <Reference1><URLImage>, no
 * como un campo del producto. Puede venir como "NA" cuando no hay. Aquí
 * se extrae y se normaliza (Dropbox con ?dl=0 no sirve dentro de un
 * <img>; se convierte a ?raw=1 en EmidaRepo::normalizarImagen).
 */
final class Emida
{
    const OPERACIONES = [
        'saldo'     => ['GetMerchantBalance', 'GetTerminalBalance', 'GetAccountBalance'],
        'validar'   => ['CheckTrxById', 'LookUpTransactionByInvocieNo', 'CheckTBID'],
        'vender'    => ['PinDistSale', 'PinDistSale_01', 'CardSale'],
        'productos' => ['GetProductList', 'GetProductListExt', 'GetProductListDetailed'],
        'carriers'  => ['GetCarrierList'],
        'prueba'    => ['CommTest'],
    ];

    const ERRORES = [
        '16'   => 'El número no existe o no admite recargas',
        '51'   => 'Monto no válido para esa compañía',
        '12'   => 'Terminal o comercio no válidos',
        '294'  => 'Ya se hizo esa misma recarga hace menos de 5 minutos',
        '504'  => 'El proveedor no respondió a tiempo',
        '2518' => 'Error de comunicación con la compañía telefónica',
    ];

    private $cfg;

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    public function porProxy()
    {
        return trim((string)($this->cfg['proxy'] ?? '')) !== '';
    }

    /**
     * Llama a un script del intermediario.
     *
     * CADA SCRIPT TIENE SU PROPIO CONTRATO
     *
     *   get_products.php   acepta GET con ?terminal=...&clerk=...
     *   pinDistSale.php    exige POST con JSON en el body
     *
     * Y EL "success:false" DEL INTERMEDIARIO TIENE DOS SIGNIFICADOS
     *
     * El procesador del intermediario envuelve TODO con success. Un
     * success:false puede ser:
     *
     *   A · Pre-flight: parámetros mal, método mal, timeout del propio
     *       script. La petición NO llegó a Emida. No hay nada incierto.
     *
     *   B · Respuesta de Emida: el proveedor contestó con un código de
     *       rechazo (51, 16, etc.) y el processor lo puso como
     *       success:false con los códigos adentro. La transacción SÍ
     *       llegó y PUDO haber salido.
     *
     * Se distinguen por la presencia de responseCode/H2HResultCode.
     * Antes TODO se trataba como caso A, así que un rechazo de Emida
     * quedaba como "no salió" y no había forma de saber si el saldo
     * del cliente se había movido.
     */
    private function proxy($script, array $params, $forzarMetodo = null)
    {
        $base   = rtrim((string)($this->cfg['proxy'] ?? ''), '/');
        $url    = $base . '/' . ltrim($script, '/');
        $metodo = strtoupper($forzarMetodo ?: 'POST');
        $seg    = max(5, (int)($this->cfg['timeout'] ?? 30));

        error_log('[LibertyFin] proxy ' . $script . ' → '
            . json_encode($params, JSON_UNESCAPED_UNICODE));

        $intenta = function ($metodo) use ($url, $params, $seg) {
            $ch = curl_init();
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $seg,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'LibertyFin/1.0',
            ];
            if ($metodo === 'POST') {
                $opts[CURLOPT_URL]        = $url;
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE);
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            } else {
                $opts[CURLOPT_URL] = $params ? ($url . '?' . http_build_query($params)) : $url;
            }
            curl_setopt_array($ch, $opts);
            $cuerpo = curl_exec($ch);
            $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            curl_close($ch);
            return [$cuerpo, $codigo, $error];
        };

        list($cuerpo, $codigo, $error) = $intenta($metodo);

        error_log('[LibertyFin] proxy ' . $script . ' ← '
            . mb_substr(is_string($cuerpo) ? $cuerpo : json_encode($cuerpo), 0, 800));

        if (is_string($cuerpo) && $cuerpo !== '') {
            $j = json_decode($cuerpo, true);
            if (is_array($j) && !empty($j['message'])
                && stripos($j['message'], 'método') !== false
                && stripos($j['message'], 'permitido') !== false) {
                $otro = $metodo === 'POST' ? 'GET' : 'POST';
                list($cuerpo, $codigo, $error) = $intenta($otro);
            }
        }

        if ($cuerpo === false) {
            return ['ok' => false, 'error' => 'No se pudo llamar al intermediario: ' . $error];
        }
        if ($codigo >= 400) {
            return ['ok' => false, 'error' => 'El intermediario respondió ' . $codigo];
        }

        $j = json_decode($cuerpo, true);

        if ($j === null) {
            return ['ok' => true, 'datos' => $cuerpo, 'crudo' => $cuerpo];
        }

        if (is_array($j) && isset($j['success']) && $j['success'] === false) {
            $tieneCodigos = false;
            foreach (['responseCode','ResponseCode','h2hResultCode','H2HResultCode'] as $c) {
                if (!empty($j[$c])) { $tieneCodigos = true; break; }
            }
            if (!$tieneCodigos && isset($j['data']) && is_array($j['data'])) {
                foreach (['responseCode','ResponseCode','h2hResultCode','H2HResultCode'] as $c) {
                    if (!empty($j['data'][$c])) { $tieneCodigos = true; break; }
                }
            }

            if ($tieneCodigos) {
                return ['ok' => true, 'datos' => $j, 'crudo' => $cuerpo];
            }

            $msj = null;
            foreach (['message','mensaje','error','errorMessage','msg'] as $k) {
                if (!empty($j[$k]) && is_string($j[$k])) { $msj = $j[$k]; break; }
            }
            if ($msj === null) {
                $msj = 'respuesta del intermediario sin campo de mensaje: ' . $cuerpo;
            }
            return [
                'ok'    => false,
                'error' => 'El intermediario rechazó la petición: ' . $msj,
                'datos' => $j,
                'crudo' => $cuerpo,
            ];
        }

        return ['ok' => true, 'datos' => $j, 'crudo' => $cuerpo];
    }

    public function cifrado()
    {
        return strncasecmp((string)($this->cfg['wsdl'] ?? ''), 'https://', 8) === 0;
    }

    private function cliente()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') {
            throw new \RuntimeException('Falta la dirección del WSDL en config/integraciones.php');
        }
        if (!class_exists('SoapClient')) {
            throw new \RuntimeException(
                'Este servidor no tiene la extensión SOAP de PHP. Actívala para usar recargas.');
        }
        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(
                'Este servidor tiene allow_url_fopen apagado y SOAP lo necesita para leer el WSDL.');
        }

        $seg  = max(5, (int)($this->cfg['timeout'] ?? 30));
        $usr  = (string)($this->cfg['usuario'] ?? '');
        $pwd  = (string)($this->cfg['clave'] ?? '');

        $http = ['timeout' => $seg, 'user_agent' => 'LibertyFin/1.0'];
        if ($usr !== '') {
            $http['header'] = "Authorization: Basic " . base64_encode($usr . ':' . $pwd);
        }

        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                      'allow_self_signed' => false],
            'http' => $http,
        ]);

        $opciones = [
            'trace'              => true,
            'exceptions'         => true,
            'cache_wsdl'         => !empty($this->cfg['sandbox']) ? WSDL_CACHE_NONE : WSDL_CACHE_DISK,
            'connection_timeout' => $seg,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'encoding'           => 'UTF-8',
            'stream_context'     => $ctx,
        ];
        if ($usr !== '') { $opciones['login'] = $usr; $opciones['password'] = $pwd; }

        try {
            return new \SoapClient($wsdl, $opciones);
        } catch (\SoapFault $e) {
            throw new \RuntimeException(self::explicar($wsdl, $e->getMessage()));
        }
    }

    private static function explicar($wsdl, $mensaje)
    {
        $esHttps = strncasecmp($wsdl, 'https://', 8) === 0;
        $esIp    = (bool)preg_match('~^https?://\d{1,3}(\.\d{1,3}){3}~', $wsdl);

        if (stripos($mensaje, 'external entity') !== false
            || stripos($mensaje, "Couldn't load from") !== false) {
            if ($esHttps && $esIp) {
                return 'No se pudo leer el WSDL en ' . $wsdl . '. Está apuntando a una '
                     . 'dirección IP por HTTPS, y eso casi nunca funciona: los certificados '
                     . 'se emiten para nombres de dominio, no para IPs. Cámbialo a http:// '
                     . 'y pídele a Emida un dominio con certificado.';
            }
            return 'No se pudo leer el WSDL en ' . $wsdl . '. Puede ser que el servidor de '
                 . 'Emida no responda desde aquí, que las credenciales no sirvan para '
                 . 'descargarlo, o que el hosting bloquee la salida a esa dirección. '
                 . 'Comprueba desde el servidor con: curl -u USUARIO:CLAVE ' . $wsdl;
        }
        return 'No se pudo conectar con Emida: ' . $mensaje;
    }

    public function probar()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        $r = [];

        $agrega = function (&$r, $que, $ok, $detalle, $bloquea = true) {
            $r[] = ['que' => $que, 'ok' => $ok, 'detalle' => $detalle, 'bloquea' => $bloquea];
        };

        $agrega($r, 'Extensión SOAP de PHP', class_exists('SoapClient'),
            class_exists('SoapClient') ? 'disponible' : 'falta', false);
        $agrega($r, 'allow_url_fopen', (bool)ini_get('allow_url_fopen'),
            ini_get('allow_url_fopen') ? 'encendido' : 'apagado: SOAP no puede leer el WSDL');
        $agrega($r, 'Dirección del WSDL', $wsdl !== '', $wsdl ?: 'sin configurar');
        $agrega($r, 'Credenciales', trim((string)($this->cfg['usuario'] ?? '')) !== ''
                                 && trim((string)($this->cfg['merchant_id'] ?? '')) !== '',
            'usuario y comercio');
        $agrega($r, 'Va cifrada', $this->cifrado(),
            $this->cifrado() ? 'HTTPS' : 'HTTP: las credenciales viajan en claro', false);
        $agrega($r, 'Cómo habla con Emida', true,
            $this->porProxy()
                ? 'por intermediario: ' . $this->cfg['proxy']
                : 'directo al endpoint del WSDL', false);

        foreach ($r as $x) if ($x['bloquea'] && !$x['ok']) return $r;

        if ($this->porProxy()) {
            $p = $this->proxy($this->cfg['proxy_saldo'] ?? 'get_balance.php', [
                'username' => $this->cfg['usuario'] ?? '',
                'password' => $this->cfg['clave'] ?? '',
            ]);
            $agrega($r, 'El intermediario responde', $p['ok'],
                $p['ok'] ? mb_substr(is_string($p['crudo']) ? $p['crudo'] : '', 0, 160)
                         : $p['error']);
        }

        $d = $this->bajarWsdl();
        $agrega($r, 'Descarga del WSDL', $d['ok'],
            $d['ok'] ? ($d['bytes'] . ' bytes de XML') : $d['error']);
        if (!$d['ok']) return $r;

        $ep = $this->endpoint();
        $mismoHost = $ep && parse_url($ep, PHP_URL_HOST) === parse_url($wsdl, PHP_URL_HOST);
        $agrega($r, 'A dónde manda las llamadas', (bool)$ep,
            $ep ? ($ep . ($mismoHost ? '' : '  ← host distinto al del WSDL'))
                : 'el WSDL no declara <soap:address>');

        if ($ep && !$this->porProxy()) {
            $abre = @fsockopen(
                (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 'ssl://' : '') . parse_url($ep, PHP_URL_HOST),
                parse_url($ep, PHP_URL_PORT) ?: (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 443 : 80),
                $errno, $errstr, 6);
            if ($abre) { fclose($abre); $agrega($r, 'El endpoint responde', true, 'contesta'); }
            else {
                $agrega($r, 'El endpoint responde', false,
                    ($errstr ?: 'no contesta') . '. Emida solo acepta conexiones desde '
                    . 'direcciones autorizadas.');
            }
        }

        $ops = $this->operacionesDelXml();
        if ($ops['ok']) {
            $n = $ops['operaciones'];
            $agrega($r, 'Operaciones que ofrece', count($n) > 0, count($n) . ' en total');
            foreach (['saldo' => 'saldo', 'validar' => 'validar número',
                      'vender' => 'recargar', 'productos' => 'catálogo'] as $k => $rotulo) {
                $op = $this->operacionPara($k);
                $agrega($r, 'Operación de ' . $rotulo, (bool)$op,
                    $op ?: 'ninguna de ' . implode(', ', self::OPERACIONES[$k] ?? []) . ' está',
                    false);
            }
        } else {
            $agrega($r, 'Operaciones que ofrece', false, $ops['error']);
        }
        return $r;
    }

    private function base()
    {
        return [
            'UserId'         => $this->cfg['usuario'] ?? '',
            'Password'       => $this->cfg['clave'] ?? '',
            'MerchantId'     => $this->cfg['merchant_id'] ?? '',
            'ClerkPassword'  => $this->cfg['clerk_password'] ?? ($this->cfg['clave'] ?? ''),
        ];
    }

    public function bajarWsdl()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') return ['ok' => false, 'error' => 'Sin dirección de WSDL'];

        $http = ['timeout' => max(5, (int)($this->cfg['timeout'] ?? 30)),
                 'user_agent' => 'LibertyFin/1.0', 'ignore_errors' => true];
        $usr = (string)($this->cfg['usuario'] ?? '');
        if ($usr !== '') {
            $http['header'] = 'Authorization: Basic '
                . base64_encode($usr . ':' . (string)($this->cfg['clave'] ?? ''));
        }
        $ctx = stream_context_create(['http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $xml = @file_get_contents($wsdl, false, $ctx);
        if ($xml === false) {
            $e = error_get_last();
            return ['ok' => false, 'error' => 'No se pudo descargar: '
                . ($e['message'] ?? 'sin detalle')];
        }
        if (stripos($xml, '<definitions') === false && stripos($xml, ':definitions') === false) {
            return ['ok' => false,
                    'error' => 'Lo que devolvió no es un WSDL. Primeros caracteres: '
                             . mb_substr(strip_tags($xml), 0, 120)];
        }
        return ['ok' => true, 'xml' => $xml, 'bytes' => strlen($xml)];
    }

    public function endpoint()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return null;
        if (preg_match('/<(?:\w+:)?address\s+location=["\']([^"\']+)["\']/i', $d['xml'], $m)) {
            return $m[1];
        }
        return null;
    }

    public function operacionesDelXml()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return ['ok' => false, 'error' => $d['error']];
        preg_match_all('/<(?:\w+:)?operation\s+name=["\']([^"\']+)["\']/i', $d['xml'], $m);
        $nombres = array_values(array_unique($m[1] ?? []));
        return ['ok' => true, 'operaciones' => $nombres];
    }

    public function operaciones()
    {
        try {
            $fns = $this->cliente()->__getFunctions();
            $r = [];
            foreach ((array)$fns as $f) {
                if (preg_match('/\s(\w+)\(/', (string)$f, $m)) $r[$m[1]] = (string)$f;
            }
            return ['ok' => true, 'operaciones' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function tipos()
    {
        try { return ['ok' => true, 'tipos' => (array)$this->cliente()->__getTypes()]; }
        catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    public function operacionPara($proposito)
    {
        $propia = trim((string)($this->cfg['op_' . $proposito] ?? ''));
        $ops = $this->operacionesDelXml();
        $hay = $ops['ok'] ? $ops['operaciones'] : [];

        if ($propia !== '') {
            return in_array($propia, $hay, true) || !$hay ? $propia : null;
        }
        foreach (self::OPERACIONES[$proposito] ?? [] as $n) {
            if (!$hay || in_array($n, $hay, true)) return $n;
        }
        return null;
    }

    private function nombresDisponibles()
    {
        $o = $this->operacionesDelXml();
        return $o['ok'] ? implode(', ', $o['operaciones']) : ('no se pudieron leer: ' . $o['error']);
    }

    public function catalogo()
    {
        if ($this->porProxy()) {
            $script = $this->cfg['proxy_catalogo'] ?? 'get_products.php';
            $r = $this->proxy($script, [
                'terminal' => $this->cfg['terminal'] ?? '',
                'clerk'    => $this->cfg['clerk']    ?? '',
            ], 'GET');

            if (!$r['ok']) {
                if (strpos($r['error'], '404') !== false) {
                    return ['ok' => false, 'sin_script' => true, 'error' =>
                        'El intermediario no tiene el script del catálogo (' . $script . '). '
                      . 'Mientras lo suben, puedes pegar el catálogo a mano.'];
                }
                return $r;
            }

            $datos = is_array($r['datos']) ? $r['datos'] : self::deXml($r['crudo']);
            return ['ok' => true, 'via' => 'intermediario',
                    'productos' => self::normalizar($datos)];
        }
        try {
            $op = $this->operacionPara('productos');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de catálogo. Ofrece: '
                    . $this->nombresDisponibles()];
            }
            $r = $this->cliente()->__soapCall($op, [$this->base()]);
            return ['ok' => true, 'operacion' => $op, 'productos' => self::normalizar($r)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function desenvolver($cuerpo)
    {
        if (!is_string($cuerpo) || trim($cuerpo) === '') return '';

        $ini = ltrim($cuerpo);
        if (strncmp($ini, '<?xml', 5) === 0
            || stripos($ini, '<soapenv:Envelope') === 0
            || stripos($ini, '<Envelope') === 0) {
            return $cuerpo;
        }

        $p = stripos($cuerpo, '<pre');
        if ($p === false) {
            if (strpos($cuerpo, '&lt;') !== false) {
                return html_entity_decode($cuerpo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $cuerpo;
        }

        $ini = strpos($cuerpo, '>', $p);
        if ($ini === false) return $cuerpo;
        $ini++;

        $fin = stripos($cuerpo, '</pre>', $ini);
        $trozo = $fin === false
            ? substr($cuerpo, $ini)
            : substr($cuerpo, $ini, $fin - $ini);

        return html_entity_decode($trozo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function deXml($xml)
    {
        $xml = self::desenvolver($xml);
        if ($xml === '') return [];

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($sx === false) return [];

        $ret = $sx->xpath('//*[local-name()="return"]');
        if ($ret && isset($ret[0])) {
            $prev = libxml_use_internal_errors(true);
            $interno = simplexml_load_string((string)$ret[0]);
            libxml_use_internal_errors($prev);

            if ($interno !== false) {
                $rc = $interno->xpath('//*[local-name()="ResponseCode"]');
                $cod = $rc ? trim((string)$rc[0]) : '';
                if ($cod !== '' && $cod !== '00') {
                    $rm = $interno->xpath('//*[local-name()="ResponseMessage"]');
                    error_log('[LibertyFin] catálogo Emida: ResponseCode ' . $cod
                        . ' — ' . ($rm ? mb_substr((string)$rm[0], 0, 200) : 'sin mensaje'));
                    return [];
                }

                $prod = $interno->xpath('//*[local-name()="Products"]/*[local-name()="Product"]');
                if (!$prod) $prod = $interno->xpath('//*[local-name()="Product"]');
                if ($prod && count($prod) > 0) {
                    return json_decode(json_encode($prod), true);
                }
            }
        }

        $cuerpo = $sx;
        $env = $sx->children('http://schemas.xmlsoap.org/soap/envelope/');
        if (isset($env->Body)) $cuerpo = $env->Body;
        return self::aLista(json_decode(json_encode($cuerpo), true));
    }

    private static function desdeProxy($respuesta)
    {
        $xml = self::desenvolver($respuesta);
        if ($xml === '') return [];

        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if ($sx === false) return [];

        $ret = $sx->xpath('//*[local-name()="return"]');
        if ($ret && isset($ret[0])) {
            libxml_use_internal_errors(true);
            $sx2 = simplexml_load_string((string)$ret[0]);
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            if ($sx2 !== false) {
                return json_decode(json_encode($sx2), true) ?: [];
            }
        }
        return json_decode(json_encode($sx), true) ?: [];
    }

    private static function buscar($nodo, array $claves)
    {
        if (!is_array($nodo)) return null;
        foreach ($nodo as $k => $v) {
            if (in_array($k, $claves, true) && !is_array($v)) return $v;
        }
        foreach ($nodo as $v) {
            if (is_array($v)) {
                $r = self::buscar($v, $claves);
                if ($r !== null) return $r;
            }
        }
        return null;
    }

    private static function aLista($nodo, $profundidad = 0)
    {
        if ($profundidad > 6 || !is_array($nodo)) return [];

        $esLista = true;
        foreach ($nodo as $k => $v) {
            if (is_int($k) && is_array($v)) continue;
            $esLista = false;
            break;
        }
        if ($esLista && $nodo) return array_values($nodo);

        foreach ($nodo as $v) {
            if (!is_array($v)) continue;
            $r = self::aLista($v, $profundidad + 1);
            if ($r) return $r;
        }
        return [];
    }

    /**
     * Saca la URL de la imagen del producto.
     *
     * En el XML de Emida la imagen vive dentro de ReferenceParameters →
     * Reference1 → URLImage. También puede aparecer como "NA" cuando no
     * hay. Se busca en varias formas porque el proveedor cambia de
     * mayúsculas según el endpoint (URLImage vs urlImage), y a veces la
     * manda como campo propio del producto.
     */
    private static function imagenDe(array $p)
    {
        if (isset($p['ReferenceParameters']['Reference1']['URLImage'])) {
            return (string)$p['ReferenceParameters']['Reference1']['URLImage'];
        }
        foreach ([
            'URLImage', 'urlImage', 'Urlimage', 'Image', 'image',
            'UrlImagen', 'urlImagen', 'Imagen', 'imagen',
        ] as $k) {
            if (isset($p[$k]) && !is_array($p[$k])) return (string)$p[$k];
        }
        $encontrado = self::buscar($p, ['URLImage', 'urlImage', 'Image', 'image']);
        return $encontrado === null ? '' : (string)$encontrado;
    }

    private static function normalizar($datos)
    {
        $lista = [];
        if (is_object($datos)) $datos = (array)$datos;
        if (is_array($datos)) {
            foreach (['Products','Product','productos','items','data'] as $k) {
                if (isset($datos[$k])) { $datos = $datos[$k]; break; }
            }
            if (is_object($datos)) $datos = (array)$datos;

            foreach ((array)$datos as $p) {
                $p = (array)$p;
                $id = self::campo($p, ['ProductId','productId','id','ProductID']);
                if ($id === null || $id === '') continue;

                $min   = self::campo($p, ['AmountMin','MinAmount','minAmount','monto_min','MinimumAmount']);
                $max   = self::campo($p, ['AmountMax','MaxAmount','maxAmount','monto_max','MaximumAmount']);
                $monto = self::campo($p, ['Amount','amount','monto','Price','FaceValue']);
                $flow  = strtoupper((string)self::campo($p, ['FlowType','flowType']));

                if ($flow === 'B')      $tipo = 'consulta';
                elseif ($flow === 'A')  $tipo = 'directa';
                else $tipo = (self::num($min) > 0 || self::num($max) > 0 || self::num($monto) <= 0)
                             ? 'consulta' : 'directa';

                $imagen = EmidaRepo::normalizarImagen(self::imagenDe($p));

                $lista[] = [
                    'producto_id' => (string)$id,
                    'nombre'      => (string)self::campo($p, ['ProductName','productName','nombre','Description','Name']),
                    'categoria'   => (string)self::campo($p, ['ProductCategory','CategoryName','category','categoria','Category']),
                    'carrier'     => (string)self::campo($p, ['CarrierName','carrier','proveedor','Carrier']),
                    'comision'    => self::num(self::campo($p, ['ProductUFee','Fee','fee','comision','UserFee'])),
                    'monto'       => self::num($monto),
                    'monto_min'   => self::num($min),
                    'monto_max'   => self::num($max),
                    'tipo'        => $tipo,
                    'imagen'      => $imagen,
                ];
            }
        }
        return $lista;
    }

    private static function campo(array $p, array $nombres)
    {
        foreach ($nombres as $n) {
            if (array_key_exists($n, $p) && $p[$n] !== null && $p[$n] !== '') return $p[$n];
        }
        return null;
    }

    private static function num($v)
    {
        if ($v === null || $v === '') return 0.0;
        return (float)preg_replace('/[^0-9.\-]/', '', (string)$v);
    }

    public function saldo()
    {
        if ($this->porProxy()) {
            $r = $this->proxy($this->cfg['proxy_saldo'] ?? 'get_balance.php', [
                'username' => $this->cfg['usuario'] ?? '',
                'password' => $this->cfg['clave'] ?? '',
            ]);
            if (!$r['ok']) return $r;
            $d = is_array($r['datos'])
                ? $r['datos']
                : self::desdeProxy(is_string($r['crudo']) ? $r['crudo'] : '');
            return ['ok' => true, 'via' => 'intermediario',
                    'saldo' => self::buscar($d, ['balance','Balance','saldo','Saldo','Amount']),
                    'crudo' => $d];
        }
        try {
            $op = $this->operacionPara('saldo');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de saldo. Las que ofrece son: '
                    . $this->nombresDisponibles()];
            }
            $r = $this->cliente()->__soapCall($op, [$this->base()]);
            return ['ok' => true, 'saldo' => $r->Balance ?? $r->balance ?? $r->Amount ?? null,
                    'operacion' => $op, 'crudo' => $r];
        } catch (\SoapFault $e) {
            return ['ok' => false, 'error' => self::explicar(
                (string)($this->cfg['wsdl'] ?? ''), $e->getMessage())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function validar($numero, $productoId)
    {
        try {
            $op = $this->operacionPara('validar');
            if (!$op) {
                return ['ok' => true, 'sin_validar' => true];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
            ])]);
            return ['ok' => true, 'operacion' => $op, 'crudo' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ejecuta la recarga.
     *
     * PIN_DIST_SALE.PHP ESPERA ESTOS CAMPOS, EN JSON, POR POST
     *
     *   terminalId      → cfg['terminal']
     *   clerkId         → cfg['clerk']
     *   productId       → productoId
     *   accountId       → número del cliente
     *   amount          → monto
     *   invoiceNo       → salesId (nuestro identificador de duplicados;
     *                     el script lo llama "invoiceNo", no "salesId")
     *   tipo_operacion  → "recarga"
     */
    public function recargar($numero, $productoId, $monto, $salesId)
    {
        if (!$this->cifrado()) {
            error_log('[LibertyFin] Emida sobre HTTP sin cifrar: ' . ($this->cfg['wsdl'] ?? ''));
        }

        if ($this->porProxy()) {
            $r = $this->proxy($this->cfg['proxy_venta'] ?? 'pinDistSale.php', [
                'terminalId'     => $this->cfg['terminal'] ?? '',
                'clerkId'        => $this->cfg['clerk']    ?? '',
                'productId'      => $productoId,
                'accountId'      => $numero,
                'amount'         => number_format((float)$monto, 2, '.', ''),
                'invoiceNo'      => $salesId,
                'tipo_operacion' => 'recarga',
            ], 'POST');

            if (!$r['ok']) return $r;

            $d = is_array($r['datos'])
                ? $r['datos']
                : self::desdeProxy(is_string($r['crudo']) ? $r['crudo'] : '');

            $resp  = (string)(self::buscar($d, ['ResponseCode','responseCode']) ?? '');
            $h2h   = (string)(self::buscar($d, ['H2HResultCode','h2hResultCode']) ?? '');
            $folio = self::buscar($d, ['CarrierControlNo','carrierControlNo',
                                       'TransactionId','transactionId']);

            if ($resp === '' && $h2h === '') {
                error_log('[LibertyFin] pinDistSale sin códigos. Crudo: '
                    . mb_substr(is_string($r['crudo']) ? $r['crudo'] : '', 0, 800));
                return ['ok' => false, 'incierta' => true,
                        'error' => 'La respuesta del proveedor no trae los códigos esperados. '
                                 . 'La recarga PUDO haber salido: consulta el saldo antes de reintentar.',
                        'crudo' => $d];
            }
            if (self::exitosa($resp, $h2h)) {
                return ['ok' => true, 'via' => 'intermediario',
                        'duplicada' => $h2h === '294',
                        'folio' => $folio, 'crudo' => $d];
            }
            return ['ok' => false, 'error' => self::mensaje($resp, $h2h),
                    'codigo' => $resp, 'h2h' => $h2h];
        }

        try {
            $op = $this->operacionPara('vender');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de recarga. Las que ofrece son: '
                    . $this->nombresDisponibles()];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
                'Amount'    => number_format((float)$monto, 2, '.', ''),
                'SalesId'   => $salesId,
            ])]);

            $resp = (string)($r->ResponseCode ?? '');
            $h2h  = (string)($r->H2HResultCode ?? '');

            if (self::exitosa($resp, $h2h)) {
                return ['ok' => true, 'duplicada' => $h2h === '294',
                        'folio' => $r->CarrierControlNo ?? $r->TransactionId ?? null,
                        'crudo' => $r];
            }
            return ['ok' => false, 'error' => self::mensaje($resp, $h2h),
                    'codigo' => $resp, 'h2h' => $h2h];

        } catch (\SoapFault $e) {
            $esTimeout = stripos($e->getMessage(), 'timed out') !== false
                      || stripos($e->getMessage(), 'timeout') !== false;
            return ['ok' => false, 'incierta' => $esTimeout,
                    'error' => $esTimeout
                        ? 'El proveedor no respondió a tiempo. La recarga PUDO haber salido: '
                        . 'consulta el saldo antes de reintentar.'
                        : 'El proveedor respondió con un error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function exitosa($responseCode, $h2h)
    {
        if ($responseCode !== '00') return false;
        return $h2h === '' || $h2h === '0' || $h2h === '294';
    }

    public static function mensaje($responseCode, $h2h)
    {
        if (isset(self::ERRORES[$h2h]))          return self::ERRORES[$h2h];
        if (isset(self::ERRORES[$responseCode])) return self::ERRORES[$responseCode];
        return 'El proveedor rechazó la recarga (código ' . $responseCode . '/' . $h2h . ')';
    }
}