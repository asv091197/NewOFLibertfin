<?php
/**
 * El panel de pago.
 *
 * Se usa igual dentro de Caja, en Ligas y en la hoja imprimible. Una
 * sola copia: si cada pantalla armara lo suyo, en tres meses dirían
 * cosas distintas sobre cómo pagar.
 *
 * Espera: $l (la liga guardada) y, opcional, $compacto.
 */
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Vista\Qr;
use LibertyFin\Vista\Barras;
use LibertyFin\Dominio\Dinero as D;

$compacto = !empty($compacto);
$lid = (int)($l['id'] ?? 0);
?>
<div class="lf-pagar<?= $compacto ? ' compacto' : '' ?>">

  <?php if (!empty($l['liga'])): ?>
    <div class="via">
      <div class="cab"><?= W::icono('cobro','16px') ?><b>Tarjeta o pago en línea</b></div>
      <?php $qr = Qr::svg($l['liga'], $compacto ? 150 : 186); ?>
      <?php if ($qr): ?>
        <div class="qr"><?= $qr ?></div>
        <p class="ind">Que lo escanee con la cámara de su teléfono.</p>
      <?php endif; ?>
      <div class="copiar">
        <input type="text" readonly value="<?= P::e($l['liga']) ?>"
               id="pgL<?= $lid ?>">
        <button type="button" class="btn btn-secondary btn-sm"
                data-copiar="pgL<?= $lid ?>">Copiar</button>
      </div>
      <a class="abrir" href="<?= P::e($l['liga']) ?>" target="_blank" rel="noopener">
        Abrirla en otra pestaña</a>
    </div>
  <?php endif; ?>

  <?php if (!empty($l['clabe'])): ?>
    <div class="via">
      <div class="cab"><?= W::icono('venta','16px') ?><b>Transferencia SPEI</b></div>
      <p class="ind">Desde su banco, a esta CLABE. Se acredita en minutos.</p>
      <div class="dato lf-mono"><?= P::e($l['clabe']) ?></div>
      <div class="copiar">
        <input type="text" readonly value="<?= P::e($l['clabe']) ?>"
               id="pgC<?= $lid ?>" class="lf-mono">
        <button type="button" class="btn btn-secondary btn-sm"
                data-copiar="pgC<?= $lid ?>">Copiar</button>
      </div>
      <p class="ind">
        Por <b><?= D::pesos($l['monto']) ?></b> exactos. Una cantidad distinta no
        se asocia sola y hay que buscarla a mano.
      </p>
    </div>
  <?php endif; ?>

  <?php if (!empty($l['barras'])): ?>
    <div class="via">
      <div class="cab"><?= W::icono('caja','16px') ?><b>Efectivo en tiendas</b></div>
      <?php $bc = Barras::svg($l['barras'], $compacto ? 44 : 56); ?>
      <?php if ($bc): ?>
        <div class="barras"><?= $bc ?></div>
      <?php else: ?>
        <div class="dato lf-mono"><?= P::e($l['barras']) ?></div>
      <?php endif; ?>
      <p class="ind">
        En OXXO y tiendas participantes. Si no pueden escanear, que capturen
        la referencia.
      </p>
      <?php if ($lid > 0): ?>
        <a class="abrir" href="/ligas/<?= $lid ?>/documento" target="_blank">
          <?= W::icono('baja','14px') ?>Imprimir el comprobante</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!$compacto): ?>
<p class="lf-pagar-nota">
  <b>Todavía no entró el dinero.</b> La venta sigue con saldo hasta que el
  proveedor confirme el pago.
  <?php if (!empty($l['vence'])): ?>
    Esta liga vence el <?= date('d/m/Y', strtotime($l['vence'])) ?>.
  <?php endif; ?>
</p>
<?php endif; ?>

<script>
document.querySelectorAll('[data-copiar]').forEach(function(b){
  if (b.dataset.listo) return;
  b.dataset.listo = '1';
  b.addEventListener('click', function(){
    var i = document.getElementById(b.dataset.copiar);
    navigator.clipboard.writeText(i.value).then(function(){
      var t = b.textContent; b.textContent = 'Copiado';
      setTimeout(function(){ b.textContent = t; }, 1600);
    }).catch(function(){ i.select(); });
  });
});
</script>