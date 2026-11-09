<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Compras as C;
use LibertyFin\Servicio\Inventario as I;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$varias = count($sucursales) > 1;
$colores = ['pedida' => 'bg-warning', 'recibida' => 'bg-success', 'cancelada' => 'bg-secondary'];
// Los renglones iniciales: lo sugerido (bajo su mínimo) o uno vacío.
$inicial = array_map(function ($s) {
    $falta = max(1, (float)$s['minimo'] * 2 - (float)$s['existencia']);
    return ['producto_id' => (int)$s['id'], 'cantidad' => round($falta, 3), 'costo' => (float)$s['costo']];
}, $sugerido);
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<details class="lf-alta" id="altaCompra" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?> Nuevo pedido a proveedor</summary>
  <?php if (!$productos): ?>
    <div class="card-body lf-inv-vacio">Ningún producto lleva existencias. En <a href="/servicios">Productos</a>, marca «Lleva existencias» en lo que compras para revender.</div>
  <?php else: ?>
  <form method="post" action="/compras/guardar" class="lf-form" id="formCompra">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="flex:2;min-width:220px">
      <label class="form-label" for="coProv">Proveedor</label>
      <?php if ($proveedores): ?>
        <select class="form-select" name="proveedor_id" id="coProv">
          <option value="">Otro (escríbelo abajo)…</option>
          <?php foreach ($proveedores as $p): ?><option value="<?= (int)$p['id'] ?>"><?= P::e($p['nombre']) ?></option><?php endforeach; ?>
        </select>
        <input class="form-control" name="proveedor_nombre" id="coProvNom" maxlength="200" placeholder="Nombre del proveedor" style="margin-top:8px">
      <?php else: ?>
        <input class="form-control" name="proveedor_nombre" id="coProvNom" maxlength="200" required placeholder="Nombre del proveedor">
      <?php endif; ?>
    </div>
    <?php if ($varias): ?>
    <div style="width:190px">
      <label class="form-label" for="coSuc">Llega a</label>
      <select class="form-select" name="sucursal_id" id="coSuc">
        <?php foreach ($sucursales as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$sucursal ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php else: ?><input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>"><?php endif; ?>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="coNotas">Nota <small>(opcional)</small></label>
      <input class="form-control" name="notas" id="coNotas" maxlength="255" placeholder="Pedido por WhatsApp, llega el jueves…">
    </div>

    <div class="lf-cot-renglones">
      <span class="form-label">Qué se pide
        <a class="lf-co-sug" href="/compras?sugerir=1&amp;s=<?= (int)$sucursal ?>#altaCompra">Llenar con lo que está bajo su mínimo</a></span>
      <table class="lf-cot-tabla">
        <thead><tr><th>Producto</th><th class="text-end">Hay</th><th class="text-end">Cantidad</th><th class="text-end">Costo c/u</th><th class="text-end">Importe</th><th></th></tr></thead>
        <tbody id="coCuerpo"></tbody>
        <tfoot><tr><td colspan="4" class="text-end">Total</td><td class="text-end lf-mono" id="coTotal">$0.00</td><td></td></tr></tfoot>
      </table>
      <button type="button" class="btn btn-secondary btn-sm" id="coMas" style="align-self:flex-start"><?= W::icono('mas','14px') ?> Otro producto</button>
    </div>
    <button class="btn btn-primary" type="submit">Registrar pedido</button>
  </form>
  <template id="coFila">
    <tr>
      <td><select class="form-select form-select-sm" name="producto[]" required>
        <option value="">Elige…</option>
        <?php foreach ($productos as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-costo="<?= P::e((float)$p['costo']) ?>" data-hay="<?= P::e(I::conUnidad($p['existencia'], $p['unidad_medida'])) ?>"><?= P::e($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select></td>
      <td class="text-end lf-mono hay">–</td>
      <td class="text-end"><input class="form-control form-control-sm lf-mono" type="number" name="cantidad[]" step="0.001" min="0.001" required></td>
      <td class="text-end"><input class="form-control form-control-sm lf-mono" type="number" name="costo[]" step="0.01" min="0" placeholder="0.00"></td>
      <td class="text-end lf-mono imp">$0.00</td>
      <td class="text-end"><button type="button" class="lf-btn-ghost" data-quitar aria-label="Quitar">✕</button></td>
    </tr>
  </template>
  <script>
  (function(){
    var cuerpo = document.getElementById('coCuerpo'), tpl = document.getElementById('coFila');
    var INICIAL = <?= json_encode($inicial) ?>;
    function pesos(n){ return '$' + (Math.round(n * 100) / 100).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function sumar(){
      var t = 0;
      cuerpo.querySelectorAll('tr').forEach(function(tr){
        var c = parseFloat(tr.querySelector('[name="cantidad[]"]').value) || 0, k = parseFloat(tr.querySelector('[name="costo[]"]').value) || 0;
        tr.querySelector('.imp').textContent = pesos(c * k); t += c * k;
      });
      document.getElementById('coTotal').textContent = pesos(t);
    }
    function fila(d){
      var tr = tpl.content.firstElementChild.cloneNode(true);
      cuerpo.appendChild(tr);
      if (d) {
        tr.querySelector('select').value = d.producto_id;
        tr.querySelector('[name="cantidad[]"]').value = d.cantidad;
        if (d.costo) tr.querySelector('[name="costo[]"]').value = (+d.costo).toFixed(2);
        elegido(tr);
      }
      return tr;
    }
    function elegido(tr){
      var o = tr.querySelector('select').selectedOptions[0];
      tr.querySelector('.hay').textContent = o && o.value ? o.dataset.hay : '–';
      var k = tr.querySelector('[name="costo[]"]');
      if (o && o.value && !k.value && +o.dataset.costo) k.value = (+o.dataset.costo).toFixed(2);
    }
    cuerpo.addEventListener('change', function(e){ if (e.target.tagName === 'SELECT') elegido(e.target.closest('tr')); sumar(); });
    cuerpo.addEventListener('input', sumar);
    cuerpo.addEventListener('click', function(e){
      if (e.target.closest('[data-quitar]') && cuerpo.children.length > 1) { e.target.closest('tr').remove(); sumar(); }
    });
    document.getElementById('coMas').addEventListener('click', function(){ fila().querySelector('select').focus(); });
    if (INICIAL.length) INICIAL.forEach(fila); else fila();
    sumar();
    /* Proveedor del catálogo u otro escrito. */
    var sel = document.getElementById('coProv'), nom = document.getElementById('coProvNom');
    if (sel) { var ver = function(){ nom.hidden = !!sel.value; nom.required = !sel.value; }; sel.addEventListener('change', ver); ver(); }
  })();
  </script>
  <?php endif; ?>
</details>
<?php endif; ?>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <?php foreach (['' => 'Todas'] + C::ESTADOS as $k => $t): ?>
    <a class="lf-pill <?= $estado === $k ? 'active' : '' ?>" href="/compras<?= $k !== '' ? '?estado=' . $k : '' ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Folio</th><th>Proveedor</th><?php if ($varias): ?><th>Llega a</th><?php endif; ?><th>Estado</th><th class="text-end">Total</th><th></th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="6" class="lf-inv-vacio">Todavía no hay pedidos<?= $estado !== '' ? ' con ese estado' : '' ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $c): ?>
        <tr>
          <td data-label="Folio"><a class="lf-mono" href="/compras/<?= (int)$c['id'] ?>"><b><?= P::e($c['folio']) ?></b></a>
            <small class="lf-inv-cod" style="display:block"><?= P::e(date('d/m/Y', strtotime($c['creado_en']))) ?> · <?= (int)$c['renglones'] ?> productos</small></td>
          <td data-label="Proveedor"><?= P::e($c['proveedor_nombre']) ?></td>
          <?php if ($varias): ?><td data-label="Llega a"><?= P::e($c['sucursal'] ?: '—') ?></td><?php endif; ?>
          <td data-label="Estado"><span class="badge <?= $colores[$c['estado']] ?? 'bg-secondary' ?>"><?= P::e(C::ESTADOS[$c['estado']] ?? $c['estado']) ?></span>
            <?php if ($c['recibida_en']): ?><small class="lf-inv-cod" style="display:block"><?= P::e(date('d/m H:i', strtotime($c['recibida_en']))) ?></small><?php endif; ?></td>
          <td data-label="Total" class="text-end lf-mono"><b><?= D::pesos($c['total']) ?></b></td>
          <td class="lf-inv-acc"><a class="btn btn-<?= $c['estado'] === 'pedida' ? 'primary' : 'secondary' ?> btn-sm" href="/compras/<?= (int)$c['id'] ?>"><?= $c['estado'] === 'pedida' ? 'Recibir' : 'Abrir' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>Un pedido no mueve el inventario hasta que lo <b>recibes</b>. Al recibirlo, cada producto entra
    con su costo y su proveedor (lo verás en <a href="/inventario?t=movimientos">Inventario → Movimientos</a>).</span></div>
</section>
