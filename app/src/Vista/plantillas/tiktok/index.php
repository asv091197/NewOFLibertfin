<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Datos\TikTokRepo;
use LibertyFin\Servicio\Inventario as I;

$estadoCuenta = $cuenta['estado'] ?? 'desconectada';
$conTienda    = $cuenta && !empty($cuenta['shop_id']);
$hayCred      = $propias || $hayGlobal;
$coloresOrden = ['aplicada' => 'bg-success', 'devuelta' => 'bg-info', 'esperando' => 'bg-warning',
                 'sin_vincular' => 'bg-danger', 'cancelada' => 'bg-secondary', 'anterior' => 'bg-secondary'];
$rotuloOrden  = ['aplicada' => 'Descontada', 'devuelta' => 'Devuelta', 'esperando' => 'Esperando',
                 'sin_vincular' => 'No se descontó', 'cancelada' => 'Cancelada', 'anterior' => 'Anterior', 'nueva' => 'Nueva'];
$volver = function () use ($ver, $buscar) {
    return '<input type="hidden" name="volver_ver" value="' . P::e($ver) . '">'
         . '<input type="hidden" name="volver_q" value="' . P::e($buscar) . '">';
};

/* El formulario de las credenciales propias («Seller in-house»). Sale en
   Ajustes y, si todavía no hay con qué conectar, también arriba. */
$formCredenciales = function () use ($token, $cuenta, $propias, $hayGlobal, $urlRetorno, $urlAviso) { ?>
  <form method="post" action="/tiktok/credenciales" autocomplete="off">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <p style="margin:0 0 10px">
      <?php if ($hayGlobal): ?>
        Normalmente no hace falta: LibertyFin tiene su propia aplicación de TikTok Shop. Llena esto solo si
        registraste <b>tu propia aplicación «Seller in-house»</b> en el Partner Center.
      <?php else: ?>
        Registra tu aplicación <b>«Seller in-house»</b> en el Partner Center de TikTok Shop y copia aquí sus datos.
      <?php endif; ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <label style="flex:1;min-width:180px"><b>App Key</b>
        <input name="app_key" value="<?= P::e($cuenta['app_key'] ?? '') ?>" style="display:block;width:100%;margin-top:4px" required></label>
      <label style="flex:1;min-width:180px"><b>App Secret</b>
        <input name="app_secret" type="password" style="display:block;width:100%;margin-top:4px"
               placeholder="<?= $propias ? '•••••••• (déjalo vacío para conservarlo)' : '' ?>" <?= $propias ? '' : 'required' ?>></label>
      <label style="flex:1;min-width:160px"><b>Service ID</b>
        <input name="service_id" value="<?= P::e($cuenta['service_id'] ?? '') ?>" inputmode="numeric" style="display:block;width:100%;margin-top:4px"></label>
    </div>
    <small class="lf-inv-cod" style="display:block;margin-bottom:10px">
      En la aplicación pon como <b>Redirect URL</b> <code><?= P::e($urlRetorno) ?></code> y como
      <b>Webhook URL</b> <code><?= P::e($urlAviso) ?></code>. El App Secret se guarda cifrado y no se vuelve a mostrar.
    </small>
    <button class="btn btn-primary" type="submit">Guardar credenciales</button>
    <?php if ($propias): ?>
      <button class="btn btn-secondary" type="submit" name="olvidar" value="1" formnovalidate
              onclick="return confirm('¿Quitar tus credenciales? La tienda se desconecta<?= $hayGlobal ? ' y se usará la aplicación de LibertyFin' : '' ?>.')">
        Quitar mis credenciales</button>
    <?php endif; ?>
  </form>
<?php };
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':($aviso['tipo']==='aviso'?'warning':'success') ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($faltaMigrar): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base.</b> TikTok Shop necesita tres tablas que todavía no existen.
    Sal del sistema y vuelve a entrar, o en phpMyAdmin elige la base de tu empresa y pega
    <code>app/config/actualizar-v33.sql</code>.</span>
</div>
<?php return; endif; ?>

<?php if (!$appLista): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>TikTok Shop no está activado en el servidor.</b>
    Quien administra LibertyFin tiene que llenar el bloque <code>tiktok</code> en
    <code>app/config/integraciones.php</code>. Falta: <?= P::e(implode(', ', $faltan)) ?>.</span>
