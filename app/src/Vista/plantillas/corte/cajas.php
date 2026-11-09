<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

/* Las cajas de TODOS, para quien responde por el dinero.
   Incluye a los cajeros sin caja abierta a propósito: lo que hace falta
   saber a las nueve de la mañana no es solo quién abrió, es quién
   todavía no — ese es el que va a cobrar sin turno. */
$abiertas = 0;
$total = 0.0;
foreach ($cajeros as $c) { if ($c['caja_id']) { $abiertas++; $total += (float)$c['cobrado']; } }
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-pills" role="tablist" style="margin-bottom:16px">
  <a class="lf-pill" role="tab" href="/corte">Mi turno</a>
  <a class="lf-pill active" role="tab" href="/corte?t=cajas">Cajeros</a>
  <a class="lf-pill" role="tab" href="/corte?t=historial">Historial</a>
</div>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title">Cajeros y sus cajas</h3>
    <small style="color:var(--lf-tinta-4)">
      <?= $abiertas ?> <?= $abiertas === 1 ? 'caja abierta' : 'cajas abiertas' ?>
      <?php if ($abiertas): ?> · <?= P::e(D::pesos($total)) ?> cobrados en los turnos de hoy<?php endif; ?>
    </small>
  </span></div>

  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr>
        <th>Cajero</th><th>Caja</th><th>Desde</th>
        <th class="text-end">Fondo</th><th class="text-end">Cobrado</th>
        <th class="text-end">Efectivo</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$cajeros): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay cajeros en esta sucursal.</td></tr>
      <?php endif; ?>
      <?php foreach ($cajeros as $c):
        $abierta = !empty($c['caja_id']);
        $esYo    = (int)$c['usuario_id'] === (int)$yo; ?>
        <tr style="<?= $c['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Cajero">
            <b><?= P::e($c['nombre']) ?></b><?= $esYo ? ' <small style="color:var(--lf-tinta-4)">(tú)</small>' : '' ?>
            <small style="display:block;color:var(--lf-tinta-4);font-family:var(--lf-mono);font-size:11px">
              <?= P::e($c['username']) ?> · <?= P::e($c['rol']) ?></small>
          </td>
          <td data-label="Caja">
            <?php if ($abierta): ?>
              <span class="badge bg-success">Abierta</span>
              <small style="display:block;color:var(--lf-tinta-4);font-family:var(--lf-mono);font-size:11px">
                #<?= (int)$c['caja_id'] ?></small>
            <?php else: ?>
              <span class="badge bg-secondary">Sin abrir</span>
            <?php endif; ?>
          </td>
          <td data-label="Desde" class="lf-mono" style="font-size:12px">
            <?= $abierta && $c['fecha_apertura']
                ? P::e(date('d/m/y H:i', strtotime($c['fecha_apertura'])))
                : '<span style="color:var(--lf-tinta-4)">—</span>' ?></td>
          <td data-label="Fondo" class="text-end lf-mono">
            <?= $abierta ? P::e(D::pesos((float)$c['monto_apertura'])) : '–' ?></td>
          <td data-label="Cobrado" class="text-end lf-mono">
            <?= $abierta ? P::e(D::pesos((float)$c['cobrado'])) : '–' ?>
            <?php if ($abierta && (int)$c['cobros']): ?>
              <small style="display:block;color:var(--lf-tinta-4);font-size:11px">
                <?= (int)$c['cobros'] ?> <?= (int)$c['cobros'] === 1 ? 'cobro' : 'cobros' ?></small>
            <?php endif; ?></td>
          <td data-label="Efectivo" class="text-end lf-mono">
            <?= $abierta ? P::e(D::pesos((float)$c['efectivo'])) : '–' ?>
            <?php if ($abierta): ?>
              <small style="display:block;color:var(--lf-tinta-4);font-size:11px">esperado en el cajón:
                <?= P::e(D::pesos((float)$c['monto_apertura'] + (float)$c['efectivo'])) ?></small>
            <?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($abierta && !$esYo): ?>
              <button type="button" class="btn btn-secondary btn-sm lf-cerrar-ajena"
                      data-id="<?= (int)$c['caja_id'] ?>" data-n="<?= P::e($c['nombre']) ?>"
                      data-esp="<?= P::e(number_format((float)$c['monto_apertura'] + (float)$c['efectivo'], 2, '.', '')) ?>"
                      title="Cerrarla tú, cuando el cajero ya no está">Cerrar</button>
            <?php endif; ?>
            <?php if ($puedeVerComo && !$esYo && $c['activo'] && $c['rol'] !== 'admin'): ?>
              <button type="button" class="btn btn-secondary btn-sm lf-ver-como"
                      data-id="<?= (int)$c['usuario_id'] ?>" data-n="<?= P::e($c['nombre']) ?>"
                      title="Ver el sistema con su pantalla, sin pedirle su contraseña">Ver como</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card-footer">
    <b>Cerrar</b> sirve para el turno que se quedó abierto porque el cajero se fue: si no se cierra,
    las ventas de mañana entran al corte de hoy. Pide decir por qué lo cierras tú, aunque cuadre —
    el que cierra no es el que contó.<br><br>
    <b>Ver como</b> abre el sistema con la pantalla de esa persona, para entender «a mí no me aparece
    el botón». Escribes <b>tu</b> contraseña, no la suya: no necesitas conocerla, y queda anotado
    quién entró como quién. Mientras tanto no se puede tocar usuarios, ajustes ni la bitácora.
  </div>
