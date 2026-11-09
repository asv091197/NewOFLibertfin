<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$c = $cli;
$wa = null;
$num = preg_replace('/\D/', '', (string)$c['telefono']);
if (strlen($num) >= 10) $wa = 'https://wa.me/' . (strlen($num) === 10 ? '52' . $num : $num);
$iniciales = function ($n) {
    $p = preg_split('/\s+/', trim((string)$n));
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
};
$peso = function ($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; };
$estCita = \LibertyFin\Servicio\Agenda::ESTADOS;
$estCot = \LibertyFin\Servicio\Cotizaciones::ESTADOS;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<section class="card lf-cf-cab">
  <div class="card-body">
    <span class="lf-av"><?= P::e($iniciales($c['nombre'])) ?></span>
    <div class="quien">
      <h3><?= P::e($c['nombre']) ?></h3>
      <span>
        <?php if ($c['telefono']): ?><?= P::e($c['telefono']) ?><?php endif; ?>
        <?php if ($c['email']): ?> · <?= P::e($c['email']) ?><?php endif; ?>
        <?php if ($c['rfc']): ?> · RFC <?= P::e($c['rfc']) ?><?php endif; ?>
        <?php if (!$c['telefono'] && !$c['email'] && !$c['rfc']): ?>Sin datos de contacto<?php endif; ?>
      </span>
    </div>
    <div class="acc">
      <a class="btn btn-secondary btn-sm" href="/clientes?editar=<?= (int)$c['id'] ?>">Editar datos</a>
      <?php if ($wa): ?><a class="btn btn-secondary btn-sm" href="<?= P::e($wa) ?>" target="_blank" rel="noopener"><?= W::icono('chat','14px') ?> WhatsApp</a><?php endif; ?>
      <?php if (!empty($mods['agenda'])): ?><a class="btn btn-secondary btn-sm" href="/agenda?nueva=1#altaCita">Agendar cita</a><?php endif; ?>
      <?php if (!empty($mods['cotizaciones'])): ?><a class="btn btn-secondary btn-sm" href="/cotizaciones?nueva=1&amp;cliente=<?= (int)$c['id'] ?>#altaCot">Cotizar</a><?php endif; ?>
      <?php if ($puedeCobrar): ?><a class="btn btn-primary btn-sm" href="/caja?cliente=<?= (int)$c['id'] ?>"><?= W::icono('caja','14px') ?> Nueva venta</a><?php endif; ?>
    </div>
  </div>
</section>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= number_format($numeros['visitas']) ?></div><div class="stat-label">Compras</div>
    <div class="stat-meta"><?= $numeros['ultima'] ? 'última: ' . P::e(date('d/m/Y', strtotime($numeros['ultima']))) : 'todavía ninguna' ?></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($numeros['gastado']) ?></div><div class="stat-label">Le ha comprado al negocio</div>
    <div class="stat-meta"><?= $numeros['visitas'] ? 'promedio ' . D::pesos($numeros['gastado'] / $numeros['visitas']) : '—' ?></div></div>
  <div class="stat-card"><div class="lf-tile <?= $numeros['saldo'] > 0 ? 'a' : 'g' ?>"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($numeros['saldo']) ?></div><div class="stat-label">Debe</div>
    <div class="stat-meta"><?= $numeros['saldo'] > 0 ? '<a href="/cobranza?q=' . rawurlencode($c['nombre']) . '">ver en Cobranza</a>' : 'al corriente' ?></div></div>
</div>

