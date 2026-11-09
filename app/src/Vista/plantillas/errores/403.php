<?php use LibertyFin\Vista\Plantilla as P; use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos; ?>
<div class="card" style="max-width:520px;margin:50px auto;text-align:center">
  <div class="card-body" style="padding:38px 32px">
    <div class="lf-tile a" style="width:54px;height:54px;border-radius:18px;margin:0 auto 18px">
      <?= W::icono('alerta','26px') ?></div>
    <h2 style="font-size:19px;margin-bottom:8px">Esta sección no es para tu rol</h2>
    <p style="color:var(--lf-tinta-3);font-size:13.5px;line-height:1.6;margin-bottom:6px">
      Entraste como <b><?= P::e(Permisos::rotulo($_SESSION['usuario_rol'] ?? '')) ?></b>,
      y este rol no incluye ese permiso.
    </p>
    <p style="color:var(--lf-tinta-4);font-size:12px;margin-bottom:24px">
      Si lo necesitas para tu trabajo, pídeselo a un administrador.
    </p>
    <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
      <a class="btn btn-primary" href="/panel">Volver al panel</a>
      <?php if (\LibertyFin\Dominio\Permisos::puede('abrir.ticket')): ?>
        <a class="btn btn-secondary" href="/ayuda">Reportarlo a soporte</a>
      <?php endif; ?>
    </div>
  </div>
</div>
