<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Trabajos as T;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$total = array_sum(array_map('count', $tablero));
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get" action="/seguimiento">
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($filtros['q']) ?>" placeholder="Folio, cliente o descripción"></div>
  <?php if ($equipo): ?>
  <select class="form-select form-select-sm" name="resp" style="width:auto" aria-label="Responsable" onchange="this.form.submit()">
    <option value="0">Todo el equipo</option>
    <?php foreach ($equipo as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= (int)$filtros['responsable'] === (int)$p['id'] ? 'selected' : '' ?>><?= P::e($p['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<?php if (!$total): ?>
<section class="card"><div class="card-body lf-inv-vacio">
  <?= $filtros['q'] !== '' || $filtros['responsable'] ? 'Nada coincide con el filtro.'
      : 'Aquí aparece cada venta desde ' . P::e(date('d/m/Y', strtotime($filtros['desde']))) . ' (cuando se encendió el módulo), como un trabajo «Pendiente».' ?>
</div></section>
<?php endif; ?>

<div class="lf-tab-tablero">
<?php foreach (T::ESTADOS as $k => $titulo): ?>
  <section class="lf-tab-col t-<?= $k ?>">
    <header><b><?= P::e($titulo) ?></b><span><?= count($tablero[$k]) ?></span></header>
    <?php if (!$tablero[$k]): ?><p class="lf-tab-vacio">Nada aquí.</p><?php endif; ?>
    <?php foreach ($tablero[$k] as $v): ?>
      <article class="lf-tab-tarjeta<?= $v['atrasado'] ? ' atrasado' : '' ?>">
        <div class="cab">
          <a class="lf-mono" href="/ventas/<?= (int)$v['id'] ?>"><?= P::e($v['codigo_venta']) ?></a>
          <?php if ($v['entrega']): ?><span class="entrega<?= $v['atrasado'] ? ' mal' : '' ?>" title="Entrega"><?= $v['atrasado'] ? 'Atrasado · ' : '' ?><?= P::e(date('d/m', strtotime($v['entrega']))) ?></span><?php endif; ?>
        </div>
        <b class="cli"><?= $v['cliente_id'] ? '<a href="/clientes/' . (int)$v['cliente_id'] . '">' . P::e($v['cliente']) . '</a>' : P::e($v['cliente'] ?: 'Sin cliente') ?></b>
        <small class="que"><?= P::e($v['conceptos'] ?: ($v['descripcion'] ?: '—')) ?></small>
        <?php if ($v['nota']): ?><small class="nota">“<?= P::e($v['nota']) ?>”</small><?php endif; ?>
        <div class="pie">
          <span class="lf-mono"><?= D::pesos($v['total']) ?><?= (float)$v['saldo'] > 0.005 ? ' · <em>debe ' . D::pesos($v['saldo']) . '</em>' : '' ?></span>
          <span><?= P::e($v['responsable'] ?: ($v['especialista_nombre'] ?: '')) ?></span>
        </div>
        <?php if ($puedeEditar): ?>
        <form method="post" action="/seguimiento/guardar" class="lf-tab-mover">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <input type="hidden" name="venta_id" value="<?= (int)$v['id'] ?>">
          <select name="estado" class="form-select form-select-sm" aria-label="Estado" onchange="this.form.submit()">
            <?php foreach (T::ESTADOS as $ek => $et): ?><option value="<?= $ek ?>" <?= $ek === $k ? 'selected' : '' ?>><?= P::e($et) ?></option><?php endforeach; ?>
          </select>
          <details>
            <summary>Detalles</summary>
            <label>Responsable
              <select name="responsable_id" class="form-select form-select-sm">
                <option value="">—</option>
                <?php foreach ($equipo as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$v['responsable_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= P::e($p['nombre']) ?></option><?php endforeach; ?>
              </select></label>
            <label>Entrega <input type="date" name="entrega" class="form-control form-control-sm" value="<?= P::e($v['entrega'] ?? '') ?>"></label>
            <label>Nota <input name="nota" class="form-control form-control-sm" maxlength="255" value="<?= P::e($v['nota'] ?? '') ?>" placeholder="Falta la constancia…"></label>
            <button class="btn btn-primary btn-sm" type="submit">Guardar</button>
          </details>
        </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
</div>
<p class="pista" style="margin-top:12px">Los entregados se quedan aquí <?= T::DIAS_ENTREGADOS ?> días y luego se archivan. Las ventas canceladas no aparecen.</p>
