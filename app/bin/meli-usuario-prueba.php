<?php
/**
 * Mercado Libre: crea un USUARIO DE PRUEBA, con la llave de una cuenta
 * que ya esté conectada en LibertyFin.
 *
 *   php app/bin/meli-usuario-prueba.php --empresa=BASE
 *   php app/bin/meli-usuario-prueba.php --empresa=BASE --sitio=MLM
 *
 * Mercado Libre no tiene pantalla para esto: solo se hacen por la API, y
 * la API pide la llave de una cuenta real. Se usa la de la empresa.
 *
 * LO QUE HAY QUE SABER DE LOS USUARIOS DE PRUEBA
 *
 *  · Solo compran y venden con OTROS usuarios de prueba. Para probar una
 *    venta se hacen dos: uno vende (se conecta a LibertyFin) y otro compra.
 *  · Máximo 10 por cuenta, y se borran solos tras 60 días sin uso.
 *  · El usuario y la contraseña se enseñan UNA vez, aquí. Guárdalos.
 *  · Al iniciar sesión, Mercado Libre puede pedir un código por correo:
 *    son los últimos 6 dígitos del id del usuario.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    
$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\MeliInventario;
use LibertyFin\Servicio\MercadoLibre;

function arg(array $argv, string $n, string $d = ''): string {
    foreach ($argv as $a) {
        if (strncmp($a, '--' . $n . '=', strlen($n) + 3) === 0) return trim(substr($a, strlen($n) + 3));
    }
    return $d;
}

$base  = arg($argv, 'empresa');
$sitio = strtoupper(arg($argv, 'sitio', 'MLM'));   // MLM México · MLA Argentina · MLB Brasil · MCO · MLC · MPE · MLU
if ($base === '' || !preg_match('/^[A-Za-z0-9_]+$/', $base)) {
    fwrite(STDERR, "Uso: php app/bin/meli-usuario-prueba.php --empresa=BASE_DE_LA_EMPRESA [--sitio=MLM]\n");
    exit(1);
}

$archivoCfg = getenv('LF_CONFIG') ?: $raiz . '/config/config.php';
$cfg = require $archivoCfg;
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);

if (!MercadoLibre::activa()) { fwrite(STDERR, "Falta configurar el bloque mercadolibre de config/integraciones.php\n"); exit(1); }

try {
    $srv = new MeliInventario(Conexion::de($base));
    if (!$srv->conectada()) { fwrite(STDERR, "La empresa $base no tiene una cuenta de Mercado Libre conectada.\n"); exit(1); }
    $token = $srv->token();
    if ($token === '') { fwrite(STDERR, "No se pudo obtener una llave vigente: " . $srv->api()->error() . "\n"); exit(1); }

    $u = $srv->api()->crearUsuarioPrueba($token, $sitio);
    if (!is_array($u) || empty($u['nickname'])) {
        fwrite(STDERR, "Mercado Libre no lo creó: " . $srv->api()->error() . "\n");
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$id = (string)($u['id'] ?? '');
echo "\nUsuario de prueba creado ($sitio). Guárdalo: no se vuelve a enseñar.\n\n";
echo "  Usuario:     " . $u['nickname'] . "\n";
echo "  Contraseña:  " . ($u['password'] ?? '') . "\n";
echo "  Id:          " . $id . "\n";
if (strlen($id) >= 6) echo "  Código de verificación (si lo pide): " . substr($id, -6) . "\n";
echo "\n";
