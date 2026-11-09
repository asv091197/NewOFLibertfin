<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Datos\UsuarioRepo;

/** Crear la contraseña desde el enlace del correo. Ver CrearClaveControlador. */
$token = $_SESSION['lf_token'];
$logo  = __DIR__ . '/../../../public/assets/img/logo-blanco.png';
?>
<main class="lf-entrada">
  <div class="lf-logo">
    <a href="/" aria-label="Volver al inicio">
    <?php if (is_file($logo)): ?>
      <img src="/assets/img/logo-blanco.png?v=<?= filemtime($logo) ?>" alt="LibertyFin">
    <?php else: ?>
      <span class="lf-logo-texto">Libertyfin</span>
    <?php endif; ?>
    </a>
  </div>

  <div class="lf-tarjeta">
  <?php if (!$enlace): ?>
    <h1>Este enlace ya no sirve</h1>
    <p class="lf-sub">Se usó o venció</p>
    <p style="font-size:13px;line-height:1.65;color:color-mix(in srgb,#fff 80%,transparent)">
      Cada enlace sirve una sola vez y dura <?= (int)\LibertyFin\Servicio\EnlaceClave::HORAS ?> horas.
      Si ya creaste tu contraseña, entra con ella. Si no, pide un enlace nuevo a soporte de LibertyFin.
    </p>
    <a class="lf-boton" href="/login" style="text-decoration:none;margin-top:20px">
      <span class="txt">Ir a iniciar sesión</span>
    </a>
  <?php else: ?>
    <h1>Crea tu contraseña</h1>
    <p class="lf-sub"><?= P::e($enlace['empresa']) ?> · usuario <b><?= P::e($enlace['username']) ?></b></p>

    <?php if ($error): ?>
      <div class="lf-msg err" role="alert"><?= P::e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/crear-clave" id="ccForm">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="t" value="<?= P::e($t) ?>">
      <?php /* Para que el gestor de contraseñas la guarde con el usuario correcto. */ ?>
      <input type="text" name="usuario" value="<?= P::e($enlace['username']) ?>"
             autocomplete="username" hidden readonly>

      <label for="clave">Contraseña nueva</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
        </svg>
        <input type="password" id="clave" name="clave" autocomplete="new-password"
               minlength="<?= (int)UsuarioRepo::CLAVE_MINIMA ?>" placeholder="Al menos <?= (int)UsuarioRepo::CLAVE_MINIMA ?> caracteres"
               autofocus required>
      </div>

      <label for="confirmar">Repítela</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
        </svg>
        <input type="password" id="confirmar" name="confirmar" autocomplete="new-password"
               minlength="<?= (int)UsuarioRepo::CLAVE_MINIMA ?>" placeholder="••••••••" required>
      </div>

      <button class="lf-boton" type="submit" id="ccEnviar" style="margin-top:18px">
        <span class="txt">Guardar y continuar</span>
      </button>
    </form>
  <?php endif; ?>
  </div>

  <p class="lf-pie-entrada">© <?= date('Y') ?> Libertyfin · Todos los derechos reservados</p>
</main>

<script>
(function(){
  var f = document.getElementById('ccForm');
  if (!f) return;
  var c = document.getElementById('clave'), r = document.getElementById('confirmar');
  function igual(){ r.setCustomValidity(r.value && r.value !== c.value ? 'Las contraseñas no coinciden' : ''); }
  c.addEventListener('input', igual); r.addEventListener('input', igual);
  f.addEventListener('submit', function(){ document.getElementById('ccEnviar').disabled = true; });
})();
</script>
