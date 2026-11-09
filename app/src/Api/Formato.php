<?php
namespace LibertyFin\Api;

/**
 * Cómo sale cada cosa por la API.
 *
 * Los nombres de los campos son un contrato: un programa ajeno los lee.
 * Por eso se arman aquí, campo por campo, y no se manda la fila de la
 * base tal cual: una columna nueva, renombrada o interna (el uuid de la
 * factura, el token de facturación) no debe aparecer ni desaparecer de la
 * API sin que alguien lo decida.
 *
 *   importes  número con dos decimales (1500.0, 99.5)
 *   fechas    ISO 8601 con zona: 2026-10-16T15:41:38-06:00
 *   días      AAAA-MM-DD cuando la base solo guarda el día (fecha de pago)
 */
final class Formato
{
    public static function dinero($v)
    {
        return round((float)$v, 2);
    }

    public static function fechaHora($v)
    {
        if ($v === null || $v === '' || strncmp((string)$v, '0000', 4) === 0) return null;
        try {
            return (new \DateTime((string)$v))->format(DATE_ATOM);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function dia($v)
    {
        if ($v === null || $v === '') return null;
        return substr((string)$v, 0, 10);
    }

    /** cancelada · liquidada · con_saldo */
    public static function estadoVenta(array $v)
    {
        if (($v['estado'] ?? '') === 'cancelada') return 'cancelada';
        return (float)($v['saldo'] ?? 0) > 0.01 ? 'con_saldo' : 'liquidada';
    }

    /** Una venta en una lista. */
    public static function ventaResumen(array $v)
    {
        return [
            'id'      => (int)$v['id'],
            'folio'   => (string)$v['codigo_venta'],
            'fecha'   => self::fechaHora($v['fecha'] ?? null),
            'cliente' => $v['cliente'] !== null && $v['cliente'] !== '' ? (string)$v['cliente'] : null,
            'area'    => ($v['area_nombre'] ?? '') !== '' ? (string)$v['area_nombre'] : null,
            'total'   => self::dinero($v['total']),
            'cobrado' => self::dinero($v['cobrado'] ?? 0),
            'saldo'   => self::dinero(max(0, (float)($v['saldo'] ?? 0))),
            'estado'  => self::estadoVenta($v),
        ];
    }

    /** Una venta con sus renglones y sus cobros. */
    public static function venta(array $v, array $lineas, array $pagos, $conComision = false)
    {
        return [
            'id'          => (int)$v['id'],
            'folio'       => (string)$v['codigo_venta'],
            'fecha'       => self::fechaHora($v['fecha'] ?? null),
            'cliente'     => !empty($v['cliente_id']) ? [
                'id'       => (int)$v['cliente_id'],
                'nombre'   => (string)$v['cliente'],
                'telefono' => ($v['telefono'] ?? '') !== '' ? (string)$v['telefono'] : null,
            ] : null,
            'vendedor'    => ($v['vendedor'] ?? '') !== '' ? (string)$v['vendedor'] : null,
            'area'        => ($v['area_nombre'] ?? '') !== '' ? (string)$v['area_nombre'] : null,
            'descripcion' => ($v['descripcion'] ?? '') !== '' ? (string)$v['descripcion'] : null,
            'subtotal'    => self::dinero($v['subtotal'] ?? 0),
            'descuento'   => self::dinero($v['descuento'] ?? 0),
            'iva'         => self::dinero($v['iva'] ?? 0),
            'total'       => self::dinero($v['total']),
            'cobrado'     => self::dinero($v['cobrado'] ?? 0),
            'saldo'       => self::dinero(max(0, (float)($v['saldo'] ?? 0))),
            'estado'      => self::estadoVenta($v),
            'lineas'      => array_map([self::class, 'linea'], $lineas),
            'pagos'       => array_map(function ($p) use ($conComision) {
                return self::pago($p, $conComision);
            }, $pagos),
        ];
    }

    public static function linea(array $l)
    {
        return [
            'producto_id'     => (int)$l['producto_id'],
            'producto'        => ($l['producto'] ?? '') !== '' ? (string)$l['producto'] : null,
            'codigo'          => ($l['codigo'] ?? '') !== '' ? (string)$l['codigo'] : null,
            'cantidad'        => (float)$l['cantidad'],
            'precio_unitario' => self::dinero($l['precio_unitario']),
            'descuento'       => self::dinero($l['descuento'] ?? 0),
            'subtotal'        => self::dinero($l['subtotal']),
        ];
    }

    /**
     * Un cobro. La comisión de cada cobro solo va a quien puede ver
     * comisiones: el cajero no las ve en pantalla, tampoco por la API.
     */
    public static function pago(array $p, $conComision = false)
    {
        $r = [
            'id'         => (int)$p['id'],
            'folio'      => ($p['folio'] ?? '') !== '' ? (string)$p['folio'] : null,
            'fecha'      => self::dia($p['fecha_pago'] ?? null),
            'monto'      => self::dinero($p['monto']),
            'tipo'       => (string)($p['tipo'] ?? 'abono'),
            'metodo'     => ($p['metodo_pago'] ?? '') !== '' ? (string)$p['metodo_pago'] : null,
            'banco'      => ($p['banco'] ?? '') !== '' ? (string)$p['banco'] : null,
            'referencia' => ($p['referencia'] ?? '') !== '' ? (string)$p['referencia'] : null,
            'cancelado'  => (int)($p['cancelado'] ?? 0) === 1,
        ];
        if ($r['cancelado']) {
            $r['motivo_cancelacion'] = ($p['motivo_cancelacion'] ?? '') !== '' ? (string)$p['motivo_cancelacion'] : null;
        }
        if ($conComision) $r['comision'] = self::dinero($p['comision'] ?? 0);
        return $r;
    }

    public static function cliente(array $c)
    {
        return [
            'id'       => (int)$c['id'],
            'nombre'   => (string)$c['nombre'],
            'telefono' => ($c['telefono'] ?? '') !== '' ? (string)$c['telefono'] : null,
            'email'    => ($c['email'] ?? '') !== '' ? (string)$c['email'] : null,
            'rfc'      => ($c['rfc'] ?? '') !== '' ? (string)$c['rfc'] : null,
            'alta'     => self::fechaHora($c['fecha_creacion'] ?? null),
            'compras'  => (int)($c['compras'] ?? 0),
            'saldo'    => self::dinero(max(0, (float)($c['saldo'] ?? 0))),
        ];
    }

    public static function producto(array $p)
    {
        return [
            'id'        => (int)$p['id'],
            'codigo'    => (string)$p['codigo'],
            'nombre'    => (string)$p['nombre'],
            'precio'    => self::dinero($p['precio']),
            'categoria' => ($p['categoria'] ?? '') !== '' ? (string)$p['categoria'] : null,
            'unidad'    => ($p['unidad_medida'] ?? '') !== '' ? (string)$p['unidad_medida'] : null,
        ];
    }

    /** Una venta con saldo, vista desde cobranza. */
    public static function saldoAbierto(array $s)
    {
        return [
            'venta_id'          => (int)$s['id'],
            'folio'             => (string)$s['codigo_venta'],
            'fecha'             => self::fechaHora($s['fecha'] ?? null),
            'cliente'           => !empty($s['cliente_id']) ? [
                'id'       => (int)$s['cliente_id'],
                'nombre'   => (string)$s['cliente'],
                'telefono' => ($s['telefono'] ?? '') !== '' ? (string)$s['telefono'] : null,
            ] : null,
            'total'             => self::dinero($s['total']),
            'cobrado'           => self::dinero($s['cobrado']),
            'saldo'             => self::dinero($s['saldo']),
            'abonos'            => (int)($s['abonos'] ?? 0),
            'ultimo_movimiento' => self::dia($s['ultimo_mov'] ?? null),
            // Desde el último abono, no desde la venta: quien abona seguido no es urgente.
            'dias_sin_abono'    => (int)($s['dias'] ?? 0),
        ];
    }
}
