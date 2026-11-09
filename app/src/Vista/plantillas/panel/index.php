<?php
use LibertyFin\Vista\Plantilla as P;
// Fechas en palabras. Vive aquí porque solo esta vista la usa.
if (!function_exists('lf_cuando')) {
    function lf_cuando($f) {
        $t = strtotime($f); $d = time() - $t;
        if ($d < 3600)  return 'hace ' . max(1, (int)($d/60)) . ' min';
        if ($d < 86400) return 'hace ' . (int)($d/3600) . ' h';
        if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'ayer';
        return date('d M', $t);
    }
}
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$cobrado = (float)($resumen['cobrado'] ?? 0);
$vendido = (float)($resumen['vendido'] ?? 0);
$saldoT  = (float)($resumen['saldo']   ?? 0);
$hoyMonto= (float)($hoy['cobrado'] ?? 0);
$delta   = $promedio > 0 ? round((($hoyMonto - $promedio) / $promedio) * 100) : null;
?>

<div class="lf-sintesis">
  <div class="lf-frase">
    <div style="flex:1;min-width:230px">
      <span class="lf-tag"><i></i>Síntesis del día</span>
      <h2>La cobranza <em><?= P::e($sintesis['tono']) ?></em><?php if(isset($sintesis['pct'])): ?>:
        <?= $sintesis['pct'] ?>% de lo vendido ya entró.<?php endif; ?></h2>
      <p><?= P::e($sintesis['detalle']) ?></p>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:8px">
        El marcador es qué tan al corriente va la cobranza: el porcentaje ya
        cobrado, castigado por la parte del saldo que lleva más de 30 días
        sin un abono.
      </p>
    </div>
    <?php W::marcador($alCorriente, 'al corriente'); ?>
  </div>

  <div class="lf-oscura">
    <div class="wm"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm.9 15.3v1.2h-1.7v-1.2c-1.7-.2-3-1.1-3.4-2.7l1.8-.7c.3 1 1.1 1.6 2.4 1.6 1.2 0 1.9-.5 1.9-1.3 0-.8-.6-1.1-2.2-1.5-2-.5-3.5-1.2-3.5-3.1 0-1.6 1.2-2.6 2.9-2.9V5.5h1.7v1.2c1.6.3 2.6 1.2 3 2.6l-1.8.7c-.3-.9-1-1.4-2-1.4-1.1 0-1.8.5-1.8 1.2 0 .8.7 1 2.3 1.4 2 .5 3.4 1.2 3.4 3.2 0 1.6-1.2 2.7-3 2.9z"/></svg></div>
    <div class="hd">
      <span class="lv"><i></i>Caja de hoy</span>
      <span class="st"><?= date('d M') ?></span>
    </div>
    <div class="big"><?= D::pesos($hoyMonto) ?></div>
    <div class="sm">
      <?php if ((int)($hoy['cobros'] ?? 0) > 0): ?>
        <?= (int)$hoy['cobros'] ?> cobro<?= $hoy['cobros'] == 1 ? '' : 's' ?>
        desde las <?= date('g:i a', strtotime($hoy['primero'])) ?>
      <?php else: ?>
        Todavía no entra nada hoy
      <?php endif; ?>
    </div>
    <div class="ft">
      <div><small>Promedio diario</small>
        <b><?= D::corto($promedio) ?><?php if ($delta !== null): ?>
          <i class="<?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>%</i>
        <?php endif; ?></b></div>
      <div><small>En efectivo</small><b><?= D::corto($hoy['efectivo'] ?? 0) ?></b></div>
    </div>
  </div>
</div>

