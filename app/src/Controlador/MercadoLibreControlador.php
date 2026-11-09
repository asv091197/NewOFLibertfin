<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaMeliRepo;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Servicio\MeliInventario;
use LibertyFin\Servicio\MercadoLibre;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Mercado Libre: conectar la cuenta de vendedor de la empresa y ver cómo
 * va la sincronización con el inventario.
 *
 * Hablar con Mercado Libre es cosa de Servicio\MercadoLibre; qué se
 * descuenta y qué se manda, de Servicio\MeliInventario. Aquí se arma la
 * pantalla y se decide a dónde regresa el usuario.
 *
 * CONECTAR ES DE ADMINISTRADOR
 *
 * Quien conecta le da a LibertyFin permiso de escribir en la cuenta de
 * vendedor de la empresa. Eso pide `editar.ajustes` (ver public/index.php),
 * no solo `editar.mercadolibre`: el encargado de inventario puede vincular
 * publicaciones, pero no decidir con qué cuenta vende la empresa.
 */
final class MercadoLibreControlador
{
    const PESTANAS = ['publicaciones' => 'Publicaciones', 'ordenes' => 'Órdenes', 'ajustes' => 'Ajustes'];

    public function index()
    {
        if (!Modulos::exigir('mercadolibre')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        $t   = Peticion::opcion('t', array_keys(self::PESTANAS), 'publicaciones');
        $ver = Peticion::opcion('ver', ['', 'sin', 'con', 'difiere', 'error'], '');
        $q   = Peticion::texto('q');

        $datos = [
            'titulo'      => 'Mercado Libre',
            'icono'       => 'bolsa',
            'subtitulo'   => 'Ventas en línea y existencias',
            'pestana'     => $t,
            'pestanas'    => self::PESTANAS,
            'ver'         => $ver,
            'buscar'      => $q,
            'token'       => $_SESSION['lf_token'],
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
            'appLista'    => MercadoLibre::activa(),
            'faltan'      => MercadoLibre::faltan(),
            'puedeEditar' => Permisos::puede('editar.mercadolibre'),
            'puedeConectar' => Permisos::puede('editar.ajustes'),
            'cuenta'      => null, 'conectada' => false, 'faltaMigrar' => false,
            'resumen'     => ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0],
            'lista'       => [], 'ordenes' => [], 'productos' => [], 'sucursales' => [],
            'sucursal'    => 0,
            'urlAviso'    => self::urlAviso(),
        ];
        unset($_SESSION['lf_aviso']);

        try {
            $srv = new MeliInventario($db);
            $datos['cuenta']    = $srv->cuenta();
            $datos['conectada'] = $srv->conectada();
            $datos['sucursal']  = $srv->sucursal();
            $datos['resumen']   = $srv->repo()->resumen();
            $inv = new Inventario($db);
            $datos['sucursales'] = $inv->sucursales();
            if ($t === 'publicaciones') {
                $datos['lista'] = $srv->repo()->listar(['ver' => $ver, 'q' => $q], $datos['sucursal']);
                if ($datos['puedeEditar']) $datos['productos'] = $inv->listado($datos['sucursal']);
            } elseif ($t === 'ordenes') {
                $datos['ordenes'] = $srv->repo()->ordenes(150);
            }
        } catch (\Throwable $e) {
            $datos['faltaMigrar'] = self::faltaTabla($e);
            if (!$datos['faltaMigrar']) error_log('[LibertyFin] mercadolibre: ' . $e->getMessage());
        }

        Plantilla::pagina('mercadolibre/index', $datos);
    }

    // ════════════════════════════════════════════════════════
    //  CONECTAR
    // ════════════════════════════════════════════════════════

    /** Manda al administrador a Mercado Libre a autorizar. */
    public function conectar()
    {
        $this->exigirToken('ajustes');
        if (!Modulos::exigir('mercadolibre')) return;
        if (!MercadoLibre::activa()) {
            $this->volver('La aplicación de Mercado Libre no está configurada en el servidor. Falta: '
                . implode(', ', MercadoLibre::faltan()) . '.', 'error', 'ajustes');
        }
        $a = MercadoLibre::urlAutorizar();
        // Se recuerda a qué empresa iba: si mientras tanto alguien cambia
        // de empresa en la misma sesión, la cuenta no se conecta a la otra.
        $_SESSION['lf_meli_oauth'] = [
            'state'       => $a['state'],
            'verificador' => $a['verificador'],
            'empresa_db'  => $_SESSION['empresa_db'],
            'creado'      => time(),
        ];
        header('Location: ' . $a['url']);
        exit;
    }

