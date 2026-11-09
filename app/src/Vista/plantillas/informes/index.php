<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\TicketRepo as T;
$r = $resumen; $cf = $cifras;
?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)$r['activas'] ?></div>
    <div class="stat-label">Empresas activas</div>
    <?php W::avance($r['empresas'] ? $r['activas'] / $r['empresas'] * 100 : 0); ?>
    <div class="stat-meta">de <?= (int)$r['empresas'] ?> registradas</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= $dormidas ? 'a' : '' ?>"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= (int)$dormidas ?></div>
    <div class="stat-label">Sin vender hace un mes</div>
    <div class="stat-meta">tienen cuenta y no la usan</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)$r['vencidas'] ?></div>
    <div class="stat-label">Suscripción vencida</div>
    <div class="stat-meta"><?= (int)$r['por_vencer'] ?> vencen en 15 días</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['total'] ?? 0) ?></div>
    <div class="stat-label">Tickets en total</div>
    <div class="stat-meta"><?= (int)($cf['activos'] ?? 0) ?> siguen abiertos</div>
  </div>
</div>

<?php if (!$conPulso): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Son demasiadas empresas para consultar sus cifras en vivo. Las secciones de
    uso y esquema quedan vacías; el resto sigue siendo correcto.</span>
</div>
<?php endif; ?>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>De qué se queja la gente</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Un problema en treinta tickets se arregla una vez en el producto</p></div>
    </header>
    <div class="card-body">
      <?php if (!$categorias): ?>
        <p style="font-size:12.5px;color:var(--lf-tinta-4);margin:0">Todavía no hay tickets.</p>
      <?php endif; ?>
      <?php $mx = 0; foreach ($categorias as $c) $mx = max($mx, (int)$c['cuantos']);
      foreach ($categorias as $c): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
            <b style="font-weight:600"><?= P::e(T::CATEGORIAS[$c['categoria']] ?? $c['categoria']) ?></b>
            <span class="lf-mono" style="color:var(--lf-tinta-3)"><?= (int)$c['cuantos'] ?></span>
          </div>
          <?php W::avance($mx ? $c['cuantos'] / $mx * 100 : 0, (int)$c['abiertos'] > 0); ?>
          <?php if ($c['horas']): ?>
            <small style="font-size:10.5px;color:var(--lf-tinta-4)">
              se resuelven en <?= round($c['horas'],1) ?> h · <?= (int)$c['abiertos'] ?> abiertos</small>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">
        <div><span>Cómo va el equipo</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Se cuenta lo resuelto, no lo asignado</p></div>
      </header>
      <div class="table-responsive lf-cards" style="padding:0 12px 6px">
        <table class="table">
          <thead><tr><th>Agente</th><th class="text-end">Resueltos</th>
            <th class="text-end">Abiertos</th><th class="text-end">Respuesta</th></tr></thead>
          <tbody>
          <?php if (!$agentes): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--lf-tinta-4);padding:24px">
              Sin datos.</td></tr>
          <?php endif; ?>
          <?php foreach ($agentes as $a): ?>
            <tr>
              <td data-label="Agente"><b style="font-weight:600"><?= P::e($a['nombre']) ?></b></td>
              <td data-label="Resueltos" class="text-end lf-mono"><?= (int)$a['resueltos'] ?></td>
              <td data-label="Abiertos" class="text-end lf-mono"><?= (int)$a['activos'] ?></td>
              <td data-label="Respuesta" class="text-end lf-mono" style="font-size:12px">
                <?= $a['promedio'] === null ? '—'
                    : ($a['promedio'] < 60 ? round($a['promedio']) . ' min'
                                           : round($a['promedio']/60,1) . ' h') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        Acumular tickets sin cerrarlos no es trabajo hecho. Medir por asignación
        premiaría justamente eso.
      </div>
    </section>

    <?php if ($esquemas): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Versión del esquema</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Las atrasadas fallan en funciones nuevas sin explicación</p></div>
      </header>
      <div class="card-body">
        <?php $tot = array_sum($esquemas);
        foreach ($esquemas as $v => $n): $alDia = $v >= $ultima; ?>
          <div style="margin-bottom:11px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
              <b style="font-weight:600">Versión <?= (int)$v ?>
                <?= $alDia ? '' : '<span style="color:var(--lf-amb);font-weight:400">· atrasada</span>' ?></b>
              <span class="lf-mono" style="color:var(--lf-tinta-3)"><?= (int)$n ?></span>
            </div>
            <?php W::avance($tot ? $n / $tot * 100 : 0, !$alDia); ?>
          </div>
        <?php endforeach; ?>
        <?php if ($sinResponder): ?>
          <p style="font-size:11.5px;color:var(--lf-rojo);margin-top:12px">
            <?= (int)$sinResponder ?> base<?= $sinResponder==1?'':'s' ?> no respondió.</p>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
