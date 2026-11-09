<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$mv  = $resumen['mas_vendido'] ?? null;
$at  = $resumen['area_top'] ?? null;
$tot = 0; foreach ($areas as $a) $tot += (float)$a['monto'];
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;
$qs = function (array $x = []) use ($desde,$hasta,$buscar) {
    return '?' . http_build_query(array_merge(['desde'=>$desde,'hasta'=>$hasta,'q'=>$buscar], $x)); };
?>

<?php P::parcial('servicios/_pestanas', ['actual' => 'catalogo', 'vigentes' => $vigentes ?? 0]); ?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary>
    <?= W::icono($e ? 'serv' : 'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Dar de alta un producto' ?>
  </summary>
  <form method="post" action="/servicios/guardar" class="lf-form" enctype="multipart/form-data">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px">
      <label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required>
    </div>
    <?php /* El SKU se puede dejar en blanco: sale solo del área, tipo
             PAN-0001. Antes era obligatorio y la gente escribía «1», «2»,
             «aaa» con tal de salir del paso. */ ?>
    <div style="width:130px">
      <label class="form-label" for="pCodigo">SKU</label>
      <input class="form-control" name="codigo" id="pCodigo" value="<?= P::e($e['codigo'] ?? '') ?>"
             style="text-transform:uppercase" placeholder="Se genera solo"
             title="El código con el que tú identificas el producto. Déjalo vacío y se genera.">
    </div>
    <div style="width:170px">
      <label class="form-label" for="pBarras">Código de barras</label>
      <input class="form-control lf-mono" name="codigo_barras" id="pBarras"
             value="<?= P::e($e['codigo_barras'] ?? '') ?>" autocomplete="off"
             placeholder="Escanéalo aquí"
             title="El que viene impreso del fabricante. Pon el cursor aquí y dispara el escáner.">
    </div>
    <?php /* Al escribir una palabra aquí salen las claves del catálogo
             del SAT que le quedan, vía Facturapi. */ ?>
    <div class="lf-sat" style="width:150px">
      <label class="form-label" for="pSat">Clave SAT</label>
      <input class="form-control lf-mono" name="clave_sat" id="pSat" value="<?= P::e($e['clave_sat'] ?? '') ?>"
             pattern="\d{8}" placeholder="Busca o escribe" autocomplete="off"
             role="combobox" aria-controls="pSatLista" aria-expanded="false"
             title="Clave de producto o servicio del SAT: 8 dígitos. Escribe una palabra para buscarla.">
      <ul class="lf-sat-lista" id="pSatLista" role="listbox" hidden></ul>
    </div>
    <?php /* Área y subárea en un solo selector, agrupadas. Es como la
             gente las piensa —«Bebidas → Refrescos»— y evita un segundo
             campo que haya que mantener en sincronía con el primero. */
    $catSel = isset($e['categoria_id']) ? (int)$e['categoria_id'] : 0; ?>
    <div style="flex:1;min-width:170px">
      <label class="form-label" for="pCat">Área</label>
      <select class="form-select" name="categoria_id" id="pCat">
        <option value="">Sin área</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $catSel === (int)$c['id'] ? 'selected' : '' ?>>
            <?= P::e($c['nombre']) ?></option>
          <?php if (!empty($c['hijas'])): ?>
            <?php foreach ($c['hijas'] as $h): ?>
              <option value="<?= (int)$h['id'] ?>" <?= $catSel === (int)$h['id'] ? 'selected' : '' ?>>
                &nbsp;&nbsp;↳ <?= P::e($h['nombre']) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
    </div>

    <?php /* La marca ya vivía en la base desde el sistema anterior, pero
             no tenía campo: al editar un producto se guardaba vacía y se
             perdía la que traía. */ ?>
    <div style="width:160px">
      <label class="form-label" for="pMarca">Marca <small>(opcional)</small></label>
      <input class="form-control" name="marca" id="pMarca" maxlength="100"
             value="<?= P::e($e['marca'] ?? '') ?>" list="lMarcas" autocomplete="off">
      <datalist id="lMarcas">
        <?php foreach (array_unique(array_filter(array_column($catalogo ?? [], 'marca'))) as $m): ?>
          <option value="<?= P::e($m) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <?php /* ── EL PRECIO ──
             Precio, costo y utilidad se calculan entre sí, como en el
             sistema anterior, pero en los dos sentidos: se escribe la
             utilidad y sale el precio, o se escribe el precio y sale la
             utilidad. La utilidad no se guarda: sale de los otros dos. */ ?>
    <div class="lf-salto" aria-hidden="true"></div>
    <div style="width:140px">
      <label class="form-label" for="pPrecio">Precio</label>
      <input class="form-control lf-mono" type="number" name="precio" id="pPrecio" step="0.01" min="0" placeholder="0.00"
             value="<?= P::e($e['subprecio'] ?? $e['precio'] ?? '') ?>" required>
    </div>
    <div style="width:140px">
      <label class="form-label" for="pCosto">Costo <small>(opcional)</small></label>
      <input class="form-control lf-mono" type="number" name="costo" id="pCosto" step="0.01" min="0"
             value="<?= P::e($e['costo'] ?? '0') ?>">
    </div>
    <div style="width:120px">
      <label class="form-label" for="pUtil">Utilidad</label>
      <div class="lf-sufijo">
        <input class="form-control lf-mono" type="number" id="pUtil" step="0.01" min="0" placeholder="—"
               title="Sobre el costo. Escríbela y el precio se calcula solo.">
        <span>%</span>
      </div>
    </div>
    <div style="flex:1;min-width:200px">
      <div class="lf-margen" id="pMargen" aria-live="polite">
        <span class="t">Te queda por venta</span><b class="lf-mono" id="pMargenMonto">—</b>
      </div>
    </div>
    <div class="lf-salto" aria-hidden="true"></div>
    <div style="flex:1;min-width:230px">
      <label class="form-label">Imagen</label>
      <div class="lf-foto">
        <span class="prev cuadro" id="prevServ" style="width:48px;height:48px;font-size:16px;
              <?= !empty($e['imagen']) ? "background-image:url('".P::e($e['imagen'])."')" : '' ?>">
          <?= !empty($e['imagen']) ? '' : '+' ?></span>
        <div style="flex:1;min-width:0">
          <span class="lf-file">
            <input type="file" name="imagen" id="inpServ"
                   accept="image/png,image/jpeg,image/webp">
            <label class="bt" for="inpServ">Elegir imagen</label>
            <span class="n" data-vacio="Ninguna imagen elegida">Ninguna imagen elegida</span>
          </span>
          <?php if (!empty($e['imagen'])): ?>
            <label style="font-size:11px;color:var(--lf-tinta-3);display:flex;
                   align-items:center;gap:6px;margin-top:5px;cursor:pointer">
              <input type="checkbox" name="quitar_imagen" value="1"> Quitar
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php /* ── INVENTARIO ──
             Solo con Inventario encendido (Ajustes). Un servicio deja la
             casilla apagada y no ve nada más. La existencia se captura una
             sola vez; después cambia con ventas, entradas, salidas y
             conteos, para que cada pieza tenga su rastro. */
    if (!empty($inventario)):
      $lleva = !empty($e['lleva_inventario']);
      $sucs  = $inventario['sucursales'];
      $uniE  = strtolower(trim((string)($e['unidad_medida'] ?? 'pieza')));
    ?>
    <div class="lf-prod-inv">
      <input type="hidden" name="inv_en_form" value="1">
      <label class="lf-sw">
        <input type="checkbox" name="lleva_inventario" value="1" id="pLleva" <?= $lleva ? 'checked' : '' ?>>
        <i aria-hidden="true"></i>
        <span><b>Lleva existencias</b> <small>Es un producto físico: Caja descuenta las piezas al venderlo.</small></span>
      </label>
      <div class="cuerpo" id="pInv" <?= $lleva ? '' : 'hidden' ?>>

        <?php /* ── Impuestos ──
                 Solo para producto físico. Un servicio sigue con la regla
                 de IVA de la empresa, que es lo que un despacho necesita.
                 En una tienda no alcanza: el refresco lleva IVA e IEPS, el
                 cuaderno solo IVA, y la tortilla va a tasa cero. */
        $ivaAp  = !array_key_exists('iva_aplica', (array)$e) || !empty($e['iva_aplica']);
        $ivaT   = (string)($e['iva_tasa'] ?? '');
        $iepsAp = !empty($e['ieps_aplica']);
        $iepsT  = (string)($e['ieps_tasa'] ?? '');
        ?>
        <input type="hidden" name="impuestos_en_form" value="1">
        <div class="lf-prod-imp">
          <label class="lf-sw">
            <input type="checkbox" name="iva_aplica" value="1" id="pIva" <?= $ivaAp ? 'checked' : '' ?>>
            <i aria-hidden="true"></i>
            <span><b>Lleva IVA</b> <small>Apágalo solo si el producto está exento.</small></span>
          </label>
          <div id="pIvaT" <?= $ivaAp ? '' : 'hidden' ?> style="margin:6px 0 10px 46px">
            <label class="form-label" for="pIvaTasa">Tasa</label>
            <select class="form-select" name="iva_tasa" id="pIvaTasa" style="max-width:280px">
              <option value="">La de la empresa (Ajustes)</option>
              <?php foreach (\LibertyFin\Dominio\Impuesto::TASAS_IVA as $k => $r): ?>
                <option value="<?= P::e($k) ?>" <?= $ivaT !== '' && (float)$ivaT === (float)$k ? 'selected' : '' ?>>
                  <?= P::e($r) ?></option>
              <?php endforeach; ?>
            </select>
            <small style="display:block;color:var(--lf-tinta-4);margin-top:3px">
              «Tasa cero» no es lo mismo que exento: el producto sí causa IVA, al 0 %.</small>
          </div>

          <label class="lf-sw">
            <input type="checkbox" name="ieps_aplica" value="1" id="pIeps" <?= $iepsAp ? 'checked' : '' ?>>
            <i aria-hidden="true"></i>
            <span><b>Lleva IEPS</b> <small>Refrescos, botanas, alcohol, tabaco.</small></span>
          </label>
          <div id="pIepsT" <?= $iepsAp ? '' : 'hidden' ?> style="margin:6px 0 10px 46px">
            <label class="form-label" for="pIepsTasa">Tasa de IEPS</label>
            <select class="form-select" name="ieps_tasa" id="pIepsTasa" style="max-width:360px">
              <option value="">Elige una</option>
              <?php foreach (\LibertyFin\Dominio\Impuesto::TASAS_IEPS as $k => $r): ?>
                <option value="<?= P::e($k) ?>" <?= $iepsT !== '' && (float)$iepsT === (float)$k ? 'selected' : '' ?>>
                  <?= P::e($r) ?></option>
              <?php endforeach; ?>
            </select>
            <small style="display:block;color:var(--lf-tinta-4);margin-top:3px">
              El IEPS se calcula antes que el IVA, y el IVA sobre la suma de los dos.</small>
          </div>
        </div>

        <?php /* ── Perecederos ── */
        $per  = !empty($e['es_perecedero']);
        $cad  = (string)($e['fecha_caducidad'] ?? '');
        $dias = (string)($e['dias_alerta_caducidad'] ?? '');
        ?>
        <input type="hidden" name="caducidad_en_form" value="1">
        <div class="lf-prod-per">
          <label class="lf-sw">
            <input type="checkbox" name="es_perecedero" value="1" id="pPer" <?= $per ? 'checked' : '' ?>>
            <i aria-hidden="true"></i>
            <span><b>Es perecedero</b> <small>Caduca: avisamos antes de que se venza.</small></span>
          </label>
          <div id="pPerC" <?= $per ? '' : 'hidden' ?>
               style="margin:6px 0 10px 46px;display:flex;gap:12px;flex-wrap:wrap">
            <div style="width:170px">
              <label class="form-label" for="pCad">Caduca el</label>
              <input class="form-control" type="date" name="fecha_caducidad" id="pCad" value="<?= P::e($cad) ?>">
            </div>
            <div style="width:190px">
              <label class="form-label" for="pDias">Avisar con</label>
              <input class="form-control lf-mono" type="number" min="0" max="3650" step="1"
                     name="dias_alerta_caducidad" id="pDias" value="<?= P::e($dias) ?>"
                     placeholder="días antes">
              <small style="display:block;color:var(--lf-tinta-4);margin-top:3px">
                Vacío usa los días de Ajustes.</small>
            </div>
          </div>
        </div>

        <div class="lf-prod-inv-uni">
          <label class="form-label" for="pUnidad">Se cuenta por</label>
          <select class="form-select" name="unidad_medida" id="pUnidad">
            <?php foreach (\LibertyFin\Servicio\Inventario::UNIDADES as $k => $u): ?>
              <option value="<?= $k ?>" <?= $uniE === $k ? 'selected' : '' ?>><?= P::e($u[0]) ?></option>
            <?php endforeach; ?>
            <?php if ($uniE !== '' && !isset(\LibertyFin\Servicio\Inventario::UNIDADES[$uniE])): /* la del sistema anterior: se respeta */ ?>
              <option value="<?= P::e($e['unidad_medida']) ?>" selected><?= P::e($e['unidad_medida']) ?></option>
            <?php endif; ?>
          </select>
        </div>
        <table class="lf-prod-inv-tabla">
          <thead><tr>
            <?php if (count($sucs) > 1): ?><th>Sucursal</th><?php endif; ?>
            <th>Existencia</th><th>Avisar cuando queden</th>
          </tr></thead>
          <tbody>
          <?php foreach ($sucs as $sid => $s): ?>
            <tr>
              <?php if (count($sucs) > 1): ?><td class="suc"><?= P::e($s['nombre']) ?></td><?php endif; ?>
              <td>
                <?php if ($s['puedeInicial']): ?>
                  <input class="form-control lf-mono" type="number" name="inicial[<?= (int)$sid ?>]" min="0" step="0.001"
                         placeholder="0" aria-label="Existencia inicial<?= count($sucs) > 1 ? ' en ' . P::e($s['nombre']) : '' ?>">
                <?php else: ?>
                  <b class="lf-mono<?= $s['existencia'] <= 0 ? ' mal' : '' ?>"><?= P::e(\LibertyFin\Servicio\Inventario::conUnidad($s['existencia'], $uniE)) ?></b>
                  <a class="lf-link" href="/inventario?s=<?= (int)$sid ?>&amp;mover=entrada&amp;producto=<?= (int)($e['id'] ?? 0) ?>#invMover">Registrar entrada</a>
                <?php endif; ?>
              </td>
              <td>
                <input class="form-control lf-mono" type="number" name="minimo[<?= (int)$sid ?>]" min="0" step="0.001"
                       value="<?= $s['minimo'] > 0 ? P::e(\LibertyFin\Servicio\Inventario::num($s['minimo'])) : '' ?>"
                       placeholder="Sin aviso" aria-label="Mínimo<?= count($sucs) > 1 ? ' en ' . P::e($s['nombre']) : '' ?>">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="pista">La existencia inicial se escribe una sola vez. Después cambia sola con las ventas,
          y con las entradas, salidas y conteos de <a href="/inventario">Inventario</a>.</p>
      </div>
    </div>
    <script>
    (function(){
      // Cada casilla enseña u oculta su bloque. Si el navegador no corre
      // esto, los bloques quedan visibles y el formulario sigue sirviendo:
      // lo que se guarda lo decide el servidor, no estos `hidden`.
      [['pLleva','pInv'], ['pIva','pIvaT'], ['pIeps','pIepsT'], ['pPer','pPerC']]
        .forEach(function (par) {
          var c = document.getElementById(par[0]), b = document.getElementById(par[1]);
          if (c && b) c.addEventListener('change', function(){ b.hidden = !c.checked; });
        });

      // El escáner teclea el código y manda Enter. Sin esto, ese Enter
      // envía el formulario a medio llenar.
      var bar = document.getElementById('pBarras');
      if (bar) bar.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); bar.blur(); }
      });
    })();
    </script>
    <?php endif; ?>

    <?php if (!empty($modProducto)): $mp = $modProducto; ?>
    <?php /* Lo que piden los módulos encendidos (Ajustes → Módulos). */ ?>
    <div class="lf-prod-mods">
      <?php if ($mp['agenda']): ?>
        <input type="hidden" name="mod_agenda" value="1">
        <div class="lf-prod-mod">
          <label class="form-label" for="pDur">Duración <small>(Agenda)</small></label>
          <div class="lf-prod-dur">
            <input class="form-control lf-mono" type="number" name="duracion_min" id="pDur" min="5" max="720" step="5"
                   value="<?= P::e($e['duracion_min'] ?? '') ?>" placeholder="—"><span>min</span>
          </div>
          <p class="pista">Lo que ocupa en la agenda. Vacío si no se agenda.</p>
        </div>
      <?php endif; ?>
      <?php if ($mp['paquetes']): $esPaq = (int)($e['sesiones'] ?? 0) > 0; ?>
        <input type="hidden" name="mod_paquetes" value="1">
        <div class="lf-prod-mod lf-prod-paq">
          <label class="lf-sw"><input type="checkbox" name="es_paquete" value="1" id="pEsPaq" <?= $esPaq ? 'checked' : '' ?>>
            <i aria-hidden="true"></i><span><b>Es un paquete de sesiones</b><small>Ej. «10 masajes»: el cliente lo paga una vez y las usa en sus visitas.</small></span></label>
          <div class="cuerpo" id="pPaq" <?= $esPaq ? '' : 'hidden' ?>>
            <div style="width:110px"><label class="form-label" for="pSes">Sesiones</label>
              <input class="form-control lf-mono" type="number" name="sesiones" id="pSes" min="2" max="200" value="<?= P::e($esPaq ? (int)$e['sesiones'] : 10) ?>"></div>
            <div style="flex:1;min-width:200px"><label class="form-label" for="pSesDe">De qué servicio</label>
              <select class="form-select" name="sesion_de" id="pSesDe">
                <option value="">Elige…</option>
                <?php foreach ($mp['servicios'] as $s): if ($e && (int)$s['id'] === (int)$e['id']) continue; ?>
                  <option value="<?= (int)$s['id'] ?>" <?= (int)($e['sesion_de'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <p class="pista">El precio de arriba es el del paquete completo. En Caja, cuando el cliente vuelve, el servicio se cobra en $0 y se descuenta una sesión.</p>
          </div>
        </div>
        <script>
        (function(){
          var c = document.getElementById('pEsPaq'), b = document.getElementById('pPaq');
          if (c && b) c.addEventListener('change', function(){ b.hidden = !c.checked; });
        })();
        </script>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* Lo de vez en cuando, plegado. La descripción ya se guardaba,
             pero no había campo: al editar un producto se borraba la que
             traía del sistema anterior. */ ?>
    <details class="lf-mas-datos lf-prod-mas" <?= trim((string)($e['descripcion'] ?? '')) !== '' ? 'open' : '' ?>>
      <summary><span>Más detalles</span><small>descripción</small></summary>
      <div class="cuerpo">
        <label class="form-label" for="pDesc">Descripción <small>(opcional)</small></label>
        <textarea class="form-control" name="descripcion" id="pDesc" rows="2" maxlength="2000"
                  placeholder="Qué incluye, para quién es, requisitos…"><?= P::e($e['descripcion'] ?? '') ?></textarea>
        <p class="pista">Se ve en Caja al pasar el mouse sobre el producto.</p>
      </div>
    </details>
    <div style="display:flex;flex-direction:row;flex-wrap:wrap;align-items:center;gap:8px 14px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Dar de alta' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/servicios">Cancelar</a><?php endif; ?>
      <?php if ($e): ?>
        <?php /* Los descuentos viven en Promociones (con fechas, solos en
                 Caja y sin acumularse): aquí no hay campo de descuento. */ ?>
        <a class="lf-link" href="/servicios?t=promociones&amp;nueva=1&amp;producto=<?= (int)$e['id'] ?>">
          Ponerle una promoción</a>
      <?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      Escribe la utilidad y el precio se calcula solo, o al revés. El costo se resta antes de calcular
      comisiones, así que no puede ser mayor al precio. Para un descuento, usa una promoción.
    </p>
  </form>
  <script>
  /* Precio, costo y utilidad: cualquiera de los tres mueve a los otros. */
  (function(){
    var P = document.getElementById('pPrecio'), C = document.getElementById('pCosto'),
        U = document.getElementById('pUtil'), M = document.getElementById('pMargen'),
        $m = document.getElementById('pMargenMonto');
    if (!P || !C || !U) return;
    var num = function(el){ return parseFloat(el.value) || 0; };
    var pesos = function(n){ return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('es-MX',
                               {minimumFractionDigits:2, maximumFractionDigits:2}); };
    var red = function(n){ return Math.round(n * 100) / 100; };
    function margen(){
      var p = num(P), c = num(C), t = M.querySelector('.t');
      M.className = 'lf-margen';
      U.disabled = c <= 0;
      U.placeholder = c > 0 ? '0' : '—';
      if (c <= 0) U.value = '';
      if (p <= 0) { t.textContent = 'Precio en cero'; $m.textContent = 'se pone al vender'; M.classList.add('neutro'); return; }
      if (c > p)  { t.textContent = 'El costo es mayor al precio'; $m.textContent = pesos(red(p - c)); M.classList.add('mal'); return; }
      t.textContent = c > 0 ? 'Te queda por venta' : 'Sin costo: te queda todo';
      $m.textContent = pesos(red(p - c));
    }
    function utilDesdePrecio(){
      var p = num(P), c = num(C);
      if (document.activeElement !== U) U.value = (c > 0 && p > 0) ? red((p / c - 1) * 100) : '';
    }
    P.addEventListener('input', function(){ utilDesdePrecio(); margen(); });
    C.addEventListener('input', function(){ utilDesdePrecio(); margen(); });
    U.addEventListener('input', function(){
      var c = num(C);
      if (c > 0 && U.value !== '') P.value = red(c * (1 + num(U) / 100)).toFixed(2);
      margen();
    });
    utilDesdePrecio(); margen();
  })();
  </script>
  <script>
  /* Clave SAT: al escribir una palabra en el campo se buscan claves en el
     catálogo del SAT (Facturapi). Al elegir una se queda solo el número. */
  (function(){
    var S = document.getElementById('pSat'), L = document.getElementById('pSatLista');
    if (!S || !L) return;
    var t = null, pedido = 0, ultima = '', activa = -1;

    function cerrar(){ L.hidden = true; L.innerHTML = ''; activa = -1; S.setAttribute('aria-expanded','false'); }
    function nota(txt){ L.innerHTML = ''; var li = document.createElement('li'); li.className = 'nota';
      li.textContent = txt; L.appendChild(li); L.hidden = false; }
    function elegir(clave){ S.value = clave; cerrar(); S.focus(); }
    function marcar(i){
      var items = L.querySelectorAll('li[data-clave]');
      if (!items.length) return;
      activa = (i + items.length) % items.length;
      items.forEach(function(li, k){ li.classList.toggle('activa', k === activa); });
      items[activa].scrollIntoView({block:'nearest'});
    }
    function buscar(q){
      q = q.trim();
      if (q.length < 3) { cerrar(); return; }
      if (q === ultima && !L.hidden) return;
      ultima = q;
      var mio = ++pedido;
      nota('Buscando en el catálogo del SAT…');
      fetch('/servicios/claves-sat?q=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (mio !== pedido) return;                   /* llegó una búsqueda más nueva */
          if (d.error) { nota(d.error); return; }
          if (!d.claves || !d.claves.length) { nota('Sin claves para «' + q + '».'); return; }
          L.innerHTML = ''; activa = -1;
          d.claves.forEach(function(c){
            var li = document.createElement('li'), b = document.createElement('b'), s = document.createElement('span');
            li.setAttribute('role','option'); li.dataset.clave = c.clave;
            b.textContent = c.clave; s.textContent = c.descripcion;
            li.appendChild(b); li.appendChild(s);
            li.addEventListener('mousedown', function(ev){ ev.preventDefault(); elegir(c.clave); });
            L.appendChild(li);
          });
          L.hidden = false; S.setAttribute('aria-expanded','true');
        })
        .catch(function(){ if (mio === pedido) nota('No se pudo consultar el catálogo del SAT.'); });
    }
    function pronto(q){ clearTimeout(t); t = setTimeout(function(){ buscar(q); }, 400); }

    S.addEventListener('input', function(){
      var v = S.value.trim();
      if (/^\d*$/.test(v)) { clearTimeout(t); cerrar(); return; }   /* teclea el número directo */
      pronto(v);
    });
    S.addEventListener('keydown', function(e){
      if (L.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); marcar(activa + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(activa - 1); }
      else if (e.key === 'Enter' && activa >= 0) { e.preventDefault(); elegir(L.querySelectorAll('li[data-clave]')[activa].dataset.clave); }
      else if (e.key === 'Escape') cerrar();
    });
    document.addEventListener('mousedown', function(e){ if (!e.target.closest('.lf-sat')) cerrar(); });
  })();
  </script>
</details>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Producto o código">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['activos'] ?? 0) ?></div>
    <div class="stat-label">Productos activos</div>
    <div class="stat-meta">en el catálogo</div></div>

  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($mv['nombre'] ?? '—') ?></div>
    <div class="stat-label">Más vendido</div>
    <div class="stat-meta"><?= $mv ? (int)$mv['veces'] . ' ventas · ' . D::corto($mv['ingreso']) : 'sin ventas en el periodo' ?></div></div>

  <div class="stat-card"><div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($at['area'] ?? '—') ?></div>
    <div class="stat-label">Área con más ingreso</div>
    <div class="stat-meta"><?= $at ? D::pesos($at['cobrado']) . ' cobrados' : '—' ?></div></div>

  <div class="stat-card"><div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['sin_ventas'] ?? 0) ?></div>
    <div class="stat-label">Sin ventas</div>
    <div class="stat-meta">en los últimos 60 días</div></div>
