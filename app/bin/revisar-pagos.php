<?php
/**
 * Revisa la configuración de cobros de Paga de Todo.
 *
 *   php app/bin/revisar-pagos.php
 *   php app/bin/revisar-pagos.php --probar     también llama al proveedor
 *
 * POR QUÉ EXISTE
 *
 * Cuando un cobro no sale, el proveedor contesta un número. "22", "1",
 * "El ID de la escuela es obligatorio". Ninguno dice que el problema
 * está en que `url_referencia` apunta al servicio de domiciliación, ni
 * que el usuario de producción se está mandando con la contraseña de
 * Sandbox.
 *
 * Esto enseña exactamente qué se va a mandar y a dónde, antes de que
 * nadie intente cobrar.
 *
 * No escribe nada. No toca la base. Con --probar hace llamadas reales,
 * pero solo de consulta: no genera cobros ni mueve dinero.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\LigaPago;

$probar = in_array('--probar', $argv, true);

/**
 * El dominio de esta instalación.
 *
 * El script imprimía «TU-DOMINIO» y quien lee tenía que sustituirlo a
 * mano al pasar las direcciones al panel del proveedor. Es justo el
 * paso donde se cuela el error —se copia la URL sin el `?k=`, o con el
 * dominio de otra instalación— y ese error no se ve hasta que un
 * cliente paga y le revierten el dinero.
 *
 * Se deduce de la carpeta, que en este hosting es el propio dominio.
 * Se puede mandar a mano:  --dominio=https://midominio.com
 */
