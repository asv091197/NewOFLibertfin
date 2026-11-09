<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Envios as E;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
// La vista no confía en que le llegue todo: si un día alguien la llama
// sin destino o sin bultos, se pinta un formulario en blanco y no una
// pantalla llena de avisos de PHP.
$d = is_array($destino ?? null) ? $destino : [];
foreach (['nombre','telefono','email','calle','numero','colonia',
          'ciudad','estado','cp','pais','referencia'] as $campo) {
    if (!isset($d[$campo])) $d[$campo] = '';
}
if ($d['pais'] === '') $d['pais'] = 'MX';
$paquetes = (is_array($paquetes ?? null) && $paquetes) ? $paquetes
          : [['tipo'=>'box','contenido'=>'Mercancía','valor'=>0,
              'peso'=>1,'largo'=>30,'ancho'=>20,'alto'=>15]];
$venta   = is_array($venta ?? null) ? $venta : null;
$ventaId = $venta ? (int)($venta['id'] ?? 0) : 0;
// La ficha del cliente guarda «Jalisco» —así lo pide el CFDI— pero la
// paquetería quiere «JA». Enseñar el nombre largo en un campo que solo
// acepta dos letras confunde: se deja vacío y lo llena el código postal.
$estadoPre = mb_strlen(trim((string)$d['estado'])) === 2 ? mb_strtoupper(trim($d['estado'])) : '';
?>

<div class="lf-doc-acciones">
  <a class="btn btn-secondary" href="/envios"><?= W::icono('atras','14px') ?> Envíos</a>
  <?php if ($venta): ?>
    <span class="esp"></span>
    <a class="btn btn-secondary" href="/ventas/<?= $ventaId ?>">Ver la venta <?= P::e($venta['codigo_venta']) ?></a>
  <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($error) ?></span>
</div>
<?php endif; ?>

<?php /* El catálogo de CPs no pudo confirmar algo, pero se siguió
         adelante con lo que escribió el usuario. No es un error: es un
         «fíjate en esto antes de pagar la guía». */ ?>
<?php if (!empty($avisoCp)): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($avisoCp) ?></span>
</div>
<?php endif; ?>

<?php if ($faltaOrigen !== ''): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><?= P::e($faltaOrigen) ?>
    <a href="/envios/origenes">Capturar el domicilio de salida</a>.</span>
</div>
<?php endif; ?>

<?php if ($sandbox): ?>
<p class="pista" style="margin:-6px 0 14px"><b>Modo de pruebas:</b> la guía que salga no sirve para mandar nada.</p>
<?php endif; ?>

