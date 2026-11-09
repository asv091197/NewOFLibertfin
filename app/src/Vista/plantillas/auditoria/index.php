<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Servicio\Auditoria as A;
use LibertyFin\Dominio\Dinero as D;

$qs = function ($c = []) use ($filtros) {
    $b = array_filter([
        'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
        'accion' => $filtros['accion'], 'usuario' => $filtros['usuario'] ?: null,
        'q' => $filtros['q'],
    ]);
    return '?' . http_build_query(array_merge($b, array_filter($c, function ($v) { return $v !== null; })));
};
$ini = function ($n) { return mb_strtoupper(mb_substr(trim((string)$n), 0, 1) ?: '?'); };
$pctTxt = function ($p) { return rtrim(rtrim(number_format((float)$p, 4, '.', ''), '0'), '.') . '%'; };
// Lo que mueve dinero se marca: es lo que se revisa primero.
$pesadas = ['pago.cancelar','gasto.borrar','servicio.precio','comision.quitar',
            'usuario.rol','usuario.clave','usuario.alternar'];
?>

<?php P::parcial('auditoria/_pestanas', ['actual' => 'bitacora']); ?>

<form class="lf-filtros" method="get">
  <div class="lf-search" style="max-width:250px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q"
           value="<?= P::e($filtros['q']) ?>" placeholder="Qué se tocó o quién">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde"
         value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta"
         value="<?= P::e($hasta) ?>" style="width:auto">
  <select class="form-select form-select-sm" name="accion" style="width:auto">
    <option value="">Toda acción</option>
    <?php foreach (A::ACCIONES as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $filtros['accion']===$k?'selected':'' ?>><?= P::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="usuario" style="width:auto">
    <option value="">Todos</option>
    <?php foreach ($usuarios as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= (int)$filtros['usuario']===(int)$u['id']?'selected':'' ?>>
        <?= P::e($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Movimientos</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Lo que alguien podría querer negar o necesitar reconstruir</p></div>
    </header>
    <div style="padding:0 6px 6px">
      <?php if (!$filas): ?>
        <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:34px">
          No hay movimientos en este periodo.</p>
      <?php endif; ?>
      <?php foreach ($filas as $f):
        $pesada = in_array($f['accion'], $pesadas, true); ?>
        <div class="lf-audit<?= $pesada ? ' peso' : '' ?>">
          <span class="lf-av <?= $pesada ? '' : 'gris' ?>"><?= P::e($ini($f['usuario_nombre'])) ?></span>
          <div style="flex:1;min-width:0">
            <div class="cab">
              <b><?= P::e($f['usuario_nombre'] ?: 'Sistema') ?></b>
              <span class="acc"><?= P::e(A::ACCIONES[$f['accion']] ?? $f['accion']) ?></span>
              <span class="fecha"><?= date('d/m/y H:i', strtotime($f['creado_en'])) ?></span>
            </div>
            <div class="sobre"><?= P::e($f['sobre']) ?></div>
            <?php if ($f['antes'] !== null || $f['despues'] !== null): ?>
              <div class="cambio">
                <?php if ($f['antes'] !== null): ?>
                  <span class="antes"><?= P::e(mb_substr($f['antes'], 0, 120)) ?></span>
                <?php endif; ?>
                <?php if ($f['antes'] !== null && $f['despues'] !== null): ?>
                  <span class="fl">→</span>
                <?php endif; ?>
                <?php if ($f['despues'] !== null): ?>
                  <span class="desp"><?= P::e(mb_substr($f['despues'], 0, 120)) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php
            // Operaciones en lote: la lista de lo que tocaron, desplegable.
            $det = !empty($f['detalle']) ? json_decode($f['detalle'], true) : null;
            if (is_array($det) && (!empty($det['comisiones']) || !empty($det['omitidas']))):
                $porVenta = [];
                foreach ((array)($det['comisiones'] ?? []) as $c) {
                    $vid = (int)($c['venta'] ?? 0);
                    if (!isset($porVenta[$vid])) {
                        $porVenta[$vid] = ['folio' => $c['folio'] ?? ('#' . $vid),
                                           'cliente' => $c['cliente'] ?? '', 'monto' => 0.0, 'filas' => [],
                                           // Venta de antes del periodo: el abono o la
                                           // liquidación que trajo su comisión aquí.
                                           'anterior' => !empty($c['anterior']),
                                           'fecha'    => $c['fecha_venta'] ?? '',
                                           'cobro'    => $c['cobro'] ?? ''];
                    }
                    $porVenta[$vid]['filas'][] = $c;
                    $porVenta[$vid]['monto'] += (float)($c['monto'] ?? 0);
                }
                $nC = count((array)($det['comisiones'] ?? []));
                $nV = count($porVenta);
                $nAntV = count(array_filter($porVenta, function ($pv) { return $pv['anterior']; }));
                $om = (array)($det['omitidas'] ?? []); ?>
              <details class="lf-audit-det">
                <summary>
                  Ver <?= $nV ?> venta<?= $nV === 1 ? '' : 's' ?>
                  · <?= $nC ?> comisi<?= $nC === 1 ? 'ón' : 'ones' ?>
                  <?php if (isset($det['monto'])): ?> · <?= D::pesos($det['monto']) ?><?php endif; ?>
                  <?php if ($nAntV): ?> · <span class="ant"><?= $nAntV ?> de meses anteriores</span><?php endif; ?>
                  <?php if ($om): ?> · <span class="om"><?= count($om) ?> sin asignar</span><?php endif; ?>
                </summary>
                <?php if (isset($det['en_periodo'])): ?>
                  <p class="nota">
                    <?= D::pesos($det['monto'] ?? 0) ?> es lo que tocaría si los clientes liquidan todo;
                    <b><?= D::pesos($det['en_periodo']) ?></b> ya se generó con cobros del periodo
                    <?php if (!empty($det['desde'])): ?>(<?= date('d/m/Y', strtotime($det['desde'])) ?> al
                      <?= date('d/m/Y', strtotime($det['hasta'])) ?>)<?php endif; ?>
                    y es lo que cuenta en Comisiones y Reportes de ese periodo.
                  </p>
                <?php endif; ?>
                <?php if ($porVenta): ?>
                <ul class="ventas">
                  <?php foreach ($porVenta as $vid => $pv): ?>
                    <li>
                      <div class="v">
                        <?php if ($vid > 0): ?>
                          <a href="/ventas/<?= $vid ?>" data-modal class="lf-mono"><?= P::e($pv['folio']) ?></a>
                        <?php else: ?>
                          <span class="lf-mono"><?= P::e($pv['folio']) ?></span>
                        <?php endif; ?>
                        <span class="cli"><?= P::e($pv['cliente']) ?></span>
                        <b class="lf-mono"><?= D::pesos($pv['monto']) ?></b>
                      </div>
                      <?php if ($pv['anterior']): ?>
                        <span class="ant-v">
                          <b>Venta del <?= $pv['fecha'] ? date('d/m/Y', strtotime($pv['fecha'])) : 'mes anterior' ?></b>
                          <?= $pv['cobro'] !== '' ? '· ' . P::e($pv['cobro']) : '' ?>
                        </span>
                      <?php endif; ?>
                      <?php foreach ($pv['filas'] as $c): ?>
                        <div class="c">
                          <span><?= P::e($c['colaborador'] ?? '') ?> · <?= $pctTxt($c['pct'] ?? 0) ?>
                            <small><?= P::e($c['producto'] ?? '') ?><?php
                              if (isset($c['en_periodo'])): ?> · en el periodo
                                <?= D::pesos($c['en_periodo']) ?><?php endif; ?></small></span>
                          <span class="lf-mono"><?= D::pesos($c['monto'] ?? 0) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($om): ?>
                  <p class="om-t">No se asignaron</p>
                  <ul class="omit">
                    <?php foreach ($om as $o): ?>
                      <li><b class="lf-mono"><?= P::e($o[0] ?? '') ?></b><span><?= P::e($o[1] ?? '') ?></span></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </details>
            <?php endif; ?>
            <?php if ($f['ip']): ?>
              <div class="ip"><?= P::e($f['usuario_rol']) ?> · <?= P::e($f['ip']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($filas) ?> de <?= number_format($total) ?></span>
      <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
        'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
    </div>
  </section>

  <div>
    <?php if ($porUsuario): ?>
    <section class="card">
      <header class="card-header">Quién movió más</header>
      <div class="card-body">
        <?php $mx = 0; foreach ($porUsuario as $u) $mx = max($mx, (int)$u['cuantos']);
        foreach ($porUsuario as $u): ?>
          <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
              <b style="font-weight:600"><?= P::e($u['usuario_nombre'] ?: 'Sistema') ?></b>
              <span class="lf-mono" style="color:var(--lf-tinta-3)"><?= (int)$u['cuantos'] ?></span>
            </div>
            <?php W::avance($mx ? $u['cuantos'] / $mx * 100 : 0); ?>
          </div>
        <?php endforeach; ?>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:4px;padding-top:12px;
                  border-top:1px solid var(--lf-linea);line-height:1.5">
          Muchos movimientos no es malo: quien más trabaja más aparece. Lo que se
          revisa es el <b>tipo</b> de movimiento, no la cantidad.
        </p>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($porAccion): ?>
    <section class="card">
      <header class="card-header">Qué se hizo</header>
      <div style="padding:0 10px 8px">
        <?php foreach ($porAccion as $a): ?>
          <a class="lf-row" href="<?= P::e($qs(['accion'=>$a['accion'],'p'=>null])) ?>">
            <span style="flex:1;min-width:0;font-size:12.5px">
              <?= P::e(A::ACCIONES[$a['accion']] ?? $a['accion']) ?></span>
            <b class="lf-mono" style="font-size:13px"><?= (int)$a['cuantos'] ?></b>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
