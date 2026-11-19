<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Preventa: vender lo que todavía no llega.
 *
 * CÓMO FUNCIONA
 *
 * Un producto con `productos.preventa = 1` se vende aunque no haya piezas:
 * la venta, sus líneas y sus cobros se registran como cualquier otra, pero
 * sus piezas NO salen del inventario. En su lugar queda una fila en
 * `lf_preventas` («le debemos 2 al cliente X»).
 *
 * Cuando llega la mercancía se registra la entrada en Inventario, como
 * siempre, y al ENTREGAR cada preventa recién se descuentan las piezas. El
 * movimiento lleva el `venta_id` de la venta original, así que
 * Inventario::devolverVenta() sigue funcionando sin cambios: al cancelar,
 * solo regresa lo que de verdad salió.
 *
 * Mientras el producto siga marcado en preventa, TODO lo que se venda de él
 * es preventa. Cuando ya hay piezas para vender normal, se apaga la casilla
 * en Productos y las preventas pendientes se siguen entregando desde aquí.
 */
final class Preventas
{
    const ESTADOS = [
        'pendiente' => 'Por entregar',
        'entregada' => 'Entregada',
        'cancelada' => 'Cancelada',
    ];

    private $db;
    private $hay = null;
    private $activa = null;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * ¿Se puede VENDER en preventa? La base al día y el módulo encendido
     * (Ajustes → Módulos, requiere Inventario).
     *
     * Con el módulo apagado los productos marcados se venden normal y la
     * casilla no sale en Productos. Lo que ya se prevendió se sigue
     * entregando: para eso basta disponible(), no esto. Apagar el módulo
     * no puede dejar mercancía cobrada sin forma de entregarla.
     */
    public function activa()
    {
        if ($this->activa !== null) return $this->activa;
        if (!$this->disponible()) return $this->activa = false;
        try {
            $m = new Modulos(new \LibertyFin\Datos\ConfigRepo($this->db));
            return $this->activa = $m->activo('preventa');
        } catch (\Throwable $e) {
            return $this->activa = false;
        }
    }

    /** ¿Quedan preventas por entregar? (Aunque el módulo esté apagado.) */
    public function hayPendientes()
    {
        if (!$this->disponible()) return false;
        return (bool)$this->db->query("SELECT 1 FROM lf_preventas WHERE estado = 'pendiente' LIMIT 1")->fetchColumn();
    }

    /** ¿La base ya tiene la migración 34? Sin ella, todo se vende como antes. */
    public function disponible()
    {
        if ($this->hay !== null) return $this->hay;
        try {
            $this->db->query("SELECT preventa FROM productos LIMIT 1");
            $this->db->query("SELECT 1 FROM lf_preventas LIMIT 1");
            return $this->hay = true;
        } catch (\Throwable $e) {
            return $this->hay = false;
        }
    }

    // ════════════════════════════════════════════════════════
    //  AL VENDER
    // ════════════════════════════════════════════════════════

    /**
     * Separa las líneas del ticket en las que se venden normal y las de
     * preventa.
     *
     * @return array [normales, preventa]
     */
    public function separar(array $lineas)
    {
        if (!$this->activa()) return [$lineas, []];
        $enPreventa = $this->productosEnPreventa(array_map(function ($l) {
            return (int)($l['producto_id'] ?? $l['id'] ?? 0);
        }, $lineas));
        if (!$enPreventa) return [$lineas, []];

        $normales = []; $pre = [];
        foreach ($lineas as $l) {
            $id = (int)($l['producto_id'] ?? $l['id'] ?? 0);
            if (isset($enPreventa[$id])) $pre[] = $l; else $normales[] = $l;
        }
        return [$normales, $pre];
    }

