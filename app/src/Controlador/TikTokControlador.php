<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaTikTokRepo;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Servicio\TikTok;
use LibertyFin\Servicio\TikTokInventario;
use LibertyFin\Vista\Plantilla;

/**
 * TikTok Shop: conectar la tienda de la empresa y ver cómo va la
 * sincronización con el inventario. Mismo reparto que ShopifyControlador:
 * las credenciales, conectar y los ajustes piden `editar.ajustes`;
 * vincular y sincronizar, `editar.tiktok`.
 *
 * La diferencia con Shopify es el paso de las credenciales: si la empresa
 * usa una app «Seller in-house», primero pega su App Key y App Secret; si
 * no, se usa la app pública de config/integraciones.php.
 */
final class TikTokControlador
{
    const PESTANAS = ['productos' => 'Productos', 'ordenes' => 'Órdenes', 'ajustes' => 'Ajustes'];

    public function index()
    {
        if (!Modulos::exigir('tiktok')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        $t   = Peticion::opcion('t', array_keys(self::PESTANAS), 'productos');
        $ver = Peticion::opcion('ver', ['', 'sin', 'con', 'difiere', 'error'], '');
        $q   = Peticion::texto('q');

        $datos = [
            'titulo'        => 'TikTok Shop',
            'icono'         => 'bolsa',
            'subtitulo'     => 'Tu tienda de TikTok y tus existencias',
            'pestana'       => $t,
            'pestanas'      => self::PESTANAS,
            'ver'           => $ver,
            'buscar'        => $q,
            'token'         => $_SESSION['lf_token'],
            'aviso'         => $_SESSION['lf_aviso'] ?? null,
            'appLista'      => TikTok::activa(),
            'faltan'        => TikTok::faltan(),
            'hayGlobal'     => TikTok::hayAppGlobal(),
            'propias'       => false,
            'puedeConectar' => Permisos::puede('editar.ajustes'),
            'puedeEditar'   => Permisos::puede('editar.tiktok'),
            'cuenta'        => null, 'conectada' => false, 'faltaMigrar' => false,
            'resumen'       => ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0],
            'lista'         => [], 'ordenes' => [], 'productos' => [], 'sucursales' => [],
            'almacenes'     => [],
            'sucursal'      => 0,
            'urlAviso'      => self::urlAviso(),
            'urlRetorno'    => self::urlRetorno(),
        ];
        unset($_SESSION['lf_aviso']);

        try {
            $srv = new TikTokInventario($db);
            $datos['cuenta']    = $srv->cuenta();
            $datos['conectada'] = $srv->conectada();
            $datos['propias']   = $srv->credencialesPropias();
            $datos['sucursal']  = $srv->sucursal();
            $datos['resumen']   = $srv->repo()->resumen();
            $inv = new Inventario($db);
            $datos['sucursales'] = $inv->sucursales();
            if ($t === 'productos') {
                $datos['lista'] = $srv->repo()->listar(['ver' => $ver, 'q' => $q], $datos['sucursal']);
                if ($datos['puedeEditar']) $datos['productos'] = $inv->listado($datos['sucursal']);
            } elseif ($t === 'ordenes') {
                $datos['ordenes'] = $srv->repo()->ordenes(150);
            } elseif ($t === 'ajustes' && $datos['conectada']) {
                // Los almacenes se le preguntan a TikTok solo en esta pestaña.
                $tok = $srv->token();
                $a = $tok !== '' ? $srv->api()->almacenes($tok, $srv->cipher()) : null;
                $datos['almacenes'] = is_array($a) ? $a : [];
            }
        } catch (\Throwable $e) {
            $datos['faltaMigrar'] = self::faltaTabla($e);
            if (!$datos['faltaMigrar']) error_log('[LibertyFin] tiktok: ' . $e->getMessage());
        }

        Plantilla::pagina('tiktok/index', $datos);
    }

    // ════════════════════════════════════════════════════════
    //  LAS CREDENCIALES (app «Seller in-house»)
    // ════════════════════════════════════════════════════════

