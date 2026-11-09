<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$v       = $venta;
$total   = (float)$v['total'];
$cobrado = (float)$v['cobrado'];
$saldo   = (float)$v['saldo'];
$gastoTotal = (float)$v['gastos'];   // el monto
// OJO: $gastos (sin sufijo) es la LISTA que manda el controlador. No pisarla.
$avance  = D::pct($cobrado, $total);
$base    = (float)$v['subtotal'] - (float)$v['descuento'];
$neto    = $base - $gastoTotal;
$sumAsig = 0; $sumDev = 0;
foreach ($comisiones as $c) { $sumAsig += (float)$c['asignada']; $sumDev += (float)$c['devengada']; }
$ini = function ($n) { $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : '')); };
?>

<?php if ($nueva): ?>
<div class="alert alert-success" style="margin-bottom:18px">
  <?= W::icono('cobro','18px') ?><span><b>Venta registrada.</b> Folio <?= P::e($v['codigo_venta']) ?>.</span>
</div>
<?php endif; ?>
<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($v['estado'] === 'cancelada'): ?>
<div class="alert alert-danger lf-venta-cancelada" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Venta cancelada</b><?= !empty($v['cancelada_en']) ? ' el ' . P::e(date('d/m/Y H:i', strtotime($v['cancelada_en']))) : '' ?>
    <?= !empty($v['cancelada_por']) ? ' por ' . P::e($v['cancelada_por']) : '' ?>.
    <?= !empty($v['motivo_cancelacion']) ? 'Motivo: ' . P::e($v['motivo_cancelacion']) . '.' : '' ?>
    No cuenta en reportes, cobranza ni comisiones.</span>
</div>
<?php endif; ?>

<div style="display:flex;gap:9px;margin-bottom:18px;flex-wrap:wrap">
  <a class="btn btn-secondary btn-sm" href="/ventas/<?= (int)$v['id'] ?>/ticket" target="_blank">
    <?= W::icono('venta','15px') ?>Ver ticket</a>
  <a class="btn btn-secondary btn-sm" href="/ventas/<?= (int)$v['id'] ?>/ticket?auto=1" target="_blank">
    <?= W::icono('baja','15px') ?>Imprimir</a>
  <?php /* El de la factura solo aparece cuando hay factura: un botón que
           lleva a «todavía no se timbra» es un botón que estorba. */
  if (!empty($v['factura_uuid']) && \LibertyFin\Dominio\Permisos::puede('ver.facturacion')): ?>
    <a class="btn btn-secondary btn-sm" href="/ventas/<?= (int)$v['id'] ?>/ticket-factura" target="_blank">
      <?= W::icono('venta','15px') ?>Ticket de la factura</a>
  <?php endif; ?>
  <?php /* Mandarlo a domicilio se decide aquí, con la venta enfrente, no
           en otra pantalla: el botón solo sale si el módulo Envíos está
           encendido y la venta no está cancelada. */
  if (!empty($puedeEnviar) && $v['estado'] !== 'cancelada'): ?>
    <a class="btn btn-secondary btn-sm" href="/envios/nuevo?venta=<?= (int)$v['id'] ?>">
      <?= W::icono('camion','15px') ?>Enviar a domicilio</a>
  <?php endif; ?>
  <a class="btn btn-secondary btn-sm" href="/ventas">Volver al listado</a>
  <?php if (\LibertyFin\Dominio\Permisos::puede('cancelar.venta') && $v['estado'] !== 'cancelada'): ?>
    <span style="flex:1"></span>
    <button type="button" class="btn btn-secondary btn-sm lf-btn-peligro" id="btnCancelarVenta">Cancelar venta</button>
  <?php endif; ?>
</div>
<?php if (\LibertyFin\Dominio\Permisos::puede('cancelar.venta') && $v['estado'] !== 'cancelada'): ?>
<form method="post" action="/ventas/<?= (int)$v['id'] ?>/cancelar" id="formCancelarVenta" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="motivo" id="cancVentaMotivo">
</form>
<script>
(function(){
  var b = document.getElementById('btnCancelarVenta');
  if (!b) return;
  b.addEventListener('click', function(){
    var m = prompt('Cancelar la venta <?= P::e($v['codigo_venta']) ?>.\n\n'
      + 'Se cancelan sus cobros (<?= P::e(D::pesos($cobrado)) ?>), sus comisiones, y lo que se llevó regresa al inventario.'
      + ' No se puede deshacer.\n\n¿Por qué se cancela?');
    if (m === null) return;
    if (m.trim().length < 5) { alert('Escribe el motivo (al menos unas palabras).'); return; }
    document.getElementById('cancVentaMotivo').value = m.trim();
    document.getElementById('formCancelarVenta').submit();
  });
})();
</script>
<?php endif; ?>

