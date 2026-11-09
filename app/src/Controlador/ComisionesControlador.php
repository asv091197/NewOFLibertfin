<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class ComisionesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $sinDueno = $repo->sinDueno();

        // Para cada renglón sin dueño, los candidatos de su área.
        $candidatos = [];
        foreach ($sinDueno as $s) {
            $a = $s['area_nombre'];
            if (!isset($candidatos[$a])) $candidatos[$a] = $repo->colaboradoresDe($a);
        }

        Plantilla::pagina('comisiones/index', [
            'titulo'     => 'Comisiones',
            'icono'      => 'comi',
            'subtitulo'  => Fechas::rotulo($desde, $hasta),
            'resumen'    => $repo->resumen($desde, $hasta),
            'equipo'     => $repo->porColaborador($desde, $hasta),
            'areas'      => $repo->porArea($desde, $hasta),
            'desde'      => $desde,
            'hasta'      => $hasta,
            'pagina'     => max(1, Peticion::entero('p', 1)),
            'porPag'     => 12,
            'liberacion' => $repo->liberacion($desde, $hasta),
            // Cinco y cinco: las tres tarjetas de abajo se ven juntas, y
            // con cantidades distintas una queda enana al lado de otra.
            'atadas'     => $repo->atadas($desde, $hasta, 5),
            'sin_dueno'  => $sinDueno,
            'candidatos' => $candidatos,
            'desde'      => $desde,
            'hasta'      => $hasta,
            // El último "Recalcular comisiones", con lo que cambió y su Deshacer.
            'recalculo'  => ($_SESSION['usuario_rol'] ?? '') === 'admin'
                            ? (new \LibertyFin\Servicio\RecalcularComisiones($db))->ultimo() : null,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Recalcula lo devengado de todas las ventas contra sus pagos de hoy
     * (ver Servicio\RecalcularComisiones). Lo que cambia se puede deshacer.
     */
    public function recalcular()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            $r = (new \LibertyFin\Servicio\RecalcularComisiones($db))->todas();
            Auditoria::anota('comision.recalcular', $r['revisadas'] . ' ventas revisadas', null,
                $r['cambiadas'] . ' cambiaron');
            $t = $r['cambiadas']
                ? 'Listo: se revisaron ' . $r['revisadas'] . ' ventas y ' . $r['cambiadas']
                  . ($r['cambiadas'] == 1 ? ' cambió' : ' cambiaron') . '. Abajo está qué se movió; si algo no te convence, puedes deshacerlo.'
                : 'Listo: las ' . $r['revisadas'] . ' ventas ya estaban bien y no cambió nada.';
            if ($r['errores']) {
                $t .= ' ' . $r['errores'] . ($r['errores'] == 1 ? ' venta no se pudo' : ' ventas no se pudieron')
                    . ' recalcular y ' . ($r['errores'] == 1 ? 'quedó' : 'quedaron') . ' como estaba' . ($r['errores'] == 1 ? '' : 'n') . '.';
            }
            $this->volver($t, 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] recalcular comisiones: ' . $e->getMessage());
            $this->volver('No se pudieron recalcular las comisiones.', 'error');
        }
    }

    /** Regresa las ventas de un recálculo a como estaban. */
    public function deshacerRecalculo()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        $id = (int)($_POST['id'] ?? 0);
        try {
            $r = (new \LibertyFin\Servicio\RecalcularComisiones($db))->deshacer($id);
            Auditoria::anota('comision.deshacer', 'recálculo ' . $id, null,
                $r['regresadas'] . ' ventas regresadas');
            $t = 'Se deshizo el recálculo: ' . $r['regresadas']
               . ($r['regresadas'] == 1 ? ' venta volvió' : ' ventas volvieron') . ' a como estaba'
               . ($r['regresadas'] == 1 ? '' : 'n') . '.';
            if ($r['saltadas']) {
                $t .= ' ' . $r['saltadas'] . ($r['saltadas'] == 1 ? ' tuvo' : ' tuvieron')
                    . ' un pago o una comisión nueva después y se ' . ($r['saltadas'] == 1 ? 'dejó' : 'dejaron')
                    . ' como está' . ($r['saltadas'] == 1 ? '' : 'n') . ': regresarla' . ($r['saltadas'] == 1 ? '' : 's')
                    . ' borraría eso.';
            }
            $this->volver($t, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] deshacer recálculo: ' . $e->getMessage());
            $this->volver('No se pudo deshacer el recálculo.', 'error');
        }
    }

    /**
     * Fragmento HTML con las ventas de un colaborador. Lo pide el panel
     * expandible de la tarjeta "Por colaborador"; no es una página.
     */
    public function colaborador($id)
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $nombre = Peticion::texto('nombre');
        $equipo = Peticion::texto('equipo');
        $q      = mb_substr(Peticion::texto('q'), 0, 100);

        $d = $repo->detalleColaborador((int)$id, $nombre, $equipo, $desde, $hasta,
                                       Peticion::entero('p', 1), Peticion::POR_PAGINA, $q);

        $base = '/comisiones/colaborador/' . (int)$id;
        $qs   = function ($p) use ($desde, $hasta, $nombre, $equipo, $q) {
            $x = ['desde' => $desde, 'hasta' => $hasta,
                  'nombre' => $nombre, 'equipo' => $equipo, 'p' => $p];
            if ($q !== '') $x['q'] = $q;        // la paginación conserva la búsqueda
            return http_build_query($x);
        };

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        Plantilla::parcial('comisiones/detalle', [
            'd'      => $d,
            'enlace' => function ($n) use ($base, $qs) { return $base . '?' . $qs($n); },
        ]);
    }

    /** Asigna dueño a un renglón que estaba en POR ASIGNAR. */
    public function reasignar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver('Solo un administrador puede asignar comisiones.', 'error');
        }
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }

        try {
            $nombre = (new ComisionRepo($db))->reasignar(
                (int)($_POST['renglon'] ?? 0),
                (int)($_POST['colaborador'] ?? 0)
            );
            \LibertyFin\Servicio\Auditoria::anota('comision.reasignar',
                'renglón ' . (int)($_POST['renglon'] ?? 0),
                'POR ASIGNAR', $nombre);
            $this->volver('Comisión asignada a ' . $nombre . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] reasignar: ' . $e->getMessage());
            $this->volver('No se pudo asignar. Quedó anotado el error.', 'error');
        }
    }

    /**
     * ASIGNAR COMISIONES EN LOTE · la vista previa.
     *
     * Se filtran las ventas (periodo, área, especialista, solo las que no
     * tienen comisión) y se enseñan TODAS antes de asignar nada, cada una
     * con su estado: las que no se pueden salen sin marcar y con el motivo.
     * Comisionar en lote sin ver la lista es la forma más rápida de pagar
     * dos veces o de comisionar la venta equivocada.
     */
    public function lote()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $area  = Peticion::texto('area');
        $esp   = Peticion::texto('esp');                 // '' | 'sin' | id
        $solo  = Peticion::texto('solo', '1') === '1';   // solo ventas sin comisión
        // También las ventas de antes que tuvieron cobros en el periodo
        // (abonos, liquidaciones): las "de ventas anteriores" de Reportes.
        $ant   = Peticion::texto('ant', '1') === '1';
        $tope  = 300;

        // El área y "sin comisión" se miran producto por producto, así que
        // se filtran aquí y no en la consulta. Se traen de sobra para que
        // el tope no se coma ventas que sí cumplen.
        $ventas = $repo->paraLote($desde, $hasta,
            $esp === 'sin' ? 'sin' : ((int)$esp > 0 ? (int)$esp : null), 2000, $ant);

        $quedan = [];
        foreach ($ventas as $v) {
            $obj = self::objetivo($v['lineas'], $area);
            if ($v['lineas']) {
                if (!$obj) continue;                         // ningún producto del área
                if ($solo && !array_filter($obj, function ($l) { return $l['con'] === ''; })) continue;
            } else {
                // Sin productos: sale, para que se vea por qué no se puede.
                if ($area !== '' && mb_strtolower(trim($v['area'])) !== mb_strtolower($area)) continue;
                if ($solo && trim((string)$v['comisiones']) !== '') continue;
            }
            $v['objetivo'] = array_column($obj, 'id');
            $quedan[] = $v;
        }
        $ventas  = $quedan;
        $cortado = count($ventas) > $tope;
        if ($cortado) $ventas = array_slice($ventas, 0, $tope);

        Plantilla::pagina('comisiones/lote', [
            'titulo'    => 'Comisiones en lote',
            'icono'     => 'comi',
            'subtitulo' => Fechas::rotulo($desde, $hasta) . ' · asignar a varias ventas',
            'ventas'    => $ventas,
            'cortado'   => $cortado,
            'tope'      => $tope,
            'equipo'    => $repo->equipoPorArea(),
            // El porcentaje que cada quien suele cobrar, para sugerirlo.
            'sugeridos' => $repo->porcentajesUsados(),
            'areas'     => (new \LibertyFin\Datos\CatalogoRepo($db))->areas(),
            'desde'     => $desde, 'hasta' => $hasta, 'area' => $area, 'esp' => $esp,
            'solo'      => $solo,
            'ant'       => $ant,
            'resultado' => $_SESSION['lf_lote'] ?? null,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_lote'], $_SESSION['lf_aviso']);
    }

    /**
     * ASIGNAR COMISIONES EN LOTE · la asignación.
     *
     * Varias personas, cada una con su porcentaje y su alcance (todos los
     * productos marcados, un área o un producto). Por cada venta, cada
     * persona recibe una comisión en cada producto que le toca.
     *
     * Una por una con AsignarComision: las MISMAS reglas que en el detalle de
     * la venta (base comisionable mayor a cero, nadie dos veces en el mismo
     * producto). Cada comisión va en su propia transacción: si una no se
     * puede, las demás sí quedan, y al final se dice cuáles no y por qué en
     * vez de detenerse en la primera.
     */
    public function asignarLote()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        // De vuelta a la misma lista, con los mismos filtros.
        $volverA = '/comisiones/lote?' . http_build_query([
            'desde' => (string)($_POST['desde'] ?? ''), 'hasta' => (string)($_POST['hasta'] ?? ''),
            'area'  => (string)($_POST['area'] ?? ''),  'esp'   => (string)($_POST['esp'] ?? ''),
            'solo'  => (string)($_POST['solo'] ?? '1'), 'ant' => (string)($_POST['ant'] ?? '1'),
        ]);
        $falla = function ($texto) use ($volverA) {
            $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => 'error'];
            header('Location: ' . $volverA); exit;
        };

        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $falla('Solo un administrador puede asignar comisiones.');
        }
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $falla('No se pudo verificar el formulario.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ventas'] ?? [])))));
        if (!$ids)             $falla('Marca al menos una venta.');
        if (count($ids) > 300) $falla('Son demasiadas ventas de una vez: máximo 300.');

        // Quiénes, con cuánto y en qué: una fila por persona. 'esp' es "al
        // especialista de cada venta". `en` es '' (todos los productos
        // marcados), 'a:Área' o 'p:id de producto'.
        $repo    = new ComisionRepo($db);
        $nombres = [];
        foreach ($repo->equipoPorArea() as $gente) {
            foreach ($gente as $c) $nombres[(int)$c['id']] = $c['nombre'];
        }
        $pcts  = (array)($_POST['porcentaje'] ?? []);
        $ens   = (array)($_POST['en'] ?? []);
        $filas = (array)($_POST['fila'] ?? []);       // clave de cada fila, para `para`
        $pares = []; $vistos = [];
        foreach ((array)($_POST['colaborador'] ?? []) as $i => $q) {
            $q   = trim((string)$q);
            $pct = round((float)($pcts[$i] ?? 0), 4);
            if ($q === '' && $pct == 0) continue;                       // fila vacía
            if ($q === '' || ($q !== 'esp' && !isset($nombres[(int)$q]))) {
                $falla('Elige a quién en cada fila.');
            }
            if ($pct <= 0 || $pct > 100) $falla('Cada porcentaje debe ser mayor a 0 y hasta 100.');

            $en = trim((string)($ens[$i] ?? ''));
            if (strpos($en, 'a:') === 0 && trim(substr($en, 2)) !== '') {
                $en = 'a:' . mb_substr(trim(substr($en, 2)), 0, 100);
            } elseif (strpos($en, 'p:') === 0 && (int)substr($en, 2) > 0) {
                $en = 'p:' . (int)substr($en, 2);
            } else {
                $en = '';
            }

            // La misma persona en lo mismo dos veces es un error de captura;
            // en cosas distintas sí se vale (Juan 10% en Legal y 5% en
            // Contabilidad).
            $clave = $q === 'esp' ? 'esp' : (string)(int)$q;
            if (isset($vistos[$clave . '|' . $en])) {
                $falla('Hay una persona dos veces con lo mismo: deja una sola fila.');
            }
            $vistos[$clave . '|' . $en] = true;
            $k = substr(preg_replace('/[^0-9a-z]/i', '', (string)($filas[$i] ?? '')), 0, 10);
            $pares[] = ['q' => $clave, 'pct' => $pct, 'en' => $en, 'k' => $k !== '' ? $k : 'f' . $i,
                        'nombre' => $clave === 'esp' ? 'Especialista' : $nombres[(int)$clave]];
        }
        if (!$pares)            $falla('Elige a quién se le asigna la comisión.');
        if (count($pares) > 10) $falla('Son demasiadas filas de una vez: máximo 10.');

        // El periodo de la lista: para saber qué ventas son de antes y qué
        // generó cada comisión en él (lo que cuenta en Comisiones y Reportes).
        $fechaOk = function ($s) {
            $d = \DateTime::createFromFormat('Y-m-d', (string)$s);
            return $d && $d->format('Y-m-d') === $s ? $s : null;
        };
        $pDesde = $fechaOk($_POST['desde'] ?? '') ?: date('Y-m-01');
        $pHasta = $fechaOk($_POST['hasta'] ?? '') ?: date('Y-m-t');
        $pA = $pDesde;
        $pB = date('Y-m-d', strtotime($pHasta . ' +1 day'));

        // Folio, especialista y cobros de cada venta: para los mensajes, el
        // modo "al especialista de cada venta" y la bitácora.
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("
            SELECT v.id, v.codigo_venta, v.especialista_id, v.fecha, v.total,
                   COALESCE(c.nombre, 'Público general') AS cliente,
                   COALESCE(pg.cobrado, 0) AS cobrado, pg.ultimo,
                   COALESCE(pp.n, 0) AS cobros_periodo, COALESCE(pp.monto, 0) AS cobrado_periodo,
                   pp.ultimo AS ultimo_periodo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                        FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, COUNT(*) n, SUM(monto) monto, MAX(fecha_pago) ultimo
                        FROM venta_pagos
                        WHERE cancelado = 0 AND fecha_pago >= ? AND fecha_pago < ?
                        GROUP BY venta_id ) pp ON pp.venta_id = v.id
            WHERE v.id IN ($marcas)");
        $st->execute(array_merge([$pA, $pB], $ids));
        $info = [];
        foreach ($st->fetchAll() as $x) {
            // Venta de antes del periodo: qué se le cobró en él, que es lo que
            // trae su comisión a este periodo. "abonó $1,500.00 el 03/10".
            $x['anterior'] = substr((string)$x['fecha'], 0, 10) < $pA;
            $x['cobro'] = '';
            if ($x['anterior'] && (int)$x['cobros_periodo'] > 0) {
                $liquido = (float)$x['total'] - (float)$x['cobrado'] <= 0.005
                        && $x['ultimo'] >= $pA && $x['ultimo'] < $pB;
                $n = (int)$x['cobros_periodo'];
                $x['cobro'] = ($liquido ? 'liquidó' : ($n === 1 ? 'abonó' : $n . ' abonos:'))
                            . ' ' . \LibertyFin\Dominio\Dinero::pesos($x['cobrado_periodo'])
                            . ' el ' . date('d/m', strtotime($x['ultimo_periodo']));
            }
            $info[(int)$x['id']] = $x;
        }

        // Cuánto generó ya cada comisión nueva, y cuánto de eso en el periodo.
        $generada = $db->prepare("
            SELECT COALESCE(SUM(monto),0) AS total,
                   COALESCE(SUM(CASE WHEN fecha_pago >= ? AND fecha_pago < ? THEN monto ELSE 0 END),0) AS periodo
            FROM pago_comisiones WHERE venta_comision_id = ?");

        // Los productos de cada venta. En las de varios productos manda lo
        // que se marcó en la lista; si no llegó nada, los del área filtrada
        // (o todos), como se veían de entrada.
        $area    = trim((string)($_POST['area'] ?? ''));
        $lineas  = $repo->lineasDe($ids);
        $elegido = (array)($_POST['lineas'] ?? []);
        // Por venta, a quiénes de las filas lleva (los chips). Si no llegó,
        // lleva a todos a los que les toca.
        $paraPost = (array)($_POST['para'] ?? []);
        // Por venta y por persona, en qué productos va (lo arma la lista: con
        // una persona seleccionada se le eligen los suyos). Manda sobre lo
        // marcado para todos y sobre su "En". Si no llegó, se usa lo de antes.
        $lpPost = (array)($_POST['lp'] ?? []);

        // Para los mensajes y la bitácora: "Ana 10% en Legal + Juan 5%".
        $nombreProd = [];
        foreach ($lineas as $ls) foreach ($ls as $l) $nombreProd[$l['producto_id']] = $l['producto'];
        $reparto = implode(' + ', array_map(function ($p) use ($nombreProd) {
            $en = '';
            if (strpos($p['en'], 'a:') === 0) $en = ' en ' . substr($p['en'], 2);
            if (strpos($p['en'], 'p:') === 0) $en = ' en ' . ($nombreProd[(int)substr($p['en'], 2)] ?? 'un producto');
            return $p['nombre'] . ' ' . rtrim(rtrim(number_format($p['pct'], 4, '.', ''), '0'), '.') . '%' . $en;
        }, $pares));
        $varias = count($pares) > 1;

        $srv = new \LibertyFin\Servicio\AsignarComision($db);
        $hechas = 0; $enVentas = 0; $monto = 0.0; $omitidas = [];
        $bitacora = [];        // cada comisión asignada, para desplegarla en la bitácora
        foreach ($ids as $id) {
            $folio = $info[$id]['codigo_venta'] ?? ('#' . $id);
            if (!isset($info[$id])) { $omitidas[] = [$folio, 'La venta ya no existe']; continue; }

            $todas = $lineas[$id] ?? [];
            if (!$todas) { $omitidas[] = [$folio, 'La venta no tiene productos']; continue; }
            $varios = count($todas) > 1;
            // Lo que cada persona eligió en esta venta, si llegó.
            $lpVenta = isset($lpPost[$id]) ? (array)$lpPost[$id] : null;
            if ($varios && isset($elegido[$id])) {
                $marcados = array_map('intval', (array)$elegido[$id]);
                $obj = array_values(array_filter($todas, function ($l) use ($marcados) {
                    return in_array($l['id'], $marcados, true);
                }));
                if (!$obj && $lpVenta === null) { $omitidas[] = [$folio, 'No se marcó ningún producto']; continue; }
            } else {
                $obj = self::objetivo($todas, $area);
                if (!$obj && $lpVenta === null) { $omitidas[] = [$folio, 'No tiene productos de ' . $area]; continue; }
            }

            // Por producto: quién ya quedó (nadie dos veces en lo mismo; la
            // primera fila gana) y cuánto porcentaje lleva (no más de 100).
            $para = isset($paraPost[$id]) ? array_map('strval', (array)$paraPost[$id]) : null;

            $alguna = false; $aplico = false; $quitados = false; $usados = []; $sumaPct = [];
            foreach ($pares as $par) {
                if ($lpVenta !== null && isset($lpVenta[$par['k']])) {
                    // Los productos que se le eligieron a esta persona en esta
                    // venta (solo los que son de la venta: el resto se ignora).
                    $suyos = array_map('intval', (array)$lpVenta[$par['k']]);
                    $mios = array_filter($todas, function ($l) use ($suyos) {
                        return in_array($l['id'], $suyos, true);
                    });
                } else {
                    $mios = array_filter($obj, function ($l) use ($par) { return self::enAlcance($l, $par['en']); });
                }
                if (!$mios) continue;                    // en esta venta no hay nada para esta fila
                if ($para !== null && !in_array($par['k'], $para, true)) {
                    $quitados = true;                    // se quitó de esta venta a propósito
                    continue;
                }
                $aplico = true;

                $esp = $par['q'] === 'esp';
                $col = $esp ? (int)($info[$id]['especialista_id'] ?? 0) : (int)$par['q'];
                // Con varias filas, cada mensaje dice de quién es.
                $quien = $varias ? ' · ' . ($esp ? 'especialista' : $par['nombre']) : '';
                if ($col <= 0) { $omitidas[] = [$folio . $quien, 'No tiene especialista']; continue; }

                // Lo que esa persona ya comisiona no se toca ni se reporta
                // como error: en la lista ya salía como no incluido.
                $libres = array_filter($mios, function ($l) use ($col, $usados) {
                    return !in_array($col, array_map('intval', explode(',', $l['con'])), true)
                        && !isset($usados[$col . ':' . $l['id']]);
                });
                if (!$libres) {
                    $omitidas[] = [$folio . $quien,
                        $esp ? 'Su especialista ya tiene comisión' : 'Ya tiene comisión de esta persona'];
                    continue;
                }

                foreach ($libres as $l) {
                    $donde = ($varios ? $folio . ' · ' . $l['producto'] : $folio) . $quien;
                    if (($sumaPct[$l['id']] ?? 0) + $par['pct'] > 100.0001) {
                        $omitidas[] = [$donde, 'Con las demás filas pasa de 100% en este producto'];
                        continue;
                    }
                    $usados[$col . ':' . $l['id']] = true;
                    try {
                        $r = $srv->asignar($id, $col, $par['pct'], $l['id']);
                        $hechas++;
                        $alguna = true;
                        $monto += (float)$r['asignada'];
                        $gen = ['total' => 0, 'periodo' => 0];
                        try {
                            $generada->execute([$pA, $pB, (int)($r['id'] ?? 0)]);
                            $gen = $generada->fetch() ?: $gen;
                        } catch (\Throwable $e) { /* la bitácora sale sin esa cifra */ }
                        $bitacora[] = [
                            'venta'       => $id,
                            'folio'       => $folio,
                            'cliente'     => $info[$id]['cliente'],
                            'fecha_venta' => substr((string)$info[$id]['fecha'], 0, 10),
                            'anterior'    => (bool)$info[$id]['anterior'],
                            'cobro'       => $info[$id]['cobro'],
                            'producto'    => $l['producto'],
                            'colaborador' => $r['colaborador'],
                            'pct'         => $par['pct'],
                            'monto'       => (float)$r['asignada'],       // si el cliente liquida
                            'generada'    => round((float)$gen['total'], 2),   // ya ganada con lo cobrado
                            'en_periodo'  => round((float)$gen['periodo'], 2), // la que cuenta en el periodo
                        ];
                        $sumaPct[$l['id']] = ($sumaPct[$l['id']] ?? 0) + $par['pct'];
                    } catch (\InvalidArgumentException $e) {
                        $omitidas[] = [$donde, $e->getMessage()];
                    } catch (\Throwable $e) {
                        error_log('[LibertyFin] comision en lote, venta ' . $id . ' linea ' . $l['id']
                                  . ' colaborador ' . $col . ': ' . $e->getMessage());
                        $omitidas[] = [$donde, 'No se pudo asignar (quedó anotado el error)'];
                    }
                }
            }
            if (!$aplico) {
                $omitidas[] = [$folio, $quitados ? 'Se quitó a todos de esta venta'
                                                 : 'Sus productos no son para nadie de la lista'];
            }
            if ($alguna) $enVentas++;
        }

        if ($hechas > 0) {
            // Con la lista de cada comisión (y lo que no se pudo), para
            // que la bitácora conteste "¿cuáles ventas fueron?".
            Auditoria::anotaConDetalle('comision.lote',
                $hechas . ' comisi' . ($hechas === 1 ? 'ón' : 'ones') . ' en ' . $enVentas . ' venta' . ($enVentas === 1 ? '' : 's'),
                null,
                $reparto . ($area !== '' ? ' · ' . $area : ''),
                ['comisiones' => $bitacora, 'omitidas' => $omitidas, 'monto' => round($monto, 2),
                 'en_periodo' => round(array_sum(array_column($bitacora, 'en_periodo')), 2),
                 'desde' => $pDesde, 'hasta' => $pHasta]);
        }
        $_SESSION['lf_lote'] = ['hechas' => $hechas, 'ventas' => $enVentas, 'monto' => round($monto, 2),
                                'omitidas' => $omitidas, 'reparto' => $reparto];
        header('Location: ' . $volverA); exit;
    }

    /**
     * ¿Este producto le toca a esta fila? '' = todos, 'a:Área' = los de esa
     * área (sin distinguir mayúsculas), 'p:id' = ese producto del catálogo.
     */
    private static function enAlcance(array $linea, $en)
    {
        if ($en === '') return true;
        if (strpos($en, 'a:') === 0) {
            return mb_strtolower(trim((string)$linea['area'])) === mb_strtolower(trim(substr($en, 2)));
        }
        if (strpos($en, 'p:') === 0) return (int)$linea['producto_id'] === (int)substr($en, 2);
        return false;
    }

    /**
     * Los productos de una venta que se comisionan en lote: los del área
     * elegida, o todos si no se eligió. El área se compara sin distinguir
     * mayúsculas, como en los reportes.
     */
    private static function objetivo(array $lineas, $area)
    {
        if ($area === '') return $lineas;
        $buscada = mb_strtolower(trim($area));
        return array_values(array_filter($lineas, function ($l) use ($buscada) {
            return mb_strtolower(trim((string)$l['area'])) === $buscada;
        }));
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /comisiones'); exit;
    }

}
