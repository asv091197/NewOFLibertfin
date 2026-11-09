<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$margen = $r['cobrado'] > 0 ? round($r['queda'] / $r['cobrado'] * 100, 1) : 0;
$salidas = $r['operacion'] + $r['generales'] + $r['comisiones'] + $r['iva_cobrado'];
// En qué se fue lo que no quedó: solo lo que de verdad tuvo algo.
$seFue = array_keys(array_filter([
    'gastos de operación' => $r['operacion'], 'gastos generales' => $r['generales'],
    'comisiones' => $r['comisiones'], 'IVA' => $r['iva_cobrado'],
], function ($v) { return $v > 0; }));
$seFueTxt = count($seFue) > 1
    ? implode(', ', array_slice($seFue, 0, -1)) . (end($seFue) === 'IVA' ? ' e ' : ' y ') . end($seFue)
    : (string)reset($seFue);

/* Cómo llamar a las ventas de antes del periodo: "de meses anteriores" con
   meses completos, o "anteriores al 15/10/2026" con un rango a mitad de mes.
   La regla vive en Reportes::rotuloAntes para que la pantalla, la hoja
   impresa y el Excel digan lo mismo. */
$deAntes = \LibertyFin\Servicio\Reportes::rotuloAntes($desde, $hasta);
?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <?php /* El Excel está en «Reportes del periodo» («Excel completo»), junto
           a «Imprimir…». Aquí y en la barra de arriba había otros dos botones
           que bajaban el mismo archivo. */ ?>
</form>

<div class="lf-sintesis">
  <div class="lf-frase">
    <div style="flex:1;min-width:230px">
      <span class="lf-tag"><i></i>Resultado del periodo</span>
      <h2>Entraron <em><?= D::pesos($r['cobrado']) ?></em> y
        quedaron <em><?= D::pesos($r['queda']) ?></em>.</h2>
      <p><?php if ($seFueTxt !== ''): ?>Lo demás se fue en <?= P::e($seFueTxt) ?>.<?php endif; ?>
         El margen del periodo es del <?= $margen ?>%.</p>
    </div>
    <?php W::marcador(max(0, min(100, $margen)), 'margen'); ?>
  </div>

  <div class="lf-oscura">
    <div class="wm"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm.9 15.3v1.2h-1.7v-1.2c-1.7-.2-3-1.1-3.4-2.7l1.8-.7c.3 1 1.1 1.6 2.4 1.6 1.2 0 1.9-.5 1.9-1.3 0-.8-.6-1.1-2.2-1.5-2-.5-3.5-1.2-3.5-3.1 0-1.6 1.2-2.6 2.9-2.9V5.5h1.7v1.2c1.6.3 2.6 1.2 3 2.6l-1.8.7c-.3-.9-1-1.4-2-1.4-1.1 0-1.8.5-1.8 1.2 0 .8.7 1 2.3 1.4 2 .5 3.4 1.2 3.4 3.2 0 1.6-1.2 2.7-3 2.9z"/></svg></div>
    <div class="hd"><span class="lv"><i></i>Queda</span></div>
    <div class="big"><?= D::pesos($r['queda']) ?></div>
    <div class="sm"><?= $margen ?>% de lo que entró</div>
    <div class="ft">
      <div><small>Entró</small><b><?= D::corto($r['cobrado']) ?></b></div>
      <div><small>Salió</small><b><?= D::corto($salidas) ?></b></div>
    </div>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>De dónde a dónde</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Parte del dinero que entró, no de lo vendido</p></div>
  </header>
  <div class="card-body">
    <?php /* Dos columnas: a la izquierda el desglose, angosto, con cada
             cifra cerca de su nombre; a la derecha las notas que antes iban
             abajo. Con la tabla a todo lo ancho quedaba un hueco enorme entre
             el nombre y el monto. En pantallas chicas va una debajo de otra. */ ?>
    <div class="lf-dd-dos">
    <table class="table lf-dd" style="margin-bottom:0">
      <tbody>
        <?php
        // Gastos generales solo si hay: una empresa que no los registra veía
        // siempre un renglón en $0.00. El IVA cobrado se resta porque no es de
        // la empresa (ver ReporteRepo::resultado()).
        $lineas = array_values(array_filter([
          ['Cobrado',              $r['cobrado'],      '',  'el dinero que de verdad entró'],
          ['Gastos de operación', -$r['operacion'],    'a', 'cuelgan de una venta y bajan la comisión'],
          $r['generales'] > 0 ? ['Gastos generales', -$r['generales'], 'a', 'renta, nómina, servicios'] : null,
          ['Comisiones',          -$r['comisiones'],   'a', 'devengadas por lo cobrado'],
          ['IVA',                 -$r['iva_cobrado'],  'a', 'el que viene incluido en lo cobrado'],
        ]));
        foreach ($lineas as $l): list($n,$v,$t,$nota) = $l; ?>
          <tr>
            <td><b style="font-weight:600"><?= P::e($n) ?></b>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($nota) ?></span></td>
            <td class="text-end lf-mono" style="font-weight:700;font-size:15px;white-space:nowrap;
                color:<?= $t==='a'?'var(--lf-tinta-3)':'var(--lf-brand-2)' ?>">
              <?= ($v<0?'−':'') . D::pesos(abs($v)) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="lf-dd-total">
          <td><b style="font-weight:700;font-size:15px">Queda</b></td>
          <td class="text-end lf-mono" style="font-weight:800;font-size:21px;letter-spacing:-.6px;white-space:nowrap;
              color:<?= $r['queda']>=0?'var(--lf-brand-2)':'var(--lf-rojo)' ?>">
            <?= D::pesos($r['queda']) ?></td>
        </tr>
      </tbody>
    </table>
    <div class="lf-dd-notas">
      <?php /* Decía "Se facturaron": era la suma de las ventas del periodo,
               no de las facturas (no toda venta lleva CFDI), y como lo
               cobrado trae dinero de ventas anteriores, las cifras no
               parecían cuadrar. Ahora se parte en lo cobrado y lo que falta,
               que suman exacto. */ ?>
      <p>Las ventas del periodo suman <b><?= D::pesos($r['vendido']) ?></b>: de eso
      ya se cobraron <b><?= D::pesos(max(0, $r['vendido'] - $r['por_cobrar'])) ?></b> y faltan
      <b style="color:var(--lf-amb)"><?= D::pesos($r['por_cobrar']) ?></b>.
      Lo que falta no cuenta aquí hasta que entre: vender no es cobrar.</p>
      <?php if ($r['iva'] > 0): ?>
      <p>De esas ventas, <b><?= D::pesos($r['iva']) ?></b> son IVA.</p>
      <?php endif; ?>
      <?php /* Lo que entró por ventas de meses anteriores. Ya está dentro de
               "Cobrado"; se dice aparte porque las tablas por fecha de venta
               no lo enseñan y parecía que no cuadraba. */
      if (!empty($r['de_anteriores']) && $r['de_anteriores'] > 0): ?>
      <p class="lf-otros">
        De lo que entró, <b><?= D::pesos($r['de_anteriores']) ?></b> son
        <?= (int)$r['de_anteriores_cobros'] ?> cobro<?= $r['de_anteriores_cobros'] == 1 ? '' : 's' ?>
        de <?= (int)$r['de_anteriores_ventas'] ?> venta<?= $r['de_anteriores_ventas'] == 1 ? '' : 's' ?>
        <?= P::e($deAntes) ?>: anticipos, abonos y liquidaciones que llegaron en este periodo<?php
        if (!empty($r['de_anteriores_liquidadas'])): $liq1 = $r['de_anteriores_liquidadas'] == 1; ?>;
        con ellos <?= $liq1 ? 'quedó pagada' : 'quedaron pagadas' ?>
        <b><?= (int)$r['de_anteriores_liquidadas'] ?></b>
        venta<?= $liq1 ? '' : 's' ?><?php endif; ?>.
        <?php if (!empty($r['de_anteriores_comisiones']) && $r['de_anteriores_comisiones'] > 0): ?>
          Esos cobros generaron <b><?= D::pesos($r['de_anteriores_comisiones']) ?></b> de comisiones,
          que cuentan en este periodo igual que el dinero.
        <?php endif; ?>
        <a href="?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'pagos'])) ?>#lfReportes"
           data-ir-pestana="pagos">Ver cuáles</a>
      </p>
      <?php endif; ?>
    </div>
    </div>
  </div>
