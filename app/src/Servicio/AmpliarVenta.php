<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use LibertyFin\Dominio\Ticket;
use PDO;

/**
 * Agrega servicios a una venta que ya existe.
 *
 * POR QUÉ
 *
 * El cliente paga tres servicios, se le hace el folio, y a los dos
 * minutos se acuerda de otros tres. Hasta ahora la única salida era
 * crear una venta nueva: dos folios, dos tickets y un historial que
 * cuenta dos visitas donde hubo una.
 *
 * Esto mete las líneas nuevas en la MISMA venta. El folio no cambia, el
 * cliente no cambia, y lo que crece es el total.
 *
 * QUÉ NO HACE, Y POR QUÉ
 *
 *   · No toca una venta CANCELADA. Revivirla por la puerta de atrás
 *     dejaría un documento anulado con movimientos nuevos dentro.
 *
 *   · No toca una venta de OTRO DÍA sin que quien la amplía lo sepa. Se
 *     puede —hay negocios que dejan una cuenta abierta— pero el aviso
 *     tiene que salir, porque ampliar la venta de la semana pasada mueve
 *     el total de un corte que ya se cerró.
 *
 *   · No cobra. Agrega lo vendido y abre saldo. El dinero entra por
 *     donde entra siempre: un abono.
 *
 *   · No borra líneas. Quitar algo de una venta ya cobrada es otra
 *     operación, con su propio permiso y su propio rastro.
 *
 * LO QUE SÍ DEJA ESCRITO
 *
 * Cada línea agregada guarda CUÁNDO se agregó. Sin eso, una venta de
 * $3,000 con seis servicios parece una venta de $3,000 con seis
 * servicios, y nadie puede explicar por qué el corte de ayer no cuadra
 * con el total de hoy.
 */
