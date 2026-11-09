<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Compras a proveedor: el pedido de mercancía, de que se pide a que llega.
 *
 *   pedida    → se le pidió al proveedor. No mueve nada todavía.
 *   recibida  → llegó: cada renglón entra al inventario de la sucursal
 *               (una «Entrada» con su costo y proveedor), y si se pide,
 *               se registra el gasto y se actualiza el costo del producto.
 *   cancelada → no llegó. Solo se cancela una pedida: lo recibido ya está
 *               en el anaquel y se corrige con una salida en Inventario.
 *
 * Recibir es todo o nada: si un renglón falla, no entra ninguno.
 *
 * Las tablas `compras` y `compra_detalles` del sistema anterior no se
 * tocan: esto vive en `lf_compras` para no mezclar lo que el sistema
 * anterior dejó a medias con lo que se registra ahora.
 */
final class Compras
{
    const ESTADOS = [
        'pedida'    => 'Pedida',
        'recibida'  => 'Recibida',
        'cancelada' => 'Cancelada',
    ];
    const CATEGORIA_GASTO = 'Compras de mercancía';

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    public function listar(array $f = [])
    {
        $w = ['1 = 1']; $p = [];
        if (!empty($f['estado']) && isset(self::ESTADOS[$f['estado']])) { $w[] = 'c.estado = ?'; $p[] = $f['estado']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') { $w[] = '(c.folio LIKE ? OR c.proveedor_nombre LIKE ?)'; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
        $st = $this->db->prepare("
            SELECT c.*, s.nombre AS sucursal,
                   (SELECT COUNT(*) FROM lf_compra_lineas l WHERE l.compra_id = c.id) AS renglones
            FROM lf_compras c
            LEFT JOIN sucursales s ON s.id = c.sucursal_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY CASE WHEN c.estado = 'pedida' THEN 0 ELSE 1 END, c.creado_en DESC, c.id DESC
            LIMIT 300");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function una($id)
    {
        $st = $this->db->prepare("SELECT c.*, s.nombre AS sucursal FROM lf_compras c
                                  LEFT JOIN sucursales s ON s.id = c.sucursal_id WHERE c.id = ?");
        $st->execute([(int)$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $st = $this->db->prepare("SELECT l.*, p.nombre, p.codigo, p.unidad_medida, p.costo AS costo_actual
                                  FROM lf_compra_lineas l LEFT JOIN productos p ON p.id = l.producto_id
                                  WHERE l.compra_id = ? ORDER BY l.id");
        $st->execute([(int)$id]);
        $c['lineas'] = $st->fetchAll(PDO::FETCH_ASSOC);
        return $c;
    }

    /** Lo que está bajo su mínimo en una sucursal: la sugerencia del pedido. */
    public function sugerido($sucursalId)
    {
        $st = $this->db->prepare("
            SELECT p.id, p.nombre, p.costo, p.unidad_medida, ps.stock AS existencia, ps.stock_minimo AS minimo
            FROM productos p
            INNER JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE p.activo = 1 AND p.lleva_inventario = 1 AND ps.stock_minimo > 0 AND ps.stock <= ps.stock_minimo
            ORDER BY p.nombre LIMIT 100");
        $st->execute([(int)$sucursalId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ════════════════════════════════════════════════════════
    //  ESCRIBIR
    // ════════════════════════════════════════════════════════

    /**
     * @param array $d proveedor_id | proveedor_nombre, sucursal_id, notas,
     *                 lineas: [['producto_id', 'cantidad', 'costo'], ...]
     */
    public function guardar(array $d, $usuario = '')
    {
        $suc = (int)($d['sucursal_id'] ?? 0);
        $st = $this->db->prepare("SELECT id FROM sucursales WHERE id = ? AND COALESCE(activo,1) = 1");
        $st->execute([$suc]);
        if (!$st->fetchColumn()) throw new \InvalidArgumentException('Elige la sucursal a la que llega.');

        $provId = (int)($d['proveedor_id'] ?? 0) ?: null;
        $prov = mb_substr(trim((string)($d['proveedor_nombre'] ?? '')), 0, 200);
        if ($provId) {
            $st = $this->db->prepare("SELECT nombre FROM proveedores WHERE id = ?");
            $st->execute([$provId]);
            $n = $st->fetchColumn();
            if ($n === false) $provId = null; else $prov = (string)$n;
        }
        if ($prov === '') throw new \InvalidArgumentException('Escribe o elige al proveedor.');

        $pide = [];
        foreach ((array)($d['lineas'] ?? []) as $l) {
            $pid = (int)($l['producto_id'] ?? 0);
            if (!$pid) continue;
            $cant = round((float)str_replace(',', '', (string)($l['cantidad'] ?? 0)), 3);
            $costo = round((float)str_replace(['$', ','], '', (string)($l['costo'] ?? 0)), 2);
            if ($cant <= 0) throw new \InvalidArgumentException('Cada renglón necesita cuántas piezas se piden.');
            if ($costo < 0) throw new \InvalidArgumentException('El costo no puede ser negativo.');
            $pide[] = [$pid, $cant, $costo];
        }
        if (!$pide) throw new \InvalidArgumentException('Agrega al menos un producto al pedido.');

        // Solo productos que llevan existencias: lo demás no tiene a dónde entrar.
        $ids = array_values(array_unique(array_column($pide, 0)));
        $st = $this->db->prepare("SELECT id FROM productos WHERE lleva_inventario = 1 AND id IN ("
                                 . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        $validos = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($ids as $id) {
            if (!isset($validos[$id])) throw new \InvalidArgumentException('Uno de los productos no lleva existencias. Márcalo en Productos o quítalo del pedido.');
        }

        $total = Dinero::centavos(array_sum(array_map(function ($l) { return $l[1] * $l[2]; }, $pide)));
        $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null;

        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO lf_compras (folio, proveedor_id, proveedor_nombre, sucursal_id, estado, total, notas,
                                                        creado_por, creado_en)
                                VALUES ('', ?, ?, ?, 'pedida', ?, ?, ?, NOW())")
                     ->execute([$provId, $prov, $suc, $total, $notas, mb_substr((string)$usuario, 0, 100) ?: null]);
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE lf_compras SET folio = ? WHERE id = ?")
                     ->execute(['OC-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
            $ins = $this->db->prepare("INSERT INTO lf_compra_lineas (compra_id, producto_id, cantidad, costo) VALUES (?,?,?,?)");
            foreach ($pide as $l) $ins->execute(array_merge([$id], $l));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Llegó la mercancía.
     *
     * @param array $o actualizar_costo (bool), gasto (bool), metodo_pago
     * @return array ['folio', 'renglones', 'gasto_id']
     */
    public function recibir($id, array $o = [], $usuario = '')
    {
        $c = $this->una($id);
        if (!$c) throw new \InvalidArgumentException('Esa compra no existe.');
        if ($c['estado'] !== 'pedida') {
            throw new \InvalidArgumentException('La compra ' . $c['folio'] . ' ya está ' . mb_strtolower(self::ESTADOS[$c['estado']]) . '.');
        }
        $inv = new Inventario($this->db);
        if (!$inv->activo()) throw new \InvalidArgumentException('El inventario está apagado: enciéndelo para recibir mercancía.');

        $this->db->beginTransaction();
        try {
            // Apartarla primero: dos personas recibiendo a la vez no meten la mercancía dos veces.
            $st = $this->db->prepare("UPDATE lf_compras SET estado = 'recibida', recibida_en = NOW(), recibida_por = ?
                                      WHERE id = ? AND estado = 'pedida'");
            $st->execute([mb_substr((string)$usuario, 0, 100) ?: null, (int)$id]);
            if ($st->rowCount() < 1) throw new \InvalidArgumentException('Alguien más acaba de recibir esta compra.');

            foreach ($c['lineas'] as $l) {
                $inv->registrar('entrada', (int)$l['producto_id'], (int)$c['sucursal_id'], $l['cantidad'], [
                    'costo'        => (float)$l['costo'] > 0 ? (string)$l['costo'] : '',
                    'proveedor_id' => $c['proveedor_id'],
                    'nota'         => 'Compra ' . $c['folio'] . ($c['proveedor_id'] ? '' : ' · ' . $c['proveedor_nombre']),
                ]);
                if (!empty($o['actualizar_costo']) && (float)$l['costo'] > 0) {
                    $this->db->prepare("UPDATE productos SET costo = ? WHERE id = ?")->execute([(float)$l['costo'], (int)$l['producto_id']]);
                }
            }

            $gasto = null;
            if (!empty($o['gasto']) && (float)$c['total'] > 0) {
                $metodo = in_array($o['metodo_pago'] ?? '', ['efectivo', 'transferencia', 'tarjeta'], true) ? $o['metodo_pago'] : 'transferencia';
                $this->db->prepare("INSERT INTO gastos (concepto, categoria, monto, tipo, origen, usuario_id, sucursal_id,
                                                        metodo_pago, descripcion, fecha, proveedor, numero_referencia)
                                    VALUES (?,?,?,'manual','general',?,?,?,?,NOW(),?,?)")
                         ->execute(['Compra ' . $c['folio'] . ' · ' . $c['proveedor_nombre'], self::CATEGORIA_GASTO, (float)$c['total'],
                                    $_SESSION['usuario_id'] ?? null, (int)$c['sucursal_id'], $metodo,
                                    count($c['lineas']) . ' productos recibidos', $c['proveedor_nombre'], $c['folio']]);
                $gasto = (int)$this->db->lastInsertId();
                $this->db->prepare("UPDATE lf_compras SET gasto_id = ? WHERE id = ?")->execute([$gasto, (int)$id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['folio' => $c['folio'], 'renglones' => count($c['lineas']), 'gasto_id' => $gasto];
    }

    public function cancelar($id)
    {
        $c = $this->una($id);
        if (!$c) throw new \InvalidArgumentException('Esa compra no existe.');
        if ($c['estado'] !== 'pedida') {
            throw new \InvalidArgumentException($c['estado'] === 'recibida'
                ? 'La compra ya se recibió: la mercancía está en el inventario. Si hay que regresarla, registra una salida en Inventario.'
                : 'La compra ya estaba cancelada.');
        }
        $this->db->prepare("UPDATE lf_compras SET estado = 'cancelada' WHERE id = ? AND estado = 'pedida'")->execute([(int)$id]);
        return $c;
    }
}