</div>
<?php return; endif; ?>

<?php /* ═══════════ LA TIENDA ═══════════ */ ?>
<section class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:16px 18px">
    <div class="lf-tile <?= $conectada ? 'g' : '' ?>"><?= W::icono($conectada ? 'ok' : 'bolsa','19px') ?></div>
    <div style="flex:1;min-width:220px">
      <?php if ($conectada): ?>
        <b>Conectada a «<?= P::e($cuenta['nombre'] ?: $cuenta['shop_id']) ?>»</b>
        <small class="lf-inv-cod" style="display:block">
          tienda <?= P::e($cuenta['shop_id']) ?><?= $cuenta['region'] ? ' · ' . P::e($cuenta['region']) : '' ?>
          · desde el <?= P::e(date('d/m/Y H:i', strtotime($cuenta['conectado_en']))) ?>
          <?php if ($cuenta['conectado_por']): ?> por <?= P::e($cuenta['conectado_por']) ?><?php endif; ?>
          <?php if ($cuenta['sincronizado_en']): ?> · última revisión <?= P::e(date('d/m H:i', strtotime($cuenta['sincronizado_en']))) ?><?php endif; ?>
          · <?= $propias ? 'app propia (in-house)' : 'app de LibertyFin' ?>
          · último aviso <?= $cuenta['aviso_en'] ? P::e(date('d/m H:i', strtotime($cuenta['aviso_en']))) : '<b>ninguno todavía</b>' ?>
          · almacén <?= $cuenta['almacen_nombre'] ? '«' . P::e($cuenta['almacen_nombre']) . '»' : '<b>sin elegir</b>' ?>
        </small>
      <?php elseif ($estadoCuenta === 'vencida'): ?>
        <b>La tienda «<?= P::e($cuenta['nombre'] ?: $cuenta['shop_id']) ?>» dejó de aceptar a LibertyFin</b>
        <small class="lf-inv-cod" style="display:block">Se quitó el permiso desde TikTok, o la llave caducó sin poder
          renovarse. Vuelve a conectar: los vínculos se conservan.</small>
      <?php elseif (!$hayCred): ?>
        <b>Falta tu aplicación de TikTok Shop</b>
        <small class="lf-inv-cod" style="display:block">Escribe abajo el App Key y el App Secret de tu aplicación
          «Seller in-house» para poder conectar la tienda.</small>
      <?php else: ?>
        <b>Sin tienda conectada</b>
        <small class="lf-inv-cod" style="display:block">TikTok Shop te pedirá entrar con tu cuenta de vendedor y autorizar a LibertyFin.</small>
      <?php endif; ?>
      <?php if (!empty($cuenta['ultimo_error'])): ?>
        <small style="display:block;color:var(--bs-danger,#c0392b)"><?= P::e($cuenta['ultimo_error']) ?></small>
      <?php endif; ?>
    </div>
    <?php if ($puedeConectar && $hayCred): ?>
      <form method="post" action="/tiktok/conectar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn <?= $conectada ? 'btn-secondary' : 'btn-primary' ?>" type="submit">
          <?= $conectada ? 'Reconectar' : 'Conectar con TikTok Shop' ?></button>
      </form>
    <?php endif; ?>
    <?php if ($conectada && $puedeEditar): ?>
      <form method="post" action="/tiktok/sincronizar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary" type="submit" title="Revisa órdenes nuevas y manda las existencias pendientes">Sincronizar ahora</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$hayCred && $puedeConectar): ?>
    <div style="padding:0 18px 18px"><?php $formCredenciales(); ?></div>
  <?php elseif (!$conTienda && $puedeConectar): ?>
    <details style="padding:0 18px 18px" <?= $propias ? 'open' : '' ?>>
      <summary style="cursor:pointer"><b>Tu propia aplicación (Seller in-house)</b></summary>
      <div style="margin-top:10px"><?php $formCredenciales(); ?></div>
    </details>
  <?php endif; ?>
</section>

