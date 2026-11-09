<?php
namespace LibertyFin\Datos;

/** Servicios y clientes: lo que la caja necesita para armar un ticket. */
final class CatalogoRepo extends Repo
{
    /**
     * Los servicios que se pueden vender.
     *
     * Dos detalles del esquema que cuestan caro si se ignoran:
     *
     * · `productos` NO tiene sucursal_id. La relación va por la tabla
     *   `producto_sucursal`, y con LEFT JOIN: un servicio sin renglón en
     *   esa tabla sigue siendo vendible, solo que sin stock propio. Un
     *   INNER JOIN lo desaparecería del catálogo.
     *
     * · El precio de venta es `subprecio`, no `precio`. Así lo lee
     *   caja.php del sistema anterior. `precio` queda como respaldo por
     *   si subprecio viene en cero.
     */
    public function servicios($sucursal = null, $area = '', $buscar = '', $tope = 60)
    {
        $w = ['p.activo = 1']; $p = [$sucursal ? (int)$sucursal : 0];
        if ($area !== '')   { $w[] = 'p.categoria_id = ?'; $p[] = $area; }
        if ($buscar !== '') {
            $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
            $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l;
        }
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.imagen, p.descripcion, p.unidad_medida,
                   COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
                   p.costo, p.categoria_id,
                   c.nombre AS categoria,
                   COALESCE(ps.stock, p.stock, 0) AS stock
            FROM productos p
            LEFT JOIN categorias c        ON c.id = p.categoria_id
            LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE " . implode(' AND ', $w) . "
            ORDER BY p.nombre
            LIMIT " . (int)$tope, $p);
    }

    public function areas()
    {
        return $this->todos("
            SELECT c.id, c.nombre, COUNT(p.id) AS cuantos
            FROM categorias c
            LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1
            GROUP BY c.id, c.nombre
            HAVING cuantos > 0
            ORDER BY c.nombre");
    }

    public function buscarClientes($texto, $tope = 8)
    {
        if (trim($texto) === '') return [];
        $l = '%' . trim($texto) . '%';
        return $this->todos("
            SELECT c.id, c.nombre, c.telefono,
                   COUNT(v.id) AS compras,
                   COALESCE(SUM(v.total),0) AS facturado
            FROM clientes c
            LEFT JOIN ventas v ON v.cliente_id = c.id AND v.estado <> 'cancelada'
            WHERE c.nombre LIKE ? OR c.telefono LIKE ?
            GROUP BY c.id, c.nombre, c.telefono
            ORDER BY compras DESC, c.nombre
            LIMIT " . (int)$tope, [$l, $l]);
    }

    public function cliente($id)
    {
        return $this->uno("
            SELECT c.*, COUNT(v.id) AS compras,
                   COALESCE(SUM(v.total - COALESCE(pg.cobrado,0)),0) AS saldo
            FROM clientes c
            LEFT JOIN ventas v ON v.cliente_id = c.id AND v.estado <> 'cancelada'
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE c.id = ?
            GROUP BY c.id", [(int)$id]);
    }
}
