<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Seguimiento de trabajos: en qué va lo que se vendió.
 *
 * Para quien vende algo que se entrega después (una declaración, un
 * trámite, un diseño): cada venta es un trabajo con su estado, quién lo
 * lleva y para cuándo. No se crea nada aparte: el trabajo ES la venta, y
 * mientras nadie lo mueve está «Pendiente».
 *
 * Solo entran las ventas desde que se encendió el módulo: encenderlo no
 * debe llenar el tablero con todo el historial.
 */
final class Trabajos
{
    const ESTADOS = [
        'pendiente' => 'Pendiente',
        'proceso'   => 'En proceso',
        'listo'     => 'Listo para entregar',
        'entregado' => 'Entregado',
    ];
    /** Los entregados se quedan a la vista estos días, luego se archivan. */
    const DIAS_ENTREGADOS = 15;

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * El tablero: las ventas vivas desde $desde, agrupadas por estado.
     *
     * @param array $f desde (Y-m-d), responsable, q
     * @return array [estado => [venta, ...]]
     */
    public function tablero(array $f = [])
    {
        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['desde'] ?? '')) ? $f['desde'] : date('Y-m-d', strtotime('-90 days'));
        $w = ["v.estado <> 'cancelada'", 'v.fecha >= ?'];
        $p = [$desde . ' 00:00:00'];
        if (!empty($f['responsable'])) { $w[] = 't.responsable_id = ?'; $p[] = (int)$f['responsable']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $q . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $st = $this->db->prepare("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.descripcion, v.especialista_nombre,
                   c.nombre AS cliente, v.cliente_id,
                   COALESCE(t.estado, 'pendiente') AS trabajo, t.responsable_id, t.entrega, t.nota,
                   t.actualizado_en, t.actualizado_por, cc.nombre AS responsable,
                   v.total - COALESCE((SELECT SUM(monto) FROM venta_pagos vp WHERE vp.venta_id = v.id AND vp.cancelado = 0),0) AS saldo
            FROM ventas v
            LEFT JOIN lf_trabajos t ON t.venta_id = v.id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN comision_colaboradores cc ON cc.id = t.responsable_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY CASE WHEN t.entrega IS NULL THEN 1 ELSE 0 END, t.entrega, v.fecha
            LIMIT 600");
        $st->execute($p);
        $ventas = $st->fetchAll(PDO::FETCH_ASSOC);
        $conceptos = $this->conceptos(array_column($ventas, 'id'));
        $r = array_fill_keys(array_keys(self::ESTADOS), []);
        $corte = date('Y-m-d H:i:s', strtotime('-' . self::DIAS_ENTREGADOS . ' days'));
        $hoy = date('Y-m-d');
        foreach ($ventas as $v) {
            $v['conceptos'] = implode(', ', $conceptos[(int)$v['id']] ?? []);
            $e = isset(self::ESTADOS[$v['trabajo']]) ? $v['trabajo'] : 'pendiente';
            if ($e === 'entregado' && (string)$v['actualizado_en'] < $corte) continue;
            $v['atrasado'] = $e !== 'entregado' && !empty($v['entrega']) && $v['entrega'] < $hoy;
            $r[$e][] = $v;
        }
        return $r;
    }

    /** Qué se vendió en cada venta: [venta_id => [nombre, ...]]. */
    private function conceptos(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $r = [];
        foreach (array_chunk($ids, 300) as $parte) {
            $st = $this->db->prepare("SELECT d.venta_id, pr.nombre FROM venta_detalles d
                                      LEFT JOIN productos pr ON pr.id = d.producto_id
                                      WHERE d.venta_id IN (" . implode(',', array_fill(0, count($parte), '?')) . ") ORDER BY d.id");
            $st->execute($parte);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $r[(int)$f['venta_id']][] = $f['nombre'] ?: '(producto borrado)';
        }
        return $r;
    }

    /** El de una venta, o los valores de fábrica. */
    public function deVenta($ventaId)
    {
        $st = $this->db->prepare("SELECT t.*, cc.nombre AS responsable FROM lf_trabajos t
                                  LEFT JOIN comision_colaboradores cc ON cc.id = t.responsable_id WHERE t.venta_id = ?");
        $st->execute([(int)$ventaId]);
        return $st->fetch(PDO::FETCH_ASSOC)
            ?: ['venta_id' => (int)$ventaId, 'estado' => 'pendiente', 'responsable_id' => null, 'entrega' => null,
                'nota' => null, 'actualizado_en' => null, 'actualizado_por' => null, 'responsable' => null];
    }

    /** @param array $d estado, responsable_id, entrega (Y-m-d), nota */
    public function guardar($ventaId, array $d, $usuario = '')
    {
        $ventaId = (int)$ventaId;
        $st = $this->db->prepare("SELECT estado FROM ventas WHERE id = ?");
        $st->execute([$ventaId]);
        $ev = $st->fetchColumn();
        if ($ev === false) throw new \InvalidArgumentException('Esa venta no existe.');
        if ($ev === 'cancelada') throw new \InvalidArgumentException('La venta está cancelada: no hay trabajo que seguir.');

        $actual = $this->deVenta($ventaId);
        $estado = array_key_exists('estado', $d) ? (string)$d['estado'] : $actual['estado'];
        if (!isset(self::ESTADOS[$estado])) throw new \InvalidArgumentException('Ese estado no existe.');
        $resp = array_key_exists('responsable_id', $d) ? ((int)$d['responsable_id'] ?: null) : $actual['responsable_id'];
        $entrega = array_key_exists('entrega', $d) ? trim((string)$d['entrega']) : (string)$actual['entrega'];
        if ($entrega !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entrega)) throw new \InvalidArgumentException('La fecha de entrega no es válida.');
        $nota = array_key_exists('nota', $d) ? mb_substr(trim((string)$d['nota']), 0, 255) : (string)$actual['nota'];

        $existe = $this->db->prepare("SELECT 1 FROM lf_trabajos WHERE venta_id = ?");
        $existe->execute([$ventaId]);
        $vals = [$estado, $resp, $entrega ?: null, $nota !== '' ? $nota : null, mb_substr((string)$usuario, 0, 100) ?: null];
        if ($existe->fetchColumn()) {
            $this->db->prepare("UPDATE lf_trabajos SET estado = ?, responsable_id = ?, entrega = ?, nota = ?,
                                       actualizado_en = NOW(), actualizado_por = ? WHERE venta_id = ?")
                     ->execute(array_merge($vals, [$ventaId]));
        } else {
            $this->db->prepare("INSERT INTO lf_trabajos (estado, responsable_id, entrega, nota, actualizado_en, actualizado_por, venta_id)
                                VALUES (?,?,?,?,NOW(),?,?)")
                     ->execute(array_merge($vals, [$ventaId]));
        }
        return $estado;
    }
}
