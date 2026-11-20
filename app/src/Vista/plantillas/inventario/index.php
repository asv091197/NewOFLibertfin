<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Inventario as I;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$varias = count($sucursales) > 1;
$conU = function ($n, $u) { return I::conUnidad($n, $u); };
$estados = [0 => ['Agotado', 'bg-danger'], 1 => ['Bajo su mínimo', 'bg-warning'], 2 => ['Bien', 'bg-success']];
// Para el value de un <input type="number">: sin separador de miles.
// I::num() pone «1,250» y el navegador deja vacío lo que no es número,
// así que un conteo de mil piezas o más se veía borrado al volver.
$numIn = function ($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); };
// Piso, Bodega…: una columna por almacén, solo si la sucursal tiene más de uno.
$verAlm = count($almacenes ?? []) > 1;
$principalId = 0;
foreach (($almacenes ?? []) as $a) if (!empty($a['es_principal'])) $principalId = (int)$a['id'];
// Los filtros vigentes, para que los enlaces (PDF, pestañas) no los pierdan.
$qFiltro = array_filter(['marca' => $filtro['marca'] ?? '', 'area' => (int)($filtro['area'] ?? 0) ?: '',
                         'tipo' => $filtro['tipo'] ?? ''], function ($v) { return $v !== ''; });
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$activo): /* ═══════════ APAGADO ═══════════ */ ?>
<section class="card lf-inv-apagado">
  <div class="card-body">
    <span class="lf-tile g"><?= W::icono('bolsa','22px') ?></span>
    <h3>El inventario está apagado</h3>
    <p>Sirve para las empresas que venden productos físicos: lleva las existencias de cada sucursal,
      Caja las descuenta al cobrar y avisa cuando algo se está acabando. Si solo vendes servicios,
      déjalo así.</p>
    <?php if ($puedeAjustes): ?>
      <a class="btn btn-primary" href="/ajustes?t=inventario">Encenderlo en Ajustes</a>
    <?php else: ?>
      <p class="pista">Lo enciende un administrador en Ajustes → Inventario.</p>
    <?php endif; ?>
  </div>
</section>
<?php return; endif; ?>

<?php /* ═══════════ PESTAÑAS Y SUCURSAL ═══════════ */ ?>
<div class="lf-inv-cab">
  <div class="lf-pills" role="tablist">
    <a class="lf-pill <?= $pestana === 'existencias' ? 'active' : '' ?>" role="tab"
       href="/inventario?s=<?= (int)$sucursal ?>">Existencias</a>
    <a class="lf-pill <?= $pestana === 'movimientos' ? 'active' : '' ?>" role="tab"
       href="/inventario?t=movimientos&amp;s=<?= (int)$sucursal ?>">Movimientos</a>
    <?php if ($puedeEditar): ?>
    <a class="lf-pill <?= $pestana === 'alertas' ? 'active' : '' ?>" role="tab"
       href="/inventario?t=alertas&amp;s=<?= (int)$sucursal ?>">Avisos</a>
    <a class="lf-pill <?= $pestana === 'conteo' ? 'active' : '' ?>" role="tab"
       href="/inventario?t=conteo&amp;s=<?= (int)$sucursal ?>">Conteo físico</a>
    <?php endif; ?>
  </div>
  <?php /* Capturar con la pistola. Va junto a las pestañas y no entre
           los botones de cada producto porque es lo contrario: no es
           mover UN producto, es pasar veinte seguidos sin soltar el
           lector. Si hay una captura a medias, el botón lo dice: es la
           única forma de que quien la dejó pausada se acuerde. */
  if ($puedeEditar && !empty($activo)): ?>
    <div class="lf-inv-captura" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?php if (!empty($capturaAbierta)): ?>
        <a class="btn btn-primary btn-sm" href="/inventario/captura?s=<?= (int)$sucursal ?>">
          <?= W::icono('caja','14px') ?>
          <?= $capturaAbierta['estado'] === 'pausada' ? 'Seguir' : 'Volver a' ?>
          la <?= P::e(mb_strtolower($capturaAbierta['tipo_rotulo'])) ?>
          (<?= P::e(\LibertyFin\Servicio\Captura::num($capturaAbierta['piezas'])) ?>)
        </a>
      <?php else: ?>
        <form method="post" action="/inventario/captura" style="display:flex;gap:6px">
          <input type="hidden" name="token" value="<?= P::e($token ?? '') ?>">
          <input type="hidden" name="accion" value="iniciar">
          <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>">
          <button class="btn btn-secondary btn-sm" name="tipo" value="entrada" type="submit"
                  title="Capturar varias con el lector de códigos">
            <?= W::icono('caja','14px') ?> Entrada con pistola</button>
          <button class="btn btn-secondary btn-sm" name="tipo" value="salida" type="submit"
                  title="Capturar varias con el lector de códigos">Salida con pistola</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($varias && $pestana !== 'movimientos'): ?>
    <div class="lf-segmento" aria-label="Sucursal">
      <?php foreach ($sucursales as $s): ?>
        <a class="lf-inv-suc<?= (int)$s['id'] === (int)$sucursal ? ' on' : '' ?>"
           href="/inventario?<?= $pestana === 'conteo' ? 't=conteo&amp;' : '' ?>s=<?= (int)$s['id'] ?>"><?= P::e($s['nombre']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($pestana === 'existencias'): /* ═══════════ EXISTENCIAS ═══════════ */ ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['productos']) ?></div>
    <div class="stat-label">Productos con existencias</div>
    <div class="stat-meta">los marcados en Productos</div></div>
  <div class="stat-card"><div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['agotados']) ?></div>
    <div class="stat-label">Agotados</div>
    <div class="stat-meta">en cero o menos</div></div>
  <div class="stat-card"><div class="lf-tile a"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['bajos']) ?></div>
    <div class="stat-label">Bajo su mínimo</div>
    <div class="stat-meta">hay que resurtir</div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['valor']) ?></div>
    <div class="stat-label">Valor a costo</div>
    <div class="stat-meta">lo que hay × su costo</div></div>
</div>

