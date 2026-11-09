<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Abonos sobre una venta ya cerrada.
 *
 * Dos reglas que el sistema anterior no siempre respetaba:
 *   1. No se puede cobrar más que el saldo.
 *   2. Después de tocar un pago SIEMPRE se resincronizan las comisiones.
 *
 * Saltarse la primera fue lo que dejó a Izol Nieto con un pago de $864.69
 * sobre una venta de $745.42.
 */
final class RegistrarPago
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function abonar($ventaId, array $datos)
    {
        $ventaId = (int)$ventaId;
        $monto   = Dinero::centavos($datos['monto'] ?? 0);
        if ($monto <= 0) throw new \InvalidArgumentException('El monto debe ser mayor a cero');

        $st = $this->db->prepare("
            SELECT v.total, v.estado, COALESCE(pg.cobrado,0) AS cobrado
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.id = ?");
        $st->execute([$ventaId]);
        $v = $st->fetch();
        if (!$v) throw new \InvalidArgumentException('La venta no existe');
        if ($v['estado'] === 'cancelada') throw new \InvalidArgumentException('La venta está cancelada');

        $saldo = Dinero::centavos((float)$v['total'] - (float)$v['cobrado']);
        if ($saldo <= 0.005) throw new \InvalidArgumentException('Esta venta ya está liquidada');
        if ($monto > $saldo) {
            throw new \InvalidArgumentException(
                'El abono (' . Dinero::pesos($monto) . ') supera el saldo (' . Dinero::pesos($saldo) . ')');
        }

        $tipo  = ($saldo - $monto) <= 0.005 ? 'liquidacion' : 'abono';
        $fecha = !empty($datos['fecha']) ? $datos['fecha'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

        // EL SUBFOLIO. Sin esto los doce abonos de una clienta que deja
        // $1,500 cada mes salían los doce con el folio de la venta, y
        // no había forma de referirse a uno por teléfono ni de cuadrar
        // un depósito contra el abono que le toca. Ver Servicio\Folio.
        $folio = Folio::siguiente($this->db, $ventaId);

        // El banco (tarjeta o transferencia) va solo si se anotó: los
        // abonos que llegan por liga o en efectivo no lo traen.
        $banco = trim((string)($datos['banco'] ?? ''));
        $this->db->prepare("
            INSERT INTO venta_pagos
                (venta_id, folio, monto, tipo, metodo_pago, referencia, fecha_pago, cancelado, usuario_id"
                . ($banco !== '' ? ", banco" : "") . ")
            VALUES (?,?,?,?,?,?,?,0,?" . ($banco !== '' ? ",?" : "") . ")
        ")->execute(array_merge([
            $ventaId, $folio, $monto, $tipo,
            $datos['metodo'] ?? 'efectivo',
            !empty($datos['referencia']) ? $datos['referencia'] : null,
            $fecha,
            $datos['usuario_id'] ?? null,
        ], $banco !== '' ? [$banco] : []));
        $pagoId = (int)$this->db->lastInsertId();

        (new SincronizarComisiones($this->db))->paraVenta($ventaId);
        // El id del abono se devuelve para que quien lo haya disparado
        // —una liga de pago, por ejemplo— pueda dejar constancia de cuál
        // fue, y no vuelva a abonar si se consulta dos veces.
        return ['monto' => $monto, 'tipo' => $tipo, 'folio' => $folio,
                'saldo' => Dinero::centavos($saldo - $monto),
                'pago_id' => $pagoId];
    }

    /** Cancelación lógica: deja rastro y devuelve las comisiones a su sitio. */
    public function cancelar($pagoId, $motivo, $usuarioId = null)
    {
        $pagoId = (int)$pagoId;
        $st = $this->db->prepare("SELECT venta_id, cancelado FROM venta_pagos WHERE id = ?");
        $st->execute([$pagoId]);
        $p = $st->fetch();
        if (!$p) throw new \InvalidArgumentException('Ese pago no existe');
        if ((int)$p['cancelado'] === 1) throw new \InvalidArgumentException('Ese pago ya estaba cancelado');
        if (trim((string)$motivo) === '') throw new \InvalidArgumentException('Hay que escribir el motivo');

        $this->db->prepare("
            UPDATE venta_pagos
            SET cancelado = 1, fecha_cancelacion = NOW(), motivo_cancelacion = ?
            WHERE id = ?")->execute([trim($motivo), $pagoId]);

        (new SincronizarComisiones($this->db))->paraVenta($p['venta_id']);
        return (int)$p['venta_id'];
    }
}
