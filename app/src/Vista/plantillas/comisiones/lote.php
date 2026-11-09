<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];

// Por qué una venta no se puede comisionar en lote, sin importar a quién.
// Las que dependen de a quién se elija (ya tiene comisión de esa persona,
// no tiene especialista) las marca el script al cambiar la elección.
$motivoFijo = function ($v) {
    return $v['lineas'] ? '' : 'No tiene productos';
};

$listas  = array_filter($ventas, function ($v) use ($motivoFijo) { return $motivoFijo($v) === ''; });
$volver  = '/comisiones?' . http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$hayVarios = (bool)array_filter($ventas, function ($v) { return count($v['lineas']) > 1; });

// Para el "En" de cada persona: las áreas y los productos que hay en esta
// lista. Así "Ana en Contabilidad, Juan en Legal" sale en una sola pasada.
$alcAreas = []; $alcProds = [];
foreach ($ventas as $v) {
    foreach ($v['lineas'] as $l) {
        $alcAreas[mb_strtolower(trim($l['area']))] = trim($l['area']);
        if ($l['producto_id'] > 0) $alcProds[$l['producto_id']] = $l['producto'];
    }
}
asort($alcAreas); asort($alcProds);
$clave = function ($area) { return mb_strtolower(trim((string)$area)); };

