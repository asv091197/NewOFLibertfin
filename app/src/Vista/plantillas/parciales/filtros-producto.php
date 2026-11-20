<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Servicio\FiltrosProducto as FP;

/* Marca, área y tipo de producto. El MISMO bloque en Productos,
   Existencias y Conteo físico (ver Servicio\FiltrosProducto): si cada
   pantalla tuviera el suyo, terminarían filtrando distinto.

   Recibe:
     $opcionesFiltro  lo de FiltrosProducto::opciones()
     $filtro          lo de FiltrosProducto::deLaPeticion()

   Va DENTRO de un <form method="get">; al cambiar un selector se manda
   solo, como el «Solo lo que falta» de Existencias. */
$opF = $opcionesFiltro ?? ['marcas' => [], 'areas' => [], 'tipos' => []];
$fv  = $filtro ?? ['marca' => '', 'area' => 0, 'tipo' => ''];
$mandar = "this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()";
?>
<?php if ($opF['marcas'] || ($fv['marca'] ?? '') !== ''): ?>
<select class="form-select form-select-sm" name="marca" style="width:auto;max-width:180px"
        aria-label="Marca" onchange="<?= $mandar ?>">
  <option value="">Todas las marcas</option>
  <?php foreach ($opF['marcas'] as $m): ?>
    <option value="<?= P::e($m) ?>" <?= ($fv['marca'] ?? '') === (string)$m ? 'selected' : '' ?>><?= P::e($m) ?></option>
  <?php endforeach; ?>
  <?php if (($fv['marca'] ?? '') !== '' && !in_array($fv['marca'], $opF['marcas'], true)): ?>
    <option value="<?= P::e($fv['marca']) ?>" selected><?= P::e($fv['marca']) ?></option>
  <?php endif; ?>
</select>
<?php endif; ?>

<?php if ($opF['areas']): $areaSel = (int)($fv['area'] ?? 0); ?>
<select class="form-select form-select-sm" name="area" style="width:auto;max-width:200px"
        aria-label="Área" onchange="<?= $mandar ?>">
  <option value="">Todas las áreas</option>
  <?php foreach ($opF['areas'] as $c): ?>
    <option value="<?= (int)$c['id'] ?>" <?= $areaSel === (int)$c['id'] ? 'selected' : '' ?>><?= P::e($c['nombre']) ?></option>
    <?php foreach (($c['hijas'] ?? []) as $h): ?>
      <option value="<?= (int)$h['id'] ?>" <?= $areaSel === (int)$h['id'] ? 'selected' : '' ?>>&nbsp;&nbsp;↳ <?= P::e($h['nombre']) ?></option>
    <?php endforeach; ?>
  <?php endforeach; ?>
</select>
<?php endif; ?>

<?php if (count($opF['tipos']) > 1 || ($fv['tipo'] ?? '') !== ''): ?>
<select class="form-select form-select-sm" name="tipo" style="width:auto;max-width:180px"
        aria-label="Tipo de producto" onchange="<?= $mandar ?>">
  <option value="">Todos los tipos</option>
  <?php foreach ($opF['tipos'] as $t): ?>
    <option value="<?= P::e($t) ?>" <?= ($fv['tipo'] ?? '') === (string)$t ? 'selected' : '' ?>><?= P::e(FP::rotuloTipo($t)) ?></option>
  <?php endforeach; ?>
</select>
<?php endif; ?>
