/* ══════════════════════════════════════════════════════
   LibertyFin · el chat de soporte

   Cuatro piezas:

   1. CONVERSACIÓN EN VIVO. Cualquier [data-lf-chat] (la conversación de
      un ticket en Ayuda o en Tickets) se queda "escuchando": pide con
      `esperar=1` y el servidor no contesta hasta que hay algo nuevo (un
      mensaje, o el otro lado empezó o dejó de escribir), como mucho 20 s.
      En cuanto contesta se vuelve a pedir. Así un mensaje llega en menos
      de un segundo, sin WebSockets ni nada más instalado en el servidor.
      Con la pestaña en segundo plano no se espera: se pregunta cada 10 s.

      Lo que uno envía aparece AL MOMENTO ("Enviando…") y se cambia por el
      mensaje de verdad cuando el servidor lo guarda. Si falla, se queda
      marcado con "Reintentar".

      Además: emojis, pegar una captura con Ctrl+V (o arrastrarla) y la
      vista previa de lo que se va a adjuntar. La imagen se achica (WebP)
      y empieza a subir en cuanto se adjunta, mientras se termina de
      escribir: al dar Enviar ya está arriba y el mensaje sale al momento.

   2. NOVEDADES DEL CLIENTE. Para quien puede abrir reportes, cada 20 s se
      pregunta si soporte le contestó algo que no ha visto: el menú "Ayuda"
      lleva la cuenta y sale un aviso dentro de la plataforma.

   3. CHAT FLOTANTE (cliente). Una burbuja siempre a la mano: abre la
      lista de sus reportes abiertos y, de ahí, el chat de cada uno ("←"
      regresa a la lista). Con la respuesta de soporte se le abre solo,
      esté en la pantalla que esté.

   4. NOVEDADES DE SOPORTE. Para quien atiende tickets: una campana arriba
      con los mensajes de clientes que no ha leído, la cuenta en el menú y en el
      título de la pestaña, un aviso con sonido cuando un cliente escribe
      y, con la pestaña en segundo plano, un aviso del escritorio.

   Se carga una sola vez desde el layout. Las conversaciones de las
   páginas se ligan con LFChat.enlazar(), que las vistas llaman al final
   (y se vuelve a llamar al cambiar de sección sin recargar).
   ══════════════════════════════════════════════════════ */
