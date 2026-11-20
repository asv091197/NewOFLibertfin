<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Captura as C;

/* La pantalla de capturar con la pistola. Va en modo concentrado: sin
   menú y sin barra, para que quien tiene una caja en brazos no toque
   otra cosa por error. */
$c = $captura;
$pausada = $c['estado'] === 'pausada';
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:14px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($pausada): ?>
<div class="alert alert-warning" style="margin-bottom:14px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Está en pausa.</b> Lo que llevas capturado se quedó guardado.
    Dale a <b>Seguir capturando</b> cuando vuelvas.</span>
</div>
<?php endif; ?>

<?php /* El contador grande. Es lo que se mira de reojo mientras se pasa
         la pistola, así que va arriba y con letra de lejos. */ ?>
<div class="lf-stats" style="margin-bottom:16px">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('caja','19px') ?></div>
    <div>
      <div class="n" style="font-size:30px"><?= P::e(C::num($c['piezas'])) ?></div>
      <div class="t">piezas capturadas</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('lista','19px') ?></div>
    <div>
      <div class="n"><?= count($c['lineas']) ?></div>
      <div class="t"><?= count($c['lineas']) === 1 ? 'producto' : 'productos distintos' ?></div>
    </div>
  </div>
</div>

<?php if (!$c['lineas']): ?>
  <p class="lf-inv-vacio" style="padding:30px 10px">
    Pasa el primer código con la pistola.<br>
    <small>También puedes escribir el código o parte del nombre en la barra de abajo
      y elegir el producto de la lista.</small>
  </p>
<?php else: ?>
<section class="card" style="padding:0 10px 4px">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Producto</th><th class="text-end">Capturado</th>
                 <th class="text-end">Existencia</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($c['lineas'] as $l): ?>
        <tr>
          <td data-label="Producto">
            <b><?= P::e($l['nombre']) ?></b>
            <small class="lf-inv-cod" style="display:block">
              <?= P::e($l['codigo_barras'] ?: $l['codigo']) ?></small>
          </td>
          <td data-label="Capturado" class="text-end lf-mono" style="font-size:16px">
            <b><?= P::e(C::num($l['cantidad'])) ?></b></td>
          <td data-label="Existencia" class="text-end lf-mono" style="color:var(--lf-tinta-4)">
            <?= P::e(C::num($l['existencia'])) ?></td>
          <td class="lf-inv-acc" style="white-space:nowrap">
            <?php /* Corregir sin tener que borrar y volver a escanear:
                     se pasa una caja de más y se quita ahí mismo. */ ?>
            <button type="button" class="lf-btn-ghost lf-ajustar"
                    data-id="<?= (int)$l['producto_id'] ?>"
                    data-n="<?= P::e($l['nombre']) ?>"
                    data-c="<?= P::e(C::num($l['cantidad'])) ?>"
                    title="Corregir la cantidad">Corregir</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<form method="post" action="/inventario/captura" id="fCap" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <input type="hidden" name="sucursal_id" value="<?= (int)($sucursal ?? $c['sucursal_id']) ?>">
  <input type="hidden" name="accion" id="capAccion">
  <input type="hidden" name="producto_id" id="capProd">
  <input type="hidden" name="cantidad" id="capCant">
</form>

