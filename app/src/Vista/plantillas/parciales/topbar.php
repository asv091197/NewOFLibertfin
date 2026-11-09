<?php
use LibertyFin\Vista\Widget as W;
use LibertyFin\Vista\Plantilla as P;

/**
 * La barra de arriba: título, campana y tema.
 *
 * Aquí iba un botón por sección ("Nueva venta", "Nuevo cliente", "Nuevo
 * gasto", "Nuevo producto"). Se quitó por redundante: lo mismo está en el
 * menú lateral, y en cada pantalla su propio botón de alta.
 */
?>
<header class="lf-top">
  <div class="lf-top-tit">
    <h1><?= W::icono($icono, '18px') ?><span><?= P::e($titulo) ?></span></h1>
    <?php if ($subtitulo): ?><p><?= P::e($subtitulo) ?></p><?php endif; ?>
  </div>

  <div class="lf-acc">
    <?php /* ── La campana del negocio ──
             Lo que hay que atender hoy: lo que se está acabando, la caja
             que quedó abierta de ayer, la captura que alguien dejó a
             medias. No son mensajes que alguien mandó, son situaciones
             que están pasando: por eso se calculan al vuelo y
             desaparecen solas cuando se arreglan.

             El número se guarda dos minutos en la sesión. La campana
             sale en todas las pantallas, y sin eso cada clic del menú
             dispararía cuatro consultas. */
    $lfNotis = [];
    if (!empty($_SESSION['empresa_db'])) {
      try {
        $lfNotis = (new \LibertyFin\Servicio\Notificaciones(
            \LibertyFin\Datos\Conexion::de($_SESSION['empresa_db'])
        ))->todas((int)($_SESSION['sucursal_id'] ?? 1));
      } catch (\Throwable $e) { $lfNotis = []; }
    }
    $lfUrgentes = 0;
    foreach ($lfNotis as $x) if ($x['urgente']) $lfUrgentes++;
    ?>
    <div class="lf-campana" data-lf-notis>
      <button type="button" class="lf-campana-bt" id="lfNotiBt"
              aria-haspopup="true" aria-expanded="false"
              aria-label="<?= $lfNotis ? count($lfNotis) . ' cosas por atender' : 'Nada por atender' ?>"
              title="Por atender">
        <?= W::icono('campana', '17px') ?>
        <?php if ($lfNotis): ?>
          <span class="lf-noti-num<?= $lfUrgentes ? ' lf-noti-urge' : '' ?>"><?= count($lfNotis) ?></span>
        <?php endif; ?>
      </button>

      <div class="lf-noti-panel" id="lfNotiPanel" hidden role="menu">
        <div class="lf-noti-tit">Por atender</div>
        <?php if (!$lfNotis): ?>
          <div class="lf-noti-vacio">
            <?= W::icono('ok','22px') ?>
            <p>Nada pendiente por ahora.</p>
          </div>
        <?php else: foreach ($lfNotis as $x): ?>
          <a class="lf-noti-it<?= $x['urgente'] ? ' urge' : '' ?>" href="<?= P::e($x['url']) ?>" role="menuitem">
            <span class="ic"><?= W::icono($x['icono'], '15px') ?></span>
            <span class="tx">
              <b><?= P::e($x['texto']) ?></b>
              <small><?= P::e($x['detalle']) ?></small>
            </span>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php /* La campana de soporte: mensajes de clientes que no has leído.
             La llena assets/js/lf-chat.js; aquí solo va el lugar. */
    if (\LibertyFin\Dominio\Permisos::puede('ver.tickets')): ?>
      <div class="lf-campana" data-lf-campana>
        <button type="button" class="lf-campana-bt" aria-haspopup="true" aria-expanded="false"
                aria-label="Mensajes de clientes sin leer" title="Mensajes de clientes sin leer">
          <?= W::icono('campana', '17px') ?>
          <span class="lf-noti-num" hidden></span>
        </button>
      </div>
    <?php endif; ?>

    <?php /* `data-lf-cuenta`: soporte guarda el tema en su cuenta, no solo aquí. */ ?>
    <button type="button" class="lf-tema" id="lfTema"<?= !empty($_SESSION['plataforma']) ? ' data-lf-cuenta' : '' ?>
            aria-label="Cambiar entre claro y oscuro" title="Cambiar tema">
      <span class="claro"><?= W::icono('luna', '16px') ?></span>
      <span class="oscuro"><?= W::icono('sol', '16px') ?></span>
    </button>
  </div>
