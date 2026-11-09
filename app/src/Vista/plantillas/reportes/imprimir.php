<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Servicio\Libro as L;
use LibertyFin\Dominio\Dinero as D;

/** Da formato a una celda según el tipo de su columna. */
$fmt = function ($v, $tipo) {
    // Una celda vacia se deja VACIA. La raya era para distinguir "no
    // hay dato" de "se me olvido", pero cuando una columna entera esta
    // vacia —la descripcion de los servicios, por ejemplo— la hoja se
    // llena de rayas y se vuelve ilegible.
    if ($v === null || $v === '') return '';
    switch ($tipo) {
        case L::MONEDA:  return D::pesos($v);
        case L::NUMERO:  return number_format((float)$v);
        case L::PORCENT: return number_format((float)$v * (abs($v) <= 1.5 ? 100 : 1), 1) . '%';
        // Lo que no es fecha se deja como viene. El renglón de totales pone
        // "TOTAL" en la primera columna, y si esa columna es de fecha
        // strtotime('TOTAL') da false y salía "31/12/1969".
        case L::FECHA:
            if (!$v) return '–';
            $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
            return $ts === false ? $v : date('d/m/Y', $ts);
        default:         return $v;
    }
};
$alineado = function ($tipo) {
    return in_array($tipo, [L::MONEDA, L::NUMERO, L::PORCENT], true) ? ' class="n"' : '';
};
?>
<div class="lf-hoja">
  <div class="barra">
    <button type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    <a href="/reportes">Volver</a>
    <?php if ($filtradas !== null): ?>
      <span class="filtro"><?= count($reportes) ?> de las seleccionadas</span>
    <?php endif; ?>
  </div>

  <?php if (!$reportes): ?>
    <section class="rep">
      <header><div><h1>No hay nada que imprimir</h1>
        <p class="per">Ninguna de las tablas que elegiste tiene datos en este
          periodo, o cambiaron al cambiar las fechas.</p></div></header>
    </section>
  <?php endif; ?>
  <?php foreach ($reportes as $i => $rep): ?>
  <?php
  // Con muchas columnas se baja el tamaño al imprimir. Con pocas no
  // hace falta y achicarlas sería perder legibilidad sin motivo.
  //
  // Se mide por el ANCHO de las columnas (el tercer dato de cada una), no
  // solo por cuántas son: doce columnas cortas caben en letra media, y
  // contarlas mandaba el Detalle de pagos o el de ventas a la letra más
  // chica sin necesidad. La más chica queda para lo de verdad ancho, como
  // el desglose.
  $n     = count($rep['columnas']);
  $ancho = array_sum(array_map(function ($c) { return (int)($c[2] ?? 12); }, $rep['columnas']));
  $clase = $ancho >= 230 ? ' ancha' : ($n >= 8 ? ' media' : '');
  ?>
  <section class="rep<?= $clase ?>">
    <header>
      <div>
        <h1><?= P::e($rep['titulo']) ?></h1>
        <p class="per"><?= P::e($empresa) ?> · <?= P::e($periodo) ?></p>
      </div>
      <div class="marca"><span>L</span>LibertyFin</div>
    </header>

    <?php /* Detalle de pagos: de dónde vino lo que entró. En papel no hay
             insignias ni colores de pantalla que lo digan, así que va escrito
             arriba de la tabla, y los renglones de ventas de otro periodo se
             resaltan. "Posterior" solo sale si hay. */
    if (!empty($rep['partes'])): $hayOtros = false; ?>
      <div class="partes">
        <?php foreach ($rep['partes'] as $k => $p):
          if ($k === 'posterior' && empty($p['cobros'])) continue;
          if ($k !== 'periodo' && !empty($p['cobros'])) $hayOtros = true; ?>
          <div<?= $k === 'periodo' ? '' : ' class="otro"' ?>>
            <small><?= P::e($p['rotulo']) ?></small>
            <b><?= D::pesos($p['monto']) ?></b>
            <span><?= P::e(\LibertyFin\Servicio\Reportes::cuentaPartes($p, $k)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($hayOtros):
        /* Solo se nombran las columnas que SÍ salen en la hoja: al imprimir se
           pueden ocultar, y una leyenda que manda a ver una columna que no
           está deja el papel sin forma de saber de qué venta es cada uno. */
        $dicen = array_values(array_intersect(['Origen', 'Fecha de venta'],
                                              array_column($rep['columnas'], 0))); ?>
        <p class="leyenda">Los renglones resaltados son cobros que entraron en el periodo
          por ventas de otro periodo<?= $dicen
            ? '; ' . (count($dicen) > 1
                ? 'las columnas «' . P::e(implode('» y «', $dicen)) . '» dicen'
                : 'la columna «' . P::e($dicen[0]) . '» dice') . ' de cuál.'
            : '. Las tarjetas de arriba dan el total de cada origen.' ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <?php foreach ($rep['columnas'] as $c): ?>
            <th<?= $alineado($c[1]) ?>><?= P::e($c[0]) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rep['filas']): ?>
          <tr><td colspan="<?= count($rep['columnas']) ?>" class="vacio">
            No hay datos en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($rep['filas'] as $f): ?>
          <tr<?= !empty($f['_origen']) ? ' class="otro"' : '' ?>>
            <?php foreach ($rep['columnas'] as $k => $c): ?>
              <td<?= $alineado($c[1]) ?>><?= P::e($fmt($f[$k] ?? null, $c[1])) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($rep['filas']): ?>
      <tfoot>
        <tr>
          <?php foreach ($rep['columnas'] as $k => $c): ?>
            <td<?= $alineado($c[1]) ?>><?= P::e($fmt($rep['totales'][$k] ?? '', $c[1])) ?></td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>

    <?php /* La hoja impresa va sin la nota que explica cada reporte: se
             imprime para entregarse, y la explicación se queda en pantalla. */ ?>
    <p class="pie">
      Generado el <?= date('d/m/Y \a \l\a\s H:i') ?> ·
      <?= count($rep['filas']) ?> <?= count($rep['filas'])==1 ? 'renglón' : 'renglones' ?>
    </p>
  </section>
  <?php endforeach; ?>
