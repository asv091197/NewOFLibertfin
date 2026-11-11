<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaTiendaNubeRepo;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Servicio\TiendaNube;
use LibertyFin\Servicio\TnInventario;
use LibertyFin\Servicio\TnPublicar;
use LibertyFin\Vista\Plantilla;

/**
 * Tienda Nube: conectar la tienda de la empresa y ver cómo va la
 * sincronización con el inventario. Mismo reparto que
 * MercadoLibreControlador: conectar pide `editar.ajustes`; vincular,
 * sincronizar y publicar, `editar.tiendanube`.
 */
final class TiendaNubeControlador
{
    const PESTANAS = ['productos' => 'Productos', 'ordenes' => 'Órdenes', 'ajustes' => 'Ajustes'];

    public function index()
    {
        if (!Modulos::exigir('tiendanube')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        $t   = Peticion::opcion('t', array_keys(self::PESTANAS), 'productos');
        $ver = Peticion::opcion('ver', ['', 'sin', 'con', 'difiere', 'error'], '');
        $q   = Peticion::texto('q');

        $datos = [
            'titulo'      => 'Tienda Nube',
            'icono'       => 'bolsa',
            'subtitulo'   => 'Tu tienda en línea y tus existencias',
            'pestana'     => $t,
            'pestanas'    => self::PESTANAS,
            'ver'         => $ver,
            'buscar'      => $q,
            'token'       => $_SESSION['lf_token'],
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
            'appLista'    => TiendaNube::activa(),
            'faltan'      => TiendaNube::faltan(),
            'puedeEditar' => Permisos::puede('editar.tiendanube'),
            'puedeConectar' => Permisos::puede('editar.ajustes'),
            'cuenta'      => null, 'conectada' => false, 'faltaMigrar' => false,
            'resumen'     => ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0],
            'lista'       => [], 'ordenes' => [], 'productos' => [], 'sucursales' => [],
            'sucursal'    => 0,
            'urlAviso'    => self::urlAviso(),
            'urlRetorno'  => self::urlRetorno(),
        ];
        unset($_SESSION['lf_aviso']);

        try {
            $srv = new TnInventario($db);
            $datos['cuenta']    = $srv->cuenta();
            $datos['conectada'] = $srv->conectada();
            $datos['sucursal']  = $srv->sucursal();
            $datos['resumen']   = $srv->repo()->resumen();
            $inv = new Inventario($db);
            $datos['sucursales'] = $inv->sucursales();
            if ($t === 'productos') {
                $datos['lista'] = $srv->repo()->listar(['ver' => $ver, 'q' => $q], $datos['sucursal']);
                if ($datos['puedeEditar']) $datos['productos'] = $inv->listado($datos['sucursal']);
            } elseif ($t === 'ordenes') {
                $datos['ordenes'] = $srv->repo()->ordenes(150);
            }
        } catch (\Throwable $e) {
            $datos['faltaMigrar'] = self::faltaTabla($e);
            if (!$datos['faltaMigrar']) error_log('[LibertyFin] tiendanube: ' . $e->getMessage());
        }

        Plantilla::pagina('tiendanube/index', $datos);
    }

    // ════════════════════════════════════════════════════════
    //  CONECTAR
    // ════════════════════════════════════════════════════════

    public function conectar()
    {
        $this->exigirToken('ajustes');
        if (!Modulos::exigir('tiendanube')) return;
        if (!TiendaNube::activa()) {
            $this->volver('La aplicación de Tienda Nube no está configurada en el servidor. Falta: '
                . implode(', ', TiendaNube::faltan()) . '.', 'error', 'ajustes');
        }
        $a = TiendaNube::urlAutorizar();
        $_SESSION['lf_tn_oauth'] = ['state' => $a['state'], 'empresa_db' => $_SESSION['empresa_db'], 'creado' => time()];
        header('Location: ' . $a['url']);
        exit;
    }

