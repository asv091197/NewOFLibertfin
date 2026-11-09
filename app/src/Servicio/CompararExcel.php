<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ReporteRepo;
use PDO;

/**
 * Compara la hoja de ventas que se lleva en Excel contra lo que hay en el
 * sistema, folio por folio. Es de SOLO LECTURA, como Incoherencias: dice
 * qué no coincide y quien audita decide qué está bien.
 *
 * LA HOJA SE LEE IGUAL QUE AL IMPORTAR
 *
 * Con ImportarVentas::analizar() en modo `comparar`: las mismas columnas
 * por nombre, los mismos abonos (un folio repetido sin total es otro cobro
 * de la venta), las mismas comisiones por concepto. Si se leyera distinto
 * aquí, la comparación encontraría diferencias que solo son de lectura.
 *
 * QUÉ SE COMPARA CON QUÉ
 *
 * Las columnas de la hoja van por COBRO (cada renglón es un anticipo o un
 * abono, con su IVA, sus gastos y sus comisiones), así que del sistema se
 * toma lo mismo: lo cobrado de la venta, el IVA de esos cobros (la misma
 * cuenta que Reportes, ReporteRepo::IVA_DEL_PAGO) y lo devengado de
 * comisiones. Comparar el IVA de la hoja contra el IVA de la venta completa
 * marcaría diferencia en toda venta con saldo.
 */
