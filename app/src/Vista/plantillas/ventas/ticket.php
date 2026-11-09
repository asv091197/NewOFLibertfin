<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Dominio\Impuesto;
use LibertyFin\Dominio\Iva;

$v = $venta;

/* Dos tickets en un archivo: el de siempre y el de la factura.
   Comparten el membrete, los renglones, los totales y los 80 mm de
   ancho — duplicar todo eso para agregar seis datos fiscales habría
   dejado dos tickets que se separan con el tiempo. */
$fiscal = !empty($fiscal);
$regla  = $regla ?? new Iva(0, Iva::INCLUIDO);

/* Los impuestos de cada renglón. Se calculan aquí y no en la plantilla
   para poder sumarlos: el pie necesita el total de IEPS, que no sale de
   ninguna columna de la venta. */
$impPorLinea = [];
$sumaIeps = 0.0;
$hayIeps  = false;
foreach ($lineas as $l) {
    $imp = Impuesto::deProducto($l, $regla);
    $base = (float)$l['subtotal'] - (float)($l['descuento'] ?? 0);
    $r = $imp->repartir(max(0, $base));
    $impPorLinea[(int)$l['id']] = ['imp' => $imp, 'r' => $r];
    $sumaIeps += $r['ieps'];
    if ($imp->llevaIeps()) $hayIeps = true;
}
$sumaIeps = round($sumaIeps, 2);
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='DM Sans,system-ui,sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<meta name="theme-color" content="#27ae60">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
<title><?= $fiscal ? 'Factura' : 'Ticket' ?> <?= P::e($v['codigo_venta']) ?></title>
<style>
/* El ticket trae su propio CSS: es la única pantalla que se imprime,
   y cargar la hoja completa solo para tacharla al imprimir no tiene
   sentido. Ancho de 80 mm, el de las impresoras térmicas. */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Google Sans',system-ui,sans-serif;background:#f1f4f2;
     color:#1b2420;padding:20px;display:flex;flex-direction:column;align-items:center;gap:14px}
.t{width:80mm;max-width:100%;background:#fff;padding:16px 18px;
   border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);font-size:12px;line-height:1.45}
