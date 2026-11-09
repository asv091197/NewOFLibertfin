<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Conexiones a la base, una por nombre y reutilizada toda la petición.
 * En el sistema anterior cada llamada abría un PDO nuevo: caja.php abría
 * tres por carga.
 */
final class Conexion
{
    private static $vivas = [];
    private static $cfg = null;

    public static function configurar(array $cfg) { self::$cfg = $cfg; }

    public static function de($base)
    {
        // Sin nombre de base, PDO conecta al servidor pero sin catálogo,
        // y la primera consulta revienta con "1046 No database selected"
        // y una traza que no dice de dónde vino. Mejor fallar aquí.
        if (trim((string)$base) === '') {
            throw new \RuntimeException(
                'No hay base de datos seleccionada. Si entraste con una cuenta de '
                . 'plataforma, esta pantalla es de empresa y no te corresponde.');
        }
        if (isset(self::$vivas[$base])) return self::$vivas[$base];
        if (self::$cfg === null) throw new \RuntimeException('Conexion::configurar() no se ha llamado');
        $c = self::$cfg;
        // El puerto es opcional: el hosting usa el de siempre, y las
        // pruebas levantan su propio MySQL en otro para no tocar nada.
        $puerto = !empty($c['puerto']) ? ';port=' . (int)$c['puerto'] : '';
        $pdo = new PDO(
            "mysql:host={$c['host']}{$puerto};dbname={$base};charset=utf8mb4",
            $c['usuario'], $c['clave'],
            [
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES         => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
        // La zona de la SESIÓN, no la del servidor: así funciona aunque
        // el hosting esté en UTC y no se pueda cambiar.
        if (!empty($c['zona_sql'])) {
            $pdo->exec("SET time_zone = '" . str_replace("'", '', $c['zona_sql']) . "'");
        }
        return self::$vivas[$base] = $pdo;
    }
}
