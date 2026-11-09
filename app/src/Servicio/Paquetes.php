<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Paquetes de sesiones: se vende «10 masajes» y se descuentan conforme
 * el cliente viene.
 *
 * EL PRODUCTO
 *
 * Un paquete es un producto con `sesiones` (cuántas trae) y `sesion_de`
 * (de qué servicio). Se vende en Caja como cualquier otro, a su precio.
 *
 * VENDERLO Y USARLO
 *
 * Al cobrarlo, las sesiones quedan a nombre del cliente (por eso exige
 * cliente). Cuando vuelve, en Caja el renglón de ese servicio ofrece
 * «Usar sesión del paquete»: va en $0 y se descuenta una. Las dos cosas
 * pasan dentro de la transacción de la venta (RegistrarVenta): si la
 * venta no se guarda, la sesión tampoco se gasta.
 *
 * La comisión del servicio que se cubre con el paquete es cero: ya se
 * pagó cuando se vendió el paquete.
 */
final class Paquetes
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Lo que una venta hace con paquetes: crear los que vende y gastar los
     * que usa. Corre dentro de la transacción de la venta.
     *
     * @param array $lineas  las de Ticket::lineas(), en orden
     * @param array $ctx     cliente_id, paquetes => [índice de línea => id de paquete del cliente]
     */
    public function registrarVenta($ventaId, array $lineas, array $ctx)
    {
        $cliente = (int)($ctx['cliente_id'] ?? 0);
        $usar = (array)($ctx['paquetes'] ?? []);
        $ids = array_values(array_unique(array_filter(array_map(function ($l) { return (int)$l['producto_id']; }, $lineas))));
        $vende = [];
        if ($ids) {
            $m = implode(',', array_fill(0, count($ids), '?'));
            try {
                $st = $this->db->prepare("SELECT id, nombre, sesiones, sesion_de FROM productos
                                          WHERE id IN ($m) AND COALESCE(sesiones,0) > 0 AND sesion_de IS NOT NULL");
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $vende[(int)$p['id']] = $p;
            } catch (\Throwable $e) { $vende = []; }   // sin la columna: no hay paquetes
        }
        if (!$vende && !$usar) return;

        foreach ($lineas as $i => $l) {
            $pid = (int)$l['producto_id'];
            if (isset($vende[$pid])) {
                if (!$cliente) {
                    throw new \InvalidArgumentException('Para vender «' . $vende[$pid]['nombre']
                        . '» elige al cliente: las sesiones quedan a su nombre.');
                }
                $total = (int)$vende[$pid]['sesiones'] * max(1, (int)round($l['cantidad']));
                $this->db->prepare("INSERT INTO lf_paquetes_cliente
                        (cliente_id, producto_id, paquete_id, total, usadas, venta_id, activo, creado_en)
                        VALUES (?,?,?,?,0,?,1,NOW())")
                    ->execute([$cliente, (int)$vende[$pid]['sesion_de'], $pid, $total, (int)$ventaId]);
            }
            if (isset($usar[$i])) $this->gastar((int)$usar[$i], $cliente, $pid, max(1, (int)round($l['cantidad'])), $ventaId);
        }
    }

    /** Valida antes de cobrar (para dar un buen mensaje). Devuelve el paquete. */
    public function validar($paqueteId, $clienteId, $productoId, $cantidad)
    {
        $p = $this->uno($paqueteId);
        if (!$p || !(int)$p['activo']) throw new \InvalidArgumentException('Ese paquete ya no está vigente.');
        if ((int)$p['cliente_id'] !== (int)$clienteId) throw new \InvalidArgumentException('El paquete es de otro cliente.');
        if ((int)$p['producto_id'] !== (int)$productoId) throw new \InvalidArgumentException('El paquete es de otro servicio.');
        $quedan = (int)$p['total'] - (int)$p['usadas'];
        if ($quedan < $cantidad) {
            throw new \InvalidArgumentException('Al paquete le ' . ($quedan === 1 ? 'queda 1 sesión' : 'quedan ' . $quedan . ' sesiones') . '.');
        }
        return $p;
    }

    /** Gasta N sesiones. El UPDATE comprueba lo que queda: dos cajas no gastan la última dos veces. */
    private function gastar($paqueteId, $clienteId, $productoId, $n, $ventaId)
    {
        $this->validar($paqueteId, $clienteId, $productoId, $n);
        $st = $this->db->prepare("UPDATE lf_paquetes_cliente SET usadas = usadas + ?
                                  WHERE id = ? AND activo = 1 AND total - usadas >= ?");
        // Como enteros: comparados como texto, «10 >= '2'» no siempre da verdadero.
        $st->bindValue(1, (int)$n, PDO::PARAM_INT);
        $st->bindValue(2, (int)$paqueteId, PDO::PARAM_INT);
        $st->bindValue(3, (int)$n, PDO::PARAM_INT);
        $st->execute();
        if ($st->rowCount() < 1) throw new \InvalidArgumentException('Al paquete ya no le quedan sesiones.');
        $this->db->prepare("INSERT INTO lf_paquete_usos (paquete_cliente_id, venta_id, cantidad, usuario_nombre, creado_en)
                            VALUES (?,?,?,?,NOW())")
                 ->execute([$paqueteId, (int)$ventaId, $n, $_SESSION['usuario_nombre'] ?? null]);
    }

    /** Al cancelar una venta: regresan las sesiones que usó y dejan de valer los paquetes que vendió. */
    public function deshacerVenta($ventaId)
    {
        $st = $this->db->prepare("SELECT paquete_cliente_id, SUM(cantidad) AS n FROM lf_paquete_usos
                                  WHERE venta_id = ? GROUP BY paquete_cliente_id");
        $st->execute([(int)$ventaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $this->db->prepare("UPDATE lf_paquetes_cliente SET usadas = CASE WHEN usadas >= ? THEN usadas - ? ELSE 0 END WHERE id = ?")
                     ->execute([(int)$u['n'], (int)$u['n'], (int)$u['paquete_cliente_id']]);
        }
        $this->db->prepare("DELETE FROM lf_paquete_usos WHERE venta_id = ?")->execute([(int)$ventaId]);
        $this->db->prepare("UPDATE lf_paquetes_cliente SET activo = 0 WHERE venta_id = ?")->execute([(int)$ventaId]);
    }

    /** Los paquetes de un cliente, con cuántas le quedan. */
    public function delCliente($clienteId, $soloConSesiones = false)
    {
        $st = $this->db->prepare("
            SELECT pc.*, s.nombre AS servicio, p.nombre AS paquete, pc.total - pc.usadas AS quedan, v.codigo_venta
            FROM lf_paquetes_cliente pc
            LEFT JOIN productos s ON s.id = pc.producto_id
            LEFT JOIN productos p ON p.id = pc.paquete_id
            LEFT JOIN ventas v    ON v.id = pc.venta_id
            WHERE pc.cliente_id = ? AND pc.activo = 1" . ($soloConSesiones ? " AND pc.total > pc.usadas" : "") . "
            ORDER BY pc.creado_en DESC");
        $st->execute([(int)$clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Para Caja: [producto_id => ['id', 'quedan', 'paquete']] — el que tenga más sesiones. */
    public function paraCaja($clienteId)
    {
        $r = [];
        foreach ($this->delCliente($clienteId, true) as $p) {
            $k = (int)$p['producto_id'];
            if (!isset($r[$k]) || (int)$p['quedan'] > $r[$k]['quedan']) {
                $r[$k] = ['id' => (int)$p['id'], 'quedan' => (int)$p['quedan'], 'paquete' => $p['paquete']];
            }
        }
        return $r;
    }

    private function uno($id)
    {
        $st = $this->db->prepare("SELECT * FROM lf_paquetes_cliente WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
