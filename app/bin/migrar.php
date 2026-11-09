<?php
/**
 * Pone al día el esquema de las bases, y dice por qué no lo estaba.
 *
 *   php app/bin/migrar.php                 enseña en qué versión va cada empresa
 *   php app/bin/migrar.php --aplicar       las pone al día
 *   php app/bin/migrar.php --aplicar --empresa=3
 *   php app/bin/migrar.php --base=juanc141_algo --aplicar
 *
 * POR QUÉ EXISTE
 *
 * Las migraciones corren solas al entrar y en cada petición si el código
 * avanzó. Cuando eso NO pasa —porque `Migraciones.php` no se subió, o se
 * subió el viejo, o la base no deja crear tablas— el síntoma no dice
 * nada útil: la pantalla escribe «Unknown column 'es_perecedero'» una y
 * otra vez, que manda a buscar el problema a la tabla equivocada. El
 * problema no está en la columna: está en que la migración no corrió.
 *
 * Esto contesta las tres preguntas en orden: en qué versión está la
 * base, en qué versión está el CÓDIGO, y si se puede escribir en ella.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\Migraciones;

$color = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c(string $t, string $x): string {
    global $color; if (!$color) return $t;
    $m = ['v'=>'32','r'=>'31','a'=>'33','g'=>'1','d'=>'90'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m";
}
function titulo(string $t): void { echo "\n" . c($t, 'g') . "\n"; }
function bien(string $t): void   { echo '  ' . c('ok  ', 'v') . $t . "\n"; }
function mal(string $t): void    { echo '  ' . c('MAL ', 'r') . $t . "\n"; }
function ojo(string $t): void    { echo '  ' . c('ojo ', 'a') . $t . "\n"; }
function nota(string $t): void   { echo '      ' . c($t, 'd') . "\n"; }
function morir(string $t): void  { echo "\n" . c('No se migró nada.', 'r') . ' ' . $t . "\n\n"; exit(1); }
function arg(array $argv, string $n, string $d = ''): string {
    foreach ($argv as $a) if (strncmp($a, '--'.$n.'=', strlen($n)+3) === 0) return trim(substr($a, strlen($n)+3));
    return $d;
}
function bandera(array $argv, string $n): bool { return in_array('--'.$n, $argv, true); }

echo c('ESQUEMA DE LAS BASES · LibertyFin', 'g') . "\n";

$archivoCfg = getenv('LF_CONFIG') ?: $raiz . '/config/config.php';
if (!is_readable($archivoCfg)) morir('No se encuentra ' . $archivoCfg);
$cfg = require $archivoCfg;
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);

// ── Lo primero: ¿qué versión trae el código que está en el servidor? ──
//
// Si esto dice 25 y esperabas 26, el problema no es la base: es que
// `src/Servicio/Migraciones.php` no se subió, o se subió el anterior.
titulo('El código');
bien('Migraciones.php dice versión ' . c((string)Migraciones::VERSION, 'g'));
nota('archivo: ' . realpath($raiz . '/src/Servicio/Migraciones.php'));
nota('subido:  ' . date('d/m/Y H:i', (int)@filemtime($raiz . '/src/Servicio/Migraciones.php')));
$ultima = max(array_keys(Migraciones::DESCRIPCIONES));
if ($ultima < Migraciones::VERSION) {
    ojo('La versión ' . Migraciones::VERSION . ' no tiene descripción: puede faltar código.');
}
nota('última: ' . (Migraciones::DESCRIPCIONES[Migraciones::VERSION] ?? '—'));

$aplicar = bandera($argv, 'aplicar');
$unaBase = arg($argv, 'base');
$unaEmp  = (int)arg($argv, 'empresa', '0');

// ── Qué bases revisar ──
$bases = [];
if ($unaBase !== '') {
    $bases[] = ['nombre' => 'la indicada', 'base' => $unaBase, 'id' => 0];
} else {
    $nombrePrincipal = (string)($cfg['bd']['principal'] ?? '');
    if ($nombrePrincipal === '') morir('config.php no dice cuál es la base principal (bd.principal)');
    try {
        $principal = Conexion::de($nombrePrincipal);
        $sql = "SELECT id, nombre_empresa, nombre_base_datos FROM empresas"
             . ($unaEmp ? " WHERE id = " . $unaEmp : "") . " ORDER BY id";
        foreach ($principal->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            $bases[] = ['nombre' => $e['nombre_empresa'], 'base' => $e['nombre_base_datos'], 'id' => (int)$e['id']];
        }
    } catch (\Throwable $e) {
        morir('No se pudo leer la lista de empresas: ' . $e->getMessage());
    }
}
if (!$bases) morir('No hay ninguna empresa que revisar.');

titulo(($aplicar ? 'Poniendo al día ' : 'Revisando ') . count($bases)
       . (count($bases) === 1 ? ' base' : ' bases'));

$atrasadas = 0; $arregladas = 0; $conProblema = 0;

foreach ($bases as $b) {
    $rot = '#' . $b['id'] . ' ' . $b['nombre'] . ' · ' . $b['base'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$b['base'])) {
        mal($rot . ' — nombre de base no válido'); $conProblema++; continue;
    }
    try {
        $db = Conexion::de($b['base']);
        $v  = Migraciones::versionDe($db);
    } catch (\Throwable $e) {
        mal($rot . ' — no se pudo abrir: ' . $e->getMessage());
        nota('Revisa que el usuario ' . ($cfg['bd']['usuario'] ?? '?') . ' tenga permiso sobre esa base.');
        $conProblema++; continue;
    }

    if ($v >= Migraciones::VERSION) { bien($rot . ' — al día (v' . $v . ')'); continue; }

    $atrasadas++;
    ojo($rot . ' — va en v' . $v . ', le faltan ' . (Migraciones::VERSION - $v));
    for ($i = $v + 1; $i <= Migraciones::VERSION; $i++) {
        nota('v' . $i . ' · ' . (Migraciones::DESCRIPCIONES[$i] ?? '—'));
    }

    if (!$aplicar) continue;

    // ¿Se puede crear una tabla aquí? Es la causa silenciosa más común
    // en hosting compartido: el usuario puede leer y escribir renglones
    // pero no cambiar el esquema, y la migración falla sin decirlo.
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS lf_prueba_permiso (x INT)");
        $db->exec("DROP TABLE IF EXISTS lf_prueba_permiso");
    } catch (\Throwable $e) {
        mal('   no se pueden crear tablas en ' . $b['base'] . ': ' . $e->getMessage());
        nota('En cPanel → Bases de datos MySQL, dale TODOS LOS PRIVILEGIOS al usuario.');
        $conProblema++; continue;
    }

    $hechas = Migraciones::aplicar($db);
    $ahora  = Migraciones::versionDe($db);
    if ($ahora >= Migraciones::VERSION) {
        bien('   quedó en v' . $ahora . ' (' . count($hechas) . ' aplicadas)');
        $arregladas++;
    } else {
        mal('   se quedó en v' . $ahora . ': la v' . ($ahora + 1) . ' no pasó');
        nota('El motivo está en el log de errores, como «[LibertyFin] migración ' . ($ahora + 1) . ': …».');
        $conProblema++;
    }
}

echo "\n";
if (!$aplicar) {
    if ($atrasadas) {
        ojo($atrasadas . ($atrasadas === 1 ? ' base atrasada' : ' bases atrasadas') . '. Para ponerlas al día:');
        nota('php app/bin/migrar.php --aplicar');
    } else {
        bien('Todas al día.');
    }
} else {
    if ($arregladas) bien($arregladas . ($arregladas === 1 ? ' base puesta al día' : ' bases puestas al día'));
    if ($conProblema) mal($conProblema . ' con problema (arriba está el motivo)');
    if (!$arregladas && !$conProblema) bien('No había nada que aplicar.');
    nota('Si alguien tenía la sesión abierta, que salga y vuelva a entrar.');
}
echo "\n";
exit($conProblema ? 1 : 0);