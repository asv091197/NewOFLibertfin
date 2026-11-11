<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\ShopifyPublicar as SP;

$p    = $producto;
$err  = function ($k) use ($errores) {
    return isset($errores[$k]) ? '<small style="display:block;color:var(--bs-danger,#c0392b)">' . P::e($errores[$k]) . '</small>' : '';
};
$v    = function ($k, $d = '') use ($f) { return isset($f[$k]) ? (string)$f[$k] : $d; };
$volverA = $p ? '/servicios?editar=' . (int)$p['id'] : '/servicios';
?>

<div class="lf-doc-acciones" style="margin:0 0 14px">
  <a class="btn btn-secondary" href="<?= P::e($volverA) ?>"><?= W::icono('atras','14px') ?> Volver al producto</a>
  <a class="btn btn-secondary" href="/shopify">Shopify</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:16px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($error) ?></span>
</div>
<?php endif; ?>

<?php if (!$p) return; ?>

<section class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:14px;align-items:center;padding:14px 18px;flex-wrap:wrap">
    <span class="lf-mini-img" style="width:46px;height:46px;<?= !empty($p['imagen']) ? "background-image:url('" . P::e($p['imagen']) . "')" : '' ?>">
      <?= !empty($p['imagen']) ? '' : W::icono('serv','18px') ?></span>
    <div style="flex:1;min-width:200px">
      <b><?= P::e($p['nombre']) ?></b>
      <small class="lf-inv-cod" style="display:block">SKU <?= P::e($p['codigo']) ?>
        <?= !empty($p['codigo_barras']) ? ' · ' . P::e($p['codigo_barras']) : '' ?></small>
    </div>
  </div>
</section>

<?php if ($yaPublicado): ?>
<div class="alert alert-success">
  <?= W::icono('ok','18px') ?>
  <span>Este producto ya está vinculado al producto <b>#<?= P::e($yaPublicado['producto_shop']) ?></b>
    «<?= P::e($yaPublicado['titulo']) ?>» de la tienda.
    <?php if ($yaPublicado['permalink']): ?>
      <a href="<?= P::e($yaPublicado['permalink']) ?>" target="_blank" rel="noopener">Verlo en la tienda ↗</a>
    <?php endif; ?>
    Para publicarlo otra vez, primero quítale el vínculo en <a href="/shopify">Shopify → Productos</a>.</span>
</div>
<?php return; endif; ?>

<form method="post" action="/shopify/publicar" enctype="multipart/form-data" id="fShopPub">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="producto" value="<?= (int)$p['id'] ?>">

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Datos del producto</header>
    <div class="lf-form" style="padding:14px 18px">
      <div style="flex:1 1 100%">
        <label class="form-label" for="shNombre">Nombre</label>
        <input class="form-control" id="shNombre" name="nombre" maxlength="<?= SP::MAX_NOMBRE ?>" required value="<?= P::e($v('nombre')) ?>">
        <?= $err('nombre') ?>
      </div>
      <div style="width:150px">
        <label class="form-label" for="shPrecio">Precio</label>
        <input class="form-control lf-mono" id="shPrecio" name="precio" inputmode="decimal" value="<?= P::e($v('precio')) ?>" required>
        <?= $err('precio') ?>
      </div>
      <div style="width:170px">
        <label class="form-label" for="shPromo">Precio de oferta</label>
        <input class="form-control lf-mono" id="shPromo" name="precio_promocional" inputmode="decimal" value="<?= P::e($v('precio_promocional')) ?>" placeholder="opcional">
        <?= $err('precio_promocional') ?>
      </div>
      <div style="width:130px">
        <label class="form-label" for="shCant">Existencia</label>
        <input class="form-control lf-mono" id="shCant" name="cantidad" type="number" min="0" step="1" value="<?= P::e($v('cantidad')) ?>" required>
        <?= $err('cantidad') ?>
      </div>
      <div style="width:180px">
        <label class="form-label" for="shSku">SKU</label>
        <input class="form-control lf-mono" id="shSku" name="sku" maxlength="100" value="<?= P::e($v('sku')) ?>">
      </div>
      <div style="width:200px">
        <label class="form-label" for="shBarras">Código de barras</label>
        <input class="form-control lf-mono" id="shBarras" name="codigo_barras" maxlength="48" value="<?= P::e($v('codigo_barras')) ?>">
      </div>
      <div style="width:200px">
        <label class="form-label" for="shMarca">Marca</label>
        <input class="form-control" id="shMarca" name="marca" maxlength="100" value="<?= P::e($v('marca')) ?>">
      </div>
      <div style="width:140px">
        <label class="form-label" for="shPeso">Peso (kg)</label>
        <input class="form-control lf-mono" id="shPeso" name="peso" inputmode="decimal" value="<?= P::e($v('peso')) ?>" placeholder="para envíos">
        <?= $err('peso') ?>
      </div>
      <div style="flex:1 1 100%">
        <label style="display:flex;gap:8px;align-items:center">
          <input type="checkbox" name="publicado" value="1" <?= $v('publicado') ? 'checked' : '' ?>>
          Activo desde ya (si no, queda como borrador)</label>
      </div>
    </div>
    <p class="pista" style="margin:0 18px 14px">
      La existencia sale de la sucursal elegida en Shopify → Ajustes, menos la reserva, y va a la ubicación
      elegida ahí. El SKU es el que vincula el producto: déjalo igual al de LibertyFin. El precio se manda solo ahora.</p>
  </section>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Fotos</header>
    <div style="padding:14px 18px">
      <?php if (!empty($p['imagen'])): ?>
        <label style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
          <input type="checkbox" name="usar_imagen" value="1" <?= $v('usar_imagen') ? 'checked' : '' ?>>
          <span class="lf-mini-img" style="width:40px;height:40px;background-image:url('<?= P::e($p['imagen']) ?>')"></span>
          Usar la imagen del producto
        </label>
      <?php endif; ?>
      <label class="form-label" for="shFotos">Agregar fotos (hasta <?= SP::MAX_FOTOS ?>)</label>
      <input class="form-control" type="file" id="shFotos" name="fotos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
      <small class="lf-inv-cod">JPG, PNG, GIF o WebP de hasta 20 MB. Cuadradas de 2048 px se ven mejor.</small>
      <?= $err('fotos') ?>
    </div>
  </section>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Descripción</header>
    <div style="padding:14px 18px">
      <textarea class="form-control" name="descripcion" rows="6" maxlength="50000"
                placeholder="Qué es, para qué sirve, qué incluye, medidas..."><?= P::e($v('descripcion')) ?></textarea>
      <small class="lf-inv-cod">Texto normal: cada línea en blanco separa un párrafo.</small>
    </div>
  </section>

  <div style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:30px">
    <a class="btn btn-secondary" href="<?= P::e($volverA) ?>">Cancelar</a>
    <button class="btn btn-primary" type="submit" id="shPublicar"><?= W::icono('bolsa','14px') ?> Publicar en Shopify</button>
  </div>
</form>
<script>
(function () {
  var f = document.getElementById('fShopPub'), b = document.getElementById('shPublicar');
  // Un segundo clic publicaría dos veces.
  if (f && b) f.addEventListener('submit', function () { b.disabled = true; b.textContent = 'Publicando…'; });
})();
</script>