    /**
     * Tienda Nube regresa aquí con `code` (y el `state` que se mandó). La
     * dirección es la que se dio de alta en el portal de socios, una sola
     * para todas las empresas: la empresa se sabe por la sesión.
     */
    public function retorno()
    {
        $esperado = $_SESSION['lf_tn_oauth'] ?? null;
        unset($_SESSION['lf_tn_oauth']);

        $codigo = Peticion::texto('code');
        if ($codigo === '') {
            $this->volver('No se conectó: Tienda Nube no devolvió el código de autorización.', 'error', 'ajustes');
        }
        if (!$esperado) {
            // Pasa cuando se instala la aplicación desde la tienda de aplicaciones de
            // Tienda Nube, sin pasar por el botón de aquí: no hay cómo saber la empresa.
            $this->volver('Para conectar, usa el botón «Conectar con Tienda Nube» de esta pantalla.', 'error', 'ajustes');
        }
        $state = Peticion::texto('state');
        if ($state !== '' && !hash_equals((string)$esperado['state'], $state)) {
            $this->volver('La autorización no corresponde a esta sesión. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (time() - (int)$esperado['creado'] > 900) {
            $this->volver('La autorización tardó demasiado. Vuelve a dar clic en «Conectar».', 'error', 'ajustes');
        }
        if (($esperado['empresa_db'] ?? '') !== ($_SESSION['empresa_db'] ?? '')) {
            $this->volver('Cambiaste de empresa mientras autorizabas. Vuelve a conectar desde aquí.', 'error', 'ajustes');
        }

        $db  = Conexion::de($_SESSION['empresa_db']);
        $api = new TiendaNube();
        $llave = $api->canjearCodigo($codigo);
        if (!$llave) $this->volver('No se pudo completar la conexión: ' . $api->error(), 'error', 'ajustes');
        $tid = $llave['store_id'];

        $tienda = $api->tienda($llave['access_token'], $tid);
        if (!is_array($tienda)) {
            $this->volver('Se autorizó, pero no se pudo leer la tienda: ' . $api->error(), 'error', 'ajustes');
        }
        $idioma = (string)($tienda['main_language'] ?? 'es') ?: 'es';
        $nombre = TiendaNube::texto($tienda['name'] ?? '', $idioma) ?: ('Tienda ' . $tid);
        $dominio = (string)($tienda['url_with_protocol'] ?? '');
        if ($dominio === '' && !empty($tienda['original_domain'])) $dominio = 'https://' . $tienda['original_domain'];

        $rutas = new RutaTiendaNubeRepo(Conexion::de($GLOBALS['lf_bd_principal']));
        if ($rutas->deOtra($tid, $_SESSION['empresa_db'])) {
            $this->volver('La tienda «' . $nombre . '» ya está conectada a otra empresa en LibertyFin. '
                . 'Desconéctala allá primero.', 'error', 'ajustes');
        }

        $paso = 'leer la cuenta guardada';
        try {
            $srv = new TnInventario($db, $api);
            $antes = $srv->cuenta();
            $paso = 'guardar la tienda en la base de la empresa';
            $srv->repo()->guardarConexion([
                'store_id'      => $tid,
                'nombre'        => $nombre,
                'url'           => $dominio,
                'idioma'        => $idioma,
                'moneda'        => (string)($tienda['main_currency'] ?? ''),
                'access_token'  => TiendaNube::cifrar($llave['access_token']),
                'conectado_por' => $_SESSION['usuario_nombre'] ?? '',
            ]);
            $paso = 'registrar la tienda en la base principal (lf_ruta_tn)';
            $rutas->apuntar($tid, $_SESSION['empresa_db'], $nombre);
            Auditoria::anota('tn.conectar', 'Tienda Nube', $antes && $antes['store_id'] ? ($antes['nombre'] ?: $antes['store_id']) : null,
                $nombre . ' (' . $tid . ')');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tn/retorno (' . $paso . '): ' . $e->getMessage());
            $this->volver(self::faltaTabla($e)
                ? 'Falta actualizar la base (versión 31). Sal del sistema y vuelve a entrar, o aplica config/actualizar-v31.sql. '
                  . 'Detalle al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300)
                : 'No se pudo guardar la conexión al ' . $paso . ': ' . mb_substr($e->getMessage(), 0, 300),
                'error', 'ajustes');
        }

        $txt = 'Tienda «' . $nombre . '» conectada.';
        $srv = new TnInventario($db, $api);
        $txt .= ' ' . self::registrarAvisos($srv);
        try {
            @set_time_limit(180);
            $r = $srv->importar();
            $txt .= ' Se trajeron ' . $r['productos'] . ' productos'
                  . ($r['vinculadas'] ? ' y ' . $r['vinculadas'] . ' variantes se vincularon solas por SKU' : '') . '.';
        } catch (\Throwable $e) {
            $txt .= ' No se pudieron traer los productos todavía: ' . $e->getMessage();
        }
        $this->volver($txt . ' Revisa los vínculos antes de encender «Mandar existencias».', 'ok');
    }

    /**
     * Da de alta los avisos en la tienda. Tienda Nube no acepta direcciones
     * locales ni sin https: en ese caso se deja dicho y se depende de
     * «Sincronizar ahora» o de la tarea programada.
     */
    private static function registrarAvisos(TnInventario $srv)
    {
        $url = self::urlAviso();
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (strncasecmp($url, 'https://', 8) !== 0 || $host === 'localhost' || strpos($host, '.') === false
            || preg_match('/^(127\.|10\.|192\.168\.)/', $host)) {
            $nota = 'Los avisos al instante necesitan una dirección pública con https (' . $url . ').';
            $srv->repo()->anotarAvisos(false, $nota);
            return $nota . ' Mientras, usa «Sincronizar ahora» o la tarea programada.';
        }
        $tok = $srv->token();
        $r = $srv->api()->asegurarAvisos($tok, $srv->tiendaId(), $url);
        $ok = !$r['errores'];
        $srv->repo()->anotarAvisos($ok, $ok ? null : implode(' · ', $r['errores']));
        return $ok ? 'Avisos al instante activos.' : 'No se pudieron dar de alta todos los avisos: ' . implode(' · ', $r['errores']) . '.';
    }

    public function avisos()
    {
        $this->exigirToken('ajustes');
        $srv = new TnInventario(Conexion::de($_SESSION['empresa_db']));
        if (!$srv->conectada()) $this->volver('Conecta primero la tienda.', 'error', 'ajustes');
        $txt = self::registrarAvisos($srv);
        $this->volver($txt, strpos($txt, 'activos') !== false ? 'ok' : 'aviso', 'ajustes');
    }

    public function desconectar()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TnInventario($db);
        $c   = $srv->cuenta();
        if (!$c) $this->volver('No hay ninguna tienda conectada.', 'error', 'ajustes');

        // Se borran los avisos de la tienda; si no se puede, la llave se tira igual.
        try {
            $tok = $srv->token();
            if ($tok !== '') {
                foreach ((array)$srv->api()->avisos($tok, $c['store_id']) as $w) {
                    if (strpos((string)($w['url'] ?? ''), '/tiendanube/aviso') !== false) {
                        $srv->api()->borrarAviso($tok, $c['store_id'], $w['id'] ?? 0);
                    }
                }
            }
        } catch (\Throwable $e) { /* se sigue */ }

        $srv->repo()->desconectar();
        (new RutaTiendaNubeRepo(Conexion::de($GLOBALS['lf_bd_principal'])))->soltar($_SESSION['empresa_db']);
        Auditoria::anota('tn.desconectar', 'Tienda Nube', $c['nombre'] ?: $c['store_id'], null);
        $this->volver('Tienda desconectada. Para quitar del todo el permiso, desinstala LibertyFin en tu panel de Tienda Nube → Aplicaciones. '
            . 'Los vínculos se conservan por si la vuelves a conectar.', 'ok', 'ajustes');
    }