</section>

<?php if (count($meses) > 1): ?>
<section class="card">
  <header class="card-header">Los últimos <?= count($meses) ?> meses</header>
  <div class="card-body">
    <?php
    $rot = array_map(function($m){ return date('M Y', strtotime($m['mes'].'-01')); }, $meses);
    W::curvas([
      ['datos' => array_map(function($m){ return $m['cobrado']; }, $meses)],
      ['datos' => array_map(function($m){ return $m['queda'];   }, $meses)],
    ], $rot);
    ?>
    <?php /* Misma rejilla que el panel: partes iguales que no crecen e
             importes en forma micro. Ver .lf-ejex en el CSS. */ ?>
    <div class="lf-ejex" style="--n:<?= count($meses) ?>">
      <?php foreach ($meses as $m): ?>
        <div title="<?= P::e(date('F Y', strtotime($m['mes'].'-01'))) ?> · cobrado <?= P::e(D::pesos($m['cobrado'])) ?> · quedó <?= P::e(D::pesos($m['queda'])) ?>">
          <b><?= P::e(date('M', strtotime($m['mes'].'-01'))) ?></b>
          <span class="lf-mono"><?= D::micro($m['cobrado']) ?></span>
          <span class="lf-mono q" style="color:<?= $m['queda']>=0?'var(--lf-brand-2)':'var(--lf-rojo)' ?>">
            <?= D::micro($m['queda']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="lf-leyenda">
      <span><i class="lf-dot" style="background:var(--lf-brand)"></i>Cobrado</span>
      <span><i class="lf-dot" style="background:var(--lf-pizarra)"></i>Lo que quedó</span>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($areas): ?>
<?php /* EL RESULTADO DE CADA ÁREA EN EL PERIODO, con el mismo criterio que
         el de arriba: todo lo que entró (de ventas nuevas y de anteriores),
         sus gastos y TODAS las comisiones que generó. Antes Queda y Margen
         eran solo de las ventas nuevas y lo de ventas anteriores iba en una
         columna aparte sin restarse: Legales salía con 60% cuando le quedó
         el 27%, y las áreas no sumaban lo de arriba. */
  $margenDe = function ($q, $c) { return $c > 0 ? round($q / $c * 100) : 0; };
  $claseMg  = function ($mg) { return $mg >= 40 ? 'bg-success' : ($mg >= 15 ? 'bg-warning' : 'bg-danger'); }; ?>
<section class="card">
  <header class="card-header">
    <div><span>Por área</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Todo lo que entró en el periodo, sus gastos y las comisiones que generó</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-tabla-areas">
      <thead><tr><th>Área</th><th class="text-end">Cobrado</th>
        <th class="text-end">Gastos</th><th class="text-end">Comisiones</th>
        <th class="text-end">IVA cobrado</th>
        <th class="text-end">Queda</th><th class="text-end">Margen</th></tr></thead>
      <tbody>
      <?php
      $hayAnt = false;
      foreach ($areas as $a):
        $cob  = (float)$a['cobrado_total'];
        $q    = (float)$a['queda'];
        $mg   = $margenDe($q, $cob);
        $nV   = (int)$a['ventas'];
        $mAnt = (float)($a['de_anteriores'] ?? 0);
        $nAnt = (int)($a['cobros_ant'] ?? 0);
        $lAnt = (int)($a['liquidadas_ant'] ?? 0);
        $cAnt = (float)($a['comisiones_ant'] ?? 0);
        if ($mAnt > 0 || $cAnt > 0) $hayAnt = true; ?>
        <tr>
          <td data-label="Área"><b style="font-weight:600"><?= P::e($a['area']) ?></b></td>
          <td data-label="Cobrado" class="text-end lf-mono">
            <?php /* Un solo elemento: en el celular la celda es flex y, sueltos,
                     el monto y su detalle se iban a extremos opuestos. */ ?>
            <span class="lf-ant-val"><b><?= D::pesos($cob) ?></b>
              <?php if ($nV > 0): ?>
                <small><?= D::pesos($a['cobrado']) ?> de <?= $nV ?> venta<?= $nV == 1 ? '' : 's' ?>
                  nueva<?= $nV == 1 ? '' : 's' ?></small>
              <?php endif; ?>
              <?php if ($mAnt > 0): ?>
                <small class="ant"><?= D::pesos($mAnt) ?> de ventas anteriores ·
                  <?= P::e(\LibertyFin\Servicio\Reportes::cuentaPartes(
                        ['cobros' => $nAnt, 'liquidadas' => $lAnt], 'anterior')) ?></small>
              <?php endif; ?></span></td>
          <td data-label="Gastos" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <?= D::pesos($a['gastos']) ?></td>
          <td data-label="Comisiones" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <span class="lf-ant-val"><b><?= D::pesos($a['comisiones_total']) ?></b>
              <?php if ($cAnt > 0): ?><small class="ant"><?= D::pesos($cAnt) ?> de cobros anteriores</small><?php endif; ?></span></td>
          <td data-label="IVA cobrado" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <?= (float)$a['iva'] > 0 ? D::pesos($a['iva']) : '–' ?></td>
          <td data-label="Queda" class="text-end lf-mono" style="font-weight:700;
              color:<?= $q>=0?'var(--lf-tinta)':'var(--lf-rojo)' ?>"><?= D::pesos($q) ?></td>
          <td data-label="Margen" class="text-end">
            <span class="badge <?= $claseMg($mg) ?>"><?= $mg ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php /* El total sale del resultado de arriba, no de sumar la columna: un
               cobro de una venta con dos áreas cuenta en las dos filas y cada
               área redondea su parte. Los gastos generales no son de ningún área. */
        $tq = round($r['cobrado'] - $r['operacion'] - $r['comisiones'] - $r['iva_cobrado'], 2);
        $tmg = $margenDe($tq, $r['cobrado']); ?>
      <tfoot><tr>
        <td data-label="Área"><b>Total</b></td>
        <td data-label="Cobrado" class="text-end lf-mono"><b><?= D::pesos($r['cobrado']) ?></b></td>
        <td data-label="Gastos" class="text-end lf-mono"><?= D::pesos($r['operacion']) ?></td>
        <td data-label="Comisiones" class="text-end lf-mono"><?= D::pesos($r['comisiones']) ?></td>
        <td data-label="IVA cobrado" class="text-end lf-mono"><?= D::pesos($r['iva_cobrado']) ?></td>
        <td data-label="Queda" class="text-end lf-mono"><b><?= D::pesos($tq) ?></b></td>
        <td data-label="Margen" class="text-end"><span class="badge <?= $claseMg($tmg) ?>"><?= $tmg ?>%</span></td>
      </tr></tfoot>
    </table>
  </div>
  <div class="card-footer">
    El margen es lo que queda después de gastos, comisiones e IVA. Un área con mucho
    cobrado y margen bajo está trabajando para pagar comisiones.
    <?php if ($hayAnt): ?>
      <br>«Ventas anteriores» son anticipos, abonos y liquidaciones de ventas
      <?= P::e($deAntes) ?> que llegaron en el periodo, repartidos por el producto de cada
      venta. Cuentan aquí con las comisiones que generaron, igual que en el resultado de
      arriba. Una liquidación es uno de esos cobros: el que dejó pagada la venta.
      <a href="?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'pagos'])) ?>#lfReportes"
         data-ir-pestana="pagos">Ver cada cobro</a>
    <?php endif; ?>
    <?php if ($r['generales'] > 0): ?>
      <br>Los gastos generales (<?= D::pesos($r['generales']) ?>: renta, nómina, servicios) no son
      de ningún área: se restan solo en el resultado de arriba.
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="lf-split">
  <?php if ($servicios): ?>
  <section class="card">
    <header class="card-header">Productos que más facturan</header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($s){
        return ['rotulo'=>$s['nombre'],'monto'=>$s['facturado']]; }, $servicios)); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($metodos): ?>
  <section class="card">
    <header class="card-header">
      <div><span>Cómo cobran</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Por método de pago</p></div>
    </header>
    <div class="card-body">
      <?php
      $tm = 0; foreach ($metodos as $m) $tm += (float)$m['monto'];
      W::dona(array_map(function($m){
        return ['rotulo'=>ucfirst($m['metodo']),'monto'=>$m['monto']]; }, $metodos),
        D::corto($tm), 'cobrado');
      ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php /* ═══ LOS REPORTES DEL PERIODO ═══ */ ?>