(function () {
  if (window.LFChat) return;

  var CADA_OCULTA = 10000;     // pestaña en segundo plano: sin espera, cada 10 s
  var CADA_NOVEDADES = 20000;  // aviso de respuestas
  var CADA_ESCRIBE = 2500;     // "estoy escribiendo", como mucho cada 2.5 s
  var MAX_ARCHIVO = 10485760;  // 10 MB, lo mismo que acepta el servidor
  var TIPOS = /^(image\/(png|jpeg|webp)|application\/pdf)$/;

  var EMOJIS = ('😀 😃 😄 😁 😅 😂 🙂 😉 😊 😍 🤩 😎 🤔 🤨 😐 😕 😟 😢 😭 😤 😡 😴 '
              + '🙏 👍 👎 👌 👏 🙌 💪 🤝 👋 👀 ✅ ❌ ⚠️ ❓ ❗ 💡 🔥 🎉 ❤️ 💯 '
              + '⏳ 📎 📷 🧾 💳 💰 📦 🖨️ 📞 ✉️ 🔒 🚀').split(' ');

  function token() { return document.body.getAttribute('data-lf-token') || ''; }
  function yo()    { return document.body.getAttribute('data-lf-yo') || ''; }

  /* GET o POST que espera JSON. Si el servidor contesta otra cosa (la
     sesión venció, la página de login), devuelve null. */
  function pedir(url, opciones) {
    opciones = opciones || {};
    opciones.credentials = 'same-origin';
    opciones.headers = { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' };
    return fetch(url, opciones)
      .then(function (r) {
        // La sesión ya no existe (venció, o se cerró desde otro lado) y
        // el servidor mandó a la pantalla de entrar: allá vamos.
        if (r.redirected && /\/login\/?(\?|$)/.test(String(r.url).replace(location.origin, ''))) {
          aEntrar();
          return '';
        }
        return r.text();
      })
      .then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { return null; }
        if (j && j.sesion_cerrada) aEntrar();
        return j;
      });
  }

  /* La cuenta se abrió en otro dispositivo y esta sesión se cerró (ver
     Servicio\SesionUnica): a la pantalla de entrar, que dice por qué. */
  var saliendo = false;
  function aEntrar() {
    if (saliendo) return;
    saliendo = true;
    location.href = '/login';
  }

  /* POST con archivo. Va por XMLHttpRequest y no por fetch porque fetch
     no dice cuánto se ha subido, y con una imagen pesada eso es justo lo
     que se quiere ver. Igual que `pedir`: null si no contesta JSON. */
  function subir(url, datos, avance, alAbrir) {
    return new Promise(function (listo, fallo) {
      var x = new XMLHttpRequest();
      x.open('POST', url, true);
      x.setRequestHeader('X-LF-Json', '1');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      if (alAbrir) alAbrir(x);   // para poder cancelarla
      if (x.upload && avance) {
        x.upload.onprogress = function (e) { if (e.lengthComputable && e.total) avance(e.loaded / e.total); };
      }
      x.onload = function () {
        var j = null;
        try { j = JSON.parse(x.responseText); } catch (e) {}
        if (j && j.sesion_cerrada) aEntrar();
        listo(j);
      };
      x.onerror = x.onabort = x.ontimeout = function () { fallo(new Error('red')); };
      x.send(datos);
    });
  }

  /* ── El círculo de carga sobre lo que se está subiendo ──
     Sobre la miniatura: fondo oscuro y un anillo que se llena con el
     porcentaje. Junto a un PDF: el anillo chico. Ya subido, mientras el
     servidor lo guarda, el anillo gira. */
  var VUELTA = 97.39;   // largo del anillo: 2π · 15.5
  function anillo(chica) {
    var c = crear('span', 'lf-carga gira' + (chica ? ' chica' : ''));
    c.setAttribute('role', 'progressbar');
    c.setAttribute('aria-label', 'Subiendo el archivo');
    c.setAttribute('aria-valuemin', '0');
    c.setAttribute('aria-valuemax', '100');
    c.innerHTML = '<svg viewBox="0 0 36 36" aria-hidden="true">'
      + '<circle class="pista" cx="18" cy="18" r="15.5"/><circle class="avance" cx="18" cy="18" r="15.5"/></svg>';
    c.appendChild(crear('small'));
    return c;
  }
  function ponerCarga(nodo) {
    var donde = nodo.querySelector('.adj-img') || nodo.querySelector('.adj');
    if (!donde || donde.querySelector('.lf-carga')) return;
    donde.appendChild(anillo(donde.classList.contains('adj')));
    nodo.classList.add('con-carga');
  }
  function avanceCarga(nodo, p) {
    var c = nodo.querySelector('.lf-carga');
    if (c) avanzar(c, p);
  }
  function avanzar(c, p) {
    var pct = Math.max(0, Math.min(100, Math.round(p * 100)));
    var arco = c.querySelector('.avance');
    if (pct >= 100) {
      // Ya subió: ahora lo guarda el servidor. Gira hasta que llegue.
      c.classList.add('gira');
      arco.style.strokeDashoffset = '';
      c.querySelector('small').textContent = '';
      c.removeAttribute('aria-valuenow');
      return;
    }
    c.classList.remove('gira');
    arco.style.strokeDashoffset = (VUELTA * (1 - pct / 100)).toFixed(2);
    c.querySelector('small').textContent = pct + '%';
    c.setAttribute('aria-valuenow', String(pct));
  }
  function quitarCarga(nodo) {
    var c = nodo.querySelector('.lf-carga');
    if (c) c.remove();
    nodo.classList.remove('con-carga');
  }

  function crear(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) n.className = clase;
    if (texto !== undefined && texto !== null) n.textContent = texto;
    return n;
  }

  function boton(clase, texto, titulo) {
    var b = crear('button', clase, texto);
    b.type = 'button';
    if (titulo) { b.title = titulo; b.setAttribute('aria-label', titulo); }
    return b;
  }

  /* El texto va como TEXTO, nunca como HTML: lo escribió una persona. */
  function parrafo(texto) {
    var p = crear('p');
    String(texto).split('\n').forEach(function (linea, i) {
      if (i) p.appendChild(document.createElement('br'));
      p.appendChild(document.createTextNode(linea));
    });
    return p;
  }

  function normal(t) { return String(t || '').replace(/\s+/g, ' ').trim(); }
  function esImagen(url) { return /\.(png|jpe?g|webp|gif)(\?|$)/i.test(String(url || '')); }
  function peso(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; }

  /* "09/10/2026 10:24", con el día en su propio <span> (igual que
     TicketRepo::fechaMsj): en el celular, si es de hoy, se ve solo la hora. */
  function fechaMsj(f) {
    var s = crear('span', 'fecha'), p = String(f || '').split(' ');
    if (p.length !== 2) { s.textContent = f || ''; return s; }
    var d = new Date(), dos = function (n) { return (n < 10 ? '0' : '') + n; };
    var hoy = dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear();
    s.appendChild(crear('span', 'dia' + (p[0] === hoy ? ' hoy' : ''), p[0] + ' '));
    s.appendChild(document.createTextNode(p[1]));
    return s;
  }

  /* Un mensaje, con la misma forma que el que pinta el servidor. Una
     imagen adjunta se ve en miniatura; un PDF, como enlace. */
  function pintar(m, lado) {
    // `propio`: de MI lado de la conversación (en el celular, la burbuja va
    // a la derecha). Para el cliente, lo que escribió él; para soporte,
    // todo lo que no escribió el cliente.
    var propio = lado === 'cliente' ? !!m.mio : m.tipo !== 'empresa';
    var caja = crear('div', 'lf-msj nuevo' + (m.interno ? ' interno' : '') + (m.mio ? ' mio' : '')
                            + (propio ? ' propio' : ''));
    caja.setAttribute('data-id', m.id);
    var av = crear('span', 'lf-av' + ((m.interno || (lado === 'cliente' && m.mio)) ? ' gris' : '')
                          + (m.foto ? ' con-foto' : ''), m.inicial);
    if (m.foto) av.style.backgroundImage = "url('" + String(m.foto).replace(/'/g, '%27') + "')";
    caja.appendChild(av);

    var cuerpo = crear('div', 'cuerpo');
    var cab = crear('div', 'cab');
    cab.appendChild(crear('b', '', m.autor));
    if (m.interno) cab.appendChild(crear('span', 'badge bg-secondary', 'Nota interna'));
    if (lado === 'soporte' && m.tipo === 'empresa') cab.appendChild(crear('span', 'badge bg-secondary lf-tag-cliente', 'Cliente'));
    cab.appendChild(fechaMsj(m.fecha));
    cuerpo.appendChild(cab);
    cuerpo.appendChild(parrafo(m.cuerpo));
    if (m.adjunto) {
      var a;
      if (m.esImagen || esImagen(m.adjunto)) {
        a = crear('a', 'adj-img');
        var img = crear('img');
        img.src = m.adjunto; img.alt = 'Imagen adjunta'; img.loading = 'lazy';
        a.appendChild(img);
      } else {
        a = crear('a', 'adj', lado === 'soporte' ? 'Ver evidencia' : 'Ver archivo');
      }
      a.href = m.adjunto; a.target = '_blank'; a.rel = 'noopener';
      cuerpo.appendChild(a);
    }
    caja.appendChild(cuerpo);
    return caja;
  }

  function avisoEn(form, texto) {
    var p = form.querySelector('[data-lf-error]');
    if (!p) { p = crear('p', 'lf-chat-error'); p.setAttribute('data-lf-error', ''); form.appendChild(p); }
    p.textContent = texto;
    p.hidden = false;
  }

  /* Hasta abajo de la lista; suave cuando llega algo mientras se mira. */
  function bajar(lista, suave) {
    if (suave && lista.scrollTo) lista.scrollTo({ top: lista.scrollHeight, behavior: 'smooth' });
    else lista.scrollTop = lista.scrollHeight;
  }

  /* Quita un elemento con su animación de salida (clase `sale`), no de
     golpe. Si no hay animación (movimiento reducido), se va al momento. */
  function despedir(n, ms) {
    if (!n || !n.parentNode || n._saliendo) return;
    n._saliendo = true;
    n.classList.add('sale');
    setTimeout(function () { if (n.parentNode) n.remove(); }, ms || 260);
  }

  /* "Ana está escribiendo…" con los tres puntos. Va siempre al final de
     la lista; lo nuevo se inserta antes que él. */
  function indicador(lista, nombre) {
    var ind = lista.querySelector('[data-lf-escribe-ind]');
    if (!nombre) {
      // Se va con su animación; sin la marca, lo nuevo ya no se pone antes.
      if (ind) { ind.removeAttribute('data-lf-escribe-ind'); despedir(ind, 200); }
      return;
    }
    if (!ind) {
      ind = crear('div', 'lf-escribe');
      ind.setAttribute('data-lf-escribe-ind', '');
      ind.setAttribute('aria-live', 'polite');
      var pts = crear('span', 'puntos');
      pts.appendChild(crear('i')); pts.appendChild(crear('i')); pts.appendChild(crear('i'));
      ind.appendChild(pts);
      ind.appendChild(crear('small'));
      lista.appendChild(ind);
    }
    ind.querySelector('small').textContent = nombre + ' está escribiendo…';
  }

  /* ── El título de la pestaña ──
     "💬" delante: llegó un mensaje con la pestaña en segundo plano.
     "(3)" delante: tickets que esperan respuesta (soporte). Se arma sobre
     el título de la página, que cambia al navegar sin recargar. */
  var marcaChat = false, cuentaTitulo = 0;
  var PREFIJO = /^(💬 )?(\(\d+\+?\) )?/;
  function pintarTitulo() {
    var base = document.title.replace(PREFIJO, '');
    var pre = (marcaChat ? '💬 ' : '')
            + (cuentaTitulo ? '(' + (cuentaTitulo > 9 ? '9+' : cuentaTitulo) + ') ' : '');
    if (document.title !== pre + base) document.title = pre + base;
  }
  function avisoTitulo() { marcaChat = true; pintarTitulo(); }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && marcaChat) { marcaChat = false; pintarTitulo(); }
  });
  document.addEventListener('lf:cargado', pintarTitulo);

  /* ── Emojis ──
     Un botón junto a "Adjuntar" abre la tabla; el emoji va donde está el
     cursor y la tabla se queda abierta para poner varios. */
  var emojiAbierto = null;
  function cerrarEmojis() {
    if (!emojiAbierto) return;
    var caja = emojiAbierto, pop = caja._lfPop;
    caja.classList.remove('abierto');
    caja.querySelector('.lf-emoji-bt').setAttribute('aria-expanded', 'false');
    // Vuelve a su lugar (abierta vive en <body>; ver selectorEmojis).
    if (pop) { pop.classList.remove('abierto'); caja.appendChild(pop); }
    emojiAbierto = null;
  }
  document.addEventListener('click', function (e) {
    if (!emojiAbierto) return;
    var pop = emojiAbierto._lfPop;
    if (!emojiAbierto.contains(e.target) && !(pop && pop.contains(e.target))) cerrarEmojis();
  });
  /* Abierta va pegada al botón. Si cambia el tamaño (en el celular, al
     abrirse o cerrarse el teclado) se vuelve a acomodar; si se cambia de
     página, se cierra en vez de quedar flotando. */
  function colocarEmojis() {
    if (!emojiAbierto) return;
    var bt = emojiAbierto.querySelector('.lf-emoji-bt'), pop = emojiAbierto._lfPop;
    if (!bt || !pop || !bt.isConnected) { cerrarEmojis(); return; }
    var r = bt.getBoundingClientRect();
    var vw = document.documentElement.clientWidth;
    var vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    var pw = pop.offsetWidth, ph = pop.offsetHeight;
    // Encima del botón si cabe; si no, debajo. Siempre dentro de la pantalla.
    var left = Math.min(Math.max(8, r.left), vw - pw - 8);
    var top = r.top - ph - 8;
    if (top < 8) top = Math.min(r.bottom + 8, vh - ph - 8);
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.top = Math.max(8, top) + 'px';
  }
  window.addEventListener('resize', colocarEmojis);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', colocarEmojis);
  document.addEventListener('lf:cargado', function () { cerrarEmojis(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && emojiAbierto) {
      var ta = emojiAbierto._lfTa;
      cerrarEmojis();
      if (ta) ta.focus();
    }
  });

  function insertar(ta, txt) {
    var a = ta.selectionStart, b = ta.selectionEnd;
    if (typeof a !== 'number' || document.activeElement !== ta && a === 0 && b === 0 && ta.value) {
      // Sin cursor conocido, al final.
      a = b = ta.value.length;
    }
    ta.value = ta.value.slice(0, a) + txt + ta.value.slice(b);
    ta.selectionStart = ta.selectionEnd = a + txt.length;
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function selectorEmojis(ta) {
    var caja = crear('span', 'lf-emoji');
    caja._lfTa = ta;
    var bt = boton('lf-emoji-bt', '😊', 'Emojis');
    bt.setAttribute('aria-haspopup', 'true');
    bt.setAttribute('aria-expanded', 'false');
    var pop = crear('div', 'lf-emoji-pop');
    pop.setAttribute('role', 'menu');
    EMOJIS.forEach(function (em) {
      if (!em) return;
      var b = boton('', em);
      b.setAttribute('role', 'menuitem');
      b.addEventListener('click', function () { insertar(ta, em); });
      pop.appendChild(b);
    });
    bt.addEventListener('click', function () {
      var abrir = emojiAbierto !== caja;
      cerrarEmojis();
      if (!abrir) return;
      caja.classList.add('abierto');
      bt.setAttribute('aria-expanded', 'true');
      emojiAbierto = caja;
      /* ABIERTA SE VA A <body>, FIJA A LA PANTALLA. Dentro del chat la
         recortaba su contenedor (la ventana y el chat flotante esconden lo
         que se sale): en una pantalla chica quedaba cortada o fuera de
         vista. Se pone encima del botón si cabe, debajo si no, y siempre
         dentro de la pantalla. */
      document.body.appendChild(pop);
      pop.classList.add('abierto');
      colocarEmojis();
    });
    caja._lfPop = pop;
    caja.appendChild(bt);
    caja.appendChild(pop);
    return caja;
  }

  /* ── Adjuntos: elegir, pegar (Ctrl+V) o arrastrar ──
     Lo pegado no pasa por el <input type=file> (no en todos los
     navegadores se puede escribir en él): se guarda en el formulario y se
     agrega al enviar. La vista previa muestra lo que se va a mandar. */
  function etiqueta(form, texto) {
    var n = form.querySelector('.lf-file .n');
    if (!n) return;
    n.textContent = texto || n.getAttribute('data-vacio') || '';
    n.classList.toggle('hay', !!texto);
  }

  function vistaPrevia(form, archivo, nombre) {
    var v = form._lfVista;
    if (!v) return;
    if (v._url) { URL.revokeObjectURL(v._url); v._url = null; }
    v.innerHTML = '';
    if (!archivo) { v.hidden = true; return; }
    // La miniatura va en su cajita: encima se pinta el avance de la subida.
    var mini = crear('span', 'mini');
    if (/^image\//.test(archivo.type)) {
      v._url = URL.createObjectURL(archivo);
      var img = crear('img'); img.src = v._url; img.alt = '';
      mini.appendChild(img);
    } else {
      mini.appendChild(crear('span', 'doc', 'PDF'));
    }
    v.appendChild(mini);
    var txt = crear('span', 'txt');
    txt.appendChild(crear('b', '', nombre));
    var tam = crear('small', '', peso(archivo.size));
    tam.setAttribute('data-peso', peso(archivo.size));
    txt.appendChild(tam);
    v.appendChild(txt);
    var x = boton('x lf-signo', '×', 'Quitar el adjunto');
    x.addEventListener('click', function () { quitarAdjunto(form); });
    v.appendChild(x);
    v.classList.remove('lista');
    v.hidden = false;
  }

  /* Quita lo adjunto. Si se estaba subiendo por adelantado, se cancela. */
  function quitarAdjunto(form) {
    var adj = form._lfAdj;
    if (adj && adj.subida && !adj.subida.listo && adj.subida.xhr) {
      try { adj.subida.xhr.abort(); } catch (e) {}
    }
    form._lfAdj = null;
    form._lfTurno = (form._lfTurno || 0) + 1;   // lo que se esté preparando ya no vale
    form._lfPreparando = false;
    var inp = form.querySelector('input[type=file]');
    if (inp && inp.value) {
      form._lfSilencio = true; inp.value = '';
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      form._lfSilencio = false;
    }
    vistaPrevia(form, null);
    etiqueta(form, '');
  }

  /* ── SE SUBE EN CUANTO SE ADJUNTA ──
     Mientras la persona termina de escribir, la imagen ya va subiendo. Al
     dar Enviar el mensaje solo lleva su clave y sale al instante. Si esta
     subida falla, no pasa nada: el archivo se manda con el mensaje, como
     antes. */
  function urlAdjunto(form) {
    var u = form.getAttribute('action') || '';
    return /\/responder$/.test(u) ? u.replace(/\/responder$/, '/adjunto') : '';
  }

  function presubir(form) {
    var adj = form._lfAdj, url = urlAdjunto(form);
    if (!adj || !url || !window.XMLHttpRequest || !window.Promise
        || !window.FormData || !FormData.prototype.set) return;
    var s = { avance: 0, clave: '', listo: false, fallo: false, oyentes: [], xhr: null };
    adj.subida = s;
    var fd = new FormData();
    fd.append('token', (form.querySelector('input[name=token]') || {}).value || token());
    fd.append('adjunto', adj.blob, adj.nombre);
    function contar() { s.oyentes.forEach(function (f) { f(s); }); }
    s.promesa = subir(url, fd, function (p) { s.avance = p; contar(); }, function (x) { s.xhr = x; })
      .then(function (j) {
        if (j && j.ok && j.clave) { s.clave = j.clave; s.listo = true; s.avance = 1; }
        else s.fallo = true;
        contar();
        return s;
      }, function () { s.fallo = true; contar(); return s; });
    s.oyentes.push(function () { avanceVista(form, s); });
    avanceVista(form, s);
  }

  /* El avance en la vista previa: el anillo sobre la miniatura y el
     porcentaje en el texto; al terminar, "lista para enviar". */
  function avanceVista(form, s) {
    var v = form._lfVista;
    if (!v || v.hidden || !form._lfAdj || form._lfAdj.subida !== s) return;
    var mini = v.querySelector('.mini'), tam = v.querySelector('.txt small');
    var c = mini && mini.querySelector('.lf-carga');
    var base = tam ? tam.getAttribute('data-peso') : '';
    if (s.listo || s.fallo) {
      if (c) c.remove();
      if (tam) tam.textContent = base + (s.listo ? ' · lista para enviar' : '');
      v.classList.toggle('lista', s.listo);
      return;
    }
    if (!c && mini) { c = anillo(false); mini.appendChild(c); }
    if (c) avanzar(c, s.avance);
    if (tam) tam.textContent = base + ' · subiendo ' + Math.round(s.avance * 100) + '%';
  }

  /* Si se dio Enviar mientras la imagen se preparaba, ahora sí. */
  function enviarSiEsperaba(form) {
    if (!form._lfEnviarLuego) return;
    form._lfEnviarLuego = false;
    if (form.requestSubmit) form.requestSubmit();
    else form.dispatchEvent(new Event('submit', { cancelable: true }));
  }

  function fechaArchivo() {
    var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + z(d.getMonth() + 1) + z(d.getDate()) + '-' + z(d.getHours()) + z(d.getMinutes()) + z(d.getSeconds());
  }

  /* LAS IMÁGENES SE ACHICAN ANTES DE SUBIR. Una captura en PNG pesa de 2
     a 5 MB; en WebP, del mismo tamaño en pantalla y con el texto igual de
     nítido, unos 300 KB: sube diez veces más rápido. Lo que ya es ligero
     se manda tal cual. Si el navegador no sabe hacer WebP, JPEG. Y si el
     resultado no pesa menos, se queda el original. */
  var LIGERO = 358400;   // 350 KB
  function aligerar(blob, listo) {
    if (blob.size <= LIGERO || !/^image\/(png|jpeg|webp)$/.test(blob.type) || !window.createImageBitmap) { listo(blob); return; }
    createImageBitmap(blob).then(function (bmp) {
      var max = 2560, k = Math.min(1, max / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * k)); c.height = Math.max(1, Math.round(bmp.height * k));
      var g = c.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
      g.drawImage(bmp, 0, 0, c.width, c.height);
      if (bmp.close) bmp.close();
      c.toBlob(function (w) {
        if (w && w.type === 'image/webp' && w.size < blob.size) { listo(w); return; }
        c.toBlob(function (j) { listo(j && j.size < blob.size ? j : blob); }, 'image/jpeg', 0.86);
      }, 'image/webp', 0.86);
    }, function () { listo(blob); });
  }

  /* Pegado, arrastrado o elegido: todo pasa por aquí. Se achica, se
     enseña y empieza a subir de una vez. */
  function ponerArchivo(form, blob, deDonde) {
    if (!blob) return;
    if (!TIPOS.test(blob.type)) {
      avisoEn(form, 'Solo se pueden adjuntar imágenes (JPG, PNG, WebP) o PDF.');
      if (deDonde === 'elegido') quitarAdjunto(form);   // que no se mande de todos modos
      return;
    }
    var err = form.querySelector('[data-lf-error]'); if (err) err.hidden = true;
    quitarAdjunto(form);                       // lo de antes se reemplaza
    var turno = form._lfTurno;
    form._lfPreparando = true;
    aligerar(blob, function (b) {
      if (form._lfTurno !== turno) return;     // mientras tanto se quitó o se eligió otro
      form._lfPreparando = false;
      if (b.size > MAX_ARCHIVO) {
        avisoEn(form, 'El archivo pesa ' + peso(b.size) + '; el máximo es 10 MB.');
        form._lfEnviarLuego = false;
        return;
      }
      var ext = b.type === 'application/pdf' ? 'pdf' : b.type === 'image/jpeg' ? 'jpg' : b.type.split('/')[1];
      var nombre = (deDonde === 'pegado' ? 'captura-' + fechaArchivo() : (blob.name || 'archivo').replace(/\.[^.]+$/, '')) + '.' + ext;
      form._lfAdj = { blob: b, nombre: nombre, subida: null };
      vistaPrevia(form, b, nombre);
      etiqueta(form, nombre);
      presubir(form);
      if (form._lfEnviarLuego) { enviarSiEsperaba(form); return; }
      var ta = form.querySelector('textarea'); if (ta) ta.focus();
    });
  }

  function mejorar(form, zona) {
    if (form._lfMejorado) return;
    form._lfMejorado = true;
    // La validación la hace el chat: una captura sola, sin texto, también vale.
    form.noValidate = true;
    var ta  = form.querySelector('textarea');
    var pie = form.querySelector('.pie, .lf-resp-pie');
    var inp = form.querySelector('input[type=file]');

    if (ta && pie) pie.insertBefore(selectorEmojis(ta), pie.querySelector('.lf-file') || pie.firstChild);

    // Enviar: solo el avión de papel. La palabra queda para lectores de
    // pantalla (aria-label) y para el globito al pasar el cursor.
    var env = form.querySelector('[type=submit]');
    if (env && !env.classList.contains('lf-enviar-ico')) {
      var palabra = env.textContent.trim() || 'Enviar';
      env.classList.add('lf-enviar-ico');
      env.innerHTML = ICONO_ENVIAR;
      env.appendChild(crear('span', 'txt', palabra));
      env.title = palabra;
      env.setAttribute('aria-label', palabra);
    }

    // La caja crece mientras se escribe (hasta un tope) y vuelve a su
    // tamaño al enviar: como en cualquier chat.
    if (ta) {
      var tope = form.closest('.lf-chatf') ? 120 : 180;
      form._lfCrecer = function () {
        ta.style.height = 'auto';
        if (ta.value) ta.style.height = Math.min(ta.scrollHeight + 2, tope) + 'px';
        else ta.style.height = '';
      };
      ta.addEventListener('input', form._lfCrecer);
    }

    var vista = crear('div', 'lf-adj-vista');
    vista.hidden = true;
    if (pie) pie.parentNode.insertBefore(vista, pie); else form.appendChild(vista);
    form._lfVista = vista;

    // Lo elegido con el botón también se achica y sube por adelantado.
    // (Cancelar el diálogo no quita lo que ya estaba adjunto.)
    if (inp) inp.addEventListener('change', function () {
      if (form._lfSilencio) return;
      var f = inp.files && inp.files[0];
      if (f) ponerArchivo(form, f, 'elegido');
    });

    // La zona (el chat flotante) se queda al cambiar de reporte y el
    // formulario no: se escucha una sola vez y se usa el formulario de ahora.
    zona._lfForm = form;
    if (zona._lfZona) return;
    zona._lfZona = true;

    // Ctrl+V con una captura. Si lo copiado también trae texto (una celda
    // de Excel trae las dos cosas), se pega el texto, como siempre.
    zona.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd || !cd.items || (cd.getData && cd.getData('text/plain'))) return;
      for (var i = 0; i < cd.items.length; i++) {
        var it = cd.items[i];
        if (it.kind === 'file' && /^image\//.test(it.type)) {
          var f = it.getAsFile();
          if (f) { e.preventDefault(); ponerArchivo(zona._lfForm, f, 'pegado'); return; }
        }
      }
    });

    // Arrastrar un archivo encima.
    var arrastres = 0;
    function conArchivos(e) { return e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
    zona.addEventListener('dragenter', function (e) { if (!conArchivos(e)) return; arrastres++; zona.classList.add('lf-soltar'); });
    zona.addEventListener('dragleave', function () { if (--arrastres <= 0) { arrastres = 0; zona.classList.remove('lf-soltar'); } });
    zona.addEventListener('dragover',  function (e) { if (conArchivos(e)) e.preventDefault(); });
    zona.addEventListener('drop', function (e) {
      if (!conArchivos(e)) return;
      e.preventDefault();
      arrastres = 0; zona.classList.remove('lf-soltar');
      var f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) ponerArchivo(zona._lfForm, f, 'arrastrado');
    });
  }

  /* ══ 1 · Una conversación en vivo ══ */
  var alEnviar = [];   // quién más quiere saber que se envió algo (las novedades)

  function conversacion(raiz) {
    if (raiz._lfChat) return raiz._lfChat;
    var url   = raiz.getAttribute('data-lf-chat');
    var urlEscribe = raiz.getAttribute('data-lf-escribe');
    var lado  = raiz.getAttribute('data-lf-lado') || 'cliente';
    var flota = raiz.hasAttribute('data-lf-flota');
    var lista = raiz.querySelector('[data-lf-lista]') || raiz;
    var sel   = raiz.getAttribute('data-lf-form');
    var form  = sel ? document.querySelector(sel) : raiz.querySelector('form[data-lf-enviar]');
    var ultimo = +raiz.getAttribute('data-lf-ultimo') || 0;
    var activo = true, reloj = null, ctrl = null, fallos = 0, rapidas = 0;
    var conoce = '', avisado = 0, cola = [], enviando = false;
    var yaCargo = false;   // la primera tanda se pinta sin animación (ver recibir)

    function viva() { return document.body.contains(raiz); }

    /* ── Como en un chat: se abre en el último mensaje ──
       La lista se desplaza por dentro (la ventana de chat; ver CSS
       .lf-chat-ventana). Si quien lee está al final, lo nuevo lo baja
       solo; si subió a leer lo anterior, no se le mueve: aparece
       "↓ Mensajes nuevos" para bajar cuando quiera. */
    function alFinal() { return lista.scrollHeight - lista.scrollTop - lista.clientHeight < 80; }
    var pegado = true, nuevosSinVer = 0, botonNuevos = null;

    function avisarNuevos(n) {
      nuevosSinVer += n;
      if (!botonNuevos) {
        botonNuevos = boton('lf-ir-abajo', null, 'Ir a los mensajes nuevos');
        botonNuevos.addEventListener('click', function () { bajar(lista, true); ocultarNuevos(); });
        raiz.appendChild(botonNuevos);
      }
      botonNuevos.textContent = '↓ ' + (nuevosSinVer === 1 ? '1 mensaje nuevo' : nuevosSinVer + ' mensajes nuevos');
      // Justo encima de donde termina la lista (arriba de la caja de escribir).
      var abajoDe = raiz.getBoundingClientRect().bottom - lista.getBoundingClientRect().bottom;
      botonNuevos.style.bottom = Math.max(10, abajoDe + 12) + 'px';
      botonNuevos.classList.add('ver');
    }
    function ocultarNuevos() {
      nuevosSinVer = 0;
      if (botonNuevos) botonNuevos.classList.remove('ver');
    }

    lista.addEventListener('scroll', function () {
      pegado = alFinal();
      if (pegado) ocultarNuevos();
    }, { passive: true });
    // Una imagen que termina de cargar cambia el alto: si estaba al final,
    // se queda al final (si no, abriría a medio camino).
    lista.addEventListener('load', function (e) {
      if (e.target && e.target.tagName === 'IMG' && pegado) bajar(lista, false);
    }, true);

    /* Mientras se teclea, se le avisa al otro lado (como mucho cada
       2.5 s). Una nota interna no: el cliente no debe saber que se
       escribe algo que no va a ver. */
    function tecleando() {
      if (!urlEscribe || !form) return;
      var ta = form.querySelector('textarea');
      if (!ta || !ta.value.trim()) return;
      var interno = form.querySelector('input[name=interno]');
      if (interno && interno.checked) return;
      var ahora = Date.now();
      if (ahora - avisado < CADA_ESCRIBE) return;
      avisado = ahora;
      var fd = new FormData();
      fd.append('token', (form.querySelector('input[name=token]') || {}).value || token());
      pedir(urlEscribe, { method: 'POST', body: fd }).then(null, function () {});
    }

    /* Lo enviado que todavía no confirma el servidor. */
    function pendientes() { return lista.querySelectorAll('.lf-msj.enviando'); }

    /* ¿Cuál de lo que se está enviando es este mensaje que ya llegó? Por
       el id que contestó el servidor; si llegó antes que la respuesta,
       por el texto. */
    function pendienteDe(m) {
      var ps = pendientes(), i, t = normal(m.cuerpo);
      for (i = 0; i < ps.length; i++) if (+ps[i].getAttribute('data-espera') === m.id) return ps[i];
      for (i = 0; i < ps.length; i++) if (!ps[i].hasAttribute('data-espera') && ps[i]._lfTexto === t) return ps[i];
      return null;
    }

    function soltar(nodo) {
      if (nodo && nodo._lfUrl) { URL.revokeObjectURL(nodo._lfUrl); nodo._lfUrl = null; }
    }

    /* Lo que contestó el servidor. Devuelve si cambió algo. */
    function recibir(j) {
      var cambio = false;
      var nuevos = (j.mensajes || []).filter(function (m) { return m.id > ultimo; });
      // ¿Estaba al final? Entonces lo nuevo se le muestra bajando. Si subió
      // a leer lo anterior, no se le mueve: sale el botón de "nuevos".
      var abajo = alFinal();
      // LA PRIMERA TANDA (al abrir el chat) aparece ya en el último
      // mensaje: sin la entrada de cada mensaje y sin bajar deslizándose
      // desde el primero, que se veía como si el chat empezara arriba.
      var primera = !yaCargo;
      yaCargo = true;
      if (nuevos.length) {
        cambio = true;
        var vacio = raiz.querySelector('[data-lf-vacio]');
        if (vacio) vacio.hidden = true;
        nuevos.forEach(function (m) {
          var nodo = pintar(m, lado);
          if (primera) nodo.classList.remove('nuevo');
          var p = m.mio ? pendienteDe(m) : null;
          if (p) {
            // El "Enviando…" se vuelve el mensaje de verdad, sin saltar.
            nodo.classList.remove('nuevo');
            // La imagen del servidor tarda un momento en bajar: mientras,
            // se sigue viendo la que ya estaba, sin parpadeo.
            var img = nodo.querySelector('.adj-img img');
            if (img && p._lfUrl) {
              var local = p._lfUrl, remota = img.src, pre = new Image();
              p._lfUrl = null;
              img.src = local;
              pre.onload = pre.onerror = function () { img.src = remota; URL.revokeObjectURL(local); };
              pre.src = remota;
            }
            lista.replaceChild(nodo, p);
            soltar(p);
          } else {
            // Lo de los demás va antes de lo mío que sigue enviándose.
            lista.insertBefore(nodo, pendientes()[0] || lista.querySelector('[data-lf-escribe-ind]'));
          }
          ultimo = Math.max(ultimo, m.id);
        });
        if (document.hidden && nuevos.some(function (m) { return !m.mio; })) avisoTitulo();
      }
      // El otro lado está tecleando: los tres puntos.
      var esc = j.escribiendo || '';
      if (esc !== conoce) { conoce = esc; cambio = true; }
      indicador(lista, esc || null);
      if (primera && nuevos.length) { bajar(lista, false); pegado = true; }
      else if (abajo && (nuevos.length || esc)) bajar(lista, true);
      else if (!abajo) {
        var deOtros = nuevos.filter(function (m) { return !m.mio; }).length;
        if (deOtros) avisarNuevos(deOtros);
      }
      if (j.cerrado && form && !form.hidden) {
        cambio = true;
        form.hidden = true;
        if (!raiz.querySelector('[data-lf-cerrado]')) {
          var c = crear('p', 'lf-chat-cerrado', 'Este reporte está cerrado.');
          c.setAttribute('data-lf-cerrado', '');
          (flota ? raiz : lista).appendChild(c);
        }
      }
      return cambio;
    }

    function programar(ms) {
      if (!activo) return;
      clearTimeout(reloj);
      reloj = setTimeout(ciclo, ms);
    }

    /* La vuelta de escucha: pide, espera la respuesta (hasta 20 s si hay
       nada nuevo) y vuelve a pedir. */
    function ciclo() {
      reloj = null;
      if (!activo) return;
      if (!viva()) { parar(); return; }
      if (ctrl) return;                       // ya hay una en curso
      var oculto = document.hidden;
      // Con la pestaña en segundo plano se dice `oculta`: así no cuenta como
      // "en la conversación" ni como leído (y sí llegan correo y avisos).
      // A la vista, `visto` dice hasta dónde tiene en pantalla.
      var q = 'desde=' + ultimo + (oculto
        ? '&oculta=1'
        : '&esperar=1&escribe=' + encodeURIComponent(conoce) + '&visto=' + ultimo);
      var mio = window.AbortController ? new AbortController() : {};
      ctrl = mio;
      var t0 = Date.now();
      pedir(url + (url.indexOf('?') === -1 ? '?' : '&') + q, mio.signal ? { signal: mio.signal } : {}).then(function (j) {
        if (ctrl === mio) ctrl = null;
        if (!activo) return;
        if (!j) { parar(); return; }          // la sesión venció: no insistir
        // El servidor no lo entrega: no es de quien pregunta (o ya no
        // existe). No se insiste ni se queda en "Cargando…": se dice.
        if (j.ok === false && !j.sesion_cerrada) {
          parar();
          var cargando = raiz.querySelector('[data-lf-vacio]');
          if (cargando) cargando.hidden = true;
          if (!raiz.querySelector('[data-lf-cerrado]')) {
            var aviso = crear('p', 'lf-chat-cerrado', j.error || 'No puedes ver este reporte.');
            aviso.setAttribute('data-lf-cerrado', '');
            lista.appendChild(aviso);
          }
          if (form) form.hidden = true;
          if (flota) sesionGuardar('lf_chat_abierto', null);
          return;
        }
        fallos = 0;
        var cambio = j.ok ? recibir(j) : false;
        // Si algo entre medio corta la espera y contesta al instante sin
        // novedades, no se martilla al servidor.
        rapidas = (!oculto && !cambio && Date.now() - t0 < 700) ? rapidas + 1 : 0;
        programar(document.hidden ? CADA_OCULTA : (rapidas > 3 ? 3000 : 60));
      }, function (e) {
        if (ctrl === mio) ctrl = null;
        if (!activo || (e && e.name === 'AbortError')) return;
        fallos++;
        programar(Math.min(30000, 1500 * fallos));
      });
    }

    function alVolver() {
      // Volvió a la pestaña: se escucha de nuevo al momento.
      if (!document.hidden && activo && !ctrl) { clearTimeout(reloj); ciclo(); }
    }

    function parar() {
      activo = false;
      clearTimeout(reloj); reloj = null;
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = null;
      document.removeEventListener('visibilitychange', alVolver);
    }

    /* ── Enviar: aparece al momento y se manda en orden ── */
    function siguiente() {
      if (enviando || !cola.length) return;
      enviando = true;
      var item = cola[0], s = item.subida;
      // ¿La imagen sigue subiendo desde que se adjuntó? El globo sigue
      // ese mismo avance; el mensaje sale en cuanto termine.
      if (s && !s.listo && !s.fallo) {
        avanceCarga(item.nodo, s.avance);
        s.oyentes.push(function () { if (!s.listo && !s.fallo) avanceCarga(item.nodo, s.avance); });
      }
      (s ? s.promesa : Promise.resolve(null)).then(function () {
        var fd = item.fd;
        // Ya está en el servidor: el mensaje solo lleva su clave.
        if (s && s.listo) {
          fd.set('adjunto_previo', s.clave);
          avanceCarga(item.nodo, 1);
          return pedir(form.action, { method: 'POST', body: fd });
        }
        // Si no, el archivo va con el mensaje, viendo el avance.
        if (item.blob) {
          if (fd.delete) fd.delete('adjunto_previo');
          fd.set('adjunto', item.blob, item.nombre);
        }
        return item.conArchivo && window.XMLHttpRequest
          ? subir(form.action, fd, function (p) { avanceCarga(item.nodo, p); })
          : pedir(form.action, { method: 'POST', body: fd });
      }).then(function (j) {
        enviando = false; cola.shift();
        if (j && j.ok) {
          alEnviar.forEach(function (f) { f(); });
          if (item.nodo.parentNode && item.nodo.classList.contains('enviando')) {
            // Si el mensaje ya llegó por la escucha, sobra el "Enviando…".
            if (!j.id || j.id <= ultimo) { soltar(item.nodo); item.nodo.remove(); }
            else item.nodo.setAttribute('data-espera', j.id);
          }
        } else {
          fallo(item, (j && (j.mensaje || j.error)) || 'No se pudo enviar.');
        }
        siguiente();
      }, function () {
        enviando = false; cola.shift();
        fallo(item, 'Sin conexión. Revisa tu internet.');
        siguiente();
      });
    }

    function fallo(item, texto) {
      var n = item.nodo;
      quitarCarga(n);
      n.classList.remove('enviando');
      n.classList.add('fallo');
      var f = n.querySelector('.fecha'); if (f) f.textContent = 'No se envió';
      var barra = crear('div', 'lf-reintento');
      barra.appendChild(crear('small', '', texto));
      var otra = boton('', 'Reintentar');
      var tirar = boton('', 'Descartar');
      otra.addEventListener('click', function () {
        barra.remove();
        n.classList.remove('fallo'); n.classList.add('enviando');
        if (f) f.textContent = 'Enviando…';
        if (item.conArchivo) ponerCarga(n);
        // Al reintentar, el archivo va con el mensaje: lo subido por
        // adelantado pudo haberse perdido (sesión nueva, por ejemplo).
        if (item.blob) item.subida = null;
        cola.push(item); siguiente();
      });
      tirar.addEventListener('click', function () { soltar(n); despedir(n, 260); });
      barra.appendChild(otra); barra.appendChild(tirar);
      n.querySelector('.cuerpo').appendChild(barra);
    }

    if (form && !form._lfChat) {
      form._lfChat = true;
      mejorar(form, flota ? raiz : form);
      form.addEventListener('input', function (e) {
        if (e.target.tagName === 'TEXTAREA') tecleando();
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        // La imagen todavía se está achicando (una fracción de segundo):
        // se envía en cuanto quede, sin perderla.
        if (form._lfPreparando) { form._lfEnviarLuego = true; return; }
        var ta  = form.querySelector('textarea');
        var texto = ta ? ta.value.trim() : '';
        var adj = form._lfAdj;
        var inp = form.querySelector('input[type=file]');
        var elegido = !adj && inp && inp.files && inp.files[0];
        if (!texto && !adj && !elegido) { if (ta) ta.focus(); return; }
        var err = form.querySelector('[data-lf-error]');
        if (err) err.hidden = true;

        // El archivo no va en este FormData: o ya se subió (va su clave)
        // o se agrega al momento de mandarlo (ver siguiente()).
        var fd = new FormData(form);
        var item = { fd: fd, blob: null, nombre: '', subida: null };
        if (adj) {
          if (fd.set && fd.delete) {
            fd.delete('adjunto');
            item.blob = adj.blob; item.nombre = adj.nombre; item.subida = adj.subida || null;
          } else {
            fd.append('adjunto', adj.blob, adj.nombre);
          }
        }
        var interno = form.querySelector('input[name=interno]');

        // Al momento en la lista, como "Enviando…".
        var archivo = adj ? adj.blob : elegido;
        var urlLocal = archivo ? URL.createObjectURL(archivo) : '';
        var nombre = yo();
        var nodo = pintar({
          id: 0, mio: true, tipo: '', foto: '',
          autor: lado === 'cliente' ? 'Tú' : (nombre || 'Tú'),
          inicial: (nombre.trim().charAt(0) || '?').toUpperCase(),
          interno: !!(interno && interno.checked),
          cuerpo: texto || 'Adjuntó un archivo.',
          adjunto: urlLocal, esImagen: !!(archivo && /^image\//.test(archivo.type)),
          fecha: 'Enviando…'
        }, lado);
        nodo.classList.add('enviando');
        nodo._lfTexto = normal(texto || 'Adjuntó un archivo.');
        nodo._lfUrl = urlLocal;
        if (archivo) ponerCarga(nodo);
        var vacio = raiz.querySelector('[data-lf-vacio]');
        if (vacio) vacio.hidden = true;
        lista.insertBefore(nodo, lista.querySelector('[data-lf-escribe-ind]'));
        bajar(lista, true);

        // El formulario queda listo para el siguiente, sin esperar.
        if (ta) ta.value = '';
        if (form._lfCrecer) form._lfCrecer();
        avisado = 0;
        form._lfAdj = null;
        if (inp && inp.value) { form._lfSilencio = true; inp.value = ''; inp.dispatchEvent(new Event('change', { bubbles: true })); form._lfSilencio = false; }
        vistaPrevia(form, null);
        etiqueta(form, '');
        if (interno) interno.checked = false;
        cerrarEmojis();
        if (ta) ta.focus();

        item.nodo = nodo;
        item.conArchivo = !!archivo;
        cola.push(item);
        siguiente();
      });
      /* ENTER ENVÍA y Shift+Enter hace salto de línea, en todos los chats.
         En el teléfono no: su teclado no tiene Shift+Enter, así que Enter
         es el salto de línea (como en WhatsApp) y se envía con el avión. */
      form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey || e.isComposing || e.target.tagName !== 'TEXTAREA') return;
        if (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches) return;
        e.preventDefault();
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
      });
    }

    // Se abre en el último mensaje, como cualquier chat.
    bajar(lista, false);
    pegado = true;
    document.addEventListener('visibilitychange', alVolver);
    ciclo();
    raiz._lfChat = { parar: parar };
    return raiz._lfChat;
  }

  function enlazar(ambito) {
    [].forEach.call((ambito || document).querySelectorAll('[data-lf-chat]'), conversacion);
    prepararPegado(ambito || document);
    ajustarVentanas();
  }

  /* ── Pegar una captura en un formulario normal ([data-lf-pegar]) ──
     "Reportar un problema": Ctrl+V con una captura (o arrastrarla) la
     adjunta y la enseña. Ese formulario se envía como siempre (recarga la
     página), así que la imagen se mete en su <input type=file> para que
     viaje con él. Como en el chat, la que pesa mucho se achica antes. */
  function prepararPegado(ambito) {
    if (!window.DataTransfer) return;
    [].forEach.call((ambito || document).querySelectorAll('form[data-lf-pegar]'), function (form) {
      if (form._lfPegar) return;
      var inp = form.querySelector('input[type=file]');
      if (!inp) return;
      form._lfPegar = true;

      var vista = crear('div', 'lf-adj-vista');
      vista.hidden = true;
      var ancla = inp.closest('.lf-file') || inp;
      ancla.parentNode.insertBefore(vista, ancla.nextSibling);
      form._lfVista = vista;

      function poner(blob, deDonde) {
        if (!TIPOS.test(blob.type)) {
          avisoEn(form, 'Solo se pueden adjuntar imágenes (JPG, PNG, WebP) o PDF.');
          return;
        }
        var err = form.querySelector('[data-lf-error]'); if (err) err.hidden = true;
        aligerar(blob, function (b) {
          if (b.size > MAX_ARCHIVO) { avisoEn(form, 'El archivo pesa ' + peso(b.size) + '; el máximo es 10 MB.'); return; }
          var ext = b.type === 'application/pdf' ? 'pdf' : b.type === 'image/jpeg' ? 'jpg' : b.type.split('/')[1];
          var nombre = (deDonde === 'pegado' ? 'captura-' + fechaArchivo() : (blob.name || 'archivo').replace(/\.[^.]+$/, '')) + '.' + ext;
          var dt = new DataTransfer();
          try { dt.items.add(new File([b], nombre, { type: b.type })); } catch (e) { return; }
          inp._lfListo = true;            // ya está preparado: no volver a achicarlo
          inp.files = dt.files;
          inp.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }

      // Elegido con el botón: se achica si pesa; luego, la vista previa.
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (f && !inp._lfListo && /^image\/(png|jpeg|webp)$/.test(f.type) && f.size > LIGERO) {
          poner(f, 'elegido');
          return;
        }
        inp._lfListo = false;
        vistaPrevia(form, f || null, f ? f.name : '');
      });

      // Ctrl+V. Si lo copiado también trae texto, se pega el texto.
      form.addEventListener('paste', function (e) {
        var cd = e.clipboardData;
        if (!cd || !cd.items || (cd.getData && cd.getData('text/plain'))) return;
        for (var i = 0; i < cd.items.length; i++) {
          var it = cd.items[i];
          if (it.kind === 'file' && /^image\//.test(it.type)) {
            var f = it.getAsFile();
            if (f) { e.preventDefault(); poner(f, 'pegado'); return; }
          }
        }
      });

      // Arrastrar una imagen encima.
      var arrastres = 0;
      function conArchivos(e) { return e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
      form.addEventListener('dragenter', function (e) { if (!conArchivos(e)) return; arrastres++; form.classList.add('lf-soltar'); });
      form.addEventListener('dragleave', function () { if (--arrastres <= 0) { arrastres = 0; form.classList.remove('lf-soltar'); } });
      form.addEventListener('dragover',  function (e) { if (conArchivos(e)) e.preventDefault(); });
      form.addEventListener('drop', function (e) {
        if (!conArchivos(e)) return;
        e.preventDefault();
        arrastres = 0; form.classList.remove('lf-soltar');
        var f = e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) poner(f, 'arrastrado');
      });
    });
  }
  // La página de Ayuda puede llegar sin recargar (y sin chat que la enlace).
  document.addEventListener('lf:cargado', function () { prepararPegado(document); });

  /* ── La ventana de chat ocupa JUSTO lo que queda de pantalla ──
     Como en WhatsApp: la página no se mueve, solo la lista de mensajes.
     Se mide desde donde empieza la ventana hasta el borde de abajo (en el
     celular, hasta la barra del pulgar), y se vuelve a medir al cambiar
     el tamaño, al girar el teléfono o al abrir el teclado. En soporte la
     columna de al lado (estado, prioridad…) se desplaza por su cuenta en
     vez de alargar la página. */
  // Bajo, a propósito: con la página fija, una ventana más alta que la
  // pantalla dejaría la caja de escribir fuera de alcance.
  var ALTO_MINIMO = 240;
  function ajustarVentanas() {
    var vs = document.querySelectorAll('.lf-chat-ventana');
    var html = document.documentElement;
    // Con una ventana de chat, la página NO se desplaza (CSS html.lf-fijo):
    // solo la lista de mensajes. Se sube al principio antes de fijarla.
    if (vs.length) {
      if (!html.classList.contains('lf-fijo')) {
        if (window.pageYOffset) window.scrollTo(0, 0);
        document.body.classList.remove('lf-dedo-oculta');
      }
      html.classList.add('lf-fijo');
    } else {
      html.classList.remove('lf-fijo');
    }
    [].forEach.call(vs, ajustarVentana);
  }

  /* "Detalles": en el celular la columna de la derecha del ticket es un
     panel que entra desde el lado; se cierra con la ×, tocando fuera o
     con Escape. */
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var split = e.target.closest('.lf-split-chat') || document.querySelector('.lf-split-chat');
    if (!split) return;
    if (e.target.closest('[data-lf-detalles]')) { split.classList.add('ver-detalles'); return; }
    if (e.target.closest('[data-lf-detalles-cerrar]') || e.target === split) split.classList.remove('ver-detalles');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var split = document.querySelector('.lf-split-chat.ver-detalles');
    if (split) split.classList.remove('ver-detalles');
  });
  function ajustarVentana(v) {
    if (!v.isConnected) return;
    var vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    var lista = v.querySelector('[data-lf-lista]');
    var alFin = lista && lista.scrollHeight - lista.scrollTop - lista.clientHeight < 80;

    var dedo = document.querySelector('.lf-dedo');
    var barra = (dedo && getComputedStyle(dedo).display !== 'none') ? dedo.getBoundingClientRect().height : 0;
    var arriba = v.getBoundingClientRect().top + (window.pageYOffset || 0);
    var split = v.closest('.lf-split');
    var lado = split && split.children[1] && split.children[1] !== v ? split.children[1] : null;
    var dosColumnas = !!(split && getComputedStyle(split).gridTemplateColumns.split(' ').length > 1);
    // En el celular la columna de al lado queda DEBAJO del chat: se llega
    // a ella bajando la página, como antes.
    // (En el ticket de soporte, en el celular, la columna es un panel
    // aparte —fijo, fuera del flujo—: no queda debajo.)
    var apilado = !!(lado && !dosColumnas && getComputedStyle(lado).position !== 'fixed');

    var alto = Math.max(ALTO_MINIMO, Math.floor(vh - arriba - barra - 14));
    v.style.height = alto + 'px';
    // La columna de al lado mide LO MISMO que el chat: dos paneles parejos
    // de arriba abajo. Se desplaza por dentro si no le cabe todo, y su
    // última tarjeta se estira para llenarla (CSS .lf-lado-chat).
    if (lado) {
      lado.classList.toggle('lf-lado-chat', dosColumnas);
      lado.style.height = dosColumnas ? alto + 'px' : '';
    }
    // Lo que todavía sobre de página (márgenes, rellenos) se le quita a la
    // ventana: que no quede nada que desplazar.
    if (!apilado) {
      var sobra = document.documentElement.scrollHeight - vh;
      if (sobra > 0) {
        alto = Math.max(ALTO_MINIMO, alto - Math.ceil(sobra));
        v.style.height = alto + 'px';
        if (lado && dosColumnas) lado.style.height = alto + 'px';
      }
    }
    if (alFin) lista.scrollTop = lista.scrollHeight;
  }
  var ajustePendiente = false;
  function alCambiarTamano() {
    if (ajustePendiente) return;
    ajustePendiente = true;
    requestAnimationFrame(function () { ajustePendiente = false; ajustarVentanas(); });
  }
  window.addEventListener('resize', alCambiarTamano);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', alCambiarTamano);
  window.addEventListener('load', alCambiarTamano);
  document.addEventListener('lf:cargado', alCambiarTamano);

  /* ══ Piezas compartidas por las novedades ══ */
  function leer(k)      { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function sesionLeer(k)  { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function sesionGuardar(k, v) { try { if (v === null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) {} }

  /* La cuenta en un enlace del menú (el lateral y la barra del celular;
     no en los botones "Volver" que apuntan al mismo lado). */
  function marcarEnlace(href, n, uno, varios) {
    var s = 'a[href="' + href + '"]';
    [].forEach.call(document.querySelectorAll('.lf-nav ' + s + ', .lf-dedo ' + s), function (a) {
      var b = a.querySelector('.lf-noti-num');
      if (!n) { if (b) b.remove(); return; }
      if (!b) { b = crear('span', 'lf-noti-num'); a.appendChild(b); }
      var t = n > 9 ? '9+' : String(n);
      if (b.textContent !== t) {
        b.textContent = t;
        b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop');
      }
      b.title = n === 1 ? uno : n + ' ' + varios;
    });
  }

  /* Ir a una página por el mismo camino que un clic (la navegación sin
     recargar lo toma si está). */
  function ir(href) {
    var a = crear('a'); a.href = href; a.hidden = true; a.setAttribute('data-parcial', '');
    document.body.appendChild(a); a.click(); a.remove();
  }

  /* El aviso dentro de la plataforma. `boton`: el texto del botón de
     acción ("Ver" si no se dice otro). */
  function avisar(titulo, linea, alVer, boton_) {
    despedir(document.querySelector('.lf-toast-chat'), 200);
    var caja = crear('div', 'lf-toast-chat');
    caja.setAttribute('role', 'status');
    var ico = crear('span', 'ico');
    ico.innerHTML = ICONO_CHAT;
    caja.appendChild(ico);
    var txt = crear('div', 'txt');
    txt.appendChild(crear('b', '', titulo));
    txt.appendChild(crear('small', '', linea));
    caja.appendChild(txt);
    // Sin `alVer` es solo informativo: sin botón "Ver", con el texto
    // completo (no en una línea cortada) y más tiempo para leerlo.
    if (alVer) {
      var ver = boton('btn btn-primary btn-sm', boton_ || 'Ver');
      ver.addEventListener('click', function () { despedir(caja, 200); alVer(); });
      caja.appendChild(ver);
    } else {
      caja.classList.add('largo');
    }
    var x = boton('x lf-signo', '×', 'Cerrar aviso');
    x.addEventListener('click', function () { despedir(caja, 200); });
    caja.appendChild(x);
    document.body.appendChild(caja);
    setTimeout(function () { despedir(caja, 320); }, alVer ? 9000 : 20000);
  }

  var ICONO_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M12 3.5c4.9 0 8.5 3.3 8.5 7.6s-3.6 7.6-8.5 7.6c-1 0-2-.1-2.9-.4L5 20l1.1-3.6C4.5 15 3.5 13.2 3.5 11.1 3.5 6.8 7.1 3.5 12 3.5z"/>'
    + '<path d="M8.6 11.2h.01M12 11.2h.01M15.4 11.2h.01" stroke-width="2.6"/></svg>';

  /* Minimizar (una raya) y, ya minimizado, volver a la ventana completa
     (un cuadrado): como los botones de una ventana. */
  var ICONO_MIN = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="2.2" stroke-linecap="round"><path d="M6 12h12"/></svg>';
  var ICONO_COMPLETO = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="2" stroke-linejoin="round"><rect x="5.5" y="5.5" width="13" height="13" rx="2.5"/></svg>';
  /* Enviar: el avión de papel de las apps de mensajes. */
  var ICONO_ENVIAR = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M21.5 2.5 10.6 13.4"/><path d="M21.5 2.5 14.6 21.5l-4-8.1-8.1-4z"/></svg>';

  function pintarMin(p) {
    if (!p || !p._lfMin) return;
    var minimizado = p.classList.contains('min');
    p._lfMin.innerHTML = minimizado ? ICONO_COMPLETO : ICONO_MIN;
    p._lfMin.title = minimizado ? 'Abrir en ventana completa' : 'Minimizar';
    p._lfMin.setAttribute('aria-label', p._lfMin.title);
  }

  /* ══ 2 y 3 · Novedades y chat flotante (el cliente) ══
     Como una app de mensajes:
       · la BURBUJA siempre está (para quien puede reportar problemas);
       · al tocarla, la LISTA de sus reportes abiertos: el último mensaje,
         hace cuánto y cuántos no ha leído. Elige uno y se abre su chat;
       · en el chat, "←" regresa a la lista para elegir otro;
       · sin reportes abiertos, la lista ofrece reportar un problema.
     Con un solo reporte, o con una respuesta de soporte sin leer, la
     burbuja entra directo a ese chat. Y cuando soporte contesta, el chat
     se abre solo en ese reporte. */
  var panel = null;
  var burbuja = null, pendiente = null, sinLeerAhora = 0, clienteListo = false;
  var activos = [];          // sus reportes sin resolver (la lista)
  var borradores = {};       // lo que iba escribiendo en cada uno
  var revisarCliente = function () {};

  var ESTADO_CLIENTE = {
    abierto: 'Recibido', en_curso: 'En atención', esperando: 'Espera tu respuesta'
  };

  function enPaginaDe(id) {
    if (location.pathname !== '/ayuda') return false;
    var m = /[?&]ver=(\d+)/.exec(location.search);
    return !!m && +m[1] === +id;
  }

  /* En la página de un reporte (la ventana de chat) la burbuja sobra. */
  function hayVentana() { return !!document.querySelector('.lf-chat-ventana'); }

  /* ── La burbuja ──
     Siempre a la mano. Lleva la cuenta de lo que soporte escribió y no se
     ha leído, y al pasar el cursor se abre en una pastilla que dice qué es. */
  function pintarBurbuja(sinLeer) {
    if (typeof sinLeer === 'number') sinLeerAhora = sinLeer;
    if (!clienteListo) return;      // hasta saber qué tiene (primera consulta)
    if (!burbuja) {
      burbuja = crear('button', 'lf-chat-burbuja oculta');
      burbuja.type = 'button';
      burbuja.setAttribute('aria-label', 'Abrir el chat con soporte');
      var ico = crear('span', 'ico');
      ico.innerHTML = ICONO_CHAT;
      burbuja.appendChild(ico);
      burbuja.appendChild(crear('span', 'lbl', 'Soporte'));
      burbuja.appendChild(crear('span', 'lf-noti-num'));
      burbuja.addEventListener('click', alTocarBurbuja);
      document.body.appendChild(burbuja);
      // Un cuadro después, para que la entrada se anime.
      requestAnimationFrame(function () { requestAnimationFrame(function () { pintarBurbuja(); }); });
      return;
    }
    // Se esconde (con animación) mientras el chat está abierto.
    burbuja.classList.toggle('oculta', !!panel || hayVentana());
    burbuja.classList.toggle('con-nuevos', sinLeerAhora > 0);
    var num = burbuja.querySelector('.lf-noti-num');
    var texto = sinLeerAhora > 9 ? '9+' : String(sinLeerAhora || '');
    if (num.textContent !== texto) {
      num.textContent = texto;
      // El número "salta" al cambiar, para que se note.
      num.classList.remove('pop'); void num.offsetWidth; if (sinLeerAhora) num.classList.add('pop');
    }
    num.hidden = !sinLeerAhora;
  }

  /* Respuesta sin leer: directo a ese chat. Un solo reporte: directo a él.
     Varios, o ninguno: la lista. */
  function alTocarBurbuja() {
    if (pendiente) { abrirChat(pendiente); return; }
    if (activos.length === 1) { abrirChat(activos[0]); return; }
    abrirLista();
  }

  function cerrarChat() {
    if (panel) {
      var p = panel;
      panel = null;
      if (p._lfChat) p._lfChat.parar();
      despedir(p, 240);
    }
    sesionGuardar('lf_chat_abierto', null);
    pintarBurbuja();
  }

  function crearPanel() {
    panel = crear('div', 'lf-chatf');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Chat con soporte');
    panel.setAttribute('data-lf-lado', 'cliente');
    panel.setAttribute('data-lf-flota', '');

    var cab = crear('header');
    // "←": de un chat a la lista de reportes.
    var atras = boton('bt atras', '←', 'Ver todos tus reportes');
    atras.addEventListener('click', function (e) { e.stopPropagation(); abrirLista(); });
    cab.appendChild(atras);
    var tit = crear('div', 'tit');
    tit.appendChild(crear('b', '', 'Soporte LibertyFin'));
    tit.appendChild(crear('small'));
    cab.appendChild(tit);
    var irA = crear('a', 'bt ir', '↗'); irA.title = 'Ver el reporte completo';
    var min = boton('bt min', null, 'Minimizar');
    var x = boton('bt lf-signo', '×', 'Cerrar');
    var p = panel;
    p._lfMin = min;
    // stopPropagation: el ícono se cambia aquí mismo, y el clic llegaba a
    // la barra con el ícono viejo ya fuera del botón; la barra creía que
    // se tocó fuera de un botón y volvía a abrir el chat al instante.
    min.addEventListener('click', function (e) { e.stopPropagation(); p.classList.toggle('min'); pintarMin(p); });
    x.addEventListener('click', cerrarChat);
    cab.addEventListener('click', function (e) {
      if (p.classList.contains('min') && !e.target.closest('.bt')) { p.classList.remove('min'); pintarMin(p); }
    });
    // Avisos de escritorio aunque cierre LibertyFin (se ve solo si se puede
    // y todavía no están activados; ver pintarOfertas).
    var pushBt = boton('bt push', null, 'Avisarme cuando respondan, aunque cierre LibertyFin');
    pushBt.setAttribute('data-lf-push', '');
    pushBt.innerHTML = ICONO_CAMPANA;
    pushBt.hidden = true;
    cab.appendChild(pushBt);
    cab.appendChild(irA); cab.appendChild(min); cab.appendChild(x);
    panel.appendChild(cab);

    document.body.appendChild(panel);
    pintarMin(panel);
    pintarOfertas();
  }

  /* Deja el panel sin contenido (para mostrar otro chat o la lista): deja
     de escuchar la conversación que hubiera. */
  function vaciarPanel() {
    if (panel._lfChat) panel._lfChat.parar();
    panel._lfChat = null;
    [].forEach.call(panel.querySelectorAll(
      '.lista, form, [data-lf-cerrado], .lf-chatf-reportes, .lf-chatf-pie-lista, .lf-ir-abajo'),
      function (n) { n.remove(); });
    ['data-ticket', 'data-lf-chat', 'data-lf-escribe'].forEach(function (a) { panel.removeAttribute(a); });
    panel.classList.remove('min');
    pintarMin(panel);
  }

  /* ── La lista de reportes ── */
  function abrirLista() {
    if (!panel) crearPanel(); else vaciarPanel();
    panel.classList.add('en-lista');
    sesionGuardar('lf_chat_abierto', JSON.stringify({ lista: true, quien: quien() }));
    panel.querySelector('header small').textContent = 'Tus reportes';

    panel.appendChild(crear('div', 'lf-chatf-reportes'));
    var pie = crear('div', 'lf-chatf-pie-lista');
    var todos = crear('a', 'todos', 'Ver todos mis reportes');
    todos.href = '/ayuda'; todos.setAttribute('data-parcial', '');
    var nuevo = crear('a', 'btn btn-primary btn-sm', 'Reportar un problema');
    nuevo.href = '/ayuda'; nuevo.setAttribute('data-parcial', '');
    pie.appendChild(todos); pie.appendChild(nuevo);
    panel.appendChild(pie);

    pintarLista();
    pintarBurbuja();
    revisarCliente();        // y se trae lo más reciente
  }

  function pintarLista() {
    if (!panel || !panel.classList.contains('en-lista')) return;
    var cont = panel.querySelector('.lf-chatf-reportes');
    if (!cont) return;
    cont.innerHTML = '';
    // Antes de la primera respuesta del servidor no se sabe si hay o no.
    if (!clienteListo) { cont.appendChild(crear('p', 'lf-chat-vacio', 'Cargando tus reportes…')); return; }
    if (!activos.length) {
      var nada = crear('div', 'nada');
      var ic = crear('span', 'ico'); ic.innerHTML = ICONO_CHAT;
      nada.appendChild(ic);
      nada.appendChild(crear('b', '', 'No tienes reportes abiertos'));
      nada.appendChild(crear('small', '', 'Si algo no funciona, cuéntanos y te ayudamos.'));
      cont.appendChild(nada);
      return;
    }
    activos.forEach(function (a) {
      var b = boton('rep' + (a.sin_leer ? ' sin-leer' : ''), null, 'Abrir el chat de ' + (a.folio || 'este reporte'));
      var ic = crear('span', 'ico'); ic.innerHTML = ICONO_CHAT;
      b.appendChild(ic);
      var tx = crear('span', 'tx');
      tx.appendChild(crear('b', '', a.asunto || a.folio));
      tx.appendChild(crear('small', '', a.folio + ' · ' + (ESTADO_CLIENTE[a.estado] || a.estado)));
      if (a.ultimo) tx.appendChild(crear('span', 'ult', (a.de_soporte ? 'Soporte: ' : 'Tú: ') + a.ultimo));
      b.appendChild(tx);
      var meta = crear('span', 'meta');
      if (a.hace !== null && a.hace !== undefined) meta.appendChild(crear('small', '', hace(a.hace)));
      if (a.sin_leer) meta.appendChild(crear('span', 'lf-noti-num', a.sin_leer > 9 ? '9+' : String(a.sin_leer)));
      b.appendChild(meta);
      b.addEventListener('click', function () { abrirChat(a); });
      cont.appendChild(b);
    });
  }

  /* El cuerpo del chat para un reporte: la lista y el formulario. Al
     cambiar de reporte se reemplaza solo esto; el panel se queda. */
  function armarCuerpo(t) {
    var lista = crear('div', 'lista');
    lista.setAttribute('data-lf-lista', '');
    var vacio = crear('p', 'lf-chat-vacio', 'Cargando la conversación…');
    vacio.setAttribute('data-lf-vacio', '');
    lista.appendChild(vacio);

    var form = crear('form');
    form.method = 'post';
    form.action = '/ayuda/' + (+t.id) + '/responder';
    form.enctype = 'multipart/form-data';
    form.setAttribute('data-lf-enviar', '');
    form.setAttribute('data-lf-enter', '');
    var tk = crear('input'); tk.type = 'hidden'; tk.name = 'token'; tk.value = token();
    var ta = crear('textarea'); ta.name = 'cuerpo'; ta.rows = 2;
    ta.placeholder = 'Mensaje';
    ta.value = borradores[t.id] || '';
    ta.addEventListener('input', function () { borradores[t.id] = ta.value; });
    var pie = crear('div', 'pie');
    var fid = 'lfChatAdj' + t.id;
    var lf = crear('span', 'lf-file');
    var inp = crear('input'); inp.type = 'file'; inp.name = 'adjunto'; inp.id = fid;
    inp.accept = 'image/png,image/jpeg,image/webp,application/pdf';
    var lab = crear('label', 'bt', 'Adjuntar'); lab.htmlFor = fid; lab.title = 'Adjuntar captura o archivo';
    var nom = crear('span', 'n', 'o pega con Ctrl+V'); nom.setAttribute('data-vacio', 'o pega con Ctrl+V');
    lf.appendChild(inp); lf.appendChild(lab); lf.appendChild(nom);
    var env = crear('button', 'btn btn-primary btn-sm', 'Enviar'); env.type = 'submit';
    pie.appendChild(lf); pie.appendChild(env);
    form.appendChild(tk); form.appendChild(ta); form.appendChild(pie);
    form.addEventListener('submit', function () { borradores[t.id] = ''; });
    return { lista: lista, form: form, ta: ta };
  }

  /* ── El chat de un reporte ── */
  function abrirChat(t) {
    if (panel && !panel.classList.contains('en-lista') && +panel.getAttribute('data-ticket') === +t.id) {
      panel.classList.remove('min');
      pintarMin(panel);
      var ta0 = panel.querySelector('textarea'); if (ta0) ta0.focus();
      return;
    }
    if (!panel) crearPanel(); else vaciarPanel();
    panel.classList.remove('en-lista');
    // Con la marca de la cuenta: si en esta pestaña entra otra persona, no
    // se le intenta reabrir el reporte de la anterior.
    sesionGuardar('lf_chat_abierto', JSON.stringify({ id: t.id, folio: t.folio || '', asunto: t.asunto || '', quien: quien() }));

    panel.setAttribute('data-ticket', t.id);
    panel.setAttribute('data-lf-chat', '/ayuda/' + (+t.id) + '/mensajes');
    panel.setAttribute('data-lf-escribe', '/ayuda/' + (+t.id) + '/escribiendo');
    panel.querySelector('header small').textContent = (t.folio ? t.folio + ' · ' : '') + (t.asunto || '');
    panel.querySelector('header .ir').href = '/ayuda?ver=' + (+t.id);

    var c = armarCuerpo(t);
    panel.appendChild(c.lista);
    panel.appendChild(c.form);
    pintarBurbuja();            // la burbuja se va mientras el chat está abierto
    conversacion(panel);
    c.ta.focus({ preventScroll: true });
  }

  function clienteNovedades() {
    if (document.body.getAttribute('data-lf-ayuda') !== '1' || !window.fetch) return;

    function revisar() {
      if (document.hidden) return;
      pedir('/ayuda/novedades').then(function (j) {
        if (!j || !j.ok) return;
        marcarEnlace('/ayuda', j.sin_leer, '1 respuesta de soporte sin leer', 'respuestas de soporte sin leer');
        activos = j.activos || [];
        pendiente = (j.tickets && j.tickets[0]) || null;
        clienteListo = true;
        pintarBurbuja(j.sin_leer);
        pintarLista();
        if (!j.tickets || !j.tickets.length) return;
        var t = j.tickets[0];
        // En la página de ese ticket, o con su chat abierto, la
        // conversación ya se actualiza sola.
        if (enPaginaDe(t.id) || (panel && +panel.getAttribute('data-ticket') === t.id)) return;
        // Cada mensaje se avisa UNA vez, aunque se recargue la página.
        var clave = 'lf_chat_avisado_' + t.id;
        if ((+leer(clave) || 0) >= t.mensaje_id) return;
        guardar(clave, t.mensaje_id);
        // Primero el aviso; el chat lo abre la persona cuando quiera. Abrirlo
        // solo la interrumpía a media tarea (a media venta en Caja, por
        // ejemplo). Si cierra el aviso, la burbuja sigue con la cuenta.
        avisar(t.autor + ' te respondió', t.folio + ' · ' + t.extracto,
               function () { abrirChat(t); }, 'Abrir chat');
      }, function () {});
    }
    revisarCliente = revisar;
    alEnviar.push(function () { setTimeout(revisar, 800); });
    // Al cambiar de página: en la de un reporte la burbuja se esconde.
    document.addEventListener('lf:cargado', function () { pintarBurbuja(); });

    // Si estaba abierto antes de recargar (un chat o la lista), se vuelve a abrir.
    var antes = sesionLeer('lf_chat_abierto');
    if (antes) {
      try {
        var a = JSON.parse(antes);
        // Solo si lo dejó abierto ESTA persona (la marca de su cuenta).
        if (!a || !a.quien || a.quien !== quien()) sesionGuardar('lf_chat_abierto', null);
        else if (a.lista) abrirLista();
        else if (a.id) abrirChat(a);
      } catch (e) {}
    }

    setTimeout(revisar, 600);
    setInterval(revisar, CADA_NOVEDADES);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
  }

  /* ══ 4 · Novedades de soporte ══
     Los mensajes de clientes que quien atiende NO ha leído, en sus
     tickets y en los que nadie ha tomado. En cuanto abre el ticket deja
     de salir (aunque no haya contestado todavía); si el cliente vuelve a
     escribir, sale otra vez. Llegan por cinco lados, para que no se
     escape ninguno:

       · la CAMPANA de arriba, con la lista (quién, de qué empresa, hace
         cuánto, si es nuevo o nadie lo ha tomado);
       · la cuenta en el menú "Tickets" y en el título de la pestaña;
       · un aviso dentro de la plataforma y un sonido corto;
       · un aviso del ESCRITORIO si la pestaña está en segundo plano (hay
         que permitirlo una vez, desde la campana).

     Se pregunta cada 15 s; en segundo plano cada 30 s, que es justo
     cuando el aviso del escritorio sirve. Cada mensaje se avisa UNA vez;
     la primera vez en un navegador no se avisa lo viejo: para eso está
     la lista. */
  var CADA_SOPORTE = 15000, CADA_SOPORTE_OCULTA = 30000;
  var campana = null;                   // lo último que contestó el servidor
  var revisarSoporte = function () {};

  function soporteNovedades() {
    if (document.body.getAttribute('data-lf-soporte') !== '1' || !window.fetch) return;
    var reloj = null, vivo = true;

    function revisar() {
      if (!vivo) return;
      clearTimeout(reloj);
      pedir('/tickets/novedades').then(function (j) {
        if (!j) { vivo = false; return; }          // la sesión venció: no insistir
        if (j.ok) recibirSoporte(j);
        reloj = setTimeout(revisar, document.hidden ? CADA_SOPORTE_OCULTA : CADA_SOPORTE);
      }, function () {
        reloj = setTimeout(revisar, CADA_SOPORTE_OCULTA);
      });
    }
    revisarSoporte = revisar;
    alEnviar.push(function () { setTimeout(revisar, 800); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
    // La barra de arriba se cambia al navegar: la campana nueva llega vacía.
    // Y si se entró a un ticket, ese ya se leyó: fuera de la lista, y se
    // pregunta de nuevo para tener la cuenta exacta.
    document.addEventListener('lf:cargado', function () {
      var m = /^\/tickets\/(\d+)$/.exec(location.pathname);
      if (m) { yaLeido(m[1]); setTimeout(revisar, 600); }
      pintarCampana();
    });
    var aqui = /^\/tickets\/(\d+)$/.exec(location.pathname);
    if (aqui) yaLeido(aqui[1]);
    setTimeout(revisar, 900);
  }

  function recibirSoporte(j) {
    // El ticket que tienes abierto ya lo estás leyendo: no cuenta, aunque
    // el servidor todavía no haya alcanzado a marcarlo.
    var antes = (j.tickets || []).length;
    j.tickets = (j.tickets || []).filter(function (t) { return !viendo(t.id); });
    j.esperando = Math.max(0, (+j.esperando || 0) - (antes - j.tickets.length));
    campana = j;
    contarCampana();

    var primera = !leer('lf_sop_inicio');
    guardar('lf_sop_inicio', '1');
    var nuevos = (j.tickets || []).filter(function (t) {
      return (+leer('lf_sop_avisado_' + t.id) || 0) < t.mensaje_id;
    });
    nuevos.forEach(function (t) { guardar('lf_sop_avisado_' + t.id, t.mensaje_id); });
    // DENTRO DEL CHAT DE ESE TICKET NO SE AVISA: ni aviso, ni sonido, ni
    // escritorio. Lo que escribe el cliente ya aparece en la conversación.
    // Solo si la pestaña está en segundo plano (no lo está mirando).
    nuevos = nuevos.filter(function (t) { return !viendo(t.id); });
    if (primera || !nuevos.length) return;

    var t = nuevos[0], mas = nuevos.length - 1;
    var titulo = t.autor + (t.empresa ? ' (' + t.empresa + ')' : '')
               + (t.nuevo ? ' abrió un ticket' : ' respondió');
    var linea = t.folio + ' · ' + t.extracto + (mas > 0 ? '  ·  y ' + mas + ' más' : '');
    var abrir = function () { ir('/tickets/' + t.id); };
    if (document.hidden || !document.hasFocus()) escritorio(titulo, linea, t.id, abrir);
    if (!document.hidden) avisar(titulo, linea, abrir);
    // En segundo plano y con avisos por fuera, el del sistema ya suena.
    if (!(document.hidden && pushActivo())) sonar();
  }

  /* La cuenta de lo no leído: en el menú, en el título y en la campana. */
  function contarCampana() {
    var n = campana ? (+campana.esperando || 0) : 0;
    marcarEnlace('/tickets', n, '1 ticket con mensajes sin leer', 'tickets con mensajes sin leer');
    cuentaTitulo = n; pintarTitulo();
    pintarCampana();
  }

  /* Ya lo abriste: sale de la lista al momento, sin esperar a la
     siguiente revisión (el servidor lo marca como leído al abrirlo). */
  function yaLeido(id) {
    if (!campana || !campana.tickets) return;
    var antes = campana.tickets.length;
    campana.tickets = campana.tickets.filter(function (t) { return +t.id !== +id; });
    if (campana.tickets.length < antes) {
      campana.esperando = Math.max(0, (+campana.esperando || 0) - 1);
      contarCampana();
    }
  }

  /* "hace 3 min", con los segundos que mide la base. */
  function hace(seg) {
    seg = +seg || 0;
    if (seg < 60) return 'hace un momento';
    var m = Math.floor(seg / 60);
    if (m < 60) return 'hace ' + m + ' min';
    var h = Math.floor(m / 60);
    if (h < 24) return 'hace ' + h + ' h';
    var d = Math.floor(h / 24);
    return d === 1 ? 'hace 1 día' : 'hace ' + d + ' días';
  }

  /* ── La campana ──
     Vive en la barra de arriba (topbar.php la pone solo para soporte).
     El número es cuántos esperan respuesta; el punto que late, que hay
     algo que no se ha visto desde la última vez que se abrió. */
  /* ¿Está viendo ese ticket ahora mismo? Lo que escriba ahí el cliente ya
     lo tiene enfrente en el chat: no es "algo sin ver". */
  function viendo(id) {
    return !document.hidden && location.pathname === '/tickets/' + id;
  }
  function sinVer(t, visto) { return t.mensaje_id > visto && !viendo(t.id); }

  function pintarCampana() {
    var j = campana;
    if (!j) return;
    var n = +j.esperando || 0;
    var visto = +leer('lf_sop_campana_vista') || 0;
    var maximo = (j.tickets || []).reduce(function (a, t) { return sinVer(t, visto) ? Math.max(a, t.mensaje_id) : a; }, 0);
    [].forEach.call(document.querySelectorAll('[data-lf-campana]'), function (c) {
      var num = c.querySelector('.lf-noti-num');
      var txt = n > 9 ? '9+' : String(n || '');
      if (num.textContent !== txt) {
        num.textContent = txt;
        num.classList.remove('pop'); void num.offsetWidth; if (n) num.classList.add('pop');
      }
      num.hidden = !n;
      c.classList.toggle('con-nuevos', maximo > visto);
      var pop = c.querySelector('.lf-campana-pop');
      if (pop && !pop.hidden) llenarCampana(pop);
    });
  }

  function llenarCampana(pop) {
    var j = campana || { esperando: 0, tickets: [] };
    var visto = +leer('lf_sop_campana_vista') || 0;
    pop.innerHTML = '';

    var cab = crear('header');
    cab.appendChild(crear('b', '', 'Mensajes sin leer'));
    if (+j.esperando) cab.appendChild(crear('span', 'cuantos', String(j.esperando)));
    pop.appendChild(cab);

    var lista = crear('div', 'lista');
    if (!(j.tickets || []).length) {
      var nada = crear('div', 'nada');
      nada.appendChild(crear('span', 'ico', '✓'));
      nada.appendChild(crear('b', '', 'Estás al día'));
      nada.appendChild(crear('small', '', 'No hay mensajes de clientes sin leer.'));
      lista.appendChild(nada);
    }
    (j.tickets || []).forEach(function (t) {
      var a = crear('a', 'it' + (sinVer(t, visto) ? ' sin-ver' : '') + (viendo(t.id) ? ' aqui' : ''));
      a.href = '/tickets/' + (+t.id);
      a.setAttribute('data-parcial', '');
      a.appendChild(crear('span', 'pri pri-' + (t.prioridad || 'normal')));
      var tx = crear('span', 'tx');
      var l1 = crear('span', 'l1');
      l1.appendChild(crear('b', '', t.folio));
      if (t.empresa) l1.appendChild(crear('span', 'emp', t.empresa));
      tx.appendChild(l1);
      tx.appendChild(crear('span', 'as', t.asunto || ''));
      tx.appendChild(crear('small', '', (t.autor ? t.autor + ': ' : '') + (t.extracto || '')));
      a.appendChild(tx);
      var meta = crear('span', 'meta');
      meta.appendChild(crear('small', '', hace(t.hace)));
      if (t.nuevo) meta.appendChild(crear('span', 'tag nuevo', 'Nuevo'));
      else if (t.libre) meta.appendChild(crear('span', 'tag', 'Sin asignar'));
      a.appendChild(meta);
      lista.appendChild(a);
    });
    pop.appendChild(lista);

    // El pie: ir a la bandeja y cómo avisar.
    var pie = crear('footer');
    var todos = crear('a', 'todos', 'Ver la bandeja');
    todos.href = '/tickets'; todos.setAttribute('data-parcial', '');
    pie.appendChild(todos);

    var op = crear('div', 'ops');
    var son = crear('label', 'op');
    var chk = crear('input'); chk.type = 'checkbox'; chk.checked = leer('lf_sop_sonido') !== '0';
    chk.addEventListener('change', function () { guardar('lf_sop_sonido', chk.checked ? '1' : '0'); if (chk.checked) sonar(true); });
    son.appendChild(chk); son.appendChild(document.createTextNode(' Sonido'));
    op.appendChild(son);

    if (!window.isSecureContext) {
      op.appendChild(crear('small', 'bloq', 'Los avisos del escritorio necesitan HTTPS'));
    } else if (PUSH && Notification.permission !== 'denied') {
      // Avisos del escritorio AUNQUE LibertyFin esté cerrado. Marcar la
      // casilla pide el permiso (si hace falta) y suscribe este navegador.
      var ep = crear('label', 'op');
      ep.title = 'Avisos en el escritorio aunque cierres LibertyFin';
      var cp = crear('input'); cp.type = 'checkbox'; cp.checked = pushActivo();
      cp.addEventListener('change', function () {
        cp.disabled = true;
        (cp.checked ? activarPush() : desactivarPush()).then(function () { llenarCampana(pop); });
      });
      ep.appendChild(cp); ep.appendChild(document.createTextNode(' Escritorio'));
      op.appendChild(ep);
      if (pushActivo()) {
        var pb = boton('permiso', 'Probar', 'Mandar un aviso de prueba a este navegador');
        pb.setAttribute('data-lf-push-probar', '');
        op.appendChild(pb);
      }
      // Si no se pudo activar, se dice por qué (no solo se desmarca).
      if (falloPush && !pushActivo()) op.appendChild(crear('small', 'bloq err', falloPush));
    } else if (window.Notification) {
      if (Notification.permission === 'granted') {
        var esc = crear('label', 'op');
        var ce = crear('input'); ce.type = 'checkbox'; ce.checked = leer('lf_sop_escritorio') !== '0';
        ce.addEventListener('change', function () { guardar('lf_sop_escritorio', ce.checked ? '1' : '0'); });
        esc.appendChild(ce); esc.appendChild(document.createTextNode(' Escritorio'));
        op.appendChild(esc);
      } else if (Notification.permission === 'default') {
        var pedirPermiso = boton('permiso', 'Activar avisos del escritorio');
        pedirPermiso.addEventListener('click', function (e) {
          e.stopPropagation();
          var r = Notification.requestPermission(function () { llenarCampana(pop); });
          if (r && r.then) r.then(function () { llenarCampana(pop); });
        });
        op.appendChild(pedirPermiso);
      } else {
        op.appendChild(crear('small', 'bloq', 'Avisos del escritorio bloqueados en el navegador'));
      }
    }
    pie.appendChild(op);
    pop.appendChild(pie);
  }

  function abrirCampana(c) {
    var pop = c.querySelector('.lf-campana-pop');
    if (!pop) {
      pop = crear('div', 'lf-campana-pop');
      pop.setAttribute('role', 'dialog');
      pop.setAttribute('aria-label', 'Mensajes de clientes sin leer');
      c.appendChild(pop);
    }
    pop.hidden = false;
    c.classList.add('abierta');
    c.querySelector('.lf-campana-bt').setAttribute('aria-expanded', 'true');
    llenarCampana(pop);
    // Lo que se ve aquí ya se vio: el punto deja de latir.
    var maximo = ((campana && campana.tickets) || []).reduce(function (a, t) { return Math.max(a, t.mensaje_id); }, 0);
    if (maximo) guardar('lf_sop_campana_vista', maximo);
    c.classList.remove('con-nuevos');
    revisarSoporte();     // y se trae lo más reciente
  }

  function cerrarCampana() {
    [].forEach.call(document.querySelectorAll('[data-lf-campana].abierta'), function (c) {
      c.classList.remove('abierta');
      var pop = c.querySelector('.lf-campana-pop'); if (pop) pop.hidden = true;
      c.querySelector('.lf-campana-bt').setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var bt = e.target.closest && e.target.closest('.lf-campana-bt');
    if (bt) {
      var c = bt.closest('[data-lf-campana]');
      if (c.classList.contains('abierta')) cerrarCampana(); else abrirCampana(c);
      return;
    }
    // Un clic en un ticket de la lista navega y cierra; fuera, cierra.
    var dentro = e.target.closest && e.target.closest('.lf-campana-pop');
    var it = dentro && e.target.closest('a.it');
    if (it) {
      var m = /\/tickets\/(\d+)/.exec(it.getAttribute('href') || '');
      if (m) yaLeido(m[1]);
    }
    if (!dentro || e.target.closest('a')) cerrarCampana();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarCampana(); });

  /* ── El sonido ──
     Dos notas cortas y suaves, hechas en el navegador (sin archivo). Los
     navegadores no dejan sonar nada hasta que la persona toca la página
     una vez: el primer clic deja listo el audio. */
  var audio = null;
  function prepararAudio() {
    if (audio) { if (audio.state === 'suspended' && audio.resume) audio.resume(); return; }
    var C = window.AudioContext || window.webkitAudioContext;
    if (!C) return;
    try { audio = new C(); } catch (e) { audio = null; }
  }
  document.addEventListener('pointerdown', prepararAudio, true);
  document.addEventListener('keydown', prepararAudio, true);

  function sonar(siempre) {
    if (!siempre && leer('lf_sop_sonido') === '0') return;
    if (!audio || audio.state !== 'running') return;
    try {
      var t0 = audio.currentTime;
      [[880, 0], [1318.5, 0.13]].forEach(function (nota) {
        var o = audio.createOscillator(), g = audio.createGain();
        o.type = 'sine';
        o.frequency.value = nota[0];
        g.gain.setValueAtTime(0.0001, t0 + nota[1]);
        g.gain.exponentialRampToValueAtTime(0.09, t0 + nota[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + nota[1] + 0.32);
        o.connect(g); g.connect(audio.destination);
        o.start(t0 + nota[1]); o.stop(t0 + nota[1] + 0.36);
      });
    } catch (e) {}
  }

  /* ── El aviso del escritorio ──
     Solo si la pestaña no está a la vista (si lo está, basta el de la
     plataforma) y si se permitió. Al darle clic, abre el ticket. */
  function escritorio(titulo, linea, id, abrir) {
    // Con los avisos por fuera activados, ese aviso ya lo muestra
    // lf-sw.js: dos del mismo mensaje sobran.
    if (pushActivo()) return;
    if (!window.Notification || Notification.permission !== 'granted') return;
    if (leer('lf_sop_escritorio') === '0') return;
    try {
      var n = new Notification(titulo, { body: linea, tag: 'lf-ticket-' + id });
      n.onclick = function () { window.focus(); abrir(); n.close(); };
      setTimeout(function () { n.close(); }, 20000);
    } catch (e) {}
  }

  /* Cualquier botón [data-lf-abrir-chat='{"id":…}'] abre ese reporte en el
     chat flotante (por ejemplo, desde la página del reporte). */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lf-abrir-chat]');
    if (!b) return;
    e.preventDefault();
    try { abrirChat(JSON.parse(b.getAttribute('data-lf-abrir-chat'))); } catch (err) {}
  });

  /* ══ 5 · ¿Sigue abierta esta sesión? ══
     Si la cuenta se abrió en otro dispositivo (ver Servicio\SesionUnica),
     esta pestaña se entera SOLA: al volver a ella y cada 15 s mientras
     está a la vista. Sin esto solo se enteraba al dar el siguiente clic,
     y mientras tanto parecía que no la habían sacado. */
  function pulsoSesion() {
    if (!window.fetch) return;
    var ultimo = 0;
    function revisar() {
      if (document.hidden || saliendo) return;
      if (Date.now() - ultimo < 3000) return;   // volver y enfocar llegan juntos
      ultimo = Date.now();
      pedir('/sesion/pulso').then(null, function () {});
    }
    setInterval(revisar, 15000);
    document.addEventListener('visibilitychange', revisar);
    window.addEventListener('focus', revisar);
  }

  /* ══ 6 · Avisos aunque LibertyFin esté cerrado (Web Push) ══
     Quien los activa (soporte con la casilla "Escritorio" de la campana;
     el cliente con "Avisarme cuando respondan") deja este navegador
     suscrito: public/lf-sw.js muestra los avisos aunque no haya ninguna
     pestaña abierta (ver src/Servicio/Push.php).

     Se activan SOLO con un clic: el navegador solo pregunta el permiso
     así, y nadie debe encontrarse avisos que no pidió. Quedan anotados
     con la marca de la cuenta (`data-lf-quien`): si después entra otra
     persona en este navegador, no se le reactivan solos. */
  var PUSH = !!(window.isSecureContext && window.Notification && window.Promise
                && 'serviceWorker' in navigator && 'PushManager' in window);
  var ICONO_CAMPANA = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/></svg>';

  function quien() { return document.body.getAttribute('data-lf-quien') || ''; }
  function pushActivo() {
    return PUSH && Notification.permission === 'granted' && !!quien() && leer('lf_push') === quien();
  }

  function bytesDe(b64) {
    var s = String(b64).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function registrarSW() {
    return navigator.serviceWorker.register('/lf-sw.js', { scope: '/', updateViaCache: 'none' })
      .then(function () { return navigator.serviceWorker.ready; });
  }

  /* La suscripción de este navegador. Si quedó una vieja hecha con otra
     llave, se quita y se hace de nuevo. */
  function suscripcion(reg, llave) {
    var opciones = { userVisibleOnly: true, applicationServerKey: bytesDe(llave) };
    return reg.pushManager.getSubscription()
      .then(function (s) { return s || reg.pushManager.subscribe(opciones); })
      .then(null, function () {
        return reg.pushManager.getSubscription()
          .then(function (s) { return s ? s.unsubscribe() : null; })
          .then(function () { return reg.pushManager.subscribe(opciones); });
      });
  }

  /* La dirección de suscripción va EN HEXADECIMAL. El filtro de seguridad
     del hosting (ModSecurity) rechaza con "406 Not Acceptable" —y la
     petición ni llega a LibertyFin— un campo con "https://..." tal cual, y
     también el base64, por sus guiones (los toma por un comentario de
     SQL). En hexadecimal solo hay 0-9 y a-f, como el token, que pasa. */
  function codificar(u) {
    var h = '';
    for (var i = 0; i < u.length; i++) {
      var c = u.charCodeAt(i).toString(16);
      h += (c.length < 2 ? '0' : '') + c;
    }
    return h;
  }

  /* Formulario simple (x-www-form-urlencoded): esos filtros revisan con
     más reglas el de varias partes. */
  function formulario(campos) {
    return Object.keys(campos).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(campos[k]);
    }).join('&');
  }

  /* Devuelve '' si quedó guardada, o qué falló, con el código HTTP: sin
     él no hay forma de saber si fue el filtro del hosting, un archivo sin
     subir o la base. */
  function avisarAlServidor(sub) {
    return fetch('/push/suscribir', {
      method: 'POST', credentials: 'same-origin',
      body: formulario({ token: token(), ep: codificar(sub.endpoint) }),
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) {}
        return { estado: r.status, j: j };
      });
    }).then(function (x) {
      var j = x.j;
      if (j && j.sesion_cerrada) { aEntrar(); return 'Tu sesión se cerró.'; }
      if (j && j.ok) { guardar('lf_push', j.quien || quien()); return ''; }
      if (j && j.error) return j.error;
      if (j) return 'El servidor no guardó la suscripción. Revisa que esté subida la versión nueva de '
                  + 'src/Controlador/PushControlador.php y src/Servicio/Push.php.';
      if (x.estado === 406 || x.estado === 403) {
        return 'El filtro de seguridad del hosting bloqueó la petición (HTTP ' + x.estado + ').';
      }
      return 'El servidor no contestó como se esperaba (HTTP ' + x.estado + ').';
    }, function () { return 'No hubo conexión con el servidor.'; });
  }

  function motivo(e, texto) {
    return texto + (e && e.message ? ' (' + e.message + ')' : '') + '.';
  }

  /* Lo último que falló al activar, para decirlo en la campana. */
  var falloPush = '';

  /* Activar: siempre desde un clic. Devuelve '' si quedaron activados, o
     QUÉ falló, para decirlo en vez de desmarcar la casilla en silencio. */
  function activarPush() {
    if (!window.isSecureContext) {
      return Promise.resolve('Los avisos del escritorio necesitan que LibertyFin esté en HTTPS.');
    }
    if (!PUSH) {
      return Promise.resolve('Este navegador no permite avisos con LibertyFin cerrado (en una ventana privada tampoco).');
    }
    var permiso = Notification.permission === 'granted'
      ? Promise.resolve('granted')
      : new Promise(function (listo) {
          var r = Notification.requestPermission(listo);
          if (r && r.then) r.then(listo);
        });
    return permiso.then(function (p) {
      if (p === 'denied') return 'Los bloqueaste en el navegador: permítelos desde el candado junto a la dirección y vuelve a intentar.';
      if (p !== 'granted') return 'No diste el permiso. Vuelve a intentarlo y elige "Permitir".';
      return Promise.all([
        pedir('/push/llave').then(null, function () { return null; }),
        registrarSW().then(null, function (e) { return { error: motivo(e, 'No se pudo instalar el servicio de avisos') }; })
      ]).then(function (r) {
        if (!r[0] || !r[0].ok || !r[0].llave) {
          return 'El servidor no pudo preparar los avisos. Revisa el registro de PHP ([LibertyFin] push).';
        }
        if (r[1] && r[1].error) return r[1].error;
        return suscripcion(r[1], r[0].llave).then(avisarAlServidor, function (e) {
          // Brave trae apagados de fábrica los avisos push: se dice cómo
          // encenderlos en vez de solo "no respondió".
          if (navigator.brave) {
            return 'Brave trae apagados estos avisos. Abre brave://settings/privacy, activa '
              + '"Usar los servicios de Google para la mensajería push", cierra y vuelve a abrir Brave, '
              + 'y vuelve a intentarlo.';
          }
          return motivo(e, 'El servicio de avisos del navegador no respondió');
        });
      });
    }).then(function (m) { falloPush = m || ''; pintarOfertas(); return m || ''; },
            function (e) { falloPush = motivo(e, 'No se pudieron activar'); pintarOfertas(); return falloPush; });
  }

  function desactivarPush() {
    guardar('lf_push', '');
    falloPush = '';
    if (!PUSH) return Promise.resolve('');
    return navigator.serviceWorker.getRegistration('/').then(function (reg) {
      if (!reg) return null;
      return reg.pushManager.getSubscription().then(function (s) {
        if (!s) return null;
        return fetch('/push/quitar', {
          method: 'POST', credentials: 'same-origin',
          body: formulario({ token: token(), ep: codificar(s.endpoint) }),
          headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                     'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function () { return s.unsubscribe(); }, function () { return s.unsubscribe(); });
      });
    }).then(function () { pintarOfertas(); return ''; }, function () { pintarOfertas(); return ''; });
  }

  /* Los botones "Avisarme cuando respondan": solo si se puede y todavía
     no están activados. Y "Probar aviso", solo cuando ya lo están. */
  function pintarOfertas() {
    var ocultar = !PUSH || pushActivo() || Notification.permission === 'denied';
    [].forEach.call(document.querySelectorAll('[data-lf-push]'), function (b) { b.hidden = ocultar; });
    [].forEach.call(document.querySelectorAll('[data-lf-push-probar]'), function (b) { b.hidden = !pushActivo(); });
  }

  /* "Probar aviso": el servidor manda uno de prueba a ESTE navegador
     ahora mismo y dice qué contestó el servicio de avisos. Así se sabe
     dónde se pierde: en el servidor, en el servicio, o en el sistema
     (Windows oculta las notificaciones del navegador, "No molestar"…). */
  function probarPush() {
    if (!pushActivo()) {
      return Promise.resolve(['Avisos no activados', 'Primero activa los avisos en este navegador.']);
    }
    return fetch('/push/probar', {
      method: 'POST', credentials: 'same-origin',
      body: formulario({ token: token() }),
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) {}
        return { estado: r.status, j: j };
      });
    }).then(function (x) {
      if (!x.j) return ['No se pudo probar', 'El servidor no contestó como se esperaba (HTTP ' + x.estado + ').'];
      if (x.j.sesion_cerrada) { aEntrar(); return ['Tu sesión se cerró', '']; }
      var r = (x.j.resultados || [])[0];
      if (!r) {
        guardar('lf_push', ''); pintarOfertas();
        return ['Este navegador no está suscrito', 'Vuelve a activar los avisos.'];
      }
      if (r.error) return ['No se pudo enviar el aviso', r.error];
      if (r.codigo >= 200 && r.codigo < 300) {
        return ['Aviso de prueba enviado',
                'Debe aparecer en unos segundos. Si no aparece, revisa que Windows permita las notificaciones '
                + 'de este navegador (Configuración → Sistema → Notificaciones) y que "No molestar" esté apagado.'];
      }
      if (r.codigo === 404 || r.codigo === 410) {
        guardar('lf_push', ''); pintarOfertas();
        return ['La suscripción venció', 'Vuelve a activar los avisos en este navegador.'];
      }
      return ['El servicio de avisos rechazó el envío',
              'HTTP ' + r.codigo + (r.servicio ? ' · ' + r.servicio : '') + (r.respuesta ? ' · ' + r.respuesta : '')];
    }, function () { return ['No se pudo probar', 'No hubo conexión con el servidor.']; });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lf-push-probar]');
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    cerrarCampana();
    b.disabled = true;
    probarPush().then(function (m) { b.disabled = false; avisar(m[0], m[1], null); });
  });

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lf-push]');
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    b.disabled = true;
    activarPush().then(function (m) {
      b.disabled = false;
      if (!m) avisar('Avisos activados', 'Te avisaremos cuando soporte responda, aunque cierres LibertyFin.', null);
      else avisar('No se activaron los avisos', m, null);
    });
  });

  /* Al cargar: si ESTA persona ya los tenía activados, se renueva el
     enlace con el servidor (una vez por pestaña), sin preguntar nada. Así
     vuelven solos después de "Salir" y entrar de nuevo. */
  function pushAlCargar() {
    pintarOfertas();
    document.addEventListener('lf:cargado', pintarOfertas);
    if (!pushActivo() || sesionLeer('lf_push_al_dia') === quien()) return;
    Promise.all([pedir('/push/llave'), registrarSW()]).then(function (r) {
      if (!r[0] || !r[0].ok || !r[0].llave) return false;
      return suscripcion(r[1], r[0].llave).then(avisarAlServidor);
    }).then(function (m) { if (m === '') sesionGuardar('lf_push_al_dia', quien()); }, function () {});
  }

  /* ══ 7 · Ticket (soporte): estado, prioridad, categoría y asignación
     SIN RECARGAR. Siguen siendo formularios normales (si este archivo no
     cargara, se envían como siempre); aquí se mandan por detrás y luego
     se vuelve a pedir la página para cambiar SOLO la columna de la
     derecha, con el historial y los datos al día. El chat no se toca:
     sigue en el mismo lugar, con lo que estabas escribiendo. ══ */
  function pintarGuardado(texto, clase) {
    var s = document.querySelector('[data-lf-guardado]');
    if (!s) return;
    clearTimeout(s._lfT);
    s.textContent = texto;
    s.className = 'lf-tk-guardado ' + clase;
    if (clase === 'listo') s._lfT = setTimeout(function () { s.className = 'lf-tk-guardado'; }, 2600);
  }

  function refrescarTicket() {
    return fetch(location.pathname + location.search,
                 { credentials: 'same-origin', headers: { 'X-LF-Parcial': '1' } })
      .then(function (r) {
        if (r.redirected && /\/login\/?(\?|$)/.test(String(r.url).replace(location.origin, ''))) {
          aEntrar();
          return '';
        }
        return r.ok ? r.text() : '';
      })
      .then(function (html) {
        if (!html) return false;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nueva = doc.querySelector('.lf-split-chat > div:last-child'),
            col = document.querySelector('.lf-split-chat > div:last-child');
        if (!nueva || !col) return false;
        col.innerHTML = nueva.innerHTML;
        // Cerrado: la caja de responder se esconde; al reabrirlo, vuelve.
        ['data-lf-si-abierto', 'data-lf-si-cerrado'].forEach(function (a) {
          var n = document.querySelector('.lf-chat-ventana [' + a + ']'),
              m = doc.querySelector('.lf-chat-ventana [' + a + ']');
          if (n && m) n.hidden = m.hidden;
        });
        alCambiarTamano();
        return true;
      });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.matches('form[data-lf-ticket-cambio]') || !window.fetch) return;
    ev.preventDefault();
    if (form._lfGuardando) return;
    form._lfGuardando = true;

    // Los datos se toman ANTES de deshabilitar: lo deshabilitado no viaja.
    var datos = new FormData(form), b = ev.submitter;
    if (b && b.name) datos.append(b.name, b.value);
    [].forEach.call(form.elements, function (c) { c.disabled = true; });
    pintarGuardado('Guardando…', 'guardando');

    function fin() {
      form._lfGuardando = false;
      // Si la columna no se pudo cambiar, este formulario sigue ahí.
      [].forEach.call(form.elements, function (c) { c.disabled = false; });
    }
    function fallo(texto) {
      if (saliendo) return;
      pintarGuardado('', '');
      avisar('No se guardó el cambio', texto, null);
      // Que los selectores vuelvan a decir lo que de verdad está guardado.
      return refrescarTicket().then(null, function () {});
    }

    pedir(form.action, { method: 'POST', body: datos })
      .then(function (j) {
        if (!j) return fallo('No hubo respuesta del servidor. Revisa tu conexión e inténtalo de nuevo.');
        if (!j.ok) return fallo(j.mensaje || 'Inténtalo de nuevo.');
        return refrescarTicket().then(function () {
          pintarGuardado(j.mensaje || 'Guardado.', 'listo');
        });
      })
      .then(fin, function () {
        fin();
        fallo('No hubo respuesta del servidor. Revisa tu conexión e inténtalo de nuevo.');
      });
  });

  window.LFChat = { enlazar: enlazar, abrir: abrirChat, revisar: function () { revisarCliente(); } };

  function arrancar() { enlazar(document); clienteNovedades(); soporteNovedades(); pulsoSesion(); pushAlCargar(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})();
