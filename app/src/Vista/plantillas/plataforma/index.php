<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos as Perm;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = null;
foreach ($usuarios as $u) if ((int)$u['id'] === (int)$editando) $e = $u;
$yo = (int)($_SESSION['usuario_id'] ?? 0);
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Estas cuentas <b>no pertenecen a ninguna empresa</b>. Viven en la base
    principal, así que no aparecen en el equipo de ningún cliente ni su
    administrador puede tocarlas.</span>
</div>

<details class="lf-alta" <?= $e ? 'open' : '' ?>>
  <summary><?= W::icono($e?'cliente':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Nueva cuenta de plataforma' ?></summary>
  <form method="post" action="/plataforma/usuario" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px"><label class="form-label">Nombre completo</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="width:190px"><label class="form-label">Usuario</label>
      <input class="form-control lf-mono" name="username" value="<?= P::e($e['username'] ?? '') ?>"
             pattern="[a-z0-9._-]{3,30}" required placeholder="solo minúsculas"></div>
    <div style="flex:1;min-width:200px"><label class="form-label">Correo</label>
      <input class="form-control" type="email" name="email" value="<?= P::e($e['email'] ?? '') ?>"
             placeholder="Opcional"></div>
    <div style="width:200px"><label class="form-label">Rol</label>
      <select class="form-select" name="rol" id="selRolP" required>
        <?php foreach ($roles as $k=>$v): ?>
          <option value="<?= $k ?>" data-para="<?= P::e($v['para']) ?>"
            <?= ($e['rol'] ?? '')===$k?'selected':'' ?>><?= P::e($v['rotulo']) ?></option>
        <?php endforeach; ?>
      </select>
      <small id="rolParaP" style="font-size:11px;color:var(--lf-tinta-4);
             margin-top:5px;display:block;line-height:1.4;max-width:190px"></small></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Crear' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/plataforma">Cancelar</a><?php endif; ?>
    </div>
    <?php if (!$e): ?>
      <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
        La contraseña se genera al crear y se muestra <b>una sola vez</b>.
      </p>
    <?php endif; ?>
  </form>
</details>
<script>
(function(){
  var s = document.getElementById('selRolP'), t = document.getElementById('rolParaP');
  if (!s || !t) return;
  function v(){ t.textContent = s.options[s.selectedIndex].dataset.para || ''; }
  s.addEventListener('change', v); v();
})();
</script>

<section class="card">
  <header class="card-header">
    <div><span>Equipo de LibertyFin</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Una cuenta no se borra, se desactiva</p></div>
    <span class="badge bg-secondary"><?= count($usuarios) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Usuario</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$usuarios): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--lf-tinta-4);padding:30px">
          No hay cuentas de plataforma. Crea la primera arriba.</td></tr>
      <?php endif; ?>
      <?php foreach ($usuarios as $u): $esYo = (int)$u['id'] === $yo; ?>
        <tr style="<?= $u['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Usuario">
            <b style="font-weight:600"><?= P::e($u['nombre']) ?>
              <?php if ($esYo): ?><span style="color:var(--lf-tinta-4);font-weight:400"> · tú</span><?php endif; ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($u['username']) ?><?= $u['email'] ? ' · ' . P::e($u['email']) : '' ?></span>
          </td>
          <td data-label="Rol">
            <span class="badge <?= $u['rol']==='superadmin' ? 'bg-success' : 'bg-secondary' ?>">
              <?= P::e(Perm::rotulo($u['rol'])) ?></span></td>
          <td data-label="Último acceso" class="lf-mono" style="font-size:12px">
            <?= $u['ultimo_acceso'] ? date('d/m/y H:i', strtotime($u['ultimo_acceso']))
                : '<span style="color:var(--lf-tinta-4)">nunca</span>' ?></td>
          <td data-label="Estado">
            <span class="badge <?= $u['activo'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $u['activo'] ? 'Activa' : 'Desactivada' ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/plataforma?editar=<?= (int)$u['id'] ?>"
               title="Editar"><?= W::icono('cliente','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-cl-p" title="Restablecer contraseña"
                    data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>">⚿</button>
            <?php if (!$esYo): ?>
              <button type="button" class="lf-btn-ghost lf-al-p"
                      data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>"
                      data-a="<?= $u['activo'] ? 1 : 0 ?>"><?= $u['activo'] ? '<span class="lf-signo">&times;</span>' : '✓' ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php /* ═══ INTERRUPTORES GLOBALES ═══ */ ?>
<section class="card">
  <header class="card-header">
    <div><span>Qué está disponible, para todos</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Apagar aquí lo apaga en todas las empresas, aunque ellas lo tengan encendido</p></div>
  </header>
  <div class="card-body">
    <div style="display:flex;gap:24px;flex-wrap:wrap">
      <?php foreach ([['Secciones', $global['secciones'], 'seccion.'],
                      ['Métodos de pago', $global['metodos'], 'metodo.']] as $bloque):
        list($rotulo, $items, $pre) = $bloque; ?>
        <div style="flex:1;min-width:260px">
          <b style="font-size:13px;font-weight:600;display:block;margin-bottom:11px">
            <?= P::e($rotulo) ?></b>
          <?php foreach ($items as $k => $i): ?>
            <div class="lf-row" style="padding:9px 0">
              <span style="flex:1;min-width:0">
                <b style="display:block;font-size:13px"><?= P::e($i['rotulo']) ?></b>
                <?php if (!$i['activa'] && $i['nota']): ?>
                  <small style="color:var(--lf-amb);font-size:11px;white-space:normal;line-height:1.4">
                    <?= P::e($i['nota']) ?></small>
                <?php endif; ?>
              </span>
              <button type="button" class="btn btn-sm <?= $i['activa'] ? 'btn-secondary' : 'btn-primary' ?> lf-glob"
                      data-clave="<?= P::e($pre . $k) ?>" data-n="<?= P::e($i['rotulo']) ?>"
                      data-a="<?= $i['activa'] ? 1 : 0 ?>">
                <?= $i['activa'] ? 'Apagar' : 'Encender' ?></button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;line-height:1.55;
              padding-top:14px;border-top:1px solid var(--lf-linea)">
      Los dos interruptores contestan preguntas distintas. <b>El global dice "esto
      funciona"</b> — se apaga cuando algo está roto o el proveedor se cayó.
      <b>El de la empresa dice "esto lo uso"</b>. Por eso el global manda: si una
      empresa pudiera encender algo que sabemos que no sirve, lo estaría usando roto.
      El efectivo no aparece porque apagarlo dejaría a un negocio sin poder cobrar.
    </p>
  </div>
</section>

<section class="card">
  <header class="card-header">
    <div><span>Empresas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Suspender no borra: sus datos quedan enteros y se puede deshacer</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Empresa</th><th>Plan</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($empresas as $em): ?>
        <tr style="<?= $em['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Empresa">
            <a data-lf-fila href="/soporte/<?= (int)$em['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($em['nombre_empresa']) ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($em['nombre_base_datos']) ?></span></td>
          <td data-label="Plan" style="font-size:12.5px"><?= P::e(ucfirst($em['plan'] ?: 'prueba')) ?></td>
          <td data-label="Estado">
            <span class="badge <?= $em['activo'] ? 'bg-success' : 'bg-warning' ?>">
              <?= $em['activo'] ? 'Activa' : 'Suspendida' ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <button type="button" class="btn btn-secondary btn-sm lf-acc"
                    data-id="<?= (int)$em['id'] ?>" data-n="<?= P::e($em['nombre_empresa']) ?>"
                    title="Genera una contraseña nueva para su administrador y se la manda por correo">
              Reenviar accesos</button>
            <button type="button" class="btn btn-secondary btn-sm lf-susp"
                    data-id="<?= (int)$em['id'] ?>" data-n="<?= P::e($em['nombre_empresa']) ?>"
                    data-a="<?= $em['activo'] ? 1 : 0 ?>">
              <?= $em['activo'] ? 'Suspender' : 'Reactivar' ?></button>
            <?php if (Perm::puede('eliminar.empresa')): ?>
              <button type="button" class="btn btn-sm lf-elim"
                      data-id="<?= (int)$em['id'] ?>" data-n="<?= P::e($em['nombre_empresa']) ?>"
                      style="color:var(--lf-malo,#c0392b);background:none;border:1px solid currentColor"
                      title="Solo si nunca tuvo movimientos">Eliminar</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    <b>Reenviar accesos</b> genera una contraseña nueva para su administrador y se
    la manda por correo. La anterior deja de servir. Úsalo cuando la empresa esté
    bien creada pero su correo de bienvenida no haya llegado; la contraseña sale
    también en pantalla, por si el correo vuelve a fallar.<br><br>
    <b>Una empresa que ya operó no se elimina.</b> Sus ventas y facturas hay que
    conservarlas cinco años, y la bitácora existe para contestar "¿quién hizo
    esto?" — si se pudiera borrar, dejaría de contestarlo justo cuando alguien
    tenga motivo para querer que no conteste. Para esos casos está suspender.
    Eliminar solo funciona con las que nunca tuvieron un movimiento: el sistema
    los cuenta y se niega si encuentra alguno. Su base de datos NO se borra,
    queda en el hosting por si acaso.
  </div>
</section>

<form method="post" action="/plataforma/clave" id="fClP" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="clPId">
</form>
<form method="post" action="/plataforma/alternar" id="fAlP" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="alPId">
</form>
<form method="post" action="/plataforma/global" id="fGlob" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="clave" id="globClave">
  <input type="hidden" name="nota" id="globNota">
</form>
<form method="post" action="/plataforma/empresa" id="fSusp" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="suspId">
</form>
<form method="post" action="/plataforma/empresa/accesos" id="fAcc" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="accId">
</form>
<form method="post" action="/plataforma/empresa/eliminar" id="fElim" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="elimId">
  <input type="hidden" name="confirmacion" id="elimNombre">
</form>
<script>
document.querySelectorAll('.lf-cl-p').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Restablecer la contraseña de ' + b.dataset.n + '?')) return;
    document.getElementById('clPId').value = b.dataset.id;
    document.getElementById('fClP').submit();
  });
});
document.querySelectorAll('.lf-al-p').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm((b.dataset.a === '1' ? '¿Desactivar a ' : '¿Activar a ') + b.dataset.n + '?')) return;
    document.getElementById('alPId').value = b.dataset.id;
    document.getElementById('fAlP').submit();
  });
});
document.querySelectorAll('.lf-glob').forEach(function(b){
  b.addEventListener('click', function(){
    var apagar = b.dataset.a === '1', nota = '';
    if (apagar) {
      nota = prompt('¿Por qué se apaga "' + b.dataset.n + '" para TODAS las empresas?\n\n'
        + 'Esto lo van a ver los afectados.');
      if (!nota || nota.trim().length < 8) {
        if (nota !== null) alert('Escribe al menos una frase.');
        return;
      }
    } else if (!confirm('¿Encender "' + b.dataset.n + '" para todas las empresas?')) return;
    document.getElementById('globClave').value = b.dataset.clave;
    document.getElementById('globNota').value = (nota || '').trim();
    document.getElementById('fGlob').submit();
  });
});

