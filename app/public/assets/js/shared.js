//shared.js /* ═══════════════════════════════════════════
  

// ── NAVBAR HTML (RESPONSIVE CON MENÚ HAMBURGUESA) ──
const NAV_HTML = `
<nav class="lf-nav" id="lf-nav">
  <a class="nav-logo" href="/">
    <img src="/assets/img/logo-libertyfin.png" alt="Libertyfin" class="nav-logo-img">
  </a>

  <!-- Botón hamburguesa para móvil -->
  <button class="nav-hamburger" id="navHamburger" aria-label="Menú">
    <span></span>
    <span></span>
    <span></span>
  </button>

  <div class="nav-menu-wrapper" id="navMenuWrapper">
    <ul class="nav-menu">
      <!-- Funciones -->
      <li>
        <a href="#" onclick="toggleDd(event,'dd-funciones')">
          Funciones
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </a>
        <div class="nav-dropdown" id="dd-funciones">
          <div class="nav-dropdown-grid">
            <span class="nav-dd-section-title">Ventas</span>
            <a class="nav-dd-item" href="BASEPATHpages/punto-de-venta"><div class="nav-dd-icon">🛒</div><div class="nav-dd-text"><div class="nav-dd-title">Punto de Venta</div><div class="nav-dd-desc">Cobra rápido desde cualquier dispositivo</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/cotizaciones"><div class="nav-dd-icon">📋</div><div class="nav-dd-text"><div class="nav-dd-title">Cotizaciones</div><div class="nav-dd-desc">Genera y envía cotizaciones al instante</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/facturacion"><div class="nav-dd-icon">📄</div><div class="nav-dd-text"><div class="nav-dd-title">Facturación CFDI</div><div class="nav-dd-desc">Emite facturas timbradas en segundos.</div></div></a>
            <span class="nav-dd-section-title">Inventario & Clientes</span>
            <a class="nav-dd-item" href="BASEPATHpages/inventario"><div class="nav-dd-icon">📦</div><div class="nav-dd-text"><div class="nav-dd-title">Inventario</div><div class="nav-dd-desc">Stock en tiempo real, múltiples almacenes</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/clientes"><div class="nav-dd-icon">👥</div><div class="nav-dd-text"><div class="nav-dd-title">Control de clientes</div><div class="nav-dd-desc">Historial de pedidos y facturas</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/reportes"><div class="nav-dd-icon">📊</div><div class="nav-dd-text"><div class="nav-dd-title">Reportes</div><div class="nav-dd-desc">Dashboards y análisis en tiempo real</div></div></a>
          </div>
        </div>
      </li>

      <!-- Industrias -->
      <li>
        <a href="#" onclick="toggleDd(event,'dd-industrias')">
          Industrias
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </a>
        <div class="nav-dropdown" id="dd-industrias">
          <div class="nav-dropdown-grid col2">
            <a class="nav-dd-item" href="BASEPATHpages/industria-retail"><div class="nav-dd-icon">🏪</div><div class="nav-dd-text"><div class="nav-dd-title">Retail & Tiendas</div><div class="nav-dd-desc">Ropa, abarrotes, ferretería y más</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/industria-restaurantes"><div class="nav-dd-icon">🍽️</div><div class="nav-dd-text"><div class="nav-dd-title">Restaurantes</div><div class="nav-dd-desc">Menú, mesas, cocina y delivery</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/industria-farmacias"><div class="nav-dd-icon">💊</div><div class="nav-dd-text"><div class="nav-dd-title">Farmacias</div><div class="nav-dd-desc">Lotes, caducidades y recetas</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/industria-servicios"><div class="nav-dd-icon">💈</div><div class="nav-dd-text"><div class="nav-dd-title">Servicios</div><div class="nav-dd-desc">Salones, talleres, clínicas y más</div></div></a>
            <a class="nav-dd-item" href="BASEPATHpages/industria-ecommerce"><div class="nav-dd-icon">🛍️</div><div class="nav-dd-text"><div class="nav-dd-title">E-commerce</div><div class="nav-dd-desc">Tienda en línea integrada</div></div></a>
          </div>
        </div>
      </li>

      <!-- Precios -->
      <li><a href="BASEPATHpages/precios">Precios</a></li>

      <!-- Clientes -->
      <li><a href="BASEPATHpages/clientes-casos">Clientes</a></li>

      <!-- Distribuidores -->
      <li><a href="BASEPATHpages/distribuidores">Distribuidores</a></li>
    </ul>

    <div class="nav-ctas">
      <a class="btn-nav-login" href="/login">Iniciar sesión</a>
      <a class="btn-nav-register" href="BASEPATHpages/registro">
        Registra tu empresa
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
</nav>`;