final class CompararExcel
{
    /** unidad: cómo se muestran Excel y Sistema (pesos | texto). */
    const REVISIONES = [
        'falta_sistema' => ['titulo' => 'En el Excel y no en el sistema', 'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'El folio está en la hoja pero no hay ninguna venta con ese folio.'],
        'cancelada'     => ['titulo' => 'Cancelada en el sistema', 'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'La hoja la cuenta como venta, pero en el sistema está cancelada.'],
        'total'         => ['titulo' => 'El total no coincide', 'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'El total de la venta es distinto en la hoja y en el sistema.'],
        'cobrado'       => ['titulo' => 'Lo cobrado no coincide', 'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'Anticipo más abonos de la hoja contra los pagos vigentes de la venta.'],
        'comisiones'    => ['titulo' => 'Las comisiones no coinciden', 'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'Las comisiones de la hoja (los cuatro conceptos) contra lo devengado en el sistema.'],
        'iva'           => ['titulo' => 'El IVA de lo cobrado no coincide', 'severidad' => 'media', 'unidad' => 'pesos',
            'ayuda' => 'El IVA de la hoja contra el IVA que traen los cobros de la venta.'],
        'gastos'        => ['titulo' => 'Los gastos no coinciden', 'severidad' => 'media', 'unidad' => 'pesos',
            'ayuda' => 'La columna de gastos de la hoja contra los gastos de operación de la venta.'],
        'falta_excel'   => ['titulo' => 'En el sistema y no en el Excel', 'severidad' => 'media', 'unidad' => 'pesos',
            'ayuda' => 'Una venta del periodo que la hoja no trae.'],
        'cliente'       => ['titulo' => 'Otro cliente', 'severidad' => 'baja', 'unidad' => 'texto',
            'ayuda' => 'El nombre del cliente no es el mismo (sin contar acentos ni mayúsculas).'],
        'fecha'         => ['titulo' => 'Otra fecha', 'severidad' => 'baja', 'unidad' => 'texto',
            'ayuda' => 'La venta tiene otra fecha en la hoja.'],
        'area'          => ['titulo' => 'Otra área', 'severidad' => 'baja', 'unidad' => 'texto',
            'ayuda' => 'El área de la hoja no es la de la venta en el sistema.'],
        'renglon'       => ['titulo' => 'La hoja no cuadra consigo misma', 'severidad' => 'baja', 'unidad' => 'texto',
            'ayuda' => 'Un renglón que la hoja no puede explicar: anticipo + restante no da el total, '
                     . 'la base a comisionar no cuadra, o no se pudo leer.'],
    ];

    const SEVERIDADES = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];

    /** Lo que la hoja dice de sí misma y vale la pena auditar. */
    const AVISOS_DE_LA_HOJA = ['no es anticipo + restante', 'La base a comisionar dice', 'no cabe en el total'];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @param array $hojas  lo que entrega LectorHoja
     * @param array $f      revisar => claves de REVISIONES; desde, hasta;
     *                      area; tol (pesos); hojas => cuáles leer
     */
    public function comparar(array $hojas, array $f)
    {
        $revisar = array_values(array_intersect((array)($f['revisar'] ?? []), array_keys(self::REVISIONES)))
                 ?: array_keys(self::REVISIONES);
        $tol = max(0.0, (float)($f['tol'] ?? 0.05));

        $plan = (new ImportarVentas($this->db))->analizar($hojas, [
            'hojas' => !empty($f['hojas']) ? (array)$f['hojas'] : null,
            'comparar' => true,
        ]);

        // ── La hoja, agrupada por venta ──
        $ex = []; $sueltos = [];
        foreach ($plan['renglones'] as $r) {
            if (($r['estado'] ?? '') === 'excluida') continue;
            $folio = ($r['tipo'] ?? 'venta') === 'abono' ? $r['folioVenta'] : ($r['folio'] ?? '');
            if ($folio === '' || $folio === null) {
                $sueltos[] = $r;            // sin encabezado o sin folio
                continue;
            }
            if (!isset($ex[$folio])) {
                $ex[$folio] = ['folio' => $folio, 'fecha' => null, 'cliente' => '', 'area' => '',
                    'total' => 0.0, 'cobrado' => 0.0, 'iva' => 0.0, 'gastos' => 0.0, 'comisiones' => 0.0,
                    'cobros' => 0, 'mala' => false, 'hoja' => $r['hoja'], 'fila' => $r['fila'], 'avisos' => []];
            }
            $e = &$ex[$folio];
            if (($r['tipo'] ?? 'venta') === 'venta') {
                $e['fecha'] = $r['fecha']; $e['cliente'] = $r['cliente']; $e['area'] = $r['area'];
                $e['total'] = (float)$r['total'];
                $e['hoja'] = $r['hoja']; $e['fila'] = $r['fila'];
            }
            $e['cobrado']    += (float)$r['anticipo'];
            $e['iva']        += (float)$r['iva'];
            $e['gastos']     += (float)$r['gastos'];
            $e['comisiones'] += array_sum(array_column($r['comisiones'] ?? [], 'monto'));
            if ((float)$r['anticipo'] > 0) $e['cobros']++;
            if (($r['estado'] ?? '') === 'mala') $e['mala'] = true;
            foreach ($r['avisos'] ?? [] as $a) {
                foreach (self::AVISOS_DE_LA_HOJA as $clave) {
                    if (strpos($a, $clave) !== false) { $e['avisos'][] = 'Fila ' . $r['fila'] . ': ' . $a; break; }
                }
                if (($r['estado'] ?? '') === 'mala' && !in_array('Fila ' . $r['fila'] . ': ' . $a, $e['avisos'], true)) {
                    $e['avisos'][] = 'Fila ' . $r['fila'] . ': ' . $a;
                }
            }
            unset($e);
        }

        // ── El periodo: el que se pidió o el que cubre la hoja ──
        $fechas = array_filter(array_column($ex, 'fecha'));
        $desde = ($f['desde'] ?? '') ?: ($fechas ? min($fechas) : date('Y-m-01'));
        $hasta = ($f['hasta'] ?? '') ?: ($fechas ? max($fechas) : date('Y-m-d'));
        if (!empty($f['desde']) || !empty($f['hasta'])) {
            $ex = array_filter($ex, function ($e) use ($desde, $hasta) {
                return !$e['fecha'] || ($e['fecha'] >= $desde && $e['fecha'] <= $hasta);
            });
        }

        // ── El sistema, de los mismos folios ──
        $sis = $this->delSistema(array_map('strval', array_keys($ex)));
        $conArea = $this->hayColumna('ventas', 'area_nombre');

        $h = [];
        $mete = function ($rev, array $d) use (&$h, $revisar) {
            if (!in_array($rev, $revisar, true)) return;
            $h[] = array_merge(['revision' => $rev] + self::REVISIONES[$rev],
                ['folio' => '', 'venta_id' => null, 'fecha' => null, 'cliente' => '', 'area' => '',
                 'excel' => null, 'sistema' => null, 'nota' => '', 'hoja' => '', 'fila' => 0], $d);
        };

        foreach ($ex as $folio => $e) {
            $s = $sis[(string)$folio] ?? null;
            $base = ['folio' => (string)$folio, 'fecha' => $e['fecha'], 'cliente' => $e['cliente'],
                     'area' => $e['area'], 'hoja' => $e['hoja'], 'fila' => $e['fila']];
            if ($e['avisos']) {
                $mete('renglon', $base + ['venta_id' => $s ? (int)$s['id'] : null,
                    'nota' => implode(' · ', array_unique($e['avisos']))]);
            }
            if (!$s) {
                $mete('falta_sistema', $base + ['excel' => $e['total'],
                    'nota' => 'Hoja «' . $e['hoja'] . '», fila ' . $e['fila'] . '.']);
                continue;
            }
            $base['venta_id'] = (int)$s['id'];
            if ($s['estado'] === 'cancelada') {
                $mete('cancelada', $base + ['excel' => $e['total'], 'sistema' => (float)$s['total']]);
                continue;
            }
            $dif = function ($rev, $a, $b, $nota = '') use ($mete, $base, $tol) {
                if (abs((float)$a - (float)$b) > $tol + 0.0001) {
                    $mete($rev, $base + ['excel' => round((float)$a, 2), 'sistema' => round((float)$b, 2), 'nota' => $nota]);
                }
            };
            $dif('total', $e['total'], $s['total']);
            $dif('cobrado', $e['cobrado'], $s['cobrado'],
                 $e['cobros'] . ' cobro' . ($e['cobros'] === 1 ? '' : 's') . ' en la hoja, '
                 . (int)$s['cobros'] . ' en el sistema.');
            // Un renglón que no se pudo leer completo no trae sus comisiones:
            // compararlas marcaría una diferencia que es solo de lectura.
            if (!$e['mala']) $dif('comisiones', $e['comisiones'], $s['comisiones']);
            $dif('iva', $e['iva'], $s['iva_cobrado']);
            if (!$e['mala']) $dif('gastos', $e['gastos'], $s['gastos']);

            if (self::norm($e['cliente']) !== self::norm($s['cliente'])) {
                $mete('cliente', $base + ['excel' => $e['cliente'], 'sistema' => $s['cliente']]);
            }
            $fs = substr((string)$s['fecha'], 0, 10);
            if ($e['fecha'] && $e['fecha'] !== $fs) {
                $mete('fecha', $base + ['excel' => date('d/m/Y', strtotime($e['fecha'])),
                                        'sistema' => date('d/m/Y', strtotime($fs))]);
            }
            if ($conArea && trim((string)$s['area']) !== '' && self::norm($e['area']) !== self::norm($s['area'])) {
                $mete('area', $base + ['excel' => $e['area'], 'sistema' => $s['area']]);
            }
        }

        // Lo que la hoja no pudo ni identificar (sin encabezado, sin folio).
        foreach ($sueltos as $r) {
            if (($r['estado'] ?? '') !== 'mala') continue;
            $mete('renglon', ['hoja' => $r['hoja'], 'fila' => $r['fila'],
                'nota' => 'Hoja «' . $r['hoja'] . '»' . ($r['fila'] ? ', fila ' . $r['fila'] : '') . ': '
                        . implode(' ', $r['avisos'] ?? [])]);
        }

        // ── Del sistema, lo que la hoja no trae ──
        if (in_array('falta_excel', $revisar, true)) {
            foreach ($this->ventasDelPeriodo($desde, $hasta) as $v) {
                if (isset($ex[(string)$v['codigo_venta']])) continue;
                $mete('falta_excel', ['folio' => $v['codigo_venta'], 'venta_id' => (int)$v['id'],
                    'fecha' => substr($v['fecha'], 0, 10), 'cliente' => $v['cliente'],
                    'area' => $conArea ? (string)$v['area'] : '', 'sistema' => (float)$v['total']]);
            }
        }

        // ── Filtro por área (de la hoja o del sistema) ──
        $area = self::norm($f['area'] ?? '');
        if ($area !== '') {
            $h = array_values(array_filter($h, function ($x) use ($area) {
                return strpos(self::norm($x['area']), $area) !== false;
            }));
        }

        $orden = ['alta' => 0, 'media' => 1, 'baja' => 2];
        usort($h, function ($a, $b) use ($orden) {
            return ($orden[$a['severidad']] <=> $orden[$b['severidad']]) ?: strcmp((string)$a['folio'], (string)$b['folio']);
        });

        $resumen = array_fill_keys(array_keys(self::REVISIONES), 0);
        $conDif = [];
        foreach ($h as $x) {
            $resumen[$x['revision']]++;
            if ($x['folio'] !== '' && $x['revision'] !== 'falta_excel') $conDif[$x['folio']] = true;
        }
        $enSistema = count(array_filter(array_keys($ex), function ($fo) use ($sis) { return isset($sis[(string)$fo]); }));
        $areas = array_values(array_unique(array_filter(array_column($ex, 'area'))));
        sort($areas);

        return [
            'hallazgos' => $h,
            'resumen'   => $resumen,
            'revisar'   => $revisar,
            'folios'    => count($ex),
            'enSistema' => $enSistema,
            'conDif'    => count($conDif),
            'coinciden' => max(0, $enSistema - count(array_intersect_key($conDif, $sis))),
            'desde'     => $desde,
            'hasta'     => $hasta,
            'hojas'     => $plan['hojas'],
            'areas'     => $areas,
            'tol'       => $tol,
        ];
    }

    /** Las ventas de esos folios, con lo que se compara. */
    private function delSistema(array $folios)
    {
        if (!$folios) return [];
        $iva = ReporteRepo::IVA_DEL_PAGO;
        $area = $this->hayColumna('ventas', 'area_nombre') ? 'v.area_nombre' : "''";
        $out = [];
        foreach (array_chunk($folios, 400) as $parte) {
            $marcas = implode(',', array_fill(0, count($parte), '?'));
            $st = $this->db->prepare("
                SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado, {$area} AS area,
                       COALESCE(c.nombre, 'Público general') AS cliente,
                       (SELECT COUNT(*) FROM venta_pagos p WHERE p.venta_id = v.id AND p.cancelado = 0) AS cobros,
                       (SELECT COALESCE(SUM(p.monto),0) FROM venta_pagos p
                        WHERE p.venta_id = v.id AND p.cancelado = 0) AS cobrado,
                       (SELECT COALESCE(SUM({$iva}),0) FROM venta_pagos p
                        WHERE p.venta_id = v.id AND p.cancelado = 0) AS iva_cobrado,
                       (SELECT COALESCE(SUM(g.monto),0) FROM gastos g
                        WHERE g.venta_id = v.id AND g.tipo = 'manual' AND g.categoria <> 'Costo de venta') AS gastos,
                       (SELECT COALESCE(SUM(pc.monto),0) FROM pago_comisiones pc WHERE pc.venta_id = v.id) AS comisiones
                FROM ventas v
                LEFT JOIN clientes c ON c.id = v.cliente_id
                WHERE v.codigo_venta IN ($marcas)");
            $st->execute($parte);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $out[(string)$v['codigo_venta']] = $v;
        }
        return $out;
    }

    private function ventasDelPeriodo($desde, $hasta)
    {
        $area = $this->hayColumna('ventas', 'area_nombre') ? 'v.area_nombre' : "''";
        $st = $this->db->prepare("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, {$area} AS area,
                   COALESCE(c.nombre, 'Público general') AS cliente
            FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            ORDER BY v.fecha");
        $st->execute([$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hayColumna($tabla, $columna)
    {
        static $hay = [];
        $k = $tabla . '.' . $columna;
        if (!isset($hay[$k])) {
            try {
                $st = $this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$tabla, $columna]);
                $hay[$k] = (int)$st->fetchColumn() > 0;
            } catch (\Throwable $e) { $hay[$k] = false; }
        }
        return $hay[$k];
    }

    /** Para comparar nombres: sin acentos, sin signos, en mayúsculas. */
    public static function norm($s)
    {
        $s = mb_strtoupper(trim((string)$s));
        $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
