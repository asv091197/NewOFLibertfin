<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
// El renglón que se está editando, si lo hay, de la lista que toque.
$buscar_ed = function ($lista) use ($editar) {
    foreach ($lista as $x) if ((int)$x['id'] === $editar) return $x;
    return null;
};
?>

<div class="lf-pills" style="margin-bottom:18px">
  <?php foreach ($pestanas as $k => $v): ?>
    <a class="lf-pill <?= $pestana===$k?'active':'' ?>" href="/ajustes?t=<?= $k ?>"><?= P::e($v) ?></a>
  <?php endforeach; ?>
  <a class="lf-pill" href="/usuarios">Usuarios</a>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>



<?php /* ═══════════════ EMPRESA ═══════════════ */ if ($pestana === 'empresa'):
$em = $empresa; ?>
<?php if (!$em): ?>
  <div class="alert alert-warning"><?= W::icono('alerta','18px') ?>
    <span>No se pudieron leer los datos de la empresa.</span></div>
<?php else: ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Datos de la empresa</header>
    <div class="card-body">
      <form method="post" action="/ajustes/guardar" enctype="multipart/form-data"
            class="lf-form-g">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="que" value="empresa">
        <div class="todo"><label class="form-label">Razón social</label>
          <input class="form-control" name="nombre_empresa" value="<?= P::e($em['nombre_empresa']) ?>" required></div>
        <div><label class="form-label">RFC</label>
          <input class="form-control" name="rfc" value="<?= P::e($em['rfc'] ?? '') ?>"
                 style="text-transform:uppercase" placeholder="Opcional"></div>
        <div><label class="form-label">Giro</label>
          <input class="form-control" name="giro_comercial" value="<?= P::e($em['giro_comercial'] ?? '') ?>" placeholder="Opcional"></div>
        <div><label class="form-label">Teléfono</label>
          <input class="form-control" name="telefono" value="<?= P::e($em['telefono'] ?? '') ?>" placeholder="Opcional"></div>
        <div class="todo"><label class="form-label">Dirección</label>
          <input class="form-control" name="direccion" value="<?= P::e($em['direccion'] ?? '') ?>" placeholder="Opcional"></div>
        <div><label class="form-label">Contacto</label>
          <input class="form-control" name="nombre_contacto" value="<?= P::e($em['nombre_contacto'] ?? '') ?>" placeholder="Opcional"></div>
        <div><label class="form-label">Correo</label>
          <input class="form-control" type="email" name="email_admin" value="<?= P::e($em['email_admin'] ?? '') ?>" placeholder="Opcional"></div>
        <div class="todo" style="border-top:1px solid var(--lf-linea);padding-top:18px;
                    display:flex;gap:30px;flex-wrap:wrap;align-items:flex-start">

          <div style="flex:1;min-width:250px">
            <label class="form-label">Logotipo</label>
            <div class="lf-foto">
              <span class="prev cuadro" id="prevLogo"
                    style="<?= $marca['logo'] ? "background-image:url('".P::e($marca['logo'])."')" : '' ?>">
                <?= $marca['logo'] ? '' : P::e(mb_strtoupper(mb_substr($em['nombre_empresa'],0,1))) ?></span>
              <div style="flex:1;min-width:0">
                <span class="lf-file">
                  <input type="file" name="logo" id="inpLogo"
                         accept="image/png,image/jpeg,image/webp">
                  <label class="bt" for="inpLogo">Elegir imagen</label>
                  <span class="n" data-vacio="Ninguna imagen elegida">Ninguna imagen elegida</span>
                </span>
                <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:6px;line-height:1.4">
                  Cuadrado, mínimo 128 px. Aparece arriba del menú y en los tickets.
                </p>
                <?php if ($marca['logo']): ?>
                  <label style="font-size:11.5px;color:var(--lf-tinta-3);display:flex;
                         align-items:center;gap:6px;margin-top:6px;cursor:pointer">
                    <input type="checkbox" name="quitar_logo" value="1"> Quitar el logotipo
                  </label>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div style="flex:1;min-width:250px">
            <label class="form-label">Color de la marca</label>
            <div class="lf-colores">
              <?php foreach (array_keys(W::COLORES) as $c): ?>
                <span class="lf-color<?= strtolower($marca['color'])===$c ? ' on' : '' ?>"
                      data-color="<?= $c ?>" style="background:<?= $c ?>"
                      role="button" tabindex="0" aria-label="Color <?= $c ?>"></span>
              <?php endforeach; ?>
              <input class="lf-color-libre" type="color" id="colorLibre"
                     value="<?= P::e($marca['color']) ?>" aria-label="Otro color">
              <input type="hidden" name="marca_color" id="marcaColor" value="<?= P::e($marca['color']) ?>">
            </div>
            <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:10px;line-height:1.45">
              Cambia todo el sistema: botones, gráficas, resaltados. Los tonos
              derivados se calculan solos, así que solo hay que elegir este.
            </p>
          </div>
        </div>

        <div class="todo"><button class="btn btn-primary" type="submit">Guardar</button></div>
        <p class="todo" style="font-size:11.5px;color:var(--lf-tinta-4);margin:0">
          El nombre, el RFC y la dirección salen en los tickets y en las facturas.
        </p>
      </form>

      <script>
      (function(){
        // Vista previa del logo antes de subirlo
        var inp = document.getElementById('inpLogo'), prev = document.getElementById('prevLogo');
        if (inp && prev) inp.addEventListener('change', function(){
          var f = inp.files && inp.files[0];
          if (!f) return;
          prev.style.backgroundImage = "url('" + URL.createObjectURL(f) + "')";
          prev.textContent = '';
        });

        // Color: los redondos y el selector libre escriben el mismo campo
        var oculto = document.getElementById('marcaColor'),
            libre  = document.getElementById('colorLibre'),
            raiz   = document.documentElement;
        function pon(c){
          oculto.value = c;
          if (libre) libre.value = c;
          raiz.style.setProperty('--lf-brand', c);   // se ve al instante
          document.querySelectorAll('.lf-color').forEach(function(s){
            s.classList.toggle('on', s.dataset.color.toLowerCase() === c.toLowerCase());
          });
        }
        document.querySelectorAll('.lf-color').forEach(function(s){
          s.addEventListener('click', function(){ pon(s.dataset.color); });
          s.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pon(s.dataset.color); }
          });
        });
        if (libre) libre.addEventListener('input', function(){ pon(libre.value); });
      })();
      </script>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Cuenta</header>
    <div class="card-body" style="font-size:13px">
      <?php
      $venc = !empty($em['fecha_vencimiento']) ? strtotime($em['fecha_vencimiento']) : null;
      $dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
      foreach ([
        'Plan'        => ucfirst($em['plan'] ?? 'prueba'),
        'Distribuidor'=> $em['no_distribuidor'] ?: null,
      ] as $k => $v): if ($v === null) continue; ?>
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
          <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
          <b style="font-weight:600;text-align:right;font-family:var(--lf-mono);font-size:12.5px">
            <?= P::e($v) ?></b>
        </div>
      <?php endforeach; ?>
      <?php if ($venc): ?>
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
          <span style="color:var(--lf-tinta-3)">Vence</span>
          <b style="font-weight:600;text-align:right"><?= date('d/m/Y', $venc) ?></b>
        </div>
        <div style="margin-top:12px;padding:11px 13px;border-radius:var(--lf-r);font-size:12.5px;
             background:<?= $dias<0?'var(--lf-rojo-soft)':($dias<15?'var(--lf-amb-soft)':'var(--lf-brand-soft)') ?>;
             color:<?= $dias<0?'var(--lf-rojo)':($dias<15?'var(--lf-amb)':'var(--lf-brand-2)') ?>">
          <?php if ($dias < 0): ?>La suscripción venció hace <?= abs($dias) ?> días.
          <?php elseif ($dias < 15): ?>Quedan <?= $dias ?> días de suscripción.
          <?php else: ?>Quedan <?= $dias ?> días de suscripción.<?php endif; ?>
        </div>
      <?php endif; ?>
      <p style="margin-top:14px;padding-top:14px;border-top:1px solid var(--lf-linea);
                font-size:11.5px;color:var(--lf-tinta-4);line-height:1.5">
        El plan y la fecha de vencimiento no se editan aquí:
        cambiarlos dejaría a la empresa sin poder entrar. Eso lo maneja quien
        administra la plataforma.
      </p>
      <?php /* Las preguntas del primer ingreso, otra vez: no borran nada,
               vuelven a aplicar giro, inventario, Caja, IVA y comisiones. */ ?>
      <div class="lf-ajustes-inicio">
        <div><b>Configuración inicial</b>
          <small>Tu giro, si llevas inventario, cómo cobras, el IVA y las comisiones.</small></div>
        <a class="btn btn-secondary btn-sm" href="/bienvenida?repetir=1">Volver a hacerla</a>
      </div>
    </div>
  </section>
