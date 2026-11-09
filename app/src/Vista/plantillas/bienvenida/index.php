<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Arranque as A;

/**
 * La bienvenida: la configuración inicial, una pregunta por pantalla.
 *
 * A la izquierda la pregunta con tarjetas ilustradas; a la derecha, una
 * vista previa del menú que se arma con cada respuesta (si dice que vende
 * productos, aparece Inventario). Esa vista previa es la que luego "se
 * vuelve" el sistema en la entrada animada.
 *
 * Cada pregunta llega con una respuesta ya marcada (lo que sugiere su giro,
 * o lo que ya tiene si la repite): casi siempre basta con «Siguiente».
 * Sin JavaScript se ven todas juntas y se mandan con un botón.
 */
$token  = $_SESSION['lf_token'];
$ini    = $inicial;
$repite = !empty($ini['repite']);
$quien  = trim(strtok((string)($_SESSION['usuario_nombre'] ?? ''), ' ')) ?: '';
$emp    = (string)($_SESSION['empresa_nombre'] ?? 'tu negocio');
$color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$marca['color']) ? $marca['color'] : '#27ae60';
$colores = W::COLORES;
if (!isset($colores[$color])) $colores = [$color => 'El tuyo'] + $colores;

/* Ilustraciones: el mismo trazo que los íconos del sistema, más grandes. */
$ilu = [
  'maletin'  => '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M8.5 7V5.5A2 2 0 0 1 10.5 3.5h3a2 2 0 0 1 2 2V7"/><path d="M3 13h18"/><path d="M11 13v2h2v-2"/>',
  'tijeras'  => '<circle cx="6" cy="6.5" r="3"/><circle cx="6" cy="17.5" r="3"/><path d="M20 4 8.3 15.4"/><path d="M14.5 13.2 20 20"/><path d="M8.3 8.6 11.5 11.6"/>',
  'tienda'   => '<path d="M4 10v10h16V10"/><path d="M2.5 10 4.5 4h15l2 6"/><path d="M2.5 10a3.2 3.2 0 0 0 6.3 0 3.2 3.2 0 0 0 6.4 0 3.2 3.2 0 0 0 6.3 0"/><path d="M10 20v-5h4v5"/>',
  'estrella' => '<path d="M12 3.5 14.6 9l6 .7-4.5 4.1 1.2 5.9L12 16.8l-5.3 2.9 1.2-5.9L3.4 9.7l6-.7Z"/>',
  'cubiertos'=> '<path d="M7 3v18"/><path d="M4 3v5.5a3 3 0 0 0 6 0V3"/><path d="M17.5 21V3c-2.5 1-4 3.6-4 7.5V14h4"/>',
  'cama'     => '<path d="M2.5 19V6"/><path d="M2.5 15h19v4"/><path d="M21.5 19v-5a4 4 0 0 0-4-4h-7v5"/><circle cx="6.5" cy="11.5" r="2"/>',
  'manos'    => '<path d="M8 12.5V6.8a1.7 1.7 0 0 1 3.4 0v5"/><path d="M11.4 11V5.2a1.7 1.7 0 0 1 3.4 0V11"/><path d="M14.8 10.5V7.3a1.7 1.7 0 0 1 3.4 0v6.2a7 7 0 0 1-7 7h-.6a6.4 6.4 0 0 1-5.4-3L3.4 14.7A1.7 1.7 0 0 1 6.2 13l1.8 2"/>',
  'caja'     => '<path d="M21 8 12 3.5 3 8v8.5l9 4.5 9-4.5Z"/><path d="m3 8 9 4.5L21 8"/><path d="M12 12.5V21"/><path d="m7.5 5.8 9 4.5"/>',
  'bolsa'    => '<path d="M5 8h14l1 12.5H4Z"/><path d="M8.5 8V6.5a3.5 3.5 0 0 1 7 0V8"/><path d="m9.5 14 1.8 1.8 3.4-3.6"/>',
  'billete'  => '<rect x="2" y="6" width="20" height="12.5" rx="2.5"/><path d="m8.5 12.3 2.5 2.5 4.6-4.8"/>',
  'calendario'=> '<rect x="3" y="4.5" width="18" height="16.5" rx="2.5"/><path d="M3 9.5h18"/><path d="M8 2.5v4M16 2.5v4"/><path d="M7.5 14h4M7.5 17.5h7"/>',
  'equipo'   => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M15.5 4.8a3 3 0 0 1 0 6"/><path d="M18.5 20a6 6 0 0 0-3-5.2"/>',
  'persona'  => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20.5a7 7 0 0 1 14 0"/>',
];
$svg = function ($k) use ($ilu) {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
       . ($ilu[$k] ?? '') . '</svg>';
};
/* Una pregunta de opción única con tarjetas. */
$tarjetas = function ($campo, array $ops, $cols = 3) use ($ini, $svg) {
  echo '<div class="lf-bv-tarjetas c' . (int)$cols . '">';
  foreach ($ops as $k => $o) {
    $on = (string)($ini[$campo] ?? '') === (string)$k;
    echo '<label class="tj"><input type="radio" name="' . P::e($campo) . '" value="' . P::e($k) . '"' . ($on ? ' checked' : '') . ' required>'
       . '<span class="ilu' . (!empty($o['grande']) ? ' txt' : '') . '">' . (!empty($o['grande']) ? '<b>' . P::e($o['grande']) . '</b>' : $svg($o['ilu'])) . '</span>'
       . '<b>' . P::e($o['t']) . '</b><small>' . P::e($o['d']) . '</small><i class="ok" aria-hidden="true"></i></label>';
  }
  echo '</div>';
};
$pasos = ['Tu giro', 'Qué vendes', 'Cómo te pagan', 'IVA', 'Comisiones', 'Herramientas', 'Tus áreas', 'Tu marca', 'Listo'];
$modsIni = (array)($ini['modulos'] ?? []);
$MC = \LibertyFin\Servicio\Modulos::CATALOGO;
?>
<?php /* El color va en :root: de él salen los tonos suaves y los anillos. */ ?>
<style>:root{--lf-brand:<?= P::e($color) ?>}</style>
<div class="lf-bv" id="lfBv">

  <header class="lf-bv-cab">
    <span class="lf-bv-marca">Libertyfin</span>
    <div class="lf-bv-avance" aria-hidden="true"><i id="bvBarra"></i></div>
    <span class="lf-bv-num" id="bvNum"><?= count($pasos) ?> pasos</span>
    <?php if (!$repite): ?>
      <button type="submit" form="bvSaltar" class="lf-bv-saltar">Saltar por ahora</button>
    <?php else: ?>
      <a class="lf-bv-saltar" href="/ajustes">Cancelar</a>
    <?php endif; ?>
  </header>

  <form method="post" action="/bienvenida" enctype="multipart/form-data" id="bvForm" class="lf-bv-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($repite): ?><input type="hidden" name="repite" value="1"><?php endif; ?>

    <div class="lf-bv-grid">
      <main class="lf-bv-pasos">
        <?php if (!empty($error)): ?>
          <div class="lf-aviso err" role="alert"><?= W::icono('alerta', '17px') ?><span><?= P::e($error) ?></span></div>
        <?php endif; ?>

        <?php /* NADA DE ESTO ES OBLIGATORIO, Y CONVIENE DECIRLO AQUÍ.
             Una pantalla de ocho preguntas antes de poder usar el
             sistema se siente como un trámite, y quien tiene prisa la
             contesta a lo loco con tal de pasar — que es peor que no
             contestarla—. Si se sabe desde el principio que se puede
             saltar y cambiar luego, se contesta lo que se sabe y ya. */ ?>
        <p class="lf-bv-opcional">
          <?= W::icono('alerta', '15px') ?>
          <span><b>Nada de esto es obligatorio.</b> Puedes saltarte lo que no
          tengas claro y seguir derecho a la aplicación: todo esto vive en
          <b>Ajustes</b> y se cambia cuando quieras, las veces que quieras.
          Nada de lo que elijas aquí se queda fijo.</span>
        </p>

        <?php /* ── 1 · El giro ── */ ?>
        <section class="paso on" data-paso="0" data-campo="perfil">
          <p class="eyebrow"><?= $repite ? 'Configuración inicial' : ($quien !== '' ? 'Hola, ' . P::e($quien) : 'Hola') ?></p>
          <h1>¿A qué se dedica <?= P::e($emp) ?>?</h1>
          <p class="sub">Con esto dejamos Libertyfin listo para tu negocio. Todo se puede cambiar después.</p>
          <div class="lf-bv-tarjetas c4">
            <?php foreach (A::PERFILES as $k => $p): ?>
              <?php if (!empty($p['proximamente'])): ?>
                <div class="tj prox" title="Todavía no está listo para este giro">
                  <span class="ilu"><?= $svg($p['ilu']) ?></span>
                  <b><?= P::e($p['nombre']) ?></b><small><?= P::e($p['proximamente']) ?></small>
                  <em>Próximamente</em>
                </div>
              <?php else: ?>
                <label class="tj"><input type="radio" name="perfil" value="<?= P::e($k) ?>" <?= $ini['perfil'] === $k ? 'checked' : '' ?> required>
                  <span class="ilu"><?= $svg($p['ilu']) ?></span>
                  <b><?= P::e($p['nombre']) ?></b><small><?= P::e($p['ejemplo']) ?></small><i class="ok" aria-hidden="true"></i></label>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </section>

        <?php /* ── 2 · Qué vende ── */ ?>
        <section class="paso" data-paso="1" data-campo="vende">
          <p class="eyebrow">Pregunta 2</p>
          <h1>¿Qué vendes?</h1>
          <p class="sub">Si vendes productos, llevamos tus existencias y Caja las descuenta al cobrar.</p>
          <?php $tarjetas('vende', [
            'servicios' => ['ilu' => 'manos', 't' => 'Servicios', 'd' => 'Tu tiempo y lo que sabes: consultas, trámites, sesiones.'],
            'productos' => ['ilu' => 'caja',  't' => 'Productos', 'd' => 'Cosas que se acaban. Llevamos cuántas te quedan.'],
            'ambos'     => ['ilu' => 'bolsa', 't' => 'Las dos cosas', 'd' => 'Servicios y productos en el mismo ticket.'],
          ]); ?>
        </section>

        <?php /* ── 3 · Cómo le pagan ── */ ?>
        <section class="paso" data-paso="2" data-campo="cobro">
          <p class="eyebrow">Pregunta 3</p>
          <h1>¿Cómo te pagan normalmente?</h1>
          <p class="sub">Así empieza cada venta en Caja. En cualquiera de las dos puedes cobrar completo o solo una parte.</p>
          <?php $tarjetas('cobro', [
            'completo' => ['ilu' => 'billete',    't' => 'Todo al momento', 'd' => 'Cobras la venta completa en el mostrador.'],
            'anticipo' => ['ilu' => 'calendario', 't' => 'Una parte y el resto después', 'd' => 'Anticipo hoy; el saldo queda pendiente y lo ves en Cobranza.'],
          ], 2); ?>
        </section>

        <?php /* ── 4 · IVA ── */ ?>
        <section class="paso" data-paso="3" data-campo="iva">
          <p class="eyebrow">Pregunta 4</p>
          <h1>¿Tus ventas llevan IVA?</h1>
          <p class="sub">Tus precios ya lo incluyen: esto solo dice qué parte es IVA. En cada venta se puede cambiar.</p>
          <?php $tarjetas('iva', [
            '16'  => ['grande' => '16%', 't' => 'Sí, el 16%', 'd' => 'La tasa general.'],
            '8'   => ['grande' => '8%',  't' => 'Sí, el 8%',  'd' => 'Región fronteriza.'],
            'sin' => ['grande' => '—',   't' => 'Casi nunca', 'd' => 'Lo enciendes en la venta que lo lleve.'],
          ]); ?>
        </section>

        <?php /* ── 5 · Comisiones ── */ ?>
        <section class="paso" data-paso="4" data-campo="comisiones">
          <p class="eyebrow">Pregunta 5</p>
          <h1>¿Le pagas comisiones a tu equipo?</h1>
          <p class="sub">Si sí, se calculan solas sobre lo cobrado, por colaborador y por área.</p>
          <?php $tarjetas('comisiones', [
            'si' => ['ilu' => 'equipo',  't' => 'Sí, pago comisiones', 'd' => 'Activamos la sección de Comisiones.'],
            'no' => ['ilu' => 'persona', 't' => 'No', 'd' => 'La dejamos oculta. Si un día las pagas, repites estas preguntas.'],
          ], 2); ?>
        </section>

        <?php /* ── 6 · Herramientas (módulos) ── */ ?>
        <section class="paso" data-paso="5" data-modulos>
          <input type="hidden" name="modulos_paso" value="1">
          <p class="eyebrow">Herramientas</p>
          <h1>¿Qué más te ayudaría?</h1>
          <p class="sub">Te marcamos las que suelen usar negocios como el tuyo. Enciende solo lo que vas a usar: el menú queda más limpio. Se cambian cuando quieras en Ajustes → Módulos.</p>
          <div class="lf-bv-mods">
            <?php foreach (A::MODULOS as $k): $m = $MC[$k]; $enPlan = \LibertyFin\Servicio\Modulos::enPlan($k); ?>
              <label class="lf-bv-mod<?= $enPlan ? '' : ' candado' ?>" data-mod="<?= P::e($k) ?>">
                <input type="checkbox" name="modulos[]" value="<?= P::e($k) ?>" <?= $enPlan && in_array($k, $modsIni, true) ? 'checked' : '' ?> <?= $enPlan ? '' : 'disabled' ?>>
                <span class="ico"><?= W::icono($m['icono'], '20px') ?></span>
                <span class="txt"><b><?= P::e($m['nombre']) ?></b><small><?= P::e($m['desc']) ?></small>
                  <?php if (!$enPlan): $pl = \LibertyFin\Servicio\Modulos::planesCon($k); ?>
                    <em><?= $pl ? 'Desde el plan ' . P::e($pl[0]) : 'En un plan superior' ?></em>
                  <?php elseif ($k === 'compras'): ?>
                    <em class="req" hidden>Necesita que vendas productos</em>
                  <?php endif; ?></span>
                <i class="ok" aria-hidden="true"></i>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <?php /* ── 7 · Áreas ── */ ?>
        <section class="paso" data-paso="6" data-areas>
          <p class="eyebrow">Tu catálogo</p>
          <h1>¿Cómo ordenamos lo que vendes?</h1>
          <p class="sub">Las áreas agrupan tus productos en Caja y en los reportes. Te sugerimos estas; quita o agrega las que quieras.</p>
          <div class="lf-bv-areas" id="bvAreas"></div>
          <div class="lf-bv-otra">
            <input class="form-control" type="text" id="bvOtra" maxlength="80" placeholder="Otra área, por ejemplo «Asesorías»">
            <button type="button" class="btn btn-secondary" id="bvOtraBtn">Agregar</button>
          </div>
          <?php if ($areasYa): ?>
            <p class="ya">Ya tienes: <?= P::e(implode(', ', $areasYa)) ?>.</p>
          <?php endif; ?>
        </section>

        <?php /* ── 8 · La marca ── */ ?>
        <section class="paso" data-paso="7">
          <p class="eyebrow">Tu marca</p>
          <h1>Que se vea como tu negocio</h1>
          <p class="sub">Tu logotipo y tu color aparecen en el menú, en los tickets y en todo el sistema.</p>
          <div class="lf-bv-marca-grid">
            <label class="lf-bv-logo" id="bvLogoCaja">
              <input type="file" name="logo" id="bvLogo" accept="image/png,image/jpeg,image/webp">
              <span class="vista" id="bvLogoVista"><?php if ($marca['logo']): ?><img src="<?= P::e($marca['logo']) ?>" alt=""><?php else: ?><?= W::icono('mas', '22px') ?><?php endif; ?></span>
              <b>Tu logotipo</b><small id="bvLogoNota"><?= $marca['logo'] ? 'Ya tienes uno. Toca para cambiarlo.' : 'PNG o JPG, fondo transparente si puedes. Opcional.' ?></small>
            </label>
            <fieldset class="lf-bv-colores">
              <legend>Tu color</legend>
              <div class="sw">
                <?php foreach ($colores as $hex => $nom): ?>
                  <label title="<?= P::e($nom) ?>"><input type="radio" name="marca_color" value="<?= P::e($hex) ?>" <?= strtolower($hex) === strtolower($color) ? 'checked' : '' ?>>
                    <span style="background:<?= P::e($hex) ?>"></span></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          </div>
        </section>

        <?php /* ── 9 · Resumen ── */ ?>
        <section class="paso" data-paso="8">
          <p class="eyebrow">Todo listo</p>
          <h1>Así quedó tu Libertyfin</h1>
          <p class="sub">Puedes cambiar cualquier cosa después en Ajustes.</p>
          <ul class="lf-bv-resumen" id="bvResumen"></ul>
        </section>

        <noscript><button class="lf-rg-boton" type="submit">Guardar y entrar</button></noscript>
      </main>

      <?php /* ── La vista previa del menú ── */ ?>
      <aside class="lf-bv-vista" aria-hidden="true">
        <div class="mini">
          <div class="mini-side">
            <div class="mini-marca"><span class="g" id="bvMiniLogo"><?php if ($marca['logo']): ?><img src="<?= P::e($marca['logo']) ?>" alt=""><?php else: ?><?= P::e(mb_strtoupper(mb_substr($emp, 0, 1))) ?><?php endif; ?></span>
              <b><?= P::e($emp) ?></b></div>
            <ul id="bvMenu">
              <?php foreach ([
                ['panel', 'panel', 'Panel'], ['caja', 'caja', 'Caja'], ['agenda', 'agenda', 'Agenda'], ['ventas', 'venta', 'Ventas'],
                ['clientes', 'cliente', 'Clientes'], ['cotizaciones', 'doc', 'Cotizaciones'], ['seguimiento', 'tablero', 'Trabajos'],
                ['cobranza', 'reloj', 'Cobranza'], ['igualas', 'repetir', 'Igualas'],
                ['comisiones', 'comi', 'Comisiones'], ['gastos', 'baja', 'Gastos'],
                ['productos', 'serv', 'Productos'], ['inventario', 'bolsa', 'Inventario'], ['compras', 'camion', 'Compras'],
                ['reportes', 'pct', 'Reportes'], ['ajustes', 'serv', 'Ajustes'],
              ] as $m): ?>
                <li data-k="<?= $m[0] ?>"<?= in_array($m[0], A::MODULOS, true) ? ' data-mod' : '' ?>><?= W::icono($m[1], '13px') ?><span><?= P::e($m[2]) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="mini-main">
            <i class="b w60"></i><i class="b w35 t"></i>
            <div class="cards"><i></i><i></i><i></i></div>
            <i class="b w90"></i><i class="b w75"></i><i class="b w80"></i>
          </div>
        </div>
        <p>Así se verá tu menú</p>
      </aside>
    </div>

    <footer class="lf-bv-pie">
      <button type="button" class="lf-bv-atras" id="bvAtras">Atrás</button>
      <button type="button" class="lf-rg-boton" id="bvSig"><span id="bvSigTxt">Siguiente</span>
        <svg class="fl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
        <span class="giro" aria-hidden="true"></span></button>
    </footer>
  </form>

  <form method="post" action="/bienvenida" id="bvSaltar" hidden>
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="saltar" value="1">
  </form>