    /**
     * Mercado Libre regresa aquí con `code` y `state` (o con `error` si
     * la persona no autorizó). La dirección tiene que ser EXACTAMENTE la
     * que se dio de alta en la aplicación, por eso es una sola para todas
     * las empresas y la empresa se sabe por la sesión.
     */
    public function retorno()
    {
        $esperado = $_SESSION['lf_meli_oauth'] ?? null;
        unset($_SESSION['lf_meli_oauth']);   // un solo uso, salga como salga

        $error = Peticion::texto('error');
        if ($error !== '') {
            $this->volver($error === 'access_denied'
                ? 'No se conectó: en Mercado Libre se eligió no autorizar a LibertyFin.'
                : 'Mercado Libre no autorizó la conexión (' . $error . ').', 'error', 'ajustes');
        }

        $state = Peticion::texto('state');
        if (!$esperado || $state === '' || !hash_equals((string)$esperado['state'], $state)) {
            $this->volver('La autorización no corresponde a esta sesión. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (time() - (int)$esperado['creado'] > 900) {
            $this->volver('La autorización tardó demasiado. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (($esperado['empresa_db'] ?? '') !== ($_SESSION['empresa_db'] ?? '')) {
            $this->volver('Cambiaste de empresa mientras autorizabas. Vuelve a conectar desde aquí.', 'error', 'ajustes');
        }
        $codigo = Peticion::texto('code');
        if ($codigo === '') $this->volver('Mercado Libre no devolvió el código de autorización.', 'error', 'ajustes');

        $db  = Conexion::de($_SESSION['empresa_db']);
        $api = new MercadoLibre();
        $llaves = $api->canjearCodigo($codigo, (string)$esperado['verificador']);
        if (!$llaves) $this->volver('No se pudo completar la conexión: ' . $api->error(), 'error', 'ajustes');

        $yo = $api->yo($llaves['access_token']);
        if (!is_array($yo) || empty($yo['id'])) {
            $this->volver('Se autorizó, pero no se pudo leer la cuenta: ' . $api->error(), 'error', 'ajustes');
        }
        $userId = (string)$yo['id'];

        $rutas = new RutaMeliRepo(Conexion::de($GLOBALS['lf_bd_principal']));
        if ($rutas->deOtra($userId, $_SESSION['empresa_db'])) {
            // La misma cuenta en dos empresas descontaría cada venta dos veces.
            $this->volver('La cuenta «' . ($yo['nickname'] ?? $userId) . '» ya está conectada a otra empresa '
                . 'en LibertyFin. Desconéctala allá primero, o entra a Mercado Libre con la cuenta de esta empresa.',
                'error', 'ajustes');
        }

        $paso = 'leer la cuenta guardada';
        try {
            $srv = new MeliInventario($db, $api);
            $antes = $srv->cuenta();
            $paso = 'guardar la cuenta en la base de la empresa';
            $srv->repo()->guardarConexion([
                'meli_user_id'  => $userId,
                'nickname'      => (string)($yo['nickname'] ?? ''),
                'site_id'       => (string)($yo['site_id'] ?? ''),
                'access_token'  => MercadoLibre::cifrar($llaves['access_token']),
                'refresh_token' => MercadoLibre::cifrar($llaves['refresh_token']),
                'expira_en'     => $llaves['expira_en'],
                'conectado_por' => $_SESSION['usuario_nombre'] ?? '',
            ]);
            $paso = 'registrar la cuenta en la base principal (lf_ruta_meli)';
            $rutas->apuntar($userId, $_SESSION['empresa_db'], (string)($yo['nickname'] ?? ''));
            Auditoria::anota('meli.conectar', 'Mercado Libre',
                $antes && $antes['meli_user_id'] ? ($antes['nickname'] ?: $antes['meli_user_id']) : null,
                ($yo['nickname'] ?? '') . ' (' . $userId . ')');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] meli/retorno (' . $paso . '): ' . $e->getMessage());
            // Solo administradores llegan aquí, y el mensaje no lleva llaves
            // (van cifradas y PDO no repite los valores): se enseña el motivo
            // real para no tener que ir a buscar el log del servidor.
            $this->volver(self::faltaTabla($e)
                ? 'Falta actualizar la base (versión 30). Sal del sistema y vuelve a entrar, o aplica config/actualizar-v30.sql. '
                  . 'Detalle al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300)
                : 'No se pudo guardar la conexión al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300),
                'error', 'ajustes');
        }

        // Lo primero que alguien quiere ver al conectar son sus publicaciones.
        $txt = 'Cuenta «' . ($yo['nickname'] ?? $userId) . '» conectada.';
        try {
            @set_time_limit(180);
            $r = (new MeliInventario($db, $api))->importar();
            $txt .= ' Se trajeron ' . $r['publicaciones'] . ' publicaciones'
                  . ($r['vinculadas'] ? ' y ' . $r['vinculadas'] . ' se vincularon solas por SKU' : '') . '.';
        } catch (\Throwable $e) {
            $txt .= ' No se pudieron traer las publicaciones todavía: ' . $e->getMessage();
        }
        $this->volver($txt . ' Revisa los vínculos antes de encender «Mandar existencias».', 'ok');
    }

    public function desconectar()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new MeliInventario($db);
        $c   = $srv->cuenta();
        if (!$c) $this->volver('No hay ninguna cuenta conectada.', 'error', 'ajustes');

        // Se intenta quitar el permiso también del lado de Mercado Libre.
        // Si no se puede, las llaves se borran de todas formas: sin ellas
        // LibertyFin ya no puede hacer nada con la cuenta.
        try {
            $tok = $srv->token();
            if ($tok !== '') $srv->api()->revocar($tok, $c['meli_user_id']);
        } catch (\Throwable $e) { /* se sigue */ }

        $srv->repo()->desconectar();
        (new RutaMeliRepo(Conexion::de($GLOBALS['lf_bd_principal'])))->soltar($_SESSION['empresa_db']);
        Auditoria::anota('meli.desconectar', 'Mercado Libre', $c['nickname'] ?: $c['meli_user_id'], null);
        $this->volver('Cuenta desconectada. Los vínculos de publicaciones se conservan por si la vuelves a conectar.', 'ok', 'ajustes');
    }

