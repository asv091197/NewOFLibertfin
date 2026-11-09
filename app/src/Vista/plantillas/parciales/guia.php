<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Permisos;

/**
 * Guía de primer uso.
 *
 * Señala la sección REAL del menú e ilustra al lado cómo se ve esa
 * pantalla cuando ya tiene datos.
 *
 * POR QUÉ NO LLEVA AL USUARIO A CADA SECCIÓN DE VERDAD:
 * esto corre en el primer ingreso, cuando la empresa no tiene una sola
 * venta, ni un cliente, ni un servicio. Ventas sale vacía, Reportes en
 * ceros y Caja sin catálogo. Enseñar pantallas vacías no enseña el
 * sistema: enseña que el sistema está vacío.
 *
 * Los pasos se FILTRAN contra los permisos del rol: a un cajero no se le
 * explica Comisiones, porque no la tiene.
 *
 * El velo es claro a propósito. Con uno oscuro el menú de atrás deja de
 * leerse y la guía pierde la mitad de su sentido: se trata de ver el
 * menú real iluminado, no una caja de texto flotando en negro.
 */

$pasos = [
  ['id' => null, 'ruta' => null, 'icono' => 'bienvenida',
   'titulo' => 'Bienvenido a LibertyFin',
   'texto'  => 'En menos de un minuto te mostramos dónde está cada cosa. '
             . 'Puedes salirte cuando quieras y volver a verla desde Mi cuenta.'],

  ['ruta' => '/panel', 'p' => 'ver.panel', 'icono' => 'panel',
   'titulo' => 'Tu panorama',
   'texto'  => 'De un vistazo: cuánto entró hoy, qué falta por cobrar y cómo va el mes.'],

  ['ruta' => '/servicios', 'p' => 'ver.servicios', 'icono' => 'servicios',
   'titulo' => 'Qué vas a cobrar',
   'texto'  => 'Da de alta tus productos con su precio. Los que se cotizan por caso '
             . 'déjalos en cero: el precio se pone al vender.'],

  ['ruta' => '/inventario', 'p' => 'ver.inventario', 'mod' => 'inventario', 'icono' => 'servicios',
   'titulo' => 'Tus existencias',
   'texto'  => 'Cuánto queda de cada producto en cada sucursal. Caja lo descuenta al cobrar '
             . 'y el Panel te avisa cuando algo se está acabando.'],

  ['ruta' => '/compras', 'p' => 'ver.compras', 'mod' => 'compras', 'icono' => 'servicios',
   'titulo' => 'Compras a proveedor',
   'texto'  => 'Haz el pedido y, cuando llegue, dale «Recibir»: la mercancía entra sola al '
             . 'inventario con su costo, y si quieres, queda el gasto registrado.'],

  ['ruta' => '/clientes', 'p' => 'ver.clientes', 'icono' => 'clientes',
   'titulo' => 'A quién le cobras',
   'texto'  => 'Tus clientes, con su área y su saldo. Toca un nombre para abrir su ficha: '
             . 'sus compras, lo que debe y todo lo que sabes de él.'],

  ['ruta' => '/cotizaciones', 'p' => 'ver.cotizaciones', 'mod' => 'cotizaciones', 'icono' => 'ventas',
   'titulo' => 'Cotizaciones',
   'texto'  => 'Arma el presupuesto, imprímelo o guárdalo en PDF, y cuando el cliente acepte, '
             . '«Cobrar en Caja» lo vuelve venta sin capturarlo otra vez.'],

  ['ruta' => '/seguimiento', 'p' => 'ver.seguimiento', 'mod' => 'seguimiento', 'icono' => 'reportes',
   'titulo' => 'En qué va cada trabajo',
   'texto'  => 'Cada venta aparece como un trabajo pendiente. Muévelo a «En proceso», '
             . '«Listo» y «Entregado», con responsable y fecha de entrega.'],

  ['ruta' => '/caja', 'p' => 'cobrar', 'icono' => 'caja',
   'titulo' => 'Cobrar',
   'texto'  => 'Tu pantalla del día a día. Puedes cobrar completo o dejar un anticipo: '
             . 'el saldo queda persiguiéndose solo. En Ajustes → Caja eliges con qué '
             . 'empieza cada venta (cobro completo o vacío, con IVA o sin IVA).'],

  ['ruta' => '/agenda', 'p' => 'ver.agenda', 'mod' => 'agenda', 'icono' => 'clientes',
   'titulo' => 'Tu agenda',
   'texto'  => 'Las citas del día por especialista. Toca un espacio libre para agendar; '
             . 'manda el recordatorio por WhatsApp y cobra la cita en Caja con un clic.'],

  ['ruta' => '/ventas', 'p' => 'ver.ventas', 'icono' => 'ventas',
   'titulo' => 'Cobrado, no vendido',
   'texto'  => 'La cifra grande es lo que de verdad entró, no lo facturado. '
             . 'Son cosas distintas y aquí no se confunden.'],

  ['ruta' => '/cobranza', 'p' => 'ver.cobranza', 'sec' => 'cobranza', 'icono' => 'cobranza',
   'titulo' => 'A quién le hablas hoy',
   'texto'  => 'Ordenado por antigüedad del último abono, no por monto. '
             . 'Lo viejo pesa más que lo grande.'],

  ['ruta' => '/igualas', 'p' => 'ver.igualas', 'mod' => 'igualas', 'icono' => 'cobranza',
   'titulo' => 'Igualas',
   'texto'  => 'Lo que un cliente te paga cada mes. El día de cobro se genera sola la venta '
             . 'y aparece en Cobranza: nadie tiene que acordarse.'],

  ['ruta' => '/corte', 'p' => 'ver.corte', 'sec' => 'corte', 'icono' => 'corte',
   'titulo' => 'Cerrar el turno',
   'texto'  => 'Cuadra el efectivo del cajón. Transferencias y tarjeta se muestran '
             . 'aparte: no pasan por ahí.'],

  ['ruta' => '/comisiones', 'p' => 'ver.comisiones', 'sec' => 'comisiones', 'icono' => 'comisiones',
   'titulo' => 'Comisiones',
   'texto'  => 'Se liberan conforme el cliente paga. Si nunca liquida, esa parte '
             . 'no se devenga.'],

  ['ruta' => '/gastos', 'p' => 'ver.gastos', 'sec' => 'gastos', 'icono' => 'gastos',
   'titulo' => 'Gastos',
   'texto'  => 'Los generales no tocan comisiones. Los de operación sí: cuelgan de '
             . 'una venta y bajan su base.'],

  ['ruta' => '/reportes', 'p' => 'ver.reportes', 'sec' => 'reportes', 'icono' => 'reportes',
   'titulo' => 'Cuánto queda',
   'texto'  => 'Lo cobrado menos gastos y comisiones. La cifra que nadie tenía.'],

  ['ruta' => '/ajustes', 'p' => 'ver.ajustes', 'icono' => 'ajustes',
   'titulo' => 'Tu equipo y tus catálogos',
   'texto'  => 'Sucursales, áreas, colaboradores, usuarios y el color de tu marca.'],

  ['id' => 'tema', 'ruta' => null, 'icono' => 'tema', 'siempre' => true,
   'titulo' => 'Claro u oscuro',
   'texto'  => 'Se queda guardado para la próxima vez que entres.'],

  ['ruta' => '/cuenta', 'icono' => 'cuenta', 'siempre' => true,
   'ir' => '/cuenta?t=fiscales', 'boton' => 'Ir a configurar',
   'titulo' => 'Ahora sí: configura tu cuenta',
   'texto'  => 'Ya viste lo que puedes hacer. Falta un paso para cobrar de verdad: '
             . 'tus datos fiscales y tus documentos.'],
];