</div>

<script>
(function(){
  var bv = document.getElementById('lfBv'), f = document.getElementById('bvForm');
  if (!bv || !f) return;
  bv.classList.add('js');
  var $ = function(id){ return document.getElementById(id); };
  var pasos = [].slice.call(f.querySelectorAll('.paso')), n = pasos.length, i = 0;
  var PERFILES = <?= json_encode(array_map(function ($p) {
      return ['nombre' => $p['nombre'], 'vende' => $p['vende'] ?? null, 'cobro' => $p['cobro'] ?? null,
              'iva' => $p['iva'] ?? null, 'comisiones' => $p['comisiones'] ?? null, 'areas' => $p['areas'] ?? [],
              'modulos' => $p['modulos'] ?? []];
  }, A::elegibles()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var YA = <?= json_encode(array_map('mb_strtolower', $areasYa), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var tocado = {}, areasTocadas = false, logoCambio = false, modsTocados = <?= $repite ? 'true' : 'false' ?>;
  var NOMBRE_MOD = <?= json_encode(array_map(function ($m) { return $m['nombre']; }, $MC), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  function modOn(k){ var c = f.querySelector('input[name="modulos[]"][value="' + k + '"]'); return !!(c && c.checked && !c.disabled); }
  /* Los módulos que sugiere el giro (los que el plan permite). */
  function sugerirMods(){
    var p = PERFILES[val('perfil')] || {modulos: []};
    f.querySelectorAll('input[name="modulos[]"]').forEach(function(c){ if (!c.disabled || c.dataset.req) c.checked = p.modulos.indexOf(c.value) !== -1 && !c.closest('.candado'); });
    reqCompras();
  }
  /* Compras necesita Inventario: con solo servicios no se puede. */
  function reqCompras(){
    var c = f.querySelector('input[name="modulos[]"][value="compras"]');
    if (!c || c.closest('.candado')) return;
    var sinInv = val('vende') === 'servicios';
    c.disabled = sinInv; c.dataset.req = '1';
    if (sinInv) c.checked = false;
    var em = c.closest('label').querySelector('.req'); if (em) em.hidden = !sinInv;
    c.closest('label').classList.toggle('apagado', sinInv);
  }
  function val(campo){ var r = f.querySelector('input[name="' + campo + '"]:checked'); return r ? r.value : ''; }
  function marcar(campo, v){ var r = f.querySelector('input[name="' + campo + '"][value="' + v + '"]'); if (r) r.checked = true; }
  function esc(t){ var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
  function colorNombre(){ var r = f.querySelector('input[name="marca_color"]:checked'); return r ? (r.parentNode.title || '').toLowerCase() : ''; }

  /* ── Las áreas: chips que se pueden quitar, más las que agregue ── */
  function chip(nombre, on){
    return '<label class="chip"><input type="checkbox" name="areas[]" value="' + esc(nombre) + '"' + (on ? ' checked' : '')
         + '><span>' + esc(nombre) + '</span></label>';
  }
  function pintarAreas(){
    var p = PERFILES[val('perfil')] || {areas: []};
    var sug = p.areas.filter(function(a){ return YA.indexOf(a.toLowerCase()) === -1; });
    $('bvAreas').innerHTML = sug.length ? sug.map(function(a){ return chip(a, true); }).join('')
      : '<p class="vacio">Sin sugerencias para este giro. Agrega las tuyas o sigue: se crean después en Ajustes.</p>';
  }
  function agregarArea(){
    var v = $('bvOtra').value.trim().replace(/\s+/g, ' ');
    if (!v) return;
    var ya = [].slice.call(f.querySelectorAll('input[name="areas[]"]')).some(function(c){ return c.value.toLowerCase() === v.toLowerCase(); });
    if (!ya && YA.indexOf(v.toLowerCase()) === -1) {
      var vacio = $('bvAreas').querySelector('.vacio'); if (vacio) vacio.remove();
      $('bvAreas').insertAdjacentHTML('beforeend', chip(v, true));
    }
    areasTocadas = true; $('bvOtra').value = ''; $('bvOtra').focus();
  }
  $('bvOtraBtn').addEventListener('click', agregarArea);
  $('bvOtra').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); agregarArea(); } });
  $('bvAreas').addEventListener('change', function(){ areasTocadas = true; });

  /* ── La vista previa ── */
  function vista(){
    var vende = val('vende'), com = val('comisiones');
    [].forEach.call($('bvMenu').children, function(li){
      var k = li.dataset.k, ver = true;
      if (k === 'inventario') ver = vende === 'productos' || vende === 'ambos';
      if (k === 'comisiones') ver = com !== 'no';
      if (li.hasAttribute('data-mod')) ver = modOn(k);
      li.classList.toggle('fuera', !ver);
    });
    var c = val('marca_color'); if (c) document.documentElement.style.setProperty('--lf-brand', c);
  }

  /* ── El resumen ── */
  function resumen(){
    var p = PERFILES[val('perfil')] || {nombre: ''};
    var areas = [].slice.call(f.querySelectorAll('input[name="areas[]"]:checked')).map(function(c){ return c.value; });
    var vende = val('vende'), iva = val('iva');
    var filas = [
      ['Giro', p.nombre],
      ['Inventario', vende === 'servicios' ? 'Apagado: vendes servicios' : 'Encendido: Caja descuenta tus existencias'],
      ['Caja', val('cobro') === 'anticipo' ? 'Empieza con el anticipo vacío; el saldo queda pendiente' : 'Empieza cobrando la venta completa'],
      ['IVA', iva === 'sin' ? 'Apagado al empezar cada venta' : 'Encendido, ' + iva + '% (tus precios ya lo incluyen)'],
      ['Comisiones', val('comisiones') === 'si' ? 'Encendidas' : 'Ocultas'],
      ['Herramientas', [].slice.call(f.querySelectorAll('input[name="modulos[]"]:checked:not(:disabled)')).map(function(c){ return NOMBRE_MOD[c.value]; }).join(', ') || 'Ninguna por ahora'],
      ['Áreas', areas.length ? areas.join(', ') : 'Ninguna nueva'],
      ['Marca', (logoCambio ? 'Logotipo nuevo · ' : '') + 'Color ' + colorNombre()]
    ];
    $('bvResumen').innerHTML = filas.map(function(r){
      return '<li><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></li>';
    }).join('');
  }

  /* ── Moverse entre pasos ── */
  function ir(k){
    i = Math.max(0, Math.min(n - 1, k));
    pasos.forEach(function(p, j){ p.classList.toggle('on', j === i); });
    $('bvBarra').style.width = Math.round((i + 1) / n * 100) + '%';
    $('bvNum').textContent = (i + 1) + ' de ' + n;
    $('bvAtras').style.visibility = i === 0 ? 'hidden' : 'visible';
    var ultimo = i === n - 1;
    $('bvSigTxt').textContent = ultimo ? 'Entrar a Libertyfin' : 'Siguiente';
    if (ultimo) resumen();
    if (pasos[i].hasAttribute('data-areas') && !areasTocadas) pintarAreas();
    window.scrollTo(0, 0);
    var foco = pasos[i].querySelector('input:checked, input, button'); if (foco && pasos[i].dataset.campo) foco.focus({preventScroll: true});
  }
  function completo(){
    var campo = pasos[i].dataset.campo;
    return !campo || !!val(campo);
  }
  $('bvSig').addEventListener('click', function(){
    if (!completo()) { pasos[i].classList.add('falta'); setTimeout(function(){ pasos[i].classList.remove('falta'); }, 600); return; }
    if (i < n - 1) return ir(i + 1);
    var b = $('bvSig'); b.classList.add('cargando'); b.disabled = true;
    f.submit();
  });
  $('bvAtras').addEventListener('click', function(){ ir(i - 1); });

  /* Elegir una tarjeta avanza sola: es una decisión, no un formulario. */
  f.addEventListener('change', function(e){
    var t = e.target;
    if (t.name === 'perfil') {
      // El giro sugiere las respuestas siguientes que todavía no tocó.
      var p = PERFILES[t.value];
      ['vende', 'cobro', 'iva', 'comisiones'].forEach(function(c){ if (!tocado[c] && p && p[c]) marcar(c, p[c]); });
      if (!areasTocadas) pintarAreas();
      if (!modsTocados) sugerirMods();
    } else if (['vende', 'cobro', 'iva', 'comisiones'].indexOf(t.name) !== -1) {
      tocado[t.name] = true;
    } else if (t.name === 'modulos[]') {
      modsTocados = true;
    }
    if (t.name === 'vende' || t.name === 'perfil') reqCompras();
    vista();
    if (t.type === 'radio' && t.name !== 'marca_color' && pasos[i].dataset.campo === t.name) {
      setTimeout(function(){ ir(i + 1); }, 260);
    }
  });

  /* El logotipo: se ve al momento, en la tarjeta y en la vista previa. */
  $('bvLogo').addEventListener('change', function(){
    var a = this.files && this.files[0];
    if (!a) return;
    if (a.size > 2 * 1024 * 1024) { $('bvLogoNota').textContent = 'Pesa más de 2 MB: elige uno más ligero.'; this.value = ''; return; }
    var lector = new FileReader();
    lector.onload = function(){
      var img = '<img src="' + lector.result + '" alt="">';
      $('bvLogoVista').innerHTML = img; $('bvMiniLogo').innerHTML = img;
      $('bvLogoNota').textContent = a.name; logoCambio = true;
    };
    lector.readAsDataURL(a);
  });

  /* Enter avanza (no manda el formulario a medias). */
  f.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target.id !== 'bvOtra' && e.target.tagName !== 'BUTTON') { e.preventDefault(); $('bvSig').click(); }
  });

  pintarAreas(); reqCompras(); vista(); ir(0);
})();
</script>