<?php /* Las guías de esta venta. Se enseñan aquí porque la pregunta
         «¿ya se le mandó?» se hace mirando la venta, no el listado de
         envíos. */
if (!empty($envios)): ?>
<section class="card" style="padding:14px 18px;margin-bottom:18px">
  <div class="tit" style="margin-bottom:8px"><?= W::icono('camion','16px') ?><b>Envíos</b></div>
  <?php foreach ($envios as $en): ?>
    <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:6px">
      <a class="lf-mono" href="/envios/<?= (int)$en['id'] ?>"><b><?= P::e($en['guia'] ?: '—') ?></b></a>
      <span><?= P::e(\LibertyFin\Servicio\Envios::carrier($en['carrier'])) ?></span>
      <span class="badge <?= $en['estado'] === 'entregado' ? 'bg-success'
            : ($en['estado'] === 'cancelado' ? 'bg-secondary' : 'bg-info') ?>">
        <?= P::e($en['estado_rotulo']) ?></span>
      <small class="pista"><?= P::e($en['ultimo_estado'] ?: '') ?></small>
      <span style="flex:1"></span>
      <?php if ($en['etiqueta_url']): ?>
        <a class="btn btn-secondary btn-sm" href="<?= P::e($en['etiqueta_url']) ?>"
           target="_blank" rel="noopener">Etiqueta</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!empty($trabajo)): $tr = $trabajo['t']; $estT = \LibertyFin\Servicio\Trabajos::ESTADOS; ?>
