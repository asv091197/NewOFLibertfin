<?php
namespace LibertyFin\Datos;

/**
 * Reportes.
 *
 * La cifra que importa no es lo vendido ni lo cobrado: es lo que queda
 * después de pagar lo que la venta costó. Al empezar la migración el
 * sistema decía $558,057.60 de "monto total" sumando `ventas.total`, y
 * lo cobrado de verdad eran $289,468.05.
 *
 * Por eso aquí todo parte del COBRADO y se le restan las tres salidas:
 * gastos de operación, gastos generales y comisiones devengadas.
 *
 * Los criterios de fecha NO son iguales para todo, a propósito:
 *   cobrado             -> venta_pagos.fecha_pago  (cuándo entró el dinero)
 *   gastos de operación -> ventas.fecha            (cuelgan de su venta)
 *   gastos generales    -> gastos.fecha            (cuándo se pagó)
 *   comisiones          -> pago_comisiones.fecha_pago (cuándo entró el pago
 *                          que la generó, igual que el cobrado)
 *
 * Las comisiones iban por ventas.fecha: un abono de octubre a una venta de
 * septiembre entraba en octubre, pero su comisión caía en septiembre. Ahora
 * van con el dinero que las generó.
 *
 * Excepción: las tablas que van POR VENTA (por área, detalle de ventas, por
 * línea) siguen sumando a cada venta del periodo todo lo suyo —cobrado y
 * comisiones—, y aparte enseñan lo que entró de ventas anteriores.
 */
final class ReporteRepo extends Repo
{
    /**
     * Cuánto le toca a un renglón del dinero de su venta, para repartirlo
     * entre áreas. Se usa con `d` (venta_detalles) y `tot` (suma y número
     * de renglones de la venta) unidos con LEFT JOIN:
     *
     *   renglones que suman algo  ->  lo que pesa cada uno
     *   renglones que suman $0    ->  partes iguales
     *   venta SIN renglones       ->  la venta completa, a su área escrita
     *
     * Antes era `d.subtotal / NULLIF(tot.suma,0)` con INNER JOIN: las ventas
     * sin renglones —muchas vienen del sistema anterior— o con renglones en
     * $0 desaparecían de la tabla por área, con todo y sus abonos y
     * liquidaciones.
     */
    const PESO = "CASE WHEN tot.venta_id IS NULL THEN 1
                       WHEN tot.suma = 0 THEN 1.0 / tot.n
                       ELSE d.subtotal / tot.suma END";

    /**
     * ¿Este pago dejó su venta en cero? (1 o 0). Se usa con `p` (el pago) y
     * `v` (su venta). Es el mismo cálculo que "Le falta" del Detalle de
     * pagos —pagos vivos de la venta hasta este, por fecha y luego id—, así
     * que "venta liquidada" y el estado "Liquidación" nunca se contradicen.
     */
    /**
     * Las comisiones devengadas, por PRODUCTO. Se une con `d` y `v` y deja
     * dos columnas:
     *
     *   cp.comision  la de este producto: la comisión dice a cuál va.
     *   cs.comision  la de la venta que no dice de qué producto es —las
     *                viejas, o la de un producto que ya no está—. Esa sí
     *                se reparte con PESO, como lo cobrado.
     *
     * Antes toda la comisión de la venta se repartía con el peso: en una
     * venta de contabilidad y un trámite legal, la comisión del abogado
     * le restaba utilidad también a contabilidad.
     */
    const COMISION_POR_PRODUCTO = "
                LEFT JOIN ( SELECT vc.venta_detalle_id AS detalle_id, SUM(pc.monto) comision
                            FROM pago_comisiones pc
                            INNER JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                            INNER JOIN venta_detalles dd   ON dd.id = vc.venta_detalle_id
                                                          AND dd.venta_id = pc.venta_id
                            GROUP BY vc.venta_detalle_id ) cp ON cp.detalle_id = d.id
                LEFT JOIN ( SELECT pc.venta_id, SUM(pc.monto) comision
                            FROM pago_comisiones pc
                            LEFT JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                            LEFT JOIN venta_detalles dd   ON dd.id = vc.venta_detalle_id
                                                         AND dd.venta_id = pc.venta_id
                            WHERE dd.id IS NULL
                            GROUP BY pc.venta_id ) cs ON cs.venta_id = v.id";

    /**
     * COMISION_POR_PRODUCTO, pero solo lo que devengaron los cobros DEL
     * PERIODO (cpp/csp). Es el criterio del resumen: la comisión cuenta en
     * el mes del cobro que la generó, como el dinero. Las fechas van como
     * literales (citadas por PDO) para no mover los marcadores de quien la usa.
     */
    private function comisionPorProductoEn($a, $b)
    {
        $f = ' AND pc.fecha_pago >= ' . $this->db->quote($a)
           . ' AND pc.fecha_pago < '  . $this->db->quote($b);
        return "
                LEFT JOIN ( SELECT vc.venta_detalle_id AS detalle_id, SUM(pc.monto) comision
                            FROM pago_comisiones pc
                            INNER JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                            INNER JOIN venta_detalles dd   ON dd.id = vc.venta_detalle_id
                                                          AND dd.venta_id = pc.venta_id
                            WHERE 1 = 1 {$f}
                            GROUP BY vc.venta_detalle_id ) cpp ON cpp.detalle_id = d.id
                LEFT JOIN ( SELECT pc.venta_id, SUM(pc.monto) comision
                            FROM pago_comisiones pc
                            LEFT JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                            LEFT JOIN venta_detalles dd   ON dd.id = vc.venta_detalle_id
                                                         AND dd.venta_id = pc.venta_id
                            WHERE dd.id IS NULL {$f}
                            GROUP BY pc.venta_id ) csp ON csp.venta_id = v.id";
    }

