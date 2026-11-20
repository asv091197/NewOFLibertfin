<?php
use LibertyFin\Vista\Plantilla as P;

/* La hoja de Inventario para imprimir o guardar en PDF.

   Mismo diseño que la de Reportes (reportes/imprimir.php) para que todos
   los papeles del sistema se parezcan. Cada hoja llega ya armada del
   controlador:

     titulo, sub         encabezado
     datos               [[rótulo, valor], …] — los cuadros de arriba
     columnas            [[nombre, 't'|'n'], …] — 'n' alinea a la derecha
     filas               [['celdas' => […], 'marca' => 'mal'|'ojo'|'mas'|'menos'|'renglon'|''], …]
     totales             la fila de abajo (opcional)
     nota                el texto al pie (opcional)
     firmas              ['Entregó', 'Recibió'] (opcional) */
?>
<div class="lf-hoja">
  <div class="barra">
    <button type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    <a href="<?= P::e($volver ?? '/inventario') ?>">Volver</a>
    <span class="ayuda">En el cuadro de imprimir elige «Guardar como PDF» para descargarlo.</span>
  </div>

  <?php foreach ($hojas as $h): $cols = $h['columnas'] ?? []; ?>
  <section class="rep<?= count($cols) >= 10 ? ' ancha' : (count($cols) >= 8 ? ' media' : '') ?>">
    <header>
      <div>
        <h1><?= P::e($h['titulo']) ?></h1>
        <p class="per"><?= P::e($empresa) ?><?= !empty($h['sub']) ? ' · ' . P::e($h['sub']) : '' ?></p>
      </div>
      <div class="marca"><span>L</span>LibertyFin</div>
    </header>

    <?php if (!empty($h['datos'])): ?>
      <div class="partes">
        <?php foreach ($h['datos'] as $d): ?>
          <div><small><?= P::e($d[0]) ?></small><b><?= P::e($d[1]) ?></b></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($cols): ?>
    <table>
      <thead><tr>
        <?php foreach ($cols as $c): ?><th<?= ($c[1] ?? 't') === 'n' ? ' class="n"' : '' ?>><?= P::e($c[0]) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php if (empty($h['filas'])): ?>
          <tr><td colspan="<?= count($cols) ?>" class="vacio">No hay renglones con estos filtros.</td></tr>
        <?php endif; ?>
        <?php foreach ($h['filas'] as $f): ?>
          <tr<?= !empty($f['marca']) ? ' class="' . P::e($f['marca']) . '"' : '' ?>>
            <?php foreach ($f['celdas'] as $i => $v): ?>
              <td<?= ($cols[$i][1] ?? 't') === 'n' ? ' class="n"' : '' ?>><?= P::e($v) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if (!empty($h['totales'])): ?>
      <tfoot><tr>
        <?php foreach ($h['totales'] as $i => $v): ?>
          <td<?= ($cols[$i][1] ?? 't') === 'n' ? ' class="n"' : '' ?>><?= P::e($v) ?></td>
        <?php endforeach; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($h['nota'])): ?><p class="nota"><?= P::e($h['nota']) ?></p><?php endif; ?>

    <?php if (!empty($h['firmas'])): ?>
      <div class="firmas">
        <?php foreach ($h['firmas'] as $fi): ?><div><span></span><?= P::e($fi) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="pie">Impreso el <?= date('d/m/Y H:i') ?><?= !empty($_SESSION['usuario_nombre']) ? ' por ' . P::e($_SESSION['usuario_nombre']) : '' ?>.</p>
  </section>
  <?php endforeach; ?>
</div>

<style>
/* ── En pantalla ── (el mismo papel que reportes/imprimir.php) */
body{background:#eef1ef;margin:0;
  font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1b2420}
.lf-hoja{max-width:1100px;margin:0 auto;padding:22px 16px 60px}
.barra{display:flex;gap:12px;align-items:center;margin-bottom:18px;flex-wrap:wrap}
.barra button{padding:11px 22px;border:none;border-radius:99px;background:#27ae60;color:#fff;
  font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}