/*
 * SOPORTE (cuentas de plataforma). No tienen empresa: nada de Caja, Ventas
 * ni datos fiscales. Su menú es otro, y su guía también. Igual que la de
 * empresa, cada paso se filtra contra los permisos del rol: un validador no
 * ve Informes ni Mantenimiento, y Cuentas es solo del superadministrador.
 */
if (!empty($_SESSION['plataforma'])) $pasos = [
  ['id' => null, 'ruta' => null, 'icono' => 'bienvenida',
   'titulo' => 'Bienvenido al equipo de soporte',
   'texto'  => 'En menos de un minuto te mostramos dónde está cada cosa. '
             . 'Puedes salirte cuando quieras y volver a verla desde Mi cuenta.'],

  ['ruta' => '/panel', 'p' => 'ver.soporte', 'icono' => 'cola',
   'titulo' => 'Lo que está esperando',
   'texto'  => 'Tu panel junta lo pendiente: tickets sin dueño, documentos por revisar '
             . 'y empresas por dar de alta. Es lo que hay que vaciar antes de irte.'],

  ['ruta' => '/tickets', 'p' => 'ver.tickets', 'icono' => 'tickets',
   'titulo' => 'Tickets',
   'texto'  => 'Lo urgente sale primero; toca la fila para abrirlo y asígnatelo para que '
             . 'se sepa quién lo lleva. El chat se actualiza solo, puedes pegar capturas '
             . 'con Ctrl+V y una nota interna no la ve el cliente.'],

  ['id' => 'campana', 'ruta' => null, 'p' => 'ver.tickets', 'icono' => 'campana',
   'titulo' => 'La campana',
   'texto'  => 'Te avisa cuando un cliente escribe, estés en la sección que estés. '
             . 'Ábrela y marca «Escritorio» para enterarte aunque cierres LibertyFin.'],

  ['ruta' => '/soporte', 'p' => 'ver.empresas', 'icono' => 'empresas',
   'titulo' => 'Empresas',
   'texto'  => 'La ficha de cada cliente: su plan, lo que tiene encendido, sus usuarios '
             . 'y qué puede estar causando su problema.'],

  ['ruta' => '/conocimiento', 'p' => 'ver.conocimiento', 'icono' => 'conocimiento',
   'titulo' => 'Conocimiento',
   'texto'  => 'Artículos, errores conocidos y plantillas de respuesta. Las plantillas '
             . 'salen al contestar un ticket, y los errores de su categoría, al lado.'],

  ['ruta' => '/informes', 'p' => 'ver.informes', 'icono' => 'informes',
   'titulo' => 'Informes',
   'texto'  => 'De qué se queja la gente y cómo va el equipo. Se cuenta lo resuelto, '
             . 'no lo asignado.'],

  ['ruta' => '/mantenimiento', 'p' => 'ver.mantenimiento', 'icono' => 'mantenimiento',
   'titulo' => 'Mantenimiento',
   'texto'  => 'Altas de empresas, pagos de plan y documentos por revisar, y la salud '
             . 'del sistema: bases, correo e integraciones.'],

  ['ruta' => '/plataforma', 'p' => 'usuarios.plataforma', 'icono' => 'cuentas',
   'titulo' => 'Cuentas del equipo',
   'texto'  => 'Quién entra a soporte y con qué rol. Solo el superadministrador las da de alta.'],

  ['id' => 'tema', 'ruta' => null, 'icono' => 'tema', 'siempre' => true,
   'titulo' => 'Claro u oscuro',
   'texto'  => 'Se guarda en tu cuenta: lo verás igual en cualquier equipo donde entres.'],

  ['ruta' => '/cuenta', 'icono' => 'perfil', 'siempre' => true,
   'ir' => '/cuenta', 'boton' => 'Ir a mi perfil',
   'titulo' => 'Tu perfil',
   'texto'  => 'Tu foto (y si el cliente la ve en los tickets), tu contraseña y la '
             . 'apariencia: tema y color.'],
];

