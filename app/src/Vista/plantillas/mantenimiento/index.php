<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
// Un rol de plataforma no tiene base de empresa, así que no hay
// diagnóstico que mostrar. Se rellena con nulos en vez de acceder a
// claves que no existen: el aviso de más arriba ya explica por qué.
$d = $diagnostico + [
  'php' => null, 'mysql' => null, 'zona_php' => null, 'zona_sql' => null,
  'hora_php' => null, 'hora_sql' => null, 'tablas' => [],
  'ventas_sin_area' => null, 'ventas_desfasadas' => null,
  'cobrado_mayor_total' => null, 'comisiones_sin_dueno' => null,
  'ventas_sin_cliente' => null, 'cajas_abiertas' => null,
];

// Las señales que de verdad importan, con su umbral.
$señales = $d['php'] === null ? [] : [
  ['Ventas sin área',            $d['ventas_sin_area'],      'Los reportes por área salen vacíos'],
  ['Ventas con fecha desfasada', $d['ventas_desfasadas'],    'Pueden caer en el mes equivocado'],
  ['Cobrado mayor al total',     $d['cobrado_mayor_total'],  'Alguien cobró de más'],
  ['Comisiones sin dueño',       $d['comisiones_sin_dueno'], 'Cuentan en el total, no se pagan'],
  ['Ventas sin cliente',         $d['ventas_sin_cliente'],   'No se pueden perseguir en cobranza'],
  ['Cajas abiertas ahora',       $d['cajas_abiertas'],       'Turnos sin cerrar'],
];
$hora_ok = ($d['hora_php'] && $d['hora_sql'])
         ? abs(strtotime($d['hora_php']) - strtotime($d['hora_sql'])) <= 60
         : null;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php
use LibertyFin\Datos\CuentaRepo as C;
$atrasadas = 0;
foreach ($empresas as $e) if ($e['version'] !== null && $e['version'] < $esquema['ultima']) $atrasadas++;
?>

<?php if (!empty($sinEmpresa)): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Entraste con una cuenta de plataforma, que <b>no pertenece a ninguna
    empresa</b>. Por eso no ves aquí las secciones apagables ni el diagnóstico:
    esos son de cada empresa y se miran desde su ficha, en
    <a href="/soporte">Empresas</a>.</span>
</div>
<?php endif; ?>

<?php if ($todo && $esquema['actual'] < $esquema['ultima']): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Esta empresa está en la versión <?= (int)$esquema['actual'] ?> de
    <?= (int)$esquema['ultima'] ?>.</b>
    Hasta ponerla al día, funciones nuevas pueden fallar sin explicación —por
    ejemplo, asignar el rol de soporte. Se aplica sola al entrar; si no, usa el
    botón de abajo.</span>
</div>
<?php endif; ?>

<?php /* ═══ SOLICITUDES DE ALTA ═══ */ ?>
<?php if ($puedeAlta): ?>
<?php if (!$altaLista): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>El registro de empresas está apagado: faltan las credenciales de
    <b>cPanel</b> en <code>config/integraciones.php</code>. Sin ellas no se puede
    crear la base de una empresa nueva, así que la página pública de registro
    tampoco existe.</span>
</div>
<?php endif; ?>

