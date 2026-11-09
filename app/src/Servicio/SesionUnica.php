<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use PDO;

/**
 * UNA SESIÓN POR CUENTA.
 *
 * Una cuenta abierta en dos lugares a la vez es una cuenta compartida (o
 * una contraseña que se filtró). Cada cuenta tiene aquí UNA sesión
 * vigente, en la base principal (`lf_sesiones`), con una ficha al azar
 * que también guarda la sesión de PHP.
 *
 * AL ENTRAR, si la cuenta tiene otra sesión con movimiento en los últimos
 * ACTIVA segundos, en otro navegador, NO se entra de golpe: se dice dónde
 * está abierta y desde cuándo, y se pregunta si cerrarla (ver
 * Autenticar::entrar y la pantalla de login). Si la otra está inactiva,
 * o es este mismo navegador, se reemplaza sin preguntar.
 *
 * Por qué preguntar y no bloquear: bloquear deja afuera a quien dejó la
 * sesión abierta en la computadora del trabajo, y eso se vuelve una
 * llamada a soporte. Preguntando nadie se queda afuera, y compartir la
 * cuenta deja de servir: cada vez que uno entra, el otro sale.
 *
 * EN CADA PETICIÓN el portero (public/index.php) pregunta vigente(): si
 * la ficha de esta sesión ya no es la de la cuenta, otra la reemplazó y
 * esta se cierra. Es una consulta por llave primaria. Además, cada página
 * abierta pregunta sola cada 15 s (lf-chat.js), para enterarse sin clic.
 *
 * Si la tabla falla, NO se saca a nadie: mejor una sesión doble un rato
 * que todo el mundo afuera por un problema de la base.
 */
final class SesionUnica
{
    const ACTIVA  = 900;   // 15 min: con movimiento en ese lapso, "está abierta"
    const ESPERA  = 180;   // cuánto vale un "¿cerrar la otra y entrar?" sin contestar

    /** El dispositivo de la sesión que desplazó a esta (para el aviso). */
    public static $desplazadaPor = '';

    private static function db()
    {
        $db = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        if (empty($_SESSION['lf_sesiones_tabla'])) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS lf_sesiones (
                    cuenta      VARCHAR(80)  NOT NULL PRIMARY KEY,
                    token       CHAR(40)     NOT NULL,
                    dispositivo VARCHAR(120) NULL,
                    ip          VARCHAR(45)  NULL,
                    disp        CHAR(32)     NULL,
                    inicio      DATETIME     NOT NULL,
                    ultimo      DATETIME     NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $_SESSION['lf_sesiones_tabla'] = 1;
        }
        return $db;
    }

    /**
     * La llave de una cuenta. Los ids se repiten entre la tabla de
     * plataforma y las de cada empresa: van con su origen.
     */
    public static function cuenta($tipo, $usuarioId, $empresaId = 0)
    {
        return $tipo === 'p' ? 'p:' . (int)$usuarioId : 'e:' . (int)$empresaId . ':' . (int)$usuarioId;
    }

    public static function cuentaDeSesion()
    {
        // Mientras un administrador ve el sistema como otro, la sesión
        // SIGUE SIENDO SUYA: la cuenta es la real, no la suplantada.
        //
        // Sin esto, cambiar `usuario_id` hacía que esta sesión pareciera
        // una segunda sesión del cajero, el portero la daba por
        // desplazada en la siguiente petición y echaba al administrador
        // al login sin decir por qué. Y de paso habría cerrado la sesión
        // de verdad del cajero, que podía estar cobrando en ese momento.
        $u = (int)(Suplantar::real()['usuario_id'] ?? ($_SESSION['usuario_id'] ?? 0));
        if (!$u) return null;
        if (!empty($_SESSION['plataforma'])) return self::cuenta('p', $u);
        $e = (int)($_SESSION['empresa_id'] ?? 0);
        return $e ? self::cuenta('e', $u, $e) : null;
    }