$dominio = '';
foreach ($argv as $a) {
    if (strncmp($a, '--dominio=', 10) === 0) $dominio = rtrim(substr($a, 10), '/');
}
if ($dominio === '') {
    $carpeta = basename(dirname($raiz));
    if (strpos($carpeta, '.') !== false && strpos($carpeta, ' ') === false) {
        $dominio = 'https://' . $carpeta;
    }
}
$probarAvisos = in_array('--probar-avisos', $argv, true);
$color  = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c($t, $x) { global $color; if (!$color) return $t;
    $m = ['v' => '32', 'r' => '31', 'a' => '33', 'g' => '1', 'd' => '90'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m"; }
function titulo($t) { echo "\n" . c($t, 'g') . "\n"; }
function bien($t)   { echo '  ' . c('ok  ', 'v') . $t . "\n"; }
function mal($t)    { echo '  ' . c('MAL ', 'r') . $t . "\n"; }
function ojo($t)    { echo '  ' . c('ojo ', 'a') . $t . "\n"; }
function nota($t)   { echo '      ' . c($t, 'd') . "\n"; }

$problemas = 0;

echo c('REVISIÓN DE COBROS · PAGA DE TODO', 'g') . "\n";

// ── El archivo ──────────────────────────────────────────────
titulo('El archivo de configuración');
$ruta = $raiz . '/config/integraciones.php';
if (!is_readable($ruta)) {
    mal('no existe ' . $ruta);
    nota('cópialo de config/integraciones.php.ejemplo');
    exit(1);
}
bien('se lee ' . $ruta);
Integraciones::cargar($raiz);
$crudo = (array)require $ruta;

// Bloques con el nombre mal escrito. El sistema los ignora enteros y en
// silencio, que es la peor forma de que algo no funcione.
$conocidos = array_keys(Integraciones::CATALOGO);
foreach (array_keys($crudo) as $bloque) {
    if (in_array($bloque, $conocidos, true)) continue;
    $cerca = '';
    foreach ($conocidos as $k) {
        if (levenshtein((string)$bloque, $k) <= 3) { $cerca = $k; break; }
    }
    $problemas++;
    mal("el bloque '" . $bloque . "' no lo lee nadie");
    nota($cerca !== ''
        ? "¿querías decir '" . $cerca . "'? tal como está, se ignora completo"
        : 'no está en el catálogo: se ignora completo');
}

if (!Integraciones::activa('spei')) {
    $e = Integraciones::estado()['spei'] ?? [];
    mal('la integración de cobros está apagada');
    if (!empty($e['faltan'])) nota('faltan: ' . implode(', ', $e['faltan']));
    else nota("falta poner 'activo' => true");
    exit(1);
}
$cfg = Integraciones::de('spei');
bien('la integración de cobros está encendida'
   . (!empty($cfg['sandbox']) ? ' ' . c('(Sandbox)', 'a') : ' ' . c('(PRODUCCIÓN)', 'r')));

// ── Credenciales ────────────────────────────────────────────
titulo('Credenciales');
$api = new LigaPago($cfg);
$r   = new ReflectionClass($api);
$mUrl = $r->getMethod('url');          $mUrl->setAccessible(true);
$mCre = $r->getMethod('credenciales'); $mCre->setAccessible(true);
$mNeg = $r->getMethod('negocio');      $mNeg->setAccessible(true);
$mRef = $r->getMethod('largoReferencia'); $mRef->setAccessible(true);
$mPag = $r->getMethod('contado');      $mPag->setAccessible(true);

list($usuario, $clave) = $mCre->invoke($api);
$tapa = function ($v) { $v = (string)$v;
    return $v === '' ? c('(vacío)', 'r')
         : (mb_strlen($v) <= 2 ? str_repeat('•', mb_strlen($v))
            : mb_substr($v, 0, 2) . str_repeat('•', max(1, mb_strlen($v) - 2))); };

echo '  usuario  ' . $tapa($usuario) . "\n";
echo '  clave    ' . $tapa($clave) . "\n";

nota(!empty($cfg['sandbox']) ? 'son las de Sandbox (usuario_prueba / clave_prueba)'
                              : 'son las de producción (usuario / clave)');

// En Sandbox nunca se usan las de producción: sin las de prueba, o con
// las mismas de producción, no se genera ningún cobro.
if (($bloqueo = $api->bloqueoSandbox()) !== '') {
    $problemas++; mal($bloqueo);
} elseif (trim((string)$usuario) === '' || trim((string)$clave) === '') {
    $problemas++; mal('falta usuario o clave');
}

$negocio = $mNeg->invoke($api);
if (trim((string)$negocio) === '') {
    $problemas++; mal('falta `negocio_id` (BusinessID): los tres servicios lo piden');
} else {
    bien('BusinessID ' . $negocio
       . (trim((string)($cfg['negocio_id'] ?? '')) === ''
          ? c('  ← sale de `escuela_id`, que es de Paga la Escuela', 'a') : ''));
}
echo '  integración  ' . ($cfg['integracion_id'] ?: c('(vacía)', 'r')) . "\n";

// Cada forma de pago puede traer SUS credenciales (usuario_clabe,
// negocio_id_referencia, integracion_id_liga…). Aquí se ve cuáles usa
// de verdad cada una, que es donde rebota cuando solo una funciona.
$mInt = $r->getMethod('integracion'); $mInt->setAccessible(true);
echo "\n  Lo que usa cada servicio:\n";
foreach (['liga' => 'tarjeta', 'clabe' => 'SPEI', 'referencia' => 'tienda'] as $svc => $rotulo) {
    list($uS, $cS) = $mCre->invoke($api, $svc);
    $nS = $mNeg->invoke($api, $svc);
    $iS = $mInt->invoke($api, $svc);
    $propias = [];
    foreach (['usuario','clave','negocio_id','integracion_id'] as $campo) {
        if (trim((string)($cfg[$campo . '_' . $svc] ?? '')) !== '') $propias[] = $campo . '_' . $svc;
    }
    echo '  · ' . str_pad($rotulo, 8) . ' usuario ' . $tapa($uS)
       . ' · clave ' . $tapa($cS)
       . ' · negocio ' . ($nS !== '' ? $nS : c('(vacío)', 'r'))
       . ' · integración ' . ($iS !== '' ? $iS : c('(vacía)', 'r')) . "\n";
    nota($propias ? 'propias: ' . implode(', ', $propias) : 'usa las generales');
    if (trim((string)$uS) === '' || trim((string)$cS) === '' || $nS === '' || $iS === '') {
        $problemas++; mal($rotulo . ': le falta usuario, clave, negocio o integración');
    }
}
echo '  referencia   ' . $mRef->invoke($api) . " dígitos\n";
echo '  contado      ' . $mPag->invoke($api)
   . c('   (41 producción · 401 Sandbox)', 'd') . "\n";

// ── A dónde pega cada forma de pago ─────────────────────────
titulo('A dónde pega cada forma de pago');

$servicios = [
    ['tarjeta',            'liga',       '/Service/GenerarLigaIndi'],
    ['SPEI',               'clabe',      '/Service/GenerarClabeIndi'],
    ['efectivo en tienda', 'referencia', '/Service/GenerarReferenciaIndi'],
    ['consulta estatus',   'estado',     '/Service/ConsultarEstatusLigaIndi'],
];
$destinos = [];
foreach ($servicios as $s) {
    list($rotulo, $clave_, $ruta) = $s;
    $puesta = trim((string)($cfg['url_' . $clave_] ?? ''));
    $usa    = $mUrl->invoke($api, $clave_, $ruta);
    $destinos[$rotulo] = $usa;

    echo "\n  " . c(str_pad($rotulo, 20), 'g') . $usa . "\n";

    if ($puesta !== '') {
        $queja = LigaPago::revisarUrl($puesta, $ruta);
        if ($queja !== '') {
            $problemas++;
            mal('`url_' . $clave_ . '` ' . $queja);
            nota('la tienes en: ' . $puesta);
            nota('se ignora y se usa la de arriba; bórrala del archivo');
        } else {
            nota('la pusiste a mano en `url_' . $clave_ . '`');
        }
    }
    // Host distinto al de Paga de Todo: puede ser a propósito, pero casi
    // siempre es un dedazo. Un host mal escrito no da un error del
    // proveedor: da "no se pudo contactar".
    $h = parse_url($usa, PHP_URL_HOST) ?: '';
    if ($h !== '' && stripos($h, 'pagadetodo.mx') === false) {
        ojo('el host es ' . $h . ', no pagadetodo.mx');
        if (levenshtein(strtolower($h), 'pagadetodo.mx') <= 3) {
            $problemas++;
            nota('se parece demasiado: revisa si es un dedazo');
        }
    }
}

// ── Los avisos ──────────────────────────────────────────────
titulo('Los avisos del proveedor (SPEI y tienda dependen de esto)');
$secreto = trim((string)($cfg['secreto_webhook'] ?? ''));
if ($secreto === '') {
    $problemas++;
    mal('`secreto_webhook` está vacío');
    nota('SPEI y efectivo en tienda NO se van a aplicar solos: los avisos se rechazan');
    nota('genera uno:  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"');
} elseif (strlen($secreto) < 16) {
    $problemas++;
    mal('`secreto_webhook` tiene ' . strlen($secreto) . ' caracteres: muy corto');
    nota('es lo único que protege esas direcciones; que tenga 32 o más');
} else {
    bien('`secreto_webhook` puesto (' . strlen($secreto) . ' caracteres)');
    // El dominio real, deducido arriba. Antes aquí se pisaba con el
    // literal «TU-DOMINIO» y había que sustituirlo a mano al copiar las
    // direcciones al panel: justo donde se cuela el error que hace que
    // el proveedor revierta los cobros.
    $muestra = $dominio !== '' ? $dominio : 'https://TU-DOMINIO';
    echo "\n  Da de alta EXACTAMENTE esto en el panel, pestaña EndPoint:\n\n";
    foreach ([
        'Comercios · Consultar referencia' => 'consulta-referencia',
        'Comercios · Pagar referencia'     => 'pago-referencia',
        'Comercios · Cancelar pago'        => 'cancela-pago',
        'SPEI · Consultar clabe'           => 'consulta-clabe',
        'SPEI · Pagar clabe'               => 'pago-clabe',
        'SPEI · Cancelar pago'             => 'cancela-pago',
        'Pago en línea · Pagar liga'       => 'pago-liga',
    ] as $rotulo => $r_) {
        // EL SECRETO VA EN LA RUTA, no en `?k=`.
        // El proveedor AÑADE «/?r=REFERENCIA» a lo que se registre, y
        // pegado detrás de un «?k=SECRETO» rompe el secreto y la
        // referencia de un golpe. En la ruta no estorba.
        echo '    ' . str_pad($rotulo, 34) . $muestra . '/pagadetodo/'
           . c(substr($secreto, 0, 4) . '…', 'd') . '/' . $r_ . "\n";
    }
    nota('(el secreto va completo; aquí se recorta para no dejarlo escrito)');
    nota('OJO: el secreto va DENTRO de la ruta, no como ?k=. El proveedor');
    nota('añade «/?r=REFERENCIA» al final y detrás de un ?k= lo rompe todo.');

    // `secreto_webhook_prueba` es opcional: se acepta igual que el
    // principal, para quien ya registró las direcciones con él.
    $secretoPrueba = trim((string)($cfg['secreto_webhook_prueba'] ?? ''));
    if ($secretoPrueba !== '' && $secretoPrueba !== $secreto) {
        nota('también se acepta `secreto_webhook_prueba` (' . c(substr($secretoPrueba, 0, 4) . '…', 'd') . ')');
    }

    if ($dominio === '') {
        ojo('No pude deducir tu dominio: sustituye TU-DOMINIO a mano');
        nota('o vuelve a correr esto con  --dominio=https://midominio.com');
    }
    nota('para ver el secreto entero:  grep secreto_webhook app/config/integraciones.php');
}

// ── ¿Responden NUESTRAS direcciones? ────────────────────────
//
// Esta es la prueba que de verdad contesta «¿por qué me revierten los
// cobros?». Las otras miran la configuración; esta llama a las mismas
// direcciones que llama el proveedor, con el mismo secreto, desde
// fuera. Si aquí contestan y al proveedor no, lo que está mal es lo
// que se dio de alta en su panel.
if ($probarAvisos) {
    titulo('Llamando a TUS avisos, como haría el proveedor');
    if ($secreto === '') {
        mal('sin `secreto_webhook` no hay nada que probar');
    } elseif ($dominio === '') {
        mal('no sé cuál es tu dominio: añade --dominio=https://midominio.com');
    } else {
        foreach ([
            ['GET',  'consulta-referencia?r=000000000000001', 'Consultar referencia'],
            ['GET',  'consulta-clabe?r=646180000000000001',   'Consultar clabe'],
            ['POST', 'pago-referencia',                       'Pagar referencia'],
            ['POST', 'pago-clabe',                            'Pagar clabe'],
            ['POST', 'cancela-pago',                          'Cancelar pago'],
            ['POST', 'pago-liga',                             'Pagar liga'],
        ] as $e) {
            list($metodo, $cola, $rotulo) = $e;
            $url = $dominio . '/pagadetodo/' . rawurlencode($secreto) . '/' . $cola;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $metodo,
                // Un cuerpo que NO existe a propósito: se quiere saber si
                // la dirección responde, no mover dinero de nadie.
                CURLOPT_POSTFIELDS => $metodo === 'POST'
                    ? '{"referencia":"000000000000001","monto":"0","transaccion":"prueba"}' : null,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $resp = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                $problemas++;
                mal(str_pad($rotulo, 22) . $err);
                continue;
            }
            $j = json_decode((string)$resp, true);
            if ($http === 404) {
                $problemas++;
                mal(str_pad($rotulo, 22) . 'HTTP 404 · esa dirección no existe');
                nota('revisa que el .htaccess mande todo a public/index.php');
            } elseif ($http === 403) {
                $problemas++;
                mal(str_pad($rotulo, 22) . 'HTTP 403 · el secreto no cuadra');
            } elseif (!is_array($j)) {
                $problemas++;
                mal(str_pad($rotulo, 22) . 'HTTP ' . $http . ' · no contestó JSON');
                nota(mb_substr(trim(strip_tags((string)$resp)), 0, 110));
            } else {
                // Un 200 con JSON es lo correcto AUNQUE diga que no
                // reconoce la referencia: la de prueba no existe. Lo que
                // importa es que conteste, y que conteste 200: un código
                // distinto hace que el proveedor revierta el cobro.
                bien(str_pad($rotulo, 22) . 'HTTP ' . $http . ' · '
                   . json_encode($j, JSON_UNESCAPED_UNICODE));
            }
        }
        nota('Un 200 con «Adquiriente inválido» está BIEN: la referencia de');
        nota('prueba no existe. Lo que importa es que conteste 200 y JSON.');
    }
} else {
    nota("\n  (--probar-avisos llama a tus propias direcciones, que es lo que");
    nota('   dice si el proveedor puede alcanzarlas)');
}

// ── Llamadas de verdad al proveedor ─────────────────────────
if ($probar) {
    titulo('Llamando al proveedor');
    foreach ($destinos as $rotulo => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            // A propósito SIN credenciales: se quiere saber si la
            // dirección existe y contesta, no generar un cobro.
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $problemas++;
            mal(str_pad($rotulo, 20) . $err);
            if (stripos($err, 'resolve') !== false) {
                nota('ese dominio no existe: es un dedazo en la dirección');
            }
            continue;
        }
        $j = json_decode((string)$resp, true);
        if (is_array($j)) {
            // Contestó JSON: la dirección existe y es un servicio del
            // proveedor. Sin credenciales va a rechazar, y está bien.
            $cod = $j['Error'] ?? $j['code'] ?? $j['codigo'] ?? '';
            bien(str_pad($rotulo, 20) . 'contesta (HTTP ' . $http . ')');
            nota('rechazó con ' . ($cod !== '' ? 'código ' . $cod : 'un mensaje')
               . ' porque se mandó vacío a propósito: eso es lo esperado');
        } else {
            $problemas++;
            mal(str_pad($rotulo, 20) . 'HTTP ' . $http . ', no contestó JSON');
            nota(mb_substr(trim(strip_tags((string)$resp)), 0, 120));
        }
    }
} else {
    nota("\n  (corre con --probar para llamar al proveedor y confirmar que las direcciones existen)");
}

echo "\n" . str_repeat('─', 60) . "\n";
if ($problemas === 0) {
    echo '  ' . c('Todo en orden.', 'v') . "\n";
} else {
    echo '  ' . c($problemas . ' cosa(s) que revisar.', 'r') . "\n";
}
exit($problemas === 0 ? 0 : 1);