// ── FOOTER HTML ─────────────────────────────
const FOOTER_HTML = `
<footer class="lf-footer">
  <div class="lf-footer-inner">
    <div class="lf-footer-top">
      <div>
        <div class="footer-logo">
          <img src="/assets/img/logo-libertyfin-white.png" alt="Libertyfin" class="footer-logo-img">
        </div>
        <p class="footer-desc">Sistema de ventas en la nube para negocios retail. Sencillo, poderoso y 100% en español.</p>
        <div class="social-links">
          <a class="social-btn" href="#" title="Facebook" target="_blank"><svg viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
          <a class="social-btn" href="#" title="Instagram" target="_blank"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1" fill="rgba(255,255,255,0.5)"/></svg></a>
          <a class="social-btn" href="#" title="TikTok" target="_blank"><svg viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.34 6.34 0 0 0-6.13 6.33 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z"/></svg></a>
          <a class="social-btn" href="#" title="YouTube" target="_blank"><svg viewBox="0 0 24 24"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 0 0 1.46 6.42 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.96-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#111827" stroke="none"/></svg></a>
          <a class="social-btn" href="#" title="LinkedIn" target="_blank"><svg viewBox="0 0 24 24"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg></a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Producto</div>
        <ul class="footer-links">
          <li><a href="BASEPATHpages/punto-de-venta">Punto de venta</a></li>
          <li><a href="BASEPATHpages/facturacion">Facturación CFDI</a></li>
          <li><a href="BASEPATHpages/inventario">Inventario</a></li>
          <li><a href="BASEPATHpages/reportes">Reportes</a></li>
        </ul>
      </div>
      <div>
        <div class="footer-col-title">Empresa</div>
        <ul class="footer-links">
          <li><a href="#">Acerca de</a></li>
          <li><a href="#">Blog</a></li>
          <li><a href="BASEPATHpages/distribuidores">Distribuidores</a></li>
        </ul>
      </div>
      <div>
        <div class="footer-col-title">Industrias</div>
        <ul class="footer-links">
          <li><a href="#">Retail & Tiendas</a></li>
          <li><a href="#">Restaurantes</a></li>
          <li><a href="#">Farmacias</a></li>
          <li><a href="#">Servicios</a></li>
        </ul>
      </div>
      <div>
        <div class="footer-col-title">Soporte</div>
        <ul class="footer-links">
          <li><a href="#">Centro de ayuda</a></li>
          <li><a href="#">Documentación</a></li>
          <li><a href="#">Estado del sistema</a></li>
          <li><a href="#">Contacto</a></li>
          <li><a href="#">WhatsApp soporte</a></li>
        </ul>
      </div>
    </div>
    <div class="lf-footer-bottom">
      <span class="footer-copy">© 2026 Libertyfin. Todos los derechos reservados.</span>
      <div class="footer-legal">
        <a href="#">Privacidad</a>
        <a href="BASEPATHpages/terminos-y-condiciones">Términos</a>
        <a href="BASEPATHpages/aviso-privacidad">Aviso legal</a>
        <a href="#">Cookies</a>
      </div>
    </div>
  </div>
</footer>

<!-- WhatsApp -->
<div class="wa-float" id="waFloat">
  <div class="wa-bubble" id="waBubble">
    <strong>¡Hola! 👋 ¿En qué te ayudamos?</strong>
    Chatea con nosotros en WhatsApp al instante.
  </div>
  <div class="wa-circle" onclick="openWhatsApp()" title="WhatsApp">
    <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
    <div class="wa-ping"></div>
  </div>
</div>`;