<?php if ($puedeEditar && $productos): $tipoSel = $mover ?: 'entrada'; ?>
<details class="lf-alta" id="invMover" <?= $mover ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?> Registrar entrada, salida o conteo</summary>
  <form method="post" action="/inventario/mover" class="lf-form lf-inv-form" id="formMover"
        data-principal="<?= (int)$principalId ?>"
        data-almacenes="<?= P::e(json_encode((object)array_column(array_map(function ($a) {
            return ['id' => (string)(int)$a['id'], 'n' => $a['nombre']]; }, $almacenes ?? []), 'n', 'id'))) ?>">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if (!$varias): ?><input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>"><?php endif; ?>

    <div class="lf-inv-tipos">
      <span class="form-label" id="etTipo">¿Qué pasó?</span>
      <div class="lf-segmento" role="radiogroup" aria-labelledby="etTipo">
        <?php
        // El traspaso aparece si hay a dónde: otro almacén de esta misma
        // tienda (piso ↔ bodega) o bien otra sucursal.
        $hayDestino = count($almacenesTodos ?? []) > 1;
        foreach (['entrada' => 'Llegó mercancía', 'salida' => 'Salió sin venderse',
                  'merma'   => 'Se perdió o se dañó', 'conteo' => 'Lo conté']
                 + ($hayDestino ? ['traspaso' => 'Lo pasé a otro lado'] : []) as $k => $t): ?>
          <label><input type="radio" name="tipo" value="<?= $k ?>" <?= $tipoSel === $k ? 'checked' : '' ?>><?= P::e($t) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="pista" id="ayudaTipo"></p>
    </div>

    <?php /* ── Buscador, no lista desplegable ──
             Con cuatro productos una lista está bien. Con cuatrocientos,
             encontrar «Refresco 600ml» entre todos obliga a soltar el
             escáner y bajar con el ratón. Esto es un campo donde se
             escribe parte del nombre, el SKU o el código de barras —o se
             dispara el escáner, que teclea el código y manda Enter.
             El `producto_id` lo resuelve el JavaScript; si no corre, el
             texto viaja igual y lo resuelve el servidor. */
    $sel = null;
    foreach ($productos as $p) if ((int)$producto === (int)$p['id']) $sel = $p;
    ?>
    <div style="flex:2;min-width:240px">
      <label class="form-label" for="mBuscar">Producto</label>
      <input class="form-control" id="mBuscar" name="producto_buscar" list="mLista"
             autocomplete="off" required
             placeholder="Nombre, código o escanéalo"
             value="<?= $sel ? P::e($sel['codigo'] . ' · ' . $sel['nombre']) : '' ?>">
      <input type="hidden" name="producto_id" id="mProd" value="<?= $sel ? (int)$sel['id'] : '' ?>">
      <datalist id="mLista">
        <?php foreach ($productos as $p):
          /* Lo que hay en CADA almacén: el conteo y la salida mueven uno
             solo, y la vista previa tiene que hablar de ése, no del total. */
          $enAlm = [];
          foreach (($porAlmacen[(int)$p['id']] ?? []) as $aid => $v) $enAlm[(string)$aid] = (float)$v; ?>
          <option value="<?= P::e($p['codigo'] . ' · ' . $p['nombre']) ?>"
                  data-id="<?= (int)$p['id'] ?>"
                  data-hay="<?= P::e($numIn($p['existencia'])) ?>"
                  data-alm="<?= P::e(json_encode((object)$enAlm)) ?>"
                  data-u="<?= P::e(I::abrev($p['unidad_medida'])) ?>"><?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></option>
        <?php endforeach; ?>
      </datalist>
    </div>

    <?php /* ── En qué almacén ── */
    if (count($almacenes) > 1): ?>
    <div style="width:170px">
      <label class="form-label" for="mAlm"><span data-et="origen">En qué almacén</span></label>
      <select class="form-select" name="almacen_id" id="mAlm">
        <?php foreach ($almacenes as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $a['es_principal'] ? 'selected' : '' ?>>
            <?= P::e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($varias): ?>
    <div style="width:170px">
      <label class="form-label" for="mSuc">Sucursal</label>
      <select class="form-select" name="sucursal_id" id="mSuc">
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$sucursal ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php /* El destino del traspaso: cualquier almacén, de esta tienda o
             de otra. Piso → Bodega y Matriz → Centro son el mismo gesto. */
    if ($hayDestino): ?>
    <div style="width:220px" data-ver-tipo="traspaso">
      <label class="form-label" for="mDest">A dónde pasa</label>
      <select class="form-select" name="almacen_destino" id="mDest">
        <?php
        $sucAnt = null;
        foreach ($almacenesTodos as $a):
          if ($sucAnt !== null && $sucAnt !== $a['sucursal']) echo '</optgroup>';
          if ($sucAnt !== $a['sucursal']) { echo '<optgroup label="' . P::e($a['sucursal']) . '">'; $sucAnt = $a['sucursal']; }
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= P::e($a['nombre']) ?></option>
        <?php endforeach; if ($sucAnt !== null) echo '</optgroup>'; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php /* Por qué se perdió. Obligatorio: «salida» sin motivo no deja
             contestar cuánto se está tirando ni por qué. */ ?>
    <div style="width:190px" data-ver-tipo="merma">
      <label class="form-label" for="mMotivo">Por qué</label>
      <select class="form-select" name="motivo" id="mMotivo">
        <?php foreach (I::MOTIVOS_MERMA as $k => $t): ?>
          <option value="<?= P::e($k) ?>"><?= P::e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:140px">
      <label class="form-label" for="mCant" id="lblCant">Cuántas llegaron</label>
      <input class="form-control lf-mono" type="number" name="cantidad" id="mCant" step="0.001" min="0" required>
    </div>
    <div style="width:140px" data-ver-tipo="entrada">
      <label class="form-label" for="mCosto">Costo c/u <small>(opcional)</small></label>
      <input class="form-control lf-mono" type="number" name="costo" id="mCosto" step="0.01" min="0" placeholder="0.00">
    </div>
    <?php if ($proveedores): ?>
    <div style="width:190px" data-ver-tipo="entrada">
      <label class="form-label" for="mProv">Proveedor <small>(opcional)</small></label>
      <select class="form-select" name="proveedor_id" id="mProv">
        <option value="">—</option>
        <?php foreach ($proveedores as $pv): ?>
          <option value="<?= (int)$pv['id'] ?>"><?= P::e($pv['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="mNota" id="lblNota">Nota <small>(opcional)</small></label>
      <input class="form-control" name="nota" id="mNota" maxlength="255" placeholder="Factura, remisión…">
    </div>
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px">
      <button class="btn btn-primary" type="submit">Registrar</button>
      <span class="lf-inv-queda" id="mQueda" aria-live="polite"></span>
    </div>
  </form>
</details>
<?php endif; ?>

<?php $qExist = array_merge(['que' => 'existencias', 's' => (int)$sucursal], $qFiltro,
        array_filter(['q' => $buscar, 'bajos' => $bajos ? '1' : ''], function ($v) { return $v !== ''; })); ?>
<form class="lf-filtros" method="get" action="/inventario">
  <input type="hidden" name="s" value="<?= (int)$sucursal ?>">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Producto, código o código de barras">
  </div>
  <?php P::parcial('parciales/filtros-producto', ['opcionesFiltro' => $opcionesFiltro, 'filtro' => $filtro]); ?>
  <label class="lf-inv-check"><input type="checkbox" name="bajos" value="1" <?= $bajos ? 'checked' : '' ?>
         onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"> Solo lo que falta</label>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <?php if ($buscar !== '' || $bajos || $qFiltro): ?>
    <a class="lf-link" href="/inventario?s=<?= (int)$sucursal ?>">Quitar filtros</a>
  <?php endif; ?>
  <span style="flex:1"></span>
  <?php /* El reporte sale con los mismos filtros que la tabla: lo que se
           ve es lo que se imprime. */ ?>
  <a class="btn btn-secondary btn-sm" href="/inventario/imprimir?<?= P::e(http_build_query($qExist + ['auto' => 1])) ?>"
     target="_blank" rel="noopener" title="Reporte de existencias para imprimir o guardar como PDF">
    <?= W::icono('descargar','14px') ?> Reporte PDF</a>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-inv-tabla">
      <thead><tr>
        <th>Producto</th>
        <?php if ($verAlm): foreach ($almacenes as $a): ?>
          <th class="text-end" title="Lo que hay en <?= P::e($a['nombre']) ?>"><?= P::e($a['nombre']) ?></th>
        <?php endforeach; endif; ?>
        <th class="text-end"><?= $verAlm ? 'Total' . ($varias ? ' sucursal' : '') : 'Hay' ?></th>
        <th class="text-end">Mínimo</th><th>Estado</th>
        <?php if ($puedeEditar): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$listado): ?>
        <tr><td colspan="<?= 5 + ($verAlm ? count($almacenes) : 0) ?>" class="lf-inv-vacio">
          <?php if ($buscar !== '' || $bajos || $qFiltro): ?>
            Nada coincide con el filtro.
          <?php else: ?>
            Ningún producto lleva existencias todavía.<br>
            En <a href="/servicios">Productos</a>, edita uno y marca <b>«Lleva existencias»</b>.
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($listado as $p): $e = $estados[(int)$p['estado']]; ?>
        <tr>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($p['nombre']) ?></b>
            <small class="lf-inv-cod"><?= P::e($p['codigo']) ?><?php
              foreach (array_filter([$p['marca'] ?? '', $p['categoria'] ?? '']) as $extra): ?> · <?= P::e($extra) ?><?php endforeach; ?></small></td>
          <?php if ($verAlm): foreach ($almacenes as $a):
            $va = $porAlmacen[(int)$p['id']][(int)$a['id']] ?? 0.0; ?>
            <td data-label="<?= P::e($a['nombre']) ?>" class="text-end lf-mono<?= $va < 0 ? ' mal' : '' ?>"
                style="<?= abs($va) < 0.0005 ? 'color:var(--lf-tinta-4)' : '' ?>"><?= P::e(I::num($va)) ?></td>
          <?php endforeach; endif; ?>
          <td data-label="<?= $verAlm ? 'Total' : 'Hay' ?>" class="text-end lf-mono lf-inv-hay<?= (float)$p['existencia'] <= 0 ? ' mal' : '' ?>">
            <?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></td>
          <td data-label="Mínimo" class="text-end lf-mono"><?= (float)$p['minimo'] > 0 ? P::e(I::num($p['minimo'])) : '–' ?></td>
          <td data-label="Estado"><span class="badge <?= $e[1] ?>"><?= $e[0] ?></span></td>
          <?php if ($puedeEditar): ?>
          <td class="lf-inv-acc">
            <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=entrada&amp;producto=<?= (int)$p['id'] ?>#invMover"><span class="lf-signo">+</span> Entrada</a>
            <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=conteo&amp;producto=<?= (int)$p['id'] ?>#invMover">Contar</a>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    Caja descuenta las existencias al cobrar<?= $bloquea ? ' y no deja vender más de lo que hay' : '; si no alcanzan, avisa pero cobra' ?>.
    Cada cambio queda en <a href="/inventario?t=movimientos&amp;s=<?= (int)$sucursal ?>">Movimientos</a>.
  </span></div>
</section>

<?php if ($puedeEditar && $productos): ?>
<script>
/* Lo que cambia según «¿Qué pasó?»: etiquetas, campos y lo que quedará. */
(function(){
  var f = document.getElementById('formMover');
  if (!f) return;
  var AYUDA = {
    entrada: 'Compras o devoluciones de proveedor. Suma a lo que hay.',
    salida:  'Uso interno, regalo, lo que sale sin venderse. Resta y pide el motivo.',
    merma:   'Mercancía que se perdió: dañada, caducada, robada. Se anota aparte de las salidas para poder saber cuánto se tira.',
    conteo:  'Escribe cuántas hay de verdad EN ESE ALMACÉN: el sistema se ajusta a tu conteo y anota la diferencia.',
    traspaso:'Salen de un almacén y entran al otro, en un solo paso. Sirve igual para piso → bodega que para una sucursal a otra.'
  };
  var CANT = { entrada:'Cuántas llegaron', salida:'Cuántas salen', merma:'Cuántas se perdieron',
               conteo:'Cuántas hay', traspaso:'Cuántas pasan' };
  var buscar = document.getElementById('mBuscar'), prod = document.getElementById('mProd'),
      cant = document.getElementById('mCant'), nota = document.getElementById('mNota'),
      queda = document.getElementById('mQueda'), lista = document.getElementById('mLista');

  function tipo(){ var r = f.querySelector('input[name="tipo"]:checked'); return r ? r.value : 'entrada'; }
  function num(n){ return (Math.round(n * 1000) / 1000).toLocaleString('es-MX', {maximumFractionDigits:3}); }

  /* Empareja lo escrito o escaneado con un renglón de la lista. Primero
     exacto —que es lo que manda el escáner— y si no, el único que
     contenga el texto. Con dos candidatos no se elige: la persona
     termina de escribir. */
  function emparejar(){
    var v = (buscar.value || '').trim().toLowerCase();
    if (!v) { prod.value = ''; return null; }
    var ops = lista ? Array.prototype.slice.call(lista.options) : [];
    var exacto = ops.filter(function(o){
      var val = o.value.toLowerCase();
      return val === v || val.split(' · ')[0].toLowerCase() === v;
    });
    var cand = exacto.length ? exacto : ops.filter(function(o){ return o.value.toLowerCase().indexOf(v) >= 0; });
    if (cand.length === 1) { prod.value = cand[0].dataset.id; return cand[0]; }
    prod.value = '';
    return null;
  }

  function pintar(){
    var t = tipo();
    document.getElementById('ayudaTipo').textContent = AYUDA[t] || '';
    document.getElementById('lblCant').textContent = CANT[t] || 'Cuántas';
    f.querySelectorAll('[data-ver-tipo]').forEach(function(el){ el.hidden = el.dataset.verTipo !== t; });
    document.getElementById('lblNota').innerHTML = t === 'salida' ? 'Motivo' : 'Nota <small>(opcional)</small>';
    nota.required = t === 'salida';
    nota.placeholder = t === 'salida' ? 'Uso interno, regalo…'
                     : (t === 'conteo' ? 'Inventario mensual…'
                     : (t === 'merma' ? 'Qué pasó (opcional)' : 'Factura, remisión…'));
    // En traspaso, el almacén de arriba es el ORIGEN.
    var et = f.querySelector('[data-et="origen"]');
    if (et) et.textContent = t === 'traspaso' ? 'De qué almacén' : 'En qué almacén';

    var o = emparejar(), c = parseFloat(cant.value);
    if (!o || isNaN(c)) { queda.textContent = ''; return; }
    var total = parseFloat(String(o.dataset.hay).replace(/,/g, '')) || 0, u = o.dataset.u ? ' ' + o.dataset.u : '';

    /* EL MOVIMIENTO TOCA UN ALMACÉN, NO LA SUCURSAL ENTERA.
       Antes la cuenta se hacía contra el total: con 10 en el piso y 5 en
       la bodega, «Lo conté: 12» prometía «quedarán 12» y la tabla
       terminaba en 17. Ahora se dice en qué almacén y cuánto quedará en
       los dos lados. */
    var alms = {}, nombres = {};
    try { alms = JSON.parse(o.dataset.alm || '{}'); } catch (e) {}
    try { nombres = JSON.parse(f.dataset.almacenes || '{}'); } catch (e) {}
    var selAlm = document.getElementById('mAlm');
    var almId = selAlm ? selAlm.value : (f.dataset.principal || '');
    var variosAlm = Object.keys(nombres).length > 1;
    var hay = variosAlm ? (parseFloat(alms[almId]) || 0) : total;
    var fin = t === 'entrada' ? hay + c : ((t === 'conteo') ? c : hay - c);
    var donde = variosAlm && nombres[almId] ? 'En ' + nombres[almId] + ' ' : '';
    var txt = t === 'conteo'
      ? donde + (donde ? 'el sistema dice ' : 'El sistema dice ') + num(hay) + u + ' → quedará en ' + num(fin) + u
        + (Math.abs(fin - hay) < 0.0005 ? ' (cuadra)' : ' (' + (fin > hay ? '+' : '−') + num(Math.abs(fin - hay)) + ')')
      : (donde ? donde + 'hay ' : 'Hay ') + num(hay) + u + ' → quedarán ' + num(fin) + u;
    if (variosAlm && t !== 'traspaso') {
      txt += ' · en toda la sucursal: ' + num(total) + ' → ' + num(total - hay + fin) + u;
    }
    queda.textContent = txt;
    queda.classList.toggle('mal', fin < 0);
  }

  /* El escáner teclea el código y manda Enter. Sin esto, ese Enter manda
     el formulario sin cantidad. Con esto, salta a la cantidad, que es lo
     siguiente que hay que escribir. */
  buscar.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    if (emparejar()) { cant.focus(); cant.select(); }
    else buscar.select();
  });

  f.addEventListener('change', pintar);
  f.addEventListener('input', pintar);
  pintar();
  if (location.hash === '#invMover') { if (prod.value) cant.focus(); else buscar.focus(); }
})();
</script>
<?php endif; ?>

