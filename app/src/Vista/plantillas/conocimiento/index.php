<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\BaseConocimientoRepo as B;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;
$cf = $cifras;
$color = ['articulo'=>'bg-secondary','plantilla'=>'bg-success','error'=>'bg-warning'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($abrir): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 36%,transparent)">
  <header class="card-header">
    <div>
      <span><?= P::e($abrir['titulo']) ?></span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:3px;font-weight:400">
        <?= P::e(B::TIPOS[$abrir['tipo']] ?? '') ?> ·
        <?= P::e(B::AREAS[$abrir['area']] ?? '') ?> ·
        <?= (int)$abrir['vistas'] ?> vistas</p>
    </div>
    <a class="btn btn-secondary btn-sm" href="/conocimiento">Cerrar</a>
  </header>
  <div class="card-body">
    <?php if ($abrir['sintoma']): ?>
      <div style="padding:11px 14px;border-radius:var(--lf-r);background:var(--lf-amb-soft);
           color:var(--lf-amb);font-size:12.5px;margin-bottom:16px;line-height:1.5">
        <b>Se ve así:</b> <?= P::e($abrir['sintoma']) ?></div>
    <?php endif; ?>
    <div style="font-size:13.5px;line-height:1.7;color:var(--lf-tinta-2);overflow-wrap:anywhere">
      <?= nl2br(P::e($abrir['cuerpo'])) ?></div>
    <?php if ($abrir['tipo'] === 'plantilla'): ?>
      <button type="button" class="btn btn-primary btn-sm" id="copiar" style="margin-top:16px">
        Copiar al portapapeles</button>
      <textarea id="txtCopia" hidden><?= P::e($abrir['cuerpo']) ?></textarea>
    <?php endif; ?>
    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;padding-top:14px;
              border-top:1px solid var(--lf-linea)">
      Escrito por <?= P::e($abrir['autor_nombre'] ?: 'alguien del equipo') ?>,
      <?= date('d/m/Y', strtotime($abrir['creado_en'])) ?>
      <?php if ($abrir['actualizado_en']): ?>
        · actualizado el <?= date('d/m/Y', strtotime($abrir['actualizado_en'])) ?><?php endif; ?>
      · <a href="/conocimiento?editar=<?= (int)$abrir['id'] ?>">Editar</a>
    </p>
  </div>
</section>
<script>
(function(){
  var b = document.getElementById('copiar'), t = document.getElementById('txtCopia');
  if (!b || !t) return;
  b.addEventListener('click', function(){
    navigator.clipboard.writeText(t.value).then(function(){
      b.textContent = 'Copiado'; setTimeout(function(){ b.textContent = 'Copiar al portapapeles'; }, 1800);
    }).catch(function(){ b.textContent = 'No se pudo copiar'; });
  });
})();
</script>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['total'] ?? 0) ?></div>
    <div class="stat-label">Entradas</div>
    <div class="stat-meta"><?= (int)($cf['articulos'] ?? 0) ?> artículos ·
      <?= (int)($cf['errores'] ?? 0) ?> errores conocidos</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['plantillas'] ?? 0) ?></div>
    <div class="stat-label">Plantillas</div>
    <div class="stat-meta">respuestas listas para copiar</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($cf['sin_usar'] ?? 0) ?></div>
    <div class="stat-label">Nunca consultadas</div>
    <div class="stat-meta">o están mal tituladas, o sobran</div>
  </div>
</div>