<section class="card lf-trab-venta">
  <form method="post" action="/ventas/<?= (int)$v['id'] ?>/trabajo" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div class="tit"><?= W::icono('tablero','16px') ?><b>Trabajo</b>
      <?php if ($tr['actualizado_en']): ?><small>Cambió <?= P::e($tr['actualizado_por'] ?: '') ?> el <?= P::e(date('d/m H:i', strtotime($tr['actualizado_en']))) ?></small><?php endif; ?></div>
    <div style="width:190px"><label class="form-label" for="trEstado">Estado</label>
      <select class="form-select form-select-sm" name="estado" id="trEstado" <?= $trabajo['puede'] ? '' : 'disabled' ?>>
        <?php foreach ($estT as $k => $t): ?><option value="<?= $k ?>" <?= $tr['estado'] === $k ? 'selected' : '' ?>><?= P::e($t) ?></option><?php endforeach; ?>
      </select></div>
    <div style="width:180px"><label class="form-label" for="trResp">Responsable</label>
      <select class="form-select form-select-sm" name="responsable_id" id="trResp" <?= $trabajo['puede'] ? '' : 'disabled' ?>>
        <option value="">—</option>
        <?php foreach ($trabajo['equipo'] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$tr['responsable_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= P::e($p['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div style="width:150px"><label class="form-label" for="trEnt">Entrega</label>
      <input class="form-control form-control-sm" type="date" name="entrega" id="trEnt" value="<?= P::e($tr['entrega'] ?? '') ?>" <?= $trabajo['puede'] ? '' : 'disabled' ?>></div>
    <div style="flex:1;min-width:180px"><label class="form-label" for="trNota">Nota</label>
      <input class="form-control form-control-sm" name="nota" id="trNota" maxlength="255" value="<?= P::e($tr['nota'] ?? '') ?>" <?= $trabajo['puede'] ? '' : 'disabled' ?>></div>
    <?php if ($trabajo['puede']): ?><button class="btn btn-secondary btn-sm" type="submit">Guardar</button><?php endif; ?>
  </form>
</section>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($cobrado) ?></div>
    <div class="stat-label">Cobrado</div>
    <?php W::avance($avance); ?>
    <div class="stat-meta"><?= $avance ?>% de <?= D::pesos($total) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= $saldo > 0.01 ? 'a' : 'g' ?>"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($saldo) ?></div>
    <div class="stat-label">Saldo</div>
    <div class="stat-meta"><?php W::estado($saldo, $v['estado']); ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= D::pesos($neto) ?></div>
    <div class="stat-label">Neto comisionable</div>
    <div class="stat-meta">base <?= D::corto($base) ?> − gastos <?= D::corto($gastoTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($sumDev) ?></div>
    <div class="stat-label">Comisión devengada</div>
    <?php if ($sumAsig > 0) W::avance(D::pct($sumDev, $sumAsig)); ?>
    <div class="stat-meta">de <?= D::pesos($sumAsig) ?> si liquida</div>
  </div>
</div>

<div class="lf-split">
  <div>
    <section class="card">
      <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span>Cobranza</span>
        <?php $vivos = count(array_filter($pagos, function($p){ return !$p['cancelado']; })); ?>
        <span class="badge bg-secondary"><?= $vivos ?> pago<?= $vivos == 1 ? '' : 's' ?></span>
      </header>
      <div class="table-responsive lf-cards lf-rejilla" style="padding:0 12px 6px">
        <table class="table table-hover">
          <thead><tr>
            <th>Fecha</th><th>Tipo</th><th>Método</th>
            <th class="text-end">Monto</th><th class="text-end">Comisión</th><th></th>
          </tr></thead>
          <tbody>
          <?php if (!$pagos): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:28px">
              Todavía no hay ningún cobro.</td></tr>
          <?php endif; ?>
          <?php foreach ($pagos as $n => $p): $canc = (int)$p['cancelado'] === 1;
                /* EL SUBFOLIO DEL COBRO.
                   Doce abonos de una clienta que deja $1,500 al mes
                   salian los doce con el folio de la venta: nadie podia
                   referirse a uno por telefono ni cuadrar un deposito
                   contra el abono que le toca. Ver Servicio\Folio.

                   Los cobros guardados ANTES de que existiera la
                   columna no lo traen. Se arma al vuelo con su posicion
                   y se marca con ~, para no enseñar un hueco pero
                   tampoco hacer pasar por folio un numero inventado. */
                $sub = trim((string)($p['folio'] ?? ''));
                $suyo = $sub !== '';
                if (!$suyo) $sub = \LibertyFin\Servicio\Folio::deRespaldo($venta['codigo_venta'], $n + 1); ?>
            <tr style="<?= $canc ? 'opacity:.5' : '' ?>">
              <td data-label="Fecha" class="lf-mono" style="font-size:12px">
                <span style="display:block;font-weight:600;color:var(--lf-tinta-2)"
                      <?= $suyo ? '' : 'title="Cobro anterior a los subfolios: este número es aproximado"' ?>>
                  <?= P::e($sub) ?><?= $suyo ? '' : '<span style="opacity:.5">~</span>' ?></span>
                <?= date('d/m/Y', strtotime($p['fecha_pago'])) ?>
                <?php if ($p['referencia']): ?>
                  <span style="display:block;color:var(--lf-tinta-4);font-size:11px"><?= P::e($p['referencia']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Tipo">
                <span class="badge <?= $canc ? 'bg-danger' : 'bg-secondary' ?>">
                  <?= $canc ? 'Cancelado' : P::e($p['tipo']) ?></span>
                <?php if ($canc && $p['motivo_cancelacion']): ?>
                  <span style="display:block;color:var(--lf-tinta-4);font-size:11px;margin-top:3px">
                    <?= P::e($p['motivo_cancelacion']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Método" style="font-size:12.5px"><?= P::e($p['metodo_pago']) ?>
                <?php if (!empty($p['banco'])): ?>
                  <span style="display:block;color:var(--lf-tinta-4);font-size:11px"><?= P::e($p['banco']) ?></span>
                <?php endif; ?></td>
              <td data-label="Monto" class="text-end lf-mono" style="font-weight:700">
                <?= $canc ? '<s>' . D::pesos($p['monto']) . '</s>' : D::pesos($p['monto']) ?></td>
              <td data-label="Comisión" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
                <?= $canc ? '—' : D::pesos($p['comision']) ?></td>
              <td style="text-align:right">
                <?php if (!$canc && $esAdmin): ?>
                  <button type="button" class="lf-btn-ghost lf-cancelar" data-pago="<?= (int)$p['id'] ?>"
                          data-monto="<?= P::e(D::pesos($p['monto'])) ?>" title="Cancelar pago"><span class="lf-signo">&times;</span><span class="lf-txt-card">Cancelar pago</span></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($saldo > 0.01 && $v['estado'] !== 'cancelada'): ?>
      <div style="border-top:1px solid var(--lf-linea);padding:16px 20px;background:var(--lf-vidrio)">
        <form method="post" action="/ventas/<?= (int)$v['id'] ?>/pagar"
              class="lf-campos" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <div style="flex:1;min-width:130px">
            <label class="form-label">Abono</label>
            <input class="form-control lf-mono" type="number" name="monto" step="0.01" min="0.01"
                   max="<?= $saldo ?>" value="<?= number_format($saldo, 2, '.', '') ?>" required>
          </div>
          <div style="min-width:130px">
            <label class="form-label">Fecha</label>
            <input class="form-control" type="date" name="fecha" value="<?= date('Y-m-d') ?>">
          </div>
          <div style="min-width:130px">
            <label class="form-label">Método</label>
            <select class="form-select" name="metodo">
              <?php
              // Solo lo que esta empresa puede cobrar. Ofrecer tarjeta a
              // quien la tiene apagada hace que se elija y el abono falle
              // al guardar, con el cliente esperando.
              $rot = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta'];
              foreach ($metodos as $k): ?>
                <option value="<?= P::e($k) ?>" <?= $k === 'transferencia' ? 'selected' : '' ?>>
                  <?= P::e($rot[$k] ?? $k) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php /* El banco, con transferencia o tarjeta: texto libre con
                   sugerencias. En efectivo se esconde (no hay banco). */ ?>
          <div style="flex:1;min-width:130px" id="abonoBanco">
            <label class="form-label">Banco</label>
            <input class="form-control" type="text" name="banco" list="lfBancosAbono" maxlength="60"
                   autocomplete="off" placeholder="Opcional">
            <?= \LibertyFin\Dominio\Bancos::datalist('lfBancosAbono') ?>
          </div>
          <div style="flex:1;min-width:130px">
            <label class="form-label">Referencia</label>
            <input class="form-control" type="text" name="referencia" placeholder="Opcional">
          </div>
          <button class="btn btn-primary" type="submit"><?= W::icono('cobro','15px') ?>Registrar</button>
        </form>
        <script>
        (function(){
          var sel = document.querySelector('form[action$="/pagar"] select[name=metodo]');
          var caja = document.getElementById('abonoBanco');
          if (!sel || !caja) return;
          function ver(){
            caja.hidden = sel.value === 'efectivo';
            if (caja.hidden) caja.querySelector('input').value = '';
          }
          sel.addEventListener('change', ver);
          ver();
        })();
        </script>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:10px">
          No se puede abonar más del saldo. La comisión se libera en proporción a lo cobrado.</p>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($comisiones): ?>
    <section class="card">
      <header class="card-header">Comisiones</header>
      <div class="table-responsive lf-cards lf-rejilla" style="padding:0 12px 6px">
        <table class="table">
          <thead><tr>
            <th>Colaborador</th><th>Área</th><th class="text-end">%</th>
            <th class="text-end">Si liquida</th><th class="text-end">Devengada</th>
            <th class="text-end">Pendiente</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($comisiones as $c): $sd = $c['colaborador_nombre'] === 'POR ASIGNAR'; ?>
            <tr style="<?= $sd ? 'opacity:.72' : '' ?>">
              <td data-label="Colaborador">
                <span style="display:flex;align-items:center;gap:9px">
                  <span class="lf-av <?= $sd ? 'gris' : '' ?>" style="width:28px;height:28px;font-size:10px">
                    <?= $sd ? '?' : P::e($ini($c['colaborador_nombre'])) ?></span>
                  <span style="min-width:0">
                    <b style="font-weight:600"><?= P::e($c['colaborador_nombre']) ?></b>
                    <?php if (count($bases) > 1): ?>
                      <small style="display:block;font-size:11px;color:var(--lf-tinta-4)">
                        <?= $c['producto'] ? 'en ' . P::e($c['producto']) : 'toda la venta' ?></small>
                    <?php endif; ?>
                  </span>
                </span>
              </td>
              <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area_nombre']) ?></span></td>
              <td data-label="%" class="text-end lf-mono"><?= preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format((float)$c['pct'], 4, '.', '')) ?>%</td>
              <td data-label="Si liquida" class="text-end lf-mono" style="color:var(--lf-tinta-3)"><?= D::pesos($c['asignada']) ?></td>
              <td data-label="Devengada" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($c['devengada']) ?></td>
              <td data-label="Pendiente" class="text-end lf-mono" style="color:var(--lf-amb)"><?= D::pesos($c['pendiente']) ?></td>
              <td style="text-align:right">
                <?php if ($esAdmin): ?>
                  <button type="button" class="lf-btn-ghost lf-quitar-com"
                          data-com="<?= (int)$c['id'] ?>"
                          data-quien="<?= P::e($c['colaborador_nombre']) ?>"
                          title="Quitar esta comisión"><span class="lf-signo">&times;</span><span class="lf-txt-card">Quitar comisión</span></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        Se ha cobrado el <?= $avance ?>%, así que solo se devenga esa parte.
        Lo pendiente se libera conforme el cliente pague.
      </div>
    </section>
    <?php endif; ?>

    <?php if ($esAdmin && $v['estado'] !== 'cancelada'):
      // La base de cada producto: sin IVA, sin su costo y con su parte de
      // los gastos. Es la misma cuenta que hace AsignarComision al guardar.
      $varios  = count($bases) > 1;
      $conBase = array_filter($bases, function ($b) { return $b['base'] > 0; });
      $unaBase = $bases ? reset($bases)['base'] : 0; ?>
    <section class="card">
      <header class="card-header">
        <span><?= $comisiones ? 'Agregar otra comisión' : 'Asignar comisión' ?></span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          <?php if ($varios): ?>
            La venta tiene <?= count($bases) ?> productos: la comisión va a uno y se calcula sobre su propia base,
            sin IVA, sin costo y con su parte de los gastos
          <?php else: ?>
            Se calcula sobre una base de <?= D::pesos($unaBase) ?>: sin IVA, sin costo y ya con los gastos restados
          <?php endif; ?></p>
      </header>
      <div class="card-body">
        <?php if (!$bases): ?>
          <p style="font-size:13px;color:var(--lf-amb);margin:0">
            Esta venta no tiene productos, así que no hay sobre qué comisionar.</p>
        <?php elseif (!$conBase): ?>
          <p style="font-size:13px;color:var(--lf-amb);margin:0">
            Esta venta no deja base comisionable: los gastos se comen la utilidad.</p>
        <?php else:
          /* Lo ya repartido de cada producto: las comisiones vivas de ese
             producto y las viejas que van sobre toda la venta. Entre todos no
             puede pasar de 100% (lo revisa AsignarComision al guardar); aquí
             se dice antes, para no enterarse con el error. */
          $idsLinea = array_map(function ($b) { return (int)$b['id']; }, $bases);
          $repartido = function ($detId) use ($comisiones, $idsLinea) {
              $s = 0.0;
              foreach ($comisiones as $c) {
                  $d = (int)($c['venta_detalle_id'] ?? 0);
                  if ($d === (int)$detId || !$d || !in_array($d, $idsLinea, true)) $s += (float)$c['pct'];
              }
              return round($s, 2);
          };
          $pctTxt = function ($x) { return rtrim(rtrim(number_format($x, 4, '.', ''), '0'), '.'); };
          $ya = $varios ? null : $repartido(reset($bases)['id']); ?>
        <?php if (!$varios && $ya >= 99.999): ?>
          <p style="font-size:13px;color:var(--lf-amb);margin:0">
            Las comisiones de esta venta ya suman el 100%: no queda nada por repartir.</p>
        <?php else: ?>
        <form method="post" action="/ventas/<?= (int)$v['id'] ?>/comision"
              class="lf-campos" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <?php if ($varios): ?>
          <div style="flex:1 1 100%">
            <label class="form-label">Producto</label>
            <select class="form-select" name="detalle" id="selDet" required>
              <option value="">Elegir…</option>
              <?php foreach ($bases as $b): $yaB = $repartido($b['id']); $lleno = $yaB >= 99.999; ?>
                <option value="<?= (int)$b['id'] ?>" data-base="<?= P::e(number_format($b['base'], 2, '.', '')) ?>"
                        data-libre="<?= P::e($pctTxt(max(0, 100 - $yaB))) ?>"
                        <?= $b['base'] > 0 && !$lleno ? '' : 'disabled' ?>>
                  <?= P::e($b['producto']) ?> · <?= $b['base'] > 0 ? 'base ' . D::pesos($b['base']) : 'sin base comisionable' ?><?=
                      $yaB > 0 ? ' · repartido ' . $pctTxt($yaB) . '%' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div style="flex:2;min-width:220px">
            <label class="form-label">Colaborador</label>
            <select class="form-select" name="colaborador" id="selColab" required>
              <option value="">Elegir…</option>
              <?php foreach ($catalogo as $area => $gente): ?>
                <optgroup label="<?= P::e($area) ?>">
                  <?php foreach ($gente as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"
                            data-pct="<?= isset($sugeridos[$g['id']]) ? $sugeridos[$g['id']] : '' ?>">
                      <?= P::e($g['nombre']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="width:118px">
            <label class="form-label">Porcentaje</label>
            <input class="form-control lf-mono" type="number" name="pct" id="inpPct"
                   step="0.0001" min="0.0001" max="<?= $varios ? 100 : P::e($pctTxt(100 - $ya)) ?>"
                   placeholder="0.000" required>
          </div>
          <div style="width:128px">
            <label class="form-label">Le tocarían</label>
            <input class="form-control lf-mono" id="prevCom" value="—" readonly
                   style="background:var(--lf-vidrio);border-style:dashed">
          </div>
          <button class="btn btn-primary" type="submit">Asignar</button>
        </form>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px">
          <?php if (!$varios && $ya > 0): ?>
            <b style="color:var(--lf-tinta-2)">Ya está repartido el <?= $pctTxt($ya) ?>%: quedan
              <?= $pctTxt(100 - $ya) ?>% por asignar.</b><br>
          <?php endif; ?>
          El porcentaje se sugiere solo con el que ese colaborador suele cobrar.
          Nadie puede tener dos comisiones <?= $varios ? 'en el mismo producto' : 'en la misma venta' ?>,
          y entre todas no pueden pasar del 100% de <?= $varios ? 'cada producto' : 'la venta' ?>.
        </p>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div>
    <section class="card">
      <header class="card-header">Concepto</header>
      <div style="padding:0 20px">
        <?php foreach ($lineas as $l): ?>
          <div style="display:flex;gap:10px;padding:11px 0;border-bottom:1px solid var(--lf-linea)">
            <div style="flex:1;min-width:0">
              <b style="display:block;font-size:13px;font-weight:600"><?= P::e($l['producto'] ?: 'Producto') ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11px">
                <?= (float)$l['cantidad'] ?> × <?= D::pesos($l['precio_unitario']) ?>
                <?= $l['codigo'] ? ' · ' . P::e($l['codigo']) : '' ?></small>
              <?php /* El descuento de la línea (promociones). El importe de la
                       derecha ya lo trae restado. */
              if ((float)($l['descuento'] ?? 0) > 0.004): ?>
                <span class="lf-promo-tag" style="display:flex;width:fit-content;margin-top:4px">
                  <?= P::e(($promosVenta[(int)$l['producto_id']] ?? '') ?: 'Descuento') ?> ·
                  −<?= D::pesos($l['descuento']) ?></span>
              <?php endif; ?>
            </div>
            <span class="lf-mono" style="font-weight:700"><?= D::pesos($l['subtotal']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="lf-sumas">
        <div><span>Subtotal</span><span class="lf-mono"><?= D::pesos($v['subtotal']) ?></span></div>
        <?php if ((float)$v['descuento'] > 0): ?>
          <div><span>Descuento</span><span class="lf-mono">−<?= D::pesos($v['descuento']) ?></span></div>
        <?php endif; ?>
        <div><span>IVA<?= (float)$v['iva'] > 0 ? ' (' . (($v['iva_modo'] ?? 'incluido') === 'sumar' ? 'sumado' : 'incluido') . ')' : '' ?></span>
          <span class="lf-mono"><?= D::pesos($v['iva']) ?></span></div>
        <div class="tt"><span>Total</span><span><?= D::pesos($total) ?></span></div>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Datos</header>
      <div class="card-body" style="font-size:13px">
        <?php
        $datos = [
          'Folio'     => $v['codigo_venta'],
          'Cliente'   => $v['cliente'] ?: 'Público general',
          'Teléfono'  => $v['telefono'] ?: null,
          'Vendedor'  => $v['vendedor'] ?: null,
          'Área'      => $v['area_nombre'] ?: 'Sin área',
          'Método'    => $v['metodo_pago'],
          'Fecha'     => date('d/m/Y H:i', strtotime($v['fecha'])),
        ];
        foreach ($datos as $k => $val): if ($val === null) continue; ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b style="font-weight:600;text-align:right"><?= P::e($val) ?></b>
          </div>
        <?php endforeach; ?>
        <?php if ($v['descripcion']): ?>
          <p style="margin-top:12px;padding-top:12px;border-top:1px solid var(--lf-linea);
                    font-size:12.5px;color:var(--lf-tinta-2)"><?= P::e($v['descripcion']) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($gastos): ?>
    <section class="card">
      <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <span>Gastos de operación</span>
        <span class="badge bg-warning"><?= D::pesos($v['gastos']) ?></span>
      </header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ($gastos as $g): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-2)"><?= P::e($g['concepto']) ?></span>
            <b class="lf-mono"><?= D::pesos($g['monto']) ?></b>
          </div>
        <?php endforeach; ?>
        <p style="margin-top:10px;padding-top:10px;border-top:1px solid var(--lf-linea);
                  font-size:11.5px;color:var(--lf-tinta-4)">
          Se restan de la utilidad antes de calcular comisiones. El primer pago los absorbe completos.</p>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($esAdmin): ?>
<form method="post" action="/ventas/<?= (int)$v['id'] ?>/quitar-comision" id="formQuitarCom" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="comision" id="comId">
</form>
<form method="post" action="/ventas/<?= (int)$v['id'] ?>/cancelar-pago" id="formCancelar" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="pago" id="cancPago">
  <input type="hidden" name="motivo" id="cancMotivo">
</form>
<script>
// Vista previa de cuánto le tocaría, mientras se escribe.
(function(){
  // La base del único producto; con varios, la del que se elija.
  var unaBase = <?= json_encode($bases ? (float)reset($bases)['base'] : 0) ?>;
  var sel = document.getElementById('selColab'),
      pct = document.getElementById('inpPct'),
      pre = document.getElementById('prevCom'),
      det = document.getElementById('selDet');
  if (!sel || !pct || !pre) return;
  function base(){
    if (!det) return unaBase;
    var o = det.options[det.selectedIndex];
    return o && o.dataset.base ? parseFloat(o.dataset.base) : NaN;
  }
  function pintar(){
    var p = parseFloat(pct.value), b = base();
    pre.value = isNaN(p) || p <= 0 || isNaN(b) ? '—'
      : '$' + (Math.round(b * p) / 100).toLocaleString('es-MX',
              {minimumFractionDigits:2, maximumFractionDigits:2});
  }
  // Con varios productos, el tope del porcentaje es lo que queda de ESE producto.
  if (det) det.addEventListener('change', function(){
    var o = det.options[det.selectedIndex];
    pct.max = o && o.dataset.libre ? o.dataset.libre : 100;
    pintar();
  });
  sel.addEventListener('change', function(){
    var o = sel.options[sel.selectedIndex];
    if (o && o.dataset.pct) pct.value = o.dataset.pct;
    pintar();
  });
  pct.addEventListener('input', pintar);
})();

document.querySelectorAll('.lf-quitar-com').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Quitar la comisión de ' + b.dataset.quien + '?\n\n'
               + 'Se cancela y lo devengado se recalcula.')) return;
    document.getElementById('comId').value = b.dataset.com;
    document.getElementById('formQuitarCom').submit();
  });
});

document.querySelectorAll('.lf-cancelar').forEach(function(b){
  b.addEventListener('click', function(){
    var m = prompt('¿Por qué se cancela el pago de ' + b.dataset.monto + '?\n\nEl motivo queda registrado.');
    if (!m || !m.trim()) return;
    document.getElementById('cancPago').value = b.dataset.pago;
    document.getElementById('cancMotivo').value = m.trim();
    document.getElementById('formCancelar').submit();
  });
});
</script>
<?php endif; ?>