</div>
<?php endif; ?>



<?php /* ═══ CÓMO SE APLICAN LOS PAGOS EN LÍNEA ═══ */ ?>
<?php if (!empty($hayLigas)): ?>
<section class="card">
  <header class="card-header">
    <div><span>Pagos en línea</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Qué pasa cuando el proveedor confirma que el cliente pagó</p></div>
  </header>
  <div class="card-body">
    <?php /* `data-guardar` lo envía por detrás y `data-al-elegir` hace
             que baste con escoger la opción. Sin JavaScript sigue siendo
             un formulario normal con su botón, que recarga como antes.
             Ver el layout. */ ?>
    <form method="post" action="/ajustes/aprobacion" class="lf-modos"
          data-guardar data-al-elegir>
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <?php foreach ($modosAprobacion as $k => $m): ?>
        <label class="m<?= $aprobacion===$k ? ' on' : '' ?>">
          <input type="radio" name="modo" value="<?= $k ?>"
                 <?= $aprobacion===$k ? 'checked' : '' ?>>
          <span><b><?= P::e($m[0]) ?></b><small><?= P::e($m[1]) ?></small></span>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-sm lf-sin-js" type="submit">Guardar</button>
      <p data-aviso hidden></p>
    </form>
    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:14px;line-height:1.6;
              padding-top:13px;border-top:1px solid var(--lf-linea)">
      Si solo usas el sistema para llevar el historial, déjalo en
      <b>se aplican solos</b>: el dinero ya entró y anotarlo a mano es trabajo doble.
      <b>Requieren aprobación</b> sirve cuando alguien tiene que ver cada entrada antes
      de darla por buena —varias sucursales, o quien cobra no responde por la caja—.
      Mientras no se apruebe, la venta sigue con saldo y no entra al corte.
    </p>
  </div>
