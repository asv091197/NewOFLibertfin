<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Agenda as A;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];

// Lo que se había escrito si el guardado falló (un empalme, por ejemplo).
$prev = $_SESSION['lf_agenda_previo'] ?? null;
unset($_SESSION['lf_agenda_previo']);
$f = $edit ? [
    'cliente_id' => $edit['cliente_id'], 'cliente_nombre' => $edit['cliente_nombre'], 'telefono' => $edit['telefono'],
    'colaborador_id' => $edit['colaborador_id'], 'producto_id' => $edit['producto_id'],
    'hora' => date('H:i', strtotime($edit['inicio'])),
    'duracion' => (int)round((strtotime($edit['fin']) - strtotime($edit['inicio'])) / 60), 'notas' => $edit['notas'],
] : ['cliente_id' => '', 'cliente_nombre' => '', 'telefono' => '', 'colaborador_id' => $nueva['colab'] ?: '',
     'producto_id' => '', 'hora' => $nueva['hora'], 'duracion' => '', 'notas' => ''];
if ($prev) $f = array_merge($f, $prev);
$fechaForm = $edit ? substr($edit['inicio'], 0, 10) : $dia;

// La cuadrícula: de la apertura al cierre, en pasos del intervalo.
$min = function ($hhmm) { list($h, $m) = array_map('intval', explode(':', $hhmm)); return $h * 60 + $m; };
$ini = $min($horario['inicio']); $fin = $min($horario['fin']); $paso = $horario['intervalo'];
// Si hay citas fuera del horario, la cuadrícula se estira para que se vean.
foreach ($citas as $c) {
    $ini = min($ini, $min(date('H:i', strtotime($c['inicio']))));
    $fin = max($fin, $min(date('H:i', strtotime($c['fin']))));
}
$ini = (int)(floor($ini / $paso) * $paso); $fin = (int)(ceil($fin / $paso) * $paso);
$PX = 1.5;   // pixeles por minuto

// Las columnas: cada especialista, y «Sin asignar» si hace falta.
$columnas = [];
foreach ($especialistas as $e) $columnas[(int)$e['id']] = $e['nombre'];
$sinAsignar = !$columnas || array_filter($citas, function ($c) { return !$c['colaborador_id']; });
if ($sinAsignar) $columnas[0] = $columnas ? 'Sin asignar' : 'Citas';
$porColumna = [];
foreach ($citas as $c) {
    $k = isset($columnas[(int)$c['colaborador_id']]) ? (int)$c['colaborador_id'] : 0;
    if (!isset($columnas[$k])) $columnas[$k] = 'Sin asignar';
    $porColumna[$k][] = $c;
}
$colores = ['agendada' => 'ag', 'confirmada' => 'co', 'atendida' => 'at', 'no_vino' => 'nv', 'cancelada' => 'ca'];
$diasCortos = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$hoy = date('Y-m-d');
$vivas = array_filter($citas, function ($c) { return !in_array($c['estado'], A::LIBERAN, true); });
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php /* ═══════════ LA SEMANA ═══════════ */ ?>
<div class="lf-ag-semana" data-dia="<?= P::e($dia) ?>">
  <a class="lf-ag-flecha" data-parcial href="/agenda?dia=<?= date('Y-m-d', strtotime($lunes . ' -7 days')) ?>" aria-label="Semana anterior">‹</a>
  <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($lunes . " +$i days")); ?>
    <a class="lf-ag-dia<?= $d === $dia ? ' on' : '' ?><?= $d === $hoy ? ' hoy' : '' ?>" data-parcial href="/agenda?dia=<?= $d ?>">
      <small><?= $diasCortos[$i] ?></small><b><?= (int)date('j', strtotime($d)) ?></b>
      <span><?= !empty($porDia[$d]) ? $porDia[$d] . ($porDia[$d] === 1 ? ' cita' : ' citas') : '&nbsp;' ?></span>
    </a>
  <?php endfor; ?>
  <a class="lf-ag-flecha" data-parcial href="/agenda?dia=<?= date('Y-m-d', strtotime($lunes . ' +7 days')) ?>" aria-label="Semana siguiente">›</a>
  <form method="get" action="/agenda" class="lf-ag-ir">
    <input class="form-control form-control-sm" type="date" name="dia" value="<?= P::e($dia) ?>" aria-label="Ir a un día"
           onchange="if (window.lfIr) lfIr('/agenda?dia=' + this.value); else this.form.submit();">
    <?php if ($dia !== $hoy): ?><a class="btn btn-secondary btn-sm" data-parcial href="/agenda">Hoy</a><?php endif; ?>
  </form>
