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
// Los filtros vigentes viajan en cada enlace: paginar o editar no los pierde.
$fBase = array_filter(['estado' => $filtro['estado'] ?? '', 'marca' => $filtro['marca'] ?? '',
                       'area' => (int)($filtro['area'] ?? 0) ?: '', 'tipo' => $filtro['tipo'] ?? ''],
                      function ($v) { return $v !== ''; });
$qs = function (array $x = []) use ($desde,$hasta,$buscar,$fBase) {
    return '?' . http_build_query(array_filter(array_merge(['desde'=>$desde,'hasta'=>$hasta,'q'=>$buscar], $fBase, $x),
                                               function ($v) { return $v !== '' && $v !== null; })); };
// A dónde volver después de cambiar el estado, duplicar o borrar desde la lista.
$volverQ = $qs(['p' => $pagina > 1 ? $pagina : '']);
// El estado como se guardó (lo que se elige en el formulario): «agotado» a
// mano es distinto de quedarse sin existencias, que se calcula solo.
$estadoE = !$e ? 'activo' : (!(int)($e['activo'] ?? 1) ? 'inactivo' : (!empty($e['agotado']) ? 'agotado' : 'activo'));
$ESTADO_BADGE = ['activo' => 'bg-success', 'agotado' => 'bg-warning', 'inactivo' => 'bg-secondary'];
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
        <?php foreach (array_unique(array_merge($opcionesFiltro['marcas'] ?? [],
                       array_filter(array_column($catalogo ?? [], 'marca')))) as $m): ?>
          <option value="<?= P::e($m) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <?php /* Tipo de producto: por lo que filtran Productos, Existencias y
             Conteo. Ya existía en la base (el sistema anterior ponía
             «Estandar» o «Servicio»); se escribe libre, con sugerencias. */
    $tipoE = (string)($e['tipo_producto'] ?? '');
    $tipoE = ($tipoE === '' || strcasecmp($tipoE, 'Estandar') === 0) ? 'Estándar' : $tipoE; ?>
    <div style="width:150px">
      <label class="form-label" for="pTipo">Tipo de producto</label>
      <input class="form-control" name="tipo_producto" id="pTipo" maxlength="50"
             value="<?= P::e($tipoE) ?>" list="lTipos" autocomplete="off">
      <datalist id="lTipos">
        <?php foreach (array_unique(array_merge(['Estándar', 'Servicio', 'Granel', 'Paquete', 'Insumo'],
                       array_map([\LibertyFin\Servicio\FiltrosProducto::class, 'rotuloTipo'], $opcionesFiltro['tipos'] ?? []))) as $t): ?>
          <option value="<?= P::e($t) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <?php /* Activo, agotado o inactivo. Agotado a mano es para lo que no
             lleva existencias (el platillo que se acabó); lo que sí las
             lleva se ve agotado solo cuando llega a cero. */ ?>
    <div style="width:140px">
      <label class="form-label" for="pEstado">Estado</label>
      <select class="form-select" name="estado" id="pEstado">
        <?php foreach (\LibertyFin\Datos\ServicioRepo::ESTADOS as $k => $t): ?>
          <option value="<?= $k ?>" <?= $estadoE === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
        <?php endforeach; ?>
      </select>
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

    <?php /* ── PREVENTA ──
             Mientras esté encendida, lo que se venda de este producto no
             sale del inventario: queda apartado y se entrega desde
             Ventas → Preventas cuando llegue (Servicio\Preventas). */
    if (!empty($preventa)):
      $pvOn = !empty($preventa['preventa']);
      $pvApartadas = (float)($preventa['apartadas'] ?? 0);
    ?>
    <div class="lf-prod-inv">
      <input type="hidden" name="preventa_en_form" value="1">
      <label class="lf-sw">
        <input type="checkbox" name="preventa" value="1" id="pPre" <?= $pvOn ? 'checked' : '' ?>>
        <i aria-hidden="true"></i>
        <span><b>En preventa</b> <small>Se vende aunque no haya piezas. Se entrega cuando llegue.</small></span>
      </label>
      <div class="cuerpo" id="pPreC" <?= $pvOn ? '' : 'hidden' ?>>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <div style="width:170px">
            <label class="form-label" for="pPreLlega">Llega aprox.</label>
            <input class="form-control" type="date" name="preventa_llegada" id="pPreLlega"
                   value="<?= P::e($preventa['preventa_llegada'] ?? '') ?>">
          </div>
          <div style="width:150px">
            <label class="form-label" for="pPreLim">Cupo <small>(opcional)</small></label>
            <input class="form-control lf-mono" type="number" name="preventa_limite" id="pPreLim" min="0" step="0.001"
                   value="<?= $preventa['preventa_limite'] !== null ? P::e(\LibertyFin\Servicio\Inventario::num($preventa['preventa_limite'])) : '' ?>"
                   placeholder="Sin tope">
          </div>
          <div style="width:150px">
            <label class="form-label" for="pPreAnt">Anticipo mínimo</label>
            <input class="form-control lf-mono" type="number" name="preventa_anticipo" id="pPreAnt" min="0" max="100" step="0.01"
                   value="<?= $preventa['preventa_anticipo'] !== null ? P::e((float)$preventa['preventa_anticipo']) : '' ?>"
                   placeholder="% libre">
          </div>
        </div>
        <p class="pista">
          <?php if ($pvApartadas > 0): ?>
            Ya se han prevendido <b class="lf-mono"><?= P::e(\LibertyFin\Servicio\Inventario::num($pvApartadas)) ?></b>.
            Las que faltan por entregar están en <a href="/preventas?producto=<?= (int)($e['id'] ?? 0) ?>">Preventas</a>.
          <?php else: ?>
            El cupo cuenta todo lo prevendido. El anticipo es un porcentaje del importe de estos productos en el ticket.
          <?php endif; ?>
          Cuando ya tengas piezas para vender normal, apaga la casilla: las preventas pendientes se siguen entregando.
        </p>
      </div>
    </div>
    <script>
    (function(){
      var c = document.getElementById('pPre'), b = document.getElementById('pPreC');
      if (c && b) c.addEventListener('change', function(){ b.hidden = !c.checked; });
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

    <?php /* ── ETIQUETAS Y ATRIBUTOS ──
             Un renglón por etiqueta: el nombre y, si se quiere, un valor
             y un color. «Nuevo» sola; «Talla · M»; «Color · Rojo» con su
             muestra. Se ven en la lista y se encuentran con el buscador. */
    $atrs = $atributosEdit ?? []; ?>
    <details class="lf-mas-datos lf-prod-atr" <?= $atrs ? 'open' : '' ?>>
      <summary><span>Etiquetas y atributos</span>
        <small><?= $atrs ? count($atrs) . (count($atrs) === 1 ? ' etiqueta' : ' etiquetas') : 'talla, color, «nuevo»…' ?></small></summary>
      <div class="cuerpo">
        <input type="hidden" name="atributos_en_form" value="1">
        <datalist id="lAtrNombres">
          <?php foreach (array_unique(array_merge(['Color', 'Talla', 'Material', 'Tamaño', 'Sabor', 'Nuevo', 'Oferta'],
                         $nombresAtributos ?? [])) as $n): ?>
            <option value="<?= P::e($n) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <div class="lf-atr-lista" id="pAtrLista">
          <?php foreach (array_merge($atrs, [['nombre' => '', 'valor' => '', 'color' => null]]) as $i => $a):
            $conColor = !empty($a['color']); ?>
            <div class="lf-atr-fila">
              <input class="form-control" name="atr_nombre[]" maxlength="60" list="lAtrNombres"
                     value="<?= P::e($a['nombre']) ?>" placeholder="Etiqueta o atributo" aria-label="Nombre">
              <input class="form-control" name="atr_valor[]" maxlength="120"
                     value="<?= P::e($a['valor'] ?? '') ?>" placeholder="Valor (opcional)" aria-label="Valor">
              <label class="lf-atr-color" title="Ponerle color">
                <input type="hidden" name="atr_usa_color[]" value="<?= $conColor ? '1' : '' ?>" class="usa">
                <input type="color" name="atr_color[]" value="<?= P::e($conColor ? $a['color'] : '#27ae60') ?>"
                       class="<?= $conColor ? 'on' : '' ?>" aria-label="Color">
                <span><?= $conColor ? 'Color' : 'Sin color' ?></span>
              </label>
              <button type="button" class="lf-btn-ghost lf-atr-quitar" title="Quitar" aria-label="Quitar">
                <span class="lf-signo">&times;</span></button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" id="pAtrMas"><?= W::icono('etiqueta','14px') ?> Agregar otra</button>
        <p class="pista">Solo el nombre es una etiqueta («Nuevo», «Importado»). Con valor es un atributo
          («Talla · M»). El color es opcional: tócalo para ponerlo y vuelve a tocar «Color» para quitarlo.</p>
      </div>
    </details>
    <script>
    (function(){
      var lista = document.getElementById('pAtrLista'), mas = document.getElementById('pAtrMas');
      if (!lista || !mas) return;
      function limpiar(f){
        f.querySelectorAll('input[name="atr_nombre[]"], input[name="atr_valor[]"]').forEach(function(i){ i.value = ''; });
        var u = f.querySelector('.usa'), c = f.querySelector('input[type=color]'), s = f.querySelector('.lf-atr-color span');
        u.value = ''; c.classList.remove('on'); c.value = '#27ae60'; s.textContent = 'Sin color';
      }
      mas.addEventListener('click', function(){
        var f = lista.lastElementChild.cloneNode(true);
        limpiar(f); lista.appendChild(f);
        f.querySelector('input').focus();
      });
      lista.addEventListener('click', function(ev){
        var q = ev.target.closest('.lf-atr-quitar');
        if (q) {
          var f = q.closest('.lf-atr-fila');
          if (lista.children.length > 1) f.remove(); else limpiar(f);
          return;
        }
        // Tocar «Color» cuando ya tiene color lo quita.
        var s = ev.target.closest('.lf-atr-color span');
        if (s) {
          var l = s.closest('.lf-atr-color'), u = l.querySelector('.usa'), c = l.querySelector('input[type=color]');
          if (u.value === '1') { ev.preventDefault(); u.value = ''; c.classList.remove('on'); s.textContent = 'Sin color'; }
        }
      });
      // Elegir un color lo enciende.
      lista.addEventListener('input', function(ev){
        if (ev.target.type !== 'color') return;
        var l = ev.target.closest('.lf-atr-color');
        l.querySelector('.usa').value = '1'; ev.target.classList.add('on');
        l.querySelector('span').textContent = 'Color';
      });
    })();
    </script>

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
      <?php /* Mercado Libre: solo con el módulo encendido, permiso y cuenta
               conectada (ServiciosControlador::meliDe). Es un enlace y no un
               botón del formulario: publicar no guarda los cambios de aquí. */
      if ($e && !empty($meli)): ?>
        <?php if (!empty($meli['publicado'])): ?>
          <?php if (!empty($meli['publicado']['permalink'])): ?>
            <a class="lf-link" href="<?= P::e($meli['publicado']['permalink']) ?>" target="_blank" rel="noopener">
              Ver en Mercado Libre ↗</a>
          <?php else: ?>
            <span class="lf-link">En Mercado Libre: <?= P::e($meli['publicado']['item_id']) ?></span>
          <?php endif; ?>
        <?php elseif (!empty($e['lleva_inventario'])): ?>
          <a class="btn btn-secondary" href="/mercadolibre/publicar?producto=<?= (int)$e['id'] ?>">
            <?= W::icono('bolsa','14px') ?> Publicar en Mercado Libre</a>
        <?php endif; ?>
      <?php endif; ?>
       <?php /* Tienda Nube: igual, con su módulo, permiso y tienda conectada (ServiciosControlador::tnDe). */
      if ($e && !empty($tn)): ?>
        <?php if (!empty($tn['publicado'])): ?>
          <?php if (!empty($tn['publicado']['permalink'])): ?>
            <a class="lf-link" href="<?= P::e($tn['publicado']['permalink']) ?>" target="_blank" rel="noopener">
              Ver en Tienda Nube ↗</a>
          <?php else: ?>
            <span class="lf-link">En Tienda Nube: #<?= P::e($tn['publicado']['producto_tn']) ?></span>
          <?php endif; ?>
        <?php elseif (!empty($e['lleva_inventario'])): ?>
          <a class="btn btn-secondary" href="/tiendanube/publicar?producto=<?= (int)$e['id'] ?>">
            <?= W::icono('bolsa','14px') ?> Publicar en Tienda Nube</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php /* Shopify: igual, con su módulo, permiso y tienda conectada (ServiciosControlador::shopDe). */
      if ($e && !empty($shop)): ?>
        <?php if (!empty($shop['publicado'])): ?>
          <?php if (!empty($shop['publicado']['permalink'])): ?>
            <a class="lf-link" href="<?= P::e($shop['publicado']['permalink']) ?>" target="_blank" rel="noopener">
              Ver en Shopify ↗</a>
          <?php else: ?>
            <span class="lf-link">En Shopify: #<?= P::e($shop['publicado']['producto_shop']) ?></span>
          <?php endif; ?>
        <?php elseif (!empty($e['lleva_inventario'])): ?>
          <a class="btn btn-secondary" href="/shopify/publicar?producto=<?= (int)$e['id'] ?>">
            <?= W::icono('bolsa','14px') ?> Publicar en Shopify</a>
        <?php endif; ?>
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

<?php /* Activos y agotados (lo de siempre), solo agotados, inactivos o
         todos. Los inactivos estaban escondidos sin forma de verlos: un
         producto desactivado por error no se podía volver a activar. */ ?>
<div class="lf-pills" aria-label="Estado" style="margin-bottom:12px">
  <?php foreach (\LibertyFin\Datos\ServicioRepo::FILTRO_ESTADO as $k => $t):
    $n = $k === '' ? ($porEstado['activo'] ?? 0) + ($porEstado['agotado'] ?? 0)
       : ($k === 'todos' ? array_sum($porEstado ?? []) : ($porEstado[$k] ?? 0)); ?>
    <a class="lf-pill <?= ($filtro['estado'] ?? '') === $k ? 'active' : '' ?>"
       href="<?= P::e($qs(['estado' => $k, 'p' => ''])) ?>"><?= P::e($t) ?><span class="n"><?= number_format($n) ?></span></a>
  <?php endforeach; ?>
</div>

<form class="lf-filtros" method="get">
  <?php if (($filtro['estado'] ?? '') !== ''): ?><input type="hidden" name="estado" value="<?= P::e($filtro['estado']) ?>"><?php endif; ?>
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Producto, código o etiqueta">
  </div>
  <?php P::parcial('parciales/filtros-producto', ['opcionesFiltro' => $opcionesFiltro, 'filtro' => $filtro]); ?>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto" aria-label="Ventas desde">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto" aria-label="Ventas hasta">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <?php if ($buscar !== '' || $fBase): ?>
    <a class="lf-link" href="/servicios">Quitar filtros</a>
  <?php endif; ?>
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
        <th>Producto</th><th>Código</th><th>Área</th><th>Estado</th>
        <th class="text-end">Precio</th><th class="text-end">Ventas</th>
        <th class="text-end">Ingreso</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$catalogo): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay productos que coincidan.</td></tr>
      <?php endif; ?>
      <?php foreach ($catalogo as $s):
        $est = $s['estado'] ?? 'activo';
        // «Agotado» que sale solo de las existencias: se dice, para que no
        // parezca que alguien lo marcó.
        $porStock = $est === 'agotado' && !empty($s['lleva_inventario']) && (float)$s['stock'] <= 0; ?>
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
              <span style="min-width:0">
                <b style="font-weight:600"><?= P::e($s['nombre']) ?></b>
                <?php if (!empty($s['marca'])): ?><small class="lf-inv-cod" style="display:block"><?= P::e($s['marca']) ?></small><?php endif; ?>
                <?php if (!empty($atributos[(int)$s['id']])): ?>
                  <span class="lf-etiquetas">
                    <?php foreach ($atributos[(int)$s['id']] as $a): ?>
                      <span class="lf-etq<?= $a['color'] ? ' con-color' : '' ?>"
                            <?= $a['color'] ? 'style="--etq:' . P::e($a['color']) . '"' : '' ?>><?php
                        if ($a['color']): ?><i aria-hidden="true"></i><?php endif;
                        ?><?= P::e($a['nombre']) ?><?= ($a['valor'] ?? '') !== '' ? ': <b>' . P::e($a['valor']) . '</b>' : '' ?></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </span>
            </span>
          </td>
          <td data-label="Código"><span class="badge bg-secondary"><?= P::e($s['codigo']) ?></span></td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($s['categoria'] ?: 'Sin área') ?></span></td>
          <td data-label="Estado"><span class="badge <?= $ESTADO_BADGE[$est] ?? 'bg-secondary' ?>"
              <?= $porStock ? 'title="Sin existencias: vuelve a activo solo cuando entre mercancía"' : '' ?>>
              <?= P::e(\LibertyFin\Datos\ServicioRepo::ESTADOS[$est] ?? $est) ?></span>
            <?php if ($porStock): ?><small class="lf-inv-cod" style="display:block">sin existencias</small><?php endif; ?></td>
          <td data-label="Precio" class="text-end lf-mono"><?= D::pesos($s['precio']) ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Ingreso" class="text-end">
            <?php if ((int)$s['ventas'] > 0): ?>
              <b class="lf-mono"><?= D::pesos($s['ingreso']) ?></b>
            <?php else: ?>
              <span class="badge bg-warning">Sin ventas</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap"><div class="lf-prod-acc">
            <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$s['id']])) ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
            <?php /* Los tres estados en un solo control: se elige y se guarda. */ ?>
            <select class="form-select form-select-sm lf-estado-sel" aria-label="Estado de <?= P::e($s['nombre']) ?>"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>" data-antes="<?= P::e($porStock ? 'activo' : $est) ?>">
              <?php foreach (\LibertyFin\Datos\ServicioRepo::ESTADOS as $k => $t): ?>
                <option value="<?= $k ?>" <?= ($porStock ? 'activo' : $est) === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="lf-btn-ghost lf-duplicar"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                    title="Duplicar"><?= W::icono('copiar','15px') ?></button>
            <?php /* Borrar solo aparece con el producto inactivo: primero se
                     desactiva, después se borra. Dos pasos a propósito. */
            if ($est === 'inactivo'): ?>
              <button type="button" class="lf-btn-ghost lf-borrar"
                      data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                      title="Borrar" style="color:var(--lf-rojo)"><?= W::icono('basura','15px') ?></button>
            <?php endif; ?>
          </div></td>
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

