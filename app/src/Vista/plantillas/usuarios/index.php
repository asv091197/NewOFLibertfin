<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\UsuarioRepo as U;
use LibertyFin\Dominio\Permisos as Perm;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;
$yo = (int)($_SESSION['usuario_id'] ?? 0);
$ini = function ($n) { $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : '')); };
?>

<div class="lf-pills" style="margin-bottom:18px">
  <a class="lf-pill" href="/ajustes?t=sucursales">Sucursales</a>
  <a class="lf-pill" href="/ajustes?t=comisiones">Áreas y colaboradores</a>
  <a class="lf-pill" href="/ajustes?t=categorias">Categorías</a>
  <a class="lf-pill active" href="/usuarios">Usuarios</a>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php
// Lugares del plan: max = 0 es sin tope (prueba o plan fuera de catálogo).
$cupo  = $cupo ?? ['activos' => 0, 'max' => 0, 'plan' => ''];
$lleno = $cupo['max'] > 0 && $cupo['activos'] >= $cupo['max'];
$cuenta = $cupo['max'] > 0
    ? $cupo['activos'] . ' de ' . $cupo['max'] . ' usuario' . ($cupo['max'] === 1 ? '' : 's') . ' de tu plan'
    : '';
?>
<?php if ($lleno && !$e): ?>
<div class="lf-alta lf-alta-lleno">
  <div class="lf-alta-lleno-txt">
    <?= W::icono('alerta','16px') ?>
    <span>Ya usas los <b><?= (int)$cupo['max'] ?></b> usuarios activos que incluye tu plan
      <?= $cupo['plan'] !== '' ? '<b>' . P::e($cupo['plan']) . '</b>' : '' ?>.
      Para dar de alta a alguien más, desactiva a quien ya no lo use o cambia de plan.</span>
  </div>
  <a class="btn btn-primary btn-sm" href="/cuenta?t=plan">Ver planes</a>
</div>
<?php else: ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e ? 'cliente' : 'mas','16px') ?>
    <?= $e ? 'Editar a ' . P::e($e['nombre']) : 'Dar de alta un usuario' ?>
    <?php if ($cuenta !== '' && !$e): ?><span class="lf-alta-cupo"><?= P::e($cuenta) ?></span><?php endif; ?></summary>
  <form method="post" action="/usuarios/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:200px">
      <label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required>
    </div>
    <div style="flex:1;min-width:150px">
      <label class="form-label">Usuario</label>
      <input class="form-control" name="username" value="<?= P::e($e['username'] ?? '') ?>"
             pattern="[a-zA-Z0-9._-]{3,40}" style="text-transform:lowercase" required>
    </div>
    <div style="flex:1.5;min-width:180px">
      <label class="form-label">Correo</label>
      <input class="form-control" type="email" name="email" value="<?= P::e($e['email'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div style="width:150px">
      <label class="form-label">Rol</label>
      <select class="form-select" name="rol" id="selRol" required>
        <?php foreach (Perm::rolesQuePuedeAsignar() as $k => $v): ?>
          <option value="<?= $k ?>" data-para="<?= P::e($v['para']) ?>"
            <?= (isset($e['rol']) && $e['rol']===$k)?'selected':'' ?>><?= P::e($v['rotulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php /* Sin «Sin asignar»: la sucursal es dónde cobra (sus ventas, su
             corte, las existencias que descuenta) y la columna no admite
             vacío; en MySQL estricto el alta fallaba. Nueva, va la matriz. */ ?>
    <div style="width:160px">
      <label class="form-label">Sucursal</label>
      <select class="form-select" name="sucursal_id" required>
        <?php foreach ($sucursales as $k => $s):
          $sel = isset($e['sucursal_id']) ? (int)$e['sucursal_id'] === (int)$s['id'] : $k === 0; ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!$e): ?>
    <div style="width:180px">
      <label class="form-label">Contraseña</label>
      <input class="form-control" type="text" name="clave" minlength="<?= U::CLAVE_MINIMA ?>" required>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Dar de alta' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/usuarios">Cancelar</a><?php endif; ?>
    </div>
    <?php /* Para quién es el rol, a todo lo ancho: debajo del selector hacía
             más alta esa columna y descuadraba la fila. */ ?>
    <p class="lf-usr-rol" id="rolPara" aria-live="polite"></p>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      La sucursal es donde trabaja: ahí quedan sus ventas, su corte de caja y las existencias que descuenta.
      <?php if (!$e): ?>La contraseña se muestra a propósito: la escribes tú y se la dices a la persona.
      Nadie puede volver a verla después, ni tú.<?php endif; ?>
    </p>
  </form>
</details>
<?php endif; ?>

