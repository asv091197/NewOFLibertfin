<?php
/**
 * Accesos de una empresa ya creada: reenviarlos, o darla de baja.
 *
 *   php app/bin/accesos-empresa.php                      lista las empresas
 *   php app/bin/accesos-empresa.php --reenviar=3         contraseña nueva + correo
 *   php app/bin/accesos-empresa.php --reenviar=3 --sin-correo
 *   php app/bin/accesos-empresa.php --revisar=3          qué movimientos tiene
 *   php app/bin/accesos-empresa.php --eliminar=3         la da de baja (pregunta)
 *
 * POR QUÉ EXISTE, HABIENDO PANTALLA
 *
 * Lo mismo está en /plataforma. Esto sirve para los dos ratos en que esa
 * pantalla no ayuda: cuando el correo está intermitente y hay que repartir
 * contraseñas de varias empresas seguidas, y cuando algo salió mal en el
 * alta y la empresa quedó a medias —sin usuario administrador, con la base
 * vacía— que es justo cuando la pantalla enseña un renglón y poco más.
 *
 * Las guardas son las mismas, y a propósito: viven en
 * Servicio\AccesosEmpresa, no aquí. Una terminal no es permiso para
 * saltarse el conteo de movimientos.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\AccesosEmpresa;
use LibertyFin\Servicio\Integraciones;

$color = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c(string $t, string $x): string {
    global $color; if (!$color) return $t;
    $m = ['v' => '32', 'r' => '31', 'a' => '33', 'g' => '1', 'd' => '90'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m";
}
function titulo(string $t): void { echo "\n" . c($t, 'g') . "\n"; }
function bien(string $t): void   { echo '  ' . c('ok  ', 'v') . $t . "\n"; }
function mal(string $t): void    { echo '  ' . c('MAL ', 'r') . $t . "\n"; }
function ojo(string $t): void    { echo '  ' . c('ojo ', 'a') . $t . "\n"; }
function nota(string $t): void   { echo '      ' . c($t, 'd') . "\n"; }
function morir(string $t): void  { echo "\n" . c('Nada cambió.', 'r') . ' ' . $t . "\n\n"; exit(1); }
function col(string $t, int $n): string { return $t . str_repeat(' ', max(1, $n - mb_strlen($t))); }
function arg(array $argv, string $n, string $d = ''): string {
    foreach ($argv as $a) {
        if (strncmp($a, '--' . $n . '=', strlen($n) + 3) === 0) return trim(substr($a, strlen($n) + 3));
    }
    return $d;
}
function bandera(array $argv, string $n): bool { return in_array('--' . $n, $argv, true); }

echo c('ACCESOS DE EMPRESA · LibertyFin', 'g') . "\n";

$archivoCfg = getenv('LF_CONFIG') ?: $raiz . '/config/config.php';
if (!is_readable($archivoCfg)) morir('No se encuentra ' . $archivoCfg);
$cfg = require $archivoCfg;
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);
Integraciones::cargar($raiz);

$nombrePrincipal = (string)($cfg['bd']['principal'] ?? '');
if ($nombrePrincipal === '') morir('config.php no dice cuál es la base principal (bd.principal)');
try { $principal = Conexion::de($nombrePrincipal); $principal->query("SELECT 1"); }
catch (\Throwable $e) { morir('No se pudo conectar a ' . $nombrePrincipal . ': ' . $e->getMessage()); }

$svc = new AccesosEmpresa($principal);

$reenviar = (int)arg($argv, 'reenviar', '0');
$eliminar = (int)arg($argv, 'eliminar', '0');
$revisar  = (int)arg($argv, 'revisar', '0');

// ── Sin argumentos: la lista ──
if (!$reenviar && !$eliminar && !$revisar) {
    titulo('Empresas');
    $todas = $principal->query("
        SELECT id, nombre_empresa, nombre_base_datos, usuario_admin, email_admin, activo, plan
        FROM empresas ORDER BY id")->fetchAll();
    if (!$todas) { nota('No hay ninguna.'); echo "\n"; exit(0); }
    echo '  ' . c(col('#', 5) . col('Empresa', 28) . col('Base', 24) . col('Admin', 16) . 'Estado', 'd') . "\n";
    foreach ($todas as $e) {
        echo '  ' . col('#' . $e['id'], 5)
           . col(mb_substr((string)$e['nombre_empresa'], 0, 26), 28)
           . col(mb_substr((string)$e['nombre_base_datos'], 0, 22), 24)
           . col(mb_substr((string)($e['usuario_admin'] ?: '—'), 0, 14), 16)
           . ($e['activo'] ? c('activa', 'v') : c('suspendida', 'a')) . "\n";
    }
    echo "\n  " . c('--reenviar=N', 'd') . "  contraseña nueva y correo\n";
    echo '  ' . c('--revisar=N', 'd') . "   qué movimientos tiene\n";
    echo '  ' . c('--eliminar=N', 'd') . "  darla de baja (solo si nunca operó)\n\n";
    exit(0);
}

// ── --revisar ──
if ($revisar) {
    $e = $svc->empresa($revisar);
    if (!$e) morir('No existe la empresa #' . $revisar);
    titulo($e['nombre_empresa'] . '  ·  ' . $e['nombre_base_datos']);
    try { $act = $svc->actividad($revisar); }
    catch (\Throwable $ex) { morir($ex->getMessage()); }
    if (!$act) {
        bien('sin un solo movimiento: se puede eliminar');
        nota('php app/bin/accesos-empresa.php --eliminar=' . $revisar);
    } else {
        ojo('ya operó, no se puede eliminar:');
        foreach ($act as $t => $n) nota('· ' . $n . ' ' . str_replace('_', ' ', $t));
        nota('Para que deje de entrar sin borrar nada, suspéndela en /plataforma.');
    }
    echo "\n";
    exit(0);
}

// ── --reenviar ──
if ($reenviar) {
    $e = $svc->empresa($reenviar);
    if (!$e) morir('No existe la empresa #' . $reenviar);
    titulo('Accesos nuevos para ' . $e['nombre_empresa']);
    ojo('la contraseña anterior deja de servir en cuanto esto termine');

    if (!bandera($argv, 'si')) {
        echo "\n  " . c('¿Seguir?', 'g') . ' ' . c('[s/N]', 'd') . ': ';
        if (strtolower(trim((string)fgets(STDIN))) !== 's') {
            echo "\n  Cancelado. Nada cambió.\n\n"; exit(0);
        }
    }

    // --sin-correo: la cambia y la enseña, sin intentar mandarla. Para
    // cuando el proveedor bloqueó la cuenta y cada intento fallido
    // alarga el bloqueo.
    $host = $cfg['sitio']['landing'] ?? '';
    $host = $host ? (parse_url($host, PHP_URL_HOST) ?: 'libertyfin.com.mx') : 'libertyfin.com.mx';
    $url  = 'https://' . $host . '/login';

    try { $r = $svc->regenerar($reenviar, $url, !bandera($argv, 'sin-correo')); }
    catch (\Throwable $ex) { morir($ex->getMessage()); }

    echo "\n" . c('  ── Para entregarle al cliente ──────────────', 'g') . "\n\n";
    echo '  ' . col('Empresa', 14) . $r['empresa'] . "\n";
    echo '  ' . col('Usuario', 14) . c($r['usuario'], 'g') . "\n";
    echo '  ' . col('Contraseña', 14) . c($r['clave'], 'g') . "\n";
    echo '  ' . col('Correo', 14) . ($r['email'] ?: '—') . "\n\n";
    if ($r['correo_ok'])      bien('se la mandamos por correo a ' . $r['email']);
    elseif (bandera($argv, 'sin-correo')) nota('No se intentó mandar el correo (--sin-correo).');
    else {
        mal('el correo NO salió: ' . $r['correo_error']);
        nota('Entrégasela tú. Para ver por qué: php app/bin/revisar-correo.php');
    }
    echo "\n";
    exit(0);
}

// ── --eliminar ──
$e = $svc->empresa($eliminar);
if (!$e) morir('No existe la empresa #' . $eliminar);

titulo('Eliminar ' . $e['nombre_empresa']);
try { $act = $svc->actividad($eliminar); }
catch (\Throwable $ex) { morir($ex->getMessage()); }

if ($act) {
    mal($e['nombre_empresa'] . ' ya operó:');
    foreach ($act as $t => $n) nota('· ' . $n . ' ' . str_replace('_', ' ', $t));
    echo "\n";
    ojo('Una empresa con movimientos no se elimina: sus ventas y facturas hay');
    nota('que conservarlas. Suspéndela en /plataforma y deja de entrar, con');
    nota('sus datos enteros y la posibilidad de deshacerlo.');
    echo "\n";
    exit(1);
}

bien('sin un solo movimiento');
nota('base ' . $e['nombre_base_datos'] . ' · NO se va a borrar, queda en el hosting');
echo "\n  Escribe el nombre de la empresa para confirmar\n  "
   . c($e['nombre_empresa'], 'd') . "\n  > ";
$escrito = trim((string)fgets(STDIN));

try { $r = $svc->eliminar($eliminar, $escrito); }
catch (\Throwable $ex) { morir($ex->getMessage()); }

echo "\n";
bien($r['empresa'] . ' eliminada');
nota('Su base ' . $r['base'] . ' sigue en el hosting y no estorba.');
nota('Si quieres el espacio: cPanel → Bases de datos MySQL → eliminarla.');
echo "\n";