<?php elseif ($pestana === 'alertas'): /* ═══════════ AVISOS ═══════════ */
?>
<?php if (!empty($faltaMigrar)): ?>
<div class="alert alert-warning" style="margin-bottom:16px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base de datos.</b>
    Esta pantalla necesita tablas y columnas que todavía no existen.
    Corre <code>php app/bin/migrar.php --aplicar</code>, o aplica
    <code>config/actualizar-v27.sql</code> en phpMyAdmin. Después sal y vuelve a entrar.</span>
</div>
<?php endif; ?>

<?php

$ROTULO = [
  'caducado'    => ['Ya caducó',   'bg-danger'],
  'agotado'     => ['Se acabó',    'bg-danger'],
  'por_caducar' => ['Por caducar', 'bg-warning'],
  'bajo'        => ['Queda poco',  'bg-warning'],
];
?>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title">Avisos<?= $varias ? ' · ' . P::e($subtitulo) : '' ?></h3>
    <small class="lf-inv-sub">Lo que conviene atender hoy. Se configura en
      <a href="/ajustes?t=inventario">Ajustes → Inventario</a>:
      existencias <b><?= P::e($nivelStock === 'no' ? 'sin aviso' : ($nivelStock === 'obligatoria' ? 'aviso fijo' : 'avisar')) ?></b>,
      caducidad <b><?= P::e($nivelCad === 'no' ? 'sin aviso' : ($nivelCad === 'obligatoria' ? 'aviso fijo' : 'avisar')) ?></b>,
      avisando <?= (int)$conteos['dias'] ?> días antes de que algo caduque.</small>
  </span></div>

  <div class="lf-inv-resumen" style="display:flex;gap:10px;flex-wrap:wrap;padding:14px 18px">
    <?php foreach ([['Se acabaron', (int)$conteos['agotados'], 'mal'],
                    ['Queda poco',  (int)$conteos['bajos'], 'ojo'],
                    ['Ya caducaron',(int)$conteos['caducados'], 'mal'],
                    ['Por caducar', (int)$conteos['por_caducar'], 'ojo']] as $k): ?>
      <div class="stat" style="min-width:130px">
        <div class="stat-value<?= $k[1] > 0 ? ' ' . $k[2] : '' ?>"><?= $k[1] ?></div>
        <div class="stat-meta"><?= P::e($k[0]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table lf-inv-tabla">
      <thead><tr><th>Producto</th><th>Qué pasa</th><th class="text-end">Hay</th>
        <th class="text-end">Mínimo</th><th>Caduca</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$listado): ?>
        <tr><td colspan="6" class="lf-inv-vacio">
          Nada que avisar: no falta nada y nada está por caducar.</td></tr>
      <?php endif; ?>
      <?php foreach ($listado as $p): $r = $ROTULO[$p['motivo']] ?? ['—','bg-secondary']; ?>
        <tr>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($p['nombre']) ?></b>
            <small class="lf-inv-cod"><?= P::e($p['codigo']) ?></small></td>
          <td data-label="Qué pasa"><span class="badge <?= $r[1] ?>"><?= P::e($r[0]) ?></span></td>
          <td data-label="Hay" class="text-end lf-mono<?= (float)$p['existencia'] <= 0 ? ' mal' : '' ?>">
            <?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></td>
          <td data-label="Mínimo" class="text-end lf-mono"><?= (float)$p['minimo'] > 0 ? P::e(I::num($p['minimo'])) : '–' ?></td>
          <td data-label="Caduca" class="lf-mono" style="font-size:12px">
            <?php if (!empty($p['fecha_caducidad'])):
              $d = (int)$p['dias_para_caducar']; ?>
              <?= P::e(date('d/m/y', strtotime($p['fecha_caducidad']))) ?>
              <small style="display:block;color:var(--lf-tinta-4)">
                <?= $d < 0 ? 'hace ' . abs($d) . ' d' : ($d === 0 ? 'hoy' : 'en ' . $d . ' d') ?></small>
            <?php else: ?><span style="color:var(--lf-tinta-4)">—</span><?php endif; ?></td>
          <?php if ($puedeEditar): ?>
          <td class="lf-inv-acc">
            <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=entrada&amp;producto=<?= (int)$p['id'] ?>#invMover"><span class="lf-signo">+</span> Entrada</a>
            <?php if (in_array($p['motivo'], ['caducado','por_caducar'], true)): ?>
              <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=merma&amp;producto=<?= (int)$p['id'] ?>#invMover">Dar de baja</a>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    El mínimo de cada producto se pone en <a href="/servicios">Productos</a>, y puede ser distinto por
    sucursal. Los días de aviso de caducidad también se pueden fijar por producto: la leche avisa con
    tres días y el aceite con sesenta.
  </span></div>
