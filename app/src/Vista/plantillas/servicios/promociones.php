<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Promociones as PR;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;

// Lo que ya tiene elegido la que se edita: [ref_id => cantidad].
$elegidos = function ($tipo) use ($e) {
    $r = [];
    foreach (($e['objetivos'] ?? []) as $o) if ($o['tipo'] === $tipo) $r[(int)$o['ref_id']] = (int)$o['cantidad'];
    return $r;
};
$prodSel = $elegidos('producto');
if (!$e && !empty($paraProducto)) $prodSel = [(int)$paraProducto => 1];
$areaSel = $elegidos('area');
$diasSel = ($e && $e['dias']) ? explode(',', $e['dias']) : [];
$tipoE   = $e['tipo'] ?? 'porcentaje';
$alcE    = $e['alcance'] ?? 'productos';
$num     = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };

$tot = ['vigentes' => 0, 'usos' => 0, 'descontado' => 0.0, 'programadas' => 0];
foreach ($promos as $p) {
    if ($p['estado']['clave'] === 'vigente')    $tot['vigentes']++;
    if ($p['estado']['clave'] === 'programada') $tot['programadas']++;
    $tot['usos']       += (int)$p['usos'];
    $tot['descontado'] += (float)$p['descontado'];
}
$claseEstado = ['vigente' => 'bg-success', 'programada' => 'bg-info', 'hoy_no' => 'bg-secondary',
                'pausada' => 'bg-secondary', 'vencida' => 'bg-light', 'agotada' => 'bg-warning'];

/* "A qué aplica", en corto: los primeros tres y cuántos más. */
$aplicaA = function (array $p) {
    if ($p['alcance'] === 'todo') return 'Todo el ticket';
    $n = array_map(function ($o) use ($p) {
        $nom = $o['nombre'] ?: '(ya no existe)';
        return ($p['tipo'] === 'paquete' && (int)$o['cantidad'] > 1) ? (int)$o['cantidad'] . '× ' . $nom : $nom;
    }, $p['objetivos']);
    $pre = $p['alcance'] === 'areas' ? 'Área: ' : '';
    $sep = $p['tipo'] === 'paquete' ? ' + ' : ', ';
    return $pre . (count($n) > 3 ? implode($sep, array_slice($n, 0, 3)) . ' y ' . (count($n) - 3) . ' más'
                                 : implode($sep, $n));
};
$vigencia = function (array $p) {
    $f = function ($d) { return date('d/m/Y', strtotime($d)); };
    if ($p['desde'] && $p['hasta']) $t = $f($p['desde']) . ' – ' . $f($p['hasta']);
    elseif ($p['desde'])            $t = 'Desde el ' . $f($p['desde']);
    elseif ($p['hasta'])            $t = 'Hasta el ' . $f($p['hasta']);
    else                            $t = 'Sin fecha de fin';
    return $t;
};
?>

