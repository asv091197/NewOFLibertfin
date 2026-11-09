<?php use LibertyFin\Vista\Plantilla as P; ?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<script>
// Se ejecuta antes de que el navegador pinte nada: si esperara al final
// del documento, la página aparecería en claro y saltaría a oscuro. Ese
// parpadeo blanco es lo que hace que un modo oscuro se sienta barato.
(function(){
  try {
    var t = localStorage.getItem('lf-tema');
    // 'auto': lo eligió una cuenta de soporte en Mi cuenta (ver layout.php).
    if (t === 'auto') t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    if (t === 'dark' || t === 'light') {
      document.documentElement.setAttribute('data-theme', t);
      return;
    }
  } catch (e) {}
  // Sin preferencia guardada: claro. NUNCA se hereda del sistema sin que
  // el usuario lo pida.
  document.documentElement.setAttribute('data-theme', 'light');
})();
</script>

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= P::e($titulo ?? 'LibertyFin') ?> · LibertyFin</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='DM Sans,system-ui,sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<meta name="theme-color" content="#27ae60">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<?php /* La versión es la fecha del archivo: con `?v=1` fijo, el navegador
         seguía usando la hoja vieja después de cada cambio. */
$hojaLimpia = __DIR__ . '/../../../public/assets/css/libertyfin.css'; ?>
<link rel="stylesheet" href="/assets/css/libertyfin.css?v=<?= is_file($hojaLimpia) ? filemtime($hojaLimpia) : '1' ?>">
</head>
<?php /* `cuerpo`: el registro trae su propia composición (dos columnas);
         el login y lo demás, el verde centrado de siempre. */ ?>
<body class="<?= P::e($cuerpo ?? 'lf-centro') ?>"><?= $contenido ?></body>
</html>