</div>


<section class="card">
  <header class="card-header">Catálogo</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Producto</th><th>Código</th><th>Área</th>
        <th class="text-end">Precio</th><th class="text-end">Ventas</th>
        <th class="text-end">Ingreso</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$catalogo): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay productos que coincidan.</td></tr>
      <?php endif; ?>
      <?php foreach ($catalogo as $s): ?>
        <?php /* TOCAR EL RENGLÓN EDITA.
             El lápiz mide veinte píxeles y en un teléfono se falla más de
             lo que se acierta; además nadie espera que tocar un producto
             no haga nada. La fila entera lleva a editarlo, y el lápiz se
             queda para quien lo busque ahí. */ ?>
        <tr class="lf-fila-editar" tabindex="0" role="link"
            data-editar="<?= P::e($qs(['editar'=>$s['id']])) ?>"
            aria-label="Editar <?= P::e($s['nombre']) ?>">
          <td data-label="Producto">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-mini-img"
                    style="<?= !empty($s['imagen']) ? "background-image:url('".P::e($s['imagen'])."')" : '' ?>">
                <?= !empty($s['imagen']) ? '' : W::icono('serv','14px') ?></span>
              <b style="font-weight:600"><?= P::e($s['nombre']) ?></b>
            </span>
          </td>
          <td data-label="Código"><span class="badge bg-secondary"><?= P::e($s['codigo']) ?></span></td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($s['categoria'] ?: 'Sin área') ?></span></td>
          <td data-label="Precio" class="text-end lf-mono"><?= D::pesos($s['precio']) ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Ingreso" class="text-end">
            <?php if ((int)$s['ventas'] > 0): ?>
              <b class="lf-mono"><?= D::pesos($s['ingreso']) ?></b>
            <?php else: ?>
              <span class="badge bg-warning">Sin ventas</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$s['id']])) ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-alternar"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                    title="Desactivar"><span class="lf-signo">&times;</span></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($catalogo) ?> de <?= number_format($total) ?> productos</span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
