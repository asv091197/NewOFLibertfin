<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

/* Las tarjetas del catálogo de Emida: la misma tarjeta de Caja, con la
   imagen del proveedor si la tiene (si no, o si no carga, el ícono). La
   usan la página al abrir y /recargas/productos al buscar o paginar. */
foreach ($productos as $p):
    $variable = (float)$p['monto'] <= 0;
    $img = trim((string)($p['imagen'] ?? ''));
    $tieneImg = $img !== '' && strtoupper($img) !== 'NA' && preg_match('#^https?://#i', $img); ?>
  <button type="button" class="lf-serv lf-rc<?= $variable ? ' sin-precio' : '' ?>"
          data-id="<?= P::e($p['producto_id']) ?>"
          data-n="<?= P::e($p['nombre']) ?>"
          data-monto="<?= (float)$p['monto'] ?>"
          data-min="<?= (float)$p['monto_min'] ?>"
          data-max="<?= (float)$p['monto_max'] ?>"
          data-com="<?= (float)$p['comision'] ?>"
          data-img="<?= $tieneImg ? P::e($img) : '' ?>"
          title="<?= P::e($p['nombre'] . ' · ID ' . $p['producto_id']) ?>">
    <?php if ($tieneImg): ?>
      <span class="e foto"><img src="<?= P::e($img) ?>" alt="" loading="lazy"
            onerror="this.parentNode.classList.remove('foto');this.parentNode.innerHTML=<?= P::e(json_encode(W::icono('bolsa', '17px'))) ?>"></span>
    <?php else: ?>
      <span class="e"><?= W::icono('bolsa','17px') ?></span>
    <?php endif; ?>
    <b><?= P::e($p['nombre']) ?></b>
    <small><?= P::e($p['carrier'] ?: $p['categoria']) ?></small>
    <span class="p"><?= $variable ? 'Monto variable' : D::pesos($p['monto']) ?></span>
  </button>
<?php endforeach;
