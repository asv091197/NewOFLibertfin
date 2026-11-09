<?php
// Autoloader mínimo, sin composer.
// LibertyFin\Datos\VentaRepo  ->  src/Datos/VentaRepo.php
spl_autoload_register(function ($clase) {
    $pre = 'LibertyFin\\';
    if (strncmp($clase, $pre, strlen($pre)) !== 0) return;
    $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($pre))) . '.php';
    if (is_readable($ruta)) require $ruta;
});
