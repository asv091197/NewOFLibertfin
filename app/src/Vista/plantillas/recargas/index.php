<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$h = $hoy;
$total = (int)($totalCatalogo ?? count($productos));
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$cifrado && !$aceptado): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>La conexión con Emida va sin cifrar.</b> El usuario, la clave y el número
    del cliente viajan en claro. Pídele a Emida un endpoint con HTTPS.</span>
</div>
<?php endif; ?>

<?php if ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Nada llega al teléfono ni descuenta saldo real.</span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <?php /* El saldo se pide solo al abrir (por detrás: la página no espera al
           proveedor). Si se consultó hace menos de un minuto, ya viene. */ ?>
  <div class="stat-card lf-hero" id="rcSaldo" data-pendiente="<?= $saldo ? '' : '1' ?>">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value" id="rcSaldoValor"><?= $saldo ? D::pesos($saldo['saldo']) : 'Consultando…' ?></div>
    <div class="stat-label">Saldo con el proveedor</div>
    <div class="stat-meta" id="rcSaldoMeta"><?= $saldo ? 'al ' . date('H:i', $saldo['cuando']) : 'preguntando a Emida' ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= (int)($h['exitosas'] ?? 0) ?></div>
    <div class="stat-label">Operaciones de hoy</div>
    <div class="stat-meta">de <?= (int)($h['cuantas'] ?? 0) ?> intentos</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($h['vendido'] ?? 0) ?></div>
    <div class="stat-label">Vendido hoy</div>
    <div class="stat-meta">comisión <?= D::pesos($h['comision'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= $total ? '' : 'a' ?>"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= number_format($total) ?></div>
    <div class="stat-label">Productos en catálogo</div>
    <div class="stat-meta" id="rcActualizado">
      <?= $actualizado ? 'al ' . date('d/m/y H:i', strtotime($actualizado)) . ' · se actualiza solo' : 'se baja solo' ?></div>
  </div>
</div>

<?php if (!$total && $errorCatalogo): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)">
  <div class="card-body" style="text-align:center;padding:30px">
    <h3 style="font-size:16px;margin-bottom:8px">No se pudo traer el catálogo</h3>
    <p style="font-size:13px;color:var(--lf-tinta-3);max-width:520px;margin:0 auto 18px;line-height:1.6">
      <?= P::e($errorCatalogo) ?><br>Sin él no se puede vender: se vuelve a intentar solo en unos minutos.</p>
    <form method="post" action="/recargas/catalogo">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-primary" type="submit">Reintentar ahora</button>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if ($ocultos > 0): ?>
<div class="alert alert-warning lf-rc-ocultos" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= number_format($ocultos) ?> productos del catálogo están ocultos.</b>
    Son los que una descarga del proveedor dejó de traer.
    <?php if ($puedeAjustes): ?>Si deben seguir a la venta, vuélvelos a mostrar.<?php else: ?>Un administrador puede volver a mostrarlos.<?php endif; ?></span>
  <?php if ($puedeAjustes): ?>
  <form method="post" action="/recargas/ocultos" style="margin:0 0 0 auto;flex-shrink:0">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <button class="btn btn-primary btn-sm" type="submit">Volver a mostrarlos</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="lf-split">
  <section class="card lf-rc-catalogo">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
      <div><span>Catálogo</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Elige el producto, no la compañía: cada monto es uno distinto</p></div>
      <?php $paginasIni = max(1, (int)ceil($total / max(1, (int)($porPagina ?? 18)))); ?>
      <div class="lf-pag-serv" id="rcPag" <?= $paginasIni > 1 ? '' : 'hidden' ?>>
        <button type="button" id="rcAnt" aria-label="Anterior" disabled>&lsaquo;</button>
        <span><b id="rcPagN">1</b> de <b id="rcPagT"><?= $paginasIni ?></b></span>
        <button type="button" id="rcSig" aria-label="Siguiente">&rsaquo;</button>
      </div>
      <div class="lf-search" style="max-width:240px">
        <?= W::icono('buscar','15px') ?>
        <input type="search" id="rcBuscar" placeholder="Nombre, compañía o ID" autocomplete="off">
      </div>
      <form method="post" action="/recargas/catalogo" style="margin:0" id="formBajar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-primary btn-sm" type="submit" id="btnBajar"
                title="Vuelve a pedir el catálogo a Emida. Agrega y actualiza; no quita nada.">Bajar catálogo</button>
      </form>
    </header>

    <?php if ($categorias): ?>
    <div class="lf-cat-fila" id="rcCats">
      <button type="button" class="lf-pill active" data-cat="">Todos <span class="n"><?= number_format($total) ?></span></button>
      <?php foreach ($categorias as $c): ?>
        <button type="button" class="lf-pill" data-cat="<?= P::e($c['categoria']) ?>"><?= P::e(mb_convert_case(mb_strtolower($c['categoria']), MB_CASE_TITLE)) ?> <span class="n"><?= number_format((int)$c['n']) ?></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php /* La misma tarjeta de Caja: la imagen del proveedor si la tiene, si
             no el ícono; el nombre, la compañía y el monto. */ ?>
    <div class="lf-grid-serv" id="rcGrid">
      <?php if (!$total): ?>
        <p class="lf-rc-vacio">El catálogo está vacío.</p>
      <?php endif; ?>
      <?php P::parcial('recargas/tarjetas', ['productos' => $productos]); ?>
    </div>
  </section>

  <div>
    <form class="card" method="post" action="/recargas/vender" id="formVenta">
      <header class="card-header">Vender</header>
      <div class="card-body">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="producto" id="prodId">

        <div id="sinProd" style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:24px 0">
          Elige un producto del catálogo.
        </div>

        <div id="conProd" hidden>
          <div style="padding:13px 15px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
               margin-bottom:16px">
            <img id="prodImg" src="" alt="" hidden
                 style="height:40px;max-width:100%;object-fit:contain;margin-bottom:8px"
                 onerror="this.hidden=true">
            <b id="prodNombre" style="display:block;font-size:13.5px;color:var(--lf-brand-2);
               line-height:1.4"></b>
            <small id="prodId2" style="font-size:11px;color:var(--lf-tinta-4);
               font-family:var(--lf-mono)"></small>
          </div>

          <label class="form-label" for="cuenta">Número o referencia</label>
          <input class="form-control lf-mono" id="cuenta" name="cuenta" inputmode="numeric"
                 placeholder="10 dígitos, o la referencia del recibo" required>
          <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:5px">
            Para recargas, el teléfono. Para pago de servicios, el número de cuenta
            que viene en el recibo.
          </p>

          <div id="campoMonto" style="margin-top:14px" hidden>
            <label class="form-label" for="monto">Monto a pagar</label>
            <input class="form-control lf-mono" id="monto" name="monto" type="number"
                   step="0.01" placeholder="0.00">
            <p id="limites" style="font-size:11px;color:var(--lf-tinta-4);margin-top:5px"></p>
          </div>

          <button class="btn btn-primary" type="submit" style="width:100%;margin-top:18px;padding:13px">
            <?= W::icono('cobro','15px') ?>Aplicar</button>

          <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:13px;line-height:1.55">
            <b>Una vez enviada no se puede cancelar:</b> el saldo ya llegó. Revisa el
            número antes de aplicar.
          </p>
        </div>
        <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:14px;line-height:1.5;border-top:1px solid var(--lf-linea);padding-top:12px">
          Las operaciones se pagan del saldo precargado con el proveedor (arriba). Si se agota,
          se rechazan aunque el cliente ya te haya pagado.
        </p>
      </div>
    </form>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Operaciones de hoy</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Las fallidas también salen: es donde se ve si algo está mal</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Producto</th><th>Cuenta</th><th class="text-end">Monto</th>
        <th>Estado</th><th>Folio</th><th>Hora</th></tr></thead>
      <tbody>
      <?php if (!$transacciones): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:30px">
          Todavía no hay operaciones hoy.</td></tr>
      <?php endif; ?>
      <?php foreach ($transacciones as $t):
        $col = ['exitosa'=>'bg-success','fallida'=>'bg-danger',
                'incierta'=>'bg-warning','pendiente'=>'bg-secondary'][$t['estado']] ?? 'bg-secondary'; ?>
        <tr>
          <td data-label="Producto" style="font-size:12.5px">
            <b style="font-weight:600"><?= P::e($t['producto_nombre']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($t['producto_id']) ?></span></td>
          <td data-label="Cuenta" class="lf-mono" style="font-size:12.5px"><?= P::e($t['cuenta']) ?></td>
          <td data-label="Monto" class="text-end lf-mono"><?= D::pesos($t['monto']) ?></td>
          <td data-label="Estado">
            <span class="badge <?= $col ?>"><?= P::e(ucfirst($t['estado'])) ?></span>
            <?php if ($t['mensaje'] && $t['estado'] !== 'exitosa'): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                    max-width:230px;line-height:1.4"><?= P::e($t['mensaje']) ?></span>
            <?php endif; ?></td>
          <td data-label="Folio" class="lf-mono" style="font-size:11.5px">
            <?= P::e($t['folio_proveedor'] ?: '—') ?></td>
          <td data-label="Hora" class="lf-mono" style="font-size:12px">
            <?= date('H:i', strtotime($t['creado_en'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una <b>incierta</b> es la que el proveedor no confirmó a tiempo: pudo haber
    salido. Revisa el saldo (arriba) antes de reintentarla, nunca la repitas a ciegas.
  </div>
</section>

<script>
(function(){
  var $ = function (id) { return document.getElementById(id); };
  var TOKEN = <?= json_encode($token) ?>;
  var sel = null, cat = '', q = '', pag = 1, paginas = <?= (int)$paginasIni ?>, pedido = 0, temporizador = null;

  /* ── El catálogo: búsqueda, categoría y páginas se piden al servidor ──
     Así se consulta la base completa, sea del tamaño que sea. */
  function tarjetas() { return [].slice.call(document.querySelectorAll('#rcGrid .lf-rc')); }
  function pintarPag() {
    $('rcPag').hidden = paginas <= 1;
    $('rcPagN').textContent = pag; $('rcPagT').textContent = paginas;
    $('rcAnt').disabled = pag <= 1; $('rcSig').disabled = pag >= paginas;
  }
  function cargar() {
    var mio = ++pedido;
    var url = '/recargas/productos?p=' + pag + '&cat=' + encodeURIComponent(cat) + '&q=' + encodeURIComponent(q);
    $('rcGrid').style.opacity = '.55';
    fetch(url, {credentials: 'same-origin', headers: {'X-LF-Json': '1'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (mio !== pedido || !j.ok) return;
        $('rcGrid').innerHTML = j.html.trim() !== '' ? j.html
          : '<p class="lf-rc-vacio" style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);padding:30px;font-size:13px">Ningún producto coincide.</p>';
        pag = j.pagina; paginas = j.paginas; pintarPag();
        /* Si el producto elegido sigue en esta página, se conserva marcado. */
        if (sel) tarjetas().forEach(function (t) { if (t.dataset.id === sel.dataset.id) t.classList.add('on'); });
      })
      .catch(function () { /* se queda lo que había */ })
      .then(function () { if (mio === pedido) $('rcGrid').style.opacity = ''; });
  }
  function filtrar() { pag = 1; cargar(); }
  $('rcAnt').addEventListener('click', function () { if (pag > 1) { pag--; cargar(); } });
  $('rcSig').addEventListener('click', function () { if (pag < paginas) { pag++; cargar(); } });
  $('rcBuscar').addEventListener('input', function () {
    q = this.value.trim();
    clearTimeout(temporizador); temporizador = setTimeout(filtrar, 250);
  });
  $('rcBuscar').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); var t = tarjetas()[0]; if (t) elegir(t); }
    if (e.key === 'Escape') { this.value = ''; q = ''; filtrar(); }
  });
  var cats = $('rcCats');
  if (cats) cats.addEventListener('click', function (e) {
    var p = e.target.closest('.lf-pill');
    if (!p) return;
    cat = p.dataset.cat;
    cats.querySelectorAll('.lf-pill').forEach(function (x) { x.classList.toggle('active', x === p); });
    filtrar();
  });

  /* ── Elegir un producto ── */
  function elegir(b){
    tarjetas().forEach(function(x){ x.classList.remove('on'); });
    b.classList.add('on');
    sel = b;
    $('prodId').value = b.dataset.id;
    $('prodNombre').textContent = b.dataset.n;
    $('prodId2').textContent = 'ID ' + b.dataset.id
      + (parseFloat(b.dataset.com) > 0 ? ' · comisión $' + parseFloat(b.dataset.com).toFixed(2) : '');
    var img = $('prodImg');
    if (b.dataset.img) { img.src = b.dataset.img; img.hidden = false; }
    else { img.hidden = true; img.removeAttribute('src'); }
    $('sinProd').hidden = true;
    $('conProd').hidden = false;
    var variable = parseFloat(b.dataset.monto) <= 0;
    var campo = $('campoMonto'), m = $('monto');
    campo.hidden = !variable;
    m.required = variable;
    if (variable) {
      var min = parseFloat(b.dataset.min), max = parseFloat(b.dataset.max), t = [];
      if (min > 0) { m.min = min; t.push('mínimo $' + min.toFixed(2)); }
      if (max > 0) { m.max = max; t.push('máximo $' + max.toFixed(2)); }
      $('limites').textContent = t.join(' · ');
      m.value = '';
    }
    $('cuenta').focus();
    if (window.innerWidth < 941) $('formVenta').scrollIntoView({behavior:'smooth', block:'start'});
  }
  $('rcGrid').addEventListener('click', function (e) {
    var b = e.target.closest('.lf-rc');
    if (b) elegir(b);
  });

  $('formBajar').addEventListener('submit', function () {
    var b = $('btnBajar'); b.disabled = true; b.textContent = 'Bajando…';
  });

  $('formVenta').addEventListener('submit', function(e){
    if (!sel) { e.preventDefault(); return; }
    var c = $('cuenta').value.replace(/\D/g,'');
    if (!confirm('¿Aplicar "' + sel.dataset.n + '" a ' + c + '?\n\nUna vez enviada no se puede cancelar.')) e.preventDefault();
  });

  /* ── El saldo: siempre a la vista, sin botón ── */
  if ($('rcSaldo').dataset.pendiente) {
    fetch('/recargas/saldo', {credentials: 'same-origin', headers: {'X-LF-Json': '1'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) {
          $('rcSaldoValor').textContent = '$' + Number(j.saldo).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
          $('rcSaldoMeta').textContent = 'al ' + j.cuando;
        } else {
          $('rcSaldoValor').textContent = '—';
          $('rcSaldoMeta').textContent = 'No se pudo consultar: ' + (j.mensaje || 'sin respuesta');
          $('rcSaldoMeta').title = j.mensaje || '';
        }
      })
      .catch(function () { $('rcSaldoValor').textContent = '—'; $('rcSaldoMeta').textContent = 'No se pudo consultar ahora.'; });
  }

  /* ── El catálogo viejo se actualiza solo, por detrás ──
     Si nadie ha elegido nada todavía, la rejilla se cambia por la nueva;
     si ya hay un producto elegido, se respeta lo que se está haciendo. */
  <?php if ($refrescar): ?>
  var fd = new FormData(); fd.append('token', TOKEN);
  fetch('/recargas/catalogo', {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-LF-Json': '1'}})
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) return;
      $('rcActualizado').textContent = 'recién actualizado · se actualiza solo';
      if (!sel) cargar();
    })
    .catch(function () { /* se intentará la próxima vez */ });
  <?php endif; ?>

  pintarPag();
})();
</script>