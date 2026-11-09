<?php
/**
 * El comprobante para pagar en tienda.
 *
 * Esto es lo que el cliente se lleva. Tiene que servir impreso en blanco
 * y negro, leído en un teléfono, y entendido por un cajero de OXXO que
 * nunca ha visto tu sistema.
 *
 * Por eso lleva las tres cosas: el código de barras para escanear, la
 * referencia escrita grande por si el escáner falla, y las
 * instrucciones de qué decir en el mostrador.
 */
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Barras;
use LibertyFin\Dominio\Dinero as D;

$vence = $l['vence'] ? strtotime($l['vence']) : null;
?>
<div class="lf-comp">
  <div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="/ligas">Volver</a>
  </div>

  <article class="hoja">
    <header>
      <div>
        <h1>Ficha de pago</h1>
        <p class="neg"><?= P::e($empresa) ?></p>
      </div>
      <div class="monto">
        <span>Total a pagar</span>
        <b><?= D::pesos($l['monto']) ?></b>
      </div>
    </header>

    <?php if ($l['pruebas']): ?>
      <p class="prueba">FICHA DE PRUEBA · no sirve para pagar</p>
    <?php endif; ?>

    <table class="datos">
      <tr><th>Concepto</th><td><?= P::e($l['descripcion'] ?: 'Pago de servicios') ?></td></tr>
      <?php if ($l['cliente_nombre']): ?>
        <tr><th>Cliente</th><td><?= P::e($l['cliente_nombre']) ?></td></tr>
      <?php endif; ?>
      <tr><th>Emitida</th><td><?= date('d/m/Y', strtotime($l['creado_en'])) ?></td></tr>
      <?php if ($vence): ?>
        <tr><th>Vence</th>
          <td class="vence"><?= strftime_es($vence) ?>
            <?php $dias = (int)floor(($vence - strtotime('today')) / 86400); ?>
            <?php if ($dias >= 0): ?>
              <small>(<?= $dias === 0 ? 'hoy' : 'en ' . $dias . ' día' . ($dias==1?'':'s') ?>)</small>
            <?php else: ?>
              <small class="rojo">(venció)</small>
            <?php endif; ?>
          </td></tr>
      <?php endif; ?>
    </table>

    <?php if ($l['barras']): ?>
      <section class="pago">
        <h2>1 · Paga en efectivo en una tienda</h2>
        <div class="codigo">
          <?= Barras::svg($l['barras'], 70) ?>
        </div>
        <p class="ref">Referencia: <b><?= P::e($l['barras']) ?></b></p>
        <ol class="pasos">
          <li>Ve a cualquiera de las tiendas de abajo.</li>
          <li>Dile al cajero que vas a hacer un <b>pago de servicios</b>.
            Si te pregunta la empresa, es <b><?= P::e($convenio) ?></b>.</li>
          <li>Muéstrale el código de barras. Si no lo puede escanear, dicta
            la referencia.</li>
          <li>Paga <b><?= D::pesos($l['monto']) ?></b> exactos y
            <b>guarda tu ticket</b>: es tu comprobante.</li>
        </ol>
        <div class="tiendas">
          <span>Tiendas participantes</span>
          <div>
            <?php foreach ($tiendas as $t): ?><em><?= P::e($t) ?></em><?php endforeach; ?>
          </div>
        </div>
        <p class="aviso">
          La tienda puede cobrarte una comisión por el servicio. El pago se
          registra el mismo día; si pagas después de las 10 de la noche puede
          aparecer al día siguiente.
        </p>
      </section>
    <?php endif; ?>

    <?php if ($l['clabe']): ?>
      <section class="pago">
        <h2><?= $l['barras'] ? '2' : '1' ?> · O transfiere desde tu banco</h2>
        <p class="clabe"><?= P::e($l['clabe']) ?></p>
        <ol class="pasos">
          <li>Entra a tu banca en línea y da de alta esta CLABE.</li>
          <li>Transfiere <b><?= D::pesos($l['monto']) ?></b> exactos.</li>
          <li>Se acredita en unos minutos. No hace falta avisarnos.</li>
        </ol>
        <p class="aviso">
          <b>Transfiere la cantidad exacta.</b> Un monto distinto no se asocia solo
          y hay que buscarlo a mano.
        </p>
      </section>
    <?php endif; ?>

    <?php if ($l['liga']): ?>
      <section class="pago">
        <h2><?= ($l['barras'] ? 1 : 0) + ($l['clabe'] ? 1 : 0) + 1 ?> · O paga con tarjeta</h2>
        <div class="qr-y-liga">
          <?php $qr = \LibertyFin\Vista\Qr::svg($l['liga'], 150); ?>
          <?php if ($qr): ?><div class="qr"><?= $qr ?></div><?php endif; ?>
          <div>
            <p class="pasos-corto">Escanea el código con la cámara de tu teléfono,
              o escribe esta dirección:</p>
            <p class="url"><?= P::e($l['liga']) ?></p>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <footer>
      <p>¿Dudas? Comunícate con <b><?= P::e($empresa) ?></b>.
        Conserva esta ficha hasta que el pago aparezca aplicado.</p>
      <p class="folio">Referencia interna <?= P::e($l['referencia']) ?></p>
    </footer>
  </article>
