<?php use LibertyFin\Vista\Plantilla as P; use LibertyFin\Vista\Widget as W; ?>
<div class="card" style="max-width:480px;margin:auto;text-align:center;width:calc(100% - 32px)">
  <div class="card-body" style="padding:36px 30px">
    <div class="lf-tile r" style="width:54px;height:54px;border-radius:18px;margin:0 auto 18px">
      <?= W::icono('alerta','26px') ?></div>
    <h2 style="font-size:19px;margin-bottom:10px">La suscripción venció</h2>
    <p style="color:var(--lf-tinta-3);font-size:13.5px;line-height:1.65;margin-bottom:8px">
      La suscripción de <b><?= P::e($empresa) ?></b> venció el <b><?= P::e($fecha) ?></b>.
      Tu información está a salvo y vuelve a estar disponible en cuanto se renueve.
    </p>
    <p style="color:var(--lf-tinta-4);font-size:12.5px;line-height:1.6;margin-bottom:24px">
      Pídele al administrador de tu empresa que entre a <b>Mi cuenta → Plan</b> y
      renueve el plan.
    </p>
    <a class="btn btn-secondary" href="/salir">Cerrar sesión</a>
  </div>
</div>
