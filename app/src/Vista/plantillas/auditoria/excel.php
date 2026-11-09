<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\CompararExcel as C;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$R = $resultado;
$revisar = $R ? $R['revisar'] : ($filtros['revisar'] ?: array_keys(C::REVISIONES));
$clase = ['alta' => 'bg-danger', 'media' => 'bg-warning', 'baja' => 'bg-secondary'];
$TOPE = 500;   // en pantalla; el CSV trae todo

// Los filtros actuales, para el CSV y para no perderlos.
$qs = function (array $c = []) use ($filtros) {
    $b = array_filter([
        'revisar' => $filtros['revisar'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
        'area' => $filtros['area'], 'tol' => $filtros['tol'] !== '0.05' ? $filtros['tol'] : null,
        'hojas' => $filtros['hojas'],
    ]);
    return '?' . http_build_query(array_merge($b, $c));
};
$cifra = function ($v, $unidad) {
    if ($v === null || $v === '') return '–';
    return ($unidad === 'pesos' && is_numeric($v)) ? D::pesos($v) : P::e($v);
};
$hace = function ($t) {
    $m = (int)floor((time() - (int)$t) / 60);
    return $m < 1 ? 'hace un momento' : ($m < 60 ? 'hace ' . $m . ' min' : 'hace ' . floor($m / 60) . ' h');
};

/* Las casillas de "qué revisar", por gravedad. Se usan al subir y al filtrar. */
$casillas = function (array $marcadas) {
    $grupos = ['alta' => 'Dinero', 'media' => 'Montos secundarios', 'baja' => 'Datos'];
    foreach ($grupos as $sev => $rot) {
        echo '<div class="lf-aud-grupo"><small>' . P::e($rot) . '</small><div class="lf-promo-chips">';
        foreach (C::REVISIONES as $k => $r) {
            if ($r['severidad'] !== $sev) continue;
            echo '<label title="' . P::e($r['ayuda']) . '"><input type="checkbox" name="revisar[]" value="' . P::e($k) . '"'
               . (in_array($k, $marcadas, true) ? ' checked' : '') . '>' . P::e($r['titulo']) . '</label>';
        }
        echo '</div></div>';
    }
};
?>

<?php P::parcial('auditoria/_pestanas', ['actual' => 'excel']); ?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php /* ═══ SUBIR LA HOJA ═══ */ ?>
<details class="lf-alta" <?= $archivo ? '' : 'open' ?> id="altaExcel">
  <summary>
    <?= W::icono($archivo ? 'baja' : 'mas','16px') ?>
    <?= $archivo ? 'Comparar otra hoja' : 'Subir la hoja de Excel para comparar' ?>
  </summary>
  <form method="post" action="/auditoria/excel" enctype="multipart/form-data" class="lf-form lf-aud-subir">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="flex:1;min-width:260px">
      <label class="form-label">Archivo <small>(.xlsx o .csv, hasta <?= (int)$maxMb ?> MB)</small></label>
      <span class="lf-file">
        <input type="file" name="hoja" id="inpHoja" required
               accept="<?= $puedeXlsx ? '.xlsx,.xlsm,' : '' ?>.csv,.txt">
        <label class="bt" for="inpHoja">Elegir archivo</label>
        <span class="n" data-vacio="Ningún archivo elegido">Ningún archivo elegido</span>
      </span>
    </div>
    <div style="width:100%">
      <span class="form-label">Qué revisar</span>
      <?php $casillas($revisar); ?>
    </div>
    <div>
      <span class="form-label">Diferencias menores a</span>
      <div class="lf-segmento" role="radiogroup">
        <?php foreach (['0.05' => '5 centavos', '1' => '$1', '10' => '$10'] as $v => $t): ?>
          <label><input type="radio" name="tol" value="<?= $v ?>" <?= $filtros['tol'] === $v ? 'checked' : '' ?>><?= $t ?></label>
        <?php endforeach; ?>
      </div>
      <small class="lf-promo-ayuda" style="display:block;margin-top:4px">se ignoran (redondeos).</small>
    </div>
    <div><button class="btn btn-primary" type="submit"><?= W::icono('buscar','15px') ?>Comparar</button></div>
    <p class="lf-promo-ayuda" style="width:100%;margin:0">
      La misma hoja de ventas que se usa en Importar: se reconocen las columnas por su nombre
      (FOLIO, FECHA, CLIENTE, ÁREA, SERVICIO, COSTO TOTAL DE VENTA, ANTICIPO, IVA, VENDEDOR y sus
      comisiones). Se compara folio por folio contra lo capturado. <b>No cambia nada</b> en el
      sistema: para meter ventas está Importar.
    </p>
  </form>
</details>

<?php if ($archivo): ?>
<div class="lf-aud-archivo">
  <span class="ic"><?= W::icono('baja','17px') ?></span>
  <span style="flex:1;min-width:0">
    <b><?= P::e($archivo['nombre']) ?></b>
    <small>Subido <?= P::e($hace($archivo['cuando'] ?? time())) ?>
      <?php if ($R && $R['hojas']): ?> · hojas: <?= P::e(implode(', ', $R['hojas'])) ?><?php endif; ?>
      · se guarda unas horas para filtrar sin volver a subirlo</small>
  </span>
  <a class="btn btn-secondary btn-sm" href="/auditoria/excel/quitar"
     onclick="return confirm('¿Quitar el archivo? Para volver a comparar tendrás que subirlo otra vez.')">Quitar archivo</a>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($error) ?></span>
</div>
<?php endif; ?>

<?php if ($R): ?>
<?php /* ═══ FILTROS ═══ */ ?>
<form class="card lf-aud-filtros" method="get" action="/auditoria/excel">
  <div class="lf-form" style="padding:16px 20px">
    <div>
      <label class="form-label" for="fDesde">Ventas desde</label>
      <input class="form-control form-control-sm" type="date" name="desde" id="fDesde" value="<?= P::e($filtros['desde'] ?: $R['desde']) ?>">
    </div>
    <div>
      <label class="form-label" for="fHasta">hasta</label>
      <input class="form-control form-control-sm" type="date" name="hasta" id="fHasta" value="<?= P::e($filtros['hasta'] ?: $R['hasta']) ?>">
    </div>
    <div>
      <label class="form-label" for="fArea">Área</label>
      <select class="form-select form-select-sm" name="area" id="fArea">
        <option value="">Todas</option>
        <?php foreach ($R['areas'] as $a): ?>
          <option value="<?= P::e($a) ?>" <?= $filtros['area'] === $a ? 'selected' : '' ?>><?= P::e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label" for="fTol">Ignorar menos de</label>
      <select class="form-select form-select-sm" name="tol" id="fTol">
        <?php foreach (['0.05' => '5 centavos', '1' => '$1', '10' => '$10'] as $v => $t): ?>
          <option value="<?= $v ?>" <?= $filtros['tol'] === $v ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (count($R['todasHojas']) > 1): ?>
    <div>
      <span class="form-label">Hojas</span>
      <div class="lf-promo-chips">
        <?php foreach ($R['todasHojas'] as $hj): ?>
          <label><input type="checkbox" name="hojas[]" value="<?= P::e($hj) ?>"
                 <?= (!$filtros['hojas'] || in_array($hj, $filtros['hojas'], true)) ? 'checked' : '' ?>><?= P::e($hj) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div style="width:100%">
      <span class="form-label">Qué revisar</span>
      <?php $casillas($revisar); ?>
    </div>
    <div style="display:flex;flex-direction:row;gap:8px">
      <button class="btn btn-primary btn-sm" type="submit">Volver a comparar</button>
      <a class="btn btn-secondary btn-sm" href="/auditoria/excel">Quitar filtros</a>
    </div>
  </div>
</form>

<?php /* ═══ EL RESUMEN ═══ */ ?>
<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= number_format($R['folios']) ?></div>
    <div class="stat-label">Ventas en la hoja</div>
    <div class="stat-meta"><?= P::e(date('d/m/Y', strtotime($R['desde'])) . ' – ' . date('d/m/Y', strtotime($R['hasta']))) ?></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= number_format($R['coinciden']) ?></div>
    <div class="stat-label">Coinciden</div>
    <div class="stat-meta">de <?= number_format($R['enSistema']) ?> que están en el sistema</div></div>
  <div class="stat-card<?= $R['conDif'] ? ' lf-aud-mal' : '' ?>"><div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= number_format($R['conDif']) ?></div>
    <div class="stat-label">Con diferencias</div>
    <div class="stat-meta"><?= number_format(count($R['hallazgos'])) ?> diferencia<?= count($R['hallazgos']) === 1 ? '' : 's' ?> en total</div></div>
</div>

<?php /* ═══ LAS DIFERENCIAS ═══ */ ?>
<section class="card" id="audExcel">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><span>Diferencias</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Lo que la hoja y el sistema dicen distinto. Solo se señala: nada se corrige solo.</p></div>
    <?php if ($R['hallazgos']): ?>
      <a class="btn btn-secondary btn-sm" href="<?= P::e($qs(['formato' => 'csv'])) ?>" data-completo>Descargar CSV</a>
    <?php endif; ?>
  </header>

  <?php if ($R['hallazgos']): ?>
  <div class="lf-pills" style="padding:0 20px 12px">
    <button type="button" class="lf-pill active" data-aud="">Todas <span class="n"><?= count($R['hallazgos']) ?></span></button>
    <?php foreach ($R['resumen'] as $k => $n): if (!$n) continue; ?>
      <button type="button" class="lf-pill" data-aud="<?= P::e($k) ?>"><?= P::e(C::REVISIONES[$k]['titulo']) ?>
        <span class="n"><?= (int)$n ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-promo-tabla lf-aud-tabla">
      <thead><tr>
        <th>Gravedad</th><th>Qué</th><th>Folio</th><th>Fecha</th><th>Cliente</th>
        <th class="text-end">En la hoja</th><th class="text-end">En el sistema</th><th>Detalle</th>
      </tr></thead>
      <tbody>
      <?php if (!$R['hallazgos']): ?>
        <tr><td colspan="8" style="text-align:center;padding:34px">
          <b style="display:block;color:var(--lf-brand-2);margin-bottom:4px">Todo coincide.</b>
          <span style="color:var(--lf-tinta-4)">Con lo que marcaste, la hoja y el sistema dicen lo mismo.</span></td></tr>
      <?php endif; ?>
      <?php foreach (array_slice($R['hallazgos'], 0, $TOPE) as $h): ?>
        <tr data-rev="<?= P::e($h['revision']) ?>">
          <td data-label="Gravedad"><span class="badge <?= $clase[$h['severidad']] ?>"><?= P::e(C::SEVERIDADES[$h['severidad']]) ?></span></td>
          <td data-label="Qué"><b style="font-weight:600"><?= P::e($h['titulo']) ?></b></td>
          <td data-label="Folio" class="lf-mono">
            <?php if ($h['venta_id']): ?>
              <a class="lf-folio" href="/ventas/<?= (int)$h['venta_id'] ?>" data-modal><?= P::e($h['folio']) ?></a>
            <?php else: ?><?= P::e($h['folio'] ?: '–') ?><?php endif; ?></td>
          <td data-label="Fecha"><?= $h['fecha'] ? P::e(date('d/m/Y', strtotime($h['fecha']))) : '–' ?></td>
          <td data-label="Cliente"><?= P::e($h['cliente'] ?: '–') ?></td>
          <?php $mono = $h['unidad'] === 'pesos' ? ' lf-mono' : ''; ?>
          <td data-label="En la hoja" class="text-end<?= $mono ?>"><?= $cifra($h['excel'], $h['unidad']) ?></td>
          <td data-label="En el sistema" class="text-end<?= $mono ?>"><?= $cifra($h['sistema'], $h['unidad']) ?></td>
          <td data-label="Detalle" style="font-size:12px;color:var(--lf-tinta-3)">
            <?php $d = (is_numeric($h['excel']) && is_numeric($h['sistema'])) ? (float)$h['excel'] - (float)$h['sistema'] : 0;
              if ($h['unidad'] === 'pesos' && abs($d) >= 0.005): ?>
              <b class="lf-mono" style="color:var(--lf-tinta-2)"><?= $d > 0 ? '+' : '−' ?><?= D::pesos(abs($d)) ?></b>
              <?= $d > 0 ? 'más en la hoja' : 'más en el sistema' ?><?= $h['nota'] ? ' · ' : '' ?>
            <?php endif; ?><?= P::e($h['nota']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($R['hallazgos']) > $TOPE): ?>
    <p style="font-size:12px;color:var(--lf-tinta-4);padding:0 20px 14px">
      Se muestran <?= $TOPE ?> de <?= number_format(count($R['hallazgos'])) ?>; el CSV las trae todas.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function(){
  /* Filtrar por tipo de diferencia sin volver a comparar. */
  var caja = document.getElementById('audExcel');
  if (caja) caja.addEventListener('click', function(e){
    var b = e.target.closest('.lf-pill[data-aud]');
    if (!b) return;
    caja.querySelectorAll('.lf-pill[data-aud]').forEach(function(x){ x.classList.toggle('active', x === b); });
    var k = b.dataset.aud;
    caja.querySelectorAll('tbody tr[data-rev]').forEach(function(tr){
      tr.hidden = k !== '' && tr.dataset.rev !== k;
    });
  });
  /* El nombre del archivo elegido, junto al botón. */
  var i = document.getElementById('inpHoja');
  if (i) i.addEventListener('change', function(){
    var n = i.closest('.lf-file').querySelector('.n');
    n.textContent = i.files && i.files[0] ? i.files[0].name : n.dataset.vacio;
  });
})();
</script>
