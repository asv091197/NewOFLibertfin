<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Envios as E;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $envio;
$colores = ['borrador' => 'bg-secondary', 'generado' => 'bg-info',
            'en_camino' => 'bg-warning', 'entregado' => 'bg-success',
            'cancelado' => 'bg-secondary'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':($aviso['tipo']==='aviso'?'warning':'success') ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-doc-acciones">
  <a class="btn btn-secondary" href="/envios"><?= W::icono('atras','14px') ?> Envíos</a>
  <span class="esp"></span>
  <?php if ($e['etiqueta_url']): ?>
    <a class="btn btn-primary" href="<?= P::e($e['etiqueta_url']) ?>" target="_blank" rel="noopener">
      <?= W::icono('doc','14px') ?> Imprimir etiqueta</a>
  <?php endif; ?>
  <?php /* En modo de pruebas la dirección de rastreo apunta a un
           servidor que no existe (test.envia.com), así que el botón
           lleva a un error de Cloudflare. Mejor no ofrecerlo: parece
           que algo se rompió en LibertyFin cuando no es así. */
  $rastreoRoto = !empty($sandbox)
      || stripos((string)$e['rastreo_url'], 'test.envia.com') !== false;
  if ($e['rastreo_url'] && !$rastreoRoto): ?>
    <a class="btn btn-secondary" href="<?= P::e($e['rastreo_url']) ?>" target="_blank" rel="noopener">
      Rastrear en la paquetería</a>
  <?php endif; ?>
  <?php if (!$e['cerrado'] && $e['guia'] && $listo): ?>
    <form method="post" action="/envios/<?= (int)$e['id'] ?>/rastrear" style="display:inline">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-secondary" type="submit"><?= W::icono('repetir','14px') ?> Actualizar estado</button>
    </form>
  <?php endif; ?>
  <?php if (!$e['cerrado'] && $puedeEditar && $e['envia_envio_id'] && $listo): ?>
    <form method="post" action="/envios/<?= (int)$e['id'] ?>/cancelar" style="display:inline"
          onsubmit="return confirm('¿Cancelar esta guía?\n\nSolo se puede mientras la paquetería no haya recogido el paquete. Si ya lo recogió, el reembolso lo decide ella.')">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-secondary" type="submit">Cancelar guía</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!empty($sandbox)): ?>
<p class="pista" style="margin:-4px 0 14px">
  <b>Modo de pruebas.</b> Esta guía no sirve para mandar nada, y la paquetería
  no va a reportar movimientos: en Sandbox no hay paquete que seguir.
  <?php if ($e['rastreo_url']): ?>
    Por eso tampoco está el botón de rastrear: la dirección que da Envia en
    pruebas (<code><?= P::e(parse_url($e['rastreo_url'], PHP_URL_HOST) ?: '—') ?></code>)
    no existe.
  <?php endif; ?>
</p>
<?php endif; ?>

<section class="card" style="padding:18px 20px;margin-bottom:16px">
  <div class="lf-grid2">
    <div>
      <div class="pista">Número de guía</div>
      <div class="lf-mono" style="font-size:22px;font-weight:700;letter-spacing:.5px">
        <?= P::e($e['guia'] ?: '—') ?></div>
      <?php if (count($e['guias']) > 1): ?>
        <div class="pista" style="margin-top:4px">Y además:
          <?= P::e(implode(', ', array_slice($e['guias'], 1))) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="pista">Estado</div>
      <div style="margin:4px 0">
        <span class="badge <?= $colores[$e['estado']] ?? 'bg-secondary' ?>" style="font-size:13px">
          <?= P::e($e['estado_rotulo']) ?></span></div>
      <?php if ($e['ultimo_estado']): ?>
        <div class="pista"><?= P::e($e['ultimo_estado']) ?>
          <?php if ($e['rastreado_en']): ?>
            · consultado el <?= P::e(date('d/m/Y H:i', strtotime($e['rastreado_en']))) ?>
          <?php endif; ?></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="pista">Paquetería</div>
      <div><b><?= P::e(E::carrier($e['carrier'])) ?></b>
        <?= $e['servicio_desc'] ? '· ' . P::e($e['servicio_desc']) : '' ?></div>
    </div>
    <div>
      <div class="pista">Costo de la guía</div>
      <div class="lf-mono"><b><?= D::pesos($e['costo']) ?></b>
        <?php if ($e['moneda'] !== 'MXN'): ?> <small><?= P::e($e['moneda']) ?></small><?php endif; ?></div>
    </div>
  </div>
</section>

<section class="card" style="padding:18px 20px;margin-bottom:16px">
  <h3 style="margin:0 0 10px">A dónde va</h3>
  <?php /* Esta dirección se guardó el día que se generó la guía y ya no
           cambia: es a donde el paquete salió de verdad, aunque el
           cliente después corrija su ficha. */ ?>
  <p style="margin:0 0 4px"><b><?= P::e($e['dest_nombre'] ?: '—') ?></b>
    <?php if ($e['dest_telefono']): ?> · <?= P::e($e['dest_telefono']) ?><?php endif; ?></p>
  <p style="margin:0" class="pista">
    <?= P::e(trim($e['dest_calle'] . ' ' . $e['dest_numero'])) ?><?php if ($e['dest_colonia']): ?>,
      col. <?= P::e($e['dest_colonia']) ?><?php endif; ?><br>
    <?= P::e($e['dest_ciudad']) ?>, <?= P::e($e['dest_estado']) ?>
    <?= P::e($e['dest_cp']) ?> <?= P::e($e['dest_pais']) ?>
    <?php if ($e['dest_referencia']): ?><br><?= P::e($e['dest_referencia']) ?><?php endif; ?>
  </p>
  <?php if ($e['codigo_venta']): ?>
    <p style="margin:10px 0 0">Venta
      <a href="/ventas/<?= (int)$e['venta_id'] ?>"><?= P::e($e['codigo_venta']) ?></a></p>
  <?php endif; ?>
  <?php if ($e['nota']): ?>
    <p style="margin:10px 0 0" class="pista">Nota: <?= P::e($e['nota']) ?></p>
  <?php endif; ?>
</section>

<?php if ($e['paquetes']): ?>
<section class="card" style="padding:0 12px 6px;margin-bottom:16px">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Bulto</th><th>Tipo</th><th class="text-end">Peso</th>
                 <th class="text-end">Medidas</th><th class="text-end">Valor</th></tr></thead>
      <tbody>
      <?php foreach ($e['paquetes'] as $p): ?>
        <tr>
          <td data-label="Bulto"><?= P::e($p['contenido'] ?? '') ?></td>
          <td data-label="Tipo"><?= P::e(\LibertyFin\Servicio\Envia::TIPOS[$p['tipo'] ?? 'box'] ?? '') ?></td>
          <td data-label="Peso" class="text-end lf-mono"><?= P::e((string)($p['peso'] ?? '')) ?> kg</td>
          <td data-label="Medidas" class="text-end lf-mono">
            <?= P::e((string)($p['largo'] ?? '')) ?>×<?= P::e((string)($p['ancho'] ?? '')) ?>×<?= P::e((string)($p['alto'] ?? '')) ?> cm</td>
          <td data-label="Valor" class="text-end lf-mono"><?= D::pesos($p['valor'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if (is_array($eventos) && $eventos): ?>
<section class="card" style="padding:18px 20px">
  <h3 style="margin:0 0 10px">Por dónde ha pasado</h3>
  <?php /* No se guardan: la paquetería es la dueña de esta lista y la
           reescribe más seguido de lo que parece. Se piden y se
           enseñan. */ ?>
  <ul style="margin:0;padding-left:18px">
    <?php foreach ($eventos as $ev): ?>
      <li style="margin-bottom:6px">
        <b><?= P::e($ev['descripcion'] ?: '—') ?></b>
        <?php if ($ev['donde']): ?> · <?= P::e($ev['donde']) ?><?php endif; ?>
        <?php if ($ev['cuando']): ?>
          <small class="pista"><?= P::e($ev['cuando']) ?></small>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif (is_array($eventos)): ?>
<p class="pista">La paquetería todavía no reporta movimientos de este paquete.</p>
<?php endif; ?>