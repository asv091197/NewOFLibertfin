<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Datos\GastoRepo as G;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$e = $editando;
$qs = function (array $x = []) use ($desde,$hasta,$cat,$buscar,$soloPeriodo) {
    return '?' . http_build_query(array_merge(
        ['desde'=>$desde,'hasta'=>$hasta,'cat'=>$cat,'q'=>$buscar] + ($soloPeriodo ? ['periodo'=>1] : []), $x)); };
$totCat = 0; foreach ($categorias as $c) $totCat += (float)$c['monto'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-pills" style="margin-bottom:18px">
  <a class="lf-pill <?= $vista==='generales'?'active':'' ?>"
     href="?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'t'=>'generales'])) ?>">
    Generales</a>
  <a class="lf-pill <?= $vista==='operacion'?'active':'' ?>"
     href="?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'t'=>'operacion'])) ?>">
    De operación</a>
  <a class="lf-pill <?= $vista==='proveedores'?'active':'' ?>"
     href="?<?= P::e(http_build_query(['t'=>'proveedores'])) ?>">
    Proveedores</a>
</div>

<?php if ($vista === 'generales'): ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e ? 'baja' : 'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['concepto']) : 'Registrar un gasto' ?></summary>
  <form method="post" action="/gastos/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:200px">
      <label class="form-label">Concepto</label>
      <input class="form-control" name="concepto" value="<?= P::e($e['concepto'] ?? '') ?>" required>
    </div>
    <div style="width:150px">
      <label class="form-label">Categoría</label>
      <select class="form-select" name="categoria">
        <?php foreach (G::CATEGORIAS as $c): ?>
          <option value="<?= P::e($c) ?>" <?= (isset($e['categoria']) && $e['categoria']===$c)?'selected':'' ?>>
            <?= P::e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:140px">
      <label class="form-label">Monto</label>
      <input class="form-control lf-mono" type="number" name="monto" step="0.01" min="0.01"
             value="<?= P::e($e['monto'] ?? '') ?>" required>
    </div>
    <div style="width:160px">
      <label class="form-label">Fecha</label>
      <input class="form-control" type="date" name="fecha" max="<?= date('Y-m-d') ?>"
             value="<?= P::e(isset($e['fecha']) ? date('Y-m-d', strtotime($e['fecha'])) : date('Y-m-d')) ?>">
    </div>
    <div style="width:150px">
      <label class="form-label">Método</label>
      <select class="form-select" name="metodo_pago">
        <?php foreach (['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= (isset($e['metodo_pago']) && $e['metodo_pago']===$k)?'selected':'' ?>>
            <?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:170px">
      <label class="form-label">Proveedor</label>
      <input class="form-control" name="proveedor" list="lfProv"
             value="<?= P::e($e['proveedor'] ?? '') ?>" placeholder="Opcional">
      <datalist id="lfProv">
        <?php foreach ($prov_lista as $pv): ?>
          <option value="<?= P::e($pv['nombre']) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Registrar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/gastos">Cancelar</a><?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      Estos son gastos <b>generales</b>: renta, nómina, servicios. No pertenecen a
      ninguna venta y no tocan comisiones. Los gastos de operación de una venta se
      capturan en Caja o en el detalle de esa venta.
    </p>
  </form>
</details>
<?php endif; ?>

<?php if ($vista !== 'proveedores'): ?>
<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['total'] ?? 0) ?></div>
    <div class="stat-label">Gastos generales</div>
    <div class="stat-meta"><?= (int)($resumen['cuantos'] ?? 0) ?> registros en el periodo</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['operacion'] ?? 0) ?></div>
    <div class="stat-label">De operación</div>
    <div class="stat-meta">cuelgan de ventas · no se suman aquí</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos(($resumen['total'] ?? 0) + ($resumen['operacion'] ?? 0)) ?></div>
    <div class="stat-label">Salida total</div>
    <div class="stat-meta">generales más operación</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($categorias[0]['categoria'] ?? '—') ?></div>
    <div class="stat-label">Categoría que más pesa</div>
    <div class="stat-meta"><?= $categorias ? D::pesos($categorias[0]['monto']) : '—' ?></div>
  </div>
</div>

<?php if ($vista === 'generales' && !$gastos && ($resumen['operacion'] ?? 0) > 0): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>No hay gastos <b>generales</b> en este periodo, pero sí
    <b><?= D::pesos($resumen['operacion']) ?></b> de <b>operación</b> repartidos en ventas.
    Están en la pestaña de arriba.</span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get">
  <input type="hidden" name="t" value="<?= P::e($vista) ?>">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Concepto o nota">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <select class="form-select form-select-sm" name="cat" style="width:auto">
    <option value="">Todas las categorías</option>
    <?php foreach (G::CATEGORIAS as $c): ?>
      <option value="<?= P::e($c) ?>" <?= $cat===$c?'selected':'' ?>><?= P::e($c) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="lf-solo" title="Con una búsqueda se mira todo el historial; márcalo para limitarla a las fechas de arriba">
    <input type="checkbox" name="periodo" value="1" <?= $soloPeriodo ? 'checked' : '' ?>> Solo este periodo</label>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>
<?php if ($todo): ?>
  <p class="lf-nota-busqueda">Buscando «<?= P::e($buscar) ?>» en <b>todo el historial</b>,
    sin importar las fechas. Marca <b>Solo este periodo</b> para acotarla.</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($vista === 'proveedores'):
$pe = $prov_editando; ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($pe?'cliente':'mas','16px') ?>
    <?= $pe ? 'Editar ' . P::e($pe['nombre']) : 'Agregar proveedor' ?></summary>
  <form method="post" action="/gastos/proveedor" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($pe): ?><input type="hidden" name="id" value="<?= (int)$pe['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:200px"><label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($pe['nombre'] ?? '') ?>" required></div>
    <div style="flex:1;min-width:160px"><label class="form-label">Contacto</label>
      <input class="form-control" name="contacto" value="<?= P::e($pe['contacto'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="width:150px"><label class="form-label">Teléfono</label>
      <input class="form-control" name="telefono" value="<?= P::e($pe['telefono'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="width:160px"><label class="form-label">RFC</label>
      <input class="form-control" name="rfc" value="<?= P::e($pe['rfc'] ?? '') ?>"
             style="text-transform:uppercase" placeholder="Opcional"></div>
    <div style="flex:1;min-width:180px"><label class="form-label">Correo</label>
      <input class="form-control" type="email" name="email" value="<?= P::e($pe['email'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $pe?'Guardar':'Agregar' ?></button>
      <?php if ($pe): ?><a class="btn btn-secondary" href="/gastos?t=proveedores">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>

<section class="card">
  <header class="card-header">
    <div><span>Proveedores</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        A quién se le paga. Aparecen como sugerencia al registrar un gasto.</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Proveedor</th><th>Contacto</th><th>RFC</th>
        <th class="text-end">Gastos</th><th class="text-end">Pagado</th>
        <th>Último</th><th></th></tr></thead>
      <tbody>
      <?php if (!$proveedores): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay proveedores. Agrega el primero arriba.</td></tr>
      <?php endif; ?>
      <?php foreach ($proveedores as $pv): $a = (int)$pv['activo']===1; ?>
        <tr style="<?= $a?'':'opacity:.55' ?>">
          <td data-label="Proveedor"><b style="font-weight:600"><?= P::e($pv['nombre']) ?></b>
            <?php if (!$a): ?><span class="badge bg-secondary" style="margin-left:6px">Inactivo</span><?php endif; ?></td>
          <td data-label="Contacto" style="font-size:12.5px">
            <?= P::e($pv['contacto'] ?: '—') ?>
            <?php if ($pv['telefono']): ?>
              <a style="display:block;font-size:11.5px;font-family:var(--lf-mono)"
                 href="tel:<?= P::e($pv['telefono']) ?>"><?= P::e($pv['telefono']) ?></a>
            <?php endif; ?></td>
          <td data-label="RFC" class="lf-mono" style="font-size:11.5px"><?= P::e($pv['rfc'] ?: '—') ?></td>
          <td data-label="Gastos" class="text-end lf-mono"><?= (int)$pv['gastos'] ?></td>
          <td data-label="Pagado" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($pv['pagado']) ?></td>
          <td data-label="Último" class="lf-mono" style="font-size:12px">
            <?= $pv['ultimo'] ? date('d M Y', strtotime($pv['ultimo'])) : '—' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/gastos?t=proveedores&editar=<?= (int)$pv['id'] ?>"
               title="Editar"><?= W::icono('cliente','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-alt-prov"
                    data-id="<?= (int)$pv['id'] ?>" data-nombre="<?= P::e($pv['nombre']) ?>"
                    data-activo="<?= $a?1:0 ?>"><?= $a?'<span class="lf-signo">&times;</span>':'✓' ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    "Pagado" suma los gastos registrados a nombre de este proveedor. Si le
    cambias el nombre, los gastos anteriores se actualizan solos.
  </div>
</section>

<form method="post" action="/gastos/proveedor/alternar" id="formAltProv" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="apId">
</form>
<script>
document.querySelectorAll('.lf-alt-prov').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm((b.dataset.activo==='1'?'¿Desactivar ':'¿Activar ') + b.dataset.nombre + '?')) return;
    document.getElementById('apId').value = b.dataset.id;
    document.getElementById('formAltProv').submit();
  });
});
</script>

