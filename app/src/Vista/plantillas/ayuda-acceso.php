<?php
use LibertyFin\Vista\Plantilla as P;
?>
<main class="lf-entrada">
  <div class="lf-logo">
    <?php $logo = __DIR__ . '/../../public/assets/img/logo-blanco.png'; ?>
    <?php if (is_file($logo)): ?>
      <img src="/assets/img/logo-blanco.png?v=<?= filemtime($logo) ?>" alt="LibertyFin">
    <?php else: ?>
      <span class="lf-logo-texto">Libertyfin</span>
    <?php endif; ?>
  </div>

  <div class="lf-tarjeta">
    <h1>¿No recuerdas tu contraseña?</h1>
    <p class="lf-sub">Se restablece a mano, y hay una razón</p>

    <div style="font-size:13px;line-height:1.65;color:color-mix(in srgb,#fff 80%,transparent)">
      <p style="margin-bottom:14px">
        No hay un correo de "recuperar contraseña" porque este sistema maneja el
        dinero de un negocio. Un enlace que llega al correo es tan seguro como ese
        correo, y si alguien entró ahí, entraría aquí.
      </p>
      <p style="margin-bottom:6px"><b style="color:#fff">Pídesela a quien corresponda:</b></p>
      <ul style="margin:0 0 16px 18px;padding:0">
        <li style="margin-bottom:6px">Si eres cajero o de inventario, al
          <b style="color:#fff">administrador de tu negocio</b>. La restablece en
          Ajustes, en un clic.</li>
        <li>Si eres el administrador, a <b style="color:#fff">soporte de LibertyFin</b>.</li>
      </ul>
      <p style="font-size:12px;color:color-mix(in srgb,#fff 62%,transparent)">
        En los dos casos se genera una contraseña nueva que se entrega en persona o
        por teléfono, y se cambia al entrar.
      </p>
    </div>

    <a class="lf-boton" href="/login" style="text-decoration:none;margin-top:20px">
      <span class="txt">Volver a entrar</span>
    </a>
  </div>

  <p class="lf-pie-entrada">© <?= date('Y') ?> Libertyfin · Todos los derechos reservados</p>
</main>
