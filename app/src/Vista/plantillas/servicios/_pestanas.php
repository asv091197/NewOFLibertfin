<?php
use LibertyFin\Vista\Plantilla as P;
/* Catálogo y Promociones, las dos pestañas de Productos. Son enlaces: la
   navegación parcial del armazón las carga sin recargar la página. */
$vig = (int)($vigentes ?? 0);
?>
<div class="lf-pills" style="margin-bottom:18px" role="tablist">
  <a class="lf-pill <?= $actual === 'catalogo' ? 'active' : '' ?>" role="tab"
     aria-selected="<?= $actual === 'catalogo' ? 'true' : 'false' ?>" href="/servicios">Catálogo</a>
  <a class="lf-pill <?= $actual === 'promociones' ? 'active' : '' ?>" role="tab"
     aria-selected="<?= $actual === 'promociones' ? 'true' : 'false' ?>" href="/servicios?t=promociones"
     title="<?= $vig ? P::e($vig . ' vigente' . ($vig === 1 ? '' : 's') . ' hoy') : 'Ninguna vigente hoy' ?>">
    Promociones<?php if ($vig): ?> <span class="n"><?= $vig ?></span><?php endif; ?></a>
</div>