</section>

<form method="post" action="/corte/cerrar-ajena" id="fCerrarAjena" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="caja_id" id="caId">
  <input type="hidden" name="contado" id="caContado">
  <input type="hidden" name="nota" id="caNota">
</form>

<?php if ($puedeVerComo): ?>
<form method="post" action="/corte/ver-como" id="fVerComo" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="usuario_id" id="vcId">
  <input type="hidden" name="clave" id="vcClave">
</form>
<?php endif; ?>

<script>
document.querySelectorAll('.lf-cerrar-ajena').forEach(function(b){
  b.addEventListener('click', function(){
    var c = prompt('Cerrar la caja de ' + b.dataset.n + '.\n\n'
      + 'En el cajón deberían estar $' + b.dataset.esp + '.\n'
      + '¿Cuánto hay de verdad?', b.dataset.esp);
    if (c === null) return;
    if (isNaN(parseFloat(c)) || parseFloat(c) < 0) { alert('Escribe una cantidad.'); return; }
    var n = prompt('¿Por qué la cierras tú? Es lo único que va a quedar escrito.');
    if (n === null) return;
    if (n.trim().length < 5) { alert('Escribe al menos una frase.'); return; }
    document.getElementById('caId').value = b.dataset.id;
    document.getElementById('caContado').value = parseFloat(c);
    document.getElementById('caNota').value = n.trim();
    document.getElementById('fCerrarAjena').submit();
  });
});

document.querySelectorAll('.lf-ver-como').forEach(function(b){
  b.addEventListener('click', function(){
    /* La contraseña se pide con prompt(), que la deja a la vista de quien
       esté al lado. Es una decisión consciente: la alternativa era un
       modal propio, y para esto —un administrador frente a su pantalla,
       escribiendo SU contraseña— no compensa. Si algún día se pide desde
       el mostrador, esto se cambia por un campo de tipo password. */
    var k = prompt('Vas a ver el sistema como ' + b.dataset.n + '.\n\n'
      + 'Escribe TU contraseña (no la suya). Queda anotado quién entró como quién.');
    if (k === null || k === '') return;
    document.getElementById('vcId').value = b.dataset.id;
    document.getElementById('vcClave').value = k;
    document.getElementById('fVerComo').submit();
  });
});
</script>