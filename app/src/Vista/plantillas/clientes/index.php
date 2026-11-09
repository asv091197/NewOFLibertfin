<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$ini = function ($n) {
    $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : ''));
};
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;
$qs = function (array $x = []) use ($desde,$hasta,$buscar,$soloPeriodo) {
    return '?' . http_build_query(array_merge(['desde'=>$desde,'hasta'=>$hasta,'q'=>$buscar] + ($soloPeriodo ? ['periodo'=>1] : []), $x));
};
$ant = $antiguedad;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary>
    <?= W::icono($e ? 'cliente' : 'mas','16px') ?>
    <?= $e ? 'Editar a ' . P::e($e['nombre']) : 'Dar de alta un cliente' ?>
  </summary>
  <form method="post" action="/clientes/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px">
      <label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required>
    </div>
    <div style="flex:1;min-width:140px">
      <label class="form-label">RFC</label>
      <input class="form-control" name="rfc" value="<?= P::e($e['rfc'] ?? '') ?>"
             placeholder="Opcional" style="text-transform:uppercase">
    </div>
    <div style="flex:1;min-width:150px">
      <label class="form-label">Área</label>
      <input class="form-control" name="area" list="lfAreas"
             value="<?= P::e($e['area'] ?? '') ?>" placeholder="Opcional">
      <datalist id="lfAreas">
        <?php foreach ($areas as $a): ?><option value="<?= P::e($a) ?>"></option><?php endforeach; ?>
      </datalist>
    </div>
    <div style="flex:1;min-width:150px">
      <label class="form-label">Teléfono</label>
      <input class="form-control" name="telefono" value="<?= P::e($e['telefono'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div style="flex:1.5;min-width:180px">
      <label class="form-label">Correo</label>
      <input class="form-control" type="email" name="email" value="<?= P::e($e['email'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div style="flex:2;min-width:200px">
      <label class="form-label">Dirección</label>
      <input class="form-control" name="direccion" value="<?= P::e($e['direccion'] ?? '') ?>" placeholder="Opcional">
    </div>
    <?php /* Lo fiscal: régimen y uso se guardan como la clave del SAT. */
          $regE = (string)($e['regimen_fiscal'] ?? ''); $usoE = (string)($e['uso_cfdi'] ?? ''); ?>
    <div style="width:110px">
      <label class="form-label" for="cCp">C.P.</label>
      <input class="form-control lf-mono" name="codigo_postal" id="cCp" value="<?= P::e($e['codigo_postal'] ?? '') ?>"
             inputmode="numeric" pattern="\d{5}" maxlength="5" placeholder="Opcional"
             title="Código postal fiscal: 5 dígitos">
    </div>
    <div style="flex:2;min-width:220px">
      <label class="form-label" for="cRegimen">Régimen fiscal</label>
      <select class="form-select" name="regimen_fiscal" id="cRegimen">
        <option value="">Sin régimen</option>
        <?php foreach (\LibertyFin\Datos\ClienteRepo::REGIMENES as $k => $v): ?>
          <option value="<?= $k ?>" <?= $regE === (string)$k ? 'selected' : '' ?>><?= $k ?> · <?= P::e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:2;min-width:220px">
      <label class="form-label" for="cUso">Uso CFDI</label>
      <select class="form-select" name="uso_cfdi" id="cUso">
        <option value="">Sin uso</option>
        <?php foreach (\LibertyFin\Datos\ClienteRepo::USOS_CFDI as $k => $v): ?>
          <option value="<?= $k ?>" <?= $usoE === $k ? 'selected' : '' ?>><?= $k ?> · <?= P::e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Dar de alta' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/clientes">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Nombre o teléfono">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <label class="lf-solo" title="Con una búsqueda se mira todo el historial; márcalo para limitarla a las fechas de arriba">
    <input type="checkbox" name="periodo" value="1" <?= $soloPeriodo ? 'checked' : '' ?>> Solo este periodo</label>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>
<?php if ($todo): ?>
  <p class="lf-nota-busqueda">Buscando «<?= P::e($buscar) ?>» en <b>todo el historial</b>,
    sin importar las fechas. Marca <b>Solo este periodo</b> para acotarla.</p>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['activos'] ?? 0) ?></div>
    <div class="stat-label">Clientes activos</div>
    <div class="stat-meta">con venta en el periodo</div></div>

  <div class="stat-card"><div class="lf-tile l"><?= W::icono('mas','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['nuevos'] ?? 0) ?></div>
    <div class="stat-label">Nuevos</div>
    <div class="stat-meta">su primera compra fue en este periodo</div></div>

  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['con_saldo'] ?? 0) ?></div>
    <div class="stat-label">Con saldo abierto</div>
    <div class="stat-meta"><?= D::pesos($resumen['saldo'] ?? 0) ?> por cobrar</div></div>

  <div class="stat-card"><div class="lf-tile g"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['recurrentes'] ?? 0) ?></div>
    <div class="stat-label">Recurrentes</div>
    <div class="stat-meta">dos o más compras</div></div>