<?php
/**
 * Todos van en la página, y la pestaña solo muestra uno.
 *
 * Antes cada pestaña era un enlace y recargaba: se perdía el lugar en la
 * página y había que esperar. Comparar "por área" con "por colaborador"
 * costaba dos viajes al servidor.
 *
 * Los datos ya estaban consultados de todos modos para el Excel, así que
 * cambiar de pestaña es instantáneo.
 */
$celda = function ($v, $t) {
    if ($v === null || $v === '') return '';
    if ($t === '$') return D::pesos($v);
    if ($t === 'n') return number_format((float)$v);
    if ($t === '%') return number_format((float)$v * (abs($v) <= 1.5 ? 100 : 1), 1) . '%';
    if ($t === 'f') {
        // Lo que no es fecha se deja como viene: el pie pone "TOTAL" en la
        // primera columna, y en las tablas que empiezan por fecha
        // strtotime('TOTAL') da false y salía "31/12/1969".
        $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
        return $ts === false ? $v : date('d/m/Y', $ts);
    }
    return $v;
};
$derecha = function ($t) { return in_array($t, ['$','n','%'], true); };

/* El contenido de una celda, YA ESCAPADO, listo para pintar.
 *
 * El folio de la venta —o la columna "Venta" en el detalle de pagos— se
 * vuelve un enlace que abre esa venta en el panel de al lado (data-modal),
 * sin salir del reporte. Lo hace con el id que trae la fila en '_venta',
 * que no es columna: el Excel y la impresión no lo ven.
 *
 * Solo si quien mira puede ver ventas: a alguien con permiso de reportes
 * pero no de ventas, el enlace le abriría un "sin permiso".
 *
 * En el detalle de pagos, la fecha de una venta de otro periodo lleva una
 * marca: es justo lo que esa tabla vino a enseñar. */
