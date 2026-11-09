<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$r = $resumen;
$qs = function (array $x = []) use ($tramo,$orden,$buscar,$vista) {
    return '?' . http_build_query(array_merge(
        ['tramo'=>$tramo,'orden'=>$orden,'q'=>$buscar,'ver'=>$vista], $x)); };
$ini = function ($n) { $p = preg_split('/\s+/', trim($n ?: '?'));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : '')); };
// El color dice la urgencia, no el monto.
$tono = function ($d) { return $d > 60 ? 'r' : ($d > 30 ? 'a' : ''); };
?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['total'] ?? 0) ?></div>
    <div class="stat-label">Por cobrar</div>
    <div class="stat-meta"><?= (int)($r['ventas'] ?? 0) ?> ventas ·
      <?= (int)($r['clientes'] ?? 0) ?> clientes</div>
  </div>
  <a class="stat-card" href="<?= P::e($qs(['tramo'=>'t30'])) ?>" style="text-decoration:none;color:inherit">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['t30'] ?? 0) ?></div>
    <div class="stat-label">1 a 30 días</div>
    <div class="stat-meta">fresco, se cobra solo</div>
  </a>
  <a class="stat-card" href="<?= P::e($qs(['tramo'=>'t60'])) ?>" style="text-decoration:none;color:inherit">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['t60'] ?? 0) ?></div>
    <div class="stat-label">31 a 60 días</div>
    <div class="stat-meta">hay que recordarles</div>
  </a>
  <a class="stat-card" href="<?= P::e($qs(['tramo'=>'t60mas'])) ?>" style="text-decoration:none;color:inherit">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['t60mas'] ?? 0) ?></div>
    <div class="stat-label">Más de 60 días</div>
    <div class="stat-meta"><?= (int)($r['n60mas'] ?? 0) ?> ventas · hablar hoy</div>
  </a>
</div>

<form class="lf-filtros" method="get">
  <input type="hidden" name="ver" value="<?= P::e($vista) ?>">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Cliente o folio">
  </div>
  <select class="form-select form-select-sm" name="tramo" style="width:auto">
    <option value="todos"  <?= $tramo==='todos'?'selected':'' ?>>Toda la antigüedad</option>
    <option value="t30"    <?= $tramo==='t30'?'selected':'' ?>>1 a 30 días</option>
    <option value="t60"    <?= $tramo==='t60'?'selected':'' ?>>31 a 60 días</option>
    <option value="t60mas" <?= $tramo==='t60mas'?'selected':'' ?>>Más de 60 días</option>
  </select>
  <select class="form-select form-select-sm" name="orden" style="width:auto">
    <option value="dias"    <?= $orden==='dias'?'selected':'' ?>>Lo más viejo primero</option>
    <option value="saldo"   <?= $orden==='saldo'?'selected':'' ?>>Lo más grande primero</option>
    <option value="cliente" <?= $orden==='cliente'?'selected':'' ?>>Por cliente</option>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <div class="lf-pills" style="margin-left:auto">
    <a class="lf-pill <?= $vista==='ventas'?'active':'' ?>" href="<?= P::e($qs(['ver'=>'ventas'])) ?>">Por venta</a>
    <a class="lf-pill <?= $vista==='clientes'?'active':'' ?>" href="<?= P::e($qs(['ver'=>'clientes'])) ?>">Por cliente</a>
  </div>
</form>

<?php if ($vista === 'clientes'): ?>
<section class="card">
  <header class="card-header">Con quién hay que sentarse</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Cliente</th><th>Teléfono</th><th class="text-end">Ventas</th>
        <th class="text-end">Debe</th><th class="text-end">Sin abonar</th></tr></thead>
      <tbody>
      <?php if (!$clientes): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Nadie debe nada. Todo liquidado.</td></tr>
      <?php endif; ?>
      <?php foreach ($clientes as $c): ?>
        <tr>
          <td data-label="Cliente">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-av gris" style="width:30px;height:30px;font-size:11px">
                <?= P::e($ini($c['cliente'])) ?></span>
              <b style="font-weight:600"><?= P::e($c['cliente'] ?: 'Público general') ?></b>
            </span>
          </td>
          <td data-label="Teléfono">
            <?php if ($c['telefono']): ?>
              <a class="lf-mono" style="font-size:12.5px" href="tel:<?= P::e($c['telefono']) ?>">
                <?= P::e($c['telefono']) ?></a>
            <?php else: ?><span style="color:var(--lf-tinta-4)">—</span><?php endif; ?>
          </td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$c['ventas'] ?></td>
          <td data-label="Debe" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($c['saldo']) ?></td>
          <td data-label="Sin abonar" class="text-end">
            <span class="badge <?= $c['dias']>60?'bg-danger':($c['dias']>30?'bg-warning':'bg-secondary') ?>">
              <?= (int)$c['dias'] ?> días</span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($clientes) ?> de <?= (int)($resumen['clientes'] ?? 0) ?> clientes</span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
</section>

<?php else: ?>
<section class="card">
  <header class="card-header">Ventas con saldo</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Cliente</th><th class="text-end">Venta</th><th class="text-end">Cobrado</th>
        <th>Avance</th><th class="text-end">Debe</th><th class="text-end">Sin abonar</th>
      </tr></thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          <?= $buscar || $tramo!=='todos' ? 'Nada con esos filtros.' : 'Nadie debe nada. Todo liquidado.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): $t = $tono($f['dias']); ?>
        <tr>
          <td data-label="Cliente">
            <a data-lf-fila href="/ventas/<?= (int)$f['id'] ?>" data-modal style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($f['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($f['codigo_venta']) ?> · <?= date('d M Y', strtotime($f['fecha'])) ?>
              <?= $f['abonos'] > 0 ? ' · ' . (int)$f['abonos'] . ' abono' . ($f['abonos']==1?'':'s') : ' · sin abonos' ?>
            </span>
          </td>
          <td data-label="Venta" class="text-end lf-mono"><?= D::pesos($f['total']) ?></td>
          <td data-label="Cobrado" class="text-end lf-mono"><?= D::pesos($f['cobrado']) ?></td>
          <td data-label="Avance"><?php W::avanceMini($f['cobrado'], $f['total']); ?></td>
          <td data-label="Debe" class="text-end lf-mono" style="font-weight:700;
              color:<?= $t==='r'?'var(--lf-rojo)':($t==='a'?'var(--lf-amb)':'var(--lf-tinta)') ?>">
            <?= D::pesos($f['saldo']) ?></td>
          <td data-label="Sin abonar" class="text-end">
            <span class="badge <?= $t==='r'?'bg-danger':($t==='a'?'bg-warning':'bg-secondary') ?>">
              <?= (int)$f['dias'] ?> días</span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($filas) ?> de <?= number_format($total) ?> ventas con saldo</span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
  <div class="card-footer" style="border-top:none;padding-top:0">
    Los días se cuentan desde el último abono, no desde la venta. Una venta de
    hace seis meses con un abono ayer está al corriente; una de hace dos meses
    sin tocar, no.
  </div>
</section>
<?php endif; ?>