</section>
<?php endif; ?>

<?php /* ═══════════════ CAJA ═══════════════ */ elseif ($pestana === 'caja'):
  $opCaja = \LibertyFin\Datos\ConfigRepo::CAJA;
  /* Cada grupo es su propio formulario con `data-al-elegir`: basta con
     escoger la opción y se guarda (ver el layout). Sin JavaScript queda
     el botón Guardar. */
  $grupoCaja = function ($clave) use ($opCaja, $caja, $token) { ?>
    <form method="post" action="/ajustes/guardar" class="lf-modos" data-guardar data-al-elegir>
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="caja">
      <?php foreach ($opCaja[$clave] as $k => $m): $on = ($caja[$clave] ?? '') === (string)$k; ?>
        <label class="m<?= $on ? ' on' : '' ?>">
          <input type="radio" name="<?= P::e($clave) ?>" value="<?= P::e($k) ?>" <?= $on ? 'checked' : '' ?>>
          <span><b><?= P::e($m[0]) ?></b><small><?= P::e($m[1]) ?></small></span>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-sm lf-sin-js" type="submit">Guardar</button>
      <p data-aviso hidden></p>
    </form>
  <?php }; ?>

<div class="lf-ajustes-caja">
  <section class="card">
    <header class="card-header">
      <div><span>Al cobrar, el anticipo empieza en…</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Lo que aparece solo en «Anticipo que se cobra hoy»</p></div>
    </header>
    <div class="card-body">
      <?php $grupoCaja('anticipo'); ?>
      <p class="lf-ajustes-nota">Con cualquiera de las dos, en Caja están los botones
        <b>Total</b> y <b>50%</b> junto al anticipo para no teclear.</p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <div><span>IVA</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cómo empieza cada venta y con qué tasa</p></div>
    </header>
    <div class="card-body">
      <?php $grupoCaja('iva_inicio'); ?>
      <h4 class="lf-ajustes-sub">Tasa</h4>
      <?php $grupoCaja('iva_tasa'); ?>
      <p class="lf-ajustes-nota">Los precios del catálogo ya incluyen el IVA: encenderlo
        <b>no cambia lo que paga el cliente</b>, dice qué parte de lo cobrado es IVA. De ahí
        salen el IVA de Reportes, el de las facturas, y la base de las comisiones, que se
        calculan sin IVA. En Caja se enciende o apaga en cada venta.</p>
    </div>
  </section>
</div>

<?php /* ═══════════════ MÓDULOS ═══════════════
         Lo que se enciende según el giro, encima del núcleo (Servicio\Modulos).
         Cada uno es su propio formulario: se guarda al mover el interruptor. */
elseif ($pestana === 'modulos'):
  $grupos = [];
  foreach (($modulos ?? []) as $k => $m) $grupos[$m['grupo']][$k] = $m; ?>
<p class="lf-mod-intro">Caja, ventas, clientes, cobranza, corte, gastos, productos y reportes vienen siempre.
  Aquí enciendes lo que tu negocio usa además. Puedes apagar algo cuando quieras: lo que ya registraste no se borra.</p>
<?php foreach ($grupos as $g => $lista): ?>
  <h3 class="lf-mod-grupo"><?= P::e($g) ?></h3>
  <div class="lf-mod-grid">
    <?php foreach ($lista as $k => $m):
      $req = $m['requiere'] ?? null; ?>
      <form method="post" action="/ajustes/guardar" class="lf-mod<?= $m['encendido'] && $m['enPlan'] ? ' on' : '' ?><?= !$m['enPlan'] ? ' candado' : '' ?>"
            data-guardar data-al-elegir>
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="que" value="modulo">
        <input type="hidden" name="clave" value="<?= P::e($k) ?>">
        <span class="ico"><?= W::icono($m['icono'], '19px') ?></span>
        <div class="txt">
          <b><?= P::e($m['nombre']) ?></b>
          <small><?= P::e($m['desc']) ?></small>
          <?php if (!$m['enPlan']): ?>
            <small class="plan"><?= W::icono('alerta', '12px') ?> <?= $m['planes'] ? 'Desde el plan ' . P::e($m['planes'][0]) : 'En un plan superior' ?> ·
              <a href="/cuenta?t=plan">Ver planes</a></small>
          <?php elseif ($req): ?>
            <small class="req">Necesita <?= P::e(\LibertyFin\Servicio\Modulos::CATALOGO[$req]['nombre']) ?>: se enciende con él.</small>
          <?php endif; ?>
        </div>
        <?php if ($m['enPlan']): ?>
          <input type="hidden" name="activo" value="0">
          <label class="lf-sw" title="<?= $m['encendido'] ? 'Apagar' : 'Encender' ?>">
            <input type="checkbox" name="activo" value="1" <?= $m['encendido'] ? 'checked' : '' ?> aria-label="<?= P::e($m['nombre']) ?>">
            <i aria-hidden="true"></i></label>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm lf-sin-js" type="submit">Guardar</button>
        <p data-aviso hidden></p>
      </form>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<script>
