<?php
use LibertyFin\Vista\Plantilla as P;
$token = $_SESSION['lf_token'];
?>
<main class="lf-entrada">
  <div class="lf-logo">
    <?php
    /**
     * EL LOGOTIPO
     *
     * Pon tu PNG en  public/assets/img/logo-blanco.png  y aparece aquí
     * solo. Mientras no exista, se dibuja el texto: una imagen rota en
     * la pantalla de entrar es peor que no tener imagen, porque es lo
     * primero que ve alguien que todavía no confía en el sistema.
     *
     * Para que se vea bien sobre el verde: fondo transparente, trazo
     * blanco, y al menos 600 px de ancho.
     */
    $logo = __DIR__ . '/../../../public/assets/img/logo-blanco.png';
    ?>
    <?php /* El logo lleva a la landing, como en cualquier sitio. */ ?>
    <a href="/" aria-label="Volver al inicio">
    <?php if (is_file($logo)): ?>
      <img src="/assets/img/logo-blanco.png?v=<?= filemtime($logo) ?>" alt="LibertyFin">
    <?php else: ?>
      <span class="lf-logo-texto">Libertyfin</span>
    <?php endif; ?>
    </a>
  </div>

  <div class="lf-tarjeta">
  <?php if (!empty($pendiente)):
    /* LA CUENTA YA ESTÁ ABIERTA en otro dispositivo (ver
       Servicio\SesionUnica). La contraseña ya se comprobó: aquí solo se
       decide si se cierra la otra sesión y se entra. */
    $otra = $pendiente['otra'] ?? []; ?>
    <div class="lf-sesion-abierta" data-lf-sesion-abierta>
      <h1>Tu cuenta ya está abierta</h1>
      <p class="lf-sub">Hay una sesión activa con esta cuenta en otro dispositivo.</p>

      <div class="lf-disp">
        <span class="ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
               stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>
          </svg>
        </span>
        <span class="txt">
          <b><?= P::e($otra['dispositivo'] ?? 'Otro dispositivo') ?></b>
          <small>Activa <?= P::e(\LibertyFin\Servicio\SesionUnica::hace($otra['hace'] ?? 0)) ?></small>
        </span>
      </div>

      <p class="lf-nota">Si continúas, esa sesión se cerrará y quien la esté usando
        tendrá que volver a entrar. Si no la reconoces, entra y cambia tu contraseña.</p>

      <form method="post" action="/login/confirmar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="lf-boton" type="submit">
          <span class="txt">Cerrarla y entrar aquí</span>
        </button>
      </form>
      <a class="lf-olvide lf-cancelar" href="/login?cancelar=1">Cancelar</a>
    </div>
  <?php else: ?>
    <h1>Iniciar sesión</h1>
    <p class="lf-sub">Sistema de gestión de negocios · Multiempresa</p>

    <?php if ($error): ?>
      <div class="lf-msg err"><?= P::e($error) ?></div>
    <?php endif; ?>

    <?php if ($bloqueo > 0): ?>
      <div class="lf-msg amb">
        Demasiados intentos. Vuelve a probar en <?= ceil($bloqueo / 60) ?> minutos.
      </div>
    <?php endif; ?>

    <form method="post" action="/login" autocomplete="on" id="lfForm">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">

      <label for="usuario">Correo</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3 7 9 6 9-6"/>
        </svg>
        <input type="text" id="usuario" name="usuario" value="<?= P::e($usuario) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               placeholder="tu@correo.com"
               <?= $bloqueo > 0 ? 'disabled' : 'autofocus' ?> required>
      </div>

      <label for="clave">Contraseña</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="10.5" width="16" height="10" rx="2.5"/>
          <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
        </svg>
        <input type="password" id="clave" name="clave" autocomplete="current-password"
               placeholder="••••••••" <?= $bloqueo > 0 ? 'disabled' : '' ?> required>
        <button type="button" id="ojo" class="lf-ojo" aria-label="Mostrar contraseña">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/>
            <circle cx="12" cy="12" r="2.8"/>
          </svg>
        </button>
      </div>

      <a class="lf-olvide" href="/ayuda-acceso">¿No recuerdas tu contraseña?</a>

      <label class="lf-recordar">
        <input type="checkbox" name="recordar" value="1"
               <?= !empty($_COOKIE['lf_recordar']) ? 'checked' : '' ?>>
        <span>Recordar sesión en este dispositivo</span>
      </label>

      <button class="lf-boton" type="submit" id="btnEntrar" <?= $bloqueo > 0 ? 'disabled' : '' ?>>
        <span class="txt">Entrar al sistema</span>
        <svg class="fl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 12h13M13 6l6 6-6 6"/>
        </svg>
        <span class="giro" aria-hidden="true"></span>
      </button>
    </form>
    <?php /* La puerta para quien todavía no tiene cuenta. Antes no había:
             a /registro solo se llegaba sabiendo la dirección. */ ?>
    <p class="lf-registrate">¿Tu negocio aún no usa Libertyfin? <a href="/registro">Regístralo gratis</a></p>
  <?php endif; ?>
  </div>

  <a class="lf-volver" href="/">← Volver al inicio</a>

  <p class="lf-pie-entrada">
    © <?= date('Y') ?> Libertyfin · Todos los derechos reservados
  </p>