$puedeVerVentas = \LibertyFin\Dominio\Permisos::puede('ver.ventas');
$valor = function (array $f, $j, array $c) use ($celda, $puedeVerVentas) {
    $txt = P::e($celda($f[$j] ?? null, $c[1])) ?: '–';
    if ($txt === '–') return $txt;
    if ($puedeVerVentas && !empty($f['_venta']) && in_array($c[0], ['Folio', 'Venta'], true)) {
        return '<a class="lf-folio" href="/ventas/' . (int)$f['_venta'] . '" data-modal'
             . ' title="Ver la venta ' . $txt . '">' . $txt . '</a>';
    }
    // En el detalle de pagos, la columna "Origen" de un cobro de una venta de
    // otro periodo se pinta como insignia: es justo lo que esa tabla vino a
    // enseñar. Es un solo elemento, así que en el celular no se separa.
    if ($c[0] === 'Origen' && !empty($f['_origen'])) {
        return '<span class="lf-origen">' . $txt . '</span>';
    }
    return $txt;
};

/* Columnas de UNA tabla, para elegir cuáles imprimir. Cada reporte o área
   tiene las suyas: lo que se desmarca aquí no toca a las demás. */
$columnasDe = function (array $cols, $clase = 'cols-l', $rotulo = 'Columnas', $extra = '') {
    if (count($cols) < 2) return;
    echo '<details class="cols-it ' . $extra . '"' . ($extra ? ' hidden' : '') . '><summary>' . $rotulo
       . ' <small class="cn"></small></summary><div class="' . $clase . '">';
    foreach ($cols as $c) {
        echo '<label><input type="checkbox" value="' . P::e($c[0]) . '" checked>'
           . '<span>' . P::e($c[0]) . '</span></label>';
    }
    echo '</div></details>';
};
?>
<section class="card lf-tabs" data-tabs="reportes" id="lfReportes">
  <header class="card-header">
    <div><span>Reportes del periodo</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Mismo dato en los tres lados: pantalla, Excel e impresión</p></div>
    <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm"
         href="/reportes/excel?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta]) ?>">
        <?= W::icono('baja','14px') ?>Excel completo</a>
      <button type="button" class="btn btn-secondary btn-sm" id="abrirElegirTipos">
        Imprimir…</button>
    </div>
  </header>

  <?php /* Qué reportes imprimir, cuando son varios */ ?>
  <div class="lf-elegir" id="elegirTipos" hidden
       data-base="/reportes/imprimir?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta])) ?>">
    <div class="cab">
      <b><?= W::icono('baja','15px') ?>Qué imprimir</b>
      <span class="todas">
        <button type="button" data-todas="1">Todos</button>
        <button type="button" data-todas="0">Ninguno</button>
      </span>
    </div>
    <div class="ops">
      <?php foreach ($tipos as $k => $t): ?>
        <div class="it">
          <label>
            <input type="checkbox" value="<?= P::e($k) ?>" <?= $tipo===$k?'checked':'' ?>>
            <span><?= P::e($t['rotulo']) ?>
              <small><?= $k === 'desglose'
                         ? $desglose['cuantas'] . ' tablas'
                         : count($reportes[$k]['filas']) ?></small></span>
          </label>
          <div class="sub">
            <?php $columnasDe($k === 'desglose'
                  ? ($desglose['tablas'] ? $desglose['tablas'][0]['columnas'] : [])
                  : $reportes[$k]['columnas']); ?>
            <?php if ($k === 'colaborador' && $comisiones['tablas']): ?>
              <label class="det" title="Agrega una tabla por colaborador con el porqué de cada comisión">
                <input type="checkbox" data-detalle="1"><span>Con detalle</span></label>
              <?php /* Las columnas de las tablas del detalle, aparte de las del
                       resumen. Solo se muestran con "Con detalle" marcado. */
                    $columnasDe($comisiones['tablas'][0]['columnas'], 'cols-l cols-d',
                                'Columnas del detalle', 'cols-det'); ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-primary btn-sm ir" href="#" target="_blank">Imprimir</a>
    <p class="nota">El desglose sale con una tabla por área. Para elegir
      <b>cuáles</b> áreas, hazlo desde su propia pestaña. Abre
      <b>Columnas</b> en cada reporte para ocultar las que no quieras; solo
      afecta a ese reporte.</p>
  </div>

  <div class="lf-pills" style="padding:4px 20px 14px" role="tablist">
    <?php foreach ($tipos as $k => $t): ?>
      <a class="lf-pill <?= $tipo===$k?'active':'' ?>" role="tab" data-tab="<?= $k ?>"
         aria-selected="<?= $tipo===$k?'true':'false' ?>"
         href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$k]) ?>">
        <?= P::e($t['rotulo']) ?>
        <span class="n"><?= count($reportes[$k]['filas']) ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php /* El desglose no es una tabla: son varias, una por área. */ ?>
  <div data-panel="desglose" <?= $tipo==='desglose' ? '' : 'hidden' ?>>
    <div style="padding:0 20px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span style="font-size:12.5px;color:var(--lf-tinta-3)">Agrupar por el área</span>
      <div class="lf-pills" style="margin:0">
        <a class="lf-pill <?= $por==='servicio'?'active':'' ?>"
           href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>'servicio']) ?>">
          del producto contratado</a>
        <a class="lf-pill <?= $por==='origen'?'active':'' ?>"
           href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>'origen']) ?>">
          de donde salió la venta</a>
      </div>
    </div>

    <div style="padding:0 20px 14px">
      <p style="font-size:11.5px;color:var(--lf-tinta-4);line-height:1.6;margin:0;
                padding:12px 14px;background:var(--lf-vidrio);border-radius:var(--lf-r)">
        <b>Una venta puede pertenecer a dos áreas a la vez.</b> Si recepción cobró un
        servicio contable, la venta salió de Administración y el trabajo es de
        Contabilidad: las dos cosas son ciertas. Por eso se elige con cuál agrupar en
        vez de que el sistema decida.
        <b>El total no cambia</b> al cambiar la agrupación — es el mismo dinero contado
        de dos maneras.
        <?php if ($por === 'servicio'): ?>
          Agrupando por producto, contabilidad se parte en personas físicas y morales.
        <?php else: ?>
          Agrupando por origen no se parte contabilidad: ahí la pregunta es de qué
          equipo salió la venta.
        <?php endif; ?>
      </p>
      <?php /* Las ventas de antes con cobros en el periodo ya salen en estas
               tablas, en el área de su producto y marcadas. */
      if (!empty($r['de_anteriores']) && $r['de_anteriores'] > 0): ?>
        <p class="lf-otros en-tabla" style="margin:10px 0 0">
          En este periodo entraron <b><?= D::pesos($r['de_anteriores']) ?></b> de
          <?= (int)$r['de_anteriores_ventas'] ?> venta<?= $r['de_anteriores_ventas'] == 1 ? '' : 's' ?>
          <?= P::e($deAntes) ?> (anticipos, abonos y liquidaciones). Salen en el área de
          su producto, marcadas <i class="lf-origen">Venta anterior</i>, con lo que entró en
          «Cobrado en el periodo».
          <a href="?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'pagos'])) ?>#lfReportes"
             data-ir-pestana="pagos">Ver cada cobro</a>
        </p>
      <?php endif; ?>
    </div>

    <?php if (!$desglose['tablas']): ?>
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:32px">
        No hay ventas en este periodo.</p>
    <?php else: ?>
      <?php /* Elegir qué tablas imprimir. Imprimir las nueve para leer
               una gasta papel y esconde lo que se buscaba. */ ?>
      <div class="lf-elegir" id="elegirAreas"
           data-base="/reportes/imprimir?<?= P::e(http_build_query(
             ['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>$por])) ?>">
        <div class="cab">
          <b><?= W::icono('baja','15px') ?>Imprimir</b>
          <span class="todas">
            <button type="button" data-todas="1">Todas</button>
            <button type="button" data-todas="0">Ninguna</button>
          </span>
        </div>
        <div class="ops">
          <?php foreach ($desglose['tablas'] as $tb): ?>
            <div class="it">
              <label>
                <input type="checkbox" value="<?= P::e($tb['titulo']) ?>" checked>
                <span><?= P::e($tb['titulo']) ?>
                  <small><?= count($tb['filas']) ?></small></span>
              </label>
              <?php $columnasDe($tb['columnas']); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <a class="btn btn-primary btn-sm ir" href="#" target="_blank">
          Imprimir las <?= count($desglose['tablas']) ?></a>
      </div>
    <?php endif; ?>

    <?php foreach ($desglose['tablas'] as $tb): ?>
      <div style="padding:0 12px 20px">
        <h3 style="font-size:14px;font-weight:700;padding:0 8px 9px;margin:0;
                   border-bottom:2px solid var(--lf-brand);display:flex;
                   justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span><?= P::e($tb['titulo']) ?></span>
          <span style="font-size:12px;font-weight:600;color:var(--lf-brand-2);
                       font-family:var(--lf-mono)">
            <?php /* Por posición: 10 es "Cobrado" y 11 "Cobrado en el periodo"
                     (ver Reportes::desglose). */
            $nAntTb = count(array_filter($tb['filas'], function ($x) { return !empty($x['_origen']); })); ?>
            <?= D::pesos(array_sum(array_column($tb['filas'], 10))) ?>
            <span style="color:var(--lf-tinta-4);font-weight:400">
              · <?= count($tb['filas']) ?> renglones<?php if ($nAntTb): ?>
              (<?= $nAntTb ?> de ventas anteriores)<?php endif; ?>
              · entró en el periodo <?= D::pesos(array_sum(array_column($tb['filas'], 11))) ?></span></span>
        </h3>
        <div class="table-responsive lf-cards">
          <table class="table">
            <thead><tr>
              <?php foreach ($tb['columnas'] as $c): ?>
                <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
              <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($tb['filas'], 0, 40) as $f): ?>
              <tr<?= !empty($f['_origen']) ? ' class="lf-fila-otro"' : '' ?>>
                <?php foreach ($tb['columnas'] as $j => $c): ?>
                  <td data-label="<?= P::e($c[0]) ?>"
                      <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                    <?= $valor($f, $j, $c) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="border-top:2px solid var(--lf-brand)">
              <?php foreach ($tb['columnas'] as $j => $c): ?>
                <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                    style="font-weight:700"><?= P::e($celda($tb['totales'][$j] ?? '', $c[1])) ?></td>
              <?php endforeach; ?>
            </tr></tfoot>
          </table>
        </div>
        <?php if (count($tb['filas']) > 40): ?>
          <p style="font-size:11.5px;color:var(--lf-tinta-4);padding:8px">
            Se muestran 40 de <?= count($tb['filas']) ?>; el Excel los trae todos.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span><?= $desglose['cuantas'] ?> tabla<?= $desglose['cuantas']==1?'':'s' ?> ·
        entró en el periodo <b class="lf-mono" style="color:var(--lf-tinta)">
          <?= D::pesos($desglose['total']) ?></b></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose']) ?>">
        Imprimir</a>
    </div>
  </div>

  <?php /* Debajo del resumen por colaborador: el porque de cada comision. */ ?>
  <div data-panel="colaborador" <?= $tipo==='colaborador' ? '' : 'hidden' ?>>
    <?php $rep = $reportes['colaborador']; ?>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table">
        <thead><tr>
          <?php foreach ($rep['columnas'] as $c): ?>
            <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rep['filas']): ?>
          <tr><td colspan="<?= count($rep['columnas']) ?>"
              style="text-align:center;color:var(--lf-tinta-4);padding:32px">
            No hay comisiones en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($rep['filas'] as $f): ?>
          <tr>
            <?php foreach ($rep['columnas'] as $j => $c): ?>
              <td data-label="<?= P::e($c[0]) ?>"
                  <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                <?= P::e($celda($f[$j] ?? null, $c[1])) ?: '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rep['filas']): ?>
        <tfoot><tr style="border-top:2px solid var(--lf-brand)">
          <?php foreach ($rep['columnas'] as $j => $c): ?>
            <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                style="font-weight:700"><?= P::e($celda($rep['totales'][$j] ?? '', $c[1])) ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>

    <?php if ($comisiones['tablas']): ?>
      <div style="padding:18px 20px 6px">
        <h3 style="font-size:14px;font-weight:700;margin:0 0 4px">De dónde sale cada comisión</h3>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);line-height:1.6;margin:0 0 4px">
          Cada renglón es un pago. <b>La comisión no es un porcentaje de la venta:
          es el porcentaje aplicado sobre lo que se cobró en ese pago.</b> Por eso un
          anticipo del 25% con comisión del 30% no da el 30% de la venta.
          Las columnas <b>% comisión</b> y <b>Sobre</b> explican el número.
        </p>
      </div>

      <?php foreach ($comisiones['tablas'] as $tb): ?>
        <details class="lf-colab">
          <summary>
            <span class="n"><?= P::e($tb['titulo']) ?></span>
            <span class="p"><?= (int)$tb['pagos'] ?> pago<?= $tb['pagos']==1?'':'s' ?></span>
            <b class="lf-mono"><?= D::pesos($tb['monto']) ?></b>
          </summary>
          <div class="table-responsive lf-cards">
            <table class="table">
              <thead><tr>
                <?php foreach ($tb['columnas'] as $c): ?>
                  <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
                <?php endforeach; ?>
              </tr></thead>
              <tbody>
              <?php foreach ($tb['filas'] as $f): ?>
                <tr>
                  <?php foreach ($tb['columnas'] as $j => $c): ?>
                    <td data-label="<?= P::e($c[0]) ?>"
                        <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                      <?= $valor($f, $j, $c) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr style="border-top:2px solid var(--lf-brand)">
                <?php foreach ($tb['columnas'] as $j => $c): ?>
                  <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                      style="font-weight:700"><?= P::e($celda($tb['totales'][$j] ?? '', $c[1])) ?></td>
                <?php endforeach; ?>
              </tr></tfoot>
            </table>
          </div>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span><?= P::e($rep['nota']) ?></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,
               'tipo'=>'colaborador','detalle'=>1]) ?>">Imprimir con el detalle</a>
    </div>
  </div>

  <?php foreach ($reportes as $k => $rep): if ($k === 'desglose' || $k === 'colaborador') continue;
    /* El detalle de pagos se lee entero para cuadrar contra "Entraron": se
       enseñan más renglones que en las demás tablas. */
    $tope = $k === 'pagos' ? 300 : 50; ?>
  <div data-panel="<?= $k ?>" <?= $tipo===$k ? '' : 'hidden' ?>>
    <?php if (!empty($rep['partes']) && $rep['filas']): $pt = $rep['partes']; ?>
      <?php /* De dónde vino lo que entró: ventas de este periodo o de otro. */ ?>
      <div class="lf-partes">
        <div>
          <small><?= P::e($pt['periodo']['rotulo']) ?></small>
          <b class="lf-mono"><?= D::pesos($pt['periodo']['monto']) ?></b>
          <span><?= P::e(\LibertyFin\Servicio\Reportes::cuentaPartes($pt['periodo'], 'periodo')) ?></span>
        </div>
        <div class="otro">
          <small><?= P::e($pt['anterior']['rotulo']) ?></small>
          <b class="lf-mono"><?= D::pesos($pt['anterior']['monto']) ?></b>
          <span><?= P::e(\LibertyFin\Servicio\Reportes::cuentaPartes($pt['anterior'], 'anterior')) ?>
            · marcados <i class="lf-origen">Venta anterior</i></span>
        </div>
        <?php if ($pt['posterior']['cobros'] > 0): ?>
        <div class="otro">
          <small><?= P::e($pt['posterior']['rotulo']) ?></small>
          <b class="lf-mono"><?= D::pesos($pt['posterior']['monto']) ?></b>
          <span><?= P::e(\LibertyFin\Servicio\Reportes::cuentaPartes($pt['posterior'], 'posterior')) ?>
            · su fecha se movió a mano</span>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>


    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table">
        <thead><tr>
          <?php foreach ($rep['columnas'] as $c): ?>
            <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rep['filas']): ?>
          <tr><td colspan="<?= count($rep['columnas']) ?>"
              style="text-align:center;color:var(--lf-tinta-4);padding:32px">
            No hay datos en este periodo. Cambia las fechas de arriba.</td></tr>
        <?php endif; ?>
        <?php foreach (array_slice($rep['filas'], 0, $tope) as $f): ?>
          <tr<?= !empty($f['_origen']) ? ' class="lf-fila-otro"' : '' ?>>
            <?php foreach ($rep['columnas'] as $j => $c): ?>
              <td data-label="<?= P::e($c[0]) ?>"
                  <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                <?= $valor($f, $j, $c) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rep['filas']): ?>
        <tfoot><tr style="border-top:2px solid var(--lf-brand)">
          <?php foreach ($rep['columnas'] as $j => $c): ?>
            <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                style="font-weight:700"><?= P::e($celda($rep['totales'][$j] ?? '', $c[1])) ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="flex:1;min-width:220px"><?= P::e($rep['nota']) ?>
        <?php if (count($rep['filas']) > $tope): ?>
          <b>Se muestran <?= $tope ?> de <?= count($rep['filas']) ?>; el Excel los trae todos.</b>
        <?php endif; ?></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$k]) ?>">
        Imprimir este</a>
    </div>
  </div>
  <?php endforeach; ?>