<?php elseif ($vista === 'operacion'): ?>
<section class="card">
  <header class="card-header">
    <div><span>Gastos de operación</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Cuelgan de una venta y bajan la base de comisión. Se editan desde el detalle
        de cada venta, no aquí.</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Venta</th><th>Concepto</th><th class="text-end">Venta</th>
        <th class="text-end">Gasto</th><th class="text-end">Peso</th><th>Fecha</th></tr></thead>
      <tbody>
      <?php if (!$operacion): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay gastos de operación en este periodo.</td></tr>
      <?php endif; ?>
      <?php foreach ($operacion as $g):
        $peso = (float)$g['venta_total'] > 0 ? round($g['monto'] / $g['venta_total'] * 100) : 0; ?>
        <tr>
          <td data-label="Venta">
            <a href="/ventas/<?= (int)$g['venta_id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($g['cliente']) ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($g['codigo_venta']) ?></span>
          </td>
          <td data-label="Concepto" style="font-size:12.5px"><?= P::e($g['concepto']) ?></td>
          <td data-label="Venta" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <?= D::pesos($g['venta_total']) ?></td>
          <td data-label="Gasto" class="text-end lf-mono" style="font-weight:700">
            <?= D::pesos($g['monto']) ?></td>
          <td data-label="Peso" class="text-end">
            <span class="badge <?= $peso>=70?'bg-danger':($peso>=40?'bg-warning':'bg-secondary') ?>">
              <?= $peso ?>%</span></td>
          <td data-label="Fecha" class="lf-mono" style="font-size:12px">
            <?= date('d M', strtotime($g['fecha'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    La columna "peso" dice qué parte de la venta se fue en gastos. Arriba del 70%
    la venta casi no deja comisión.
  </div>
</section>

<?php else: ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Movimientos</header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Concepto</th><th>Categoría</th><th>Método</th>
          <th class="text-end">Monto</th><th>Fecha</th><th></th></tr></thead>
        <tbody>
        <?php if (!$gastos): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
            No hay gastos generales en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($gastos as $g): ?>
          <tr>
            <td data-label="Concepto">
              <b style="font-weight:600"><?= P::e($g['concepto']) ?></b>
              <?php if ($g['proveedor'] || $g['descripcion']): ?>
                <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                  <?= P::e($g['proveedor'] ?: $g['descripcion']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Categoría"><span class="badge bg-secondary"><?= P::e($g['categoria']) ?></span></td>
            <td data-label="Método" style="font-size:12.5px"><?= P::e($g['metodo_pago']) ?></td>
            <td data-label="Monto" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($g['monto']) ?></td>
            <td data-label="Fecha" class="lf-mono" style="font-size:12px"><?= date('d M', strtotime($g['fecha'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$g['id']])) ?>"
                 title="Editar"><?= W::icono('baja','15px') ?></a>
              <?php if ($esAdmin): ?>
                <button type="button" class="lf-btn-ghost lf-borrar-gasto"
                        data-id="<?= (int)$g['id'] ?>" data-con="<?= P::e($g['concepto']) ?>"
                        title="Eliminar"><span class="lf-signo">&times;</span></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($gastos) ?> de <?= number_format($totalG) ?> ·
        <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($resumen['total'] ?? 0) ?></b></span>
      <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
        'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n,'t'=>'generales']); }]); ?>
    </div>
  </section>

  <?php if ($categorias): ?>
  <section class="card">
    <header class="card-header">En qué se va</header>
    <div class="card-body">
      <?php W::dona(array_map(function($c){
        return ['rotulo'=>$c['categoria'],'monto'=>$c['monto']]; }, $categorias),
        D::corto($totCat), 'del periodo'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($esAdmin && $vista === 'generales'): ?>
<form method="post" action="/gastos/borrar" id="formBorrarG" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="bgId">
</form>
<script>
document.querySelectorAll('.lf-borrar-gasto').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Eliminar "' + b.dataset.con + '"?\n\nNo se puede deshacer.')) return;
    document.getElementById('bgId').value = b.dataset.id;
    document.getElementById('formBorrarG').submit();
  });
});
</script>
<?php endif; ?>