<section class="card">
  <header class="card-header">Equipo<?php if ($cuenta !== ''): ?>
    <span class="lf-alta-cupo<?= $lleno ? ' lleno' : '' ?>"><?= P::e($cuenta) ?></span><?php endif; ?></header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Usuario</th><th>Rol</th><th>Sucursal</th>
        <th class="text-end">Ventas</th><th>Estado</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): $act = (int)$u['activo'] === 1; ?>
        <tr style="<?= $act ? '' : 'opacity:.55' ?>">
          <td data-label="Usuario">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-av <?= $u['rol']==='admin' ? '' : 'gris' ?>"
                    style="width:30px;height:30px;font-size:11px"><?= P::e($ini($u['nombre'])) ?></span>
              <span style="min-width:0">
                <b style="display:block;font-weight:600"><?= P::e($u['nombre']) ?>
                  <?php if ((int)$u['id'] === $yo): ?>
                    <span style="font-weight:400;color:var(--lf-tinta-4);font-size:11.5px">· tú</span>
                  <?php endif; ?></b>
                <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($u['username']) ?></small>
              </span>
            </span>
          </td>
          <td data-label="Rol">
            <span class="badge <?= $u['rol']==='admin' ? 'bg-success'
                                 : (Perm::conocido($u['rol']) ? 'bg-secondary' : 'bg-warning') ?>">
              <?= P::e(Perm::rotulo($u['rol'])) ?></span>
            <?php if (!Perm::conocido($u['rol'])): ?>
              <span style="display:block;color:var(--lf-amb);font-size:11px;margin-top:3px">
                El sistema no reconoce este rol: no tiene permisos</span>
            <?php endif; ?>
          </td>
          <td data-label="Sucursal" style="font-size:12.5px"><?= P::e($u['sucursal'] ?: '—') ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$u['ventas'] ?></td>
          <td data-label="Estado">
            <?= $act ? '<span class="badge bg-success">Activo</span>'
                     : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/usuarios?editar=<?= (int)$u['id'] ?>"
               title="Editar"><?= W::icono('cliente','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-clave"
                    data-id="<?= (int)$u['id'] ?>" data-nombre="<?= P::e($u['nombre']) ?>"
                    title="Restablecer contraseña">⚿</button>
            <?php if ((int)$u['id'] !== $yo): ?>
              <button type="button" class="lf-btn-ghost lf-alt-usuario"
                      data-id="<?= (int)$u['id'] ?>" data-nombre="<?= P::e($u['nombre']) ?>"
                      data-activo="<?= $act ? 1 : 0 ?>"
                      title="<?= $act ? 'Desactivar' : 'Activar' ?>"><?= $act ? '<span class="lf-signo">&times;</span>' : '✓' ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Un usuario no se borra, se desactiva. Las ventas apuntan a quien las hizo,
    y borrarlo sería perder el rastro de quién cobró.
  </div>
</section>

<section class="card">
  <header class="card-header">
    <div><span>Qué abre cada rol</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        El permiso se verifica en el servidor, no escondiendo el enlace</p></div>
  </header>
  <div class="lf-equipo" style="padding:4px 20px 18px">
    <?php foreach (Perm::rolesQuePuedeAsignar() as $k => $r): $res = Perm::resumen($k); ?>
      <div class="lf-pers" style="align-items:flex-start">
        <span class="lf-av <?= $k==='admin' ? '' : 'gris' ?>" style="flex-shrink:0">
          <?= P::e(mb_strtoupper(mb_substr($r['rotulo'],0,2))) ?></span>
        <div style="flex:1;min-width:0">
          <b style="white-space:normal"><?= P::e($r['rotulo']) ?></b>
          <small style="white-space:normal;line-height:1.45"><?= P::e($r['para']) ?></small>
        </div>
        <span class="mn" style="font-size:12px">
          <?= $res['ve'] ?> ve<i><?= $res['hace'] ?> hace</i></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<form method="post" action="/usuarios/restablecer" id="formClave" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="clvId">
  <input type="hidden" name="clave" id="clvNueva">
</form>
<form method="post" action="/usuarios/alternar" id="formAltU" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="altUId">
</form>

<script>
// Al elegir rol se explica para quién es. Nombrar un rol sin saber qué
// abre es la forma más común de darle a alguien más de lo que necesita.
(function(){
  var s = document.getElementById('selRol'), t = document.getElementById('rolPara');
  if (!s || !t) return;
  function pinta(){
    var o = s.options[s.selectedIndex];
    t.innerHTML = '';
    if (!o || !o.dataset.para) return;
    var b = document.createElement('b'); b.textContent = o.textContent.trim() + ': ';
    t.appendChild(b); t.appendChild(document.createTextNode(o.dataset.para));
  }
  s.addEventListener('change', pinta); pinta();
})();

document.querySelectorAll('.lf-clave').forEach(function(b){
  b.addEventListener('click', function(){
    var c = prompt('Contraseña nueva para ' + b.dataset.nombre
      + '\n\nMínimo <?= U::CLAVE_MINIMA ?> caracteres. Anótala: no se podrá volver a ver.');
    if (!c || !c.trim()) return;
    document.getElementById('clvId').value = b.dataset.id;
    document.getElementById('clvNueva').value = c;
    document.getElementById('formClave').submit();
  });
});
document.querySelectorAll('.lf-alt-usuario').forEach(function(b){
  b.addEventListener('click', function(){
    var act = b.dataset.activo === '1';
    if (!confirm((act ? '¿Desactivar a ' : '¿Activar a ') + b.dataset.nombre + '?'
      + (act ? '\n\nDeja de poder entrar. Sus ventas no se tocan.' : ''))) return;
    document.getElementById('altUId').value = b.dataset.id;
    document.getElementById('formAltU').submit();
  });
});
</script>