/* Encender o apagar sin recargar: la tarjeta cambia al momento y, cuando
   contesta el servidor, todas quedan como de verdad quedaron (encender
   Compras enciende Inventario; apagar Inventario apaga Compras). El menú
   lo cambia el armazón (lfPonerMenu). Una sola vez por pestaña. */
(function () {
  if (window.lfModulosJs) return;
  window.lfModulosJs = true;
  function tarjeta(clave) {
    var i = document.querySelector('.lf-mod input[name="clave"][value="' + clave + '"]');
    return i ? i.closest('.lf-mod') : null;
  }
  function poner(t, on) {
    var cb = t.querySelector('input[type="checkbox"][name="activo"]');
    if (cb) cb.checked = on;
    t.classList.toggle('on', on && !t.classList.contains('candado'));
    var sw = t.querySelector('.lf-sw'); if (sw) sw.title = on ? 'Apagar' : 'Encender';
  }
  document.addEventListener('change', function (e) {
    var t = e.target.closest('.lf-mod');
    if (t && e.target.matches('input[type="checkbox"][name="activo"]')) poner(t, e.target.checked);
  });
  document.addEventListener('lf:guardado', function (e) {
    var f = e.detail.form, d = e.detail.datos;
    if (!f || !f.classList.contains('lf-mod')) return;
    /* El aviso va arriba, flotando: dentro de la tarjeta se encimaba con
       la descripción. El interruptor ya dice cómo quedó. */
    if (window.lfAvisar && d.mensaje) window.lfAvisar(d.mensaje, d.ok ? 'ok' : 'error');
    if (d.ok && d.modulos) {
      Object.keys(d.modulos).forEach(function (k) { var t = tarjeta(k); if (t) poner(t, !!d.modulos[k]); });
    } else if (!d.ok) {
      var cb = f.querySelector('input[type="checkbox"][name="activo"]');
      if (cb) poner(f, !cb.checked);   // no se guardó: vuelve a como estaba
    }
  });
})();
</script>

<?php /* ═══════════════ INVENTARIO ═══════════════ */ elseif ($pestana === 'inventario'):
  $opInv = \LibertyFin\Datos\ConfigRepo::INVENTARIO;
  $grupoInv = function ($clave) use ($opInv, $inventario, $token) { ?>
    <form method="post" action="/ajustes/guardar" class="lf-modos" data-guardar data-al-elegir>
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="inventario">
      <?php foreach ($opInv[$clave] as $k => $m): $on = ($inventario[$clave] ?? '') === (string)$k; ?>
        <label class="m<?= $on ? ' on' : '' ?>">
          <input type="radio" name="<?= P::e($clave) ?>" value="<?= P::e($k) ?>" <?= $on ? 'checked' : '' ?>>
          <span><b><?= P::e($m[0]) ?></b><small><?= P::e($m[1]) ?></small></span>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-sm lf-sin-js" type="submit">Guardar</button>
      <p data-aviso hidden></p>
    </form>
  <?php }; ?>

<div class="lf-ajustes-caja">
  <section class="card">
    <header class="card-header">
      <div><span>Inventario</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Para las empresas que venden productos físicos</p></div>
    </header>
    <div class="card-body">
      <?php $grupoInv('activo'); ?>
      <p class="lf-ajustes-nota">Encendido, aparece <b>Inventario</b> en el menú y, en cada producto,
        la casilla <b>«Lleva existencias»</b>. Solo cuentan los productos que la tengan marcada: los
        servicios se quedan como están. <?= ($inventario['activo'] ?? '') === 'si'
          ? '<a href="/inventario">Ir a Inventario</a>.' : '' ?></p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <div><span>Si en Caja no alcanzan las existencias…</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Por ejemplo, quedan 2 y el cliente lleva 3</p></div>
    </header>
    <div class="card-body">
      <?php $grupoInv('sin_existencia'); ?>
      <p class="lf-ajustes-nota">Recomendado al empezar: <b>avisar y dejar vender</b>. Mientras las
        existencias no estén al día, bloquear deja clientes sin atender por un número mal capturado.</p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <div><span>Cuando algo se acaba o queda poco</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          El aviso sale arriba, en todas las pantallas</p></div>
    </header>
    <div class="card-body">
      <?php $grupoInv('alerta_stock'); ?>
      <p class="lf-ajustes-nota">«Queda poco» es el <b>mínimo</b> que le pongas a cada producto en
        <a href="/servicios">Productos</a>, y puede ser distinto por sucursal. Sin mínimo, un producto
        solo avisa cuando llega a cero.
        <?= ($inventario['activo'] ?? '') === 'si' ? '<a href="/inventario?t=alertas">Ver los avisos de hoy</a>.' : '' ?></p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <div><span>Cuando algo está por caducar</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Solo para los productos marcados como perecederos</p></div>
    </header>
    <div class="card-body">
      <?php $grupoInv('alerta_caducidad'); ?>
      <form method="post" action="/ajustes/guardar" class="lf-form" data-guardar style="margin-top:12px;gap:10px">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="que" value="inventario">
        <div style="width:210px">
          <label class="form-label" for="aCad">Avisar con cuántos días</label>
          <input class="form-control lf-mono" type="number" min="1" max="365" step="1"
                 name="dias_caducidad" id="aCad"
                 value="<?= (int)($inventario['dias_caducidad'] ?? 15) ?>">
        </div>
        <div style="display:flex;align-items:flex-end">
          <button class="btn btn-primary btn-sm" type="submit">Guardar</button>
        </div>
        <p data-aviso hidden></p>
      </form>
      <p class="lf-ajustes-nota">Este número es el general. Cada producto puede traer el suyo: la leche
        avisa con tres días y el aceite con sesenta. Se marca en <a href="/servicios">Productos</a>,
        en <b>«Es perecedero»</b>.</p>
    </div>
  </section>
