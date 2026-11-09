<?php
namespace LibertyFin\Datos;

/**
 * Cuentas por cobrar.
 *
 * La pantalla contesta "a quién le hablo hoy", no "cuánto me deben". Por
 * eso ordena por antigüedad del último abono y no por monto: $18,000
 * parados dos meses son peor noticia que $30,000 que abonaron ayer.
 */
final class CobranzaRepo extends Repo
{
    /** Base común: cada venta con saldo y sus días sin movimiento. */
    private function base()
    {
        return "
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.area_nombre, v.descripcion,
                   c.id AS cliente_id, c.nombre AS cliente, c.telefono,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(pg.ultimo, v.fecha) AS ultimo_mov,
                   DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias,
                   COALESCE(pg.abonos,0) AS abonos
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo,
                               COUNT(*) abonos
                        FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                   ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND v.total - COALESCE(pg.cobrado,0) > 0.01";
    }

    public function resumen()
    {
        return $this->uno("
            SELECT COUNT(*) AS ventas,
                   COUNT(DISTINCT cliente_id) AS clientes,
                   COALESCE(SUM(saldo),0) AS total,
                   COALESCE(SUM(CASE WHEN dias <= 30 THEN saldo END),0) AS t30,
                   COALESCE(SUM(CASE WHEN dias > 30 AND dias <= 60 THEN saldo END),0) AS t60,
                   COALESCE(SUM(CASE WHEN dias > 60 THEN saldo END),0) AS t60mas,
                   COUNT(CASE WHEN dias > 60 THEN 1 END) AS n60mas,
                   COALESCE(MAX(saldo),0) AS mayor
            FROM (" . $this->base() . ") x") ?: [];
    }

    /**
     * @param string $orden  dias | saldo | cliente
     * @param string $tramo  todos | t30 | t60 | t60mas
     */
    public function cuantas($tramo = 'todos', $buscar = '')
    {
        list($w, $p) = $this->filtro($tramo, $buscar);
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return (int)$this->valor("SELECT COUNT(*) FROM (" . $this->base() . ") x {$where}", $p);
    }

    private function filtro($tramo, $buscar)
    {
        $w = []; $p = [];
        if ($tramo === 't30')    $w[] = 'dias <= 30';
        if ($tramo === 't60')    $w[] = 'dias > 30 AND dias <= 60';
        if ($tramo === 't60mas') $w[] = 'dias > 60';
        if ($buscar !== '') { $w[] = '(cliente LIKE ? OR codigo_venta LIKE ?)';
                              $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return [$w, $p];
    }

    public function listado($orden = 'dias', $tramo = 'todos', $buscar = '', $limite = 10, $desfase = 0)
    {
        list($w, $p) = $this->filtro($tramo, $buscar);
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $orden = ['dias' => 'dias DESC, saldo DESC',
                  'saldo' => 'saldo DESC',
                  'cliente' => 'cliente, dias DESC'][$orden] ?? 'dias DESC, saldo DESC';

        return $this->todos("SELECT * FROM (" . $this->base() . ") x {$where}
            ORDER BY {$orden} LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    /** Agrupado por cliente: con quién hay que sentarse, no qué venta. */
    public function porCliente($tope = 10, $desfase = 0)
    {
        return $this->todos("
            SELECT cliente_id, cliente, telefono,
                   COUNT(*) AS ventas,
                   SUM(saldo) AS saldo,
                   MAX(dias)  AS dias,
                   MIN(ultimo_mov) AS mas_viejo
            FROM (" . $this->base() . ") x
            GROUP BY cliente_id, cliente, telefono
            ORDER BY MAX(dias) DESC, SUM(saldo) DESC
            LIMIT " . (int)$tope . " OFFSET " . (int)$desfase);
    }
}
