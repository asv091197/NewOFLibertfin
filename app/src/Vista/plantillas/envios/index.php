<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Envios as E;

$colores = ['borrador' => 'bg-secondary', 'generado' => 'bg-info',
            'en_camino' => 'bg-warning', 'entregado' => 'bg-success',
            'cancelado' => 'bg-secondary'];
$sinOrigen = [];
foreach (($origenes ?? []) as $o) if (empty($o['completo'])) $sinOrigen[] = $o['sucursal'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':($aviso['tipo']==='aviso'?'warning':'success') ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($faltaMigrar): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base.</b> Envíos necesita dos tablas que todavía no existen.
    Entra a phpMyAdmin, elige la base de tu empresa y pega
    <code>app/config/actualizar-v28.sql</code>. Después sal del sistema y vuelve a entrar.</span>
</div>
<?php endif; ?>

<?php /* Las dos cosas que impiden generar una guía, dichas antes de que
         alguien llene un formulario entero para toparse con el error. */ ?>
<?php if (!$listo): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta el token de Envia.com.</b> Sácalo del panel de Envia, en
    <b>Developers</b>, y ponlo en <code>app/config/integraciones.php</code>, en el
    bloque <code>envia</code>. Para comprobarlo: <code>php app/bin/revisar-envios.php</code>.
    <br><small>Ojo: el token de Sandbox y el de producción son distintos y no se
    pueden intercambiar.</small></span>
</div>
<?php elseif ($sinOrigen): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Falta el domicilio de salida de <b><?= P::e(implode(', ', $sinOrigen)) ?></b>.
    Sin él ninguna paquetería cotiza.
    <a href="/envios/origenes">Capturarlo ahora</a>.</span>
</div>
<?php endif; ?>

<?php if ($sandbox && $listo): ?>
<p class="pista" style="margin:-6px 0 14px">
  <b>Modo de pruebas.</b> Las guías que generes no sirven para mandar nada de verdad.
</p>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('camion','19px') ?></div>
    <div><div class="n"><?= (int)(($resumen['generado']['n'] ?? 0) + ($resumen['en_camino']['n'] ?? 0)) ?></div>
      <div class="t">En tránsito</div></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('ok','19px') ?></div>
    <div><div class="n"><?= (int)($resumen['entregado']['n'] ?? 0) ?></div>
      <div class="t">Entregados</div></div></div>
  <div class="stat-card"><div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div><div class="n"><?= D::corto(array_sum(array_column($resumen ?: [], 'costo'))) ?></div>
      <div class="t">Gastado en guías</div></div></div>
</div>

<div class="lf-doc-acciones" style="margin:4px 0 14px">
  <?php if ($puedeEditar): ?>
    <a class="btn btn-primary" href="/envios/nuevo"><?= W::icono('mas','14px') ?> Nuevo envío</a>
  <?php endif; ?>
  <span class="esp"></span>
  <a class="btn btn-secondary" href="/envios/origenes">De dónde sale</a>
</div>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <a class="lf-pill <?= $estado === '' ? 'active' : '' ?>" href="/envios">Todos</a>
  <?php foreach ($estados as $k => $t): ?>
    <a class="lf-pill <?= $estado === $k ? 'active' : '' ?>" href="/envios?estado=<?= P::e($k) ?>">
      <?= P::e($t) ?><?php if (!empty($resumen[$k]['n'])): ?> <small>(<?= (int)$resumen[$k]['n'] ?>)</small><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/envios">
  <?php if ($estado !== ''): ?><input type="hidden" name="estado" value="<?= P::e($estado) ?>"><?php endif; ?>
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Guía, venta o quien recibe"></div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Guía</th><th>Para</th><th>Paquetería</th><th>Estado</th>
                 <th class="text-end">Costo</th><th></th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="6" class="lf-inv-vacio">
          <?= $buscar !== '' || $estado !== '' ? 'Nada coincide con el filtro.'
              : 'Todavía no has generado ninguna guía.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $e): ?>
        <tr>
          <td data-label="Guía">
            <a class="lf-mono" href="/envios/<?= (int)$e['id'] ?>"><b><?= P::e($e['guia'] ?: '—') ?></b></a>
            <small class="lf-inv-cod" style="display:block">
              <?= P::e(date('d/m/Y', strtotime($e['creado_en']))) ?>
              <?php if ($e['codigo_venta']): ?> · Venta <?= P::e($e['codigo_venta']) ?><?php endif; ?>
            </small>
          </td>
          <td data-label="Para"><?= P::e($e['dest_nombre'] ?: '—') ?>
            <small class="lf-inv-cod" style="display:block">
              <?= P::e(trim($e['dest_ciudad'] . ' ' . $e['dest_estado'] . ' ' . $e['dest_cp'])) ?></small></td>
          <td data-label="Paquetería"><?= P::e(E::carrier($e['carrier'])) ?>
            <?php if ($e['servicio_desc']): ?>
              <small class="lf-inv-cod" style="display:block"><?= P::e($e['servicio_desc']) ?></small>
            <?php endif; ?></td>
          <td data-label="Estado">
            <span class="badge <?= $colores[$e['estado']] ?? 'bg-secondary' ?>"><?= P::e($e['estado_rotulo']) ?></span>
            <?php if ($e['ultimo_estado']): ?>
              <small class="lf-inv-cod" style="display:block"><?= P::e($e['ultimo_estado']) ?></small>
            <?php endif; ?></td>
          <td data-label="Costo" class="text-end lf-mono"><b><?= D::pesos($e['costo']) ?></b></td>
          <td class="lf-inv-acc"><a class="btn btn-secondary btn-sm" href="/envios/<?= (int)$e['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($paginas > 1): ?>
<div class="lf-pag">
  <?php for ($i = 1; $i <= $paginas; $i++): ?>
    <a class="lf-pill <?= $i === $pagina ? 'active' : '' ?>"
       href="/envios?p=<?= $i ?><?= $estado !== '' ? '&estado=' . P::e($estado) : '' ?><?= $buscar !== '' ? '&q=' . urlencode($buscar) : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>