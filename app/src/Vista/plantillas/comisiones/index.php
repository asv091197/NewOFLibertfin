<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$total   = (float)($resumen['total'] ?? 0);
$pagar   = (float)($resumen['por_pagar'] ?? 0);
$sin     = (float)($resumen['sin_asignar'] ?? 0);
$iniciales = function ($n) {
    $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : ''));
};

// Conserva el periodo al cambiar de página: sin esto, pasar a la página
// dos devolvería al mes actual y las cifras cambiarían sin aviso.
$qs = function ($x = []) use ($desde, $hasta) {
    return '?' . http_build_query(array_merge(
        array_filter(['desde' => $desde, 'hasta' => $hasta]),
        array_filter($x, function ($v) { return $v !== null; })));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <?php if ($esAdmin): ?>
    <?php /* `form=`: el botón manda el formulario de abajo, que va fuera de este
             (un formulario no puede ir dentro de otro). */ ?>
    <button class="btn btn-secondary btn-sm" style="margin-left:auto" type="submit" form="formRecalcular"
            title="Vuelve a repartir lo devengado de cada venta entre los pagos que tiene hoy">
      Recalcular comisiones</button>
    <a class="btn btn-primary btn-sm"
       href="/comisiones/lote?<?= P::e(http_build_query(['desde' => $desde, 'hasta' => $hasta])) ?>">Asignar en lote</a>
  <?php endif; ?>
</form>

<?php if ($esAdmin): ?>
<form method="post" action="/comisiones/recalcular" id="formRecalcular" hidden
      onsubmit="return confirm('¿Recalcular las comisiones de todas las ventas?\n\nCada venta se vuelve a calcular contra los pagos que tiene hoy. Las que ya están bien no cambian.\n\nAl terminar verás qué cambió y podrás deshacerlo.')">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
</form>
<?php endif; ?>

<?php /* EL ÚLTIMO RECÁLCULO: qué ventas cambiaron, cuánto se movió de un mes
         a otro, y el botón para regresarlo. Ver Servicio\RecalcularComisiones. */
if (!empty($recalculo) && !$recalculo['deshecho_en'] && $recalculo['ventas']):
  $mesCorto = function ($ym) {
      $n = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun',
            '07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
      return ($n[substr($ym, 5, 2)] ?? $ym) . ' ' . substr($ym, 0, 4);
  };
  $cuantas = count($recalculo['ventas']); ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 35%,transparent)">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><span>Último recálculo</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        <?= date('d/m/Y H:i', strtotime($recalculo['creado_en'])) ?>
        <?= $recalculo['usuario_nombre'] ? '· ' . P::e($recalculo['usuario_nombre']) : '' ?>
        · de <?= (int)$recalculo['ventas_revisadas'] ?> ventas revisadas, cambiaron <?= $cuantas ?></p></div>
    <form method="post" action="/comisiones/recalcular/deshacer"
          onsubmit="return confirm('¿Deshacer este recálculo?\n\nLas ventas de la lista vuelven a como estaban. Si alguna tuvo un pago o una comisión nueva después, esa se deja como está para no borrar lo nuevo.')">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="id" value="<?= (int)$recalculo['id'] ?>">
      <button class="btn btn-secondary btn-sm" type="submit">Deshacer</button>
    </form>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Venta</th><th>Cliente</th><th class="text-end">Comisión antes</th>
        <th class="text-end">Después</th><th>Qué se movió</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($recalculo['ventas'], 0, 50) as $x): ?>
        <tr>
          <td data-label="Venta">
            <a data-lf-fila href="/ventas/<?= (int)$x['venta_id'] ?>" data-modal
               class="lf-mono" style="font-weight:600;color:var(--lf-tinta)"><?= P::e($x['folio']) ?></a></td>
          <td data-label="Cliente" style="font-size:12.5px"><?= P::e($x['cliente']) ?></td>
          <td data-label="Comisión antes" class="text-end lf-mono" style="color:var(--lf-tinta-3)"><?= D::pesos($x['antes']) ?></td>
          <td data-label="Después" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($x['despues']) ?></td>
          <td data-label="Qué se movió" style="font-size:12px">
            <?php foreach ($x['meses'] as $ym => $dif): ?>
              <span style="display:inline-block;margin-right:8px;white-space:nowrap;
                    color:<?= $dif > 0 ? 'var(--lf-amb)' : 'var(--lf-brand-2)' ?>">
                <?= P::e($mesCorto($ym)) ?> <?= $dif > 0 ? '+' : '−' ?><?= D::pesos(abs($dif)) ?></span>
            <?php endforeach; ?>
            <?php /* Entre quiénes: se dice siempre que cambie, aunque el mes no. */
            if (!empty($x['personas'])): ?>
              <span style="display:block;margin-top:3px;color:var(--lf-tinta-3)">
                <?= implode(' · ', array_map(function ($n, $d) {
                      return P::e($n ?: 'Sin nombre') . ' ' . ($d > 0 ? '+' : '−') . D::pesos(abs($d));
                    }, array_keys($x['personas']), $x['personas'])) ?></span>
            <?php elseif (!$x['meses'] && $x['pagos']): ?>
              <span style="color:var(--lf-tinta-3)">Mismo total y mismo mes: solo se reacomodó entre
                <?= (int)$x['pagos'] ?> pagos de la venta. No cambia ningún reporte.</span>
            <?php elseif (!$x['meses']): ?>
              <span style="color:var(--lf-tinta-3)">Mismos montos; solo se rehicieron sus registros.
                No cambia ningún reporte.</span>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Lo devengado de cada venta se volvió a repartir entre los pagos que tiene hoy; solo salen
    las que cambiaron<?= $cuantas > 50 ? ' (aquí las 50 que más se movieron, de ' . $cuantas . ')' : '' ?>.
    «+» es comisión que se suma a ese mes y «−» la que sale de él.
    Quién cobra y cuánto, si liquida, no cambió.
  </div>
