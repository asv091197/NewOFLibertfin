<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Cancelar una venta completa.
 *
 * QUÉ DESHACE, TODO EN UNA TRANSACCIÓN
 *
 *   · La venta queda `cancelada`, con quién, cuándo y por qué. No se
 *     borra: los folios no se reciclan y la historia tiene que cuadrar.
 *   · Sus cobros se cancelan (el dinero se devuelve; con tarjeta o SPEI la
 *     devolución se hace por fuera, esto solo deja el registro).
 *   · Sus comisiones se cancelan y deja de haber comisión devengada.
 *   · Las piezas que se llevó regresan al inventario.
 *   · Las sesiones de paquete que usó regresan al cliente, y los paquetes
 *     que vendió dejan de valer.
 *   · Los usos de promoción se liberan (cuentan para el límite).
 *
 * Lo que NO hace: cancelar la factura ante el SAT. Si la venta está
 * facturada, avisa: eso se cancela en Facturación.
 */
final class CancelarVenta
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @return array ['cobros' => n, 'devuelto' => monto, 'facturada' => bool, 'folio' => ...]
     * @throws \InvalidArgumentException
     */
    public function cancelar($ventaId, $motivo, $usuarioNombre = '')
    {
        $ventaId = (int)$ventaId;
        $motivo  = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$motivo)), 0, 255);
        if (mb_strlen($motivo) < 5) throw new \InvalidArgumentException('Escribe por qué se cancela (al menos unas palabras).');

        $st = $this->db->prepare("SELECT id, codigo_venta, estado, factura_uuid FROM ventas WHERE id = ?");
        $st->execute([$ventaId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new \InvalidArgumentException('Esa venta no existe.');
        if ($v['estado'] === 'cancelada') throw new \InvalidArgumentException('La venta ya estaba cancelada.');

        $quien = $usuarioNombre !== '' ? $usuarioNombre : ($_SESSION['usuario_nombre'] ?? null);
        $r = ['folio' => $v['codigo_venta'], 'facturada' => !empty($v['factura_uuid'])];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE ventas SET estado = 'cancelada', cancelada_en = NOW(), cancelada_por = ?,
                                motivo_cancelacion = ? WHERE id = ?")->execute([$quien, $motivo, $ventaId]);

            // Los cobros vivos.
            $st = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(monto),0) AS m FROM venta_pagos
                                      WHERE venta_id = ? AND cancelado = 0");
            $st->execute([$ventaId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            $r['cobros'] = (int)$p['n']; $r['devuelto'] = round((float)$p['m'], 2);
            $this->db->prepare("UPDATE venta_pagos SET cancelado = 1, fecha_cancelacion = NOW(), motivo_cancelacion = ?
                                WHERE venta_id = ? AND cancelado = 0")->execute(['Venta cancelada: ' . $motivo, $ventaId]);

            // Las comisiones.
            $this->db->prepare("UPDATE venta_comisiones SET cancelada = 1, cancelada_por = ?, fecha_cancelacion = NOW(),
                                motivo_cancelacion = ? WHERE venta_id = ? AND cancelada = 0")
                     ->execute([$_SESSION['usuario_id'] ?? null, 'Venta cancelada: ' . $motivo, $ventaId]);

            // Inventario, paquetes y promociones: cada uno si existe en esta base.
            $this->siHay("lf_inventario_movimientos", function () use ($ventaId) {
                (new Inventario($this->db, ['activo' => 'si']))->devolverVenta($ventaId, 'Venta cancelada');
            });
            $this->siHay("lf_paquete_usos", function () use ($ventaId) {
                (new Paquetes($this->db))->deshacerVenta($ventaId);
            });
            $this->siHay("lf_promocion_usos", function () use ($ventaId) {
                $this->db->prepare("DELETE FROM lf_promocion_usos WHERE venta_id = ?")->execute([$ventaId]);
            });

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Sin cobros ni asignaciones vivas, no queda nada devengado.
        try { (new SincronizarComisiones($this->db))->paraVenta($ventaId); }
        catch (\Throwable $e) { error_log('[LibertyFin] comisiones al cancelar ' . $ventaId . ': ' . $e->getMessage()); }
        try {
            Auditoria::anota('venta.cancelar', 'venta ' . $v['codigo_venta'], 'activa', 'cancelada: ' . $motivo);
        } catch (\Throwable $e) { /* sin bitácora se cancela igual */ }
        return $r;
    }

    /** Corre $f solo si la tabla existe (una base sin esa migración no truena). */
    private function siHay($tabla, callable $f)
    {
        try { $this->db->query("SELECT 1 FROM $tabla LIMIT 1"); }
        catch (\Throwable $e) { return; }
        $f();
    }
}
