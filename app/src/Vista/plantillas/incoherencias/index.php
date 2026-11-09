<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Incoherencias as I;
use LibertyFin\Dominio\Dinero as D;

// Conserva los filtros al cambiar de página o de regla.
$qs = function ($c = []) use ($filtros) {
    $b = array_filter([
        'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
        'regla' => $filtros['regla'], 'severidad' => $filtros['severidad'],
        'sucursal' => $filtros['sucursal'] ?: null, 'usuario' => $filtros['usuario'] ?: null,
        'q' => $filtros['q'],
    ], function ($v) { return $v !== null && $v !== ''; });
    return '?' . http_build_query(array_merge($b, array_filter($c, function ($v) { return $v !== null; })));
};
// Una regla elegida se quita volviendo a pulsarla.
$sinRegla = function () use ($qs) { return $qs(['regla' => null, 'p' => null]) ; };

$pct = function ($v) {
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') . '%';
};
$cifra = function ($v, $unidad) use ($pct) {
    if ($v === null) return '—';
    return $unidad === 'pct' ? $pct($v) : D::pesos($v);
};
$clase = ['alta' => 'bg-danger', 'media' => 'bg-warning', 'baja' => 'bg-secondary'];
?>

<?php P::parcial('auditoria/_pestanas', ['actual' => 'incoherencias']); ?>

<form class="lf-filtros" method="get">
  <div class="lf-search" style="max-width:210px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q"
           value="<?= P::e($filtros['q']) ?>" placeholder="Folio de la venta">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde"
         value="<?= P::e($filtros['desde']) ?>" style="width:auto" title="Ventas desde">
  <input class="form-control form-control-sm" type="date" name="hasta"
         value="<?= P::e($filtros['hasta']) ?>" style="width:auto" title="Ventas hasta">
  <select class="form-select form-select-sm" name="regla" style="width:auto;max-width:260px">
    <option value="">Toda regla</option>
    <?php foreach (I::REGLAS as $k => $r): ?>
      <option value="<?= P::e($k) ?>" <?= $filtros['regla'] === $k ? 'selected' : '' ?>><?= P::e($r['titulo']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="severidad" style="width:auto">
    <option value="">Toda gravedad</option>
    <?php foreach (I::SEVERIDADES as $k => $v): ?>
      <option value="<?= $k ?>" <?= $filtros['severidad'] === $k ? 'selected' : '' ?>><?= P::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="sucursal" style="width:auto">
    <option value="">Toda sucursal</option>
    <?php foreach ($sucursales as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$filtros['sucursal'] === (int)$s['id'] ? 'selected' : '' ?>>
        <?= P::e($s['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="usuario" style="width:auto">
    <option value="">Quien capturó: todos</option>
    <?php foreach ($usuarios as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= (int)$filtros['usuario'] === (int)$u['id'] ? 'selected' : '' ?>>
        <?= P::e($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <a class="btn btn-outline-secondary btn-sm" href="<?= P::e($qs(['formato' => 'csv', 'p' => null])) ?>">Descargar CSV</a>
</form>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Hallazgos</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Lo capturado que no cuadra con las reglas del propio sistema. Solo se señala: nada se corrige solo.</p></div>
    </header>

    <?php if ($truncadas): ?>
      <p style="margin:0 16px 10px;font-size:12.5px;color:var(--lf-amb)">
        Hay más de <?= number_format(I::TOPE_POR_REGLA) ?> hallazgos en
        <?= P::e(implode(', ', array_map(function ($k) { return I::REGLAS[$k]['titulo']; }, $truncadas))) ?>:
        se muestran los más recientes. Acota las fechas para ver el resto.</p>
    <?php endif; ?>

    <div class="table-responsive lf-cards">
      <table class="table table-hover">
        <thead><tr>
          <th>Gravedad</th><th>Venta</th><th>Qué pasa</th>
          <th class="text-end">Hay</th><th class="text-end">Debería</th><th>Capturó</th>
        </tr></thead>
        <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:34px">
            <?= $total === 0 && !array_sum($resumen)
                ? 'No se encontró nada con estos filtros. Si el periodo es amplio y esperabas algo, revisa las fechas.'
                : 'No hay hallazgos con esta regla.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($filas as $h): ?>
          <tr>
            <td data-label="Gravedad">
              <span class="badge <?= $clase[$h['severidad']] ?>"><?= P::e(I::SEVERIDADES[$h['severidad']]) ?></span></td>
            <td data-label="Venta">
              <a href="/ventas/<?= (int)$h['venta_id'] ?>" data-modal class="lf-mono" style="font-weight:600;color:var(--lf-tinta)">
                <?= P::e($h['codigo']) ?></a>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                <?= $h['fecha'] ? date('d/m/Y', strtotime($h['fecha'])) : '' ?>
                <?= $h['sucursal'] !== '' ? ' · ' . P::e($h['sucursal']) : '' ?></span></td>
            <td data-label="Qué pasa">
              <?= P::e($h['titulo']) ?>
              <?php if ($h['nota'] !== ''): ?>
                <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($h['nota']) ?></span>
              <?php endif; ?></td>
            <td data-label="Hay" class="text-end lf-mono"><?= $cifra($h['actual'], $h['unidad']) ?></td>
            <td data-label="Debería" class="text-end lf-mono" style="color:var(--lf-tinta-4)"><?= $cifra($h['esperado'], $h['unidad']) ?></td>
            <td data-label="Capturó"><?= P::e($h['usuario'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($filas) ?> de <?= number_format($total) ?></span>
      <?php P::parcial('parciales/paginacion', ['pagina' => $pagina, 'paginas' => $paginas,
        'enlace' => function ($n) use ($qs) { return $qs(['p' => $n]); }]); ?>
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Por gravedad</header>
      <div class="card-body">
        <?php foreach (I::SEVERIDADES as $k => $nombre): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px;margin-bottom:8px">
            <a href="<?= P::e($qs(['severidad' => $filtros['severidad'] === $k ? '' : $k, 'regla' => null, 'p' => null])) ?>"
               style="color:var(--lf-tinta);<?= $filtros['severidad'] === $k ? 'font-weight:700' : '' ?>">
              <span class="badge <?= $clase[$k] ?>"><?= P::e($nombre) ?></span></a>
            <b class="lf-mono"><?= (int)$porSeveridad[$k] ?></b>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Por regla</header>
      <div class="card-body">
        <?php foreach (I::REGLAS as $k => $r):
          if (!empty($filtros['severidad']) && $filtros['severidad'] !== $r['severidad']) continue;
          $n = (int)($resumen[$k] ?? 0);
          $activa = $filtros['regla'] === $k; ?>
          <div style="margin-bottom:11px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;font-size:12.5px">
              <a href="<?= P::e($activa ? $sinRegla() : $qs(['regla' => $k, 'p' => null])) ?>"
                 title="<?= P::e($r['ayuda']) ?>"
                 style="color:var(--lf-tinta);<?= $activa ? 'font-weight:700' : '' ?>"><?= P::e($r['titulo']) ?></a>
              <b class="lf-mono" style="<?= $n ? '' : 'color:var(--lf-tinta-4);font-weight:400' ?>"><?= $n ?></b>
            </div>
          </div>
        <?php endforeach; ?>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:4px;padding-top:12px;
                  border-top:1px solid var(--lf-linea);line-height:1.5">
          Cada regla es algo que el sistema ya exige al capturar (el tope de 100% en
          comisiones, no cobrar de más...). Un hallazgo no siempre es un error:
          en datos importados o históricos algunos son esperables.
        </p>
      </div>
    </section>
  </div>
</div>