</section>

<?php elseif ($pestana === 'conteo'): /* ═══════════ CONTEO FÍSICO ═══════════ */
?>
<?php if (!empty($faltaMigrar)): ?>
<div class="alert alert-warning" style="margin-bottom:16px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Falta actualizar la base de datos.</b>
    Esta pantalla necesita tablas y columnas que todavía no existen.
    Corre <code>php app/bin/migrar.php --aplicar</code>, o aplica
    <code>config/actualizar-v27.sql</code> en phpMyAdmin. Después sal y vuelve a entrar.</span>
</div>
<?php endif; ?>

<?php

/* El conteo es una SESIÓN: se inicia, se va guardando, se pausa, se
   reanuda y se finaliza. Nada se ajusta hasta el final, así que la
   tienda puede seguir vendiendo mientras alguien cuenta. */
$enCurso  = !empty($conteo);
$pausado  = $enCurso && $conteo['estado'] === 'pausado';
$buscarC  = (string)($buscar ?? '');
$hayFiltroC = $buscarC !== '' || $qFiltro;
// Los filtros de la lista, para la hoja imprimible y para volver a ellos al guardar.
$qConteoFiltro = $qFiltro + ($buscarC !== '' ? ['q' => $buscarC] : []);
?>

<?php if (!$enCurso): /* ── Nadie está contando: se empieza uno ── */ ?>
<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title">Conteo físico<?= $varias ? ' · ' . P::e($subtitulo) : '' ?></h3>
    <small class="lf-inv-sub">Cuenta lo que hay en el anaquel. Puedes pausarlo y seguir después:
      nada se ajusta hasta que lo finalices, así que la caja puede seguir cobrando mientras cuentas.
      Ya iniciado, puedes filtrar por marca, área o tipo para contar por partes.</small>
  </span></div>
  <?php if (!$listado && !$hayFiltroC): ?>
    <div class="card-body lf-inv-vacio">Ningún producto lleva existencias todavía.</div>
  <?php else: ?>
  <form method="post" action="/inventario/conteo" class="lf-form" style="padding:16px 18px;gap:12px">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>">
    <input type="hidden" name="accion" value="iniciar">
    <?php if (count($almacenes) > 1): ?>
    <div style="width:200px">
      <label class="form-label" for="cAlm">Qué almacén vas a contar</label>
      <select class="form-select" name="almacen_id" id="cAlm">
        <?php foreach ($almacenes as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $a['es_principal'] ? 'selected' : '' ?>><?= P::e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="cNom">Cómo lo llamas <small>(opcional)</small></label>
      <input class="form-control" name="nombre" id="cNom" maxlength="120" placeholder="Inventario de fin de mes">
    </div>
    <div style="display:flex;align-items:flex-end">
      <button class="btn btn-primary" type="submit">Iniciar conteo</button>
    </div>
  </form>
  <?php endif; ?>
</section>

<?php else: /* ── Hay uno en curso ── */ ?>

<?php /* Filtros: marca, área y tipo, los mismos de Productos y
         Existencias. Sirven para contar por partes —hoy los refrescos,
         mañana la limpieza—: al guardar solo se tocan los renglones que
         se ven. */ ?>
<form class="lf-filtros" method="get" action="/inventario">
  <input type="hidden" name="t" value="conteo">
  <input type="hidden" name="s" value="<?= (int)$sucursal ?>">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscarC) ?>" placeholder="Producto o código">
  </div>
  <?php P::parcial('parciales/filtros-producto', ['opcionesFiltro' => $opcionesFiltro, 'filtro' => $filtro]); ?>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <?php if ($hayFiltroC): ?>
    <a class="lf-link" href="/inventario?t=conteo&amp;s=<?= (int)$sucursal ?>">Quitar filtros</a>
  <?php endif; ?>
  <span style="flex:1"></span>
  <?php /* La hoja para contar a mano: con lo que dice el sistema y un
           espacio para escribir. Sale con los mismos filtros. */ ?>
  <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
     href="/inventario/imprimir?<?= P::e(http_build_query(['que' => 'conteo', 'id' => (int)$conteo['id'], 'hoja' => 1,
                                                          's' => (int)$sucursal, 'auto' => 1] + $qConteoFiltro)) ?>"
     title="Imprimir la lista para contar a mano">
    <?= W::icono('descargar','14px') ?> Hoja para contar</a>