    /**
     * Guarda el App Key, App Secret y Service ID de la app propia de la
     * empresa. El secreto se cifra antes de tocar la base. Cambiarlas tira
     * la conexión: la llave que había la dio la otra app.
     */
    public function credenciales()
    {
        $this->exigirToken('ajustes');
        if (!Modulos::exigir('tiktok')) return;
        if (!TikTok::activa()) {
            $this->volver('TikTok Shop no está activado en el servidor. Falta: ' . implode(', ', TikTok::faltan()) . '.', 'error', 'ajustes');
        }
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TikTokInventario($db);

        if (!empty($_POST['olvidar'])) {
            $srv->repo()->borrarCredenciales();
            (new RutaTikTokRepo(Conexion::de($GLOBALS['lf_bd_principal'])))->soltar($_SESSION['empresa_db']);
            Auditoria::anota('tiktok.credenciales', 'TikTok Shop', 'propias', 'globales');
            $this->volver(TikTok::hayAppGlobal()
                ? 'Se quitaron las credenciales propias: se usará la aplicación de LibertyFin. Vuelve a conectar la tienda.'
                : 'Se quitaron las credenciales propias.', 'ok', 'ajustes');
        }

        $key = trim((string)($_POST['app_key'] ?? ''));
        $sec = trim((string)($_POST['app_secret'] ?? ''));
        $sid = trim((string)($_POST['service_id'] ?? ''));
        $antes = null;
        try { $antes = $srv->cuenta(); } catch (\Throwable $e) {
            $this->volver(self::faltaTabla($e) ? 'Falta actualizar la base (versión 33). Sal del sistema y vuelve a entrar.'
                : 'No se pudo leer la cuenta: ' . $e->getMessage(), 'error', 'ajustes');
        }
        // El secreto no se vuelve a mostrar: vacío = dejar el que hay.
        if ($sec === '' && $antes && !empty($antes['app_secret']) && $key === (string)$antes['app_key']) {
            $secCifrado = (string)$antes['app_secret'];
        } else {
            if (!preg_match('/^[A-Za-z0-9]{6,64}$/', $key)) $this->volver('Escribe el App Key tal como sale en el Partner Center.', 'error', 'ajustes');
            if (!preg_match('/^[A-Za-z0-9]{10,128}$/', $sec)) $this->volver('Escribe el App Secret tal como sale en el Partner Center.', 'error', 'ajustes');
            $secCifrado = TikTok::cifrar($sec);
        }
        if ($sid !== '' && !preg_match('/^[0-9]{6,32}$/', $sid)) $this->volver('El Service ID son solo números.', 'error', 'ajustes');

        $cambiaApp = !$antes || (string)$antes['app_key'] !== $key;
        $srv->repo()->guardarCredenciales($key, $secCifrado, $sid);
        if ($cambiaApp) (new RutaTikTokRepo(Conexion::de($GLOBALS['lf_bd_principal'])))->soltar($_SESSION['empresa_db']);
        Auditoria::anota('tiktok.credenciales', 'TikTok Shop', $antes['app_key'] ?? null, $key);
        $this->volver('Credenciales guardadas.' . ($cambiaApp ? ' Ahora conecta la tienda.' : ''), 'ok', 'ajustes');
    }

    // ════════════════════════════════════════════════════════
    //  CONECTAR
    // ════════════════════════════════════════════════════════

    /** Manda a autorizar con la app que toque (la propia o la global). */
    public function conectar()
    {
        $this->exigirToken('ajustes');
        if (!Modulos::exigir('tiktok')) return;
        if (!TikTok::activa()) {
            $this->volver('TikTok Shop no está activado en el servidor. Falta: ' . implode(', ', TikTok::faltan()) . '.', 'error', 'ajustes');
        }
        $cuenta = null;
        try { $cuenta = (new TikTokInventario(Conexion::de($_SESSION['empresa_db'])))->cuenta(); }
        catch (\Throwable $e) { $this->volver('Falta actualizar la base (versión 33). Sal del sistema y vuelve a entrar.', 'error', 'ajustes'); }

        $cred = TikTok::credenciales($cuenta);
        if ($cred['app_key'] === '' || $cred['app_secret'] === '') {
            $this->volver('Primero escribe el App Key y el App Secret de tu aplicación de TikTok Shop (abajo, en Ajustes).', 'error', 'ajustes');
        }
        if ($cred['service_id'] === '' && trim((string)(TikTok::config()['url_autorizar'] ?? '')) === '') {
            $this->volver('Falta el Service ID de la aplicación (lo ves en el Partner Center, junto al App Key).', 'error', 'ajustes');
        }
        $a = TikTok::urlAutorizar($cred['service_id']);
        $_SESSION['lf_tt_oauth'] = ['state' => $a['state'], 'empresa_db' => $_SESSION['empresa_db'],
                                    'app_key' => $cred['app_key'], 'creado' => time()];
        header('Location: ' . $a['url']);
        exit;
    }

