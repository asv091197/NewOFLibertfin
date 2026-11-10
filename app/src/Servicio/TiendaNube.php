<?php
namespace LibertyFin\Servicio;

/**
 * Tienda Nube: hablar con su API en nombre de UNA tienda.
 *
 * ──────────────────────────────────────────────────────────────────────
 * UNA APLICACIÓN, MUCHAS TIENDAS (igual que Mercado Libre)
 *
 * LibertyFin registra UNA aplicación en el portal de socios de Tienda
 * Nube (partners.tiendanube.com): app_id + secreto. Esa va en
 * config/integraciones.php, bloque `tiendanube`, y es de toda la
 * instalación.
 *
 * Cada EMPRESA conecta su propia tienda: el administrador entra a Tienda
 * Nube, autoriza a LibertyFin, y Tienda Nube nos da una llave que solo
 * sirve para ESA tienda. La llave se guarda cifrada en la base de la
 * empresa.
 * ──────────────────────────────────────────────────────────────────────
 *
 * DIFERENCIAS CON MERCADO LIBRE QUE IMPORTAN
 *
 *  · La llave NO caduca. No hay refresh_token ni candado para renovarla:
 *    vale hasta que la tienda desinstala la aplicación o se pide otra.
 *  · La dirección de retorno NO se manda al autorizar: es la que se dio
 *    de alta en el portal de socios. Por eso no hay `retorno` que llenar.
 *  · Los avisos (webhooks) se dan de alta POR TIENDA, desde la API, al
 *    conectar. Y sí vienen firmados: HMAC-SHA256 del cuerpo con el
 *    secreto de la aplicación (ver firmaValida()).
 *  · Todo producto tiene al menos una variante: la existencia vive
 *    siempre en la variante, nunca en el producto.
 *  · Los textos (nombre, descripción) vienen por idioma: {"es": "..."}.
 *
 * Esta clase no sabe de tablas: quien la usa (Servicio\TnInventario)
 * decide dónde se guarda la cuenta.
 */
final class TiendaNube
{
    const AUTORIZAR = 'https://www.tiendanube.com/apps/%s/authorize';
    const TOKEN     = 'https://www.tiendanube.com/apps/authorize/token';
    const API       = 'https://api.tiendanube.com/2025-03/';

    /** Los avisos que se dan de alta en cada tienda al conectar. */
    const EVENTOS = ['order/paid', 'order/cancelled', 'order/updated', 'product/updated', 'product/deleted', 'app/uninstalled'];

    private static $config = null;

    private $ultimoError = '';
    private $ultimoHttp = 0;
    private $espia = null;

    // ════════════════════════════════════════════════════════
    //  LA CONFIGURACIÓN DE LA APLICACIÓN
    // ════════════════════════════════════════════════════════

    /** El bloque `tiendanube` de config/integraciones.php (ver MercadoLibre::config, por qué directo). */
    public static function config()
    {
        if (self::$config !== null) return self::$config;
        $ruta = dirname(__DIR__, 2) . '/config/integraciones.php';
        $todo = is_readable($ruta) ? (array)(require $ruta) : [];
        return self::$config = (array)($todo['tiendanube'] ?? []);
    }

    public static function usarConfig(array $c) { self::$config = $c; }

    public static function activa() { return self::faltan() === []; }

    public static function faltan()
    {
        $c = self::config();
        $f = [];
        if (empty($c['activo']))                        $f[] = "'activo' => true";
        if (trim((string)($c['app_id'] ?? '')) === '')   $f[] = 'app_id';
        if (trim((string)($c['secreto'] ?? '')) === '')  $f[] = 'secreto';
        if (trim((string)($c['contacto'] ?? '')) === '') $f[] = 'contacto';
        return $f;
    }

    public static function appId() { return trim((string)(self::config()['app_id'] ?? '')); }
    private static function secreto() { return trim((string)(self::config()['secreto'] ?? '')); }

    /**
     * Tienda Nube rechaza (400) toda llamada sin User-Agent, y pide que
     * lleve el nombre de la aplicación y un correo o página de contacto.
     */
    private static function agente()
    {
        return 'LibertyFin (' . trim((string)(self::config()['contacto'] ?? '')) . ')';
    }

    public function error() { return $this->ultimoError; }
    public function http()  { return $this->ultimoHttp; }
    public function espiar(callable $f) { $this->espia = $f; return $this; }

    // ════════════════════════════════════════════════════════
    //  CONECTAR UNA TIENDA (OAuth)
    // ════════════════════════════════════════════════════════

