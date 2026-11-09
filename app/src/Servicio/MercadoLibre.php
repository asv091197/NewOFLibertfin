<?php
namespace LibertyFin\Servicio;

/**
 * Mercado Libre: hablar con su API en nombre de UNA cuenta de vendedor.
 *
 * ──────────────────────────────────────────────────────────────────────
 * UNA APLICACIÓN, MUCHAS CUENTAS
 *
 * LibertyFin registra UNA aplicación en developers.mercadolibre.com.mx
 * (app_id + secreto + dirección de retorno). Esa va en
 * config/integraciones.php, bloque `mercadolibre`, y es de toda la
 * instalación.
 *
 * Pero quien vende es cada EMPRESA, con su propia cuenta de vendedor. La
 * empresa "conecta" su cuenta: el administrador entra a Mercado Libre,
 * autoriza a LibertyFin, y Mercado Libre nos devuelve un par de llaves
 * (access_token y refresh_token) que solo sirven para ESA cuenta. Esas
 * llaves se guardan en la base de la empresa, cifradas.
 *
 * Así una empresa nunca puede tocar las publicaciones de otra: no tiene
 * sus llaves.
 * ──────────────────────────────────────────────────────────────────────
 *
 * LAS LLAVES CADUCAN, Y EL REFRESH SE GASTA
 *
 * El access_token dura 6 horas. Se renueva con el refresh_token, y aquí
 * está la trampa: cada refresh_token sirve UNA sola vez. Mercado Libre
 * devuelve uno nuevo en cada renovación y el viejo deja de valer.
 *
 * Si dos peticiones renuevan al mismo tiempo, la segunda usa un refresh
 * que la primera ya gastó, recibe `invalid_grant` y la cuenta queda
 * desconectada sin que nadie haya hecho nada mal. Por eso la renovación
 * va dentro de un candado de MySQL (GET_LOCK) y relee la cuenta al
 * entrar: si otro ya renovó, usa lo que dejó.
 *
 * Esta clase no sabe de tablas: recibe y devuelve la cuenta como arreglo,
 * y quien la usa (Servicio\MeliInventario) decide dónde se guarda.
 */
final class MercadoLibre
{
    const API = 'https://api.mercadolibre.com';

    /** Dónde se autoriza, por país. El de la API es el mismo para todos. */
    const AUTORIZAR = [
        'MX' => 'https://auth.mercadolibre.com.mx/authorization',
        'AR' => 'https://auth.mercadolibre.com.ar/authorization',
        'CO' => 'https://auth.mercadolibre.com.co/authorization',
        'CL' => 'https://auth.mercadolibre.cl/authorization',
        'PE' => 'https://auth.mercadolibre.com.pe/authorization',
        'UY' => 'https://auth.mercadolibre.com.uy/authorization',
        'BR' => 'https://auth.mercadolivre.com.br/authorization',
    ];

    /** Cuánto antes de que caduque se renueva (segundos). */
    const MARGEN = 300;

    private static $config = null;

    private $ultimoError = '';
    private $ultimoHttp = 0;
    private $espia = null;

    // ════════════════════════════════════════════════════════
    //  LA CONFIGURACIÓN DE LA APLICACIÓN
    // ════════════════════════════════════════════════════════

    /**
     * El bloque `mercadolibre` de config/integraciones.php.
     *
     * Se lee directo del archivo y no por Servicio\Integraciones: aquel
     * solo entrega los bloques de su catálogo, y uno que no esté ahí se
     * ignora en silencio. Leerlo aquí hace que esta integración funcione
     * sin depender de que alguien se acuerde de darlo de alta allá.
     */
    public static function config()
    {
        if (self::$config !== null) return self::$config;
        $ruta = dirname(__DIR__, 2) . '/config/integraciones.php';
        $todo = is_readable($ruta) ? (array)(require $ruta) : [];
        return self::$config = (array)($todo['mercadolibre'] ?? []);
    }

    /** Para pruebas y para bin/: fija la configuración a mano. */
    public static function usarConfig(array $c) { self::$config = $c; }

