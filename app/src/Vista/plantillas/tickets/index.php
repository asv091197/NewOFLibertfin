<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\TicketRepo as T;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$cf = $cifras;
$colorP = ['critica'=>'bg-danger','alta'=>'bg-warning','normal'=>'bg-secondary','baja'=>'bg-secondary'];
$qs = function ($cambios = []) use ($filtros, $mios) {
    $b = array_filter(['estado'=>$filtros['estado'],'prioridad'=>$filtros['prioridad'],
                       'q'=>$filtros['q'],'mios'=>$mios?1:null]);
    return '?' . http_build_query(array_merge($b, $cambios));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['activos'] ?? 0) ?></div>
    <div class="stat-label">Tickets abiertos</div>
    <div class="stat-meta"><?= (int)($cf['sin_tocar'] ?? 0) ?> sin tocar todavía</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['criticos'] ?? 0) ?></div>
    <div class="stat-label">Críticos</div>
    <div class="stat-meta">no pueden cobrar ni facturar</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?php
      $m = (float)($cf['min_respuesta'] ?? 0);
      echo $m > 0 ? ($m < 60 ? round($m) . ' min' : round($m/60, 1) . ' h') : '—'; ?></div>
    <div class="stat-label">Primera respuesta</div>
    <div class="stat-meta">promedio</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?php
      $h = (float)($cf['horas_resolucion'] ?? 0);
      echo $h > 0 ? ($h < 24 ? round($h,1) . ' h' : round($h/24,1) . ' d') : '—'; ?></div>
    <div class="stat-label">Hasta resolver</div>
    <div class="stat-meta"><?= (int)($cf['resueltos'] ?? 0) ?> resueltos</div>
  </div>
</div>