<section class="card" style="<?= $solicitudes
    ? 'border-color:color-mix(in srgb,var(--lf-brand) 36%,transparent)' : '' ?>">
  <header class="card-header">
    <div><span>Empresas por dar de alta</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Al aprobar se crea su base de datos, se carga el esquema y queda lista para entrar</p></div>
    <span class="badge <?= $solicitudes ? 'bg-warning' : 'bg-success' ?>">
      <?= $solicitudes ? count($solicitudes) . ' esperando' : 'Nada pendiente' ?></span>
  </header>

  <?php if (!$solicitudes): ?>
    <div class="card-body">
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:14px 0">
        No hay solicitudes de registro.</p>
    </div>
  <?php else: ?>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Negocio</th><th>Contacto</th><th>Distribuidor</th>
        <th>Esperando</th><th style="width:220px">Decisión</th></tr></thead>
      <tbody>
      <?php foreach ($solicitudes as $s): $h = (int)$s['horas']; ?>
        <tr>
          <td data-label="Negocio"><b style="font-weight:600"><?= P::e($s['nombre_empresa']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              <?= P::e($s['giro_comercial'] ?: 'sin giro') ?>
              <?= !empty($s['empleados']) ? ' · ' . P::e(\LibertyFin\Servicio\CrearEmpresa::EMPLEADOS[$s['empleados']] ?? $s['empleados'])
                  . ($s['empleados'] === '1' ? '' : ' personas') : '' ?>
              <?= $s['rfc'] ? ' · ' . P::e($s['rfc']) : '' ?>
              <?= !empty($s['plan']) && $s['plan'] !== 'prueba' ? ' · plan ' . P::e($s['plan']) : '' ?></span></td>
          <td data-label="Contacto" style="font-size:12.5px">
            <?= P::e($s['nombre_contacto']) ?>
            <a style="display:block;font-size:11.5px" href="mailto:<?= P::e($s['email_admin']) ?>">
              <?= P::e($s['email_admin']) ?></a>
            <?php if (!empty($s['telefono'])): ?>
              <a style="display:block;font-size:11.5px" href="tel:<?= P::e($s['telefono']) ?>"><?= P::e($s['telefono']) ?></a>
            <?php endif; ?></td>
          <td data-label="Distribuidor" style="font-size:12px">
            <?= $s['no_distribuidor'] ? P::e($s['no_distribuidor'])
                : '<span style="color:var(--lf-tinta-4)">directo</span>' ?></td>
          <td data-label="Esperando">
            <span class="badge <?= $h > 72 ? 'bg-danger' : ($h > 24 ? 'bg-warning' : 'bg-secondary') ?>">
              <?= $h < 24 ? $h . ' h' : floor($h/24) . ' d' ?></span></td>
          <td data-label="Decisión">
            <div style="display:flex;gap:6px">
              <form method="post" action="/mantenimiento/empresa/aprobar" style="flex:1"
                    onsubmit="return confirm('Se va a crear la base de datos de &quot;<?= P::e($s['nombre_empresa']) ?>&quot;.\n\nEsto no se deshace solo.');">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit" style="width:100%"
                        <?= $altaLista ? '' : 'disabled' ?>>Aprobar</button>
              </form>
              <button type="button" class="btn btn-secondary btn-sm lf-rech-emp" style="flex:1"
                      data-id="<?= (int)$s['id'] ?>" data-n="<?= P::e($s['nombre_empresa']) ?>">Rechazar</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    La contraseña del administrador se genera al aprobar y <b>se muestra una sola
    vez</b>. No se guarda en claro: anótala y entrégala tú.
  </div>
  <?php endif; ?>
</section>

<form method="post" action="/mantenimiento/empresa/rechazar" id="formRechEmp" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="reId">
  <input type="hidden" name="motivo" id="reMotivo">
</form>
<script>
document.querySelectorAll('.lf-rech-emp').forEach(function(b){
  b.addEventListener('click', function(){
    var m = prompt('¿Por qué se rechaza la solicitud de "' + b.dataset.n + '"?');
    if (!m || m.trim().length < 10) { if (m !== null) alert('Escribe al menos una frase.'); return; }
    document.getElementById('reId').value = b.dataset.id;
    document.getElementById('reMotivo').value = m.trim();
    document.getElementById('formRechEmp').submit();
  });
});
</script>

<?php endif; /* fin de solicitudes de alta */ ?>

<?php /* ═══ PAGOS DE PLAN ═══
   Comprobantes de transferencia que las empresas suben desde Mi cuenta → Plan.
   Aprobar cambia su plan y extiende su vencimiento. */
$pagosPlan = $pagosPlan ?? []; ?>
<?php if ($pagosPlan || Permisos::puede('revisar.docs')): ?>
<section class="card" style="<?= $pagosPlan
    ? 'border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)' : '' ?>">
  <header class="card-header">
    <div><span>Pagos de plan por revisar</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Verifica el depósito contra la referencia antes de aprobar: al aprobar, el plan
        cambia y el vencimiento se extiende.</p></div>
    <span class="badge <?= $pagosPlan ? 'bg-warning' : 'bg-success' ?>">
      <?= $pagosPlan ? count($pagosPlan) . ' esperando' : 'Nada pendiente' ?></span>
  </header>

  <?php if (!$pagosPlan): ?>
    <div class="card-body">
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:14px 0">
        No hay comprobantes esperando revisión.</p>
    </div>
  <?php else: ?>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Empresa</th><th>Plan</th><th class="text-end">Monto</th>
        <th>Referencia</th><th>Comprobante</th><th style="width:300px">Revisión</th></tr></thead>
      <tbody>
      <?php foreach ($pagosPlan as $pp): ?>
        <tr>
          <td data-label="Empresa"><b style="font-weight:600"><?= P::e($pp['nombre_empresa']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              Hoy: <?= P::e(ucfirst($pp['plan_actual'] ?: 'prueba')) ?>
              <?= $pp['fecha_vencimiento'] ? '· vence ' . date('d/m/Y', strtotime($pp['fecha_vencimiento'])) : '' ?></span></td>
          <td data-label="Plan" style="font-size:12.5px"><?= P::e($pp['nombre_plan']) ?>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              +<?= (int)$pp['meses'] ?> mes<?= (int)$pp['meses'] === 1 ? '' : 'es' ?></span></td>
          <td data-label="Monto" class="text-end lf-mono" style="font-weight:700">
            <?= \LibertyFin\Dominio\Dinero::pesos($pp['monto']) ?></td>
          <td data-label="Referencia" class="lf-mono" style="font-size:12px"><?= P::e($pp['referencia']) ?></td>
          <td data-label="Comprobante">
            <a href="<?= P::e($pp['comprobante']) ?>" target="_blank" rel="noopener"
               style="font-size:12.5px;font-weight:600">Abrir</a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              hace <?= (int)$pp['horas'] < 24 ? (int)$pp['horas'] . ' h' : floor($pp['horas']/24) . ' d' ?></span></td>
          <td data-label="Revisión">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <form method="post" action="/mantenimiento/pago" style="flex:1;min-width:90px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
                <input type="hidden" name="decision" value="aprobado">
                <button class="btn btn-primary btn-sm" type="submit" style="width:100%">Aprobar</button>
              </form>
              <form method="post" action="/mantenimiento/pago" style="flex:2;min-width:170px;display:flex;gap:6px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
                <input type="hidden" name="decision" value="rechazado">
                <input class="form-control form-control-sm" name="motivo" placeholder="Motivo" required
                       maxlength="300" style="min-width:0">
                <button class="btn btn-secondary btn-sm" type="submit">Rechazar</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ═══ BANDEJA DE REVISIÓN ═══ */ ?>
<section class="card" style="<?= $porRevisar
    ? 'border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)' : '' ?>">
  <header class="card-header">
    <div><span>Documentos por revisar</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        De todas las empresas. Hasta que se aprueben, el negocio no puede cobrar con tarjeta.</p></div>
    <span class="badge <?= $porRevisar ? 'bg-warning' : 'bg-success' ?>">
      <?= $porRevisar ? count($porRevisar) . ' esperando' : 'Nada pendiente' ?></span>
  </header>

  <?php if (!$porRevisar): ?>
    <div class="card-body">
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:14px 0">
        No hay documentos esperando revisión.</p>
    </div>
  <?php else: ?>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Empresa</th><th>Documento</th><th>Esperando</th>
        <th>Archivo</th><th style="width:230px">Revisión</th></tr></thead>
      <tbody>
      <?php foreach ($porRevisar as $d):
        $h = (int)$d['horas'];
        $urge = $h > 72; ?>
        <tr>
          <td data-label="Empresa"><b style="font-weight:600"><?= P::e($d['empresa']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              <?= P::e($d['quien'] ?: '') ?></span></td>
          <td data-label="Documento" style="font-size:12.5px">
            <?= P::e(C::DOCUMENTOS[$d['tipo']][0] ?? $d['tipo']) ?></td>
          <td data-label="Esperando">
            <span class="badge <?= $urge ? 'bg-danger' : ($h > 24 ? 'bg-warning' : 'bg-secondary') ?>">
              <?= $h < 24 ? $h . ' h' : floor($h/24) . ' d' ?></span></td>
          <td data-label="Archivo">
            <a href="<?= P::e($d['ruta_archivo']) ?>" target="_blank" rel="noopener"
               style="font-size:12.5px;font-weight:600">Abrir</a>
            <?php if ($d['tamano_bytes']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                <?= round($d['tamano_bytes']/1024) ?> KB</span>
            <?php endif; ?>
          </td>
          <td data-label="Revisión">
            <div style="display:flex;gap:6px">
              <form method="post" action="/mantenimiento/revisar" style="flex:1">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="base" value="<?= P::e($d['base']) ?>">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="decision" value="aprobado">
                <button class="btn btn-primary btn-sm" type="submit" style="width:100%">Aprobar</button>
              </form>
              <button type="button" class="btn btn-secondary btn-sm lf-rechazar"
                      data-base="<?= P::e($d['base']) ?>" data-id="<?= (int)$d['id'] ?>"
                      data-doc="<?= P::e(C::DOCUMENTOS[$d['tipo']][0] ?? $d['tipo']) ?>"
                      style="flex:1">Rechazar</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Al rechazar hay que escribir el motivo. Es lo único que el negocio tiene para
    saber qué corregir: un "rechazado" a secas garantiza que vuelvan a subir lo mismo.
  </div>
  <?php endif; ?>
</section>

<form method="post" action="/mantenimiento/revisar" id="formRechazo" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="decision" value="rechazado">
  <input type="hidden" name="base" id="rzBase">
  <input type="hidden" name="id" id="rzId">
  <input type="hidden" name="motivo" id="rzMotivo">
</form>
<script>
document.querySelectorAll('.lf-rechazar').forEach(function(b){
  b.addEventListener('click', function(){
    var m = prompt('¿Por qué se rechaza "' + b.dataset.doc + '"?\n\n'
      + 'Esto le llega al negocio y es lo único que tiene para corregirlo.');
    if (!m || m.trim().length < 10) {
      if (m !== null) alert('Escribe al menos una frase.');
      return;
    }
    document.getElementById('rzBase').value = b.dataset.base;
    document.getElementById('rzId').value = b.dataset.id;
    document.getElementById('rzMotivo').value = m.trim();
    document.getElementById('formRechazo').submit();
  });
});
</script>

<?php if ($todo): ?>
<section class="card" style="<?= $atrasadas ? 'border-color:color-mix(in srgb,var(--lf-amb) 34%,transparent)' : '' ?>">
  <header class="card-header">
    <div><span>Esquema de las bases</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        LibertyFin crea una base por empresa. Cada una tiene que estar en la misma versión.</p></div>
    <span class="badge <?= $atrasadas ? 'bg-warning' : 'bg-success' ?>">
      <?= $atrasadas ? $atrasadas . ' atrasada' . ($atrasadas==1?'':'s') : 'Todas al día' ?></span>
  </header>

  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Empresa</th><th>Base</th><th class="text-end">Versión</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$empresas): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--lf-tinta-4);padding:28px">
          No se pudo leer la lista de empresas.</td></tr>
      <?php endif; ?>
      <?php foreach ($empresas as $e):
        $atras = $e['version'] !== null && $e['version'] < $esquema['ultima']; ?>
        <tr style="<?= $e['activo'] ? '' : 'opacity:.6' ?>">
          <td data-label="Empresa"><b style="font-weight:600"><?= P::e($e['nombre']) ?></b>
            <?php if (!$e['activo']): ?>
              <span class="badge bg-secondary" style="margin-left:6px">Inactiva</span><?php endif; ?></td>
          <td data-label="Base" class="lf-mono" style="font-size:12px"><?= P::e($e['base']) ?></td>
          <td data-label="Versión" class="text-end lf-mono">
            <?= $e['version'] === null ? '—' : (int)$e['version'] ?> / <?= (int)$esquema['ultima'] ?></td>
          <td data-label="Estado">
            <?php if ($e['error']): ?>
              <span class="badge bg-danger"><?= P::e($e['error']) ?></span>
            <?php elseif ($atras): ?>
              <span class="badge bg-warning">Le faltan <?= $esquema['ultima'] - $e['version'] ?></span>
            <?php else: ?>
              <span class="badge bg-success">Al día</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card-body" style="border-top:1px solid var(--lf-linea)">
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:250px">
        <b style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">
          Qué trae cada versión</b>
        <?php foreach ($esquema['que_hace'] as $v => $q): ?>
          <div style="display:flex;gap:9px;padding:3px 0;font-size:12.5px;color:var(--lf-tinta-3)">
            <span class="lf-mono" style="color:var(--lf-tinta-4);min-width:18px"><?= $v ?></span>
            <span><?= P::e($q) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="flex:1;min-width:250px">
        <form method="post" action="/mantenimiento/migrar">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <button class="btn <?= $atrasadas ? 'btn-primary' : 'btn-secondary' ?>" type="submit"
                  style="width:100%"<?= $atrasadas ? '' : ' disabled' ?>>
            <?= W::icono('cobro','15px') ?>Poner todas al día</button>
        </form>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.55">
          Cada base se pone al día sola cuando alguien entra, así que esto casi
          nunca hace falta. Sirve para empresas donde nadie ha entrado todavía,
          y para ver POR QUÉ una base no avanza.
          Las migraciones son repetibles: correrlas dos veces no hace nada.
        </p>
      </div>
    </div>
  </div>

  <?php /* Lo que pasó la última vez que se apretó el botón, base por
           base. Antes esto solo iba al log del servidor, que desde
           cPanel casi nadie abre: una base atorada decía «con problema»
           y ahí se acababa la pista. */ ?>
  <?php if (!empty($migDetalle)): ?>
  <div class="card-body" style="border-top:1px solid var(--lf-linea)">
    <b style="font-size:13px;font-weight:600;display:block;margin-bottom:10px">
      Resultado de la última actualización</b>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Empresa</th><th>Versión</th><th>Qué pasó</th></tr></thead>
        <tbody>
        <?php foreach ($migDetalle as $m): ?>
          <tr>
            <td data-label="Empresa"><?= P::e($m['empresa']) ?>
              <small class="lf-mono" style="display:block;color:var(--lf-tinta-4)">
                <?= P::e($m['base']) ?></small></td>
            <td data-label="Versión" class="lf-mono">
              <?php if ($m['de'] === null): ?>—
              <?php elseif ($m['de'] === $m['a']): ?><?= (int)$m['a'] ?>
              <?php else: ?><?= (int)$m['de'] ?> → <b><?= (int)$m['a'] ?></b><?php endif; ?></td>
            <td data-label="Qué pasó">
              <?php if ($m['error'] === ''): ?>
                <span class="badge bg-success">
                  <?= $m['de'] === $m['a'] ? 'Ya estaba al día' : 'Actualizada' ?></span>
              <?php else: ?>
                <span class="badge bg-danger">Se atoró</span>
                <div class="lf-mono" style="font-size:11.5px;color:var(--lf-tinta-3);
                     margin-top:6px;white-space:pre-wrap;word-break:break-word">
                  <?= P::e($m['error']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:10px;line-height:1.55">
      Si dice <b>«Se atoró en la migración N»</b>, ése es el renglón que hay que
      corregir: el mensaje de abajo es lo que contestó MySQL, tal cual.
      Si todas dicen <b>«Ya estaba al día»</b> pero te falta una función, lo que
      no subió es el <code>Migraciones.php</code> nuevo.
    </p>
  </div>
  <?php endif; ?>
</section>

<?php if ($señales): ?>
<section class="card">
  <header class="card-header">
    <div><span>Revisión de la base</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Lo que suele estar detrás de un número que no cuadra</p></div>
  </header>
  <div class="lf-equipo" style="padding:4px 20px 18px">
    <?php foreach ($señales as $s): list($n, $v, $porque) = $s; $mal = $v > 0; ?>
      <div class="lf-pers<?= $mal ? '' : ' sin' ?>" style="align-items:flex-start">
        <span class="lf-tile <?= $mal ? 'a' : '' ?>" style="width:34px;height:34px;
              border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono($mal ? 'alerta' : 'cobro', '16px') ?></span>
        <div style="flex:1;min-width:0">
          <b style="white-space:normal"><?= P::e($n) ?></b>
          <small style="white-space:normal;line-height:1.4"><?= P::e($porque) ?></small>
        </div>
        <span class="mn" style="color:<?= $mal ? 'var(--lf-amb)' : 'var(--lf-tinta-4)' ?>">
          <?= (int)$v ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer">
    Un cero en todas no garantiza que esté bien, pero cualquier número distinto
    de cero explica un problema concreto.
  </div>
</section>
<?php endif; ?>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Secciones</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Apaga las que esta empresa no usa</p></div>
    </header>
    <div style="padding:0 10px 8px">
      <?php foreach ($secciones as $k => $s): ?>
        <div class="lf-row">
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($s['rotulo']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= $s['activa'] ? 'Visible en el menú' : 'Oculta para todos' ?></small>
          </span>
          <form method="post" action="/mantenimiento/secciones">
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <input type="hidden" name="seccion" value="<?= P::e($k) ?>">
            <button class="btn btn-sm <?= $s['activa'] ? 'btn-secondary' : 'btn-primary' ?>" type="submit">
              <?= $s['activa'] ? 'Apagar' : 'Encender' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer">
      Panel, Caja, Ventas, Clientes y Ajustes no se pueden apagar: sin ellas no
      se puede trabajar, y el usuario pensaría que el sistema se rompió.
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Entorno</header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ([
          'PHP'            => $d['php'],
          'MySQL'          => $d['mysql'],
          'Zona de PHP'    => $d['zona_php'],
          'Zona de MySQL'  => $d['zona_sql'],
          'Hora de PHP'    => $d['hora_php'],
          'Hora de MySQL'  => $d['hora_sql'],
        ] as $k => $v): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b class="lf-mono" style="font-size:12px;text-align:right"><?= P::e($v) ?></b>
          </div>
        <?php endforeach; ?>
        <?php if ($hora_ok !== null): ?>
        <div style="margin-top:12px;padding:11px 13px;border-radius:var(--lf-r);font-size:12.5px;
             background:<?= $hora_ok ? 'var(--lf-brand-soft)' : 'var(--lf-rojo-soft)' ?>;
             color:<?= $hora_ok ? 'var(--lf-brand-2)' : 'var(--lf-rojo)' ?>">
          <?= $hora_ok
            ? 'PHP y MySQL están a la misma hora.'
            : 'PHP y MySQL NO coinciden. Las ventas de la tarde pueden registrarse al día siguiente.' ?>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card-header">
        <div><span>Correo saliente</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Sin esto, ningún aviso llega</p></div>
        <span class="badge <?= $correoListo ? 'bg-success' : 'bg-secondary' ?>">
          <?= $correoListo ? 'Configurado' : 'Pendiente' ?></span>
      </header>
      <div class="card-body">
        <?php if (!$correoListo): ?>
          <p style="font-size:12.5px;color:var(--lf-tinta-3);margin:0;line-height:1.55">
            Faltan las credenciales de <code>smtp</code> en
            <code>config/integraciones.php</code>. Mientras tanto nadie recibe su
            contraseña al darse de alta, ni el motivo de un documento rechazado.
          </p>
        <?php else: ?>
          <form method="post" action="/mantenimiento/correo"
                class="lf-campos" style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <div style="flex:1;min-width:190px">
              <label class="form-label">Mandar una prueba a</label>
              <input class="form-control" type="email" name="para"
                     value="<?= P::e($miCorreo) ?>" placeholder="tu@correo.com" required>
            </div>
            <button class="btn btn-secondary" type="submit">Enviar</button>
          </form>
          <?php if ($correoPrueba): ?>
            <div style="margin-top:13px;padding:11px 13px;border-radius:var(--lf-r);font-size:12.5px;
                 background:<?= $correoPrueba['ok'] ? 'var(--lf-brand-soft)' : 'var(--lf-rojo-soft)' ?>;
                 color:<?= $correoPrueba['ok'] ? 'var(--lf-brand-2)' : 'var(--lf-rojo)' ?>">
              <?= $correoPrueba['ok']
                  ? 'El servidor aceptó el mensaje. Que salga no garantiza que llegue: revisa la bandeja y el spam.'
                  : P::e($correoPrueba['error']) ?>
            </div>
          <?php endif; ?>
          <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.5">
            Se avisa de cinco cosas: cuenta creada, solicitud rechazada, documento
            rechazado, respuesta a un ticket y suscripción por vencer. Nada más:
            un correo por cada venta convierte el buzón en ruido.
          </p>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Integraciones</header>
      <div style="padding:0 10px 8px">
        <?php foreach ($integraciones as $k => $i): ?>
          <div class="lf-row">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($i['nombre']) ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
                <?= P::e($k) ?></small>
            </span>
            <?php if ($i['activa'] && $i['sandbox']): ?>
              <span class="badge bg-warning">Pruebas</span>
            <?php elseif ($i['activa']): ?>
              <span class="badge bg-success">Activa</span>
            <?php else: ?>
              <span class="badge bg-secondary">Pendiente</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Tamaño de las tablas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Si la columna de índice sale en cero, esa tabla se recorre completa en cada consulta</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Tabla</th><th class="text-end">Filas</th>
        <th class="text-end">Datos + índice</th><th class="text-end">Solo índice</th></tr></thead>
      <tbody>
      <?php foreach ($d['tablas'] as $t): ?>
        <tr>
          <td data-label="Tabla" class="lf-mono" style="font-size:12.5px"><?= P::e($t['tabla']) ?></td>
          <td data-label="Filas" class="text-end lf-mono"><?= number_format((int)$t['filas']) ?></td>
          <td data-label="Datos + índice" class="text-end lf-mono"><?= number_format((int)$t['kb']) ?> KB</td>
          <td data-label="Solo índice" class="text-end lf-mono"
              style="color:<?= (int)$t['ikb'] === 0 ? 'var(--lf-amb)' : 'var(--lf-tinta-3)' ?>">
            <?= number_format((int)$t['ikb']) ?> KB</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php endif; /* fin de lo que solo ve soporte */ ?>

<section class="card">
  <header class="card-header">Qué puede cada rol</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Rol</th><th>Para quién</th>
        <th class="text-end">Ve</th><th class="text-end">Hace</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $k => $r): $s = Permisos::resumen($k); ?>
        <tr>
          <td data-label="Rol"><b style="font-weight:600"><?= P::e($r['rotulo']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                  font-family:var(--lf-mono)"><?= P::e($k) ?>
              <?php if (($r['nivel'] ?? '') === 'plataforma'): ?>
                <span class="badge bg-secondary" style="margin-left:4px">plataforma</span>
              <?php endif; ?></span></td>
          <td data-label="Para quién" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($r['para']) ?></td>
          <td data-label="Ve" class="text-end lf-mono"><?= $s['ve'] ?></td>
          <td data-label="Hace" class="text-end lf-mono"><?= $s['hace'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Soporte ve casi todo y no mueve dinero: no cobra, no cancela pagos ni asigna
    comisiones. Si también pudiera, no habría forma de saber si un descuadre lo
    causó la empresa o quien vino a ayudar.
  </div>
</section>