    /**
     * La dirección a la que se manda al administrador para que autorice.
     * `state` evita que alguien nos haga conectar SU tienda a NUESTRA
     * empresa con un enlace preparado: se guarda en la sesión y al volver
     * tiene que coincidir.
     * @return array ['url' => ..., 'state' => ...]
     */
    public static function urlAutorizar()
    {
        $state = bin2hex(random_bytes(20));
        return [
            'url'   => sprintf(self::AUTORIZAR, rawurlencode(self::appId())) . '?state=' . $state,
            'state' => $state,
        ];
    }

    /**
     * Cambia el `code` (dura 5 minutos) por la llave de la tienda.
     * @return array|null ['store_id','access_token','scope'] o null
     */
    public function canjearCodigo($codigo)
    {
        $r = $this->llamarUrl('POST', self::TOKEN, null, [
            'client_id'     => self::appId(),
            'client_secret' => self::secreto(),
            'grant_type'    => 'authorization_code',
            'code'          => (string)$codigo,
        ]);
        if (!is_array($r) || empty($r['access_token']) || empty($r['user_id'])) {
            if ($this->ultimoError === '') {
                $this->ultimoError = is_array($r) && !empty($r['error_description'])
                    ? (string)$r['error_description'] : 'Tienda Nube no devolvió la llave.';
            }
            return null;
        }
        return [
            'store_id'     => (string)$r['user_id'],   // en Tienda Nube el «user_id» ES la tienda
            'access_token' => (string)$r['access_token'],
            'scope'        => (string)($r['scope'] ?? ''),
        ];
    }

    /** ¿El último fallo fue porque la llave ya no vale? Entonces hay que reconectar. */
    public function fueAutorizacion()
    {
        return $this->ultimoHttp === 401;
    }

    // ════════════════════════════════════════════════════════
    //  LOS AVISOS
    // ════════════════════════════════════════════════════════

    /**
     * ¿El aviso viene de Tienda Nube? Firma = HMAC-SHA256 del cuerpo
     * crudo con el secreto de la aplicación, en hexadecimal. Se acepta
     * también en base64 por si cambian la codificación.
     */
    public static function firmaValida($cuerpo, $firma)
    {
        $firma = trim((string)$firma);
        if ($firma === '' || self::secreto() === '') return false;
        $crudo = hash_hmac('sha256', (string)$cuerpo, self::secreto(), true);
        return hash_equals(bin2hex($crudo), strtolower($firma)) || hash_equals(base64_encode($crudo), $firma);
    }

    public function avisos($token, $tienda)
    {
        return $this->llamar('GET', $tienda, 'webhooks', $token);
    }

    public function crearAviso($token, $tienda, $evento, $url)
    {
        return $this->llamar('POST', $tienda, 'webhooks', $token, ['event' => (string)$evento, 'url' => (string)$url]);
    }

    public function borrarAviso($token, $tienda, $id)
    {
        return $this->llamar('DELETE', $tienda, 'webhooks/' . (int)$id, $token) !== null;
    }