    public function ajustes()
    {
        $this->exigirToken('ajustes');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TnInventario($db);
        $antes = $srv->cuenta();
        if (!$antes) $this->volver('Conecta primero la tienda de Tienda Nube.', 'error', 'ajustes');

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
        Auditoria::anota('tn.ajustes', 'Tienda Nube',
            json_encode(['sucursal' => $antes['sucursal_id'], 'reserva' => $antes['reserva'],
                         'enviar' => $antes['enviar_existencias'], 'descontar' => $antes['descontar_ventas']]),
            json_encode(['sucursal' => $suc, 'reserva' => $reserva, 'enviar' => $enviar ? 1 : 0,
                         'descontar' => !empty($_POST['descontar_ventas']) ? 1 : 0]));

        $txt = 'Guardado.';
        if ($enviar && (!(int)$antes['enviar_existencias'] || (int)$antes['sucursal_id'] !== $suc
                        || (float)$antes['reserva'] !== (float)$reserva)) {
            $n = $srv->repo()->marcarTodasPendientes();
            $r = (new TnInventario($db))->empujarPendientes(40);
            $txt .= ' Se mandaron ' . $r['enviadas'] . ' de ' . $n . ' existencias a Tienda Nube'
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
        @set_time_limit(180);
        try {
            $r = (new TnInventario(Conexion::de($_SESSION['empresa_db'])))->importar();
            $this->volver('Se trajeron ' . $r['productos'] . ' productos (' . $r['renglones'] . ' variantes)'
                . ($r['vinculadas'] ? '; ' . $r['vinculadas'] . ' se vincularon solas por SKU' : '') . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tn/importar: ' . $e->getMessage());
            $this->volver('No se pudieron traer los productos: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Un solo producto por código, código de barras o nombre exacto; si no, el
     * único cuyo nombre contenga el texto. Para cuando Inventario no trae el suyo.
     */
    private static function buscarProducto(\PDO $db, $texto)
    {
        $base = "SELECT id, nombre FROM productos WHERE activo = 1 AND lleva_inventario = 1 AND ";
        $st = $db->prepare($base . "(codigo = ? OR codigo_barras = ? OR nombre = ?) LIMIT 2");
        $st->execute([$texto, $texto, $texto]);
        $f = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (count($f) !== 1) {
            $st = $db->prepare($base . "nombre LIKE ? LIMIT 2");
            $st->execute(['%' . addcslashes($texto, '%_\\') . '%']);
            $f = $st->fetchAll(\PDO::FETCH_ASSOC);
        }
        return count($f) === 1 ? $f[0] : null;
    }

    public function vincular()
    {
        $this->exigirToken();
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new TnInventario($db);
        $id  = (int)($_POST['variante_id'] ?? 0);

        $pid = (int)($_POST['producto_id'] ?? 0);
        $texto = trim((string)($_POST['producto_buscar'] ?? ''));
        if (!$pid && $texto !== '' && empty($_POST['soltar'])) {
            $inv = new Inventario($db);
            $p = method_exists($inv, 'buscarProducto') ? $inv->buscarProducto($texto) : self::buscarProducto($db, $texto);
            if (!$p) $this->volver('No encontré un solo producto con «' . $texto . '». Escribe su SKU o elígelo de la lista.', 'error');
            $pid = (int)$p['id'];
        }
        if (!empty($_POST['soltar'])) $pid = 0;
        if (!$pid && empty($_POST['soltar'])) $this->volver('Elige el producto.', 'error');

        try {
            $v = $srv->vincular($id, $pid);
            Auditoria::anota('tn.vincular', $v['producto_tn'] . '/' . $v['variante_tn'], $v['producto_id'] ?: null, $pid ?: null);
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
            $r = (new TnInventario(Conexion::de($_SESSION['empresa_db'])))->sincronizar(100);
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
            error_log('[LibertyFin] tn/sincronizar: ' . $e->getMessage());
            $this->volver('No se pudo sincronizar: ' . $e->getMessage(), 'error');
        }
    }

    // ════════════════════════════════════════════════════════
    //  PUBLICAR UN PRODUCTO
    // ════════════════════════════════════════════════════════

    public function publicar()
    {
        if (!Modulos::exigir('tiendanube')) return;
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
        $db  = Conexion::de($_SESSION['empresa_db']);
        $pub = new TnPublicar($db);
        $d   = $this->datosPublicar();
        try {
            $p = $pub->producto(Peticion::entero('producto'));
            $d['producto'] = $p;
            $d['yaPublicado'] = $pub->yaPublicado($p['id']);
            if (!$d['yaPublicado']) $d['f'] = $this->valoresIniciales($pub, $p);
        } catch (\InvalidArgumentException $e) {
            $d['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tn/publicar: ' . $e->getMessage());
            $d['error'] = self::faltaTabla($e) ? 'Falta actualizar la base (versión 31).' : $e->getMessage();
        }
        Plantilla::pagina('tiendanube/publicar', $d);
    }

    public function publicarGuardar()
    {
        if (!Modulos::exigir('tiendanube')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $pub = new TnPublicar($db);
        $d   = $this->datosPublicar();
        $d['f'] = $_POST;

        $ok = !empty($_SESSION['lf_token']) && !empty($_POST['token']) && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
        try {
            if (!$ok) throw new \InvalidArgumentException('No se pudo verificar el formulario. Recarga la página.');
            $p = $pub->producto((int)($_POST['producto'] ?? 0));
            $d['producto'] = $p;
            if ($ya = $pub->yaPublicado($p['id'])) {
                $d['yaPublicado'] = $ya;
                Plantilla::pagina('tiendanube/publicar', $d);
                return;
            }
            list($cuerpo, $errores) = TnPublicar::armar($_POST, $pub->srv()->idioma());
            $fotos = $this->fotosDelFormulario($p, $errores);
            if ($errores) {
                $d['errores'] = $errores;
                $d['error'] = 'Revisa lo marcado en rojo.';
                Plantilla::pagina('tiendanube/publicar', $d);
                return;
            }

            @set_time_limit(120);
            $r = $pub->publicar($p, $cuerpo, $fotos);
            $tn = $r['producto'];
            Auditoria::anota('tn.publicar', (string)$tn['id'], null,
                $p['codigo'] . ' · ' . ($_POST['nombre'] ?? '') . ' · $' . $cuerpo['variants'][0]['price'] . ' × ' . $cuerpo['variants'][0]['stock']);
            $this->volver('Publicado en Tienda Nube: «' . TiendaNube::texto($tn['name'] ?? '', $pub->srv()->idioma()) . '», '
                . 'ya vinculado a ' . $p['codigo'] . '.'
                . (empty($tn['published']) ? ' Quedó OCULTO en la tienda: hazlo visible desde Tienda Nube cuando quieras.' : '')
                . $r['aviso'], 'ok');
        } catch (\InvalidArgumentException $e) {
            $d['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tn/publicar POST: ' . $e->getMessage());
            $d['error'] = 'No se pudo publicar: ' . $e->getMessage();
        }
        Plantilla::pagina('tiendanube/publicar', $d);
    }

    private function datosPublicar()
    {
        return [
            'titulo' => 'Publicar en Tienda Nube', 'icono' => 'bolsa', 'subtitulo' => 'Un producto a tu tienda en línea',
            'token' => $_SESSION['lf_token'] ?? '', 'producto' => null, 'yaPublicado' => null,
            'f' => [], 'errores' => [], 'error' => '',
        ];
    }

    private function valoresIniciales(TnPublicar $pub, array $p)
    {
        $precio = (float)($p['subprecio'] ?? 0) > 0 ? (float)$p['subprecio'] : (float)($p['precio'] ?? 0);
        return [
            'nombre'        => mb_substr(trim((string)$p['nombre']), 0, TnPublicar::MAX_NOMBRE),
            'precio'        => number_format($precio, 2, '.', ''),
            'precio_promocional' => '',
            'cantidad'      => $pub->srv()->cantidadAPublicar($p['id']),
            'sku'           => (string)($p['codigo'] ?? ''),
            'codigo_barras' => (string)($p['codigo_barras'] ?? ''),
            'marca'         => (string)($p['marca'] ?? ''),
            'peso'          => '',
            'descripcion'   => trim((string)($p['descripcion'] ?? '')),
            'publicado'     => '1',
            'usar_imagen'   => !empty($p['imagen']) ? '1' : '',
        ];
    }

    /** Igual que en Mercado Libre: la imagen del producto y las agregadas; se revisa el CONTENIDO. */
    private function fotosDelFormulario(array $p, array &$errores)
    {
        $fotos = [];
        $tipos = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];

        $img = (string)($p['imagen'] ?? '');
        if (!empty($_POST['usar_imagen']) && strpos($img, '/assets/subidas/') === 0 && strpos($img, '..') === false) {
            $ruta = dirname(__DIR__, 2) . '/public' . $img;
            $info = is_file($ruta) ? @getimagesize($ruta) : false;
            if ($info && isset($tipos[$info[2]])) $fotos[] = ['ruta' => $ruta, 'mime' => $tipos[$info[2]]];
            else $errores['fotos'] = 'La imagen del producto no se encontró o no es JPG, PNG, GIF o WebP: agrega otra.';
        }
        $up = $_FILES['fotos'] ?? null;
        if ($up && is_array($up['name'] ?? null)) {
            foreach ($up['name'] as $i => $nombre) {
                if ((int)$up['error'][$i] === UPLOAD_ERR_NO_FILE || $nombre === '') continue;
                if ((int)$up['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$i])) {
                    $errores['fotos'] = 'No se pudo recibir «' . $nombre . '». Puede que pese demasiado.'; continue;
                }
                if ((int)$up['size'][$i] > 10485760) { $errores['fotos'] = '«' . $nombre . '» pesa más de 10 MB.'; continue; }
                $info = @getimagesize($up['tmp_name'][$i]);
                if (!$info || !isset($tipos[$info[2]])) { $errores['fotos'] = '«' . $nombre . '» no es JPG, PNG, GIF o WebP.'; continue; }
                $fotos[] = ['ruta' => $up['tmp_name'][$i], 'mime' => $tipos[$info[2]]];
            }
        }
        return array_slice($fotos, 0, TnPublicar::MAX_FOTOS);
    }

    // ════════════════════════════════════════════════════════

    /** La dirección pública de los avisos. Se puede fijar con `dominio` en el bloque de config. */
    public static function urlAviso()  { return self::base() . '/tiendanube/aviso'; }
    public static function urlRetorno() { return self::base() . '/tiendanube/retorno'; }

    private static function base()
    {
        $d = rtrim(trim((string)(TiendaNube::config()['dominio'] ?? '')), '/');
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
        header('Location: /tiendanube?' . http_build_query($q));
        exit;
    }
}
