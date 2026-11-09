<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];

$totalVenta    = (float) $venta['total'];
$metodoFactura = $venta['factura_metodo_pago'] ?? '';
$esPpd         = $metodoFactura === 'PPD';
$tieneFactura  = !empty($venta['factura_uuid']);
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Los complementos no tienen validez fiscal.</span>
</div>
<?php endif; ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header">
    <div><span>Factura origen</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Los complementos se ligan al folio fiscal de esta factura</p></div>
  </header>
  <div class="card-body">
    <div class="lf-row" style="display:flex;gap:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:220px">
        <small style="color:var(--lf-tinta-4);display:block;font-size:11px">Folio fiscal (UUID)</small>
        <span class="lf-mono" style="font-size:13px"><?= P::e($venta['factura_uuid'] ?: '—') ?></span>
      </div>
      <div style="flex:1;min-width:180px">
        <small style="color:var(--lf-tinta-4);display:block;font-size:11px">Método de pago</small>
        <span style="font-size:13px;font-weight:600">
          <?= P::e($metodoFactura ?: '—') ?>
          <?= $esPpd ? ' (parcialidades)' : ' (una sola exhibición)' ?>
        </span>
      </div>
      <div style="flex:1;min-width:120px">
        <small style="color:var(--lf-tinta-4);display:block;font-size:11px">Total facturado</small>
        <span class="lf-mono" style="font-size:13px;font-weight:600"><?= D::pesos($totalVenta) ?></span>
      </div>
      <div style="flex:0 0 auto;display:flex;gap:6px;align-items:flex-end">
        <a class="btn btn-secondary btn-sm"
           href="/facturacion/<?= (int)$venta['id'] ?>/descargar?tipo=pdf">PDF factura</a>
        <a class="btn btn-secondary btn-sm"
           href="/facturacion/<?= (int)$venta['id'] ?>/descargar?tipo=xml">XML factura</a>
      </div>
    </div>
  </div>
</section>

<?php if (!$esPpd): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Esta venta está facturada como <b>PUE</b>: no admite complementos de
    pago. Los complementos solo aplican a facturas con método <b>PPD</b>.</span>
</div>
<?php endif; ?>

<section class="card">
  <header class="card-header">
    <div><span>Abonos recibidos</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Un complemento por cada abono. Se numera como parcialidad en el orden
        real de captura.</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>#</th>
        <th>Fecha de pago</th>
        <th class="text-end">Monto</th>
        <th>Método</th>
        <th>Complemento</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if (!$pagos): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Esta venta todavía no tiene abonos registrados.</td></tr>
      <?php endif; ?>

      <?php foreach ($pagos as $p):
        $monto        = (float) $p['monto'];
        $pagadoAntes  = (float) $p['pagado_antes'];
        $saldoAntes   = max(0.0, round($totalVenta - $pagadoAntes, 2));
        $saldoDespues = max(0.0, round($saldoAntes - $monto, 2));
        $tieneComp    = !empty($p['cfdi_complemento_uuid']);
        $cancelado    = !empty($p['cancelado']);
        $puedeTimb    = $esPpd && $tieneFactura && !$tieneComp && !$cancelado;

        // `fecha_pago` es DATE (solo día). La hora real de captura está en
        // `creado_en`. Si por algún motivo viene vacío, se muestra solo el día.
        $fhReal     = (string) ($p['creado_en'] ?? '');
        $fhValida   = $fhReal !== '' && $fhReal !== '0000-00-00 00:00:00';
        $mismoDia   = $fhValida
            && substr($fhReal, 0, 10) === substr((string) $p['fecha_pago'], 0, 10);
      ?>
        <tr>
          <td data-label="#">
            <span class="lf-mono" style="font-weight:600">
              <?= (int)$p['parcialidad'] ?>
            </span>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($p['tipo']) ?>
            </span>
          </td>
          <td data-label="Fecha de pago">
            <?= date('d M Y', strtotime($p['fecha_pago'])) ?>
            <?php if ($fhValida && $mismoDia): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                <?= date('H:i', strtotime($fhReal)) ?>
              </span>
            <?php endif; ?>
            <?php if ($cancelado): ?>
              <span class="badge bg-danger" style="margin-top:2px">Cancelado</span>
            <?php endif; ?>
          </td>
          <td data-label="Monto" class="text-end lf-mono">
            <?= D::pesos($monto) ?>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
              base <?= D::pesos(round($monto / 1.16, 2)) ?>
            </span>
          </td>
          <td data-label="Método">
            <?= P::e($p['metodo_pago'] ?: '—') ?>
            <?php if (!empty($p['referencia'])): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                Ref. <?= P::e($p['referencia']) ?>
              </span>
            <?php endif; ?>
          </td>
          <td data-label="Complemento">
            <?php if ($cancelado && !$tieneComp): ?>
              <span class="badge bg-secondary">No aplica</span>
            <?php elseif ($tieneComp): ?>
              <span class="badge bg-info">Timbrado</span>
              <div style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:2px"
                   title="<?= P::e($p['cfdi_complemento_uuid']) ?>">
                <?= P::e(substr($p['cfdi_complemento_uuid'], 0, 8)) ?>…
              </div>
              <?php if (!empty($p['cfdi_complemento_en'])): ?>
                <div style="font-size:11px;color:var(--lf-tinta-4)">
                  <?= date('d M Y H:i', strtotime($p['cfdi_complemento_en'])) ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge bg-warning">Pendiente</span>
              <div style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:2px">
                Saldo antes: <?= D::pesos($saldoAntes) ?><br>
                Saldo después: <?= D::pesos($saldoDespues) ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
              <?php if ($tieneComp): ?>
                <a class="btn btn-secondary btn-sm"
                   href="/facturacion/complemento/<?= (int)$p['id'] ?>/descargar?tipo=pdf">PDF</a>
                <a class="btn btn-secondary btn-sm"
                   href="/facturacion/complemento/<?= (int)$p['id'] ?>/descargar?tipo=xml">XML</a>
              <?php elseif ($puedeTimb): ?>
                <form method="post"
                      action="/facturacion/<?= (int)$venta['id'] ?>/complemento/<?= (int)$p['id'] ?>"
                      onsubmit="return confirm('Se emitirá el complemento de pago (CFDI tipo P) por <?= D::pesos($monto) ?>. ¿Continuar?')">
                  <input type="hidden" name="lf_token" value="<?= P::e($token) ?>">
                  <button type="submit" class="btn btn-primary btn-sm">
                    Emitir complemento
                  </button>
                </form>
              <?php elseif (!$esPpd): ?>
                <small style="color:var(--lf-tinta-4);font-size:11.5px">
                  Factura PUE: no aplica
                </small>
              <?php elseif ($cancelado): ?>
                <small style="color:var(--lf-tinta-4);font-size:11.5px">
                  Pago cancelado
                </small>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="border-top:none;padding-top:0;font-size:12px;color:var(--lf-tinta-4)">
    El complemento de pago (CFDI tipo P) no lleva conceptos: el importe va
    dentro del bloque de pago, amarrado al UUID de la factura. El SAT exige
    explícitos el monto, el saldo anterior y el saldo posterior de cada
    parcialidad.
  </div>
</section>

<div style="margin-top:14px">
  <a class="btn btn-secondary btn-sm" href="/facturacion">← Volver a facturación</a>
  <a class="btn btn-secondary btn-sm" href="/ventas/<?= (int)$venta['id'] ?>">Ver venta</a>
</div>