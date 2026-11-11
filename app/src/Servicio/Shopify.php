<?php
namespace LibertyFin\Servicio;

/**
 * Shopify: hablar con su API en nombre de UNA tienda.
 *
 * ──────────────────────────────────────────────────────────────────────
 * UNA APLICACIÓN, MUCHAS TIENDAS (igual que Mercado Libre y Tienda Nube)
 *
 * LibertyFin registra UNA aplicación en Shopify (Dev Dashboard o Partners):
 * client_id + secreto. Esa va en config/integraciones.php, bloque
 * `shopify`, y es de toda la instalación.
 *
 * Cada EMPRESA conecta su propia tienda: escribe su dominio
 * (mitienda.myshopify.com), entra a Shopify, autoriza a LibertyFin, y
 * Shopify nos da una llave que solo sirve para ESA tienda. La llave se
 * guarda cifrada en la base de la empresa.
 * ──────────────────────────────────────────────────────────────────────
 *
 * DIFERENCIAS CON TIENDA NUBE QUE IMPORTAN
 *
 *  · La tienda se identifica por su dominio *.myshopify.com, no por un
 *    número. Hay que pedirlo ANTES de mandar a autorizar: la dirección de
 *    autorización es de la propia tienda.
 *  · El regreso de la autorización viene FIRMADO (hmac en el query). Se
 *    revisa antes de canjear nada (ver hmacQueryValido()).
 *  · La API es GraphQL: una sola dirección, todo es POST. La REST Admin
 *    API es «legacy» y Shopify ya no la acepta en aplicaciones públicas
 *    nuevas.
 *  · La existencia NO vive en la variante: vive en el «inventory item» de
 *    la variante, POR UBICACIÓN (location). Por eso la cuenta guarda qué
 *    ubicación de Shopify corresponde a la sucursal de LibertyFin.
 *  · Los avisos vienen firmados en base64 (X-Shopify-Hmac-Sha256), y si la
 *    firma no vale hay que contestar 401: Shopify lo revisa al aprobar la
 *    aplicación.
 *  · La llave offline normalmente no caduca. Si Shopify la entrega con
 *    `expires_in` + `refresh_token` (llaves que caducan), se guarda
 *    también y se renueva sola (ver renovar()).
 *
 * Los ids se guardan como el número (legacyResourceId) y se convierten a
 * GID («gid://shopify/ProductVariant/123») solo para hablar con la API:
 * los avisos traen el número.
 *
 * Esta clase no sabe de tablas: quien la usa (Servicio\ShopifyInventario)
 * decide dónde se guarda la cuenta.
 */
final class Shopify
{
    /** Versión de la API si `api_version` viene vacía. Shopify sostiene cada versión ~12 meses. */
    const VERSION = '2026-10';

    const SCOPES = 'read_products,write_products,read_orders,read_inventory,write_inventory,read_locations';

    /** Los avisos que se dan de alta en cada tienda al conectar (formato GraphQL de WebhookSubscriptionTopic). */
    const EVENTOS = ['ORDERS_PAID', 'ORDERS_CANCELLED', 'ORDERS_UPDATED', 'PRODUCTS_UPDATE', 'PRODUCTS_DELETE', 'APP_UNINSTALLED'];

    private static $config = null;

    private $ultimoError = '';
    private $ultimoHttp = 0;
    private $espia = null;

    // ════════════════════════════════════════════════════════
    //  LA CONFIGURACIÓN DE LA APLICACIÓN
    // ════════════════════════════════════════════════════════

    /** El bloque `shopify` de config/integraciones.php (ver MercadoLibre::config, por qué directo). */
    public static function config()
    {
        if (self::$config !== null) return self::$config;
        $ruta = dirname(__DIR__, 2) . '/config/integraciones.php';
        $todo = is_readable($ruta) ? (array)(require $ruta) : [];
        return self::$config = (array)($todo['shopify'] ?? []);
    }

    public static function usarConfig(array $c) { self::$config = $c; }

    public static function activa() { return self::faltan() === []; }

