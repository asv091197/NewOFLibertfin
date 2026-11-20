<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Inventario as I;

/* El detalle de un movimiento, con TODO su documento: la entrada con
   pistola completa, las dos mitades de un traspaso, el conteo o la venta.
   Desde aquí se descarga en PDF. */
$m    = $mov;
$esTr = $m['tipo'] === 'traspaso';
$esCo = $m['tipo'] === 'conteo';
$doc  = I::documento($m);
$conCosto = false;
foreach ($grupo as $g) if ($g['costo'] !== null) $conCosto = true;
$fmt = function ($n) { $n = (float)$n; return ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . I::num(abs($n)); };
?>

<div class="lf-inv-cab">
  <a class="btn btn-secondary btn-sm" href="<?= P::e($volverA) ?>"><?= W::icono('atras','14px') ?> Movimientos</a>
  <span style="flex:1"></span>
  <a class="btn btn-primary btn-sm" target="_blank" rel="noopener"
     href="/inventario/imprimir?que=movimiento&amp;id=<?= (int)$m['id'] ?>&amp;auto=1">
    <?= W::icono('descargar','14px') ?> Descargar PDF</a>
</div>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title"><?= P::e($doc) ?>
      <span class="lf-inv-tipo t-<?= P::e($m['tipo']) ?>" style="margin-left:8px;vertical-align:middle">
        <?= P::e(I::TIPOS[$m['tipo']] ?? $m['tipo']) ?></span></h3>
    <small class="lf-inv-sub"><?= count($grupo) === 1 ? 'Un renglón' : count($grupo) . ' renglones' ?> en este documento.</small>
  </span></div>
  <dl class="lf-mov-datos">
    <div><dt>Cuándo</dt><dd><?= P::e(date('d/m/Y H:i', strtotime($m['creado_en']))) ?></dd></div>
    <div><dt>Quién</dt><dd><?= P::e($m['usuario_nombre'] ?: '—') ?></dd></div>
    <div><dt>Sucursal</dt><dd><?= P::e($m['sucursal'] ?: '—') ?></dd></div>
    <?php if (!$esTr && !empty($m['almacen'])): ?>
      <div><dt>Almacén</dt><dd><?= P::e($m['almacen']) ?></dd></div>
    <?php endif; ?>
    <?php if (!empty($m['proveedor'])): ?>
      <div><dt>Proveedor</dt><dd><?= P::e($m['proveedor']) ?></dd></div>
    <?php endif; ?>
    <?php if (!empty($m['venta_id'])): ?>
      <div><dt>Venta</dt><dd><a href="/ventas/<?= (int)$m['venta_id'] ?>"><?= P::e($m['codigo_venta'] ?: '#' . $m['venta_id']) ?></a></dd></div>
    <?php endif; ?>
    <?php if (trim((string)$m['nota']) !== ''): ?>
      <div class="ancho"><dt>Nota</dt><dd><?= P::e($m['nota']) ?></dd></div>
    <?php endif; ?>
  </dl>
</section>

<?php if ($esTr): /* ── Traspaso: de dónde salió y a dónde llegó ── */ ?>
  <?php foreach (I::traspasosEnPares($grupo) as $par): $s = $par['sale']; $l = $par['llega'];
    $u = ($s ?: $l)['unidad_medida'] ?? ''; ?>
  <section class="card">
    <div class="card-header"><span><h3 class="card-title"><?= P::e(($s ?: $l)['producto'] ?: '(ya no existe)') ?></h3>
      <small class="lf-inv-sub"><?= P::e(($s ?: $l)['codigo'] ?? '') ?></small></span></div>
    <div class="lf-traspaso">
      <div class="lado sale">
        <small>Salió de</small>
        <b><?= $s ? P::e(I::lugar($s)) : '—' ?></b>
        <?php if ($s): ?>
          <dl>
            <div><dt>Había</dt><dd class="lf-mono"><?= P::e(I::conUnidad(I::habia($s), $u)) ?></dd></div>
            <div><dt>Se fueron</dt><dd class="lf-mono lf-inv-cant menos">−<?= P::e(I::num(abs((float)$s['cantidad']))) ?></dd></div>
            <div><dt>Quedó</dt><dd class="lf-mono"><b><?= P::e(I::conUnidad($s['existencia'], $u)) ?></b></dd></div>
          </dl>
        <?php else: ?><p class="pista">No se encontró la salida de este traspaso.</p><?php endif; ?>
      </div>
      <div class="flecha" aria-hidden="true">→</div>
      <div class="lado llega">
        <small>Llegó a</small>
        <b><?= $l ? P::e(I::lugar($l)) : '—' ?></b>
        <?php if ($l): ?>
          <dl>
            <div><dt>Había</dt><dd class="lf-mono"><?= P::e(I::conUnidad(I::habia($l), $u)) ?></dd></div>
            <div><dt>Llegaron</dt><dd class="lf-mono lf-inv-cant mas">+<?= P::e(I::num(abs((float)$l['cantidad']))) ?></dd></div>
            <div><dt>Quedó</dt><dd class="lf-mono"><b><?= P::e(I::conUnidad($l['existencia'], $u)) ?></b></dd></div>
          </dl>
        <?php else: ?><p class="pista">No se encontró la llegada de este traspaso.</p><?php endif; ?>
      </div>
    </div>
  </section>
  <?php endforeach; ?>