<script>
(function () {
  var f = document.getElementById('fCap');
  var mandar = function (accion, extra) {
    document.getElementById('capAccion').value = accion;
    if (extra) {
      document.getElementById('capProd').value = extra.producto || '';
      document.getElementById('capCant').value = extra.cantidad || '';
    }
    f.submit();
  };
  window.lfCap = mandar;

  // La barra del código va en el pie del layout, DESPUÉS de este script:
  // hay que esperar a que exista. (Antes se buscaba aquí mismo, salía
  // null y nada de lo de abajo corría.)
  document.addEventListener('DOMContentLoaded', function () {
    var campo = document.getElementById('capCodigo');
    if (!campo) return;
    var lista = document.getElementById('capLista'),
        lupa  = document.getElementById('capTeclado'),
        form  = document.getElementById('capForm'),
        cant  = document.getElementById('capCantidad');

    // ── El foco vuelve al campo del código ──
    // La pistola es un teclado: escribe donde esté el foco y manda Enter.
    // Si el foco se pierde —un clic en la tabla, volver de otra pestaña—
    // el siguiente disparo se va al vacío y la caja no se cuenta.
    // Pero solo si el foco no está en OTRO campo: si no, no se podía
    // escribir la cantidad, porque el foco se la robaba a medio número.
    var libre = function () {
      var a = document.activeElement;
      return !a || a === document.body || !a.closest('input, select, textarea, button, a');
    };
    var volver = function () { if (document.activeElement !== campo && libre()) campo.focus(); };
    campo.focus();
    setInterval(volver, 700);
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('button, a, input, select, textarea, li')) volver();
    });
    window.addEventListener('focus', volver);

    // ── Buscador ──
    // El mismo campo sirve para escanear y para buscar. La pistola teclea
    // muy rápido y manda Enter antes de que salga la lista, así que el
    // escaneo no cambia. A mano, al escribir parte del nombre, aparece la
    // lista; se elige con las flechas y Enter, o tocándola.
    var t = null, pedido = 0, activa = -1, items = [];
    function cerrar() { lista.hidden = true; lista.innerHTML = ''; items = []; activa = -1;
                        campo.setAttribute('aria-expanded', 'false'); }
    function elegir(p) {
      // El SKU es único: con él el servidor lo encuentra sin adivinar.
      campo.value = p.codigo || p.barras || p.nombre;
      cerrar();
      form.submit();
    }
    function marcar(i) {
      var lis = lista.querySelectorAll('li[data-i]');
      if (!lis.length) return;
      activa = (i + lis.length) % lis.length;
      lis.forEach(function (li, k) { li.classList.toggle('activa', k === activa); });
      lis[activa].scrollIntoView({block: 'nearest'});
    }
    function nota(txt) { lista.innerHTML = ''; var li = document.createElement('li'); li.className = 'nota';
                         li.textContent = txt; lista.appendChild(li); lista.hidden = false; }
    function buscar(q) {
      var mio = ++pedido;
      fetch('/inventario/buscar?s=' + encodeURIComponent(campo.dataset.s || '') + '&q=' + encodeURIComponent(q),
            {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (mio !== pedido || campo.value.trim() !== q) return;
          items = d.productos || [];
          if (!items.length) { nota('Nada con «' + q + '». Si es un código, dale Enter.'); return; }
          lista.innerHTML = ''; activa = -1;
          items.forEach(function (p, i) {
            var li = document.createElement('li'), b = document.createElement('b'),
                s = document.createElement('small'), h = document.createElement('span');
            li.setAttribute('role', 'option'); li.dataset.i = i;
            b.textContent = p.nombre;
            s.textContent = [p.codigo, p.barras].filter(Boolean).join(' · ');
            h.textContent = p.hay; h.className = 'hay';
            li.appendChild(b); li.appendChild(s); li.appendChild(h);
            li.addEventListener('mousedown', function (ev) { ev.preventDefault(); elegir(p); });
            lista.appendChild(li);
          });
          lista.hidden = false; campo.setAttribute('aria-expanded', 'true');
        })
        .catch(function () { if (mio === pedido) nota('No se pudo buscar.'); });
    }
    campo.addEventListener('input', function () {
      clearTimeout(t);
      var q = campo.value.trim();
      // Solo números y largo de código: es la pistola o un código tecleado.
      if (q.length < 2 || /^\d{6,}$/.test(q)) { cerrar(); return; }
      t = setTimeout(function () { buscar(q); }, 280);
    });
    campo.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && !lista.hidden) { e.preventDefault(); marcar(activa + 1); }
      else if (e.key === 'ArrowUp' && !lista.hidden) { e.preventDefault(); marcar(activa - 1); }
      else if (e.key === 'Escape') { cerrar(); }
      else if (e.key === 'Enter') {
        clearTimeout(t);
        if (!lista.hidden && activa >= 0 && items[activa]) { e.preventDefault(); elegir(items[activa]); }
        else if (!lista.hidden && items.length === 1) { e.preventDefault(); elegir(items[0]); }
        // Si no, se manda tal cual: es lo que hace la pistola.
      }
    });
    campo.addEventListener('blur', function () { setTimeout(cerrar, 150); });

    // La lupa trae el teclado en tabletas (el campo nace sin él para que
    // no tape la pantalla al escanear).
    if (lupa) lupa.addEventListener('click', function () {
      var conTeclado = campo.getAttribute('inputmode') !== 'none';
      campo.setAttribute('inputmode', conTeclado ? 'none' : 'search');
      lupa.classList.toggle('on', !conTeclado);
      campo.blur(); campo.focus();
    });
    // Enter en la cantidad: si ya hay código, se agrega; si no, se pasa al
    // código para escanear con esa cantidad.
    if (cant) cant.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && campo.value.trim() === '') { e.preventDefault(); campo.focus(); }
    });
  });

  document.querySelectorAll('.lf-ajustar').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = prompt('¿Cuántas de «' + b.dataset.n + '»?\n\n'
                   + 'Pon 0 para quitarlo de la captura.', b.dataset.c);
      if (v === null) return;
      if (isNaN(parseFloat(v)) || parseFloat(v) < 0) { alert('Escribe un número.'); return; }
      mandar('ajustar', { producto: b.dataset.id, cantidad: parseFloat(v) });
    });
  });
})();
</script>