</section>

<?php /* LOS REPORTES VAN DEBAJO DEL CATÁLOGO.
     Estaban arriba, así que al entrar a Productos lo primero que se
     veía eran dos gráficas y había que bajar para llegar a la lista.
     A esta pantalla se entra a buscar un producto o a editarlo; lo
     que más facturó es interesante, pero después. */ ?>
<div class="lf-split">
  <?php if ($top): ?>
  <section class="card">
    <header class="card-header">
      <div><span>Productos que más facturan</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Del periodo filtrado</p></div>
    </header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($t){
        return ['rotulo'=>$t['nombre'],'monto'=>$t['monto']]; }, $top)); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($areas): ?>
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <div><span>Cobrado por área</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cobrado, no facturado</p></div>
    </header>
    <div class="card-body">
      <?php W::dona(array_map(function($a){
        return ['rotulo'=>$a['area'],'monto'=>$a['monto']]; }, $areas),
        D::corto($tot), 'cobrado'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<form method="post" action="/servicios/alternar" id="formAlternar" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="altId">
</form>
<script>
document.querySelectorAll('.lf-alternar').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Desactivar "' + b.dataset.nombre + '"?\n\n'
      + 'Deja de aparecer en Caja, pero las ventas que ya lo usaron no se tocan.')) return;
    document.getElementById('altId').value = b.dataset.id;
    document.getElementById('formAlternar').submit();
  });
});
</script>

