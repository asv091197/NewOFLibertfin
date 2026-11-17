<?php
/**
 * TikTok Shop: la tarea que sincroniza a todas las empresas conectadas.
 *
 *   php app/bin/tiktok-sincronizar.php                 sincroniza todas
 *   php app/bin/tiktok-sincronizar.php --empresa=BASE  solo una
 *   php app/bin/tiktok-sincronizar.php --revisar       solo revisa config y tiendas, no mueve nada
 *   php app/bin/tiktok-sincronizar.php --crudo         enseña lo que va y viene (sin llaves)
 *
 * Los avisos llegan al instante, pero no siempre. Esta tarea pregunta por
 * las órdenes que cambiaron desde la última vez y manda las existencias
 * pendientes. Es idempotente: correrla seguido no descuenta nada dos veces.
 *
 * Además RENUEVA LAS LLAVES: TikTok las da por unos días. Si una tienda no
 * tiene movimiento y nadie corre esto, la llave caduca y hay que reconectar.
 *
 * En cPanel → Cron Jobs, cada 10 minutos:
 *
 *   * /10 * * * *  php /home/USUARIO/libertyfin-app/bin/tiktok-sincronizar.php >/dev/null 2>&1
 *   (sin el espacio entre * y /10)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaTikTokRepo;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Servicio\TikTok;
use LibertyFin\Servicio\TikTokInventario;

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

echo c('TIKTOK SHOP · LibertyFin · ' . date('Y-m-d H:i:s'), 'g') . "\n";

titulo('La configuración');
if (!TikTok::activa()) {
    mal('El bloque `tiktok` de config/integraciones.php no está completo.');
    nota('Falta: ' . implode(', ', TikTok::faltan()));
    exit(1);
}
if (TikTok::hayAppGlobal()) bien('App pública (ISV): App Key ' . TikTok::credencialesGlobales()['app_key']);
else ojo('Sin app pública: solo pueden conectar las empresas con su propia app «Seller in-house».');
$dom = trim((string)(TikTok::config()['dominio'] ?? ''));
if ($dom === '') ojo('`dominio` vacío: la URL de retorno que se muestra sale de la dirección del navegador.');
elseif (strncasecmp($dom, 'https://', 8) !== 0) ojo('`dominio` no es https: TikTok no manda avisos sin https.');
else {
    nota('Redirect URL de la app: ' . rtrim($dom, '/') . '/tiktok/retorno');
    nota('Webhook URL de la app:  ' . rtrim($dom, '/') . '/tiktok/aviso');
}
if (!function_exists('openssl_encrypt')) { mal('PHP no tiene openssl: no se pueden cifrar las llaves.'); exit(1); }

$nombrePrincipal = (string)($cfg['bd']['principal'] ?? '');
try { $principal = Conexion::de($nombrePrincipal); }
catch (\Throwable $e) { mal('No se pudo abrir la base principal: ' . $e->getMessage()); exit(1); }

$tiendas = (new RutaTikTokRepo($principal))->todas();
if ($solo !== '') $tiendas = array_values(array_filter($tiendas, function ($f) use ($solo) { return $f['empresa_db'] === $solo; }));

titulo('Tiendas conectadas: ' . count($tiendas));
if (!$tiendas) { nota('Ninguna empresa ha conectado su tienda todavía.'); exit(0); }

$fallos = 0;
foreach ($tiendas as $f) {
    $base = $f['empresa_db'];
    echo "\n  " . c($base, 'g') . ' · ' . ($f['nombre'] ?: $f['shop_id']) . "\n";
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

        $srv = new TikTokInventario($db);
        $c = $srv->cuenta();
        if (!$c || (string)$c['shop_id'] !== (string)$f['shop_id']) {
            ojo('el índice apunta aquí pero la empresa tiene otra tienda (o ninguna). Se ignora.');
            continue;
        }
        if ($crudo) $srv->api()->espiar(function ($q, $t) { nota($q . ' ' . $t); });
        if (!$srv->conectada()) { ojo('tienda ' . $c['estado'] . ($c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '')); continue; }

        // La llave se renueva aunque el módulo esté apagado: si caduca, hay que reconectar.
        if ($srv->token() === '') {
            $c = $srv->repo()->cuenta();
            mal('la llave ya no vale: ' . ($c['ultimo_error'] ?? 'hay que reconectar'));
            $fallos++;
            continue;
        }
        $c = $srv->repo()->cuenta();
        if (!$srv->encendido()) { ojo('TikTok Shop o Inventario está apagado en Ajustes → Módulos: no se sincroniza.'); continue; }
        if ($srv->almacen() === '') ojo('sin almacén elegido: no se mandan existencias hasta elegirlo en Ajustes.');

        bien('conectada · ' . ($srv->credencialesPropias() ? 'app propia' : 'app de LibertyFin')
             . ' · llave hasta ' . ($c['expira_en'] ?: '—')
             . ' · último aviso ' . ($c['aviso_en'] ?: 'ninguno')
             . ' · almacén ' . ($c['almacen_nombre'] ?: '—')
             . ' · mandar existencias ' . ((int)$c['enviar_existencias'] ? 'ENCENDIDO' : 'apagado'));
        if ($c['refresh_expira_en'] && strtotime((string)$c['refresh_expira_en']) < strtotime('+7 days')) {
            ojo('la renovación vence el ' . $c['refresh_expira_en'] . ': hay que volver a conectar antes.');
        }
        if ($revisar) { $r = $srv->repo()->resumen(); nota($r['total'] . ' SKU, ' . $r['vinculadas'] . ' vinculados, ' . $r['pendientes'] . ' por mandar'); continue; }

        $r = $srv->sincronizar(200);
        bien($r['revisadas'] . ' órdenes revisadas · ' . $r['aplicadas'] . ' descontadas · ' . $r['devueltas'] . ' devueltas · '
             . $r['enviadas'] . ' existencias mandadas' . ($r['fallas'] ? ' · ' . c($r['fallas'] . ' con error', 'r') : '')
             . ($r['quedan'] ? ' · quedan más para la siguiente vuelta' : ''));
        if ($r['fallas']) $fallos++;
    } catch (\Throwable $e) {
        mal($e->getMessage());
        error_log('[LibertyFin] tiktok-sincronizar ' . $base . ': ' . $e->getMessage());
        $fallos++;
    }
}
echo "\n";
exit($fallos ? 2 : 0);
