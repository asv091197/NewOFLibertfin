<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$activas = array_filter($igualas, function ($i) { return (int)$i['activo'] === 1; });
$e = $edit;
$nombreCliente = '';
if ($e) foreach ($clientes as $c) if ((int)$c['id'] === (int)$e['cliente_id']) { $nombreCliente = $c['nombre']; break; }
$meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$mes = function ($p) use ($meses) { return $meses[(int)substr($p, 5, 2)] . ' ' . substr($p, 0, 4); };
$fecha = function ($d) use ($meses) { return $d ? (int)substr($d, 8, 2) . ' ' . $meses[(int)substr($d, 5, 2)] : '—'; };
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('repetir','19px') ?></div>
    <div class="stat-value"><?= count($activas) ?></div>
    <div class="stat-label">Igualas activas</div>
    <div class="stat-meta"><?= count($igualas) - count($activas) ?> en pausa</div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($mensual) ?></div>
    <div class="stat-label">Al mes</div>
    <div class="stat-meta">lo que se genera cada mes</div></div>
</div>

<?php if ($puedeEditar): ?>
<details class="lf-alta" id="altaIguala" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e ? 'repetir' : 'mas','16px') ?> <?= $e ? 'Editar la iguala' : 'Nueva iguala' ?></summary>
  <form method="post" action="/igualas/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:230px">
      <?php P::parcial('parciales/elegir-cliente', ['pref' => 'ig', 'etiqueta' => 'Cliente', 'exigir' => true,
            'idVal' => $e ? (string)$e['cliente_id'] : (string)($clientePre['id'] ?? ''),
            'nomVal' => $e ? $nombreCliente : ($clientePre['nombre'] ?? '')]); ?>
    </div>
    <div style="flex:2;min-width:220px">
      <label class="form-label" for="igServ">Servicio que se cobra</label>
      <select class="form-select" name="producto_id" id="igServ" required>
        <option value="">Elige…</option>
        <?php foreach ($servicios as $s): ?>
          <option value="<?= (int)$s['id'] ?>" data-precio="<?= P::e((float)$s['precio']) ?>" <?= $e && (int)$e['producto_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:140px">
      <label class="form-label" for="igMonto">Monto al mes</label>
      <input class="form-control lf-mono" type="number" step="0.01" min="0.01" name="monto" id="igMonto" required
             value="<?= $e ? P::e((float)$e['monto']) : '' ?>" placeholder="0.00">
    </div>
    <div style="width:110px">
      <label class="form-label" for="igDia">Día de cobro</label>
      <input class="form-control lf-mono" type="number" min="1" max="28" name="dia" id="igDia" required value="<?= P::e($e['dia'] ?? 1) ?>">
    </div>
    <div style="width:150px">
      <label class="form-label" for="igDesde">Empieza en</label>
      <input class="form-control" type="month" name="desde" id="igDesde" value="<?= P::e($e['desde'] ?? date('Y-m')) ?>">
    </div>
    <div style="width:190px">
      <label class="form-label" for="igEsp">Quién la atiende <small>(comisión)</small></label>
      <select class="form-select" name="especialista_id" id="igEsp">
        <option value="">Sin asignar</option>
        <?php foreach ($especialistas as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $e && (int)$e['especialista_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="igConcepto">Concepto <small>(opcional)</small></label>
      <input class="form-control" name="concepto" id="igConcepto" maxlength="200" value="<?= P::e($e['concepto'] ?? '') ?>" placeholder="Contabilidad mensual, RIF…">
    </div>
    <div style="flex-direction:row;gap:10px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar cambios' : 'Crear iguala' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/igualas">Cancelar</a><?php endif; ?>
    </div>
    <p class="pista" style="flex:0 0 100%;margin:0">Cada mes, al llegar el día de cobro, se genera sola una venta sin anticipo para este cliente:
      la verás en Cobranza con su saldo. Del 1 al 28, para que todos los meses lo tengan.</p>
  </form>
</details>
<?php endif; ?>

<section class="card">
  <div class="card-header"><span><h3 class="card-title">Igualas</h3></span>
    <?php if ($puedeEditar && $activas): ?>
    <form method="post" action="/igualas/generar" style="margin:0">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-secondary btn-sm" type="submit" title="Se revisa sola una vez al día">Revisar cargos ahora</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Cliente</th><th>Servicio</th><th class="text-end">Al mes</th><th>Día</th><th>Siguiente cargo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$igualas): ?>
        <tr><td colspan="7" class="lf-inv-vacio">Todavía no hay igualas. Crea la primera arriba: un cliente, un servicio y cuánto paga al mes.</td></tr>
      <?php endif; ?>
      <?php foreach ($igualas as $i): $on = (int)$i['activo'] === 1; ?>
        <tr class="<?= $on ? '' : 'lf-apagado' ?>">
          <td data-label="Cliente"><a href="/clientes/<?= (int)$i['cliente_id'] ?>"><b><?= P::e($i['cliente'] ?: '(cliente borrado)') ?></b></a>
            <?php if ($i['concepto']): ?><small class="lf-inv-cod" style="display:block"><?= P::e($i['concepto']) ?></small><?php endif; ?></td>
          <td data-label="Servicio"><?= P::e($i['producto'] ?: '—') ?><?= $i['especialista'] ? '<small class="lf-inv-cod" style="display:block">' . P::e($i['especialista']) . '</small>' : '' ?></td>
          <td data-label="Al mes" class="text-end lf-mono"><b><?= D::pesos($i['monto']) ?></b></td>
          <td data-label="Día" class="lf-mono"><?= (int)$i['dia'] ?></td>
          <td data-label="Siguiente"><?= $on ? P::e($fecha($i['siguiente'])) : '—' ?>
            <?php if ($i['ultimo']): ?><small class="lf-inv-cod" style="display:block">último: <?= P::e($mes($i['ultimo'])) ?></small><?php endif; ?></td>
          <td data-label="Estado"><span class="badge <?= $on ? 'bg-success' : 'bg-secondary' ?>"><?= $on ? 'Activa' : 'En pausa' ?></span></td>
          <td class="lf-inv-acc">
            <?php if ($puedeEditar): ?>
              <a class="btn btn-secondary btn-sm" href="/igualas?editar=<?= (int)$i['id'] ?>#altaIguala">Editar</a>
              <form method="post" action="/igualas/alternar" style="display:inline">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button class="btn btn-secondary btn-sm" type="submit"><?= $on ? 'Pausar' : 'Reanudar' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($historial): ?>
<section class="card">
  <div class="card-header"><span><h3 class="card-title">Cargos generados</h3>
    <small class="lf-inv-sub">Cada mes de cada iguala, con su venta. Se cobran en Cobranza.</small></span></div>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Mes</th><th>Cliente</th><th>Venta</th><th class="text-end">Total</th><th class="text-end">Falta cobrar</th></tr></thead>
      <tbody>
      <?php foreach ($historial as $h): $debe = (float)$h['saldo'] > 0.005 && $h['estado'] !== 'cancelada'; ?>
        <tr>
          <td data-label="Mes"><?= P::e($mes($h['periodo'])) ?></td>
          <td data-label="Cliente"><?= P::e($h['cliente'] ?: '—') ?></td>
          <td data-label="Venta"><?= $h['venta_id'] ? '<a class="lf-mono" href="/ventas/' . (int)$h['venta_id'] . '">' . P::e($h['codigo_venta']) . '</a>' : '—' ?>
            <?= $h['estado'] === 'cancelada' ? '<span class="badge bg-danger">Cancelada</span>' : '' ?></td>
          <td data-label="Total" class="text-end lf-mono"><?= D::pesos($h['total']) ?></td>
          <td data-label="Falta" class="text-end"><?= $debe ? '<b class="lf-mono" style="color:var(--lf-amb)">' . D::pesos($h['saldo']) . '</b>' : '<span class="badge bg-success">Pagado</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script>
(function(){
  /* Al elegir el servicio, su precio como monto sugerido (si no se escribió otro). */
  var s = document.getElementById('igServ'), m = document.getElementById('igMonto');
  if (s && m) s.addEventListener('change', function(){
    var o = s.options[s.selectedIndex];
    if (o && o.dataset.precio && !m.value) m.value = parseFloat(o.dataset.precio).toFixed(2);
  });
})();
</script>
