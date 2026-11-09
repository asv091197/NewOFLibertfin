/* ══════════════════════════════════════════════════════
   LibertyFin · avisos aunque la plataforma esté cerrada

   Este archivo lo instala el navegador cuando alguien activa los avisos
   de escritorio (soporte desde la campana; el cliente desde su reporte).
   Vive aparte de las páginas: el navegador lo despierta cuando el
   servidor "toca" (ver src/Servicio/Push.php), aunque no haya ninguna
   pestaña de LibertyFin abierta.

   El toque llega vacío. Aquí se pregunta qué mostrar (/push/pendiente,
   identificándose con la propia suscripción) y se muestra: quién y de
   qué ticket, nunca el texto del mensaje.

   Si LibertyFin está a la vista, no se muestra nada: la página ya avisa
   por dentro, y dos avisos del mismo mensaje sobran.

   No intercepta ninguna petición de la página (no hay `fetch`): solo
   avisos. Va en la raíz para poder abrir cualquier sección al tocarlo.
   ══════════════════════════════════════════════════════ */

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

/* En hexadecimal, lo mismo que hace lf-chat.js al suscribirse: el filtro
   de seguridad del hosting rechaza (406) "https://..." tal cual y también
   los guiones del base64. */
function codificar(u) {
  var h = '';
  for (var i = 0; i < u.length; i++) {
    var c = u.charCodeAt(i).toString(16);
    h += (c.length < 2 ? '0' : '') + c;
  }
  return h;
}

function ventanas() {
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
}

var GENERICO = { titulo: 'LibertyFin', cuerpo: 'Tienes un aviso nuevo.', url: '/panel', tag: 'lf-aviso' };

self.addEventListener('push', function (e) {
  e.waitUntil(self.registration.pushManager.getSubscription().then(function (s) {
    if (!s) return null;
    // La dirección va codificada: el filtro de seguridad del hosting
    // rechaza (406) cualquier campo que traiga "https://..." tal cual.
    return fetch('/push/pendiente', {
      method: 'POST',
      credentials: 'omit',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ep=' + encodeURIComponent(codificar(s.endpoint))
    }).then(function (r) { return r.json(); }).then(null, function () { return null; });
  }).then(function (j) {
    var a = (j && j.ok && j.aviso) || GENERICO;
    return ventanas().then(function (vs) {
      // Con LibertyFin a la vista no se muestra (la página ya avisa por
      // dentro), salvo el aviso de prueba, que se pidió mirando.
      var mirando = vs.some(function (v) { return v.visibilityState === 'visible' && v.focused; });
      if (mirando && !a.forzar) return null;
      return self.registration.showNotification(a.titulo || GENERICO.titulo, {
        body: a.cuerpo || '',
        tag: a.tag || GENERICO.tag,
        renotify: true,
        data: { url: a.url || '/panel' }
      });
    });
  }));
});

/* Al tocarlo: a una pestaña de LibertyFin que ya esté abierta, o una
   nueva. Si no hay sesión, la página pide entrar. */
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/panel';
  e.waitUntil(ventanas().then(function (vs) {
    for (var i = 0; i < vs.length; i++) {
      var v = vs[i];
      if (v.url.indexOf(self.location.origin) === 0 && 'focus' in v) {
        return v.focus().then(function (w) {
          if (w && w.navigate) return w.navigate(url).then(null, function () { return self.clients.openWindow(url); });
          return self.clients.openWindow(url);
        });
      }
    }
    return self.clients.openWindow(url);
  }));
});