</div>

<?php if ($puedeEditar): ?>
<details class="lf-alta" id="altaCita" <?= $abrir || $prev ? 'open' : '' ?>>
  <summary><?= W::icono('agenda','16px') ?> <span id="citaTitulo"><?= $edit ? 'Mover o cambiar la cita de ' . P::e($edit['cliente_nombre']) : 'Agendar una cita' ?></span></summary>
  <form method="post" action="/agenda/guardar" class="lf-form" id="formCita">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="id" id="citaId" value="<?= $edit ? (int)$edit['id'] : '' ?>">
    <div style="flex:2;min-width:230px">
      <?php P::parcial('parciales/elegir-cliente', ['pref' => 'cita', 'etiqueta' => 'Cliente', 'alElegir' => 'lfCitaCliente',
            'idVal' => (string)($f['cliente_id'] ?? ''), 'nomVal' => (string)($f['cliente_nombre'] ?? '')]); ?>
    </div>
    <div style="width:160px">
      <label class="form-label" for="citaTel" title="Para mandarle el recordatorio">WhatsApp</label>
      <input class="form-control" name="telefono" id="citaTel" inputmode="tel" maxlength="30" value="<?= P::e($f['telefono'] ?? '') ?>" placeholder="10 dígitos">
    </div>
    <div style="flex:2;min-width:220px">
      <label class="form-label" for="citaServ">Servicio</label>
      <select class="form-select" name="producto_id" id="citaServ">
        <option value="">— Sin especificar —</option>
        <?php foreach ($servicios as $s): ?>
          <option value="<?= (int)$s['id'] ?>" data-dur="<?= (int)$s['duracion_min'] ?>" <?= (int)$f['producto_id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= P::e($s['nombre']) ?><?= (int)$s['duracion_min'] ? ' · ' . (int)$s['duracion_min'] . ' min' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:190px">
      <label class="form-label" for="citaEsp">Con quién</label>
      <select class="form-select" name="colaborador_id" id="citaEsp">
        <option value="">Quien esté disponible</option>
        <?php foreach ($especialistas as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= (int)$f['colaborador_id'] === (int)$e['id'] ? 'selected' : '' ?>><?= P::e($e['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:155px">
      <label class="form-label" for="citaFecha">Día</label>
      <input class="form-control" type="date" name="fecha" id="citaFecha" value="<?= P::e($fechaForm) ?>" required>
    </div>
    <div style="width:115px">
      <label class="form-label" for="citaHora">Hora</label>
      <input class="form-control" type="time" name="hora" id="citaHora" step="<?= (int)$paso * 60 ?>" value="<?= P::e($f['hora']) ?>" required>
    </div>
    <div style="width:115px">
      <label class="form-label" for="citaDur">Dura <small>(min)</small></label>
      <input class="form-control lf-mono" type="number" name="duracion" id="citaDur" min="5" max="720" step="5"
             value="<?= P::e($f['duracion']) ?>" placeholder="<?= (int)$paso ?>">
    </div>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="citaNotas">Nota <small>(opcional)</small></label>
      <input class="form-control" name="notas" id="citaNotas" maxlength="255" value="<?= P::e($f['notas']) ?>" placeholder="Viene con su hija, alergia al látex…">
    </div>
    <div style="flex-direction:row;gap:10px">
      <button class="btn btn-primary" type="submit" id="citaBoton"><?= $edit ? 'Guardar cambios' : 'Agendar' ?></button>
      <button class="btn btn-secondary" type="button" id="citaCancelar" <?= $edit ? '' : 'hidden' ?>>Cancelar</button>
    </div>
    <p data-aviso hidden></p>
  </form>
</details>
<?php endif; ?>

<?php /* ═══════════ EL DÍA ═══════════ */ ?>
<section class="card lf-ag-card">
  <div class="card-header"><span>
    <h3 class="card-title"><?= count($vivas) ?> <?= count($vivas) === 1 ? 'cita' : 'citas' ?></h3>
    <small class="lf-inv-sub">Toca una cita para verla, confirmarla o cobrarla<?= $puedeEditar ? '; toca un espacio libre para agendar ahí' : '' ?>.</small>
  </span></div>
  <?php /* UNA PERSONA A LA VEZ EN EL TELÉFONO.
       Cada especialista es una columna de 150px y las horas se llevan
       56 más: en una pantalla de 360 no caben dos, así que había que
       arrastrar de lado para ver a la segunda —y peor, no había nada
       que dijera que la segunda existía—. Aquí se eligen por nombre y
       la columna ocupa el ancho entero.
       En pantalla grande este selector no aparece: ahí caben todas y
       verlas juntas es justo para lo que sirve una agenda. */ ?>
  <?php if (count($columnas) > 1): ?>
  <div class="lf-ag-quien" role="tablist" aria-label="De quién ves la agenda">
    <?php $primera = true; foreach ($columnas as $cid => $cnom): ?>
      <button type="button" class="lf-pill<?= $primera ? ' active' : '' ?>"
              role="tab" aria-selected="<?= $primera ? 'true' : 'false' ?>"
              data-col="<?= (int)$cid ?>"><?= P::e($cnom) ?></button>
    <?php $primera = false; endforeach; ?>
    <button type="button" class="lf-pill lf-ag-todas" data-col="todas">Todas</button>
  </div>
  <?php endif; ?>
  <div class="lf-ag-scroll">
    <div class="lf-ag-grid" style="--cols:<?= count($columnas) ?>;--alto:<?= ($fin - $ini) * $PX ?>px">
      <div class="lf-ag-horas">
        <div class="lf-ag-colcab"></div>
        <div class="lf-ag-cuerpo">
          <?php for ($m = $ini; $m < $fin; $m += $paso): ?>
            <span style="top:<?= ($m - $ini) * $PX ?>px"><?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?></span>
          <?php endfor; ?>
        </div>
      </div>
      <?php foreach ($columnas as $cid => $cnom): ?>
      <div class="lf-ag-col" data-col="<?= (int)$cid ?>">
        <div class="lf-ag-colcab"><?= P::e($cnom) ?></div>
        <div class="lf-ag-cuerpo">
          <?php if ($puedeEditar): for ($m = $ini; $m < $fin; $m += $paso): ?>
            <a class="lf-ag-hueco" style="top:<?= ($m - $ini) * $PX ?>px;height:<?= $paso * $PX ?>px"
               data-hora="<?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?>" data-colab="<?= $cid ? (int)$cid : '' ?>"
               href="/agenda?dia=<?= P::e($dia) ?>&amp;nueva=1&amp;hora=<?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?><?= $cid ? '&amp;colab=' . (int)$cid : '' ?>#altaCita"
               aria-label="Agendar a las <?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?>"></a>
          <?php endfor; endif; ?>
          <?php foreach ($porColumna[$cid] ?? [] as $c):
              $a = $min(date('H:i', strtotime($c['inicio']))); $b = max($a + 10, $min(date('H:i', strtotime($c['fin'])))); ?>
            <button type="button" class="lf-ag-cita e-<?= $colores[$c['estado']] ?? 'ag' ?>" data-cita="<?= (int)$c['id'] ?>"
                    style="top:<?= ($a - $ini) * $PX ?>px;height:<?= max(22, ($b - $a) * $PX - 2) ?>px">
              <b><?= P::e(date('H:i', strtotime($c['inicio']))) ?> · <?= P::e($c['cliente_nombre']) ?></b>
              <small><?= P::e($c['servicio'] ?: 'Sin servicio') ?></small>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$especialistas): ?>
    <div class="card-footer"><span>Para tener una columna por especialista, da de alta a tu equipo en <a href="/comisiones">Comisiones</a>.</span></div>
  <?php endif; ?>
</section>

<?php /* ═══════════ LA LISTA (también para el celular) ═══════════ */ ?>
<section class="card lf-ag-lista">
  <div class="card-header"><span><h3 class="card-title">Del día, en orden</h3></span></div>
  <?php if (!$citas): ?>
    <div class="card-body lf-inv-vacio">No hay citas este día.</div>
  <?php endif; ?>
  <?php foreach ($citas as $c):
      $datosCita = ['id' => (int)$c['id'], 'cliente_id' => $c['cliente_id'] ? (int)$c['cliente_id'] : '',
          'cliente_nombre' => (string)$c['cliente_nombre'], 'telefono' => (string)$c['telefono'],
          'colaborador_id' => $c['colaborador_id'] ? (int)$c['colaborador_id'] : '', 'producto_id' => $c['producto_id'] ? (int)$c['producto_id'] : '',
          'fecha' => substr($c['inicio'], 0, 10), 'hora' => date('H:i', strtotime($c['inicio'])),
          'duracion' => (int)round((strtotime($c['fin']) - strtotime($c['inicio'])) / 60), 'notas' => (string)$c['notas']]; ?>
    <div class="lf-ag-item" id="cita-<?= (int)$c['id'] ?>" data-datos="<?= P::e(json_encode($datosCita, JSON_UNESCAPED_UNICODE)) ?>">
      <div class="h lf-mono"><?= P::e(date('H:i', strtotime($c['inicio']))) ?><small><?= P::e(date('H:i', strtotime($c['fin']))) ?></small></div>
      <div class="q">
        <b><?= $c['cliente_id'] ? '<a href="/clientes/' . (int)$c['cliente_id'] . '">' . P::e($c['cliente_nombre']) . '</a>' : P::e($c['cliente_nombre']) ?></b>
        <small><?= P::e($c['servicio'] ?: 'Sin servicio') ?><?= $c['especialista'] ? ' · con ' . P::e($c['especialista']) : '' ?><?= $c['notas'] ? ' · ' . P::e($c['notas']) : '' ?></small>
      </div>
      <span class="lf-ag-estado e-<?= $colores[$c['estado']] ?? 'ag' ?>"><?= P::e(A::ESTADOS[$c['estado']] ?? $c['estado']) ?></span>
      <div class="acc">
        <?php if ($c['estado'] === 'atendida'): ?>
          <?php if ($c['venta_id']): ?><a class="btn btn-secondary btn-sm" href="/ventas/<?= (int)$c['venta_id'] ?>">Venta <?= P::e($c['codigo_venta'] ?: '') ?></a><?php endif; ?>
        <?php else: ?>
          <?php if ($c['whatsapp'] && !in_array($c['estado'], A::LIBERAN, true)): ?>
            <a class="btn btn-secondary btn-sm lf-ag-wa" href="<?= P::e($c['whatsapp']) ?>" target="_blank" rel="noopener"><?= W::icono('chat','14px') ?> Recordar</a>
          <?php endif; ?>
          <?php if ($puedeCobrar && !in_array($c['estado'], A::LIBERAN, true)): ?>
            <a class="btn btn-primary btn-sm" href="/caja?cita=<?= (int)$c['id'] ?>">Cobrar</a>
          <?php endif; ?>
          <?php if ($puedeEditar): ?>
            <details class="lf-ag-mas">
              <summary class="btn btn-secondary btn-sm">Más</summary>
              <div>
                <?php foreach (['confirmada' => 'Confirmó', 'agendada' => 'Sin confirmar', 'no_vino' => 'No vino', 'cancelada' => 'Cancelar cita'] as $k => $t):
                    if ($k === $c['estado']) continue; ?>
                  <form method="post" action="/agenda/estado" class="lf-ag-f">
                    <input type="hidden" name="token" value="<?= P::e($token) ?>">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="estado" value="<?= $k ?>">
                    <button type="submit"><?= P::e($t) ?></button>
                  </form>
                <?php endforeach; ?>
                <a href="/agenda?dia=<?= P::e($dia) ?>&amp;editar=<?= (int)$c['id'] ?>#altaCita" data-editar>Mover o cambiar</a>
              </div>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card-footer"><span>«Recordar» abre WhatsApp con el mensaje escrito; lo mandas tú desde tu teléfono.
    Al cobrar una cita en Caja, queda <b>atendida</b> y ligada a su venta.</span></div>
</section>

<?php if ($puedeAjustes): ?>
<details class="lf-alta lf-ag-horario">
  <summary><?= W::icono('reloj','16px') ?> Horario de la agenda: <?= P::e($horario['inicio']) ?> a <?= P::e($horario['fin']) ?>, cada <?= (int)$horario['intervalo'] ?> min</summary>
  <form method="post" action="/agenda/horario?dia=<?= P::e($dia) ?>" class="lf-form lf-ag-f">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="width:130px"><label class="form-label" for="hIni">Abre</label>
      <input class="form-control" type="time" name="inicio" id="hIni" value="<?= P::e($horario['inicio']) ?>" required></div>
    <div style="width:130px"><label class="form-label" for="hFin">Cierra</label>
      <input class="form-control" type="time" name="fin" id="hFin" value="<?= P::e($horario['fin']) ?>" required></div>
    <div style="width:150px"><label class="form-label" for="hInt">Espacios de</label>
      <select class="form-select" name="intervalo" id="hInt">
        <?php foreach ([15, 20, 30, 60] as $n): ?><option value="<?= $n ?>" <?= (int)$horario['intervalo'] === $n ? 'selected' : '' ?>><?= $n ?> minutos</option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-primary" type="submit">Guardar horario</button>
  </form>
</details>
<?php endif; ?>

<script>
(function(){
  /* El servicio trae su duración; el cliente, su teléfono. */
  var serv = document.getElementById('citaServ'), dur = document.getElementById('citaDur');
  if (serv && dur) serv.addEventListener('change', function(){
    var o = serv.options[serv.selectedIndex], d = +(o && o.dataset.dur);
    if (d) dur.value = d;
  });
  window.lfCitaCliente = function(c){
    var t = document.getElementById('citaTel');
    if (t && !t.value && c.telefono) t.value = c.telefono;
  };
  /* Llegar desde un hueco: el formulario a la vista. */
  if (location.hash === '#altaCita') {
    var a = document.getElementById('altaCita');
    if (a) { a.open = true; setTimeout(function(){ a.scrollIntoView({block: 'start'}); var n = document.getElementById('citaNombre'); if (n) n.focus(); }, 60); }
  }
  /* La cuadrícula arranca en la hora actual, no en la apertura. */
  var sc = document.querySelector('.lf-ag-scroll');
  if (sc && <?= $dia === $hoy ? 'true' : 'false' ?>) {
    var ahora = new Date(), m = ahora.getHours() * 60 + ahora.getMinutes();
    sc.scrollTop = Math.max(0, (m - <?= $ini ?> - 60) * <?= $PX ?>);
  }

  /* ══ SIN RECARGAR ══
     Agendar, mover, confirmar o cancelar se guardan por detrás y solo se
     vuelve a pedir lo de la agenda (la semana, la cuadrícula y la lista).
     Tocar un espacio libre abre el formulario ahí, con su hora y su
     especialista. Se conecta una sola vez por pestaña: todo va delegado
     al documento, así sirve también para lo que se vuelve a pintar. */
  if (window.lfAgendaJs) return;
  window.lfAgendaJs = true;
  var $ = function (id) { return document.getElementById(id); };
  var avisar = function (t, tipo) { if (window.lfAvisar) window.lfAvisar(t, tipo); else alert(t); };
  function diaActual() { var s = document.querySelector('.lf-ag-semana'); return s ? s.dataset.dia : ''; }

  function abrirForm(c, editando) {
    var f = $('formCita');
    if (!f) return;
    $('citaId').value = editando ? c.id : '';
    var caja = f.querySelector('[data-elige-cli]');
    if (caja) {
      caja.querySelector('input[type="text"]').value = c.cliente_nombre || '';
      caja.querySelector('input[type="hidden"]').value = c.cliente_id || '';
      var pista = caja.querySelector('.lf-elige-cli-pista'); if (pista) pista.textContent = c.cliente_id ? '✓ Cliente del catálogo' : '';
      var sug = caja.querySelector('.lf-sugerencias'); if (sug) sug.hidden = true;
    }
    $('citaTel').value = c.telefono || '';
    $('citaServ').value = c.producto_id || '';
    $('citaEsp').value = c.colaborador_id || '';
    $('citaFecha').value = c.fecha || diaActual();
    $('citaHora').value = c.hora || '';
    $('citaDur').value = c.duracion || '';
    $('citaNotas').value = c.notas || '';
    $('citaTitulo').textContent = editando ? 'Mover o cambiar la cita de ' + c.cliente_nombre : 'Agendar una cita';
    $('citaBoton').textContent = editando ? 'Guardar cambios' : 'Agendar';
    $('citaCancelar').hidden = !editando;
    var av = f.querySelector('[data-aviso]'); if (av) av.hidden = true;
    var d = $('altaCita');
    d.open = true;
    d.scrollIntoView({behavior: 'smooth', block: 'start'});
    setTimeout(function () { var n = editando ? $('citaHora') : $('citaNombre'); if (n) n.focus({preventScroll: true}); }, 350);
  }

  /* Vuelve a pedir la agenda del día y cambia solo sus partes. */
  function refrescar(dia, id) {
    if (dia && dia !== diaActual() && window.lfIr) { window.lfIr('/agenda?dia=' + dia); return; }
    var sc = document.querySelector('.lf-ag-scroll'), arriba = sc ? sc.scrollTop : 0;
    fetch('/agenda?dia=' + encodeURIComponent(dia || diaActual()), {headers: {'X-LF-Parcial': '1'}, credentials: 'same-origin'})
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        ['.lf-ag-semana', '.lf-ag-card', '.lf-ag-lista', '.lf-ag-horario'].forEach(function (s) {
          var viejo = document.querySelector(s), nuevo = doc.querySelector(s);
          if (viejo && nuevo) viejo.replaceWith(document.importNode(nuevo, true));
        });
        var sc2 = document.querySelector('.lf-ag-scroll');
        if (sc2) sc2.scrollTop = arriba;
        var c = id && document.querySelector('.lf-ag-cita[data-cita="' + id + '"]');
        if (c && sc2) {
          var top = c.offsetTop, alto = sc2.clientHeight - 40;
          if (top < sc2.scrollTop || top > sc2.scrollTop + alto) sc2.scrollTop = Math.max(0, top - 60);
          c.classList.add('nueva');
        }
      })
      .catch(function () { location.reload(); });
  }

  function enviar(f) {
    if (f._enviando) return;
    f._enviando = true;
    var esCita = f.getAttribute('id') === 'formCita', btn = f.querySelector('[type="submit"]');
    if (esCita && btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }
    fetch(f.action, {method: 'POST', body: new FormData(f), credentials: 'same-origin',
                     headers: {'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) {}
        if (!j) { location.reload(); return; }
        if (!j.ok) {
          if (esCita) {
            var av = f.querySelector('[data-aviso]');
            av.className = 'lf-guardado mal'; av.textContent = j.mensaje; av.hidden = false;
          } else avisar(j.mensaje, 'error');
          return;
        }
        avisar(j.mensaje, 'ok');
        var mas = f.closest('details'); if (mas && !esCita) mas.open = false;
        if (esCita) {
          abrirForm({}, false);
          $('altaCita').open = false;
          var card = document.querySelector('.lf-ag-card');
          if (card) card.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        refrescar(j.dia, j.id);
      })
      .catch(function () { avisar('No se pudo guardar: revisa tu conexión.', 'error'); })
      .then(function () {
        f._enviando = false;
        if (esCita && btn) { btn.disabled = false; btn.textContent = $('citaId').value ? 'Guardar cambios' : 'Agendar'; }
      });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || (f.getAttribute('id') !== 'formCita' && !f.classList.contains('lf-ag-f'))) return;
    e.preventDefault();
    enviar(f);
  });

  document.addEventListener('click', function (e) {
    /* Un espacio libre: el formulario con esa hora y ese especialista. */
    var h = e.target.closest('.lf-ag-hueco');
    if (h && $('formCita')) {
      e.preventDefault();
      abrirForm({hora: h.dataset.hora, colaborador_id: h.dataset.colab, fecha: diaActual()}, false);
      return;
    }
    /* «Mover o cambiar»: el formulario con los datos de la cita. */
    var ed = e.target.closest('[data-editar]');
    if (ed && $('formCita')) {
      e.preventDefault();
      var it = ed.closest('.lf-ag-item'), mas = ed.closest('details');
      if (mas) mas.open = false;
      try { abrirForm(JSON.parse(it.dataset.datos), true); } catch (x) { location.href = ed.href; }
      return;
    }
    if (e.target.closest('#citaCancelar')) { abrirForm({}, false); $('altaCita').open = false; return; }
    /* Tocar una cita en la cuadrícula lleva a su renglón de la lista, con sus acciones. */
    var b = e.target.closest('.lf-ag-cita');
    if (b) {
      var fila = document.getElementById('cita-' + b.dataset.cita);
      if (!fila) return;
      fila.scrollIntoView({behavior: 'smooth', block: 'center'});
      fila.classList.remove('foco'); void fila.offsetWidth; fila.classList.add('foco');
    }
  });
})();
</script>

<script>
/* De quién se ve la agenda en el teléfono. */
(function () {
  var sel = document.querySelector('.lf-ag-quien');
  if (!sel) return;
  var grid = document.querySelector('.lf-ag-grid');
  if (!grid) return;
  var CLAVE = 'lf-agenda-col';

  function mostrar(col) {
    var cols = grid.querySelectorAll('.lf-ag-col'), vistas = 0;
    cols.forEach(function (c) {
      var suya = (col === 'todas') || c.dataset.col === col;
      c.classList.toggle('lf-ag-oculta', !suya);
      if (suya) vistas++;
    });
    /* La rejilla reparte el ancho entre las columnas que SIGUEN
       puestas: si no se le dice, deja el hueco de las escondidas. */
    grid.style.setProperty('--cols', vistas || 1);
    sel.querySelectorAll('[data-col]').forEach(function (b) {
      var on = b.dataset.col === col;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    try { sessionStorage.setItem(CLAVE, col); } catch (e) {}
  }

  sel.addEventListener('click', function (e) {
    var b = e.target.closest('[data-col]');
    if (b) mostrar(b.dataset.col);
  });

  /* Se recuerda a quién estabas viendo: en un día de trabajo se vuelve
     a la agenda veinte veces y volver a elegir cada vez cansa. */
  var guardada = null;
  try { guardada = sessionStorage.getItem(CLAVE); } catch (e) {}
  var existe = guardada && (guardada === 'todas'
    || grid.querySelector('.lf-ag-col[data-col="' + guardada.replace(/"/g, '') + '"]'));
  /* Solo manda en pantalla chica. En grande caben todas y esconder
     gente sería esconder trabajo. */
  var chica = window.matchMedia('(max-width: 760px)').matches;
  if (chica) mostrar(existe ? guardada : (sel.querySelector('[data-col]') || {}).dataset.col);
  else mostrar('todas');

  window.matchMedia('(max-width: 760px)').addEventListener('change', function (ev) {
    if (!ev.matches) mostrar('todas');
    else mostrar(existe ? guardada : (sel.querySelector('[data-col]') || {}).dataset.col);
  });
})();
</script>