document.querySelectorAll('.lf-acc').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Generar accesos nuevos para "' + b.dataset.n + '"?\n\n'
      + 'Se le manda la contraseña nueva por correo y también sale en pantalla.\n'
      + 'La contraseña anterior DEJA DE SERVIR: si alguien ya estaba dentro, '
      + 'tendrá que entrar con la nueva.')) return;
    document.getElementById('accId').value = b.dataset.id;
    document.getElementById('fAcc').submit();
  });
});

// Escribir el nombre, no un "¿seguro?" que se contesta sin leer. El
// servidor lo vuelve a comparar: esto es comodidad, no la guarda.
document.querySelectorAll('.lf-elim').forEach(function(b){
  b.addEventListener('click', function(){
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

document.querySelectorAll('.lf-susp').forEach(function(b){
  b.addEventListener('click', function(){
    var s = b.dataset.a === '1';
    if (!confirm((s ? '¿Suspender a ' : '¿Reactivar a ') + b.dataset.n + '?\n\n'
      + (s ? 'Su gente deja de entrar. Sus datos NO se borran y esto se puede deshacer.'
           : 'Su gente vuelve a entrar normalmente.'))) return;
    document.getElementById('suspId').value = b.dataset.id;
    document.getElementById('fSusp').submit();
  });
});
</script>