    /**
     * Deja dados de alta los avisos de EVENTOS apuntando a $url: borra los
     * nuestros que apunten a otra dirección y crea los que falten.
     * @return array ['creados' => n, 'errores' => [texto]]
     */
    public function asegurarAvisos($token, $tienda, $url)
    {
        $r = ['creados' => 0, 'errores' => []];
        $hay = $this->avisos($token, $tienda);
        if (!is_array($hay)) { $r['errores'][] = $this->ultimoError; return $r; }
        $tengo = [];
        foreach ($hay as $w) {
            $ev = (string)($w['event'] ?? '');
            if (!in_array($ev, self::EVENTOS, true)) continue;
            if ((string)($w['url'] ?? '') === $url) { $tengo[$ev] = true; continue; }
            $this->borrarAviso($token, $tienda, $w['id'] ?? 0);   // de otra dirección (dominio viejo)
        }
        foreach (self::EVENTOS as $ev) {
            if (isset($tengo[$ev])) continue;
            if ($this->crearAviso($token, $tienda, $ev, $url) !== null) $r['creados']++;
            else $r['errores'][] = $ev . ': ' . $this->ultimoError;
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════
    //  LO QUE SE USA
    // ════════════════════════════════════════════════════════

    /** La tienda: name, original_domain, main_language, country, main_currency… */
    public function tienda($token, $tienda)
    {
        return $this->llamar('GET', $tienda, 'store', $token);
    }

    /**
     * Todos los productos, en páginas de 200.
     * @return array|null la lista, o null si no se pudo leer ni la primera página
     */
    public function productos($token, $tienda, $tope = 10000)
    {
        $todos = [];
        for ($pag = 1; $pag <= 100 && count($todos) < $tope; $pag++) {
            $r = $this->llamar('GET', $tienda, 'products?per_page=200&page=' . $pag, $token);
            if ($r === null) {
                // Pedir una página después de la última contesta 404: es el final, no un error.
                if ($this->ultimoHttp === 404 && $pag > 1) { $this->ultimoError = ''; break; }
                return $pag === 1 ? null : $todos;
            }
            foreach ((array)$r as $p) $todos[] = $p;
            if (count((array)$r) < 200) break;
        }
        return $todos;
    }

    public function producto($token, $tienda, $productoId)
    {
        return $this->llamar('GET', $tienda, 'products/' . (int)$productoId, $token);
    }

    /**
     * Cambia la existencia de UNA variante. `replace` pone el número tal
     * cual; Tienda Nube no borra variantes con esta llamada (a diferencia
     * de PUT /variants, que trata la lista como completa).
     */
    public function ponerExistencia($token, $tienda, $productoId, $varianteId, $cantidad)
    {
        return $this->llamar('POST', $tienda, 'products/' . (int)$productoId . '/variants/stock', $token, [
            'action' => 'replace',
            'value'  => max(0, (int)$cantidad),
            'id'     => (int)$varianteId,
        ]) !== null;
    }

    public function orden($token, $tienda, $ordenId)
    {
        return $this->llamar('GET', $tienda, 'orders/' . (int)$ordenId, $token);
    }

    /** Las órdenes que cambiaron desde una fecha: la red de seguridad de los avisos. */
    public function ordenesDesde($token, $tienda, $desde, $tope = 600)
    {
        $todas = [];
        $iso = date('c', strtotime((string)$desde));
        for ($pag = 1; count($todas) < $tope; $pag++) {
            $r = $this->llamar('GET', $tienda, 'orders?per_page=200&page=' . $pag
                               . '&updated_at_min=' . rawurlencode($iso), $token);
            if ($r === null) {
                if ($this->ultimoHttp === 404) { $this->ultimoError = ''; }
                break;
            }
            foreach ((array)$r as $o) $todas[] = $o;
            if (count((array)$r) < 200) break;
        }
        return $todas;
    }

    // ── Publicar ────────────────────────────────────────────────

    public function crearProducto($token, $tienda, array $cuerpo)
    {
        return $this->llamar('POST', $tienda, 'products', $token, $cuerpo);
    }

    /**
     * Sube una foto desde un archivo del servidor, en base64. Se manda el
     * archivo y no una dirección: así sirve aunque las imágenes de
     * LibertyFin pidan sesión o el servidor sea local.
     */
    public function subirFoto($token, $tienda, $productoId, $rutaArchivo, $mime, $posicion)
    {
        if (!is_file($rutaArchivo) || filesize($rutaArchivo) > 10485760) {
            $this->ultimoError = 'No se encontró la imagen, o pesa más de 10 MB.';
            return null;
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        return $this->llamar('POST', $tienda, 'products/' . (int)$productoId . '/images', $token, [
            'filename'   => 'foto-' . (int)$posicion . '.' . ($ext[$mime] ?? 'jpg'),
            'position'   => (int)$posicion,
            'attachment' => base64_encode((string)file_get_contents($rutaArchivo)),
        ]);
    }

    // ════════════════════════════════════════════════════════
    //  CIFRAR LA LLAVE (igual que MercadoLibre::cifrar, con su propia llave)
    // ════════════════════════════════════════════════════════

    public static function cifrar($texto)
    {
        $texto = (string)$texto;
        if ($texto === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($texto, 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new \RuntimeException('No se pudo cifrar la llave de Tienda Nube.');
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function descifrar($guardado)
    {
        $guardado = (string)$guardado;
        if (strncmp($guardado, 'v1:', 3) !== 0) return '';
        $b = base64_decode(substr($guardado, 3), true);
        if ($b === false || strlen($b) < 29) return '';
        $t = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA,
                             substr($b, 0, 12), substr($b, 12, 16));
        return $t === false ? '' : $t;
    }

    private static function llave()
    {
        $c = self::config();
        $base = trim((string)($c['llave_cifrado'] ?? '')) ?: self::secreto();
        if ($base === '') throw new \RuntimeException('Falta la configuración de Tienda Nube.');
        return hash('sha256', 'libertyfin|tiendanube|' . $base, true);
    }

    // ════════════════════════════════════════════════════════

    /** Un texto por idioma ({"es": "..."}) en el idioma pedido, o el primero que haya. */
    public static function texto($v, $idioma = 'es')
    {
        if (!is_array($v)) return (string)$v;
        if (isset($v[$idioma]) && $v[$idioma] !== '') return (string)$v[$idioma];
        foreach ($v as $t) if (is_string($t) && $t !== '') return $t;
        return '';
    }

    private function llamar($verbo, $tienda, $ruta, $token, $cuerpo = null)
    {
        return $this->llamarUrl($verbo, self::API . rawurlencode((string)$tienda) . '/' . $ruta, $token, $cuerpo);
    }

    /**
     * Una llamada. Devuelve el JSON como arreglo, o null y el motivo en error().
     * El 429 (demasiadas peticiones: 2 por segundo por tienda) se reintenta.
     */
    private function llamarUrl($verbo, $url, $token, $cuerpo = null)
    {
        $this->ultimoError = '';
        $this->ultimoHttp  = 0;

        for ($intento = 1; $intento <= 3; $intento++) {
            $cab = ['Accept: application/json', 'User-Agent: ' . self::agente()];
            if ($token !== null && $token !== '') {
                // La documentación nueva dice «Authorization: Bearer» y la de
                // siempre «Authentication: bearer». Se mandan las dos.
                $cab[] = 'Authorization: Bearer ' . $token;
                $cab[] = 'Authentication: bearer ' . $token;
            }
            $op = [
                CURLOPT_CUSTOMREQUEST  => $verbo,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)(self::config()['timeout'] ?? 25),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($cuerpo !== null) {
                $cab[] = 'Content-Type: application/json; charset=utf-8';
                $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $op[CURLOPT_HTTPHEADER] = $cab;

            // Nunca al espía: ni la llave, ni el secreto, ni las fotos en base64.
            $ver = $cuerpo !== null && !isset($cuerpo['client_secret']) && !isset($cuerpo['attachment'])
                 ? ' ' . $op[CURLOPT_POSTFIELDS] : '';
            $this->ver('>>>', $verbo . ' ' . preg_replace('~^https://[^/]+~', '', $url) . $ver);

            $ch = curl_init($url);
            curl_setopt_array($ch, $op);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->ultimoHttp = $http;

            if ($res === false) {
                $this->ultimoError = 'No se pudo conectar con Tienda Nube: ' . ($err ?: 'sin detalle');
                return null;
            }
            $this->ver('<<<', 'HTTP ' . $http . ' ' . mb_substr((string)$res, 0, 800));

            if ($http === 429 && $intento < 3) { usleep(1100000 * $intento); continue; }

            $j = json_decode((string)$res, true);
            if ($http >= 200 && $http < 300) return is_array($j) ? $j : [];
            $this->ultimoError = self::mensajeDeError(is_array($j) ? $j : [], $http, (string)$res);
            return null;
        }
        return null;
    }

    private static function mensajeDeError(array $j, $http, $crudo)
    {
        if ($http === 401) return 'Tienda Nube no aceptó la llave de la tienda (401): hay que volver a conectar.';
        if ($http === 402) return 'Tienda Nube suspendió el acceso a la API de esta tienda por un pago pendiente (402).';
        if ($http === 403) return 'Tienda Nube no deja hacer eso (403): revisa los permisos de la aplicación en el portal de socios.';
        $partes = [];
        foreach (['message', 'description', 'error_description', 'error'] as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) $partes[] = $j[$k];
        }
        // El 422 trae {campo: [mensajes]}.
        foreach ($j as $campo => $msgs) {
            if (in_array($campo, ['code', 'message', 'description', 'error', 'error_description'], true)) continue;
            if (is_array($msgs)) {
                $txt = implode(', ', array_filter(array_map(function ($m) { return is_string($m) ? $m : ''; }, $msgs)));
                if ($txt !== '') $partes[] = $campo . ': ' . $txt;
            }
        }
        if (!$partes && trim($crudo) !== '') $partes[] = mb_substr(trim(strip_tags($crudo)), 0, 200);
        return ($partes ? implode(' · ', array_unique($partes)) : 'Tienda Nube rechazó la petición') . ' (HTTP ' . $http . ')';
    }

    private function ver($quien, $que)
    {
        if ($this->espia) call_user_func($this->espia, $quien, (string)$que);
    }
}
