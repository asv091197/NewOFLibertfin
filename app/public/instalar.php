<?php
/**
 * INSTALADOR DE LA PRIMERA CUENTA · córrelo una vez y bórralo
 *
 * POR QUÉ EXISTE, EN VEZ DE UN INSERT CON EL HASH ESCRITO
 *
 * Un hash de bcrypt empieza con `$2y$10$`. Metido en un archivo .sql,
 * cualquier cosa lo rompe: un cliente que trata `$` como variable, un
 * copiar-pegar que se come un carácter, un editor que cambia comillas.
 * Y cuando se rompe, el síntoma es el mismo que el de una contraseña
 * equivocada: no entra, y no hay forma de saber por qué.
 *
 * Aquí la contraseña se escribe en un formulario y el hash lo genera
 * TU PHP, el mismo que después va a comprobarla. No hay nada que copiar
 * ni que se pueda cortar.
 *
 * SEGURIDAD
 *
 * Esto es una página pública que crea una cuenta con todos los poderes,
 * así que se apaga sola: si ya existe un superadministrador activo,
 * responde 404 y no hace nada. La ventana está abierta solo mientras no
 * hay nadie dentro, que es cuando de verdad hace falta.
 *
 * Aun así, BÓRRALO al terminar. Un archivo que se apaga solo sigue
 * siendo un archivo que alguien puede encontrar.
 */

declare(strict_types=1);
session_start();

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

$cfgRuta = $raiz . '/config/config.php';
if (!is_readable($cfgRuta)) {
    salir('Falta config/config.php', 'Copia config.php.ejemplo y llena los datos de tu base.');
}
$cfg = require $cfgRuta;
$bd  = $cfg['bd'] ?? [];
$principalNombre = $bd['principal'] ?? '';

if ($principalNombre === '') {
    salir('Falta <code>bd.principal</code>',
          'En <code>config/config.php</code>, el campo <code>principal</code> tiene que '
        . 'decir el nombre de tu base principal (la que tiene la tabla <code>empresas</code>).');
}

