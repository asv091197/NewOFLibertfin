<?php
namespace LibertyFin\Datos;

/**
 * Consultas de ventas y cobranza. UN solo lugar.
 *
 *   VENDIDO  = suma de ventas.total        lo facturado
 *   COBRADO  = suma de venta_pagos.monto   el dinero que entró
 *
 * Y el periodo se aplica sobre DOS columnas distintas a propósito:
 *   ventas.fecha           -> qué ventas se listan
 *   venta_pagos.fecha_pago -> qué cobros cuentan
 */
final class VentaRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        $w = []; $p = [];
        if ($desde) { $w[] = 'v.fecha >= ?'; $p[] = $desde . ' 00:00:00'; }
        if ($hasta) { $w[] = 'v.fecha < ?';  $p[] = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'; }
        return [$w, $p];
    }

    const ESTADOS = ['liquidada' => 'Liquidadas', 'debe' => 'Con saldo', 'cancelada' => 'Canceladas'];

    const JOIN_COBRADO = "LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos
                                      WHERE cancelado = 0 GROUP BY venta_id ) pv ON pv.venta_id = v.id";

    private static function condicionEstado($estado)
    {
        switch ($estado) {
            case 'cancelada': return "v.estado = 'cancelada'";
            case 'liquidada': return "v.estado <> 'cancelada' AND v.total - COALESCE(pv.cobrado,0) <= 0.01";
            case 'debe':      return "v.estado <> 'cancelada' AND v.total - COALESCE(pv.cobrado,0) > 0.01";
        }
        return '';
    }

    private function rangoPagos($desde, $hasta)
    {
        $w = 'WHERE cancelado = 0'; $p = [];
        if ($desde) { $w .= ' AND fecha_pago >= ?'; $p[] = $desde; }
        if ($hasta) { $w .= ' AND fecha_pago <= ?'; $p[] = $hasta; }
        return [$w, $p];
    }

    public function resumen($desde, $hasta, array $filtros = [])
    {
        list($w, $p)   = $this->rango($desde, $hasta);
        list($pw, $pp) = $this->rangoPagos($desde, $hasta);

        if ($c = self::condicionEstado($filtros['estado'] ?? '')) $w[] = $c;
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $jc = self::JOIN_COBRADO;

        return $this->uno("
            SELECT
                COUNT(DISTINCT v.id) AS ventas,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN COALESCE(pg.cobrado,0) END),0) AS cobrado,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS vendido,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total - COALESCE(pg.cobrado,0) END),0) AS saldo,
                COALESCE(AVG(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS promedio,
                SUM(CASE WHEN v.estado = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN v.estado <> 'cancelada'
                          AND v.total - COALESCE(pg.cobrado,0) > 0.01 THEN 1 ELSE 0 END) AS con_saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos {$pw} GROUP BY venta_id )
                   pg ON pg.venta_id = v.id
            {$jc}
            {$where}
        ", array_merge($pp, $p)) ?: [];
    }

    public function listado($desde, $hasta, array $filtros = [], $limite = 25, $desfase = 0)
    {
        list($w, $p) = $this->rango($desde, $hasta);

        if ($c = self::condicionEstado($filtros['estado'] ?? '')) $w[] = $c;
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $jc = self::JOIN_COBRADO;

        return $this->todos("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado, v.factura_uuid,
                   v.factura_metodo_pago, c.regimen_fiscal,
                   COALESCE(NULLIF(v.area_nombre,''), (SELECT cat.nombre FROM venta_detalles d2 INNER JOIN productos p2 ON p2.id = d2.producto_id INNER JOIN categorias cat ON cat.id = p2.categoria_id WHERE d2.venta_id = v.id AND cat.nombre <> '' GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1)) AS area_nombre,
                   c.nombre AS cliente,
                   COALESCE(pv.cobrado,0) AS cobrado,
                   v.total - COALESCE(pv.cobrado,0) AS saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            {$jc}
            {$where}
            ORDER BY v.fecha DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase . "
        ", $p);
    }

    public function cobradoPorMes($meses = 6)
    {
        return $this->todos("
            SELECT DATE_FORMAT(p.fecha_pago, '%Y-%m') AS mes,
                   SUM(p.monto) AS cobrado,
                   COUNT(*)     AS pagos
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= DATE_SUB(CURDATE(), INTERVAL " . (int)$meses . " MONTH)
            GROUP BY DATE_FORMAT(p.fecha_pago, '%Y-%m')
            ORDER BY mes
        ");
    }

    public function saldosAbiertos($tope = 5)
    {
        return $this->todos("
            SELECT v.id, v.codigo_venta, v.fecha, v.total,
                   COALESCE(NULLIF(v.area_nombre,''), (SELECT cat.nombre FROM venta_detalles d2 INNER JOIN productos p2 ON p2.id = d2.producto_id INNER JOIN categorias cat ON cat.id = p2.categoria_id WHERE d2.venta_id = v.id AND cat.nombre <> '' GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1)) AS area_nombre,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY saldo DESC
            LIMIT " . (int)$tope . "
        ");
    }

    // ─────────────────────────────────────────────────────────────
    // DETALLE DE UNA VENTA
    // ─────────────────────────────────────────────────────────────

    public function detalle($id)
    {
        return $this->uno("
            SELECT v.*,
                   COALESCE(NULLIF(v.area_nombre,''), (SELECT cat.nombre FROM venta_detalles d2 INNER JOIN productos p2 ON p2.id = d2.producto_id INNER JOIN categorias cat ON cat.id = p2.categoria_id WHERE d2.venta_id = v.id AND cat.nombre <> '' GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1)) AS area_nombre,
                   c.nombre AS cliente, c.telefono,
                   u.nombre AS vendedor,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(g.gastos,0) AS gastos
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN usuarios u ON u.id = v.usuario_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            WHERE v.id = ?", [(int)$id]);
    }

    public function lineas($id)
    {
    // Los impuestos del producto viajan con el renglón para que el
    // ticket y la factura puedan desglosar IVA e IEPS sin una consulta
    // por línea. Un producto borrado deja NULL y el ticket cae en la
    // regla de la empresa, que es como se comportaba antes.
    return $this->todos("
        SELECT vd.*, p.nombre AS producto, p.codigo,
               COALESCE(p.unidad_medida, '') AS unidad_medida,
               COALESCE(p.clave_sat, '') AS clave_sat,
               p.iva_aplica, p.iva_tasa, p.ieps_aplica, p.ieps_tasa
        FROM venta_detalles vd
        LEFT JOIN productos p ON p.id = vd.producto_id
        WHERE vd.venta_id = ?", [(int)$id]);
    }

    public function pagos($id)
    {
        return $this->todos("
            SELECT p.*, COALESCE(pc.comision,0) AS comision
            FROM venta_pagos p
            LEFT JOIN ( SELECT pago_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY pago_id ) pc ON pc.pago_id = p.id
            WHERE p.venta_id = ?
            ORDER BY p.fecha_pago, p.id", [(int)$id]);
    }

    public function gastos($id)
    {
        return $this->todos("
            SELECT id, concepto, monto, categoria, fecha
            FROM gastos WHERE venta_id = ?
              AND tipo = 'manual' AND categoria <> 'Costo de venta'
            ORDER BY id", [(int)$id]);
    }

    public function comisiones($id)
    {
        return $this->todos("
            SELECT vc.id, vc.colaborador_nombre, vc.area_nombre, vc.porcentaje_regla AS pct,
                   vc.venta_detalle_id, pr.nombre AS producto,
                   vc.monto_comision AS asignada,
                   COALESCE(pc.devengada,0) AS devengada,
                   vc.monto_comision - COALESCE(pc.devengada,0) AS pendiente
            FROM venta_comisiones vc
            LEFT JOIN ( SELECT venta_comision_id, SUM(monto) devengada
                        FROM pago_comisiones GROUP BY venta_comision_id ) pc
                   ON pc.venta_comision_id = vc.id
            LEFT JOIN venta_detalles vd ON vd.id = vc.venta_detalle_id AND vd.venta_id = vc.venta_id
            LEFT JOIN productos pr      ON pr.id = vd.producto_id
            WHERE vc.venta_id = ? AND vc.cancelada = 0
            ORDER BY vc.porcentaje_regla DESC", [(int)$id]);
    }

    public function cuantas($desde, $hasta, array $filtros = [])
    {
        list($w, $p) = $this->rango($desde, $hasta);
        if ($c = self::condicionEstado($filtros['estado'] ?? '')) $w[] = $c;
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $jc = self::JOIN_COBRADO;
        return (int)$this->valor("
            SELECT COUNT(DISTINCT v.id) FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            {$jc} {$where}", $p);
    }

    public function conSaldo($tope = 30)
    {
        return $this->todos("
            SELECT v.id, v.codigo_venta, v.fecha, v.total,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   ROUND(v.total - COALESCE(pg.cobrado,0), 2) AS saldo,
                   DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                        FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                   ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY dias DESC, saldo DESC
            LIMIT " . (int)$tope);
    }

    // ─────────────────────────────────────────────────────────────
    // FACTURACIÓN (CFDI)
    // ─────────────────────────────────────────────────────────────

    public function datosFiscalesCliente($clienteId)
    {
        return $this->uno("
            SELECT rfc, razon_social, regimen_fiscal AS regimen,
                   uso_cfdi, codigo_postal AS cp, email,
                   calle_numero, numero_interior, colonia,
                   delegacion_municipio, ciudad, estado_direccion, pais
            FROM clientes WHERE id = ?", [(int)$clienteId]);
    }

    public function marcarFacturada($ventaId, array $d)
    {
        $this->db->prepare("
            UPDATE ventas SET
                facturada              = 1,
                factura_uuid           = ?,
                factura_folio          = ?,
                facturapi_receipt_id   = ?,
                urlfacturacion         = ?,
                factura_fecha_timbrado = ?,
                factura_subtotal       = ?,
                factura_iva            = ?,
                factura_total          = ?,
                factura_rfc_receptor   = ?,
                factura_razon_social   = ?,
                factura_uso_cfdi       = ?,
                factura_metodo_pago    = ?,
                factura_forma_pago     = ?,
                factura_cp_receptor    = ?,
                factura_email_receptor = ?
            WHERE id = ?
        ")->execute([
            $d['factura_uuid'],
            $d['factura_folio'],
            $d['facturapi_id'],
            $d['urlfacturacion'],
            $d['factura_fecha_timbrado'],
            $d['factura_subtotal'],
            $d['factura_iva'],
            $d['factura_total'],
            $d['factura_rfc_receptor'],
            $d['factura_razon_social'],
            $d['factura_uso_cfdi'],
            $d['factura_metodo_pago'],
            $d['factura_forma_pago'],
            $d['factura_cp_receptor'],
            $d['factura_email_receptor'],
            (int) $ventaId,
        ]);
    }

    public function guardarFiscalesCliente($clienteId, array $d)
    {
        $this->db->prepare("
            UPDATE clientes SET
                rfc            = ?,
                razon_social   = ?,
                regimen_fiscal = ?,
                uso_cfdi       = ?,
                codigo_postal  = ?
            WHERE id = ?
        ")->execute([
            $d['rfc'],
            $d['razon_social'],
            $d['regimen'],
            $d['uso_cfdi'],
            $d['cp'],
            (int) $clienteId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // COMPLEMENTOS DE PAGO (CFDI tipo "P")
    // ─────────────────────────────────────────────────────────────

    /**
     * Un pago con el contexto de su venta. Para emitir su complemento.
     */
    public function pago($pagoId)
    {
    return $this->uno("
        SELECT p.*, p.creado_en AS pagado_en,
               v.factura_uuid, v.factura_metodo_pago,
               v.total AS venta_total, v.cliente_id, v.codigo_venta
        FROM venta_pagos p
        INNER JOIN ventas v ON v.id = p.venta_id
        WHERE p.id = ?", [(int) $pagoId]);
    }

    /**
     * Suma de los pagos ANTERIORES a este (por fecha, luego por id).
     * Necesario porque el complemento lleva `last_balance`: lo que se debía
     * ANTES de este abono, no el saldo actual.
     */
    public function pagadoAntesDe($ventaId, $pagoId, $fechaPago)
    {
        return (float) $this->valor("
            SELECT COALESCE(SUM(monto), 0) FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0
              AND (fecha_pago < ? OR (fecha_pago = ? AND id < ?))",
            [(int) $ventaId, $fechaPago, $fechaPago, (int) $pagoId]);
    }

    /**
     * Número de parcialidad (1-based) de este pago dentro de su venta,
     * contando solo los no cancelados.
     */
    public function parcialidadDe($ventaId, $pagoId, $fechaPago)
    {
        return 1 + (int) $this->valor("
            SELECT COUNT(*) FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0
              AND (fecha_pago < ? OR (fecha_pago = ? AND id < ?))",
            [(int) $ventaId, $fechaPago, $fechaPago, (int) $pagoId]);
    }

    /**
     * Lista de pagos con el estado de su complemento, para la vista.
     * Incluye `pagado_antes` y `parcialidad` para mostrarlos en la tabla
     * sin volver a calcular.
     */
    public function pagosConComplemento($ventaId)
    {
        return $this->todos("
        SELECT p.id, p.monto, p.fecha_pago, p.creado_en, p.metodo_pago,
               p.referencia, p.tipo, p.cancelado,
               p.cfdi_complemento_id, p.cfdi_complemento_uuid, p.cfdi_complemento_en,
               (SELECT COALESCE(SUM(p2.monto), 0) FROM venta_pagos p2
                 WHERE p2.venta_id = p.venta_id AND p2.cancelado = 0
                   AND (p2.fecha_pago < p.fecha_pago
                        OR (p2.fecha_pago = p.fecha_pago AND p2.id < p.id))) AS pagado_antes,
               (SELECT COUNT(*) FROM venta_pagos p3
                 WHERE p3.venta_id = p.venta_id AND p3.cancelado = 0
                   AND (p3.fecha_pago < p.fecha_pago
                        OR (p3.fecha_pago = p.fecha_pago AND p3.id < p.id))) + 1 AS parcialidad
        FROM venta_pagos p
        WHERE p.venta_id = ?
        ORDER BY p.fecha_pago, p.id", [(int) $ventaId]);
    }   

    /**
     * Guarda el resultado del timbrado del complemento en `venta_pagos`.
     * Idempotente por diseño: solo actualiza si `cfdi_complemento_uuid`
     * todavía está vacío, para no quemar timbre real por reenvíos.
     */
    public function marcarComplementoPago($pagoId, array $d)
    {
        $this->db->prepare("
            UPDATE venta_pagos SET
                cfdi_complemento_id   = ?,
                cfdi_complemento_uuid = ?,
                cfdi_complemento_en   = NOW()
            WHERE id = ? AND (cfdi_complemento_uuid IS NULL OR cfdi_complemento_uuid = '')
        ")->execute([$d['id'], $d['uuid'], (int) $pagoId]);
    }
}