</form>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title"><?= P::e($conteo['nombre'] ?: ('Conteo #' . (int)$conteo['id'])) ?>
      <span class="badge <?= $pausado ? 'bg-warning' : 'bg-success' ?>" style="margin-left:8px">
        <?= $pausado ? 'Pausado' : 'En curso' ?></span></h3>
    <small class="lf-inv-sub">
      Contando <b><?= P::e($conteo['almacen'] ?: 'el almacén') ?></b>.
      Llevas <b><?= (int)$avance['capturados'] ?></b> de <?= (int)$avance['total'] ?>
      <?php if ((int)$avance['diferencias']): ?> · <?= (int)$avance['diferencias'] ?> con diferencia<?php endif; ?>.
      <b>Nada se ajusta hasta que finalices</b>, así que la caja puede seguir cobrando.
      <a class="lf-link" href="/inventario/conteo/ver?id=<?= (int)$conteo['id'] ?>">Ver lo contado</a>
    </small>
    <?php if ($hayFiltroC): ?>
      <small class="lf-inv-sub" style="display:block;margin-top:4px;color:var(--lf-amb)">
        Con el filtro se ven <?= count($listado) ?> productos. Al guardar solo se tocan esos renglones;
        lo que ya contaste de los demás sigue guardado.</small>
    <?php endif; ?>
  </span></div>

  <form method="post" action="/inventario/conteo" id="formConteo">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>">
    <input type="hidden" name="conteo_id" value="<?= (int)$conteo['id'] ?>">
    <?php /* Para volver a la misma lista filtrada después de guardar. */ ?>
    <input type="hidden" name="f_q" value="<?= P::e($buscarC) ?>">
    <input type="hidden" name="f_marca" value="<?= P::e($filtro['marca'] ?? '') ?>">
    <input type="hidden" name="f_area" value="<?= (int)($filtro['area'] ?? 0) ?: '' ?>">
    <input type="hidden" name="f_tipo" value="<?= P::e($filtro['tipo'] ?? '') ?>">

    <div style="padding:12px 18px 0">
      <label class="form-label" for="cBuscar">Busca o escanea para saltar al producto</label>
      <input class="form-control" id="cBuscar" autocomplete="off"
             placeholder="Nombre, código o dispara el escáner" style="max-width:420px">
    </div>

    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table lf-inv-tabla lf-conteo">
        <thead><tr><th>Producto</th><th class="text-end">El sistema dice</th><th class="text-end">Contaste</th><th class="text-end">Diferencia</th></tr></thead>
        <tbody>
        <?php if (!$listado): ?>
          <tr><td colspan="4" class="lf-inv-vacio">Nada coincide con el filtro.</td></tr>
        <?php endif; ?>
        <?php foreach ($listado as $p):
          $id  = (int)$p['id'];
          // Lo que hay EN EL ALMACÉN que se cuenta, no en toda la tienda.
          $hay = $enAlmacen[$id] ?? 0.0;
          $ya  = $capturado[$id]['contado'] ?? null;
        ?>
          <tr data-hay="<?= P::e($numIn($hay)) ?>"
              data-busca="<?= P::e(mb_strtolower($p['codigo'] . ' ' . $p['nombre'] . ' ' . ($p['codigo_barras'] ?? ''))) ?>">
            <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($p['nombre']) ?></b>
              <small class="lf-inv-cod"><?= P::e($p['codigo']) ?><?php
                foreach (array_filter([$p['marca'] ?? '', $p['categoria'] ?? '']) as $extra): ?> · <?= P::e($extra) ?><?php endforeach; ?></small></td>
            <td data-label="Sistema" class="text-end lf-mono"><?= P::e($conU($hay, $p['unidad_medida'])) ?></td>
            <td data-label="Contaste" class="text-end">
              <input class="form-control form-control-sm lf-mono lf-conteo-in" type="number" step="0.001" min="0"
                     name="contado[<?= $id ?>]" inputmode="decimal"
                     value="<?= $ya !== null ? P::e($numIn((float)$ya)) : '' ?>"
                     <?= $ya !== null ? 'data-guardado="1"' : '' ?>
                     aria-label="Contaste de <?= P::e($p['nombre']) ?>"></td>
            <td data-label="Diferencia" class="text-end lf-mono lf-conteo-dif">–</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer lf-conteo-pie" style="flex-wrap:wrap;gap:10px">
      <input class="form-control" name="nota" maxlength="200"
             placeholder="Nota (opcional)" style="max-width:260px">
      <span id="conteoCuenta" class="pista"></span>
      <span style="flex:1"></span>
      <button class="btn btn-secondary" type="submit" name="accion" value="guardar">Guardar avance</button>
      <?php if ($pausado): ?>
        <button class="btn btn-secondary" type="submit" name="accion" value="reanudar">Reanudar</button>
      <?php else: ?>
        <button class="btn btn-secondary" type="submit" name="accion" value="pausar">Pausar</button>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit" name="accion" value="finalizar"
              data-confirmar="Al finalizar se ajustan las existencias a lo que contaste. Lo que dejaste vacío no se toca. ¿Finalizar?">
        Finalizar y ajustar</button>
      <button class="btn btn-secondary" type="submit" name="accion" value="cancelar"
              style="color:var(--lf-malo,#c0392b)"
              data-confirmar="Se pierde lo capturado y no se ajusta ninguna existencia. ¿Cancelar el conteo?">
        Cancelar</button>
      <p class="pista" style="flex:0 0 100%;margin:0">Para quitar un producto del conteo, borra su número y guarda.
        Un 0 sí cuenta: quiere decir que no hay ninguno.</p>
    </div>
  </form>
  <script>
  (function(){
    var f = document.getElementById('formConteo');
    if (!f) return;
    function num(n){ return (Math.round(n * 1000) / 1000).toLocaleString('es-MX', {maximumFractionDigits:3}); }
    function pintar(){
      var n = 0, d = 0, q = 0;
      f.querySelectorAll('tr[data-hay]').forEach(function(tr){
        var i = tr.querySelector('.lf-conteo-in'), c = tr.querySelector('.lf-conteo-dif');
        if (i.value === '') {
          // Tenía número y se borró: al guardar se quita del conteo.
          if (i.dataset.guardado) { q++; c.textContent = 'Se quitará'; c.className = 'text-end lf-mono lf-conteo-dif menos'; }
          else { c.textContent = '–'; c.className = 'text-end lf-mono lf-conteo-dif'; }
          return;
        }
        var dif = (parseFloat(i.value) || 0) - (parseFloat(String(tr.dataset.hay).replace(/,/g, '')) || 0);
        n++; if (Math.abs(dif) >= 0.0005) d++;
        c.textContent = Math.abs(dif) < 0.0005 ? 'Cuadra' : (dif > 0 ? '+' : '−') + num(Math.abs(dif));
        c.className = 'text-end lf-mono lf-conteo-dif ' + (Math.abs(dif) < 0.0005 ? 'ok' : (dif > 0 ? 'mas' : 'menos'));
      });
      document.getElementById('conteoCuenta').textContent = (n
        ? n + (n === 1 ? ' producto contado' : ' productos contados') + (d ? ' · ' + d + ' con diferencia' : ' · todo cuadra')
        : 'Nada capturado en esta lista.') + (q ? ' · ' + q + ' por quitar' : '');
    }
    f.addEventListener('input', pintar);
    // Enter pasa al siguiente: se cuenta con el teclado, sin mouse.
    f.addEventListener('keydown', function(e){
      if (e.key !== 'Enter' || !e.target.classList.contains('lf-conteo-in')) return;
      e.preventDefault();
      var todos = [].slice.call(f.querySelectorAll('.lf-conteo-in')), i = todos.indexOf(e.target);
      if (todos[i + 1]) todos[i + 1].focus();
    });
    /* La rueda del ratón sobre una casilla numérica con el cursor adentro
       le SUMA o le RESTA: se escribía 12, se bajaba la página y quedaba
       en 9 sin que nadie lo notara. Al girar la rueda se suelta la casilla
       y la página baja, que es lo que se quería. */
    f.addEventListener('wheel', function(e){
      if (e.target.classList && e.target.classList.contains('lf-conteo-in') && document.activeElement === e.target) {
        e.target.blur();
      }
    }, {passive: true});

    /* Buscar o escanear salta al renglón y pone el cursor en su casilla.
       En una tienda con cuatrocientos productos, bajar con el ratón
       hasta «Refresco 600ml» es lo que hace que nadie cuente. */
    var bus = document.getElementById('cBuscar');
    if (bus) {
      function saltar(){
        var v = (bus.value || '').trim().toLowerCase();
        if (!v) return false;
        var filas = [].slice.call(f.querySelectorAll('tr[data-busca]'));
        var hit = filas.filter(function(tr){ return tr.dataset.busca.indexOf(v) >= 0; });
        if (!hit.length) return false;
        var inp = hit[0].querySelector('.lf-conteo-in');
        hit[0].scrollIntoView({block:'center', behavior:'smooth'});
        inp.focus(); inp.select();
        return true;
      }
      bus.addEventListener('keydown', function(e){
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (saltar()) bus.value = ''; else bus.select();
      });
    }

    /* Finalizar y cancelar sí preguntan; guardar avance y pausar no,
       porque no destruyen nada y preguntarlo cada vez estorba. */
    f.addEventListener('click', function(e){
      var b = e.target.closest ? e.target.closest('[data-confirmar]') : null;
      if (b && !confirm(b.dataset.confirmar)) e.preventDefault();
    });
    pintar();
  })();
  </script>
