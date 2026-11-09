<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Comision;
use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Asigna una comisión a una línea de venta.
 *
 * Es el reemplazo de guardar_comision_producto.php, y la diferencia está
 * en una sola línea: la base sale de Dominio\Comision, que ya sabe quitar
 * el IVA. El archivo viejo tomaba venta_detalles.precio_unitario tal cual
 * y suponía que venía sin impuesto.
 *
 * Esa suposición costó tres errores en la migración: Francisco Flores con
 * $672.80 donde le tocaban $580, Reyna comisionando sobre $579.98 en vez
 * de $499.98, y la venta de PW sobre $1,478.84 en vez de $999.
 */
final class AsignarComision
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Los productos de una venta, cada uno con su base comisionable.
     *
     * La comisión se asigna POR PRODUCTO: en una venta de contabilidad y
     * un trámite legal, el contador comisiona sobre la contabilidad y el
     * abogado sobre el trámite, no los dos sobre todo.
     *
     * El gasto de operación es de la VENTA, no de un producto. Con un solo
     * producto se le resta completo, como siempre. Con varios se reparte
     * según lo que vale cada uno: restárselo entero a cada producto lo
     * cobraría dos o tres veces.
     *
     * @return array|null ['venta' => fila, 'lineas' => [id => [id, producto,
     *               valor, gasto, base, fila]]] o null si no existe.
     */
    public function lineas($ventaId)
    {
        $st = $this->db->prepare("
            SELECT v.id, v.subtotal, v.descuento, v.iva, v.total,
                   COALESCE(v.iva_modo,'incluido') AS iva_modo, v.estado
            FROM ventas v WHERE v.id = ?");
        $st->execute([(int)$ventaId]);
        $venta = $st->fetch();
        if (!$venta) return null;

        $st = $this->db->prepare("
            SELECT vd.id, vd.cantidad, vd.precio_unitario, vd.descuento,
                   COALESCE(p.costo,0) AS costo, COALESCE(p.nombre,'Producto') AS producto
            FROM venta_detalles vd
            LEFT JOIN productos p ON p.id = vd.producto_id
            WHERE vd.venta_id = ? ORDER BY vd.id");
        $st->execute([(int)$ventaId]);
        $filas = $st->fetchAll();

        $st = $this->db->prepare("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id = ? AND tipo = 'manual' AND categoria <> 'Costo de venta'");
        $st->execute([(int)$ventaId]);
        $gasto = Dinero::centavos($st->fetchColumn());

        // Lo que vale cada producto decide su parte del gasto.
        $valores = [];
        foreach ($filas as $f) {
            $valores[$f['id']] = max(0, Comision::desdeLinea($venta, $f, 0)->valorLinea());
        }
        $suma = array_sum($valores);
        $n    = count($filas);

        $lineas = []; $repartido = 0.0; $i = 0;
        foreach ($filas as $f) {
            $i++;
            // El último se lleva los centavos del redondeo, así los
            // pedazos suman exactamente el gasto.
            if ($i === $n) {
                $g = Dinero::centavos($gasto - $repartido);
            } else {
                $g = Dinero::centavos($gasto * ($suma > 0 ? $valores[$f['id']] / $suma : 1 / $n));
                $repartido += $g;
            }
            $com = Comision::desdeLinea($venta, $f, $g);
            $lineas[(int)$f['id']] = [
                'id'       => (int)$f['id'],
                'producto' => $f['producto'],
                'valor'    => $com->valorLinea(),
                'gasto'    => $g,
                'base'     => $com->baseAsignada(),
                'fila'     => $f,
            ];
        }
        return ['venta' => $venta, 'lineas' => $lineas];
    }

    /**
     * @param int|null $detalleId  el producto (venta_detalles.id). Puede
     *                             omitirse solo si la venta tiene uno.
     * @throws \InvalidArgumentException con un mensaje apto para mostrar
     */
    /**
     * Qué porcentaje de un producto ya está repartido en comisiones vivas:
     * las de ese producto y las viejas que van sobre toda la venta (sin
     * producto, o con uno que ya no está en ella).
     */
    public function repartido($ventaId, $detalleId)
    {
        $st = $this->db->prepare("
            SELECT COALESCE(SUM(vc.porcentaje_regla),0) FROM venta_comisiones vc
            WHERE vc.venta_id = ? AND vc.cancelada = 0
              AND ( vc.venta_detalle_id = ? OR vc.venta_detalle_id IS NULL
                    OR NOT EXISTS ( SELECT 1 FROM venta_detalles dx
                                    WHERE dx.id = vc.venta_detalle_id AND dx.venta_id = vc.venta_id ) )");
        $st->execute([(int)$ventaId, (int)$detalleId]);
        return round((float)$st->fetchColumn(), 4);
    }

    public function asignar($ventaId, $colaboradorId, $porcentaje, $detalleId = null)
    {
        $ventaId = (int)$ventaId;
        $pct     = round((float)$porcentaje, 4);   // hasta 4 decimales: 33.3333
        if ($pct <= 0 || $pct > 100) {
            throw new \InvalidArgumentException('El porcentaje debe estar entre 0 y 100');
        }

        $datos = $this->lineas($ventaId);
        if (!$datos) throw new \InvalidArgumentException('La venta no existe');
        $venta = $datos['venta'];
        if ($venta['estado'] === 'cancelada') {
            throw new \InvalidArgumentException('No se puede comisionar una venta cancelada');
        }

        $st = $this->db->prepare("
            SELECT c.id, c.nombre, a.id AS area_id, a.nombre AS area
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            WHERE c.id = ? AND c.activo = 1");
        $st->execute([(int)$colaboradorId]);
        $col = $st->fetch();
        if (!$col) throw new \InvalidArgumentException('Ese colaborador no existe o está inactivo');

        // El producto.
        $lineas = $datos['lineas'];
        if (!$lineas) throw new \InvalidArgumentException('La venta no tiene productos');
        $varios = count($lineas) > 1;
        if ($detalleId) {
            if (!isset($lineas[(int)$detalleId])) {
                throw new \InvalidArgumentException('Ese producto no es de esta venta');
            }
            $l = $lineas[(int)$detalleId];
        } elseif ($varios) {
            throw new \InvalidArgumentException(
                'Esta venta tiene ' . count($lineas) . ' productos. Elige a cuál va la comisión.');
        } else {
            $l = reset($lineas);
        }

        // Una sola vez por colaborador y producto: dos renglones del mismo
        // nombre sobre lo mismo es siempre un error de captura. Una comisión
        // vieja sin producto (o con uno que ya no está en la venta) cuenta
        // como de toda la venta.
        $st = $this->db->prepare("
            SELECT COUNT(*) FROM venta_comisiones vc
            WHERE vc.venta_id = ? AND vc.colaborador_id = ? AND vc.cancelada = 0
              AND ( vc.venta_detalle_id = ? OR vc.venta_detalle_id IS NULL
                    OR NOT EXISTS ( SELECT 1 FROM venta_detalles dx
                                    WHERE dx.id = vc.venta_detalle_id AND dx.venta_id = vc.venta_id ) )");
        $st->execute([$ventaId, $col['id'], $l['id']]);
        if ((int)$st->fetchColumn() > 0) {
            throw new \InvalidArgumentException($col['nombre'] . ' ya tiene comisión en '
                . ($varios ? $l['producto'] : 'esta venta'));
        }

        // NI MÁS DEL 100% DE UN PRODUCTO ENTRE TODOS. Cada comisión ya estaba
        // limitada a 100%, pero la suma no: cuatro personas podían repartirse
        // más de lo que deja el producto y la empresa pagaba de su bolsa. Se
        // cuentan las de ese producto y las viejas que van sobre toda la venta.
        $ya = $this->repartido($ventaId, $l['id']);
        if ($ya + $pct > 100.0001) {
            $n = function ($x) { return rtrim(rtrim(number_format($x, 4, '.', ''), '0'), '.'); };
            $que = $varios ? $l['producto'] : 'esta venta';
            throw new \InvalidArgumentException($ya >= 99.999
                ? 'Las comisiones de ' . $que . ' ya suman el 100%: no queda nada por repartir.'
                : 'Con ' . $n($pct) . '%, las comisiones de ' . $que . ' sumarían ' . $n($ya + $pct)
                  . '%: se pagaría más de lo que deja. Ya está repartido el ' . $n($ya)
                  . '%; quedan ' . $n(100 - $ya) . '% por asignar.');
        }

        $linea = $l['fila'];
        $gasto = $l['gasto'];

        // Aquí está todo el asunto: el dominio quita el IVA.
        $com  = Comision::desdeLinea($venta, $linea, $gasto);
        $base = $com->baseAsignada();
        if ($base <= 0) {
            throw new \InvalidArgumentException($varios
                ? $l['producto'] . ' no deja base comisionable: su costo y su parte de los gastos se comen la utilidad'
                : 'Esta venta no deja base comisionable: los gastos se comen la utilidad');
        }

        $iva    = \LibertyFin\Dominio\Iva::desdeVenta($venta);
        $precio = $iva->quitar($linea['precio_unitario']);
        $desc   = $iva->quitar($linea['descuento']);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO venta_comisiones
                    (venta_id, venta_detalle_id, area_id, area_nombre, regla_id, concepto,
                     colaborador_id, colaborador_nombre, porcentaje_regla, porcentaje_reparto,
                     costo_unitario, gasto_operacion, precio_unitario, descuento_linea,
                     cantidad, monto_base, monto_comision, usuario_id)
                VALUES (?,?,?,?,NULL,?,?,?,?,100.00,?,?,?,?,?,?,?,?)
            ")->execute([
                $ventaId, $linea['id'], $col['area_id'], $col['area'], $col['area'],
                $col['id'], $col['nombre'], $pct,
                $linea['costo'], $gasto, $precio, $desc, $linea['cantidad'],
                $base, $com->asignada($pct),
                $_SESSION['usuario_id'] ?? null,
            ]);
            $nuevoId = (int)$this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }

        (new SincronizarComisiones($this->db))->paraVenta($ventaId);

        return ['id' => $nuevoId, 'colaborador' => $col['nombre'], 'asignada' => $com->asignada($pct),
                'base' => $base, 'producto' => $l['producto'], 'varios' => $varios];
    }

    /** Cancelación lógica: deja rastro y resincroniza. */
    public function quitar($comisionId)
    {
        $st = $this->db->prepare("
            SELECT venta_id, colaborador_nombre FROM venta_comisiones
            WHERE id = ? AND cancelada = 0");
        $st->execute([(int)$comisionId]);
        $c = $st->fetch();
        if (!$c) throw new \InvalidArgumentException('Esa comisión no existe o ya estaba cancelada');

        $this->db->prepare("UPDATE venta_comisiones SET cancelada = 1 WHERE id = ?")
                 ->execute([(int)$comisionId]);
        (new SincronizarComisiones($this->db))->paraVenta($c['venta_id']);
        return $c;
    }
}