    /** ¿Está puesta la aplicación? Sin ella no hay nada que conectar. */
    public static function activa()
    {
        return self::faltan() === [];
    }

    /** Qué le falta al bloque, para decirlo en pantalla. */
    public static function faltan()
    {
        $c = self::config();
        $f = [];
        if (empty($c['activo']))                       $f[] = "'activo' => true";
        if (trim((string)($c['app_id'] ?? '')) === '')  $f[] = 'app_id';
        if (trim((string)($c['secreto'] ?? '')) === '') $f[] = 'secreto';
        if (trim((string)($c['retorno'] ?? '')) === '') $f[] = 'retorno';
        return $f;
    }

    public static function appId()   { return trim((string)(self::config()['app_id'] ?? '')); }
    public static function retorno() { return trim((string)(self::config()['retorno'] ?? '')); }
    public static function pais()
    {
        $p = strtoupper(trim((string)(self::config()['pais'] ?? 'MX')));
        return isset(self::AUTORIZAR[$p]) ? $p : 'MX';
    }

    public function error() { return $this->ultimoError; }
    public function http()  { return $this->ultimoHttp; }
    public function espiar(callable $f) { $this->espia = $f; return $this; }

    // ════════════════════════════════════════════════════════
    //  CONECTAR UNA CUENTA (OAuth)
    // ════════════════════════════════════════════════════════

    /**
     * La dirección a la que se manda al administrador para que autorice.
     *
     * `state` es lo que evita que alguien nos haga conectar SU cuenta de
     * Mercado Libre a NUESTRA empresa con un enlace preparado: se genera
     * aquí, se guarda en la sesión, y al volver tiene que coincidir.
     *
     * PKCE (code_challenge) va encendido por omisión: si la aplicación lo
     * tiene activado en el panel de Mercado Libre es obligatorio, y si no,
     * no estorba. Se puede apagar con 'pkce' => false.
     *
     * @return array ['url' => ..., 'state' => ..., 'verificador' => ...]
     */
    public static function urlAutorizar()
    {
        $state = bin2hex(random_bytes(20));
        $q = [
            'response_type' => 'code',
            'client_id'     => self::appId(),
            'redirect_uri'  => self::retorno(),
            'state'         => $state,
        ];
        $verificador = '';
        if ((self::config()['pkce'] ?? true) !== false) {
            $verificador = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $q['code_challenge']        = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
            $q['code_challenge_method'] = 'S256';
        }
        return [
            'url'         => self::AUTORIZAR[self::pais()] . '?' . http_build_query($q),
            'state'       => $state,
            'verificador' => $verificador,
        ];
    }

    /**
     * Cambia el `code` que regresó Mercado Libre por las llaves.
     *
     * @return array|null ['user_id','access_token','refresh_token','expira_en'] o null
     */
    public function canjearCodigo($codigo, $verificador = '')
    {
        $p = [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::appId(),
            'client_secret' => trim((string)(self::config()['secreto'] ?? '')),
            'code'          => (string)$codigo,
            'redirect_uri'  => self::retorno(),
        ];
        if ($verificador !== '') $p['code_verifier'] = $verificador;
        return $this->pedirToken($p);
    }

    /**
     * Renueva con el refresh_token. Ver arriba por qué se gasta.
     * @return array|null las llaves nuevas, o null (y error()) si no se pudo
     */
    public function renovar($refresh)
    {
        return $this->pedirToken([
            'grant_type'    => 'refresh_token',
            'client_id'     => self::appId(),
            'client_secret' => trim((string)(self::config()['secreto'] ?? '')),
            'refresh_token' => (string)$refresh,
        ]);
    }

    /** ¿El último fallo fue porque la autorización ya no vale? Entonces hay que reconectar. */
    public function fueAutorizacion()
    {
        $t = strtolower($this->ultimoError);
        return strpos($t, 'invalid_grant') !== false || strpos($t, 'invalid_token') !== false
            || strpos($t, 'unauthorized_client') !== false || $this->ultimoHttp === 401;
    }

