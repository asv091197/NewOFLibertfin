<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Inventario as I;
use LibertyFin\Servicio\Conteo as C;

/* Un conteo abierto desde «Conteos anteriores»: qué se contó, contra qué
   y cuánto salió de diferencia. Se descarga en PDF desde aquí. */
$c   = $det['conteo'];
$t   = $det['totales'];
$abi = $det['abierto'];
$est = $c['estado'];
?>

<div class="lf-inv-cab">
  <a class="btn btn-secondary btn-sm" href="/inventario?t=conteo&amp;s=<?= (int)$c['sucursal_id'] ?>">
    <?= W::icono('atras','14px') ?> Conteo físico</a>
  <span style="flex:1"></span>
  <?php if ($abi): ?>
    <a class="btn btn-secondary btn-sm" href="/inventario?t=conteo&amp;s=<?= (int)$c['sucursal_id'] ?>">Seguir contando</a>
    <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
       href="/inventario/imprimir?que=conteo&amp;id=<?= (int)$c['id'] ?>&amp;hoja=1&amp;auto=1">
      <?= W::icono('descargar','14px') ?> Hoja para contar</a>
  <?php endif; ?>
  <a class="btn btn-primary btn-sm" target="_blank" rel="noopener"
     href="/inventario/imprimir?que=conteo&amp;id=<?= (int)$c['id'] ?>&amp;auto=1">
    <?= W::icono('descargar','14px') ?> Descargar PDF</a>
</div>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title"><?= P::e($c['nombre'] ?: ('Conteo #' . (int)$c['id'])) ?>
      <span class="badge <?= $est === 'cerrado' ? 'bg-success' : ($est === 'cancelado' ? 'bg-secondary' : 'bg-warning') ?>"
            style="margin-left:8px"><?= P::e(C::ESTADOS[$est] ?? $est) ?></span></h3>
    <small class="lf-inv-sub">
      #<?= (int)$c['id'] ?> · <?= P::e($c['almacen'] ?: 'Almacén') ?> de <?= P::e($c['sucursal'] ?: '—') ?>
      · lo inició <?= P::e($c['usuario_nombre'] ?: '—') ?> el <?= P::e(date('d/m/Y H:i', strtotime($c['creado_en']))) ?>
      <?php if (!empty($c['cerrado_en'])): ?> · cerró el <?= P::e(date('d/m/Y H:i', strtotime($c['cerrado_en']))) ?><?php endif; ?>
      <?php if (!empty($c['nota'])): ?> · <?= P::e($c['nota']) ?><?php endif; ?>
    </small>
  </span></div>
  <div class="lf-stats" style="padding:14px 18px 4px;margin:0">
    <div class="stat-card"><div class="stat-value"><?= number_format($t['productos']) ?></div>
      <div class="stat-label">Contados</div></div>
    <div class="stat-card"><div class="stat-value"><?= number_format($t['diferencias']) ?></div>
      <div class="stat-label">Con diferencia</div></div>
    <div class="stat-card"><div class="stat-value lf-inv-cant mas">+<?= P::e(I::num($t['sobran'])) ?></div>
      <div class="stat-label">Sobraron</div><div class="stat-meta">contaste más de lo que decía</div></div>
    <div class="stat-card"><div class="stat-value lf-inv-cant menos">−<?= P::e(I::num($t['faltan'])) ?></div>
      <div class="stat-label">Faltaron</div><div class="stat-meta">contaste menos</div></div>
    <div class="stat-card"><div class="stat-value"><?= D::pesos($t['valor']) ?></div>
      <div class="stat-label">Valor de la diferencia</div><div class="stat-meta">a costo</div></div>
  </div>

  <div style="padding:6px 18px 0">
    <label class="lf-inv-check"><input type="checkbox" id="soloDif"> Solo los que tienen diferencia</label>
  </div>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table lf-inv-tabla" id="tablaConteo">
      <thead><tr><th>Producto</th>
        <th class="text-end"><?= $abi ? 'El sistema dice (ahora)' : 'El sistema decía' ?></th>
        <th class="text-end">Contaste</th><th class="text-end">Diferencia</th><th class="text-end">Valor</th></tr></thead>
      <tbody>
      <?php if (!$det['lineas']): ?>
        <tr><td colspan="5" class="lf-inv-vacio">No se capturó ningún producto en este conteo.</td></tr>
      <?php endif; ?>
      <?php foreach ($det['lineas'] as $l): $d = (float)$l['diferencia']; $cuadra = abs($d) < 0.0005; ?>
        <tr data-dif="<?= $cuadra ? 0 : 1 ?>">
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($l['producto']) ?></b>
            <small class="lf-inv-cod"><?= P::e($l['codigo']) ?></small></td>
          <td data-label="Sistema" class="text-end lf-mono"><?= P::e(I::conUnidad($l['sistema'], $l['unidad_medida'])) ?></td>
          <td data-label="Contaste" class="text-end lf-mono"><b><?= P::e(I::conUnidad($l['contado'], $l['unidad_medida'])) ?></b></td>
          <td data-label="Diferencia" class="text-end lf-mono lf-conteo-dif <?= $cuadra ? 'ok' : ($d > 0 ? 'mas' : 'menos') ?>">
            <?= $cuadra ? 'Cuadra' : ($d > 0 ? '+' : '−') . P::e(I::num(abs($d))) ?></td>
          <td data-label="Valor" class="text-end lf-mono"><?= $cuadra ? '–' : D::pesos($l['valor_dif']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    <?= $abi
      ? 'El conteo sigue abierto: la diferencia es contra lo que hay ahora y nada se ha ajustado todavía.'
      : ($est === 'cerrado'
          ? 'La diferencia es contra lo que decía el sistema cuando se contó. El ajuste quedó en <a href="/inventario?t=movimientos&amp;tipo=conteo&amp;s=' . (int)$c['sucursal_id'] . '">Movimientos</a>.'
          : 'Este conteo se canceló: no movió ninguna existencia.') ?>
  </span></div>
</section>

<script>
(function(){
  var c = document.getElementById('soloDif'), t = document.getElementById('tablaConteo');
  if (!c || !t) return;
  c.addEventListener('change', function(){
    t.querySelectorAll('tbody tr[data-dif]').forEach(function(tr){ tr.hidden = c.checked && tr.dataset.dif === '0'; });
  });
})();
</script>