    public static function faltan()
    {
        $c = self::config();
        $f = [];
        if (empty($c['activo']))                         $f[] = "'activo' => true";
        if (trim((string)($c['client_id'] ?? '')) === '') $f[] = 'client_id';
        if (trim((string)($c['secreto'] ?? '')) === '')   $f[] = 'secreto';
        return $f;
    }

    public static function clientId() { return trim((string)(self::config()['client_id'] ?? '')); }
    private static function secreto() { return trim((string)(self::config()['secreto'] ?? '')); }

    public static function version()
    {
        $v = trim((string)(self::config()['api_version'] ?? ''));
        return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : self::VERSION;
    }

    public static function scopes()
    {
        $s = trim((string)(self::config()['scopes'] ?? ''));
        return $s !== '' ? preg_replace('/\s+/', '', $s) : self::SCOPES;
    }

    public function error() { return $this->ultimoError; }
    public function http()  { return $this->ultimoHttp; }
    public function espiar(callable $f) { $this->espia = $f; return $this; }

    // ════════════════════════════════════════════════════════
    //  LA TIENDA: SU DOMINIO
    // ════════════════════════════════════════════════════════

    /**
     * Lo que escribe la persona → «mitienda.myshopify.com», o '' si no sirve.
     * Acepta «mitienda», «mitienda.myshopify.com», «https://mitienda.myshopify.com/admin»
     * y «admin.shopify.com/store/mitienda». NO acepta el dominio propio
     * (www.mitienda.com): Shopify solo autoriza en el *.myshopify.com.
     */
    public static function normalizarTienda($texto)
    {
        $t = strtolower(trim((string)$texto));
        if ($t === '') return '';
        if (preg_match('~admin\.shopify\.com/store/([a-z0-9][a-z0-9\-]*)~', $t, $m)) return $m[1] . '.myshopify.com';
        $t = preg_replace('~^https?://~', '', $t);
        $t = preg_replace('~[/?#].*$~', '', $t);
        if (strpos($t, '.') === false) $t .= '.myshopify.com';
        return self::tiendaValida($t) ? $t : '';
    }

