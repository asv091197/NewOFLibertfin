<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\CrearEmpresa as CE;

/**
 * Registro · a donde lleva la landing.
 *
 * Dos columnas: a la izquierda la marca y por qué vale la pena; a la
 * derecha, el formulario. En el teléfono el formulario va primero: quien
 * llegó desde la landing ya decidió, no hay que volver a convencerlo.
 *
 * Lo que se promete es lo que pasa: se manda una SOLICITUD, alguien la
 * revisa y los accesos llegan por correo (ver Servicio\CrearEmpresa). La
 * prueba dura 30 días, que es lo que se le da al aprobarla.
 */
$token = $_SESSION['lf_token'];
$prev = $previo ?? [];   // lo ya escrito, o lo que trae el enlace de la landing
$d = function ($k) use ($prev) { return P::e($prev[$k] ?? ''); };
$sitio   = $sitio ?? [];
$landing = trim((string)($sitio['landing'] ?? ''));
$plan    = (string)($prev['plan'] ?? 'prueba');
$dist    = trim((string)($prev['no_distribuidor'] ?? ''));
$logo    = __DIR__ . '/../../../../public/assets/img/logo-blanco.png';
$check   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
$flecha  = '<svg class="fl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>';
?>
<div class="lf-rg">

  <?php /* ═══════════ LA MARCA ═══════════ */ ?>
  <aside class="lf-rg-lado">
    <div class="lf-rg-lado-in">
      <a class="lf-rg-logo" href="<?= $landing !== '' ? P::e($landing) : '/login' ?>">
        <?php if (is_file($logo)): ?>
          <img src="/assets/img/logo-blanco.png?v=<?= filemtime($logo) ?>" alt="Libertyfin">
        <?php else: ?>Libertyfin<?php endif; ?>
      </a>

      <span class="lf-rg-chip"><i></i><?= (int)CE::DIAS_PRUEBA ?> días gratis · Sin tarjeta de crédito</span>
      <h1>Tu negocio,<br><em>en orden.</em></h1>
      <p class="lf-rg-intro">Cobra en caja, persigue lo que te deben y reparte comisiones desde un solo
        lugar, en la computadora o en el celular.</p>

      <ul class="lf-rg-beneficios">
        <?php foreach ([
          ['caja',  'Caja lista para cobrar',          'Completo o con anticipo: el saldo queda pendiente solo.'],
          ['reloj', 'Cobranza sin adivinar',           'Quién te debe, cuánto y desde cuándo, en una lista.'],
          ['comi',  'Comisiones que se calculan solas', 'Sobre lo cobrado, por colaborador y por área.'],
          ['bolsa', 'Inventario si vendes productos',  'Existencias por sucursal que bajan al cobrar.'],
        ] as $b): ?>
          <li><span class="ico"><?= W::icono($b[0], '18px') ?></span>
            <div><b><?= P::e($b[1]) ?></b><small><?= P::e($b[2]) ?></small></div></li>
        <?php endforeach; ?>
      </ul>

      <ul class="lf-rg-checks">
        <?php foreach (['Sin tarjeta de crédito', '30 días para probarlo completo',
                        'Tu información en su propia base de datos', 'Soporte desde el mismo sistema'] as $c): ?>
          <li><?= $check ?><?= P::e($c) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>

  <?php /* ═══════════ EL FORMULARIO ═══════════ */ ?>
  <main class="lf-rg-form-lado">
    <div class="lf-rg-caja">
      <a class="lf-rg-logo-movil" href="<?= $landing !== '' ? P::e($landing) : '/login' ?>">Libertyfin</a>

    <?php if ($ok): $ok = is_array($ok) ? $ok : ['nombre' => '', 'email' => '']; ?>
      <?php /* ── Enviada ── */ ?>
      <div class="lf-rg-listo">
        <span class="ok"><?= $check ?></span>
        <h2>¡Listo<?= $ok['nombre'] !== '' ? ', ' . P::e($ok['nombre']) : '' ?>!</h2>
        <p>Recibimos tu solicitud.<?= $ok['email'] !== ''
            ? ' Te escribimos a <b>' . P::e($ok['email']) . '</b> en cuanto tu cuenta esté lista.' : '' ?></p>
        <ol class="lf-rg-pasos">
          <?php foreach ([
            ['Revisamos tus datos',   'Una persona revisa tu solicitud, en menos de un día hábil.'],
            ['Te llegan tus accesos', 'Tu usuario y tu contraseña, al correo que escribiste.'],
            ['Entras y lo configuras', 'Tus productos, tu equipo y tus datos fiscales. La guía te acompaña.'],
          ] as $i => $ps): ?>
            <li><span class="n"><?= $i + 1 ?></span><div><b><?= P::e($ps[0]) ?></b><small><?= P::e($ps[1]) ?></small></div></li>
          <?php endforeach; ?>
        </ol>
        <a class="lf-rg-boton" href="/login"><span>Ir al inicio de sesión</span><?= $flecha ?></a>
        <?php if ($landing !== ''): ?><a class="lf-rg-volver" href="<?= P::e($landing) ?>">Volver al sitio</a><?php endif; ?>
      </div>

    <?php else: ?>
      <h2>Crea tu cuenta gratis</h2>
      <p class="lf-rg-sub"><?= (int)CE::DIAS_PRUEBA ?> días de prueba. Sin tarjeta de crédito.</p>
      <?php if ($plan !== 'prueba' && isset($planes[$plan])): ?>
        <p class="lf-rg-plan">Elegiste el plan <b><?= P::e($planes[$plan]) ?></b>: empiezas con los 30 días sin costo.</p>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="lf-aviso err" role="alert"><?= W::icono('alerta','17px') ?><span><?= P::e($error) ?></span></div>
      <?php endif; ?>

      <form method="post" action="/registro" class="lf-rg-form" id="rgForm" novalidate>
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="plan" value="<?= P::e($plan) ?>">
        <?php /* Trampa para robots: una persona no la ve ni la llena. */ ?>
        <div class="lf-rg-trampa" aria-hidden="true">
          <label>Sitio web <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="dos">
          <div class="campo">
            <label for="rgNom">Nombre <i>*</i></label>
            <input class="form-control" id="rgNom" name="nombre" value="<?= $d('nombre') ?>" autocomplete="given-name"
                   placeholder="Ej. Juan" maxlength="80" required>
          </div>
          <div class="campo">
            <label for="rgApe">Apellido <i>*</i></label>
            <input class="form-control" id="rgApe" name="apellido" value="<?= $d('apellido') ?>" autocomplete="family-name"
                   placeholder="Ej. Rodríguez" maxlength="80" required>
          </div>
        </div>

        <div class="campo">
          <label for="rgEmp">Nombre de tu negocio <i>*</i></label>
          <input class="form-control" id="rgEmp" name="nombre_empresa" value="<?= $d('nombre_empresa') ?>" autocomplete="organization"
                 placeholder="Como lo conocen tus clientes" maxlength="200" minlength="3" required>
        </div>

        <div class="campo">
          <label for="rgCor">Correo electrónico <i>*</i></label>
          <input class="form-control" type="email" id="rgCor" name="email_admin" value="<?= $d('email_admin') ?>" autocomplete="email"
                 placeholder="correo@tunegocio.com" maxlength="160" required>
          <small>Aquí te llegan tu usuario y tu contraseña.</small>
        </div>

        <div class="dos">
          <div class="campo">
            <label for="rgTel">Teléfono <i>*</i></label>
            <input class="form-control" type="tel" id="rgTel" name="telefono" value="<?= $d('telefono') ?>" autocomplete="tel"
                   inputmode="tel" placeholder="55 1234 5678" maxlength="20" pattern="[\d\s+()\-]{10,20}" required>
          </div>
          <div class="campo">
            <label for="rgGiro">Giro del negocio <i>*</i></label>
            <select class="form-select" id="rgGiro" name="giro" required>
              <option value="">Elige…</option>
              <?php foreach (CE::GIROS as $g): ?>
                <option <?= ($prev['giro'] ?? '') === $g ? 'selected' : '' ?>><?= P::e($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="campo" id="rgOtro" <?= ($prev['giro'] ?? '') === 'Otro' ? '' : 'hidden' ?>>
          <label for="rgGiroOtro">¿A qué se dedica tu negocio? <i>*</i></label>
          <input class="form-control" id="rgGiroOtro" name="giro_otro" value="<?= $d('giro_otro') ?>" maxlength="120"
                 placeholder="Ej. Agencia de viajes">
        </div>

        <fieldset class="campo">
          <legend>¿Cuántas personas trabajan en tu negocio? <i>*</i></legend>
          <div class="lf-rg-opciones">
            <?php foreach (CE::EMPLEADOS as $k => $t): ?>
              <label><input type="radio" name="empleados" value="<?= P::e($k) ?>"
                     <?= (string)($prev['empleados'] ?? '') === (string)$k ? 'checked' : '' ?> required><span><?= P::e($t) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <?php /* El código de distribuidor: si vino en el enlace, ya está; si
                 no, se pide solo a quien lo tiene. */ ?>
        <?php if ($dist !== ''): ?>
          <input type="hidden" name="no_distribuidor" value="<?= P::e($dist) ?>">
          <p class="lf-rg-dist">Te invitó el distribuidor <b class="lf-mono"><?= P::e($dist) ?></b>.</p>
        <?php else: ?>
          <details class="lf-rg-dist-abre">
            <summary>¿Te invitó un distribuidor?</summary>
            <div class="campo">
              <label for="rgDist">Código de distribuidor</label>
              <input class="form-control lf-mono" id="rgDist" name="no_distribuidor" maxlength="50" placeholder="Ej. DIST123"
                     style="text-transform:uppercase">
            </div>
          </details>
        <?php endif; ?>

        <button class="lf-rg-boton" type="submit" id="rgEnviar">
          <span>Crear mi cuenta gratis</span><?= $flecha ?><span class="giro" aria-hidden="true"></span>
        </button>

        <p class="lf-rg-nota">Revisamos cada solicitud y en menos de un día hábil te llegan tus accesos por correo.
          <?php
          $ter = trim((string)($sitio['terminos'] ?? '')); $pri = trim((string)($sitio['privacidad'] ?? ''));
          $leg = [];
          if ($ter !== '') $leg[] = '<a href="' . P::e($ter) . '" target="_blank" rel="noopener">Términos de uso</a>';
          if ($pri !== '') $leg[] = 'el <a href="' . P::e($pri) . '" target="_blank" rel="noopener">Aviso de privacidad</a>';
          if ($leg) echo '<br>Al registrarte aceptas los ' . implode(' y ', $leg) . '.';
          ?></p>
      </form>

      <p class="lf-rg-entrar">¿Ya tienes cuenta? <a href="/login">Inicia sesión</a></p>
    <?php endif; ?>
    </div>
  </main>
</div>

<script>
(function(){
  var f = document.getElementById('rgForm');
  if (!f) return;
  var giro = document.getElementById('rgGiro'), otro = document.getElementById('rgOtro'),
      otroIn = document.getElementById('rgGiroOtro');
  function verOtro(){
    var s = giro.value === 'Otro';
    otro.hidden = !s; otroIn.required = s;
    if (s && document.activeElement === giro) otroIn.focus();
  }
  giro.addEventListener('change', verOtro); verOtro();

  /* Validación del navegador, pero con un solo aviso a la vez y el cursor
     en el primer campo que falta: `novalidate` en el form evita que salte
     antes de tiempo; aquí se valida al enviar. */
  f.addEventListener('submit', function(e){
    if (!f.checkValidity()) {
      e.preventDefault();
      var mal = f.querySelector(':invalid');
      if (mal) { mal.reportValidity ? mal.reportValidity() : null; if (mal.focus) mal.focus(); }
      return;
    }
    var b = document.getElementById('rgEnviar');
    b.classList.add('cargando'); b.disabled = true;
  });
})();
window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('rgEnviar');
  if (b) { b.classList.remove('cargando'); b.disabled = false; }
});
</script>