<?php /* Un formulario para las tres acciones de la lista (estado, duplicar,
         borrar): cambia el action y se manda. */ ?>
<form method="post" id="formAccion" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="accId">
  <input type="hidden" name="estado" id="accEstado">
  <input type="hidden" name="volver" value="<?= P::e($volverQ) ?>">
</form>
<script>
(function(){
  var f = document.getElementById('formAccion');
  function mandar(accion, id, estado){
    f.action = '/servicios/' + accion;
    document.getElementById('accId').value = id;
    document.getElementById('accEstado').value = estado || '';
    f.submit();
  }
  var AVISO = {
    inactivo: 'Deja de aparecer en Caja, pero las ventas que ya lo usaron no se tocan. Ya inactivo, se puede borrar.',
    agotado:  'Sigue en el catálogo marcado como agotado, hasta que lo vuelvas a poner activo.',
    activo:   'Vuelve a venderse normal.'
  };
  document.querySelectorAll('.lf-estado-sel').forEach(function(s){
    s.addEventListener('change', function(){
      var t = s.options[s.selectedIndex].text;
      if (!confirm('¿Poner «' + s.dataset.nombre + '» como ' + t.toLowerCase() + '?\n\n' + (AVISO[s.value] || ''))) {
        s.value = s.dataset.antes; return;
      }
      mandar('estado', s.dataset.id, s.value);
    });
  });
  document.querySelectorAll('.lf-duplicar').forEach(function(b){
    b.addEventListener('click', function(){
      if (!confirm('¿Duplicar «' + b.dataset.nombre + '»?\n\n'
        + 'Se crea una copia con otro SKU, sin código de barras y sin existencias. Se abre para que la edites.')) return;
      mandar('duplicar', b.dataset.id);
    });
  });
  document.querySelectorAll('.lf-borrar').forEach(function(b){
    b.addEventListener('click', function(){
      if (!confirm('¿Borrar «' + b.dataset.nombre + '»?\n\n'
        + 'Desaparece de Productos, Caja e Inventario. Las ventas y movimientos que ya lo usaron se quedan como están. '
        + 'Esto no se puede deshacer desde aquí.')) return;
      mandar('borrar', b.dataset.id);
    });
  });
})();
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