    /**
     * Registra las líneas de preventa de una venta. Corre DENTRO de la
     * transacción de la venta: si algo no cuadra (se acabó el cupo, falta
     * anticipo), lanza y la venta entera no se guarda.
     *
     * @param array $ctx sucursal_id, cliente_id, anticipo, total_venta, en_linea
     * @throws \InvalidArgumentException
     */
    public function registrarVenta($ventaId, array $lineas, array $ctx = [])
    {
        if (!$lineas || !$this->activa()) return;

        $pide = [];
        foreach ($lineas as $l) {
            $id = (int)($l['producto_id'] ?? $l['id'] ?? 0);
            if ($id > 0) $pide[$id] = ($pide[$id] ?? 0) + (float)($l['cantidad'] ?? 0);
        }
        $productos = $this->productosEnPreventa(array_keys($pide), true);

        // ── El cupo ──
        // FOR UPDATE en los productos: dos cajas vendiendo el último lugar
        // de la preventa al mismo tiempo no deben leer las dos «queda 1».
        $faltan = [];
        foreach ($productos as $id => $p) {
            if ($p['preventa_limite'] === null) continue;
            $apartadas = $this->apartadas($id);
            $quedan = (float)$p['preventa_limite'] - $apartadas;
            if ($pide[$id] > $quedan + 0.0005) {
                $faltan[] = $p['nombre'] . ' (quedan ' . Inventario::num(max(0, $quedan)) . ' lugares)';
            }
        }
        if ($faltan) {
            throw new \InvalidArgumentException('Ya no alcanza la preventa de ' . implode(', ', $faltan) . '.');
        }

        // ── El anticipo mínimo ──
        // Solo si se cobra en el mostrador. Con liga de pago el cliente
        // todavía no paga nada y no hay anticipo que revisar.
        if (empty($ctx['en_linea'])) {
            $minimo = 0.0;
            foreach ($lineas as $l) {
                $id = (int)($l['producto_id'] ?? $l['id'] ?? 0);
                $pct = $productos[$id]['preventa_anticipo'] ?? null;
                if ($pct === null) continue;
                $importe = (float)$l['precio'] * (float)$l['cantidad'] - (float)($l['descuento'] ?? 0);
                $minimo += $importe * (float)$pct / 100;
            }
            $minimo = Dinero::centavos($minimo);
            $anticipo = Dinero::centavos($ctx['anticipo'] ?? 0);
            if ($minimo > 0 && $anticipo + 0.005 < $minimo) {
                throw new \InvalidArgumentException('Los productos en preventa piden un anticipo de al menos '
                    . Dinero::pesos($minimo) . '. Se capturó ' . Dinero::pesos($anticipo) . '.');
            }
        }

        $ins = $this->db->prepare("
            INSERT INTO lf_preventas
                (venta_id, producto_id, sucursal_id, cliente_id, cantidad, estado, llegada_estimada, creado_en)
            VALUES (?,?,?,?,?,'pendiente',?,NOW())");
        foreach ($pide as $id => $cantidad) {
            if (!isset($productos[$id])) continue;
            $ins->execute([
                (int)$ventaId, $id,
                ($ctx['sucursal_id'] ?? null) ?: null,
                ($ctx['cliente_id'] ?? null) ?: null,
                round($cantidad, 3),
                $productos[$id]['preventa_llegada'] ?: null,
            ]);
        }
    }

    // ════════════════════════════════════════════════════════
    //  AL ENTREGAR
    // ════════════════════════════════════════════════════════

    /**
     * Llegó la mercancía y el cliente se la lleva: salen las piezas del
     * inventario y la preventa queda entregada.
     *
     * @return array la preventa, con el saldo que le queda a la venta
     * @throws \InvalidArgumentException
     */
    public function entregar($preventaId, $usuarioId = null)
    {
        $preventaId = (int)$preventaId;
        $this->db->beginTransaction();
        try {
            $bloqueo = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $this->db->prepare("
                SELECT pv.*, v.estado AS venta_estado, v.codigo_venta, v.sucursal_id AS venta_sucursal,
                       p.nombre AS producto, p.lleva_inventario
                  FROM lf_preventas pv
                  JOIN ventas v    ON v.id = pv.venta_id
                  JOIN productos p ON p.id = pv.producto_id
                 WHERE pv.id = ?" . $bloqueo);
            $st->execute([$preventaId]);
            $pv = $st->fetch(PDO::FETCH_ASSOC);

            if (!$pv) throw new \InvalidArgumentException('Esa preventa no existe.');
            if ($pv['estado'] !== 'pendiente') {
                throw new \InvalidArgumentException('Esa preventa ya estaba ' . mb_strtolower(self::ESTADOS[$pv['estado']] ?? $pv['estado']) . '.');
            }
            if ($pv['venta_estado'] === 'cancelada') {
                throw new \InvalidArgumentException('La venta ' . $pv['codigo_venta'] . ' está cancelada.');
            }

            // Las piezas salen como parte de LA VENTA original (mismo
            // venta_id), no como un movimiento suelto: así cancelarla
            // después las regresa solas.
            $inv = new Inventario($this->db);
            if ($inv->activo() && (int)$pv['lleva_inventario'] === 1) {
                $suc = (int)($pv['sucursal_id'] ?: $pv['venta_sucursal'] ?: $inv->sucursalActual());
                $inv->descontarVenta((int)$pv['venta_id'],
                    [['producto_id' => (int)$pv['producto_id'], 'cantidad' => (float)$pv['cantidad']]], $suc);
            }

            $this->db->prepare("
                UPDATE lf_preventas SET estado = 'entregada', entregado_en = NOW(), entregado_por = ?
                 WHERE id = ?")->execute([$usuarioId ?: null, $preventaId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        try {
            Auditoria::anota('preventa.entregar', 'venta ' . $pv['codigo_venta'],
                'pendiente', 'entregada: ' . Inventario::num($pv['cantidad']) . ' ' . $pv['producto']);
        } catch (\Throwable $e) { /* sin bitácora se entrega igual */ }

        $pv['saldo'] = $this->saldoDeVenta((int)$pv['venta_id']);
        return $pv;
    }

    /** Al cancelar la venta: lo que no se entregó ya no se debe. */
    public function cancelarDeVenta($ventaId)
    {
        if (!$this->disponible()) return;
        $this->db->prepare("UPDATE lf_preventas SET estado = 'cancelada' WHERE venta_id = ? AND estado = 'pendiente'")
                 ->execute([(int)$ventaId]);
    }

    /** Cambia la fecha estimada de un producto y de lo que ya se le debe. */
    public function cambiarLlegada($productoId, $fecha)
    {
        $fecha = $this->fechaValida($fecha);
        $this->db->prepare("UPDATE productos SET preventa_llegada = ? WHERE id = ?")
                 ->execute([$fecha, (int)$productoId]);
        $this->db->prepare("UPDATE lf_preventas SET llegada_estimada = ? WHERE producto_id = ? AND estado = 'pendiente'")
                 ->execute([$fecha, (int)$productoId]);
    }

    // ════════════════════════════════════════════════════════
    //  EL PRODUCTO
    // ════════════════════════════════════════════════════════

    /** Lo de preventa de un producto, para el formulario. */
    public function deProducto($productoId)
    {
        $vacio = ['preventa' => 0, 'preventa_llegada' => null, 'preventa_limite' => null,
                  'preventa_anticipo' => null, 'apartadas' => 0.0];
        if (!$productoId || !$this->disponible()) return $vacio;
        $st = $this->db->prepare("SELECT preventa, preventa_llegada, preventa_limite, preventa_anticipo
                                    FROM productos WHERE id = ?");
        $st->execute([(int)$productoId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $vacio;
        $r['apartadas'] = $this->apartadas($productoId);
        return $r;
    }

    /** Guarda lo de preventa del formulario de Productos. */
    public function guardarProducto($productoId, array $d)
    {
        if (!$this->activa()) return;
        $activa   = !empty($d['preventa']) ? 1 : 0;
        $limite   = trim((string)($d['preventa_limite'] ?? ''));
        $anticipo = trim((string)($d['preventa_anticipo'] ?? ''));

        $limite   = $limite === '' ? null : max(0, round((float)$limite, 3));
        $anticipo = $anticipo === '' ? null : max(0, min(100, round((float)$anticipo, 2)));
        $llegada  = $this->fechaValida($d['preventa_llegada'] ?? '');

        $this->db->prepare("
            UPDATE productos
               SET preventa = ?, preventa_llegada = ?, preventa_limite = ?, preventa_anticipo = ?
             WHERE id = ?")->execute([$activa, $llegada, $limite, $anticipo, (int)$productoId]);

        // Las pendientes siguen la fecha nueva.
        if ($llegada) {
            $this->db->prepare("UPDATE lf_preventas SET llegada_estimada = ? WHERE producto_id = ? AND estado = 'pendiente'")
                     ->execute([$llegada, (int)$productoId]);
        }
    }

    /**
     * Para Caja: [producto_id => ['llegada', 'anticipo', 'quedan']] de los
     * productos que están en preventa ahora.
     */
    public function catalogo()
    {
        if (!$this->activa()) return [];
        $r = [];
        $st = $this->db->query("
            SELECT p.id, p.preventa_llegada, p.preventa_limite, p.preventa_anticipo,
                   COALESCE(a.apartadas, 0) AS apartadas
              FROM productos p
              LEFT JOIN ( SELECT producto_id, SUM(cantidad) AS apartadas FROM lf_preventas
                           WHERE estado <> 'cancelada' GROUP BY producto_id ) a ON a.producto_id = p.id
             WHERE p.activo = 1 AND p.preventa = 1");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $r[(int)$f['id']] = [
                'llegada'  => $f['preventa_llegada'],
                'anticipo' => $f['preventa_anticipo'] !== null ? (float)$f['preventa_anticipo'] : null,
                'quedan'   => $f['preventa_limite'] !== null
                              ? max(0, (float)$f['preventa_limite'] - (float)$f['apartadas']) : null,
            ];
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    /**
     * Las preventas, con su venta, cliente y producto.
     * @param array $f estado, producto_id, q
     */
    public function listado(array $f = [])
    {
        $w = ['1=1']; $p = [];
        $estado = $f['estado'] ?? 'pendiente';
        if ($estado !== '' && isset(self::ESTADOS[$estado])) { $w[] = 'pv.estado = ?'; $p[] = $estado; }
        if (!empty($f['producto_id'])) { $w[] = 'pv.producto_id = ?'; $p[] = (int)$f['producto_id']; }
        if (($f['q'] ?? '') !== '') {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR pr.nombre LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($p, $like, $like, $like);
        }
        $st = $this->db->prepare("
            SELECT pv.*, v.codigo_venta, v.total, c.nombre AS cliente, c.telefono,
                   pr.nombre AS producto, pr.unidad_medida, s.nombre AS sucursal,
                   v.total - COALESCE(pg.cobrado, 0) AS saldo,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_preventas pv
              JOIN ventas v     ON v.id = pv.venta_id
              JOIN productos pr ON pr.id = pv.producto_id
              LEFT JOIN clientes c   ON c.id = pv.cliente_id
              LEFT JOIN sucursales s ON s.id = pv.sucursal_id
              LEFT JOIN producto_sucursal ps ON ps.producto_id = pv.producto_id AND ps.sucursal_id = pv.sucursal_id
              LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                           WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = pv.venta_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY pv.estado = 'pendiente' DESC, pv.creado_en ASC
             LIMIT 500");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lo pendiente, sumado por producto: «llegaron 10 y hay 7 apartadas». */
    public function resumenPorProducto()
    {
        return $this->db->query("
            SELECT pr.id, pr.nombre, pr.unidad_medida, pr.preventa, pr.preventa_llegada, pr.preventa_limite,
                   SUM(pv.cantidad) AS por_entregar, COUNT(*) AS clientes,
                   COALESCE(pr.stock, 0) AS existencia
              FROM lf_preventas pv
              JOIN productos pr ON pr.id = pv.producto_id
             WHERE pv.estado = 'pendiente'
             GROUP BY pr.id, pr.nombre, pr.unidad_medida, pr.preventa, pr.preventa_llegada, pr.preventa_limite, pr.stock
             ORDER BY pr.preventa_llegada IS NULL, pr.preventa_llegada, pr.nombre")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Las preventas de una venta, para su detalle. */
    public function deVenta($ventaId)
    {
        if (!$this->disponible()) return [];
        $st = $this->db->prepare("
            SELECT pv.*, pr.nombre AS producto, pr.unidad_medida
              FROM lf_preventas pv JOIN productos pr ON pr.id = pv.producto_id
             WHERE pv.venta_id = ? ORDER BY pv.id");
        $st->execute([(int)$ventaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ════════════════════════════════════════════════════════

    /**
     * De estos productos, los que están en preventa: [id => fila].
     * Con $bloquear, los deja apartados hasta el fin de la transacción.
     */
    private function productosEnPreventa(array $ids, $bloquear = false)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $m = implode(',', array_fill(0, count($ids), '?'));
        $bloqueo = ($bloquear && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';
        $st = $this->db->prepare("
            SELECT id, nombre, preventa_llegada, preventa_limite, preventa_anticipo
              FROM productos WHERE preventa = 1 AND id IN ($m) ORDER BY id" . $bloqueo);
        $st->execute($ids);
        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $r[(int)$f['id']] = $f;
        return $r;
    }

    /** Cuántas se han prevendido de un producto (pendientes y entregadas). */
    private function apartadas($productoId)
    {
        $st = $this->db->prepare("SELECT COALESCE(SUM(cantidad),0) FROM lf_preventas
                                   WHERE producto_id = ? AND estado <> 'cancelada'");
        $st->execute([(int)$productoId]);
        return (float)$st->fetchColumn();
    }

    private function saldoDeVenta($ventaId)
    {
        $st = $this->db->prepare("
            SELECT v.total - COALESCE((SELECT SUM(monto) FROM venta_pagos WHERE venta_id = v.id AND cancelado = 0), 0)
              FROM ventas v WHERE v.id = ?");
        $st->execute([(int)$ventaId]);
        return Dinero::centavos($st->fetchColumn());
    }

    private function fechaValida($f)
    {
        $f = trim((string)$f);
        if ($f === '') return null;
        $d = \DateTime::createFromFormat('Y-m-d', $f);
        return ($d && $d->format('Y-m-d') === $f) ? $f : null;
    }
}
