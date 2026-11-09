<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$cobrado = (float)($resumen['cobrado'] ?? 0);
$vendido = (float)($resumen['vendido'] ?? 0);
$saldo   = (float)($resumen['saldo']   ?? 0);
$avance  = D::pct($cobrado, $vendido);
$qs = function (array $extra = []) use ($desde, $hasta, $filtros, $soloPeriodo) {
    return '?' . http_build_query(array_merge(
        ['desde'=>$desde,'hasta'=>$hasta,'estado'=>$filtros['estado'],'q'=>$filtros['buscar']]
        + ($soloPeriodo ? ['periodo'=>1] : []), $extra));
};
?>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($filtros['buscar']) ?>" placeholder="Cliente, folio o producto">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <select class="form-select form-select-sm" name="estado" style="width:auto">
    <option value="">Todos los estados</option>
    <?php /* Los mismos estados que la insignia de cada fila. */
    foreach (\LibertyFin\Datos\VentaRepo::ESTADOS as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $filtros['estado']===$k?'selected':'' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <label class="lf-solo" title="Con una búsqueda se mira todo el historial; márcalo para limitarla a las fechas de arriba">
    <input type="checkbox" name="periodo" value="1" <?= $soloPeriodo ? 'checked' : '' ?>> Solo este periodo</label>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>
<?php if ($todo): ?>
  <p class="lf-nota-busqueda">Buscando «<?= P::e($filtros['buscar']) ?>» en <b>todo el historial</b>,
    sin importar las fechas. Marca <b>Solo este periodo</b> para acotarla.</p>
<?php endif; ?>

<div class="lf-stats">
  <?php
  W::cifra(['hero'=>true,'icono'=>'cobro','valor'=>D::corto($cobrado),
            'etiqueta'=>'Cobrado','avance'=>$avance,
            'pie'=>$avance.'% de lo vendido en el periodo']);
  W::cifra(['icono'=>'reloj','tono'=>'a','valor'=>D::corto($saldo),
            'etiqueta'=>'Por cobrar','avance'=>100-$avance,'ambar'=>true,
            'pie'=>(int)($resumen['con_saldo'] ?? 0).' ventas con saldo abierto']);
  W::cifra(['icono'=>'bolsa','tono'=>'g','valor'=>D::corto($vendido),
            'etiqueta'=>'Vendido','pie'=>number_format($total).' ventas']);
  W::cifra(['icono'=>'venta','tono'=>'l','valor'=>D::corto($resumen['promedio'] ?? 0),
            'etiqueta'=>'Ticket promedio','pie'=>'por venta del periodo']);
  ?>
</div>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Movimientos</header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr>
          <th>Cliente</th><th>Área</th>
          <th class="text-end">Venta</th><th class="text-end">Cobrado</th>
          <th>Avance</th><th>Estado</th>
        </tr></thead>
        <tbody>
        <?php if (!$ventas): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
            No hay ventas en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($ventas as $v): ?>
          <tr>
            <td data-label="Cliente">
              <?php /* `data-modal`: se abre encima y al cerrar sigues en la lista,
                       con tu filtro y tu scroll. Ver el layout. */ ?>
              <a data-lf-fila href="/ventas/<?= (int)$v['id'] ?>" data-modal
                 style="font-weight:600;color:var(--lf-tinta)">
                <?= P::e($v['cliente'] ?: 'Público general') ?></a>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                <?= P::e($v['codigo_venta']) ?> · <?= date('d M', strtotime($v['fecha'])) ?></span>
            </td>
            <td data-label="Área"><span class="badge bg-secondary"><?= P::e($v['area_nombre'] ?: 'Sin área') ?></span></td>
            <td data-label="Venta"   class="text-end lf-mono"><?= D::pesos($v['total']) ?></td>
            <td data-label="Cobrado" class="text-end lf-mono"><?= D::pesos($v['cobrado']) ?></td>
            <td data-label="Avance"><?php W::avanceMini($v['cobrado'], $v['total']); ?></td>
            <td data-label="Estado"><?php W::estado($v['saldo'], $v['estado']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($ventas) ?> de <?= number_format($total) ?> ventas</span>
      <?php P::parcial('parciales/paginacion', [
        'pagina'  => $pagina, 'paginas' => $paginas,
        'enlace'  => function ($n) use ($qs) { return $qs(['p' => $n]); },
      ]); ?>
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Saldos abiertos</header>
      <div style="padding:0 10px 8px">
        <?php if (!$saldos): ?>
          <p style="padding:20px;color:var(--lf-tinta-4);font-size:13px;text-align:center">
            Nadie debe nada. Todo liquidado.</p>
        <?php endif; ?>
        <?php foreach ($saldos as $s): ?>
          <a class="lf-row" href="/ventas/<?= (int)$s['id'] ?>" data-modal>
            <span class="lf-av gris"><?= P::e(strtoupper(mb_substr($s['cliente'] ?: '?', 0, 2))) ?></span>
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13.5px"><?= P::e($s['cliente'] ?: 'Público general') ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11.5px">
                <?= P::e($s['area_nombre'] ?: 'Sin área') ?> · <?= date('d M', strtotime($s['fecha'])) ?></small>
            </span>
            <span style="text-align:right">
              <b class="lf-mono" style="font-size:14px"><?= D::pesos($s['saldo']) ?></b>
              <small style="display:block;color:var(--lf-tinta-4);font-size:11px">
                de <?= D::corto($s['total']) ?></small>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="card-footer" style="display:flex;justify-content:space-between">
        <span><?= (int)($resumen['con_saldo'] ?? 0) ?> ventas con saldo</span>
        <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($saldo) ?></b>
      </div>
    </section>

    <?php if ($meses): ?>
    <section class="card">
      <header class="card-header">Cobranza por mes</header>
      <div class="card-body">
        <?php W::barras(array_map(function($m){
            return ['cobrado'=>$m['cobrado']]; }, $meses)); ?>
        <?php /* Una columna por barra, del mismo ancho que ella. El monto va
                 en micro (181k): completo (181,098 pesos) no cabía en el
                 celular, se salía de la tarjeta y ensanchaba la página.
                 El importe exacto queda en el title. El mes, en español:
                 date('M') lo da en inglés. */
        $nombreMes = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']; ?>
        <div class="lf-meses-rot">
          <?php foreach ($meses as $m): ?>
            <div title="<?= P::e(D::pesos($m['cobrado'])) ?>">
              <b><?= $nombreMes[(int)substr($m['mes'], 5, 2)] ?? '' ?></b>
              <span class="lf-mono"><?= D::micro($m['cobrado']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