final class AmpliarVenta
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @param int    $ventaId
     * @param Ticket $ticket  solo las líneas nuevas
     * @param array  $ctx     usuario_id, concepto_gasto
     * @return array
     * @throws \InvalidArgumentException
     */
    public function agregar($ventaId, Ticket $ticket, array $ctx = [])
    {
        $ventaId = (int)$ventaId;
        if ($ticket->vacio()) {
            throw new \InvalidArgumentException('No hay servicios que agregar');
        }

        $v = $this->venta($ventaId);
        if (!$v) throw new \InvalidArgumentException('Esa venta no existe');
        if ($v['estado'] === 'cancelada') {
            throw new \InvalidArgumentException(
                'La venta ' . $v['codigo_venta'] . ' está cancelada. '
                . 'No se le puede agregar nada: haz una venta nueva.');
        }

        $t = $ticket->totales();

        // El IVA de la venta manda sobre el del ticket nuevo. Una misma
        // venta con la mitad de las líneas con IVA incluido y la otra
        // mitad sumado da un total que no cuadra con ninguna de las dos
        // formas de calcularlo.
        if ($t['iva_modo'] !== $v['iva_modo']) {
            throw new \InvalidArgumentException(
                'La venta ' . $v['codigo_venta'] . ' tiene el IVA '
                . ($v['iva_modo'] === 'sumar' ? 'sumado aparte' : 'incluido en el precio')
                . ' y lo que quieres agregar lo tiene al revés. '
                . 'Cámbialo en el ticket o haz una venta aparte.');
        }

        $this->db->beginTransaction();
        try {
            $sd = $this->db->prepare("
                INSERT INTO venta_detalles
                    (venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal, agregado_en)
                VALUES (?,?,?,?,?,?,NOW())
            ");
            foreach ($ticket->lineas() as $l) {
                $sd->execute([
                    $ventaId, $l['producto_id'], $l['cantidad'], $l['precio'], $l['descuento'],
                    Dinero::centavos($l['precio'] * $l['cantidad'] - $l['descuento']),
                ]);
            }

            // Lo agregado también sale del inventario, de la sucursal de
            // quien lo agrega (ver RegistrarVenta).
            (new Inventario($this->db))->descontarVenta($ventaId, $ticket->lineas(), $ctx['sucursal_id'] ?? null);
            // Paquetes: con el cliente de la venta que se amplía.
            (new Paquetes($this->db))->registrarVenta($ventaId, $ticket->lineas(),
                ['cliente_id' => $v['cliente_id'] ?? null, 'paquetes' => $ctx['paquetes'] ?? []]);

            if ($t['gastos'] > 0) {
                $this->db->prepare("
                    INSERT INTO gastos (venta_id, concepto, monto, tipo, categoria, fecha)
                    VALUES (?,?,?,'manual','Gasto de operación',NOW())
                ")->execute([$ventaId,
                    $ctx['concepto_gasto'] ?? 'Gastos de operación', $t['gastos']]);
            }

            // Los totales se SUMAN a los que ya había. Recalcularlos
            // desde las líneas sería más limpio en teoría, pero la venta
            // pudo llevar un descuento puesto a mano que no vive en
            // ninguna línea y se perdería.
            $this->db->prepare("
                UPDATE ventas
                SET subtotal = subtotal + ?, iva = iva + ?, total = total + ?
                WHERE id = ?
            ")->execute([$t['subtotal'], $t['iva'], $t['total'], $ventaId]);

            // Una venta que estaba liquidada vuelve a tener saldo. Si
            // quedó marcada como completada, se queda: `completada` es
            // el estado del documento, no del cobro. El saldo sale de
            // comparar total contra pagos, y ese ya cambió solo.
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Las comisiones se recalculan FUERA de la transacción: tocan
        // varias tablas y, si truena, es preferible una venta correcta
        // con comisiones a medias que perder las dos cosas.
        try {
            (new SincronizarComisiones($this->db))->paraVenta($ventaId);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] comisiones al ampliar venta ' . $ventaId . ': '
                . $e->getMessage());
        }

        $despues = $this->venta($ventaId);
        $cobrado = $this->cobrado($ventaId);

        return [
            'id'       => $ventaId,
            'codigo'   => $v['codigo_venta'],
            'agregado' => $t['total'],
            'lineas'   => count($ticket->lineas()),
            'total'    => Dinero::centavos($despues['total']),
            'cobrado'  => $cobrado,
            'saldo'    => Dinero::centavos((float)$despues['total'] - $cobrado),
            // Para avisar cuando se amplía algo de otro día.
            'de_hoy'   => date('Y-m-d', strtotime($v['fecha'])) === date('Y-m-d'),
            'fecha'    => $v['fecha'],
        ];
    }

    /**
     * Las ventas a las que se les puede agregar algo.
     *
     * Se ofrecen las del día y las que siguen con saldo, que son los dos
     * casos reales: el cliente que se acordó de otro servicio y la
     * clienta que viene cada mes a dejar su parte.
     *
     * Las canceladas no salen. Las liquidadas de días anteriores
     * tampoco: ampliar una cuenta que ya se cerró y se cobró es casi
     * siempre un error de dedo, y para el caso de verdad está el
     * buscador por folio.
     */
    public function candidatas($clienteId = null, $limite = 25)
    {
        $w = ["v.estado <> 'cancelada'"];
        $p = [];
        if ($clienteId) { $w[] = 'v.cliente_id = ?'; $p[] = (int)$clienteId; }
        $w[] = "(DATE(v.fecha) = CURDATE() OR v.total - COALESCE(pg.cobrado,0) > 0.005)";

        $sql = "
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado, v.iva_modo,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   (SELECT COUNT(*) FROM venta_detalles d WHERE d.venta_id = v.id) AS lineas
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY v.fecha DESC
            LIMIT " . max(1, (int)$limite);
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    /** Una venta por folio o por id, para el buscador de la caja. */
    public function porFolio($texto)
    {
        $texto = trim((string)$texto);
        if ($texto === '') return null;
        // Un subfolio de cobro lleva a su venta: quien teclea
        // `...-02` quiere la venta, no el renglón del abono.
        $texto = Folio::raiz($texto);

        $st = $this->db->prepare("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado, v.iva_modo,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   (SELECT COUNT(*) FROM venta_detalles d WHERE d.venta_id = v.id) AS lineas
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.codigo_venta = ? OR v.id = ?
            LIMIT 1");
        $st->execute([$texto, ctype_digit($texto) ? (int)$texto : 0]);
        return $st->fetch() ?: null;
    }

    private function venta($id)
    {
        $st = $this->db->prepare("
            SELECT id, codigo_venta, estado, total, iva_modo, fecha, cliente_id
            FROM ventas WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    private function cobrado($id)
    {
        $st = $this->db->prepare("
            SELECT COALESCE(SUM(monto),0) FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0");
        $st->execute([(int)$id]);
        return Dinero::centavos($st->fetchColumn());
    }
}
