<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Modulos;

/* Quien abre la página de un módulo apagado (o fuera de su plan) ve por
   qué y cómo encenderlo, en vez de un 404 que no explica nada. */
$m = Modulos::CATALOGO[$modulo] ?? ['nombre' => 'Este módulo', 'desc' => '', 'icono' => 'serv'];
$admin = \LibertyFin\Dominio\Permisos::puede('editar.ajustes');
?>
<section class="card lf-inv-apagado">
  <div class="card-body">
    <span class="lf-tile g"><?= W::icono($m['icono'], '22px') ?></span>
    <h3><?= P::e($m['nombre']) ?> está apagado</h3>
    <p><?= P::e($m['desc']) ?></p>
    <?php if (!$enPlan): ?>
      <p>Tu plan no lo incluye<?= $planes ? '. Está en: <b>' . P::e(implode(', ', $planes)) . '</b>' : '' ?>.</p>
      <?php if ($admin): ?><a class="btn btn-primary" href="/cuenta?t=plan">Ver planes</a><?php endif; ?>
    <?php elseif ($admin): ?>
      <a class="btn btn-primary" href="/ajustes?t=modulos">Encenderlo en Ajustes → Módulos</a>
    <?php else: ?>
      <p class="pista">Lo enciende un administrador en Ajustes → Módulos.</p>
    <?php endif; ?>
  </div>
</section>
