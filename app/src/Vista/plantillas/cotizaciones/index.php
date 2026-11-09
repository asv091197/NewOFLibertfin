<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Cotizaciones as C;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$colores = ['borrador' => 'bg-secondary', 'enviada' => 'bg-info', 'aceptada' => 'bg-success', 'rechazada' => 'bg-danger'];
$filtrosEstado = ['' => 'Todas', 'abiertas' => 'Abiertas', 'vencida' => 'Vencidas'] + C::ESTADOS;
// Los renglones del formulario: los de la que se edita, o uno vacío.
$renglonesForm = $edit ? array_map(function ($l) {
    return ['producto_id' => (int)$l['producto_id'], 'nombre' => $l['nombre'], 'cantidad' => (float)$l['cantidad'], 'precio' => (float)$l['precio']];
}, $edit['lineas']) : [];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('doc','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['abiertas']) ?></div>
    <div class="stat-label">Abiertas</div>
    <div class="stat-meta"><?= D::pesos($resumen['monto_abierto']) ?> por cerrar</div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['aceptadas_mes']) ?></div>
    <div class="stat-label">Aceptadas este mes</div>
    <div class="stat-meta"><?= D::pesos($resumen['monto_aceptado_mes']) ?> vendidos</div></div>
  <a class="stat-card" href="/cotizaciones?estado=vencida"><div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['vencidas']) ?></div>
    <div class="stat-label">Vencidas</div>
    <div class="stat-meta">ya pasó su vigencia</div></a>
</div>