<?php P::parcial('servicios/_pestanas', ['actual' => 'promociones', 'vigentes' => $vigentes]); ?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?> id="altaPromo">
  <summary>
    <?= W::icono($e ? 'pct' : 'mas','16px') ?>
    <?= $e ? 'Editar «' . P::e($e['nombre']) . '»' : 'Crear una promoción' ?>
  </summary>
  <form method="post" action="/servicios/promociones/guardar" class="lf-form lf-promo-form" id="formPromo">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>

    <div style="flex:2;min-width:220px">
      <label class="form-label" for="pNombre">Nombre</label>
      <input class="form-control" id="pNombre" name="nombre" maxlength="120" required
             value="<?= P::e($e['nombre'] ?? '') ?>" placeholder="Ej. Temporada de declaraciones">
    </div>
    <div style="width:230px">
      <label class="form-label" for="pTipo">Tipo</label>
      <select class="form-select" name="tipo" id="pTipo">
        <?php foreach (PR::TIPOS as $k => $t): ?>
          <option value="<?= $k ?>" <?= $tipoE === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:110px" data-ver-tipo="volumen">
      <label class="form-label" for="pDesde">Desde (piezas)</label>
      <input class="form-control lf-mono" type="number" name="desde_piezas" id="pDesde" min="2" max="9999" step="1"
             value="<?= P::e(($e && $e['tipo'] === 'volumen') ? (int)$e['lleva'] : 6) ?>">
    </div>
    <div style="width:150px" data-ver-tipo="porcentaje monto precio paquete volumen">
      <label class="form-label" for="pValor" id="lblValor">Porcentaje</label>
      <input class="form-control lf-mono" type="number" name="valor" id="pValor" step="0.01" min="0"
             value="<?= $e && $e['tipo'] !== 'nxm' ? P::e($num($e['valor'])) : '' ?>" placeholder="0">
    </div>
    <div style="display:flex;flex-direction:row;gap:10px" data-ver-tipo="nxm">
      <div style="width:90px">
        <label class="form-label" for="pLleva">Lleva</label>
        <input class="form-control lf-mono" type="number" name="lleva" id="pLleva" min="2" max="99" step="1"
               value="<?= P::e($e['lleva'] ?? 3) ?>">
      </div>
      <div style="width:90px">
        <label class="form-label" for="pPaga">Paga</label>
        <input class="form-control lf-mono" type="number" name="paga" id="pPaga" min="1" max="98" step="1"
               value="<?= P::e($e['paga'] ?? 2) ?>">
      </div>
    </div>

    <?php /* ── A QUÉ APLICA ── */ ?>
    <div class="lf-promo-bloque">
      <span class="form-label" id="etAlcance">Aplica a</span>
      <div class="lf-segmento" role="radiogroup" aria-labelledby="etAlcance" data-ver-tipo="porcentaje monto nxm">
        <?php foreach (PR::ALCANCES as $k => $t): ?>
          <label data-alcance="<?= $k ?>">
            <input type="radio" name="alcance" value="<?= $k ?>" <?= $alcE === $k ? 'checked' : '' ?>><?= P::e($t) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="lf-promo-ayuda" id="ayudaAlcance"></p>

      <div class="lf-promo-chips" data-ver-alcance="areas">
        <?php if (!$categorias): ?>
          <span class="lf-promo-ayuda">No hay áreas. Se crean en Ajustes.</span>
        <?php endif; ?>
        <?php foreach ($categorias as $c): ?>
          <label><input type="checkbox" name="areas[]" value="<?= (int)$c['id'] ?>"
                 <?= isset($areaSel[(int)$c['id']]) ? 'checked' : '' ?>><?= P::e($c['nombre']) ?></label>
        <?php endforeach; ?>
      </div>

      <div class="lf-promo-elige" data-ver-alcance="productos">
        <div class="lf-search">
          <?= W::icono('buscar','15px') ?>
          <input type="search" id="buscaProdPromo" placeholder="Buscar producto o código" autocomplete="off">
        </div>
        <div class="lista" id="listaProdPromo">
          <?php foreach ($productos as $s): $sel = isset($prodSel[(int)$s['id']]); ?>
            <label class="fila<?= $sel ? ' on' : '' ?>"
                   data-b="<?= P::e(mb_strtolower($s['nombre'] . ' ' . $s['codigo'] . ' ' . ($s['categoria'] ?? ''))) ?>">
              <input type="checkbox" name="productos[]" value="<?= (int)$s['id'] ?>" <?= $sel ? 'checked' : '' ?>>
              <span class="n"><b><?= P::e($s['nombre']) ?></b><small><?= P::e($s['categoria'] ?: 'Sin área') ?></small></span>
              <span class="p lf-mono"><?= (float)$s['precio'] > 0 ? D::pesos($s['precio']) : 'Precio libre' ?></span>
              <span class="cant" data-ver-tipo="paquete" title="Cuántos lleva el paquete">
                ×<input type="number" name="paquete[<?= (int)$s['id'] ?>]" min="1" max="99" step="1"
                        value="<?= $sel ? (int)$prodSel[(int)$s['id']] : 1 ?>" aria-label="Cantidad en el paquete"></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="lf-promo-ayuda" id="cuantosProd"></p>
      </div>
    </div>

    <?php /* ── CUÁNDO ── */ ?>
    <div style="width:155px">
      <label class="form-label" for="pDesde">Desde <small>(opcional)</small></label>
      <input class="form-control" type="date" name="desde" id="pDesde" value="<?= P::e($e['desde'] ?? '') ?>">
    </div>
    <div style="width:155px">
      <label class="form-label" for="pHasta">Hasta <small>(opcional)</small></label>
      <input class="form-control" type="date" name="hasta" id="pHasta" value="<?= P::e($e['hasta'] ?? '') ?>">
    </div>
    <div>
      <span class="form-label" id="etDias">Días <small>(ninguno = todos)</small></span>
      <div class="lf-promo-chips dias" role="group" aria-labelledby="etDias">
        <?php foreach (PR::DIAS as $n => $d): ?>
          <label><input type="checkbox" name="dias[]" value="<?= $n ?>"
                 <?= in_array((string)$n, $diasSel, true) ? 'checked' : '' ?>><?= P::e($d) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php /* ── CONDICIONES ── */ ?>
    <?php /* "Código" a secas se leía como el folio de la promoción: se
             llenaba y la promoción dejaba de aplicarse sola. */ ?>
    <div style="width:200px">
      <label class="form-label" for="pCodigo">Código de cupón <small>(opcional)</small></label>
      <input class="form-control lf-mono lf-promo-cod" name="codigo" id="pCodigo" maxlength="30"
             style="text-transform:uppercase" value="<?= P::e($e['codigo'] ?? '') ?>" placeholder="Vacío: se aplica sola">
    </div>
    <div style="width:150px">
      <label class="form-label" for="pMinimo">Compra mínima</label>
      <input class="form-control lf-mono" type="number" name="minimo" id="pMinimo" step="0.01" min="0"
             value="<?= $e && (float)$e['minimo'] > 0 ? P::e($num($e['minimo'])) : '' ?>" placeholder="Sin mínimo">
    </div>
    <div style="width:140px">
      <label class="form-label" for="pLimite">Máximo de ventas</label>
      <input class="form-control lf-mono" type="number" name="limite" id="pLimite" step="1" min="1"
             value="<?= P::e($e['limite'] ?? '') ?>" placeholder="Sin límite">
    </div>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="pNota">Nota <small>(opcional)</small></label>
      <input class="form-control" name="nota" id="pNota" maxlength="255" value="<?= P::e($e['nota'] ?? '') ?>"
             placeholder="Para quién es, de dónde salió…">
    </div>

    <div style="display:flex;flex-direction:row;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Crear promoción' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/servicios?t=promociones">Cancelar</a><?php endif; ?>
    </div>
    <p class="lf-promo-ayuda" style="width:100%;margin:0">
      Caja la aplica sola mientras esté vigente; el cajero puede quitarla de un renglón si no
      corresponde. Si a un producto le tocan dos, se queda la que más descuenta: no se acumulan.
      La comisión se calcula sobre el precio con descuento. Con <b>código de cupón</b>, solo aplica
      si el cajero lo escribe en Caja, y Caja no la anuncia en los productos; déjalo vacío para que
      se aplique sola.
    </p>
  </form>