<details class="lf-alta">
  <summary><?= W::icono('mas','16px') ?>Nuevo ticket</summary>
  <form method="post" action="/tickets/crear" class="lf-form" enctype="multipart/form-data">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="flex:2;min-width:240px"><label class="form-label">Asunto</label>
      <input class="form-control" name="asunto" required
             placeholder="Qué pasa, en una línea"></div>
    <div style="flex:1;min-width:190px"><label class="form-label">Empresa</label>
      <select class="form-select" name="empresa_id">
        <option value="">Sin empresa</option>
        <?php foreach ($empresas as $e): ?>
          <option value="<?= (int)$e['id'] ?>"><?= P::e($e['nombre_empresa']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="width:180px"><label class="form-label">Categoría</label>
      <select class="form-select" name="categoria">
        <?php foreach (T::CATEGORIAS as $k=>$v): ?>
          <option value="<?= $k ?>"><?= P::e($v) ?></option><?php endforeach; ?>
      </select></div>
    <div style="width:160px"><label class="form-label">Prioridad</label>
      <select class="form-select" name="prioridad">
        <?php foreach (T::PRIORIDADES as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $k==='normal'?'selected':'' ?>>
            <?= P::e($v[0]) ?></option><?php endforeach; ?>
      </select></div>
    <div style="width:100%"><label class="form-label">Qué pasó y qué esperaba</label>
      <textarea class="form-control lf-desc" name="cuerpo" rows="3" required
                placeholder="Pasos para reproducirlo, qué vio y qué esperaba ver"></textarea></div>
    <div style="width:100%"><label class="form-label">Evidencia <span style="font-weight:400;color:var(--lf-tinta-4)">(opcional)</span></label>
      <span class="lf-file">
        <input type="file" name="adjunto" id="adjTicket"
               accept="image/png,image/jpeg,image/webp,application/pdf">
        <label class="bt" for="adjTicket">Adjuntar captura o archivo</label>
        <span class="n" data-vacio="Ningún archivo elegido">Ningún archivo elegido</span>
      </span></div>
    <button class="btn btn-primary" type="submit">Crear ticket</button>
  </form>
</details>

<div class="lf-pills" style="margin-bottom:14px">
  <a class="lf-pill <?= $filtros['estado']==='activos'?'active':'' ?>"
     href="<?= P::e($qs(['estado'=>'activos','p'=>null])) ?>">Activos</a>
  <?php foreach (T::ESTADOS as $k=>$v): ?>
    <a class="lf-pill <?= $filtros['estado']===$k?'active':'' ?>"
       href="<?= P::e($qs(['estado'=>$k,'p'=>null])) ?>"><?= P::e($v) ?></a>
  <?php endforeach; ?>
  <a class="lf-pill <?= $mios?'active':'' ?>"
     href="<?= P::e($qs(['mios'=>$mios?null:1,'p'=>null])) ?>">Míos</a>
</div>

<form class="lf-filtros" method="get">
  <input type="hidden" name="estado" value="<?= P::e($filtros['estado']) ?>">
  <?php if ($mios): ?><input type="hidden" name="mios" value="1"><?php endif; ?>
  <div class="lf-search" style="max-width:290px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q"
           value="<?= P::e($filtros['q']) ?>" placeholder="Folio, asunto o empresa">
  </div>
  <select class="form-select form-select-sm" name="prioridad" style="width:auto">
    <option value="">Toda prioridad</option>
    <?php foreach (T::PRIORIDADES as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $filtros['prioridad']===$k?'selected':'' ?>>
        <?= P::e($v[0]) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Bandeja</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Por prioridad y luego por antigüedad: lo urgente no espera su turno</p></div>
    </header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Ticket</th><th>Empresa</th><th>Prioridad</th>
          <th>Estado</th><th>Tiempo</th><th>Quién</th></tr></thead>
        <tbody>
        <?php if (!$tickets): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
            No hay tickets con esos filtros.</td></tr>
        <?php endif; ?>
        <?php foreach ($tickets as $t):
          $min  = (int)$t['min_respuesta']; ?>
          <tr>
            <td data-label="Ticket">
              <a data-lf-fila href="/tickets/<?= (int)$t['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
                <?= P::e($t['asunto']) ?></a>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
                <?= P::e($t['folio']) ?> ·
                <?= P::e(T::CATEGORIAS[$t['categoria']] ?? $t['categoria']) ?> ·
                <?= (int)$t['mensajes'] ?> msj</span>
            </td>
            <td data-label="Empresa" style="font-size:12.5px">
              <?= $t['empresa_id']
                  ? '<a href="/soporte/' . (int)$t['empresa_id'] . '">' . P::e($t['nombre_empresa']) . '</a>'
                  : '<span style="color:var(--lf-tinta-4)">—</span>' ?></td>
            <td data-label="Prioridad">
              <span class="badge <?= $colorP[$t['prioridad']] ?? 'bg-secondary' ?>">
                <?= P::e(T::PRIORIDADES[$t['prioridad']][0] ?? $t['prioridad']) ?></span></td>
            <td data-label="Estado" style="font-size:12.5px">
              <?= P::e(T::ESTADOS[$t['estado']] ?? $t['estado']) ?></td>
            <td data-label="Tiempo">
              <?php /* Cuánto tardó la primera respuesta; sin tiempo comprometido. */
                    if ($t['primera_respuesta_en']): ?>
                <span class="badge bg-success">
                  <?= $min < 60 ? $min . ' min' : round($min/60,1) . ' h' ?></span>
              <?php else: ?>
                <span class="badge bg-secondary">sin responder</span>
              <?php endif; ?>
            </td>
            <td data-label="Quién" style="font-size:12px">
              <?= $t['asignado_nombre']
                  ? P::e($t['asignado_nombre'])
                  : '<span style="color:var(--lf-tinta-4)">sin asignar</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($tickets) ?> de <?= number_format($totalT) ?></span>
      <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
        'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <div><span>De qué se queja la gente</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Lo que más se repite es lo que hay que arreglar en el producto</p></div>
    </header>
    <div class="card-body">
      <?php if (!$categorias): ?>
        <p style="font-size:12.5px;color:var(--lf-tinta-4);margin:0">Todavía no hay tickets.</p>
      <?php endif; ?>
      <?php
      $mayor = 0; foreach ($categorias as $c) $mayor = max($mayor, (int)$c['cuantos']);
      foreach ($categorias as $c): ?>
        <div style="margin-bottom:13px">
          <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
            <b style="font-weight:600"><?= P::e(T::CATEGORIAS[$c['categoria']] ?? $c['categoria']) ?></b>
            <span class="lf-mono" style="color:var(--lf-tinta-3)">
              <?= (int)$c['cuantos'] ?>
              <?php if ((int)$c['abiertos']): ?>
                <span style="color:var(--lf-amb)">· <?= (int)$c['abiertos'] ?> abiertos</span>
              <?php endif; ?></span>
          </div>
          <?php W::avance($mayor ? $c['cuantos'] / $mayor * 100 : 0, (int)$c['abiertos'] > 0); ?>
          <?php if ($c['horas']): ?>
            <small style="font-size:10.5px;color:var(--lf-tinta-4)">
              se resuelven en <?= round($c['horas'],1) ?> h en promedio</small>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