    /**
     * TikTok regresa aquí con `code` y `state`. La dirección es una sola para
     * todas las empresas (y es la que se escribe en la app como «Redirect
     * URL»): la empresa se sabe por la sesión.
     */
    public function retorno()
    {
        $esperado = $_SESSION['lf_tt_oauth'] ?? null;
        unset($_SESSION['lf_tt_oauth']);

        $codigo = Peticion::texto('code');
        if ($codigo === '') {
            $this->volver('No se conectó: TikTok Shop no devolvió el código de autorización.', 'error', 'ajustes');
        }
        if (!$esperado) {
            $this->volver('Para conectar, usa el botón «Conectar con TikTok Shop» de esta pantalla.', 'error', 'ajustes');
        }
        if (!hash_equals((string)$esperado['state'], Peticion::texto('state'))) {
            $this->volver('La autorización no corresponde a esta sesión. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (time() - (int)$esperado['creado'] > 900) {
            $this->volver('La autorización tardó demasiado. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (($esperado['empresa_db'] ?? '') !== ($_SESSION['empresa_db'] ?? '')) {
            $this->volver('Cambiaste de empresa mientras autorizabas. Vuelve a conectar desde aquí.', 'error', 'ajustes');
        }

        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TikTokInventario($db);
        $cred = TikTok::credenciales($srv->cuenta());
        if ($cred['app_key'] !== (string)$esperado['app_key']) {
            $this->volver('Cambiaron las credenciales mientras autorizabas. Vuelve a conectar.', 'error', 'ajustes');
        }
        $api = new TikTok($cred);
        $llave = $api->canjearCodigo($codigo);
        if (!$llave) $this->volver('No se pudo completar la conexión: ' . $api->error(), 'error', 'ajustes');

        $tiendas = $api->tiendas($llave['access_token']);
        if (!$tiendas) {
            $this->volver('Se autorizó, pero no se pudo leer la tienda: ' . ($api->error() ?: 'la cuenta no tiene tiendas.'), 'error', 'ajustes');
        }
        // Una cuenta puede tener tiendas en varios países: se prefiere la de México.
        $tienda = $tiendas[0];
        foreach ($tiendas as $s) { if (strtoupper($s['region']) === 'MX') { $tienda = $s; break; } }
        if ($tienda['id'] === '' || $tienda['cipher'] === '') {
            $this->volver('TikTok Shop no devolvió el identificador de la tienda. Vuelve a conectar.', 'error', 'ajustes');
        }
        $nombre = $tienda['nombre'] ?: ($llave['vendedor'] ?: 'Tienda ' . $tienda['id']);

        // El almacén de venta por omisión, para empezar. Se cambia en Ajustes.
        $alms = $api->almacenes($llave['access_token'], $tienda['cipher']);
        $alm = ['id' => '', 'nombre' => ''];
        foreach ((array)$alms as $a) { if ($a['activo'] && $a['venta'] && $a['omision']) { $alm = $a; break; } }
        if ($alm['id'] === '') foreach ((array)$alms as $a) { if ($a['activo'] && $a['venta']) { $alm = $a; break; } }

        $rutas = new RutaTikTokRepo(Conexion::de($GLOBALS['lf_bd_principal']));
        if ($rutas->deOtra($tienda['id'], $_SESSION['empresa_db'])) {
            $this->volver('La tienda «' . $nombre . '» ya está conectada a otra empresa en LibertyFin. Desconéctala allá primero.', 'error', 'ajustes');
        }

        $paso = 'leer la cuenta guardada';
        try {
            $antes = $srv->cuenta();
            $paso = 'guardar la tienda en la base de la empresa';
            $srv->repo()->guardarConexion([
                'shop_id'           => $tienda['id'],
                'shop_cipher'       => $tienda['cipher'],
                'nombre'            => $nombre,
                'region'            => $tienda['region'] ?: $llave['region'],
                'vendedor'          => $llave['vendedor'],
                'access_token'      => TikTok::cifrar($llave['access_token']),
                'refresh_token'     => $llave['refresh_token'] !== '' ? TikTok::cifrar($llave['refresh_token']) : '',
                'expira_en'         => $llave['expira_en'],
                'refresh_expira_en' => $llave['refresh_expira_en'],
                'almacen_id'        => $alm['id'],
                'almacen_nombre'    => $alm['nombre'],
                'conectado_por'     => $_SESSION['usuario_nombre'] ?? '',
            ]);
            $paso = 'registrar la tienda en la base principal (lf_ruta_tiktok)';
            $rutas->apuntar($tienda['id'], $_SESSION['empresa_db'], $nombre);
            Auditoria::anota('tiktok.conectar', 'TikTok Shop', $antes && $antes['shop_id'] ? ($antes['nombre'] ?: $antes['shop_id']) : null,
                $nombre . ' (' . $tienda['id'] . ($cred['propias'] ? ', app propia' : '') . ')');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tiktok/retorno (' . $paso . '): ' . $e->getMessage());
            $this->volver(self::faltaTabla($e)
                ? 'Falta actualizar la base (versión 33). Sal del sistema y vuelve a entrar, o aplica config/actualizar-v33.sql. '
                  . 'Detalle al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300)
                : 'No se pudo guardar la conexión al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300),
                'error', 'ajustes');
        }

        $txt = 'Tienda «' . $nombre . '» conectada.' . (count($tiendas) > 1 ? ' (La cuenta tiene ' . count($tiendas) . ' tiendas; se conectó esta.)' : '');
        $srv = new TikTokInventario($db, $api);
        if ($srv->almacen() === '') {
            $this->volver($txt . ' Elige abajo el almacén de TikTok que surte tu tienda y luego trae los productos.', 'aviso', 'ajustes');
        }
        try {
            @set_time_limit(180);
            $r = $srv->importar();
            $txt .= ' Se trajeron ' . $r['productos'] . ' productos'
                  . ($r['vinculadas'] ? ' y ' . $r['vinculadas'] . ' SKU se vincularon solos' : '') . '.';
        } catch (\Throwable $e) {
            $txt .= ' No se pudieron traer los productos todavía: ' . $e->getMessage();
        }
        $this->volver($txt . ' Revisa los vínculos y el almacén (Ajustes) antes de encender «Mandar existencias».', 'ok');
    }

    public function desconectar()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TikTokInventario($db);
        $c   = $srv->cuenta();
        if (!$c || !$c['shop_id']) $this->volver('No hay ninguna tienda conectada.', 'error', 'ajustes');

        $srv->repo()->desconectar();
        (new RutaTikTokRepo(Conexion::de($GLOBALS['lf_bd_principal'])))->soltar($_SESSION['empresa_db']);
        Auditoria::anota('tiktok.desconectar', 'TikTok Shop', $c['nombre'] ?: $c['shop_id'], null);
        $this->volver('Tienda desconectada. Para quitar del todo el permiso, retíralo en tu Seller Center de TikTok Shop. '
            . 'Los vínculos se conservan por si la vuelves a conectar.', 'ok', 'ajustes');
    }

    public function ajustes()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TikTokInventario($db);
        $antes = $srv->cuenta();
        if (!$antes || !$antes['shop_id']) $this->volver('Conecta primero la tienda de TikTok Shop.', 'error', 'ajustes');

        $suc = (int)($_POST['sucursal_id'] ?? 0);
        $validas = array_map('intval', array_column((new Inventario($db))->sucursales(), 'id'));
        if ($suc && !in_array($suc, $validas, true)) $this->volver('Elige una sucursal válida.', 'error', 'ajustes');

        // El almacén se revisa contra lo que dice TikTok: no se acepta un número cualquiera.
        $alm = trim((string)($_POST['almacen_id'] ?? ''));
        $almNombre = (string)$antes['almacen_nombre'];
        if ($alm !== '' && $alm !== (string)$antes['almacen_id']) {
            $tok = $srv->token();
            $lista = $tok !== '' ? $srv->api()->almacenes($tok, $srv->cipher()) : null;
            $hallado = null;
            foreach ((array)$lista as $a) if ($a['id'] === $alm) $hallado = $a;
            if (!$hallado) $this->volver('Ese almacén no existe en tu tienda de TikTok Shop.', 'error', 'ajustes');
            $almNombre = $hallado['nombre'];
        }
        if ($alm === '') $alm = (string)$antes['almacen_id'];

        $reserva = trim(str_replace(',', '', (string)($_POST['reserva'] ?? '0')));
        if ($reserva === '') $reserva = '0';
        if (!is_numeric($reserva) || (float)$reserva < 0 || (float)$reserva > 9999) {
            $this->volver('La reserva va de 0 en adelante.', 'error', 'ajustes');
        }
        $enviar = !empty($_POST['enviar_existencias']);
        if ($enviar && $alm === '') $this->volver('Para mandar existencias, elige primero el almacén de TikTok.', 'error', 'ajustes');

        $srv->repo()->guardarAjustes([
            'sucursal_id' => $suc, 'almacen_id' => $alm, 'almacen_nombre' => $almNombre, 'reserva' => (float)$reserva,
            'enviar_existencias' => $enviar, 'descontar_ventas' => !empty($_POST['descontar_ventas']),
        ]);
        Auditoria::anota('tiktok.ajustes', 'TikTok Shop',
            json_encode(['sucursal' => $antes['sucursal_id'], 'almacen' => $antes['almacen_id'], 'reserva' => $antes['reserva'],
                         'enviar' => $antes['enviar_existencias'], 'descontar' => $antes['descontar_ventas']]),
            json_encode(['sucursal' => $suc, 'almacen' => $alm, 'reserva' => $reserva, 'enviar' => $enviar ? 1 : 0,
                         'descontar' => !empty($_POST['descontar_ventas']) ? 1 : 0]));

        $txt = 'Guardado.';
        // Otro almacén: la existencia que se tenía de TikTok era del anterior.
        if ($alm !== (string)$antes['almacen_id']) {
            try {
                (new TikTokInventario($db))->importar();
                $txt .= ' Se volvieron a leer las existencias de «' . $almNombre . '».';
            } catch (\Throwable $e) {
                $txt .= ' No se pudieron leer las existencias del nuevo almacén: ' . $e->getMessage();
            }
        }
        if ($enviar && (!(int)$antes['enviar_existencias'] || (int)$antes['sucursal_id'] !== $suc
                        || (float)$antes['reserva'] !== (float)$reserva || $alm !== (string)$antes['almacen_id'])) {
            $n = $srv->repo()->marcarTodasPendientes();
            $r = (new TikTokInventario($db))->empujarPendientes(40);
            $txt .= ' Se mandaron ' . $r['enviadas'] . ' de ' . $n . ' existencias a TikTok Shop'
                  . ($r['quedan'] ? '; el resto sale en la siguiente sincronización' : '')
                  . ($r['fallas'] ? ' (' . $r['fallas'] . ' con error, míralas en Productos)' : '') . '.';
        }
        $this->volver($txt, 'ok', 'ajustes');
    }

