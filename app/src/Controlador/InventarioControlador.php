<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
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
        $ids = array_map('intval', array_column($sucursales, 'id'));
        $suc = Peticion::entero('s');
        if (!in_array($suc, $ids, true)) $suc = $inv->sucursalActual();

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

        if ($t === 'movimientos') {
            $f = [
                'desde'    => Peticion::fecha('desde', date('Y-m-d', strtotime('-30 days'))),
                'hasta'    => Peticion::fecha('hasta', date('Y-m-d')),
                'q'        => Peticion::texto('q'),
                'tipo'     => Peticion::opcion('tipo', array_keys(Inventario::TIPOS), ''),
                // En Movimientos se puede ver todas las sucursales juntas.
                'sucursal' => Peticion::texto('s') === '0' ? 0 : $suc,
            ];
            $datos['filtros'] = $f;
            $datos['movimientos'] = $inv->movimientos($f);
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
            $datos['listado'] = $inv->listado($suc);
            $datos['faltaMigrar'] = false;
            try {
                $cn = new \LibertyFin\Servicio\Conteo($db);
                $abierto = $cn->enCurso($suc);
                $datos['conteo']    = $abierto;
                $datos['avance']    = $abierto ? $cn->avance($abierto['id']) : null;
                $datos['recientes'] = $cn->recientes($suc, 8);
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
            $datos['listado']  = $inv->listado($suc, $buscar, $bajos);
            // Para el formulario: todos, sin el filtro de la tabla.
            $datos['productos'] = ($buscar === '' && !$bajos) ? $datos['listado'] : $inv->listado($suc);
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
            $queda = Inventario::conUnidad($r['existencia'], $p['unidad_medida']);
            if ($tipo === 'conteo') {
                $msg = abs($r['cambio']) < 0.0005
                    ? 'Conteo anotado: ' . $p['nombre'] . ' cuadra (' . $queda . ').'
                    : 'Conteo anotado: ' . $p['nombre'] . ' queda en ' . $queda . ' ('
                      . ($r['cambio'] > 0 ? '+' : '−') . Inventario::num(abs($r['cambio'])) . ' contra el sistema).';
            } elseif ($tipo === 'traspaso') {
                $msg = 'Traspaso registrado: ' . $p['nombre'] . ' queda en ' . $queda . ' en el origen y '
                     . Inventario::conUnidad($r['destino'], $p['unidad_medida']) . ' en el destino.';
            } elseif ($tipo === 'merma') {
                $msg = 'Merma registrada: ' . $p['nombre'] . ' queda en ' . $queda . '.';
            } else {
                $msg = ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' registrada: ' . $p['nombre'] . ' queda en ' . $queda . '.';
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
        if (!$this->tokenValido()) $this->volverConteo('No se pudo verificar el formulario.', 'error', $suc);
        if (!$inv->activo()) $this->volverConteo('El inventario está apagado (Ajustes → Inventario).', 'error', $suc);

        $cn     = new \LibertyFin\Servicio\Conteo($db);
        $accion = (string)($_POST['accion'] ?? 'guardar');
        $id     = (int)($_POST['conteo_id'] ?? 0);

        try {
            switch ($accion) {
                case 'iniciar':
                    $c = $cn->abrir($suc, (int)($_POST['almacen_id'] ?? 0) ?: null, (string)($_POST['nombre'] ?? ''));
                    $this->volverConteo('Conteo iniciado en ' . ($c['almacen'] ?? 'el almacén')
                        . '. Cuenta con calma: puedes pausarlo y seguir después.', 'ok', $suc);

                case 'guardar':
                    $n = 0;
                    foreach ((array)($_POST['contado'] ?? []) as $pid => $val) {
                        if (trim((string)$val) === '') continue;
                        $cn->capturar($id, (int)$pid, $val);
                        $n++;
                    }
                    $av = $cn->avance($id);
                    $this->volverConteo($n . ($n === 1 ? ' renglón guardado' : ' renglones guardados')
                        . '. Llevas ' . $av['capturados'] . ' de ' . $av['total']
                        . ($av['diferencias'] ? ', ' . $av['diferencias'] . ' con diferencia' : '')
                        . '. Nada se ajusta hasta que finalices.', 'ok', $suc);

                case 'pausar':
                    $cn->pausar($id);
                    $this->volverConteo('Conteo pausado. Lo capturado queda guardado; retómalo cuando quieras.', 'ok', $suc);

                case 'reanudar':
                    $cn->reanudar($id);
                    $this->volverConteo('Conteo reanudado.', 'ok', $suc);

                case 'cancelar':
                    $cn->cancelar($id, (string)($_POST['nota'] ?? ''));
                    $this->volverConteo('Conteo cancelado. No se movió ninguna existencia.', 'ok', $suc);

                case 'finalizar':
                    // Lo que esté escrito en pantalla se guarda antes de
                    // cerrar: si no, lo último capturado se perdería por
                    // no haber apretado «Guardar avance» primero.
                    foreach ((array)($_POST['contado'] ?? []) as $pid => $val) {
                        if (trim((string)$val) === '') continue;
                        $cn->capturar($id, (int)$pid, $val);
                    }
                    $r = $cn->finalizar($id, (string)($_POST['nota'] ?? ''));
                    \LibertyFin\Servicio\Auditoria::anota('inventario.conteo', 'sucursal ' . $suc, null,
                        $r['contados'] . ' productos contados, ' . $r['ajustados'] . ' con diferencia', $db);
                    $this->volverConteo('Conteo finalizado: ' . $r['contados']
                        . ($r['contados'] === 1 ? ' producto contado' : ' productos contados')
                        . ($r['ajustados']
                           ? ', ' . $r['ajustados'] . ' ajustados (ver Movimientos).'
                           : '; todo cuadró.'), 'ok', $suc);

                default:
                    $this->volverConteo('Esa acción no existe.', 'error', $suc);
            }
        } catch (\InvalidArgumentException $e) {
            $this->volverConteo($e->getMessage(), 'error', $suc);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/conteo: ' . $e->getMessage());
            $this->volverConteo('No se pudo guardar el conteo.', 'error', $suc);
        }
    }

    /**
     * Cerrar el aviso de arriba.
     *
     * Guarda la HUELLA de los números, no un simple «ya lo vi»: así el
     * aviso vuelve a salir en cuanto la situación cambia. Cerrar «falta
     * 1 producto» no puede esconder «faltan 12» media hora después.
     */
    public function callar()
    {
        if ($this->tokenValido()) {
            $_SESSION['lf_alerta_cerrada'] = (string)($_POST['huella'] ?? '');
        }
        // Se vuelve a donde estaba, pero solo a una RUTA de aquí: el
        // Referer lo escribe quien sea, y redirigir a lo que traiga es
        // una invitación a mandar gente a otro sitio desde el nuestro.
        $ruta = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        $q    = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
        $destino = preg_match('~^/[A-Za-z0-9/_.-]*$~', $ruta) ? $ruta . ($q !== '' ? '?' . $q : '') : '/panel';
        header('Location: ' . $destino);
        exit;
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

    private function volverConteo($texto, $tipo, $suc)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /inventario?t=conteo&s=' . (int)$suc); exit;
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
        $acciones = '<button type="button" class="btn btn-secondary btn-sm"'
            . ' onclick="lfCap(\'' . ($pausada ? 'reanudar' : 'pausar') . '\')">'
            . ($pausada ? 'Seguir capturando' : 'Pausar') . '</button>'
            . ' <button type="button" class="btn btn-primary btn-sm"'
            . ' onclick="if(confirm(\'¿Aplicar la captura al inventario? Esto sí mueve las existencias.\'))'
            . ' lfCap(\'finalizar\')">Finalizar</button>';

        $pie = $pausada ? '' : '
            <form method="post" action="/inventario/captura" autocomplete="off">
              <input type="hidden" name="token" value="' . Plantilla::e($_SESSION['lf_token']) . '">
              <input type="hidden" name="id" value="' . (int)$c['id'] . '">
              <input type="hidden" name="accion" value="escanear">
              <div style="display:flex;gap:8px;align-items:center">
                <input name="codigo" id="capCodigo" inputmode="none" autofocus
                       placeholder="Pasa el código con la pistola…"
                       style="flex:1;font-size:17px;padding:12px 14px">
                <input name="cantidad" type="number" step="0.001" min="0.001" value="1"
                       title="Cuántas de ese código" style="width:88px;font-size:17px;padding:12px 8px">
                <button class="btn btn-primary" type="submit">Agregar</button>
              </div>
            </form>';

        Plantilla::pagina('inventario/captura', [
            'titulo'    => $c['tipo_rotulo'] . ' · capturando',
            'icono'     => 'caja',
            'subtitulo' => $c['almacen'] . ' · empezada ' . date('H:i', strtotime($c['creado_en'])),
            'captura'   => $c,
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