<?php if ($puedeEditar): ?>
<details class="lf-alta" id="altaCot" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($edit ? 'doc' : 'mas','16px') ?>
    <?= $edit ? 'Editar la cotización ' . P::e($edit['folio']) : 'Nueva cotización' ?></summary>
  <form method="post" action="/cotizaciones/guardar" class="lf-form lf-cot-form" id="formCot">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <input type="hidden" name="lineas" id="cotLineas" value="[]">

    <div style="flex:2;min-width:240px">
      <?php P::parcial('parciales/elegir-cliente', [
          'pref' => 'cot', 'etiqueta' => 'Para quién',
          'idVal' => $edit ? (string)($edit['cliente_id'] ?? '') : (string)($clientePre['id'] ?? ''),
          'nomVal' => $edit ? $edit['cliente_nombre'] : ($clientePre['nombre'] ?? ''),
      ]); ?>
    </div>
    <div style="width:170px">
      <label class="form-label" for="cotVig">Vigente hasta</label>
      <input class="form-control" type="date" name="vigencia" id="cotVig"
             value="<?= P::e($edit['vigencia'] ?? date('Y-m-d', strtotime('+' . C::VIGENCIA_DIAS . ' days'))) ?>">
    </div>

    <div class="lf-cot-renglones">
      <span class="form-label">Conceptos</span>
      <div class="lf-cot-agregar">
        <select class="form-select" id="cotProd" aria-label="Agregar del catálogo">
          <option value="">Agregar del catálogo…</option>
          <?php foreach ($catalogo as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-precio="<?= P::e((float)$p['precio']) ?>"><?= P::e($p['nombre']) ?><?= (float)$p['precio'] > 0 ? ' · ' . D::pesos($p['precio']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary" id="cotLibre"><?= W::icono('mas','14px') ?> Concepto libre</button>
      </div>
      <table class="lf-cot-tabla">
        <thead><tr><th>Concepto</th><th class="text-end">Cant.</th><th class="text-end">Precio c/u</th><th class="text-end">Importe</th><th></th></tr></thead>
        <tbody id="cotCuerpo"></tbody>
        <tfoot><tr><td colspan="3" class="text-end">Total</td><td class="text-end lf-mono" id="cotTotal">$0.00</td><td></td></tr></tfoot>
      </table>
      <p class="pista" id="cotVacio">Agrega lo que vas a cotizar: del catálogo (con su precio) o un concepto libre.</p>
    </div>

    <div style="flex:0 0 100%">
      <label class="form-label" for="cotNotas">Notas para el cliente <small>(opcional)</small></label>
      <textarea class="form-control" name="notas" id="cotNotas" rows="2" maxlength="2000"
                placeholder="Condiciones, tiempos de entrega, forma de pago…"><?= P::e($edit['notas'] ?? '') ?></textarea>
    </div>
    <div style="flex-direction:row;gap:10px">
      <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear cotización' ?></button>
      <?php if ($edit): ?><a class="btn btn-secondary" href="/cotizaciones/<?= (int)$edit['id'] ?>">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>
<?php endif; ?>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <?php foreach ($filtrosEstado as $k => $t): ?>
    <a class="lf-pill <?= $estado === $k ? 'active' : '' ?>" href="/cotizaciones<?= $k !== '' ? '?estado=' . $k : '' ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/cotizaciones">
  <?php if ($estado !== ''): ?><input type="hidden" name="estado" value="<?= P::e($estado) ?>"><?php endif; ?>
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Folio o cliente"></div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Folio</th><th>Cliente</th><th>Estado</th><th>Vigencia</th><th class="text-end">Total</th><th></th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="6" class="lf-inv-vacio">
          <?= $buscar !== '' || $estado !== '' ? 'Nada coincide con el filtro.' : 'Todavía no hay cotizaciones. Crea la primera arriba.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $c): ?>
        <tr>
          <td data-label="Folio"><a class="lf-mono" href="/cotizaciones/<?= (int)$c['id'] ?>"><b><?= P::e($c['folio']) ?></b></a>
            <small class="lf-inv-cod" style="display:block"><?= P::e(date('d/m/Y', strtotime($c['creado_en']))) ?> · <?= (int)$c['renglones'] ?> conceptos</small></td>
          <td data-label="Cliente"><?= P::e($c['cliente_nombre']) ?></td>
          <td data-label="Estado">
            <?php if ($c['vencida']): ?><span class="badge bg-warning">Vencida</span>
            <?php else: ?><span class="badge <?= $colores[$c['estado']] ?? 'bg-secondary' ?>"><?= P::e(C::ESTADOS[$c['estado']] ?? $c['estado']) ?></span><?php endif; ?>
            <?php if ($c['codigo_venta']): ?><small class="lf-inv-cod" style="display:block">Venta <?= P::e($c['codigo_venta']) ?></small><?php endif; ?>
          </td>
          <td data-label="Vigencia" class="lf-mono" style="font-size:12.5px"><?= $c['vigencia'] ? P::e(date('d/m/Y', strtotime($c['vigencia']))) : '—' ?></td>
          <td data-label="Total" class="text-end lf-mono"><b><?= D::pesos($c['total']) ?></b></td>
          <td class="lf-inv-acc"><a class="btn btn-secondary btn-sm" href="/cotizaciones/<?= (int)$c['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($puedeEditar): ?>
<script>
(function(){
  var f = document.getElementById('formCot');
  if (!f) return;
  var R = <?= json_encode($renglonesForm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var cuerpo = document.getElementById('cotCuerpo');
  function esc(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function pesos(n){ return '$' + (Math.round(n * 100) / 100).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function pintar(){
    cuerpo.innerHTML = '';
    var total = 0;
    R.forEach(function(l, i){
      var imp = (+l.cantidad || 0) * (+l.precio || 0); total += imp;
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><input class="form-control form-control-sm" data-k="nombre" data-i="' + i + '" maxlength="255" value="' + esc(l.nombre) + '"'
        + (l.producto_id ? ' title="Del catálogo; puedes cambiar cómo se lee"' : ' placeholder="Describe el concepto"') + ' required></td>'
        + '<td class="text-end"><input class="form-control form-control-sm lf-mono" type="number" step="0.001" min="0.001" data-k="cantidad" data-i="' + i + '" value="' + l.cantidad + '"></td>'
        + '<td class="text-end"><input class="form-control form-control-sm lf-mono" type="number" step="0.01" min="0" data-k="precio" data-i="' + i + '" value="' + (+l.precio).toFixed(2) + '"></td>'
        + '<td class="text-end lf-mono">' + pesos(imp) + '</td>'
        + '<td class="text-end"><button type="button" class="lf-btn-ghost" data-quitar="' + i + '" aria-label="Quitar">✕</button></td>';
      cuerpo.appendChild(tr);
    });
    document.getElementById('cotTotal').textContent = pesos(total);
    document.getElementById('cotVacio').hidden = R.length > 0;
    document.getElementById('cotLineas').value = JSON.stringify(R);
  }
  cuerpo.addEventListener('input', function(e){
    var el = e.target, i = +el.dataset.i, k = el.dataset.k;
    if (!k || !R[i]) return;
    R[i][k] = k === 'nombre' ? el.value : (parseFloat(el.value) || 0);
    var tr = el.closest('tr'), imp = (+R[i].cantidad || 0) * (+R[i].precio || 0);
    tr.children[3].textContent = pesos(imp);
    var total = R.reduce(function(s, l){ return s + (+l.cantidad || 0) * (+l.precio || 0); }, 0);
    document.getElementById('cotTotal').textContent = pesos(total);
    document.getElementById('cotLineas').value = JSON.stringify(R);
  });
  cuerpo.addEventListener('click', function(e){
    var b = e.target.closest('[data-quitar]');
    if (b) { R.splice(+b.dataset.quitar, 1); pintar(); }
  });
  document.getElementById('cotProd').addEventListener('change', function(){
    var o = this.options[this.selectedIndex];
    if (!o.value) return;
    var ya = R.filter(function(l){ return l.producto_id === +o.value; })[0];
    if (ya) ya.cantidad = (+ya.cantidad || 0) + 1;
    else R.push({producto_id: +o.value, nombre: o.text.replace(/ · \$[\d,.]+$/, ''), cantidad: 1, precio: parseFloat(o.dataset.precio) || 0});
    this.value = '';
    pintar();
  });
  document.getElementById('cotLibre').addEventListener('click', function(){
    R.push({producto_id: 0, nombre: '', cantidad: 1, precio: 0});
    pintar();
    var ins = cuerpo.querySelectorAll('input[data-k="nombre"]');
    if (ins.length) ins[ins.length - 1].focus();
  });
  f.addEventListener('submit', function(e){
    if (!R.length) { e.preventDefault(); alert('Agrega al menos un concepto.'); }
  });
  pintar();
})();
</script>
<?php endif; ?>