<details class="lf-alta" <?= $e ? 'open' : '' ?>>
  <summary><?= W::icono($e?'cliente':'mas','16px') ?>
    <?= $e ? 'Editar entrada' : 'Escribir una entrada' ?></summary>
  <form method="post" action="/conocimiento/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="width:180px"><label class="form-label">Tipo</label>
      <select class="form-select" name="tipo" id="selTipo">
        <?php foreach (B::TIPOS as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($e['tipo'] ?? '')===$k?'selected':'' ?>><?= P::e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="width:210px"><label class="form-label">Área</label>
      <select class="form-select" name="area">
        <?php foreach (B::AREAS as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($e['area'] ?? '')===$k?'selected':'' ?>><?= P::e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="flex:1;min-width:250px"><label class="form-label">Título</label>
      <input class="form-control" name="titulo" value="<?= P::e($e['titulo'] ?? '') ?>" required
             placeholder="Cómo lo llamaría quien lo necesita"></div>
    <div style="width:100%" id="campoSintoma">
      <label class="form-label">Cómo se ve el problema</label>
      <input class="form-control" name="sintoma" value="<?= P::e($e['sintoma'] ?? '') ?>"
             placeholder="&quot;Mis ventas de fin de mes salen en el mes siguiente&quot;">
      <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:5px">
        Nadie busca "zona horaria": buscan el síntoma. Esto es lo que hace que se encuentre.
      </p>
    </div>
    <div style="width:100%"><label class="form-label">Contenido</label>
      <textarea class="form-control lf-desc" name="cuerpo" rows="8" required
                placeholder="Los pasos, completos. Si hay que correr SQL, ponlo tal cual."><?= P::e($e['cuerpo'] ?? '') ?></textarea></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Publicar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/conocimiento">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>
<script>
(function(){
  // El síntoma solo aplica a errores conocidos y plantillas no lo usan.
  var s = document.getElementById('selTipo'), c = document.getElementById('campoSintoma');
  if (!s || !c) return;
  function ver(){ c.style.display = s.value === 'plantilla' ? 'none' : ''; }
  s.addEventListener('change', ver); ver();
})();
</script>

<form class="lf-filtros" method="get">
  <div class="lf-search" style="max-width:300px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q" value="<?= P::e($q) ?>"
           placeholder="Busca por el síntoma, no por el nombre">
  </div>
  <select class="form-select form-select-sm" name="tipo" style="width:auto">
    <option value="">Todo tipo</option>
    <?php foreach (B::TIPOS as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $tipo===$k?'selected':'' ?>><?= P::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="area" style="width:auto">
    <option value="">Toda área</option>
    <?php foreach (B::AREAS as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $area===$k?'selected':'' ?>><?= P::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
</form>

<?php $qsC = function ($x = []) use ($q, $tipo, $area) {
  return '?' . http_build_query(array_merge(array_filter(['q'=>$q,'tipo'=>$tipo,'area'=>$area]),
    array_filter($x, function($z){ return $z !== null; }))); }; ?>

<div class="lf-docs">
  <?php if (!$filas): ?>
    <p style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:34px">
      <?= $q ? 'Nada coincide con esa búsqueda.' : 'Todavía no hay entradas. Escribe la primera.' ?></p>
  <?php endif; ?>
  <?php foreach ($filas as $f): ?>
    <section class="card lf-doc">
      <div class="card-body">
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:9px;flex-wrap:wrap">
          <span class="badge <?= $color[$f['tipo']] ?? 'bg-secondary' ?>">
            <?= P::e(B::TIPOS[$f['tipo']] ?? '') ?></span>
          <span style="font-size:11px;color:var(--lf-tinta-4)">
            <?= P::e(B::AREAS[$f['area']] ?? '') ?></span>
          <span style="margin-left:auto;font-size:11px;color:var(--lf-tinta-4);font-family:var(--lf-mono)">
            <?= (int)$f['vistas'] ?> ↗</span>
        </div>
        <a href="/conocimiento?ver=<?= (int)$f['id'] ?>"
           style="font-size:14px;font-weight:600;color:var(--lf-tinta);display:block;line-height:1.4">
          <?= P::e($f['titulo']) ?></a>
        <?php if ($f['sintoma']): ?>
          <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:6px;line-height:1.5">
            <?= P::e(mb_substr($f['sintoma'], 0, 110)) ?></p>
        <?php endif; ?>
        <div style="display:flex;gap:8px;margin-top:auto;padding-top:13px">
          <a class="btn btn-secondary btn-sm" href="/conocimiento?ver=<?= (int)$f['id'] ?>"
             style="flex:1">Abrir</a>
          <a class="btn btn-secondary btn-sm" href="/conocimiento?editar=<?= (int)$f['id'] ?>">Editar</a>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<?php if ($paginas > 1): ?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;
     flex-wrap:wrap;margin-top:18px;font-size:12.5px;color:var(--lf-tinta-4)">
  <span><?= count($filas) ?> de <?= number_format($totalC) ?></span>
  <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
    'enlace'=>function($n) use ($qsC){ return $qsC(['p'=>$n]); }]); ?>
</div>
<?php endif; ?>
