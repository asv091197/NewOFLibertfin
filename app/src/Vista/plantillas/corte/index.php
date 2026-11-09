<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$fondo = $caja ? (float)$caja['monto_apertura'] : 0;
$efe   = (float)($mov['efectivo'] ?? 0);
$esperado = $fondo + $efe;
?>

<div class="lf-pills" style="margin-bottom:18px">
  <a class="lf-pill active" href="/corte">Turno actual</a>
  <a class="lf-pill" href="/corte?t=historial">Historial</a>
  <?php if (\LibertyFin\Dominio\Permisos::puede('ver.usuarios')): ?>
    <a class="lf-pill" href="/corte?t=cajas">Cajeros</a><?php endif; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$caja): ?>
  <section class="card" style="max-width:460px;margin:0 auto">
    <header class="card-header">Abrir caja</header>
    <div class="card-body">
      <p style="font-size:13px;color:var(--lf-tinta-3);margin-bottom:18px">
        Anota con cuánto efectivo empiezas. Al cerrar se compara contra eso.
      </p>
      <form method="post" action="/corte/abrir">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <label class="form-label" for="monto">Fondo inicial</label>
        <input class="form-control lf-mono" type="number" id="monto" name="monto"
               step="0.01" min="0" value="0.00" style="font-size:19px;font-weight:700" required>
        <label class="form-label" for="nota" style="margin-top:14px">Nota</label>
        <input class="form-control" type="text" id="nota" name="nota" placeholder="Opcional">
        <button class="btn btn-primary" type="submit"
                style="width:100%;margin-top:18px;padding:12px">Abrir caja</button>
      </form>
    </div>
  </section>

<?php else: ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('caja','19px') ?></div>
    <div class="stat-value"><?= D::pesos($esperado) ?></div>
    <div class="stat-label">Debe haber en el cajón</div>
    <div class="stat-meta">fondo <?= D::corto($fondo) ?> + efectivo <?= D::corto($efe) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($mov['total'] ?? 0) ?></div>
    <div class="stat-label">Cobrado en el turno</div>
    <div class="stat-meta"><?= (int)($mov['cobros'] ?? 0) ?> cobros ·
      <?= (int)($mov['ventas'] ?? 0) ?> ventas</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($mov['transferencia'] ?? 0) ?></div>
    <div class="stat-label">Transferencia</div>
    <div class="stat-meta">no pasa por el cajón</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($mov['tarjeta'] ?? 0) ?></div>
    <div class="stat-label">Tarjeta</div>
    <div class="stat-meta">no pasa por el cajón</div>
  </div>