<?php if (!$conTienda) return; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['total'] ?></div><div class="t">SKU</div></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('ok','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['vinculadas'] ?></div><div class="t">Vinculados</div></div></div>
  <div class="stat-card"><div class="lf-tile"><?= W::icono('repetir','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['pendientes'] ?></div><div class="t">Por mandar</div></div></div>
  <div class="stat-card"><div class="lf-tile"><?= W::icono('alerta','19px') ?></div>
    <div><div class="n"><?= (int)$resumen['errores'] ?></div><div class="t">Con error</div></div></div>
</div>

<div class="lf-pills" role="tablist" style="margin:4px 0 14px">
  <?php foreach ($pestanas as $k => $t): ?>
    <a class="lf-pill <?= $pestana === $k ? 'active' : '' ?>" role="tab" href="/tiktok?t=<?= P::e($k) ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($pestana === 'productos'): ?>
<?php /* ═══════════ PRODUCTOS ═══════════ */ ?>

<?php if ($conectada && !$cuenta['almacen_id']): ?>
<p class="pista" style="margin:-4px 0 12px">
  <b>Falta elegir el almacén.</b> En TikTok Shop la existencia vive por almacén.
  Elige en <a href="/tiktok?t=ajustes">Ajustes</a> cuál corresponde a tu sucursal y luego trae los productos.
</p>
<?php elseif ($conectada && !(int)$cuenta['enviar_existencias']): ?>
<p class="pista" style="margin:-4px 0 12px">
  <b>Mandar existencias está apagado.</b> Las ventas de TikTok sí se descuentan de tu inventario,
  pero lo que vendes aquí todavía no se refleja allá. Revisa que cada SKU esté con su producto
  —la columna «Aquí» contra «En TikTok»— y enciéndelo en <a href="/tiktok?t=ajustes">Ajustes</a>.
</p>
<?php endif; ?>

<div class="lf-doc-acciones" style="margin:0 0 12px">
  <?php if ($puedeEditar && $conectada): ?>
    <form method="post" action="/tiktok/importar" style="display:inline">
      <input type="hidden" name="token" value="<?= P::e($token) ?>"><?= $volver() ?>
      <button class="btn btn-primary" type="submit"><?= W::icono('repetir','14px') ?> Traer productos</button>
    </form>
  <?php endif; ?>
  <span class="esp"></span>
</div>

<div class="lf-pills lf-pills-filtro" role="tablist">
  <?php foreach (['' => 'Todos', 'sin' => 'Sin vincular', 'con' => 'Vinculados', 'difiere' => 'No cuadran', 'error' => 'Con error'] as $k => $t): ?>
    <a class="lf-pill <?= $ver === $k ? 'active' : '' ?>"
       href="/tiktok?t=productos<?= $k !== '' ? '&amp;ver=' . P::e($k) : '' ?><?= $buscar !== '' ? '&amp;q=' . urlencode($buscar) : '' ?>"><?= P::e($t) ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/tiktok">
  <input type="hidden" name="t" value="productos">
  <?php if ($ver !== ''): ?><input type="hidden" name="ver" value="<?= P::e($ver) ?>"><?php endif; ?>
  <div class="lf-search"><?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Nombre, SKU, id… o producto"></div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
</form>

<?php if ($puedeEditar && $productos): ?>
<datalist id="lfTtProductos">
  <?php foreach ($productos as $p): ?>
    <option value="<?= P::e(($p['codigo'] ? $p['codigo'] . ' · ' : '') . $p['nombre']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<?php endif; ?>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>En TikTok</th><th>Producto en LibertyFin</th>
                 <th class="text-end">En TikTok</th><th class="text-end">Aquí</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="5" class="lf-inv-vacio">
          <?= $buscar !== '' || $ver !== '' ? 'Nada coincide con el filtro.'
              : 'Todavía no hay productos. Da clic en «Traer productos».' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $m):
            $aqui = (int)floor((float)$m['existencia'] - (float)$cuenta['reserva'] + 0.0005);
            $sinControl = $m['stock_tt'] === null;
            $difiere = $m['producto_id'] && ($sinControl || (int)$m['stock_tt'] !== max(0, $aqui)); ?>
        <tr>
          <td data-label="En TikTok">
            <b><?= P::e($m['titulo']) ?></b>
            <small class="lf-inv-cod" style="display:block">
              #<?= P::e($m['producto_tt']) ?><?php if ($m['variante']): ?> · <?= P::e($m['variante']) ?><?php endif; ?>
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
            <?php if ($puedeEditar): ?>
              <form method="post" action="/tiktok/vincular" style="display:flex;gap:6px;margin-top:4px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>"><?= $volver() ?>
                <input type="hidden" name="sku_id" value="<?= (int)$m['id'] ?>">
                <?php if ($m['producto_id']): ?>
                  <button class="btn btn-secondary btn-sm" type="submit" name="soltar" value="1"
                          onclick="return confirm('¿Quitar el vínculo? Las ventas de este SKU dejarán de descontarse.')">Quitar</button>
                <?php else: ?>
                  <input name="producto_buscar" list="lfTtProductos" placeholder="SKU o nombre…"
                         value="<?= P::e($m['sku']) ?>" style="flex:1;min-width:140px">
                  <button class="btn btn-primary btn-sm" type="submit">Vincular</button>
                <?php endif; ?>
              </form>
            <?php elseif (!$m['producto_id']): ?>
              <span class="lf-inv-cod">—</span>
            <?php endif; ?>
          </td>
          <td data-label="En TikTok" class="text-end lf-mono">
            <b><?= $sinControl ? '—' : (int)$m['stock_tt'] ?></b>
            <?php if ($sinControl): ?><small class="lf-inv-cod" style="display:block" title="Este SKU no tiene existencia en el almacén elegido">sin existencia en el almacén</small><?php endif; ?>
          </td>
          <td data-label="Aquí" class="text-end lf-mono">
            <?php if ($m['producto_id']): ?>
              <span style="<?= $difiere ? 'color:var(--bs-warning,#d68910);font-weight:600' : '' ?>"><?= P::e(I::num($m['existencia'])) ?></span>
              <?php if ((float)$cuenta['reserva'] > 0): ?><small class="lf-inv-cod" style="display:block">publica <?= max(0, $aqui) ?></small><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="Estado">
            <span class="badge <?= $m['estado_tt'] === 'visible' ? 'bg-success' : ($m['estado_tt'] === 'rechazado' ? 'bg-danger' : 'bg-secondary') ?>">
              <?= P::e(TikTokRepo::ESTADOS[$m['estado_tt']] ?? $m['estado_tt']) ?></span>
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
    «Aquí» es la existencia de <b><?= P::e(array_column($sucursales, 'nombre', 'id')[$sucursal] ?? 'la sucursal') ?></b>;
    «En TikTok», la del almacén <b><?= P::e($cuenta['almacen_nombre'] ?: '—') ?></b>.
    Los productos se dan de alta en TikTok Shop (que los revisa antes de publicarlos); aquí solo se vinculan.
  </span></div>
</section>

<?php elseif ($pestana === 'ordenes'): ?>
<?php /* ═══════════ ÓRDENES ═══════════ */ ?>
<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Orden</th><th class="text-end">Total</th><th>Inventario</th></tr></thead>
      <tbody>
      <?php if (!$ordenes): ?>
        <tr><td colspan="3" class="lf-inv-vacio">Todavía no llega ninguna orden desde que se conectó la tienda.</td></tr>
      <?php endif; ?>
      <?php foreach ($ordenes as $o): ?>
        <tr>
          <td data-label="Orden"><b class="lf-mono">#<?= P::e($o['orden_id']) ?></b>
            <small class="lf-inv-cod" style="display:block">
              <?= $o['fecha_tt'] ? P::e(date('d/m/Y H:i', strtotime($o['fecha_tt']))) : '' ?>
              <?= $o['estado_tt'] ? ' · ' . P::e($o['estado_tt']) : '' ?></small></td>
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
    Una orden se descuenta UNA vez, cuando TikTok la pasa a «por enviar» (pagada y fuera del periodo de cancelación);
    si después se cancela, la mercancía regresa.
    Cada movimiento queda en <a href="/inventario?t=movimientos">Inventario → Movimientos</a> con la nota «Orden TikTok».
  </span></div>
</section>

<?php else: ?>
<?php /* ═══════════ AJUSTES ═══════════ */ ?>
<section class="card" style="padding:18px">
  <?php if ($puedeConectar): ?>
  <form method="post" action="/tiktok/ajustes">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <label style="display:block;margin-bottom:14px"><b>Sucursal que surte la tienda de TikTok</b>
      <select name="sucursal_id" style="display:block;margin-top:6px">
        <option value="0">La matriz</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$cuenta['sucursal_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="lf-inv-cod">De aquí se descuenta lo vendido allá, y su existencia es la que se publica.</small>
    </label>

    <label style="display:block;margin-bottom:14px"><b>Almacén de TikTok que le corresponde</b>
      <?php if ($almacenes): ?>
        <select name="almacen_id" style="display:block;margin-top:6px">
          <?php if (!$cuenta['almacen_id']): ?><option value="">— Elige —</option><?php endif; ?>
          <?php foreach ($almacenes as $a): if (!$a['venta']) continue; ?>
            <option value="<?= P::e($a['id']) ?>" <?= (string)$cuenta['almacen_id'] === $a['id'] ? 'selected' : '' ?>>
              <?= P::e($a['nombre']) ?><?= $a['activo'] ? '' : ' (inactivo)' ?><?= $a['omision'] ? ' · por omisión' : '' ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <span style="display:block;margin-top:6px"><?= P::e($cuenta['almacen_nombre'] ?: 'No se pudieron leer los almacenes de TikTok Shop.') ?></span>
      <?php endif; ?>
      <small class="lf-inv-cod">En TikTok Shop la existencia vive por almacén. Es el que se lee y el que se sobreescribe.</small>
    </label>

    <label style="display:block;margin-bottom:14px">
      <input type="checkbox" name="descontar_ventas" value="1" <?= (int)$cuenta['descontar_ventas'] ? 'checked' : '' ?>>
      <b>Descontar las ventas de TikTok Shop</b>
      <small class="lf-inv-cod" style="display:block">Cuando una orden pasa a «por enviar», sale de tus existencias con la nota «Orden TikTok».</small>
    </label>

    <label style="display:block;margin-bottom:14px">
      <input type="checkbox" name="enviar_existencias" value="1" <?= (int)$cuenta['enviar_existencias'] ? 'checked' : '' ?>>
      <b>Mandar existencias a TikTok Shop</b>
      <small class="lf-inv-cod" style="display:block">Cada vez que algo se mueve aquí (una venta en Caja, una entrada, un conteo)
        se actualiza la existencia del almacén. <b>Al encenderlo se sobreescribe la existencia de TODOS los SKU vinculados</b>
        con la de LibertyFin: revisa antes los vínculos.</small>
    </label>

    <label style="display:block;margin-bottom:18px"><b>Reserva</b>
      <input type="number" name="reserva" min="0" step="1" value="<?= P::e(I::num($cuenta['reserva'])) ?>" style="display:block;width:120px;margin-top:6px">
      <small class="lf-inv-cod">Piezas que NO se publican, para no vender en línea la última que alguien tiene en la mano en el mostrador.</small>
    </label>

    <button class="btn btn-primary" type="submit">Guardar</button>
  </form>

  <hr style="margin:22px 0">
  <form method="post" action="/tiktok/desconectar"
        onsubmit="return confirm('¿Desconectar la tienda? Las ventas de TikTok Shop dejarán de descontarse.')">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <button class="btn btn-secondary" type="submit">Desconectar la tienda</button>
    <small class="lf-inv-cod">Los vínculos se conservan por si la vuelves a conectar.</small>
  </form>

  <hr style="margin:22px 0">
  <h3 style="font-size:1rem;margin:0 0 10px">Tu propia aplicación (Seller in-house)</h3>
  <?php $formCredenciales(); ?>
  <?php else: ?>
    <p>Solo un administrador puede cambiar estos ajustes.</p>
  <?php endif; ?>

  <hr style="margin:22px 0">
  <p class="lf-inv-cod" style="margin:0">
    Los avisos al instante llegan a <code><?= P::e($urlAviso) ?></code>. En TikTok Shop se configuran en la aplicación
    del Partner Center (no aquí): suscribe el aviso de cambios de estado de orden.
    Si uno no llega, «Sincronizar ahora» (o la tarea programada) recupera las órdenes de las últimas horas.
  </p>
</section>
<?php endif; ?>
