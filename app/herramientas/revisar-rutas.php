<?php
/**
 * Comprueba que cada ruta declare quién puede entrar.
 *
 * Una ruta sin permiso en la tabla del portero NO queda cerrada: queda
 * ABIERTA a cualquiera que haya iniciado sesión. Es el fallo que no se
 * nota, porque la pantalla funciona perfecto.
 *
 * Sale con código 1 si encuentra una, para que el pipeline (GitHub
 * Actions) no deje pasar el cambio. Antes solo lo imprimía y siempre
 * terminaba "bien": así sobrevivieron cuatro rutas de Facturación
 * abiertas a cualquier cajero.
 *
 * Las rutas de la API no van aquí: llevan su propio permiso en
 * src/Api/Puerta.php (RUTAS) y lo revisa tests/Unidad/Api/PuertaTest.php.
 */
$i = file_get_contents(__DIR__ . "/../public/index.php");
preg_match_all('/\x27(\/[^\x27]*)\x27\s*=>/', $i, $p);
preg_match_all('/\$r->(?:get|post|delete)\s*\(\x27([^\x27]+)\x27/', $i, $r);

// Estas no llevan permiso A PROPÓSITO. Cada una dice por qué; agregar
// una aquí es una decisión, no un atajo para que pase la revisión.
$aproposito = [
    // Públicas: se usan sin sesión.
    '/login', '/login/confirmar', '/salir', '/registro', '/ayuda-acceso',
    // Los avisos de Paga de Todo: llama un servidor del proveedor, sin
    // sesión. Los protege el secreto de la URL o la IP de origen, que
    // revisa PagosEntrantesControlador.
    '/pagadetodo/consulta-referencia', '/pagadetodo/pago-referencia',
    '/pagadetodo/consulta-clabe', '/pagadetodo/pago-clabe',
    '/pagadetodo/pago-liga', '/pagadetodo/cancela-pago',
    '/pagadetodo/{k}/consulta-referencia', '/pagadetodo/{k}/pago-referencia',
    '/pagadetodo/{k}/consulta-clabe', '/pagadetodo/{k}/pago-clabe',
    '/pagadetodo/{k}/pago-liga', '/pagadetodo/{k}/cancela-pago',
    '/Service/ConsultaClabe', '/Service/PagoClabe', '/Service/ConsultaReferencia',
    '/Service/PagoReferencia', '/Service/PagoLinea', '/Service/EntregarPagoLiga',
    '/Service/CancelaPago',
    // El panel bifurca según el nivel del rol.
    '/panel',
    // Sobre SÍ MISMO: cualquiera con sesión. Pedir permiso aquí dejaría a
    // un cajero sin poder cambiar su contraseña o su foto.
    '/cuenta', '/cuenta/clave', '/cuenta/foto', '/cuenta/foto-publica', '/cuenta/apariencia',
    '/cuenta/fiscales', '/cuenta/comercio', '/cuenta/documento', '/guia', '/guia/vista',
    // ¿Sigue viva mi sesión? y los avisos de escritorio de cada quien.
    '/sesion/pulso', '/push/llave', '/push/suscribir', '/push/quitar', '/push/probar', '/push/pendiente',
];

$sin = array_values(array_diff(array_unique($r[1]), $p[1], $aproposito));
sort($sin);

if ($sin) {
    echo "  RUTAS SIN PERMISO DECLARADO (quedan abiertas a cualquiera con sesión):\n";
    foreach ($sin as $x) echo "    " . $x . "\n";
    echo "\n  " . count($sin) . " por declarar en la tabla de permisos de public/index.php\n";
    exit(1);
}
printf("  rutas: las %d llevan permiso, o están en la lista de excepciones\n",
    count(array_unique($r[1])));
