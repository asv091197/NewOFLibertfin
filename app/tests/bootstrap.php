<?php
/**
 * Arranque de las pruebas.
 *
 * Carga el mismo autoload que la aplicación (src/autoload.php) y el de
 * Composer, que solo trae PHPUnit y las clases de tests/. La aplicación
 * no depende de Composer en producción: el servidor recibe los archivos
 * por FTP y no corre `composer install`.
 */
declare(strict_types=1);

define('LF_RAIZ', dirname(__DIR__));

require LF_RAIZ . '/src/autoload.php';
require LF_RAIZ . '/vendor/autoload.php';

// La misma zona que la aplicación: los folios y los cortes dependen de ella.
date_default_timezone_set('America/Mexico_City');