</div>


<section class="card">
  <header class="card-header">Directorio</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Cliente</th><th>Área</th><th class="text-end">Compras</th>
        <th class="text-end">Facturado</th><th class="text-end">Saldo</th><th>Última</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$clientes): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Ningún cliente con actividad en este periodo.</td></tr>
      <?php endif; ?>
      <?php foreach ($clientes as $c): $deb = (float)$c['saldo'] > 0.01; ?>
        <tr>
          <td data-label="Cliente">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-av gris" style="width:30px;height:30px;font-size:11px">
                <?= P::e($ini($c['nombre'])) ?></span>
              <span style="min-width:0">
                <a href="/clientes/<?= (int)$c['id'] ?>" class="lf-cli-nombre"><b style="display:block;font-weight:600"><?= P::e($c['nombre']) ?></b></a>
                <?php if ($c['telefono']): ?>
                  <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($c['telefono']) ?></small>
                <?php endif; ?>
              </span>
            </span>
          </td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area'] ?: 'Sin área') ?></span></td>
          <td data-label="Compras"   class="text-end lf-mono"><?= (int)$c['compras'] ?></td>
          <td data-label="Facturado" class="text-end lf-mono"><?= D::pesos($c['facturado']) ?></td>
          <td data-label="Saldo" class="text-end">
            <?php if ($deb): ?>
              <b class="lf-mono" style="color:var(--lf-amb)"><?= D::pesos($c['saldo']) ?></b>
            <?php else: ?>
              <span class="badge bg-success">Al corriente</span>
            <?php endif; ?>
          </td>
          <td data-label="Última" class="lf-mono" style="font-size:12px">
            <?= date('d M', strtotime($c['ultima'])) ?></td>
          <td style="text-align:right">
            <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$c['id']])) ?>"
               title="Editar"><?= W::icono('cliente','15px') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($clientes) ?> de <?= number_format($total) ?> clientes</span>
    <?php P::parcial('parciales/paginacion', [
        'pagina'  => $pagina, 'paginas' => $paginas,
        'enlace'  => function ($n) use ($qs) { return $qs(['p' => $n]); },
      ]); ?>
  </div>
</section>

<?php /* LAS GRÁFICAS, DEBAJO DEL DIRECTORIO.
     Estaban arriba, así que al entrar a Clientes lo primero eran dos
     gráficas y había que bajar para llegar a la lista. A esta
     pantalla se entra a buscar a alguien o a darlo de alta; quién
     factura más es interesante, pero después. Igual que en
     Productos. */ ?>
<div class="lf-split">
  <?php if ($top): ?>
  <section class="card">
    <header class="card-header">Clientes que más facturan</header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($t){
        return ['rotulo'=>$t['nombre'],'monto'=>$t['monto']]; }, $top)); ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div><span>Antigüedad del saldo</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Desde el último abono</p></div>
      <span class="badge bg-warning"><?= D::pesos($ant['total'] ?? 0) ?></span>
    </header>
    <div class="card-body">
      <?php if ((float)($ant['total'] ?? 0) <= 0): ?>
        <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:14px 0">
          Nadie debe nada. Todo liquidado.</p>
      <?php else:
        W::segmentos([
          ['rotulo'=>'1 a 30 días',   'monto'=>$ant['t30']    ?? 0, 'color'=>'var(--lf-brand)'],
          ['rotulo'=>'31 a 60 días',  'monto'=>$ant['t60']    ?? 0, 'color'=>'var(--lf-amb)'],
          ['rotulo'=>'Más de 60 días','monto'=>$ant['t60mas'] ?? 0, 'color'=>'var(--lf-rojo)'],
        ]);
        if ((float)($ant['t60mas'] ?? 0) > 0): ?>
          <p style="margin-top:16px;padding-top:14px;border-top:1px solid var(--lf-linea);
                    font-size:12.5px;color:var(--lf-tinta-3)">
            <?= D::pesos($ant['t60mas']) ?> en <?= (int)$ant['n60mas'] ?>
            venta<?= $ant['n60mas']==1?'':'s' ?> llevan más de 60 días sin un solo abono.
            Son a los que hay que llamar primero.</p>
        <?php endif;
      endif; ?>
    </div>
  </section>
</div>