</header>

<style>
/* El panel de «Por atender». Va aquí y no en la hoja general porque
   solo existe en esta barra. */
.lf-noti-panel {
  position: absolute; right: 0; top: calc(100% + 8px); z-index: 60;
  width: min(360px, calc(100vw - 24px));
  background: var(--lf-papel, #fff);
  border: 1px solid var(--lf-linea, #e5e7eb);
  border-radius: 12px; box-shadow: 0 12px 34px rgba(0,0,0,.14);
  overflow: hidden; max-height: min(70vh, 520px); overflow-y: auto;
}
.lf-noti-tit {
  padding: 11px 14px; font-size: 12px; font-weight: 650;
  color: var(--lf-tinta-3, #374151);
  border-bottom: 1px solid var(--lf-linea, #e5e7eb);
  position: sticky; top: 0; background: var(--lf-papel, #fff);
}
.lf-noti-it {
  display: flex; gap: 11px; align-items: flex-start;
  padding: 11px 14px; text-decoration: none; color: inherit;
  border-bottom: 1px solid var(--lf-linea, #f0f1f3);
}
.lf-noti-it:last-child { border-bottom: 0; }
.lf-noti-it:hover { background: var(--lf-fondo, #f6f7f9); }
.lf-noti-it .ic { flex: 0 0 auto; margin-top: 1px; color: var(--lf-tinta-4, #6b7280); }
.lf-noti-it.urge .ic { color: var(--lf-rojo, #b91c1c); }
.lf-noti-it .tx { min-width: 0; }
.lf-noti-it .tx b { display: block; font-size: 13px; font-weight: 600; }
.lf-noti-it .tx small {
  display: block; font-size: 11.5px; color: var(--lf-tinta-4, #6b7280);
  margin-top: 2px; line-height: 1.45;
}
.lf-noti-vacio { padding: 26px 14px; text-align: center; color: var(--lf-tinta-4, #6b7280); }
.lf-noti-vacio p { margin: 8px 0 0; font-size: 12.5px; }
.lf-noti-num.lf-noti-urge { background: var(--lf-rojo, #b91c1c); }
</style>

<script>
(function () {
  // La campana del negocio. Delegado en document por lo mismo que la de
  // abajo: al navegar sin recarga la barra se reemplaza entera.
  document.addEventListener('click', function (ev) {
    var bt = ev.target.closest('#lfNotiBt');
    var panel = document.getElementById('lfNotiPanel');
    if (!panel) return;
    if (bt) {
      ev.preventDefault();
      var abierto = !panel.hidden;
      panel.hidden = abierto;
      bt.setAttribute('aria-expanded', abierto ? 'false' : 'true');
      return;
    }
    // Un clic fuera la cierra; uno dentro no, para poder usar los enlaces.
    if (!ev.target.closest('#lfNotiPanel')) panel.hidden = true;
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var p = document.getElementById('lfNotiPanel');
    if (p && !p.hidden) {
      p.hidden = true;
      var b = document.getElementById('lfNotiBt');
      if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
    }
  });
})();
</script>

<script>
(function(){
  /* Delegado en document: la navegación sin recarga reemplaza el
     innerHTML de la barra, y un listener puesto directo en el botón
     se perdía en la primera navegación. */
  if (window.lfTemaListo) return;
  window.lfTemaListo = true;
  document.addEventListener('click', function(ev){
    var b = ev.target.closest('#lfTema');
    if (!b) return;
    var raiz = document.documentElement;
    var nuevo = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    if (window.lfAplicarTema) window.lfAplicarTema(nuevo);
    else raiz.setAttribute('data-theme', nuevo);
    try { localStorage.setItem('lf-tema', nuevo); } catch (e) {}
    // Cuenta de soporte: también en su cuenta, para que la siga a otro equipo.
    if (b.hasAttribute('data-lf-cuenta') && window.fetch) {
      var d = new FormData();
      d.append('token', document.body.getAttribute('data-lf-token') || '');
      d.append('tema', nuevo);
      fetch('/cuenta/apariencia', { method: 'POST', body: d, credentials: 'same-origin',
        headers: { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(null, function () {});
    }
  });
})();
</script>