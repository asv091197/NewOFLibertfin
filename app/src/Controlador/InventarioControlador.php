<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\FiltrosProducto;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Vista\Plantilla;

/**
 * Inventario: qué hay en cada sucursal (Existencias) y por qué cambió
 * (Movimientos). Solo para las empresas que lo encendieron en Ajustes.
 *
 * Aquí no se "edita" una existencia: se registra una entrada, una salida
 * o un conteo, y el servicio deja el rastro. Ver Servicio\Inventario.
 */
final class InventarioControlador
{
    public function index()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $t   = Peticion::opcion('t', ['existencias', 'movimientos', 'conteo', 'alertas'], 'existencias');
        if ($t === 'conteo' && !Permisos::puede('editar.inventario')) $t = 'existencias';

        $sucursales = $inv->sucursales();
        $suc = $this->sucursalPedida($inv, $sucursales);

        $datos = [
            'titulo'      => 'Inventario',
            'icono'       => 'bolsa',
            'subtitulo'   => count($sucursales) > 1 ? $this->nombreDe($sucursales, $suc) : 'Existencias y movimientos',
            'activo'      => $inv->activo(),
            'bloquea'     => $inv->bloquea(),
            'pestana'     => $t,
            'sucursales'  => $sucursales,
            'sucursal'    => $suc,
            'puedeEditar' => Permisos::puede('editar.inventario'),
            'puedeAjustes'=> Permisos::puede('editar.ajustes'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
            // Los almacenes de esta sucursal, y todos (para el traspaso,
            // que puede ir a otra tienda).
            'almacenes'   => [],
            'almacenesTodos' => [],
            // Marca, área y tipo: los mismos filtros que Productos.
            'filtro'         => FiltrosProducto::deLaPeticion(),
            'opcionesFiltro' => ['marcas' => [], 'areas' => [], 'tipos' => []],
            'porAlmacen'     => [],
        ];
        // Sin almacenes la pantalla funciona como antes: un solo lugar
        // por sucursal. Si la base todavía no se migró, se dice una vez
        // y con qué arreglarlo, en vez de un renglón por página.
        // La captura que esta persona dejó a medias, si la hay. Va en su
        // propio try: con la base sin migrar, Existencias tiene que
        // seguir abriendo —el botón se esconde y ya—, no caerse entera.
        $datos['capturaAbierta'] = null;
        try {
            $datos['capturaAbierta'] = (new \LibertyFin\Servicio\Captura($db))
                ->mia($suc, (int)($_SESSION['usuario_id'] ?? 0));
        } catch (\Throwable $e) { $this->avisarEsquema('captura', $e); }

        $datos['almacenResumen'] = [];
        try {
            $al = new \LibertyFin\Servicio\Almacenes($db);
            $al->asegurar($suc);
            $datos['almacenes']      = $al->deSucursal($suc);
            $datos['almacenesTodos'] = $al->todosConSucursal();
            $datos['almacenResumen'] = $al->resumen($suc);
            $datos['porAlmacen']     = $inv->porAlmacen($suc);
        } catch (\Throwable $e) {
            if (empty($_SESSION['lf_almacenes_rotos'])) {
                $_SESSION['lf_almacenes_rotos'] = 1;
                error_log('[LibertyFin] almacenes en Inventario: ' . $e->getMessage()
                    . ' · la base no está al día. Corre: php app/bin/migrar.php');
            }
        }
        unset($_SESSION['lf_aviso']);

        if (!$datos['activo']) {
            Plantilla::pagina('inventario/index', $datos);
            return;
        }

        if ($t === 'existencias' || $t === 'conteo') {
            $datos['opcionesFiltro'] = FiltrosProducto::opciones($db, true);
        }

        if ($t === 'movimientos') {
            $f = $this->filtrosMovimientos($suc);
            $datos['filtros'] = $f;
            $datos['movimientos'] = $inv->movimientos($f);
            // Cuánto entró y cuánto salió, por tipo: el mismo filtro que la
            // lista pero SIN el tipo, para que el desglose y las pastillas
            // enseñen todos los tipos aunque se esté viendo uno.
            try { $datos['resumenMov'] = $inv->resumenMovimientos(array_merge($f, ['tipo' => ''])); }
            catch (\Throwable $e) { $datos['resumenMov'] = []; }
        } elseif ($t === 'alertas') {
            // Con la base atrasada esto revienta la pantalla entera. No
            // vale eso: se enseña vacía y se dice qué falta.
            $datos['conteos'] = ['agotados'=>0,'bajos'=>0,'caducados'=>0,'por_caducar'=>0,'dias'=>15];
            $datos['listado'] = [];
            $datos['nivelStock'] = 'avisar';
            $datos['nivelCad']   = 'avisar';
            $datos['faltaMigrar'] = false;
            try {
                $al = new \LibertyFin\Servicio\Alertas($db);
                $datos['conteos']  = $al->contar($suc);
                $datos['listado']  = $al->detalle($suc);
                $datos['nivelStock'] = $al->nivelStock();
                $datos['nivelCad']   = $al->nivelCaducidad();
            } catch (\Throwable $e) {
                $datos['faltaMigrar'] = $this->avisarEsquema('avisos', $e);
            }
        } elseif ($t === 'conteo') {
            $datos['conteo'] = null; $datos['avance'] = null; $datos['recientes'] = [];
            $datos['capturado'] = []; $datos['enAlmacen'] = [];
            $datos['buscar'] = Peticion::texto('q');
            // Por nombre: así se recorre el anaquel. Con los filtros se
            // cuenta por partes —hoy los refrescos, mañana la limpieza—.
            $datos['listado'] = $inv->listado($suc, $datos['buscar'], false, $datos['filtro'], 5000, 'nombre');
            $datos['faltaMigrar'] = false;
            try {
                $cn = new \LibertyFin\Servicio\Conteo($db);
                $abierto = $cn->enCurso($suc);
                $datos['conteo']    = $abierto;
                $datos['avance']    = $abierto ? $cn->avance($abierto['id']) : null;
                $datos['recientes'] = $cn->recientes($suc, 12);
                // Lo ya capturado, para repintar los renglones al volver.
                if ($abierto) {
                    foreach ($cn->lineas($abierto['id']) as $l) {
                        $datos['capturado'][(int)$l['producto_id']] = $l;
                    }
                    // Las existencias del almacén que se está contando, no
                    // las de la sucursal entera: se cuenta un anaquel.
                    $st = $db->prepare("SELECT producto_id, stock FROM lf_producto_almacen WHERE almacen_id = ?");
                    $st->execute([(int)$abierto['almacen_id']]);
                    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $f) {
                        $datos['enAlmacen'][(int)$f['producto_id']] = (float)$f['stock'];
                    }
                }
            } catch (\Throwable $e) {
                $datos['faltaMigrar'] = $this->avisarEsquema('conteo', $e);
            }
        } else {
            $buscar = Peticion::texto('q');
            $bajos  = Peticion::texto('bajos') !== '';
            $datos['buscar']   = $buscar;
            $datos['bajos']    = $bajos;
            $datos['resumen']  = $inv->resumen($suc);
            $datos['listado']  = $inv->listado($suc, $buscar, $bajos, $datos['filtro']);
            // Para el formulario: todos, sin el filtro de la tabla.
            $datos['productos'] = ($buscar === '' && !$bajos && !FiltrosProducto::hay($datos['filtro']))
                                ? $datos['listado'] : $inv->listado($suc, '', false, [], 5000);
            $datos['mover']     = Peticion::opcion('mover', ['entrada', 'salida', 'merma', 'conteo', 'traspaso'], '');
            $datos['producto']  = Peticion::entero('producto');
            try {
                $datos['proveedores'] = (new \LibertyFin\Datos\GastoRepo($db))->proveedoresActivos();
            } catch (\Throwable $e) {
                $datos['proveedores'] = [];
            }
        }
        Plantilla::pagina('inventario/index', $datos);
    }

    /** Entrada, salida o conteo. */
    public function mover()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $suc = (int)($_POST['sucursal_id'] ?? 0);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error', $suc);
        if (!$inv->activo()) $this->volver('El inventario está apagado (Ajustes → Inventario).', 'error', $suc);

        $tipo = (string)($_POST['tipo'] ?? '');

        // El producto puede llegar por id (se eligió de la lista) o como
        // texto (se escaneó, o se escribió y el navegador no emparejó).
        // Resolverlo aquí hace que el escáner funcione aunque el
        // JavaScript no corra.
        $pid = (int)($_POST['producto_id'] ?? 0);
        if (!$pid && trim((string)($_POST['producto_buscar'] ?? '')) !== '') {
            $hallado = $inv->buscarProducto($_POST['producto_buscar']);
            if (!$hallado) {
                $this->volver('No encontré «' . trim((string)$_POST['producto_buscar'])
                    . '». Escribe parte del nombre o escanea otra vez.', 'error', $suc, $tipo);
            }
            $pid = (int)$hallado['id'];
        }

        try {
            if ($tipo === 'traspaso') {
                $r = $inv->traspasarAlmacen($pid,
                        (int)($_POST['almacen_id'] ?? 0) ?: null,
                        (int)($_POST['almacen_destino'] ?? 0),
                        $_POST['cantidad'] ?? 0, $_POST['nota'] ?? '');
            } else {
                $r = $inv->registrar($tipo, $pid, $suc, $_POST['cantidad'] ?? 0, $_POST);
            }
            $p = $db->prepare("SELECT nombre, unidad_medida FROM productos WHERE id = ?");
            $p->execute([$pid]);
            $p = $p->fetch(\PDO::FETCH_ASSOC) ?: ['nombre' => 'El producto', 'unidad_medida' => ''];
            $u = $p['unidad_medida'];

            // EN QUÉ ALMACÉN, Y CUÁNTO EN TODA LA SUCURSAL.
            //
            // El movimiento cambia UN almacén (el piso, si no se dijo otro),
            // pero la tabla de Existencias enseña el total de la sucursal.
            // Decir solo «queda en 12» cuando la tabla dice 17 —porque en la
            // bodega hay 5— es lo que hacía parecer que el conteo salía mal.
            $donde = $this->almacenDelMovimiento($db, $suc, (int)($_POST['almacen_id'] ?? 0));
            $enDonde = $donde ? ' en ' . $donde : '';
            $total = $tipo === 'traspaso' ? null : $inv->totalEnSucursal($pid, $suc);
            $suma = ($donde && $total !== null)
                  ? ' (' . Inventario::conUnidad($total, $u) . ' en toda la sucursal)' : '';

            $queda = Inventario::conUnidad($r['existencia'], $u);
            if ($tipo === 'conteo') {
                $habia = round((float)$r['existencia'] - (float)$r['cambio'], 3);
                $msg = abs($r['cambio']) < 0.0005
                    ? 'Conteo anotado: ' . $p['nombre'] . ' cuadra' . $enDonde . ' (' . $queda . ')' . $suma . '.'
                    : 'Conteo anotado: ' . $p['nombre'] . $enDonde . ' el sistema decía '
                      . Inventario::conUnidad($habia, $u) . ', contaste ' . $queda . ' ('
                      . ($r['cambio'] > 0 ? '+' : '−') . Inventario::num(abs($r['cambio'])) . ')' . $suma . '.';
            } elseif ($tipo === 'traspaso') {
                $msg = 'Traspaso registrado: ' . $p['nombre'] . '. Salieron ' . Inventario::conUnidad(abs($r['cambio']), $u)
                     . ' de ' . ($r['origen_nombre'] ?? 'el origen') . ' (quedan ' . $queda . ') y llegaron a '
                     . ($r['destino_nombre'] ?? 'el destino') . ' (ahora hay '
                     . Inventario::conUnidad($r['destino'], $u) . ').';
            } elseif ($tipo === 'merma') {
                $msg = 'Merma registrada: ' . $p['nombre'] . ' queda en ' . $queda . $enDonde . $suma . '.';
            } else {
                $msg = ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' registrada: ' . $p['nombre']
                     . ' queda en ' . $queda . $enDonde . $suma . '.';
            }
            $this->volver($msg, 'ok', $suc);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error', $suc, $tipo, $pid);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/mover: ' . $e->getMessage());
            $this->volver('No se pudo registrar el movimiento.', 'error', $suc);
        }
    }

    /**
     * El conteo físico, que ahora es una SESIÓN y no un solo envío.
     *
     * Un inventario de tienda se hace entre clientes y en varias
     * sentadas. Con el formulario de un golpe, cerrar la pestaña tiraba
     * dos horas de trabajo. Todo pasa por aquí —iniciar, guardar avance,
     * pausar, reanudar, finalizar, cancelar— porque son pasos de la
     * misma cosa y separarlos en seis rutas solo reparte el mismo
     * `if`.
     */
    public function conteo()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $suc = (int)($_POST['sucursal_id'] ?? 0);
        // Los filtros con los que se estaba contando: al guardar se vuelve
        // a la misma lista, no a todos los productos.
        $fil = $this->filtrosDelPost();
        if (!$this->tokenValido()) $this->volverConteo('No se pudo verificar el formulario.', 'error', $suc, $fil);
        if (!$inv->activo()) $this->volverConteo('El inventario está apagado (Ajustes → Inventario).', 'error', $suc, $fil);

        $cn     = new \LibertyFin\Servicio\Conteo($db);
        $accion = (string)($_POST['accion'] ?? 'guardar');
        $id     = (int)($_POST['conteo_id'] ?? 0);

        try {
            switch ($accion) {
                case 'iniciar':
                    $c = $cn->abrir($suc, (int)($_POST['almacen_id'] ?? 0) ?: null, (string)($_POST['nombre'] ?? ''));
                    $this->volverConteo('Conteo iniciado en ' . ($c['almacen'] ?? 'el almacén')
                        . '. Cuenta con calma: puedes pausarlo y seguir después.', 'ok', $suc, $fil);

                case 'guardar':
                    $n = $this->guardarRenglones($cn, $id);
                    $av = $cn->avance($id);
                    $this->volverConteo($n . ($n === 1 ? ' renglón guardado' : ' renglones guardados')
                        . '. Llevas ' . $av['capturados'] . ' de ' . $av['total']
                        . ($av['diferencias'] ? ', ' . $av['diferencias'] . ' con diferencia' : '')
                        . '. Nada se ajusta hasta que finalices.', 'ok', $suc, $fil);

                case 'pausar':
                    // Lo escrito en pantalla se guarda antes de pausar: pausar
                    // y perder lo último que se contó no tiene sentido.
                    $this->guardarRenglones($cn, $id);
                    $cn->pausar($id);
                    $this->volverConteo('Conteo pausado. Lo capturado queda guardado; retómalo cuando quieras.', 'ok', $suc, $fil);

                case 'reanudar':
                    $cn->reanudar($id);
                    $this->volverConteo('Conteo reanudado.', 'ok', $suc, $fil);

                case 'cancelar':
                    $cn->cancelar($id, (string)($_POST['nota'] ?? ''));
                    $this->volverConteo('Conteo cancelado. No se movió ninguna existencia.', 'ok', $suc);

                case 'finalizar':
                    // Lo que esté escrito en pantalla se guarda antes de
                    // cerrar: si no, lo último capturado se perdería por
                    // no haber apretado «Guardar avance» primero.
                    $this->guardarRenglones($cn, $id);
                    $r = $cn->finalizar($id, (string)($_POST['nota'] ?? ''));
                    \LibertyFin\Servicio\Auditoria::anota('inventario.conteo', 'sucursal ' . $suc, null,
                        $r['contados'] . ' productos contados, ' . $r['ajustados'] . ' con diferencia', $db);
                    $this->volverConteo('Conteo finalizado: ' . $r['contados']
                        . ($r['contados'] === 1 ? ' producto contado' : ' productos contados')
                        . ($r['ajustados']
                           ? ', ' . $r['ajustados'] . ' ajustados (ver Movimientos).'
                           : '; todo cuadró.'), 'ok', $suc);

                default:
                    $this->volverConteo('Esa acción no existe.', 'error', $suc, $fil);
            }
        } catch (\InvalidArgumentException $e) {
            $this->volverConteo($e->getMessage(), 'error', $suc, $fil);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/conteo: ' . $e->getMessage());
            $this->volverConteo('No se pudo guardar el conteo.', 'error', $suc, $fil);
        }
    }

    /**
     * Guarda los renglones que llegaron del formulario.
     *
     * Una casilla que ANTES tenía número y ahora llega vacía es que la
     * persona lo borró: se quita del conteo. Antes se ignoraba, y lo
     * contado por error se quedaba pegado sin forma de quitarlo —el
     * renglón volvía a salir con el número viejo y al finalizar se
     * ajustaba con él—.
     *
     * @return int cuántos se guardaron con número
     */
    private function guardarRenglones(\LibertyFin\Servicio\Conteo $cn, $id)
    {
        $ya = [];
        foreach ($cn->lineas($id) as $l) {
            if ($l['contado'] !== null) $ya[(int)$l['producto_id']] = true;
        }
        $n = 0;
        foreach ((array)($_POST['contado'] ?? []) as $pid => $val) {
            $pid = (int)$pid;
            if (trim((string)$val) === '') {
                if (isset($ya[$pid])) $cn->capturar($id, $pid, null);
                continue;
            }
            $cn->capturar($id, $pid, $val);
            $n++;
        }
        return $n;
    }

    /** Un conteo, para verlo con sus diferencias (desde «Conteos anteriores»). */
    public function verConteo()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        try {
            $d = (new \LibertyFin\Servicio\Conteo($db))->detalle(Peticion::entero('id'));
        } catch (\Throwable $e) {
            $this->avisarEsquema('conteo', $e);
            $d = null;
        }
        if (!$d) {
            $_SESSION['lf_aviso'] = ['texto' => 'Ese conteo no existe.', 'tipo' => 'error'];
            header('Location: /inventario?t=conteo'); exit;
        }
        Plantilla::pagina('inventario/conteo-ver', [
            'titulo'    => 'Inventario',
            'icono'     => 'bolsa',
            'subtitulo' => $d['conteo']['nombre'] ?: ('Conteo #' . (int)$d['conteo']['id']),
            'det'       => $d,
            'activo'    => $inv->activo(),
        ]);
    }

    /**
     * Un movimiento con todo su documento: la captura entera, el traspaso
     * con sus dos lados, el conteo o la venta.
     */
    public function movimiento()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $m = $inv->movimiento(Peticion::entero('id'));
        if (!$m) {
            $_SESSION['lf_aviso'] = ['texto' => 'Ese movimiento no existe.', 'tipo' => 'error'];
            header('Location: /inventario?t=movimientos'); exit;
        }
        $grupo = $inv->grupoDe($m);
        Plantilla::pagina('inventario/movimiento', [
            'titulo'    => 'Inventario',
            'icono'     => 'bolsa',
            'subtitulo' => Inventario::documento($m),
            'mov'       => $m,
            'grupo'     => $grupo,
            'volverA'   => '/inventario?t=movimientos&s=' . (int)$m['sucursal_id'],
        ]);
    }

    /**
     * Sugerencias para el buscador de la captura con pistola: escribir
     * parte del nombre y elegir, sin tener el código a la mano.
     */
    public function buscar()
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = Peticion::texto('q');
        if (mb_strlen($q) < 2) { echo json_encode(['productos' => []]); return; }
        try {
            $db  = Conexion::de($_SESSION['empresa_db']);
            $inv = new Inventario($db);
            $suc = Peticion::entero('s') ?: $inv->sucursalActual();
            $r = [];
            foreach ($inv->sugerencias($q, $suc, 12) as $p) {
                $r[] = ['id' => (int)$p['id'], 'codigo' => (string)$p['codigo'],
                        'barras' => (string)($p['codigo_barras'] ?? ''), 'nombre' => (string)$p['nombre'],
                        'hay' => Inventario::conUnidad($p['existencia'], $p['unidad_medida'])];
            }
            echo json_encode(['productos' => $r], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/buscar: ' . $e->getMessage());
            echo json_encode(['productos' => [], 'error' => 'No se pudo buscar.'], JSON_UNESCAPED_UNICODE);
        }
    }

    // ═════════════════════════════════════════════════════════
    //  IMPRIMIR / DESCARGAR PDF
    // ═════════════════════════════════════════════════════════

    /**
     * La hoja imprimible, que el navegador guarda como PDF.
     *
     * Igual que Reportes (ver ReportesControlador::imprimir): una hoja HTML
     * con `@media print` sale con acentos, encabezado repetido en cada
     * página y números de página, sin una librería de PDF que mantener.
     *
     *   que=existencias  el reporte de existencias, con los filtros de la pantalla
     *   que=movimientos  la bitácora filtrada, con entradas y salidas desglosadas
     *   que=movimiento   un documento: captura, traspaso, conteo o venta (id=)
     *   que=conteo       un conteo con sus diferencias, o la hoja para contar (id=)
     *
     * Con auto=1 abre el cuadro de imprimir solo: «Descargar PDF» es un
     * clic, no dos.
     */
    public function imprimir()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $que = Peticion::opcion('que', ['existencias', 'movimientos', 'movimiento', 'conteo'], 'existencias');
        $sucursales = $inv->sucursales();
        $suc = $this->sucursalPedida($inv, $sucursales);
        $hojas = []; $nombre = 'Inventario'; $volver = '/inventario?s=' . $suc;

        try {
            if ($que === 'existencias') {
                $hojas[] = $this->hojaExistencias($db, $inv, $suc, $sucursales);
                $nombre = 'Existencias ' . $this->nombreDe($sucursales, $suc) . ' ' . date('Y-m-d');
            } elseif ($que === 'movimientos') {
                $f = $this->filtrosMovimientos($suc);
                $hojas[] = $this->hojaMovimientos($inv, $f, $sucursales);
                $nombre = 'Movimientos ' . $f['desde'] . ' a ' . $f['hasta'];
                $volver = '/inventario?t=movimientos&s=' . $suc;
            } elseif ($que === 'movimiento') {
                $m = $inv->movimiento(Peticion::entero('id'));
                if (!$m) throw new \InvalidArgumentException('Ese movimiento no existe.');
                $hojas[] = $this->hojaDocumento($m, $inv->grupoDe($m));
                $nombre = Inventario::documento($m) . ' ' . date('Y-m-d', strtotime($m['creado_en']));
                $volver = '/inventario/movimiento?id=' . (int)$m['id'];
            } else {
                if (!Permisos::puede('editar.inventario')) throw new \InvalidArgumentException('No tienes acceso a los conteos.');
                $hojas[] = $this->hojaConteo($db, $inv, Peticion::entero('id'), Peticion::texto('hoja') === '1');
                $nombre = 'Conteo ' . Peticion::entero('id');
                $volver = '/inventario/conteo/ver?id=' . Peticion::entero('id');
            }
        } catch (\InvalidArgumentException $e) {
            $hojas = [['titulo' => 'No hay nada que imprimir', 'sub' => $e->getMessage(),
                       'columnas' => [], 'filas' => []]];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/imprimir: ' . $e->getMessage());
            $hojas = [['titulo' => 'No se pudo armar la hoja', 'sub' => 'Inténtalo otra vez en un momento.',
                       'columnas' => [], 'filas' => []]];
        }

        Plantilla::pagina('inventario/imprimir', [
            // El título de la página es el nombre que el navegador le
            // propone al PDF: «Existencias Matriz 2026-11-20», no «LibertyFin».
            'titulo'  => $nombre,
            'hojas'   => $hojas,
            'empresa' => $_SESSION['empresa_nombre'] ?? 'LibertyFin',
            'auto'    => Peticion::texto('auto') === '1',
            'volver'  => $volver,
            // Sin la clase del login (fondo verde y todo centrado): es papel.
            'cuerpo'  => 'lf-papel',
        ], 'layout-limpio');
    }

    private function hojaExistencias($db, Inventario $inv, $suc, array $sucursales)
    {
        $filtro = FiltrosProducto::deLaPeticion();
        $buscar = Peticion::texto('q');
        $bajos  = Peticion::texto('bajos') !== '';
        $lista  = $inv->listado($suc, $buscar, $bajos, $filtro, 5000, 'nombre');
        $alms = []; $por = [];
        try {
            $alms = (new \LibertyFin\Servicio\Almacenes($db))->deSucursal($suc);
            $por  = $inv->porAlmacen($suc);
        } catch (\Throwable $e) { /* sin almacenes: solo el total */ }
        $verAlm = count($alms) > 1;

        $col = [['Código', 't'], ['Producto', 't'], ['Marca', 't'], ['Área', 't']];
        if ($verAlm) foreach ($alms as $a) $col[] = [$a['nombre'], 'n'];
        $col = array_merge($col, [['Total', 'n'], ['Mínimo', 'n'], ['Estado', 't'], ['Costo', 'n'], ['Valor', 'n']]);

        $est = [0 => 'Agotado', 1 => 'Bajo su mínimo', 2 => 'Bien'];
        $filas = []; $piezas = 0.0; $valor = 0.0; $porA = [];
        foreach ($lista as $p) {
            $id = (int)$p['id'];
            $f = [$p['codigo'], $p['nombre'], (string)$p['marca'], (string)($p['categoria'] ?? '')];
            if ($verAlm) foreach ($alms as $a) {
                $v = $por[$id][(int)$a['id']] ?? 0.0;
                $porA[(int)$a['id']] = ($porA[(int)$a['id']] ?? 0) + $v;
                $f[] = Inventario::num($v);
            }
            $hay = (float)$p['existencia'];
            $val = $hay > 0 ? $hay * (float)$p['costo'] : 0.0;
            $piezas += $hay; $valor += $val;
            $f[] = Inventario::conUnidad($hay, $p['unidad_medida']);
            $f[] = (float)$p['minimo'] > 0 ? Inventario::num($p['minimo']) : '';
            $f[] = $est[(int)$p['estado']] ?? '';
            $f[] = \LibertyFin\Dominio\Dinero::pesos($p['costo']);
            $f[] = \LibertyFin\Dominio\Dinero::pesos($val);
            $filas[] = ['celdas' => $f, 'marca' => (int)$p['estado'] === 0 ? 'mal' : ((int)$p['estado'] === 1 ? 'ojo' : '')];
        }
        $tot = ['TOTAL · ' . count($lista) . ' productos', '', '', ''];
        if ($verAlm) foreach ($alms as $a) $tot[] = Inventario::num($porA[(int)$a['id']] ?? 0);
        $tot = array_merge($tot, [Inventario::num($piezas), '', '', '', \LibertyFin\Dominio\Dinero::pesos($valor)]);

        return [
            'titulo'   => 'Reporte de existencias',
            'sub'      => $this->nombreDe($sucursales, $suc) . ' · al ' . date('d/m/Y H:i'),
            'datos'    => array_values(array_filter([
                ['Sucursal', $this->nombreDe($sucursales, $suc)],
                $this->rotuloFiltros($filtro, $buscar, $bajos),
                ['Productos', number_format(count($lista))],
                ['Valor a costo', \LibertyFin\Dominio\Dinero::pesos($valor)],
            ])),
            'columnas' => $col,
            'filas'    => $filas,
            'totales'  => $tot,
            'nota'     => 'El total es la suma de los almacenes de la sucursal. El valor es lo que hay × su costo; '
                        . 'lo agotado no suma.',
        ];
    }

    private function hojaMovimientos(Inventario $inv, array $f, array $sucursales)
    {
        $f['tope'] = 5000;
        $movs = $inv->movimientos($f);
        $res  = [];
        try { $res = $inv->resumenMovimientos($f); } catch (\Throwable $e) {}
        $varias = count($sucursales) > 1;

        $col = [['Cuándo', 't'], ['Producto', 't'], ['Movimiento', 't']];
        if ($varias) $col[] = ['Sucursal', 't'];
        $col = array_merge($col, [['Almacén', 't'], ['Había', 'n'], ['Cantidad', 'n'], ['Quedó', 'n'],
                                  ['En la sucursal', 'n'], ['Quién', 't'], ['Detalle', 't']]);
        $filas = [];
        foreach ($movs as $m) {
            $c = (float)$m['cantidad'];
            $x = [date('d/m/Y H:i', strtotime($m['creado_en'])), (string)($m['producto'] ?: '(ya no existe)'),
                  Inventario::TIPOS[$m['tipo']] ?? $m['tipo']];
            if ($varias) $x[] = (string)$m['sucursal'];
            $x[] = Inventario::rutaAlmacen($m);
            $x[] = Inventario::num(Inventario::habia($m));
            $x[] = ($c > 0 ? '+' : ($c < 0 ? '−' : '')) . Inventario::num(abs($c));
            $x[] = Inventario::conUnidad($m['existencia'], $m['unidad_medida']);
            $x[] = $m['existencia_sucursal'] !== null && $m['existencia_sucursal'] !== ''
                 ? Inventario::num($m['existencia_sucursal']) : '';
            $x[] = (string)($m['usuario_nombre'] ?: '');
            $x[] = Inventario::detalleTexto($m);
            $filas[] = ['celdas' => $x, 'marca' => $c < 0 ? 'menos' : ($c > 0 ? 'mas' : '')];
        }

        // Entradas y salidas desglosadas, arriba de la tabla.
        $desglose = []; $entro = 0.0; $salio = 0.0;
        foreach (Inventario::TIPOS as $k => $t) {
            if (empty($res[$k])) continue;
            $entro += $res[$k]['entro']; $salio += $res[$k]['salio'];
            $desglose[] = [$t, ($res[$k]['entro'] > 0 ? '+' . Inventario::num($res[$k]['entro']) : '')
                             . ($res[$k]['entro'] > 0 && $res[$k]['salio'] > 0 ? ' / ' : '')
                             . ($res[$k]['salio'] > 0 ? '−' . Inventario::num($res[$k]['salio']) : '')
                             . ($res[$k]['entro'] <= 0 && $res[$k]['salio'] <= 0 ? '0' : '')];
        }
        array_unshift($desglose, ['Entró en total', '+' . Inventario::num($entro)],
                                 ['Salió en total', '−' . Inventario::num($salio)]);

        $suc = (int)($f['sucursal'] ?? 0);
        return [
            'titulo'   => 'Movimientos de inventario',
            'sub'      => ($suc ? $this->nombreDe($sucursales, $suc) : 'Todas las sucursales')
                        . ' · ' . date('d/m/Y', strtotime($f['desde'])) . ' al ' . date('d/m/Y', strtotime($f['hasta']))
                        . (!empty($f['tipo']) ? ' · solo ' . mb_strtolower(Inventario::TIPOS[$f['tipo']] ?? $f['tipo']) : ''),
            'datos'    => $desglose,
            'columnas' => $col,
            'filas'    => $filas,
            'nota'     => '«Había» y «Quedó» son del almacén donde pasó el movimiento (piso, bodega…); '
                        . '«En la sucursal» es el total de todos sus almacenes después del movimiento.'
                        . (count($movs) >= 5000 ? ' Se imprimieron los 5,000 más recientes: acorta las fechas para ver los demás.' : ''),
        ];
    }

    /** Un documento: los renglones de una misma operación. */
    private function hojaDocumento(array $m, array $grupo)
    {
        $tipo = Inventario::TIPOS[$m['tipo']] ?? $m['tipo'];
        $datos = [['Documento', Inventario::documento($m)], ['Tipo', $tipo],
                  ['Cuándo', date('d/m/Y H:i', strtotime($m['creado_en']))],
                  ['Quién', (string)($m['usuario_nombre'] ?: '—')],
                  ['Sucursal', (string)$m['sucursal']]];
        if (!empty($m['proveedor'])) $datos[] = ['Proveedor', $m['proveedor']];
        if (!empty($m['codigo_venta'])) $datos[] = ['Venta', $m['codigo_venta']];

        if ($m['tipo'] === 'traspaso') {
            // Un traspaso se lee de un lado al otro: cuánto se fue, cuánto
            // quedó, cuánto llegó y cuánto hay allá ahora.
            $col = [['Producto', 't'], ['Sale de', 't'], ['Había', 'n'], ['Se fueron', 'n'], ['Quedó', 'n'],
                    ['Llega a', 't'], ['Había', 'n'], ['Llegaron', 'n'], ['Quedó', 'n']];
            $filas = [];
            foreach (Inventario::traspasosEnPares($grupo) as $par) {
                $s = $par['sale']; $l = $par['llega'];
                $filas[] = ['celdas' => [
                    (string)($s['producto'] ?? $l['producto'] ?? ''),
                    $s ? Inventario::lugar($s) : '', $s ? Inventario::num(Inventario::habia($s)) : '',
                    $s ? Inventario::num(abs((float)$s['cantidad'])) : '', $s ? Inventario::num($s['existencia']) : '',
                    $l ? Inventario::lugar($l) : '', $l ? Inventario::num(Inventario::habia($l)) : '',
                    $l ? Inventario::num(abs((float)$l['cantidad'])) : '', $l ? Inventario::num($l['existencia']) : '',
                ], 'marca' => ''];
            }
            $tot = null;
        } else {
            $col = [['Código', 't'], ['Producto', 't'], ['Almacén', 't'], ['Había', 'n'],
                    [$m['tipo'] === 'conteo' ? 'Ajuste' : 'Cantidad', 'n'],
                    [$m['tipo'] === 'conteo' ? 'Contado' : 'Quedó', 'n'], ['En la sucursal', 'n']];
            $conCosto = false;
            foreach ($grupo as $g) if ($g['costo'] !== null) $conCosto = true;
            if ($conCosto) { $col[] = ['Costo c/u', 'n']; $col[] = ['Importe', 'n']; }
            $filas = []; $suma = 0.0; $importe = 0.0;
            foreach ($grupo as $g) {
                $c = (float)$g['cantidad']; $suma += $c;
                $x = [(string)$g['codigo'], (string)($g['producto'] ?: '(ya no existe)'), (string)($g['almacen'] ?? ''),
                      Inventario::num(Inventario::habia($g)),
                      ($c > 0 ? '+' : ($c < 0 ? '−' : '')) . Inventario::num(abs($c)),
                      Inventario::conUnidad($g['existencia'], $g['unidad_medida']),
                      $g['existencia_sucursal'] !== null && $g['existencia_sucursal'] !== ''
                          ? Inventario::num($g['existencia_sucursal']) : ''];
                if ($conCosto) {
                    $imp = $g['costo'] !== null ? abs($c) * (float)$g['costo'] : 0.0;
                    $importe += $imp;
                    $x[] = $g['costo'] !== null ? \LibertyFin\Dominio\Dinero::pesos($g['costo']) : '';
                    $x[] = $g['costo'] !== null ? \LibertyFin\Dominio\Dinero::pesos($imp) : '';
                }
                $filas[] = ['celdas' => $x, 'marca' => ''];
            }
            $tot = ['TOTAL · ' . count($grupo) . (count($grupo) === 1 ? ' producto' : ' productos'), '', '', '',
                    ($suma > 0 ? '+' : ($suma < 0 ? '−' : '')) . Inventario::num(abs($suma)), '', ''];
            if ($conCosto) { $tot[] = ''; $tot[] = \LibertyFin\Dominio\Dinero::pesos($importe); }
        }
        $nota = trim((string)$m['nota']);
        return [
            'titulo'   => Inventario::documento($m),
            'sub'      => $tipo . ' · ' . date('d/m/Y H:i', strtotime($m['creado_en'])),
            'datos'    => $datos,
            'columnas' => $col,
            'filas'    => $filas,
            'totales'  => $tot,
            'nota'     => $nota !== '' && count($grupo) === 1 ? 'Nota: ' . $nota : '',
            'firmas'   => in_array($m['tipo'], ['entrada', 'salida', 'merma', 'traspaso'], true)
                        ? ['Entregó', 'Recibió'] : [],
        ];
    }

    private function hojaConteo($db, Inventario $inv, $id, $paraContar)
    {
        $cn = new \LibertyFin\Servicio\Conteo($db);
        $d  = $cn->detalle($id);
        if (!$d) throw new \InvalidArgumentException('Ese conteo no existe.');
        $c = $d['conteo'];
        $datos = [['Almacén', (string)($c['almacen'] ?: '—')], ['Sucursal', (string)$c['sucursal']],
                  ['Estado', \LibertyFin\Servicio\Conteo::ESTADOS[$c['estado']] ?? $c['estado']],
                  ['Inició', date('d/m/Y H:i', strtotime($c['creado_en'])) . ' · ' . ($c['usuario_nombre'] ?: '—')]];
        if (!empty($c['cerrado_en'])) $datos[] = ['Cerró', date('d/m/Y H:i', strtotime($c['cerrado_en']))];

        if ($paraContar) {
            // La hoja para contar a mano: todos los productos (con los
            // filtros que se pidan), lo que dice el sistema y un espacio
            // para escribir. Lo ya capturado sale escrito.
            $lista = $inv->listado((int)$c['sucursal_id'], Peticion::texto('q'), false,
                                   FiltrosProducto::deLaPeticion(), 5000, 'nombre');
            $ya = []; foreach ($cn->lineas($id) as $l) $ya[(int)$l['producto_id']] = $l['contado'];
            $st = $db->prepare("SELECT producto_id, stock FROM lf_producto_almacen WHERE almacen_id = ?");
            $st->execute([(int)$c['almacen_id']]);
            $enAlm = []; foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $f) $enAlm[(int)$f['producto_id']] = (float)$f['stock'];
            $filas = [];
            foreach ($lista as $p) {
                $v = $ya[(int)$p['id']] ?? null;
                $filas[] = ['celdas' => [$p['codigo'], $p['nombre'], (string)$p['marca'],
                                         Inventario::conUnidad($enAlm[(int)$p['id']] ?? 0, $p['unidad_medida']),
                                         $v !== null ? Inventario::num($v) : '', ''], 'marca' => 'renglon'];
            }
            return ['titulo' => 'Hoja de conteo · ' . ($c['nombre'] ?: 'Conteo #' . (int)$c['id']),
                    'sub' => 'Para contar a mano y capturar después', 'datos' => $datos,
                    'columnas' => [['Código', 't'], ['Producto', 't'], ['Marca', 't'], ['Sistema', 'n'],
                                   ['Contado', 'n'], ['Notas', 't']],
                    'filas' => $filas, 'firmas' => ['Contó', 'Revisó']];
        }

        $filas = [];
        foreach ($d['lineas'] as $l) {
            $dif = (float)$l['diferencia'];
            $filas[] = ['celdas' => [(string)$l['codigo'], (string)$l['producto'],
                                     Inventario::conUnidad($l['sistema'], $l['unidad_medida']),
                                     Inventario::conUnidad($l['contado'], $l['unidad_medida']),
                                     abs($dif) < 0.0005 ? 'Cuadra' : ($dif > 0 ? '+' : '−') . Inventario::num(abs($dif)),
                                     abs($dif) < 0.0005 ? '' : \LibertyFin\Dominio\Dinero::pesos($l['valor_dif'])],
                        'marca' => abs($dif) < 0.0005 ? '' : ($dif > 0 ? 'mas' : 'menos')];
        }
        $t = $d['totales'];
        $datos[] = ['Contados', number_format($t['productos'])];
        $datos[] = ['Con diferencia', number_format($t['diferencias'])];
        $datos[] = ['Valor de la diferencia', \LibertyFin\Dominio\Dinero::pesos($t['valor'])];
        return [
            'titulo'   => $c['nombre'] ?: ('Conteo #' . (int)$c['id']),
            'sub'      => 'Conteo físico #' . (int)$c['id'],
            'datos'    => $datos,
            'columnas' => [['Código', 't'], ['Producto', 't'], [$d['abierto'] ? 'Sistema (ahora)' : 'Sistema decía', 'n'],
                           ['Contado', 'n'], ['Diferencia', 'n'], ['Valor', 'n']],
            'filas'    => $filas,
            'totales'  => ['TOTAL · ' . $t['productos'] . ' productos', '', '', '',
                           '+' . Inventario::num($t['sobran']) . ' / −' . Inventario::num($t['faltan']),
                           \LibertyFin\Dominio\Dinero::pesos($t['valor'])],
            'nota'     => $d['abierto']
                ? 'El conteo sigue abierto: la diferencia es contra lo que hay ahora y puede cambiar hasta que se finalice.'
                : 'La diferencia es contra lo que decía el sistema al contar. El ajuste quedó en Movimientos.',
            'firmas'   => ['Contó', 'Revisó'],
        ];
    }

    /** «Marca: X · Área: Y», para el encabezado de la hoja. null si no hay filtro. */
    private function rotuloFiltros(array $f, $buscar = '', $bajos = false)
    {
        $t = [];
        if ($buscar !== '') $t[] = '«' . $buscar . '»';
        if ($f['marca'] !== '') $t[] = 'Marca: ' . $f['marca'];
        if ($f['area']) {
            try {
                $st = Conexion::de($_SESSION['empresa_db'])->prepare("SELECT nombre FROM categorias WHERE id = ?");
                $st->execute([(int)$f['area']]);
                $t[] = 'Área: ' . ((string)$st->fetchColumn() ?: '#' . (int)$f['area']);
            } catch (\Throwable $e) { $t[] = 'Área #' . (int)$f['area']; }
        }
        if ($f['tipo'] !== '') $t[] = 'Tipo: ' . FiltrosProducto::rotuloTipo($f['tipo']);
        if ($bajos) $t[] = 'Solo lo que falta';
        return $t ? ['Filtro', implode(' · ', $t)] : null;
    }

    /**
     * La base no tiene lo que esta pantalla necesita.
     *
     * Devuelve true para que la vista lo diga en pantalla en vez de
     * reventar con un SQLSTATE que no le sirve a nadie. Se anota una vez
     * por sesión y por pantalla: con la base atrasada esto se repetiría
     * en cada carga.
     *
     * Distingue «falta migrar» de cualquier otro error de base, porque
     * lo que hay que hacer es distinto: uno se arregla con el SQL, el
     * otro hay que mirarlo.
     */
    private function avisarEsquema($donde, \Throwable $e)
    {
        $m = $e->getMessage();
        $falta = strpos($m, "doesn't exist") !== false
              || strpos($m, 'Unknown column') !== false
              || strpos($m, '42S02') !== false || strpos($m, '42S22') !== false;
        $llave = 'lf_esq_' . $donde;
        if (empty($_SESSION[$llave])) {
            $_SESSION[$llave] = 1;
            error_log('[LibertyFin] ' . $donde . ': ' . $m
                . ($falta ? ' · la base no está al día; corre php app/bin/migrar.php --aplicar '
                          . 'o aplica config/actualizar-v27.sql en phpMyAdmin' : ''));
        }
        return $falta;
    }

    private function volverConteo($texto, $tipo, $suc, array $filtros = [])
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $q = array_merge(['t' => 'conteo', 's' => (int)$suc], array_filter($filtros, function ($v) {
            return $v !== '' && $v !== 0 && $v !== null; }));
        header('Location: /inventario?' . http_build_query($q)); exit;
    }

    /** Los filtros de la lista del conteo que viajaron en el formulario. */
    private function filtrosDelPost()
    {
        $f = FiltrosProducto::deLaPeticion([
            'marca' => $_POST['f_marca'] ?? '', 'area' => $_POST['f_area'] ?? 0, 'tipo' => $_POST['f_tipo'] ?? '']);
        $f['q'] = mb_substr(trim((string)($_POST['f_q'] ?? '')), 0, 100);
        return $f;
    }

    /** Fechas, tipo, texto y sucursal de Movimientos (la lista y su PDF). */
    private function filtrosMovimientos($suc)
    {
        return [
            'desde'    => Peticion::fecha('desde', date('Y-m-d', strtotime('-30 days'))),
            'hasta'    => Peticion::fecha('hasta', date('Y-m-d')),
            'q'        => Peticion::texto('q'),
            'tipo'     => Peticion::opcion('tipo', array_keys(Inventario::TIPOS), ''),
            // En Movimientos se puede ver todas las sucursales juntas.
            'sucursal' => Peticion::texto('s') === '0' ? 0 : $suc,
        ];
    }

    /** La sucursal de la URL, si es una de las activas; si no, la de quien opera. */
    private function sucursalPedida(Inventario $inv, array $sucursales)
    {
        $ids = array_map('intval', array_column($sucursales, 'id'));
        $suc = Peticion::entero('s');
        return in_array($suc, $ids, true) ? $suc : $inv->sucursalActual();
    }

    /**
     * El nombre del almacén que tocó un movimiento, solo si la sucursal
     * tiene más de uno: con un solo almacén decir «en Piso» es ruido.
     */
    private function almacenDelMovimiento($db, $suc, $almacenId)
    {
        try {
            $al = new \LibertyFin\Servicio\Almacenes($db);
            if (count($al->deSucursal($suc)) < 2) return '';
            $a = $almacenId ? $al->porId($almacenId) : $al->principal($suc);
            return $a ? (string)$a['nombre'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ═════════════════════════════════════════════════════════
    //  CAPTURA CON PISTOLA
    // ═════════════════════════════════════════════════════════

    /**
     * La pantalla de capturar, en modo concentrado.
     *
     * Sin menú y sin barra de arriba: quien está pasando cajas con el
     * lector no necesita nada de eso, y sí puede tocarlo por error.
     */
    public function captura()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $suc = (int)($_GET['s'] ?? 0) ?: $inv->sucursalActual();

        try {
            $cap = new \LibertyFin\Servicio\Captura($db);
            $c   = $cap->mia($suc, (int)($_SESSION['usuario_id'] ?? 0));
        } catch (\Throwable $e) {
            $falta = $this->avisarEsquema('captura', $e);
            $this->volver($falta
                ? 'La base no tiene todavía las tablas de captura. Aplica '
                  . 'config/actualizar-v29.sql en phpMyAdmin, o entra a Mantenimiento '
                  . 'y aprieta «Poner todas al día».'
                : 'No se pudo abrir la captura.', 'error', $suc);
            return;
        }

        if (!$c) {
            $this->volver('No tienes ninguna captura abierta. Empieza una desde Existencias.',
                          'error', $suc);
            return;
        }

        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
        $pausada = $c['estado'] === 'pausada';

        // Los botones de la barra y la caja de abajo se arman aquí y se
        // le pasan al layout: así la plantilla se queda solo con lo
        // capturado, que es de lo que habla.
        //
        // «Regresar» sale a Existencias sin tocar la captura: queda tal
        // cual, y el botón de Existencias dice «Volver a la entrada (12)».
        $acciones = '<a class="btn btn-secondary btn-sm" href="/inventario?s=' . (int)$suc . '"'
            . ' title="Volver a Inventario. La captura se queda guardada como está.">← Regresar</a>'
            . ' <button type="button" class="btn btn-secondary btn-sm"'
            . ' onclick="lfCap(\'' . ($pausada ? 'reanudar' : 'pausar') . '\')">'
            . ($pausada ? 'Seguir capturando' : 'Pausar') . '</button>'
            . ' <button type="button" class="btn btn-primary btn-sm"'
            . ' onclick="if(confirm(\'¿Aplicar la captura al inventario? Esto sí mueve las existencias.\'))'
            . ' lfCap(\'finalizar\')">Finalizar</button>';

        // El campo del código es también BUSCADOR: con la pistola llega el
        // código y un Enter; a mano se escribe parte del nombre y sale la
        // lista (ver inventario/captura.php). `inputmode=none` evita que en
        // la tableta el teclado tape la pantalla al escanear; el botón de
        // la lupa lo trae cuando se quiere escribir.
        $pie = $pausada ? '' : '
            <form method="post" action="/inventario/captura" autocomplete="off" id="capForm">
              <input type="hidden" name="token" value="' . Plantilla::e($_SESSION['lf_token']) . '">
              <input type="hidden" name="id" value="' . (int)$c['id'] . '">
              <input type="hidden" name="sucursal_id" value="' . (int)$suc . '">
              <input type="hidden" name="accion" value="escanear">
              <div class="lf-cap-barra">
                <div class="lf-cap-busca">
                  <input name="codigo" id="capCodigo" inputmode="none" autofocus
                         placeholder="Pasa el código con la pistola o escribe el nombre…"
                         role="combobox" aria-autocomplete="list" aria-expanded="false"
                         aria-controls="capLista" data-s="' . (int)$suc . '">
                  <button type="button" class="lf-cap-lupa" id="capTeclado"
                          title="Escribir para buscar por nombre" aria-label="Buscar por nombre">⌕</button>
                  <ul class="lf-cap-lista" id="capLista" role="listbox" hidden></ul>
                </div>
                <input name="cantidad" id="capCantidad" type="number" step="0.001" min="0.001" value="1"
                       title="Cuántas de ese código" aria-label="Cantidad">
                <button class="btn btn-primary" type="submit">Agregar</button>
              </div>
            </form>';

        Plantilla::pagina('inventario/captura', [
            'titulo'    => $c['tipo_rotulo'] . ' · capturando',
            'icono'     => 'caja',
            'subtitulo' => $c['almacen'] . ' · empezada ' . date('H:i', strtotime($c['creado_en'])),
            'captura'   => $c,
            'sucursal'  => $suc,
            'token'     => $_SESSION['lf_token'],
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'accionesConcentrado' => $acciones,
            'pieConcentrado'      => $pie,
        ], 'layout-concentrado');
        unset($_SESSION['lf_aviso']);
    }

    /** Todo lo que se hace desde la captura: un solo POST. */
    public function capturaAccion()
    {
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $suc = (int)($_POST['sucursal_id'] ?? 0) ?: $inv->sucursalActual();
        $id  = (int)($_POST['id'] ?? 0);
        $acc = (string)($_POST['accion'] ?? '');

        try {
            $cap = new \LibertyFin\Servicio\Captura($db);

            if ($acc === 'iniciar') {
                if (!$inv->activo()) {
                    $this->volver('El inventario está apagado (Ajustes → Inventario).', 'error', $suc);
                }
                $c = $cap->abrir($suc, (string)($_POST['tipo'] ?? 'entrada'),
                                 (int)($_POST['almacen_id'] ?? 0) ?: null,
                                 (string)($_POST['motivo'] ?? ''),
                                 (string)($_POST['nota'] ?? ''));
                header('Location: /inventario/captura?s=' . $suc); exit;
            }

            if (!$id) $this->volver('Falta la captura.', 'error', $suc);

            switch ($acc) {
                case 'escanear':
                    $r = $cap->escanear($id, (string)($_POST['codigo'] ?? ''),
                                        (float)($_POST['cantidad'] ?? 1));
                    $_SESSION['lf_aviso'] = ['texto' => $r['texto'],
                                             'tipo'  => $r['ok'] ? 'ok' : 'error'];
                    break;

                case 'ajustar':
                    $cap->ajustar($id, (int)($_POST['producto_id'] ?? 0),
                                       (float)($_POST['cantidad'] ?? 0));
                    break;

                case 'pausar':
                    $cap->pausar($id);
                    $_SESSION['lf_aviso'] = ['texto' => 'Pausada. Lo capturado te espera aquí.',
                                             'tipo' => 'ok'];
                    header('Location: /inventario?s=' . $suc); exit;

                case 'reanudar':
                    $cap->reanudar($id);
                    break;

                case 'cancelar':
                    $cap->cancelar($id);
                    $this->volver('Captura cancelada. No se movió ninguna existencia.', 'ok', $suc);
                    return;

                case 'finalizar':
                    $r = $cap->finalizar($id);
                    if ($r['ok']) $this->volver($r['texto'], 'ok', $suc);
                    $_SESSION['lf_aviso'] = ['texto' => $r['texto'], 'tipo' => 'error'];
                    break;

                default:
                    $this->volver('Esa acción no existe.', 'error', $suc);
            }
        } catch (\Throwable $e) {
            $falta = $this->avisarEsquema('captura', $e);
            $_SESSION['lf_aviso'] = ['texto' => $falta
                ? 'Falta aplicar config/actualizar-v29.sql en la base.'
                : 'No se pudo: ' . $e->getMessage(), 'tipo' => 'error'];
            if ($falta) { $this->volver($_SESSION['lf_aviso']['texto'], 'error', $suc); return; }
        }

        header('Location: /inventario/captura?s=' . $suc); exit;
    }

    private function nombreDe(array $sucursales, $id)
    {
        foreach ($sucursales as $s) if ((int)$s['id'] === (int)$id) return $s['nombre'];
        return 'Existencias y movimientos';
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    /** Vuelve a Existencias; si hubo error, con el formulario abierto como estaba. */
    private function volver($texto, $tipo, $suc = 0, $mover = '', $producto = 0)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $q = array_filter(['s' => (int)$suc ?: null, 'mover' => $mover ?: null, 'producto' => $producto ?: null]);
        header('Location: /inventario' . ($q ? '?' . http_build_query($q) : '')); exit;
    }
}