    /** "Chrome · Windows": lo que se le enseña a la persona. */
    public static function dispositivo($ua = null)
    {
        $ua = (string)($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $nav = 'Navegador';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet',
                  'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome',
                  'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $nav = $v; break; }
        }
        // iPhone y Android antes que Mac y Linux: sus agentes también los mencionan.
        $so = 'otro sistema';
        foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
                  'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'CrOS' => 'Chromebook',
                  'Linux' => 'Linux'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $so = $v; break; }
        }
        return $nav . ' · ' . $so;
    }

    /**
     * Este navegador. Una cookie al azar que dura un año: así, volver a
     * entrar desde el mismo navegador (la sesión venció, se borró la
     * cookie de sesión) no pregunta por "otro dispositivo" que es este.
     */
    public static function navegador()
    {
        $d = (string)($_COOKIE['lf_disp'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $d)) {
            $d = bin2hex(random_bytes(16));
            setcookie('lf_disp', $d, [
                'expires' => time() + 365 * 86400, 'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
            ]);
            $_COOKIE['lf_disp'] = $d;
        }
        return $d;
    }

    /**
     * ¿La cuenta está abierta AHORA en otro navegador? La fila (con
     * `dispositivo` y `hace` en segundos) o null.
     */
    public static function otraActiva($cuenta)
    {
        try {
            $st = self::db()->prepare("
                SELECT dispositivo, disp, TIMESTAMPDIFF(SECOND, ultimo, NOW()) AS hace
                FROM lf_sesiones WHERE cuenta = ?");
            $st->execute([$cuenta]);
            $f = $st->fetch();
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sesiones (consultar): ' . $e->getMessage());
            return null;
        }
        if (!$f || (int)$f['hace'] > self::ACTIVA) return null;
        if (!empty($f['disp']) && hash_equals((string)$f['disp'], self::navegador())) return null;
        return $f;
    }

    /** Esta sesión pasa a ser LA de la cuenta. Las demás quedan fuera. */
    public static function abrir($cuenta)
    {
        $token = bin2hex(random_bytes(20));
        try {
            self::db()->prepare("
                INSERT INTO lf_sesiones (cuenta, token, dispositivo, ip, disp, inicio, ultimo)
                VALUES (?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE token = VALUES(token), dispositivo = VALUES(dispositivo),
                    ip = VALUES(ip), disp = VALUES(disp), inicio = NOW(), ultimo = NOW()
            ")->execute([$cuenta, $token, mb_substr(self::dispositivo(), 0, 120),
                         substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), self::navegador()]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sesiones (abrir): ' . $e->getMessage());
        }
        $_SESSION['lf_sesion_cuenta']   = $cuenta;
        $_SESSION['lf_sesion_token']    = $token;
        // Si en este navegador había avisos de escritorio de OTRA persona,
        // se quitan: quien entra no hereda los avisos de quien se fue.
        Push::soltarNavegador(self::navegador(), $cuenta);
    }

    /**
     * ¿Esta sesión sigue siendo la de su cuenta? False: otra la reemplazó
     * (y `$desplazadaPor` dice desde qué dispositivo).
     */
    public static function vigente()
    {
        $cuenta = self::cuentaDeSesion();
        if (!$cuenta) return true;
        $mio = (string)($_SESSION['lf_sesion_token'] ?? '');
        // Se revisa en CADA petición. Antes se confiaba en la última
        // revisión durante 20 s y, en ese lapso, la sesión desplazada
        // seguía funcionando: parecía que no la había sacado.
        try {
            $db = self::db();
            $st = $db->prepare("SELECT token, dispositivo, TIMESTAMPDIFF(SECOND, ultimo, NOW()) AS hace
                                FROM lf_sesiones WHERE cuenta = ?");
            $st->execute([$cuenta]);
            $f = $st->fetch();

            if ($f && $mio !== '' && hash_equals((string)$f['token'], $mio)) {
                // Es la vigente. El "último movimiento" se anota como mucho
                // una vez por minuto.
                if ((int)$f['hace'] >= 60) {
                    $db->prepare("UPDATE lf_sesiones SET ultimo = NOW() WHERE cuenta = ? AND token = ?")
                       ->execute([$cuenta, $mio]);
                }
                return true;
            }
            // Tenía ficha y ya no es la de la cuenta (o la fila se borró
            // porque la otra sesión salió): otra la reemplazó.
            if ($mio !== '') {
                self::$desplazadaPor = $f ? (string)$f['dispositivo'] : '';
                return false;
            }
            // Una sesión abierta antes de que existiera esto (sin ficha).
            // Si la cuenta está en uso ahora mismo en otro lado, la de
            // antes cede; si no, se registra como la vigente.
            if ($f && (int)$f['hace'] <= self::ACTIVA) {
                self::$desplazadaPor = (string)$f['dispositivo'];
                return false;
            }
            self::abrir($cuenta);
            return true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sesiones (revisar): ' . $e->getMessage());
            return true;
        }
    }

    /** Al salir: la cuenta queda libre (solo si esta era la vigente). */
    public static function cerrar()
    {
        $cuenta = self::cuentaDeSesion();
        $mio = (string)($_SESSION['lf_sesion_token'] ?? '');
        if (!$cuenta || $mio === '') return;
        try {
            self::db()->prepare("DELETE FROM lf_sesiones WHERE cuenta = ? AND token = ?")->execute([$cuenta, $mio]);
        } catch (\Throwable $e) { /* se libera sola al pasar ACTIVA */ }
    }

    /** El "¿cerrar la otra y entrar?" pendiente, si sigue vigente. */
    public static function pendiente()
    {
        $p = $_SESSION['lf_login_pendiente'] ?? null;
        if (!$p || ($p['hasta'] ?? 0) < time()) return null;
        return $p;
    }

    /** "hace 3 min", para la pantalla de entrar. */
    public static function hace($seg)
    {
        $seg = (int)$seg;
        if ($seg < 60) return 'hace un momento';
        $m = intdiv($seg, 60);
        return $m === 1 ? 'hace 1 minuto' : 'hace ' . $m . ' minutos';
    }
}