    public function ajustes()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new MeliInventario($db);
        $antes = $srv->cuenta();
        if (!$antes) $this->volver('Conecta primero la cuenta de Mercado Libre.', 'error', 'ajustes');

        $suc = (int)($_POST['sucursal_id'] ?? 0);
        $validas = array_map('intval', array_column((new Inventario($db))->sucursales(), 'id'));
        if ($suc && !in_array($suc, $validas, true)) $this->volver('Elige una sucursal válida.', 'error', 'ajustes');

        $reserva = trim(str_replace(',', '', (string)($_POST['reserva'] ?? '0')));
        if ($reserva === '') $reserva = '0';
        if (!is_numeric($reserva) || (float)$reserva < 0 || (float)$reserva > 9999) {
            $this->volver('La reserva va de 0 en adelante.', 'error', 'ajustes');
        }
        $enviar = !empty($_POST['enviar_existencias']);
        $srv->repo()->guardarAjustes([
            'sucursal_id' => $suc, 'reserva' => (float)$reserva,
            'enviar_existencias' => $enviar, 'descontar_ventas' => !empty($_POST['descontar_ventas']),
        ]);
        Auditoria::anota('meli.ajustes', 'Mercado Libre',
            json_encode(['sucursal' => $antes['sucursal_id'], 'reserva' => $antes['reserva'],
                         'enviar' => $antes['enviar_existencias'], 'descontar' => $antes['descontar_ventas']]),
            json_encode(['sucursal' => $suc, 'reserva' => $reserva, 'enviar' => $enviar ? 1 : 0,
                         'descontar' => !empty($_POST['descontar_ventas']) ? 1 : 0]));

