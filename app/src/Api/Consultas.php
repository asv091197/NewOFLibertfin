<?php
namespace LibertyFin\Api;

use LibertyFin\Datos\Repo;

/**
 * Las consultas que la API necesita y las pantallas no.
 *
 * Las pantallas listan clientes POR PERIODO (quién compró en octubre); un
 * sistema que se sincroniza necesita la lista completa. Lo demás —ventas,
 * cobranza, cobros— usa los mismos repositorios que las pantallas, para
 * que la API y el sistema nunca digan cifras distintas.
 */
final class Consultas extends Repo
{
    const SALDOS = "LEFT JOIN (
            SELECT v.cliente_id,
                   COUNT(*) AS compras,
                   SUM(v.total - COALESCE(pg.cobrado, 0)) AS saldo
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.cliente_id IS NOT NULL
            GROUP BY v.cliente_id
        ) s ON s.cliente_id = c.id";

    private function filtroCliente($buscar)
    {
        if ($buscar === '') return ['', []];
        $l = '%' . $buscar . '%';
        return ['WHERE (c.nombre LIKE ? OR c.telefono LIKE ? OR c.email LIKE ? OR c.rfc LIKE ?)', [$l, $l, $l, $l]];
    }

    public function clientes($buscar, $limite, $desfase)
    {
        list($w, $p) = $this->filtroCliente($buscar);
        return $this->todos("
            SELECT c.id, c.nombre, c.telefono, c.email, c.rfc, c.fecha_creacion,
                   COALESCE(s.compras, 0) AS compras, COALESCE(s.saldo, 0) AS saldo
            FROM clientes c " . self::SALDOS . " {$w}
            ORDER BY c.nombre, c.id
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantosClientes($buscar)
    {
        list($w, $p) = $this->filtroCliente($buscar);
        return (int)$this->valor("SELECT COUNT(*) FROM clientes c {$w}", $p);
    }

    public function cliente($id)
    {
        return $this->uno("
            SELECT c.id, c.nombre, c.telefono, c.email, c.rfc, c.direccion, c.fecha_creacion,
                   COALESCE(s.compras, 0) AS compras, COALESCE(s.saldo, 0) AS saldo
            FROM clientes c " . self::SALDOS . "
            WHERE c.id = ?", [(int)$id]);
    }

    private function filtroProducto($buscar)
    {
        $w = 'WHERE p.activo = 1';
        if ($buscar === '') return [$w, []];
        $l = '%' . $buscar . '%';
        return [$w . ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)', [$l, $l]];
    }

    public function productos($buscar, $limite, $desfase)
    {
        list($w, $p) = $this->filtroProducto($buscar);
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.unidad_medida,
                   COALESCE(NULLIF(p.subprecio, 0), p.precio) AS precio,
                   cat.nombre AS categoria
            FROM productos p
            LEFT JOIN categorias cat ON cat.id = p.categoria_id
            {$w}
            ORDER BY p.nombre, p.id
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantosProductos($buscar)
    {
        list($w, $p) = $this->filtroProducto($buscar);
        return (int)$this->valor("SELECT COUNT(*) FROM productos p {$w}", $p);
    }
}
