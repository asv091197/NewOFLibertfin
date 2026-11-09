<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Datos\MeliRepo;
use LibertyFin\Servicio\Inventario as I;

$estadoCuenta = $cuenta['estado'] ?? 'desconectada';
$coloresOrden = ['aplicada' => 'bg-success', 'devuelta' => 'bg-info', 'esperando' => 'bg-warning',
                 'sin_vincular' => 'bg-danger', 'cancelada' => 'bg-secondary', 'anterior' => 'bg-secondary'];
$rotuloOrden  = ['aplicada' => 'Descontada', 'devuelta' => 'Devuelta', 'esperando' => 'Esperando pago',
                 'sin_vincular' => 'No se descontó', 'cancelada' => 'Cancelada', 'anterior' => 'Anterior', 'nueva' => 'Nueva'];
$volver = function () use ($ver, $buscar) {
    return '<input type="hidden" name="volver_ver" value="' . P::e($ver) . '">'
         . '<input type="hidden" name="volver_q" value="' . P::e($buscar) . '">';
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':($aviso['tipo']==='aviso'?'warning':'success') ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($faltaMigrar): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base.</b> Mercado Libre necesita tres tablas que todavía no existen.
    Sal del sistema y vuelve a entrar, o en phpMyAdmin elige la base de tu empresa y pega
    <code>app/config/actualizar-v30.sql</code>.</span>
</div>
<?php return; endif; ?>

<?php if (!$appLista): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>La aplicación de Mercado Libre no está configurada en el servidor.</b>
    Quien administra LibertyFin tiene que llenar el bloque <code>mercadolibre</code> en
    <code>app/config/integraciones.php</code>. Falta: <?= P::e(implode(', ', $faltan)) ?>.</span>
</div>
<?php endif; ?>

<?php /* ═══════════ LA CUENTA ═══════════ */ ?>
<section class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:16px 18px">
    <div class="lf-tile <?= $conectada ? 'g' : '' ?>"><?= W::icono($conectada ? 'ok' : 'bolsa','19px') ?></div>
    <div style="flex:1;min-width:220px">
      <?php if ($conectada): ?>
        <b>Conectada a «<?= P::e($cuenta['nickname'] ?: $cuenta['meli_user_id']) ?>»</b>
        <small class="lf-inv-cod" style="display:block">
          Desde el <?= P::e(date('d/m/Y H:i', strtotime($cuenta['conectado_en']))) ?>
          <?php if ($cuenta['conectado_por']): ?> por <?= P::e($cuenta['conectado_por']) ?><?php endif; ?>
          <?php if ($cuenta['sincronizado_en']): ?> · última revisión <?= P::e(date('d/m H:i', strtotime($cuenta['sincronizado_en']))) ?><?php endif; ?>
        </small>
      <?php elseif ($estadoCuenta === 'vencida'): ?>
        <b>La autorización de «<?= P::e($cuenta['nickname'] ?: $cuenta['meli_user_id']) ?>» venció</b>
        <small class="lf-inv-cod" style="display:block">Mercado Libre ya no acepta las llaves guardadas
          (se cambió la contraseña, se quitó el permiso o pasaron meses sin usarse). Vuelve a conectar:
          los vínculos se conservan.</small>
      <?php else: ?>
        <b>Sin cuenta conectada</b>
        <small class="lf-inv-cod" style="display:block">Conecta la cuenta de vendedor de tu empresa.
          Mercado Libre te pedirá entrar y autorizar a LibertyFin.</small>
      <?php endif; ?>
      <?php if (!empty($cuenta['ultimo_error'])): ?>
        <small style="display:block;color:var(--bs-danger,#c0392b)"><?= P::e($cuenta['ultimo_error']) ?></small>
      <?php endif; ?>
    </div>
    <?php if ($puedeConectar && $appLista): ?>
      <form method="post" action="/mercadolibre/conectar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn <?= $conectada ? 'btn-secondary' : 'btn-primary' ?>" type="submit">
          <?= $conectada ? 'Reconectar' : 'Conectar con Mercado Libre' ?></button>
      </form>
    <?php endif; ?>
    <?php if ($conectada && $puedeEditar): ?>
      <form method="post" action="/mercadolibre/sincronizar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary" type="submit" title="Revisa órdenes nuevas y manda las existencias pendientes">Sincronizar ahora</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php if (!$cuenta || !$cuenta['meli_user_id']) return; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['total'] ?></div><div class="t">Publicaciones</div></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('ok','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['vinculadas'] ?></div><div class="t">Vinculadas</div></div></div>
  <div class="stat-card"><div class="lf-tile"><?= W::icono('repetir','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['pendientes'] ?></div><div class="t">Por mandar</div></div></div>
  <div class="stat-card"><div class="lf-tile"><?= W::icono('alerta','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['errores'] ?></div><div class="t">Con error</div></div></div>
</div>

<div class="lf-pills" role="tablist" style="margin:4px 0 14px">
  <?php foreach ($pestanas as $k => $t): ?>
    <a class="lf-pill <?= $pestana === $k ? 'active' : '' ?>" role="tab" href="/mercadolibre?t=<?= P::e($k) ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($pestana === 'publicaciones'): ?>
<?php /* ═══════════ PUBLICACIONES ═══════════ */ ?>

<?php if ($conectada && !(int)$cuenta['enviar_existencias']): ?>
<p class="pista" style="margin:-4px 0 12px">
  <b>Mandar existencias está apagado.</b> Las ventas de Mercado Libre sí se descuentan de tu inventario,
  pero lo que vendes aquí todavía no se refleja allá. Revisa que cada publicación esté con su producto
  —la columna «Aquí» contra «En ML»— y enciéndelo en <a href="/mercadolibre?t=ajustes">Ajustes</a>.
</p>
<?php endif; ?>

<div class="lf-doc-acciones" style="margin:0 0 12px">
  <?php if ($puedeEditar && $conectada): ?>
    <form method="post" action="/mercadolibre/importar" style="display:inline">
      <input type="hidden" name="token" value="<?= P::e($token) ?>"><?= $volver() ?>
      <button class="btn btn-primary" type="submit"><?= W::icono('repetir','14px') ?> Traer publicaciones</button>
    </form>
  <?php endif; ?>
  <span class="esp"></span>
</div>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <?php foreach (['' => 'Todas', 'sin' => 'Sin vincular', 'con' => 'Vinculadas', 'difiere' => 'No cuadran', 'error' => 'Con error'] as $k => $t): ?>
    <a class="lf-pill <?= $ver === $k ? 'active' : '' ?>"
       href="/mercadolibre?t=publicaciones<?= $k !== '' ? '&amp;ver=' . P::e($k) : '' ?><?= $buscar !== '' ? '&amp;q=' . urlencode($buscar) : '' ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/mercadolibre">
  <input type="hidden" name="t" value="publicaciones">
  <?php if ($ver !== ''): ?><input type="hidden" name="ver" value="<?= P::e($ver) ?>"><?php endif; ?>
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Título, SKU, MLM… o producto"></div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
</form>

<?php if ($puedeEditar && $productos): ?>
<datalist id="lfMeliProductos">
  <?php foreach ($productos as $p): ?>
    <option value="<?= P::e(($p['codigo'] ? $p['codigo'] . ' · ' : '') . $p['nombre']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<?php endif; ?>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Publicación</th><th>Producto en LibertyFin</th>
                 <th class="text-end">En ML</th><th class="text-end">Aquí</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="5" class="lf-inv-vacio">
          <?= $buscar !== '' || $ver !== '' ? 'Nada coincide con el filtro.'
              : 'Todavía no hay publicaciones. Da clic en «Traer publicaciones».' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $m):
            $aqui = (int)floor((float)$m['existencia'] - (float)$cuenta['reserva'] + 0.0005);
            $difiere = $m['producto_id'] && (int)$m['stock_ml'] !== max(0, $aqui);
            $full = ($m['logistica'] ?? '') === 'fulfillment'; ?>
        <tr>
          <td data-label="Publicación">
            <?php if ($m['permalink']): ?><a href="<?= P::e($m['permalink']) ?>" target="_blank" rel="noopener"><b><?= P::e($m['titulo']) ?></b></a>
            <?php else: ?><b><?= P::e($m['titulo']) ?></b><?php endif; ?>
            <small class="lf-inv-cod" style="display:block">
              <?= P::e($m['item_id']) ?><?php if ($m['variacion']): ?> · <?= P::e($m['variacion']) ?><?php endif; ?>
              <?php if ($m['sku']): ?> · SKU <?= P::e($m['sku']) ?><?php endif; ?>
              · <?= D::pesos($m['precio']) ?>
            </small>
          </td>
          <td data-label="Producto">
            <?php if ($m['producto_id']): ?>
              <?= P::e($m['producto']) ?>
              <small class="lf-inv-cod" style="display:block"><?= P::e($m['producto_codigo']) ?>
                <?= $m['vinculo'] === 'auto' ? '· vinculado por SKU' : '' ?></small>
            <?php endif; ?>
            <?php if ($puedeEditar && !$full): ?>
              <form method="post" action="/mercadolibre/vincular" style="display:flex;gap:6px;margin-top:4px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>"><?= $volver() ?>
                <input type="hidden" name="publicacion_id" value="<?= (int)$m['id'] ?>">
                <?php if ($m['producto_id']): ?>
                  <button class="btn btn-secondary btn-sm" type="submit" name="soltar" value="1"
                          onclick="return confirm('¿Quitar el vínculo? Las ventas de esta publicación dejarán de descontarse.')">Quitar</button>
                <?php else: ?>
                  <input name="producto_buscar" list="lfMeliProductos" placeholder="SKU o nombre…"
                         value="<?= P::e($m['sku']) ?>" style="flex:1;min-width:140px">
                  <button class="btn btn-primary btn-sm" type="submit">Vincular</button>
                <?php endif; ?>
              </form>
            <?php elseif (!$m['producto_id']): ?>
              <span class="lf-inv-cod">—</span>
            <?php endif; ?>
          </td>
          <td data-label="En ML" class="text-end lf-mono"><b><?= (int)$m['stock_ml'] ?></b></td>
          <td data-label="Aquí" class="text-end lf-mono">
            <?php if ($m['producto_id']): ?>
              <span style="<?= $difiere ? 'color:var(--bs-warning,#d68910);font-weight:600' : '' ?>"><?= P::e(I::num($m['existencia'])) ?></span>
              <?php if ((float)$cuenta['reserva'] > 0): ?><small class="lf-inv-cod" style="display:block">publica <?= max(0, $aqui) ?></small><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="Estado">
            <span class="badge <?= $m['estado_ml'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
              <?= P::e(MeliRepo::ESTADOS_ML[$m['estado_ml']] ?? $m['estado_ml']) ?></span>
            <?php if ($full): ?><span class="badge bg-info" title="La existencia la lleva Mercado Libre en su bodega">Full</span><?php endif; ?>
            <?php if ((int)$m['pendiente']): ?><small class="lf-inv-cod" style="display:block">por mandar</small><?php endif; ?>
            <?php if ($m['error']): ?><small style="display:block;color:var(--bs-danger,#c0392b)"><?= P::e($m['error']) ?></small>
            <?php elseif ($m['enviado_en']): ?><small class="lf-inv-cod" style="display:block">mandado <?= P::e(date('d/m H:i', strtotime($m['enviado_en']))) ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    «Aquí» es la existencia de <b><?= P::e(array_column($sucursales, 'nombre', 'id')[$sucursal] ?? 'la sucursal') ?></b>.
    Las publicaciones en Full no se tocan: esa mercancía está en la bodega de Mercado Libre.
  </span></div>
</section>

<?php elseif ($pestana === 'ordenes'): ?>
<?php /* ═══════════ ÓRDENES ═══════════ */ ?>
<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Orden</th><th>Comprador</th><th class="text-end">Total</th><th>Inventario</th></tr></thead>
      <tbody>
      <?php if (!$ordenes): ?>
        <tr><td colspan="4" class="lf-inv-vacio">Todavía no llega ninguna orden desde que se conectó la cuenta.</td></tr>
      <?php endif; ?>
      <?php foreach ($ordenes as $o): ?>
        <tr>
          <td data-label="Orden"><b class="lf-mono">#<?= P::e($o['orden_id']) ?></b>
            <small class="lf-inv-cod" style="display:block">
              <?= $o['fecha_ml'] ? P::e(date('d/m/Y H:i', strtotime($o['fecha_ml']))) : '' ?>
              <?= $o['estado_ml'] ? ' · ' . P::e($o['estado_ml']) : '' ?></small></td>
          <td data-label="Comprador"><?= P::e($o['comprador'] ?: '—') ?></td>
          <td data-label="Total" class="text-end lf-mono"><?= D::pesos($o['total']) ?></td>
          <td data-label="Inventario">
            <span class="badge <?= $coloresOrden[$o['estado']] ?? 'bg-secondary' ?>"><?= P::e($rotuloOrden[$o['estado']] ?? $o['estado']) ?></span>
            <?php if ($o['nota']): ?><small class="lf-inv-cod" style="display:block"><?= P::e($o['nota']) ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    Una orden se descuenta UNA vez, cuando queda pagada; si después se cancela, la mercancía regresa.
    Cada movimiento queda en <a href="/inventario?t=movimientos&amp;tipo=mercadolibre&amp;s=0">Inventario → Movimientos</a>.
  </span></div>
</section>

<?php else: ?>
<?php /* ═══════════ AJUSTES ═══════════ */ ?>
<section class="card" style="padding:18px">
  <?php if ($puedeConectar): ?>
  <form method="post" action="/mercadolibre/ajustes">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <label style="display:block;margin-bottom:14px"><b>Sucursal que surte Mercado Libre</b>
      <select name="sucursal_id" style="display:block;margin-top:6px">
        <option value="0">La matriz</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$cuenta['sucursal_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="lf-inv-cod">De aquí se descuenta lo vendido allá, y su existencia es la que se publica.</small>
    </label>

    <label style="display:block;margin-bottom:14px">
      <input type="checkbox" name="descontar_ventas" value="1" <?= (int)$cuenta['descontar_ventas'] ? 'checked' : '' ?>>
      <b>Descontar las ventas de Mercado Libre</b>
      <small class="lf-inv-cod" style="display:block">Cuando una orden queda pagada, sale de tus existencias con un movimiento «Mercado Libre».</small>
    </label>

    <label style="display:block;margin-bottom:14px">
      <input type="checkbox" name="enviar_existencias" value="1" <?= (int)$cuenta['enviar_existencias'] ? 'checked' : '' ?>>
      <b>Mandar existencias a Mercado Libre</b>
      <small class="lf-inv-cod" style="display:block">Cada vez que algo se mueve aquí (una venta en Caja, una entrada, un conteo)
        se actualiza la cantidad publicada. <b>Al encenderlo se sobreescribe la cantidad de TODAS las publicaciones vinculadas</b>
        con la de LibertyFin: revisa antes los vínculos.</small>
    </label>

    <label style="display:block;margin-bottom:18px"><b>Reserva</b>
      <input type="number" name="reserva" min="0" step="1" value="<?= P::e(I::num($cuenta['reserva'])) ?>" style="display:block;width:120px;margin-top:6px">
      <small class="lf-inv-cod">Piezas que NO se publican, para no vender en línea la última que alguien tiene en la mano en el mostrador.</small>
    </label>

    <button class="btn btn-primary" type="submit">Guardar</button>
  </form>

  <hr style="margin:22px 0">
  <form method="post" action="/mercadolibre/desconectar"
        onsubmit="return confirm('¿Desconectar la cuenta? Las ventas de Mercado Libre dejarán de descontarse.')">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <button class="btn btn-secondary" type="submit">Desconectar la cuenta</button>
    <small class="lf-inv-cod">Los vínculos se conservan por si la vuelves a conectar.</small>
  </form>
  <?php else: ?>
    <p>Solo un administrador puede cambiar estos ajustes.</p>
  <?php endif; ?>

  <hr style="margin:22px 0">
  <p class="lf-inv-cod" style="margin:0">
    Para que las ventas lleguen al instante, la aplicación de Mercado Libre debe avisar a
    <code><?= P::e($urlAviso) ?></code> con los temas <code>orders_v2</code> e <code>items</code>.
    Si un aviso no llega, «Sincronizar ahora» (o la tarea programada) recupera las órdenes de las últimas horas.
  </p>
</section>
<?php endif; ?>
