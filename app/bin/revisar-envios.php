<?php
/**
 * Revisa la integración con Envia.com, de principio a fin.
 *
 *   php app/bin/revisar-envios.php                      revisa config y catálogo
 *   php app/bin/revisar-envios.php --cp=44100           qué es ese código postal
 *   php app/bin/revisar-envios.php --cotizar            cotiza una ruta de prueba
 *   php app/bin/revisar-envios.php --cotizar --de=64000 --a=44100 --peso=2
 *   php app/bin/revisar-envios.php --crudo              enseña el JSON que va y viene
 *
 * POR QUÉ EXISTE
 *
 * Esta API tiene dos trampas, y las dos se ven igual desde afuera —«no
 * salen envíos»— aunque la causa sea distinta:
 *
 *   1. Contesta HTTP 200 con el error ADENTRO, en `"meta":"error"`.
 *      Quien revise solo el código HTTP cree que todo salió bien.
 *
 *   2. El estado va en una clave de dos letras que es de Envia y que no
 *      coincide con la del SAT ni con la ISO: la Ciudad de México es CX.
 *      Con el nombre completo no da error: devuelve una lista VACÍA.
 *
 * Esto enseña las dos cosas por separado, para no andar adivinando.
 *
 * EL TOKEN NO SE IMPRIME. Solo se dice cuántos caracteres mide y cómo
 * empieza, lo suficiente para saber si está puesto el de Sandbox o el de
 * producción. Así se puede pegar esta salida en un chat sin regalar la
 * llave de la cuenta.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Servicio\Envia;
use LibertyFin\Servicio\Envios;
use LibertyFin\Servicio\Integraciones;

$color = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c(string $t, string $x): string {
    global $color; if (!$color) return $t;
    $m = ['v' => '32', 'r' => '31', 'a' => '33', 'g' => '1', 'd' => '90', 'c' => '36'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m";
}
function titulo(string $t): void { echo "\n" . c($t, 'g') . "\n"; }
function bien(string $t): void   { echo '  ' . c('ok  ', 'v') . $t . "\n"; }
function mal(string $t): void    { echo '  ' . c('MAL ', 'r') . $t . "\n"; }
function ojo(string $t): void    { echo '  ' . c('ojo ', 'a') . $t . "\n"; }
function nota(string $t): void   { echo '      ' . c($t, 'd') . "\n"; }
function bandera(array $argv, string $n): bool { return in_array('--' . $n, $argv, true); }
function arg(array $argv, string $n, string $d = ''): string {
    foreach ($argv as $a) {
        if (strncmp($a, '--' . $n . '=', strlen($n) + 3) === 0) return trim(substr($a, strlen($n) + 3));
    }
    return $d;
}

echo c('REVISIÓN DE ENVÍOS · Envia.com · LibertyFin', 'g') . "\n";

Integraciones::cargar($raiz);
$arch = Integraciones::archivo();

// ── 1 · El archivo ───────────────────────────────────────────────
titulo('Configuración');
if (!$arch['existe']) {
    mal('No existe ' . $arch['ruta']);
    nota('Copia config/integraciones.php.ejemplo a config/integraciones.php');
    exit(1);
}
bien('Archivo: ' . $arch['ruta'] . ' (' . $arch['bloques'] . ' bloques)');

$cfg = Integraciones::de('envia');
if (!$cfg) {
    if (!Integraciones::lista('envia')) {
        mal('Falta el `token` en el bloque `envia`.');
        nota('Sácalo del panel de Envia.com, en Developers.');
    } else {
        mal('El bloque `envia` existe pero está con `activo` en false.');
    }
    nota('Así queda el bloque:');
    nota("  'envia' => [");
    nota("      'activo'       => true,");
    nota("      'sandbox'      => true,        // false cuando ya vayas a mandar de verdad");
    nota("      'token'        => 'TU_TOKEN_DE_PRODUCCION',");
    nota("      'token_prueba' => 'TU_TOKEN_DE_SANDBOX',");
    nota("  ],");
    // Aun sin token se puede probar el catálogo de CPs: es público.
    $cfg = [];
}

$envia = new Envia($cfg);
if ($cfg) {
    $modo = $envia->sandbox() ? 'Sandbox (pruebas)' : 'PRODUCCIÓN (se cobra de verdad)';
    bien('Modo: ' . c($modo, $envia->sandbox() ? 'a' : 'r'));
    bien('Dirección: ' . $envia->base());

    $t = $envia->token();
    if ($t === '') {
        mal('El token del modo en curso está vacío.');
    } else {
        bien('Token: ' . strlen($t) . ' caracteres, empieza en «' . substr($t, 0, 4) . '…»');
        if ($envia->sandbox() && trim((string)($cfg['token_prueba'] ?? '')) === '') {
            ojo('Estás en Sandbox pero no hay `token_prueba`: se está mandando el de producción.');
            nota('Son llaves distintas. La de producción NUNCA autentica en Sandbox, y el');
            nota('error que devuelve no dice que ese sea el problema.');
        }
    }
}

// ── 2 · El catálogo de códigos postales ──────────────────────────
// Va primero a propósito: es público y no gasta credenciales, así que
// sirve para saber si la salida a internet funciona antes de culpar al
// token.
titulo('Catálogo de códigos postales (público, sin token)');

// El espía se enciende ANTES de preguntar: cuando el catálogo dice que
// un CP no existe, lo único que sirve es ver con qué contestó. Es lo
// que distingue «Envia no lo tiene» de «Envia cambió el formato».
if (bandera($argv, 'crudo')) {
    $envia->espiar(function ($quien, $que) {
        echo '  ' . c($quien, $quien === '>>>' ? 'c' : 'd') . ' ' . $que . "\n";
    });
}
$cps = array_filter([arg($argv, 'cp'), arg($argv, 'de', '64000'), arg($argv, 'a', '44100')]);
$resuelto = [];
foreach (array_unique($cps) as $cp) {
    $g = $envia->cp($cp);
    if ($g) {
        $resuelto[$cp] = $g;
        bien($cp . ' → ' . $g['ciudad'] . ', ' . c($g['estado'], 'c') . ' (' . $g['pais'] . ')');
    } else {
        mal($cp . ' → ' . $envia->error());
        if (!bandera($argv, 'crudo')) {
            nota('Para ver con qué contestó Envia:');
            nota('  php app/bin/revisar-envios.php --cp=' . $cp . ' --crudo');
        }
        ojo('Que el catálogo no lo tenga NO te impide usarlo: en la pantalla');
        nota('escribe tú la clave de dos letras del estado y guarda igual.');
    }
}
if ($resuelto) {
    nota('Fíjate que el estado sale en DOS LETRAS. Eso es lo que hay que mandar.');
    nota('Escribir «Jalisco» en vez de «JA» no da error: deja la cotización vacía.');
}

// Lo que de verdad importa saber: ¿falla UNO o fallan TODOS? Si los dos
// de referencia —Monterrey y Guadalajara— contestan bien, el catálogo
// funciona y lo que falta es ese CP. Si fallan los dos, el problema es
// de conexión o de credenciales, no del código postal.
$refs = array_intersect_key($resuelto, array_flip(['64000', '44100']));
if (count($cps) > 1) {
    titulo('Veredicto del catálogo');
    if (count($refs) === 2) {
        bien('El catálogo SÍ funciona: contestó bien por Monterrey y Guadalajara.');
        nota('Entonces, si un CP tuyo sale rechazado, es que ese código no está en');
        nota('su base. Escribe la clave del estado a mano en la pantalla y guarda:');
        nota('el sistema te deja. Para ver las claves válidas:');
        nota('  php app/bin/revisar-envios.php --estados');
    } elseif (!$refs) {
        mal('Fallaron hasta los CP de referencia (64000 Monterrey, 44100 Guadalajara).');
        nota('Esos dos existen con seguridad, así que el problema NO son tus códigos');
        nota('postales: es que este servidor no está llegando al catálogo, o que');
        nota('Envia está contestando distinto. Para ver su respuesta tal cual:');
        nota('  php app/bin/revisar-envios.php --cp=64000 --crudo');
    } else {
        ojo('Unos sí y otros no: el catálogo responde pero está incompleto.');
    }
}

// ── 2b · La lista BUENA de estados, la de Envia ──────────────────
// Cuando el catálogo de CPs no reconoce un código, hay que escribir la
// clave del estado a mano. Ésta es la lista con la que hay que hacerlo:
// no la del SAT, no la de la ISO, la de ellos.
if (bandera($argv, 'estados')) {
    titulo('Claves de estado que acepta Envia (su propia lista)');
    $est = $envia->estados(arg($argv, 'pais', 'MX'));
    if (!$est) {
        mal($envia->error() ?: 'No devolvió nada.');
        nota('Esta lista sí necesita token. Si falla, revisa el de arriba.');
        exit(1);
    }
    bien(count($est) . ' estados:');
    $i = 0;
    foreach ($est as $clave => $nombre) {
        printf("      %-4s %-28s%s", c($clave, 'c'), mb_substr($nombre, 0, 28),
               (++$i % 2 === 0) ? "\n" : '');
    }
    if ($i % 2) echo "\n";
    exit(0);
}

// ── 2b-bis · Probar el camino del aviso SIN depender de Envia ───
//
// En Sandbox los paquetes no se mueven, así que no hay avisos de
// verdad que esperar. Y el endpoint de prueba de Envia
// (/ship/webhooktest/) truena por su lado.
//
// Esto arma un aviso con la MISMA firma que usa Envia y se lo manda a
// tu propio servidor. No prueba a Envia —eso no se puede desde aquí—
// pero sí prueba todo lo tuyo: que la ruta conteste, que la firma se
// verifique, que encuentre la empresa de esa guía y que el estado
// cambie en la pantalla. Que es lo que de verdad podría estar roto.
if (arg($argv, 'simular-aviso') !== '') {
    titulo('Aviso de prueba, firmado como lo firma Envia');

    $guia   = arg($argv, 'simular-aviso');
    $estado = arg($argv, 'estado', 'In transit');
    $url    = arg($argv, 'url', 'https://libertyfin.com.mx/envia/aviso');
    $secreto = trim((string)($cfg['secreto_webhook'] ?? ''));

    if ($secreto === '') {
        ojo('No hay `secreto_webhook` en config/integraciones.php.');
        nota('El aviso va sin firmar. Tu servidor lo va a aceptar igual —así está');
        nota('hecho a propósito— pero no se prueba la verificación.');
    }

    $cuerpo = json_encode(['type' => 'tracking.simple', 'created_at' => date('c'),
        'data' => ['tracking_number' => $guia, 'carrier_name' => 'prueba',
                   'status' => strtolower($estado), 'status_description' => $estado,
                   'location' => 'Prueba desde la terminal']], JSON_UNESCAPED_UNICODE);

    $ts   = (string)(time() * 1000);
    $cabs = ['Content-Type: application/json',
             'X-Webhook-Event: tracking.simple',
             'X-Webhook-Timestamp: ' . $ts,
             'X-Webhook-Id: prueba_' . bin2hex(random_bytes(4))];
    if ($secreto !== '') {
        $cabs[] = 'X-Webhook-Signature: v1='
                . hash_hmac('sha256', $ts . '.tracking.simple.' . $cuerpo, $secreto);
    }

    bien('Mandando a ' . $url);
    nota('guía ' . $guia . ' · estado «' . $estado . '»');

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cabs, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "\n";
    if ($res === false) { mal('No se pudo llegar: ' . $err); exit(1); }
    if ($http === 200) {
        bien('Tu servidor contestó HTTP 200: ' . trim((string)$res));
        nota('Ahora abre esa guía en Envíos y mira si cambió el estado.');
        nato:
        nota('Si NO cambió, el motivo está en el log de errores de tu servidor,');
        nota('con la marca [LibertyFin].');
    } else {
        mal('Tu servidor contestó HTTP ' . $http . ': ' . mb_substr(trim((string)$res), 0, 200));
        nota('Con un 404, falta subir el index.php nuevo (trae la ruta /envia/aviso).');
    }
    exit(0);
}

// ── 2c · Avisos automáticos (webhooks) ──────────────────────────
// Sin esto, el estado de un envío solo cambia cuando alguien aprieta
// «Actualizar estado». Con esto, la paquetería avisa sola.
if (bandera($argv, 'avisos') || arg($argv, 'registrar-aviso') !== '') {
    titulo('Avisos automáticos de la paquetería');

    if (bandera($argv, 'crudo')) {
        $envia->espiar(function ($quien, $que) {
            echo '  ' . c($quien, $quien === '>>>' ? 'c' : 'd') . ' ' . $que . "\n";
        });
    }

    $nueva = arg($argv, 'registrar-aviso');
    if ($nueva !== '') {
        $r = $envia->registrarAviso($nueva, (int)(arg($argv, 'tipo', '3') ?: 3));
        if (!$r) { mal($envia->error()); exit(1); }
        // El id puede venir con varios nombres según la versión; si no
        // se encuentra, la lista de abajo lo enseña de todas formas.
        $id = '';
        foreach (['id', 'webhook_id', 'webhookId'] as $k) {
            if (!empty($r[$k])) { $id = (string)$r[$k]; break; }
        }
        bien('Registrado' . ($id !== '' ? ' con el id ' . $id : '') . ' → ' . $nueva);
        if ($id === '') nota('Su id sale en la lista de abajo.');
    }

    $tipos = $envia->tiposDeAviso();
    if ($tipos) {
        bien('Clases de aviso disponibles:');
        foreach ($tipos as $id => $nombre) {
            $marca = ((int)$id === 3) ? c('  ← el de rastreo, firmado', 'v') : '';
            printf("      %-4s %s%s\n", $id, $nombre, $marca);
        }
    } else {
        ojo('No se pudo leer la lista de clases: ' . $envia->error());
    }

    $puestos = $envia->avisos();
    echo "\n";
    if ($puestos) {
        bien(count($puestos) . ' aviso(s) registrado(s):');
        foreach ($puestos as $a) {
            printf("      %-6s %-52s %s\n", $a['id'] ?? '?',
                mb_substr((string)($a['url'] ?? ''), 0, 52),
                empty($a['active']) ? c('apagado', 'd') : c('activo', 'v'));
        }
    } else {
        ojo('No hay ningún aviso registrado todavía.');
        nota('Para registrar el tuyo (tiene que ser https y público):');
        nota('  php app/bin/revisar-envios.php \\');
        nota('      --registrar-aviso=https://libertyfin.com.mx/envia/aviso');
        nota('');
        nota('Y pon `secreto_webhook` en el bloque `envia` de');
        nota('config/integraciones.php con el secreto que te dé Envia:');
        nota('sin él los avisos se aceptan sin poder verificar quién los manda.');
    }
    exit(0);
}

if (arg($argv, 'cp') !== '' && !bandera($argv, 'cotizar')) exit(0);

// ── 3 · Cotizar de verdad ────────────────────────────────────────
if (!bandera($argv, 'cotizar')) {
    titulo('Siguiente paso');
    nota('Para probar una cotización de verdad:');
    nota('  php app/bin/revisar-envios.php --cotizar');
    nota('  php app/bin/revisar-envios.php --cotizar --de=64000 --a=44100 --peso=2 --crudo');
    exit(0);
}

if (!$envia->listo()) {
    titulo('Cotización');
    mal('Sin token no se puede cotizar. Ponlo y vuelve a correr esto.');
    exit(1);
}

$de   = arg($argv, 'de', '64000');
$a    = arg($argv, 'a',  '44100');
$peso = (float)(arg($argv, 'peso', '1') ?: 1);

// Si el catálogo no contestó, no se abandona: se cotiza con dos plazas
// conocidas. Un servidor puede tener bloqueado geocodes.envia.com y
// abierto api.envia.com, y justo ahí es donde esta revisión tiene que
// seguir diciendo algo útil sobre el token.
foreach ([$de => ['Monterrey', 'NL'], $a => ['Guadalajara', 'JA']] as $cp => $par) {
    if (!empty($resuelto[$cp])) continue;
    $resuelto[$cp] = ['cp' => $cp, 'ciudad' => $par[0], 'estado' => $par[1], 'pais' => 'MX'];
    ojo('El catálogo no contestó para ' . $cp . '; se cotiza como '
        . $par[0] . ', ' . $par[1] . ' solo para esta prueba.');
}

$dir = function (array $g, string $nombre): array {
    return ['nombre' => $nombre, 'telefono' => '8180000000', 'calle' => 'Calle de prueba',
            'numero' => '100', 'colonia' => 'Centro', 'ciudad' => $g['ciudad'],
            'estado' => $g['estado'], 'cp' => $g['cp'], 'pais' => 'MX'];
};

titulo('Cotización de prueba · ' . $de . ' → ' . $a . ' · ' . $peso . ' kg');
$op = $envia->cotizar(
    $dir($resuelto[$de], 'Remitente de prueba'),
    $dir($resuelto[$a],  'Destinatario de prueba'),
    [['tipo' => 'box', 'contenido' => 'Prueba', 'cantidad' => 1, 'valor' => 100,
      'peso' => $peso, 'largo' => 30, 'ancho' => 20, 'alto' => 15]]
);

if ($op === null) {
    mal($envia->error());
    nota('Si dice algo de autenticación, es el token: revisa que el de Sandbox');
    nota('esté en `token_prueba` y el de producción en `token`.');
    exit(1);
}
if (!$op) {
    mal('Ninguna paquetería cotizó esta ruta, pero la API no devolvió error.');
    nota('Casi siempre es la dirección. Revisa que los dos CP existan arriba y');
    nota('que el estado haya salido en dos letras.');
    exit(1);
}

bien(count($op) . ' opciones:');
foreach ($op as $o) {
    printf("      %-16s %-26s %10s  %s\n",
        Envios::carrier($o['carrier']),
        mb_substr($o['descripcion'], 0, 26),
        '$' . number_format($o['precio'], 2) . ' ' . $o['moneda'],
        $o['dias'] !== '' ? c($o['dias'], 'd') : '');
}

titulo('Listo');
nota('La integración responde. Lo que falta es capturar el domicilio de salida');
nota('de cada sucursal en el sistema: Envíos → De dónde sale.');