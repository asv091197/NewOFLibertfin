<?php
/**
 * Revisa la sintaxis de cada archivo PHP del proyecto (php -l).
 *
 * Un error de sintaxis en un archivo que no se abre seguido —un
 * controlador de un módulo apagado, una plantilla de un reporte— no se
 * ve hasta que alguien entra justo ahí y le sale una página en blanco.
 * Esto los encuentra todos de una vez, antes de subir.
 *
 * Sale con código 1 si alguno falla.
 */
$raiz = dirname(__DIR__);
$php = PHP_BINARY;
$archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
$total = 0;
$malos = [];
foreach ($archivos as $f) {
    $ruta = $f->getPathname();
    if (substr($ruta, -4) !== '.php') continue;
    $rel = str_replace('\\', '/', substr($ruta, strlen($raiz) + 1));
    if (strncmp($rel, 'vendor/', 7) === 0) continue;
    $total++;
    $salida = [];
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);
    if ($codigo !== 0) $malos[$rel] = trim(implode("\n", $salida));
}
if ($malos) {
    echo "  ERRORES DE SINTAXIS:\n";
    foreach ($malos as $rel => $msg) echo "    $rel\n      $msg\n";
    exit(1);
}
printf("  sintaxis: los %d archivos PHP están bien\n", $total);