// Ventas de antes del periodo que tuvieron cobros en él (abonos, liquidaciones).
$mesDe = function ($fecha) {
    $m = ['','enero','febrero','marzo','abril','mayo','junio','julio',
          'agosto','septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($fecha);
    return $m[(int)date('n', $t)] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
};
$nAnt = count(array_filter($ventas, function ($v) { return !empty($v['anterior']); }));
$nDel = count($ventas) - $nAnt;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($resultado): $om = $resultado['omitidas']; ?>
<section class="card lf-lote-res">
  <header class="card-header">
    <div>
      <?php if ($resultado['hechas'] > 0): ?>
        <span><?= (int)$resultado['hechas'] ?> comisi<?= $resultado['hechas'] === 1 ? 'ón asignada' : 'ones asignadas' ?><?php
          if (($resultado['ventas'] ?? $resultado['hechas']) !== $resultado['hechas']): ?>
          en <?= (int)$resultado['ventas'] ?> venta<?= $resultado['ventas'] === 1 ? '' : 's' ?><?php endif; ?></span>
        <p>Suman <b><?= D::pesos($resultado['monto']) ?></b> · <?= P::e($resultado['reparto'] ?? '') ?>.
          Ya se ven en Comisiones y en los reportes.</p>
      <?php else: ?>
        <span>No se asignó ninguna comisión</span>
        <p>Ninguna de las ventas marcadas cumplía las reglas. Abajo está el motivo de cada una.</p>
      <?php endif; ?>
    </div>
    <?php if ($om): ?><span class="badge bg-warning"><?= count($om) ?> sin asignar</span><?php endif; ?>
  </header>
  <?php if ($om): ?>
  <ul class="lf-lote-omit">
    <?php foreach (array_slice($om, 0, 50) as $o): ?>
      <li><b class="lf-mono"><?= P::e($o[0]) ?></b><span><?= P::e($o[1]) ?></span></li>
    <?php endforeach; ?>
    <?php if (count($om) > 50): ?><li class="mas">y <?= count($om) - 50 ?> más</li><?php endif; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<a class="lf-lote-atras" href="<?= P::e($volver) ?>">&larr; Volver a Comisiones</a>

<?php /* ═══════════ 1 · QUÉ VENTAS ═══════════ */ ?>
<section class="card">
  <header class="card-header">
    <div><span><b class="lf-lote-paso">1</b>Qué ventas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Por fecha de venta, igual que en Comisiones</p></div>
  </header>
  <form class="lf-lote-filtros" method="get" action="/comisiones/lote">
    <label>Desde<input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>"></label>
    <label>Hasta<input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>"></label>
    <label>Área
      <select class="form-select form-select-sm" name="area">
        <option value="">Todas</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= P::e($a['nombre']) ?>" <?= mb_strtolower($a['nombre']) === mb_strtolower($area) ? 'selected' : '' ?>><?= P::e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Especialista
      <select class="form-select form-select-sm" name="esp">
        <option value="">Cualquiera</option>
        <option value="sin" <?= $esp === 'sin' ? 'selected' : '' ?>>Sin especialista</option>
        <?php foreach ($equipo as $nomArea => $gente): ?>
          <optgroup label="<?= P::e($nomArea) ?>">
            <?php foreach ($gente as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (string)(int)$c['id'] === $esp ? 'selected' : '' ?>><?= P::e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="lf-lote-chk">
      <input type="hidden" name="solo" value="0">
      <input type="checkbox" name="solo" value="1" <?= $solo ? 'checked' : '' ?>> Solo las que no tienen comisión
    </label>
    <label class="lf-lote-chk" title="Abonos y liquidaciones de este periodo a ventas de meses anteriores">
      <input type="hidden" name="ant" value="0">
      <input type="checkbox" name="ant" value="1" <?= $ant ? 'checked' : '' ?>> Incluir ventas anteriores con cobros en el periodo
    </label>
    <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
  </form>
</section>

<?php if (!$equipo): ?>
<div class="alert alert-warning">
  <?= W::icono('alerta','18px') ?><span>Todavía no hay colaboradores activos. Dalos de alta en Ajustes para poder asignarles comisiones.</span>
</div>
<?php else: ?>

<form method="post" action="/comisiones/lote" id="loteForm">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="desde" value="<?= P::e($desde) ?>">
  <input type="hidden" name="hasta" value="<?= P::e($hasta) ?>">
  <input type="hidden" name="area"  value="<?= P::e($area) ?>">
  <input type="hidden" name="esp"   value="<?= P::e($esp) ?>">
  <input type="hidden" name="solo"  value="<?= $solo ? '1' : '0' ?>">
  <input type="hidden" name="ant"   value="<?= $ant ? '1' : '0' ?>">

  <?php /* ═══════════ 2 · A QUIÉN Y CUÁNTO ═══════════ */ ?>
  <section class="card">
    <header class="card-header">
      <div><span><b class="lf-lote-paso">2</b>A quién y cuánto</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cada quien con su porcentaje y en qué productos, sobre la base comisionable de cada uno (sin IVA, sin costo y sin gastos), igual que al asignar desde la venta</p></div>
    </header>
    <div class="lf-lote-quienes" id="loteQuienes">
      <?php /* Una tarjeta por persona. Tocarla la selecciona y la lista de
               abajo pasa a ser la de SUS ventas: ahí se marcan o desmarcan
               las que le tocan, cuando sea, aunque ya haya más personas. */ ?>
      <div class="lf-lote-quien" data-fila>
       <div class="campos">
        <label class="quien">Colaborador
          <select class="form-select" name="colaborador[]" required>
            <option value="">Elegir…</option>
            <option value="esp">Al especialista de cada venta</option>
            <?php foreach ($equipo as $nomArea => $gente): ?>
              <optgroup label="<?= P::e($nomArea) ?>">
                <?php foreach ($gente as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"
                          data-pct="<?= isset($sugeridos[$c['id']]) ? P::e(rtrim(rtrim(number_format($sugeridos[$c['id']], 4, '.', ''), '0'), '.')) : '' ?>"><?= P::e($c['nombre']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="pct">Porcentaje
          <span class="lf-lote-pct">
            <input class="form-control" type="number" name="porcentaje[]"
                   min="0.0001" max="100" step="0.0001" inputmode="decimal" placeholder="10" required>
            <i>%</i>
          </span>
        </label>
        <label class="en">En
          <select class="form-select" name="en[]">
            <option value="">Todos los productos marcados</option>
            <?php if (count($alcAreas) > 1): ?>
              <optgroup label="Solo el área">
                <?php foreach ($alcAreas as $a): ?>
                  <option value="a:<?= P::e($a) ?>"><?= P::e($a) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            <?php if (count($alcProds) > 1): ?>
              <optgroup label="Solo el producto">
                <?php foreach ($alcProds as $pid => $pn): ?>
                  <option value="p:<?= (int)$pid ?>"><?= P::e($pn) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </label>
        <button type="button" class="lf-lote-quitar" data-quitar aria-label="Quitar este colaborador" hidden><span class="lf-signo">&times;</span></button>
       </div>
       <div class="pie">
         <span data-cuenta>Elige a alguien para ver en cuántas ventas va</span>
         <button type="button" class="lf-lote-elegir" data-elegir>Elegir sus ventas</button>
       </div>
        <?php /* Clave de la fila: con ella cada venta dice a quiénes de aquí lleva. */ ?>
        <input type="hidden" name="fila[]" value="1">
      </div>
    </div>
    <div class="lf-lote-mas">
      <button type="button" class="btn btn-secondary btn-sm" id="loteMas"><span class="lf-signo">+</span> Agregar colaborador</button>
      <span id="loteSuma"></span>
    </div>
  </section>

  <?php /* ═══════════ 3 · REVISAR Y ASIGNAR ═══════════ */ ?>
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div><span><b class="lf-lote-paso">3</b>Revisa y asigna</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          <?= $nDel ?> venta<?= $nDel === 1 ? '' : 's' ?> del periodo<?php if ($nAnt): ?>
          + <?= $nAnt ?> de antes con abonos o liquidaciones en el periodo<?php endif; ?>
          · las que no se pueden salen sin marcar y con el motivo
          · toca la tarjeta de una persona (arriba) para elegir solo sus ventas
          <?php if ($hayVarios): ?>
            · con varios productos, marca a cuáles va la comisión (vienen marcados <?= $area !== '' ? 'los de ' . P::e($area) : 'todos' ?>); cada uno sobre su propia base
          <?php endif; ?></p></div>
      <span class="badge bg-secondary" id="loteCuenta"><?= count($listas) ?> marcadas</span>
    </header>

    <?php if ($cortado): ?>
      <p class="lf-lote-tope">Se muestran las primeras <?= (int)$tope ?>. Acorta el periodo o filtra por área para ver las demás.</p>
    <?php endif; ?>

    <?php if (!$ventas): ?>
      <p class="lf-lote-vacio">No hay ventas con esos filtros<?= $solo ? ' que no tengan comisión' : '' ?>.</p>
    <?php else: ?>
    <?php /* Se ve mientras hay una persona seleccionada: la lista es la suya. */ ?>
    <div class="lf-lote-modo" id="lotePersona" hidden>
      <span id="lotePersonaT"></span>
      <button type="button" class="btn btn-primary btn-sm" id="lotePersonaOk">Listo</button>
    </div>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover lf-lote-tabla">
        <thead><tr>
          <th style="width:34px"><input type="checkbox" id="loteTodas" aria-label="Marcar todas" checked></th>
          <th>Venta</th><th>Área</th><th>Especialista</th>
          <th class="text-end">Total</th><th>Comisiones</th><th>Estado</th>
        </tr></thead>
        <tbody>
        <?php foreach ($ventas as $v):
          $fijo  = $motivoFijo($v);
          $nLin  = count($v['lineas']); ?>
          <tr data-n="<?= $nLin ?>" data-esp="<?= (int)$v['especialista_id'] ?>"
              <?php /* Con un solo producto no hay qué elegir: quién ya comisiona va aquí. */ ?>
              <?php if ($nLin === 1): $l1 = $v['lineas'][0]; ?>data-con="<?= P::e($l1['con']) ?>"
                data-linea="<?= (int)$l1['id'] ?>" data-area="<?= P::e($clave($l1['area'])) ?>"
                data-pid="<?= (int)$l1['producto_id'] ?>"<?php endif; ?>
              data-fijo="<?= P::e($fijo) ?>" class="<?= $fijo ? 'no' : '' ?>">
            <td data-label="Incluir">
              <?php /* La casilla que se ve: la venta para todos, o para la persona
                       seleccionada. Lo que se envía lo calcula el script: la
                       venta va (`ventas[]`) si alguien quedó en ella. */ ?>
              <input type="checkbox" class="lf-lote-sel"
                     aria-label="Incluir <?= P::e($v['codigo_venta']) ?>"
                     <?= $fijo ? 'disabled' : 'checked' ?>>
              <input type="hidden" name="ventas[]" value="<?= (int)$v['id'] ?>" <?= $fijo ? 'disabled' : '' ?>>
            </td>
            <td data-label="Venta">
              <a href="/ventas/<?= (int)$v['id'] ?>" data-modal style="font-weight:600;color:var(--lf-tinta)">
                <?= P::e($v['cliente'] ?: 'Público general') ?></a>
              <span class="lf-lote-sub"><?= P::e($v['codigo_venta']) ?> · <?= date('d/m/Y', strtotime($v['fecha'])) ?>
                <?php if ($nLin === 1): ?> · <?= P::e($v['lineas'][0]['producto']) ?><?php endif; ?></span>
              <?php if (!empty($v['anterior'])):
                // Venta de antes del periodo: qué se le cobró en él.
                $nCob = (int)$v['cobros_periodo']; ?>
                <span class="lf-lote-ant">
                  <b>Venta de <?= P::e($mesDe($v['fecha'])) ?></b>
                  <?= $v['liquido_periodo'] ? 'liquidó' : ($nCob === 1 ? 'abonó' : $nCob . ' abonos:') ?>
                  <?= D::pesos($v['cobrado_periodo']) ?> el <?= date('d/m', strtotime($v['ultimo_cobro_periodo'])) ?>
                </span>
              <?php endif; ?>
              <?php if ($nLin > 1): ?>
                <?php /* A qué productos va la comisión. Sin nadie seleccionado,
                         las casillas son las de TODOS (vienen marcados todos, o
                         los del área si se filtró por área). Con una persona
                         seleccionada, son las de ELLA: Ana en uno y Juan en otro.
                         No llevan `name`: lo que se envía, por persona, lo arma
                         el script (lp[venta][fila][]). */ ?>
                <ul class="lf-lote-prods">
                  <?php foreach ($v['lineas'] as $l): $fuera = !in_array($l['id'], $v['objetivo'], true); ?>
                    <li class="<?= $fuera ? 'fuera' : '' ?>">
                      <label>
                        <input type="checkbox" data-prod
                               value="<?= (int)$l['id'] ?>" data-con="<?= P::e($l['con']) ?>"
                               data-area="<?= P::e($clave($l['area'])) ?>" data-pid="<?= (int)$l['producto_id'] ?>"
                               <?= $fuera ? '' : 'checked' ?>>
                        <span><?= P::e($l['producto']) ?>
                          <small><?= P::e($l['area']) ?><?= $fuera ? ' · otra área' : '' ?><?=
                            $l['comisiones'] !== '' ? ' · ' . P::e($l['comisiones']) : '' ?></small>
                          <?php /* Quién va en este producto (lo pinta el script). */ ?>
                          <em class="quien"></em></span>
                      </label>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
            <td data-label="Área"><span class="badge bg-secondary"><?= P::e($v['area']) ?></span></td>
            <td data-label="Especialista"><?= $v['especialista'] !== '' ? P::e($v['especialista'])
                : '<span class="lf-lote-sub" style="display:inline">Sin especialista</span>' ?></td>
            <td data-label="Total" class="text-end lf-mono"><?= D::pesos($v['total']) ?></td>
            <td data-label="Comisiones"><?= $v['comisiones'] ? P::e($v['comisiones'])
                : '<span class="lf-lote-sub" style="display:inline">Ninguna</span>' ?></td>
            <td data-label="Estado">
              <div class="lf-lote-estado">
                <span class="lf-lote-motivo"><?= $fijo ? P::e($fijo) : 'Lista' ?></span>
                <?php /* Con dos o más personas, quiénes van en esta venta. Solo se lee; lo pinta el script. */ ?>
                <div class="lf-lote-para" hidden></div>
                <?php /* Lo que se envía de "a quiénes": lo llena el script. */ ?>
                <div class="lf-lote-datos" hidden></div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <footer class="lf-lote-pie">
      <p id="loteResumen">Marca las ventas, elige a quién y el porcentaje.</p>
      <button class="btn btn-primary" type="submit" id="loteOk">Asignar comisiones</button>
    </footer>
    <?php endif; ?>
  </section>
</form>

<div class="lf-modal" id="loteConf" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="loteConfT">
    <header>
      <div>
        <h2 id="loteConfT">¿Asignar estas comisiones?</h2>
        <p>Se asignan una por una con las mismas reglas que desde la venta. Si alguna no se puede, las demás sí quedan.</p>
      </div>
      <button type="button" class="cerrar" data-lote-cerrar aria-label="Cerrar"><span class="lf-signo">&times;</span></button>
    </header>
    <div class="cuerpo"><dl class="lf-conf-datos" id="loteConfD"></dl></div>
    <footer>
      <button type="button" class="btn btn-secondary" data-lote-cerrar>Revisar</button>
      <button type="button" class="btn btn-primary" id="loteConfOk">Sí, asignar</button>
    </footer>
  </div>
</div>
<?php endif; ?>

<script>
/* Asignar en lote.

   Varias personas a la vez, cada una con su porcentaje y su alcance (En).
   Cada venta lleva a algunas de ellas; eso se guarda por venta y por
   persona, y se edita de dos formas:

   · Sin nadie seleccionado, la casilla de cada venta la pone o la quita
     para TODOS.
   · Al tocar la tarjeta de una persona, la lista pasa a ser la SUYA: la
     casilla dice si esa venta le toca a ella, y se marca o desmarca sin
     tocar a los demás. Se puede volver a cualquier persona cuando sea.

   Lo que una persona ya comisiona no se le vuelve a dar, "al especialista"
   no aplica donde no hay, nadie va dos veces en el mismo producto y en un
   producto no se pasa de 100%. Lo que se ve marcado es lo que se asigna.

   La página se reemplaza al navegar sin recargar y este script vuelve a
   correr: todo se engancha a los elementos de esta página, que son
   nuevos cada vez. */
(function () {
  var form = document.getElementById('loteForm');
  if (!form) return;
  var caja   = document.getElementById('loteQuienes');
  var mas    = document.getElementById('loteMas');
  var suma   = document.getElementById('loteSuma');
  var todas  = document.getElementById('loteTodas');
  var cuenta = document.getElementById('loteCuenta');
  var resum  = document.getElementById('loteResumen');
  var conf   = document.getElementById('loteConf');
  var modo   = document.getElementById('lotePersona');
  var modoT  = document.getElementById('lotePersonaT');
  var tabla  = form.querySelector('.lf-lote-tabla');
  var filas  = [].slice.call(form.querySelectorAll('tbody tr'));
  var MAX    = 10;
  var confirmado = false;

  /* Quién va en qué venta.
       'venta:clave' -> true  la persona NO va en esa venta
                        false la persona SÍ va, aunque la venta se haya quitado
       'venta:*'     -> true  la venta se quitó para todos (y para quien se agregue)
     Sin nada anotado, todos van. */
  var fuera = {};
  // La persona cuyas ventas se están eligiendo (su clave), o null.
  var activa = null;
  // Clave de la siguiente fila. No se reutiliza al quitar una fila, para
  // que lo anotado de una persona no pase a otra.
  var sig = 1;
  [].forEach.call(caja.querySelectorAll('input[name="fila[]"]'), function (i) {
    sig = Math.max(sig, +i.value || 0);
  });

  /* ══ Las personas ══ */
  function renglones() { return [].slice.call(caja.querySelectorAll('[data-fila]')); }
  function claveDe(r)  { return r.querySelector('input[name="fila[]"]').value; }

  /* [{q: 'esp' | id, pct, k, nombre, en, enTexto}] de las filas con alguien
     elegido. `en` es '' (todos), 'a:Área' o 'p:id de producto'. Durante
     `revisar` se lee una vez y se reutiliza. */
  var QS = null;
  function leerQuienes() {
    return renglones().map(function (r) {
      var s = r.querySelector('select[name="colaborador[]"]'),
          p = r.querySelector('input[name="porcentaje[]"]'),
          e = r.querySelector('select[name="en[]"]');
      return { q: s.value, pct: parseFloat(p.value), k: claveDe(r),
               nombre: s.value ? s.options[s.selectedIndex].text : '',
               corto: s.value === 'esp' ? 'Especialista' : (s.value ? s.options[s.selectedIndex].text : ''),
               en: e.value, enTexto: e.value ? e.options[e.selectedIndex].text : '' };
    }).filter(function (x) { return x.q; });
  }
  function quienes() { return QS || leerQuienes(); }
  function persona(k) {
    var qs = quienes();
    for (var i = 0; i < qs.length; i++) if (qs[i].k === k) return qs[i];
    return null;
  }

  function pintarRenglones() {
    var rs = renglones(), vistos = {}, total = 0, todosEnTodo = true;
    rs.forEach(function (r) {
      var s = r.querySelector('select[name="colaborador[]"]'), e = r.querySelector('select[name="en[]"]');
      var p = parseFloat(r.querySelector('input[name="porcentaje[]"]').value);
      r.querySelector('[data-quitar]').hidden = rs.length < 2;
      // La misma persona en lo mismo dos veces es un error de captura. En
      // cosas distintas sí se vale: Juan 10% en Legal y 5% en Contabilidad.
      var k = s.value + '|' + e.value;
      s.setCustomValidity(s.value && vistos[k] ? 'Ya está en otra fila con lo mismo' : '');
      if (s.value) vistos[k] = true;
      if (e.value) todosEnTodo = false;
      if (p > 0) total += p;
      var sel = activa === claveDe(r);
      r.classList.toggle('activa', sel);
      r.querySelector('[data-elegir]').textContent = sel ? 'Listo' : 'Elegir sus ventas';
      r.querySelector('[data-elegir]').disabled = !s.value;
    });
    mas.disabled = rs.length >= MAX;
    // A 4 decimales, como se guardan: a 2, 33.3334 × 3 decía "100%" y el
    // servidor lo rechazaba por 100.0002.
    total = Math.round(total * 10000) / 10000;
    // La suma solo dice algo si todos van a todo; si no, se revisa
    // producto por producto en la lista.
    suma.textContent = rs.length > 1 && total && todosEnTodo
      ? 'Suman ' + total + '%' + (total > 100 ? ': no puede pasar de 100%' : '') : '';
    suma.className = todosEnTodo && total > 100 ? 'mal' : '';
  }

  function seleccionar(k) {
    activa = k && persona(k) ? k : null;
    pintarRenglones();
    revisar();
  }

  mas.addEventListener('click', function () {
    var rs = renglones();
    if (rs.length >= MAX) return;
    var nuevo = rs[0].cloneNode(true);
    [].forEach.call(nuevo.querySelectorAll('select'), function (s) { s.value = ''; s.setCustomValidity(''); });
    nuevo.querySelector('input[name="porcentaje[]"]').value = '';
    nuevo.querySelector('input[name="fila[]"]').value = String(++sig);
    nuevo.classList.remove('activa');
    caja.appendChild(nuevo);
    pintarRenglones();
    revisar();
    nuevo.querySelector('select').focus();
  });

  caja.addEventListener('click', function (e) {
    var r = e.target.closest('[data-fila]');
    if (!r) return;
    if (e.target.closest('[data-quitar]')) {
      if (renglones().length < 2) return;
      if (activa === claveDe(r)) activa = null;
      r.remove();
      pintarRenglones();
      revisar();
      return;
    }
    if (e.target.closest('[data-elegir]')) {
      seleccionar(activa === claveDe(r) ? null : claveDe(r));
      return;
    }
    // Tocar la tarjeta (fuera de sus campos) también la selecciona.
    if (e.target.closest('select, input, label, button')) return;
    if (!r.querySelector('select[name="colaborador[]"]').value) return;
    seleccionar(activa === claveDe(r) ? null : claveDe(r));
  });

  caja.addEventListener('change', function (e) {
    if (e.target.name === 'colaborador[]') {
      // El porcentaje que esa persona suele cobrar, si no hay uno escrito.
      var o = e.target.options[e.target.selectedIndex];
      var p = e.target.closest('[data-fila]').querySelector('input[name="porcentaje[]"]');
      if (o && o.dataset.pct && !p.value) p.value = o.dataset.pct;
      if (!e.target.value && activa === claveDe(e.target.closest('[data-fila]'))) activa = null;
    }
    pintarRenglones();
    revisar();
  });
  // El porcentaje cuenta para el tope de 100% por producto: se revisa todo.
  caja.addEventListener('input', function () { pintarRenglones(); revisar(); });

  if (modo) modo.querySelector('#lotePersonaOk').addEventListener('click', function () { seleccionar(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && activa && conf.hidden && document.body.contains(form)) seleccionar(null);
  });

  /* ══ Cada venta ══ */
  function ventaId(tr) { return tr.querySelector('input[name="ventas[]"]').value; }

  /* Qué productos de cada venta van, en dos capas:
       base   'venta:producto'        lo marcado para TODOS, sin nadie seleccionado
       deUno  'venta:clave:producto'  lo marcado para UNA persona, con ella
                                      seleccionada. Manda sobre lo de todos y
                                      sobre su "En".
     Sin nada propio, a cada persona le tocan los productos marcados para
     todos que caen en su "En". Así Ana va en un producto y Juan en otro de
     la misma venta, sin tocar "En". */
  var base = {}, deUno = {};
  filas.forEach(function (tr) {
    var vid = ventaId(tr);
    [].forEach.call(tr.querySelectorAll('input[data-prod]'), function (p) {
      base[vid + ':' + p.value] = p.checked;
    });
  });

  /* Todos los productos de la venta, cada uno con quién ya comisiona en él. */
  function lineas(tr) {
    var vid = ventaId(tr), ps = tr.querySelectorAll('input[data-prod]');
    if (!ps.length) {
      return +tr.dataset.n === 1
        ? [{ k: tr.dataset.linea, c: tr.dataset.con || '', a: tr.dataset.area, p: tr.dataset.pid, base: true }] : [];
    }
    return [].map.call(ps, function (p) {
      return { k: p.value, c: p.dataset.con || '', a: p.dataset.area, p: p.dataset.pid,
               base: base[vid + ':' + p.value] !== false, el: p };
    });
  }
  /* Los marcados para todos. */
  function productos(tr) { return lineas(tr).filter(function (l) { return l.base; }); }

  function idDe(tr, x) { return x.q === 'esp' ? tr.dataset.esp : x.q; }
  function yaTiene(l, id) { return (',' + l.c + ',').indexOf(',' + id + ',') !== -1; }

  /* Los productos de esta venta que le tocan a esta persona. */
  function deLaPersona(tr, x) {
    var vid = ventaId(tr);
    return lineas(tr).filter(function (l) {
      var e = deUno[vid + ':' + x.k + ':' + l.k];
      return e !== undefined ? e : (l.base && enAlcance(l, x.en));
    });
  }

  /* ¿Este producto es para esta persona? */
  function enAlcance(p, en) {
    if (!en) return true;
    if (en.indexOf('a:') === 0) return p.a === en.slice(2).trim().toLowerCase();
    if (en.indexOf('p:') === 0) return p.p === en.slice(2);
    return false;
  }

  function quitado(vid, k) {
    var v = fuera[vid + ':' + k];
    return v === true || (v !== false && fuera[vid + ':*'] === true);
  }
  function soloVenta(vid) {
    Object.keys(fuera).forEach(function (c) {
      if (c.indexOf(vid + ':') === 0) delete fuera[c];
    });
  }
  /* Pone o quita la venta para todos. */
  function ponerVenta(vid, si) {
    soloVenta(vid);
    if (!si) fuera[vid + ':*'] = true;
  }

  /* Por persona: si le toca algo de esta venta, si se quitó de ella, y
     cuántas comisiones le salen. Igual que en el servidor: nadie va dos
     veces en el mismo producto (la primera fila gana) y en un producto
     los porcentajes no pasan de 100. Con `todos`, como si nadie se
     hubiera quitado: sirve para saber qué es posible. */
  function reparto(tr, todos) {
    var usados = {}, suma = {}, vid = ventaId(tr);
    return quienes().map(function (x) {
      var r = { x: x, libres: 0, aplica: false, quitado: false, motivo: '', mios: [], lineas: [] };
      var esp = x.q === 'esp';
      var mios = deLaPersona(tr, x);
      r.mios = mios;
      if (!mios.length) { r.motivo = 'No es de su alcance (' + (x.enTexto || 'sus productos') + ')'; return r; }
      r.aplica = true;
      if (!todos && quitado(vid, x.k)) { r.quitado = true; return r; }
      var id = idDe(tr, x);
      if (!id || id === '0') { r.motivo = 'No tiene especialista'; return r; }
      var pasa = false;
      var l = mios.filter(function (p) {
        if ((',' + p.c + ',').indexOf(',' + id + ',') !== -1 || usados[id + ':' + p.k]) return false;
        if ((suma[p.k] || 0) + (x.pct || 0) > 100.0001) { pasa = true; return false; }
        return true;
      });
      l.forEach(function (p) { usados[id + ':' + p.k] = true; suma[p.k] = (suma[p.k] || 0) + (x.pct || 0); });
      r.libres = l.length;
      r.lineas = l;
      r.motivo = l.length ? '' : pasa ? 'Los porcentajes pasan de 100% en un producto'
               : esp ? 'Su especialista ya tiene comisión' : 'Ya tiene comisión';
      return r;
    });
  }
  function de(rs, k) {
    for (var i = 0; i < rs.length; i++) if (rs[i].x.k === k) return rs[i];
    return null;
  }

  /* Por qué esta venta no se puede, aunque se quisiera. '' si sí se puede. */
  function imposible(tr) {
    if (tr.dataset.fijo) return tr.dataset.fijo;
    if (!quienes().length) return productos(tr).length ? '' : 'Marca al menos un producto';
    var rs = reparto(tr, true);
    if (rs.some(function (r) { return r.libres; })) return '';
    var ap = rs.filter(function (r) { return r.aplica; });
    if (!ap.length) {
      return productos(tr).length ? 'Sus productos no son para nadie de la lista' : 'Marca al menos un producto';
    }
    if (ap.length === 1) return ap[0].motivo;
    return 'Ya tienen comisión' + (ap.some(function (r) { return r.motivo === 'No tiene especialista'; })
      ? ' (y no tiene especialista)' : '');
  }

  function libres(tr) {
    return reparto(tr).reduce(function (s, r) { return s + r.libres; }, 0);
  }

  /* ¿La venta va? Antes de elegir a nadie, si no se quitó; después, si a
     alguien le sale al menos una comisión. */
  function incluida(tr) {
    if (imposible(tr)) return false;
    if (!quienes().length) return !fuera[ventaId(tr) + ':*'];
    return libres(tr) > 0;
  }

  /* "Lista", o cuántas comisiones salen de esta venta. */
  function listo(tr) {
    var l = libres(tr), n = +tr.dataset.n;
    if (quienes().length > 1) return 'Lista · ' + l + (l === 1 ? ' comisión' : ' comisiones');
    if (n < 2) return 'Lista';
    return 'Lista · ' + (l === n ? l + ' productos' : l + ' de ' + n + ' productos');
  }

  function marcadas() { return filas.filter(incluida); }
  function comisiones() {
    return marcadas().reduce(function (s, tr) { return s + libres(tr); }, 0);
  }

  function describir(qs) {
    return qs.map(function (x) {
      return x.nombre + (x.pct > 0 ? ' ' + x.pct + '%' : '') + (x.en ? ' en ' + x.enTexto : '');
    }).join(' + ');
  }

  /* ══ Pintar ══ */
  function revisar() {
    QS = leerQuienes();
    if (activa && !persona(activa)) activa = null;
    var yo = activa ? persona(activa) : null;
    var porPersona = {};

    filas.forEach(function (tr) {
      var c   = tr.querySelector('.lf-lote-sel');
      var vid = ventaId(tr);
      var no  = imposible(tr);
      // El reparto se calcula aunque la venta no se pueda "para todos": con
      // una persona seleccionada, ella todavía puede ponerse productos.
      var rs  = tr.dataset.fijo ? [] : reparto(tr);
      var va  = !no && (QS.length ? rs.some(function (r) { return r.libres; }) : !fuera[vid + ':*']);

      rs.forEach(function (r) { if (r.libres) porPersona[r.x.k] = (porPersona[r.x.k] || 0) + 1; });

      // La casilla: la venta para todos, o para la persona seleccionada.
      if (yo) {
        // Puede ir en esta venta si queda algún producto en el que todavía
        // no comisione, aunque su "En" no lo incluya: aquí se elige a mano.
        var idYo  = idDe(tr, yo);
        var conId = !!idYo && idYo !== '0';
        var puede = !tr.dataset.fijo && conId
                    && lineas(tr).some(function (l) { return !yaTiene(l, idYo); });
        var mio = de(rs, activa);
        c.disabled = !puede;
        c.checked = !!(puede && mio && mio.libres);
        c.indeterminate = false;
        c.title = puede ? '' : (tr.dataset.fijo || (!conId ? 'No tiene especialista'
                                                           : 'Ya comisiona en todos sus productos'));
        tr.classList.toggle('ajena', !puede);
        tr.classList.toggle('mia', c.checked);
      } else {
        var aplican = rs.filter(function (r) { return r.aplica; });
        var dentro  = aplican.filter(function (r) { return !r.quitado; });
        c.disabled = !!no;
        c.checked = va;
        c.indeterminate = va && dentro.length > 0 && dentro.length < aplican.length;
        c.title = '';
        tr.classList.remove('ajena', 'mia');
      }

      tr.classList.toggle('no', !!no || !va);
      tr.querySelector('.lf-lote-motivo').textContent = no || (va ? listo(tr) : 'No va');

      // Lo que se envía: la venta si va, a quiénes lleva y, de cada quien,
      // en qué productos (lp[venta][fila][]; el "0" marca que se eligió,
      // aunque no quede ninguno).
      tr.querySelector('input[name="ventas[]"]').disabled = !va;
      var datos = tr.querySelector('.lf-lote-datos');
      datos.innerHTML = '';
      if (va && QS.length) {
        datos.appendChild(oculto('para[' + vid + '][]', '-'));
        rs.forEach(function (r) {
          if (!r.aplica || r.quitado) return;
          datos.appendChild(oculto('para[' + vid + '][]', r.x.k));
          datos.appendChild(oculto('lp[' + vid + '][' + r.x.k + '][]', '0'));
          r.mios.forEach(function (l) { datos.appendChild(oculto('lp[' + vid + '][' + r.x.k + '][]', l.k)); });
        });
      }
      pintarProductos(tr, rs, yo);
      pintarPara(tr, no ? [] : rs);
    });

    // En cada tarjeta, en cuántas ventas va esa persona.
    renglones().forEach(function (r) {
      var k = claveDe(r), x = persona(k), n = porPersona[k] || 0;
      r.querySelector('[data-cuenta]').textContent = !x ? 'Elige a alguien para ver en cuántas ventas va'
        : n ? 'Va en ' + n + (n === 1 ? ' venta' : ' ventas') : 'No va en ninguna venta de la lista';
    });

    // El aviso de arriba de la lista mientras se eligen las ventas de alguien.
    if (modo) modo.hidden = !yo;
    if (tabla) tabla.classList.toggle('modo-persona', !!yo);
    if (yo && modoT) {
      modoT.innerHTML = '';
      modoT.appendChild(document.createTextNode('Eligiendo las ventas de '));
      var b = document.createElement('b'); b.textContent = yo.nombre; modoT.appendChild(b);
      modoT.appendChild(document.createTextNode(
        (yo.pct > 0 ? ' · ' + yo.pct + '%' : '') + (yo.en ? ' en ' + yo.enTexto : '')
        + '. Marca sus ventas y, en las de varios productos, en cuáles va. '
        + 'A los demás no les cambia nada.'));
    }

    contar();
    QS = null;
  }

  /* Las casillas de producto de una venta con varios: las de todos, o las
     de la persona seleccionada. Junto a cada producto, quién va en él. */
  function pintarProductos(tr, rs, yo) {
    var ls = lineas(tr);
    if (ls.length < 2 || !ls[0].el) return;
    var vid = ventaId(tr);
    var quien = {};
    rs.forEach(function (r) {
      r.lineas.forEach(function (l) { (quien[l.k] = quien[l.k] || []).push(r.x.corto); });
    });
    var idYo  = yo ? idDe(tr, yo) : null;
    var conId = !!idYo && idYo !== '0';
    var mios  = yo ? deLaPersona(tr, yo).map(function (l) { return l.k; }) : [];
    var fueraYo = yo ? quitado(vid, activa) : false;
    ls.forEach(function (l) {
      var cb = l.el, li = cb.closest('li');
      if (yo) {
        var tiene = conId && yaTiene(l, idYo);
        cb.disabled = !conId || tiene || !!tr.dataset.fijo;
        cb.checked  = !cb.disabled && !fueraYo && mios.indexOf(l.k) !== -1;
        li.title    = tiene ? 'Ya comisiona en este producto' : '';
      } else {
        cb.disabled = false;
        cb.checked  = l.base;
        li.title    = '';
      }
      li.classList.toggle('fuera', !cb.checked);
      var em = li.querySelector('.quien');
      if (em) {
        em.textContent = !quienes().length ? ''
          : quien[l.k] ? '→ ' + quien[l.k].join(', ') : '→ nadie';
      }
    });
  }

  function oculto(nombre, valor) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = nombre; i.value = valor;
    return i;
  }

  /* Quiénes van en esta venta, para verlo de un vistazo sin seleccionar a
     cada persona. Solo se lee: se cambia tocando la tarjeta de la persona.
     Antes eran chips que también editaban, y era lo mismo que la tarjeta
     por otro camino. Sale con dos o más personas. */
  function pintarPara(tr, rs) {
    var box = tr.querySelector('.lf-lote-para');
    box.innerHTML = '';
    var van = rs.filter(function (r) { return r.libres; });
    if (quienes().length < 2 || !van.length) { box.hidden = true; return; }
    box.hidden = false;
    van.forEach(function (r) {
      var s = document.createElement('span');
      s.textContent = r.x.corto;
      if (r.x.k === activa) s.className = 'yo';
      box.appendChild(s);
    });
  }

  function contar() {
    var propio = !QS;
    if (propio) QS = leerQuienes();
    var vs = marcadas(), n = vs.length;
    if (cuenta) cuenta.textContent = n + (n === 1 ? ' venta va' : ' ventas van');
    if (todas) {
      var abiertas = filas.filter(function (tr) { return !tr.querySelector('.lf-lote-sel').disabled; });
      var on = abiertas.filter(function (tr) { return tr.querySelector('.lf-lote-sel').checked; }).length;
      todas.checked = abiertas.length > 0 && on === abiertas.length;
      todas.indeterminate = on > 0 && on < abiertas.length;
      todas.disabled = !abiertas.length;
      todas.setAttribute('aria-label', activa ? 'Marcar todas sus ventas' : 'Marcar todas');
    }
    if (resum) {
      var qs = quienes(), c = vs.reduce(function (s, tr) { return s + libres(tr); }, 0);
      var faltaPct = qs.some(function (x) { return !(x.pct > 0); });
      var ventas = n + (n === 1 ? ' venta' : ' ventas')
        + (qs.length && c !== n ? ' (' + c + (c === 1 ? ' comisión)' : ' comisiones)') : '');
      resum.textContent = !n ? 'No hay ventas marcadas.'
        : !qs.length ? n + (n === 1 ? ' venta marcada.' : ' ventas marcadas.') + ' Elige a quién.'
        : faltaPct ? ventas + ' para ' + describir(qs) + '. Falta el porcentaje.'
        : ventas + ' · ' + describir(qs);
    }
    if (propio) QS = null;
  }

  /* ══ Marcar ══ */
  function marcarVenta(tr, si) {
    var vid = ventaId(tr);
    if (!activa) { ponerVenta(vid, si); return; }
    fuera[vid + ':' + activa] = !si;
    if (!si) return;
    // Si no le tocaba nada aquí (su "En" no cae en esta venta, o se le
    // quitaron sus productos), al marcarla se le ponen los marcados para
    // todos en los que todavía no comisiona; si no hay, todos los que pueda.
    var x = persona(activa);
    if (!x) return;
    var mio = de(reparto(tr), activa);
    if (mio && mio.libres) return;
    var id = idDe(tr, x);
    var puede = lineas(tr).filter(function (l) { return !yaTiene(l, id); });
    var marcados = puede.filter(function (l) { return l.base; });
    (marcados.length ? marcados : puede).forEach(function (l) {
      deUno[vid + ':' + activa + ':' + l.k] = true;
    });
  }

  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t === todas) {
      filas.forEach(function (tr) {
        if (!tr.querySelector('.lf-lote-sel').disabled) marcarVenta(tr, todas.checked);
      });
      revisar();
    } else if (t.matches('.lf-lote-sel')) {
      marcarVenta(t.closest('tr'), t.checked);
      revisar();
    } else if (t.matches('input[data-prod]')) {
      // Con alguien seleccionado, el producto es suyo; si no, de todos.
      var tr = t.closest('tr'), vid = ventaId(tr);
      if (activa) {
        deUno[vid + ':' + activa + ':' + t.value] = t.checked;
        if (t.checked) fuera[vid + ':' + activa] = false;   // ponerse un producto es ir en la venta
      } else {
        base[vid + ':' + t.value] = t.checked;
      }
      revisar();
    }
  });

  /* Enter en un porcentaje no manda nada: aquí un envío por accidente
     son decenas de comisiones. */
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') e.preventDefault();
  });

  /* ══ Confirmar ══ */
  function cerrar() { conf.hidden = true; document.body.classList.remove('lf-modal-abierto'); }
  [].forEach.call(conf.querySelectorAll('[data-lote-cerrar]'), function (b) {
    b.addEventListener('click', cerrar);
  });
  conf.addEventListener('click', function (e) { if (e.target === conf) cerrar(); });
  conf.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });

  form.addEventListener('submit', function (e) {
    if (confirmado) return;                  // ya confirmado: que se envíe
    e.preventDefault();
    revisar();                               // lo que se envía, al día
    var vs = marcadas(), n = vs.length;
    if (!n) { alert('Marca al menos una venta.'); return; }
    if (!form.reportValidity()) return;
    var qs = quienes();
    var c = comisiones();
    if (!c) { alert('Con esa elección no queda ninguna comisión por asignar.'); return; }

    // Por persona, en cuántas de las ventas que van.
    var cuantas = {};
    vs.forEach(function (tr) {
      reparto(tr).forEach(function (r) { if (r.libres) cuantas[r.x.k] = (cuantas[r.x.k] || 0) + 1; });
    });
    var datos = [['Ventas', n]];
    if (c !== n) datos.push(['Comisiones', c + (qs.length > 1 ? '' : ' (una por producto)')]);
    qs.forEach(function (x) {
      var m = cuantas[x.k] || 0;
      datos.push([x.nombre, x.pct + '%' + (x.en ? ' · solo ' + x.enTexto : '')
                  + ' · ' + m + (m === 1 ? ' venta' : ' ventas')]);
    });

    var d = document.getElementById('loteConfD');
    d.innerHTML = '';
    datos.forEach(function (x) {
      var div = document.createElement('div');
      var dt = document.createElement('dt'); dt.textContent = x[0];
      var dd = document.createElement('dd'); dd.textContent = x[1];
      div.appendChild(dt); div.appendChild(dd); d.appendChild(div);
    });
    conf.hidden = false;
    document.body.classList.add('lf-modal-abierto');
    conf.querySelector('[data-lote-cerrar].btn').focus();   // el foco en "Revisar", no en "Sí"
  });

  document.getElementById('loteConfOk').addEventListener('click', function () {
    confirmado = true;
    this.disabled = true;
    this.textContent = 'Asignando…';
    form.submit();
  });

  pintarRenglones();
  revisar();
})();
</script>
