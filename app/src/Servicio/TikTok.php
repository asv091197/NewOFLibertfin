<?php
namespace LibertyFin\Servicio;

/**
 * TikTok Shop: hablar con su Open API en nombre de UNA tienda.
 *
 * ──────────────────────────────────────────────────────────────────────
 * DOS FORMAS DE TENER LA APLICACIÓN (y el mismo código para las dos)
 *
 *  · App pública (ISV): LibertyFin registra UNA aplicación en el Partner
 *    Center de TikTok Shop. app_key + app_secret van en
 *    config/integraciones.php, bloque `tiktok`, y sirven para todas las
 *    empresas. Igual que Shopify.
 *
 *  · Seller in-house: cada vendedor registra SU aplicación, que solo puede
 *    conectar sus propias tiendas. Su app_key + app_secret se pegan en la
 *    pantalla de TikTok Shop de LibertyFin y se guardan, cifrados, en la
 *    base de SU empresa.
 *
 * credenciales() decide: si la empresa tiene las suyas, se usan esas; si
 * no, las globales. Pasar de una forma a la otra no cambia nada más.
 * ──────────────────────────────────────────────────────────────────────
 *
 * LO QUE TIENE DE PARTICULAR TIKTOK
 *
 *  · Cada petición va FIRMADA (firmar()): HMAC-SHA256 con el app_secret de
 *    la ruta + los parámetros ordenados + el cuerpo, envuelto en el secreto.
 *  · La llave (access_token) dura días, no meses: se renueva sola con el
 *    refresh_token antes de que caduque (ver TikTokInventario::token()).
 *  · Además de la llave hace falta el `shop_cipher` de la tienda: va en
 *    casi todas las llamadas. Se pide al conectar (tiendas()).
 *  · La existencia vive por SKU y por ALMACÉN (warehouse). La cuenta guarda
 *    qué almacén de TikTok corresponde a la sucursal de LibertyFin.
 *  · Los avisos NO se dan de alta por API: se configuran una vez en la app
 *    del Partner Center, apuntando a /tiktok/aviso. Vienen firmados en la
 *    cabecera Authorization (ver firmaAvisoValida()).
 *  · Todas las respuestas traen `code`: 0 es éxito, cualquier otro es error
 *    aunque el HTTP sea 200.
 *
 * Las rutas llevan la versión (202309) en el camino. Si TikTok publica una
 * nueva, se cambia aquí; `--crudo` en bin/tiktok-sincronizar.php enseña lo
 * que va y viene.
 *
 * Esta clase no sabe de tablas: quien la usa (Servicio\TikTokInventario)
 * decide dónde se guarda la cuenta.
 */
final class TikTok
{
    const API       = 'https://open-api.tiktokglobalshop.com';
    const AUTH      = 'https://auth.tiktok-shops.com';
    const AUTORIZAR = 'https://services.tiktokshop.com/open/authorize';

    /** Códigos de `code` que dicen «la llave ya no vale»: hay que renovar o reconectar. */
    const CODIGOS_LLAVE = [105000, 105001, 105002, 105003, 105004, 105005, 36004004];