.barra button:hover{background:#1f8b4d}
.barra a{font-size:13px;color:#43504a}
.barra .ayuda{font-size:12px;color:#6d7a74}

.rep{background:#fff;border-radius:12px;padding:30px 32px;margin-bottom:22px;
  box-shadow:0 2px 10px rgba(0,0,0,.07);overflow-x:auto}
.rep header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
  padding-bottom:16px;margin-bottom:18px;border-bottom:2px solid #27ae60}
.rep h1{font-size:19px;margin:0 0 4px;letter-spacing:-.3px}
.per{font-size:12.5px;color:#6d7a74;margin:0}
.marca{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;flex-shrink:0}
.marca span{width:26px;height:26px;border-radius:8px;background:#27ae60;color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:14px}

.partes{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.partes > div{flex:1;min-width:140px;padding:9px 12px;border:1px solid #e6ebe8;
  border-radius:8px;background:#f8faf9}
.partes small{display:block;font-size:10.5px;color:#6d7a74}
.partes b{display:block;font-size:14px;margin-top:2px;font-variant-numeric:tabular-nums}

table{width:100%;border-collapse:collapse;font-size:12px;min-width:max-content}
th{background:#27ae60;color:#fff;text-align:left;padding:9px 10px;font-weight:600;
  font-size:11.5px;white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid #e6ebe8}
th.n,td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tbody tr:nth-child(even){background:#f8faf9}
tbody tr.mal td:first-child{box-shadow:inset 3px 0 0 #cf4436}
tbody tr.ojo td:first-child{box-shadow:inset 3px 0 0 #c58a14}
tbody tr.menos td.n{color:#b23a2e}
tbody tr.mas td.n{color:#1f8b4d}
/* La hoja para contar: renglones altos para escribir a mano. */
tbody tr.renglon td{padding-top:12px;padding-bottom:12px}
tbody tr.renglon td:nth-last-child(-n+2){min-width:90px;border-bottom:1px solid #9aa8a2}
tfoot td{background:#eff5f1;font-weight:700;border-top:2px solid #27ae60;border-bottom:none}
.vacio{text-align:center;color:#6d7a74;padding:26px}
.nota{font-size:11px;color:#6d7a74;line-height:1.55;margin:14px 0 0;
  padding-top:12px;border-top:1px solid #e6ebe8}
.firmas{display:flex;gap:40px;margin-top:46px}
.firmas div{flex:1;text-align:center;font-size:11.5px;color:#43504a}
.firmas span{display:block;border-top:1px solid #1b2420;margin-bottom:6px}
.pie{font-size:10.5px;color:#9aa8a2;margin:10px 0 0}

/* ── Al imprimir ── encabezado de la tabla en cada página, sin botones,
   y las tablas anchas se encogen en vez de cortarse. */
@media print{
  @page{size:A4 landscape;margin:9mm 7mm 12mm}
  body{background:#fff}
  .lf-hoja{max-width:none;padding:0}
  .barra{display:none}
  .rep{box-shadow:none;border-radius:0;padding:0;margin:0;overflow:visible;
    break-after:page;page-break-after:always}
  .rep:last-child{break-after:auto;page-break-after:auto}
  table{min-width:0;table-layout:auto}
  thead{display:table-header-group}
  tfoot{display:table-row-group}
  tr{break-inside:avoid;page-break-inside:avoid}
  .rep.ancha th, .rep.ancha td{font-size:8px;padding:3px 4px;overflow-wrap:anywhere}
  .rep.media th, .rep.media td{font-size:9.5px;padding:4px 5px;overflow-wrap:anywhere}
  th{background:#27ae60 !important;color:#fff !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody tr:nth-child(even){background:#f8faf9 !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tfoot td{background:#eff5f1 !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .marca span, .partes > div, tbody tr td:first-child{
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .partes, .firmas{break-inside:avoid;page-break-inside:avoid}
}
</style>

<?php if (!empty($auto)): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
<?php endif; ?>