<?php else: /* ── Entrada, salida, conteo, venta…: un renglón por producto ── */
  $suma = 0.0; $importe = 0.0; ?>
<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table lf-inv-tabla">
      <thead><tr>
        <th>Producto</th><th>Almacén</th>
        <th class="text-end"><?= $esCo ? 'El sistema decía' : 'Había' ?></th>
        <th class="text-end"><?= $esCo ? 'Ajuste' : 'Cantidad' ?></th>
        <th class="text-end"><?= $esCo ? 'Contado' : 'Quedó' ?></th>
        <th class="text-end">En la sucursal</th>
        <?php if ($conCosto): ?><th class="text-end">Costo c/u</th><th class="text-end">Importe</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($grupo as $g):
        $c = (float)$g['cantidad']; $suma += $c;
        $imp = $g['costo'] !== null ? abs($c) * (float)$g['costo'] : 0.0; $importe += $imp; ?>
        <tr<?= (int)$g['id'] === (int)$m['id'] && count($grupo) > 1 ? ' class="lf-fila-actual"' : '' ?>>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($g['producto'] ?: '(ya no existe)') ?></b>
            <small class="lf-inv-cod"><?= P::e($g['codigo'] ?? '') ?><?= !empty($g['marca']) ? ' · ' . P::e($g['marca']) : '' ?></small></td>
          <td data-label="Almacén"><?= P::e($g['almacen'] ?: '—') ?></td>
          <td data-label="<?= $esCo ? 'El sistema decía' : 'Había' ?>" class="text-end lf-mono"><?= P::e(I::conUnidad(I::habia($g), $g['unidad_medida'])) ?></td>
          <td data-label="<?= $esCo ? 'Ajuste' : 'Cantidad' ?>" class="text-end lf-mono lf-inv-cant <?= $c > 0 ? 'mas' : ($c < 0 ? 'menos' : '') ?>">
            <?= $esCo && abs($c) < 0.0005 ? 'Cuadra' : P::e($fmt($c)) ?></td>
          <td data-label="<?= $esCo ? 'Contado' : 'Quedó' ?>" class="text-end lf-mono"><b><?= P::e(I::conUnidad($g['existencia'], $g['unidad_medida'])) ?></b></td>
          <td data-label="En la sucursal" class="text-end lf-mono"><?= ($g['existencia_sucursal'] ?? null) !== null
              ? P::e(I::num($g['existencia_sucursal'])) : '<span style="color:var(--lf-tinta-4)">–</span>' ?></td>
          <?php if ($conCosto): ?>
            <td data-label="Costo c/u" class="text-end lf-mono"><?= $g['costo'] !== null ? D::pesos($g['costo']) : '–' ?></td>
            <td data-label="Importe" class="text-end lf-mono"><?= $g['costo'] !== null ? D::pesos($imp) : '–' ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if (count($grupo) > 1): ?>
      <tfoot><tr>
        <td colspan="3"><b>Total · <?= count($grupo) ?> productos</b></td>
        <td class="text-end lf-mono"><b><?= P::e($fmt($suma)) ?></b></td>
        <td></td><td></td>
        <?php if ($conCosto): ?><td></td><td class="text-end lf-mono"><b><?= D::pesos($importe) ?></b></td><?php endif; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="card-footer"><span>
    «Había» y «Quedó» son del almacén donde pasó; «En la sucursal», el total de todos sus almacenes justo después.
    <?php if (($m['anterior'] ?? null) === null): ?>
      En los movimientos anteriores a esta versión «Había» se calcula y el total de la sucursal no se guardó.
    <?php endif; ?>
  </span></div>
</section>
<?php endif; ?>
