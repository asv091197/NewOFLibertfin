<?php
/**
 * Shopify: la tarea que sincroniza a todas las empresas conectadas.
 *
 *   php app/bin/shopify-sincronizar.php                 sincroniza todas
 *   php app/bin/shopify-sincronizar.php --empresa=BASE  solo una
 *   php app/bin/shopify-sincronizar.php --revisar       solo revisa config y tiendas, no mueve nada
 *   php app/bin/shopify-sincronizar.php --crudo         enseña lo que va y viene (sin llaves)
 *
 * Los avisos llegan al instante, pero no siempre. Esta tarea pregunta por
 * las órdenes que cambiaron desde la última vez y manda las existencias
 * pendientes. Es idempotente: correrla seguido no descuenta nada dos veces.
 *
 * En cPanel → Cron Jobs, cada 10 minutos:
 *
 *   * /10 * * * *  php /home/USUARIO/libertyfin-app/bin/shopify-sincronizar.php >/dev/null 2>&1
 *   (sin el espacio entre * y /10)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaShopifyRepo;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Servicio\Shopify;
use LibertyFin\Servicio\ShopifyInventario;

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

echo c('SHOPIFY · LibertyFin · ' . date('Y-m-d H:i:s'), 'g') . "\n";

titulo('La aplicación');
if (!Shopify::activa()) {
    mal('El bloque `shopify` de config/integraciones.php no está completo.');
    nota('Falta: ' . implode(', ', Shopify::faltan()));
    exit(1);
}
bien('Client ID ' . Shopify::clientId() . ' · API ' . Shopify::version());
nota('Permisos: ' . Shopify::scopes());
$dom = trim((string)(Shopify::config()['dominio'] ?? ''));
if ($dom === '') ojo('`dominio` vacío: el retorno y los avisos usan la dirección del navegador de quien conecta.');
elseif (strncasecmp($dom, 'https://', 8) !== 0) ojo('`dominio` no es https: Shopify no manda avisos sin https.');
else nota('URL de retorno a dar de alta en la app: ' . rtrim($dom, '/') . '/shopify/retorno');
if (!function_exists('openssl_encrypt')) { mal('PHP no tiene openssl: no se pueden cifrar las llaves.'); exit(1); }

$nombrePrincipal = (string)($cfg['bd']['principal'] ?? '');
try { $principal = Conexion::de($nombrePrincipal); }
catch (\Throwable $e) { mal('No se pudo abrir la base principal: ' . $e->getMessage()); exit(1); }

$tiendas = (new RutaShopifyRepo($principal))->todas();
if ($solo !== '') $tiendas = array_values(array_filter($tiendas, function ($f) use ($solo) { return $f['empresa_db'] === $solo; }));

titulo('Tiendas conectadas: ' . count($tiendas));
if (!$tiendas) { nota('Ninguna empresa ha conectado su tienda todavía.'); exit(0); }

$fallos = 0;
foreach ($tiendas as $f) {
    $base = $f['empresa_db'];
    echo "\n  " . c($base, 'g') . ' · ' . ($f['nombre'] ?: $f['tienda']) . "\n";
    try {
        $db = Conexion::de($base);
        if (!Migraciones::alDia($db)) {
            $antes = Migraciones::versionDe($db);
            $hecho = Migraciones::aplicar($db);
            if ($hecho) bien('base puesta al día: versión ' . $antes . ' → ' . max($hecho));
            if ($m = Migraciones::fallo()) {
                mal('la migración ' . $m['version'] . ' falló: ' . $m['mensaje']);
                $fallos++;
                continue;
            }
        }

        $api = new Shopify();
        if ($crudo) $api->espiar(function ($q, $t) { nota($q . ' ' . $t); });
        $srv = new ShopifyInventario($db, $api);
        $c = $srv->cuenta();

        if (!$c || strtolower((string)$c['tienda']) !== strtolower((string)$f['tienda'])) {
            ojo('el índice apunta aquí pero la empresa tiene otra tienda (o ninguna). Se ignora.');
            continue;
        }
        if (!$srv->conectada()) { ojo('tienda ' . $c['estado'] . ($c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '')); continue; }
        if (!$srv->encendido())  { ojo('Shopify o Inventario está apagado en Ajustes → Módulos: no se sincroniza.'); continue; }
        if ($srv->ubicacion() === '') ojo('sin ubicación elegida: no se mandan existencias hasta elegirla en Ajustes.');

        bien('conectada · avisos ' . ((int)$c['avisos_ok'] ? 'activos' : 'apagados')
             . ' · ubicación ' . ($c['location_nombre'] ?: '—')
             . ' · mandar existencias ' . ((int)$c['enviar_existencias'] ? 'ENCENDIDO' : 'apagado'));
        if ($revisar) { $r = $srv->repo()->resumen(); nota($r['total'] . ' variantes, ' . $r['vinculadas'] . ' vinculadas, ' . $r['pendientes'] . ' por mandar'); continue; }

        $r = $srv->sincronizar(200);
        bien($r['revisadas'] . ' órdenes revisadas · ' . $r['aplicadas'] . ' descontadas · ' . $r['devueltas'] . ' devueltas · '
             . $r['enviadas'] . ' existencias mandadas' . ($r['fallas'] ? ' · ' . c($r['fallas'] . ' con error', 'r') : '')
             . ($r['quedan'] ? ' · quedan más para la siguiente vuelta' : ''));
        if ($r['fallas']) $fallos++;
    } catch (\Throwable $e) {
        mal($e->getMessage());
        error_log('[LibertyFin] shopify-sincronizar ' . $base . ': ' . $e->getMessage());
        $fallos++;
    }
}
echo "\n";
exit($fallos ? 2 : 0);