    // ════════════════════════════════════════════════════════
    //  PRODUCTOS Y SINCRONIZACIÓN
    // ════════════════════════════════════════════════════════

    public function importar()
    {
        $this->exigirToken();
        @set_time_limit(300);
        try {
            $r = (new TikTokInventario(Conexion::de($_SESSION['empresa_db'])))->importar();
            $this->volver('Se trajeron ' . $r['productos'] . ' productos (' . $r['renglones'] . ' SKU)'
                . ($r['vinculadas'] ? '; ' . $r['vinculadas'] . ' se vincularon solos por SKU' : '') . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tiktok/importar: ' . $e->getMessage());
            $this->volver('No se pudieron traer los productos: ' . $e->getMessage(), 'error');
        }
    }

    public function vincular()
    {
        $this->exigirToken();
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TikTokInventario($db);
        $id  = (int)($_POST['sku_id'] ?? 0);

        $pid = (int)($_POST['producto_id'] ?? 0);
        $texto = trim((string)($_POST['producto_buscar'] ?? ''));
        if (!$pid && $texto !== '' && empty($_POST['soltar'])) {
            $p = (new Inventario($db))->buscarProducto($texto);
            if (!$p) $this->volver('No encontré un solo producto con «' . $texto . '». Escribe su SKU o elígelo de la lista.', 'error');
            $pid = (int)$p['id'];
        }
        if (!empty($_POST['soltar'])) $pid = 0;
        if (!$pid && empty($_POST['soltar'])) $this->volver('Elige el producto.', 'error');

        try {
            $v = $srv->vincular($id, $pid);
            Auditoria::anota('tiktok.vincular', $v['producto_tt'] . '/' . $v['sku_tt'], $v['producto_id'] ?: null, $pid ?: null);
            if ($pid && $srv->enviaExistencias()) $srv->empujarPendientes(5);
            $this->volver($pid ? 'Vinculado.' : 'Se quitó el vínculo.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        }
    }

    public function sincronizar()
    {
        $this->exigirToken();
        @set_time_limit(180);
        try {
            $r = (new TikTokInventario(Conexion::de($_SESSION['empresa_db'])))->sincronizar(100);
            $partes = [$r['revisadas'] . ' órdenes revisadas'];
            if ($r['aplicadas']) $partes[] = $r['aplicadas'] . ' descontadas';
            if ($r['devueltas']) $partes[] = $r['devueltas'] . ' canceladas que regresaron mercancía';
            if ($r['enviadas'])  $partes[] = $r['enviadas'] . ' existencias mandadas';
            if ($r['fallas'])    $partes[] = $r['fallas'] . ' con error';
            $this->volver('Listo: ' . implode(', ', $partes) . ($r['quedan'] ? '. Quedan más pendientes: vuelve a sincronizar.' : '.'),
                          $r['fallas'] ? 'aviso' : 'ok', Peticion::opcion('t', array_keys(self::PESTANAS), 'productos'));
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tiktok/sincronizar: ' . $e->getMessage());
            $this->volver('No se pudo sincronizar: ' . $e->getMessage(), 'error');
        }
    }

    // ════════════════════════════════════════════════════════

    /** Las direcciones públicas. Se pueden fijar con `dominio` en el bloque de config. */
    public static function urlAviso()   { return self::base() . '/tiktok/aviso'; }
    public static function urlRetorno() { return self::base() . '/tiktok/retorno'; }

    private static function base()
    {
        $d = rtrim(trim((string)(TikTok::config()['dominio'] ?? '')), '/');
        if ($d !== '') return preg_match('~^https?://~', $d) ? $d : 'https://' . $d;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'TU-DOMINIO');
    }

    private static function faltaTabla(\Throwable $e)
    {
        $m = $e->getMessage();
        return strpos($m, "doesn't exist") !== false || strpos($m, '42S02') !== false
            || strpos($m, 'Unknown column') !== false || strpos($m, '42S22') !== false;
    }

    private function exigirToken($pestana = 'productos')
    {
        $ok = !empty($_SESSION['lf_token']) && !empty($_POST['token'])
           && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
        if (!$ok) $this->volver('No se pudo verificar el formulario. Recarga la página.', 'error', $pestana);
    }

    private function volver($texto, $tipo, $pestana = 'productos')
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $q = ['t' => $pestana];
        foreach (['ver', 'q'] as $k) if (!empty($_POST['volver_' . $k])) $q[$k] = (string)$_POST['volver_' . $k];
        header('Location: /tiktok?' . http_build_query($q));
        exit;
    }
}