</section>
<?php endif; ?>

<?php if (!empty($recientes)): ?>
<section class="card">
  <div class="card-header"><span><h3 class="card-title">Conteos anteriores</h3>
    <small class="lf-inv-sub">Ábrelo para ver qué se contó, contra qué y cuánto salió de diferencia, o descárgalo en PDF.</small></span></div>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Conteo</th><th>Almacén</th><th class="text-end">Capturados</th><th>Estado</th><th>Cuándo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recientes as $c): ?>
        <tr>
          <td data-label="Conteo"><a href="/inventario/conteo/ver?id=<?= (int)$c['id'] ?>"><b><?= P::e($c['nombre'] ?: ('Conteo #' . (int)$c['id'])) ?></b></a>
            <small style="display:block;color:var(--lf-tinta-4)">#<?= (int)$c['id'] ?> · <?= P::e($c['usuario_nombre'] ?: '—') ?></small></td>
          <td data-label="Almacén"><?= P::e($c['almacen'] ?: '—') ?></td>
          <td data-label="Capturados" class="text-end lf-mono"><?= (int)$c['capturados'] ?></td>
          <td data-label="Estado"><span class="badge <?= $c['estado'] === 'cerrado' ? 'bg-success'
                : ($c['estado'] === 'cancelado' ? 'bg-secondary' : 'bg-warning') ?>">
            <?= P::e(\LibertyFin\Servicio\Conteo::ESTADOS[$c['estado']] ?? $c['estado']) ?></span></td>
          <td data-label="Cuándo" class="lf-mono" style="font-size:12px">
            <?= P::e(date('d/m/y H:i', strtotime($c['cerrado_en'] ?: $c['creado_en']))) ?></td>
          <td class="lf-inv-acc">
            <a class="btn btn-secondary btn-sm" href="/inventario/conteo/ver?id=<?= (int)$c['id'] ?>">Ver</a>
            <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
               href="/inventario/imprimir?que=conteo&amp;id=<?= (int)$c['id'] ?>&amp;auto=1"
               title="Descargar en PDF"><?= W::icono('descargar','14px') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php else: /* ═══════════ MOVIMIENTOS ═══════════ */
