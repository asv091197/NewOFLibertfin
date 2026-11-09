<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Comision;
use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Recalcula lo devengado de una venta.
 *
 * Esta es la pieza que en el sistema anterior estaba mal en cuatro sitios
 * a la vez. Aquí llama al dominio y nada más:
 *
 *   base = (valor de la línea x % cobrado) - costo - gasto completo
 *   devengado = base x porcentaje
 *
 * El gasto de operación se resta ENTERO, no prorrateado: el primer pago lo
 * absorbe. Así lo hace el Excel de la empresa y así quedó documentado.
 *
 * Se llama después de CUALQUIER cambio en los pagos de una venta.
 */
final class SincronizarComisiones
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function paraVenta($ventaId)
    {
        $ventaId = (int)$ventaId;

        $st = $this->db->prepare("
            SELECT v.id, v.subtotal, v.descuento, v.iva, v.total,
                   COALESCE(v.iva_modo,'incluido') AS iva_modo,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   COALESCE(g.gastos,0)   AS gastos
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            WHERE v.id = ?");
        $st->execute([$ventaId]);
        $venta = $st->fetch();
        if (!$venta) return 0;

        $st = $this->db->prepare("
            SELECT vc.*, vd.precio_unitario AS precio_detalle, vd.cantidad AS cant_detalle,
                   vd.descuento AS desc_detalle
            FROM venta_comisiones vc
            LEFT JOIN venta_detalles vd ON vd.id = vc.venta_detalle_id
            WHERE vc.venta_id = ? AND vc.cancelada = 0");
        $st->execute([$ventaId]);
        $asignaciones = $st->fetchAll();
        if (!$asignaciones) {
            // Sin asignaciones vivas no debe quedar nada devengado.
            $this->db->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([$ventaId]);
            return 0;
        }

        // Los pagos vivos, en orden. El primero absorbe el gasto completo.
        $st = $this->db->prepare("
            SELECT id, monto, fecha_pago FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0
            ORDER BY fecha_pago, id");
        $st->execute([$ventaId]);
        $pagos = $st->fetchAll();

        // Con varios productos, lo cobrado se reparte entre ellos según lo
        // que vale cada uno: si el cliente pagó un tercio de la venta, la
        // comisión de cada producto va en un tercio. Sin esto, un abono
        // igual al precio de UN producto liberaba completa la comisión de
        // cada uno de ellos.
        // Con un solo producto la parte es 1 y nada cambia. Una comisión
        // vieja sin producto se queda con toda la venta, como antes.
        $st = $this->db->prepare("
            SELECT id, GREATEST(precio_unitario * cantidad - COALESCE(descuento,0), 0) AS valor
            FROM venta_detalles WHERE venta_id = ?");
        $st->execute([$ventaId]);
        $valores = [];
        foreach ($st->fetchAll() as $d) $valores[(int)$d['id']] = (float)$d['valor'];
        $sumaValores = array_sum($valores);
        $parte = function ($a) use ($valores, $sumaValores) {
            $d = (int)($a['venta_detalle_id'] ?? 0);
            if (count($valores) < 2 || $sumaValores <= 0 || !isset($valores[$d])) return 1.0;
            return $valores[$d] / $sumaValores;
        };

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([$ventaId]);

            $ins = $this->db->prepare("
                INSERT INTO pago_comisiones
                    (pago_id, venta_comision_id, venta_id, colaborador_id, colaborador_nombre,
                     area_nombre, porcentaje, proporcion_cobrada, monto, fecha_pago)
                VALUES (?,?,?,?,?,?,?,?,?,?)");

            $escritas = 0;
            $acumulado = 0.0;

            foreach ($pagos as $pago) {
                $antes   = $acumulado;
                $acumulado = Dinero::centavos($acumulado + (float)$pago['monto']);

                foreach ($asignaciones as $a) {
                    $com = new Comision(
                        (float)$a['precio_unitario'],
                        (float)$a['cantidad'],
                        (float)$a['descuento_linea'],
                        (float)$a['costo_unitario'],
                        (float)$a['gasto_operacion']
                    );
                    // Lo devengado al cierre de este pago, menos lo que ya
                    // se había devengado con los anteriores. La diferencia
                    // es lo que ESTE pago liberó.
                    $p     = $parte($a);
                    $hasta = $com->devengada($a['porcentaje_regla'], $acumulado * $p);
                    $desde = $com->devengada($a['porcentaje_regla'], $antes * $p);
                    $delta = Dinero::centavos($hasta - $desde);
                    if (abs($delta) < 0.005) continue;

                    $prop = (float)$venta['total'] > 0
                          ? min(1, $acumulado / (float)$venta['total']) : 0;

                    $ins->execute([
                        $pago['id'], $a['id'], $ventaId,
                        $a['colaborador_id'], $a['colaborador_nombre'], $a['area_nombre'],
                        $a['porcentaje_regla'], round($prop, 4), $delta, $pago['fecha_pago'],
                    ]);
                    $escritas++;
                }
            }
            $this->db->commit();
            return $escritas;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