</main>

<script>
/* ══════════════════════════════════════════════════════
   LO QUE QUEDÓ DE LA SESIÓN ANTERIOR, FUERA
   Estar en el login significa que nadie ha entrado. Lo que el
   navegador guardó de la cuenta de antes —el ticket a medio hacer,
   las ventanas abiertas, los filtros— no tiene por qué seguir ahí, y
   si la siguiente cuenta es de otra empresa es peor que inútil: son
   conceptos ajenos listos para cobrar.
   Se borra en el login y no al pulsar «Salir» porque también hay que
   limpiar cuando la sesión caduca sola o alguien llega por un enlace.
   ══════════════════════════════════════════════════════ */
(function(){
  try {
    [sessionStorage, localStorage].forEach(function (d) {
      for (var i = d.length - 1; i >= 0; i--) {
        var k = d.key(i);
        /* El tema y el tamaño de letra SÍ se quedan: son de este
           aparato, no de la cuenta, y resetearlos en cada login sería
           pelearse con quien eligió modo oscuro. */
        if (k && k.indexOf('lf-') === 0 && k !== 'lf-tema' && k !== 'lf-letra') d.removeItem(k);
      }
    });
  } catch (e) { /* modo privado: no había nada guardado de todas formas */ }
})();
(function(){
  var o = document.getElementById('ojo'), c = document.getElementById('clave');
  if (o && c) o.addEventListener('click', function(){
    var ver = c.type === 'password';
    c.type = ver ? 'text' : 'password';
    o.classList.toggle('on', ver);
    o.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
    c.focus();
  });

  var f = document.getElementById('lfForm'),
      b = document.getElementById('btnEntrar');
  if (!f || !b) return;

  var txt   = b.querySelector('.txt'),
      lento = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Cada letra por su cuenta, para que puedan juntarse. Se hace al
     cargar y no al enviar: medir y reescribir en el mismo cuadro en que
     empieza la animación la deja a medias. El texto sigue leyéndose
     igual para un lector de pantalla porque no cambia, solo se parte. */
  var letras = [];
  if (txt && !lento) {
    var frase = txt.textContent;
    txt.textContent = '';
    for (var k = 0; k < frase.length; k++) {
      var i2 = document.createElement('i');
      i2.textContent = frase[k];
      txt.appendChild(i2);
      letras.push(i2);
    }
    var anillo = document.createElement('span');
    anillo.className = 'lf-anillo';
    anillo.setAttribute('aria-hidden', 'true');
    b.appendChild(anillo);

    /* La X blanca de cuando no entra. Se crea al cargar, como el anillo,
       para que al fallar solo haya que mostrarla. */
    var equis = document.createElement('span');
    equis.className = 'lf-equis';
    equis.setAttribute('aria-hidden', 'true');
    equis.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" '
                    + 'stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    b.appendChild(equis);
  }
  var t0 = 0;   // cuándo empezó a cerrarse el botón

  /* Hacia dónde viaja cada letra: al centro del botón. Se mide ANTES de
     encoger, porque después el botón ya no mide lo mismo. */
  function juntar() {
    if (letras.length) {
      var cb = b.getBoundingClientRect(),
          cx = cb.left + cb.width / 2,
          cy = cb.top + cb.height / 2;
      letras.forEach(function (el) {
        var r = el.getBoundingClientRect();
        el.style.setProperty('--dx', (cx - (r.left + r.width / 2)).toFixed(1) + 'px');
        el.style.setProperty('--dy', (cy - (r.top + r.height / 2)).toFixed(1) + 'px');
      });
    }
    b.classList.add('lf-cerrando');
    b.setAttribute('aria-busy', 'true');
    t0 = Date.now();
  }

  /* NO ENTRÓ. El círculo se pone rojo con una X blanca, se queda un
     momento y el botón vuelve a su tamaño normal.
     Antes se llamaba directo a soltar(): quitaba el círculo de golpe y
     las letras reaparecían mientras el botón todavía se estaba
     encogiendo. Aquí primero se espera a que el círculo esté completo
     (si el servidor contestó rápido, el botón aún iba a la mitad),
     después se muestra la X, y solo entonces se abre de vuelta.
     `alMostrar` pinta el motivo justo cuando aparece la X. */
  function fallar(alMostrar, alTerminar) {
    if (lento || !letras.length) { soltar(); alMostrar(); if (alTerminar) alTerminar(); return; }
    var falta = Math.max(0, 760 - (Date.now() - t0));
    setTimeout(function () {
      b.classList.add('lf-error');
      alMostrar();
      setTimeout(function () {
        b.classList.add('lf-vuelve');
        b.classList.remove('lf-error');
        soltar();
        if (alTerminar) alTerminar();
        setTimeout(function () { b.classList.remove('lf-vuelve'); }, 750);
      }, 950);
    }, falta);
  }

  function abrir(cuando) {
    var r = b.getBoundingClientRect(),
        cx = r.left + r.width / 2, cy = r.top + r.height / 2,
        W = innerWidth, H = innerHeight,
        /* El diámetro llega a la esquina más lejana desde el centro de
           la pantalla, con holgura: si se queda corto se ven cuatro
           picos de fondo en las esquinas. */
        d = Math.hypot(W, H) * 1.15;
    var m = document.createElement('div');
    m.className = 'lf-mancha';
    m.style.width = m.style.height = d + 'px';
    m.style.left = cx + 'px';
    m.style.top  = cy + 'px';
    document.body.appendChild(m);

    if (lento) { cuando(); return; }
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        m.style.left = (W / 2) + 'px';
        m.style.top  = (H / 2) + 'px';
        m.style.transform = 'translate(-50%,-50%) scale(1)';
      });
    });
    /* Se navega cuando la pantalla ya está tapada, no cuando termina la
       animación: los últimos milisegundos no aportan y retrasar la
       llegada sí se nota. */
    setTimeout(cuando, 470);
  }

  function soltar() {
    b.classList.remove('lf-cerrando');
    b.removeAttribute('aria-busy');
    b.disabled = false;
    letras.forEach(function (el) {
      el.style.removeProperty('--dx'); el.style.removeProperty('--dy');
    });
  }

  function decir(texto) {
    var caja = f.parentNode.querySelector('.lf-msg.err');
    if (!caja) {
      caja = document.createElement('div');
      caja.className = 'lf-msg err';
      f.parentNode.insertBefore(caja, f);
    }
    caja.textContent = texto;
    caja.setAttribute('role', 'alert');
  }

  /* Sin fetch se envía como siempre: la animación es un adorno, no
     puede ser el único camino para entrar. */
  if (!window.fetch || !window.FormData) {
    f.addEventListener('submit', function () {
      if (!f.checkValidity || f.checkValidity()) { juntar(); b.disabled = true; }
    });
    return;
  }

  f.addEventListener('submit', function (ev) {
    if (f.checkValidity && !f.checkValidity()) return;
    ev.preventDefault();
    if (b.disabled) return;
    b.disabled = true;
    juntar();

    /* Se envía POR DETRÁS para poder animar la salida. El servidor hace
       exactamente lo mismo que con un envío normal —misma validación,
       mismo conteo de intentos— y redirige; aquí solo se mira a dónde
       acabó para saber si entró. */
    fetch(f.action, {
      method: 'POST',
      body: new FormData(f),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) {
        return r.text().then(function (t) { return { url: r.url, html: t, ok: r.ok }; });
      })
      .then(function (res) {
        var destino = res.url || '/panel';
        var siguePidiendo = /\/login\/?($|\?)/.test(destino);

        if (!siguePidiendo) { abrir(function () { location.replace(destino); }); return; }

        /* No entró. El motivo viene dentro de la página que contestó el
           servidor; se saca de ahí en vez de inventarlo, para que diga
           lo mismo que diría sin JavaScript —incluido el bloqueo por
           intentos, que trae su propio texto—. */
        var doc = new DOMParser().parseFromString(res.html, 'text/html'),
            msg = doc.querySelector('.lf-msg');

        /* La contraseña era correcta, pero la cuenta está abierta en otro
           dispositivo: en vez de la X, la pregunta de si se cierra. */
        var abierta = doc.querySelector('[data-lf-sesion-abierta]');
        if (abierta) {
          soltar();
          var tarjeta = f.closest('.lf-tarjeta');
          if (tarjeta) {
            tarjeta.innerHTML = '';
            tarjeta.appendChild(document.importNode(abierta, true));
            var bt = tarjeta.querySelector('button');
            if (bt) bt.focus();
          } else {
            location.replace('/login');
          }
          return;
        }

        /* El token puede haber rotado: reenviar el viejo daría un error
           de formulario en vez del de contraseña, y nadie entendería
           por qué el segundo intento falla distinto. Se actualiza YA, no
           al terminar la animación: no depende de ella. */
        var tk = doc.querySelector('input[name=token]');
        var mio = f.querySelector('input[name=token]');
        if (tk && mio && tk.value) mio.value = tk.value;
        var bloqueado = !!doc.querySelector('#usuario[disabled]');

        fallar(function () {
          /* Sale junto con la X: el motivo y el círculo rojo llegan a la vez. */
          if (msg) {
            var vieja = f.parentNode.querySelector('.lf-msg');
            if (vieja) vieja.replaceWith(msg.cloneNode(true));
            else f.parentNode.insertBefore(msg.cloneNode(true), f);
          } else {
            decir('No se pudo entrar. Revisa tu correo y tu contraseña.');
          }
        }, function () {
          /* Si el servidor deshabilitó los campos por bloqueo, se respeta. */
          if (bloqueado) {
            f.querySelectorAll('input,button').forEach(function (e2) { e2.disabled = true; });
            return;
          }
          var cl = document.getElementById('clave');
          if (cl && !cl.disabled) { cl.value = ''; cl.focus(); }
        });
      })
      .catch(function () {
        fallar(function () {
          decir('No se pudo conectar. Revisa tu conexión e inténtalo de nuevo.');
        });
      });
  });
})();
/* Volver con el botón de atrás no debe dejar el botón girando. */
window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('btnEntrar');
  if (b) { b.classList.remove('lf-cerrando','cargando'); b.disabled = false; }
  document.querySelectorAll('.lf-mancha').forEach(function(m){ m.remove(); });
});
</script>
