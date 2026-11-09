<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\MeliPublicar as MP;

$p    = $producto;
$err  = function ($k) use ($errores) {
    return isset($errores[$k]) ? '<small style="display:block;color:var(--bs-danger,#c0392b)">' . P::e($errores[$k]) . '</small>' : '';
};
$v    = function ($k, $d = '') use ($f) { return isset($f[$k]) ? (string)$f[$k] : $d; };
$volverA = $p ? '/servicios?editar=' . (int)$p['id'] : '/servicios';
?>

<div class="lf-doc-acciones" style="margin:0 0 14px">
  <a class="btn btn-secondary" href="<?= P::e($volverA) ?>"><?= W::icono('atras','14px') ?> Volver al producto</a>
  <a class="btn btn-secondary" href="/mercadolibre">Mercado Libre</a>
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
    <?php if ($paso): ?>
      <div class="lf-pills">
        <span class="lf-pill <?= $paso === 1 ? 'active' : '' ?>">1 · Categoría</span>
        <span class="lf-pill <?= $paso === 2 ? 'active' : '' ?>">2 · Datos y ficha técnica</span>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php /* ═══════════ YA ESTÁ PUBLICADO ═══════════ */ ?>
<?php if ($yaPublicado): ?>
<div class="alert alert-success">
  <?= W::icono('ok','18px') ?>
  <span>Este producto ya está vinculado a la publicación <b><?= P::e($yaPublicado['item_id']) ?></b>
    «<?= P::e($yaPublicado['titulo']) ?>».
    <?php if ($yaPublicado['permalink']): ?>
      <a href="<?= P::e($yaPublicado['permalink']) ?>" target="_blank" rel="noopener">Verla en Mercado Libre ↗</a>
    <?php endif; ?>
    Para publicarlo otra vez, primero quítale el vínculo en <a href="/mercadolibre">Mercado Libre → Publicaciones</a>.</span>
</div>
<?php return; endif; ?>

<?php /* ═══════════ PASO 1 · CATEGORÍA ═══════════ */ ?>
<?php if ($paso === 1): ?>
<section class="card">
  <header class="card-header">¿En qué categoría va?</header>
  <div style="padding:14px 18px">
    <form method="get" action="/mercadolibre/publicar" class="lf-form" style="margin-bottom:14px">
      <input type="hidden" name="producto" value="<?= (int)$p['id'] ?>">
      <div style="flex:1;min-width:240px">
        <label class="form-label" for="mlBuscar">Describe el producto como lo buscaría un comprador</label>
        <input class="form-control" id="mlBuscar" name="q" value="<?= P::e($buscar) ?>" required>
      </div>
      <div style="align-self:flex-end"><button class="btn btn-secondary" type="submit">Buscar categorías</button></div>
    </form>

    <?php if (!$sugerencias): ?>
      <p class="pista">Mercado Libre no sugirió ninguna categoría para «<?= P::e($buscar) ?>». Prueba con palabras más
        generales: «crema facial», «playera algodón», «taladro inalámbrico».</p>
    <?php else: ?>
      <p class="pista" style="margin-top:0">Sugerencias de Mercado Libre. Elige la que mejor describa el producto:</p>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($sugerencias as $s): ?>
          <a class="card" style="display:block;padding:12px 14px;text-decoration:none;color:inherit"
             href="/mercadolibre/publicar?<?= P::e(http_build_query(['producto' => $p['id'], 'categoria' => $s['id']])) ?>">
            <b><?= P::e($s['nombre']) ?></b>
            <small class="lf-inv-cod" style="display:block"><?= P::e($s['dominio']) ?> · <?= P::e($s['id']) ?></small>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php return; endif; ?>