</section>

<script>
/* ══════════════════════════════════════════════════════
   "VER CUÁLES": IR A UNA PESTAÑA DESDE OTRO LADO
   Los avisos de cobros de meses anteriores llevan a la
   pestaña "Detalle de pagos". Se "toca" su píldora para que
   cambie igual que siempre (sin recargar y con la dirección
   al día) y se baja hasta los reportes. Sin JavaScript el
   enlace navega a ?tipo=pagos#lfReportes, que da lo mismo.
   ══════════════════════════════════════════════════════ */
(function () {
  /* La navegación sin recarga vuelve a correr este script cada vez
     que se entra a Reportes; el escucha se pone una sola vez. */
  if (window.lfIrPestana) return;
  window.lfIrPestana = true;
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-ir-pestana]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var pill = document.querySelector('[data-tabs="reportes"] .lf-pill[data-tab="'
                                      + a.getAttribute('data-ir-pestana') + '"]');
    if (!pill) return;               // sin la pestaña, que navegue el enlace
    e.preventDefault();
    pill.click();
    var sec = document.getElementById('lfReportes');
    if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
</script>

<script>
/* ══════════════════════════════════════════════════════
   ELEGIR QUÉ IMPRIMIR
   Las casillas arman la dirección. El botón siempre dice
   cuántas van, porque "Imprimir" a secas no deja claro si
   respeta lo marcado.
   ══════════════════════════════════════════════════════ */
(function () {
  document.querySelectorAll('.lf-elegir').forEach(function (caja) {
    var ir    = caja.querySelector('.ir');
    var base  = caja.dataset.base;
    var esAreas = caja.id === 'elegirAreas';

    /* Cada elemento (reporte o área) lleva sus propias columnas. Se manda
       solo lo OCULTO, por elemento: ocultar[clave]=Col|Col. Así lo que se
       quita en uno no se arrastra a los demás. */
    function refrescar() {
      var items = Array.prototype.slice.call(caja.querySelectorAll('.ops > .it'));
      var marcadas = [], ocultas = [], sinCols = false;

      items.forEach(function (it) {
        var chk  = it.querySelector('label input');
        /* Las del resumen y las del detalle van aparte: `.cols-d` es de
           las tablas de detalle del colaborador. */
        var cols = it.querySelectorAll('.cols-l:not(.cols-d) input');
        var off  = [];
        cols.forEach(function (c) { if (!c.checked) off.push(c.value); });
        var cn = it.querySelector('details:not(.cols-det) .cn');
        if (cn) cn.textContent = off.length
          ? '(' + (cols.length - off.length) + ' de ' + cols.length + ')' : '';

        var det = it.querySelector('[data-detalle]');
        var dcols = it.querySelectorAll('.cols-d input');
        var caja2 = it.querySelector('.cols-det');
        if (caja2) caja2.hidden = !(det && det.checked && chk.checked);
        var doff = [];
        dcols.forEach(function (c) { if (!c.checked) doff.push(c.value); });
        var dn = it.querySelector('.cols-det .cn');
        if (dn) dn.textContent = doff.length
          ? '(' + (dcols.length - doff.length) + ' de ' + dcols.length + ')' : '';

        if (!chk.checked) return;
        marcadas.push(chk.value);
        if (cols.length && off.length === cols.length) sinCols = true;
        if (off.length) ocultas.push([chk.value, off]);
        if (det && det.checked) {
          if (dcols.length && doff.length === dcols.length) sinCols = true;
          if (doff.length) ocultas.push(['comisiones', doff]);
        }
      });
      var total = items.length;

      if (!marcadas.length || sinCols) {
        ir.classList.add('apagado');
        ir.removeAttribute('href');
        ir.textContent = !marcadas.length ? 'Elige al menos una'
                                          : 'Hay una con todas las columnas ocultas';
        return;
      }
      ir.classList.remove('apagado');

      /* Si están todas, no se manda el filtro: la dirección queda
         corta y se puede compartir sin arrastrar una lista enorme. */
      var url = base;
      if (marcadas.length < total) {
        url += '&' + (esAreas ? 'areas=' : 'tipos=')
             + marcadas.map(encodeURIComponent).join(esAreas ? '|' : ',');
      } else if (!esAreas) {
        url += '&todos=1';
      }
      var det = caja.querySelector('[data-detalle]');
      if (det && det.checked && marcadas.indexOf('colaborador') !== -1) url += '&detalle=1';
      ocultas.forEach(function (o) {
        url += '&' + encodeURIComponent('ocultar[' + o[0] + ']') + '='
             + o[1].map(encodeURIComponent).join('|');
      });
      ir.href = url;
      ir.textContent = marcadas.length === total
        ? ('Imprimir ' + (esAreas ? 'las ' : 'los ') + total)
        : ('Imprimir ' + marcadas.length + ' de ' + total);
    }

    caja.addEventListener('change', refrescar);
    caja.querySelectorAll('[data-todas]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.dataset.todas === '1';
        caja.querySelectorAll('.ops > .it > label input').forEach(function (c) { c.checked = v; });
        refrescar();
      });
    });
    refrescar();
  });

  var abrir = document.getElementById('abrirElegirTipos');
  var caja  = document.getElementById('elegirTipos');
  if (abrir && caja) {
    abrir.addEventListener('click', function () {
      caja.hidden = !caja.hidden;
      if (!caja.hidden) caja.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }
})();
</script>
