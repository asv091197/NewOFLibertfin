/* TABLA SI CABE, TARJETAS SI NO.
   Cada tabla con modo tarjeta (`.table-responsive.lf-cards`) se mide a su
   ancho natural —sin partir ningún renglón— y se compara con el espacio
   que tiene. Si cabe, se queda como tabla; si no, se le pone `lf-apilada`
   y cada renglón se dibuja como una tarjeta (ver libertyfin.css).

   Se mide la caja de la tabla, no la pantalla: la misma tabla cabe a
   página completa y no en la columna angosta del detalle de una venta.
   Un ResizeObserver vuelve a decidir cuando cambia el ancho (girar el
   teléfono, abrir el menú, una pestaña que estaba oculta), y un
   MutationObserver alcanza las tablas que llegan después: la navegación
   sin recarga y el panel que se abre encima. */
(function () {
  if (window.lfTablasListo) return;
  window.lfTablasListo = true;

  var SEL = '.table-responsive.lf-cards';
  // En el teléfono ya hay tarjetas de siempre (libertyfin.css, 576 px).
  var telefono = window.matchMedia ? window.matchMedia('(max-width: 576px)') : null;

  function decidir(caja) {
    var tabla = caja.querySelector('table');
    if (!tabla || !caja.clientWidth) return;          // oculta: se decide al verse
    /* En el teléfono, las de siempre usan sus tarjetas de rótulo a la
       izquierda; las marcadas con `lf-rejilla` (el detalle de una venta)
       van en tarjetas de columnas, que acomodan mejor las celdas de dos
       datos (folio y fecha, método y banco). */
    if (telefono && telefono.matches) {
      caja.classList.toggle('lf-apilada', caja.classList.contains('lf-rejilla'));
      return;
    }

    /* Si cambiara de opinión varias veces seguidas, es que su ancho depende
       de cómo se dibuja: se queda como está en vez de parpadear. */
    var ahora = Date.now();
    if (ahora - (caja._lfDesde || 0) > 1000) { caja._lfDesde = ahora; caja._lfVeces = 0; }
    if (++caja._lfVeces > 4) return;

    var estilo = getComputedStyle(caja);
    var hueco = caja.clientWidth - parseFloat(estilo.paddingLeft) - parseFloat(estilo.paddingRight);
    caja.classList.remove('lf-apilada');
    caja.classList.add('lf-midiendo');
    var natural = tabla.offsetWidth;
    caja.classList.remove('lf-midiendo');
    caja.classList.toggle('lf-apilada', natural > hueco + 1);
  }

  var vigia = window.ResizeObserver ? new ResizeObserver(function (cambios) {
    cambios.forEach(function (c) {
      var ancho = Math.round(c.contentRect.width);
      if (ancho === c.target._lfAncho) return;        // solo cambió la altura
      c.target._lfAncho = ancho;
      decidir(c.target);
    });
  }) : null;

  function vigilar(caja) {
    if (caja._lfVigilada) return;
    caja._lfVigilada = true;
    if (vigia) vigia.observe(caja); else decidir(caja);
  }
  function buscar(raiz) {
    if (!raiz || raiz.nodeType !== 1) return;
    if (raiz.matches(SEL)) vigilar(raiz);
    [].forEach.call(raiz.querySelectorAll(SEL), vigilar);
  }

  buscar(document.body);
  if (window.MutationObserver) {
    new MutationObserver(function (lista) {
      lista.forEach(function (m) { [].forEach.call(m.addedNodes, buscar); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (telefono && telefono.addEventListener) {
    telefono.addEventListener('change', function () {
      [].forEach.call(document.querySelectorAll(SEL), decidir);
    });
  }
  if (!vigia) window.addEventListener('resize', function () {
    [].forEach.call(document.querySelectorAll(SEL), decidir);
  });
})();