    /**
     * Estados de orden en los que ya está PAGADA y libre de la ventana de
     * arrepentimiento (ON_HOLD). Es cuando se descuenta.
     */
    const PAGADAS = ['AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING', 'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

    private static $config = null;

    private $appKey;
    private $appSecret;
    private $ultimoError = '';
    private $ultimoHttp = 0;
    private $ultimoCodigo = 0;
    private $espia = null;

    /**
     * @param array|null $cred ['app_key' => ..., 'app_secret' => ...] o null
     *                         para usar las globales de config.
     */
    public function __construct($cred = null)
    {
        $cred = is_array($cred) ? $cred : self::credencialesGlobales();
        $this->appKey    = trim((string)($cred['app_key'] ?? ''));
        $this->appSecret = trim((string)($cred['app_secret'] ?? ''));
    }

    // ════════════════════════════════════════════════════════
    //  LA CONFIGURACIÓN
    // ════════════════════════════════════════════════════════

    /** El bloque `tiktok` de config/integraciones.php (ver Shopify::config). */
    public static function config()
    {
        if (self::$config !== null) return self::$config;
        $ruta = dirname(__DIR__, 2) . '/config/integraciones.php';
        $todo = is_readable($ruta) ? (array)(require $ruta) : [];
        return self::$config = (array)($todo['tiktok'] ?? []);
    }

    public static function usarConfig(array $c) { self::$config = $c; }

    /** El módulo se puede usar en el servidor: está `activo` y hay con qué cifrar llaves. */
    public static function activa() { return self::faltan() === []; }

    public static function faltan()
    {
        $c = self::config();
        $f = [];
        if (empty($c['activo'])) $f[] = "'activo' => true";
        if (trim((string)($c['llave_cifrado'] ?? '')) === '' && trim((string)($c['app_secret'] ?? '')) === '') {
            $f[] = 'llave_cifrado (o app_secret)';
        }
        return $f;
    }

    /** ¿Hay app pública (ISV) en config? Si no, cada empresa pone la suya. */
    public static function hayAppGlobal()
    {
        $c = self::config();
        return !empty($c['activo']) && trim((string)($c['app_key'] ?? '')) !== ''
            && trim((string)($c['app_secret'] ?? '')) !== '';
    }

    public static function credencialesGlobales()
    {
        $c = self::config();
        return [
            'app_key'    => trim((string)($c['app_key'] ?? '')),
            'app_secret' => trim((string)($c['app_secret'] ?? '')),
            'service_id' => trim((string)($c['service_id'] ?? '')),
            'propias'    => false,
        ];
    }

    /**
     * Las credenciales que tocan a una empresa: las suyas (in-house) si las
     * guardó, si no las globales (ISV).
     * @param array|null $cuenta el renglón de lf_tt_cuenta
     * @return array ['app_key','app_secret','service_id','propias' => bool]
     */
    public static function credenciales($cuenta)
    {
        if (is_array($cuenta) && trim((string)($cuenta['app_key'] ?? '')) !== '' && !empty($cuenta['app_secret'])) {
            $secreto = self::descifrar($cuenta['app_secret']);
            if ($secreto !== '') {
                return [
                    'app_key'    => trim((string)$cuenta['app_key']),
                    'app_secret' => $secreto,
                    'service_id' => trim((string)($cuenta['service_id'] ?? '')),
                    'propias'    => true,
                ];
            }
        }
        return self::credencialesGlobales();
    }

    public function appKey() { return $this->appKey; }
    public function listo()  { return $this->appKey !== '' && $this->appSecret !== ''; }

    public function error()  { return $this->ultimoError; }
    public function http()   { return $this->ultimoHttp; }
    public function codigo() { return $this->ultimoCodigo; }
    public function espiar(callable $f) { $this->espia = $f; return $this; }

    // ════════════════════════════════════════════════════════
    //  LA FIRMA
    // ════════════════════════════════════════════════════════

    /**
     * La firma de una petición a la Open API:
     *   1. los parámetros del query, sin `sign` ni `access_token`, ordenados por nombre
     *   2. pegados como nombrevalor
     *   3. la ruta al principio y el cuerpo (JSON crudo) al final
     *   4. todo envuelto en el secreto: secreto + texto + secreto
     *   5. HMAC-SHA256 con el secreto, en hexadecimal
     */
    public static function firmar($ruta, array $query, $cuerpo, $secreto)
    {
        unset($query['sign'], $query['access_token']);
        ksort($query, SORT_STRING);
        $texto = (string)$ruta;
        foreach ($query as $k => $v) $texto .= $k . (is_array($v) ? implode(',', $v) : (string)$v);
        $texto .= (string)$cuerpo;
        return hash_hmac('sha256', $secreto . $texto . $secreto, (string)$secreto);
    }

    /**
     * ¿El aviso viene de TikTok? La cabecera Authorization trae
     * HMAC-SHA256(app_key + cuerpo crudo) con el app_secret, en hexadecimal.
     */
    public static function firmaAvisoValida($cuerpo, $firma, $appKey, $secreto)
    {
        $firma = strtolower(trim((string)$firma));
        if ($firma === '' || (string)$appKey === '' || (string)$secreto === '') return false;
        return hash_equals(hash_hmac('sha256', (string)$appKey . (string)$cuerpo, (string)$secreto), $firma);
    }

    // ════════════════════════════════════════════════════════
    //  CONECTAR UNA TIENDA (OAuth)
    // ════════════════════════════════════════════════════════

    /**
     * La dirección a la que se manda al vendedor para que autorice.
     * Se puede fijar entera con `url_autorizar` en config (con {service_id}
     * y {state}): TikTok da el enlace exacto en la página de la app.
     * @return array ['url' => ..., 'state' => ...]
     */
    public static function urlAutorizar($serviceId, $state = null)
    {
        $state = $state ?: bin2hex(random_bytes(20));
        $plantilla = trim((string)(self::config()['url_autorizar'] ?? ''));
        if ($plantilla !== '') {
            $url = strtr($plantilla, ['{service_id}' => rawurlencode((string)$serviceId), '{state}' => rawurlencode($state)]);
            if (strpos($plantilla, '{state}') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'state=' . rawurlencode($state);
            }
        } else {
            $url = self::AUTORIZAR . '?' . http_build_query(['service_id' => (string)$serviceId, 'state' => $state]);
        }
        return ['url' => $url, 'state' => $state];
    }

    /**
     * Cambia el `code` del regreso por la llave.
     * @return array|null ['access_token','refresh_token','expira_en','refresh_expira_en','vendedor','region']
     */
    public function canjearCodigo($codigo)
    {
        return $this->pedirLlave('/api/v2/token/get', [
            'app_key' => $this->appKey, 'app_secret' => $this->appSecret,
            'auth_code' => (string)$codigo, 'grant_type' => 'authorized_code',
        ], 'TikTok no devolvió la llave.');
    }

    public function renovar($refreshToken)
    {
        return $this->pedirLlave('/api/v2/token/refresh', [
            'app_key' => $this->appKey, 'app_secret' => $this->appSecret,
            'refresh_token' => (string)$refreshToken, 'grant_type' => 'refresh_token',
        ], 'TikTok no renovó la llave.');
    }

    private function pedirLlave($ruta, array $q, $siFalla)
    {
        if (!$this->listo()) { $this->ultimoError = 'Faltan el App Key y el App Secret de TikTok Shop.'; return null; }
        $d = $this->llamar('GET', self::AUTH . $ruta . '?' . http_build_query($q), null, null, true);
        if (!is_array($d) || empty($d['access_token'])) {
            if ($this->ultimoError === '') $this->ultimoError = $siFalla;
            return null;
        }
        return [
            'access_token'      => (string)$d['access_token'],
            'refresh_token'     => (string)($d['refresh_token'] ?? ''),
            'expira_en'         => self::fechaDeExpiracion($d['access_token_expire_in'] ?? 0),
            'refresh_expira_en' => self::fechaDeExpiracion($d['refresh_token_expire_in'] ?? 0),
            'vendedor'          => (string)($d['seller_name'] ?? ''),
            'region'            => (string)($d['seller_base_region'] ?? ''),
        ];
    }

    /**
     * TikTok manda la caducidad como fecha (segundos Unix). Por si algún
     * día llega como «segundos que faltan», un número chico se suma a hoy.
     */
    public static function fechaDeExpiracion($valor)
    {
        $v = (int)$valor;
        if ($v <= 0) return null;
        return date('Y-m-d H:i:s', $v > 1000000000 ? $v : time() + $v);
    }

    /** ¿El último fallo fue porque la llave ya no vale? */
    public function fueAutorizacion()
    {
        return $this->ultimoHttp === 401 || in_array($this->ultimoCodigo, self::CODIGOS_LLAVE, true);
    }

    // ════════════════════════════════════════════════════════
    //  LA TIENDA Y SUS ALMACENES
    // ════════════════════════════════════════════════════════

    /** @return array|null [['id','nombre','region','cipher']] las tiendas que autorizó el vendedor */
    public function tiendas($token)
    {
        $d = $this->api('GET', '/authorization/202309/shops', $token, null);
        if ($d === null) return null;
        $r = [];
        foreach ((array)($d['shops'] ?? []) as $s) {
            $r[] = ['id' => (string)($s['id'] ?? ''), 'nombre' => (string)($s['name'] ?? ''),
                    'region' => (string)($s['region'] ?? ''), 'cipher' => (string)($s['cipher'] ?? '')];
        }
        return $r;
    }

    /** @return array|null [['id','nombre','activo' => bool,'venta' => bool,'omision' => bool]] */
    public function almacenes($token, $cipher)
    {
        $d = $this->api('GET', '/logistics/202309/warehouses', $token, $cipher);
        if ($d === null) return null;
        $r = [];
        foreach ((array)($d['warehouses'] ?? []) as $w) {
            $r[] = [
                'id'      => (string)($w['id'] ?? ''),
                'nombre'  => (string)($w['name'] ?? ''),
                'activo'  => strtoupper((string)($w['effect_status'] ?? 'ENABLED')) === 'ENABLED',
                'venta'   => strtoupper((string)($w['type'] ?? 'SALES_WAREHOUSE')) === 'SALES_WAREHOUSE',
                'omision' => !empty($w['is_default']),
            ];
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════
    //  PRODUCTOS (cada SKU es un renglón)
    // ════════════════════════════════════════════════════════

    /**
     * Todos los productos con sus SKU, de 100 en 100.
     * @return array|null la lista, o null si no se pudo leer ni la primera página
     */
    public function productos($token, $cipher, $tope = 20000)
    {
        $todos = [];
        $pagina = '';
        for ($n = 1; $n <= 300 && count($todos) < $tope; $n++) {
            $q = ['page_size' => 100];
            if ($pagina !== '') $q['page_token'] = $pagina;
            $d = $this->api('POST', '/product/202309/products/search', $token, $cipher, $q, ['status' => 'ALL']);
            if ($d === null) return $n === 1 ? null : $todos;
            foreach ((array)($d['products'] ?? []) as $p) $todos[] = $p;
            $pagina = (string)($d['next_page_token'] ?? '');
            if ($pagina === '') break;
        }
        return $todos;
    }

    /** Un producto con sus SKU. null + http 404 si ya no existe. */
    public function producto($token, $cipher, $productoId)
    {
        $d = $this->api('GET', '/product/202309/products/' . rawurlencode((string)$productoId), $token, $cipher);
        if ($d === null) return null;
        if (empty($d['id'])) { $this->ultimoHttp = 404; $this->ultimoError = 'El producto ya no existe en TikTok Shop.'; return null; }
        return $d;
    }

    /** Pone la existencia de UN SKU en UN almacén, tal cual. */
    public function ponerExistencia($token, $cipher, $productoId, $skuId, $almacen, $cantidad)
    {
        $cuerpo = ['skus' => [[
            'id' => (string)$skuId,
            'inventory' => [['warehouse_id' => (string)$almacen, 'quantity' => max(0, (int)$cantidad)]],
        ]]];
        $d = $this->api('POST', '/product/202309/products/' . rawurlencode((string)$productoId) . '/inventory/update',
                        $token, $cipher, [], $cuerpo);
        return $d !== null;
    }

    // ════════════════════════════════════════════════════════
    //  ÓRDENES
    // ════════════════════════════════════════════════════════

    public function orden($token, $cipher, $ordenId)
    {
        $d = $this->api('GET', '/order/202309/orders', $token, $cipher, ['ids' => (string)$ordenId]);
        if ($d === null) return null;
        $o = $d['orders'][0] ?? null;
        if (!$o) { $this->ultimoHttp = 404; $this->ultimoError = 'La orden no existe en TikTok Shop.'; return null; }
        return self::orden_($o);
    }

    /** Las órdenes que cambiaron desde una fecha: la red de seguridad de los avisos. */
    public function ordenesDesde($token, $cipher, $desde, $tope = 600)
    {
        $todas = [];
        $pagina = '';
        $filtro = ['update_time_ge' => strtotime((string)$desde), 'update_time_lt' => time() + 60];
        while (count($todas) < $tope) {
            $q = ['page_size' => 50, 'sort_field' => 'update_time', 'sort_order' => 'ASC'];
            if ($pagina !== '') $q['page_token'] = $pagina;
            $d = $this->api('POST', '/order/202309/orders/search', $token, $cipher, $q, $filtro);
            if ($d === null) break;
            foreach ((array)($d['orders'] ?? []) as $o) $todas[] = self::orden_($o);
            $pagina = (string)($d['next_page_token'] ?? '');
            if ($pagina === '') break;
        }
        return $todas;
    }

    /**
     * La orden en un arreglo plano. Sin datos del comprador a propósito.
     * En la versión 202309 cada renglón de `line_items` es UNA pieza: el
     * mismo SKU comprado tres veces llega en tres renglones. Por si llega
     * `quantity`, se respeta.
     */
    public static function orden_(array $o)
    {
        $lineas = [];
        foreach ((array)($o['line_items'] ?? []) as $l) {
            $lineas[] = [
                'sku_id'   => (string)($l['sku_id'] ?? ''),
                'sku'      => trim((string)($l['seller_sku'] ?? '')),
                'nombre'   => trim((string)($l['product_name'] ?? '') . (!empty($l['sku_name']) ? ' · ' . $l['sku_name'] : '')),
                'cantidad' => (float)($l['quantity'] ?? 1),
                'cancelada'=> in_array(strtoupper((string)($l['display_status'] ?? '')), ['CANCELLED'], true),
            ];
        }
        $creada = (int)($o['create_time'] ?? 0);
        return [
            'id'     => (string)($o['id'] ?? ''),
            'estado' => strtoupper((string)($o['status'] ?? '')),
            'creada' => $creada > 0 ? date('Y-m-d H:i:s', $creada) : '',
            'total'  => (float)($o['payment']['total_amount'] ?? 0),
            'lineas' => $lineas,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  CIFRAR (igual que Shopify::cifrar, con su propia llave)
    // ════════════════════════════════════════════════════════

    public static function cifrar($texto)
    {
        $texto = (string)$texto;
        if ($texto === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($texto, 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new \RuntimeException('No se pudo cifrar la llave de TikTok Shop.');
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function descifrar($guardado)
    {
        $guardado = (string)$guardado;
        if (strncmp($guardado, 'v1:', 3) !== 0) return '';
        $b = base64_decode(substr($guardado, 3), true);
        if ($b === false || strlen($b) < 29) return '';
        try { $llave = self::llave(); } catch (\RuntimeException $e) { return ''; }
        $t = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $llave, OPENSSL_RAW_DATA,
                             substr($b, 0, 12), substr($b, 12, 16));
        return $t === false ? '' : $t;
    }

    /**
     * La llave sale de `llave_cifrado`, o del app_secret global. NO del
     * secreto de cada empresa: ese secreto es justo una de las cosas que se
     * cifran con ella.
     */
    private static function llave()
    {
        $c = self::config();
        $base = trim((string)($c['llave_cifrado'] ?? '')) ?: trim((string)($c['app_secret'] ?? ''));
        if ($base === '') throw new \RuntimeException('Falta la configuración de TikTok Shop (llave_cifrado).');
        return hash('sha256', 'libertyfin|tiktok|' . $base, true);
    }

    // ════════════════════════════════════════════════════════

    /**
     * Una llamada firmada a la Open API. Devuelve `data`, o null y el
     * motivo en error().
     */
    public function api($verbo, $ruta, $token, $cipher, array $query = [], $cuerpo = null)
    {
        if (!$this->listo()) { $this->ultimoError = 'Faltan el App Key y el App Secret de TikTok Shop.'; return null; }
        $query['app_key']   = $this->appKey;
        $query['timestamp'] = time();
        if ($cipher !== null && $cipher !== '') $query['shop_cipher'] = (string)$cipher;
        $json = $cuerpo !== null ? json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $query['sign'] = self::firmar($ruta, $query, $json, $this->appSecret);
        return $this->llamar($verbo, self::API . $ruta . '?' . http_build_query($query), $token, $cuerpo !== null ? $json : null);
    }

    /**
     * Una llamada. TikTok contesta {code, message, data}: code 0 es éxito.
     * Se reintenta si dice que vamos muy rápido (HTTP 429) o si falla su lado (5xx).
     */
    private function llamar($verbo, $url, $token, $json = null, $esAuth = false)
    {
        $this->ultimoError  = '';
        $this->ultimoHttp   = 0;
        $this->ultimoCodigo = 0;

        for ($intento = 1; $intento <= 3; $intento++) {
            $cab = ['Accept: application/json', 'User-Agent: LibertyFin'];
            if ($token !== null && $token !== '') $cab[] = 'x-tts-access-token: ' . $token;
            $op = [
                CURLOPT_CUSTOMREQUEST  => $verbo,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)(self::config()['timeout'] ?? 25),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($json !== null) {
                $cab[] = 'Content-Type: application/json';
                $op[CURLOPT_POSTFIELDS] = $json;
            }
            $op[CURLOPT_HTTPHEADER] = $cab;

            // Nunca al espía: ni la llave ni el secreto (en /token van en el query).
            $ver = $esAuth ? preg_replace('~\?.*$~', '?…', $url) : preg_replace('~([?&](sign|app_key))=[^&]*~', '$1=…', $url);
            $this->ver('>>>', $verbo . ' ' . preg_replace('~^https://[^/]+~', '', $ver)
                . ($json !== null ? ' ' . mb_substr($json, 0, 600) : ''));

            $ch = curl_init($url);
            curl_setopt_array($ch, $op);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->ultimoHttp = $http;

            if ($res === false) {
                $this->ultimoError = 'No se pudo conectar con TikTok Shop: ' . ($err ?: 'sin detalle');
                return null;
            }
            $this->ver('<<<', 'HTTP ' . $http . ' ' . ($esAuth ? '(llave)' : mb_substr((string)$res, 0, 800)));

            if (($http === 429 || $http >= 500) && $intento < 3) { usleep(1100000 * $intento); continue; }

            $j = json_decode((string)$res, true);
            if (!is_array($j)) {
                $this->ultimoError = 'TikTok Shop contestó algo que no se entiende (HTTP ' . $http . ').';
                return null;
            }
            $this->ultimoCodigo = (int)($j['code'] ?? 0);
            if ($http >= 200 && $http < 300 && $this->ultimoCodigo === 0) return (array)($j['data'] ?? []);
            $this->ultimoError = self::mensajeDeError($j, $http);
            return null;
        }
        return null;
    }

    private static function mensajeDeError(array $j, $http)
    {
        $codigo = (int)($j['code'] ?? 0);
        $msg = trim((string)($j['message'] ?? ''));
        if ($http === 401 || in_array($codigo, self::CODIGOS_LLAVE, true)) {
            return 'TikTok Shop no aceptó la llave de la tienda (' . ($codigo ?: $http) . '): hay que volver a conectar.'
                . ($msg !== '' ? ' ' . $msg : '');
        }
        if ($http === 403) return 'TikTok Shop no deja hacer eso (403): revisa los permisos de la aplicación.' . ($msg !== '' ? ' ' . $msg : '');
        return 'TikTok Shop: ' . ($msg !== '' ? $msg : 'rechazó la petición') . ' (código ' . $codigo . ', HTTP ' . $http . ')';
    }

    private function ver($quien, $que)
    {
        if ($this->espia) call_user_func($this->espia, $quien, (string)$que);
    }
}
