<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Captura as C;

/* La pantalla de capturar con la pistola. Va en modo concentrado: sin
   menú y sin barra, para que quien tiene una caja en brazos no toque
   otra cosa por error. */
$c = $captura;
$pausada = $c['estado'] === 'pausada';
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:14px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($pausada): ?>
<div class="alert alert-warning" style="margin-bottom:14px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Está en pausa.</b> Lo que llevas capturado se quedó guardado.
    Dale a <b>Seguir capturando</b> cuando vuelvas.</span>
</div>
<?php endif; ?>

<?php /* El contador grande. Es lo que se mira de reojo mientras se pasa
         la pistola, así que va arriba y con letra de lejos. */ ?>
<div class="lf-stats" style="margin-bottom:16px">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('caja','19px') ?></div>
    <div>
      <div class="n" style="font-size:30px"><?= P::e(C::num($c['piezas'])) ?></div>
      <div class="t">piezas capturadas</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('lista','19px') ?></div>
    <div>
      <div class="n"><?= count($c['lineas']) ?></div>
      <div class="t"><?= count($c['lineas']) === 1 ? 'producto' : 'productos distintos' ?></div>
    </div>
  </div>
</div>

<?php if (!$c['lineas']): ?>
  <p class="lf-inv-vacio" style="padding:30px 10px">
    Pasa el primer código con la pistola.<br>
    <small>También puedes escribirlo y darle Enter.</small>
  </p>
<?php else: ?>
<section class="card" style="padding:0 10px 4px">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Producto</th><th class="text-end">Capturado</th>
                 <th class="text-end">Existencia</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($c['lineas'] as $l): ?>
        <tr>
          <td data-label="Producto">
            <b><?= P::e($l['nombre']) ?></b>
            <small class="lf-inv-cod" style="display:block">
              <?= P::e($l['codigo_barras'] ?: $l['codigo']) ?></small>
          </td>
          <td data-label="Capturado" class="text-end lf-mono" style="font-size:16px">
            <b><?= P::e(C::num($l['cantidad'])) ?></b></td>
          <td data-label="Existencia" class="text-end lf-mono" style="color:var(--lf-tinta-4)">
            <?= P::e(C::num($l['existencia'])) ?></td>
          <td class="lf-inv-acc" style="white-space:nowrap">
            <?php /* Corregir sin tener que borrar y volver a escanear:
                     se pasa una caja de más y se quita ahí mismo. */ ?>
            <button type="button" class="lf-btn-ghost lf-ajustar"
                    data-id="<?= (int)$l['producto_id'] ?>"
                    data-n="<?= P::e($l['nombre']) ?>"
                    data-c="<?= P::e(C::num($l['cantidad'])) ?>"
                    title="Corregir la cantidad">Corregir</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<form method="post" action="/inventario/captura" id="fCap" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <input type="hidden" name="accion" id="capAccion">
  <input type="hidden" name="producto_id" id="capProd">
  <input type="hidden" name="cantidad" id="capCant">
</form>

<script>
(function () {
  var f = document.getElementById('fCap');
  var mandar = function (accion, extra) {
    document.getElementById('capAccion').value = accion;
    if (extra) {
      document.getElementById('capProd').value = extra.producto || '';
      document.getElementById('capCant').value = extra.cantidad || '';
    }
    f.submit();
  };
  window.lfCap = mandar;

  // ── El foco vuelve SIEMPRE al campo del código ──
  // La pistola es un teclado: escribe donde esté el foco y manda Enter.
  // Si el foco se pierde —un clic en la tabla, volver de otra pestaña—
  // el siguiente disparo se va al vacío y la caja no se cuenta, que es
  // justo el error que nadie nota hasta el final.
  var campo = document.getElementById('capCodigo');
  if (campo) {
    var volver = function () { if (document.activeElement !== campo) campo.focus(); };
    campo.focus();
    setInterval(volver, 700);
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('button, a, input, select, textarea')) volver();
    });
    window.addEventListener('focus', volver);
  }

  document.querySelectorAll('.lf-ajustar').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = prompt('¿Cuántas de «' + b.dataset.n + '»?\n\n'
                   + 'Pon 0 para quitarlo de la captura.', b.dataset.c);
      if (v === null) return;
      if (isNaN(parseFloat(v)) || parseFloat(v) < 0) { alert('Escribe un número.'); return; }
      mandar('ajustar', { producto: b.dataset.id, cantidad: parseFloat(v) });
    });
  });
})();
</script>