<?php /* ═══════════ PASO 2 · DATOS Y FICHA ═══════════ */ ?>
<?php if ($paso === 2 && $categoria): ?>
<form method="post" action="/mercadolibre/publicar" enctype="multipart/form-data" id="fMeliPub">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="producto" value="<?= (int)$p['id'] ?>">
  <input type="hidden" name="categoria" value="<?= P::e($categoria['id']) ?>">
  <?php foreach ((array)($f['fotos_ml'] ?? []) as $fid): ?>
    <input type="hidden" name="fotos_ml[]" value="<?= P::e($fid) ?>">
  <?php endforeach; ?>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Categoría</header>
    <div style="padding:12px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span style="flex:1"><?= P::e($categoria['ruta']) ?> <small class="lf-inv-cod"><?= P::e($categoria['id']) ?></small></span>
      <a class="btn btn-secondary btn-sm" href="/mercadolibre/publicar?producto=<?= (int)$p['id'] ?>">Cambiar</a>
    </div>
  </section>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Datos de la publicación</header>
    <div class="lf-form" style="padding:14px 18px">
      <div style="flex:1 1 100%">
        <label class="form-label" for="mlTitulo">Título</label>
        <input class="form-control" id="mlTitulo" name="titulo" maxlength="<?= MP::MAX_TITULO ?>" required
               value="<?= P::e($v('titulo')) ?>">
        <small class="lf-inv-cod"><span id="mlCuenta"><?= mb_strlen($v('titulo')) ?></span>/<?= MP::MAX_TITULO ?> ·
          Producto, marca y lo que lo distingue. Sin precios, promociones ni signos de más.</small>
        <?= $err('titulo') ?>
      </div>
      <div style="width:150px">
        <label class="form-label" for="mlPrecio">Precio (<?= P::e($moneda) ?>)</label>
        <input class="form-control lf-mono" id="mlPrecio" name="precio" inputmode="decimal" value="<?= P::e($v('precio')) ?>" required>
        <?= $err('precio') ?>
      </div>
      <div style="width:130px">
        <label class="form-label" for="mlCant">Cantidad</label>
        <input class="form-control lf-mono" id="mlCant" name="cantidad" type="number" min="1" step="1" value="<?= P::e($v('cantidad')) ?>" required>
        <?= $err('cantidad') ?>
      </div>
      <div style="width:170px">
        <label class="form-label">Condición</label>
        <select class="form-control" name="condicion">
          <option value="new" <?= $v('condicion', 'new') === 'new' ? 'selected' : '' ?>>Nuevo</option>
          <option value="used" <?= $v('condicion') === 'used' ? 'selected' : '' ?>>Usado</option>
        </select>
      </div>
      <div style="width:250px">
        <label class="form-label">Tipo de publicación</label>
        <select class="form-control" name="tipo">
          <?php foreach (MP::TIPOS as $k => $t): ?>
            <option value="<?= P::e($k) ?>" <?= $v('tipo', 'gold_special') === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="width:250px">
        <label class="form-label">Envío</label>
        <select class="form-control" name="envio">
          <?php foreach (MP::ENVIOS as $k => $t): ?>
            <option value="<?= P::e($k) ?>" <?= $v('envio', 'me2') === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
          <?php endforeach; ?>
        </select>
        <label style="display:flex;gap:6px;align-items:center;margin-top:6px;font-size:13px">
          <input type="checkbox" name="envio_gratis" value="1" <?= $v('envio_gratis') ? 'checked' : '' ?>> Envío gratis</label>
      </div>
      <div style="width:220px">
        <label class="form-label">Garantía</label>
        <select class="form-control" name="garantia">
          <?php foreach (MP::GARANTIAS as $k => $t): ?>
            <option value="<?= P::e($k) ?>" <?= $v('garantia') === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="width:190px">
        <label class="form-label">Duración</label>
        <div style="display:flex;gap:6px">
          <input class="form-control lf-mono" name="garantia_tiempo" style="width:70px" value="<?= P::e($v('garantia_tiempo', '30')) ?>">
          <select class="form-control" name="garantia_unidad">
            <?php foreach (['días', 'meses', 'años'] as $u): ?>
              <option <?= $v('garantia_unidad', 'días') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $err('garantia_tiempo') ?>
      </div>
    </div>
    <p class="pista" style="margin:0 18px 14px">
      La cantidad sale de la existencia de la sucursal elegida en Mercado Libre → Ajustes, menos la reserva.
      El precio se manda solo ahora: si después lo cambias aquí, no cambia allá.</p>
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
      <?php if (!empty($f['fotos_ml'])): ?>
        <p class="pista" style="margin:0 0 8px"><?= count($f['fotos_ml']) ?> foto(s) ya subidas a Mercado Libre en el intento anterior: se usan otra vez.</p>
      <?php endif; ?>
      <label class="form-label" for="mlFotos">Agregar fotos (hasta <?= MP::MAX_FOTOS ?>)</label>
      <input class="form-control" type="file" id="mlFotos" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple>
      <small class="lf-inv-cod">JPG o PNG, fondo blanco de preferencia, al menos 500 px por lado.</small>
      <?= $err('fotos') ?>
    </div>
  </section>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Ficha técnica</header>
    <div class="lf-form" style="padding:14px 18px">
      <?php if (!$categoria['atributos']): ?>
        <p class="pista" style="margin:0">Esta categoría no pide datos técnicos.</p>
      <?php endif; ?>
      <?php foreach ($categoria['atributos'] as $id => $a):
        $val = (string)($f['attr'][$id] ?? '');
        $nom = 'attr[' . $id . ']'; $dom = 'mlA_' . preg_replace('/[^A-Za-z0-9_]/', '', $id); ?>
        <div style="width:<?= $a['tipo'] === 'numero_unidad' ? '240' : '260' ?>px">
          <label class="form-label" for="<?= P::e($dom) ?>"><?= P::e($a['nombre']) ?><?= $a['obligatorio'] ? ' *' : '' ?></label>
          <?php if ($a['tipo'] === 'lista'): ?>
            <select class="form-control" id="<?= P::e($dom) ?>" name="<?= P::e($nom) ?>">
              <option value="">—</option>
              <?php foreach ($a['valores'] as $x): ?>
                <option value="<?= P::e($x['id']) ?>" <?= $val === $x['id'] ? 'selected' : '' ?>><?= P::e($x['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($a['tipo'] === 'numero_unidad'): ?>
            <div style="display:flex;gap:6px">
              <input class="form-control lf-mono" id="<?= P::e($dom) ?>" name="<?= P::e($nom) ?>" inputmode="decimal" value="<?= P::e($val) ?>">
              <select class="form-control" name="attr_unidad[<?= P::e($id) ?>]" style="width:90px">
                <?php $u = (string)($f['attr_unidad'][$id] ?? $a['unidad']);
                foreach ($a['unidades'] ?: [$a['unidad']] as $x): ?>
                  <option <?= $u === $x ? 'selected' : '' ?>><?= P::e($x) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input class="form-control<?= $a['tipo'] === 'numero' ? ' lf-mono' : '' ?>" id="<?= P::e($dom) ?>" name="<?= P::e($nom) ?>"
                   value="<?= P::e($val) ?>" maxlength="<?= (int)$a['max'] ?>"
                   <?= $a['tipo'] === 'numero' ? 'inputmode="decimal"' : '' ?>
                   <?= $a['valores'] ? 'list="' . P::e($dom) . '_l"' : '' ?>>
            <?php if ($a['valores']): ?>
              <datalist id="<?= P::e($dom) ?>_l">
                <?php foreach (array_slice($a['valores'], 0, 300) as $x): ?><option value="<?= P::e($x['nombre']) ?>"><?php endforeach; ?>
              </datalist>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($id === 'GTIN'): ?><small class="lf-inv-cod">El código de barras del fabricante.</small><?php endif; ?>
          <?php if ($id === 'EMPTY_GTIN_REASON'): ?><small class="lf-inv-cod">Solo si no tiene código de barras.</small><?php endif; ?>
          <?php if ($a['ayuda'] !== ''): ?><small class="lf-inv-cod" style="display:block"><?= P::e($a['ayuda']) ?></small><?php endif; ?>
          <?= $err('attr_' . $id) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card" style="margin-bottom:16px">
    <header class="card-header">Descripción</header>
    <div style="padding:14px 18px">
      <textarea class="form-control" name="descripcion" rows="6" maxlength="50000"
                placeholder="Qué es, para qué sirve, qué incluye, medidas..."><?= P::e($v('descripcion')) ?></textarea>
      <small class="lf-inv-cod">Solo texto: Mercado Libre no acepta HTML, teléfonos ni enlaces.</small>
    </div>
  </section>

  <div style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:30px">
    <a class="btn btn-secondary" href="<?= P::e($volverA) ?>">Cancelar</a>
    <button class="btn btn-primary" type="submit" id="mlPublicar"><?= W::icono('bolsa','14px') ?> Publicar en Mercado Libre</button>
  </div>
</form>
<script>
(function () {
  var t = document.getElementById('mlTitulo'), c = document.getElementById('mlCuenta');
  if (t && c) t.addEventListener('input', function () { c.textContent = t.value.length; });
  var f = document.getElementById('fMeliPub'), b = document.getElementById('mlPublicar');
  // Un segundo clic publicaría dos veces.
  if (f && b) f.addEventListener('submit', function () { b.disabled = true; b.textContent = 'Publicando…'; });
})();
</script>
<?php endif; ?>