.mrc{text-align:center;padding-bottom:12px;border-bottom:1px dashed #cfd8d3}
.mrc b{font-size:15px;letter-spacing:-.3px;display:block}
.mrc small{font-size:10.5px;color:#6d7a74;display:block;margin-top:2px}
.fol{text-align:center;padding:11px 0;border-bottom:1px dashed #cfd8d3}
.fol b{font-family:ui-monospace,monospace;font-size:13px;letter-spacing:.5px}
.fol small{display:block;font-size:10.5px;color:#6d7a74;margin-top:2px}
.dat{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.dat div{display:flex;justify-content:space-between;gap:10px;padding:2px 0}
.dat span{color:#6d7a74} .dat b{font-weight:600;text-align:right}
.it{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.it .l{display:flex;justify-content:space-between;gap:10px;padding:4px 0}
.it .l b{font-weight:600} .it .l small{display:block;color:#6d7a74;font-size:10.5px}
.it .m{font-family:ui-monospace,monospace;font-weight:600;white-space:nowrap}
.sum{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.sum div{display:flex;justify-content:space-between;gap:10px;padding:2px 0}
.sum .tt{font-size:15px;font-weight:800;padding-top:8px;margin-top:6px;border-top:1px solid #1b2420}
.sum .m{font-family:ui-monospace,monospace}
.sal{margin-top:10px;padding:9px 11px;border-radius:7px;background:#fbf3e0;color:#c58a14;
     font-size:11.5px;text-align:center}
/* El bloque fiscal: letra un punto menor, que son seis renglones más
   en un papel de 80 mm. */
.dat.fis{font-size:11px}
.dat.fis b{font-family:ui-monospace,monospace;font-weight:600;word-break:break-word}
.uuid{padding:9px 0;border-bottom:1px dashed #cfd8d3;text-align:center}
.uuid small{display:block;color:#6d7a74;font-size:10px;margin-bottom:2px}
.uuid b{font-family:ui-monospace,monospace;font-size:10.5px;letter-spacing:.4px;
        word-break:break-all;line-height:1.5}
.pie{text-align:center;padding-top:12px;font-size:10.5px;color:#6d7a74;line-height:1.6}
.acc{display:flex;gap:9px}
.acc button,.acc a{padding:9px 18px;border-radius:99px;border:1px solid #e6ebe8;background:#fff;
  color:#43504a;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}
.acc button{background:#27ae60;border-color:#27ae60;color:#fff}
@media print{
  body{background:#fff;padding:0;display:block}
  .t{width:auto;box-shadow:none;border-radius:0;padding:0}
  .acc{display:none}
  @page{margin:6mm}
}
</style>
</head>
<body>
<div class="t">
  <div class="mrc">
    <b><?= P::e($empresa['nombre_empresa'] ?? 'LibertyFin') ?></b>
    <?php if (!empty($empresa['rfc'])): ?><small>RFC <?= P::e($empresa['rfc']) ?></small><?php endif; ?>
    <?php if (!empty($empresa['direccion'])): ?><small><?= P::e($empresa['direccion']) ?></small><?php endif; ?>
    <?php if (!empty($empresa['telefono'])): ?><small>Tel. <?= P::e($empresa['telefono']) ?></small><?php endif; ?>
  </div>

  <div class="fol">
    <?php if ($fiscal): ?>
      <small style="margin:0 0 4px;font-weight:700;letter-spacing:.8px;text-transform:uppercase">
        Comprobante de factura</small>
    <?php endif; ?>
    <b><?= P::e($v['codigo_venta']) ?></b>
    <small><?= date('d/m/Y · H:i', strtotime($v['fecha'])) ?></small>
  </div>

  <?php if ($fiscal): /* ── Los datos del CFDI ── */ ?>
  <div class="dat fis">
    <?php if (!empty($v['factura_folio'])): ?>
      <div><span>Folio</span><b><?= P::e($v['factura_folio']) ?></b></div><?php endif; ?>
    <div><span>RFC receptor</span><b><?= P::e($v['factura_rfc_receptor'] ?: '—') ?></b></div>
    <?php if (!empty($v['factura_razon_social'])): ?>
      <div><span>Razón social</span><b><?= P::e($v['factura_razon_social']) ?></b></div><?php endif; ?>
    <?php if (!empty($v['factura_uso_cfdi'])): ?>
      <div><span>Uso del CFDI</span><b><?= P::e($v['factura_uso_cfdi']) ?></b></div><?php endif; ?>
    <?php if (!empty($v['factura_metodo_pago'])): ?>
      <div><span>Método</span><b><?= P::e($v['factura_metodo_pago']) ?></b></div><?php endif; ?>
    <?php if (!empty($v['factura_forma_pago'])): ?>
      <div><span>Forma</span><b><?= P::e($v['factura_forma_pago']) ?></b></div><?php endif; ?>
    <?php if (!empty($v['factura_fecha_timbrado'])): ?>
      <div><span>Timbrado</span><b><?= date('d/m/Y H:i', strtotime($v['factura_fecha_timbrado'])) ?></b></div><?php endif; ?>
  </div>
  <?php if (!empty($v['factura_uuid'])): ?>
    <?php /* El UUID va en dos renglones y en monoespaciado: son 36
             caracteres y en 80 mm, en una sola línea, se parten donde
             caiga y quedan ilegibles justo cuando alguien los teclea. */ ?>
    <div class="uuid">
      <small>Folio fiscal (UUID)</small>
      <b><?= P::e($v['factura_uuid']) ?></b>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="dat">
    <div><span>Cliente</span><b><?= P::e($v['cliente'] ?: 'Público general') ?></b></div>
    <?php if ($v['vendedor']): ?>
      <div><span>Atendió</span><b><?= P::e($v['vendedor']) ?></b></div><?php endif; ?>
    <div><span>Pago</span><b><?= P::e(ucfirst($v['metodo_pago'])) ?></b></div>
  </div>

  <div class="it">
    <?php foreach ($lineas as $l):
      $x = $impPorLinea[(int)$l['id']] ?? null; ?>
      <div class="l">
        <span><b><?= P::e($l['producto'] ?: 'Producto') ?></b>
          <small><?= (float)$l['cantidad'] ?> × <?= D::pesos($l['precio_unitario']) ?></small>
          <?php if ((float)($l['descuento'] ?? 0) > 0.004): ?>
            <small><?= P::e(($promos[(int)$l['producto_id']] ?? '') ?: 'Descuento') ?>
              −<?= D::pesos($l['descuento']) ?></small>
          <?php endif; ?>
          <?php /* El impuesto por renglón solo en el de factura, y solo
                   cuando dice algo: ponerle «IVA 16 %» a los doce
                   renglones de un ticket de tienda es ruido en papel que
                   se paga por metro. En el fiscal sí va siempre: es lo
                   que el cliente revisa. */
          // En el renglón va la TASA, no el importe: el importe ya está
          // sumado abajo, y repetirlo en un papel de 80 mm gasta línea
          // para decir dos veces lo mismo.
          if ($fiscal && $x): ?>
            <small><?= P::e($x['imp']->rotulo()) ?></small>
          <?php elseif ($x && $x['imp']->llevaIeps()): ?>
            <small>incluye IEPS <?= D::pesos($x['r']['ieps']) ?></small>
          <?php endif; ?></span>
        <span class="m"><?= D::pesos($l['subtotal']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="sum">
    <div><span>Subtotal</span><span class="m"><?= D::pesos($v['subtotal']) ?></span></div>
    <?php if ((float)$v['descuento'] > 0): ?>
      <div><span>Descuento</span><span class="m">−<?= D::pesos($v['descuento']) ?></span></div><?php endif; ?>
    <?php if ($hayIeps && $sumaIeps > 0.004): ?>
      <div><span>IEPS</span><span class="m"><?= D::pesos($sumaIeps) ?></span></div><?php endif; ?>
    <?php /* En el fiscal manda lo TIMBRADO, no lo que se recalcule hoy:
             un CFDI de 2024 con IVA del 16 % sigue diciendo eso aunque
             el producto haya cambiado de tasa desde entonces. */
    $ivaMostrar = ($fiscal && $v['factura_iva'] !== null) ? (float)$v['factura_iva'] : (float)$v['iva'];
    if ($ivaMostrar > 0): ?>
      <div><span>IVA</span><span class="m"><?= D::pesos($ivaMostrar) ?></span></div><?php endif; ?>
    <div class="tt"><span>Total</span><span class="m"><?= D::pesos(
        ($fiscal && $v['factura_total'] !== null) ? (float)$v['factura_total'] : (float)$v['total']) ?></span></div>
    <div style="margin-top:6px"><span>Pagado</span><span class="m"><?= D::pesos($v['cobrado']) ?></span></div>
  </div>

  <?php if ((float)$v['saldo'] > 0.01): ?>
    <div class="sal">Queda un saldo de <b><?= D::pesos($v['saldo']) ?></b></div>
  <?php endif; ?>

  <div class="pie">
    <?php if ((float)$v['saldo'] > 0.01): ?>
      Este comprobante ampara un pago parcial.<br>
    <?php endif; ?>
    <?php if ($fiscal): ?>
      <?php /* Decirlo es lo que impide que este papel se use como si
               fuera la factura. Un comprobante que se le parece y no lo
               es, es justo lo que después nadie puede deducir. */ ?>
      <b>Este papel no es el comprobante fiscal.</b><br>
      El CFDI es el archivo XML timbrado; se envía
      <?= !empty($v['factura_email_receptor'])
          ? 'a ' . P::e($v['factura_email_receptor'])
          : 'al correo registrado' ?>.<br>
      Verifícalo en verificacfdi.facturaelectronica.sat.gob.mx<br><br>
    <?php endif; ?>
    Gracias por su preferencia.
  </div>
</div>

<div class="acc">
  <button type="button" onclick="window.print()">Imprimir</button>
  <?php if (!$fiscal && !empty($v['factura_uuid'])): ?>
    <a href="/ventas/<?= (int)$v['id'] ?>/ticket-factura">Ticket de la factura</a>
  <?php elseif ($fiscal): ?>
    <a href="/ventas/<?= (int)$v['id'] ?>/ticket">Ticket normal</a>
  <?php endif; ?>
  <a href="/ventas/<?= (int)$v['id'] ?>">Volver</a>
</div>

<script>
// Si llega con ?auto=1 desde el cobro, se manda a imprimir solo.
if (new URLSearchParams(location.search).get('auto') === '1') {
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });
}
</script>
</body>
</html>