<form method="post" action="/envios/cotizar" class="lf-form" id="fEnvio">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="venta_id" value="<?= $ventaId ?>">

  <section class="card" style="padding:16px 18px;margin-bottom:16px">
    <h3 style="margin:0 0 4px">A dónde va</h3>
    <p class="pista" style="margin:0 0 14px">
      Escribe el código postal y la ciudad y el estado se llenan solos.
      La paquetería usa claves de dos letras —Jalisco es <b>JA</b>, la Ciudad de México
      es <b>CX</b>—, así que es mejor no teclearlas a mano.
    </p>

    <div class="lf-grid2">
      <label>Quien recibe
        <input name="d_nombre" value="<?= P::e($d['nombre']) ?>" maxlength="120" required></label>
      <label>Teléfono
        <input name="d_telefono" value="<?= P::e($d['telefono']) ?>" maxlength="30"
               placeholder="10 dígitos"></label>
      <label>Correo <small class="pista">(para los avisos de la paquetería)</small>
        <input type="email" name="d_email" value="<?= P::e($d['email']) ?>" maxlength="120"></label>
      <label>Código postal
        <input name="d_cp" id="dCp" value="<?= P::e($d['cp']) ?>" maxlength="10"
               inputmode="numeric" required>
        <small class="pista" id="dCpAviso"></small></label>
      <label>Calle
        <input name="d_calle" value="<?= P::e($d['calle']) ?>" maxlength="160" required></label>
      <label>Número
        <input name="d_numero" value="<?= P::e($d['numero']) ?>" maxlength="30"></label>
      <?php /* La lista se llena con las colonias que el catálogo
               devuelve para ese CP. Es `list`, no `select`: si el
               catálogo no contesta, sigue siendo un campo de texto
               normal y nadie se queda atorado. */ ?>
      <label>Colonia
        <input name="d_colonia" id="dColonia" list="dColonias"
               value="<?= P::e($d['colonia']) ?>" maxlength="120"
               autocomplete="off">
        <datalist id="dColonias"></datalist></label>
      <label>Ciudad o municipio
        <input name="d_ciudad" id="dCiudad" value="<?= P::e($d['ciudad']) ?>" maxlength="120" required></label>
      <?php /* maxlength=2 en vez de readonly: así el código postal lo
               llena solo, pero si el catálogo no contesta todavía se
               puede escribir a mano. Readonly dejaba un callejón sin
               salida el día que Envia se cayera. */ ?>
      <label>Estado <small class="pista">(clave de 2 letras)</small>
        <input name="d_estado" id="dEstado" value="<?= P::e($estadoPre) ?>" maxlength="2"
               style="text-transform:uppercase" placeholder="JA" required></label>
      <label>Referencia <small class="pista">(entre qué calles, color de la casa)</small>
        <input name="d_referencia" value="<?= P::e($d['referencia']) ?>" maxlength="160"></label>
    </div>
    <input type="hidden" name="d_pais" value="<?= P::e($d['pais'] ?: 'MX') ?>">
  </section>

  <section class="card" style="padding:16px 18px;margin-bottom:16px">
    <h3 style="margin:0 0 4px">Qué se manda</h3>
    <p class="pista" style="margin:0 0 14px">
      El peso y las medidas son lo que decide el precio. Mide la caja ya cerrada:
      si al recogerlo no coincide, la paquetería recotiza y cobra la diferencia.
    </p>
    <div class="table-responsive">
      <table class="table" id="tPaq">
        <thead><tr><th>Qué es</th><th>Tipo</th><th>Peso (kg)</th>
                   <th>Largo</th><th>Ancho</th><th>Alto</th><th>Valor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($paquetes as $p): ?>
          <tr>
            <td><input name="p_contenido[]" value="<?= P::e($p['contenido'] ?? 'Mercancía') ?>" maxlength="60"></td>
            <td><select name="p_tipo[]">
                <?php foreach ($tipos as $k => $t): ?>
                  <option value="<?= P::e($k) ?>" <?= ($p['tipo'] ?? 'box') === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
                <?php endforeach; ?></select></td>
            <td><input name="p_peso[]" type="number" step="0.01" min="0.01" style="width:90px"
                       value="<?= P::e((string)($p['peso'] ?? 1)) ?>" required></td>
            <td><input name="p_largo[]" type="number" step="1" min="1" style="width:80px"
                       value="<?= P::e((string)($p['largo'] ?? 30)) ?>"></td>
            <td><input name="p_ancho[]" type="number" step="1" min="1" style="width:80px"
                       value="<?= P::e((string)($p['ancho'] ?? 20)) ?>"></td>
            <td><input name="p_alto[]" type="number" step="1" min="1" style="width:80px"
                       value="<?= P::e((string)($p['alto'] ?? 15)) ?>"></td>
            <td><input name="p_valor[]" type="number" step="0.01" min="0" style="width:100px"
                       value="<?= P::e((string)($p['valor'] ?? 0)) ?>"></td>
            <td><button type="button" class="lf-btn-ghost" data-quitar aria-label="Quitar">✕</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="bAgregar">
      <?= W::icono('mas','13px') ?> Otro bulto</button>
    <p class="pista" style="margin:10px 0 0">
      Las medidas van en centímetros y el peso en kilos.
      El <b>valor</b> es lo que la paquetería repone si se pierde.
    </p>
  </section>

  <section class="card" style="padding:16px 18px;margin-bottom:16px">
    <label>Nota para esta guía <small class="pista">(solo se ve aquí dentro)</small>
      <input name="nota" value="<?= P::e($nota ?? '') ?>" maxlength="255"></label>
  </section>

  <div class="lf-doc-acciones">
    <button class="btn btn-primary" type="submit" <?= $listo ? '' : 'disabled' ?>>
      <?= W::icono('buscar','14px') ?> Ver precios</button>
    <?php if (!$listo): ?>
      <span class="pista">Falta el token de Envia en <code>config/integraciones.php</code>.</span>
    <?php endif; ?>
  </div>
