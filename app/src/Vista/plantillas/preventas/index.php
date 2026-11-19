<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Inventario as I;
use LibertyFin\Servicio\Preventas as PV;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$colores = ['pendiente' => 'bg-warning', 'entregada' => 'bg-success', 'cancelada' => 'bg-secondary'];
$filtros = ['pendiente' => 'Por entregar', 'entregada' => 'Entregadas', 'cancelada' => 'Canceladas', '' => 'Todas'];
$volver  = '/preventas?' . http_build_query(array_filter(['estado' => $estado, 'q' => $buscar, 'producto' => $producto ?: null],
                                                          function ($v) { return $v !== null && $v !== ''; }));
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$disponible): ?>
<div class="alert alert-warning">
  <?= W::icono('alerta','18px') ?>
  <span>La base de esta empresa todavía no tiene la preventa. Sal y vuelve a entrar para que se actualice,
    o corre <code>app/config/actualizar-v34.sql</code>.</span>
</div>
<?php return; endif; ?>

<?php if (!empty($moduloApagado)): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>El módulo Preventa está apagado.</b> Ya no se vende en preventa, pero lo que quedó pendiente
    se puede seguir entregando aquí. Cuando no quede nada, esta sección se oculta.</span>
</div>
<?php endif; ?>

<?php /* ── Por producto: cuánto se debe y cuánto hay ya para entregar ── */ ?>
<?php if ($resumen): ?>
<section class="card" style="margin-bottom:18px">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Producto</th><th class="text-end">Por entregar</th><th class="text-end">Hay</th><th>Llega aprox.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($resumen as $r):
        $alcanza = (float)$r['existencia'] >= (float)$r['por_entregar'] - 0.0005; ?>
        <tr>
          <td data-label="Producto"><b><?= P::e($r['nombre']) ?></b>
            <small class="lf-inv-cod" style="display:block"><?= (int)$r['clientes'] ?> pendiente<?= (int)$r['clientes'] === 1 ? '' : 's' ?>
              <?= (int)$r['preventa'] ? '' : ' · ya no está en preventa' ?></small></td>
          <td data-label="Por entregar" class="text-end lf-mono"><b><?= P::e(I::conUnidad($r['por_entregar'], $r['unidad_medida'] ?? '')) ?></b></td>
          <td data-label="Hay" class="text-end lf-mono" style="color:var(<?= $alcanza ? '--lf-tinta-2' : '--lf-rojo' ?>)">
            <?= P::e(I::conUnidad($r['existencia'], $r['unidad_medida'] ?? '')) ?></td>
          <td data-label="Llega aprox.">
            <?php if ($puedeEditar): ?>
              <form method="post" action="/preventas/llegada" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="volver" value="<?= P::e($volver) ?>">
                <input type="hidden" name="producto_id" value="<?= (int)$r['id'] ?>">
                <input class="form-control form-control-sm" type="date" name="llegada" style="max-width:150px"
                       value="<?= P::e($r['preventa_llegada'] ?? '') ?>" aria-label="Fecha estimada de <?= P::e($r['nombre']) ?>">
                <button class="btn btn-secondary btn-sm" type="submit">Cambiar</button>
              </form>
            <?php else: ?>
              <?= $r['preventa_llegada'] ? P::e(date('d/m/Y', strtotime($r['preventa_llegada']))) : '—' ?>
            <?php endif; ?>
          </td>
          <td class="lf-inv-acc"><a class="btn btn-secondary btn-sm" href="/preventas?producto=<?= (int)$r['id'] ?>">Ver clientes</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <?php foreach ($filtros as $k => $t): ?>
    <a class="lf-pill <?= $estado === $k ? 'active' : '' ?>"
       href="/preventas?estado=<?= P::e($k) ?><?= $producto ? '&amp;producto=' . (int)$producto : '' ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/preventas">
  <input type="hidden" name="estado" value="<?= P::e($estado) ?>">
  <?php if ($producto): ?><input type="hidden" name="producto" value="<?= (int)$producto ?>"><?php endif; ?>
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Folio, cliente o producto"></div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
  <?php if ($producto || $buscar !== ''): ?><a class="btn btn-secondary btn-sm" href="/preventas?estado=<?= P::e($estado) ?>">Quitar filtro</a><?php endif; ?>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Venta</th><th>Cliente</th><th>Producto</th><th class="text-end">Cant.</th><th>Estado</th><th class="text-end">Saldo</th><th></th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="7" class="lf-inv-vacio">
          <?= $buscar !== '' || $producto ? 'Nada coincide con el filtro.'
              : 'No hay preventas aquí. Marca un producto «En preventa» en Productos y véndelo en Caja.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $f):
        $alcanza = (float)$f['existencia'] >= (float)$f['cantidad'] - 0.0005; ?>
        <tr>
          <td data-label="Venta"><a class="lf-mono" href="/ventas/<?= (int)$f['venta_id'] ?>"><b><?= P::e($f['codigo_venta']) ?></b></a>
            <small class="lf-inv-cod" style="display:block"><?= P::e(date('d/m/Y', strtotime($f['creado_en']))) ?>
              <?= $f['sucursal'] ? ' · ' . P::e($f['sucursal']) : '' ?></small></td>
          <td data-label="Cliente"><?= P::e($f['cliente'] ?: 'Público general') ?>
            <?php if (!empty($f['telefono'])): ?><small class="lf-inv-cod" style="display:block"><?= P::e($f['telefono']) ?></small><?php endif; ?></td>
          <td data-label="Producto"><?= P::e($f['producto']) ?>
            <?php if ($f['estado'] === 'pendiente' && $f['llegada_estimada']): ?>
              <small class="lf-inv-cod" style="display:block">Llega aprox. <?= P::e(date('d/m/Y', strtotime($f['llegada_estimada']))) ?></small>
            <?php elseif ($f['estado'] === 'entregada' && $f['entregado_en']): ?>
              <small class="lf-inv-cod" style="display:block">Entregada el <?= P::e(date('d/m/Y', strtotime($f['entregado_en']))) ?></small>
            <?php endif; ?></td>
          <td data-label="Cant." class="text-end lf-mono"><?= P::e(I::conUnidad($f['cantidad'], $f['unidad_medida'] ?? '')) ?></td>
          <td data-label="Estado"><span class="badge <?= $colores[$f['estado']] ?? 'bg-secondary' ?>"><?= P::e(PV::ESTADOS[$f['estado']] ?? $f['estado']) ?></span></td>
          <td data-label="Saldo" class="text-end lf-mono"><?= (float)$f['saldo'] > 0.005 ? '<b>' . D::pesos($f['saldo']) . '</b>' : 'Pagada' ?></td>
          <td class="lf-inv-acc">
            <?php if ($f['estado'] === 'pendiente' && $puedeEditar): ?>
              <form method="post" action="/preventas/<?= (int)$f['id'] ?>/entregar" style="margin:0"
                    onsubmit="return confirm(<?= P::e(json_encode(
                        'Entregar ' . I::num($f['cantidad']) . ' ' . $f['producto'] . ' a ' . ($f['cliente'] ?: 'Público general') . '?'
                        . ($alcanza ? '' : "\n\nOjo: en la sucursal no alcanzan las existencias."), JSON_UNESCAPED_UNICODE)) ?>)">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="volver" value="<?= P::e($volver) ?>">
                <button class="btn btn-<?= $alcanza ? 'primary' : 'secondary' ?> btn-sm" type="submit">Entregar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