</section>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($pagar) ?></div>
    <div class="stat-label">Por pagar</div>
    <?php W::avance(D::pct($pagar, max(0.01,$total))); ?>
    <div class="stat-meta">de <?= D::corto($total) ?> que generó el periodo</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($sin) ?></div>
    <div class="stat-label">Sin dueño</div>
    <?php W::avance(D::pct($sin, max(0.01,$total)), true); ?>
    <div class="stat-meta"><?= (int)($resumen['ventas_pendientes'] ?? 0) ?> ventas sin confirmar quién vendió</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['por_liberar'] ?? 0) ?></div>
    <div class="stat-label">Por liberar</div>
    <div class="stat-meta">se libera conforme los clientes paguen</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['colaboradores'] ?? 0) ?></div>
    <div class="stat-label">Colaboradores</div>
    <div class="stat-meta">con comisión en el periodo</div>
  </div>
</div>

<?php if ($sin_dueno): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 30%,transparent)">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><span>Comisiones sin dueño</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Cuentan para el total, pero no se le pueden pagar a nadie hasta confirmar quién vendió</p></div>
    <span class="badge bg-warning"><?= D::pesos($sin) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Venta</th><th>Área</th><th class="text-end">%</th>
        <th class="text-end">Monto</th><th style="width:250px">Asignar a</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sin_dueno as $s): ?>
        <tr>
          <td data-label="Venta">
            <a data-lf-fila href="/ventas/<?= (int)$s['venta_id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($s['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($s['codigo_venta']) ?> · <?= date('d M', strtotime($s['fecha'])) ?></span>
          </td>
          <td data-label="Área">
            <span class="badge bg-secondary"><?= P::e($s['area_servicio']) ?></span>
            <?php if ($s['area_nombre'] && $s['area_nombre'] !== $s['area_servicio']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:10.5px;margin-top:3px">
                comisión del equipo <?= P::e($s['area_nombre']) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="%" class="text-end lf-mono"><?= preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format((float)$s['porcentaje'], 4, '.', '')) ?>%</td>
          <td data-label="Monto" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($s['monto']) ?></td>
          <td data-label="Asignar a">
            <?php if (!$esAdmin): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">Solo un administrador</span>
            <?php elseif (empty($candidatos[$s['area_nombre']])): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">
                No hay colaboradores en <?= P::e($s['area_nombre']) ?></span>
            <?php else: ?>
              <form method="post" action="/comisiones/reasignar" style="display:flex;gap:7px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="renglon" value="<?= (int)$s['renglon'] ?>">
                <select class="form-select form-select-sm" name="colaborador" required>
                  <option value="">Elegir…</option>
                  <?php foreach ($candidatos[$s['area_nombre']] as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" type="submit">Asignar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <header class="card-header">
    <div><span>Por colaborador</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Devengado en el periodo</p></div>
    <span class="badge bg-secondary"><?= count($equipo) ?> con comisión</span>
  </header>
  <?php
  // La rejilla cabe sin scroll hasta doce. Con treinta colaboradores la
  // tarjeta crece tanto que las métricas de arriba se pierden.
  $total_eq = count($equipo);
  $paginas_eq = max(1, (int)ceil($total_eq / $porPag));
  $pag_eq = min($pagina, $paginas_eq);
  $equipo = array_slice($equipo, ($pag_eq - 1) * $porPag, $porPag);
  ?>
  <div class="lf-equipo" data-desde="<?= P::e($desde) ?>" data-hasta="<?= P::e($hasta) ?>">
    <?php if (!$equipo): ?>
      <p style="grid-column:1/-1;padding:26px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
        No hay comisiones devengadas en este periodo.</p>
    <?php endif; ?>
    <?php
    // Rejilla en vez de lista: con seis u ocho colaboradores una lista
    // vertical deja media pantalla en blanco.
    $mayor = 0; foreach ($equipo as $c) $mayor = max($mayor, (float)$c['devengado']);
    foreach ($equipo as $c): $huerfano = (int)$c['sin_dueno'] === 1; ?>
      <div class="lf-pers<?= $huerfano ? ' sin' : '' ?>" role="button" tabindex="0" title="Ver sus ventas en una ventana"
           data-id="<?= (int)$c['colaborador_id'] ?>"
           data-nombre="<?= P::e($c['colaborador_nombre']) ?>"
           data-equipo="<?= P::e($c['equipo']) ?>">
        <span class="lf-av <?= $huerfano ? 'gris' : '' ?>">
          <?= $huerfano ? '?' : P::e($iniciales($c['colaborador_nombre'])) ?></span>
        <div style="flex:1;min-width:0">
          <b><?= P::e($c['colaborador_nombre']) ?></b>
          <small><?= P::e($c['area_nombre']) ?> · <?= (int)$c['ventas'] ?> venta<?= $c['ventas']==1?'':'s' ?></small>
          <?php W::avance($mayor > 0 ? $c['devengado'] / $mayor * 100 : 0, $huerfano); ?>
        </div>
        <span class="mn"><?= D::pesos($c['devengado']) ?>
          <?php if ($huerfano): ?><i>no se paga</i><?php endif; ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($equipo) ?> de <?= $total_eq ?> ·
      total generado <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($total) ?></b></span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pag_eq,'paginas'=>$paginas_eq,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
</section>

<div class="lf-tres">
    <?php if ($areas): ?>
    <section class="card">
      <header class="card-header">Por área</header>
      <div class="card-body">
        <?php W::dona(array_map(function($a){
          return ['rotulo'=>$a['area'] ?: 'Sin área','monto'=>$a['monto']]; }, $areas),
          D::corto($total), 'generado'); ?>
      </div>
      <div class="card-footer">
        El total reparte lo generado en el periodo, pagado o no.
      </div>
    </section>
    <?php endif; ?>

    <?php if ($liberacion): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Cuánto se ha liberado</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            De lo asignado, qué parte ya se ganó</p></div>
      </header>
      <div class="card-body">
        <?php foreach ($liberacion as $l):
          $pct = D::pct($l['devengada'], max(0.01, $l['asignada'])); ?>
          <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
              <b style="font-weight:600"><?= P::e($l['area'] ?: 'Sin área') ?></b>
              <span class="lf-mono" style="color:var(--lf-tinta-3)">
                <?= D::pesos($l['devengada']) ?> de <?= D::pesos($l['asignada']) ?></span>
            </div>
            <?php W::avance($pct, $pct < 99.5); ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        Lo que falta se libera conforme los clientes paguen. Un área muy abajo
        no está vendiendo mal: está esperando cobranza.
      </div>
    </section>
    <?php endif; ?>

    <?php if ($atadas): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Comisión atada</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Las ventas que más comisión retienen sin cobrar</p></div>
      </header>
      <div style="padding:0 10px 8px">
        <?php foreach ($atadas as $a): ?>
          <a class="lf-row" href="/ventas/<?= (int)$a['id'] ?>">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($a['cliente'] ?: 'Público general') ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11.5px">
                cobrado <?= D::pesos($a['cobrado']) ?> de <?= D::pesos($a['total']) ?></small>
            </span>
            <b class="lf-mono" style="font-size:13.5px;color:var(--lf-amb)">
              <?= D::pesos($a['pendiente']) ?></b>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        Cobrar estas ventas es lo que libera esa comisión. Es la lista corta de
        a quién perseguir si el equipo quiere cobrar su parte.
      </div>
    </section>
    <?php endif; ?>
</div>

<style>
/* Las ventanas flotantes ya NO viven aquí: se movieron a libertyfin.css
   para que cualquier sección pueda abrir una. Estaban aquí porque la
   hoja global se guardaba con `?v=2` y un cambio no se veía hasta
   vaciar la caché; eso ya se arregló —ahora la versión sale de la fecha del
   archivo— así que el motivo desapareció. */
/* Ventana flotante por colaborador.
   Vive en <body>, fuera de la rejilla: no mueve nada de lo que hay debajo.
   z-index 200-290: encima de menú y velos móviles (<=60), debajo de los
   modales (300) y de la guía (9000). */
.lf-pers[data-id]{cursor:pointer;transition:border-color .15s,background .15s}
.lf-pers[data-id]:hover{border-color:var(--lf-brand)}
.lf-pers[data-id]:focus-visible{outline:2px solid var(--lf-brand);outline-offset:2px}
.lf-pers.abierta{border-color:var(--lf-brand);background:var(--lf-brand-glow)}

</style>

<script>
/* Ventanas flotantes por colaborador.
   Se arrastran desde la barra, se minimizan, se cierran y se redimensionan
   desde las esquinas de abajo. Pueden abrirse varias a la vez. Viven en
   <body>, así que no alteran la rejilla.
   El contenido se reemplaza al navegar sin recargar y este script se
   vuelve a ejecutar: la guarda evita oyentes duplicados. */
(function () {
  if (window.__lfComWin) return;
  window.__lfComWin = true;

  var ventanas = [];       /* orden de apilado: la última es la de arriba */
  var MIN_W = 320, MIN_H = 180;
  var ICO_MIN = '<svg viewBox="0 0 16 16"><path d="M3.5 8h9"/></svg>';
  var ICO_MAX = '<svg viewBox="0 0 16 16"><rect x="3.5" y="3.5" width="9" height="9" rx="1.5"/></svg>';
  var ICO_BUSCAR = '<svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
  var ICO_X   = '<svg viewBox="0 0 16 16"><path d="M4 4l8 8M12 4l-8 8"/></svg>';

  function esc(t) {
    return String(t).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fecha(iso) {
    var m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    var p = iso.split('-');
    return parseInt(p[2], 10) + ' ' + m[parseInt(p[1], 10) - 1] + ' ' + p[0];
  }
  function limitar(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function alFrente(w) {
    var i = ventanas.indexOf(w);
    if (i > -1) ventanas.splice(i, 1);
    ventanas.push(w);
    ventanas.forEach(function (x, n) {
      x.style.zIndex = 200 + n;
      x.classList.toggle('activa', x === w);
    });
  }

  function marcarTarjeta(w, abierta) {
    var c = w._card;
    if (!c || !document.contains(c)) return;
    var otras = ventanas.some(function (x) { return x !== w && x._card === c; });
    c.classList.toggle('abierta', abierta || otras);
  }

  function cerrar(w) {
    var i = ventanas.indexOf(w);
    if (i > -1) ventanas.splice(i, 1);
    w.remove();
    marcarTarjeta(w, false);
    if (ventanas.length) alFrente(ventanas[ventanas.length - 1]);
  }

  function minimizar(w, forzar) {
    var mini = (forzar === undefined) ? !w.classList.contains('mini') : forzar;
    w.classList.toggle('mini', mini);
    /* Una ventana restaurada por el layout trae `.mini` en vez de
       `.minimizar`: ese botón no existe y las tres líneas de abajo
       reventaban. Se envuelven las tres, no solo la primera. */
    var b = w.querySelector('.minimizar');
    if (b) {
      b.innerHTML = mini ? ICO_MAX : ICO_MIN;
      b.title = mini ? 'Restaurar' : 'Minimizar';
      b.setAttribute('aria-label', b.title);
    }
    if (!mini) acomodar(w);
  }

  /* Que ninguna ventana quede con la barra fuera de la pantalla. */
  function acomodar(w) {
    var r = w.getBoundingClientRect();
    w.style.left = limitar(r.left, 4 - r.width + 120, innerWidth - 120) + 'px';
    w.style.top  = limitar(r.top, 4, innerHeight - 44) + 'px';
  }

  function urlDe(w, p) {
    var b = w._base;
    var q = new URLSearchParams({ desde: b.desde, hasta: b.hasta,
      nombre: b.nombre, equipo: b.equipo, p: p || 1 });
    if (w._q) q.set('q', w._q);
    return '/comisiones/colaborador/' + b.id + '?' + q;
  }

  function cargar(w, url) {
    /* Si se sigue escribiendo, la petición anterior ya no sirve. */
    if (w._ac) w._ac.abort();
    w._ac = window.AbortController ? new AbortController() : null;
    w.classList.add('cargando');
    /* Se apunta ANTES de pedirlo, no solo si falla. El módulo compartido
       lo necesita para volver a abrir la ventana donde iba después de
       una recarga; sin esto, la ventana de un colaborador no sobrevivía
       a cambiar de sección. */
    w.dataset.url = url;
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' },
                 signal: w._ac ? w._ac.signal : undefined })
      .then(function (r) {
        if (r.redirected) { location.reload(); throw 0; }     /* sesión vencida */
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var cu = w.querySelector('.lf-win-cuerpo');
        cu.innerHTML = html;
        cu.scrollTop = 0;
        w.classList.remove('cargando');
      })
      .catch(function (e) {
        if (e === 0 || (e && e.name === 'AbortError')) return;
        w.classList.remove('cargando');
        w.dataset.url = url;
        w.querySelector('.lf-win-cuerpo').innerHTML =
          '<p class="lf-win-msj">No se pudo cargar el detalle. ' +
          '<a href="#" data-reintentar>Reintentar</a></p>';
      });
  }

  function abrir(card) {
    var cont = card.closest('.lf-equipo');
    var llave = [card.dataset.id, card.dataset.nombre, card.dataset.equipo,
                 cont.dataset.desde, cont.dataset.hasta].join('|');

    /* La misma persona y periodo: se trae al frente en vez de duplicarla.
       Se busca en el DOCUMENTO y no solo en la lista propia: tras una
       recarga la ventana la vuelve a poner el módulo compartido del
       layout, y esa nunca pasó por aquí. Sin esto saldría una segunda
       ventana del mismo colaborador encima de la primera. */
    var previa = null;
    [].forEach.call(document.querySelectorAll('.lf-win'), function (x) {
      if (previa) return;
      if (x.dataset.llave === llave) previa = x;
      else if ((x.dataset.url || '')
               .indexOf('/comisiones/colaborador/' + card.dataset.id + '?') === 0) previa = x;
    });
    if (previa) {
      /* Si venía de fuera, se adopta: `alFrente` ordena esta lista y
         con un elemento que no está en ella haría un splice(-1). */
      if (ventanas.indexOf(previa) === -1) ventanas.push(previa);
      previa.dataset.llave = llave;
      previa._card = card;
      minimizar(previa, false);
      alFrente(previa);
      return;
    }

    var w = document.createElement('div');
    w.className = 'lf-win';
    w.dataset.llave = llave;
    w.setAttribute('role', 'dialog');
    w.setAttribute('aria-label', 'Ventas de ' + card.dataset.nombre);
    w._card = card;
    w.innerHTML =
      '<div class="lf-win-bar">' +
        '<div class="lf-win-tit"><b>' + esc(card.dataset.nombre) + '</b>' +
        '<small>' + esc(card.dataset.equipo ? card.dataset.equipo + ' · ' : '') +
          fecha(cont.dataset.desde) + ' – ' + fecha(cont.dataset.hasta) + '</small></div>' +
        '<div class="lf-win-btns">' +
          '<button type="button" class="minimizar" title="Minimizar" aria-label="Minimizar">' + ICO_MIN + '</button>' +
          '<button type="button" class="cerrar" title="Cerrar" aria-label="Cerrar">' + ICO_X + '</button>' +
        '</div>' +
      '</div>' +
      '<div class="lf-win-busca"><div class="lf-search">' + ICO_BUSCAR +
        '<input class="form-control form-control-sm" type="search" autocomplete="off" ' +
        'placeholder="Buscar cliente, folio, área, fecha o monto…" aria-label="Buscar en las ventas">' +
      '</div></div>' +
      '<div class="lf-win-cuerpo"><p class="lf-win-msj">Cargando…</p></div>' +
      '<span class="lf-win-grip sw" data-grip="sw"></span>' +
      '<span class="lf-win-grip se" data-grip="se"></span>';

    /* Tamaño y posición inicial: en cascada para que varias no se tapen. */
    var ancho = Math.min(720, innerWidth - 16);
    var n = ventanas.length % 8;
    w.style.width = ancho + 'px';
    w.style.left = limitar((innerWidth - ancho) / 2 + n * 28, 4, Math.max(4, innerWidth - ancho - 4)) + 'px';
    w.style.top  = limitar(90 + n * 28, 4, Math.max(4, innerHeight - 200)) + 'px';

    document.body.appendChild(w);
    alFrente(w);
    card.classList.add('abierta');

    w._base = { id: card.dataset.id, desde: cont.dataset.desde, hasta: cont.dataset.hasta,
                nombre: card.dataset.nombre, equipo: card.dataset.equipo };
    w._q = '';
    cargar(w, urlDe(w, 1));
  }

  /* ── Arrastrar desde la barra ── */
  function empezarMover(ev, w) {
    var r = w.getBoundingClientRect();
    var dx = ev.clientX - r.left, dy = ev.clientY - r.top;
    w.classList.add('moviendo');
    function mover(e) {
      w.style.left = limitar(e.clientX - dx, 120 - w.offsetWidth, innerWidth - 120) + 'px';
      w.style.top  = limitar(e.clientY - dy, 4, innerHeight - 44) + 'px';
    }
    function soltar() {
      w.classList.remove('moviendo');
      document.removeEventListener('pointermove', mover);
      document.removeEventListener('pointerup', soltar);
      document.removeEventListener('pointercancel', soltar);
    }
    document.addEventListener('pointermove', mover);
    document.addEventListener('pointerup', soltar);
    document.addEventListener('pointercancel', soltar);
  }

  /* ── Redimensionar desde las esquinas de abajo ── */
  function empezarRedim(ev, w, lado) {
    var r = w.getBoundingClientRect();
    var x0 = ev.clientX, y0 = ev.clientY;
    w.classList.add('redim');
    w.style.height = r.height + 'px';
    function mover(e) {
      var h = limitar(r.height + (e.clientY - y0), MIN_H, innerHeight - r.top - 4);
      var wd;
      if (lado === 'se') {
        wd = limitar(r.width + (e.clientX - x0), MIN_W, innerWidth - r.left - 4);
      } else {                               /* sw: crece hacia la izquierda */
        wd = limitar(r.width - (e.clientX - x0), MIN_W, r.right - 4);
        w.style.left = (r.right - wd) + 'px';
      }
      w.style.width = wd + 'px';
      w.style.height = h + 'px';
    }
    function soltar() {
      w.classList.remove('redim');
      document.removeEventListener('pointermove', mover);
      document.removeEventListener('pointerup', soltar);
      document.removeEventListener('pointercancel', soltar);
    }
    document.addEventListener('pointermove', mover);
    document.addEventListener('pointerup', soltar);
    document.addEventListener('pointercancel', soltar);
  }

  document.addEventListener('pointerdown', function (ev) {
    var w = ev.target.closest('.lf-win');
    if (!w) return;
    alFrente(w);
    if (ev.button !== 0) return;
    var grip = ev.target.closest('.lf-win-grip');
    if (grip) { ev.preventDefault(); empezarRedim(ev, w, grip.dataset.grip); return; }
    if (ev.target.closest('.lf-win-btns')) return;
    if (ev.target.closest('.lf-win-bar')) { ev.preventDefault(); empezarMover(ev, w); }
  });

  document.addEventListener('dblclick', function (ev) {
    var bar = ev.target.closest('.lf-win-bar');
    if (bar && !ev.target.closest('.lf-win-btns')) minimizar(bar.closest('.lf-win'));
  });

  document.addEventListener('click', function (ev) {
    var w = ev.target.closest('.lf-win');
    if (w) {
      if (ev.target.closest('.cerrar')) { cerrar(w); return; }
      if (ev.target.closest('.minimizar')) { minimizar(w); return; }
      var re = ev.target.closest('[data-reintentar]');
      if (re) { ev.preventDefault(); cargar(w, w.dataset.url); return; }
      var pag = ev.target.closest('.page-item a');
      if (pag) {
        ev.preventDefault();
        if (!pag.closest('.page-item.disabled')) cargar(w, pag.getAttribute('href'));
      }
      return;          /* los enlaces de venta funcionan normal */
    }
    var card = ev.target.closest('.lf-pers[data-id]');
    if (card) abrir(card);
  });

  /* Búsqueda: espera a que se deje de escribir y consulta en el servidor,
     para que cubra TODAS las ventas y no solo las de la página visible. */
  document.addEventListener('input', function (ev) {
    var inp = ev.target.closest && ev.target.closest('.lf-win-busca input');
    if (!inp) return;
    var w = inp.closest('.lf-win');
    clearTimeout(w._t);
    w._t = setTimeout(function () {
      var q = inp.value.replace(/\s+/g, ' ').trim();
      if (q === w._q) return;
      w._q = q;
      cargar(w, urlDe(w, 1));
    }, 300);
  });

  document.addEventListener('keydown', function (ev) {
    var w = ev.target.closest && ev.target.closest('.lf-win');
    if (w && ev.key === 'Enter' && ev.target.closest('.lf-win-busca input')) {
      ev.preventDefault();
      clearTimeout(w._t);
      w._q = ev.target.value.replace(/\s+/g, ' ').trim();
      cargar(w, urlDe(w, 1));
      return;
    }
    /* Esc en el buscador: primero limpia; con el campo vacío, cierra. */
    if (w && ev.key === 'Escape') {
      var campo = ev.target.closest('.lf-win-busca input');
      if (campo && campo.value !== '') {
        campo.value = ''; clearTimeout(w._t); w._q = ''; cargar(w, urlDe(w, 1));
      } else { cerrar(w); }
      return;
    }
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    var card = ev.target.closest && ev.target.closest('.lf-pers[data-id]');
    if (card && ev.target === card) { ev.preventDefault(); abrir(card); }
  });

  /* Si la pantalla cambia de tamaño, ninguna ventana se pierde fuera. */
  window.addEventListener('resize', function () {
    ventanas.forEach(function (w) {
      w.style.width = Math.min(w.offsetWidth, innerWidth - 8) + 'px';
      acomodar(w);
    });
  });

  /* ANTES SE CERRABAN AL CAMBIAR DE SECCIÓN. Ya no.
     La idea era que una ventana de Comisiones flotando sobre Ventas
     confundía, y cuando las ventanas solo existían aquí tenía sentido.
     Resultó al revés: tener las ventas de un colaborador abiertas
     MIENTRAS se trabaja en otra sección es justo para lo que sirve una
     ventana que se mueve. Cerrarlas era tirar el trabajo de quien las
     había abierto para eso.
     Ahora persisten: el módulo compartido del layout las adopta, las
     apunta y las vuelve a abrir si se recarga. Lo único que queda por
     hacer aquí es soltar la tarjeta, que ya no está en el documento. */
  var cont = document.querySelector('.lf-cont');
  if (cont && window.MutationObserver) {
    new MutationObserver(function () {
      if (document.querySelector('.lf-equipo')) return;
      ventanas.forEach(function (w) { w._card = null; });
    }).observe(cont, { childList: true });
  }
})();
</script>
