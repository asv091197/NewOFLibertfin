<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;

/**
 * El layout de MODO CONCENTRADO.
 *
 * PARA QUÉ
 *
 * Quien está recibiendo mercancía con la pistola en la mano no necesita
 * el menú, ni la barra de arriba, ni el aviso de la suscripción. Lo que
 * necesita es ver qué acaba de escanear y cuánto lleva. Todo lo demás
 * es algo que puede tocar por error con el codo mientras sostiene una
 * caja.
 *
 * Así que esta pantalla se despeja: se van el menú de la izquierda y la
 * barra de arriba, y queda la captura sola, centrada, con letra grande.
 *
 * POR QUÉ UN LAYOUT Y NO CSS
 *
 * Esconder el menú con `display:none` lo deja en la página: sigue
 * recibiendo el foco con el tabulador, los lectores de pantalla lo
 * siguen leyendo, y un atajo de teclado puede llevar a otra pantalla a
 * media captura. Aquí sencillamente no está.
 *
 * Reusa el mismo <head> que el layout normal —los mismos estilos, el
 * mismo tema claro u oscuro— para que no se sienta otra aplicación.
 */

// Misma hoja y mismo versionado que el layout normal: si no coincidiera,
// el navegador bajaría dos copias del mismo CSS.
$hoja = __DIR__ . '/../../../public/assets/css/libertyfin.css';
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= P::e($titulo ?? 'Capturando') ?> · LibertyFin</title>
<link rel="stylesheet" href="/assets/css/libertyfin.css?v=<?= is_file($hoja) ? filemtime($hoja) : '3' ?>">
<style>
  /* La pantalla entera, sin nada alrededor. */
  html, body { height: 100%; }
  body.lf-concentrado {
    margin: 0;
    background: var(--lf-fondo, #f6f7f9);
    display: flex;
    flex-direction: column;
    min-height: 100dvh;
  }
  .lf-conc-barra {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 18px;
    background: var(--lf-papel, #fff);
    border-bottom: 1px solid var(--lf-linea, #e5e7eb);
    position: sticky; top: 0; z-index: 10;
  }
  .lf-conc-barra h1 {
    margin: 0; font-size: 16px; font-weight: 650; letter-spacing: -.01em;
  }
  .lf-conc-barra .sub {
    font-size: 12px; color: var(--lf-tinta-4, #6b7280); display: block; font-weight: 400;
  }
  .lf-conc-cuerpo {
    flex: 1; width: 100%; max-width: 820px; margin: 0 auto;
    padding: 18px 16px 90px;
  }
  /* La entrada se queda abajo, siempre a la mano, como la barra de
     cobro de la caja: con una caja en brazos nadie quiere buscar. */
  .lf-conc-pie {
    position: sticky; bottom: 0;
    background: var(--lf-papel, #fff);
    border-top: 1px solid var(--lf-linea, #e5e7eb);
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
  }
  .lf-conc-pie .dentro { max-width: 820px; margin: 0 auto; }

  /* Aparecer sin sobresaltos. Quien activa «menos movimiento» en su
     sistema no ve la animación: para alguien con mareo o vértigo una
     pantalla que se desliza es un problema, no un detalle bonito. */
  @keyframes lfEntrar { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
  .lf-conc-cuerpo, .lf-conc-barra { animation: lfEntrar .22s ease-out both; }
  .lf-conc-cuerpo { animation-delay: .04s; }
  @media (prefers-reduced-motion: reduce) {
    .lf-conc-cuerpo, .lf-conc-barra { animation: none; }
  }
</style>
</head>
<body class="lf-concentrado" data-lf-token="<?= P::e($_SESSION['lf_token'] ?? '') ?>">

<div class="lf-conc-barra">
  <?= W::icono($icono ?? 'caja', '20px') ?>
  <div style="flex:1">
    <h1><?= P::e($titulo ?? '') ?></h1>
    <?php if (!empty($subtitulo)): ?>
      <span class="sub"><?= P::e($subtitulo) ?></span>
    <?php endif; ?>
  </div>
  <?= $accionesConcentrado ?? '' ?>
</div>

<div class="lf-conc-cuerpo"><?= $contenido ?></div>

<?php if (!empty($pieConcentrado)): ?>
  <div class="lf-conc-pie"><div class="dentro"><?= $pieConcentrado ?></div></div>
<?php endif; ?>

<script>
// El tema que tenga elegido la persona, para no deslumbrarla al entrar
// en modo oscuro.
try {
  var t = localStorage.getItem('lf-tema');
  if (t) document.documentElement.setAttribute('data-theme', t);
} catch (e) {}
</script>
</body>
</html>