// ── SHARED JS ───────────────────────────────
function initShared() {
  // Detect if running from /pages/ subfolder and fix all internal links
  const inSubfolder = window.location.pathname.includes('/pages/');
  const base = inSubfolder ? '../' : '';
  
  // Inject nav
  const fixedNav = NAV_HTML.replaceAll('BASEPATH', base);
  const navEl = document.getElementById('lf-nav-placeholder');
  if (navEl) navEl.outerHTML = fixedNav;

  // Inject footer
  const fixedFooter = FOOTER_HTML.replaceAll('BASEPATH', base);
  const footerEl = document.getElementById('lf-footer-placeholder');
  if (footerEl) footerEl.outerHTML = fixedFooter;

  // Navbar scroll effect
  window.addEventListener('scroll', () => {
    document.getElementById('lf-nav')?.classList.toggle('scrolled', window.scrollY > 20);
  });

  // --- MENÚ HAMBURGUESA ---
  const hamburger = document.getElementById('navHamburger');
  const menuWrapper = document.getElementById('navMenuWrapper');
  
  if (hamburger && menuWrapper) {
    hamburger.addEventListener('click', (e) => {
      e.stopPropagation();
      hamburger.classList.toggle('active');
      menuWrapper.classList.toggle('active');
      document.body.classList.toggle('menu-open');
    });

    // CERRAR MENÚ SOLO PARA ENLACES QUE NO SON DROPDOWNS
    // Selecciona solo los enlaces que NO tienen dropdown (precios, clientes, distribuidores)
    const regularLinks = menuWrapper.querySelectorAll('.nav-menu > li:not(:has(.nav-dropdown)) > a');
    regularLinks.forEach(link => {
      link.addEventListener('click', () => {
        hamburger.classList.remove('active');
        menuWrapper.classList.remove('active');
        document.body.classList.remove('menu-open');
      });
    });
  }

  // Close dropdowns on outside click
  document.addEventListener('click', e => {
    // Cerrar menú hamburguesa si está abierto y se clickea fuera
    if (menuWrapper?.classList.contains('active') && !menuWrapper.contains(e.target) && !hamburger?.contains(e.target)) {
      hamburger?.classList.remove('active');
      menuWrapper?.classList.remove('active');
      document.body.classList.remove('menu-open');
    }
    
    // Cerrar dropdowns si se clickea fuera
    if (!e.target.closest('.nav-menu')) {
      document.querySelectorAll('.nav-menu > li').forEach(li => li.classList.remove('open'));
    }
  });

  // Scroll reveal
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.1 });
  document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

  // WhatsApp bubble
  setTimeout(() => document.getElementById('waBubble')?.classList.add('show'), 3000);
  document.addEventListener('click', e => {
    if (!document.getElementById('waFloat')?.contains(e.target))
      document.getElementById('waBubble')?.classList.remove('show');
  });
}

function toggleDd(e, id) {
  e.preventDefault();
  e.stopPropagation();
  const li = e.currentTarget.closest('li');
  const isOpen = li.classList.contains('open');
  
  // Cerrar todos los demás dropdowns
  document.querySelectorAll('.nav-menu > li').forEach(l => {
    if (l !== li) {
      l.classList.remove('open');
    }
  });
  
  // Toggle el dropdown actual
  if (!isOpen) {
    li.classList.add('open');
  } else {
    li.classList.remove('open');
  }
}

function openWhatsApp() {
  const msg = encodeURIComponent('¡Hola! Me interesa conocer más sobre Libertyfin 😊');
  window.open('https://wa.me/5215611062957?text=' + msg, '_blank');
}

document.addEventListener('DOMContentLoaded', initShared);