        // Al ENCENDER el envío, o al cambiar de dónde sale, todo lo
        // vinculado tiene que ponerse al día con la cantidad nueva.
        $txt = 'Guardado.';
        if ($enviar && (!(int)$antes['enviar_existencias'] || (int)$antes['sucursal_id'] !== $suc
                        || (float)$antes['reserva'] !== (float)$reserva)) {
            $n = $srv->repo()->marcarTodasPendientes();
            $r = (new MeliInventario($db))->empujarPendientes(40);
            $txt .= ' Se mandaron ' . $r['enviadas'] . ' de ' . $n . ' existencias a Mercado Libre'
                  . ($r['quedan'] ? '; el resto sale en la siguiente sincronización' : '')
                  . ($r['fallas'] ? ' (' . $r['fallas'] . ' con error, míralas en Publicaciones)' : '') . '.';
        }
        $this->volver($txt, 'ok', 'ajustes');
    }

    // ════════════════════════════════════════════════════════
    //  PUBLICACIONES Y SINCRONIZACIÓN
    // ════════════════════════════════════════════════════════

    public function importar()
    {
        $this->exigirToken();
        @set_time_limit(180);
        try {
            $r = (new MeliInventario(Conexion::de($_SESSION['empresa_db'])))->importar();
            $this->volver('Se trajeron ' . $r['publicaciones'] . ' publicaciones (' . $r['renglones'] . ' renglones con sus variaciones)'
                . ($r['vinculadas'] ? '; ' . $r['vinculadas'] . ' se vincularon solas por SKU' : '') . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] meli/importar: ' . $e->getMessage());
            $this->volver('No se pudieron traer las publicaciones: ' . $e->getMessage(), 'error');
        }
    }

    /** Vincular (o soltar) una publicación con un producto. */
    public function vincular()
    {
        $this->exigirToken();
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new MeliInventario($db);
        $id  = (int)($_POST['publicacion_id'] ?? 0);

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
            $pub = $srv->vincular($id, $pid);
            Auditoria::anota('meli.vincular', $pub['item_id'] . ($pub['variacion_id'] ? '/' . $pub['variacion_id'] : ''),
                $pub['producto_id'] ?: null, $pid ?: null);
            if ($pid && $srv->enviaExistencias()) $srv->empujarPendientes(5);
            $this->volver($pid ? 'Vinculada.' : 'Se quitó el vínculo.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        }
    }

    /** Revisar órdenes y mandar lo pendiente, ahora. */
    public function sincronizar()
    {
        $this->exigirToken();
        @set_time_limit(180);
        try {
            $r = (new MeliInventario(Conexion::de($_SESSION['empresa_db'])))->sincronizar(100);
            $partes = [$r['revisadas'] . ' órdenes revisadas'];
            if ($r['aplicadas'])  $partes[] = $r['aplicadas'] . ' descontadas';
            if ($r['devueltas'])  $partes[] = $r['devueltas'] . ' canceladas que regresaron mercancía';
            if ($r['enviadas'])   $partes[] = $r['enviadas'] . ' existencias mandadas';
            if ($r['fallas'])     $partes[] = $r['fallas'] . ' con error';
            $this->volver('Listo: ' . implode(', ', $partes) . ($r['quedan'] ? '. Quedan más pendientes: vuelve a sincronizar.' : '.'),
                          $r['fallas'] ? 'aviso' : 'ok', Peticion::opcion('t', array_keys(self::PESTANAS), 'publicaciones'));
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] meli/sincronizar: ' . $e->getMessage());
            $this->volver('No se pudo sincronizar: ' . $e->getMessage(), 'error');
        }
    }

    // ════════════════════════════════════════════════════════

    /** La dirección pública de los avisos, para darla de alta en la aplicación. */
    public static function urlAviso()
    {
        $r = MercadoLibre::retorno();
        $p = $r !== '' ? parse_url($r) : null;
        if ($p && !empty($p['host'])) {
            return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/mercadolibre/aviso';
        }
        return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'TU-DOMINIO') . '/mercadolibre/aviso';
    }

    private static function faltaTabla(\Throwable $e)
    {
        $m = $e->getMessage();
        return strpos($m, "doesn't exist") !== false || strpos($m, '42S02') !== false
            || strpos($m, 'Unknown column') !== false || strpos($m, '42S22') !== false;
    }

    private function exigirToken($pestana = 'publicaciones')
    {
        $ok = !empty($_SESSION['lf_token']) && !empty($_POST['token'])
           && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
        if (!$ok) $this->volver('No se pudo verificar el formulario. Recarga la página.', 'error', $pestana);
    }

    private function volver($texto, $tipo, $pestana = 'publicaciones')
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $q = ['t' => $pestana];
        foreach (['ver', 'q'] as $k) if (!empty($_POST['volver_' . $k])) $q[$k] = (string)$_POST['volver_' . $k];
        header('Location: /mercadolibre?' . http_build_query($q));
        exit;
    }
}
