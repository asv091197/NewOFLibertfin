<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Dominio\Permisos as Perm;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $f['empresa'];
$c = $f['cifras'];
$venc = !empty($e['fecha_vencimiento']) ? strtotime($e['fecha_vencimiento']) : null;
$dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
$atras = $f['esquema'] !== null && $f['esquema'] < $ultima;
?>

<div style="display:flex;gap:9px;margin-bottom:18px;flex-wrap:wrap">
  <a class="btn btn-secondary btn-sm" href="/soporte">Volver a la lista</a>
  <?php if ($e['email_admin']): ?>
    <a class="btn btn-secondary btn-sm" href="mailto:<?= P::e($e['email_admin']) ?>">
      Escribirle</a><?php endif; ?>
  <?php if ($e['telefono']): ?>
    <a class="btn btn-secondary btn-sm" href="tel:<?= P::e($e['telefono']) ?>">
      <?= P::e($e['telefono']) ?></a><?php endif; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($f['error']): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= P::e($f['error']) ?>.</b> Los datos de la ficha no se pudieron leer.</span>
</div>
<?php endif; ?>

<?php /* ── Señales, primero: es lo que explica la llamada ── */
$malas = array_filter($f['senales'], function ($s) { return (int)$s['valor'] > 0; }); ?>
<?php if ($malas): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)">
  <header class="card-header">
    <div><span>Qué puede estar causando su problema</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Cada una dice qué síntoma produce, no solo que hay un número</p></div>
    <span class="badge bg-warning"><?= count($malas) ?></span>
  </header>
  <div class="lf-equipo" style="padding:4px 20px 18px">
    <?php foreach ($malas as $s): ?>
      <div class="lf-pers" style="align-items:flex-start">
        <span class="lf-tile a" style="width:34px;height:34px;border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono('alerta','16px') ?></span>
        <div style="flex:1;min-width:0">
          <b style="white-space:normal"><?= P::e($s['rotulo']) ?></b>
          <small style="white-space:normal;line-height:1.4"><?= P::e($s['porque']) ?></small>
        </div>
        <span class="mn" style="color:var(--lf-amb)"><?= (int)$s['valor'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($c['cobrado_mes'] ?? 0) ?></div>
    <div class="stat-label">Cobrado este mes</div>
    <div class="stat-meta"><?= number_format((int)($c['ventas'] ?? 0)) ?> ventas en total</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($c['por_cobrar'] ?? 0) ?></div>
    <div class="stat-label">Por cobrar</div>
    <div class="stat-meta">saldo abierto</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= number_format((int)($c['clientes'] ?? 0)) ?></div>
    <div class="stat-label">Clientes</div>
    <div class="stat-meta"><?= (int)($c['servicios'] ?? 0) ?> productos activos</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= (int)($c['cajas_abiertas'] ?? 0) ? '' : 'g' ?>">
      <?= W::icono('caja','19px') ?></div>
    <div class="stat-value"><?= (int)($c['cajas_abiertas'] ?? 0) ?></div>
    <div class="stat-label">Cajas abiertas</div>
    <div class="stat-meta">
      <?= !empty($c['ultima_venta'])
          ? 'última venta ' . date('d/m/y', strtotime($c['ultima_venta']))
          : 'sin ventas' ?></div>
  </div>
</div>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Ficha</header>
    <div class="card-body" style="font-size:13px">
      <?php
      $docEst = ['sin_enviar'=>'Faltan documentos','en_revision'=>'En revisión',
                 'rechazada'=>'Con rechazos','aprobada'=>'Aprobada'];
      foreach ([
        'Razón social'  => $e['nombre_empresa'],
        'RFC'           => $e['rfc'] ?: '—',
        'Giro'          => $e['giro_comercial'] ?: '—',
        'Contacto'      => $e['nombre_contacto'] ?: '—',
        'Correo'        => $e['email_admin'] ?: '—',
        'Teléfono'      => $e['telefono'] ?: '—',
        'Dirección'     => $e['direccion'] ?? '—',
        'Usuario admin' => $e['usuario_admin'] ?: '—',
        'Base de datos' => $e['nombre_base_datos'],
        'Distribuidor'  => $e['no_distribuidor'] ?: 'directo',
        'Plan'          => ucfirst($e['plan'] ?: 'prueba'),
        'Documentación' => $f['docs'] ? ($docEst[$f['docs']['estado']] ?? '—') : '—',
      ] as $k => $v): if ($v === null || $v === '') continue; ?>
        <div style="display:flex;justify-content:space-between;gap:14px;padding:6px 0;
             border-bottom:1px solid var(--lf-linea)">
          <span style="color:var(--lf-tinta-3);flex-shrink:0"><?= P::e($k) ?></span>
          <b style="font-weight:600;text-align:right;overflow-wrap:anywhere"><?= P::e($v) ?></b>
        </div>
      <?php endforeach; ?>

      <div style="display:flex;justify-content:space-between;gap:14px;padding:8px 0">
        <span style="color:var(--lf-tinta-3)">Esquema</span>
        <?php if ($f['esquema'] === null): ?>
          <span style="color:var(--lf-tinta-4)">no se pudo leer</span>
        <?php elseif ($atras): ?>
          <span class="badge bg-warning">v<?= (int)$f['esquema'] ?> · le faltan
            <?= $ultima - $f['esquema'] ?></span>
        <?php else: ?>
          <span class="badge bg-success">al día (v<?= (int)$f['esquema'] ?>)</span>
        <?php endif; ?>
      </div>

      <?php if ($venc): ?>
        <div style="margin-top:12px;padding:12px 14px;border-radius:var(--lf-r);font-size:12.5px;
             background:<?= $dias<0?'var(--lf-rojo-soft)':($dias<15?'var(--lf-amb-soft)':'var(--lf-brand-soft)') ?>;
             color:<?= $dias<0?'var(--lf-rojo)':($dias<15?'var(--lf-amb)':'var(--lf-brand-2)') ?>">
          <?= $dias < 0 ? 'Venció hace ' . abs($dias) . ' días'
                        : 'Quedan ' . $dias . ' día' . ($dias==1?'':'s') ?>
          · <?= date('d/m/Y', $venc) ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <div>
    <?php if ($f['sucursales']): ?>
    <section class="card">
      <header class="card-header">Sucursales</header>
      <div style="padding:0 10px 8px">
        <?php foreach ($f['sucursales'] as $s): ?>
          <div class="lf-row" style="<?= $s['activo'] ? '' : 'opacity:.55' ?>">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($s['nombre']) ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11px">
                <?= $s['es_matriz'] ? 'Matriz' : 'Sucursal' ?></small>
            </span>
            <?php if (!$s['activo']): ?>
              <span class="badge bg-secondary">Inactiva</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($f['secciones'])): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Qué tiene encendido</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Si pregunta por algo que no ve, aquí está la respuesta</p></div>
      </header>
      <div style="padding:0 10px 8px">
        <?php foreach ([['Secciones', $f['secciones'], 'seccion'],
                        ['Métodos de pago', $f['metodos'] ?? [], 'metodo']] as $bl):
          list($rotulo, $items, $que) = $bl; if (!$items) continue; ?>
          <div class="sec" style="padding:12px 10px 5px"><?= P::e($rotulo) ?></div>
          <?php foreach ($items as $k => $i):
            $fijo = !empty($i['fijo']);
            $bloqueadoGlobal = isset($i['global']) && !$i['global']; ?>
            <div class="lf-row" style="padding:8px 10px">
              <span style="flex:1;min-width:0">
                <b style="display:block;font-size:13px"><?= P::e($i['rotulo']) ?></b>
                <?php if ($bloqueadoGlobal): ?>
                  <small style="color:var(--lf-amb);font-size:11px">
                    apagado por LibertyFin para todos</small>
                <?php elseif ($fijo): ?>
                  <small style="color:var(--lf-tinta-4);font-size:11px">
                    no se puede apagar</small>
                <?php endif; ?>
              </span>
              <?php if ($fijo || $bloqueadoGlobal): ?>
                <span class="badge <?= $i['activa'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= $i['activa'] ? 'Encendido' : 'Apagado' ?></span>
              <?php else: ?>
                <button type="button" class="btn btn-sm <?= $i['activa'] ? 'btn-secondary' : 'btn-primary' ?> lf-aj"
                        data-que="<?= $que ?>" data-clave="<?= P::e($k) ?>"
                        data-n="<?= P::e($i['rotulo']) ?>" data-a="<?= $i['activa'] ? 1 : 0 ?>">
                  <?= $i['activa'] ? 'Apagar' : 'Encender' ?></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        Esto solo afecta a esta empresa. Para apagar algo en todas, es
        <a href="/plataforma">Cuentas</a>.
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Usuarios</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Se puede restablecer una contraseña y bloquear una cuenta. Los roles no se tocan desde aquí.</p></div>
    <span class="badge bg-secondary"><?= count($f['usuarios']) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Usuario</th><th>Rol</th><th>Sucursal</th>
        <th class="text-end">Ventas</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$f['usuarios']): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:28px">
          No se pudieron leer sus usuarios.</td></tr>
      <?php endif; ?>
      <?php foreach ($f['usuarios'] as $u): ?>
        <tr style="<?= $u['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Usuario">
            <b style="font-weight:600"><?= P::e($u['nombre']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($u['username']) ?><?= $u['email'] ? ' · ' . P::e($u['email']) : '' ?></span>
          </td>
          <td data-label="Rol">
            <span class="badge <?= $u['rol']==='admin' ? 'bg-success'
                 : (Perm::conocido($u['rol']) ? 'bg-secondary' : 'bg-warning') ?>">
              <?= P::e(Perm::rotulo($u['rol'])) ?></span></td>
          <td data-label="Sucursal" style="font-size:12.5px"><?= P::e($u['sucursal'] ?: '—') ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= number_format((int)$u['ventas']) ?></td>
          <td data-label="Estado">
            <span class="badge <?= $u['activo'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $u['activo'] ? 'Activo' : 'Bloqueado' ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <button type="button" class="lf-btn-ghost lf-rest" title="Restablecer contraseña"
                    data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>">⚿</button>
            <button type="button" class="lf-btn-ghost lf-blq"
                    title="<?= $u['activo'] ? 'Bloquear' : 'Desbloquear' ?>"
                    data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>"
                    data-a="<?= $u['activo'] ? 1 : 0 ?>"><?= $u['activo'] ? '<span class="lf-signo">&times;</span>' : '✓' ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    La contraseña nueva se muestra <b>una sola vez</b>. No se guarda en claro:
    anótala y entrégasela tú.
  </div>
</section>

<form method="post" action="/soporte/ajuste" id="fAj" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="base" value="<?= P::e($e['nombre_base_datos']) ?>">
  <input type="hidden" name="empresa" value="<?= (int)$e['id'] ?>">
  <input type="hidden" name="que" id="ajQue">
  <input type="hidden" name="clave" id="ajClave">
</form>
<form method="post" action="/soporte/restablecer" id="fRest" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="base" value="<?= P::e($e['nombre_base_datos']) ?>">
  <input type="hidden" name="empresa" value="<?= (int)$e['id'] ?>">
  <input type="hidden" name="id" id="rsId">
</form>
<form method="post" action="/soporte/alternar" id="fBlq" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="base" value="<?= P::e($e['nombre_base_datos']) ?>">
  <input type="hidden" name="empresa" value="<?= (int)$e['id'] ?>">
  <input type="hidden" name="id" id="blId">
</form>
<script>
document.querySelectorAll('.lf-aj').forEach(function(b){
  b.addEventListener('click', function(){
    var apagar = b.dataset.a === '1';
    if (!confirm((apagar ? '¿Apagar "' : '¿Encender "') + b.dataset.n + '" para esta empresa?\n\n'
      + (apagar ? 'Su gente deja de verlo al recargar.' : 'Vuelve a aparecer en su menú.'))) return;
    document.getElementById('ajQue').value = b.dataset.que;
    document.getElementById('ajClave').value = b.dataset.clave;
    document.getElementById('fAj').submit();
  });
});

document.querySelectorAll('.lf-rest').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Restablecer la contraseña de ' + b.dataset.n + '?\n\n'
      + 'La actual deja de servir en ese momento.')) return;
    document.getElementById('rsId').value = b.dataset.id;
    document.getElementById('fRest').submit();
  });
});
document.querySelectorAll('.lf-blq').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm((b.dataset.a === '1' ? '¿Bloquear a ' : '¿Desbloquear a ') + b.dataset.n + '?')) return;
    document.getElementById('blId').value = b.dataset.id;
    document.getElementById('fBlq').submit();
  });
});
</script>
