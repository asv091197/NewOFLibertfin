<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Dinero as D;
$c = $cot;
$nombreEmpresa = $empresa['nombre_empresa'] ?? ($_SESSION['empresa_nombre'] ?? 'LibertyFin');
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#27ae60">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
<title>Cotización <?= P::e($c['folio']) ?> · <?= P::e($c['cliente_nombre']) ?></title>
<style>
/* Hoja carta suelta: se imprime o se guarda como PDF desde el navegador. */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Google Sans',system-ui,sans-serif;background:#eef2ef;color:#1b2420;padding:24px 16px;
     display:flex;flex-direction:column;align-items:center;gap:14px}
.h{width:216mm;max-width:100%;min-height:250mm;background:#fff;padding:18mm 16mm;border-radius:10px;
   box-shadow:0 2px 14px rgba(0,0,0,.08);font-size:13px;line-height:1.5;display:flex;flex-direction:column}
.cab{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;padding-bottom:16px;border-bottom:2px solid #1b2420}
.cab img{max-height:56px;max-width:180px;object-fit:contain;display:block;margin-bottom:8px}
.cab .emp b{font-size:18px;letter-spacing:-.3px;display:block}
.cab .emp small{color:#6d7a74;display:block}
.cab .doc{text-align:right}
.cab .doc b{font-size:22px;letter-spacing:-.5px;display:block;color:#27ae60}
.cab .doc span{font-family:ui-monospace,monospace;font-size:13px}
.par{display:flex;gap:20px;margin:18px 0}
.par > div{flex:1;background:#f6f8f7;border-radius:8px;padding:11px 14px}
.par small{display:block;text-transform:uppercase;letter-spacing:.6px;font-size:10px;color:#6d7a74;margin-bottom:3px}
.par b{font-size:14px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:#6d7a74;padding:8px 6px;border-bottom:1px solid #cfd8d3}
td{padding:9px 6px;border-bottom:1px solid #eef2ef;vertical-align:top}
.n{text-align:right;font-family:ui-monospace,monospace;white-space:nowrap}
.tot td{border:none;padding-top:14px;font-size:16px;font-weight:800}
.notas{margin-top:22px;padding:12px 14px;border-left:3px solid #27ae60;background:#f6f8f7;border-radius:0 8px 8px 0;white-space:pre-line}
.pie{margin-top:auto;padding-top:24px;font-size:11px;color:#6d7a74;text-align:center}
.acc{display:flex;gap:9px}
.acc button,.acc a{padding:9px 18px;border-radius:99px;border:1px solid #e6ebe8;background:#fff;color:#43504a;
  font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}
.acc button{background:#27ae60;border-color:#27ae60;color:#fff}
@media print{
  body{background:#fff;padding:0;display:block}
  .h{width:auto;min-height:0;box-shadow:none;border-radius:0;padding:0}
  .acc{display:none}
  @page{size:letter;margin:14mm}
}
</style>
</head>
<body>
<div class="acc">
  <button type="button" onclick="window.print()">Imprimir o guardar PDF</button>
  <a href="/cotizaciones/<?= (int)$c['id'] ?>">Volver</a>
</div>
<div class="h">
  <div class="cab">
    <div class="emp">
      <?php if ($logo !== '' && preg_match('#^/[\w/.\-]+$#', $logo)): ?><img src="<?= P::e($logo) ?>" alt=""><?php endif; ?>
      <b><?= P::e($nombreEmpresa) ?></b>
      <?php if (!empty($empresa['telefono'])): ?><small>Tel. <?= P::e($empresa['telefono']) ?></small><?php endif; ?>
      <?php if (!empty($empresa['email'])): ?><small><?= P::e($empresa['email']) ?></small><?php endif; ?>
    </div>
    <div class="doc">
      <b>Cotización</b>
      <span><?= P::e($c['folio']) ?></span>
    </div>
  </div>
  <div class="par">
    <div><small>Para</small><b><?= P::e($c['cliente_nombre']) ?></b>
      <?php if (!empty($cliente['rfc'])): ?><br><span>RFC <?= P::e($cliente['rfc']) ?></span><?php endif; ?>
      <?php if (!empty($cliente['telefono'])): ?><br><span><?= P::e($cliente['telefono']) ?></span><?php endif; ?></div>
    <div><small>Fecha</small><b><?= P::e(date('d/m/Y', strtotime($c['creado_en']))) ?></b></div>
    <div><small>Vigente hasta</small><b><?= $c['vigencia'] ? P::e(date('d/m/Y', strtotime($c['vigencia']))) : '—' ?></b></div>
  </div>
  <table>
    <thead><tr><th>Concepto</th><th class="n">Cantidad</th><th class="n">Precio c/u</th><th class="n">Importe</th></tr></thead>
    <tbody>
    <?php foreach ($c['lineas'] as $l): ?>
      <tr><td><?= P::e($l['nombre']) ?></td>
        <td class="n"><?= P::e(\LibertyFin\Servicio\Inventario::num($l['cantidad'])) ?></td>
        <td class="n"><?= D::pesos($l['precio']) ?></td>
        <td class="n"><?= D::pesos($l['cantidad'] * $l['precio']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="tot"><td colspan="3" style="text-align:right">Total</td><td class="n"><?= D::pesos($c['total']) ?></td></tr>
    </tbody>
  </table>
  <?php if ($c['notas']): ?><div class="notas"><?= P::e($c['notas']) ?></div><?php endif; ?>
  <div class="pie">Precios en pesos mexicanos. Esta cotización no es un comprobante fiscal.</div>
</div>
</body>
</html>
