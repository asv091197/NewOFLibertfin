<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
$r = $resumen;
$pct = ($r['cortes'] ?? 0) > 0 ? round(($r['cuadrados'] ?? 0) / $r['cortes'] * 100) : 0;
?>

<div class="lf-pills" style="margin-bottom:18px">
  <a class="lf-pill" href="/corte">Turno actual</a>
  <a class="lf-pill active" href="/corte?t=historial">Historial</a>
  <?php if (\LibertyFin\Dominio\Permisos::puede('ver.usuarios')): ?>
    <a class="lf-pill" href="/corte?t=cajas">Cajeros</a><?php endif; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('caja','19px') ?></div>
    <div class="stat-value"><?= (int)($r['cortes'] ?? 0) ?></div>
    <div class="stat-label">Cortes cerrados</div>
    <?php W::avance($pct); ?>
    <div class="stat-meta"><?= $pct ?>% cuadraron exacto</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= (int)($r['cuadrados'] ?? 0) ?></div>
    <div class="stat-label">Cuadraron</div>
    <div class="stat-meta">sin un peso de diferencia</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('mas','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['sobrantes'] ?? 0) ?></div>
    <div class="stat-label">Sobrantes acumulados</div>
    <div class="stat-meta">dinero de más en el cajón</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($r['faltantes'] ?? 0) ?></div>
    <div class="stat-label">Faltantes acumulados</div>
    <div class="stat-meta">dinero que no apareció</div>
  </div>
</div>

<section class="card">
  <header class="card-header">Cortes anteriores</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Cierre</th><th>Quién</th><th class="text-end">Fondo</th>
        <th class="text-end">Cobrado</th><th class="text-end">Contado</th>
        <th class="text-end">Diferencia</th><th>Nota</th>
      </tr></thead>
      <tbody>
      <?php if (!$cortes): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Todavía no hay cortes cerrados.</td></tr>
      <?php endif; ?>
      <?php foreach ($cortes as $c):
        $abierta = ($c['estado'] ?? '') === 'abierta';
        $d = (float)($c['diferencia'] ?? 0);
        $cuadro = abs($d) <= 0.009; ?>
        <tr style="<?= $abierta ? 'background:var(--lf-brand-glow)' : '' ?>">
          <td data-label="Cierre" class="lf-mono" style="font-size:12px">
            <?php if ($abierta): ?>
              <span class="badge bg-success">Abierta ahora</span>
            <?php else: ?>
              <?= $c['fecha_cierre'] ? date('d/m/Y H:i', strtotime($c['fecha_cierre'])) : '—' ?>
            <?php endif; ?>
          </td>
          <td data-label="Quién" style="font-size:12.5px"><?= P::e($c['usuario'] ?: '—') ?></td>
          <td data-label="Fondo" class="text-end lf-mono"><?= D::pesos($c['monto_apertura']) ?></td>
          <td data-label="Cobrado" class="text-end lf-mono">
            <?= D::pesos($c['cobrado']) ?>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              <?= D::pesos($c['efectivo']) ?> en efectivo</span>
          </td>
          <td data-label="Contado" class="text-end lf-mono" style="font-weight:700">
            <?= $abierta ? '—' : D::pesos($c['monto_cierre']) ?></td>
          <td data-label="Diferencia" class="text-end">
            <?php if ($abierta): ?>
              <span style="color:var(--lf-tinta-4)">—</span>
            <?php elseif ($cuadro): ?>
              <span class="badge bg-success">Cuadró</span>
            <?php else: ?>
              <span class="badge <?= $d>0?'bg-warning':'bg-danger' ?>">
                <?= $d>0?'Sobró ':'Faltó ' ?><?= D::pesos(abs($d)) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Nota" style="font-size:11.5px;color:var(--lf-tinta-3);max-width:230px">
            <?= P::e($c['observaciones'] ?: '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($cortes) ?> de <?= number_format($totalC) ?> cortes</span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n){ return '?t=historial&p=' . $n; }]); ?>
  </div>
  <div class="card-footer" style="border-top:none;padding-top:0">
    El "cobrado" incluye transferencias y tarjeta; el "contado" es solo efectivo.
    Por eso casi nunca coinciden, y no tienen por qué.
  </div>
</section>