</div>

<?php
function strftime_es($t) {
    $m = ['enero','febrero','marzo','abril','mayo','junio','julio',
          'agosto','septiembre','octubre','noviembre','diciembre'];
    return date('j', $t) . ' de ' . $m[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}
?>

<style>
body{background:#eef1ef;margin:0;color:#1b2420;
  font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.lf-comp{max-width:760px;margin:0 auto;padding:22px 16px 60px}
.barra{display:flex;gap:12px;align-items:center;margin-bottom:18px}
.barra button{padding:11px 24px;border:none;border-radius:99px;background:#27ae60;color:#fff;
  font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}
.barra button:hover{background:#1f8b4d}
.barra a{font-size:13px;color:#43504a}

.hoja{background:#fff;border-radius:12px;padding:34px 36px 26px;
  box-shadow:0 2px 10px rgba(0,0,0,.07)}
.hoja header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
  padding-bottom:16px;margin-bottom:18px;border-bottom:3px solid #27ae60;flex-wrap:wrap}
.hoja h1{font-size:22px;margin:0 0 3px;letter-spacing:-.4px}
.neg{font-size:13px;color:#43504a;margin:0;font-weight:600}
.monto{text-align:right}
.monto span{display:block;font-size:11px;color:#6c7771;text-transform:uppercase;
  letter-spacing:.6px;margin-bottom:2px}
.monto b{font-size:26px;font-weight:800;color:#1f8b4d;letter-spacing:-.8px}

.prueba{background:#fbf3e0;color:#8a6410;padding:9px 14px;border-radius:8px;
  font-size:12px;font-weight:700;text-align:center;margin:0 0 18px;letter-spacing:.5px}

.datos{width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13px}
.datos th{text-align:left;padding:6px 14px 6px 0;color:#6c7771;font-weight:500;
  width:110px;vertical-align:top}
.datos td{padding:6px 0;font-weight:600}
.vence small{font-weight:400;color:#6c7771}
.vence .rojo{color:#c0392b;font-weight:600}

.pago{border:1px solid #e6ebe8;border-radius:10px;padding:20px 22px;margin-bottom:16px}
.pago h2{font-size:14px;margin:0 0 14px;color:#1f8b4d;letter-spacing:-.2px}
.codigo{text-align:center;padding:4px 0 10px}
.codigo svg{max-width:100%;height:auto}
.ref{text-align:center;font-size:13px;margin:0 0 16px;color:#43504a}
.ref b{font-family:ui-monospace,monospace;font-size:16px;letter-spacing:2px;color:#1b2420}
.clabe{text-align:center;font-family:ui-monospace,monospace;font-size:22px;font-weight:700;
  letter-spacing:3px;background:#f6f8f7;padding:15px;border-radius:8px;margin:0 0 16px}

.pasos{margin:0 0 14px;padding-left:20px;font-size:13px;line-height:1.75;color:#43504a}
.pasos li{margin-bottom:3px}
.pasos-corto{font-size:13px;color:#43504a;line-height:1.6;margin:0 0 8px}
.url{font-family:ui-monospace,monospace;font-size:11.5px;word-break:break-all;
  background:#f6f8f7;padding:9px 11px;border-radius:7px;margin:0}

.qr-y-liga{display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.qr-y-liga .qr{flex-shrink:0}
.qr-y-liga .qr svg{border-radius:8px}
.qr-y-liga > div:last-child{flex:1;min-width:200px}

.tiendas{background:#f6f8f7;border-radius:8px;padding:13px 15px;margin-bottom:12px}
.tiendas > span{display:block;font-size:10.5px;color:#6c7771;text-transform:uppercase;
  letter-spacing:.6px;margin-bottom:8px;font-weight:600}
.tiendas div{display:flex;flex-wrap:wrap;gap:6px}
.tiendas em{font-style:normal;font-size:11.5px;font-weight:600;background:#fff;
  border:1px solid #e6ebe8;border-radius:99px;padding:4px 11px;color:#43504a}

.aviso{font-size:11.5px;color:#6c7771;line-height:1.55;margin:0}

.hoja footer{margin-top:20px;padding-top:16px;border-top:1px solid #e6ebe8}
.hoja footer p{font-size:11.5px;color:#6c7771;line-height:1.55;margin:0 0 4px}
.folio{font-family:ui-monospace,monospace;font-size:10.5px;color:#9aa8a2}

@media print{
  @page{size:A4;margin:12mm}
  body{background:#fff}
  .lf-comp{max-width:none;padding:0}
  .barra{display:none}
  .hoja{box-shadow:none;border-radius:0;padding:0}
  .pago{break-inside:avoid;page-break-inside:avoid}
  .monto b,.pago h2{color:#1f8b4d !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .hoja header{border-bottom-color:#27ae60 !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .clabe,.tiendas,.url{background:#f6f8f7 !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