</div>

<?php /* ═══════════════ SUCURSALES ═══════════════ */ elseif ($pestana === 'sucursales'):
$e = $buscar_ed($sucursales); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e?'serv':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Agregar sucursal' ?></summary>
  <form method="post" action="/ajustes/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="que" value="sucursal">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:1;min-width:180px"><label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="flex:2;min-width:220px"><label class="form-label">Dirección</label>
      <input class="form-control" name="direccion" value="<?= P::e($e['direccion'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="width:150px"><label class="form-label">Teléfono</label>
      <input class="form-control" name="telefono" value="<?= P::e($e['telefono'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="flex:1;min-width:170px"><label class="form-label">Responsable</label>
      <input class="form-control" name="responsable" value="<?= P::e($e['responsable'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Agregar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/ajustes?t=sucursales">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>

<section class="card">
  <header class="card-header">Sucursales</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Sucursal</th><th>Responsable</th><th class="text-end">Usuarios</th>
        <th class="text-end">Ventas</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sucursales as $s): $a = (int)$s['activo']===1; ?>
        <tr style="<?= $a?'':'opacity:.55' ?>">
          <td data-label="Sucursal"><b style="font-weight:600"><?= P::e($s['nombre']) ?></b>
            <?php if ($s['direccion']): ?><span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($s['direccion']) ?></span><?php endif; ?></td>
          <td data-label="Responsable" style="font-size:12.5px"><?= P::e($s['responsable'] ?: '—') ?></td>
          <td data-label="Usuarios" class="text-end lf-mono"><?= (int)$s['usuarios'] ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Estado"><?= $a?'<span class="badge bg-success">Activa</span>'
                                     :'<span class="badge bg-secondary">Inactiva</span>' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/ajustes?t=sucursales&editar=<?= (int)$s['id'] ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-alt" data-que="sucursal"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                    data-activo="<?= $a?1:0 ?>"><?= $a?'<span class="lf-signo">&times;</span>':'✓' ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>


<?php /* ═══════════════ ÁREAS Y COLABORADORES ═══════════════ */ elseif ($pestana === 'comisiones'):
$ea = $buscar_ed($areas); $ec = $buscar_ed($colaboradores); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?>
    <?= $ec ? 'Editar a ' . P::e($ec['nombre']) : ($ea ? 'Editar el área ' . P::e($ea['nombre']) : 'Agregar colaborador o área') ?></summary>
  <div style="padding:18px 20px;display:flex;gap:26px;flex-wrap:wrap">
    <form method="post" action="/ajustes/guardar" class="lf-campos" style="flex:2;min-width:300px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="colaborador">
      <?php if ($ec): ?><input type="hidden" name="id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
      <div style="flex:2;min-width:180px"><label class="form-label">Colaborador</label>
        <input class="form-control" name="nombre" value="<?= P::e($ec['nombre'] ?? '') ?>" required></div>
      <?php /* SE ESCRIBE, NO SE ELIGE DE UNA LISTA QUE PUEDE ESTAR VACÍA.
           Con un desplegable, quien daba de alta a su primer colaborador
           se encontraba la lista en blanco y tenía que irse a crear el
           área antes de poder seguir. Aquí se escribe: si el área existe
           se usa esa —el desplegable de abajo la sugiere— y si no, se
           crea al guardar. */
      $areaActual = '';
      if (isset($ec['area_id'])) {
          foreach ($areas as $a) {
              if ((int)$a['id'] === (int)$ec['area_id']) { $areaActual = $a['nombre']; break; }
          }
      } ?>
      <div style="flex:1;min-width:150px"><label class="form-label" for="colArea">Área</label>
        <input class="form-control" name="area_nombre" id="colArea" list="lfAreasColab"
               value="<?= P::e($areaActual) ?>" required autocomplete="off"
               placeholder="<?= $areas ? P::e($areas[0]['nombre']) : 'Legal, Contabilidad…' ?>">
        <datalist id="lfAreasColab">
          <?php foreach ($areas as $a): ?>
            <option value="<?= P::e($a['nombre']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <small class="lf-pista-area"><?= $areas
          ? 'Si escribes una que no existe, se crea.'
          : 'Todavía no hay áreas: la que escribas se crea sola.' ?></small></div>
      <button class="btn btn-primary" type="submit"><?= $ec?'Guardar':'Agregar' ?></button>
    </form>

    <form method="post" action="/ajustes/guardar" class="lf-campos" style="flex:1;min-width:230px;display:flex;gap:10px;align-items:flex-end;
          border-left:1px solid var(--lf-linea);padding-left:24px">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="area">
      <?php if ($ea): ?><input type="hidden" name="id" value="<?= (int)$ea['id'] ?>"><?php endif; ?>
      <div style="flex:1"><label class="form-label">Área nueva</label>
        <input class="form-control" name="nombre" value="<?= P::e($ea['nombre'] ?? '') ?>"
               placeholder="Ej. Legal" required></div>
      <button class="btn btn-secondary" type="submit"><?= $ea?'Guardar':'Crear' ?></button>
    </form>
  </div>