</div>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Cobros del turno</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Del más reciente al primero</p></div>
      <span class="badge bg-secondary"><?= number_format($totalC) ?></span>
    </header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Hora</th><th>Cliente</th><th>Método</th><th class="text-end">Monto</th></tr></thead>
        <tbody>
        <?php if (!$cobros): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--lf-tinta-4);padding:30px">
            Todavía no entra nada en este turno.</td></tr>
        <?php endif; ?>
        <?php foreach ($cobros as $c): ?>
          <tr>
            <td data-label="Hora" class="lf-mono" style="font-size:12px">
              <?= date('H:i', strtotime($c['fecha_pago'])) ?></td>
            <td data-label="Cliente">
              <b style="font-weight:600"><?= P::e($c['cliente'] ?: 'Público general') ?></b>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                <?= P::e($c['codigo_venta']) ?> · <?= P::e($c['tipo']) ?></span>
            </td>
            <td data-label="Método">
              <span class="badge <?= $c['metodo_pago']==='efectivo'?'bg-success':'bg-secondary' ?>">
                <?= P::e($c['metodo_pago']) ?></span></td>
            <td data-label="Monto" class="text-end lf-mono" style="font-weight:700">
              <?= D::pesos($c['monto']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($cobros) ?> de <?= number_format($totalC) ?></span>
      <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
        'enlace'=>function($n){ return '?p=' . $n; }]); ?>
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Cerrar caja</header>
      <div class="card-body">
        <div class="lf-sumas" style="padding:0;border:none;margin-bottom:16px">
          <div><span>Fondo inicial</span><span class="lf-mono"><?= D::pesos($fondo) ?></span></div>
          <div><span>Cobrado en efectivo</span><span class="lf-mono">+<?= D::pesos($efe) ?></span></div>
          <div class="tt"><span>Debe haber</span><span><?= D::pesos($esperado) ?></span></div>
        </div>

        <form method="post" action="/corte/cerrar" id="formCierre">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <input type="hidden" name="esperado" value="<?= $esperado ?>">
          <label class="form-label" for="contado">Cuánto hay realmente</label>
          <input class="form-control lf-mono" type="number" id="contado" name="contado"
                 step="0.01" min="0" placeholder="0.00"
                 style="font-size:19px;font-weight:700" required>

          <div id="dif" hidden style="margin-top:12px;padding:11px 13px;border-radius:var(--lf-r);font-size:13px"></div>

          <label class="form-label" for="nota" style="margin-top:14px">Nota</label>
          <input class="form-control" type="text" id="nota" name="nota"
                 placeholder="Obligatoria si hay diferencia">

          <button class="btn btn-primary" type="submit"
                  style="width:100%;margin-top:18px;padding:12px">Cerrar caja</button>
        </form>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.5">
          Solo se cuenta el efectivo. Transferencias y tarjeta no pasan por el cajón:
          sumarlas al corte es la forma más común de cuadrar una caja que no cuadra.
        </p>
      </div>
    </section>

    <?php if ($historial): ?>
    <section class="card">
      <header class="card-header">Cortes anteriores</header>
      <div style="padding:0 10px 8px">
        <?php foreach ($historial as $h): $d = (float)$h['diferencia']; ?>
          <div class="lf-row">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px">
                <?= $h['fecha_cierre'] ? date('d M · H:i', strtotime($h['fecha_cierre'])) : '—' ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($h['usuario'] ?: '') ?></small>
            </span>
            <span style="text-align:right">
              <b class="lf-mono" style="font-size:13px"><?= D::pesos($h['monto_cierre']) ?></b>
              <small style="display:block;font-size:11px;
                     color:<?= abs($d)<=0.009 ? 'var(--lf-tinta-4)' : ($d>0?'var(--lf-brand-2)':'var(--lf-rojo)') ?>">
                <?= abs($d)<=0.009 ? 'cuadró' : ($d>0?'sobró ':'faltó ') . D::pesos(abs($d)) ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var esperado = <?= json_encode((float)$esperado) ?>;
  var c = document.getElementById('contado'), d = document.getElementById('dif'),
      n = document.getElementById('nota');
  if (!c) return;
  var pesos = function(v){ return '$' + Math.abs(v).toLocaleString('es-MX',
                 {minimumFractionDigits:2, maximumFractionDigits:2}); };
  c.addEventListener('input', function(){
    var v = parseFloat(c.value);
    if (isNaN(v)) { d.hidden = true; n.placeholder = 'Obligatoria si hay diferencia'; return; }
    var dif = Math.round((v - esperado) * 100) / 100;
    d.hidden = false;
    if (Math.abs(dif) < 0.005) {
      d.style.background = 'var(--lf-brand-soft)'; d.style.color = 'var(--lf-brand-2)';
      d.textContent = 'Cuadra exacto.';
      n.placeholder = 'Opcional';
    } else {
      var sobra = dif > 0;
      d.style.background = sobra ? 'var(--lf-amb-soft)' : 'var(--lf-rojo-soft)';
      d.style.color = sobra ? 'var(--lf-amb)' : 'var(--lf-rojo)';
      d.textContent = (sobra ? 'Sobran ' : 'Faltan ') + pesos(dif) + '. Escribe a qué se debe.';
      n.placeholder = 'Obligatoria: hay diferencia';
    }
  });
})();
</script>

<?php endif; ?>