<script>
(function(){
  var i = document.getElementById('inpServ'), p = document.getElementById('prevServ');
  if (!i || !p) return;
  i.addEventListener('change', function(){
    var f = i.files && i.files[0];
    if (!f) return;
    p.style.backgroundImage = "url('" + URL.createObjectURL(f) + "')";
    p.textContent = '';
  });
})();
</script>

<script>
/* Tocar un renglón del catálogo lo abre para editar.
   Se ignora cuando el toque fue en un botón, un enlace o un campo: ahí
   la persona quiso hacer OTRA cosa, y robarle el clic para editar es
   la clase de detalle que hace que una pantalla se sienta traicionera. */
(function () {
  function abrir(fila, nueva) {
    var u = fila.getAttribute('data-editar');
    if (!u) return;
    if (nueva) window.open(u, '_blank', 'noopener'); else location.href = u;
  }
  document.addEventListener('click', function (e) {
    var fila = e.target.closest('.lf-fila-editar');
    if (!fila) return;
    if (e.target.closest('a,button,input,select,textarea,label,[role=button]')) return;
    if (window.getSelection && String(window.getSelection())) return;  /* estaba copiando */
    e.preventDefault();
    abrir(fila, e.ctrlKey || e.metaKey);
  });
  /* Con teclado: Enter o espacio, como cualquier enlace. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var fila = e.target.closest && e.target.closest('.lf-fila-editar');
    if (!fila || e.target !== fila) return;
    e.preventDefault();
    abrir(fila, false);
  });
})();
</script>