</details>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Colaboradores</header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Nombre</th><th>Área</th><th class="text-end">% usual</th>
          <th class="text-end">Devengado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($colaboradores as $c): $a = (int)$c['activo']===1;
              $sis = $c['nombre'] === 'POR ASIGNAR'; ?>
          <tr style="<?= $a?'':'opacity:.55' ?>">
            <td data-label="Nombre"><b style="font-weight:600"><?= P::e($c['nombre']) ?></b>
              <?php if ($sis): ?><span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                del sistema · marca comisiones sin dueño</span><?php endif; ?></td>
            <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area']) ?></span></td>
            <td data-label="% usual" class="text-end lf-mono">
              <?= $c['pct_usual'] !== null ? preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format((float)$c['pct_usual'], 4, '.', '')).'%' : '—' ?></td>
            <td data-label="Devengado" class="text-end lf-mono" style="font-weight:700">
              <?= D::pesos($c['devengado']) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if (!$sis): ?>
                <a class="lf-btn-ghost" href="/ajustes?t=comisiones&editar=<?= (int)$c['id'] ?>"
                   title="Editar"><?= W::icono('cliente','15px') ?></a>
                <button type="button" class="lf-btn-ghost lf-alt" data-que="colaborador"
                        data-id="<?= (int)$c['id'] ?>" data-nombre="<?= P::e($c['nombre']) ?>"
                        data-activo="<?= $a?1:0 ?>"><?= $a?'<span class="lf-signo">&times;</span>':'✓' ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      El "% usual" sale de su historial, no de una regla fija. Es el que el
      sistema sugiere al asignar una comisión nueva.
    </div>
  </section>

  <section class="card">
    <header class="card-header">Áreas</header>
    <div style="padding:0 10px 8px">
      <?php foreach ($areas as $a): ?>
        <div class="lf-row">
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($a['nombre']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= (int)$a['colaboradores'] ?> colaborador<?= $a['colaboradores']==1?'':'es' ?></small>
          </span>
          <b class="lf-mono" style="font-size:13px"><?= D::corto($a['asignado']) ?></b>
          <a class="lf-btn-ghost" href="/ajustes?t=comisiones&editar=<?= (int)$a['id'] ?>"
             title="Renombrar"><?= W::icono('serv','15px') ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>


<?php /* ═══════════════ INTEGRACIONES ═══════════════ */ elseif ($pestana === 'integraciones'):
$listas = 0; foreach ($integraciones as $i) if ($i['activa']) $listas++;
?>
<?php $arch = \LibertyFin\Servicio\Integraciones::archivo(); ?>

<?php if (!$arch['existe']): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>No existe el archivo de credenciales.</b><br>
    Se buscó en <code><?= P::e($arch['ruta']) ?></code>.<br>
    Copia <code>config/integraciones.php.ejemplo</code> como
    <code>config/integraciones.php</code>. Editar el <code>.ejemplo</code> no
    sirve: el sistema no lo lee.</span>
</div>
<?php else: ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Leyendo <code><?= P::e(basename($arch['ruta'])) ?></code> ·
    <?= (int)$arch['bloques'] ?> servicios declarados.
    Las credenciales viven fuera de la carpeta pública y <b>nunca se muestran
    aquí</b>: esta pantalla solo dice si están puestas.</span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= $listas ?></div>
    <div class="stat-label">Integraciones activas</div>
    <div class="stat-meta">de <?= count($integraciones) ?> disponibles</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= count($integraciones) - $listas ?></div>
    <div class="stat-label">Pendientes</div>
    <div class="stat-meta">sin credenciales</div>
  </div>
</div>

<section class="card">
  <header class="card-header">Servicios externos</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Servicio</th><th>Para qué</th><th>Estado</th><th>Falta</th></tr></thead>
      <tbody>
      <?php foreach ($integraciones as $k => $i): ?>
        <tr style="<?= $i['activa'] ? '' : 'opacity:.7' ?>">
          <td data-label="Servicio">
            <b style="font-weight:600"><?= P::e($i['nombre']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                  font-family:var(--lf-mono)"><?= P::e($k) ?></span>
          </td>
          <td data-label="Para qué" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($i['para']) ?></td>
          <td data-label="Estado">
            <?php if ($i['activa'] && $i['sandbox']): ?>
              <span class="badge bg-warning">Pruebas</span>
            <?php elseif ($i['activa']): ?>
              <span class="badge bg-success">Activa</span>
            <?php elseif ($i['lista']): ?>
              <span class="badge bg-secondary">Configurada, apagada</span>
            <?php else: ?>
              <span class="badge bg-secondary">Pendiente</span>
            <?php endif; ?>
          </td>
          <td data-label="Falta" style="font-size:12px;color:var(--lf-tinta-4)">
            <?php if ($i['faltan']): ?>
              <span class="lf-mono"><?= P::e(implode(', ', $i['faltan'])) ?></span>
            <?php elseif (!empty($i['aviso'])): ?>
              <?php /* Encendida pero a medias. Sin esto solo se descubre
                       el día que un cliente paga y el pago no aparece. */ ?>
              <span style="color:var(--lf-amb)"><?= P::e($i['aviso']) ?></span>
            <?php elseif (!$i['activa']): ?>
              poner <span class="lf-mono">'activo' =&gt; true</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una integración sin credenciales queda apagada sola: su sección no aparece en
    el menú y sus rutas responden 404. No hay que desactivar nada a mano.
  </div>
</section>

<section class="card">
  <header class="card-header">Cómo se configuran</header>
  <div class="card-body" style="font-size:13px;line-height:1.7;color:var(--lf-tinta-2)">
    <p style="margin-bottom:12px">
      1. Copia <code>config/integraciones.php.ejemplo</code> como
      <code>config/integraciones.php</code>.
    </p>
    <p style="margin-bottom:12px">
      2. Llena el bloque del servicio que vayas a usar y pon
      <code>'activo' =&gt; true</code>.
    </p>
    <p style="margin-bottom:12px">
      3. Déjalo en <code>'sandbox' =&gt; true</code> hasta haber probado. En pruebas
      nada cobra ni se envía de verdad.
    </p>
    <p style="margin:0;padding-top:12px;border-top:1px solid var(--lf-linea);
              font-size:12px;color:var(--lf-tinta-4)">
      Agrega <code>config/integraciones.php</code> al <code>.gitignore</code>. En el
      sistema anterior las credenciales de Emida estaban escritas dentro de
      <code>EmidaServicios/inicio.php</code>, y por eso acabaron en el repositorio.
    </p>
  </div>
</section>


<?php /* ═══════════════ API ═══════════════
         Las llaves con las que otros sistemas (una tienda en línea, un
         sistema contable, una app) entran a la API de esta empresa. Cada
         llave tiene un rol y puede lo mismo que un usuario de ese rol. */
elseif ($pestana === 'api'):
$baseApi = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio') . '/api/v1'; ?>

<?php if ($llaveNueva): ?>
<section class="card" style="border-color:var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)">
  <header class="card-header">Llave nueva: «<?= P::e($llaveNueva['nombre']) ?>»</header>
  <div class="card-body">
    <p style="margin:0 0 12px;font-size:13px;color:var(--lf-tinta-2)">
      <b>Cópiala ahora y guárdala en un lugar seguro.</b> Es la única vez que se muestra:
      LibertyFin solo guarda una huella para reconocerla. Si se pierde, revócala y crea otra.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input class="form-control lf-mono" id="llaveNueva" readonly value="<?= P::e($llaveNueva['token']) ?>"
             style="flex:1;min-width:260px;font-size:13px" onclick="this.select()">
      <button type="button" class="btn btn-primary" id="copiarLlave">Copiar</button>
    </div>
    <p style="margin:12px 0 0;font-size:12px;color:var(--lf-tinta-3)">
      Se usa en la cabecera <code>Authorization: Bearer …</code>. Prueba:
      <code class="lf-mono">curl -H "Authorization: Bearer <?= P::e(substr($llaveNueva['token'], 0, 12)) ?>…" <?= P::e($baseApi) ?>/yo</code></p>
  </div>
  <script>
  document.getElementById('copiarLlave').addEventListener('click', function () {
    var i = document.getElementById('llaveNueva'), b = this;
    i.select();
    try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); }
    b.textContent = 'Copiada'; setTimeout(function () { b.textContent = 'Copiar'; }, 1600);
  });
  </script>