// ── Conexión ──
try {
    $dsn = 'mysql:host=' . ($bd['host'] ?? 'localhost')
         . ';dbname=' . $principalNombre . ';charset=utf8mb4';
    $db = new PDO($dsn, $bd['usuario'] ?? '', $bd['clave'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    salir('No se pudo conectar a la base', htmlspecialchars($e->getMessage()));
}

// ── La tabla ──
$db->exec("
    CREATE TABLE IF NOT EXISTS usuarios_plataforma (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        password VARCHAR(255) NOT NULL,
        nombre VARCHAR(160) NOT NULL,
        email VARCHAR(160) NULL,
        rol VARCHAR(30) NOT NULL DEFAULT 'soporte',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL,
        ultimo_acceso DATETIME NULL,
        UNIQUE KEY ix_up_user (username),
        KEY ix_up_activo (activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── El candado: si ya hay superadministrador, esto no existe ──
$cuantos = (int)$db->query(
    "SELECT COUNT(*) FROM usuarios_plataforma WHERE rol = 'superadmin' AND activo = 1")
    ->fetchColumn();

if ($cuantos > 0) {
    http_response_code(404);
    salir('Ya hay un superadministrador',
        'Este instalador se apaga en cuanto existe una cuenta con todos los poderes. '
      . 'Si perdiste el acceso, crea otra desde MySQL o pídele a alguien del equipo '
      . 'que te la restablezca desde <b>Cuentas</b>.<br><br>'
      . '<b>Borra este archivo del servidor.</b>');
}

$error = '';
$hecho = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $user   = strtolower(trim($_POST['username'] ?? ''));
    $email  = trim($_POST['email'] ?? '');
    $c1     = (string)($_POST['clave'] ?? '');
    $c2     = (string)($_POST['clave2'] ?? '');

    if (mb_strlen($nombre) < 3) {
        $error = 'Escribe tu nombre completo.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $user)) {
        $error = 'El usuario son de 3 a 30 caracteres: minúsculas, números, punto, guion o guion bajo.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    } elseif (mb_strlen($c1) < 10) {
        // Diez y no ocho: esta cuenta abre todas las empresas.
        $error = 'La contraseña necesita al menos 10 caracteres. Esta cuenta abre todo.';
    } elseif ($c1 !== $c2) {
        $error = 'Las dos contraseñas no son iguales.';
    } else {
        try {
            // El hash lo genera ESTE PHP, el mismo que después la
            // comprueba. No hay nada que copiar ni que se pueda cortar.
            $hash = password_hash($c1, PASSWORD_DEFAULT);

            $db->prepare("
                INSERT INTO usuarios_plataforma
                    (username, password, nombre, email, rol, activo, creado_en)
                VALUES (?,?,?,?, 'superadmin', 1, NOW())
                ON DUPLICATE KEY UPDATE
                    password = VALUES(password), nombre = VALUES(nombre),
                    email = VALUES(email), rol = 'superadmin', activo = 1
            ")->execute([$user, $hash, $nombre, $email ?: null]);

            // Se comprueba de inmediato contra lo que quedó guardado.
            // Si algo pasó en el camino, es mejor saberlo aquí que en la
            // pantalla de entrar, donde el mensaje no distingue.
            $st = $db->prepare("SELECT password FROM usuarios_plataforma WHERE username = ?");
            $st->execute([$user]);
            $guardado = (string)$st->fetchColumn();

            if (!password_verify($c1, $guardado)) {
                $error = 'La cuenta se creó pero la contraseña no quedó bien guardada. '
                       . 'Revisa que la columna `password` sea VARCHAR(255).';
            } else {
                $hecho = $user;
            }
        } catch (Throwable $e) {
            $error = 'No se pudo crear: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── Pantalla ──
function salir(string $titulo, string $texto): void {
    pintar($titulo, '<p>' . $texto . '</p>');
    exit;
}

function pintar(string $titulo, string $cuerpo): void {
    ?><!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>LibertyFin · Instalación</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f1f4f2;color:#1b2420;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.caja{width:100%;max-width:440px;background:#fff;border:1px solid #e6ebe8;border-radius:18px;
  padding:34px 32px 28px;box-shadow:0 12px 40px rgba(0,0,0,.09)}
.marca{display:flex;align-items:center;gap:13px;margin-bottom:24px}
.g{width:44px;height:44px;border-radius:14px;background:#27ae60;color:#fff;display:flex;
  align-items:center;justify-content:center;font-weight:800;font-size:19px}
.marca b{display:block;font-size:18px;letter-spacing:-.4px}
.marca small{font-size:12.5px;color:#6d7a74}
h1{font-size:17px;margin-bottom:8px}
p{font-size:13.5px;line-height:1.6;color:#43504a;margin-bottom:14px}
label{display:block;font-size:12px;font-weight:600;color:#43504a;margin-bottom:6px}
input{width:100%;padding:11px 13px;background:#f6f8f7;border:1px solid #e6ebe8;border-radius:10px;
  font-size:14px;font-family:inherit;outline:none;margin-bottom:15px}
input:focus{background:#fff;border-color:#27ae60;box-shadow:0 0 0 3px rgba(39,174,96,.15)}
button{width:100%;padding:13px;border:none;border-radius:99px;background:#27ae60;color:#fff;
  font-family:inherit;font-size:14.5px;font-weight:700;cursor:pointer}
button:hover{background:#1f8b4d}
.err{background:#fdecea;color:#c0392b;padding:11px 14px;border-radius:10px;font-size:12.5px;
  margin-bottom:16px;line-height:1.5}
.ok{background:#e8f6ee;color:#1f8b4d;padding:13px 16px;border-radius:10px;font-size:13px;
  margin-bottom:18px;line-height:1.6}
.pie{margin-top:18px;padding-top:16px;border-top:1px solid #e6ebe8;font-size:11.5px;
  color:#6d7a74;line-height:1.6}
code{background:#f1f4f2;padding:2px 5px;border-radius:4px;font-size:12px}
a{color:#1f8b4d}
</style></head><body>
<div class="caja">
  <div class="marca"><span class="g">L</span>
    <div><b>LibertyFin</b><small>Instalación</small></div></div>
  <h1><?= $titulo ?></h1>
  <?= $cuerpo ?>
</div></body></html><?php
}

if ($hecho) {
    pintar('Cuenta creada',
        '<div class="ok"><b>Listo.</b> Entra con <b>' . htmlspecialchars($hecho)
      . '</b> y la contraseña que acabas de escribir.</div>'
      . '<p><b>Ahora borra este archivo del servidor:</b><br>'
      . '<code>public/instalar.php</code></p>'
      . '<p>Se apaga solo en cuanto existe un superadministrador, pero un archivo '
      . 'que se apaga solo sigue siendo un archivo que alguien puede encontrar.</p>'
      . '<form method="get" action="/login"><button type="submit">Ir a entrar</button></form>');
    exit;
}

pintar('Crea la primera cuenta',
    '<p>Esta cuenta administra <b>toda la plataforma</b>: las empresas, el equipo de '
  . 'soporte y las demás cuentas. Solo se puede crear una vez desde aquí.</p>'
  . ($error ? '<div class="err">' . $error . '</div>' : '')
  . '<form method="post">'
  . '<label for="n">Tu nombre completo</label>'
  . '<input id="n" name="nombre" required autofocus value="'
  . htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES) . '">'
  . '<label for="u">Usuario</label>'
  . '<input id="u" name="username" required pattern="[a-z0-9._-]{3,30}" '
  . 'placeholder="solo minúsculas, sin espacios" value="'
  . htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) . '">'
  . '<label for="e">Correo</label>'
  . '<input id="e" name="email" type="email" placeholder="Opcional, sirve para entrar también" value="'
  . htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) . '">'
  . '<label for="c">Contraseña</label>'
  . '<input id="c" name="clave" type="password" required minlength="10" '
  . 'autocomplete="new-password" placeholder="Al menos 10 caracteres">'
  . '<label for="c2">Repítela</label>'
  . '<input id="c2" name="clave2" type="password" required minlength="10" '
  . 'autocomplete="new-password">'
  . '<button type="submit">Crear cuenta</button></form>'
  . '<p class="pie">El hash lo genera este mismo PHP, así que no hay nada que copiar '
  . 'ni que se pueda cortar en el camino. Y se comprueba de inmediato contra lo que '
  . 'quedó guardado, para que un problema se vea aquí y no al intentar entrar.</p>');