    private function pedirToken(array $p)
    {
        $r = $this->llamar('POST', '/oauth/token', null, $p, true);
        if (!is_array($r) || empty($r['access_token'])) {
            if ($this->ultimoError === '') $this->ultimoError = 'Mercado Libre no devolvió las llaves.';
            return null;
        }
        return [
            'user_id'       => (string)($r['user_id'] ?? ''),
            'access_token'  => (string)$r['access_token'],
            'refresh_token' => (string)($r['refresh_token'] ?? ''),
            // Se guarda la hora en que caduca, no "dura 6 horas": así no
            // hay que recordar cuándo se pidió.
            'expira_en'     => date('Y-m-d H:i:s', time() + max(60, (int)($r['expires_in'] ?? 21600))),
        ];
    }

    // ════════════════════════════════════════════════════════
    //  LO QUE SE USA
    // ════════════════════════════════════════════════════════

    /** Quién es el dueño del token. */
    public function yo($token)
    {
        return $this->llamar('GET', '/users/me', $token);
    }

    /**
     * Todos los ids de publicaciones del vendedor.
     *
     * Con la búsqueda normal Mercado Libre corta en mil (offset máximo).
     * El modo `scan` no tiene ese tope: se pide en tandas de cien con un
     * `scroll_id` hasta que deja de devolver resultados.
     */
    public function idsDePublicaciones($token, $userId, $tope = 5000)
    {
        $ids = [];
        $scroll = '';
        for ($vuelta = 0; $vuelta < 100 && count($ids) < $tope; $vuelta++) {
            $q = '/users/' . rawurlencode((string)$userId) . '/items/search?search_type=scan&limit=100'
               . ($scroll !== '' ? '&scroll_id=' . rawurlencode($scroll) : '');
            $r = $this->llamar('GET', $q, $token);
            if (!is_array($r)) return $ids ?: null;
            $lote = (array)($r['results'] ?? []);
            if (!$lote) break;
            foreach ($lote as $id) $ids[] = (string)$id;
            $scroll = (string)($r['scroll_id'] ?? '');
            if ($scroll === '') break;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Varias publicaciones de una vez (Mercado Libre acepta hasta 20).
     *
     * `include_attributes=all` hace que cada variación traiga sus propios
     * atributos, que es donde vive su SELLER_SKU. Sin eso, una playera en
     * tres tallas llega con un solo SKU para las tres.
     *
     * @return array [item_id => cuerpo]
     */
    public function publicaciones($token, array $ids)
    {
        $r = [];
        foreach (array_chunk(array_values($ids), 20) as $lote) {
            $res = $this->llamar('GET', '/items?include_attributes=all&ids=' . implode(',', array_map('rawurlencode', $lote)), $token);
            if (!is_array($res)) continue;
            foreach ($res as $f) {
                if ((int)($f['code'] ?? 0) === 200 && !empty($f['body']['id'])) {
                    $r[(string)$f['body']['id']] = $f['body'];
                }
            }
        }
        return $r;
    }

    public function publicacion($token, $itemId)
    {
        return $this->llamar('GET', '/items/' . rawurlencode((string)$itemId) . '?include_attributes=all', $token);
    }

    /**
     * Cambia la existencia de una publicación.
     *
     * CON VARIACIONES HAY UNA TRAMPA QUE BORRA COSAS
     *
     * Para cambiar la existencia de una talla se manda la lista
     * `variations`, y Mercado Libre interpreta esa lista como la lista
     * COMPLETA: la variación que no venga, la elimina. Por eso aquí se
     * pide la publicación tal como está AHORA (no como la guardamos al
     * importar, que puede ser vieja) y se mandan todas sus variaciones: las
     * que cambian con su cantidad nueva, las demás solo con su id.
     *
     * @param array $cantidades [variacion_id => cantidad]; 0 = sin variación
     */
    public function ponerExistencia($token, $itemId, array $cantidades)
    {
        $itemId = (string)$itemId;
        if (isset($cantidades[0]) && count($cantidades) === 1) {
            return $this->llamar('PUT', '/items/' . rawurlencode($itemId), $token,
                                 ['available_quantity' => max(0, (int)$cantidades[0])]) !== null;
        }

        $actual = $this->llamar('GET', '/items/' . rawurlencode($itemId) . '?attributes=id,variations', $token);
        if (!is_array($actual)) return false;
        $vars = (array)($actual['variations'] ?? []);
        if (!$vars) {
            $this->ultimoError = 'La publicación ya no tiene variaciones en Mercado Libre. Vuelve a importar.';
            return false;
        }
        $lista = [];
        $tocadas = 0;
        foreach ($vars as $v) {
            $vid = (string)($v['id'] ?? '');
            if ($vid === '') continue;
            if (array_key_exists($vid, $cantidades)) {
                $lista[] = ['id' => (int)$vid, 'available_quantity' => max(0, (int)$cantidades[$vid])];
                $tocadas++;
            } else {
                $lista[] = ['id' => (int)$vid];
            }
        }
        if (!$tocadas) {
            $this->ultimoError = 'Las variaciones vinculadas ya no existen en Mercado Libre. Vuelve a importar.';
            return false;
        }
        return $this->llamar('PUT', '/items/' . rawurlencode($itemId), $token, ['variations' => $lista]) !== null;
    }

    public function orden($token, $ordenId)
    {
        return $this->llamar('GET', '/orders/' . rawurlencode((string)$ordenId), $token);
    }

    /**
     * Las órdenes que cambiaron desde una fecha. Es la red de seguridad de
     * los avisos: si uno no llegó (el servidor estaba caído, o en local no
     * hay dirección pública), aquí se recupera.
     */
    public function ordenesDesde($token, $userId, $desde, $tope = 200)
    {
        $todas = [];
        $iso = date('Y-m-d\TH:i:s.000P', strtotime((string)$desde));
        for ($off = 0; $off < $tope; $off += 50) {
            $q = '/orders/search?seller=' . rawurlencode((string)$userId)
               . '&order.date_last_updated.from=' . rawurlencode($iso)
               . '&sort=date_asc&limit=50&offset=' . $off;
            $r = $this->llamar('GET', $q, $token);
            if (!is_array($r)) break;
            $lote = (array)($r['results'] ?? []);
            foreach ($lote as $o) $todas[] = $o;
            if (count($lote) < 50) break;
        }
        return $todas;
    }

    // ════════════════════════════════════════════════════════
    //  PUBLICAR DESDE LIBERTYFIN
    // ════════════════════════════════════════════════════════

    const SITIOS  = ['MX' => 'MLM', 'AR' => 'MLA', 'BR' => 'MLB', 'CO' => 'MCO', 'CL' => 'MLC', 'PE' => 'MPE', 'UY' => 'MLU'];
    const MONEDAS = ['MLM' => 'MXN', 'MLA' => 'ARS', 'MLB' => 'BRL', 'MCO' => 'COP', 'MLC' => 'CLP', 'MPE' => 'PEN', 'MLU' => 'UYU'];

    /** El sitio de Mercado Libre del país configurado: MLM para México. */
    public static function sitio()  { return self::SITIOS[self::pais()]; }
    public static function moneda() { return self::MONEDAS[self::sitio()]; }

    /**
     * Las categorías que Mercado Libre sugiere para un título.
     * @return array [{category_id, category_name, domain_name}] (vacío si no sugiere nada)
     */
    public function sugerirCategorias($token, $titulo, $tope = 6)
    {
        $r = $this->llamar('GET', '/sites/' . self::sitio() . '/domain_discovery/search?limit=' . (int)$tope
                           . '&q=' . rawurlencode((string)$titulo), $token);
        $lista = [];
        foreach ((array)$r as $s) {
            if (!empty($s['category_id'])) $lista[(string)$s['category_id']] = $s;
        }
        return array_values($lista);
    }

    /** La categoría: su ruta (path_from_root) y si admite publicar ahí (settings.listing_allowed). */
    public function categoria($token, $categoriaId)
    {
        return $this->llamar('GET', '/categories/' . rawurlencode((string)$categoriaId), $token);
    }

    /** La ficha técnica que pide la categoría. */
    public function atributosDeCategoria($token, $categoriaId)
    {
        return $this->llamar('GET', '/categories/' . rawurlencode((string)$categoriaId) . '/attributes', $token);
    }

    /**
     * Sube una foto desde un archivo del servidor. Se sube el archivo y no
     * se manda una dirección: así sirve aunque las imágenes de LibertyFin
     * no se puedan ver sin iniciar sesión, o el servidor sea local.
     * @return string el id de la foto en Mercado Libre, o '' si no se pudo
     */
    public function subirFoto($token, $rutaArchivo, $mime = 'image/jpeg')
    {
        if (!is_file($rutaArchivo)) { $this->ultimoError = 'No se encontró la imagen.'; return ''; }
        $r = $this->llamar('POST', '/pictures/items/upload', $token,
                           ['file' => new \CURLFile($rutaArchivo, $mime, basename($rutaArchivo))], 'multipart');
        return is_array($r) && !empty($r['id']) ? (string)$r['id'] : '';
    }

    public function crearPublicacion($token, array $cuerpo)
    {
        return $this->llamar('POST', '/items', $token, $cuerpo);
    }

    /** La descripción va aparte: Mercado Libre no la acepta dentro de la publicación. */
    public function ponerDescripcion($token, $itemId, $texto)
    {
        return $this->llamar('POST', '/items/' . rawurlencode((string)$itemId) . '/description', $token,
                             ['plain_text' => (string)$texto]) !== null;
    }

    // ════════════════════════════════════════════════════════

    /**
     * Crea un usuario de PRUEBA de Mercado Libre (para bin/meli-usuario-prueba.php).
     * Devuelve {id, nickname, password, site_status} o null.
     * Solo opera con otros usuarios de prueba; máximo 10 por cuenta.
     */
    public function crearUsuarioPrueba($token, $siteId = 'MLM')
    {
        return $this->llamar('POST', '/users/test_user', $token, ['site_id' => (string)$siteId]);
    }

    /** Quitar el permiso de LibertyFin en la cuenta. Si falla no importa: las llaves se borran igual. */
    public function revocar($token, $userId)
    {
        return $this->llamar('DELETE', '/users/' . rawurlencode((string)$userId) . '/applications/'
                             . rawurlencode(self::appId()), $token) !== null;
    }

    // ════════════════════════════════════════════════════════
    //  CIFRAR LAS LLAVES
    // ════════════════════════════════════════════════════════

    /**
     * Las llaves de la cuenta van cifradas en la base.
     *
     * Un respaldo de la base, un phpMyAdmin compartido o un volcado
     * mandado por correo no deben bastar para vender, pausar o cambiar
     * precios en nombre de la empresa. La llave de cifrado vive en
     * config/integraciones.php ('llave_cifrado'), fuera de la base; si no
     * está, se deriva del secreto de la aplicación.
     *
     * Cambiar cualquiera de las dos deja las llaves guardadas ilegibles:
     * las empresas tendrán que volver a conectar. No se pierde nada más.
     */
    public static function cifrar($texto)
    {
        $texto = (string)$texto;
        if ($texto === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($texto, 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new \RuntimeException('No se pudo cifrar la llave de Mercado Libre.');
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
        $base = trim((string)($c['llave_cifrado'] ?? '')) ?: trim((string)($c['secreto'] ?? ''));
        if ($base === '') throw new \RuntimeException('Falta la configuración de Mercado Libre.');
        return hash('sha256', 'libertyfin|meli|' . $base, true);
    }

    // ════════════════════════════════════════════════════════

    /**
     * Una llamada. Devuelve el JSON como arreglo, o null y el motivo en error().
     *
     * Mercado Libre sí usa los códigos HTTP como se debe: 2xx es éxito y
     * lo demás trae {message, error, cause}. Lo que hay que cuidar es el
     * 429 (demasiadas peticiones), que aquí se reintenta una vez.
     */
    private function llamar($verbo, $ruta, $token, $cuerpo = null, $formulario = false)
    {
        $this->ultimoError = '';
        $this->ultimoHttp  = 0;

        for ($intento = 1; $intento <= 2; $intento++) {
            $cab = ['Accept: application/json'];
            if ($token !== null && $token !== '') $cab[] = 'Authorization: Bearer ' . $token;

            $op = [
                CURLOPT_CUSTOMREQUEST  => $verbo,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)(self::config()['timeout'] ?? 25),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($cuerpo !== null) {
                if ($formulario === 'multipart') {
                    // curl arma el multipart y su Content-Type con el límite.
                    $op[CURLOPT_POSTFIELDS] = $cuerpo;
                } elseif ($formulario) {
                    $cab[] = 'Content-Type: application/x-www-form-urlencoded';
                    $op[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
                } else {
                    $cab[] = 'Content-Type: application/json';
                    $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            $op[CURLOPT_HTTPHEADER] = $cab;

            // Nunca al espía: ni el token ni el secreto de la aplicación.
            $this->ver('>>>', $verbo . ' ' . $ruta . ($cuerpo !== null && !$formulario ? ' ' . $op[CURLOPT_POSTFIELDS] : ''));

            $ch = curl_init(self::API . $ruta);
            curl_setopt_array($ch, $op);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->ultimoHttp = $http;

            if ($res === false) {
                $this->ultimoError = 'No se pudo conectar con Mercado Libre: ' . ($err ?: 'sin detalle');
                return null;
            }
            $this->ver('<<<', 'HTTP ' . $http . ' ' . mb_substr((string)$res, 0, 800));

            if ($http === 429 && $intento === 1) { usleep(1500000); continue; }

            $j = json_decode((string)$res, true);
            if ($http >= 200 && $http < 300) {
                // Un DELETE o un PUT pueden contestar sin cuerpo.
                return is_array($j) ? $j : [];
            }
            $this->ultimoError = self::mensajeDeError(is_array($j) ? $j : [], $http);
            return null;
        }
        return null;
    }

    private static function mensajeDeError(array $j, $http)
    {
        $msg = trim((string)($j['message'] ?? ''));
        $cod = trim((string)($j['error'] ?? ''));
        $causas = [];
        foreach ((array)($j['cause'] ?? []) as $c) {
            if (is_array($c) && !empty($c['message'])) $causas[] = (string)$c['message'];
            elseif (is_string($c) && $c !== '') $causas[] = $c;
        }
        $t = strtolower($msg . ' ' . $cod . ' ' . implode(' ', $causas));

        if (strpos($t, 'invalid_grant') !== false) {
            return 'La autorización de Mercado Libre ya no vale (invalid_grant). Hay que volver a conectar la cuenta.';
        }
        if ($http === 401 || strpos($t, 'invalid_token') !== false || strpos($t, 'invalid access token') !== false) {
            return 'Mercado Libre no aceptó la llave de la cuenta (invalid_token).';
        }
        if (strpos($t, 'fulfillment') !== false) {
            return 'Esa publicación está en Mercado Libre Full: la existencia la lleva Mercado Libre, no se puede cambiar desde aquí.';
        }
        if ($http === 403) {
            return 'Mercado Libre no deja hacer eso con esta cuenta (403). '
                 . ($msg !== '' ? $msg : 'Revisa que la aplicación tenga permisos de lectura y escritura.');
        }
        $txt = $msg !== '' ? $msg : ($cod !== '' ? $cod : 'Mercado Libre rechazó la petición');
        if ($causas) $txt .= ' · ' . implode(' · ', array_unique($causas));
        return $txt . ' (HTTP ' . $http . ')';
    }

    private function ver($quien, $que)
    {
        if ($this->espia) call_user_func($this->espia, $quien, (string)$que);
    }
}
