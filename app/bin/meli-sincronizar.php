<?php
/**
 * Mercado Libre: la tarea que sincroniza a todas las empresas conectadas.
 *
 *   php app/bin/meli-sincronizar.php                 sincroniza todas
 *   php app/bin/meli-sincronizar.php --empresa=BASE  solo una
 *   php app/bin/meli-sincronizar.php --revisar       solo revisa config y cuentas, no mueve nada
 *   php app/bin/meli-sincronizar.php --crudo         enseña lo que va y viene (sin llaves)
 *
 * PARA QUÉ, SI YA HAY AVISOS
 *
 * Los avisos de Mercado Libre llegan al instante, pero no siempre: el
 * servidor estuvo caído, el hosting cortó la petición, o en local no hay
 * dirección pública. Esta tarea pregunta por las órdenes que cambiaron
 * desde la última vez y manda las existencias que quedaron pendientes.
 * Es idempotente: correrla seguido no descuenta nada dos veces.
 *
 * En cPanel → Cron Jobs, cada 10 minutos:
 *
 *   * /10 * * * *  php /home/USUARIO/libertyfin-app/bin/meli-sincronizar.php >/dev/null 2>&1
 *   (sin el espacio entre * y /10)
 *
 * LAS LLAVES NO SE IMPRIMEN NUNCA, ni con --crudo.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaMeliRepo;
use LibertyFin\Servicio\MeliInventario;
use LibertyFin\Servicio\MercadoLibre;
use LibertyFin\Servicio\Migraciones;

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
function arg(array $argv, string $n, string $d = ''): string {
    foreach ($argv as $a) {
        if (strncmp($a, '--' . $n . '=', strlen($n) + 3) === 0) return trim(substr($a, strlen($n) + 3));
    }
    return $d;
}
function bandera(array $argv, string $n): bool { return in_array('--' . $n, $argv, true); }

$archivoCfg = getenv('LF_CONFIG') ?: $raiz . '/config/config.php';
if (!is_readable($archivoCfg)) { mal('No se encuentra ' . $archivoCfg); exit(1); }
$cfg = require $archivoCfg;
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);

$revisar = bandera($argv, 'revisar');
$crudo   = bandera($argv, 'crudo');
$solo    = arg($argv, 'empresa');

echo c('MERCADO LIBRE · LibertyFin · ' . date('Y-m-d H:i:s'), 'g') . "\n";

// ── La aplicación ───────────────────────────────────────────────
titulo('La aplicación');
if (!MercadoLibre::activa()) {
    mal('El bloque `mercadolibre` de config/integraciones.php no está completo.');
    nota('Falta: ' . implode(', ', MercadoLibre::faltan()));
    exit(1);
}
bien('App ID ' . MercadoLibre::appId() . ' · país ' . MercadoLibre::pais());
$ret = MercadoLibre::retorno();
if (strncasecmp($ret, 'https://', 8) !== 0) ojo('`retorno` no es https: Mercado Libre la rechaza fuera de pruebas locales.');
if (substr(rtrim($ret, '/'), -strlen('/mercadolibre/retorno')) !== '/mercadolibre/retorno') {
    ojo('`retorno` debería terminar en /mercadolibre/retorno (es: ' . $ret . ')');
}
if (!function_exists('openssl_encrypt')) { mal('PHP no tiene openssl: no se pueden cifrar las llaves.'); exit(1); }

$nombrePrincipal = (string)($cfg['bd']['principal'] ?? '');
try { $principal = Conexion::de($nombrePrincipal); }
catch (\Throwable $e) { mal('No se pudo abrir la base principal: ' . $e->getMessage()); exit(1); }

$cuentas = (new RutaMeliRepo($principal))->todas();
if ($solo !== '') $cuentas = array_values(array_filter($cuentas, function ($f) use ($solo) { return $f['empresa_db'] === $solo; }));

titulo('Cuentas conectadas: ' . count($cuentas));
if (!$cuentas) { nota('Ninguna empresa ha conectado su cuenta todavía.'); exit(0); }

$fallos = 0;
foreach ($cuentas as $f) {
    $base = $f['empresa_db'];
    echo "\n  " . c($base, 'g') . ' · ' . ($f['nickname'] ?: $f['meli_user_id']) . "\n";
    try {
        $db = Conexion::de($base);
        if (!Migraciones::alDia($db)) Migraciones::aplicar($db);

        $api = new MercadoLibre();
        if ($crudo) $api->espiar(function ($q, $t) { nota($q . ' ' . $t); });
        $srv = new MeliInventario($db, $api);
        $c = $srv->cuenta();

        if (!$c || (string)$c['meli_user_id'] !== (string)$f['meli_user_id']) {
            ojo('el índice apunta aquí pero la empresa tiene otra cuenta (o ninguna). Se ignora.');
            continue;
        }
        if (!$srv->conectada()) { ojo('autorización ' . $c['estado'] . ($c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '')); continue; }
        if (!$srv->encendido())  { ojo('Mercado Libre o Inventario está apagado en Ajustes → Módulos: no se sincroniza.'); continue; }

        if ($srv->token() === '') {
            mal('no se pudo obtener una llave vigente: ' . ($srv->repo()->cuenta()['ultimo_error'] ?? $api->error()));
            $fallos++;
            continue;
        }
        bien('llave vigente hasta ' . $srv->repo()->cuenta()['expira_en']
             . ' · mandar existencias ' . ((int)$c['enviar_existencias'] ? 'ENCENDIDO' : 'apagado'));
        if ($revisar) { $r = $srv->repo()->resumen(); nota($r['total'] . ' publicaciones, ' . $r['vinculadas'] . ' vinculadas, ' . $r['pendientes'] . ' por mandar'); continue; }

        $r = $srv->sincronizar(200);
        bien($r['revisadas'] . ' órdenes revisadas · ' . $r['aplicadas'] . ' descontadas · ' . $r['devueltas'] . ' devueltas · '
             . $r['enviadas'] . ' existencias mandadas' . ($r['fallas'] ? ' · ' . c($r['fallas'] . ' con error', 'r') : '')
             . ($r['quedan'] ? ' · quedan más para la siguiente vuelta' : ''));
        if ($r['fallas']) $fallos++;
    } catch (\Throwable $e) {
        mal($e->getMessage());
        error_log('[LibertyFin] meli-sincronizar ' . $base . ': ' . $e->getMessage());
        $fallos++;
    }
}
echo "\n";
exit($fallos ? 2 : 0);
