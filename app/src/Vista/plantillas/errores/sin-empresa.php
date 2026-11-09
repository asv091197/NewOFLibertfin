<?php use LibertyFin\Vista\Plantilla as P; use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos; ?>
<div class="card" style="max-width:540px;margin:50px auto;text-align:center">
  <div class="card-body" style="padding:38px 32px">
    <div class="lf-tile a" style="width:54px;height:54px;border-radius:18px;margin:0 auto 18px">
      <?= W::icono('alerta','26px') ?></div>
    <h2 style="font-size:19px;margin-bottom:10px">Esta pantalla es de una empresa</h2>
    <p style="color:var(--lf-tinta-3);font-size:13.5px;line-height:1.65;margin-bottom:8px">
      Entraste como <b><?= P::e(Permisos::rotulo($_SESSION['usuario_rol'] ?? '')) ?></b>,
      y esa cuenta <b>no pertenece a ninguna empresa</b>. Aquí se mostrarían las
      ventas, la caja o los ajustes de una en concreto, y no hay ninguna que
      mostrar.
    </p>
    <p style="color:var(--lf-tinta-4);font-size:12px;line-height:1.6;margin-bottom:24px">
      Para ver los datos de un cliente, ábrelo desde Empresas: ahí sí queda claro
      de quién son los números.
    </p>
    <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
      <a class="btn btn-primary" href="/soporte">Ir a Empresas</a>
      <a class="btn btn-secondary" href="/panel">Volver al panel</a>
    </div>
  </div>
</div>