// Además del permiso, lo que la empresa tiene encendido: si Comisiones
// quedó apagada en la configuración inicial, no se explica; Inventario,
// solo si está encendido. Sin empresa (soporte) no aplica.
$cfgGuia = null;
try {
    if (!empty($_SESSION['empresa_db'])) {
        $cfgGuia = new \LibertyFin\Datos\ConfigRepo(\LibertyFin\Datos\Conexion::de($_SESSION['empresa_db']));
    }
} catch (\Throwable $e) { $cfgGuia = null; }

// Los módulos (Ajustes → Módulos): solo se explica lo que está encendido.
$modsGuia = [];
try { if ($cfgGuia) $modsGuia = (new \LibertyFin\Servicio\Modulos($cfgGuia))->activos(); } catch (\Throwable $e) { $modsGuia = []; }

$mios = [];
foreach ($pasos as $p) {
    if (empty($p['siempre']) && isset($p['p']) && !Permisos::puede($p['p'])) continue;
    if ($cfgGuia && isset($p['sec']) && !$cfgGuia->seccionActiva($p['sec'])) continue;
    if (isset($p['mod']) && empty($modsGuia[$p['mod']])) continue;
    $mios[] = $p;
}
?>
<div class="lf-guia" id="lfGuia" hidden>
  <div class="lf-guia-velo" id="lfGuiaVelo"></div>
  <div class="lf-guia-foco" id="lfGuiaFoco" hidden></div>
  <div class="lf-guia-card" id="lfGuiaCard">
    <div class="ilu" id="lfGuiaIlu"></div>
    <div class="txt">
      <div class="num" id="lfGuiaNum"></div>
      <h3 id="lfGuiaTit"></h3>
      <p id="lfGuiaTxt"></p>
      <div class="acc">
        <button type="button" class="btn btn-secondary btn-sm" id="lfGuiaSaltar">Saltar</button>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-secondary btn-sm" id="lfGuiaAtras" hidden>Atrás</button>
        <button type="button" class="btn btn-primary btn-sm" id="lfGuiaSig">Siguiente</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var PASOS = <?= json_encode(array_map(function ($p) {
      return ['ruta' => $p['ruta'] ?? null, 'id' => $p['id'] ?? null,
              'icono' => $p['icono'], 'titulo' => $p['titulo'],
              'texto' => $p['texto'],
              // El último paso puede llevar a algún lado al terminar.
              'ir' => $p['ir'] ?? null, 'boton' => $p['boton'] ?? null];
  }, $mios), JSON_UNESCAPED_UNICODE) ?>;

  // Bocetos de cada sección. Son SVG con currentColor y var(--lf-brand),
  // así que siguen el tema y el color de la marca sin tocar nada.
  var V = 'var(--lf-brand)';
  function svg(c){ return '<svg viewBox="0 0 280 104" style="width:100%;height:auto;display:block;'
    + 'color:var(--lf-tinta)">' + c + '</svg>'; }
  function r(x,y,w,h,o,f,rx){ return '<rect x="'+x+'" y="'+y+'" width="'+w+'" height="'+h
    + '" rx="'+(rx===undefined?3:rx)+'" fill="'+(f||'currentColor')+'" opacity="'+(o===undefined?.18:o)+'"/>'; }
  function c(cx,cy,rr,o,f){ return '<circle cx="'+cx+'" cy="'+cy+'" r="'+rr+'" fill="'
    + (f||'currentColor')+'" opacity="'+(o===undefined?.18:o)+'"/>'; }
  function fila(y,w,on){ return c(24,y+9,7,on?.9:.16,on?V:null) + r(38,y+3,w,5,on?.5:.16) + r(38,y+12,w*.6,4,.1); }

  var ILUS = {
    bienvenida: function(){ return svg(
      '<polyline points="96,46 140,22 184,46" fill="none" stroke="'+V+'" stroke-width="3" '
      + 'stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>'
      + r(104,46,72,40,.10) + r(116,58,16,14,.26) + r(148,58,16,14,.26) + r(132,74,16,12,.5,V)
      + c(70,78,5,.18) + c(210,78,5,.18) + r(52,94,176,4,.7,V,2)); },
    panel: function(){ return svg(
      r(12,10,78,40,.07) + r(101,10,78,40,.07) + r(190,10,78,40,.07)
      + r(20,18,30,4,.14) + r(20,28,46,9,.75,V) + r(109,18,30,4,.14) + r(109,28,40,9,.28)
      + r(198,18,30,4,.14) + r(198,28,44,9,.28)
      + '<polyline points="16,90 58,76 100,82 142,62 184,68 226,48 264,54" fill="none" stroke="'+V
      + '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity=".85"/>'); },
    servicios: function(){ return svg(
      r(12,10,256,24,.06) + r(22,18,92,7,.4) + r(210,18,46,7,.75,V)
      + r(12,40,256,24,.06) + r(22,48,74,7,.22) + r(210,48,46,7,.22)
      + r(12,70,256,24,.06) + r(22,78,84,7,.22) + r(210,78,46,7,.22)); },
    clientes: function(){ return svg(r(12,8,256,88,.05) + fila(16,120,true) + fila(44,100) + fila(72,132)); },
    caja: function(){ return svg(
      r(12,8,158,88,.05) + r(22,18,84,6,.2) + r(126,18,34,6,.16)
      + r(22,34,68,6,.2) + r(126,34,34,6,.16) + r(22,50,76,6,.2) + r(126,50,34,6,.16)
      + '<line x1="22" y1="66" x2="160" y2="66" stroke="currentColor" stroke-width="1" opacity=".16"/>'
      + r(22,74,44,8,.45) + r(112,72,48,11,.85,V)
      + r(180,8,88,26,.85,V,6) + r(180,40,88,26,.07) + r(180,70,88,26,.07)); },
    ventas: function(){ return svg(
      r(12,8,256,16,.09)
      + r(12,28,256,20,.05) + r(22,35,78,6,.22) + r(212,33,46,10,.85,V,5)
      + r(12,52,256,20,.05) + r(22,59,66,6,.22) + r(212,57,46,10,.4,V,5)
      + r(12,76,256,20,.05) + r(22,83,84,6,.22) + r(212,81,46,10,.85,V,5)); },
    cobranza: function(){ return svg(
      r(12,8,256,26,.05) + r(24,16,84,7,.22) + r(214,15,44,9,.85,'#cf4436')
      + r(12,39,256,26,.05) + r(24,47,106,7,.22) + r(214,46,44,9,.6,'#c58a14')
      + r(12,70,256,26,.05) + r(24,78,68,7,.22) + r(214,77,44,9,.28)); },
    corte: function(){ return svg(
      r(12,10,122,84,.06) + r(146,10,122,84,.06)
      + r(24,22,52,5,.16) + r(24,34,74,10,.4) + r(158,22,52,5,.16) + r(158,34,74,10,.4)
      + r(24,62,96,7,.12) + r(158,62,96,7,.12) + r(24,76,62,8,.85,V)); },
    comisiones: function(){ return svg(
      r(12,14,124,34,.06) + c(30,31,10,.85,V) + r(46,24,54,5,.4) + r(46,34,40,4,.14)
      + r(12,56,124,34,.06) + c(30,73,10,.2) + r(46,66,54,5,.22) + r(46,76,40,4,.1)
      + r(148,14,120,76,.05)
      + '<circle cx="208" cy="52" r="26" fill="none" stroke="currentColor" stroke-width="10" opacity=".12"/>'
      + '<circle cx="208" cy="52" r="26" fill="none" stroke="'+V+'" stroke-width="10" '
      + 'stroke-dasharray="112 164" stroke-linecap="round" transform="rotate(-90 208 52)" opacity=".9"/>'); },
    gastos: function(){ return svg(
      r(12,12,256,22,.06) + r(24,19,88,7,.22) + r(214,19,44,7,.55,'#c58a14')
      + r(12,40,256,22,.06) + r(24,47,70,7,.22) + r(214,47,44,7,.28)
      + r(12,68,256,22,.06) + r(24,75,96,7,.22) + r(214,75,44,7,.28)); },
    reportes: function(){ return svg(
      '<line x1="20" y1="88" x2="264" y2="88" stroke="currentColor" stroke-width="1.2" opacity=".2"/>'
      + r(32,58,26,30,.2,null,2.5) + r(72,44,26,44,.2,null,2.5) + r(112,64,26,24,.2,null,2.5)
      + r(152,28,26,60,.9,V,2.5) + r(192,50,26,38,.2,null,2.5) + r(232,38,26,50,.2,null,2.5)); },
    ajustes: function(){ return svg(
      r(12,20,78,64,.06) + c(51,42,12,.85,V) + r(28,62,46,5,.22) + r(34,72,34,7,.5,V,3.5)
      + r(101,20,78,64,.06) + c(140,42,12,.18) + r(117,62,46,5,.22) + r(123,72,34,7,.2,null,3.5)
      + r(190,20,78,64,.06) + c(229,42,12,.18) + r(206,62,46,5,.22) + r(212,72,34,7,.2,null,3.5)); },
    tema: function(){ return svg(
      r(14,16,104,72,.06,null,8) + r(26,28,56,6,.3) + r(26,42,80,4,.14) + r(26,52,64,4,.14)
      + c(96,34,8,.9,'#c58a14')
      + r(162,16,104,72,.82,'var(--lf-tinta)',8) + r(174,28,56,6,.5,'var(--lf-sup)')
      + r(174,42,80,4,.3,'var(--lf-sup)') + r(174,52,64,4,.3,'var(--lf-sup)') + c(244,34,8,.9,V)
      + r(126,44,28,16,.85,V,8) + c(146,52,5.5,1,'var(--lf-sup)')); },
    cuenta: function(){ return svg(
      r(12,6,256,20,.05) + r(24,12,104,7,.22) + c(250,16,7,.85,V)
      + r(12,30,256,20,.05) + r(24,36,88,7,.22) + c(250,40,7,.85,V)
      + r(12,54,256,20,.05) + r(24,60,112,7,.22) + c(250,64,7,.85,'#c58a14')
      + r(12,78,256,20,.05) + r(24,84,76,7,.22) + c(250,88,7,.12)); },

    // ── Soporte ──
    cola: function(){ return svg(
      r(12,10,256,24,.06) + r(22,16,12,12,.85,V,4) + r(44,18,96,7,.4) + r(232,16,26,12,.85,V,6)
      + r(12,40,256,24,.06) + r(22,46,12,12,.55,'#c58a14',4) + r(44,48,80,7,.22) + r(232,46,26,12,.3,null,6)
      + r(12,70,256,24,.06) + r(22,76,12,12,.3,null,4) + r(44,78,110,7,.22) + r(232,76,26,12,.3,null,6)); },
    tickets: function(){ return svg(
      r(12,8,100,88,.05) + r(20,16,84,16,.5,V,4) + r(20,38,84,16,.12,null,4) + r(20,60,84,16,.12,null,4)
      + r(124,8,144,88,.05)
      + r(134,18,84,14,.16,null,7) + r(176,40,82,14,.8,V,7) + r(134,62,70,14,.16,null,7)
      + r(134,82,124,8,.1,null,4)); },
    campana: function(){ return svg(
      '<path d="M140 22c-13 0-22 10-22 23v15l-7 10h58l-7-10V45c0-13-9-23-22-23z" fill="none" '
      + 'stroke="currentColor" stroke-width="3" opacity=".35" stroke-linejoin="round"/>'
      + '<path d="M133 76a7 7 0 0 0 14 0" fill="none" stroke="currentColor" stroke-width="3" '
      + 'opacity=".35" stroke-linecap="round"/>'
      + c(160,28,9,.95,V)
      + r(12,58,82,30,.07,null,6) + c(28,73,7,.85,V) + r(40,68,44,5,.3) + r(40,77,30,4,.14)
      + r(186,58,82,30,.07,null,6) + r(194,66,50,5,.4) + r(194,76,36,4,.16)); },
    empresas: function(){ return svg(
      r(12,8,256,88,.05) + r(24,20,40,40,.85,V,10) + r(76,24,110,8,.45) + r(76,38,70,5,.18)
      + r(24,72,60,6,.18) + r(96,72,60,6,.18) + r(168,72,60,6,.18)
      + r(206,22,44,14,.85,V,7) + c(243,29,5,1,'var(--lf-sup)')); },
    conocimiento: function(){ return svg(
      r(12,8,80,88,.06) + r(20,18,64,6,.4) + r(20,30,52,4,.16) + r(20,40,58,4,.16) + r(20,80,30,8,.55,'#c58a14',4)
      + r(100,8,80,88,.06) + r(108,18,64,6,.4) + r(108,30,52,4,.16) + r(108,40,58,4,.16) + r(108,80,30,8,.85,V,4)
      + r(188,8,80,88,.06) + r(196,18,64,6,.4) + r(196,30,52,4,.16) + r(196,40,58,4,.16) + r(196,80,30,8,.3,null,4)); },
    informes: function(){ return svg(
      r(12,10,124,84,.05) + r(22,22,96,8,.85,V,4) + r(22,38,72,8,.45,V,4) + r(22,54,50,8,.28,V,4)
      + r(22,70,30,8,.16,V,4)
      + r(146,10,122,84,.05) + c(162,28,7,.85,V) + r(174,25,50,6,.3) + r(240,25,18,6,.5)
      + c(162,50,7,.2) + r(174,47,44,6,.22) + r(240,47,18,6,.3)
      + c(162,72,7,.2) + r(174,69,56,6,.22) + r(240,69,18,6,.3)); },
    mantenimiento: function(){ return svg(
      r(12,8,256,20,.05) + c(26,18,5,.85,V) + r(38,15,110,6,.25) + r(226,14,32,8,.3,null,4)
      + r(12,32,256,20,.05) + c(26,42,5,.85,V) + r(38,39,90,6,.25) + r(226,38,32,8,.3,null,4)
      + r(12,56,256,20,.05) + c(26,66,5,.85,'#c58a14') + r(38,63,124,6,.25) + r(226,62,32,8,.6,'#c58a14',4)
      + r(12,80,256,20,.05) + c(26,90,5,.85,V) + r(38,87,76,6,.25) + r(226,86,32,8,.3,null,4)); },
    cuentas: function(){ return ILUS.ajustes(); },
    perfil: function(){ return svg(
      r(12,8,256,88,.05) + c(46,40,20,.85,V) + r(78,28,90,8,.4) + r(78,42,60,5,.18)
      + r(26,70,96,16,.1,null,8) + r(29,73,30,10,.9,'var(--lf-sup)',5)
      + c(150,78,6,.9,'#27ae60') + c(166,78,6,.9,'#1d6fa5') + c(182,78,6,.9,'#7c3aed')
      + c(198,78,6,.9,'#c2410c') + c(214,78,6,.9,'#be123c')); }
  };

  var g = document.getElementById('lfGuia');
  if (!g || !PASOS.length) return;
  var i = 0;
  var foco = document.getElementById('lfGuiaFoco'),
      card = document.getElementById('lfGuiaCard');

  function ancla(p){
    if (p.id === 'tema') return document.getElementById('lfTema');
    if (p.id === 'campana') return document.querySelector('[data-lf-campana] .lf-campana-bt');
    if (!p.ruta) return null;
    return document.querySelector('.lf-nav a[href="' + p.ruta + '"]');
  }

  function pinta(){
    var p = PASOS[i];
    document.getElementById('lfGuiaNum').textContent = (i+1) + ' de ' + PASOS.length;
    document.getElementById('lfGuiaTit').textContent = p.titulo;
    document.getElementById('lfGuiaTxt').textContent = p.texto;
    document.getElementById('lfGuiaIlu').innerHTML = (ILUS[p.icono] || ILUS.bienvenida)();
    document.getElementById('lfGuiaAtras').hidden = i === 0;
    document.getElementById('lfGuiaSig').textContent = (i === PASOS.length-1)
      ? (p.boton || 'Terminar') : 'Siguiente';

    var el = ancla(p);
    if (!el) {
      foco.hidden = true;
      card.className = 'lf-guia-card centro';
      card.style.top = ''; card.style.left = '';
      return;
    }
    var b = el.getBoundingClientRect();
    // Si el ancla está fuera de pantalla —menú cerrado en móvil— se cae
    // al modo centrado en vez de dibujar un foco sobre la nada.
    if (b.width < 4 || b.right < 8 || b.left > innerWidth - 8) {
      foco.hidden = true; card.className = 'lf-guia-card centro';
      card.style.top = ''; card.style.left = ''; return;
    }
    foco.hidden = false;
    foco.style.top = (b.top - 6) + 'px';
    foco.style.left = (b.left - 6) + 'px';
    foco.style.width = (b.width + 12) + 'px';
    foco.style.height = (b.height + 12) + 'px';

    card.className = 'lf-guia-card';
    var alto = card.offsetHeight || 300, ancho = card.offsetWidth || 330;
    var izq = Math.min(b.right + 18, innerWidth - ancho - 14);
    if (izq < b.right + 8) {
      /* No cabe a un costado: el ancla (el botón de tema, arriba a la
         derecha) quedaba TAPADA por la tarjeta. Se pone debajo, alineada
         a su borde derecho; si abajo no hay lugar, encima. */
      var lef = Math.max(14, Math.min(b.right - ancho, innerWidth - ancho - 14));
      var abajo = b.bottom + 16;
      card.style.left = lef + 'px';
      card.style.top = (abajo + alto <= innerHeight - 14
        ? abajo : Math.max(14, b.top - alto - 16)) + 'px';
    } else {
      card.style.left = izq + 'px';
      card.style.top = Math.max(14, Math.min(b.top - 30, innerHeight - alto - 14)) + 'px';
    }
  }

  function cerrar(irA){
    g.hidden = true;
    document.body.style.overflow = '';
    /* Si se cambia de página en seguida, el aviso se cancelaba y la guía
       volvía a salir. Se espera la respuesta (o medio segundo) antes de ir,
       y `keepalive` lo deja terminar aunque la página se vaya. */
    var ido = false, ir = function(){ if (!ido && irA) { ido = true; location.href = irA; } };
    fetch('/guia/vista', {method:'POST', keepalive:true, credentials:'same-origin',
                          headers:{'X-Requested-With':'fetch'}})
      .then(ir, ir);
    if (irA) setTimeout(ir, 600);
  }

  document.getElementById('lfGuiaSaltar').addEventListener('click', function(){ cerrar(); });
  document.getElementById('lfGuiaAtras').addEventListener('click', function(){ if (i>0){ i--; pinta(); } });
  document.getElementById('lfGuiaSig').addEventListener('click', function(){
    if (i < PASOS.length-1) { i++; pinta(); }
    else cerrar(PASOS[i].ir || null);
  });
  document.addEventListener('keydown', function(e){
    if (g.hidden) return;
    if (e.key === 'Escape') cerrar();
    if (e.key === 'ArrowRight') document.getElementById('lfGuiaSig').click();
    if (e.key === 'ArrowLeft')  document.getElementById('lfGuiaAtras').click();
  });
  addEventListener('resize', function(){ if (!g.hidden) pinta(); });

  function arrancar(){
    g.hidden = false;
    document.body.style.overflow = 'hidden';
    pinta();
  }
  /* Después de la configuración inicial el sistema entra animado: la guía
     espera a que termine para no encimarse (ver layout, `lf:estreno-fin`). */
  if (document.documentElement.classList.contains('lf-estreno') && !window.lfEstrenoFin) {
    document.addEventListener('lf:estreno-fin', arrancar, { once: true });
  } else {
    arrancar();
  }
})();
</script>