    /**
     * El área del producto sobre el que va una comisión devengada (`pc`).
     * NULL si la comisión no dice de qué producto es o el producto no tiene
     * categoría: ahí se cae al área de la venta, como antes.
     */
    const AREA_DE_LA_COMISION = "(
                       SELECT NULLIF(catx.nombre,'')
                       FROM venta_comisiones vcx
                       INNER JOIN venta_detalles dx ON dx.id = vcx.venta_detalle_id
                                                   AND dx.venta_id = vcx.venta_id
                       LEFT JOIN productos px    ON px.id = dx.producto_id
                       LEFT JOIN categorias catx ON catx.id = px.categoria_id
                       WHERE vcx.id = pc.venta_comision_id)";

    const LIQUIDA = "(v.total - (SELECT COALESCE(SUM(pl.monto),0) FROM venta_pagos pl
                        WHERE pl.venta_id = p.venta_id AND pl.cancelado = 0
                          AND (pl.fecha_pago < p.fecha_pago
                               OR (pl.fecha_pago = p.fecha_pago AND pl.id <= p.id)))) <= 0.005";

    /**
     * La parte de IVA de un pago (`p`) de una venta (`v`): el pago por la
     * proporción de IVA de su venta, redondeado al centavo POR PAGO. Una sola
     * definición para el resumen, el detalle y por área: así suman lo mismo.
     */
    const IVA_DEL_PAGO = "CASE WHEN v.total > 0
                               THEN ROUND(p.monto * COALESCE(v.iva,0) / v.total, 2) ELSE 0 END";

    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    /**
     * El resultado del periodo, línea por línea.
     *
     * @param bool $conAnteriores  separar lo cobrado de ventas de antes del
     *                             periodo. La gráfica de meses no lo usa y
     *                             se ahorra la consulta.
     */
    public function resultado($desde, $hasta, $conAnteriores = true)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        $cobrado = (float)$this->valor("
            SELECT COALESCE(SUM(p.monto),0)
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?", [$a, $b]);

        $vendido = (float)$this->valor("
            SELECT COALESCE(SUM(total),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        $operacion = (float)$this->valor("
            SELECT COALESCE(SUM(g.monto),0)
            FROM gastos g INNER JOIN ventas v ON v.id = g.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
              AND g.tipo = 'manual' AND g.categoria <> 'Costo de venta'", [$a, $b]);

        $generales = (float)$this->valor("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?", [$a, $b]);

        // POR LA FECHA DEL PAGO QUE LA GENERÓ, como `cobrado`: la comisión
        // de un abono de octubre a una venta de septiembre es de octubre,
        // igual que el abono. Antes iba por fecha de venta y el dinero caía
        // en un mes y su comisión en otro.
        $comisiones = (float)$this->valor("
            SELECT COALESCE(SUM(pc.monto),0)
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND pc.fecha_pago >= ? AND pc.fecha_pago < ?", [$a, $b]);

        $iva = (float)$this->valor("
            SELECT COALESCE(SUM(iva),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        // EL IVA QUE VINO DENTRO DE LO COBRADO. No es de la empresa: se le
        // paga al SAT. Antes "Queda" lo contaba como propio (las comisiones
        // ya se calculaban sin IVA). Va por la fecha del cobro, como el
        // dinero: cada pago trae la parte de IVA de su venta, redondeada por
        // pago, así cada tabla suma lo mismo (ver IVA_DEL_PAGO).
        $ivaCobrado = (float)$this->valor("
            SELECT COALESCE(SUM(" . self::IVA_DEL_PAGO . "),0)
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?", [$a, $b]);

        // LO QUE ENTRÓ POR VENTAS DE ANTES DEL PERIODO.
        //
        // Ya está dentro de `cobrado`: un abono de octubre sobre una venta de
        // septiembre es dinero de octubre. Se separa para poder DECIRLO,
        // porque las tablas que van por fecha de venta (por área, por
        // cliente, el desglose…) no lo enseñan, y sin esta cifra "Entraron"
        // parecía no cuadrar con nada.
        $anteriores = $conAnteriores ? $this->deAnteriores($desde, $hasta)
                                     : ['monto' => 0, 'cobros' => 0, 'ventas' => 0];

        // LO QUE TODAVÍA DEBEN LAS VENTAS DEL PERIODO.
        //
        // Se calculaba como "facturado − cobrado", pero el cobrado es por
        // fecha de pago e incluye los abonos de ventas de meses anteriores:
        // en octubre de 2026 entraron $4,500 de ventas de agosto, la resta
        // daba negativo y la pantalla decía "quedan $0.00 sin cobrar"
        // cuando una venta del mes debía $900. Ahora es el saldo de cada
        // venta del periodo, con todo lo que se le ha cobrado.
        $porCobrar = (float)$this->valor("
            SELECT COALESCE(SUM(GREATEST(v.total - COALESCE(pg.cobrado,0), 0)),0)
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?", [$a, $b]);

        return [
            'de_anteriores'        => round((float)$anteriores['monto'], 2),
            'de_anteriores_cobros' => (int)$anteriores['cobros'],
            'de_anteriores_ventas' => (int)$anteriores['ventas'],
            'de_anteriores_liquidadas' => (int)($anteriores['liquidadas'] ?? 0),
            // La parte de `comisiones` que generaron esos cobros.
            'de_anteriores_comisiones' => round((float)($anteriores['comisiones'] ?? 0), 2),
            'cobrado'    => $cobrado,
            'vendido'    => $vendido,
            'por_cobrar' => round($porCobrar, 2),
            'iva'        => $iva,
            'iva_cobrado'=> round($ivaCobrado, 2),
            'operacion'  => $operacion,
            'generales'  => $generales,
            'comisiones' => $comisiones,
            'queda'      => round($cobrado - $operacion - $generales - $comisiones - $ivaCobrado, 2),
        ];
    }

    /**
     * Para el Panel: lo que entró en el periodo —la misma cifra que
     * «Entraron» de resultado()— y de dónde: de las ventas del periodo o de
     * ventas anteriores. Dos consultas, no todo el resultado.
     */
    public function entro($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $sql = "SELECT COALESCE(SUM(p.monto),0)
                FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                  AND p.fecha_pago >= ? AND p.fecha_pago < ?";
        $total = (float)$this->valor($sql, [$a, $b]);
        $ant   = (float)$this->valor($sql . " AND v.fecha < ?", [$a, $b, $a]);
        return ['total' => round($total, 2), 'anteriores' => round($ant, 2),
                'nuevas' => round($total - $ant, 2)];
    }

    /** Lo mismo mes a mes, para ver la tendencia. */
    public function porMes($meses = 6)
    {
        $desde = date('Y-m-01', strtotime("-" . ((int)$meses - 1) . " month"));
        $salida = [];
        for ($i = 0; $i < $meses; $i++) {
            $m  = date('Y-m', strtotime("+{$i} month", strtotime($desde)));
            $r  = $this->resultado($m . '-01', date('Y-m-t', strtotime($m . '-01')), false);
            $r['mes'] = $m;
            $salida[] = $r;
        }
        return $salida;
    }

    /**
     * Lo que entró en el periodo por ventas de ANTES del periodo: anticipos
     * y abonos que llegaron ahora sobre ventas de otro mes.
     *
     * Mismos filtros que el "cobrado" de resultado() más la fecha de la
     * venta, así que es la parte de "Entraron" que las tablas por fecha de
     * venta no enseñan.
     *
     * @return array ['monto' => float, 'cobros' => int, 'ventas' => int]
     */
    public function deAnteriores($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $liq = self::LIQUIDA;
        $r = $this->uno("
            SELECT COALESCE(SUM(p.monto),0)   AS monto,
                   COUNT(*)                   AS cobros,
                   COUNT(DISTINCT p.venta_id) AS ventas,
                   COUNT(DISTINCT CASE WHEN {$liq} THEN p.venta_id END) AS liquidadas
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
              AND v.fecha < ?", [$a, $b, $a]);
        // Las comisiones que generaron esos cobros: cuentan en este periodo,
        // como el cobro. Ver resultado().
        $com = (float)$this->valor("
            SELECT COALESCE(SUM(pc.monto),0)
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada'
              AND pc.fecha_pago >= ? AND pc.fecha_pago < ?
              AND v.fecha < ?", [$a, $b, $a]);
        return ['monto'      => round((float)($r['monto'] ?? 0), 2),
                'cobros'     => (int)($r['cobros'] ?? 0),
                'ventas'     => (int)($r['ventas'] ?? 0),
                // Ventas de antes que quedaron pagadas en el periodo.
                'liquidadas' => (int)($r['liquidadas'] ?? 0),
                'comisiones' => round($com, 2)];
    }

    /**
     * Las comisiones que generaron en el periodo los cobros a ventas de
     * ANTES del periodo, por área. Acompaña a anterioresPorArea().
     *
     * La comisión que dice de qué producto es va completa al área de ese
     * producto; la que no (las viejas) se reparte con PESO, como el cobro.
     */
    public function comisionesAnterioresPorArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $peso = self::PESO;
        return $this->todos("
            SELECT area, ROUND(SUM(monto), 2) AS monto
            FROM (
                SELECT COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       CASE WHEN dl.id IS NOT NULL
                            THEN CASE WHEN d.id = dl.id THEN pc.monto ELSE 0 END
                            ELSE pc.monto * ({$peso}) END AS monto
                FROM pago_comisiones pc
                INNER JOIN ventas v           ON v.id = pc.venta_id
                LEFT JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                LEFT JOIN venta_detalles dl   ON dl.id = vc.venta_detalle_id AND dl.venta_id = pc.venta_id
                LEFT JOIN venta_detalles d    ON d.venta_id = v.id
                LEFT JOIN productos pr        ON pr.id = d.producto_id
                LEFT JOIN categorias cat      ON cat.id = pr.categoria_id
                LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                            FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                WHERE v.estado <> 'cancelada'
                  AND pc.fecha_pago >= ? AND pc.fecha_pago < ?
                  AND v.fecha < ?
            ) x
            GROUP BY area
            HAVING ABS(SUM(monto)) > 0.004", [$a, $b, $a]);
    }

    /**
     * Lo mismo que deAnteriores(), pero POR ÁREA.
     *
     * Con el mismo criterio que porArea(): el área sale del producto de cada
     * renglón de la venta, y el pago se reparte entre sus renglones según lo
     * que pesa cada uno. Un abono de $3,000 a una venta que fue mitad legal
     * y mitad contable pone $1,500 en cada área.
     *
     * `cobros` cuenta los pagos que tocan el área. Un pago repartido entre
     * dos áreas cuenta en las dos, así que la suma por área puede pasar del
     * total de cobros; el total real es el de deAnteriores().
     */
    public function anterioresPorArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $peso = self::PESO;
        $liq  = self::LIQUIDA;
        return $this->todos("
            SELECT area,
                   COUNT(DISTINCT pago_id) AS cobros,
                   COUNT(DISTINCT CASE WHEN liquida = 1 THEN venta_id END) AS liquidadas,
                   ROUND(SUM(monto), 2)    AS monto
            FROM (
                SELECT p.id AS pago_id, v.id AS venta_id,
                       ({$liq}) AS liquida,
                       COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       p.monto * ({$peso}) AS monto
                FROM venta_pagos p
                INNER JOIN ventas v        ON v.id = p.venta_id
                LEFT JOIN venta_detalles d ON d.venta_id = v.id
                LEFT JOIN productos pr     ON pr.id = d.producto_id
                LEFT JOIN categorias cat   ON cat.id = pr.categoria_id
                LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                            FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                  AND p.fecha_pago >= ? AND p.fecha_pago < ?
                  AND v.fecha < ?
            ) x
            GROUP BY area
            ORDER BY monto DESC", [$a, $b, $a]);
    }

    /**
     * Por área: las ventas del periodo y, además, lo que entró por ventas de
     * meses anteriores.
     *
     * Cada renglón trae `cobros_ant` y `de_anteriores`: los anticipos y
     * abonos que llegaron en el periodo sobre ventas de antes, de esa área.
     * Un área que en el periodo SOLO recibió eso —ninguna venta nueva— se
     * agrega al final con lo demás en cero: si no, ese dinero no salía en
     * ningún lado de la tabla.
     *
     * @param bool $conAnteriores  false = solo las ventas del periodo.
     */
    public function porArea($desde, $hasta, $conAnteriores = true)
    {
        $filas = $this->porAreaVentas($desde, $hasta);
        if (!$conAnteriores) return $filas;

        // Se cruzan por nombre SIN distinguir mayúsculas ni espacios, como
        // agrupa MySQL: "Legales" y "LEGALES" son la misma área, y cruzarlas
        // al pie de la letra daba dos filas, una de ellas "sin ventas".
        $clave = function ($s) { return mb_strtolower(trim((string)$s)); };
        $ant = [];
        foreach ($this->anterioresPorArea($desde, $hasta) as $x) $ant[$clave($x['area'])] = $x;
        // Las comisiones que generaron esos cobros, también de este periodo.
        $comAnt = [];
        foreach ($this->comisionesAnterioresPorArea($desde, $hasta) as $x) {
            $comAnt[$clave($x['area'])] = ['area' => $x['area'], 'monto' => (float)$x['monto']];
        }

        foreach ($filas as $i => $f) {
            $k = $clave($f['area']);
            $x = $ant[$k] ?? null;
            $filas[$i]['cobros_ant']     = $x ? (int)$x['cobros'] : 0;
            $filas[$i]['liquidadas_ant'] = $x ? (int)$x['liquidadas'] : 0;
            $filas[$i]['de_anteriores']  = $x ? round((float)$x['monto'], 2) : 0.0;
            $filas[$i]['comisiones_ant'] = isset($comAnt[$k]) ? round($comAnt[$k]['monto'], 2) : 0.0;
            unset($ant[$k], $comAnt[$k]);
        }
        foreach ($ant as $k => $x) {
            $filas[] = ['area' => $x['area'], 'ventas' => 0, 'vendido' => 0, 'cobrado' => 0,
                        'gastos' => 0, 'comisiones' => 0,
                        'cobros_ant'     => (int)$x['cobros'],
                        'liquidadas_ant' => (int)$x['liquidadas'],
                        'de_anteriores'  => round((float)$x['monto'], 2),
                        'comisiones_ant' => isset($comAnt[$k]) ? round($comAnt[$k]['monto'], 2) : 0.0];
            unset($comAnt[$k]);
        }
        // Comisión de un cobro anterior en un área sin cobros anteriores: no
        // debería pasar (la comisión sale del cobro), pero si el área del
        // producto comisionado no coincide con el reparto, que no se pierda.
        foreach ($comAnt as $x) {
            $filas[] = ['area' => $x['area'], 'ventas' => 0, 'vendido' => 0, 'cobrado' => 0,
                        'gastos' => 0, 'comisiones' => 0, 'cobros_ant' => 0, 'liquidadas_ant' => 0,
                        'de_anteriores' => 0.0, 'comisiones_ant' => round($x['monto'], 2)];
        }

        // EL RESULTADO DEL ÁREA EN EL PERIODO, con el criterio del resumen:
        // todo lo que entró (de ventas nuevas y de anteriores), sus gastos y
        // todas las comisiones que generó. Antes "Queda" y el margen eran
        // solo de las ventas nuevas: Legales salía con 60% cuando, contando
        // los $4,500 que entraron de agosto y sus $3,930 de comisión, le
        // quedó el 27%. Así las áreas suman lo mismo que "Queda".
        // Y el IVA que vino en lo cobrado (de ventas nuevas y anteriores): no
        // es del área, se le paga al SAT. Ver resultado().
        $iva = [];
        foreach ($this->ivaPorArea($desde, $hasta) as $x) $iva[$clave($x['area'])] = (float)$x['iva'];
        foreach ($filas as &$f) {
            $f['cobrado_total']    = round((float)$f['cobrado'] + (float)$f['de_anteriores'], 2);
            $f['comisiones_total'] = round((float)$f['comisiones'] + (float)$f['comisiones_ant'], 2);
            $f['iva']   = round($iva[$clave($f['area'])] ?? 0, 2);
            $f['queda'] = round($f['cobrado_total'] - (float)$f['gastos'] - $f['comisiones_total'] - $f['iva'], 2);
        }
        unset($f);
        usort($filas, function ($x, $y) { return $y['cobrado_total'] <=> $x['cobrado_total']; });
        return $filas;
    }

    /**
     * El IVA de los cobros del periodo (de cualquier venta), por área, con el
     * mismo reparto que lo cobrado (PESO). Ver IVA_DEL_PAGO.
     */
    private function ivaPorArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $peso = self::PESO;
        return $this->todos("
            SELECT area, ROUND(SUM(iva), 2) AS iva
            FROM (
                SELECT COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       (" . self::IVA_DEL_PAGO . ") * ({$peso}) AS iva
                FROM venta_pagos p
                INNER JOIN ventas v        ON v.id = p.venta_id
                LEFT JOIN venta_detalles d ON d.venta_id = v.id
                LEFT JOIN productos pr     ON pr.id = d.producto_id
                LEFT JOIN categorias cat   ON cat.id = pr.categoria_id
                LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                            FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada' AND COALESCE(v.iva,0) <> 0
                  AND p.fecha_pago >= ? AND p.fecha_pago < ?
            ) x
            GROUP BY area", [$a, $b]);
    }

    /** Las ventas del periodo por área (sin lo de ventas anteriores). */
    private function porAreaVentas($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        // EL ÁREA SALE DEL PRODUCTO, NO DE LA VENTA NI DEL CLIENTE.
        //
        // `ventas.area_nombre` guarda UN área por venta, y eso está mal
        // en cuanto alguien contrata dos cosas: un cliente que pide un
        // juicio y su contabilidad genera una sola venta, y todo el
        // dinero se le cargaba a un área sola. El mismo cliente aparecía
        // en Legal un mes y en Contabilidad el siguiente, según qué se
        // capturó primero.
        //
        // Aquí cada RENGLÓN lleva el área de su producto, y el dinero de
        // la venta —lo cobrado, los gastos y las comisiones— se reparte
        // entre sus renglones en proporción a lo que pesa cada uno.
        //
        // Si un producto no tiene categoría se cae al área de la venta, y
        // si tampoco, a "Sin área": es preferible un renglón honesto que
        // perder la venta del reporte.
        //
        // Por lo mismo, se parte de la VENTA y no de sus renglones: una venta
        // sin renglones (muchas vienen del sistema anterior) o con renglones
        // en $0 desaparecía de esta tabla con todo y lo cobrado. Ver PESO.
        //
        // Y LO COBRADO Y SUS COMISIONES, SOLO LO DEL PERIODO. Antes era todo lo
        // de cada venta, aunque llegara después: consultar septiembre un mes
        // más tarde metía en septiembre los abonos de octubre, y el reporte
        // dejaba de cuadrar con "Entraron" y "Queda", que van por la fecha
        // del cobro. Ahora es el mismo criterio que el resumen.
        $peso = self::PESO;
        $cpro = $this->comisionPorProductoEn($a, $b);
        return $this->todos("
            SELECT area,
                   COUNT(DISTINCT venta_id)  AS ventas,
                   ROUND(SUM(vendido),2)     AS vendido,
                   ROUND(SUM(cobrado),2)     AS cobrado,
                   ROUND(SUM(gastos),2)      AS gastos,
                   ROUND(SUM(comisiones),2)  AS comisiones
            FROM (
                SELECT v.id AS venta_id,
                       COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       -- SE REPARTE `v.total`, NO SE SUMA `d.subtotal`.
                       --
                       -- No siempre son lo mismo: el IVA, los descuentos
                       -- y los ajustes se aplican a la venta, no a sus
                       -- renglones. Sumar los renglones perdía esa
                       -- diferencia —en este histórico, $655.85 que
                       -- salían de un área y no entraban a ninguna—.
                       --
                       -- El renglón solo decide la PROPORCIÓN. Lo que se
                       -- reparte es el total de la venta, así el reporte
                       -- siempre suma lo mismo que las ventas.
                       v.total                 * ({$peso}) AS vendido,
                       COALESCE(pg.cobrado,0)  * ({$peso}) AS cobrado,
                       COALESCE(g.gastos,0)    * ({$peso}) AS gastos,
                       -- La comisión de un producto es de SU área; solo
                       -- la que no dice de qué producto es (las viejas) se
                       -- reparte con el peso, como lo demás.
                       COALESCE(cpp.comision,0)
                         + COALESCE(csp.comision,0) * ({$peso}) AS comisiones
                FROM ventas v
                LEFT JOIN venta_detalles d ON d.venta_id = v.id
                LEFT JOIN productos p      ON p.id = d.producto_id
                LEFT JOIN categorias cat   ON cat.id = p.categoria_id
                LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                            FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                            WHERE cancelado = 0 AND fecha_pago >= ? AND fecha_pago < ?
                            GROUP BY venta_id ) pg ON pg.venta_id = v.id
                LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                            WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                            GROUP BY venta_id ) g ON g.venta_id = v.id
                {$cpro}
                WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            ) x
            GROUP BY area ORDER BY cobrado DESC", [$a, $b, $a, $b]);
    }

    /**
     * Lo vendido por producto. `$tope` = 0: todos (el reporte); con número,
     * los que más venden (la gráfica de la pantalla).
     *
     * SE REPARTE `v.total`, COMO EN porAreaVentas(). Antes se sumaba el
     * precio de cada renglón, y ese precio no siempre es lo mismo: con IVA
     * incluido el renglón ya lo trae y con IVA agregado no (se suma aparte en
     * la venta), y los descuentos de la venta no están en sus renglones. En
     * septiembre de 2026 daba $255.85 menos que las ventas: el IVA de una
     * venta con IVA agregado. Además se perdían las ventas sin renglones o
     * con un renglón sin producto (ahora van en "Sin producto"), y el
     * reporte salía cortado a los 12 que más vendían. El renglón decide solo
     * la PROPORCIÓN; así el reporte suma lo mismo que las ventas.
     */
    public function porServicio($desde, $hasta, $tope = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $peso = self::PESO;
        return $this->todos("
            SELECT COALESCE(p.id, 0)                 AS producto_id,
                   COALESCE(p.nombre, 'Sin producto') AS nombre,
                   COALESCE(p.codigo, '')            AS codigo,
                   COUNT(*)                          AS veces,
                   ROUND(SUM(v.total * ({$peso})), 2) AS facturado
            FROM ventas v
            LEFT JOIN venta_detalles d ON d.venta_id = v.id
            LEFT JOIN productos p      ON p.id = d.producto_id
            LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                        FROM venta_detalles GROUP BY venta_id ) tot
                   ON tot.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY COALESCE(p.id, 0), COALESCE(p.nombre, 'Sin producto'), COALESCE(p.codigo, '')
            ORDER BY facturado DESC" . ((int)$tope > 0 ? " LIMIT " . (int)$tope : ''), [$a, $b]);
    }

    public function porMetodo($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(p.metodo_pago,''),'sin método') AS metodo,
                   SUM(p.monto) AS monto, COUNT(*) AS cobros
            FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            GROUP BY metodo ORDER BY monto DESC", [$a, $b]);
    }

    public function porColaborador($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $areaCom = self::AREA_DE_LA_COMISION;
        return $this->todos("
            -- El area es la del SERVICIO vendido, no el equipo de quien
            -- comisiono: una venta de contabilidad cerrada por alguien
            -- de Administracion es de contabilidad. Si la comision dice
            -- de que producto es, el area de ese producto.
            SELECT pc.colaborador_nombre AS nombre,
                   GROUP_CONCAT(DISTINCT COALESCE({$areaCom}, (
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area')
                       ORDER BY 1 SEPARATOR ', ') AS area,
                   ROUND(SUM(pc.monto),2) AS devengado,
                   COUNT(*) AS ventas
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND pc.fecha_pago >= ? AND pc.fecha_pago < ?
            GROUP BY pc.colaborador_nombre
            ORDER BY (pc.colaborador_nombre = 'POR ASIGNAR'), devengado DESC", [$a, $b]);
    }

    /**
     * Una VENTA por renglón: las ventas hechas en el periodo.
     *
     * Su "cobrado" es todo lo que se le ha cobrado a cada venta, sin
     * importar cuándo. Lo que ENTRÓ en el periodo —con los abonos de ventas
     * de meses anteriores— está en pagosDelPeriodo().
     *
     * Las canceladas no entran: antes sí, y su total se sumaba al de la
     * pestaña aunque ninguna otra tabla las contara.
     *
     * Y ADEMÁS, al final, las ventas de ANTES del periodo que tuvieron cobros
     * en él (anticipos, abonos, liquidaciones): con `anterior` = true y
     * `cobrado_periodo`, lo que entró de ellas en el periodo. Antes esta tabla
     * no las enseñaba y había que ir al Detalle de pagos a buscarlas.
     * `liquidada` dice si con esos cobros quedó pagada.
     */
    public function detalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $filas = $this->todos("
            SELECT v.id AS venta_id, v.codigo_venta AS folio, DATE(v.fecha) AS fecha,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   -- Mismo criterio que el resumen: el área del producto
                   -- de la línea, no la de la venta.
                   COALESCE(NULLIF((
                       SELECT cat.nombre FROM venta_detalles d2
                       LEFT JOIN productos p2  ON p2.id = d2.producto_id
                       LEFT JOIN categorias cat ON cat.id = p2.categoria_id
                       WHERE d2.venta_id = v.id AND cat.nombre IS NOT NULL
                       GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1
                   ),''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                   v.subtotal, v.iva, v.total,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(g.gastos,0) AS gastos,
                   COALESCE(cm.comision,0) AS comision,
                   -- La que generaron los cobros DEL PERIODO: la que cuenta
                   -- en «Queda», como el dinero (ver resultado()).
                   COALESCE(cmp.comision,0) AS comision_periodo,
                   -- El IVA que vino en lo cobrado en el periodo (no es de
                   -- la empresa). Ver IVA_DEL_PAGO.
                   COALESCE(ivp.iva,0) AS iva_periodo,
                   COALESCE(pp.monto,0) AS cobrado_periodo,
                   COALESCE(pp.n,0)     AS cobros_periodo,
                   pg.ultimo,
                   v.estado
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            -- Lo que entró EN EL PERIODO de cada venta, sea del periodo o de antes.
            LEFT JOIN ( SELECT venta_id, COUNT(*) n, SUM(monto) monto FROM venta_pagos
                        WHERE cancelado = 0 AND fecha_pago >= ? AND fecha_pago < ?
                        GROUP BY venta_id ) pp ON pp.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY venta_id ) cm ON cm.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        WHERE fecha_pago >= ? AND fecha_pago < ?
                        GROUP BY venta_id ) cmp ON cmp.venta_id = v.id
            LEFT JOIN ( SELECT p.venta_id, SUM(" . self::IVA_DEL_PAGO . ") iva
                        FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
                        WHERE p.cancelado = 0 AND p.fecha_pago >= ? AND p.fecha_pago < ?
                        GROUP BY p.venta_id ) ivp ON ivp.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND ((v.fecha >= ? AND v.fecha < ?) OR (v.fecha < ? AND pp.venta_id IS NOT NULL))
            ORDER BY (v.fecha < ?), v.fecha", [$a, $b, $a, $b, $a, $b, $a, $b, $a, $a]);
        return $this->marcarAnteriores($filas, $a, $b);
    }

    /**
     * A cada fila (una venta o una línea de venta, con `fecha`, `total`,
     * `ultimo` y el cobrado de su venta) le pone `anterior` (la venta es de
     * antes del periodo) y `liquidada` (quedó pagada con un cobro del
     * periodo).
     */
    private function marcarAnteriores(array $filas, $a, $b)
    {
        $ia = substr($a, 0, 10); $ib = substr($b, 0, 10);
        foreach ($filas as &$f) {
            $f['anterior']  = substr((string)$f['fecha'], 0, 10) < $ia;
            $ultimo = isset($f['ultimo']) ? substr((string)$f['ultimo'], 0, 10) : '';
            $saldo  = isset($f['saldo_venta']) ? (float)$f['saldo_venta'] : (float)($f['saldo'] ?? 1);
            $f['liquidada'] = $f['anterior'] && $saldo <= 0.005 && $ultimo >= $ia && $ultimo < $ib;
        }
        unset($f);
        return $filas;
    }

    /**
     * UN RENGLÓN POR COBRO: lo que entró en el periodo, venga de la venta
     * que venga.
     *
     * Va por `venta_pagos.fecha_pago`, con los mismos filtros que el
     * "Entraron" de resultado(), así que la suma de `monto` es exactamente
     * esa cifra. Es la tabla que enseña lo que las tablas por fecha de
     * venta no pueden: el anticipo o abono que llegó este mes sobre una
     * venta del mes pasado, y a qué venta pertenece.
     *
     * `posicion` sirve para armar el subfolio de los cobros guardados antes
     * de que existiera (ver Folio::deRespaldo); se cuenta igual que en el
     * detalle de la venta: por fecha y luego por id, con cancelados.
     *
     * `le_falta` es lo que la venta quedó debiendo DESPUÉS DE ESE PAGO, no
     * lo que debe hoy. En un renglón de pago eso es lo que se lee: "pagó
     * tanto y le faltan tanto". El saldo de hoy confundía: en un abono de
     * hace tres semanas enseñaba lo que faltaba después de abonos que
     * todavía no existían cuando se hizo. Se suman los pagos vivos de la
     * misma venta hasta este, en el mismo orden (fecha y luego id).
     */
    public function pagosDelPeriodo($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.id, p.folio, p.tipo, p.monto, p.fecha_pago, p.metodo_pago,
                   v.id AS venta_id, v.codigo_venta, v.fecha AS fecha_venta, v.total,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   COALESCE(NULLIF((
                       SELECT cat.nombre FROM venta_detalles d2
                       LEFT JOIN productos p2  ON p2.id = d2.producto_id
                       LEFT JOIN categorias cat ON cat.id = p2.categoria_id
                       WHERE d2.venta_id = v.id AND cat.nombre IS NOT NULL
                       GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1
                   ),''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                   ROUND(v.total - (
                       SELECT COALESCE(SUM(p4.monto),0) FROM venta_pagos p4
                       WHERE p4.venta_id = p.venta_id AND p4.cancelado = 0
                         AND (p4.fecha_pago < p.fecha_pago
                              OR (p4.fecha_pago = p.fecha_pago AND p4.id <= p.id))
                   ), 2) AS le_falta,
                   -- Cuántos pagos vivos tuvo la venta ANTES de este: con cero
                   -- es el primero (anticipo o pago completo); si no, es un
                   -- abono o la liquidación de un saldo.
                   (SELECT COUNT(*) FROM venta_pagos p6
                    WHERE p6.venta_id = p.venta_id AND p6.cancelado = 0
                      AND (p6.fecha_pago < p.fecha_pago
                           OR (p6.fecha_pago = p.fecha_pago AND p6.id < p.id))
                   ) AS pagos_antes,
                   CASE WHEN p.folio IS NULL OR p.folio = '' THEN (
                       SELECT COUNT(*) FROM venta_pagos p3
                       WHERE p3.venta_id = p.venta_id
                         AND (p3.fecha_pago < p.fecha_pago
                              OR (p3.fecha_pago = p.fecha_pago AND p3.id <= p.id))
                   ) END AS posicion
            FROM venta_pagos p
            INNER JOIN ventas v  ON v.id = p.venta_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            ORDER BY p.fecha_pago, p.id", [$a, $b]);
    }

    /**
     * Qué compró cada cliente y qué debe.
     *
     * El área es la de mayor peso en dinero entre lo que contrató, no un
     * dato capturado: un cliente que pidió un juicio y su contabilidad
     * pertenece a las dos, y lo honesto es decir cuál pesa más.
     */
    public function porCliente($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(v.cliente_id, 0) AS cliente_id,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   (SELECT cat.nombre
                    FROM venta_detalles d2
                    INNER JOIN ventas v2    ON v2.id = d2.venta_id
                    LEFT  JOIN productos p2 ON p2.id = d2.producto_id
                    LEFT  JOIN categorias cat ON cat.id = p2.categoria_id
                    WHERE v2.cliente_id = v.cliente_id AND v2.estado <> 'cancelada'
                      AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                    GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1) AS area,
                   COUNT(DISTINCT v.id)        AS compras,
                   COALESCE(SUM(v.total),0)    AS vendido,
                   COALESCE(SUM(pg.cobrado),0) AS cobrado
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY v.cliente_id, cliente
            ORDER BY vendido DESC", [$a, $b]);
    }

    /**
     * Lo que falta por cobrar.
     *
     * De lo más viejo a lo más nuevo: una deuda de noventa días necesita
     * otra conversación que una de tres, y ordenarla por monto esconde
     * justo las que llevan más tiempo.
     */
    public function cobranza($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Con las ventas de ANTES del periodo que abonaron en él y todavía
        // deben: un abono de este mes a una venta vieja también es cobranza
        // que se está moviendo. Van marcadas (`anterior`).
        $filas = $this->todos("
            SELECT v.id AS venta_id, v.codigo_venta, v.fecha,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   v.total,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   ROUND(v.total - COALESCE(pg.cobrado,0), 2) AS saldo,
                   COALESCE(pp.monto,0) AS cobrado_periodo,
                   pg.ultimo,
                   DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                        FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                   ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) monto FROM venta_pagos
                        WHERE cancelado = 0 AND fecha_pago >= ? AND fecha_pago < ?
                        GROUP BY venta_id ) pp ON pp.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND ((v.fecha >= ? AND v.fecha < ?) OR (v.fecha < ? AND pp.venta_id IS NOT NULL))
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY (v.fecha < ?), dias DESC, saldo DESC", [$a, $b, $a, $b, $a, $a]);
        return $this->marcarAnteriores($filas, $a, $b);
    }

    /**
     * Por producto: lo que entró en el periodo por ventas de ANTES del
     * periodo. Cada cobro se reparte entre las líneas de su venta según lo
     * que pesa cada una, igual que en el reporte por área.
     *
     * @return array ['filas' => [producto_id => [nombre, codigo, cobros,
     *               monto]], 'cobros' => cobros distintos, 'monto' => total]
     */
    public function servicioAnteriores($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Como porServicio(): las ventas sin renglones o con un renglón sin
        // producto van en "Sin producto" en vez de perderse, así esta columna
        // suma lo mismo que "De lo que entró…" (deAnteriores()).
        $peso = self::PESO;
        $filas = $this->todos("
            SELECT COALESCE(p.id, 0)                 AS producto_id,
                   COALESCE(p.nombre, 'Sin producto') AS nombre,
                   COALESCE(p.codigo, '')            AS codigo,
                   COUNT(DISTINCT pa.id)                    AS cobros,
                   ROUND(SUM(pa.monto * ({$peso})), 2)      AS monto
            FROM venta_pagos pa
            INNER JOIN ventas v        ON v.id = pa.venta_id
            LEFT JOIN venta_detalles d ON d.venta_id = v.id
            LEFT JOIN productos p      ON p.id = d.producto_id
            LEFT JOIN ( SELECT venta_id, SUM(subtotal) suma, COUNT(*) n
                        FROM venta_detalles GROUP BY venta_id ) tot ON tot.venta_id = v.id
            WHERE pa.cancelado = 0 AND v.estado <> 'cancelada'
              AND pa.fecha_pago >= ? AND pa.fecha_pago < ? AND v.fecha < ?
            GROUP BY COALESCE(p.id, 0), COALESCE(p.nombre, 'Sin producto'), COALESCE(p.codigo, '')
            ORDER BY monto DESC", [$a, $b, $a]);
        // Un cobro de una venta con dos productos cuenta en los dos renglones:
        // el total de cobros se cuenta aparte, sin repetir.
        $tot = $this->uno("
            SELECT COUNT(DISTINCT pa.id) AS cobros
            FROM venta_pagos pa
            INNER JOIN ventas v ON v.id = pa.venta_id
            WHERE pa.cancelado = 0 AND v.estado <> 'cancelada'
              AND pa.fecha_pago >= ? AND pa.fecha_pago < ? AND v.fecha < ?",
            [$a, $b, $a]) ?: ['cobros' => 0];
        $r = [];
        foreach ($filas as $f) $r[(int)$f['producto_id']] = $f;
        return ['filas' => $r, 'cobros' => (int)$tot['cobros'],
                'monto' => round(array_sum(array_column($filas, 'monto')), 2)];
    }

    /**
     * Por cliente: lo que entró en el periodo por ventas suyas de ANTES del
     * periodo, cuántos cobros fueron y cuántas de esas ventas quedaron
     * pagadas. [cliente_id => [cliente, cobros, monto, liquidadas]]
     * (cliente_id 0 es Público general).
     */
    public function clienteAnteriores($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $liq = self::LIQUIDA;
        $filas = $this->todos("
            SELECT COALESCE(v.cliente_id, 0)             AS cliente_id,
                   COALESCE(c.nombre, 'Público general') AS cliente,
                   COUNT(*)                              AS cobros,
                   ROUND(SUM(p.monto), 2)                AS monto,
                   COUNT(DISTINCT CASE WHEN {$liq} THEN p.venta_id END) AS liquidadas
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ? AND v.fecha < ?
            GROUP BY COALESCE(v.cliente_id, 0), cliente
            ORDER BY monto DESC", [$a, $b, $a]);
        $r = [];
        foreach ($filas as $f) $r[(int)$f['cliente_id']] = $f;
        return $r;
    }

    /** Lo cobrado día por día. */
    public function porDia($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT DATE(p.fecha_pago) AS dia,
                   COUNT(*) AS pagos,
                   COALESCE(SUM(p.monto),0) AS cobrado
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            GROUP BY dia ORDER BY dia", [$a, $b]);
    }

    /**
     * El detalle, UN RENGLON POR LINEA DE VENTA.
     *
     * POR QUE POR LINEA Y NO POR VENTA
     *
     * Para poner la columna "Producto" hace falta saber de cual se
     * habla, y una venta puede tener varios. Por venta habria que
     * amontonarlos en una celda, y entonces no se puede filtrar ni
     * sumar por producto.
     *
     * El dinero de la venta se reparte entre sus lineas segun lo que
     * pesa cada una, igual que en el reporte por area. Asi la suma de
     * los renglones sigue dando el total de las ventas.
     *
     * LAS DOS AREAS, Y POR QUE VAN LAS DOS
     *
     *   area_servicio  de que fue el trabajo (la categoria del producto)
     *   area_origen    de donde salio la venta (el area escrita en ella)
     *
     * Una venta hecha en Administracion que incluye un servicio
     * contable pertenece a las dos: a Administracion por origen, a
     * Contabilidad por trabajo. No hay que elegir una; hay que poder
     * agrupar por cualquiera de las dos.
     *
     * Y dentro de contabilidad se separa persona fisica de moral, que
     * es la division que lleva la oficina.
     */
    public function detallePorLinea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        // El especialista llega con la migracion 10, que se aplica al
        // ENTRAR. Quien ya tenia sesion abierta al actualizar todavia
        // no la tiene, y el reporte reventaba con 'Unknown column'.
        //
        // Un reporte no puede depender de que alguien haya vuelto a
        // entrar: si la columna no esta, se devuelve 'Sin asignar' y
        // todo lo demas sigue funcionando.
        $hayEsp = $this->hayColumna('ventas', 'especialista_nombre');
        $esp = $hayEsp
             ? "COALESCE(NULLIF(TRIM(v.especialista_nombre),''), 'Sin asignar')"
             : "'Sin asignar'";
        $cpro = self::COMISION_POR_PRODUCTO;
        // Y la que devengaron los cobros DEL PERIODO (cpp/csp): la que cuenta
        // en el resultado del periodo, como el dinero.
        $cpro .= $this->comisionPorProductoEn($a, $b);

        // Con las líneas de las ventas de ANTES del periodo que tuvieron
        // cobros en él: van marcadas (`anterior`) y con `cobrado_periodo`,
        // lo que entró de esa línea en el periodo.
        $filas = $this->todos("
            SELECT v.id                                    AS venta_id,
                   v.codigo_venta                          AS folio,
                   v.fecha,
                   COALESCE(cl.nombre, 'Publico general')  AS cliente,
                   COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin area')
                                                           AS area_servicio,
                   COALESCE(NULLIF(v.area_nombre,''), NULLIF(cat.nombre,''), 'Sin area')
                                                           AS area_origen,
                   -- Dentro de contabilidad: fisica o moral.
                   -- Se mira el nombre del servicio, que es lo unico que
                   -- lo dice hoy. 'PF' y 'PM' cuentan: asi se escriben
                   -- varios, y buscar solo 'FISICA' los dejaba fuera.
                   CASE
                     WHEN UPPER(COALESCE(cat.nombre,'')) NOT LIKE '%CONTAB%' THEN NULL
                     WHEN UPPER(p.nombre) LIKE '%MORAL%'
                       OR UPPER(p.nombre) REGEXP '(^| )PM( |$)'      THEN 'Personas morales'
                     WHEN UPPER(p.nombre) LIKE '%FISICA%'
                       OR UPPER(p.nombre) LIKE '%FÍSICA%'
                       OR UPPER(p.nombre) REGEXP '(^| )PF( |$)'      THEN 'Personas fisicas'
                     WHEN CHAR_LENGTH(TRIM(COALESCE(cl.rfc,''))) = 12 THEN 'Personas morales'
                     WHEN CHAR_LENGTH(TRIM(COALESCE(cl.rfc,''))) = 13 THEN 'Personas fisicas'
                     ELSE 'Sin clasificar'
                   END                                     AS tipo_persona,
                   COALESCE(p.nombre, 'Sin producto')      AS producto,
                   COALESCE(NULLIF(TRIM(p.descripcion),''), '')  AS producto_desc,
                   {$esp}                                  AS especialista,
                   COALESCE(NULLIF(TRIM(v.descripcion),''), '')  AS nota_venta,
                   d.cantidad,
                   d.precio_unitario,
                   -- El peso de la linea dentro de su venta
                   ROUND(v.total     * (d.subtotal / NULLIF(tot.suma,0)), 2) AS total_linea,
                   ROUND(COALESCE(pg.cobrado,0) * (d.subtotal / NULLIF(tot.suma,0)), 2) AS cobrado,
                   ROUND((v.total - COALESCE(pg.cobrado,0))
                         * (d.subtotal / NULLIF(tot.suma,0)), 2) AS saldo,
                   ROUND(COALESCE(g.gastos,0)   * (d.subtotal / NULLIF(tot.suma,0)), 2) AS gastos,
                   -- La del producto, completa; la que no dice de qué
                   -- producto es, por su peso. Ver COMISION_POR_PRODUCTO.
                   ROUND(COALESCE(cp.comision,0)
                         + COALESCE(cs.comision * (d.subtotal / NULLIF(tot.suma,0)), 0), 2) AS comision,
                   ROUND(COALESCE(pp.monto,0) * (d.subtotal / NULLIF(tot.suma,0)), 2) AS cobrado_periodo,
                   ROUND(COALESCE(cpp.comision,0)
                         + COALESCE(csp.comision * (d.subtotal / NULLIF(tot.suma,0)), 0), 2) AS comision_periodo,
                   -- La parte de esta línea del IVA cobrado en el periodo.
                   ROUND(COALESCE(ivp.iva,0) * (d.subtotal / NULLIF(tot.suma,0)), 2) AS iva_periodo,
                   v.total - COALESCE(pg.cobrado,0)        AS saldo_venta,
                   pg.ultimo,
                   v.metodo_pago                           AS metodo,
                   suc.nombre                              AS sucursal
            FROM venta_detalles d
            INNER JOIN ventas v     ON v.id = d.venta_id
            LEFT  JOIN productos p  ON p.id = d.producto_id
            LEFT  JOIN categorias cat ON cat.id = p.categoria_id
            LEFT  JOIN clientes cl  ON cl.id = v.cliente_id
            LEFT  JOIN sucursales suc ON suc.id = v.sucursal_id
            INNER JOIN ( SELECT venta_id, SUM(subtotal) suma
                         FROM venta_detalles GROUP BY venta_id ) tot ON tot.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) monto FROM venta_pagos
                        WHERE cancelado = 0 AND fecha_pago >= ? AND fecha_pago < ?
                        GROUP BY venta_id ) pp ON pp.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            {$cpro}
            LEFT JOIN ( SELECT p.venta_id, SUM(" . self::IVA_DEL_PAGO . ") iva
                        FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
                        WHERE p.cancelado = 0 AND p.fecha_pago >= ? AND p.fecha_pago < ?
                        GROUP BY p.venta_id ) ivp ON ivp.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND ((v.fecha >= ? AND v.fecha < ?) OR (v.fecha < ? AND pp.venta_id IS NOT NULL))
            ORDER BY area_servicio, tipo_persona, v.fecha DESC, v.codigo_venta",
            [$a, $b, $a, $b, $a, $b, $a]);
        return $this->marcarAnteriores($filas, $a, $b);
    }

    /**
     * Existe esa columna?
     *
     * Se consulta una vez por peticion y se recuerda: preguntarlo en
     * cada reporte seria una consulta mas por cada tabla.
     */
    protected function hayColumna($tabla, $columna)
    {
        static $visto = [];
        $k = $tabla . '.' . $columna;
        if (isset($visto[$k])) return $visto[$k];
        try {
            $visto[$k] = (bool)$this->valor("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$tabla, $columna]);
        } catch (\Throwable $e) { $visto[$k] = false; }
        return $visto[$k];
    }

    /**
     * Cada comision, con el porque al lado.
     *
     * UN RENGLON POR COMISION, NO POR COLABORADOR
     *
     * El resumen dice que Gisselle gano $7,336.91. Esto dice de donde:
     * de que venta, de que pago, con que porcentaje y sobre cuanto.
     *
     * Las tres columnas que explican el numero son el porcentaje, lo
     * cobrado en ese pago y la proporcion: una comision del 30% sobre un
     * anticipo del 25% no da el 30% de la venta, da el 30% de ese
     * cuarto. Sin esas tres juntas, el monto parece sacado de la nada y
     * el colaborador llega a preguntar.
     */
    public function comisionesDetalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $areaCom = self::AREA_DE_LA_COMISION;
        return $this->todos("
            SELECT pc.colaborador_nombre              AS colaborador,
                   v.id                               AS venta_id,
                   v.codigo_venta                     AS folio,
                   COALESCE(cl.nombre,'Publico general') AS cliente,
                   v.fecha                            AS fecha_venta,
                   p.fecha_pago,
                   p.tipo                             AS tipo_pago,
                   p.metodo_pago                      AS metodo,
                   -- El area del SERVICIO, igual que en el resto: la del
                   -- producto comisionado, o la que mas pesa en la venta
                   COALESCE({$areaCom}, (
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area') AS area,
                   pc.area_nombre                     AS equipo,
                   v.total                            AS total_venta,
                   p.monto                            AS cobrado,
                   pc.porcentaje,
                   pc.proporcion_cobrada              AS proporcion,
                   pc.monto                           AS comision
            FROM pago_comisiones pc
            INNER JOIN venta_pagos p ON p.id = pc.pago_id
            INNER JOIN ventas v      ON v.id = pc.venta_id
            LEFT  JOIN clientes cl   ON cl.id = v.cliente_id
            WHERE v.estado <> 'cancelada' AND p.cancelado = 0
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            ORDER BY pc.colaborador_nombre, p.fecha_pago, v.codigo_venta",
            [$a, $b]);
    }
}
