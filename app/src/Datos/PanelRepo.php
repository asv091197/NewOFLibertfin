<?php
namespace LibertyFin\Datos;

/**
 * Las consultas del panel.
 *
 * Todas miden COBRADO, no vendido. El panel es la pantalla que más se
 * mira y la que más fácil engaña: si aquí se suma `ventas.total` como si
 * fuera ingreso, todo el mundo trabaja con una cifra que no existe.
 */
final class PanelRepo extends Repo
{
    /** Lo cobrado hoy, con su desglose. */
    public function hoy()
    {
        $r = $this->uno("
            SELECT COALESCE(SUM(p.monto),0) AS cobrado,
                   COUNT(*)                 AS cobros,
                   COALESCE(SUM(CASE WHEN p.metodo_pago = 'efectivo' THEN p.monto END),0) AS efectivo,
                   MIN(p.fecha_pago)        AS primero
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND DATE(p.fecha_pago) = CURDATE()
        ") ?: [];
        $r['ventas_nuevas'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas
            WHERE DATE(fecha) = CURDATE() AND estado <> 'cancelada'");
        return $r;
    }

    /** Promedio diario de cobranza de los últimos 30 días, para comparar. */
    public function promedioDiario($dias = 30)
    {
        return (float)$this->valor("
            SELECT COALESCE(AVG(t),0) FROM (
                SELECT SUM(p.monto) AS t
                FROM venta_pagos p
                INNER JOIN ventas v ON v.id = p.venta_id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                  AND p.fecha_pago >= DATE_SUB(CURDATE(), INTERVAL " . (int)$dias . " DAY)
                GROUP BY DATE(p.fecha_pago)
            ) d");
    }

    /**
     * Cobrado y vendido por mes.
     * El cobrado va por fecha_pago y el vendido por fecha de venta: son
     * dos cosas distintas y se miden en su propia columna.
     */
    public function porMes($meses = 7)
    {
        $cob = $this->todos("
            SELECT DATE_FORMAT(p.fecha_pago,'%Y-%m') mes, SUM(p.monto) v
            FROM venta_pagos p INNER JOIN ventas s ON s.id = p.venta_id
            WHERE p.cancelado = 0 AND s.estado <> 'cancelada'
              AND p.fecha_pago >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL " . (int)($meses-1) . " MONTH)
            GROUP BY 1");
        $ven = $this->todos("
            SELECT DATE_FORMAT(fecha,'%Y-%m') mes, SUM(total) v
            FROM ventas
            WHERE estado <> 'cancelada'
              AND fecha >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL " . (int)($meses-1) . " MONTH)
            GROUP BY 1");

        $mapa = [];
        foreach ($cob as $r) $mapa[$r['mes']]['cobrado'] = (float)$r['v'];
        foreach ($ven as $r) $mapa[$r['mes']]['vendido'] = (float)$r['v'];

        $salida = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} month", strtotime(date('Y-m-01'))));
            $salida[] = [
                'mes'     => $m,
                'cobrado' => $mapa[$m]['cobrado'] ?? 0.0,
                'vendido' => $mapa[$m]['vendido'] ?? 0.0,
            ];
        }
        return $salida;
    }

    /** Los últimos cobros, para la línea de tiempo. */
    public function movimientos($tope = 6)
    {
        return $this->todos("
            SELECT p.id, p.monto, p.tipo, p.fecha_pago, p.metodo_pago,
                   v.id AS venta_id, v.total, v.descripcion,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado_total
            FROM venta_pagos p
            INNER JOIN ventas v   ON v.id = p.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            LEFT  JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                         WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
            ORDER BY p.fecha_pago DESC, p.id DESC
            LIMIT " . (int)$tope);
    }

    /** Saldo que lleva más de N días sin moverse. Es a quien hay que llamar. */
    public function saldoViejo($dias = 30)
    {
        return $this->uno("
            SELECT COUNT(*) AS ventas, COALESCE(SUM(saldo),0) AS monto FROM (
                SELECT v.id, v.total - COALESCE(pg.cobrado,0) AS saldo
                FROM ventas v
                LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                            FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                       ON pg.venta_id = v.id
                WHERE v.estado <> 'cancelada'
                  AND v.total - COALESCE(pg.cobrado,0) > 0.01
                  AND COALESCE(pg.ultimo, v.fecha) < DATE_SUB(CURDATE(), INTERVAL " . (int)$dias . " DAY)
            ) x") ?: ['ventas' => 0, 'monto' => 0];
    }

    /**
     * Comisiones del periodo, con las DOS métricas.
     * POR ASIGNAR cuenta para el total y no para lo que se paga.
     */
    public function comisiones($desde, $hasta)
    {
        return $this->uno("
            SELECT
                COALESCE(SUM(pc.monto),0) AS total,
                COALESCE(SUM(CASE WHEN pc.colaborador_nombre <> 'POR ASIGNAR' THEN pc.monto END),0) AS por_pagar,
                COALESCE(SUM(CASE WHEN pc.colaborador_nombre  = 'POR ASIGNAR' THEN pc.monto END),0) AS sin_asignar,
                COUNT(DISTINCT CASE WHEN pc.colaborador_nombre = 'POR ASIGNAR' THEN pc.venta_id END) AS ventas_pendientes
            FROM pago_comisiones pc
            INNER JOIN ventas v ON v.id = pc.venta_id
            -- Por la fecha del pago que la generó, como en Comisiones y Reportes.
            WHERE pc.fecha_pago >= ? AND pc.fecha_pago < ? AND v.estado <> 'cancelada'
        ", [$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'])
        ?: ['total'=>0,'por_pagar'=>0,'sin_asignar'=>0,'ventas_pendientes'=>0];
    }
}