</div>

<style>
/* ── En pantalla ── */
body{background:#eef1ef;margin:0;
  font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1b2420}
.lf-hoja{max-width:1000px;margin:0 auto;padding:22px 16px 60px}
.barra{display:flex;gap:12px;align-items:center;margin-bottom:18px}
.barra button{padding:11px 22px;border:none;border-radius:99px;background:#27ae60;color:#fff;
  font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}
.barra button:hover{background:#1f8b4d}
.barra a{font-size:13px;color:#43504a}
.barra .filtro{font-size:12px;color:#1f8b4d;background:#e8f6ee;
  padding:5px 12px;border-radius:99px;font-weight:600}

.rep{background:#fff;border-radius:12px;padding:30px 32px;margin-bottom:22px;
  box-shadow:0 2px 10px rgba(0,0,0,.07)}
.rep header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
  padding-bottom:16px;margin-bottom:18px;border-bottom:2px solid #27ae60}
.rep h1{font-size:19px;margin:0 0 4px;letter-spacing:-.3px}
.per{font-size:12.5px;color:#6d7a74;margin:0}
.marca{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;flex-shrink:0}
.marca span{width:26px;height:26px;border-radius:8px;background:#27ae60;color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:14px}

/* En pantalla sí se puede desplazar; al imprimir se encoge. */
.rep{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:max-content}
@media print{ table{min-width:0} .rep{overflow:visible} }
th{background:#27ae60;color:#fff;text-align:left;padding:9px 10px;font-weight:600;
  font-size:11.5px;white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid #e6ebe8}
th.n,td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tbody tr:nth-child(even){background:#f8faf9}
tfoot td{background:#eff5f1;font-weight:700;border-top:2px solid #27ae60;border-bottom:none}
.vacio{text-align:center;color:#6d7a74;padding:26px}

/* Detalle de pagos: resumen por origen y cobros de ventas de otro periodo */
.partes{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}
.partes > div{flex:1;min-width:160px;padding:9px 12px;border:1px solid #e6ebe8;
  border-radius:8px;background:#f8faf9}
.partes > div.otro{background:#fdf3dc;border-color:#ecd39a}
.partes small{display:block;font-size:10.5px;color:#6d7a74}
.partes b{display:block;font-size:16px;margin-top:2px;font-variant-numeric:tabular-nums}
.partes span{display:block;font-size:10.5px;color:#43504a}
.leyenda{font-size:10.5px;color:#8a6410;margin:0 0 12px}
tbody tr.otro, tbody tr.otro:nth-child(even){background:#fdf3dc}
tbody tr.otro td:first-child{box-shadow:inset 3px 0 0 #c58a14}
.nota{font-size:11px;color:#6d7a74;line-height:1.55;margin:14px 0 0;
  padding-top:12px;border-top:1px solid #e6ebe8}
.pie{font-size:10.5px;color:#9aa8a2;margin:8px 0 0}

/* ── Al imprimir ──
   Cada reporte en su hoja, el encabezado de la tabla repetido en cada
   página, y sin la barra de botones. Una tabla larga sin encabezado
   repetido es ilegible a partir de la segunda hoja. */
@media print{
  @page{size:A4 landscape;margin:9mm 7mm 12mm}
  body{background:#fff}
  .lf-hoja{max-width:none;padding:0}
  .barra{display:none}
  .rep{box-shadow:none;border-radius:0;padding:0;margin:0;
    break-after:page;page-break-after:always}
  .rep:last-child{break-after:auto;page-break-after:auto}
  thead{display:table-header-group}
  tfoot{display:table-row-group}
  tr{break-inside:avoid;page-break-inside:avoid}

  /* TABLAS ANCHAS: SE ENCOGEN, NO SE CORTAN.
     El desglose tiene quince columnas. En A4 apaisado, con el tamaño
     normal, las últimas cinco quedaban fuera de la hoja y no había
     forma de verlas: el papel no se desplaza.
     Se reparte el ancho y se deja que el texto baje de renglón. */
  table{table-layout:fixed;width:100%}
  .rep.ancha th, .rep.ancha td{font-size:7.5px;padding:3px 4px;
    word-break:break-word;overflow-wrap:anywhere}
  .rep.ancha th{font-size:7px;letter-spacing:-.1px}
  .rep.media th, .rep.media td{font-size:9px;padding:4px 5px;
    word-break:break-word;overflow-wrap:anywhere}
  th{background:#27ae60 !important;color:#fff !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody tr:nth-child(even){background:#f8faf9 !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tfoot td{background:#eff5f1 !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .marca span{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  /* Sin esto el navegador no imprime fondos y el resaltado se pierde. Va
     DESPUÉS de la regla de los renglones pares para ganarle. */
  tbody tr.otro, tbody tr.otro:nth-child(even){background:#fdf3dc !important;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody tr.otro td:first-child, .partes > div{
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .partes{break-inside:avoid;page-break-inside:avoid}
}
</style>

<?php if ($auto): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
<?php endif; ?>
