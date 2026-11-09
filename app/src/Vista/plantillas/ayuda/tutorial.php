<?php
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Integraciones;

/**
 * Tutorial · el ENTORNO DE PRÁCTICA.
 *
 * Un LibertyFin de prueba, con datos de ejemplo, que se recorre con una
 * guía de lecciones al lado. Se entra por una pantalla igual a la de
 * inicio de sesión y, dentro, el menú, las pantallas y las cifras se
 * comportan como las de verdad: el producto que se da de alta aparece en
 * Caja, la venta que queda con saldo aparece en Ventas y en Cobranza, y
 * lo cobrado en efectivo es lo que debe cuadrar en el corte.
 *
 * NADA DE ESTO TOCA LA BASE. Todo vive en el navegador (localStorage) y
 * se reinicia con un botón.
 *
 * Lo único que viene del servidor son los atajos «En tu sistema: …» de
 * cada lección, con las mismas reglas que el menú (permiso, módulo,
 * integración, sección apagada): un atajo que lleva a «Esta sección no es
 * para tu rol» enseña lo contrario de lo que se quiere.
 *
 * El menú simulado NO usa las clases del menú real (.lf-nav): el layout
 * las intercepta para su navegación sin recarga.
 */
$modulos  = $modulos ?? [];
$apagadas = $apagadas ?? [];
$puede = function ($permiso, array $c = []) use ($modulos, $apagadas) {
    if ($permiso && !Permisos::puede($permiso)) return false;
    if (isset($c['mod']) && empty($modulos[$c['mod']])) return false;
    if (isset($c['si'])  && !Integraciones::activa($c['si'])) return false;
    if (isset($c['sec']) && isset($apagadas[$c['sec']])) return false;
    return true;
};
$atajos = [];
foreach ([
    'productos' => ['/servicios', 'Productos', 'ver.servicios', []],
    'caja'      => ['/caja', 'Caja', 'cobrar', []],
    'ventas'    => ['/ventas', 'Ventas', 'ver.ventas', []],
    'cobranza'  => ['/cobranza', 'Cobranza', 'ver.cobranza', ['sec' => 'cobranza']],
    'ligas'     => ['/ligas', 'Ligas de pago', 'ver.ligas', ['si' => 'spei', 'sec' => 'ligas']],
    'corte'     => ['/corte', 'Corte de caja', 'ver.corte', ['sec' => 'corte']],
    'reportes'  => ['/reportes', 'Reportes', 'ver.reportes', ['sec' => 'reportes']],
    'ajustes'   => ['/ajustes?t=modulos', 'Ajustes', 'ver.ajustes', []],
] as $k => $a) {
    if ($puede($a[2], $a[3])) $atajos[$k] = ['url' => $a[0], 'txt' => $a[1]];
}
?>
<style>
  /* El tutorial va SIEMPRE con el verde de LibertyFin, aunque la empresa
     haya elegido otro color: el layout lo cambia en :root, y los tonos
     derivados se calculan ahí, así que se vuelven a declarar todos aquí. */
  .tu{container-type:inline-size;--tu-verde-osc:#26512e;--tu-ease:cubic-bezier(.2,.8,.2,1);
    --lf-brand:#4baa5c;
    --lf-brand-2:color-mix(in srgb, var(--lf-brand) 82%, #000);
    --lf-brand-d:color-mix(in srgb, var(--lf-brand) 82%, #000);
    --lf-brand-3:color-mix(in srgb, var(--lf-brand) 65%, #fff);
    --lf-brand-soft:color-mix(in srgb, var(--lf-brand) 11%, #fff);
    --lf-brand-glow:color-mix(in srgb, var(--lf-brand) 10%, transparent);
    --lf-brand-ring:color-mix(in srgb, var(--lf-brand) 22%, transparent)}
  :root[data-theme="dark"] .tu{
    --lf-brand-soft:color-mix(in srgb, var(--lf-brand) 18%, #0c110f);
    --lf-brand-2:color-mix(in srgb, var(--lf-brand) 58%, #fff)}
  .tu [hidden]{display:none!important}
  .tu svg{flex:none}

  /* ── PORTADA ─────────────────────────────────────────── */
  .tu-portada{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);background:var(--lf-sup);
    border:1px solid var(--lf-linea);border-radius:var(--lf-r-panel);overflow:hidden;box-shadow:var(--lf-s1)}
  .tu-portada-txt{padding:44px 44px 40px}
  .tu-eti{display:inline-block;font-size:11px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--lf-brand-2)}
  .tu-portada h2{font-size:clamp(26px,3.2vw,34px);font-weight:700;letter-spacing:-1px;line-height:1.12;margin:12px 0 12px}
  .tu-portada-txt > p{font-size:15px;color:var(--lf-tinta-2);margin:0 0 22px;max-width:520px}
  .tu-incluye{list-style:none;margin:0 0 28px;padding:0;display:flex;flex-direction:column;gap:11px}
  .tu-incluye li{display:flex;gap:11px;font-size:13.5px;color:var(--lf-tinta-2);line-height:1.45}
  .tu-incluye li svg{color:var(--lf-brand);margin-top:1px}
  .tu-incluye b{color:var(--lf-tinta);font-weight:600}
  .tu-portada-acc{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .tu-portada-acc small{color:var(--lf-tinta-4)}
  .tu-portada-arte{position:relative;background:var(--lf-brand);display:flex;align-items:center;justify-content:center;padding:40px 32px;
    background-image:radial-gradient(520px 340px at 50% 40%, color-mix(in srgb,#fff 12%,transparent), transparent 65%)}
  .tu-mini-app{width:100%;max-width:400px;background:var(--lf-lienzo);border-radius:14px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.28);
    transform:rotate(-1.5deg)}
  .tu-mini-barra{height:26px;background:var(--lf-sup);display:flex;gap:5px;align-items:center;padding:0 10px;border-bottom:1px solid var(--lf-linea)}
  .tu-mini-barra i{width:7px;height:7px;border-radius:50%;background:var(--lf-linea-f)}
  .tu-mini-cuerpo{display:grid;grid-template-columns:88px 1fr;height:220px}
  .tu-mini-nav{padding:12px 8px;display:flex;flex-direction:column;gap:6px;position:relative}
  .tu-mini-nav i{height:8px;border-radius:4px;background:var(--lf-linea-f)}
  .tu-mini-nav::before{content:'';position:absolute;left:8px;right:8px;top:12px;height:8px;border-radius:4px;background:var(--lf-brand);opacity:.6;
    transform:translateY(14px);animation:tu-mini-ind 10s var(--tu-ease) infinite}
  @keyframes tu-mini-ind{0%,18%{transform:translateY(14px)}25%,43%{transform:translateY(42px)}50%,68%{transform:translateY(0)}75%,93%{transform:translateY(70px)}100%{transform:translateY(14px)}}
  .tu-mini-main{background:var(--lf-panel);border-radius:12px 0 0 0;border:1px solid var(--lf-linea);border-width:1px 0 0 1px;margin-top:8px;padding:14px;
    display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:auto 1fr;gap:8px}
  .tu-mini-main b{display:block;height:46px;border-radius:9px;background:var(--lf-sup);border:1px solid var(--lf-linea)}
  .tu-mini-main b:first-child{background:var(--lf-brand-soft)}
  .tu-mini-main em{grid-column:1/-1;border-radius:9px;background:var(--lf-sup);border:1px solid var(--lf-linea)}

  /* Debajo de la portada: cómo funciona y el temario */
  .tu-inicio{display:flex;flex-direction:column;gap:18px}
  .tu-bloque-tit{font-size:15px;font-weight:700;letter-spacing:-.3px;margin:0;color:var(--lf-tinta)}
  .tu-como{background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:var(--lf-r-panel);padding:22px 26px;box-shadow:var(--lf-s1)}
  .tu-pasos3{list-style:none;margin:16px 0 0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}
  .tu-pasos3 li{display:flex;gap:12px;align-items:flex-start}
  .tu-pasos3 .n{flex:none;width:28px;height:28px;border-radius:50%;background:var(--lf-brand-soft);color:var(--lf-brand-2);display:grid;place-items:center;font-weight:700;font-size:13px}
  .tu-pasos3 b{display:block;font-size:13.5px;font-weight:600;color:var(--lf-tinta);margin:4px 0 3px}
  .tu-pasos3 p{margin:0;font-size:12.5px;color:var(--lf-tinta-3);line-height:1.5}
  .tu-como-ayuda{margin:18px 0 0;padding-top:14px;border-top:1px solid var(--lf-linea);font-size:12.5px;color:var(--lf-tinta-3)}
  .tu-como-ayuda a{font-weight:600}
  .tu-bloque-cab{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:6px 2px 12px}
  .tu-avance{font-size:12.5px;color:var(--lf-tinta-3);font-weight:600}
  .tu-temario{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .tu-tema{display:flex;flex-direction:column;gap:9px;text-align:left;padding:16px 16px 14px;background:var(--lf-sup);border:1px solid var(--lf-linea);
    border-radius:var(--lf-r-lg);cursor:pointer;font-family:var(--lf-font);color:var(--lf-tinta-2);min-height:118px;
    transition:transform .22s var(--tu-ease),box-shadow .22s,border-color .22s}
  .tu-tema:hover{transform:translateY(-3px);box-shadow:var(--lf-s2);border-color:color-mix(in srgb,var(--lf-brand) 45%,var(--lf-linea))}
  .tu-tema:active{transform:translateY(-1px) scale(.99)}
  .tu-tema-cab{display:flex;align-items:center;gap:10px}
  .tu-tema .n{flex:none;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--lf-vidrio);color:var(--lf-tinta-3);
    transition:background .2s,color .2s}
  .tu-tema b{font-size:14px;font-weight:600;color:var(--lf-tinta);line-height:1.3}
  .tu-tema small{font-size:12.5px;color:var(--lf-tinta-3);line-height:1.45}
  .tu-tema-pie{margin-top:auto;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:11.5px;color:var(--lf-tinta-4)}
  .tu-tema-pie em{font-style:normal;font-weight:600;color:var(--lf-brand-2);opacity:0;transform:translateX(-5px);transition:opacity .2s,transform .2s var(--tu-ease)}
  .tu-tema:hover .tu-tema-pie em, .tu-tema:focus-visible .tu-tema-pie em, .tu-tema.actual .tu-tema-pie em{opacity:1;transform:none}
  .tu-tema:hover .n{background:var(--lf-brand-soft);color:var(--lf-brand-2)}
  .tu-tema.hecha .n{background:var(--lf-brand);color:#fff}
  .tu-tema.hecha .estado{color:var(--lf-brand-2);font-weight:600}
  .tu-tema.actual{border-color:var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)}
  .tu-tema.actual .n{background:var(--lf-brand-soft);color:var(--lf-brand-2)}
  .tu-tema.actual .estado{color:var(--lf-brand-2);font-weight:600}

  /* ── INGRESO ─────────────────────────────────────────── */
  .tu-entrada{min-height:560px;border-radius:var(--lf-r-panel);background:var(--lf-brand);display:flex;align-items:center;justify-content:center;padding:40px 20px;
    background-image:radial-gradient(900px 560px at 50% 38%, color-mix(in srgb,#fff 9%,transparent) 0%, transparent 62%)}
  .tu-login{width:100%;max-width:380px;display:flex;flex-direction:column;align-items:center}
  .tu-login-marca{font-size:32px;font-weight:800;letter-spacing:-1.3px;color:#fff;margin-bottom:22px}
  .tu-login-tarjeta{width:100%;background:var(--tu-verde-osc);border-radius:16px;padding:32px 32px 30px;box-shadow:0 7px 44px rgb(0 0 0 / 45%);
    border:1px solid rgba(255,255,255,.35)}
  .tu-login-tarjeta h3{font-size:17px;font-weight:700;color:#fff;margin:0 0 2px}
  .tu-login-tarjeta > p{font-size:12px;color:rgba(255,255,255,.66);margin:0 0 22px;line-height:1.45}
  .tu-login-tarjeta label{display:block;font-size:12px;font-weight:500;margin-bottom:7px;color:rgba(255,255,255,.82)}
  .tu-login-campo{position:relative;margin-bottom:16px}
  .tu-login-campo svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#6d7a74}
  .tu-login-campo input{width:100%;height:44px;padding:0 14px 0 41px;background:#fff;border:1.5px solid transparent;border-radius:9px;color:#1b2420;
    font-size:14px;font-family:var(--lf-font);outline:none}
  .tu-login-boton{width:100%;height:46px;margin-top:8px;border:0;border-radius:10px;background:var(--lf-brand);color:#fff;font-weight:700;font-size:14px;
    font-family:var(--lf-font);cursor:pointer;box-shadow:0 6px 18px rgba(0,0,0,.25);transition:filter .15s}
  .tu-login-boton:hover{filter:brightness(1.07)}
  .tu-login-nota{font-size:11.5px;color:rgba(255,255,255,.62);margin:16px 0 0;text-align:center;line-height:1.5}
  .tu-login-volver{margin-top:18px;background:none;border:0;color:rgba(255,255,255,.8);font-size:12.5px;text-decoration:underline;cursor:pointer;font-family:var(--lf-font)}

  /* ── ENTORNO: ventana + guía ─────────────────────────── */
  .tu-entorno{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start}
  .tu-v{border:1px solid var(--lf-linea-f);border-radius:var(--lf-r-lg);overflow:hidden;background:var(--lf-lienzo);box-shadow:var(--lf-s2)}
  .tu-v-barra{display:flex;align-items:center;gap:12px;padding:9px 14px;background:var(--lf-sup);border-bottom:1px solid var(--lf-linea)}
  .tu-v-puntos{display:flex;gap:6px}
  .tu-v-puntos i{width:9px;height:9px;border-radius:50%;background:var(--lf-linea-f)}
  .tu-v-titulo{font-size:12.5px;color:var(--lf-tinta-3);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tu-v-titulo b{color:var(--lf-tinta);font-weight:600}
  .tu-v-barra .btn{padding:5px 11px;font-size:11.5px}
  .tu-v-cuerpo{display:grid;grid-template-columns:184px minmax(0,1fr);min-height:600px}
  .tu-v-lado{display:flex;flex-direction:column;padding:4px 0 12px}
  .tu-v-marca{display:flex;align-items:center;gap:9px;padding:14px 16px 10px}
  .tu-v-marca .g{width:30px;height:30px;border-radius:50%;background:var(--lf-brand);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px}
  .tu-v-marca b{font-size:14px;font-weight:700;letter-spacing:-.2px;display:block;line-height:1.1}
  .tu-v-marca small{font-size:9.5px;color:var(--lf-tinta-4);font-weight:600;letter-spacing:.6px;text-transform:uppercase}
  .tu-v-nav{display:flex;flex-direction:column;gap:2px;padding:4px 10px;position:relative}
  .tu-v-nav > *{position:relative;z-index:1}
  .tu-v-nav > .tu-v-ind{position:absolute;left:0;top:0;z-index:0;border-radius:var(--lf-r);background:var(--lf-brand-soft);pointer-events:none;
    transition:transform .34s var(--tu-ease),width .34s var(--tu-ease),height .34s var(--tu-ease),opacity .2s}
  .tu-v-nav > .tu-v-ind.quieto{transition:none}
  .tu-v-nav .sec{font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--lf-tinta-4);padding:12px 10px 5px}
  .tu-v-nav button{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--lf-r);border:0;background:none;cursor:pointer;
    color:var(--lf-tinta-2);font-size:13px;font-weight:500;font-family:var(--lf-font);text-align:left;position:relative;transition:background .15s,color .15s}
  .tu-v-nav button:hover{background:var(--lf-brand-glow);color:var(--lf-tinta)}
  .tu-v-nav button.on{background:var(--lf-brand-soft);color:var(--lf-brand-2);font-weight:600}
  .tu-v-nav.con-ind button.on{background:transparent}
  .tu-v-nav button.nuevo{animation:tu-nav-nuevo .5s var(--tu-ease) both, tu-brilla 1.6s ease .4s 2}
  @keyframes tu-nav-nuevo{from{opacity:0;transform:translateX(-10px)}}
  .tu-v-nav button .pista{position:absolute;right:9px;top:50%;width:8px;height:8px;margin-top:-4px;border-radius:50%;background:var(--lf-brand);
    box-shadow:0 0 0 0 var(--lf-brand-ring);animation:tu-pulso 1.6s ease infinite}
  @keyframes tu-pulso{0%{box-shadow:0 0 0 0 var(--lf-brand-ring)}70%{box-shadow:0 0 0 7px transparent}100%{box-shadow:0 0 0 0 transparent}}
  @keyframes tu-brilla{0%,100%{background:transparent}50%{background:var(--lf-brand-soft)}}
  .tu-v-main{background:var(--lf-panel);border-radius:var(--lf-r-lg) 0 0 0;border-left:1px solid var(--lf-linea);border-top:1px solid var(--lf-linea);margin-top:10px;min-width:0}
  .tu-v-top{padding:16px 22px 10px}
  .tu-v-top h3{font-size:16px;margin:0;display:flex;align-items:center;gap:8px}
  .tu-v-top h3 svg{color:var(--lf-brand)}
  .tu-v-top p{font-size:12px;color:var(--lf-tinta-4);margin:3px 0 0}
  .tu-v-cont{padding:8px 22px 24px}

  /* Componentes del sistema, a la escala de la ventana */
  .tu-v .stat-card{padding:12px 14px}
  .tu-v .stat-card:hover{transform:none}
  .tu-v .stat-value{font-size:18px;letter-spacing:-.6px;white-space:nowrap}
  .tu-v .stat-label{font-size:11.5px}
  .tu-v .card-header{padding:12px 16px 10px;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:10px}
  .tu-v .card-body{padding:14px 16px}
  .tu-v .table > tbody > tr > td{padding:10px 12px;font-size:12.5px}
  .tu-v .table > thead > tr > th{padding:9px 12px}
  .tu-cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(124px,1fr));gap:10px;margin-bottom:14px}
  .tu-dos{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}
  .tu-pila{display:flex;flex-direction:column;gap:14px}
  .tu-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;align-items:end}
  .tu-form .form-label{margin-bottom:5px}
  .tu .form-control,.tu .form-select{width:100%;box-sizing:border-box}
  .tu-form .btn{height:34px;padding-top:0;padding-bottom:0}
  .tu-msj{font-size:12.5px;margin:10px 0 0;line-height:1.45;color:var(--lf-tinta-3)}
  .tu-msj:empty{display:none}
  .tu-msj.bien{color:var(--lf-brand-2);font-weight:600}
  .tu-msj.mal{color:var(--lf-rojo);font-weight:600}
  .tu-msj.aviso{color:var(--lf-amb);font-weight:600}
  .tu-seg{display:inline-flex;gap:2px;padding:3px;background:var(--lf-vidrio);border:1px solid var(--lf-linea);border-radius:var(--lf-r-pill);flex-wrap:wrap}
  .tu-seg button{border:0;background:none;border-radius:var(--lf-r-pill);padding:6px 13px;font-size:12px;font-weight:600;color:var(--lf-tinta-3);
    cursor:pointer;font-family:var(--lf-font)}
  .tu-seg button.on{background:var(--lf-sup);color:var(--lf-tinta);box-shadow:var(--lf-s1)}
  .tu-barra-fil{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .tu-vacio{font-size:12.5px;color:var(--lf-tinta-4);text-align:center;padding:18px 10px;border:1px dashed var(--lf-linea-f);border-radius:var(--lf-r)}
  .tu-link{border:0;background:none;color:var(--lf-brand-2);font-weight:600;cursor:pointer;padding:0;font-family:var(--lf-font);font-size:inherit}
  .tu-link:hover{text-decoration:underline}
  .tu-mono{font-family:var(--lf-mono);font-variant-numeric:tabular-nums}

  /* Caja */
  .tu-caja{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:14px;align-items:start}
  .tu-prods{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px}
  .tu-prod{border:1px solid var(--lf-linea);border-radius:var(--lf-r);padding:11px;background:var(--lf-sup);text-align:left;cursor:pointer;font-family:var(--lf-font);
    font-size:12.5px;color:var(--lf-tinta-2);transition:border-color .15s,box-shadow .15s;min-height:66px;display:flex;flex-direction:column;justify-content:space-between}
  .tu-prod:hover{border-color:var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)}
  .tu-prod b{color:var(--lf-tinta);font-size:13px;font-family:var(--lf-mono);font-weight:600}
  .tu-prod.libre b{font-family:var(--lf-font);color:var(--lf-tinta-3);font-weight:500;font-size:12px}
  .tu-ticket .card-body{display:flex;flex-direction:column;gap:12px}
  .tu-lineas{display:flex;flex-direction:column}
  .tu-linea{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--lf-linea);font-size:12.5px}
  .tu-linea:last-child{border-bottom:0}
  .tu-linea .nom{color:var(--lf-tinta);font-weight:600;line-height:1.3}
  .tu-linea .imp{text-align:right;font-weight:600}
  .tu-linea input.form-control{width:104px;padding:5px 8px;font-size:12px}
  .tu-cant{display:inline-flex;align-items:center;gap:6px;font-size:12.5px}
  .tu-cant button{width:22px;height:22px;border-radius:7px;border:1px solid var(--lf-linea-f);background:var(--lf-sup);cursor:pointer;color:var(--lf-tinta);
    font-family:var(--lf-font);line-height:1;padding:0}
  .tu-quitar{justify-self:end;height:24px;min-width:24px;border:0;background:none;border-radius:7px;color:var(--lf-tinta-3);cursor:pointer;font-size:12px;font-family:var(--lf-font);line-height:1;padding:0 6px}
  .tu-quitar:hover{background:var(--lf-rojo-soft);color:var(--lf-rojo)}
  .tu-total{display:flex;justify-content:space-between;align-items:baseline;border-top:1px solid var(--lf-linea);padding-top:10px}
  .tu-total span{font-size:13px;color:var(--lf-tinta-3)}
  .tu-total b{font-family:var(--lf-mono);font-size:22px;letter-spacing:-.6px}
  .tu-sub-caja{font-size:12.5px;color:var(--lf-tinta-3)}
  .tu-cambio{font-family:var(--lf-mono);font-size:15px;font-weight:700}
  .tu-check{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:var(--lf-tinta-2)}
  .tu-revisa{border:1px solid var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)}
  .tu-avisos{list-style:none;margin:0 0 12px;padding:0;display:flex;flex-direction:column;gap:6px}
  .tu-avisos li{font-size:12.5px;color:var(--lf-tinta-2);background:var(--lf-amb-soft);border-radius:var(--lf-r-sm);padding:7px 11px}
  .tu-filas{display:flex;flex-direction:column;gap:5px;font-size:12.5px;margin-bottom:14px}
  .tu-filas div{display:flex;justify-content:space-between;gap:12px;color:var(--lf-tinta-3)}
  .tu-filas div b{color:var(--lf-tinta);font-weight:600}
  .tu-filas .grande{padding-top:6px;border-top:1px solid var(--lf-linea);margin-top:3px}
  .tu-filas .grande b{font-family:var(--lf-mono);font-size:17px}
  .tu-acc{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .tu-recibo{max-width:360px;font-family:var(--lf-mono);font-size:12px}
  .tu-recibo .card-body{display:flex;flex-direction:column;gap:6px}
  .tu-recibo .r-fila{display:flex;justify-content:space-between;gap:10px;color:var(--lf-tinta-2)}
  .tu-recibo .r-tit{font-family:var(--lf-font);font-weight:700;font-size:14px;color:var(--lf-tinta);text-align:center}
  .tu-recibo .r-sep{border-top:1px dashed var(--lf-linea-f);margin:4px 0}
  .tu-recibo .r-pie{font-family:var(--lf-font);font-size:11.5px;color:var(--lf-tinta-3);text-align:center}

  /* Ventas, cobranza, corte, reportes */
  .tu-pago{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--lf-linea);font-size:12.5px;color:var(--lf-tinta-2)}
  .tu-pago:last-child{border-bottom:0}
  .tu-pago .f{font-family:var(--lf-mono);color:var(--lf-tinta)}
  .tu-pago .f em{font-style:normal;color:var(--lf-brand-2);font-weight:700}
  .tu-pago .m{margin-left:auto;font-family:var(--lf-mono);color:var(--lf-tinta);font-weight:600;white-space:nowrap}
  .tu-pago.cancelado{opacity:.55}
  .tu-pago.cancelado .f, .tu-pago.cancelado .m{text-decoration:line-through}
  tr.tu-fila-nueva{animation:tu-sube .4s var(--tu-ease) both}
  tr.tu-fila-nueva > td{animation:tu-resalta 1.8s ease both}
  .tu-pago.nuevo{animation:tu-sube .4s var(--tu-ease) both, tu-resalta 1.6s ease both}
  .tu-liga{display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:var(--lf-sup-2);border:1px solid var(--lf-linea);border-radius:var(--lf-r);padding:14px;margin-top:12px}
  .tu-qr{width:96px;height:96px;display:grid;grid-template-columns:repeat(11,1fr);gap:1px;padding:6px;background:#fff;border-radius:8px;border:1px solid var(--lf-linea)}
  .tu-qr i{background:#1b2420;border-radius:1px}
  .tu-qr i.b{background:#fff}
  .tu-clabe{font-family:var(--lf-mono);font-size:17px;font-weight:700;color:var(--lf-tinta);letter-spacing:.4px;margin:4px 0}
  .tu-cuenta{display:flex;justify-content:space-between;align-items:baseline;background:var(--lf-brand-soft);border-radius:var(--lf-r);padding:12px 14px}
  .tu-cuenta span{font-size:12.5px;color:var(--lf-tinta-2)}
  .tu-cuenta b{font-family:var(--lf-mono);font-size:22px;color:var(--lf-tinta)}
  .tu-cascada{display:flex;flex-direction:column;gap:9px}
  .tu-cas{display:grid;grid-template-columns:150px minmax(0,1fr) 96px;gap:12px;align-items:center;font-size:12.5px}
  .tu-cas span{color:var(--lf-tinta-2)}
  .tu-cas em{font-style:normal;text-align:right;font-family:var(--lf-mono);color:var(--lf-tinta);font-weight:600}
  .tu-carril{height:12px;background:var(--lf-vidrio);border-radius:99px;position:relative;overflow:hidden}
  .tu-carril i{position:absolute;top:0;bottom:0;border-radius:99px;transition:left .35s,width .35s}
  .tu-cas.base i{background:var(--lf-tinta-2)} .tu-cas.menos i{background:var(--lf-amb)} .tu-cas.queda i{background:var(--lf-brand)}
  .tu-cas.queda span, .tu-cas.queda em{color:var(--lf-brand-2);font-weight:700}
  .tu-hero{background:linear-gradient(135deg,var(--lf-brand) 0%,var(--lf-brand-d) 100%);border-radius:var(--lf-r-lg);color:#fff;padding:18px 20px;margin-bottom:14px}
  .tu-hero small{font-size:12px;opacity:.85}
  .tu-hero div{font-size:19px;font-weight:600;margin-top:3px;letter-spacing:-.3px}
  .tu-hero b{font-family:var(--lf-mono)}
  .tu-sw{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--lf-linea)}
  .tu-sw:last-child{border-bottom:0}
  .tu-sw b{font-size:13.5px;font-weight:600;color:var(--lf-tinta);display:block}
  .tu-sw small{font-size:12px;color:var(--lf-tinta-3)}
  .tu-sw button{width:40px;height:23px;border-radius:99px;border:0;background:var(--lf-linea-f);position:relative;cursor:pointer;flex:none;transition:background .2s}
  .tu-sw button::after{content:'';position:absolute;top:3px;left:3px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s}
  .tu-sw button[aria-pressed="true"]{background:var(--lf-brand)}
  .tu-sw button[aria-pressed="true"]::after{left:20px}

  /* ── GUÍA ────────────────────────────────────────────── */
  .tu-guia{position:sticky;top:16px;display:flex;flex-direction:column;gap:12px}
  .tu-guia .card-body{padding:18px}
  .tu-g-n{display:flex;justify-content:space-between;align-items:center;font-size:11.5px;font-weight:600;color:var(--lf-tinta-3);margin-bottom:8px}
  .tu-g-prog{height:4px;border-radius:99px;background:var(--lf-linea);overflow:hidden;margin-bottom:14px}
  .tu-g-prog i{display:block;height:100%;background:var(--lf-brand);border-radius:99px;transition:width .7s var(--tu-ease)}
  .tu-guia h4{font-size:17px;letter-spacing:-.4px;margin:0 0 6px;line-height:1.25}
  .tu-guia .d{font-size:13px;color:var(--lf-tinta-2);margin:0 0 14px;line-height:1.5}
  .tu-pasos{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}
  .tu-pasos li{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--lf-tinta-2);padding:8px 0;line-height:1.45}
  .tu-pasos li + li{border-top:1px solid var(--lf-linea)}
  .tu-pasos .ck{flex:none;width:20px;height:20px;border-radius:50%;border:1.5px solid var(--lf-linea-f);display:grid;place-items:center;margin-top:-1px;color:#fff}
  .tu-pasos li.hecho .ck{background:var(--lf-brand);border-color:var(--lf-brand)}
  .tu-pasos li.hecho{color:var(--lf-tinta-3)}
  .tu-pasos li.actual{color:var(--lf-tinta)}
  .tu-pasos li.actual .ck{border-color:var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)}
  .tu-pasos b{color:inherit;font-weight:600}
  .tu-g-nota{font-size:12px;color:var(--lf-tinta-3);background:var(--lf-vidrio);border-radius:var(--lf-r-sm);padding:9px 11px;margin-top:12px;line-height:1.5}
  .tu-g-hecha{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:var(--lf-brand-2);margin-top:12px}
  .tu-g-real{display:block;margin-top:12px;font-size:12.5px;font-weight:600}
  .tu-g-nav{display:flex;gap:8px;margin-top:16px}
  .tu-g-nav .btn{flex:1}
  .tu-g-lista summary{list-style:none;cursor:pointer;font-size:13px;font-weight:600;color:var(--lf-tinta);display:flex;justify-content:space-between;align-items:center}
  .tu-g-lista summary::-webkit-details-marker{display:none}
  .tu-g-lista summary::after{content:'';width:7px;height:7px;border-right:1.5px solid var(--lf-tinta-3);border-bottom:1.5px solid var(--lf-tinta-3);transform:rotate(45deg);margin-top:-4px}
  .tu-g-lista[open] summary::after{transform:rotate(-135deg);margin-top:3px}
  .tu-g-lista ol{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-direction:column;gap:2px}
  .tu-g-lista ol button{width:100%;display:flex;gap:10px;align-items:center;border:0;background:none;padding:7px 8px;border-radius:var(--lf-r-sm);cursor:pointer;
    font-family:var(--lf-font);font-size:12.5px;color:var(--lf-tinta-2);text-align:left}
  .tu-g-lista ol button:hover{background:var(--lf-vidrio)}
  .tu-g-lista ol button.on{background:var(--lf-brand-soft);color:var(--lf-brand-2);font-weight:600}
  .tu-g-lista .n{flex:none;width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:10.5px;font-weight:700;background:var(--lf-vidrio);color:var(--lf-tinta-3)}
  .tu-g-lista .hecha .n{background:var(--lf-brand);color:#fff}

  /* ── MOVIMIENTO ──────────────────────────────────────────
     Tres momentos: al cambiar de vista (portada, ingreso, entorno), al
     cambiar de pantalla dentro del entorno (.tu-entra) y la respuesta a
     cada acción (línea nueva, pago nuevo, paso cumplido). Lo que se
     vuelve a pintar SIN cambiar de pantalla no repite la entrada. */
  @keyframes tu-sube{from{opacity:0;transform:translateY(10px)}}
  @keyframes tu-baja{from{opacity:0;transform:translateY(-8px)}}
  @keyframes tu-aparece{from{opacity:0}}
  @keyframes tu-desde-der{from{opacity:0;transform:translateX(22px)}}
  @keyframes tu-desde-izq{from{opacity:0;transform:translateX(-14px)}}
  @keyframes tu-escala{from{opacity:0;transform:translateY(14px) scale(.975)}}
  @keyframes tu-sale{to{opacity:0;transform:translateY(-10px) scale(1.02)}}
  @keyframes tu-sale-linea{to{opacity:0;transform:translateX(14px)}}
  @keyframes tu-pop{0%{transform:scale(.4)}60%{transform:scale(1.18)}100%{transform:scale(1)}}
  @keyframes tu-pop-seg{from{transform:scale(.9)}}
  @keyframes tu-traza{from{stroke-dashoffset:24}}
  @keyframes tu-imprime{from{clip-path:inset(0 0 100% 0);transform:translateY(-12px)}to{clip-path:inset(0 0 -20px 0)}}
  @keyframes tu-crece{from{transform:scaleX(0)}}
  @keyframes tu-salta{40%{transform:scale(1.07)}}
  @keyframes tu-latido{0%{box-shadow:0 0 0 0 var(--lf-brand-ring)}100%{box-shadow:0 0 0 12px transparent}}
  @keyframes tu-gira{to{transform:rotate(360deg)}}
  @keyframes tu-flota{50%{transform:rotate(-1.5deg) translateY(-7px)}}
  @keyframes tu-resalta{from{background:var(--lf-brand-soft)}}

  /* Portada */
  .tu-mini-app{animation:tu-flota 6s ease-in-out infinite}
  .tu-vista-entra .tu-portada .tu-portada-txt > *{animation:tu-sube .55s var(--tu-ease) both}
  .tu-vista-entra .tu-portada .tu-portada-txt > :nth-child(2){animation-delay:.06s}
  .tu-vista-entra .tu-portada .tu-portada-txt > :nth-child(3){animation-delay:.12s}
  .tu-vista-entra .tu-portada .tu-portada-txt > :nth-child(4){animation:none}
  .tu-vista-entra .tu-portada .tu-portada-txt > :nth-child(5){animation-delay:.4s}
  .tu-vista-entra .tu-portada .tu-incluye li{animation:tu-sube .5s var(--tu-ease) both}
  .tu-vista-entra .tu-portada .tu-incluye li:nth-child(1){animation-delay:.2s}
  .tu-vista-entra .tu-portada .tu-incluye li:nth-child(2){animation-delay:.27s}
  .tu-vista-entra .tu-portada .tu-incluye li:nth-child(3){animation-delay:.34s}
  .tu-vista-entra .tu-portada .tu-portada-arte{animation:tu-aparece .6s ease both}
  .tu-vista-entra .tu-portada .tu-mini-app{animation:tu-escala .8s var(--tu-ease) .15s both}
  .tu-vista-entra .tu-como{animation:tu-sube .55s var(--tu-ease) .3s both}
  .tu-vista-entra .tu-temario-sec .tu-bloque-cab{animation:tu-sube .5s var(--tu-ease) .4s both}
  .tu-vista-entra .tu-tema{animation:tu-sube .5s var(--tu-ease) both;animation-delay:calc(.44s + var(--i,0) * 40ms)}

  /* Ingreso */
  .tu-vista-entra.tu-entrada{animation:tu-aparece .45s ease both}
  .tu-vista-entra .tu-login-marca{animation:tu-baja .6s var(--tu-ease) .1s both}
  .tu-vista-entra .tu-login-tarjeta{animation:tu-escala .65s var(--tu-ease) .18s both}
  .tu-vista-entra .tu-login-volver{animation:tu-aparece .5s ease .45s both}
  .tu-login.sale{animation:tu-sale .38s ease both}
  .tu-login-boton:disabled{cursor:progress;filter:none}

  /* Entorno: la ventana sube, la guía entra por la derecha, el menú en cascada */
  .tu-v-main{--tu-d:0s}
  .tu-vista-entra .tu-v{animation:tu-escala .6s var(--tu-ease) both}
  .tu-vista-entra .tu-guia{animation:tu-desde-der .6s var(--tu-ease) .12s both}
  .tu-vista-entra .tu-v-marca{animation:tu-aparece .5s ease .15s both}
  .tu-vista-entra .tu-v-nav > :not(.tu-v-ind){animation:tu-desde-izq .45s var(--tu-ease) both;animation-delay:calc(.18s + var(--i,0) * 26ms)}
  .tu-vista-entra .tu-v-nav > .tu-v-ind{animation:tu-aparece .4s ease .5s both}
  .tu-vista-entra .tu-v-main{--tu-d:.28s}

  /* Al cambiar de pantalla */
  .tu-entra .tu-v-top > *{animation:tu-aparece .4s ease both;animation-delay:var(--tu-d)}
  .tu-entra .tu-v-cont > *{animation:tu-sube .45s var(--tu-ease) both;animation-delay:calc(var(--tu-d) + .05s)}
  .tu-entra .tu-v-cont > :nth-child(2){animation-delay:calc(var(--tu-d) + .11s)}
  .tu-entra .tu-v-cont > :nth-child(3){animation-delay:calc(var(--tu-d) + .17s)}
  .tu-entra .tu-v-cont > :nth-child(n+4){animation-delay:calc(var(--tu-d) + .23s)}
  .tu-entra .tu-v-cont > .tu-cifras, .tu-entra .tu-v-cont > .tu-caja, .tu-entra .tu-v-cont > .tu-dos{animation:none}
  .tu-entra .tu-cifras > *, .tu-entra .tu-caja > *, .tu-entra .tu-dos > *{animation:tu-sube .45s var(--tu-ease) both;animation-delay:calc(var(--tu-d) + .05s)}
  .tu-entra .tu-cifras > :nth-child(2), .tu-entra .tu-caja > :nth-child(2), .tu-entra .tu-dos > :nth-child(2){animation-delay:calc(var(--tu-d) + .1s)}
  .tu-entra .tu-cifras > :nth-child(3){animation-delay:calc(var(--tu-d) + .15s)}
  .tu-entra .tu-cifras > :nth-child(4){animation-delay:calc(var(--tu-d) + .2s)}
  .tu-entra tbody tr, .tu-reordena tbody tr, .tu-entra .tu-pago, .tu-entra .tu-prod{animation:tu-sube .4s var(--tu-ease) both;animation-delay:calc(var(--tu-d) + .2s)}
  .tu-entra tbody tr:nth-child(2), .tu-reordena tbody tr:nth-child(2), .tu-entra .tu-pago:nth-child(2), .tu-entra .tu-prod:nth-child(2){animation-delay:calc(var(--tu-d) + .24s)}
  .tu-entra tbody tr:nth-child(3), .tu-reordena tbody tr:nth-child(3), .tu-entra .tu-pago:nth-child(3), .tu-entra .tu-prod:nth-child(3){animation-delay:calc(var(--tu-d) + .28s)}
  .tu-entra tbody tr:nth-child(4), .tu-reordena tbody tr:nth-child(4), .tu-entra .tu-pago:nth-child(4), .tu-entra .tu-prod:nth-child(4){animation-delay:calc(var(--tu-d) + .32s)}
  .tu-entra tbody tr:nth-child(5), .tu-reordena tbody tr:nth-child(5), .tu-entra .tu-pago:nth-child(5), .tu-entra .tu-prod:nth-child(5){animation-delay:calc(var(--tu-d) + .36s)}
  .tu-entra tbody tr:nth-child(n+6), .tu-reordena tbody tr:nth-child(n+6), .tu-entra .tu-pago:nth-child(n+6), .tu-entra .tu-prod:nth-child(n+6){animation-delay:calc(var(--tu-d) + .4s)}
  .tu-reordena tbody tr{animation-delay:0s}
  .tu-reordena tbody tr:nth-child(2){animation-delay:.04s} .tu-reordena tbody tr:nth-child(3){animation-delay:.08s}
  .tu-reordena tbody tr:nth-child(4){animation-delay:.12s} .tu-reordena tbody tr:nth-child(n+5){animation-delay:.16s}
  .tu-entra .tu-v-cont > .tu-revisa{animation:tu-escala .45s var(--tu-ease) both}
  .tu-entra .tu-v-cont > .tu-recibo{animation:tu-imprime .85s cubic-bezier(.3,.7,.2,1) both}
  .tu-entra .tu-carril i{animation:tu-crece .7s var(--tu-ease) both;transform-origin:left center}
  .tu-entra .tu-cas.menos .tu-carril i{transform-origin:right center}
  .tu-entra .tu-cas:nth-child(1) i{animation-delay:calc(var(--tu-d) + .2s)}
  .tu-entra .tu-cas:nth-child(2) i{animation-delay:calc(var(--tu-d) + .4s)}
  .tu-entra .tu-cas:nth-child(3) i{animation-delay:calc(var(--tu-d) + .55s)}
  .tu-entra .tu-cas:nth-child(4) i{animation-delay:calc(var(--tu-d) + .7s)}
  .tu-entra .tu-cas:nth-child(5) i{animation-delay:calc(var(--tu-d) + .9s)}

  /* Respuesta a cada acción */
  .tu-prod{transition:border-color .15s,box-shadow .15s,transform .12s}
  .tu-prod:active{transform:scale(.96)}
  .tu-linea.nueva{animation:tu-sube .35s var(--tu-ease) both, tu-resalta 1.2s ease both}
  .tu-linea.sale{animation:tu-sale-linea .2s ease both}
  .tu-salta{display:inline-block;animation:tu-salta .32s ease}
  .tu-seg button{transition:background .2s,color .2s,box-shadow .2s}
  .tu-seg button.on.pop{animation:tu-pop-seg .3s var(--tu-ease)}
  .tu-msj:not(:empty){animation:tu-baja .3s var(--tu-ease)}
  .tu-liga.entra, .tu-resultado{animation:tu-escala .45s var(--tu-ease) both}
  .tu-gira{display:inline-block;width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;
    animation:tu-gira .7s linear infinite;vertical-align:-2px;margin-right:8px}
  .tu [aria-busy="true"]{cursor:progress}

  /* Guía */
  .tu-g-card.der{animation:tu-desde-der .45s var(--tu-ease) both}
  .tu-g-card.izq{animation:tu-desde-izq .45s var(--tu-ease) both}
  .tu-g-card.der .tu-pasos li, .tu-g-card.izq .tu-pasos li{animation:tu-sube .4s var(--tu-ease) both;animation-delay:.12s}
  .tu-g-card.der .tu-pasos li:nth-child(2), .tu-g-card.izq .tu-pasos li:nth-child(2){animation-delay:.18s}
  .tu-g-card.der .tu-pasos li:nth-child(3), .tu-g-card.izq .tu-pasos li:nth-child(3){animation-delay:.24s}
  .tu-g-card.der .tu-pasos li:nth-child(4), .tu-g-card.izq .tu-pasos li:nth-child(4){animation-delay:.3s}
  .tu-pasos li{transition:color .3s}
  .tu-pasos .ck{transition:background .3s,border-color .3s,box-shadow .3s}
  .tu-pasos li.recien .ck{animation:tu-pop .45s var(--tu-ease) both}
  .tu-pasos li.recien .ck path{stroke-dasharray:24;animation:tu-traza .4s ease .15s both}
  .tu-g-hecha.entra{animation:tu-sube .45s var(--tu-ease) .25s both}
  .tu-g-nav .btn.tu-latido{animation:tu-latido 1.2s ease .45s 2}

  @media (prefers-reduced-motion: reduce){
    .tu *, .tu *::before, .tu *::after{animation-duration:1ms!important;animation-delay:0s!important;animation-iteration-count:1!important;transition-duration:1ms!important}
  }

  /* ── ANCHO DISPONIBLE ────────────────────────────────── */
  @container (max-width: 1080px){
    .tu-entorno{grid-template-columns:minmax(0,1fr)}
    .tu-guia{position:static;display:grid;grid-template-columns:minmax(0,1fr) 260px;align-items:start;order:-1}
  }
  @container (max-width: 860px){
    .tu-portada{grid-template-columns:minmax(0,1fr)}
    .tu-portada-arte{display:none}
    .tu-v-cuerpo{grid-template-columns:minmax(0,1fr)}
    .tu-v-lado{padding:0;border-bottom:1px solid var(--lf-linea);min-width:0}
    .tu-v-marca{display:none}
    .tu-v-nav{flex-direction:row;overflow-x:auto;padding:8px 10px}
    .tu-v-nav .sec{display:none}
    .tu-v-nav button{white-space:nowrap}
    .tu-v-main{margin-top:0;border-radius:0;border-left:0}
    .tu-caja, .tu-dos{grid-template-columns:minmax(0,1fr)}
    .tu-guia{grid-template-columns:minmax(0,1fr)}
    .tu-temario{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @container (max-width: 700px){
    .tu-pasos3{grid-template-columns:minmax(0,1fr);gap:14px}
  }
  @container (max-width: 560px){
    .tu-portada-txt{padding:28px 22px}
    .tu-como{padding:20px 18px}
    .tu-temario{grid-template-columns:minmax(0,1fr)}
    .tu-tema{min-height:0}
    .tu-v-cont, .tu-v-top{padding-left:14px;padding-right:14px}
    .tu-cas{grid-template-columns:96px minmax(0,1fr) 84px;font-size:11.5px}
    .tu-v-barra .tu-v-puntos{display:none}
    .tu-pago{flex-wrap:wrap;row-gap:2px}
    .tu-pago > span:nth-child(2){order:5;flex-basis:100%;font-size:11.5px}
  }
</style>

<div class="tu">

  <!-- ═══ PORTADA ═══ -->
  <div class="tu-inicio" data-vista="portada">
  <section class="tu-portada">
    <div class="tu-portada-txt">
      <span class="tu-eti">Entorno de práctica</span>
      <h2>Aprende LibertyFin usándolo</h2>
      <p>Un LibertyFin de prueba con datos de ejemplo. Recorre cada función con una guía de lecciones, a tu ritmo. Nada de lo que hagas aquí cambia la información de tu negocio.</p>
      <ul class="tu-incluye">
        <li><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
          <span><b>Nueve lecciones cortas</b>, de dar de alta un producto a cerrar tu turno y ver cuánto te queda.</span></li>
        <li><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
          <span><b>Todo conectado</b>: la venta que cobras en Caja aparece en Ventas, en Cobranza y en tu corte.</span></li>
        <li><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
          <span><b>Sin riesgo</b>: puedes reiniciar los datos de práctica cuando quieras.</span></li>
      </ul>
      <div class="tu-portada-acc">
        <button type="button" class="btn btn-primary btn-lg" data-a="ir-entrada" data-boton-inicio>Ingresar al entorno de práctica</button>
        <small data-tiempo>Unos 40 minutos en total</small>
      </div>
    </div>
    <div class="tu-portada-arte" aria-hidden="true">
      <div class="tu-mini-app">
        <div class="tu-mini-barra"><i></i><i></i><i></i></div>
        <div class="tu-mini-cuerpo">
          <div class="tu-mini-nav"><i></i><i class="on"></i><i></i><i></i><i></i><i></i><i></i></div>
          <div class="tu-mini-main"><b></b><b></b><b></b><em></em></div>
        </div>
      </div>
    </div>
  </section>

  <section class="tu-como" aria-labelledby="tu-como-tit">
    <h3 id="tu-como-tit" class="tu-bloque-tit">Cómo funciona</h3>
    <ol class="tu-pasos3">
      <li><span class="n">1</span><div><b>Entra al entorno</b><p>Una copia de LibertyFin con una estética ficticia, separada de la información de tu negocio.</p></div></li>
      <li><span class="n">2</span><div><b>Sigue la guía</b><p>Cada lección te dice qué hacer y marca los pasos conforme los cumples. Lo que hagas en una sigue en la siguiente.</p></div></li>
      <li><span class="n">3</span><div><b>Hazlo en tu sistema</b><p>Al final de cada lección, un enlace te lleva a la misma sección en tu LibertyFin.</p></div></li>
    </ol>
    <p class="tu-como-ayuda">¿Te quedó una duda? <a href="/ayuda" data-parcial>Escríbenos desde Ayuda →</a></p>
  </section>

  <section class="tu-temario-sec" aria-labelledby="tu-temario-tit">
    <div class="tu-bloque-cab">
      <h3 id="tu-temario-tit" class="tu-bloque-tit">Lo que vas a aprender</h3>
      <span class="tu-avance" data-avance></span>
    </div>
    <div class="tu-temario" data-temario></div>
  </section>
  </div>

  <!-- ═══ INGRESO ═══ -->
  <section class="tu-entrada" data-vista="entrada" hidden>
    <form class="tu-login" data-form="entrar" method="dialog" autocomplete="off">
      <div class="tu-login-marca">Libertyfin</div>
      <div class="tu-login-tarjeta">
        <h3>Entorno de práctica</h3>
        <p>Datos de ejemplo de una estética ficticia. Lo que hagas aquí no cambia la información de tu negocio.</p>
        <label for="tu-correo">Correo</label>
        <div class="tu-login-campo">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
          <input id="tu-correo" value="practica@libertyfin.mx" readonly>
        </div>
        <label for="tu-clave">Contraseña</label>
        <div class="tu-login-campo">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
          <input id="tu-clave" type="password" value="practica2026" readonly>
        </div>
        <button type="submit" class="tu-login-boton">Entrar al entorno</button>
        <p class="tu-login-nota">En tu sistema, el campo «Correo» acepta tu correo o tu usuario.</p>
      </div>
      <button type="button" class="tu-login-volver" data-a="ir-portada">Volver</button>
    </form>
  </section>

  <!-- ═══ ENTORNO ═══ -->
  <section class="tu-entorno" data-vista="entorno" hidden>
    <div class="tu-v">
      <div class="tu-v-barra">
        <span class="tu-v-puntos" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="tu-v-titulo"><b>Estética Aurora</b> · Entorno de práctica · datos de ejemplo</span>
        <button type="button" class="btn btn-secondary" data-a="reiniciar">Reiniciar datos</button>
        <button type="button" class="btn btn-secondary" data-a="salir">Salir</button>
      </div>
      <div class="tu-v-cuerpo">
        <aside class="tu-v-lado">
          <div class="tu-v-marca"><span class="g">A</span><span><b>Estética Aurora</b><small>Práctica</small></span></div>
          <nav class="tu-v-nav" data-nav aria-label="Menú del entorno de práctica"></nav>
        </aside>
        <div class="tu-v-main">
          <header class="tu-v-top" data-top></header>
          <div class="tu-v-cont" data-pantalla></div>
        </div>
      </div>
    </div>
    <aside class="tu-guia" data-guia aria-label="Guía de lecciones"></aside>
  </section>
</div>

<script type="application/json" id="tu-atajos"><?= json_encode($atajos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script>
/* ═══════════════════════════════════════════════════════════════════
   EL ENTORNO DE PRÁCTICA

   Corre cada vez que se abre la sección (la navegación sin recarga
   vuelve a ejecutar los <script>): los escuchadores van en document y
   se ponen UNA vez; lo demás se vuelve a pintar con lo que hay.

   Estado en localStorage ('lf-practica-v1'). Nada va al servidor: los
   formularios son method="dialog".
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  var P = window.lfPractica = window.lfPractica || {};
  var CLAVE = 'lf-practica-v1';

  /* ── Utilidades ── */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return [].slice.call((r || document).querySelectorAll(s)); }
  function raiz() { return document.querySelector('.tu'); }
  var fmt = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  function dinero(n) { return fmt.format(+n || 0); }
  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function r2(n) { return Math.round(n * 100) / 100; }
  function dos(n) { return (n < 10 ? '0' : '') + n; }

  /* ── Movimiento ── */
  var MOV = !(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  /* Una cifra que llega a su valor en lugar de aparecer de golpe. */
  function contar(el, desde, hasta, ms, esDinero) {
    var f = function (n) { return esDinero ? dinero(n) : String(Math.round(n)); };
    if (!MOV || desde === hasta) { el.textContent = f(hasta); return; }
    var t0 = performance.now();
    el.textContent = f(desde);
    (function paso(t) {
      var k = Math.min(1, (t - t0) / ms), e = 1 - Math.pow(1 - k, 3);
      el.textContent = f(desde + (hasta - desde) * e);
      if (k < 1 && el.isConnected) requestAnimationFrame(paso); else el.textContent = f(hasta);
    })(t0);
  }
  /* Vuelve a correr una animación de una sola vez (la clase se quita y se pone). */
  function reanimar(el, clase) {
    if (!el || !MOV) return;
    el.classList.remove(clase); void el.offsetWidth; el.classList.add(clase);
  }
  /* El botón muestra que algo está pasando y, al rato, sigue. Un cobro o
     una transferencia que se aplican sin espera se sienten falsos. */
  function ocupado(b, texto, ms, fn) {
    if (!b || b.getAttribute('aria-busy') === 'true') return;
    if (!MOV) { fn(); return; }
    b.setAttribute('aria-busy', 'true'); b.disabled = true;
    b.innerHTML = '<span class="tu-gira" aria-hidden="true"></span>' + esc(texto);
    setTimeout(fn, ms);
  }
  /* Algo que se va sale primero de la vista y luego del estado. */
  function despedir(el, fn, ms) {
    if (!MOV || !el) { fn(); return; }
    if (el.classList.contains('sale')) return;
    el.classList.add('sale'); setTimeout(fn, ms || 190);
  }
  var ICO = {
    panel: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    caja: '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h4"/>',
    ventas: '<path d="M3 6h18M3 12h18M3 18h11"/>',
    cobranza: '<path d="M3 17l5.5-6 4 3.5L21 6"/><path d="M16 6h5v5"/>',
    corte: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/>',
    gastos: '<path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M4 20h16"/>',
    productos: '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M12 12v9M3.5 7.5 12 12l8.5-4.5"/>',
    reportes: '<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
    ajustes: '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
    agenda: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/><path d="M8 13.5h2.5M13.5 13.5H16M8 17h2.5"/>',
    cotizaciones: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
    inventario: '<path d="M5 8h14l1 12H4z"/><path d="M8.5 8V6a3.5 3.5 0 0 1 7 0v2"/>',
    ok: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    atras: '<path d="M19 12H5M11 6l-6 6 6 6"/>'
  };
  function ico(n, t) {
    return '<svg viewBox="0 0 24 24" width="' + (t || 16) + '" height="' + (t || 16) + '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICO[n] || '') + '</svg>';
  }
  var ATAJOS = {};
  try { ATAJOS = JSON.parse(($('#tu-atajos') || {}).textContent || '{}') || {}; } catch (e) {}

  /* ── Datos de ejemplo ── */
  function semilla() {
    return {
      entro: false, leccion: 1, pantalla: 'panel', venta: null, filtro: 'todas', orden: 'cliente',
      productos: [
        { n: 'Corte de cabello', p: 250, c: 60 }, { n: 'Tinte', p: 650, c: 200 }, { n: 'Shampoo 1 L', p: 400, c: 220 },
        { n: 'Manicure', p: 220, c: 50 }, { n: 'Asesoría de imagen', p: 0, c: 0 }
      ],
      ventas: [
        { f: '20261003101500', cli: 'Despacho Ruiz',   tot: 12000, pagos: [{ n: 1, m: 12000, met: 'Transferencia', hace: 13 }] },
        { f: '20261006113005', cli: 'Laura Méndez',    tot: 9500,  pagos: [{ n: 1, m: 1500,  met: 'Transferencia', hace: 10 }] },
        { f: '20261008124510', cli: 'Clínica Sol',     tot: 8400,  pagos: [{ n: 1, m: 8400,  met: 'Tarjeta',       hace: 8 }] },
        { f: '20260905101512', cli: 'Carlos Peña',     tot: 5000,  pagos: [{ n: 1, m: 1500,  met: 'Efectivo',      hace: 40 }] },
        { f: '20260801160210', cli: 'Taller Ruiz',     tot: 2000,  pagos: [{ n: 1, m: 800,   met: 'Efectivo',      hace: 75 }] },
        { f: '20261016093011', cli: 'Público general', tot: 650,   pagos: [{ n: 1, m: 650,   met: 'Efectivo',      hace: 0 }] },
        { f: '20261016101540', cli: 'Mariana López',   tot: 1250,  pagos: [{ n: 1, m: 1250,  met: 'Tarjeta',       hace: 0 }] }
      ],
      gastos: [{ con: 'Renta del local', cat: 'Renta', m: 6000 }, { con: 'Luz', cat: 'Servicios', m: 850 }],
      mods: { agenda: false, cotizaciones: false, inventario: false },
      corte: { fondo: 500, cerrado: null },
      hechos: {}
    };
  }
  function cargar() {
    try { var s = JSON.parse(localStorage.getItem(CLAVE)); if (s && s.ventas) return s; } catch (e) {}
    return semilla();
  }
  function guardar() { try { localStorage.setItem(CLAVE, JSON.stringify(P.s)); } catch (e) {} }
  function nuevaCaja() { return { lineas: [], metodo: 'efectivo', parte: false, deja: '', paga: '', cliente: '', fase: 'armar', recibo: null }; }

  /* ── Cuentas ── */
  function cobrado(v) { return v.pagos.reduce(function (s, p) { return s + (p.cancel ? 0 : p.m); }, 0); }
  function saldo(v) { return r2(v.tot - cobrado(v)); }
  function diasSinAbono(v) {
    var d = null; v.pagos.forEach(function (p) { if (!p.cancel && (d === null || p.hace < d)) d = p.hace; });
    return d === null ? 0 : d;
  }
  function ventaDe(f) { var r = null; P.s.ventas.forEach(function (v) { if (v.f === f) r = v; }); return r; }
  function sigN(v) { return v.pagos.reduce(function (m, p) { return Math.max(m, p.n); }, 0) + 1; }
  function folioAhora() {
    var d = new Date();
    var f = '' + d.getFullYear() + dos(d.getMonth() + 1) + dos(d.getDate()) + dos(d.getHours()) + dos(d.getMinutes()) + dos(d.getSeconds());
    while (ventaDe(f)) f = String(+f + 1);
    return f;
  }
  function cobrosHoy(met) {
    var t = 0;
    P.s.ventas.forEach(function (v) { v.pagos.forEach(function (p) { if (!p.cancel && p.hace === 0 && (!met || met.indexOf(p.met) !== -1)) t += p.m; }); });
    return r2(t);
  }
  function masViejo() {
    var r = null;
    P.s.ventas.forEach(function (v) { if (saldo(v) > 0 && (!r || diasSinAbono(v) > diasSinAbono(r))) r = v; });
    return r;
  }

  /* ═══ LECCIONES ═══ */
  var LECCIONES = [
    { t: 'Da de alta un producto', r: 'Precio, costo y precio libre.', min: 4, d: 'Lo que vendes vive en Productos. Con el costo, el sistema te dice cuánto te queda en cada venta.', real: 'productos',
      pasos: [['ir-productos', 'Abre <b>Productos</b> en el menú lateral', 'productos'],
              ['prod-costo', 'Da de alta un producto con precio y costo'],
              ['prod-libre', 'Da de alta otro con precio en 0: queda como <b>precio libre</b> y el monto se escribe al cobrar']],
      nota: 'Lo que des de alta aquí aparece en la Caja de las siguientes lecciones.' },
    { t: 'Cobra una venta', r: 'Ticket, forma de pago y cambio.', min: 6, d: 'Caja es la pantalla del día a día: armas el ticket, eliges cómo paga el cliente y confirmas.', real: 'caja',
      pasos: [['ir-caja', 'Abre <b>Caja</b>', 'caja'],
              ['caja-dos', 'Agrega dos productos distintos al ticket'],
              ['caja-cliente', 'Escribe el nombre del cliente'],
              ['caja-cambio', 'Cobra en efectivo: escribe con cuánto paga el cliente (aunque sea lo exacto) y confirma']],
      nota: 'En tu sistema, abre tu turno en Corte de caja antes de cobrar; si no, la venta no entra a tu corte.' },
    { t: 'Deja un saldo pendiente', r: 'Pagos parciales y saldo por cobrar.', min: 4, d: 'Si el cliente paga una parte, el resto queda como saldo en la misma venta y se cobra después en abonos.', real: 'caja',
      pasos: [['caja-parte', 'En Caja, agrega un producto, elige <b>Solo una parte</b> y escribe cuánto deja hoy', 'caja'],
              ['caja-saldo', 'Confirma la venta con su saldo pendiente']],
      nota: 'La comisión de esa venta se libera conforme el cliente paga.' },
    { t: 'Registra un abono', r: 'Subfolios y cuadre contra tu banco.', min: 5, d: 'Cada pago tiene su subfolio: -01 el primero, -02 el segundo. Así cuadras cada depósito contra tu banco.', real: 'ventas',
      pasos: [['ir-ventas', 'Abre <b>Ventas</b> y filtra las que tienen saldo', 'ventas'],
              ['venta-abrir', 'Abre una venta con saldo'],
              ['abono', 'Registra un abono y observa su subfolio']],
      nota: 'No se puede abonar más que el saldo. Un pago cancelado conserva su número: el siguiente no lo reutiliza.' },
    { t: 'Organiza tu cobranza', r: 'A quién cobrarle primero.', min: 4, d: 'Cobranza te dice a quién llamar. Los días se cuentan desde el último abono, no desde la fecha de la venta.', real: 'cobranza',
      pasos: [['ir-cobranza', 'Abre <b>Cobranza</b>', 'cobranza'],
              ['cob-viejo', 'Ordena por <b>Lo más viejo primero</b>'],
              ['cob-abrir', 'Abre la venta del cliente que lleva más días sin abonar']],
      nota: 'Un cliente que abona seguido no aparece como urgente aunque deba mucho.' },
    { t: 'Cobra en línea', r: 'Liga de tarjeta, CLABE y referencia de tienda.', min: 5, d: 'Con los cobros en línea activos, generas una liga de tarjeta, una CLABE o una referencia de tienda, y el pago se aplica solo.', real: 'ligas',
      pasos: [['liga-generar', 'En una venta con saldo, usa <b>Cobrar en línea</b> y genera la CLABE de SPEI', 'ventas'],
              ['liga-pago', 'Simula la transferencia y comprueba que el abono se registró solo']],
      nota: 'Si transfiere de menos, entra como abono y queda saldo. Si transfiere de más, se rechaza y el banco le regresa su dinero.' },
    { t: 'Cierra tu turno', r: 'Cuadrar el efectivo del día.', min: 4, d: 'Al final del día cuentas tu efectivo y el sistema te dice si cuadra. Tarjeta y transferencia no pasan por el cajón.', real: 'corte',
      pasos: [['ir-corte', 'Abre <b>Corte de caja</b>', 'corte'],
              ['corte-cerrar', 'Escribe cuánto hay realmente y cierra la caja; si hay diferencia, explica a qué se debe']],
      nota: 'Cada usuario tiene su propio turno.' },
    { t: 'Revisa cuánto te queda', r: 'Lo cobrado menos gastos, comisiones e IVA.', min: 5, d: 'Reportes parte de lo cobrado y descuenta gastos, comisiones e IVA para decirte lo que realmente queda.', real: 'reportes',
      pasos: [['ir-reportes', 'Abre <b>Reportes</b> y ubica la cifra que queda', 'reportes'],
              ['gasto', 'Registra un gasto en <b>Gastos</b>', 'gastos'],
              ['reportes-ver', 'Vuelve a Reportes y observa cómo cambió', 'reportes']],
      nota: 'Los gastos de operación de una venta se capturan en Caja y bajan la comisión de esa venta.' },
    { t: 'Ajusta el sistema a tu negocio', r: 'Herramientas que se encienden según tu negocio.', min: 3, d: 'Las herramientas opcionales se encienden en Ajustes y aparecen en el menú solo si las usas.', real: 'ajustes',
      pasos: [['ir-ajustes', 'Abre <b>Ajustes</b>', 'ajustes'],
              ['mod-on', 'Enciende una herramienta y observa el menú lateral']],
      nota: 'Las que tu plan no incluye aparecen con candado.' }
  ];
  function hecho(k) { return !!P.s.hechos[k]; }
  function completa(i) { return LECCIONES[i].pasos.every(function (p) { return hecho(p[0]); }); }
  function cumplir(k) {
    if (hecho(k)) return;
    P.s.hechos[k] = true; guardar(); pintarGuia(); pintarNav();
  }
  /* El destino del primer paso pendiente: ahí se enciende la pista del menú. */
  function destinoPendiente() {
    var l = LECCIONES[P.s.leccion - 1], d = null;
    l.pasos.some(function (p) { if (!hecho(p[0])) { d = p[2] || null; return true; } return false; });
    return d;
  }

  /* ═══ VISTAS ═══ */
  /* La entrada se marca solo cuando la vista CAMBIA y se quita al
     terminar: si se quedara, el menú repetiría su cascada cada vez que
     se vuelve a pintar (por ejemplo, al encender un módulo). */
  function vista(nombre) {
    var cambia = P.vista !== nombre;
    P.vista = nombre;
    $$('[data-vista]', raiz()).forEach(function (v) {
      v.hidden = v.dataset.vista !== nombre;
      /* El ingreso se queda desvanecido al entrar; al volver, aparece entero. */
      if (!v.hidden) $$('.sale', v).forEach(function (x) { x.classList.remove('sale'); });
      if (!v.hidden && cambia && MOV) {
        reanimar(v, 'tu-vista-entra');
        clearTimeout(v._tuFin);
        v._tuFin = setTimeout(function () { v.classList.remove('tu-vista-entra'); }, 2000);
      }
    });
  }
  function aplicarHash() {
    var h = location.hash, m = /^#leccion-(\d+)$/.exec(h);
    if (m) {
      P.s.entro = true;
      P.s.leccion = Math.max(1, Math.min(LECCIONES.length, +m[1]));
      guardar(); vista('entorno'); pintarTodo();
    } else if (h === '#entrar') vista('entrada');
    else if (P.s.entro && h !== '#inicio') irLeccion(P.s.leccion, true);
    else { pintarPortada(); vista('portada'); }
  }
  function irLeccion(n, reemplazar) {
    var h = '#leccion-' + n;
    if (location.hash === h) { aplicarHash(); return; }
    if (reemplazar) { try { history.replaceState(history.state, '', location.pathname + location.search + h); } catch (e) {} aplicarHash(); }
    else location.hash = h;
  }

  /* ═══ PORTADA: temario y avance ═══ */
  function pintarPortada() {
    var r = raiz(), t = $('[data-temario]', r); if (!t) return;
    var hechas = 0, falta = 0, empezado = Object.keys(P.s.hechos).length > 0;
    t.innerHTML = LECCIONES.map(function (l, k) {
      var ok = completa(k), actual = empezado && !ok && P.s.leccion === k + 1;
      if (ok) hechas++; else falta += l.min;
      return '<button type="button" class="tu-tema' + (ok ? ' hecha' : '') + (actual ? ' actual' : '') + '" data-a="empezar" data-v="' + (k + 1) + '" style="--i:' + k + '">'
        + '<span class="tu-tema-cab"><span class="n">' + (ok ? ico('ok', 13) : (k + 1)) + '</span><b>' + l.t + '</b></span>'
        + '<small>' + l.r + '</small>'
        + '<span class="tu-tema-pie"><span class="estado">' + (ok ? 'Completada' : actual ? 'Aquí te quedaste' : l.min + ' min') + '</span>'
        + '<em>' + (ok ? 'Repasar' : actual ? 'Continuar' : 'Empezar') + ' →</em></span></button>';
    }).join('');
    var av = $('[data-avance]', r);
    if (av) av.textContent = hechas ? hechas + ' de ' + LECCIONES.length + (hechas === 1 ? ' completada' : ' completadas') : LECCIONES.length + ' lecciones';
    /* Si ya avanzó, el botón principal lo regresa a donde se quedó. */
    var b = $('[data-boton-inicio]', r), tm = $('[data-tiempo]', r);
    if (b) b.textContent = empezado && hechas < LECCIONES.length ? 'Continuar en la lección ' + P.s.leccion : 'Ingresar al entorno de práctica';
    if (tm) tm.textContent = !empezado ? 'Unos 40 minutos en total' : hechas === LECCIONES.length ? 'Completaste todas las lecciones' : 'Te faltan unos ' + falta + ' minutos';
  }

  /* ═══ MENÚ ═══ */
  var NOMBRES = { panel: 'Panel', caja: 'Caja', ventas: 'Ventas', cobranza: 'Cobranza', corte: 'Corte de caja', agenda: 'Agenda',
                  cotizaciones: 'Cotizaciones', productos: 'Productos', inventario: 'Inventario', gastos: 'Gastos', reportes: 'Reportes', ajustes: 'Ajustes' };
  /* Los botones se crean solo cuando cambia la lista (un módulo que se
     enciende); lo demás es mover la marca de la sección activa, que se
     desliza de una a otra en lugar de saltar. */
  function pintarNav(nuevo) {
    var nav = $('[data-nav]', raiz()); if (!nav) return;
    var m = P.s.mods, dest = destinoPendiente();
    var grupos = [['Operación', ['panel', 'caja'].concat(m.agenda ? ['agenda'] : [], ['ventas'], m.cotizaciones ? ['cotizaciones'] : [], ['cobranza', 'corte'])],
                  ['Administración', ['gastos', 'productos'].concat(m.inventario ? ['inventario'] : [], ['reportes', 'ajustes'])]];
    var firma = JSON.stringify(grupos), nueva = nav.dataset.firma !== firma, n = 0;
    if (nueva) {
      nav.innerHTML = '<i class="tu-v-ind quieto" aria-hidden="true"></i>' + grupos.map(function (g) {
        return '<div class="sec" style="--i:' + (n++) + '">' + g[0] + '</div>' + g[1].map(function (k) {
          return '<button type="button" data-a="pantalla" data-v="' + k + '" style="--i:' + (n++) + '"' + (nuevo === k ? ' class="nuevo"' : '') + '>' + ico(k) + NOMBRES[k] + '</button>';
        }).join('');
      }).join('');
      nav.dataset.firma = firma;
      nav.classList.add('con-ind');
    }
    $$('button[data-v]', nav).forEach(function (b) {
      var k = b.dataset.v, p = $('.pista', b), quiere = dest === k && P.s.pantalla !== k;
      b.classList.toggle('on', P.s.pantalla === k);
      if (quiere && !p) b.insertAdjacentHTML('beforeend', '<span class="pista" aria-label="Siguiente paso"></span>');
      if (!quiere && p) p.remove();
    });
    moverMarca(nav, nueva);
  }
  function moverMarca(nav, sinTransicion) {
    var ind = $('.tu-v-ind', nav), b = $('button.on', nav);
    if (!ind) return;
    if (!b || !b.offsetWidth) { ind.style.opacity = '0'; return; }
    if (sinTransicion) ind.classList.add('quieto');
    ind.style.opacity = '1';
    ind.style.width = b.offsetWidth + 'px';
    ind.style.height = b.offsetHeight + 'px';
    ind.style.transform = 'translate(' + b.offsetLeft + 'px,' + b.offsetTop + 'px)';
    void ind.offsetWidth;
    ind.classList.remove('quieto');
    /* En pantallas angostas el menú es una fila que se desplaza. */
    if (nav.scrollWidth > nav.clientWidth + 2) {
      var x = b.offsetLeft - (nav.clientWidth - b.offsetWidth) / 2;
      try { nav.scrollTo({ left: Math.max(0, x), behavior: MOV ? 'smooth' : 'auto' }); } catch (e) { nav.scrollLeft = x; }
    }
  }

  /* ═══ GUÍA ═══ */
  /* Varias acciones piden pintar la guía en el mismo instante; se pinta
     una sola vez por cuadro, para que lo recién cumplido se anime una vez
     y no se pierda en un segundo pintado. */
  function pintarGuia() {
    if (P.guiaPend) return;
    P.guiaPend = true;
    /* El cuadro siguiente o, si el navegador lo retrasa, un instante después. */
    var listo = false, ya = function () { if (listo) return; listo = true; P.guiaPend = false; pintarGuiaYa(); };
    requestAnimationFrame(ya); setTimeout(ya, 60);
  }
  function pintarGuiaYa() {
    var g = $('[data-guia]', raiz()); if (!g) return;
    var i = P.s.leccion - 1, l = LECCIONES[i], total = LECCIONES.length;
    var hechas = LECCIONES.filter(function (x, k) { return completa(k); }).length;
    var etqHechas = hechas === 1 ? '1 completada' : hechas + ' completadas';
    var pct = hechas / total * 100;
    /* Lo que cambió desde el último pintado de ESTA lección. */
    var otra = P.guiaLeccion !== i, antes = otra ? null : (P.guiaHechos || {});
    var dir = otra && P.guiaLeccion != null ? (i > P.guiaLeccion ? ' der' : ' izq') : '';
    var recienCompleta = !!antes && completa(i) && !P.guiaCompleta;
    var abierta = $('.tu-g-lista', g); abierta = !!(abierta && abierta.open);
    var primeraPend = -1;
    l.pasos.forEach(function (p, k) { if (primeraPend === -1 && !hecho(p[0])) primeraPend = k; });
    var real = ATAJOS[l.real];
    g.innerHTML = '<div class="card tu-g-card' + dir + '"><div class="card-body">'
      + '<div class="tu-g-n"><span>Lección ' + (i + 1) + ' de ' + total + '</span><span>' + etqHechas + '</span></div>'
      + '<div class="tu-g-prog"><i style="width:' + (P.guiaPct == null ? pct : P.guiaPct) + '%"></i></div>'
      + '<h4>' + l.t + '</h4><p class="d">' + l.d + '</p>'
      + '<ol class="tu-pasos">' + l.pasos.map(function (p, k) {
          var h = hecho(p[0]), recien = h && antes && !antes[p[0]];
          return '<li class="' + (h ? 'hecho' : (k === primeraPend ? 'actual' : '')) + (recien ? ' recien' : '') + '"><span class="ck">' + (h ? ico('ok', 13) : '') + '</span><span>' + p[1] + '</span></li>';
        }).join('') + '</ol>'
      + (completa(i) ? '<div class="tu-g-hecha' + (recienCompleta ? ' entra' : '') + '">' + ico('ok', 15) + 'Lección completada</div>' : '')
      + '<div class="tu-g-nota">' + l.nota + '</div>'
      + (real ? '<a class="tu-g-real" href="' + esc(real.url) + '" data-parcial>Ir a ' + esc(real.txt) + ' en tu sistema →</a>' : '')
      + '<div class="tu-g-nav">'
      + (i > 0 ? '<button type="button" class="btn btn-secondary btn-sm" data-a="leccion" data-v="' + i + '">Anterior</button>' : '')
      + (i < total - 1 ? '<button type="button" class="btn ' + (completa(i) ? 'btn-primary' : 'btn-secondary') + (recienCompleta ? ' tu-latido' : '') + ' btn-sm" data-a="leccion" data-v="' + (i + 2) + '">Siguiente lección</button>'
         : (hechas === total ? '<button type="button" class="btn btn-primary' + (recienCompleta ? ' tu-latido' : '') + ' btn-sm" data-a="salir">Terminar</button>' : ''))
      + '</div></div></div>'
      + '<div class="card"><div class="card-body"><details class="tu-g-lista"' + (abierta ? ' open' : '') + '><summary>Todas las lecciones</summary><ol>'
      + LECCIONES.map(function (x, k) {
          return '<li><button type="button" data-a="leccion" data-v="' + (k + 1) + '" class="' + (k === i ? 'on' : '') + (completa(k) ? ' hecha' : '') + '">'
               + '<span class="n">' + (completa(k) ? ico('ok', 11) : (k + 1)) + '</span>' + x.t + '</button></li>';
        }).join('') + '</ol></details></div></div>';
    /* La barra parte de donde estaba y avanza. */
    var barra = $('.tu-g-prog i', g);
    if (barra && P.guiaPct != null && P.guiaPct !== pct) { void barra.offsetWidth; barra.style.width = pct + '%'; }
    P.guiaPct = pct; P.guiaLeccion = i; P.guiaCompleta = completa(i);
    P.guiaHechos = {}; for (var k in P.s.hechos) P.guiaHechos[k] = true;
  }

  /* ═══ PANTALLAS ═══ */
  var PANT = {};
  function cabecera(t, sub) { $('[data-top]', raiz()).innerHTML = '<h3>' + ico(P.s.pantalla, 17) + esc(t) + '</h3>' + (sub ? '<p>' + esc(sub) + '</p>' : ''); }
  /* Una cifra con data-n se anima: desde cero al entrar a la pantalla y
     desde su valor anterior cuando cambia (un abono que baja el saldo). */
  function cifra(n, esDinero, clave) {
    return '<span data-n="' + n + '"' + (esDinero ? ' data-dinero' : '') + ' data-k="' + esc(clave) + '">' + (esDinero ? dinero(n) : n) + '</span>';
  }
  function stat(valor, etq, meta) {
    var v = typeof valor === 'number' ? cifra(valor, true, etq) : valor;
    return '<div class="stat-card"><div class="stat-value">' + v + '</div><div class="stat-label">' + etq + '</div>' + (meta ? '<div class="stat-meta">' + meta + '</div>' : '') + '</div>';
  }
  function badgeVenta(v) {
    var s = saldo(v);
    return s <= 0.009 ? '<span class="badge bg-success">Liquidada</span>' : '<span class="badge bg-warning">Debe ' + dinero(s) + '</span>';
  }

  PANT.panel = function () {
    cabecera('Panel', 'Lo de hoy y lo que falta por cobrar');
    var porCobrar = P.s.ventas.reduce(function (t, v) { return t + Math.max(0, saldo(v)); }, 0);
    var mov = [];
    P.s.ventas.forEach(function (v) { v.pagos.forEach(function (p) { if (!p.cancel) mov.push({ v: v, p: p }); }); });
    mov.sort(function (a, b) { return a.p.hace - b.p.hace || (b.v.f > a.v.f ? 1 : -1); });
    return '<div class="tu-cifras">' + stat(r2(cobrosHoy()), 'Entró hoy') + stat(r2(cobrosHoy(['Efectivo'])), 'En efectivo')
      + stat(r2(porCobrar), 'Por cobrar') + stat(cifra(P.s.ventas.filter(function (v) { return v.pagos.some(function (p) { return p.hace === 0; }); }).length, false, 'hoy'), 'Ventas con cobro hoy') + '</div>'
      + '<div class="card"><div class="card-header">Últimos movimientos</div><div class="card-body" style="padding-top:4px;padding-bottom:4px">'
      + mov.slice(0, 6).map(function (x) {
          return '<div class="tu-pago"><span class="f">' + x.v.f + '<em>-' + dos(x.p.n) + '</em></span><span>' + esc(x.v.cli) + ' · ' + esc(x.p.met) + '</span>'
               + '<span class="m">' + dinero(x.p.m) + '</span></div>';
        }).join('') + '</div></div>';
  };

  PANT.productos = function () {
    cabecera('Productos', 'Lo que vendes y su precio');
    return '<div class="card" style="margin-bottom:14px"><div class="card-header">Dar de alta un producto</div><div class="card-body">'
      + '<form data-form="producto" method="dialog" autocomplete="off"><div class="tu-form">'
      + '<div><label class="form-label">Nombre</label><input class="form-control form-control-sm" name="n" placeholder="Ej. Peinado de evento"></div>'
      + '<div><label class="form-label">Precio</label><input class="form-control form-control-sm" name="p" type="number" min="0" step="1" data-in="prod"></div>'
      + '<div><label class="form-label">Costo <span style="font-weight:400;color:var(--lf-tinta-4)">(opcional)</span></label><input class="form-control form-control-sm" name="c" type="number" min="0" step="1" data-in="prod"></div>'
      + '<div><button class="btn btn-primary" type="submit" style="width:100%">Dar de alta</button></div></div>'
      + '<p class="tu-msj" data-queda></p><p class="tu-msj" data-msj></p></form></div></div>'
      + '<div class="card"><div class="card-header">Catálogo<span class="badge bg-secondary">' + P.s.productos.length + ' productos</span></div>'
      + '<div class="table-responsive"><table class="table"><thead><tr><th>Producto</th><th class="text-end">Precio</th><th class="text-end">Costo</th><th class="text-end">Te queda</th></tr></thead><tbody>'
      + P.s.productos.map(function (p, i) {
          return '<tr' + (P.filaNueva === 'p' + i ? ' class="tu-fila-nueva"' : '') + '><td><strong>' + esc(p.n) + '</strong></td><td class="text-end tu-mono">' + (p.p > 0 ? dinero(p.p) : '<span class="badge bg-secondary">Precio libre</span>') + '</td>'
               + '<td class="text-end tu-mono">' + (p.c > 0 ? dinero(p.c) : '—') + '</td><td class="text-end tu-mono">' + (p.p > 0 && p.c > 0 ? dinero(p.p - p.c) : '—') + '</td></tr>';
        }).join('') + '</tbody></table></div></div>';
  };

  /* Caja */
  function cuentasCaja() {
    var c = P.caja, total = 0;
    c.lineas.forEach(function (l) { total += num(l.p) * l.cant; });
    total = r2(total);
    var hoy = (c.metodo === 'efectivo' && c.parte) ? Math.max(0, Math.min(total, num(c.deja))) : total;
    return { total: total, hoy: r2(hoy), saldo: r2(total - hoy) };
  }
  PANT.caja = function () {
    cabecera('Caja', 'Venta nueva · Matriz');
    var c = P.caja;
    if (c.fase === 'recibo') return reciboHtml();
    if (c.fase === 'revisa') return revisaHtml();
    var k = cuentasCaja(), ef = c.metodo === 'efectivo';
    var lineas = c.lineas.length ? c.lineas.map(function (l, i) {
      return '<div class="tu-linea' + (P.lineaNueva === i ? ' nueva' : '') + '"><span class="nom" title="' + esc(l.n) + '">' + esc(l.n) + '</span>'
        + (l.libre ? '<input class="form-control form-control-sm" type="number" min="0" step="1" placeholder="Precio" data-in="libre" data-v="' + i + '" value="' + (l.p || '') + '">'
                   : '<span class="imp tu-mono">' + dinero(l.p * l.cant) + '</span>')
        + '<span class="tu-cant"><button type="button" data-a="menos" data-v="' + i + '" aria-label="Uno menos">−</button>' + l.cant + '<button type="button" data-a="mas" data-v="' + i + '" aria-label="Uno más">+</button>'
        + (l.libre || l.cant === 1 ? '' : '<span style="color:var(--lf-tinta-4)">× ' + dinero(l.p) + '</span>') + '</span>'
        + '<button type="button" class="tu-quitar" data-a="quitar" data-v="' + i + '">Quitar</button></div>';
    }).join('') : '<div class="tu-vacio">Toca un producto para agregarlo.</div>';
    return '<div class="tu-caja">'
      + '<div class="card"><div class="card-header">Productos<span class="tu-sub-caja">Toca uno para agregarlo</span></div><div class="card-body"><div class="tu-prods">'
      + P.s.productos.map(function (p, i) {
          return '<button type="button" class="tu-prod' + (p.p > 0 ? '' : ' libre') + '" data-a="agregar" data-v="' + i + '"><span>' + esc(p.n) + '</span><b>' + (p.p > 0 ? dinero(p.p) : 'Precio libre') + '</b></button>';
        }).join('') + '</div></div></div>'
      + '<div class="card tu-ticket"><div class="card-header">Ticket<span class="tu-sub-caja" data-conceptos>' + c.lineas.reduce(function (s, l) { return s + l.cant; }, 0) + ' conceptos</span></div><div class="card-body">'
      + '<div class="tu-lineas">' + lineas + '</div>'
      + '<div><label class="form-label">Cliente (opcional)</label><input class="form-control form-control-sm" data-in="cliente" value="' + esc(c.cliente) + '" placeholder="Nombre del cliente" autocomplete="off"></div>'
      + '<div><label class="form-label">¿Cómo paga?</label><div class="tu-seg">'
      + ['efectivo', 'tarjeta', 'spei'].map(function (m) { return '<button type="button" data-a="metodo" data-v="' + m + '" class="' + (c.metodo === m ? 'on' + popDe('metodo', m) : '') + '">' + { efectivo: 'Efectivo', tarjeta: 'Tarjeta', spei: 'SPEI' }[m] + '</button>'; }).join('')
      + '</div></div>'
      + (ef ? (c.parte
          ? '<div><label class="form-label">¿Cuánto deja hoy?</label><div class="tu-acc"><input class="form-control form-control-sm" style="flex:1" type="number" min="0" step="1" data-in="deja" value="' + esc(c.deja) + '">'
            + '<button type="button" class="btn btn-secondary btn-sm" data-a="deja" data-v="50">50%</button><button type="button" class="btn btn-secondary btn-sm" data-a="deja" data-v="100">Completo</button></div>'
            + '<p class="tu-msj aviso" data-saldo-caja>' + (k.total > 0 && k.saldo > 0 ? 'Queda un saldo de ' + dinero(k.saldo) + '.' : '') + '</p></div>'
          : '<div class="tu-sub-caja">Se cobra completo <b class="tu-mono" data-total2>' + dinero(k.total) + '</b> · <button type="button" class="tu-link" data-a="parte">Solo una parte</button></div>')
          + '<div><label class="form-label">¿Con cuánto paga? <span style="font-weight:400;color:var(--lf-tinta-4)">(opcional, para el cambio)</span></label>'
          + '<input class="form-control form-control-sm" type="number" min="0" step="1" data-in="paga" value="' + esc(c.paga) + '"><div class="tu-cambio" data-cambio style="margin-top:6px"></div></div>'
        : '<label class="tu-check"><input type="checkbox" checked disabled> Ya me pagaron — no generar liga de pago</label>')
      + '<div class="tu-total"><span>Total</span><b data-total>' + dinero(k.total) + '</b></div>'
      + '<button type="button" class="btn btn-primary btn-lg" data-a="cobrar"' + (k.total > 0 ? '' : ' disabled') + '>' + textoCobrar(k) + '</button>'
      + '</div></div></div>';
  };
  function textoCobrar(k) { return k.total <= 0 ? 'Cobrar' : (k.hoy > 0 ? 'Cobrar ' + dinero(k.hoy) : 'Registrar sin cobro'); }
  function refrescarCaja() {
    var r = raiz(), c = P.caja, k = cuentasCaja();
    var t = $('[data-total]', r);
    if (t) {
      var antes = P.totalVisto == null ? k.total : P.totalVisto;
      if (antes !== k.total) { contar(t, antes, k.total, 320, true); reanimar(t, 'tu-salta'); } else t.textContent = dinero(k.total);
      P.totalVisto = k.total;
    }
    var t2 = $('[data-total2]', r); if (t2) t2.textContent = dinero(k.total);
    var b = $('[data-a="cobrar"]', r); if (b) { b.disabled = k.total <= 0; b.textContent = textoCobrar(k); }
    var sc = $('[data-saldo-caja]', r); if (sc) sc.textContent = k.total > 0 && k.saldo > 0 ? 'Queda un saldo de ' + dinero(k.saldo) + '.' : '';
    var cam = $('[data-cambio]', r), paga = num(c.paga);
    if (cam) {
      if (c.paga !== '' && k.hoy > 0) { cam.textContent = paga >= k.hoy ? 'Cambio ' + dinero(paga - k.hoy) : 'Faltan ' + dinero(k.hoy - paga); cam.style.color = paga >= k.hoy ? 'var(--lf-brand-2)' : 'var(--lf-rojo)'; }
      else cam.textContent = '';
    }
  }
  function revisaHtml() {
    var c = P.caja, k = cuentasCaja(), av = [], paga = num(c.paga), met = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', spei: 'SPEI' }[c.metodo];
    var sinPrecio = c.lineas.some(function (l) { return l.libre && !(num(l.p) > 0); });
    if (sinPrecio) av.push('Hay un producto de precio libre sin precio: regresa y escríbelo.');
    if (!c.cliente.trim()) av.push('No elegiste cliente: la venta saldrá como Público general.');
    if (k.saldo > 0) av.push('Quedará un saldo de ' + dinero(k.saldo) + ' por cobrar.');
    if (c.metodo === 'efectivo' && c.paga !== '' && paga < k.hoy) av.push('Lo que entrega (' + dinero(paga) + ') no alcanza.');
    var f = [['Cliente', esc(c.cliente.trim() || 'Público general')], ['Forma de pago', met], ['Se cobra hoy', dinero(k.hoy)]];
    if (k.saldo > 0) f.push(['Queda debiendo', dinero(k.saldo)]);
    if (c.metodo === 'efectivo' && c.paga !== '' && paga >= k.hoy && k.hoy > 0) f.push(['Entrega', dinero(paga)], ['Cambio', dinero(paga - k.hoy)]);
    return '<div class="card tu-revisa" style="max-width:520px"><div class="card-header">Revisa antes de registrar</div><div class="card-body">'
      + '<p class="tu-sub-caja" style="margin:0 0 12px">Una vez registrada, la venta no puede eliminarse: solo cancelarse con un motivo.</p>'
      + (av.length ? '<ul class="tu-avisos">' + av.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '')
      + '<div class="tu-filas">' + c.lineas.map(function (l) { return '<div><span>' + l.cant + ' × ' + esc(l.n) + '</span><b class="tu-mono">' + dinero(num(l.p) * l.cant) + '</b></div>'; }).join('')
      + '<div class="grande"><span>Total</span><b>' + dinero(k.total) + '</b></div>'
      + f.map(function (x) { return '<div><span>' + x[0] + '</span><b>' + x[1] + '</b></div>'; }).join('') + '</div>'
      + '<div class="tu-acc"><button type="button" class="btn btn-secondary" data-a="volver-ticket">Revisar</button>'
      + '<button type="button" class="btn btn-primary" data-a="confirmar"' + (sinPrecio ? ' disabled' : '') + '>Confirmar y registrar</button></div></div></div>';
  }
  function reciboHtml() {
    var r = P.caja.recibo;
    return '<div class="card tu-recibo"><div class="card-body">'
      + '<div class="r-tit">Estética Aurora</div><div class="r-pie">Venta ' + r.f + '</div><div class="r-sep"></div>'
      + r.lineas.map(function (l) { return '<div class="r-fila"><span>' + l.cant + ' × ' + esc(l.n) + '</span><span>' + dinero(l.p * l.cant) + '</span></div>'; }).join('')
      + '<div class="r-sep"></div><div class="r-fila"><b>Total</b><b>' + dinero(r.total) + '</b></div>'
      + (r.hoy > 0 ? '<div class="r-fila"><span>Pagado · ' + r.f + '-01</span><span>' + dinero(r.hoy) + '</span></div>' : '')
      + (r.cambio > 0 ? '<div class="r-fila"><span>Cambio</span><span>' + dinero(r.cambio) + '</span></div>' : '')
      + (r.saldo > 0 ? '<div class="r-fila"><span>Saldo pendiente</span><span>' + dinero(r.saldo) + '</span></div>' : '')
      + '<div class="r-sep"></div><div class="r-pie">Cliente: ' + esc(r.cli) + (r.saldo > 0 ? '<br>Este comprobante ampara un pago parcial.' : '') + '</div>'
      + '<div class="tu-acc" style="justify-content:center;margin-top:8px;font-family:var(--lf-font)"><button type="button" class="btn btn-primary btn-sm" data-a="siguiente-venta">Siguiente venta</button>'
      + '<button type="button" class="btn btn-secondary btn-sm" data-a="pantalla" data-v="ventas">Ver en Ventas</button></div></div></div>';
  }

  /* Ventas */
  PANT.ventas = function () {
    if (P.s.venta && !P.s.desde && ventaDe(P.s.venta)) return detalleVenta(ventaDe(P.s.venta));
    cabecera('Ventas', 'Lo vendido y lo cobrado');
    var f = P.s.filtro, lista = P.s.ventas.slice().sort(function (a, b) { return a.f < b.f ? 1 : -1; }).filter(function (v) {
      return f === 'todas' || (f === 'saldo' ? saldo(v) > 0 : saldo(v) <= 0);
    });
    var tot = P.s.ventas.reduce(function (t, v) { return t + v.tot; }, 0), cob = P.s.ventas.reduce(function (t, v) { return t + cobrado(v); }, 0);
    return '<div class="tu-cifras">' + stat(r2(cob), 'Cobrado') + stat(r2(tot - cob), 'Por cobrar') + stat(r2(tot), 'Vendido') + '</div>'
      + '<div class="card"><div class="card-header">Movimientos<div class="tu-seg">'
      + [['todas', 'Todas'], ['saldo', 'Con saldo'], ['liq', 'Liquidadas']].map(function (x) { return '<button type="button" data-a="filtro" data-v="' + x[0] + '" class="' + (f === x[0] ? 'on' + popDe('filtro', x[0]) : '') + '">' + x[1] + '</button>'; }).join('')
      + '</div></div><div class="table-responsive"><table class="table"><thead><tr><th>Venta</th><th>Cliente</th><th class="text-end">Total</th><th>Estado</th><th></th></tr></thead><tbody>'
      + (lista.length ? lista.map(function (v) {
          return '<tr><td class="tu-mono">' + v.f + '</td><td><strong>' + esc(v.cli) + '</strong></td><td class="text-end tu-mono">' + dinero(v.tot) + '</td><td>' + badgeVenta(v) + '</td>'
               + '<td class="text-end"><button type="button" class="btn btn-secondary btn-sm" data-a="abrir-venta" data-v="' + v.f + '">Abrir</button></td></tr>';
        }).join('') : '<tr><td colspan="5"><div class="tu-vacio">No hay ventas con ese filtro.</div></td></tr>')
      + '</tbody></table></div></div>';
  };
  function detalleVenta(v) {
    cabecera('Venta ' + v.f, v.cli);
    var s = saldo(v), L = P.liga || {};
    var pagos = v.pagos.map(function (p, i) {
      return '<div class="tu-pago' + (p.cancel ? ' cancelado' : '') + (P.ultimoPago === v.f + '-' + p.n ? ' nuevo' : '') + '"><span class="f">' + v.f + '<em>-' + dos(p.n) + '</em></span>'
        + '<span>' + esc(p.met) + (p.cancel ? ' · cancelado' : (p.hace === 0 ? ' · hoy' : ' · hace ' + p.hace + ' días')) + '</span><span class="m">' + dinero(p.m) + '</span>'
        + (p.cancel ? '' : (P.cancelando === i
            ? '<button type="button" class="btn btn-danger btn-sm" data-a="cancelar-si" data-v="' + i + '">Cancelar pago</button><button type="button" class="tu-link" data-a="cancelar-no">No</button>'
            : '<button type="button" class="tu-quitar" data-a="cancelar" data-v="' + i + '" title="Cancelar pago" aria-label="Cancelar pago"><span class="lf-signo">&times;</span></button>'))
        + '</div>';
    }).join('');
    var liga = '';
    if (s > 0) {
      liga = '<div class="card"><div class="card-header">Cobrar en línea</div><div class="card-body"><div class="tu-seg" style="margin-bottom:12px">'
        + [['spei', 'SPEI'], ['tarjeta', 'Tarjeta'], ['tienda', 'Tienda']].map(function (x) { return '<button type="button" data-a="liga-met" data-v="' + x[0] + '" class="' + ((L.met || 'spei') === x[0] ? 'on' + popDe('liga-met', x[0]) : '') + '">' + x[1] + '</button>'; }).join('')
        + '</div>';
      if (!L.generada || L.f !== v.f) {
        liga += '<p class="tu-sub-caja" style="margin:0 0 10px">Se cobra el saldo completo: ' + dinero(s) + '.</p><button type="button" class="btn btn-primary btn-sm" data-a="liga-generar">Generar</button>';
      } else {
        var met = L.met || 'spei';
        liga += '<div class="tu-liga' + (P.ligaNueva ? ' entra' : '') + '" style="margin-top:0">' + (met === 'tarjeta' ? qr() : '') + '<div style="flex:1;min-width:190px">'
          + (met === 'spei' ? '<div class="tu-sub-caja">CLABE para transferir ' + dinero(L.monto) + ' exactos</div><div class="tu-clabe">646180 6330 0000 4003</div>'
             : met === 'tarjeta' ? '<div class="tu-sub-caja">Liga de pago por ' + dinero(L.monto) + '</div><div class="tu-clabe" style="font-size:14px">pago.libertyfin.mx/l/8F3K2Q</div>'
             : '<div class="tu-sub-caja">Referencia para pagar ' + dinero(L.monto) + ' en OXXO y tiendas participantes</div><div class="tu-clabe">905078183407627</div>')
          + (met === 'spei' ? '<div class="tu-acc" style="margin-top:8px"><input class="form-control form-control-sm" style="width:130px" type="number" min="1" step="1" data-in="transfiere" value="' + L.monto + '">'
                             + '<button type="button" class="btn btn-secondary btn-sm" data-a="liga-pagar">Simular transferencia</button></div>'
             : '<div class="tu-acc" style="margin-top:8px"><button type="button" class="btn btn-secondary btn-sm" data-a="liga-pagar">Simular pago del cliente</button></div>')
          + '</div></div>';
      }
      liga += '<p class="tu-msj" data-msj-liga>' + (P.msjLiga || '') + '</p></div></div>';
    }
    return '<button type="button" class="btn btn-secondary btn-sm" data-a="volver-ventas" style="margin-bottom:12px">' + ico('atras', 14) + (P.s.desde === 'cobranza' ? 'Volver a Cobranza' : 'Volver al listado') + '</button>'
      + '<div class="tu-cifras">' + stat(r2(v.tot), 'Total') + stat(r2(cobrado(v)), 'Cobrado') + stat(s > 0 ? s : 'Liquidada', 'Saldo') + '</div>'
      + '<div class="card" style="margin-bottom:14px"><div class="card-header">Pagos de esta venta<span class="tu-sub-caja">' + v.pagos.length + (v.pagos.length === 1 ? ' pago' : ' pagos') + '</span></div><div class="card-body" style="padding-top:2px;padding-bottom:2px">' + (pagos || '<div class="tu-vacio" style="margin:10px 0">Sin pagos todavía.</div>') + '</div></div>'
      + '<div class="tu-dos"><div class="tu-pila">'
      + (s > 0 ? '<div class="card"><div class="card-header">Registrar un abono</div><div class="card-body"><div class="tu-form">'
          + '<div><label class="form-label">Abono</label><input class="form-control form-control-sm" type="number" min="1" step="1" data-in="abono" value="' + s + '"></div>'
          + '<div><label class="form-label">Método</label><select class="form-select form-control-sm" data-in="metab"><option>Transferencia</option><option>Efectivo</option><option>Tarjeta</option></select></div>'
          + '<div><button type="button" class="btn btn-primary" style="width:100%" data-a="registrar">Registrar</button></div></div>'
          + '<p class="tu-msj" data-msj-ab>' + (P.msjAb || '') + '</p></div></div>'
          : '<div class="card"><div class="card-body"><p class="tu-msj bien" style="margin:0">' + (P.msjAb || 'La venta está liquidada.') + '</p></div></div>')
      + '</div>' + (liga ? '<div class="tu-pila">' + liga + '</div>' : '') + '</div>';
  }
  function qr() {
    var h = '';
    for (var i = 0; i < 121; i++) {
      var x = i % 11, y = (i / 11) | 0, esq = (x < 3 && y < 3) || (x > 7 && y < 3) || (x < 3 && y > 7);
      var n = esq ? !((x === 1 && y === 1) || (x === 9 && y === 1) || (x === 1 && y === 9)) : ((x * 7 + y * 13 + x * y) % 3 === 0);
      h += '<i' + (n ? '' : ' class="b"') + '></i>';
    }
    return '<div class="tu-qr" aria-hidden="true">' + h + '</div>';
  }

  PANT.cobranza = function () {
    /* La venta abierta desde Cobranza se ve DENTRO de Cobranza: el menú sigue ahí. */
    if (P.s.venta && P.s.desde === 'cobranza' && ventaDe(P.s.venta)) return detalleVenta(ventaDe(P.s.venta));
    cabecera('Cobranza', 'A quién hay que cobrarle hoy');
    var con = P.s.ventas.filter(function (v) { return saldo(v) > 0; }), o = P.s.orden;
    var tramo = function (d) { return d <= 30 ? 0 : d <= 60 ? 1 : 2; }, sumas = [0, 0, 0], total = 0;
    con.forEach(function (v) { var s = saldo(v); sumas[tramo(diasSinAbono(v))] += s; total += s; });
    con.sort(o === 'viejo' ? function (a, b) { return diasSinAbono(b) - diasSinAbono(a); }
           : o === 'grande' ? function (a, b) { return saldo(b) - saldo(a); }
           : function (a, b) { return a.cli.localeCompare(b.cli); });
    var etq = [['bg-success', '1 a 30 días'], ['bg-warning', '31 a 60 días'], ['bg-danger', 'Más de 60 días']];
    return '<div class="tu-cifras">' + stat(r2(total), 'Por cobrar') + stat(r2(sumas[0]), '1 a 30 días', 'Se cobra solo')
      + stat(r2(sumas[1]), '31 a 60 días', 'Hay que recordarles') + stat(r2(sumas[2]), 'Más de 60 días', 'Háblales hoy') + '</div>'
      + '<div class="card"><div class="card-header">Saldos abiertos<div class="tu-seg">'
      + [['cliente', 'Por cliente'], ['grande', 'Lo más grande primero'], ['viejo', 'Lo más viejo primero']].map(function (x) { return '<button type="button" data-a="orden" data-v="' + x[0] + '" class="' + (o === x[0] ? 'on' + popDe('orden', x[0]) : '') + '">' + x[1] + '</button>'; }).join('')
      + '</div></div><div class="table-responsive"><table class="table"><thead><tr><th>Cliente</th><th class="text-end">Saldo</th><th>Último abono</th><th>Antigüedad</th><th></th></tr></thead><tbody>'
      + con.map(function (v) {
          var d = diasSinAbono(v), t = etq[tramo(d)];
          return '<tr><td><strong>' + esc(v.cli) + '</strong><div class="tu-mono" style="font-size:11px;color:var(--lf-tinta-4)">' + v.f + '</div></td><td class="text-end tu-mono">' + dinero(saldo(v)) + '</td>'
               + '<td>' + (d === 0 ? 'Hoy' : 'Hace ' + d + ' días') + '</td><td><span class="badge ' + t[0] + '">' + t[1] + '</span></td>'
               + '<td class="text-end"><button type="button" class="btn btn-secondary btn-sm" data-a="abrir-venta" data-v="' + v.f + '" data-desde="cobranza">Ver venta</button></td></tr>';
        }).join('') + '</tbody></table></div></div>';
  };

  PANT.corte = function () {
    cabecera('Corte de caja', 'Turno actual · Matriz');
    var ef = cobrosHoy(['Efectivo']), tj = cobrosHoy(['Tarjeta']), tr = cobrosHoy(['Transferencia', 'SPEI']), C = P.s.corte, debe = r2(C.fondo + ef);
    var filas = [];
    P.s.ventas.forEach(function (v) { v.pagos.forEach(function (p) { if (!p.cancel && p.hace === 0) filas.push([v, p]); }); });
    var tabla = '<div class="card" style="margin-bottom:14px"><div class="card-header">Cobros del turno</div><div class="table-responsive"><table class="table"><thead><tr><th>Cobro</th><th>Cliente</th><th>Método</th><th class="text-end">Monto</th></tr></thead><tbody>'
      + filas.map(function (x) {
          var fuera = x[1].met !== 'Efectivo';
          return '<tr><td class="tu-mono">' + x[0].f + '-' + dos(x[1].n) + '</td><td>' + esc(x[0].cli) + '</td><td>' + esc(x[1].met) + (fuera ? ' <span class="badge bg-secondary">No pasa por el cajón</span>' : '') + '</td><td class="text-end tu-mono">' + dinero(x[1].m) + '</td></tr>';
        }).join('') + '</tbody></table></div></div>';
    var cierre;
    if (C.cerrado) {
      cierre = '<div class="card tu-resultado"><div class="card-body"><p class="tu-msj ' + (C.cerrado.dif === 0 ? 'bien' : 'aviso') + '" style="margin:0 0 12px">'
        + (C.cerrado.dif === 0 ? 'Caja cerrada. Cuadró exacto.' : 'Caja cerrada con una diferencia de ' + dinero(Math.abs(C.cerrado.dif)) + (C.cerrado.dif > 0 ? ' (sobrante)' : ' (faltante)') + '. Nota: «' + esc(C.cerrado.nota) + '».')
        + '</p><button type="button" class="btn btn-secondary btn-sm" data-a="reabrir">Abrir otro turno</button></div></div>';
    } else {
      cierre = '<div class="card"><div class="card-header">Cerrar caja</div><div class="card-body"><div class="tu-cuenta" style="margin-bottom:12px"><span>Debe haber en el cajón (fondo ' + dinero(C.fondo) + ' + efectivo)</span><b>' + dinero(debe) + '</b></div>'
        + '<div class="tu-form"><div><label class="form-label">Cuánto hay realmente</label><input class="form-control form-control-sm" type="number" min="0" step="1" data-in="hay" placeholder="Cuenta tu efectivo"></div>'
        + '<div style="grid-column:span 2"><label class="form-label">Nota</label><input class="form-control form-control-sm" data-in="nota" placeholder="Obligatoria si hay diferencia" autocomplete="off"></div></div>'
        + '<p class="tu-msj" data-dif></p><div class="tu-acc" style="margin-top:12px"><button type="button" class="btn btn-primary" data-a="cerrar">Cerrar caja</button></div><p class="tu-msj" data-msj-corte></p></div></div>';
    }
    return '<div class="tu-cifras">' + stat(r2(debe), 'Debe haber en el cajón') + stat(r2(ef + tj + tr), 'Cobrado en el turno') + stat(r2(tj), 'Tarjeta', 'No pasa por el cajón') + stat(r2(tr), 'Transferencia', 'No pasa por el cajón') + '</div>' + tabla + cierre;
  };

  PANT.gastos = function () {
    cabecera('Gastos', 'Lo que sale del negocio');
    return '<div class="card" style="margin-bottom:14px"><div class="card-header">Registrar un gasto</div><div class="card-body"><form data-form="gasto" method="dialog" autocomplete="off"><div class="tu-form">'
      + '<div><label class="form-label">Concepto</label><input class="form-control form-control-sm" name="con" placeholder="Ej. Productos de limpieza"></div>'
      + '<div><label class="form-label">Categoría</label><select class="form-select form-control-sm" name="cat"><option>Insumos</option><option>Servicios</option><option>Renta</option><option>Nómina</option><option>Marketing</option><option>Otros</option></select></div>'
      + '<div><label class="form-label">Monto</label><input class="form-control form-control-sm" name="m" type="number" min="1" step="1"></div>'
      + '<div><button class="btn btn-primary" type="submit" style="width:100%">Registrar</button></div></div><p class="tu-msj" data-msj></p></form></div></div>'
      + '<div class="card"><div class="card-header">Gastos del mes</div><div class="table-responsive"><table class="table"><thead><tr><th>Concepto</th><th>Categoría</th><th class="text-end">Monto</th></tr></thead><tbody>'
      + P.s.gastos.map(function (g, i) { return '<tr' + (P.filaNueva === 'g' + i ? ' class="tu-fila-nueva"' : '') + '><td><strong>' + esc(g.con) + '</strong></td><td>' + esc(g.cat) + '</td><td class="text-end tu-mono">' + dinero(g.m) + '</td></tr>'; }).join('')
      + '</tbody></table></div></div>';
  };

  PANT.reportes = function () {
    cabecera('Reportes', 'Octubre · lo cobrado, no lo vendido');
    var cob = 0, porMet = {};
    P.s.ventas.forEach(function (v) { v.pagos.forEach(function (p) { if (!p.cancel && p.hace <= 15) { cob += p.m; porMet[p.met] = (porMet[p.met] || 0) + p.m; } }); });
    cob = r2(cob);
    var gas = P.s.gastos.reduce(function (t, g) { return t + g.m; }, 0), iva = r2(cob - cob / 1.16), com = r2(cob / 1.16 * 0.1), queda = r2(cob - gas - com - iva);
    var x = cob || 1, izq = 100, fila = function (cls, t, m, desde, ancho) {
      return '<div class="tu-cas ' + cls + '"><span>' + t + '</span><div class="tu-carril"><i style="left:' + Math.max(0, desde) + '%;width:' + Math.max(0, ancho) + '%"></i></div><em>' + dinero(m) + '</em></div>';
    };
    var h = fila('base', 'Cobrado', cob, 0, 100);
    [['Gastos', gas], ['Comisiones', com], ['IVA', iva]].forEach(function (f) { izq -= f[1] / x * 100; h += fila('menos', '− ' + f[0], f[1], izq, f[1] / x * 100); });
    h += fila('queda', '= Queda', queda, 0, Math.max(0, queda) / x * 100);
    if (hecho('gasto')) cumplirLuego('reportes-ver');
    return '<div class="tu-hero"><small>Este mes</small><div>Entraron <b>' + cifra(cob, true, 'entraron') + '</b> y quedaron <b>' + cifra(queda, true, 'quedaron') + '</b></div></div>'
      + '<div class="card" style="margin-bottom:14px"><div class="card-header">De dónde a dónde</div><div class="card-body"><div class="tu-cascada">' + h + '</div></div></div>'
      + '<div class="card"><div class="card-header">Cómo cobran</div><div class="table-responsive"><table class="table"><thead><tr><th>Forma de pago</th><th class="text-end">Cobrado</th></tr></thead><tbody>'
      + Object.keys(porMet).map(function (k) { return '<tr><td>' + esc(k) + '</td><td class="text-end tu-mono">' + dinero(porMet[k]) + '</td></tr>'; }).join('') + '</tbody></table></div></div>';
  };

  PANT.ajustes = function () {
    cabecera('Ajustes', 'Módulos');
    var M = [['agenda', 'Agenda de citas', 'Las citas del día por especialista, con recordatorio por WhatsApp.'],
             ['cotizaciones', 'Cotizaciones', 'Presupuestos que se imprimen y, si el cliente acepta, pasan a Caja.'],
             ['inventario', 'Inventario', 'Existencias por sucursal. Caja las descuenta al cobrar.']];
    return '<div class="card"><div class="card-header">Herramientas opcionales</div><div class="card-body" style="padding-top:2px;padding-bottom:2px">'
      + M.map(function (m) {
          return '<div class="tu-sw"><div><b>' + m[1] + '</b><small>' + m[2] + '</small></div><button type="button" data-a="mod" data-v="' + m[0] + '" aria-pressed="' + !!P.s.mods[m[0]] + '" aria-label="' + m[1] + '"></button></div>';
        }).join('') + '</div><div class="card-footer">Caja, ventas, clientes, cobranza, corte, gastos, productos y reportes vienen siempre.</div></div>';
  };
  ['agenda', 'cotizaciones', 'inventario'].forEach(function (k) {
    PANT[k] = function () {
      cabecera(NOMBRES[k], 'Herramienta encendida');
      return '<div class="card"><div class="card-body"><p style="margin:0 0 6px;font-weight:600">' + NOMBRES[k] + ' ya aparece en tu menú.</p>'
        + '<p class="tu-sub-caja" style="margin:0">En el entorno de práctica esta sección solo se muestra encendida. En tu sistema encontrarás aquí todas sus funciones.</p></div></div>';
    };
  });

  /* ═══ PINTAR ═══ */
  var pendientes = [];
  function cumplirLuego(k) { pendientes.push(k); }
  /* La entrada en cascada corre solo si cambió la pantalla (o la venta
     abierta, o el paso de la caja). Si es la misma, se vuelve a pintar
     quieta y solo se anima lo que cambió. */
  function pintarPantalla() {
    var c = $('[data-pantalla]', raiz()); if (!c) return;
    var p = P.s.pantalla; if (!PANT[p]) p = P.s.pantalla = 'panel';
    var clave = p + '|' + ((p === 'ventas' || p === 'cobranza') ? (P.s.venta || '') : '') + '|' + (p === 'caja' ? P.caja.fase : '');
    var entra = MOV && clave !== P.clave, main = c.parentNode;
    P.clave = clave;
    main.classList.toggle('tu-entra', entra);
    main.classList.toggle('tu-reordena', MOV && !entra && !!P.reordena);
    c.innerHTML = PANT[p]();
    P.reordena = false; P.lineaNueva = null; P.filaNueva = null; P.segPop = null; P.ultimoPago = null; P.ligaNueva = false;
    if (p === 'caja') refrescarCaja();
    animarCifras(c, entra);
    while (pendientes.length) cumplir(pendientes.shift());
  }
  function animarCifras(c, entra) {
    var vistos = P.cifras || (P.cifras = {});
    $$('[data-n]', c).forEach(function (el) {
      var k = P.clave + '|' + el.dataset.k, n = +el.dataset.n, din = el.hasAttribute('data-dinero');
      var desde = entra ? 0 : (k in vistos ? vistos[k] : n);
      if (desde !== n) { contar(el, desde, n, entra ? 800 : 450, din); if (!entra) reanimar(el, 'tu-salta'); }
      vistos[k] = n;
    });
  }
  function popDe(a, v) { return P.segPop === a + ':' + v ? ' pop' : ''; }
  function pintarTodo() { pintarNav(); pintarPantalla(); pintarGuia(); }

  /* ═══ ARRANQUE ═══ */
  P.iniciar = function () {
    if (!raiz()) return;
    P.s = cargar(); P.caja = nuevaCaja(); P.liga = null; P.msjAb = ''; P.msjLiga = ''; P.cancelando = null; P.ultimoPago = null;
    /* Página recién abierta: todo entra de nuevo. */
    P.vista = null; P.clave = null; P.totalVisto = null; P.guiaLeccion = null; P.guiaPct = null; P.guiaPend = false;
    aplicarHash();
  };
  /* Un botón que no se vuelve a pintar (los de la barra, el de ingreso)
     regresa a su estado normal. */
  function soltar(b, texto) {
    if (!b) return;
    b.removeAttribute('aria-busy'); b.disabled = false; b.textContent = texto;
  }

  if (!P.listo) {
    P.listo = true;
    window.addEventListener('hashchange', function () { if (raiz()) aplicarHash(); });
    window.addEventListener('resize', function () {
      var nav = raiz() && $('[data-nav]', raiz());
      if (nav) moverMarca(nav, true);
    });

    document.addEventListener('submit', function (ev) {
      var f = ev.target.closest('.tu form[data-form]'); if (!f || !P.s) return;
      ev.preventDefault();
      var tipo = f.dataset.form, m = $('[data-msj]', f);
      if (tipo === 'entrar') {
        var bt = $('.tu-login-boton', f);
        ocupado(bt, 'Entrando…', 650, function () {
          despedir(f, function () {
            soltar(bt, 'Entrar al entorno');
            irLeccion(P.s.leccion || 1);
          }, 340);
        });
        return;
      }
      if (tipo === 'producto') {
        var n = f.n.value.trim(), p = num(f.p.value), c = num(f.c.value);
        if (!n) { m.textContent = 'Escribe el nombre.'; m.className = 'tu-msj mal'; return; }
        if (f.p.value === '') { m.textContent = 'Escribe el precio (0 si es precio libre).'; m.className = 'tu-msj mal'; return; }
        if (p > 0 && c > p) { m.textContent = 'El costo no puede ser mayor que el precio.'; m.className = 'tu-msj mal'; return; }
        P.s.productos.push({ n: n.slice(0, 40), p: p, c: c });
        P.filaNueva = 'p' + (P.s.productos.length - 1);
        if (p === 0) P.s.hechos['prod-libre'] = true; else if (c > 0) P.s.hechos['prod-costo'] = true;
        guardar(); pintarTodo();
        var m2 = $('[data-form="producto"] [data-msj]', raiz());
        if (m2) { m2.textContent = '«' + n + '» ya aparece en Caja.'; m2.className = 'tu-msj bien'; }
        return;
      }
      if (tipo === 'gasto') {
        var con = f.con.value.trim(), monto = num(f.m.value);
        if (!con || monto <= 0) { m.textContent = 'Escribe el concepto y el monto.'; m.className = 'tu-msj mal'; return; }
        P.s.gastos.push({ con: con.slice(0, 50), cat: f.cat.value, m: monto });
        P.filaNueva = 'g' + (P.s.gastos.length - 1);
        P.s.hechos.gasto = true; guardar(); pintarTodo();
        var m3 = $('[data-form="gasto"] [data-msj]', raiz());
        if (m3) { m3.textContent = 'Gasto registrado. Ya se descuenta en Reportes.'; m3.className = 'tu-msj bien'; }
      }
    });

    document.addEventListener('input', function (ev) {
      var el = ev.target.closest('.tu [data-in]'); if (!el || !P.s) return;
      var k = el.dataset.in, c = P.caja;
      if (k === 'prod') {
        var f = el.form, p = num(f.p.value), co = num(f.c.value), q = $('[data-queda]', f);
        if (f.p.value === '') { q.textContent = ''; return; }
        if (p > 0 && f.c.value !== '' && co > p) { q.textContent = 'El costo no puede ser mayor que el precio.'; q.className = 'tu-msj mal'; }
        else if (p === 0) { q.textContent = 'Precio libre: el monto se escribe al cobrar.'; q.className = 'tu-msj aviso'; }
        else if (co > 0) { q.textContent = 'Te queda por venta: ' + dinero(p - co); q.className = 'tu-msj bien'; }
        else { q.textContent = 'Agrega el costo para ver cuánto te queda por venta.'; q.className = 'tu-msj'; }
      } else if (k === 'cliente') { c.cliente = el.value; if (el.value.trim().length >= 2) cumplir('caja-cliente'); }
      else if (k === 'deja') {
        c.deja = el.value; refrescarCaja();
        var kk = cuentasCaja(); if (kk.total > 0 && num(el.value) > 0 && kk.saldo > 0) cumplir('caja-parte');
      }
      else if (k === 'paga') { c.paga = el.value; refrescarCaja(); }
      else if (k === 'libre') { var l = c.lineas[+el.dataset.v]; if (l) l.p = num(el.value); refrescarCaja(); }
      else if (k === 'hay') {
        var d = $('[data-dif]', raiz()), debe = r2(P.s.corte.fondo + cobrosHoy(['Efectivo']));
        if (el.value === '') { d.textContent = ''; return; }
        var dif = r2(num(el.value) - debe);
        d.textContent = dif === 0 ? 'Cuadra exacto.' : (dif > 0 ? 'Sobran ' : 'Faltan ') + dinero(Math.abs(dif)) + '. Escribe a qué se debe.';
        d.className = 'tu-msj ' + (dif === 0 ? 'bien' : 'aviso');
      }
    });

    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('.tu [data-a]'); if (!b || !P.s || b.disabled) return;
      var a = b.dataset.a, v = b.dataset.v, c = P.caja, s = P.s, venta;
      switch (a) {
        case 'ir-entrada': location.hash = 'entrar'; break;
        case 'empezar': s.leccion = +v; guardar(); location.hash = 'entrar'; break;
        case 'ir-portada': location.hash = 'inicio'; break;
        case 'salir': s.entro = false; guardar(); location.hash = 'inicio'; break;
        case 'reiniciar':
          ocupado(b, 'Reiniciando…', 450, function () {
            var l = P.s.leccion; P.s = semilla(); P.s.entro = true; P.s.leccion = l; P.caja = nuevaCaja(); P.liga = null; P.msjAb = ''; P.msjLiga = '';
            P.clave = null; P.totalVisto = null;
            guardar(); soltar(b, 'Reiniciar datos'); pintarTodo();
          });
          break;
        case 'leccion': irLeccion(+v); break;
        case 'pantalla':
          s.pantalla = v; s.venta = null; s.desde = null;
          if (v === 'caja' && c.fase === 'recibo') { P.caja = nuevaCaja(); P.totalVisto = 0; }
          P.cancelando = null; P.msjAb = ''; P.msjLiga = '';
          if (v === 'ventas' && s.filtro === 'saldo') P.s.hechos['ir-ventas'] = true;
          if (v !== 'ventas') P.s.hechos['ir-' + v] = true;
          guardar(); pintarNav(); pintarPantalla(); pintarGuia(); break;

        /* Caja */
        case 'agregar':
          var pr = s.productos[+v], ya = -1;
          c.lineas.forEach(function (x, i) { if (x.i === +v) ya = i; });
          if (ya > -1) c.lineas[ya].cant++; else c.lineas.push({ i: +v, n: pr.n, p: pr.p, cant: 1, libre: !(pr.p > 0) });
          P.lineaNueva = ya > -1 ? ya : c.lineas.length - 1;
          pintarPantalla(); if (c.lineas.length >= 2) cumplir('caja-dos'); break;
        case 'mas': c.lineas[+v].cant++; P.lineaNueva = +v; pintarPantalla(); break;
        case 'menos':
          if (c.lineas[+v].cant > 1) { c.lineas[+v].cant--; pintarPantalla(); break; }
          despedir(b.closest('.tu-linea'), function () { c.lineas.splice(+v, 1); pintarPantalla(); }); break;
        case 'quitar': despedir(b.closest('.tu-linea'), function () { c.lineas.splice(+v, 1); pintarPantalla(); }); break;
        case 'metodo': c.metodo = v; if (v !== 'efectivo') c.parte = false; P.segPop = 'metodo:' + v; pintarPantalla(); break;
        case 'parte': c.parte = true; c.deja = ''; pintarPantalla(); var dj = $('[data-in="deja"]', raiz()); if (dj) dj.focus(); break;
        case 'deja':
          var t = cuentasCaja().total; c.deja = String(v === '50' ? Math.round(t / 2) : t); pintarPantalla();
          var k2 = cuentasCaja(); if (k2.saldo > 0 && k2.hoy > 0) cumplir('caja-parte'); break;
        case 'cobrar': c.fase = 'revisa'; pintarPantalla(); break;
        case 'volver-ticket': c.fase = 'armar'; pintarPantalla(); break;
        case 'confirmar':
          ocupado(b, 'Registrando…', 550, function () {
            var k = cuentasCaja(), paga = num(c.paga), f = folioAhora(), met = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', spei: 'Transferencia' }[c.metodo];
            var cambio = (c.metodo === 'efectivo' && c.paga !== '' && paga > k.hoy && k.hoy > 0) ? r2(paga - k.hoy) : 0;
            s.ventas.push({ f: f, cli: c.cliente.trim() || 'Público general', tot: k.total,
                            pagos: k.hoy > 0 ? [{ n: 1, m: k.hoy, met: met, hace: 0 }] : [] });
            c.recibo = { f: f, cli: c.cliente.trim() || 'Público general', lineas: c.lineas.map(function (x) { return { n: x.n, p: num(x.p), cant: x.cant }; }),
                         total: k.total, hoy: k.hoy, saldo: k.saldo, cambio: cambio };
            c.fase = 'recibo';
            if (c.cliente.trim().length >= 2) s.hechos['caja-cliente'] = true;
            if (c.metodo === 'efectivo' && c.paga !== '' && paga >= k.hoy && k.hoy > 0) s.hechos['caja-cambio'] = true;
            if (k.saldo > 0) s.hechos['caja-saldo'] = true;
            guardar(); pintarTodo();
          });
          break;
        case 'siguiente-venta': P.caja = nuevaCaja(); P.totalVisto = 0; pintarPantalla(); break;

        /* Ventas */
        case 'filtro': s.filtro = v; if (v === 'saldo') s.hechos['ir-ventas'] = true; P.segPop = 'filtro:' + v; P.reordena = true; guardar(); pintarPantalla(); pintarGuia(); break;
        case 'abrir-venta':
          s.desde = b.dataset.desde || null; s.venta = v; s.pantalla = s.desde || 'ventas'; P.cancelando = null; P.msjAb = ''; P.msjLiga = ''; P.ultimoPago = null;
          venta = ventaDe(v);
          if (venta && saldo(venta) > 0) s.hechos['venta-abrir'] = true;
          if (b.dataset.desde === 'cobranza' && masViejo() && masViejo().f === v) s.hechos['cob-abrir'] = true;
          guardar(); pintarTodo();
          if (raiz().getBoundingClientRect().top < 0) window.scrollTo({ top: Math.max(0, raiz().getBoundingClientRect().top + window.scrollY - 12), behavior: MOV ? 'smooth' : 'auto' });
          break;
        case 'volver-ventas': s.venta = null; s.desde = null; P.msjAb = ''; P.msjLiga = ''; guardar(); pintarPantalla(); break;
        case 'registrar':
          venta = ventaDe(s.venta); if (!venta) break;
          var monto = num($('[data-in="abono"]', raiz()).value), sal = saldo(venta), metAb = $('[data-in="metab"]', raiz()).value, ma = $('[data-msj-ab]', raiz());
          if (monto <= 0) { P.msjAb = ''; ma.textContent = 'Escribe cuánto abona.'; ma.className = 'tu-msj mal'; break; }
          if (monto > sal) { ma.textContent = 'El abono (' + dinero(monto) + ') supera el saldo (' + dinero(sal) + ').'; ma.className = 'tu-msj mal'; break; }
          ocupado(b, 'Registrando…', 450, function () {
            var nn = sigN(venta);
            venta.pagos.push({ n: nn, m: monto, met: metAb, hace: 0 });
            P.ultimoPago = venta.f + '-' + nn;
            P.msjAb = 'Abono registrado con el subfolio -' + dos(nn) + '. ' + (sal - monto > 0 ? 'Queda un saldo de ' + dinero(sal - monto) + '.' : 'La venta queda liquidada.');
            s.hechos.abono = true; guardar(); pintarTodo();
            var mab = $('[data-msj-ab]', raiz()); if (mab) mab.className = 'tu-msj bien';
          });
          break;
        case 'cancelar': P.cancelando = +v; pintarPantalla(); break;
        case 'cancelar-no': P.cancelando = null; pintarPantalla(); break;
        case 'cancelar-si':
          venta = ventaDe(s.venta); if (!venta) break;
          venta.pagos[+v].cancel = true; P.cancelando = null;
          P.msjAb = 'Pago -' + dos(venta.pagos[+v].n) + ' cancelado. El siguiente abono tendrá un número nuevo.';
          guardar(); pintarTodo(); break;
        case 'liga-met': P.liga = { met: v }; P.msjLiga = ''; P.segPop = 'liga-met:' + v; pintarPantalla(); break;
        case 'liga-generar':
          venta = ventaDe(s.venta); if (!venta) break;
          ocupado(b, 'Generando…', 750, function () {
            P.liga = { met: (P.liga && P.liga.met) || 'spei', generada: true, f: venta.f, monto: saldo(venta) };
            P.msjLiga = 'Esperando el pago…'; P.ligaNueva = true;
            if (P.liga.met === 'spei') s.hechos['liga-generar'] = true;
            guardar(); pintarTodo();
          });
          break;
        case 'liga-pagar':
          venta = ventaDe(s.venta); if (!venta || !P.liga) break;
          var L = P.liga, pago = L.met === 'spei' ? num(($('[data-in="transfiere"]', raiz()) || {}).value) : L.monto, sv = saldo(venta);
          if (pago <= 0) break;
          ocupado(b, L.met === 'spei' ? 'Esperando al banco…' : 'Procesando el pago…', 1100, function () {
            if (pago > sv) {
              P.msjLiga = 'Rechazado: la transferencia es mayor que el saldo. El banco le regresa su dinero al cliente.';
              pintarPantalla(); var mr = $('[data-msj-liga]', raiz()); if (mr) mr.className = 'tu-msj mal';
              return;
            }
            var n3 = sigN(venta), metL = { spei: 'SPEI', tarjeta: 'Tarjeta', tienda: 'Tienda' }[L.met];
            venta.pagos.push({ n: n3, m: pago, met: metL, hace: 0 });
            P.ultimoPago = venta.f + '-' + n3; P.liga = { met: L.met };
            P.msjLiga = 'Pago recibido. El abono quedó aplicado con el subfolio -' + dos(n3) + (sv - pago > 0 ? '. Queda un saldo de ' + dinero(sv - pago) + '.' : '.');
            if (sv - pago <= 0) P.msjAb = P.msjLiga;
            if (L.met === 'spei') s.hechos['liga-pago'] = true;
            guardar(); pintarTodo();
            var ml = $('[data-msj-liga]', raiz()); if (ml) ml.className = 'tu-msj bien';
          });
          break;

        /* Cobranza */
        case 'orden': s.orden = v; if (v === 'viejo') s.hechos['cob-viejo'] = true; P.segPop = 'orden:' + v; P.reordena = true; guardar(); pintarPantalla(); pintarGuia(); break;

        /* Corte */
        case 'cerrar':
          var hay = $('[data-in="hay"]', raiz()), nota = $('[data-in="nota"]', raiz()), mc = $('[data-msj-corte]', raiz());
          if (hay.value === '') { mc.textContent = 'Escribe cuánto hay realmente en el cajón.'; mc.className = 'tu-msj mal'; break; }
          var dif = r2(num(hay.value) - (s.corte.fondo + cobrosHoy(['Efectivo'])));
          if (dif !== 0 && !nota.value.trim()) { mc.textContent = 'Hay una diferencia: escribe en la nota a qué se debe.'; mc.className = 'tu-msj mal'; nota.focus(); break; }
          var textoNota = nota.value.trim();
          ocupado(b, 'Cerrando…', 500, function () {
            s.corte.cerrado = { dif: dif, nota: textoNota }; s.hechos['corte-cerrar'] = true; guardar(); pintarTodo();
          });
          break;
        case 'reabrir': s.corte.cerrado = null; guardar(); pintarPantalla(); break;

        /* Ajustes: el interruptor se desliza en su lugar; no se vuelve a
           pintar la pantalla, solo el menú, que recibe la sección nueva. */
        case 'mod':
          s.mods[v] = !s.mods[v]; if (s.mods[v]) s.hechos['mod-on'] = true;
          b.setAttribute('aria-pressed', String(!!s.mods[v]));
          guardar(); pintarNav(s.mods[v] ? v : null); pintarGuia(); break;
      }
    });
  }

  P.iniciar();
})();
</script>