</details>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= $tot['vigentes'] ?></div>
    <div class="stat-label">Vigentes hoy</div>
    <div class="stat-meta"><?= $tot['programadas'] ? $tot['programadas'] . ' más programadas' : 'Caja las aplica solas' ?></div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= number_format($tot['usos']) ?></div>
    <div class="stat-label">Ventas con promoción</div>
    <div class="stat-meta">desde que se crearon</div></div>
  <div class="stat-card"><div class="lf-tile a"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= D::pesos($tot['descontado']) ?></div>
    <div class="stat-label">Descontado</div>
    <div class="stat-meta">lo que se dejó de cobrar por ellas</div></div>
</div>

<section class="card">
  <header class="card-header">Promociones</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-promo-tabla">
      <thead><tr>
        <th>Promoción</th><th>Aplica a</th><th>Cuándo y condiciones</th>
        <th class="text-end">Ventas</th><th class="text-end">Descontado</th><th>Estado</th>
        <?php if ($puedeEditar): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$promos): ?>
        <tr><td colspan="<?= $puedeEditar ? 7 : 6 ?>" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Todavía no hay promociones.<?= $puedeEditar ? ' Crea la primera arriba: Caja la aplica sola.' : '' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($promos as $p): $est = $p['estado']; ?>
        <tr>
          <td data-label="Promoción">
            <b style="font-weight:600;display:block"><?= P::e($p['nombre']) ?></b>
            <small style="color:var(--lf-tinta-4)"><?= P::e(PR::describir($p)) ?></small>
          </td>
          <td data-label="Aplica a"><?= P::e($aplicaA($p)) ?></td>
          <td data-label="Cuándo">
            <span style="display:block"><?= P::e($vigencia($p)) ?></span>
            <?php if ($p['dias']): ?><small class="lf-promo-cond"><?= P::e(PR::diasTexto($p['dias'])) ?></small><?php endif; ?>
            <?php if ($p['codigo']): ?><small class="lf-promo-cond">solo con el cupón <b class="lf-mono"><?= P::e($p['codigo']) ?></b></small><?php endif; ?>
            <?php if ((float)$p['minimo'] > 0): ?><small class="lf-promo-cond">compra mínima <?= D::pesos($p['minimo']) ?></small><?php endif; ?>
            <?php if ($p['limite'] !== null): ?><small class="lf-promo-cond">hasta <?= (int)$p['limite'] ?> venta<?= (int)$p['limite'] === 1 ? '' : 's' ?></small><?php endif; ?>
          </td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$p['usos'] ?><?= $p['limite'] !== null ? '<small style="color:var(--lf-tinta-4)"> / ' . (int)$p['limite'] . '</small>' : '' ?></td>
          <td data-label="Descontado" class="text-end lf-mono"><?= (float)$p['descontado'] > 0 ? D::pesos($p['descontado']) : '–' ?></td>
          <td data-label="Estado"><span class="badge <?= $claseEstado[$est['clave']] ?? 'bg-secondary' ?>"><?= P::e($est['texto']) ?></span></td>
          <?php if ($puedeEditar): ?>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/servicios?t=promociones&amp;editar=<?= (int)$p['id'] ?>" title="Editar"><?= W::icono('serv','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-promo-acc" data-accion="alternar" data-id="<?= (int)$p['id'] ?>"
                    data-nombre="<?= P::e($p['nombre']) ?>" data-activa="<?= (int)$p['activo'] ?>"
                    title="<?= (int)$p['activo'] ? 'Pausar' : 'Reanudar' ?>"><?= (int)$p['activo'] ? '❚❚' : '▶' ?></button>
            <?php if ((int)$p['usos'] === 0): ?>
              <button type="button" class="lf-btn-ghost lf-promo-acc" data-accion="borrar" data-id="<?= (int)$p['id'] ?>"
                      data-nombre="<?= P::e($p['nombre']) ?>" title="Borrar"><span class="lf-signo">&times;</span></button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una venta con promoción guarda el descuento en cada producto. Lo ves en el detalle de la venta y en el
    ticket, y la comisión ya sale sobre el precio con descuento.
  </div>