<div class="lf-trio">
  <div class="lf-tp"><span class="ci"><?= W::icono('cobro','17px') ?></span>
    <span><small>Cobros del día</small><b><?= (int)($hoy['cobros'] ?? 0) ?><em>movimientos</em></b></span></div>
  <div class="lf-tp"><span class="ci g"><?= W::icono('venta','17px') ?></span>
    <span><small>Ventas nuevas</small><b><?= (int)($hoy['ventas_nuevas'] ?? 0) ?><em>hoy</em></b></span></div>
  <a class="lf-tp" href="/ventas"><span class="ci a"><?= W::icono('reloj','17px') ?></span>
    <span><small>Saldo sin movimiento</small><b><?= (int)$viejo['ventas'] ?><em>+30 días</em></b></span></a>
</div>

<div class="lf-stats">
  <?php
  $sc = []; $sv = [];
  foreach ($meses as $m) { $sc[] = $m['cobrado']; $sv[] = $m['vendido']; }
  ?>
  <?php /* LO QUE ENTRÓ, la misma cifra que «Entraron» de Reportes: antes
           esta tarjeta decía "Cobrado en el periodo" pero solo contaba lo de
           las ventas del periodo, y Reportes daba otra cifra. Abajo, de dónde
           vino (las dos partes suman la grande). El avance contra lo vendido
           pasó a "Por cobrar", que es donde se pregunta eso. */ ?>
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($entro['total']) ?></div>
    <div class="stat-label">Entró en el periodo</div>
    <div class="stat-meta">
      <?php if ($entro['anteriores'] > 0): ?>
        <?= D::pesos($entro['nuevas']) ?> de ventas del periodo ·
        <?= D::pesos($entro['anteriores']) ?> de ventas anteriores
      <?php else: ?>
        todo de ventas del periodo
      <?php endif; ?></div>
    <?php W::spark($sc); ?>
  </div>

  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($saldoT) ?></div>
    <div class="stat-label">Por cobrar</div>
    <?php W::avance($avance); ?>
    <div class="stat-meta"><?= (int)($resumen['con_saldo'] ?? 0) ?> ventas con saldo ·
      llevas el <?= $avance ?>% de lo vendido</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value"><?= D::pesos($vendido) ?></div>
    <div class="stat-label">Vendido</div>
    <div class="stat-meta"><?= (int)($resumen['ventas'] ?? 0) ?> ventas · ticket <?= D::corto($resumen['promedio'] ?? 0) ?></div>
    <?php W::spark($sv); ?>
  </div>

  <?php if (\LibertyFin\Dominio\Permisos::puede('ver.comisiones')): ?>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($comisiones['por_pagar']) ?></div>
    <div class="stat-label">Comisiones por pagar</div>
    <?php W::avance(D::pct($comisiones['por_pagar'], max(0.01, $comisiones['total']))); ?>
    <div class="stat-meta">de <?= D::corto($comisiones['total']) ?> generadas</div>
  </div>
  <?php else: ?>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['ventas'] ?? 0) ?></div>
    <div class="stat-label">Ventas del periodo</div>
    <div class="stat-meta">ticket promedio <?= D::corto($resumen['promedio'] ?? 0) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="lf-split" style="margin-top:4px">
  <section class="card">
    <header class="card-header">
      <div><span>Cobrado contra vendido</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          La separación entre las dos líneas es el saldo que se abre</p></div>
    </header>
    <div class="card-body">
      <?php
      $rot = array_map(function($m){ return date('M Y', strtotime($m['mes'].'-01')); }, $meses);
      W::curvas([['datos'=>$sc], ['datos'=>$sv]], $rot);
      ?>
      <?php /* Los importes van en forma MICRO y la rejilla reparte el
               ancho en partes iguales que no pueden crecer. Antes era un
               flex sin `min-width:0` con el importe completo dentro:
               `$181,098` no cabe en un septimo de tarjeta y se encimaba
               con el mes de al lado. El importe exacto sigue a un palmo,
               en el tooltip y en Reportes. */ ?>
      <div class="lf-ejex" style="--n:<?= count($meses) ?>">
        <?php foreach ($meses as $m): ?>
          <div title="<?= P::e(date('F Y', strtotime($m['mes'].'-01'))) ?> · <?= P::e(D::pesos($m['cobrado'])) ?> cobrado">
            <b><?= P::e(date('M', strtotime($m['mes'].'-01'))) ?></b>
            <span class="lf-mono"><?= D::micro($m['cobrado']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="lf-leyenda">
        <span><i class="lf-dot" style="background:var(--lf-brand)"></i>Cobrado</span>
        <span><i class="lf-dot" style="background:var(--lf-pizarra)"></i>Vendido</span>
      </div>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Últimos movimientos</header>
    <div class="card-body">
      <?php if (!$movimientos): ?>
        <p style="color:var(--lf-tinta-4);font-size:13px;text-align:center;padding:16px 0">
          Todavía no hay cobros registrados.</p>
      <?php endif; ?>
      <div class="lf-time">
        <?php foreach ($movimientos as $m):
          $liquidada = ((float)$m['cobrado_total'] >= (float)$m['total'] - 0.01);
          $clase = $liquidada ? '' : ' amb';
        ?>
          <a class="ev<?= $clase ?>" href="/ventas/<?= (int)$m['venta_id'] ?>" data-modal>
            <span style="flex:1;min-width:0">
              <b><?= P::e($m['cliente'] ?: 'Público general') ?>
                 <?= $liquidada ? 'liquidó' : 'abonó' ?></b>
              <small><?= P::e(mb_substr($m['descripcion'] ?: 'Venta', 0, 38)) ?> ·
                <?= P::e(lf_cuando($m['fecha_pago'])) ?></small>
            </span>
            <span class="mn"><?= D::pesos($m['monto']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php
// Inventario: lo que se acabó o está por acabarse. Solo con Inventario
// encendido y para quien puede resurtir (ver PanelControlador::faltantes).
$falta = $faltantes ?? null;
if ($falta && ($falta['agotados'] + $falta['bajos']) > 0): ?>
<a class="alert alert-warning" href="/inventario?bajos=1" style="text-decoration:none">
  <span class="lf-tile a" style="width:34px;height:34px;border-radius:11px;margin:0;font-size:16px;flex-shrink:0">
    <?= W::icono('bolsa','17px') ?></span>
  <span>
    <b style="display:block;font-size:13.5px;margin-bottom:2px">
      <?php
        $partes = [];
        if ($falta['agotados']) $partes[] = $falta['agotados'] . ' agotado' . ($falta['agotados'] === 1 ? '' : 's');
        if ($falta['bajos'])    $partes[] = $falta['bajos'] . ' bajo su mínimo';
        echo P::e(implode(' y ', $partes));
      ?></b>
    <span style="font-size:12.5px;color:var(--lf-tinta-2)">
      Hay que resurtir. Ver cuáles en Inventario.</span>
  </span>
</a>
<?php endif; ?>

<?php
// El aviso de comisiones sin dueño solo lo ve quien puede entrar a
// resolverlo. A un cajero le decía un importe que no le toca saber y lo
// mandaba a una pantalla donde igual iba a recibir un 403.
if ((float)$comisiones['sin_asignar'] > 0
    && \LibertyFin\Dominio\Permisos::puede('ver.comisiones')): ?>
<a class="alert alert-warning" href="/comisiones" style="text-decoration:none">
  <span class="lf-tile a" style="width:34px;height:34px;border-radius:11px;margin:0;font-size:16px;flex-shrink:0">
    <?= W::icono('alerta','17px') ?></span>
  <span>
    <b style="display:block;font-size:13.5px;margin-bottom:2px">
      <?= D::pesos($comisiones['sin_asignar']) ?> de comisión sin dueño</b>
    <span style="font-size:12.5px;color:var(--lf-tinta-2)">
      En <?= (int)$comisiones['ventas_pendientes'] ?> ventas falta confirmar quién vendió.
      Cuenta para el total, pero no se le puede pagar a nadie.</span>
  </span>
</a>
<?php endif; ?>