</section>
<?php endif; ?>

<details class="lf-alta" <?= ($abrir || !$llaves) ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?> Crear una llave</summary>
  <form method="post" action="/ajustes/api/crear" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="flex:2;min-width:220px"><label class="form-label">Para qué sistema es</label>
      <input class="form-control" name="nombre" maxlength="80" required
             placeholder="Ej. Tienda en línea, Sistema contable"></div>
    <div style="flex:1;min-width:180px"><label class="form-label">Puede lo mismo que un</label>
      <select class="form-select" name="rol">
        <?php foreach ($rolesApi as $k => $rot): ?>
          <option value="<?= P::e($k) ?>" <?= $k === 'cajero' ? 'selected' : '' ?>><?= P::e($rot) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><button class="btn btn-primary" type="submit">Crear llave</button></div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0;line-height:1.55">
      Elige el rol más bajo que le alcance al sistema. Con <b>Cajero</b> puede ver ventas y
      clientes y registrar abonos; con <b>Administrador</b>, además, cobranza y comisiones;
      con <b>Inventario</b>, solo el catálogo. Cada movimiento queda en la Bitácora como
      «API · nombre de la llave».</p>
  </form>
</details>

<section class="card">
  <header class="card-header">Llaves de la API</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Sistema</th><th>Llave</th><th>Rol</th><th>Creada</th><th>Último uso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$llaves): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Todavía no hay llaves. Crea una arriba cuando un sistema necesite conectarse.</td></tr>
      <?php endif; ?>
      <?php foreach ($llaves as $l): $viva = empty($l['revocada_en']); ?>
        <tr style="<?= $viva ? '' : 'opacity:.55' ?>">
          <td data-label="Sistema"><b style="font-weight:600"><?= P::e($l['nombre']) ?></b></td>
          <td data-label="Llave" class="lf-mono" style="font-size:12px"><?= P::e($l['prefijo']) ?>…</td>
          <td data-label="Rol"><span class="badge bg-secondary"><?= P::e($rolesApi[$l['rol']] ?? $l['rol']) ?></span></td>
          <td data-label="Creada" style="font-size:12.5px"><?= P::e(date('d/m/Y', strtotime($l['creada_en']))) ?>
            <?php if ($l['creada_por_nombre']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px"><?= P::e($l['creada_por_nombre']) ?></span>
            <?php endif; ?></td>
          <td data-label="Último uso" style="font-size:12.5px">
            <?= $l['ultimo_uso'] ? P::e(date('d/m/Y H:i', strtotime($l['ultimo_uso']))) : '<span style="color:var(--lf-tinta-4)">Nunca</span>' ?></td>
          <td data-label="Estado">
            <span class="badge <?= $viva ? 'bg-success' : 'bg-danger' ?>"><?= $viva ? 'Vigente' : 'Revocada' ?></span></td>
          <td style="text-align:right">
            <?php if ($viva): ?>
              <form method="post" action="/ajustes/api/revocar" style="margin:0"
                    onsubmit="return confirm('¿Revocar la llave «<?= P::e(addslashes($l['nombre'])) ?>»? El sistema que la usa deja de poder entrar en ese momento. No se puede deshacer.')">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <button class="btn btn-secondary btn-sm lf-btn-peligro" type="submit">Revocar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    <span>Dirección de la API: <code class="lf-mono"><?= P::e($baseApi) ?></code>.
      La guía para quien integra está en <code>docs/api</code> del repositorio.</span>
  </div>
</section>

<?php /* ═══════════════ CATEGORÍAS ═══════════════ */ else:
$e = $buscar_ed($categorias); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e?'serv':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Agregar categoría' ?></summary>
  <form method="post" action="/ajustes/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="que" value="categoria">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:1;min-width:200px"><label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="flex:2;min-width:240px"><label class="form-label">Descripción</label>
      <input class="form-control" name="descripcion" value="<?= P::e($e['descripcion'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Agregar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/ajustes?t=categorias">Cancelar</a><?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      Las categorías agrupan los productos del catálogo. Una categoría con
      productos no se puede quitar: sus ventas quedarían sin clasificar.
    </p>
  </form>
</details>

<section class="card">
  <header class="card-header">Categorías de producto</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Categoría</th><th>Descripción</th>
        <th class="text-end">Productos</th><th></th></tr></thead>
      <tbody>
      <?php if (!$categorias): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay categorías. Agrega la primera arriba.</td></tr>
      <?php endif; ?>
      <?php foreach ($categorias as $c): ?>
        <tr>
          <td data-label="Categoría"><b style="font-weight:600"><?= P::e($c['nombre']) ?></b></td>
          <td data-label="Descripción" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($c['descripcion'] ?: '—') ?></td>
          <td data-label="Productos" class="text-end">
            <?php if ((int)$c['servicios'] > 0): ?>
              <a href="/servicios" class="badge bg-secondary" style="text-decoration:none">
                <?= (int)$c['servicios'] ?></a>
            <?php else: ?><span style="color:var(--lf-tinta-4)">0</span><?php endif; ?>
          </td>
          <td style="text-align:right">
            <a class="lf-btn-ghost" href="/ajustes?t=categorias&editar=<?= (int)$c['id'] ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<form method="post" action="/ajustes/alternar" id="formAlt" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="que" id="altQue">
  <input type="hidden" name="id" id="altId">
</form>
<script>
document.querySelectorAll('.lf-alt').forEach(function(b){
  b.addEventListener('click', function(){
    var act = b.dataset.activo === '1';
    if (!confirm((act ? '¿Desactivar ' : '¿Activar ') + b.dataset.nombre + '?')) return;
    document.getElementById('altQue').value = b.dataset.que;
    document.getElementById('altId').value = b.dataset.id;
    document.getElementById('formAlt').submit();
  });
});
</script>

<script>
/* ══════════════════════════════════════════════════════
   UN MÓDULO SE ENCIENDE TOCÁNDOLO
   El interruptor mide veinte píxeles; en un teléfono se falla más de
   lo que se acierta. La tarjeta entera es el blanco. El interruptor
   sigue ahí —y sigue siendo el que manda— para quien use teclado o
   lector de pantalla.
   ══════════════════════════════════════════════════════ */
(function () {
  document.addEventListener('click', function (e) {
    var tarjeta = e.target.closest('.lf-mod');
    if (!tarjeta || tarjeta.classList.contains('candado')) return;
    /* Si tocaron el propio interruptor, su etiqueta o el enlace de
       planes, ya hacen lo suyo: duplicarlo lo apagaría y encendería
       en el mismo gesto. */
    if (e.target.closest('input,a,button,label')) return;
    var sw = tarjeta.querySelector('input[type=checkbox]');
    if (!sw || sw.disabled) return;
    sw.checked = !sw.checked;
    /* Se avisa como si lo hubiera tocado una persona: de ahí cuelga el
       guardado sin recargar del layout. */
    sw.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
</script>