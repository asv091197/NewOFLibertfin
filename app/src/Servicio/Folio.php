<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Los folios: el de la venta y los de sus cobros.
 *
 * EL PROBLEMA
 *
 * Una venta tiene un folio. Sus cobros no tenían ninguno, así que en
 * cobranza, en el corte y en los tickets aparecían identificados con el
 * folio de la venta —el mismo para los cinco abonos— o con nada.
 *
 * Cuando una clienta deja $1,500 cada mes, eso son doce renglones que
 * dicen todos `20260102143022`. Nadie puede decir cuál es cuál, ni
 * referirse a uno por teléfono, ni cuadrar un depósito contra el abono
 * que le corresponde.
 *
 * LA FORMA
 *
 *     Venta            20260102143022
 *     Primer pago      20260102143022-01
 *     Abono de marzo   20260102143022-02
 *     Abono de abril   20260102143022-03
 *
 * Se lee de un golpe que los tres son de la misma venta y en qué orden
 * entraron. Y al buscar el folio de la venta salen todos, porque el
 * subfolio empieza por él.
 *
 * POR QUÉ UN CONSECUTIVO Y NO LA FECHA
 *
 * Con la fecha dentro, dos abonos del mismo minuto chocan y hay que
 * desempatar con algo. El consecutivo por venta no choca nunca, y además
 * dice lo que de verdad importa: si es el segundo o el séptimo pago.
 *
 * POR QUÉ NO SE REUSA UN NÚMERO
 *
 * Al cancelar un abono el número NO se recicla: si el `-02` se canceló,
 * el siguiente es `-03`. Reusarlo haría que un ticket impreso apuntara a
 * un cobro distinto del que dice, y los tickets ya están en la calle.
 */
final class Folio
{
    /**
     * El siguiente subfolio de esta venta.
     *
     * Cuenta los cobros que YA EXISTEN, cancelados incluidos, por lo que
     * se explica arriba. La cuenta se hace dentro de la misma
     * transacción que inserta el cobro, así que dos cajas cobrando a la
     * vez no pueden sacar el mismo número.
     *
     * @return string 20260102143022-02
     */
    public static function siguiente(PDO $db, $ventaId, $codigoVenta = null)
    {
        $ventaId = (int)$ventaId;
        if ($codigoVenta === null) {
            $st = $db->prepare("SELECT codigo_venta FROM ventas WHERE id = ?");
            $st->execute([$ventaId]);
            $codigoVenta = (string)$st->fetchColumn();
        }
        if ($codigoVenta === '') $codigoVenta = (string)$ventaId;

        try {
            $st = $db->prepare("SELECT COUNT(*) FROM venta_pagos WHERE venta_id = ?");
            $st->execute([$ventaId]);
            $n = (int)$st->fetchColumn() + 1;
        } catch (\Throwable $e) {
            $n = 1;
        }
        return self::formar($codigoVenta, $n);
    }

    /** codigo + guion + dos dígitos. Tres cuando pasa de 99, que casi nunca. */
    public static function formar($codigoVenta, $n)
    {
        $n = max(1, (int)$n);
        return $codigoVenta . '-' . str_pad((string)$n, $n > 99 ? 3 : 2, '0', STR_PAD_LEFT);
    }

    /**
     * El folio de la venta a la que pertenece un subfolio.
     *
     * Sirve para buscar: quien teclea `20260102143022-02` en el buscador
     * quiere la venta, no un renglón suelto.
     */
    public static function raiz($folio)
    {
        $folio = trim((string)$folio);
        $g = strrpos($folio, '-');
        return $g === false ? $folio : substr($folio, 0, $g);
    }

    /** ¿Esto es un subfolio de cobro y no el folio de una venta? */
    public static function esSubfolio($folio)
    {
        return (bool)preg_match('/^.+-\d{2,3}$/', trim((string)$folio));
    }

    /**
     * Lo que se muestra cuando un cobro todavía no tiene subfolio.
     *
     * Los cobros que ya estaban guardados antes de esta columna no lo
     * tienen. Se arma al vuelo con la posición del cobro para que la
     * pantalla no enseñe un hueco, pero NO se guarda: inventar números
     * para registros viejos haría que dos pantallas distintas enseñaran
     * cosas distintas del mismo cobro.
     */
    public static function deRespaldo($codigoVenta, $posicion)
    {
        return self::formar((string)$codigoVenta, $posicion);
    }
}
