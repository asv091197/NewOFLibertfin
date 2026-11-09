<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\TicketRepo as T;
use LibertyFin\Dominio\Permisos as Perm;

$r  = $resumen;
$cf = $cifras;
$colorP = ['critica'=>'bg-danger','alta'=>'bg-warning','normal'=>'bg-secondary','baja'=>'bg-secondary'];
// Lo que está esperando a alguien, en un solo lugar. Es lo que un turno
// de soporte tiene que vaciar antes de irse.
$cola = array_filter([
  $sinAsignar ? ['Tickets sin dueño',       count($sinAsignar), '/tickets?estado=abierto', 'a'] : null,
  $docs       ? ['Documentos por revisar',  $docs,            '/mantenimiento', 'a'] : null,
  $altas      ? ['Empresas por dar de alta',$altas,           '/mantenimiento', ''] : null,
]);
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($cola): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)">
  <header class="card-header">
    <div><span>Lo que está esperando</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Lo que un turno tiene que vaciar antes de irse</p></div>
  </header>
  <div class="lf-equipo" style="padding:4px 20px 18px">
    <?php foreach ($cola as $c): list($que, $n, $ruta, $tono) = $c; ?>
      <a class="lf-pers" href="<?= P::e($ruta) ?>" style="text-decoration:none">
        <span class="lf-tile <?= $tono ?>" style="width:34px;height:34px;border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono($tono==='r'?'alerta':'reloj','16px') ?></span>
        <div style="flex:1;min-width:0"><b style="white-space:normal"><?= P::e($que) ?></b></div>
        <span class="mn" style="color:<?= $tono==='r'?'var(--lf-rojo)':'var(--lf-amb)' ?>"><?= (int)$n ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:18px">
  <?= W::icono('cobro','18px') ?>
  <span><b>Nada pendiente.</b> No hay tickets sin dueño, ni
    documentos o altas esperando.</span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['activos'] ?? 0) ?></div>
    <div class="stat-label">Tickets abiertos</div>
    <?php W::avance(($cf['total'] ?? 0) > 0
        ? 100 - ($cf['activos'] / max(1,$cf['total']) * 100) : 100); ?>
    <div class="stat-meta"><?= (int)($cf['resueltos'] ?? 0) ?> resueltos en total</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= count($mios) ? '' : 'g' ?>"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= count($mios) ?></div>
    <div class="stat-label">Míos</div>
    <div class="stat-meta">asignados a ti ahora</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?php
      $m = (float)($cf['min_respuesta'] ?? 0);
      echo $m > 0 ? ($m < 60 ? round($m) . ' min' : round($m/60,1) . ' h') : '—'; ?></div>
    <div class="stat-label">Primera respuesta</div>
    <div class="stat-meta">promedio del equipo</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= (int)$r['activas'] ?></div>
    <div class="stat-label">Empresas activas</div>
    <div class="stat-meta">
      <?= (int)$r['por_vencer'] ?> por vencer · <?= (int)$r['vencidas'] ?> vencidas</div>
  </div>
</div>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span><?= $mios ? 'Tus tickets' : 'Tickets activos' ?></span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Por prioridad y luego por antigüedad</p></div>
    </header>
    <div style="padding:0 10px 8px">
      <?php $lista = array_slice($mios ?: $activos, 0, 8); ?>
      <?php if (!$lista): ?>
        <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:28px">
          No hay tickets activos.</p>
      <?php endif; ?>
      <?php foreach ($lista as $t): ?>
        <a class="lf-row" href="/tickets/<?= (int)$t['id'] ?>">
          <span class="lf-av gris" style="flex-shrink:0">
            <?= P::e(mb_strtoupper(mb_substr(T::PRIORIDADES[$t['prioridad']][0] ?? '?', 0, 1))) ?></span>
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13px"><?= P::e($t['asunto']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($t['nombre_empresa'] ?: 'sin empresa') ?> ·
              <?= P::e($t['folio']) ?></small>
          </span>
          <span class="badge <?= $colorP[$t['prioridad']] ?? 'bg-secondary' ?>">
            <?= P::e(T::PRIORIDADES[$t['prioridad']][0] ?? '') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (count($activos) > 8): ?>
      <div class="card-footer"><a href="/tickets">Ver los <?= count($activos) ?> activos</a></div>
    <?php endif; ?>
  </section>

  <div>
    <?php if ($alertas): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Empresas que van a llamar</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Tienen una señal concreta, no están todas</p></div>
      </header>
      <div style="padding:0 10px 8px">
        <?php foreach ($alertas as $a): ?>
          <a class="lf-row" href="/soporte/<?= (int)$a['id'] ?>">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($a['nombre']) ?></b>
              <small style="color:var(--lf-amb);font-size:11.5px">
                <?= P::e(implode(' · ', $a['porque'])) ?></small>
            </span>
            <?= W::icono('venta','15px') ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($categorias): ?>
    <section class="card">
      <header class="card-header">
        <div><span>De qué se queja la gente</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Lo que más se repite se arregla en el producto, no en el ticket</p></div>
      </header>
      <div class="card-body">
        <?php $mx = 0; foreach ($categorias as $c) $mx = max($mx, (int)$c['cuantos']);
        foreach (array_slice($categorias, 0, 6) as $c): ?>
          <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
              <b style="font-weight:600"><?= P::e(T::CATEGORIAS[$c['categoria']] ?? $c['categoria']) ?></b>
              <span class="lf-mono" style="color:var(--lf-tinta-3)"><?= (int)$c['cuantos'] ?></span>
            </div>
            <?php W::avance($mx ? $c['cuantos'] / $mx * 100 : 0, (int)$c['abiertos'] > 0); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
