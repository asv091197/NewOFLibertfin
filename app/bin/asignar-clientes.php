<?php
/**
 * Asigna cliente a ventas que quedaron como "Público general".
 *
 *   php app/bin/asignar-clientes.php --base=juanc141_jtklg              solo MUESTRA qué haría
 *   php app/bin/asignar-clientes.php --base=juanc141_jtklg --aplicar    lo hace
 *
 * POR QUÉ EXISTE
 *
 * Hasta hace poco, un nombre escrito en "Cliente" de la Caja que no se
 * elegía de la lista se perdía, y la venta salía como Público general.
 * Estas ventas ya están cobradas; solo falta enlazarlas a su cliente.
 *
 * QUÉ HACE
 *
 *   · Busca cada venta por su folio.
 *   · Busca el cliente por NOMBRE EXACTO. Si no existe, lo crea (solo con el
 *     nombre; lo demás se completa después en Clientes).
 *   · Enlaza la venta a ese cliente.
 *
 * LO QUE NO HACE
 *
 *   · No toca montos, pagos, comisiones ni fechas: solo `ventas.cliente_id`.
 *   · No pisa una venta que YA tiene cliente (la salta y avisa), a menos que
 *     pases --forzar.
 *   · Sin --aplicar no escribe nada, ni siquiera crea clientes.
 *   · Con --aplicar todo va en una transacción: o se hace todo o nada.
 *
 * PARA AGREGAR MÁS: añade renglones a $ASIGNAR de abajo (folio => nombre).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\ClienteRepo;
use LibertyFin\Datos\Conexion;

// ── Lo que hay que corregir: folio de la venta => nombre del cliente ──
$ASIGNAR = [
    '20261005175907' => 'DR. LEOPOLDO AVILA MEDRANO',
    '20261005175617' => 'TANIA ITZEL GARCIA RODRIGUEZ',
    '20261005175442' => 'RODRIGO MENENDEZ LORES',
    '20261005175319' => 'XIMENA ZARATE ORTIZ',
    '20261005174515' => 'N27 / RODRIGO ALVAREZ PAREJA',
];

// ── Argumentos ──
$aplicar = in_array('--aplicar', $argv, true);
$forzar  = in_array('--forzar', $argv, true);
$base    = '';
foreach ($argv as $a) {
    if (strpos($a, '--base=') === 0) $base = substr($a, 7);
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
    fwrite(STDERR, "Falta la base de la empresa:\n"
        . "  php app/bin/asignar-clientes.php --base=NOMBRE_DE_LA_BASE [--aplicar]\n");
    exit(1);
}

$color = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c($t, $x) { global $color; if (!$color) return $t;
    $m = ['v' => '32', 'r' => '31', 'a' => '33', 'g' => '1', 'd' => '90'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m"; }

// ── Conexión, igual que la aplicación ──
$cfg = is_readable($raiz . '/config/config.php') ? require $raiz . '/config/config.php' : null;
if (!$cfg) { fwrite(STDERR, "No se encontró config/config.php\n"); exit(1); }
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);

try {
    $db = Conexion::de($base);
} catch (\Throwable $e) {
    fwrite(STDERR, "No se pudo abrir la base {$base}: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n" . c($aplicar ? 'APLICANDO' : 'VISTA PREVIA (no se escribe nada)', 'g')
   . "  · base {$base}\n\n";

$clientes = new ClienteRepo($db);
$buscarVenta   = $db->prepare("SELECT id, cliente_id, total FROM ventas WHERE codigo_venta = ?");
$buscarCliente = $db->prepare("SELECT id, nombre FROM clientes WHERE nombre = ? ORDER BY id LIMIT 1");
$nombreDe      = $db->prepare("SELECT nombre FROM clientes WHERE id = ?");
$enlazar       = $db->prepare("UPDATE ventas SET cliente_id = ? WHERE id = ?");

$ok = $salto = $falla = $nuevos = 0;

if ($aplicar) $db->beginTransaction();
try {
    foreach ($ASIGNAR as $folio => $nombre) {
        $nombre = trim($nombre);
        $buscarVenta->execute([$folio]);
        $v = $buscarVenta->fetch();

        if (!$v) {
            echo '  ' . c('NO ESTÁ ', 'r') . "{$folio}  no existe esa venta en {$base}\n";
            $falla++;
            continue;
        }

        // Una venta que ya tiene cliente no se pisa sin pedirlo.
        if (!empty($v['cliente_id']) && !$forzar) {
            $nombreDe->execute([$v['cliente_id']]);
            echo '  ' . c('SALTADA ', 'a') . "{$folio}  ya tiene cliente: "
               . ($nombreDe->fetchColumn() ?: '#' . $v['cliente_id'])
               . c('  (usa --forzar para cambiarlo)', 'd') . "\n";
            $salto++;
            continue;
        }

        $buscarCliente->execute([$nombre]);
        $cli = $buscarCliente->fetch();

        if ($cli) {
            $cid = (int)$cli['id'];
            $queda = "usa el cliente que ya existe (#{$cid})";
        } elseif ($aplicar) {
            $cid = $clientes->crear(['nombre' => $nombre]);
            $queda = "cliente NUEVO creado (#{$cid})";
            $nuevos++;
        } else {
            $cid = 0;
            $queda = 'se CREARÍA como cliente nuevo';
            $nuevos++;
        }

        if ($aplicar) $enlazar->execute([$cid, (int)$v['id']]);

        echo '  ' . c('OK      ', 'v') . "{$folio}  Público general → " . c($nombre, 'g')
           . c("   ({$queda})", 'd') . "\n";
        $ok++;
    }
    if ($aplicar) $db->commit();
} catch (\Throwable $e) {
    if ($aplicar && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "\nSe canceló TODO, no se cambió nada: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n  {$ok} " . ($aplicar ? 'asignadas' : 'por asignar')
   . ", {$nuevos} cliente(s) " . ($aplicar ? 'creado(s)' : 'por crear')
   . ", {$salto} saltada(s), {$falla} sin encontrar.\n";
if (!$aplicar && $ok) {
    echo "\n  Si todo se ve bien, corre lo mismo con " . c('--aplicar', 'g') . ".\n";
}
echo "\n";
exit($falla ? 2 : 0);
