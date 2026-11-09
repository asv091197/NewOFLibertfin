<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Dominio\Permisos as Perm;
$r = $resumen;
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)$r['activas'] ?></div>
    <div class="stat-label">Empresas activas</div>
    <div class="stat-meta">de <?= (int)$r['empresas'] ?> registradas</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= (int)$r['por_vencer'] ?></div>
    <div class="stat-label">Vencen pronto</div>
    <div class="stat-meta">en los próximos 15 días</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)$r['vencidas'] ?></div>
    <div class="stat-label">Vencidas</div>
    <div class="stat-meta">siguen entrando igual</div>
  </div>
</div>

<form class="lf-filtros" method="get">
  <div class="lf-search" style="max-width:340px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q"
           value="<?= P::e($buscar) ?>" placeholder="Nombre, correo, RFC o base de datos">
  </div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
  <?php if ($buscar): ?><a class="btn btn-secondary btn-sm" href="/soporte">Limpiar</a><?php endif; ?>
</form>

<?php if (!$conPulso): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Son demasiadas empresas para consultar sus cifras en la lista. Búscala por
    nombre y ábrela para ver su ficha completa.</span>
</div>
<?php endif; ?>

<section class="card">
  <header class="card-header">Empresas</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Empresa</th><th>Contacto</th><th>Plan</th><th>Vence</th>
        <th class="text-end">Ventas</th><th>Última</th><th>Esquema</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$empresas): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          <?= $buscar ? 'Ninguna empresa coincide.' : 'No hay empresas registradas.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($empresas as $e):
        $venc = !empty($e['fecha_vencimiento']) ? strtotime($e['fecha_vencimiento']) : null;
        $dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
        $pu   = $e['pulso'];
        $atras = $pu && $pu['esquema'] < $ultima; ?>
        <tr style="<?= $e['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Empresa">
            <a data-lf-fila href="/soporte/<?= (int)$e['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($e['nombre_empresa']) ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($e['nombre_base_datos']) ?></span>
            <?php if (!$e['activo']): ?>
              <span class="badge bg-secondary" style="margin-top:3px">Inactiva</span><?php endif; ?>
          </td>
          <td data-label="Contacto" style="font-size:12.5px">
            <?= P::e($e['nombre_contacto'] ?: '—') ?>
            <?php if ($e['email_admin']): ?>
              <a style="display:block;font-size:11px" href="mailto:<?= P::e($e['email_admin']) ?>">
                <?= P::e($e['email_admin']) ?></a>
            <?php endif; ?>
          </td>
          <td data-label="Plan" style="font-size:12.5px"><?= P::e(ucfirst($e['plan'] ?: 'prueba')) ?>
            <?php if ($e['no_distribuidor']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                dist. <?= P::e($e['no_distribuidor']) ?></span><?php endif; ?></td>
          <td data-label="Vence">
            <?php if ($dias === null): ?><span style="color:var(--lf-tinta-4)">—</span>
            <?php elseif ($dias < 0): ?>
              <span class="badge bg-danger">hace <?= abs($dias) ?> d</span>
            <?php elseif ($dias < 15): ?>
              <span class="badge bg-warning"><?= $dias ?> d</span>
            <?php else: ?>
              <span class="lf-mono" style="font-size:12px"><?= date('d/m/y', $venc) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Ventas" class="text-end lf-mono">
            <?= $pu ? number_format((int)$pu['ventas']) : '—' ?>
            <?php if ($pu && (int)$pu['cajas'] > 0): ?>
              <span style="display:block;color:var(--lf-brand-2);font-size:11px">
                <?= (int)$pu['cajas'] ?> caja<?= $pu['cajas']==1?'':'s' ?> abierta<?= $pu['cajas']==1?'':'s' ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Última" class="lf-mono" style="font-size:12px">
            <?= ($pu && $pu['ultima']) ? date('d/m/y', strtotime($pu['ultima']))
                : '<span style="color:var(--lf-tinta-4)">—</span>' ?></td>
          <td data-label="Esquema">
            <?php if (!$pu): ?><span style="color:var(--lf-tinta-4)">—</span>
            <?php elseif ($atras): ?>
              <span class="badge bg-warning">v<?= (int)$pu['esquema'] ?> de <?= (int)$ultima ?></span>
            <?php else: ?>
              <span class="badge bg-success">al día</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (Perm::puede('clave.ajena')): ?>
              <button type="button" class="lf-btn-ghost lf-acc"
                      data-id="<?= (int)$e['id'] ?>" data-n="<?= P::e($e['nombre_empresa']) ?>"
                      title="Contraseña nueva para su administrador, y se la mandamos por correo">
                Reenviar accesos</button>
            <?php endif; ?>
            <?php
            // El botón solo aparece donde puede funcionar. Si ya vendió,
            // el servidor se va a negar de todos modos; enseñarlo sería
            // ofrecer algo que no se puede hacer, y el que lo intente va
            // a creer que el sistema falló.
            $vendio = $pu && (int)$pu['ventas'] > 0;
            if (Perm::puede('eliminar.empresa') && !$vendio): ?>
              <button type="button" class="lf-btn-ghost lf-elim"
                      data-id="<?= (int)$e['id'] ?>" data-n="<?= P::e($e['nombre_empresa']) ?>"
                      style="color:var(--lf-malo,#c0392b)"
                      title="Solo si nunca tuvo movimientos">Eliminar</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una empresa vencida sigue entrando igual: el vencimiento se muestra, no se
    aplica. Cortar el acceso solo porque venció una fecha deja a un negocio sin
    poder cobrar, y eso se decide con una llamada, no con un <code>IF</code>.
    <br><br>
    <b>Reenviar accesos</b> le genera una contraseña nueva al administrador y se
    la manda por correo; la anterior deja de servir. Sale también en pantalla,
    por si el correo falla.
    <b>Eliminar</b> solo aparece en las que no tienen una sola venta, y aun así
    el sistema cuenta el resto de sus movimientos antes de hacer nada. Su base de
    datos no se borra: queda en el hosting.
  </div>
</section>

<form method="post" action="/soporte/accesos" id="fAcc" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="accId">
</form>
<form method="post" action="/soporte/eliminar" id="fElim" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="elimId">
  <input type="hidden" name="confirmacion" id="elimNombre">
</form>
<script>
document.querySelectorAll('.lf-acc').forEach(function(b){
  b.addEventListener('click', function(ev){
    ev.preventDefault(); ev.stopPropagation();
    if (!confirm('¿Generar accesos nuevos para "' + b.dataset.n + '"?\n\n'
      + 'Se le manda la contraseña nueva por correo y también sale en pantalla.\n'
      + 'La contraseña anterior DEJA DE SERVIR.')) return;
    document.getElementById('accId').value = b.dataset.id;
    document.getElementById('fAcc').submit();
  });
});

// Escribir el nombre, no un "¿seguro?" que se contesta sin leer. El
// servidor lo vuelve a comparar: esto es comodidad, no la guarda.
document.querySelectorAll('.lf-elim').forEach(function(b){
  b.addEventListener('click', function(ev){
    ev.preventDefault(); ev.stopPropagation();
    var n = b.dataset.n;
    var r = prompt('Eliminar "' + n + '" no se puede deshacer.\n\n'
      + 'Solo funciona si nunca tuvo movimientos; si tuvo alguno, el sistema se '
      + 'va a negar y te va a decir cuántos.\n\n'
      + 'Su base de datos NO se borra: queda en el hosting.\n\n'
      + 'Escribe el nombre de la empresa para confirmar:');
    if (r === null) return;
    if (r.trim().toLowerCase() !== n.trim().toLowerCase()) {
      alert('El nombre no coincide. No se eliminó nada.');
      return;
    }
    document.getElementById('elimId').value = b.dataset.id;
    document.getElementById('elimNombre').value = r;
    document.getElementById('fElim').submit();
  });
});
</script>