</section>

<?php if ($puedeEditar): ?>
<form method="post" id="formPromoAcc" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="promoAccId">
</form>
<?php endif; ?>

<script>
(function(){
  /* Pausar, reanudar y borrar: un solo formulario oculto. */
  var fa = document.getElementById('formPromoAcc');
  document.querySelectorAll('.lf-promo-acc').forEach(function(b){
    b.addEventListener('click', function(){
      var borrar = b.dataset.accion === 'borrar';
      var msg = borrar
        ? '¿Borrar «' + b.dataset.nombre + '»?\n\nNunca se usó, así que no afecta ninguna venta.'
        : (b.dataset.activa === '1'
            ? '¿Pausar «' + b.dataset.nombre + '»?\n\nCaja deja de aplicarla. Las ventas que ya la usaron no cambian.'
            : '¿Reanudar «' + b.dataset.nombre + '»?');
      if (!confirm(msg)) return;
      fa.action = '/servicios/promociones/' + (borrar ? 'borrar' : 'alternar');
      document.getElementById('promoAccId').value = b.dataset.id;
      fa.submit();
    });
  });

  var f = document.getElementById('formPromo');
  if (!f) return;
  var tipo = document.getElementById('pTipo');
  var lista = document.getElementById('listaProdPromo');

  /* Qué se ve según el tipo y a qué aplica. Un precio especial y un
     paquete van siempre sobre productos; "lleva N paga M" no tiene sentido
     sobre todo el ticket. */
  var ROTULO = {porcentaje:'Porcentaje', monto:'Descuento ($)', precio:'Precio especial ($)', paquete:'Precio del paquete ($)',
                volumen:'Precio c/u ($)'};
  var AYUDA = {
    todo: 'Aplica a cualquier producto del ticket.',
    areas: 'Aplica a todos los productos de las áreas que marques.',
    productos: 'Aplica solo a los productos que marques.'
  };
  function alcance(){
    var t = tipo.value;
    if (t === 'precio' || t === 'paquete' || t === 'volumen') return 'productos';
    var r = f.querySelector('input[name="alcance"]:checked');
    var a = r ? r.value : 'productos';
    if (t === 'nxm' && a === 'todo') a = 'productos';
    return a;
  }
  function pintar(){
    var t = tipo.value, a = alcance();
    f.querySelectorAll('[data-ver-tipo]').forEach(function(el){
      el.hidden = el.dataset.verTipo.split(' ').indexOf(t) === -1;
    });
    f.querySelectorAll('[data-ver-alcance]').forEach(function(el){
      el.hidden = el.dataset.verAlcance !== a;
    });
    var todo = f.querySelector('label[data-alcance="todo"]');
    if (todo) todo.hidden = (t === 'nxm');
    var r = f.querySelector('input[name="alcance"][value="' + a + '"]');
    if (r) r.checked = true;
    document.getElementById('lblValor').textContent = ROTULO[t] || 'Valor';
    var v = document.getElementById('pValor');
    v.max = t === 'porcentaje' ? 100 : '';
    v.required = t !== 'nxm';
    var ay = AYUDA[a];
    if (t === 'monto') ay += a === 'todo' ? ' El monto se descuenta una vez de la compra completa.'
                                          : ' El monto se descuenta por cada pieza.';
    if (t === 'paquete') ay = 'Marca los productos que forman el paquete y cuántos de cada uno. Aplica cuando el ticket los trae todos.';
    if (t === 'precio') ay = 'Los productos que marques se cobran a este precio.';
    if (t === 'volumen') ay = 'Precio de mayoreo: cuando el ticket lleva esa cantidad o más de un producto marcado, todas sus piezas se cobran a este precio.';
    document.getElementById('ayudaAlcance').textContent = ay;
    contar();
  }
  function contar(){
    var n = lista ? lista.querySelectorAll('input[type="checkbox"]:checked').length : 0;
    var el = document.getElementById('cuantosProd');
    if (el) el.textContent = n ? n + ' producto' + (n === 1 ? '' : 's') + ' elegido' + (n === 1 ? '' : 's') : 'Ninguno elegido todavía.';
  }
  tipo.addEventListener('change', pintar);
  f.querySelectorAll('input[name="alcance"]').forEach(function(r){ r.addEventListener('change', pintar); });
  if (lista) lista.addEventListener('change', function(ev){
    var c = ev.target.closest('input[type="checkbox"]');
    if (c) { c.closest('.fila').classList.toggle('on', c.checked); contar(); }
  });

  /* Buscar en la lista de productos, sin recargar. Los elegidos siempre
     se ven: esconderlos haría creer que se desmarcaron. */
  var q = document.getElementById('buscaProdPromo');
  if (q) q.addEventListener('input', function(){
    var t = q.value.trim().toLowerCase();
    lista.querySelectorAll('.fila').forEach(function(fl){
      fl.hidden = t !== '' && fl.dataset.b.indexOf(t) === -1 && !fl.querySelector('input:checked');
    });
  });
  q && q.addEventListener('keydown', function(ev){ if (ev.key === 'Enter') ev.preventDefault(); });

  pintar();
})();
</script>