<div class="lf-cf-cols">
  <div class="lf-cf-main">

    <?php if ($conExp): ?>
    <section class="card">
      <div class="card-header"><span><h3 class="card-title">Ficha</h3>
        <small class="lf-inv-sub">Lo que hay que saber siempre: alergias, preferencias, régimen fiscal…</small></span></div>
      <?php if ($puedeExp): ?>
        <form method="post" action="/clientes/<?= (int)$c['id'] ?>/ficha" class="lf-cf-ficha">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <textarea class="form-control" name="texto" rows="4" maxlength="8000" placeholder="Escribe aquí lo importante de este cliente."><?= P::e($ficha['texto']) ?></textarea>
          <div><small><?= $ficha['actualizado_en'] ? 'Cambió ' . P::e($ficha['actualizado_por'] ?: '') . ' el ' . P::e(date('d/m/Y H:i', strtotime($ficha['actualizado_en']))) : '' ?></small>
            <button class="btn btn-primary btn-sm" type="submit">Guardar ficha</button></div>
        </form>
      <?php else: ?>
        <div class="card-body"><?= $ficha['texto'] !== '' ? nl2br(P::e($ficha['texto'])) : '<span class="lf-inv-cod">Sin ficha.</span>' ?></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-header"><span><h3 class="card-title">Notas</h3></span></div>
      <?php if ($puedeExp): ?>
        <form method="post" action="/clientes/<?= (int)$c['id'] ?>/nota" class="lf-cf-nota">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <textarea class="form-control" name="texto" rows="2" maxlength="4000" required placeholder="Qué se hizo hoy, qué quedó pendiente…"></textarea>
          <button class="btn btn-secondary btn-sm" type="submit">Agregar nota</button>
        </form>
      <?php endif; ?>
      <?php if (!$notas): ?><div class="card-body lf-inv-cod">Todavía no hay notas.</div><?php endif; ?>
      <ul class="lf-cf-notas">
        <?php foreach ($notas as $n): ?>
          <li><p><?= nl2br(P::e($n['texto'])) ?></p>
            <small><?= P::e(date('d/m/Y H:i', strtotime($n['creado_en']))) ?><?= $n['usuario_nombre'] ? ' · ' . P::e($n['usuario_nombre']) : '' ?>
              <?php if ($puedeExp): ?>
              <form method="post" action="/clientes/<?= (int)$c['id'] ?>/nota/<?= (int)$n['id'] ?>/borrar" style="display:inline" onsubmit="return confirm('¿Borrar la nota?')">
                <input type="hidden" name="token" value="<?= P::e($token) ?>"><button type="submit" class="lf-cf-borrar">Borrar</button></form>
              <?php endif; ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><span><h3 class="card-title">Últimas compras</h3></span></div>
      <div class="table-responsive lf-cards" style="padding:0 12px 6px">
        <table class="table table-hover">
          <thead><tr><th>Fecha</th><th>Qué</th><th class="text-end">Total</th><th class="text-end">Debe</th></tr></thead>
          <tbody>
          <?php if (!$ventas): ?><tr><td colspan="4" class="lf-inv-vacio">Todavía no le has vendido nada.</td></tr><?php endif; ?>
          <?php foreach ($ventas as $v): $canc = $v['estado'] === 'cancelada'; ?>
            <tr class="<?= $canc ? 'lf-apagado' : '' ?>">
              <td data-label="Fecha"><a class="lf-mono" href="/ventas/<?= (int)$v['id'] ?>"><?= P::e(date('d/m/Y', strtotime($v['fecha']))) ?></a>
                <?= $canc ? '<span class="badge bg-danger">Cancelada</span>' : '' ?></td>
              <td data-label="Qué"><?= P::e($v['conceptos'] ?: '—') ?><?= $v['especialista_nombre'] ? '<small class="lf-inv-cod" style="display:block">' . P::e($v['especialista_nombre']) . '</small>' : '' ?></td>
              <td data-label="Total" class="text-end lf-mono"><?= D::pesos($v['total']) ?></td>
              <td data-label="Debe" class="text-end lf-mono"><?= !$canc && (float)$v['saldo'] > 0.005 ? '<b style="color:var(--lf-amb)">' . D::pesos($v['saldo']) . '</b>' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="lf-cf-lado">
    <?php if ($paquetes !== null): ?>
    <section class="card"><div class="card-header"><span><h3 class="card-title">Paquetes</h3></span></div>
      <?php if (!$paquetes): ?><div class="card-body lf-inv-cod">No tiene paquetes de sesiones.</div><?php endif; ?>
      <ul class="lf-cf-lista">
        <?php foreach ($paquetes as $p): $q = (int)$p['quedan']; ?>
          <li><b><?= P::e($p['servicio'] ?: $p['paquete']) ?></b>
            <span class="lf-cf-sesiones"><i style="width:<?= (int)round(100 * (int)$p['usadas'] / max(1, (int)$p['total'])) ?>%"></i></span>
            <small><?= $q > 0 ? 'Le quedan <b>' . $q . '</b> de ' . (int)$p['total'] : 'Terminado (' . (int)$p['total'] . ' sesiones)' ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($citas !== null): ?>
    <section class="card"><div class="card-header"><span><h3 class="card-title">Citas</h3></span></div>
      <?php if (!$citas): ?><div class="card-body lf-inv-cod">Sin citas.</div><?php endif; ?>
      <ul class="lf-cf-lista">
        <?php foreach ($citas as $ci): $futura = $ci['inicio'] >= date('Y-m-d H:i:s'); ?>
          <li class="<?= $futura ? 'futura' : '' ?>"><a href="/agenda?dia=<?= P::e(substr($ci['inicio'], 0, 10)) ?>"><b><?= P::e(date('d/m/Y H:i', strtotime($ci['inicio']))) ?></b></a>
            <small><?= P::e($ci['servicio'] ?: 'Sin servicio') ?> · <?= P::e($estCita[$ci['estado']] ?? $ci['estado']) ?><?= $ci['especialista'] ? ' · ' . P::e($ci['especialista']) : '' ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($igualas !== null): ?>
    <section class="card"><div class="card-header"><span><h3 class="card-title">Igualas</h3></span>
      <a class="btn btn-secondary btn-sm" href="/igualas?nueva=1&amp;cliente=<?= (int)$c['id'] ?>#altaIguala">Nueva</a></div>
      <?php if (!$igualas): ?><div class="card-body lf-inv-cod">Sin igualas.</div><?php endif; ?>
      <ul class="lf-cf-lista">
        <?php foreach ($igualas as $ig): ?>
          <li><b><?= P::e($ig['concepto'] ?: $ig['producto']) ?></b>
            <small><?= D::pesos($ig['monto']) ?> al mes, día <?= (int)$ig['dia'] ?> · <?= (int)$ig['activo'] ? 'activa' : 'en pausa' ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($cotizaciones !== null): ?>
    <section class="card"><div class="card-header"><span><h3 class="card-title">Cotizaciones</h3></span></div>
      <?php if (!$cotizaciones): ?><div class="card-body lf-inv-cod">Sin cotizaciones.</div><?php endif; ?>
      <ul class="lf-cf-lista">
        <?php foreach ($cotizaciones as $co): ?>
          <li><a class="lf-mono" href="/cotizaciones/<?= (int)$co['id'] ?>"><b><?= P::e($co['folio']) ?></b></a>
            <small><?= D::pesos($co['total']) ?> · <?= $co['vencida'] ? 'vencida' : P::e(mb_strtolower($estCot[$co['estado']] ?? $co['estado'])) ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($conExp): ?>
    <section class="card"><div class="card-header"><span><h3 class="card-title">Archivos</h3>
      <small class="lf-inv-sub">Fotos y PDF. Solo los ve tu equipo.</small></span></div>
      <?php if ($puedeExp): ?>
        <form method="post" action="/clientes/<?= (int)$c['id'] ?>/archivo" enctype="multipart/form-data" class="lf-cf-subir">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <input class="form-control form-control-sm" type="file" name="archivo" accept="image/jpeg,image/png,image/webp,application/pdf" required>
          <button class="btn btn-secondary btn-sm" type="submit">Subir</button>
        </form>
      <?php endif; ?>
      <?php if (!$archivos): ?><div class="card-body lf-inv-cod">Sin archivos.</div><?php endif; ?>
      <ul class="lf-cf-lista">
        <?php foreach ($archivos as $a): ?>
          <li><a href="/clientes/<?= (int)$c['id'] ?>/archivo/<?= (int)$a['id'] ?>" target="_blank" rel="noopener"><b><?= P::e($a['nombre']) ?></b></a>
            <small><?= $a['mime'] === 'application/pdf' ? 'PDF' : 'Imagen' ?> · <?= $peso((int)$a['tamano']) ?> · <?= P::e(date('d/m/Y', strtotime($a['creado_en']))) ?>
              <?php if ($puedeExp): ?>
              <form method="post" action="/clientes/<?= (int)$c['id'] ?>/archivo/<?= (int)$a['id'] ?>/borrar" style="display:inline" onsubmit="return confirm('¿Borrar el archivo?')">
                <input type="hidden" name="token" value="<?= P::e($token) ?>"><button type="submit" class="lf-cf-borrar">Borrar</button></form>
              <?php endif; ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php elseif (empty($mods['expediente']) && \LibertyFin\Dominio\Permisos::puede('editar.ajustes')): ?>
    <section class="card"><div class="card-body lf-inv-cod" style="font-size:12.5px;line-height:1.55">
      ¿Quieres guardar notas, una ficha y archivos de cada cliente? Enciende <b>Expediente de clientes</b> en <a href="/ajustes?t=modulos">Ajustes → Módulos</a>.
    </div></section>
    <?php endif; ?>
  </aside>
</div>
