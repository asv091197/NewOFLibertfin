<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;

/* Elegir a un cliente escribiendo su nombre (busca en /caja/clientes).
   Lo usan Cotizaciones, Agenda e Igualas.

     $pref     prefijo de los id del HTML (dos en la misma página no chocan)
     $idVal    id del cliente ya elegido, o ''
     $nomVal   nombre que se muestra
     $exigir   true: tiene que ser uno de la lista (Igualas); false: vale
               un nombre suelto (un prospecto que todavía no es cliente)
     $etiqueta el texto de arriba
     $alElegir (opcional) nombre de una función global que se llama con
               {id, nombre, telefono} al elegir */
$pref = $pref ?? 'cli';
$exigir = !empty($exigir);
?>
<div class="lf-elige-cli" data-elige-cli data-exigir="<?= $exigir ? '1' : '' ?>" data-al-elegir="<?= P::e($alElegir ?? '') ?>">
  <label class="form-label" for="<?= P::e($pref) ?>Nombre"><?= P::e($etiqueta ?? 'Cliente') ?></label>
  <div class="lf-search">
    <?= W::icono('cliente', '15px') ?>
    <input type="text" id="<?= P::e($pref) ?>Nombre" name="cliente_nombre" maxlength="200" autocomplete="off"
           value="<?= P::e($nomVal ?? '') ?>" <?= $exigir ? 'required' : '' ?>
           placeholder="<?= $exigir ? 'Escribe y elige de la lista' : 'Escribe su nombre o elígelo de la lista' ?>">
  </div>
  <input type="hidden" name="cliente_id" id="<?= P::e($pref) ?>Id" value="<?= P::e($idVal ?? '') ?>">
  <div class="lf-sugerencias" hidden></div>
  <small class="lf-elige-cli-pista" aria-live="polite"><?= ($idVal ?? '') !== '' ? '✓ Cliente del catálogo' : '' ?></small>
</div>
<?php if (empty($GLOBALS['lf_elige_cli_js'])): $GLOBALS['lf_elige_cli_js'] = true; ?>
<script>
window.lfEligeCli = function(){
  function esc(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  document.querySelectorAll('[data-elige-cli]').forEach(function(box){
    if (box.dataset.listo) return;
    box.dataset.listo = '1';
    var inp = box.querySelector('input[type="text"]'), hid = box.querySelector('input[type="hidden"]'),
        sug = box.querySelector('.lf-sugerencias'), pista = box.querySelector('.lf-elige-cli-pista');
    var exigir = !!box.dataset.exigir, t = null;
    function marcar(){
      pista.textContent = hid.value ? '✓ Cliente del catálogo' : (inp.value.trim() && !exigir ? 'Nombre suelto: no se da de alta como cliente.' : '');
      inp.setCustomValidity(exigir && !hid.value && inp.value.trim() ? 'Elige al cliente de la lista.' : '');
    }
    inp.addEventListener('input', function(){
      hid.value = ''; marcar();
      var q = inp.value.trim();
      clearTimeout(t);
      if (q.length < 2) { sug.hidden = true; return; }
      t = setTimeout(function(){
        fetch('/caja/clientes?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(cs){
            sug.innerHTML = '';
            (cs || []).forEach(function(c){
              var b = document.createElement('button');
              b.type = 'button';
              b.innerHTML = '<b>' + esc(c.nombre) + '</b><small>' + esc(c.telefono || (c.compras > 0 ? c.compras + ' compras' : 'Sin compras')) + '</small>';
              b.addEventListener('click', function(){
                hid.value = c.id; inp.value = c.nombre; sug.hidden = true; marcar();
                var f = box.dataset.alElegir && window[box.dataset.alElegir];
                if (typeof f === 'function') f(c);
              });
              sug.appendChild(b);
            });
            sug.hidden = !(cs && cs.length);
          }).catch(function(){ sug.hidden = true; });
      }, 220);
    });
    document.addEventListener('click', function(e){ if (!box.contains(e.target)) sug.hidden = true; });
    marcar();
  });
};
</script>
<?php endif; ?>
<script>window.lfEligeCli();</script>
