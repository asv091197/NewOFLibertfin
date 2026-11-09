<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\CajaRepo;
use PDO;

/**
 * El turno de caja abierto de quien está usando el sistema.
 *
 * POR QUÉ EXISTE ESTO
 *
 * `$_SESSION['caja_id']` solo se escribía dentro de CorteControlador::index().
 * Es decir: la caja existía en la base, pero el sistema no se enteraba
 * hasta que alguien abría /corte en esa misma sesión. El resultado era
 * el que se veía todos los días:
 *
 *   · El cajero entra por la mañana, va directo a /caja y le sale
 *     "No tienes caja abierta" aunque la dejó abierta ayer.
 *   · Se cae la sesión, vuelve a entrar y pasa lo mismo.
 *   · Abre la caja, lo mandan a /caja y ahí sí funciona… hasta el
 *     siguiente login.
 *
 * Y lo peor no es el aviso: es que las ventas se guardaban con
 * `caja_id = NULL` y al cerrar el turno no aparecían en el corte. El
 * cajero cuadraba contra un total al que le faltaban ventas reales.
 *
 * La sesión es una caché, no la fuente de la verdad. Aquí se pregunta a
 * la base la primera vez de cada petición y se recuerda para las
 * siguientes, que es lo que la sesión debía haber hecho desde el
 * principio.
 */
final class Caja
{
    /** Memoria de esta petición, para no repetir la consulta. */
    private static $turno = null;
    private static $leido = false;

    /**
     * El turno abierto, o null.
     *
     * @return array|null la fila de `caja`
     */
    public static function abierta(PDO $db)
    {
        if (self::$leido) return self::$turno;
        self::$leido = true;
        self::$turno = null;

        $usuario = (int)($_SESSION['usuario_id'] ?? 0);
        if ($usuario <= 0) return null;

        try {
            $c = (new CajaRepo($db))->abierta($usuario, self::sucursal());
        } catch (\Throwable $e) {
            // Sin caja se puede cobrar igual; lo que no se puede es
            // dejar la pantalla rota por una consulta que falló.
            error_log('[LibertyFin] leer caja abierta: ' . $e->getMessage());
            return null;
        }
        if (!$c) {
            unset($_SESSION['caja_id'], $_SESSION['caja_desde']);
            return null;
        }

        self::$turno = $c;
        $_SESSION['caja_id']    = (int)$c['id'];
        $_SESSION['caja_desde'] = self::desde($c);
        return $c;
    }

    /** El id del turno abierto, o null. Es lo que se guarda en la venta. */
    public static function id(PDO $db)
    {
        $c = self::abierta($db);
        return $c ? (int)$c['id'] : null;
    }

    public static function hay(PDO $db) { return self::id($db) !== null; }

    /**
     * Desde cuándo cuenta el turno.
     *
     * `fecha_apertura` es un TIMESTAMP con DEFAULT CURRENT_TIMESTAMP, así
     * que en la práctica siempre viene. El respaldo es por bases viejas
     * que lo traían nulo: contar desde medianoche es menos malo que
     * contar desde 1970 y arrastrar las ventas de toda la historia.
     */
    public static function desde(array $caja)
    {
        $d = $caja['fecha_apertura'] ?? $caja['created_at'] ?? null;
        return ($d && $d !== '0000-00-00 00:00:00') ? $d : date('Y-m-d 00:00:00');
    }

    /**
     * La sucursal con la que se abre y se busca el turno.
     *
     * Un usuario sin sucursal asignada tenía `sucursal_id = null`, que al
     * insertar se volvía 0 y dejaba turnos colgando de una sucursal que
     * no existe. Se queda en 0 para no romper los que ya están así, pero
     * la búsqueda del turno ya no depende de que coincida.
     */
    public static function sucursal()
    {
        return (int)($_SESSION['sucursal_id'] ?? 0);
    }

    /** Al cerrar el turno. Borra la caché para que nadie siga cobrando ahí. */
    public static function olvidar()
    {
        self::$turno = null;
        self::$leido = true;
        unset($_SESSION['caja_id'], $_SESSION['caja_desde']);
    }

    /** Al abrir uno nuevo: se recuerda sin volver a consultar. */
    public static function recordar(array $caja)
    {
        self::$turno = $caja;
        self::$leido = true;
        $_SESSION['caja_id']    = (int)$caja['id'];
        $_SESSION['caja_desde'] = self::desde($caja);
    }
}
