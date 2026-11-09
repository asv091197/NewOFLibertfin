<?php
namespace LibertyFin\Http;

/**
 * Lectura de la petición. Todo lo que entra por GET o POST pasa por aquí,
 * para que ningún controlador toque $_GET directamente.
 */
final class Peticion
{
    /**
     * Filas por página en todas las tablas.
     *
     * Diez y no veinticinco: con veinticinco la tabla crece tanto que la
     * columna de al lado queda corta y la pantalla se ve desbalanceada.
     * Diez cabe en una pantalla sin bajar.
     */
    const POR_PAGINA = 10;

    public static function texto($clave, $porDefecto = '')
    { return isset($_GET[$clave]) ? trim((string)$_GET[$clave]) : $porDefecto; }

    public static function entero($clave, $porDefecto = 0)
    { return isset($_GET[$clave]) ? (int)$_GET[$clave] : $porDefecto; }

    /** Fecha en Y-m-d o cadena vacía. Nunca deja pasar texto libre a la consulta. */
    public static function fecha($clave, $porDefecto = '')
    {
        $v = self::texto($clave);
        if ($v === '') return $porDefecto;
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : $porDefecto;
    }

    /** Solo acepta valores de una lista blanca. */
    public static function opcion($clave, array $validos, $porDefecto = '')
    {
        $v = self::texto($clave);
        return in_array($v, $validos, true) ? $v : $porDefecto;
    }
}
