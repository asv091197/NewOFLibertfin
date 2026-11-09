<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':($aviso['tipo']==='aviso'?'warning':'success') ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($faltaMigrar): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base.</b> Aplica <code>app/config/actualizar-v28.sql</code>
    en phpMyAdmin y vuelve a entrar.</span>
</div>
<?php endif; ?>

<div class="lf-doc-acciones">
  <a class="btn btn-secondary" href="/envios"><?= W::icono('atras','14px') ?> Envíos</a>
</div>

<p class="pista" style="margin:4px 0 18px">
  Esta es la dirección que la paquetería imprime como <b>remitente</b> y a donde va a
  recoger el paquete. No tiene que ser la misma del mostrador: si vendes en plaza y
  despachas desde una bodega, aquí va la bodega.
</p>
<p class="pista" style="margin:-10px 0 18px">
  El código postal llena la ciudad y el estado solos. El catálogo de Envia está
  incompleto en algunas zonas: si no reconoce tu CP, <b>escribe tú la clave de dos
  letras del estado</b> y guarda igual — CDMX es <b>CX</b>, Jalisco <b>JA</b>,
  Nuevo León <b>NL</b>, Estado de México <b>EM</b>.
</p>

<?php foreach ($lista as $o): $suc = (int)$o['sucursal_id']; ?>
<section class="card" style="padding:16px 18px;margin-bottom:16px">
  <h3 style="margin:0 0 4px">
    <?= P::e($o['sucursal']) ?>
    <?php if (!empty($o['es_matriz'])): ?><small class="pista">· Matriz</small><?php endif; ?>
    <?php if (empty($o['completo'])): ?>
      <span class="badge bg-warning" style="margin-left:6px">Incompleto</span>
    <?php endif; ?>
  </h3>
  <?php if (!empty($o['actualizado'])): ?>
    <p class="pista" style="margin:0 0 12px">
      Guardado el <?= P::e(date('d/m/Y H:i', strtotime($o['actualizado']))) ?>.</p>
  <?php endif; ?>

  <form method="post" action="/envios/origenes" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="sucursal_id" value="<?= $suc ?>">
    <input type="hidden" name="pais" value="<?= P::e($o['pais'] ?: 'MX') ?>">
    <div class="lf-grid2">
      <label>Quién entrega <small class="pista">(a quién busca el repartidor)</small>
        <input name="nombre" value="<?= P::e((string)$o['nombre']) ?>" maxlength="100" required></label>
      <label>Negocio <small class="pista">(como va en la etiqueta)</small>
        <input name="empresa" value="<?= P::e((string)$o['empresa']) ?>" maxlength="100"></label>
      <label>Teléfono
        <input name="telefono" value="<?= P::e((string)$o['telefono']) ?>" maxlength="30" required></label>
      <label>Correo
        <input type="email" name="email" value="<?= P::e((string)$o['email']) ?>" maxlength="120"></label>
      <label>Código postal
        <input name="cp" value="<?= P::e((string)$o['cp']) ?>" maxlength="10"
               inputmode="numeric" data-cp="<?= $suc ?>" required>
        <small class="pista" id="cpAviso<?= $suc ?>"></small></label>
      <?php /* Se llena con el código postal, pero se puede escribir: si
               el catálogo de Envia no contesta, readonly dejaría al
               negocio sin poder guardar su propio domicilio. */ ?>
      <label>Estado <small class="pista">(2 letras; si no se llena sola, escríbela)</small>
        <input name="estado" value="<?= P::e(mb_strlen(trim((string)$o['estado'])) === 2 ? mb_strtoupper(trim((string)$o['estado'])) : '') ?>"
               maxlength="2" id="cpEstado<?= $suc ?>" style="text-transform:uppercase"
               placeholder="NL" required></label>
      <label>Calle
        <input name="calle" value="<?= P::e((string)$o['calle']) ?>" maxlength="160" required></label>
      <label>Número
        <input name="numero" value="<?= P::e((string)$o['numero']) ?>" maxlength="30"></label>
      <label>Colonia
        <input name="colonia" value="<?= P::e((string)$o['colonia']) ?>" maxlength="120"></label>
      <label>Ciudad o municipio
        <input name="ciudad" value="<?= P::e((string)$o['ciudad']) ?>" maxlength="120"
               id="cpCiudad<?= $suc ?>" required></label>
      <label>Referencia
        <input name="referencia" value="<?= P::e((string)$o['referencia']) ?>" maxlength="160"></label>
    </div>
    <div style="margin-top:12px">
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</section>
<?php endforeach; ?>

<?php if (!$lista): ?>
  <p class="lf-inv-vacio">No hay sucursales activas.</p>
<?php endif; ?>

<script>
// El mismo truco que en el formulario de envío: el CP manda, porque el
// estado va en una clave de dos letras que es de Envia y que nadie se
// sabe de memoria.
document.querySelectorAll('[data-cp]').forEach(function(cp){
  var id = cp.getAttribute('data-cp'), ultimo = '';
  var buscar = function(){
    var v = (cp.value || '').trim();
    if (v === '' || v === ultimo) return;
    ultimo = v;
    var aviso  = document.getElementById('cpAviso' + id),
        estado = document.getElementById('cpEstado' + id),
        ciudad = document.getElementById('cpCiudad' + id);
    aviso.textContent = 'Buscando…';
    fetch('/envios/cp?cp=' + encodeURIComponent(v))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { aviso.textContent = j.error || 'No se encontró ese código postal.'; return; }
        estado.value = j.estado;
        if (!ciudad.value.trim()) ciudad.value = j.ciudad;
        aviso.textContent = j.ciudad + ', ' + j.estado;
      })
      .catch(function(){ aviso.textContent = 'No se pudo consultar el código postal.'; });
  };
  cp.addEventListener('blur', buscar);
  cp.addEventListener('change', buscar);
});
</script>