    /** Solo *.myshopify.com: es a donde se manda la llave, no puede ser cualquier dominio. */
    public static function tiendaValida($tienda)
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', (string)$tienda);
    }

    // ════════════════════════════════════════════════════════
    //  CONECTAR UNA TIENDA (OAuth)
    // ════════════════════════════════════════════════════════

    /**
     * La dirección a la que se manda al administrador para que autorice.
     * Sin `grant_options[]=per-user`: así la llave es «offline», de la
     * tienda y no de la persona, y sirve para la tarea programada.
     * @return array ['url' => ..., 'state' => ...]
     */
    public static function urlAutorizar($tienda, $retorno)
    {
        $state = bin2hex(random_bytes(20));
        return [
            'url' => 'https://' . $tienda . '/admin/oauth/authorize?' . http_build_query([
                'client_id'    => self::clientId(),
                'scope'        => self::scopes(),
                'redirect_uri' => (string)$retorno,
                'state'        => $state,
            ]),
            'state' => $state,
        ];
    }

    /**
     * ¿El regreso de la autorización viene de Shopify? `hmac` = HMAC-SHA256
     * en hexadecimal del resto de los parámetros, ordenados y unidos como
     * query, con el secreto de la aplicación.
     */
    public static function hmacQueryValido(array $query)
    {
        $hmac = (string)($query['hmac'] ?? '');
        if ($hmac === '' || self::secreto() === '') return false;
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $calc = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), self::secreto());
        if (hash_equals($calc, strtolower($hmac))) return true;
        // Por si algún valor trae caracteres que los dos lados escapan distinto.
        $partes = [];
        foreach ($query as $k => $v) $partes[] = $k . '=' . (is_array($v) ? implode(',', $v) : $v);
        return hash_equals(hash_hmac('sha256', implode('&', $partes), self::secreto()), strtolower($hmac));
    }

    /**
     * Cambia el `code` por la llave de la tienda.
     * @return array|null ['access_token','scope','refresh_token','expira_en'] o null
     */
    public function canjearCodigo($tienda, $codigo)
    {
        $r = $this->llamarUrl('POST', 'https://' . $tienda . '/admin/oauth/access_token', null, [
            'client_id'     => self::clientId(),
            'client_secret' => self::secreto(),
            'code'          => (string)$codigo,
        ]);
        return $this->leerLlave($r, 'Shopify no devolvió la llave.');
    }

    /**
     * Renueva una llave que caduca. Solo aplica si Shopify la entregó con
     * refresh_token; las llaves offline de siempre no caducan.
     */
    public function renovar($tienda, $refreshToken)
    {
        $r = $this->llamarUrl('POST', 'https://' . $tienda . '/admin/oauth/access_token', null, [
            'client_id'     => self::clientId(),
            'client_secret' => self::secreto(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string)$refreshToken,
        ]);
        return $this->leerLlave($r, 'Shopify no renovó la llave.');
    }

    private function leerLlave($r, $siFalla)
    {
        if (!is_array($r) || empty($r['access_token'])) {
            if ($this->ultimoError === '') {
                $this->ultimoError = is_array($r) && !empty($r['error_description']) ? (string)$r['error_description'] : $siFalla;
            }
            return null;
        }
        $exp = (int)($r['expires_in'] ?? 0);
        return [
            'access_token'  => (string)$r['access_token'],
            'scope'         => (string)($r['scope'] ?? ''),
            'refresh_token' => (string)($r['refresh_token'] ?? ''),
            'expira_en'     => $exp > 0 ? date('Y-m-d H:i:s', time() + $exp) : null,
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

    /** ¿El aviso viene de Shopify? HMAC-SHA256 del cuerpo crudo con el secreto, en base64. */
    public static function firmaValida($cuerpo, $firma)
    {
        $firma = trim((string)$firma);
        if ($firma === '' || self::secreto() === '') return false;
        return hash_equals(base64_encode(hash_hmac('sha256', (string)$cuerpo, self::secreto(), true)), $firma);
    }

    /** @return array|null [['id' => gid, 'topic' => ..., 'url' => ...]] */
    public function avisos($token, $tienda)
    {
        $q = 'query { webhookSubscriptions(first: 100) { nodes { id topic
                endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } } } }';
        $d = $this->graphql($tienda, $token, $q);
        if ($d === null) return null;
        $r = [];
        foreach ((array)($d['webhookSubscriptions']['nodes'] ?? []) as $w) {
            $r[] = ['id' => (string)$w['id'], 'topic' => (string)$w['topic'],
                    'url' => (string)($w['endpoint']['callbackUrl'] ?? '')];
        }
        return $r;
    }

    public function crearAviso($token, $tienda, $evento, $url)
    {
        $q = 'mutation ($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
                webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
                  webhookSubscription { id } userErrors { field message } } }';
        $d = $this->graphql($tienda, $token, $q, ['topic' => (string)$evento,
                            'sub' => ['callbackUrl' => (string)$url, 'format' => 'JSON']]);
        return $this->sinErroresDeUsuario($d, 'webhookSubscriptionCreate');
    }

    public function borrarAviso($token, $tienda, $id)
    {
        $q = 'mutation ($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }';
        return $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, ['id' => (string)$id]), 'webhookSubscriptionDelete') !== null;
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
            if (!in_array($w['topic'], self::EVENTOS, true)) continue;
            if ($w['url'] === $url) { $tengo[$w['topic']] = true; continue; }
            if (strpos($w['url'], '/shopify/aviso') !== false) $this->borrarAviso($token, $tienda, $w['id']);   // dominio viejo
        }
        foreach (self::EVENTOS as $ev) {
            if (isset($tengo[$ev])) continue;
            if ($this->crearAviso($token, $tienda, $ev, $url) !== null) $r['creados']++;
            else $r['errores'][] = $ev . ': ' . $this->ultimoError;
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════
    //  LA TIENDA Y SUS UBICACIONES
    // ════════════════════════════════════════════════════════

    /** name, myshopifyDomain, url, currencyCode */
    public function tienda($token, $tienda)
    {
        $d = $this->graphql($tienda, $token, 'query { shop { name myshopifyDomain currencyCode primaryDomain { url } } }');
        return $d === null ? null : (array)($d['shop'] ?? []);
    }

    /** @return array|null [['id' => número, 'nombre' => ..., 'activa' => bool]] */
    public function ubicaciones($token, $tienda)
    {
        $d = $this->graphql($tienda, $token, 'query { locations(first: 50) { nodes { legacyResourceId name isActive } } }');
        if ($d === null) return null;
        $r = [];
        foreach ((array)($d['locations']['nodes'] ?? []) as $l) {
            $r[] = ['id' => (string)$l['legacyResourceId'], 'nombre' => (string)$l['name'], 'activa' => !empty($l['isActive'])];
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════
    //  PRODUCTOS (cada variante es un renglón)
    // ════════════════════════════════════════════════════════

    /**
     * Lo que se pide de cada variante. La existencia se pide en LA
     * ubicación de la cuenta: el total de todas no sirve para cuadrar con
     * una sucursal.
     */
    private static function camposVariante()
    {
        return 'legacyResourceId title sku price compareAtPrice
                selectedOptions { name value }
                inventoryItem { legacyResourceId tracked
                  inventoryLevel(locationId: $loc) { quantities(names: ["available"]) { name quantity } } }';
    }

    private static function camposProducto()
    {
        return 'legacyResourceId title status onlineStoreUrl
                featuredMedia { preview { image { url } } }';
    }

    /**
     * Todas las variantes de la tienda, de 80 en 80 (Shopify limita el
     * «costo» de cada consulta; con 80 queda holgado).
     * @return array|null la lista de variantes con su producto, o null si no se pudo leer ni la primera página
     */
    public function variantes($token, $tienda, $ubicacion, $tope = 20000)
    {
        $q = 'query ($c: String, $loc: ID!) { productVariants(first: 80, after: $c) {
                pageInfo { hasNextPage endCursor }
                nodes { ' . self::camposVariante() . ' product { ' . self::camposProducto() . ' } } } }';
        $todas = [];
        $cursor = null;
        for ($pag = 1; $pag <= 500 && count($todas) < $tope; $pag++) {
            $d = $this->graphql($tienda, $token, $q, ['c' => $cursor, 'loc' => self::gid('Location', $ubicacion)]);
            if ($d === null) return $pag === 1 ? null : $todas;
            $con = (array)($d['productVariants'] ?? []);
            foreach ((array)($con['nodes'] ?? []) as $v) $todas[] = $v;
            if (empty($con['pageInfo']['hasNextPage'])) break;
            $cursor = (string)$con['pageInfo']['endCursor'];
        }
        return $todas;
    }

    /** Un producto con sus variantes, en el mismo formato que variantes(). null + http 404 si ya no existe. */
    public function productoVariantes($token, $tienda, $productoId, $ubicacion)
    {
        $q = 'query ($id: ID!, $loc: ID!) { product(id: $id) { ' . self::camposProducto() . '
                variants(first: 100) { nodes { ' . self::camposVariante() . ' } } } }';
        $d = $this->graphql($tienda, $token, $q, ['id' => self::gid('Product', $productoId), 'loc' => self::gid('Location', $ubicacion)]);
        if ($d === null) return null;
        if (empty($d['product'])) { $this->ultimoHttp = 404; $this->ultimoError = 'El producto ya no existe en Shopify.'; return null; }
        $p = $d['product'];
        $r = [];
        foreach ((array)($p['variants']['nodes'] ?? []) as $v) {
            $v['product'] = $p;
            unset($v['product']['variants']);
            $r[] = $v;
        }
        return $r;
    }

    /**
     * Pone la existencia de UNA variante en UNA ubicación, tal cual.
     * Si la variante no lleva control de existencias o no está dada de alta
     * en esa ubicación, primero se activa (por eso pide `write_inventory`).
     */
    public function ponerExistencia($token, $tienda, $inventarioId, $ubicacion, $cantidad, $activar = false)
    {
        $item = self::gid('InventoryItem', $inventarioId);
        $loc  = self::gid('Location', $ubicacion);
        if ($activar && !$this->activarInventario($token, $tienda, $item, $loc)) return false;

        $renglon = ['inventoryItemId' => $item, 'locationId' => $loc, 'quantity' => max(0, (int)$cantidad)];
        $input   = ['name' => 'available', 'reason' => 'correction', 'quantities' => [$renglon]];
        $q = 'mutation ($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) {
                userErrors { field message code } } }';
        if (self::version() >= '2026-04') {
            // Desde 2026-04: @idempotent obligatorio y, en lugar de
            // ignoreCompareQuantity, changeFromQuantity: null = sin comparar.
            $input['quantities'][0]['changeFromQuantity'] = null;
            $q = self::conIdempotencia($q, 'inventorySetQuantities');
        } else {
            $input['ignoreCompareQuantity'] = true;
        }
        $ok = $this->mutacion($tienda, $token, $q, ['input' => $input], 'inventorySetQuantities');
        // «No está en esa ubicación»: se activa y se intenta una vez más.
        if (!$ok && !$activar && $this->ultimoHttp !== 401 && stripos($this->ultimoError, 'locat') !== false) {
            return $this->ponerExistencia($token, $tienda, $inventarioId, $ubicacion, $cantidad, true);
        }
        return $ok;
    }

    /** Lleva control de existencias + dada de alta en la ubicación. */
    private function activarInventario($token, $tienda, $item, $loc)
    {
        $q = 'mutation ($id: ID!) { inventoryItemUpdate(id: $id, input: { tracked: true }) { userErrors { field message } } }';
        if (!$this->mutacion($tienda, $token, $q, ['id' => $item], 'inventoryItemUpdate')) return false;
        $q = 'mutation ($item: ID!, $loc: ID!) { inventoryActivate(inventoryItemId: $item, locationId: $loc) {
                inventoryLevel { id } userErrors { field message } } }';
        return $this->mutacion($tienda, $token, $q, ['item' => $item, 'loc' => $loc], 'inventoryActivate');
    }

    /**
     * Corre una mutación y dice si salió bien. Si Shopify contesta que
     * esa mutación ahora exige @idempotent (pasa al subir de versión), se
     * repite una vez con la directiva puesta.
     */
    private function mutacion($tienda, $token, $q, array $variables, $campo)
    {
        $ok = $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, $variables), $campo) !== null;
        if (!$ok && stripos($this->ultimoError, '@idempotent') !== false && stripos($q, '@idempotent') === false) {
            $q  = self::conIdempotencia($q, $campo);
            $ok = $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, $variables), $campo) !== null;
        }
        return $ok;
    }

    /**
     * Agrega `@idempotent(key: "…")` tras los argumentos de la mutación.
     * Llave nueva en cada llamada: los reintentos por 429 de graphql()
     * reusan la misma consulta, así que Shopify no aplica el cambio dos veces.
     */
    public static function conIdempotencia($q, $campo)
    {
        $llave = bin2hex(random_bytes(16));
        return preg_replace('/\b(' . preg_quote($campo, '/') . '\s*\([^)]*\))/',
            '$1 @idempotent(key: "' . $llave . '")', $q, 1);
    }

    // ════════════════════════════════════════════════════════
    //  ÓRDENES
    // ════════════════════════════════════════════════════════

    /**
     * Lo que se pide de cada orden. Sin datos del comprador a propósito:
     * nombre, correo y dirección son «datos protegidos» y Shopify rechaza la
     * consulta ENTERA si la aplicación no tiene ese permiso aprobado.
     */
    private static function camposOrden()
    {
        return 'legacyResourceId name createdAt cancelledAt displayFinancialStatus
                totalPriceSet { shopMoney { amount } }
                lineItems(first: 50) { nodes { name sku quantity variant { legacyResourceId } } }';
    }

    public function orden($token, $tienda, $ordenId)
    {
        $q = 'query ($id: ID!) { order(id: $id) { ' . self::camposOrden() . ' } }';
        $d = $this->graphql($tienda, $token, $q, ['id' => self::gid('Order', $ordenId)]);
        if ($d === null) return null;
        if (empty($d['order'])) { $this->ultimoHttp = 404; $this->ultimoError = 'La orden no existe en Shopify.'; return null; }
        return self::orden_($d['order']);
    }

    /** Las órdenes que cambiaron desde una fecha: la red de seguridad de los avisos. */
    public function ordenesDesde($token, $tienda, $desde, $tope = 600)
    {
        $q = 'query ($c: String, $q: String) { orders(first: 15, after: $c, query: $q, sortKey: UPDATED_AT) {
                pageInfo { hasNextPage endCursor } nodes { ' . self::camposOrden() . ' } } }';
        $filtro = "updated_at:>='" . gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$desde)) . "'";
        $todas = [];
        $cursor = null;
        while (count($todas) < $tope) {
            $d = $this->graphql($tienda, $token, $q, ['c' => $cursor, 'q' => $filtro]);
            if ($d === null) break;
            $con = (array)($d['orders'] ?? []);
            foreach ((array)($con['nodes'] ?? []) as $o) $todas[] = self::orden_($o);
            if (empty($con['pageInfo']['hasNextPage'])) break;
            $cursor = (string)$con['pageInfo']['endCursor'];
        }
        return $todas;
    }

    /** La orden en un arreglo plano, igual venga de una consulta o de otra. */
    private static function orden_(array $o)
    {
        $lineas = [];
        foreach ((array)($o['lineItems']['nodes'] ?? []) as $l) {
            $lineas[] = [
                'variante' => (string)($l['variant']['legacyResourceId'] ?? ''),
                'sku'      => (string)($l['sku'] ?? ''),
                'nombre'   => (string)($l['name'] ?? ''),
                'cantidad' => (float)($l['quantity'] ?? 0),
            ];
        }
        return [
            'id'         => (string)($o['legacyResourceId'] ?? ''),
            'numero'     => ltrim((string)($o['name'] ?? ''), '#'),
            'creada'     => (string)($o['createdAt'] ?? ''),
            'cancelada'  => !empty($o['cancelledAt']),
            'financiero' => (string)($o['displayFinancialStatus'] ?? ''),
            'total'      => (float)($o['totalPriceSet']['shopMoney']['amount'] ?? 0),
            'lineas'     => $lineas,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  PUBLICAR
    // ════════════════════════════════════════════════════════

    /**
     * Crea el producto. Shopify lo crea con UNA variante por omisión; el
     * precio, SKU, código de barras y peso se le ponen después con
     * actualizarVariante().
     * @return array|null ['id', 'variante', 'inventario'] (números)
     */
    public function crearProducto($token, $tienda, array $producto)
    {
        $q = 'mutation ($p: ProductCreateInput!) { productCreate(product: $p) {
                product { legacyResourceId onlineStoreUrl
                  variants(first: 1) { nodes { legacyResourceId inventoryItem { legacyResourceId } } } }
                userErrors { field message } } }';
        $d = $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, ['p' => $producto]), 'productCreate');
        if ($d === null || empty($d['product']['legacyResourceId'])) {
            if ($this->ultimoError === '') $this->ultimoError = 'Shopify no devolvió el producto.';
            return null;
        }
        $v = $d['product']['variants']['nodes'][0] ?? [];
        return [
            'id'         => (string)$d['product']['legacyResourceId'],
            'permalink'  => (string)($d['product']['onlineStoreUrl'] ?? ''),
            'variante'   => (string)($v['legacyResourceId'] ?? ''),
            'inventario' => (string)($v['inventoryItem']['legacyResourceId'] ?? ''),
        ];
    }

    public function actualizarVariante($token, $tienda, $productoId, $varianteId, array $datos)
    {
        $datos['id'] = self::gid('ProductVariant', $varianteId);
        $q = 'mutation ($pid: ID!, $v: [ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId: $pid, variants: $v) {
                userErrors { field message } } }';
        return $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q,
            ['pid' => self::gid('Product', $productoId), 'v' => [$datos]]), 'productVariantsBulkUpdate') !== null;
    }

    /**
     * Sube una foto desde un archivo del servidor. Shopify no recibe el
     * archivo directo: se pide un lugar de subida (stagedUploadsCreate), se
     * sube ahí, y se le da al producto la dirección que devolvió. Así sirve
     * aunque las imágenes de LibertyFin pidan sesión o el servidor sea local.
     */
    public function subirFoto($token, $tienda, $productoId, $rutaArchivo, $mime, $posicion)
    {
        if (!is_file($rutaArchivo) || filesize($rutaArchivo) > 20971520) {
            $this->ultimoError = 'No se encontró la imagen, o pesa más de 20 MB.';
            return false;
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $nombre = 'foto-' . (int)$posicion . '.' . ($ext[$mime] ?? 'jpg');

        $q = 'mutation ($input: [StagedUploadInput!]!) { stagedUploadsCreate(input: $input) {
                stagedTargets { url resourceUrl parameters { name value } } userErrors { field message } } }';
        $d = $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, ['input' => [[
            'filename' => $nombre, 'mimeType' => (string)$mime, 'resource' => 'IMAGE',
            'httpMethod' => 'POST', 'fileSize' => (string)filesize($rutaArchivo),
        ]]]), 'stagedUploadsCreate');
        $t = $d['stagedTargets'][0] ?? null;
        if (!$t || empty($t['url'])) { if ($this->ultimoError === '') $this->ultimoError = 'Shopify no dio dónde subir la foto.'; return false; }

        // El archivo va AL FINAL del formulario: el almacenamiento lo exige así.
        $campos = [];
        foreach ((array)$t['parameters'] as $p) $campos[$p['name']] = $p['value'];
        $campos['file'] = new \CURLFile($rutaArchivo, (string)$mime, $nombre);
        $ch = curl_init((string)$t['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $campos, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $http < 200 || $http >= 300) {
            $this->ultimoError = 'No se pudo subir la foto a Shopify (HTTP ' . $http . ($err ? ': ' . $err : '') . ').';
            return false;
        }

        $q = 'mutation ($p: ProductUpdateInput!, $m: [CreateMediaInput!]) { productUpdate(product: $p, media: $m) {
                product { id } userErrors { field message } } }';
        return $this->sinErroresDeUsuario($this->graphql($tienda, $token, $q, [
            'p' => ['id' => self::gid('Product', $productoId)],
            'm' => [['originalSource' => (string)$t['resourceUrl'], 'mediaContentType' => 'IMAGE']],
        ]), 'productUpdate') !== null;
    }

    // ════════════════════════════════════════════════════════
    //  CIFRAR LA LLAVE (igual que TiendaNube::cifrar, con su propia llave)
    // ════════════════════════════════════════════════════════

    public static function cifrar($texto)
    {
        $texto = (string)$texto;
        if ($texto === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($texto, 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new \RuntimeException('No se pudo cifrar la llave de Shopify.');
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
        if ($base === '') throw new \RuntimeException('Falta la configuración de Shopify.');
        return hash('sha256', 'libertyfin|shopify|' . $base, true);
    }

    // ════════════════════════════════════════════════════════

    /** «gid://shopify/Tipo/123» a partir del número. Si ya es un GID, se deja. */
    public static function gid($tipo, $id)
    {
        $id = (string)$id;
        return strncmp($id, 'gid://', 6) === 0 ? $id : 'gid://shopify/' . $tipo . '/' . $id;
    }

    /**
     * Una consulta GraphQL. Devuelve `data`, o null y el motivo en error().
     * Se reintenta si Shopify dice que vamos muy rápido (429 o THROTTLED).
     */
    public function graphql($tienda, $token, $consulta, array $variables = [])
    {
        if (!self::tiendaValida($tienda)) { $this->ultimoError = 'Tienda de Shopify inválida.'; return null; }
        $url = 'https://' . $tienda . '/admin/api/' . self::version() . '/graphql.json';
        $cuerpo = ['query' => (string)$consulta];
        if ($variables) $cuerpo['variables'] = $variables;

        for ($intento = 1; $intento <= 4; $intento++) {
            $r = $this->llamarUrl('POST', $url, $token, $cuerpo);
            if ($r === null) return null;
            $errores = (array)($r['errors'] ?? []);
            if ($errores) {
                $acelerado = false;
                foreach ($errores as $e) {
                    if (($e['extensions']['code'] ?? '') === 'THROTTLED') $acelerado = true;
                }
                if ($acelerado && $intento < 4) { usleep(1000000 * $intento); continue; }
                $msgs = [];
                foreach ($errores as $e) $msgs[] = is_array($e) ? (string)($e['message'] ?? '') : (string)$e;
                $this->ultimoError = 'Shopify: ' . implode(' · ', array_filter($msgs));
                if (stripos($this->ultimoError, 'access denied') !== false) {
                    $this->ultimoError .= ' (revisa los permisos de la aplicación: ' . self::scopes() . ')';
                }
                return null;
            }
            return (array)($r['data'] ?? []);
        }
        return null;
    }

    /** La parte de una mutación, o null si trae userErrors (y el motivo en error()). */
    private function sinErroresDeUsuario($data, $mutacion)
    {
        if ($data === null) return null;
        $m = (array)($data[$mutacion] ?? []);
        $ue = (array)($m['userErrors'] ?? []);
        if ($ue) {
            $t = [];
            foreach ($ue as $e) {
                $campo = is_array($e['field'] ?? null) ? implode('.', $e['field']) : '';
                $t[] = ($campo !== '' ? $campo . ': ' : '') . (string)($e['message'] ?? '');
            }
            $this->ultimoError = implode(' · ', $t);
            return null;
        }
        return $m;
    }

    /** Una llamada. Devuelve el JSON como arreglo, o null y el motivo en error(). */
    private function llamarUrl($verbo, $url, $token, $cuerpo = null)
    {
        $this->ultimoError = '';
        $this->ultimoHttp  = 0;

        for ($intento = 1; $intento <= 3; $intento++) {
            $cab = ['Accept: application/json', 'User-Agent: LibertyFin'];
            if ($token !== null && $token !== '') $cab[] = 'X-Shopify-Access-Token: ' . $token;
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

            // Nunca al espía: ni la llave ni el secreto.
            $ver = $cuerpo !== null && !isset($cuerpo['client_secret'])
                 ? ' ' . mb_substr(preg_replace('/\s+/', ' ', $op[CURLOPT_POSTFIELDS]), 0, 600) : '';
            $this->ver('>>>', $verbo . ' ' . preg_replace('~^https://[^/]+~', '', $url) . $ver);

            $ch = curl_init($url);
            curl_setopt_array($ch, $op);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->ultimoHttp = $http;

            if ($res === false) {
                $this->ultimoError = 'No se pudo conectar con Shopify: ' . ($err ?: 'sin detalle');
                return null;
            }
            $this->ver('<<<', 'HTTP ' . $http . ' ' . mb_substr((string)$res, 0, 800));

            if (($http === 429 || $http >= 500) && $intento < 3) { usleep(1100000 * $intento); continue; }

            $j = json_decode((string)$res, true);
            if ($http >= 200 && $http < 300) return is_array($j) ? $j : [];
            $this->ultimoError = self::mensajeDeError(is_array($j) ? $j : [], $http, (string)$res);
            return null;
        }
        return null;
    }

    private static function mensajeDeError(array $j, $http, $crudo)
    {
        if ($http === 401) return 'Shopify no aceptó la llave de la tienda (401): hay que volver a conectar.';
        if ($http === 402) return 'Shopify congeló esta tienda por un pago pendiente (402).';
        if ($http === 403) return 'Shopify no deja hacer eso (403): revisa los permisos de la aplicación.';
        if ($http === 404) return 'Shopify no encontró la tienda o lo pedido (404).';
        if ($http === 423) return 'La tienda de Shopify está bloqueada (423).';
        $partes = [];
        foreach (['error_description', 'error', 'errors', 'message'] as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) $partes[] = $j[$k];
        }
        if (!$partes && trim($crudo) !== '') $partes[] = mb_substr(trim(strip_tags($crudo)), 0, 200);
        return ($partes ? implode(' · ', array_unique($partes)) : 'Shopify rechazó la petición') . ' (HTTP ' . $http . ')';
    }

    private function ver($quien, $que)
    {
        if ($this->espia) call_user_func($this->espia, $quien, (string)$que);
    }
}
