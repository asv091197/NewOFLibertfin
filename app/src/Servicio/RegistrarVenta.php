<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use LibertyFin\Dominio\Ticket;
use PDO;

/**
 * Cobra un ticket: crea la venta, sus líneas, el gasto de operación y el
 * primer pago, todo en una transacción.
 *
 * Es el único lugar del sistema que escribe una venta. En el sistema
 * anterior `INSERT INTO ventas` estaba en tres archivos distintos
 * (caja.php, cajapruebas.php y generar_logos_placeholder.php), cada uno
 * con su propia idea de cómo tratar el IVA.
 */
final class RegistrarVenta
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @return array id y código de la venta creada
     * @throws \InvalidArgumentException si el ticket o el anticipo no son válidos
     */
    public function cobrar(Ticket $ticket, array $ctx)
    {
        if ($ticket->vacio()) {
            throw new \InvalidArgumentException('El ticket está vacío');
        }
        $anticipo = $ticket->validarAnticipo($ctx['anticipo'] ?? 0);
        $t        = $ticket->totales();
        $codigo   = date('YmdHis');

        $this->db->beginTransaction();
        try {
            // ── La venta. `total` es lo VENDIDO, nunca lo cobrado. ──
            $sqlVenta = "
                INSERT INTO ventas
                    (codigo_venta, cliente_id, usuario_id, especialista_id, especialista_nombre,
                     sucursal_id, caja_id,
                     subtotal, descuento, iva, total, iva_modo,
                     metodo_pago, estado, descripcion, fecha)
                VALUES (?,?,?,?,?, ?,?, ?,?,?,?,?, ?,'completada',?,NOW())
            ";
            // El folio es la fecha y hora, y es único: dos ventas en el mismo
            // segundo (dos cajas, o las igualas del mes generándose juntas)
            // chocaban y la segunda no se guardaba. Si ya existe, el segundo
            // que sigue. (Se prepara cada vez: reusar una sentencia que falló
            // no lo admiten todos los motores.)
            for ($intento = 0; ; $intento++) {
                try {
                    $this->db->prepare($sqlVenta)->execute([
                        $codigo,
                        $ctx['cliente_id'] ?: null,
                        $ctx['usuario_id'] ?: null,
                        // Quién va a hacer el trabajo, no quién cobró.
                        $ctx['especialista_id'] ?: null,
                        $ctx['especialista_nombre'] ?: null,
                        $ctx['sucursal_id'] ?: null,
                        $ctx['caja_id'] ?: null,
                        $t['subtotal'], 0, $t['iva'], $t['total'],
                        $t['iva_modo'] === 'sumar' ? 'sumar' : 'incluido',
                        $ctx['metodo_pago'] ?? 'efectivo',
                        trim($ctx['descripcion'] ?? ''),
                    ]);
                    break;
                } catch (\PDOException $e) {
                    $repetido = (int)($e->errorInfo[1] ?? 0) === 1062
                             || stripos($e->getMessage(), 'UNIQUE') !== false;
                    if (!$repetido || $intento >= 60) throw $e;
                    $codigo = date('YmdHis', \DateTime::createFromFormat('YmdHis', $codigo)->getTimestamp() + 1);
                }
            }
            $ventaId = (int)$this->db->lastInsertId();

            // ── Las líneas ──
            $sd = $this->db->prepare("
                INSERT INTO venta_detalles
                    (venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($ticket->lineas() as $l) {
                $sd->execute([
                    $ventaId, $l['producto_id'], $l['cantidad'], $l['precio'], $l['descuento'],
                    Dinero::centavos($l['precio'] * $l['cantidad'] - $l['descuento']),
                ]);
            }

            // ── Existencias ──
            // Dentro de la misma transacción: una venta sin su salida de
            // inventario (o al revés) es justo lo que luego no cuadra. Con
            // Inventario apagado no hace nada; con «No dejar vender» y sin
            // piezas, lanza y la venta no se guarda.
            (new Inventario($this->db))->descontarVenta($ventaId, $ticket->lineas(), $ctx['sucursal_id'] ?? null);
            // Paquetes de sesiones: los que se venden quedan a nombre del
            // cliente; las sesiones que se usan, se gastan. Misma transacción.
            (new Paquetes($this->db))->registrarVenta($ventaId, $ticket->lineas(), $ctx);

            // ── El área de la venta ──
            //
            // Se guardaba vacía: el INSERT de arriba nunca la escribía, y la
            // lista y el detalle de Ventas la leen de aquí. Es el área del
            // producto que más pesó en dinero (la categoría), la misma regla
            // con la que se resume el área de un cliente.
            $q = $this->db->prepare("
                SELECT cat.nombre
                  FROM venta_detalles d
                  INNER JOIN productos p   ON p.id = d.producto_id
                  INNER JOIN categorias cat ON cat.id = p.categoria_id
                 WHERE d.venta_id = ? AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                 GROUP BY cat.nombre
                 ORDER BY SUM(d.subtotal) DESC
                 LIMIT 1");
            $q->execute([$ventaId]);
            $area = $q->fetchColumn();
            if ($area) {
                $this->db->prepare("UPDATE ventas SET area_nombre = ? WHERE id = ?")
                         ->execute([$area, $ventaId]);
            }

            // ── Gasto de operación. Se resta de la utilidad ANTES de comisionar. ──
            if ($t['gastos'] > 0) {
                $this->db->prepare("
                    INSERT INTO gastos (venta_id, concepto, monto, tipo, categoria, fecha)
                    VALUES (?,?,?,'manual','Gasto de operación',NOW())
                ")->execute([$ventaId, $ctx['concepto_gasto'] ?? 'Gastos de operación', $t['gastos']]);
            }

            // ── El primer pago. Solo si de verdad entró dinero. ──
            if ($anticipo > 0) {
                // El primer cobro es el -01 de esta venta.
                // El banco (tarjeta o SPEI) va solo si se anotó: así un
                // cobro en efectivo no depende de que la columna exista.
                $banco = trim((string)($ctx['banco'] ?? ''));
                $this->db->prepare("
                    INSERT INTO venta_pagos
                        (venta_id, folio, monto, tipo, metodo_pago, referencia, fecha_pago, cancelado, usuario_id"
                        . ($banco !== '' ? ", banco" : "") . ")
                    VALUES (?,?,?,?,?,?,NOW(),0,?" . ($banco !== '' ? ",?" : "") . ")
                ")->execute(array_merge([
                    $ventaId, Folio::formar($codigo, 1), $anticipo, $ticket->tipoPago($anticipo),
                    $ctx['metodo_pago'] ?? 'efectivo',
                    $ctx['referencia'] ?: null,
                    $ctx['usuario_id'] ?: null,
                ], $banco !== '' ? [$banco] : []));
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'id'       => $ventaId,
            'codigo'   => $codigo,
            'total'    => $t['total'],
            'cobrado'  => $anticipo,
            'saldo'    => $ticket->saldo($anticipo),
        ];
    }
}