// Los filtros vigentes, para que las pastillas de tipo y el PDF no los pierdan.
$qMov = array_filter(['t' => 'movimientos',
                      's' => $filtros['sucursal'] ? (string)(int)$filtros['sucursal'] : '0',
                      'q' => $filtros['q'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
                      'tipo' => $filtros['tipo']], function ($v) { return $v !== ''; });
$conTipo = function ($tipo) use ($qMov) {
    $q = $qMov;
    if ($tipo === '') unset($q['tipo']); else $q['tipo'] = $tipo;
    return '/inventario?' . http_build_query($q);
};
// Las que más se buscan, a un clic: cuándo se registró, se vendió, entró o salió.
$rapidos = ['' => 'Todos', 'inicial' => 'Registro', 'venta' => 'Ventas', 'entrada' => 'Entradas',
            'salida' => 'Salidas', 'conteo' => 'Conteos', 'traspaso' => 'Traspasos', 'merma' => 'Mermas',
            'devolucion' => 'Devoluciones'];
$res = $resumenMov ?? [];
$totalRen = 0; foreach ($res as $x) $totalRen += (int)$x['renglones'];
$colsMov = 10 + ($varias ? 1 : 0);
?>

<div class="lf-pills lf-mov-tipos" aria-label="Tipo de movimiento" style="margin-bottom:12px">
  <?php foreach ($rapidos as $k => $t):
    $n = $k === '' ? $totalRen : (int)($res[$k]['renglones'] ?? 0);
    // Registro, ventas, entradas y salidas siempre están a la vista; las
    // devoluciones solo cuando hay.
    if ($k === 'devolucion' && !$n && $filtros['tipo'] !== $k) continue; ?>
    <a class="lf-pill <?= $filtros['tipo'] === $k ? 'active' : '' ?>" href="<?= P::e($conTipo($k)) ?>"><?= P::e($t) ?><?php if ($n): ?><span class="n"><?= number_format($n) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get" action="/inventario">
  <input type="hidden" name="t" value="movimientos">
  <?php if ($filtros['tipo'] !== ''): ?><input type="hidden" name="tipo" value="<?= P::e($filtros['tipo']) ?>"><?php endif; ?>
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($filtros['q']) ?>" placeholder="Producto o código">
  </div>
  <?php if ($varias): ?>
  <select class="form-select form-select-sm" name="s" style="width:auto" aria-label="Sucursal">
    <option value="0" <?= !$filtros['sucursal'] ? 'selected' : '' ?>>Todas las sucursales</option>
    <?php foreach ($sucursales as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$filtros['sucursal'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?><input type="hidden" name="s" value="<?= (int)$sucursal ?>"><?php endif; ?>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($filtros['desde']) ?>" style="width:auto" aria-label="Desde">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($filtros['hasta']) ?>" style="width:auto" aria-label="Hasta">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <span style="flex:1"></span>
  <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
     href="/inventario/imprimir?<?= P::e(http_build_query(['que' => 'movimientos', 'auto' => 1] + $qMov)) ?>"
     title="Los movimientos filtrados, para imprimir o guardar como PDF">
    <?= W::icono('descargar','14px') ?> Descargar PDF</a>
</form>

<?php /* ENTRADAS Y SALIDAS DESGLOSADAS.
         Cuánto entró y cuánto salió en el periodo, por tipo. Sin esto,
         para saber cuánto se vendió contra cuánto llegó había que sumar
         la tabla a mano. */
if ($res): $entroT = 0.0; $salioT = 0.0; ?>
<details class="card lf-mov-desglose" <?= $filtros['tipo'] === '' ? 'open' : '' ?>>
  <summary class="card-header" style="cursor:pointer"><span>
    <h3 class="card-title">Entradas y salidas desglosadas</h3>
    <small class="lf-inv-sub">Del <?= P::e(date('d/m/Y', strtotime($filtros['desde']))) ?>
      al <?= P::e(date('d/m/Y', strtotime($filtros['hasta']))) ?>, con los filtros de arriba (sin el tipo).</small>
  </span></summary>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table lf-inv-tabla">
      <thead><tr><th>Tipo</th><th class="text-end">Entró</th><th class="text-end">Salió</th><th class="text-end">Movimientos</th></tr></thead>
      <tbody>
      <?php foreach (I::TIPOS as $k => $t): if (empty($res[$k])) continue;
        $x = $res[$k]; $entroT += $x['entro']; $salioT += $x['salio']; ?>
        <tr>
          <td data-label="Tipo"><a href="<?= P::e($conTipo($k)) ?>"><span class="lf-inv-tipo t-<?= P::e($k) ?>"><?= P::e($t) ?></span></a></td>
          <td data-label="Entró" class="text-end lf-mono lf-inv-cant mas"><?= $x['entro'] > 0 ? '+' . P::e(I::num($x['entro'])) : '–' ?></td>
          <td data-label="Salió" class="text-end lf-mono lf-inv-cant menos"><?= $x['salio'] > 0 ? '−' . P::e(I::num($x['salio'])) : '–' ?></td>
          <td data-label="Movimientos" class="text-end lf-mono"><?= number_format($x['renglones']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td><b>Total</b></td>
        <td class="text-end lf-mono lf-inv-cant mas"><b>+<?= P::e(I::num($entroT)) ?></b></td>
        <td class="text-end lf-mono lf-inv-cant menos"><b>−<?= P::e(I::num($salioT)) ?></b></td>
        <td class="text-end lf-mono"><b><?= number_format($totalRen) ?></b></td>
      </tr></tfoot>
    </table>
  </div>
</details>
<?php endif; ?>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-inv-tabla lf-mov-tabla">
      <thead><tr>
        <th>Cuándo</th><th>Producto</th><th>Movimiento</th>
        <?php if ($varias): ?><th>Sucursal</th><?php endif; ?>
        <th title="Piso, bodega… En un traspaso: de dónde a dónde">Almacén</th>
        <th class="text-end" title="Lo que había en ese almacén antes">Había</th>
        <th class="text-end">Cantidad</th>
        <th class="text-end" title="Lo que quedó en ese almacén">Quedó</th>
        <th class="text-end" title="El total de la sucursal después del movimiento">En la sucursal</th>
        <th>Detalle</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$movimientos): ?>
        <tr><td colspan="<?= $colsMov ?>" class="lf-inv-vacio">No hay movimientos en estas fechas.</td></tr>
      <?php endif; ?>
      <?php foreach ($movimientos as $m):
        $c = (float)$m['cantidad'];
        $esTr = $m['tipo'] === 'traspaso';
        $tot = $m['existencia_sucursal'] ?? null; ?>
        <tr>
          <td data-label="Cuándo" class="lf-inv-fecha"><?= P::e(date('d/m/Y H:i', strtotime($m['creado_en']))) ?>
            <small><?= P::e($m['usuario_nombre'] ?: '—') ?></small></td>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($m['producto'] ?: '(ya no existe)') ?></b>
            <small class="lf-inv-cod"><?= P::e($m['codigo'] ?? '') ?></small></td>
          <td data-label="Movimiento"><span class="lf-inv-tipo t-<?= P::e($m['tipo']) ?>"><?= P::e(I::TIPOS[$m['tipo']] ?? $m['tipo']) ?></span>
            <?php if ($esTr): ?><small class="lf-inv-cod" style="display:block"><?= $c < 0 ? 'Se fue' : 'Llegó' ?></small><?php endif; ?></td>
          <?php if ($varias): ?><td data-label="Sucursal"><?= P::e($m['sucursal'] ?: '—') ?></td><?php endif; ?>
          <td data-label="Almacén"><?= P::e(I::rutaAlmacen($m) ?: '—') ?></td>
          <td data-label="Había" class="text-end lf-mono"><?= P::e(I::num(I::habia($m))) ?></td>
          <td data-label="<?= $esTr ? ($c < 0 ? 'Se fue' : 'Llegó') : 'Cantidad' ?>" class="text-end lf-mono lf-inv-cant <?= $c > 0 ? 'mas' : ($c < 0 ? 'menos' : '') ?>">
            <?= $c > 0 ? '+' : ($c < 0 ? '−' : '') ?><?= P::e(I::num(abs($c))) ?></td>
          <td data-label="Quedó" class="text-end lf-mono"><?= P::e($conU($m['existencia'], $m['unidad_medida'])) ?></td>
          <td data-label="En la sucursal" class="text-end lf-mono"><?= $tot !== null && $tot !== '' ? P::e(I::num($tot)) : '<span style="color:var(--lf-tinta-4)">–</span>' ?></td>
          <td data-label="Detalle" class="lf-inv-det">
            <?php if ($m['venta_id']): ?>
              <a href="/ventas/<?= (int)$m['venta_id'] ?>">Venta <?= P::e($m['codigo_venta'] ?: '#' . $m['venta_id']) ?></a>
            <?php endif; ?>
            <?php if ($m['proveedor']): ?><span><?= P::e($m['proveedor']) ?></span><?php endif; ?>
            <?php if ($m['costo'] !== null): ?><span><?= D::pesos($m['costo']) ?> c/u</span><?php endif; ?>
            <?php if ($m['nota']): ?><span><?= P::e($m['nota']) ?></span><?php endif; ?>
          </td>
          <td class="lf-inv-acc"><a class="btn btn-secondary btn-sm" href="/inventario/movimiento?id=<?= (int)$m['id'] ?>"
               title="Ver el detalle y descargarlo en PDF">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($movimientos) >= 300): ?>
    <div class="card-footer">Se muestran los 300 más recientes. Acorta las fechas para ver los demás
      (el PDF trae hasta 5,000).</div>
  <?php endif; ?>
</section>

<?php endif; ?>
