<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Compras as C;
use LibertyFin\Servicio\Inventario as I;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$c = $compra;
$colores = ['pedida' => 'bg-warning', 'recibida' => 'bg-success', 'cancelada' => 'bg-secondary'];
$cambiaCosto = array_filter($c['lineas'], function ($l) {
    return (float)$l['costo'] > 0 && abs((float)$l['costo'] - (float)$l['costo_actual']) > 0.005;
});
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-doc-acciones">
  <a class="btn btn-secondary" href="/compras"><?= W::icono('atras','14px') ?> Compras</a>
  <span class="esp"></span>
  <?php if ($c['estado'] === 'pedida' && $puedeEditar): ?>
    <form method="post" action="/compras/<?= (int)$c['id'] ?>/cancelar" onsubmit="return confirm('¿Cancelar el pedido? No se mueve nada del inventario.')">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-secondary" type="submit">Cancelar pedido</button>
    </form>
  <?php endif; ?>
</div>

<section class="card lf-doc">
  <div class="lf-doc-cab">
    <div>
      <span class="lf-doc-folio lf-mono"><?= P::e($c['folio']) ?></span>
      <h3><?= P::e($c['proveedor_nombre']) ?></h3>
      <span class="lf-doc-sub">Llega a <?= P::e($c['sucursal'] ?: '—') ?><?= $c['notas'] ? ' · ' . P::e($c['notas']) : '' ?></span>
    </div>
    <div class="lf-doc-meta">
      <span class="badge <?= $colores[$c['estado']] ?? 'bg-secondary' ?>"><?= P::e(C::ESTADOS[$c['estado']]) ?></span>
      <dl>
        <dt>Pedido</dt><dd><?= P::e(date('d/m/Y', strtotime($c['creado_en']))) ?><?= $c['creado_por'] ? ' · ' . P::e($c['creado_por']) : '' ?></dd>
        <?php if ($c['recibida_en']): ?><dt>Recibido</dt><dd><?= P::e(date('d/m/Y H:i', strtotime($c['recibida_en']))) ?><?= $c['recibida_por'] ? ' · ' . P::e($c['recibida_por']) : '' ?></dd><?php endif; ?>
        <?php if ($c['gasto_id']): ?><dt>Gasto</dt><dd><a href="/gastos">Registrado</a></dd><?php endif; ?>
      </dl>
    </div>
  </div>
  <div class="table-responsive lf-cards" style="padding:0 12px">
    <table class="table">
      <thead><tr><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Costo c/u</th><th class="text-end">Importe</th></tr></thead>
      <tbody>
      <?php foreach ($c['lineas'] as $l): ?>
        <tr>
          <td data-label="Producto"><b><?= P::e($l['nombre'] ?: '(producto borrado)') ?></b>
            <?php if ((float)$l['costo'] > 0 && abs((float)$l['costo'] - (float)$l['costo_actual']) > 0.005 && $c['estado'] === 'pedida'): ?>
              <small class="lf-inv-cod" style="display:block">hoy cuesta <?= D::pesos($l['costo_actual']) ?></small>
            <?php endif; ?></td>
          <td data-label="Cantidad" class="text-end lf-mono"><?= P::e(I::conUnidad($l['cantidad'], $l['unidad_medida'])) ?></td>
          <td data-label="Costo" class="text-end lf-mono"><?= (float)$l['costo'] > 0 ? D::pesos($l['costo']) : '—' ?></td>
          <td data-label="Importe" class="text-end lf-mono"><b><?= D::pesos($l['cantidad'] * $l['costo']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3" class="text-end"><b>Total</b></td><td class="text-end lf-mono lf-doc-total"><?= D::pesos($c['total']) ?></td></tr></tfoot>
    </table>
  </div>

  <?php if ($c['estado'] === 'pedida' && $puedeEditar): ?>
  <form method="post" action="/compras/<?= (int)$c['id'] ?>/recibir" class="lf-co-recibir"
        onsubmit="return confirm('¿Ya llegó todo? Cada producto entra al inventario de <?= P::e(addslashes($c['sucursal'] ?: 'la sucursal')) ?>.')">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <b>¿Llegó la mercancía?</b>
    <?php if ($cambiaCosto): ?>
      <label class="lf-inv-check"><input type="checkbox" name="actualizar_costo" value="1" checked>
        Actualizar el costo de <?= count($cambiaCosto) === 1 ? 'su producto' : 'los ' . count($cambiaCosto) . ' productos' ?> al de esta compra (la utilidad se calcula con él)</label>
    <?php endif; ?>
    <?php if ((float)$c['total'] > 0): ?>
      <label class="lf-inv-check"><input type="checkbox" name="gasto" value="1" checked id="coGasto">
        Registrar el pago como gasto (<?= D::pesos($c['total']) ?>, «<?= P::e(C::CATEGORIA_GASTO) ?>»)</label>
      <select class="form-select form-select-sm" name="metodo_pago" style="width:auto" aria-label="Cómo se pagó">
        <option value="transferencia">Por transferencia</option><option value="efectivo">En efectivo</option><option value="tarjeta">Con tarjeta</option>
      </select>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= W::icono('ok','14px') ?> Recibir mercancía</button>
  </form>
  <?php endif; ?>
</section>
