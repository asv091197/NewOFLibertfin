<?php
/**
 * Comprueba que los reportes lean columnas que las consultas devuelven.
 *
 * Es el error que me costó dos vueltas: escribir `$x['monto']` cuando la
 * consulta devuelve `devengado`. No falla al compilar, no falla al
 * cargar la clase, y la pantalla sale llena de "Undefined array key"
 * encima del diseño.
 *
 * Lee los alias de cada método de ReporteRepo y los cruza contra lo que
 * Reportes.php usa de su resultado.
 */
$repo = file_get_contents("src/Datos/ReporteRepo.php");
$srv  = file_get_contents("src/Servicio/Reportes.php");

// Qué devuelve cada consulta
$devuelve = [];
preg_match_all('/public function (\w+)\(.*?\n    \}/s', $repo, $m, PREG_SET_ORDER);
foreach ($m as $x) {
    $nombre = $x[1];
    $cuerpo = $x[0];
    $cols = [];
    // `AS alias` y `SELECT tabla.columna` sin alias
    preg_match_all('/\bAS\s+([a-z_]\w*)/i', $cuerpo, $a);
    foreach ($a[1] as $c) $cols[strtolower($c)] = 1;
    preg_match_all('/(?:SELECT|,)\s+\w+\.(\w+)(?=\s*(?:,|\n))/i', $cuerpo, $b);
    foreach ($b[1] as $c) $cols[strtolower($c)] = 1;
    // Alias implícitos de subconsultas: `SUM(x) alias`
    preg_match_all('/\)\s+([a-z_]\w*)\s*(?:,|\n)/', $cuerpo, $c2);
    foreach ($c2[1] as $c) $cols[strtolower($c)] = 1;
    if ($cols) $devuelve[$nombre] = $cols;
}

// Qué usa cada caso de Reportes::armar()
$mal = 0;
// `case 'x':` y también el `default:`, que es donde vive el detalle y
// se quedaba sin revisar justo por ser el más largo.
preg_match_all('/(?:case \x27(\w+)\x27|(default))\s*:(?:\s*\/\/[^\n]*)?\s*\n\s*\$f = \$r->(\w+)\((.*?)(?=\n\s*case \x27|\n\s*default\s*:|\n\s*\}\s*\n\s*\})/s',
    $srv, $c, PREG_SET_ORDER);

foreach ($c as $x) {
    $tipo   = $x[1] !== '' ? $x[1] : 'detalle';
    $metodo = $x[3];
    $bloque = $x[4];
    if (!isset($devuelve[$metodo])) {
        printf("  %-13s ReporteRepo::%s no se pudo leer\n", $tipo, $metodo);
        continue;
    }
    preg_match_all('/\$x\[\x27(\w+)\x27\]/', $bloque, $u);
    preg_match_all('/\$t\[\x27(\w+)\x27\]/', $bloque, $u2);
    preg_match_all('/\[\x27(\w+)\x27,\x27?/', '', $z);  // ruido
    $usa = array_unique(array_merge($u[1], $u2[1]));
    $faltan = [];
    foreach ($usa as $col) {
        if (!isset($devuelve[$metodo][strtolower($col)])) $faltan[] = $col;
    }
    if ($faltan) {
        printf("  %-13s usa %s · %s devuelve: %s\n", $tipo,
            implode(", ", $faltan), $metodo, implode(", ", array_keys($devuelve[$metodo])));
        $mal += count($faltan);
    } else {
        printf("  %-13s ok (%d columnas)\n", $tipo, count($usa));
    }
}
echo $mal ? "\n  $mal columnas que no existen\n" : "\n  todos los reportes leen columnas que existen\n";
