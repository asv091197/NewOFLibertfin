/* ═══════════════════════════════════════════════════
   LIBERTYFIN — JS EXCLUSIVO DE index.html
   ═══════════════════════════════════════════════════ */

// ── GRÁFICA HERO ────────────────────────────────────
(function renderHeroChart() {
  const svg = document.getElementById('heroChart');
  if (!svg) return;
  const data = [42, 38, 56, 49, 72, 63, 81];
  const W = 300, H = 75, p = { t: 6, r: 6, b: 14, l: 6 };
  const iW = W - p.l - p.r, iH = H - p.t - p.b;
  const max = Math.max(...data) * 1.1;
  const pts = data.map((v, i) => ({
    x: p.l + (i / (data.length - 1)) * iW,
    y: p.t + iH * (1 - v / max)
  }));
  const pathD = pts.map((pt, i) =>
    i === 0
      ? `M${pt.x},${pt.y}`
      : `C${(pts[i-1].x+pt.x)/2},${pts[i-1].y} ${(pts[i-1].x+pt.x)/2},${pt.y} ${pt.x},${pt.y}`
  ).join(' ');
  const areaD = pathD + ` L${pts[pts.length-1].x},${p.t+iH} L${pts[0].x},${p.t+iH} Z`;
  const labels = ['L','M','M','J','V','S','D'].map((l, i) =>
    `<text x="${p.l+(i/(data.length-1))*iW}" y="${H}" text-anchor="middle" font-size="8" fill="#9ca3af" font-family="Poppins,sans-serif">${l}</text>`
  ).join('');
  svg.innerHTML = `
    <defs>
      <linearGradient id="hg" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#22c55e" stop-opacity="0.2"/>
        <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
      </linearGradient>
    </defs>
    <path d="${areaD}" fill="url(#hg)"/>
    <path d="${pathD}" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round"/>
    ${pts.map(pt => `<circle cx="${pt.x}" cy="${pt.y}" r="2.5" fill="#22c55e"/>`).join('')}
    ${labels}
  `;
})();

// ── FEATURE TABS ────────────────────────────────────
const feats = [
  {
    title: 'Punto de Venta',
    img: '/assets/img/feat-punto-de-venta.jpg',
    desc: 'Cobra en segundos desde cualquier dispositivo — tablet, celular o PC. Sin instalaciones, sin hardware especial requerido.',
    bullets: [
      'Acepta efectivo, tarjeta y transferencias',
      'Imprime o envía tickets por correo y WhatsApp',
      'Descuentos, devoluciones y múltiples cajas'
    ],
    link: 'pages/punto-de-venta'
  },
  {
    title: 'Facturación CFDI 4.0',
    img: '/assets/img/feat-facturacion.jpg',
    desc: 'Emite facturas timbradas en segundos. Válido para personas físicas y morales en todo México.',
    bullets: [
      'CFDI 4.0 con todos los complementos requeridos',
      'Envío automático por correo al cliente',
      'Complemento de pago incluido sin costo extra'
    ],
    link: 'pages/facturacion'
  },
  {
    title: 'Control de Inventario',
    img: '/assets/img/feat-inventario.jpg',
    desc: 'Stock en tiempo real, múltiples almacenes y alertas automáticas de inventario bajo desde cualquier dispositivo, sin instalaciones.',
    bullets: [
      'Stock en múltiples almacenes simultáneos',
      'Alertas automáticas de inventario mínimo',
      'Entradas, salidas y traspasos entre almacenes'
    ],
    link: 'pages/inventario'
  },
  {
    title: 'Reportes en Tiempo Real',
    img: '/assets/img/feat-reportes.jpg',
    desc: 'Panel de control con ventas por día, producto y canal. Exporta a Excel o PDF con un clic.',
    bullets: [
      'Dashboard actualizado al instante',
      'Exporta a Excel o PDF con un clic',
      'Ventas por vendedor, sucursal y canal'
    ],
    link: 'pages/reportes'
  }
];

function setFeat(i, btn) {
  document.querySelectorAll('.feat-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  const f = feats[i];
  document.getElementById('featTitle').textContent = f.title;
  document.getElementById('featDesc').textContent = f.desc;

  // Actualiza la imagen con fade
  const imgEl = document.getElementById('featImg');
  imgEl.style.opacity = '0';
  setTimeout(() => {
    imgEl.src = f.img;
    imgEl.alt = f.title;
    imgEl.style.opacity = '1';
  }, 150);

  const bl = document.getElementById('featBullets');
  bl.innerHTML = f.bullets.map(b => `<div class="feat-bullet">${b}</div>`).join('');
  const link = document.querySelector('#featShowcase .btn');
  if (link) link.href = f.link;
}