</form>

<?php if (is_array($opciones) && $opciones): ?>
<section class="card" style="padding:16px 18px;margin-top:18px">
  <h3 style="margin:0 0 4px">Con quién lo mandamos</h3>
  <p class="pista" style="margin:0 0 14px">
    Al elegir una se <b>compra</b> la guía y se carga a tu cuenta de Envia.
    El tiempo de entrega es el que promete cada paquetería, no una garantía.
  </p>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Paquetería</th><th>Servicio</th><th>Llega</th>
                 <th class="text-end">Precio</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($opciones as $o): ?>
        <tr>
          <td data-label="Paquetería"><b><?= P::e(E::carrier($o['carrier'])) ?></b></td>
          <td data-label="Servicio"><?= P::e($o['descripcion']) ?>
            <?php /* Se avisa ANTES de apretar «Generar guía»: con estos
                     servicios el paquete no lo recogen, lo llevas tú. */
            $avisoSuc = !empty($o['requiere_sucursal']) ? E::avisoSucursal($o['service']) : '';
            if ($avisoSuc !== ''): ?>
              <small class="lf-inv-cod" style="display:block;color:var(--lf-ambar,#b45309)">
                <?= W::icono('alerta','11px') ?> <?= P::e($avisoSuc) ?></small>
            <?php endif; ?>
            <?php /* Ya se intentó y contestó que solo cotiza. Se marca
                     en vez de esconderla: el precio sigue sirviendo para
                     comparar, y mañana puede estar habilitada. */
            if (E::esSoloCotiza($o['carrier'], $o['service'])): ?>
              <small class="lf-inv-cod" style="display:block;color:var(--lf-rojo,#b91c1c)">
                <?= W::icono('alerta','11px') ?> Ya la probaste: solo cotiza, no genera guías.</small>
            <?php endif; ?></td>
          <td data-label="Llega"><?= P::e($o['dias'] ?: '—') ?></td>
          <td data-label="Precio" class="text-end lf-mono"><b><?= D::pesos($o['precio']) ?></b>
            <?php if ($o['moneda'] !== 'MXN'): ?> <small><?= P::e($o['moneda']) ?></small><?php endif; ?></td>
          <td class="lf-inv-acc">
            <form method="post" action="/envios/generar"
                  onsubmit="return confirm('Se va a comprar la guía de <?= P::e(E::carrier($o['carrier'])) ?> por <?= P::e(D::pesos($o['precio'])) ?>.\n\nEsto se cobra a tu cuenta de Envia. ¿Seguimos?')">
              <input type="hidden" name="token" value="<?= P::e($token) ?>">
              <input type="hidden" name="venta_id" value="<?= $ventaId ?>">
              <input type="hidden" name="carrier" value="<?= P::e($o['carrier']) ?>">
              <input type="hidden" name="servicio" value="<?= P::e($o['service']) ?>">
              <input type="hidden" name="servicio_desc" value="<?= P::e($o['descripcion']) ?>">
              <input type="hidden" name="nota" value="<?= P::e($nota ?? '') ?>">
              <?php /* El destino y los bultos viajan copiados: generar
                       tiene que mandar a Envia exactamente lo mismo que
                       se cotizó, o el precio de la guía no es el que se
                       acaba de enseñar. */ ?>
              <?php foreach (['nombre','telefono','email','calle','numero','colonia',
                              'ciudad','estado','cp','pais','referencia'] as $c): ?>
                <input type="hidden" name="d_<?= $c ?>" value="<?= P::e((string)$d[$c]) ?>">
              <?php endforeach; ?>
              <?php foreach ($paquetes as $p): ?>
                <input type="hidden" name="p_contenido[]" value="<?= P::e((string)($p['contenido'] ?? '')) ?>">
                <input type="hidden" name="p_tipo[]"  value="<?= P::e((string)($p['tipo'] ?? 'box')) ?>">
                <input type="hidden" name="p_peso[]"  value="<?= P::e((string)($p['peso'] ?? 1)) ?>">
                <input type="hidden" name="p_largo[]" value="<?= P::e((string)($p['largo'] ?? 1)) ?>">
                <input type="hidden" name="p_ancho[]" value="<?= P::e((string)($p['ancho'] ?? 1)) ?>">
                <input type="hidden" name="p_alto[]"  value="<?= P::e((string)($p['alto'] ?? 1)) ?>">
                <input type="hidden" name="p_valor[]" value="<?= P::e((string)($p['valor'] ?? 0)) ?>">
              <?php endforeach; ?>
              <?php /* Con los servicios «ocurre» hay que decir a qué
                       mostrador vas a llevar el paquete. La lista se pide
                       al abrirla, no al cargar la pantalla: son llamadas
                       a Envia y la mayoría de las veces no hace falta. */
              if (!empty($o['requiere_sucursal'])): ?>
                <select name="sucursal_envia" required
                        data-sucursales="<?= P::e($o['carrier']) ?>"
                        style="max-width:230px;margin-bottom:6px">
                  <option value="">¿A qué sucursal lo llevas?</option>
                </select>
              <?php endif; ?>
              <button class="btn btn-primary btn-sm" type="submit">Generar guía</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php /* Las que no se pueden convertir en guía. Van APARTE y
           plegadas: no estorban al elegir, pero el precio sigue a la
           vista por si conviene ir a la sucursal a mano. */
  if (!empty($apartadas)): ?>
  <details style="margin-top:14px;border-top:1px solid var(--lf-linea);padding-top:12px">
    <summary style="cursor:pointer;font-size:12.5px;color:var(--lf-tinta-3)">
      <?= count($apartadas) ?>
      <?= count($apartadas) === 1 ? 'opción más barata que no se puede usar desde aquí'
                                  : 'opciones que no se pueden usar desde aquí' ?>
      — ver por qué
    </summary>
    <div class="table-responsive" style="margin-top:10px">
      <table class="table">
        <tbody>
        <?php foreach ($apartadas as $o): ?>
          <tr style="opacity:.75">
            <td><b><?= P::e(E::carrier($o['carrier'])) ?></b>
              <small class="lf-inv-cod" style="display:block"><?= P::e($o['descripcion']) ?></small></td>
            <td class="text-end lf-mono"><?= D::pesos($o['precio']) ?></td>
            <td style="font-size:11.5px;color:var(--lf-tinta-4);max-width:360px">
              <?= P::e($o['motivo']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="pista" style="margin:8px 0 0">
      Si una de éstas te conviene, puedes generarla desde el panel de Envia.com
      y capturar aquí la guía a mano.
    </p>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* Ni una sola usable, pero sí apartadas: hay que decirlo fuerte,
         porque la pantalla se vería vacía sin explicación. */
if (empty($opciones) && !empty($apartadas)): ?>
<section class="card" style="padding:16px 18px;margin-top:18px">
  <h3 style="margin:0 0 4px">Hay precios, pero ninguno se puede usar</h3>
  <p class="pista" style="margin:0 0 12px">
    Las <?= count($apartadas) ?> paqueterías que cotizaron esta ruta piden salir desde
    una de sus sucursales, y esa parte todavía no está hecha en el sistema.
  </p>
  <div class="table-responsive">
    <table class="table">
      <tbody>
      <?php foreach ($apartadas as $o): ?>
        <tr>
          <td><b><?= P::e(E::carrier($o['carrier'])) ?></b>
            <small class="lf-inv-cod" style="display:block"><?= P::e($o['descripcion']) ?></small></td>
          <td class="text-end lf-mono"><?= D::pesos($o['precio']) ?></td>
          <td style="font-size:11.5px;color:var(--lf-tinta-4)"><?= P::e($o['motivo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script>
(function(){
  // ── El CP llena ciudad y estado ──
  // El estado va de solo lectura a propósito: es el campo que, mal
  // escrito, deja la cotización vacía sin decir por qué.
  var cp = document.getElementById('dCp'),
      ciudad = document.getElementById('dCiudad'),
      estado = document.getElementById('dEstado'),
      aviso  = document.getElementById('dCpAviso');
  if (cp) {
    var ultimo = '';
    var buscar = function(){
      var v = (cp.value || '').trim();
      if (v === '' || v === ultimo) return;
      ultimo = v;
      aviso.textContent = 'Buscando…';
      fetch('/envios/cp?cp=' + encodeURIComponent(v))
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j.ok) {
            aviso.textContent = (j.error || 'No se encontró ese código postal.')
                              + ' Escribe tú la clave del estado.';
            return;
          }
          estado.value = j.estado;
          if (!ciudad.value.trim()) ciudad.value = j.ciudad;
          aviso.textContent = j.ciudad + ', ' + j.estado;

          // Las colonias de ese CP, para elegir en vez de teclear.
          var lista = document.getElementById('dColonias');
          var col   = document.getElementById('dColonia');
          if (lista && j.colonias && j.colonias.length) {
            lista.innerHTML = '';
            j.colonias.forEach(function(c){
              var o = document.createElement('option'); o.value = c; lista.appendChild(o);
            });
            aviso.textContent += ' · ' + j.colonias.length + ' colonias';
            // Una sola colonia no es una elección: se pone.
            if (j.colonias.length === 1 && col && !col.value.trim()) col.value = j.colonias[0];
          }
        })
        .catch(function(){ aviso.textContent = 'No se pudo consultar el código postal.'; });
    };
    cp.addEventListener('blur', buscar);
    cp.addEventListener('change', buscar);
  }

  // ── Sucursales de la paquetería ──
  // Se piden la primera vez que alguien abre el selector, no al cargar
  // la pantalla: cada lista es una llamada a Envia y casi siempre se
  // elige un servicio que recoge a domicilio y no la necesita.
  document.querySelectorAll('[data-sucursales]').forEach(function (sel) {
    var cargado = false;
    var traer = function () {
      if (cargado) return;
      cargado = true;
      var carrier = sel.getAttribute('data-sucursales');
      var cp = (document.getElementById('dCp') || {}).value || '';
      sel.options[0].textContent = 'Buscando sucursales…';
      fetch('/envios/sucursales?carrier=' + encodeURIComponent(carrier)
            + '&cp=' + encodeURIComponent(cp))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok || !j.sucursales.length) {
            sel.options[0].textContent = j.error || 'No hay sucursales para esta zona';
            cargado = false;          // que se pueda reintentar
            return;
          }
          sel.options[0].textContent = '¿A qué sucursal lo llevas? (' + j.sucursales.length + ')';
          j.sucursales.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s.codigo;
            o.textContent = s.nombre + (s.ciudad ? ' — ' + s.ciudad : '')
                          + (s.cp ? ' ' + s.cp : '');
            sel.appendChild(o);
          });
        })
        .catch(function () {
          sel.options[0].textContent = 'No se pudo pedir la lista';
          cargado = false;
        });
    };
    sel.addEventListener('mousedown', traer);
    sel.addEventListener('focus', traer);
  });

  // ── Bultos ──
  var tabla = document.querySelector('#tPaq tbody');
  var mas = document.getElementById('bAgregar');
  if (mas && tabla) {
    mas.addEventListener('click', function(){
      var base = tabla.rows[tabla.rows.length - 1];
      if (!base) return;
      var fila = base.cloneNode(true);
      fila.querySelectorAll('input').forEach(function(i){
        if (i.name === 'p_contenido[]') i.value = 'Mercancía';
        if (i.name === 'p_valor[]') i.value = '0';
      });
      tabla.appendChild(fila);
    });
  }
  document.addEventListener('click', function(ev){
    var b = ev.target.closest('[data-quitar]');
    if (!b || !tabla) return;
    if (tabla.rows.length <= 1) return;   // siempre queda uno
    b.closest('tr').remove();
  });
})();
</script>