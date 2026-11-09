<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
// Cómo arranca cada venta (Ajustes → Caja).
$cfgCaja = $cajaCfg ?? ['anticipo' => 'total', 'iva_inicio' => 'sin', 'iva_tasa' => '16'];
$tasaIva = (string)$cfgCaja['iva_tasa'];
$ivaOn   = $cfgCaja['iva_inicio'] === 'con';
// Qué productos tienen promoción hoy (sin las de código).
$pc      = $promoCaja ?? ['promos' => [], 'productos' => [], 'todo' => []];
$pcTodo  = array_values(array_filter(array_map(function ($id) use ($pc) {
    return $pc['promos'][$id] ?? null; }, $pc['todo'])));
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!empty($ligaLista)): $l = $ligaLista; ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 46%,transparent);
         margin-bottom:18px">
  <header class="card-header">
    <div><span>Cómo puede pagar</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        <?= P::e($l['cliente_nombre'] ?: $l['descripcion']) ?> ·
        <b style="color:var(--lf-brand-2)"><?= D::pesos($l['monto']) ?></b></p></div>
    <div style="display:flex;gap:8px;flex-shrink:0">
      <a class="btn btn-secondary btn-sm" href="/ligas/<?= (int)$l['id'] ?>/documento"
         target="_blank"><?= W::icono('baja','14px') ?>Imprimir</a>
      <a class="btn btn-secondary btn-sm" href="/caja">Siguiente venta</a>
    </div>
  </header>
  <div class="card-body">
    <?php P::parcial('ligas/panel', ['l' => $l]); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($cobrada = \LibertyFin\Http\Peticion::texto('ok', '')): ?>
<div class="alert alert-success" style="margin-bottom:14px">
  <?= W::icono('ok','18px') ?>
  <span>Venta <b><?= P::e($cobrada) ?></b> cobrada.
    <?php /* El ticket, a un clic: antes, cobrando en efectivo sin cambio, no
             había por dónde imprimirlo sin ir a buscar la venta. */
    if (($vId = \LibertyFin\Http\Peticion::entero('v')) > 0): ?>
      <a href="/ventas/<?= (int)$vId ?>/ticket?auto=1" target="_blank" data-completo
         style="font-weight:600">Imprimir ticket</a> ·
    <?php endif; ?>
    <a href="/ventas?q=<?= urlencode($cobrada) ?>" style="font-weight:600">Verla</a></span>
</div>
<?php endif; ?>

<?php if (!$cajaAbierta && \LibertyFin\Dominio\Permisos::puede('abrir.caja')): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>No tienes caja abierta.</b> Las ventas se registran igual, pero no entran
    al corte del turno y al cerrar no van a cuadrar.
    <a href="/corte" style="font-weight:600">Abrir caja</a></span>
</div>
<?php endif; ?>

<div class="lf-pos">
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
      <span>Productos</span>
      <div class="lf-pag-serv" id="pagServ" hidden>
        <button type="button" id="servAnt" aria-label="Anterior">&lsaquo;</button>
        <span><b id="servPag">1</b> de <b id="servTot">1</b></span>
        <button type="button" id="servSig" aria-label="Siguiente">&rsaquo;</button>
      </div>
      <form class="lf-search" method="get" style="max-width:240px" id="formBuscaCat">
        <?= W::icono('buscar','15px') ?>
        <input type="search" name="q" id="buscaCat" value="<?= P::e($buscar) ?>" placeholder="Nombre o código  ( / )"
               autocomplete="off" title="Atajo: / para buscar, Enter agrega el primero, Esc limpia">
        <?php if ($area): ?><input type="hidden" name="area" value="<?= P::e($area) ?>"><?php endif; ?>
      </form>
    </header>

    <div class="lf-cat-fila">
      <?php /* Los enlaces siguen siendo enlaces (sin JavaScript funcionan), pero
               con JavaScript solo filtran la rejilla: no recargan y el ticket
               se queda como está. */ ?>
      <a class="lf-pill <?= $area === '' ? 'active' : '' ?>" data-area=""
         href="/caja<?= $buscar ? '?q='.urlencode($buscar) : '' ?>">Todos</a>
      <?php foreach ($areas as $a): ?>
        <a class="lf-pill <?= (string)$area === (string)$a['id'] ? 'active' : '' ?>"
           data-area="<?= (int)$a['id'] ?>"
           href="/caja?area=<?= (int)$a['id'] ?><?= $buscar ? '&q='.urlencode($buscar) : '' ?>">
          <?= P::e($a['nombre']) ?></a>
      <?php endforeach; ?>
    </div>

    <?php /* Las de toda la compra no van en cada tarjeta (serían todas):
             se dicen una vez, arriba del catálogo. */ ?>
    <?php foreach ($pcTodo as $p): ?>
      <p class="lf-promo-hoy"><?= W::icono('pct', '15px') ?>
        <span><b><?= P::e($p['nombre']) ?>:</b> <?= P::e($p['texto']) ?>.</span></p>
    <?php endforeach; ?>

    <div class="lf-grid-serv">
      <p id="catVacio" <?= $servicios ? 'hidden' : '' ?>
         style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);padding:30px;font-size:13px">
        No hay productos que coincidan.</p>
      <?php foreach ($servicios as $s):
        $susPromos = array_values(array_filter(array_map(function ($id) use ($pc) {
            return $pc['promos'][$id] ?? null; }, $pc['productos'][(int)$s['id']] ?? [])));
        $titulo = $s['nombre'];
        $desc = trim((string)($s['descripcion'] ?? ''));
        if ($desc !== '') $titulo .= "\n" . (mb_strlen($desc) > 220 ? mb_substr($desc, 0, 220) . '…' : $desc);
        foreach ($susPromos as $p) $titulo .= "\nPromoción: " . $p['nombre'] . ' (' . $p['texto'] . ')';
        // Existencias: solo con Inventario encendido y si el producto las lleva.
        $ex = $inventario['existencias'][(int)$s['id']] ?? null;
        $hay = $ex !== null ? (float)$ex['existencia'] : null;
        if ($hay !== null) $titulo .= "\n" . ($hay > 0 ? 'Quedan ' . \LibertyFin\Servicio\Inventario::conUnidad($hay, $s['unidad_medida'] ?? '') : 'Agotado');
        // Por kilo, litro o metro: se vende en fracciones y el precio es por unidad.
        $frac = \LibertyFin\Servicio\Inventario::fraccionable($s['unidad_medida'] ?? '');
      ?>
        <button type="button" class="lf-serv<?= (float)$s['precio'] <= 0 ? ' sin-precio' : '' ?><?= $susPromos ? ' con-promo' : '' ?>"
                data-id="<?= (int)$s['id'] ?>"
                data-area="<?= (int)($s['categoria_id'] ?? 0) ?>"
                data-b="<?= P::e(mb_strtolower($s['nombre'] . ' ' . $s['codigo'])) ?>"
                data-nombre="<?= P::e($s['nombre']) ?>"
                data-codigo="<?= P::e(mb_strtolower(trim((string)$s['codigo']))) ?>"
                title="<?= P::e($titulo) ?>"
                <?php if ($hay !== null): ?>data-hay="<?= number_format($hay, 3, '.', '') ?>"<?php endif; ?>
                data-u="<?= P::e(\LibertyFin\Servicio\Inventario::abrev($s['unidad_medida'] ?? '')) ?>"
                <?= $frac ? 'data-frac="1"' : '' ?>
                data-precio="<?= (float)$s['precio'] ?>">
          <?php /* Cuántas llevas de esta. Lo pinta el JS al cambiar el
                   ticket; nace vacío para que no parpadee un 0. */ ?>
          <span class="lf-cuantas" aria-hidden="true"></span>
          <?php if ($hay !== null): ?>
            <span class="lf-serv-hay<?= $hay <= 0 ? ' agotado' : ($hay <= (float)$ex['minimo'] ? ' bajo' : '') ?>">
              <?= $hay > 0 ? P::e(\LibertyFin\Servicio\Inventario::conUnidad($hay, $s['unidad_medida'] ?? '')) : 'Agotado' ?></span>
          <?php endif; ?>
          <?php if ($susPromos): ?>
            <span class="lf-serv-promo"><?= P::e($susPromos[0]['etiqueta']) ?><?= count($susPromos) > 1
                ? ' +' . (count($susPromos) - 1) : '' ?></span>
          <?php endif; ?>
          <span class="e"<?= !empty($s['imagen'])
              ? ' style="background-image:url(\''.P::e($s['imagen']).'\');background-size:cover"' : '' ?>>
            <?= !empty($s['imagen']) ? '' : W::icono('serv','17px') ?></span>
          <b><?= P::e($s['nombre']) ?></b>
          <small><?= P::e($s['codigo']) ?></small>
          <span class="p"><?= (float)$s['precio'] > 0 ? D::pesos($s['precio']) : 'Precio libre' ?><?php if ($frac && (float)$s['precio'] > 0): ?><small class="por">/<?= P::e(\LibertyFin\Servicio\Inventario::abrev($s['unidad_medida'])) ?></small><?php endif; ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <?php /* ═══════════ EL TICKET ═══════════
       El orden sigue al cajero: a quién, qué se lleva, cuánto y cómo paga.
       Lo que se llena de vez en cuando (descripción, gastos) va plegado en
       «Más datos». El total y el botón de cobrar quedan fijos al pie: antes
       en una laptop quedaban 650 px abajo y cada venta era bajar a buscarlos. */ ?>
  <form class="card lf-ticket" method="post" action="/caja/cobrar" id="ticket" style="align-self:start">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="lineas" id="lineas" value="[]">
    <input type="hidden" name="cliente_id" id="cliente_id" value="">
    <?php /* Con valor, las lineas entran a ESA venta en vez de abrir
             una nueva. Ver Servicio\AmpliarVenta. */ ?>
    <input type="hidden" name="ampliar_venta" id="ampliarVenta" value="">
    <?php /* Promociones: las que el cajero quitó y el descuento que vio. Al
             cobrar, el servidor las vuelve a calcular y, si no da lo mismo,
             no cobra (ver CajaControlador::cobrar). */ ?>
    <input type="hidden" name="promo_quitadas" id="promoQuitadas" value="[]">
    <?php /* Lo que la venta cierra en otros módulos (ver CajaControlador::despuesDeCobrar)
             y las sesiones de paquete que usa: {renglón: paquete}. */ ?>
    <input type="hidden" name="cotizacion_id" id="cotizacionId" value="">
    <input type="hidden" name="cita_id" id="citaId" value="">
    <input type="hidden" name="paquetes" id="paquetesIn" value="{}">
    <input type="hidden" name="promo_esperado" id="promoEsperado" value="0">
    <?php /* El porcentaje que se manda: la tasa de Ajustes con el interruptor
             encendido, 0 apagado. El cajero ya no teclea un número. */ ?>
    <input type="hidden" name="iva_pct" id="ivaPct" value="<?= $ivaOn ? P::e($tasaIva) : '0' ?>">

    <?php /* La cabecera entera va del color elegido en Ajustes: el
             ticket es lo que se revisa antes de cobrar y conviene que
             se distinga de un vistazo del catálogo de al lado. */ ?>
    <header class="card-header lf-tk-cab">
      <span id="tituloTicket">Ticket</span>
      <span class="badge bg-secondary" id="conteo">0 conceptos</span>
    </header>

    <?php /* ─────────────────────────────────────────────────────
         AGREGAR A UNA VENTA QUE YA EXISTE

         El cliente paga tres servicios, se le hace el folio, y a los
         dos minutos se acuerda de otros tres. Sin esto la unica salida
         era otra venta: dos folios y un historial que cuenta dos
         visitas donde hubo una.

         Va cerrado por omision. Abrirlo es la excepcion, no el camino
         de todos los dias, y un selector siempre abierto invita a
         agregarle cosas a la venta de otro por equivocacion.
         ───────────────────────────────────────────────────── */ ?>
    <details class="lf-ampliar" id="cajaAmpliar">
      <summary>
        <?= W::icono('venta','15px') ?>
        <span>Agregar a una venta que ya existe</span>
      </summary>
      <div class="cuerpo">
        <div class="lf-search">
          <?= W::icono('buscar','15px') ?>
          <input type="text" id="buscaVenta" autocomplete="off"
                 placeholder="Folio o nombre del cliente">
        </div>
        <p class="pista">Salen las de hoy y las que siguen debiendo.</p>
        <div id="listaVentas" class="lista"></div>
      </div>
    </details>

    <div class="lf-ampliando" id="avisoAmpliar" hidden>
      <div>
        <b>Se agrega a la venta <span id="folioAmpliar" class="lf-mono"></span></b>
        <small id="detalleAmpliar"></small>
      </div>
      <button type="button" class="lf-btn-ghost" id="quitarAmpliar"
              title="Hacer una venta nueva" aria-label="Hacer una venta nueva"><span class="lf-signo">&times;</span></button>
    </div>

    <?php /* A quién y quién lo atiende. Cliente y especialista van arriba y a
             la vista: el especialista decide a quién va la comisión (sin él
             la venta queda "POR ASIGNAR"), así que no se esconde. */ ?>
    <div class="lf-ticket-quien">
      <div class="lf-search">
        <?= W::icono('cliente','15px') ?>
        <?php /* `name="cliente_nombre"`: si el nombre NO se eligió de la lista,
                 el servidor lo busca por nombre exacto o crea el cliente.
                 Antes ese texto se perdía y la venta salía sin cliente. */ ?>
        <input type="text" id="buscaCliente" name="cliente_nombre" maxlength="150"
               placeholder="Cliente (opcional)" autocomplete="off"
               title="Elígelo de la lista o escribe su nombre: si no existe, se crea al cobrar.">
      </div>
      <div id="sugerencias" class="lf-sugerencias" hidden></div>
      <label class="lf-ticket-esp">
        <span>Especialista</span>
        <select class="form-select form-select-sm" name="especialista" id="selEspecialista">
          <option value="">Sin asignar</option>
          <?php foreach (($equipo ?? []) as $nombreArea => $gente): ?>
            <optgroup label="<?= P::e($nombreArea) ?>">
              <?php foreach ($gente as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div id="lista"></div>
    <div id="promoPistas" class="lf-promo-pistas" aria-live="polite" hidden></div>
    <p id="vacio" class="lf-ticket-vacio">
      Toca un producto para agregarlo.<small>Atajo: <kbd>/</kbd> para buscar y <kbd>Enter</kbd> para agregar.</small></p>

    <?php /* Lo de vez en cuando. Los gastos de operación NO cambian lo que
             paga el cliente: antes estaban entre los totales con un "−" y
             parecía que se le restaban. */ ?>
    <details class="lf-mas-datos" id="masDatos">
      <summary><span>Más datos</span><small id="masDatosRes">descripción y gastos de operación</small></summary>
      <div class="cuerpo">
        <label class="form-label" for="descVenta">Descripción <small>(opcional)</small></label>
        <textarea class="form-control lf-desc" name="descripcion" id="descVenta" rows="2"
                  placeholder="Qué se vendió, condiciones, referencias…"></textarea>
        <label class="form-label" for="gastos" style="margin-top:12px">Gastos de operación <small>(opcional)</small></label>
        <input class="form-control lf-mono" type="number" name="gastos" id="gastos" value="0" min="0" step="0.01">
        <p class="pista">Lo que cuesta hacer el servicio (trámites, envíos, derechos). No cambia lo que
          paga el cliente: se descuenta antes de calcular la comisión.</p>
      </div>
    </details>

    <div class="lf-sumas">
      <?php if (!empty($promos['codigos'])): ?>
      <div class="lf-promo-cupon">
        <input class="form-control form-control-sm lf-mono" type="text" name="promo_codigo" id="promoCodigo"
               maxlength="30" autocomplete="off" placeholder="Código de promoción" aria-label="Código de promoción">
        <small id="promoMsg" aria-live="polite"></small>
      </div>
      <?php endif; ?>
      <div id="filaDesc" class="lf-promo-total" hidden><span>Descuento por promociones</span>
        <span class="lf-mono" id="sDesc">−$0.00</span></div>
      <div><span>Subtotal</span><span class="lf-mono" id="sSub">$0.00</span></div>
      <div class="lf-iva-fila">
        <?php /* Los precios ya traen el IVA: encenderlo no cambia el total,
                 dice qué parte es IVA (Reportes, facturas, comisiones). */ ?>
        <label class="lf-sw" title="Los precios ya incluyen el IVA: no cambia lo que paga el cliente">
          <input type="checkbox" id="conIva" <?= $ivaOn ? 'checked' : '' ?>>
          <i aria-hidden="true"></i><span>IVA <?= P::e($tasaIva) ?>%</span>
        </label>
        <span class="lf-mono" id="sIva">$0.00</span>
      </div>
    </div>

    <?php
    // Solo lo que esta empresa puede cobrar. Ofrecer tarjeta a quien
    // la tiene apagada hace que el cajero la elija y la venta falle al
    // guardar, cuando el cliente ya está esperando.
    /* LOS CUATRO, NI UNO MAS.
       Antes salian seis o siete porque se mezclaban los metodos del
       mostrador con los de linea. Con tantos botones el cajero tiene
       que leerlos cada vez en vez de dar al de siempre. */
    $opciones = [
        ['id'=>'efectivo', 'rotulo'=>'Efectivo', 'icono'=>'caja', 'linea'=>''],
    ];
    // Tarjeta y SPEI (transferencia) se ofrecen si hay proveedor para
    // generar la liga, O si el método está encendido: en ese caso se cobra
    // marcando "Ya me pagaron", que no genera ninguna liga. Efectivo en
    // tienda siempre necesita el proveedor.
    $mets = $metodos ?? [];
    if ($ligas || in_array('tarjeta', $mets, true)) {
        $opciones[] = ['id'=>'_tarjeta', 'rotulo'=>'Tarjeta', 'icono'=>'cobro', 'linea'=>'tarjeta'];
    }
    if ($ligas || in_array('transferencia', $mets, true)) {
        $opciones[] = ['id'=>'_spei', 'rotulo'=>'SPEI', 'icono'=>'venta', 'linea'=>'spei'];
    }
    if ($ligas) {
        $opciones[] = ['id'=>'_tienda', 'rotulo'=>'Efectivo (tienda)', 'icono'=>'bolsa', 'linea'=>'efectivo'];
    }
    ?>
    <div class="lf-metodos">
      <label class="form-label">¿Cómo paga?</label>
      <div class="ops">
        <?php foreach ($opciones as $n => $o): ?>
          <button type="button" class="m<?= $n === 0 ? ' on' : '' ?>"
                  data-metodo="<?= P::e($o['id']) ?>" data-linea="<?= P::e($o['linea']) ?>">
            <?= W::icono($o['icono'],'17px') ?>
            <span><?= P::e($o['rotulo']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="como_paga" id="comoPaga"
             value="<?= P::e($opciones[0]['id'] ?? 'efectivo') ?>">
    </div>

    <?php /* CUÁNTO SE COBRA HOY.
             Lo normal es cobrar completo, y entonces no hay nada que
             escribir: solo se dice el monto. El campo aparece cuando el
             cliente deja una parte («Solo una parte»). Antes había dos campos
             de dinero seguidos —este y «Paga con»— y no se entendía por qué
             escribir dos veces. Con Ajustes → Caja en «Vacío» arranca abierto. */ ?>
    <div class="lf-anticipo" id="cajaAnticipo">
      <div class="lf-cobro-completo" id="cobroCompleto">
        <span>Se cobra completo</span>
        <b class="lf-mono" id="cobroCompletoMonto">$0.00</b>
        <button type="button" class="lf-link" id="soloParte">Solo una parte</button>
      </div>
      <div class="lf-cobro-parte" id="cobroParte" hidden>
        <div class="cab">
          <label for="anticipo">¿Cuánto deja hoy?</label>
          <span class="atajos">
            <button type="button" data-ant="0.5">50%</button>
            <button type="button" data-ant="1" id="antTotal">Completo</button>
          </span>
        </div>
        <input class="lf-mono" type="number" name="anticipo" id="anticipo" value="0" min="0" step="0.01"
               placeholder="0.00">
        <p id="msgSaldo"></p>
      </div>
    </div>

    <?php /* Paga con: solo en efectivo, para el cambio. Es lo único que se
             escribe en una venta en efectivo, y los billetes de abajo lo
             evitan casi siempre. El servidor vuelve a calcular el cambio al
             cobrar: es dinero que el cajero va a entregar. */ ?>
    <div class="lf-pagacon" id="cajaPagaCon">
      <label for="pagaCon">¿Con cuánto paga? <small>(opcional, solo para calcular el cambio)</small></label>
      <div class="billetes" id="billetes"></div>
      <input type="number" name="paga_con" id="pagaCon" min="0" step="0.01" placeholder="0.00">
      <p class="cambio" id="verCambio" aria-live="polite"></p>
    </div>

    <?php /* Solo con Tarjeta, SPEI o Efectivo (tienda), que normalmente
             generan una liga. Marcado: NO se genera y el cobro pasa como
             pagado, para cuando el cliente ya transfirió o pagó por otro
             lado. Sin proveedor configurado es la única opción, así que
             va marcado. */ ?>
    <div class="lf-sinliga" id="cajaSinLiga" hidden>
      <label>
        <input type="checkbox" name="sin_liga" id="sinLiga" value="1" <?= $ligas ? '' : 'checked' ?>>
        <span><b>Ya me pagaron</b> — no generar liga de pago
          <small>El cobro se registra como pagado en este momento.</small></span>
      </label>
      <div class="ref" id="cajaRef" hidden>
        <label for="refTransf">Referencia o clave de rastreo <small>(opcional)</small></label>
        <input class="form-control lf-mono" type="text" name="referencia" id="refTransf"
               maxlength="60" autocomplete="off" placeholder="Para cuadrar el depósito después">
      </div>
      <?php /* El banco, con tarjeta o SPEI: para cuadrar contra el estado
               de cuenta. Texto libre con sugerencias: la lista ayuda a
               escribirlo igual siempre, pero si el banco no está se
               escribe y ya. */ ?>
      <div class="ref" id="cajaBanco" hidden>
        <label for="bancoPago"><span id="lblBanco">Banco</span> <small>(opcional)</small></label>
        <input class="form-control" type="text" name="banco" id="bancoPago" list="lfBancos"
               maxlength="60" autocomplete="off" placeholder="Escribe o elige: BBVA, Banorte, Nu…">
        <?= \LibertyFin\Dominio\Bancos::datalist('lfBancos') ?>
      </div>
    </div>

    <?php /* Fijo al pie del ticket mientras se ve: el total y el botón. */ ?>
    <div class="lf-cobrar-pie" id="cobrarPie">
      <div class="tot"><span>Total</span><b class="lf-mono" id="sTot">$0.00</b></div>
      <button class="btn btn-primary" type="submit" id="btnCobrar" disabled>
        <?= W::icono('cobro','16px') ?><span id="btnTexto">Cobrar</span>
      </button>
    </div>
  </form>
</div>

<?php /* En el celular el ticket queda debajo del catálogo: mientras se
         eligen productos, esta barra dice cuánto va y lleva al ticket. Se
         esconde en cuanto el ticket está a la vista. */ ?>
<div class="lf-caja-barra" id="cajaBarra" hidden>
  <span><b class="lf-mono" id="barraTotal">$0.00</b><small id="barraConteo">0 conceptos</small></span>
  <button type="button" class="btn btn-primary btn-sm" id="barraIr">Ver ticket y cobrar</button>
</div>

<script>
/* ══════════════════════════════════════════════════════
   AGREGAR A UNA VENTA QUE YA EXISTE
   Elegir una venta cambia el destino del ticket: en vez de
   abrir un folio nuevo, las lineas entran a esa.
   ══════════════════════════════════════════════════════ */
(function(){
  var det   = document.getElementById('cajaAmpliar');
  if (!det) return;
  var campo = document.getElementById('buscaVenta'),
      lista = document.getElementById('listaVentas'),
      campoId = document.getElementById('ampliarVenta'),
      aviso = document.getElementById('avisoAmpliar'),
      folio = document.getElementById('folioAmpliar'),
      detalle = document.getElementById('detalleAmpliar'),
      quitar = document.getElementById('quitarAmpliar'),
      titulo = document.getElementById('tituloTicket'),
      btn = document.getElementById('btnCobrar'),
      btnTexto = document.getElementById('btnTexto'),
      pagaCon = document.getElementById('cajaPagaCon'),
      anticipo = document.getElementById('cajaAnticipo'),
      metodos = document.querySelector('.lf-metodos'),
      elegida = null, pidiendo = null, reloj = null;

  function pintar(ventas){
    lista.innerHTML = '';
    if (!ventas.length) {
      lista.innerHTML = '<p class="nada">No hay ventas abiertas que coincidan.</p>';
      return;
    }
    ventas.forEach(function(v){
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'v' + (v.debe ? ' debe' : '');
      b.innerHTML =
        '<span class="f lf-mono">' + v.folio + '</span>' +
        '<span class="c">' + v.cliente + '</span>' +
        '<span class="d">' + v.fecha + (v.de_hoy ? '' : ' · otro día') +
        ' · ' + v.lineas + (v.lineas === 1 ? ' producto' : ' productos') + '</span>' +
        '<span class="s">' + (v.debe ? 'debe ' + v.saldo : 'liquidada') + '</span>';
      b.addEventListener('click', function(){ elegir(v); });
      lista.appendChild(b);
    });
  }

  function buscar(){
    if (pidiendo) pidiendo.abort();
    pidiendo = new AbortController();
    lista.innerHTML = '<p class="nada">Buscando…</p>';
    var cli = document.getElementById('cliente_id');
    var u = '/caja/ventas-abiertas?q=' + encodeURIComponent(campo.value.trim())
          + (cli && cli.value ? '&cliente=' + encodeURIComponent(cli.value) : '');
    fetch(u, { signal: pidiendo.signal, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ pintar(j.ventas || []); })
      .catch(function(e){
        if (e.name === 'AbortError') return;
        lista.innerHTML = '<p class="nada">No se pudo consultar.</p>';
      });
  }

  function elegir(v){
    elegida = v;
    campoId.value = v.id;
    folio.textContent = v.folio;
    detalle.textContent = v.cliente + ' · ' + v.fecha
      + (v.debe ? ' · debe ' + v.saldo : ' · liquidada')
      + (v.de_hoy ? '' : ' · OJO: es de otro día, el corte de ese día cambia');
    aviso.hidden = false;
    det.open = false;
    if (titulo) titulo.textContent = 'Productos que se agregan';
    /* AMPLIAR NO COBRA. Se agrega lo vendido y se abre saldo: el dinero
       entra despues por donde entra siempre, un abono. Dejar a la vista
       "anticipo" y "como paga" haria creer que el cliente esta pagando
       algo aqui, y no es asi. */
    if (pagaCon)  pagaCon.hidden  = true;
    if (anticipo) anticipo.hidden = true;
    if (metodos)  metodos.hidden  = true;
    var sinl = document.getElementById('cajaSinLiga'); if (sinl) sinl.hidden = true;
    refrescarBoton();
  }

  function soltar(){
    elegida = null;
    campoId.value = '';
    aviso.hidden = true;
    campo.value = '';
    lista.innerHTML = '';
    if (titulo) titulo.textContent = 'Ticket';
    if (anticipo) anticipo.hidden = false;
    if (metodos)  metodos.hidden  = false;
    refrescarBoton();
    /* El metodo de pago decide si "con cuanto te paga" vuelve: lo sabe
       el bloque de abajo, asi que se le avisa en vez de adivinarlo. */
    document.dispatchEvent(new CustomEvent('lf:ticket-cambio'));
  }

  function refrescarBoton(){
    if (!btnTexto) return;
    btnTexto.textContent = elegida ? 'Agregar a ' + elegida.folio : 'Cobrar';
    if (btn) btn.classList.toggle('lf-agregando', !!elegida);
  }

  det.addEventListener('toggle', function(){ if (det.open) buscar(); });
  campo.addEventListener('input', function(){
    clearTimeout(reloj); reloj = setTimeout(buscar, 220);
  });
  campo.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(reloj); buscar(); }
  });
  quitar.addEventListener('click', soltar);

  /* Si se elige otro cliente con una venta ya elegida, la venta manda:
     cambiar el cliente de una venta existente no es lo que se pidio. */
  var bc = document.getElementById('buscaCliente');
  if (bc) bc.addEventListener('input', function(){ if (elegida) soltar(); });

  window.lfVentaElegida = function(){ return elegida; };
})();
</script>

<script>
(function(){
  var lineas = [];
  var $ = function(id){ return document.getElementById(id); };
  var pesos = function(n){ return '$' + (Math.round(n*100)/100).toLocaleString('es-MX',
                     {minimumFractionDigits:2, maximumFractionDigits:2}); };

  /* CUÁNTAS LLEVAS DE CADA COSA.
     Tocar un servicio tres veces no daba ninguna señal en la tarjeta:
     había que bajar al ticket a contar. El número va en su esquina,
     que es donde está mirando el dedo. Se repinta junto con el ticket
     para que no puedan decir cosas distintas. */
  function marcarTarjetas(){
    var cuenta = {};
    lineas.forEach(function(l){ cuenta[l.id] = (cuenta[l.id] || 0) + (+l.cantidad || 0); });
    document.querySelectorAll('.lf-serv').forEach(function(b){
      var n = cuenta[b.dataset.id] || 0;
      var sello = b.querySelector('.lf-cuantas');
      b.classList.toggle('lleva', n > 0);
      if (!sello) return;
      /* Las fraccionarias llevan decimal: «1.5» en medio kilo, pero
         «3» y no «3.0» en lo que se cuenta por pieza. */
      sello.textContent = n > 0 ? (n % 1 ? (Math.round(n * 100) / 100) : n) : '';
      b.setAttribute('aria-label',
        (b.dataset.nombre || '') + (n > 0 ? ' · llevas ' + sello.textContent : ''));
    });
  }

  function pintar(){
    marcarTarjetas();
    var lista = $('lista'); lista.innerHTML = '';
    lineas.forEach(function(l, i){
      var d = document.createElement('div');
      /* EL MISMO COLOR QUE EN LA CUADRÍCULA.
         Lo que trae precio de catálogo va en azul y lo que se captura a
         mano en ámbar, igual que su tarjeta. Así la referencia visual
         sobrevive al salto de la cuadrícula al ticket, que es donde se
         revisa antes de cobrar —y donde importa cazar el renglón que
         se quedó en cero—. */
      d.className = 'lf-linea' + (+l.precio > 0 ? ' con-precio' : ' sin-precio');
      // El precio es editable: muchos servicios se cotizan por caso y el
      // catálogo los tiene en cero a propósito.
      // − y + para la cantidad: antes, para pasar de 3 a 2, había que
      // quitar el producto y volver a agregarlo.
      // Por kilo, litro o metro la cantidad se ESCRIBE (0.5 kg): con − y +
      // solo se llegaría a kilos enteros.
      var c = CARTA[l.id], frac = !!(c && c.frac);
      var cant = frac
        ? '<span class="lf-cant frac"><input class="lf-cant-in lf-mono" type="number" data-cant="' + i + '"'
          + ' step="0.001" min="0.001" inputmode="decimal" value="' + l.cantidad + '"'
          + ' aria-label="Cantidad en ' + escP(c.u) + '"><span class="u">' + escP(c.u) + '</span></span>'
        : '<span class="lf-cant">'
          + '<button type="button" data-menos="' + i + '" aria-label="Uno menos"'
          +   (l.cantidad <= 1 ? ' disabled' : '') + '>−</button>'
          + '<span class="n">' + l.cantidad + '</span>'
          + '<button type="button" data-mas="' + i + '" aria-label="Uno más">+</button></span>';
      d.innerHTML = '<div style="flex:1;min-width:0"><b>' + l.nombre + '</b>'
        + cant + pistaExistencia(l) + opcionPaquete(l, i) + '</div>'
        + '<input class="lf-precio lf-mono" type="number" step="0.01" min="0" '
        +   'data-i="' + i + '" value="' + l.precio.toFixed(2) + '" aria-label="Precio"' + (l.paquete ? ' readonly' : '') + '>'
        + '<button type="button" class="lf-quitar" data-i="' + i + '" aria-label="Quitar"><span class="lf-signo">&times;</span></button>'
        // La promoción va abajo, a todo lo ancho: junto al precio no cabía.
        + '<div class="lf-promo-l" data-promo-i="' + i + '"></div>';
      lista.appendChild(d);
    });
    $('vacio').hidden = lineas.length > 0;
    $('conteo').textContent = lineas.length + ' concepto' + (lineas.length===1?'':'s');
    pintarPromos();
    calcular();   // calcular() ya serializa las líneas
    pedirPromos();
  }

  /* EL TOTAL, EN UN SOLO LUGAR. La pantalla y el resumen de confirmación
     lo calculaban cada uno por su lado; si uno cambiaba, decían cosas
     distintas. */
  function totales(){
    var bruto = lineas.reduce(function(a,l){ return a + l.precio*l.cantidad; }, 0);
    var desc  = lineas.reduce(function(a,l){ return a + (l.desc || 0); }, 0);
    return { bruto: bruto, desc: desc, total: Math.max(0, bruto - desc) };
  }
  /* ¿El anticipo sigue al total? Con Ajustes en "el total" sí, salvo que
     el cajero escriba otra cantidad; con "vacío" solo si tocó «Total». */
  var ANTICIPO_TOTAL = <?= $cfgCaja['anticipo'] === 'total' ? 'true' : 'false' ?>;
  var TASA_IVA = <?= json_encode((float)$tasaIva) ?>;

  function calcular(){
    /* SE VUELVE A SERIALIZAR AQUÍ, NO SOLO EN pintar().
       Editar un precio no repinta la lista —perdería el foco a media
       escritura— y por eso el campo oculto conservaba los precios
       originales. Con los servicios de precio libre, que van en cero,
       el servidor recibía un ticket de cero aunque en pantalla se viera
       bien: "El ticket suma cero" con el precio puesto. */
    $('lineas').value = JSON.stringify(lineas.map(function(l){
      return {id:l.id, cantidad:l.cantidad, precio:l.precio}; }));
    var paqs = {};
    lineas.forEach(function(l, i){ if (l.paquete) paqs[i] = l.paquete; });
    $('paquetesIn').value = JSON.stringify(paqs);

    /* El descuento de las promociones ya viene por renglón (ver
       pedirPromos): el total es lo capturado menos eso. */
    var tt = totales(), desc = tt.desc, cap = tt.total;
    $('promoEsperado').value = desc.toFixed(2);
    $('filaDesc').hidden = desc <= 0.004;
    $('sDesc').textContent = '−' + pesos(desc);
    var pct = parseFloat($('ivaPct').value) || 0;
    // IVA incluido: el total es lo capturado y el impuesto se extrae.
    var base = pct > 0 ? cap / (1 + pct/100) : cap;
    var iva  = cap - base;
    $('sSub').textContent = pesos(base);
    $('sIva').textContent = pesos(iva);
    $('sTot').textContent = pesos(cap);

    /* "Ya me pagaron": con un método de liga, marcado = NO se genera la
       liga y el cobro pasa como pagado. Entonces el anticipo es lo que
       entró, y por defecto es el total: así cobrar completo no obliga a
       escribir nada. Si el cajero lo cambia a mano, se respeta. */
    var act = document.querySelector('.lf-metodos .m.on');
    var esLinea = !!(act && act.dataset.linea);
    var sl = $('sinLiga');
    var omitir = esLinea && sl && sl.checked;
    /* En el mostrador (o con "Ya me pagaron") el anticipo sigue al total
       mientras el cajero no escriba otra cantidad. Antes en efectivo
       arrancaba en $0: cobrar una venta completa obligaba a teclear el
       total, y si se olvidaba, la venta quedaba "sin cobro". */
    var a = $('anticipo');
    var sigue = !a.dataset.manual && (omitir || (!esLinea && (ANTICIPO_TOTAL || a.dataset.sigue === '1')));
    if (sigue) a.value = cap.toFixed(2);
    if ($('antTotal')) $('antTotal').classList.toggle('on', sigue && cap > 0);
    /* Completo: solo el monto, sin campo. Una parte: el campo para escribir
       cuánto deja. */
    $('cobroCompleto').hidden = !sigue;
    $('cobroParte').hidden = sigue;
    $('cobroCompletoMonto').textContent = pesos(cap);

    var ant = parseFloat($('anticipo').value) || 0;
    if (ant > cap) { $('anticipo').value = cap.toFixed(2); ant = cap; }
    var saldo = cap - ant;
    $('msgSaldo').innerHTML = ant <= 0
      ? 'Escribe cuánto deja hoy. En cero, la venta se registra sin cobro.'
      : (saldo <= 0.009
        ? 'Es el total: se liquida completa.'
        : 'Queda un saldo de <b class="lf-mono" style="color:var(--lf-amb)">' + pesos(saldo)
          + '</b>. La comisión se libera conforme el cliente pague.');
    var enLinea = esLinea && !omitir;
    $('btnTexto').textContent = enLinea
      ? 'Cobrar ' + pesos(cap)
      : (ant > 0 ? 'Cobrar ' + pesos(ant) : 'Registrar sin cobro');
    $('btnTexto').dataset.total = (ant > 0 ? ant : cap).toFixed(2);
    pintarBilletes(ant > 0 ? ant : cap);
    /* Mientras el servidor contesta las promociones, no se cobra: el total
       todavía puede cambiar. */
    var esperando = !!(promo && promo.esperando);
    if (esperando) $('btnTexto').textContent = 'Revisando promociones…';

    /* La barra del celular y el resumen de «Más datos». */
    if ($('barraTotal')) {
      $('barraTotal').textContent = pesos(cap);
      $('barraConteo').textContent = lineas.length + ' concepto' + (lineas.length === 1 ? '' : 's');
      barraCelular();
    }
    var gs = parseFloat($('gastos').value) || 0, ds = ($('descVenta').value || '').trim();
    $('masDatosRes').textContent = (ds || gs > 0)
      ? [ds ? 'con descripción' : '', gs > 0 ? 'gastos ' + pesos(gs) : ''].filter(Boolean).join(' · ')
      : 'descripción y gastos de operación';

    document.dispatchEvent(new Event('lf-recalcular'));
    $('btnCobrar').disabled = lineas.length === 0 || esperando;
  }

  /* ══════════════════════════════════════════════════════
     ANTICIPO, IVA Y CELULAR
     ══════════════════════════════════════════════════════ */
  /* «Solo una parte»: aparece el campo, vacío y con el cursor dentro. */
  $('soloParte').addEventListener('click', function(){
    var a = $('anticipo');
    a.dataset.manual = '1'; a.dataset.sigue = ''; a.value = '';
    calcular();
    a.focus();
    if (window.lfGuardarTicket) window.lfGuardarTicket();
  });

  /* Billetes para «¿Con cuánto paga?»: el monto exacto y los siguientes
     redondos, siempre por encima (al siguiente cien, quinientos y mil:
     con $3,000 sugiere $3,100, $3,500 y $4,000). Tocar uno llena el campo
     y el cambio sale solo. */
  var ultimoBillete = null;
  function pintarBilletes(monto){
    var caja = $('billetes');
    if (!caja) return;
    monto = Math.round(monto * 100) / 100;
    if (monto === ultimoBillete) return;
    ultimoBillete = monto;
    if (monto <= 0) { caja.innerHTML = ''; return; }
    var op = [];
    [100, 500, 1000].forEach(function(r){
      var v = Math.floor(monto / r) * r + r;
      if (op.indexOf(v) === -1) op.push(v);
    });
    op.sort(function(x, y){ return x - y; });
    caja.innerHTML = '<button type="button" data-billete="' + monto.toFixed(2) + '">Exacto</button>'
      + op.slice(0, 3).map(function(v){
          return '<button type="button" data-billete="' + v + '">' + pesos(v).replace('.00', '') + '</button>';
        }).join('');
  }
  if ($('billetes')) $('billetes').addEventListener('click', function(e){
    var b = e.target.closest('[data-billete]');
    if (!b) return;
    var i = $('pagaCon');
    i.value = (+b.dataset.billete).toFixed(2);
    i.dispatchEvent(new Event('input', { bubbles: true }));
  });

  /* Atajos del anticipo. «Completo» vuelve a seguir al total (si se agrega
     otro producto, sube con él); «50%» es una cantidad fija. */
  document.querySelectorAll('[data-ant]').forEach(function(b){
    b.addEventListener('click', function(){
      var f = +b.dataset.ant, a = $('anticipo');
      a.value = (Math.round(totales().total * f * 100) / 100).toFixed(2);
      a.dataset.manual = f === 1 ? '' : '1';
      a.dataset.sigue  = f === 1 ? '1' : '';
      calcular();
      if (window.lfGuardarTicket) window.lfGuardarTicket();
    });
  });

  /* IVA: un interruptor en vez de teclear el porcentaje. Se manda la tasa
     de Ajustes encendido y 0 apagado. */
  function ponerIva(){
    $('ivaPct').value = $('conIva').checked ? TASA_IVA : 0;
    calcular();
  }
  $('conIva').addEventListener('change', ponerIva);

  /* Celular: la barra aparece si hay productos y el ticket no se ve. */
  var ticketVisible = false;
  function barraCelular(){
    var b = $('cajaBarra');
    if (b) b.hidden = !lineas.length || ticketVisible;
  }
  /* ¿Se ve el ticket? Se revisa al desplazar: es medir un solo elemento, y
     el navegador ya manda a lo más un evento de desplazamiento por cuadro.
     El escucha se pone una sola vez —la navegación sin recarga vuelve a
     correr este script— y llama a la revisión de la Caja que esté abierta. */
  window.lfCajaRevisarBarra = function(){
    var t = $('ticket');
    if (!t || !document.body.contains(t)) return;
    var r = t.getBoundingClientRect();
    ticketVisible = r.top < window.innerHeight && r.bottom > 0;
    barraCelular();
  };
  if (!window.lfCajaScroll) {
    window.lfCajaScroll = true;
    var revisar = function(){ if (window.lfCajaRevisarBarra) window.lfCajaRevisarBarra(); };
    document.addEventListener('scroll', revisar, { passive: true, capture: true });
    window.addEventListener('resize', revisar);
  }
  window.lfCajaRevisarBarra();
  if ($('barraIr')) $('barraIr').addEventListener('click', function(){
    var t = $('ticket');
    if (!t) return;
    /* Va al ticket: la barra se quita ya, sin esperar a que se mida, y se
       vuelve a revisar cuando termina de desplazarse. */
    ticketVisible = true; barraCelular();
    t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(window.lfCajaRevisarBarra, 800);
  });

  /* ══════════════════════════════════════════════════════
     PROMOCIONES
     El servidor dice cuánto descuenta cada renglón: es el mismo que lo
     vuelve a calcular al cobrar, así que pantalla y venta no pueden
     decir cosas distintas. Aquí solo se pregunta y se enseña. El cajero
     puede quitar la de un renglón (y volver a ponerla).
     ══════════════════════════════════════════════════════ */
  var HAY_PROMOS = <?= !empty($promos['hay']) ? 'true' : 'false' ?>;
  var promo = { quitadas: [], esperando: false, n: 0, t: null };

  /* Qué productos tienen promoción (sin las de código) y lo necesario
     para decir qué le falta al ticket. Lo arma Promociones::paraCaja. */
  <?php
  // Un nombre con acentos mal guardados hace fallar json_encode y, con él,
  // todo este script: se sustituye el carácter roto en vez de romper Caja.
  $pcJson = json_encode($pc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
          | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
  ?>
  var PC = <?= $pcJson ?: 'null' ?> || { promos: {}, productos: {}, todo: [] };
  var ICO_PROMO = <?= json_encode(W::icono('pct', '14px')) ?>;
  var ICO_ALERTA = <?= json_encode(W::icono('alerta', '14px')) ?>;
  var ICO_INFO = <?= json_encode(W::icono('doc', '14px')) ?>;
  var CARTA = {};   // id → nombre, precio de catálogo y, con inventario, cuántas hay
  document.querySelectorAll('.lf-serv').forEach(function(b){
    CARTA[+b.dataset.id] = { nombre: b.dataset.nombre, precio: +b.dataset.precio,
                             hay: b.dataset.hay !== undefined ? parseFloat(b.dataset.hay) : null,
                             u: b.dataset.u || '', frac: b.dataset.frac === '1' };
  });

  /* ── EXISTENCIAS (solo con Inventario encendido) ──
     Con «No dejar vender», no se agrega más de lo que hay; con «Avisar»,
     se agrega y el renglón dice cuántas quedan. Al cobrar, el servidor
     vuelve a revisar: dos cajas pudieron vender la última. */
  var INV_BLOQUEA = <?= !empty($inventario['bloquea']) ? 'true' : 'false' ?>;
  function numInv(n){ return (Math.round(n * 1000) / 1000).toLocaleString('es-MX', {maximumFractionDigits:3}); }
  function enTicket(id){ return lineas.reduce(function(a, l){ return a + (l.id === id ? l.cantidad : 0); }, 0); }
  /* ¿Se puede agregar una más? Si no, avisa y dice que no. */
  function cabeUnaMas(id){
    var c = CARTA[id];
    if (!c || c.hay === null || !INV_BLOQUEA) return true;
    if (enTicket(id) + 1 <= c.hay + 0.0005) return true;
    mostrarAviso('<b>' + escP(c.nombre) + ': ' + (c.hay > 0 ? 'no hay más' : 'agotado') + '</b>'
      + '<small>' + (c.hay > 0 ? 'Quedan ' + numInv(c.hay) + (c.u ? ' ' + escP(c.u) : '') + ' y ya están en el ticket.' : 'No quedan existencias en esta sucursal.')
      + '</small><small>Registra la entrada en Inventario para poder venderlo.</small>', 'mal');
    return false;
  }
  /* Lo que se dice en el renglón cuando no alcanza (modo «Avisar»). */
  function pistaExistencia(l){
    var c = CARTA[l.id];
    if (!c || c.hay === null || l.cantidad <= c.hay + 0.0005) return '';
    return '<small class="lf-linea-hay">' + (c.hay > 0 ? 'Solo quedan ' + numInv(c.hay) + (c.u ? ' ' + escP(c.u) : '') : 'Agotado en esta sucursal')
      + '</small>';
  }

  function escP(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function mayus(t){ return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; }
  function yLista(a){ return a.length < 2 ? a.join('') : a.slice(0, -1).join(', ') + ' y ' + a[a.length - 1]; }

  /* Las promociones de un producto, sin las que el cajero quitó. */
  function promosDe(id){
    return (PC.productos[id] || []).filter(function(pid){
      return PC.promos[pid] && promo.quitadas.indexOf(+pid) === -1;
    });
  }

  /* Qué le falta al ticket para que la promoción aplique; '' si nada.
     Son las mismas reglas que Promociones::calcular(), dichas al revés. */
  function falta(p, l){
    if (l) {
      var c = CARTA[l.id], lista = c ? c.precio : 0;
      if (lista > 0 && Math.abs(l.precio - lista) > 0.005) return 'con el precio cambiado a mano no aplica';
      if (l.precio <= 0) return 'se aplica al escribir el precio';
      if (p.tipo === 'nxm' && l.cantidad < p.lleva) {
        var n = p.lleva - l.cantidad;
        return 'agrega ' + n + ' más para que aplique';
      }
      if (p.tipo === 'volumen' && l.cantidad < p.lleva) {
        return 'desde ' + p.lleva + ' piezas; agrega ' + (Math.round((p.lleva - l.cantidad) * 1000) / 1000) + ' más';
      }
    }
    if (p.tipo === 'paquete') {
      var faltan = [];
      Object.keys(p.piezas).forEach(function(pid){
        var y = lineas.filter(function(x){ return x.id === +pid; })[0];
        var n = p.piezas[pid] - (y ? y.cantidad : 0);
        if (n > 0) faltan.push((n > 1 ? n + ' × ' : '') + (CARTA[pid] ? CARTA[pid].nombre : 'un producto que no está en Caja'));
      });
      if (faltan.length) return 'falta agregar ' + yLista(faltan);
    }
    var bruto = totales().bruto;
    if (p.minimo > 0 && bruto + 0.005 < p.minimo) return 'faltan ' + pesos(p.minimo - bruto) + ' para la compra mínima';
    return '';
  }

  function pintarPromos(){
    lineas.forEach(function(l, i){
      var c = document.querySelector('[data-promo-i="' + i + '"]');
      if (!c) return;
      if (l.promo) {
        c.innerHTML = '<span class="lf-promo-tag' + (l.nueva ? ' nueva' : '') + '"><b>−' + pesos(l.desc) + '</b>&nbsp;· ' + escP(l.promo.nombre) + '</span>'
          + '<button type="button" class="lf-promo-x" data-promo-quitar="' + l.promo.id + '"'
          + ' title="No aplicar esta promoción a este ticket">quitar</button>';
        if (l.nueva) {
          var fila = c.closest('.lf-linea');
          if (fila) fila.classList.add('lf-promo-flash');
          l.nueva = false;
        }
      } else if (l.quitada) {
        c.innerHTML = '<span class="lf-promo-tag off">' + escP(l.quitada.nombre) + ' · quitada</span>'
          + '<button type="button" class="lf-promo-x" data-promo-volver="' + l.quitada.id + '">aplicar</button>';
      } else if (promosDe(l.id).length) {
        /* Tiene promoción y todavía no aplica: se dice cuál y qué falta,
           para que el cajero se lo ofrezca al cliente. */
        // Dos renglones: cuál es y qué le falta. En uno solo, con el
        // ticket angosto, el nombre y la condición quedaban revueltos.
        var p = PC.promos[promosDe(l.id)[0]];
        var por = falta(p, l);
        if (!por && !promo.esperando) por = 'no aplica en este ticket';
        c.innerHTML = '<div class="lf-promo-disp" title="' + escP(p.texto) + '">'
          + '<span class="i">' + ICO_PROMO + '</span>'
          + '<span class="n">' + escP(p.nombre) + '</span>'
          + '<span class="d"><span class="e">' + escP(p.etiqueta) + '</span>'
          +   escP(por ? ' · ' + mayus(por) : ' · ' + p.texto) + '</span></div>';
      } else {
        c.innerHTML = '';
      }
    });
    pintarPistas();
  }

  /* Las de toda la compra que piden un mínimo: una sola vez, abajo de la
     lista, no en cada renglón. */
  function pintarPistas(){
    var box = $('promoPistas');
    if (!box) return;
    var h = [];
    if (lineas.length) PC.todo.forEach(function(pid){
      var p = PC.promos[pid];
      if (!p || promo.quitadas.indexOf(+pid) !== -1) return;
      if (lineas.some(function(l){ return l.promo && l.promo.id === +pid; })) return;
      var f = falta(p, null);
      if (f) h.push('<p>' + ICO_PROMO + '<span><b>' + escP(p.nombre) + '</b> (' + escP(p.texto) + '): '
                    + escP(f) + '.</span></p>');
    });
    box.innerHTML = h.join('');
    box.hidden = !h.length;
  }

  /* Al agregar un producto con promoción, se avisa aunque el ticket
     quede lejos (en el teléfono queda abajo del catálogo). */
  function avisarPromo(id){
    var ids = promosDe(id);
    if (!ids.length) return;
    var l = lineas.filter(function(x){ return x.id === id; })[0];
    var p = PC.promos[ids[0]], f = falta(p, l);
    mostrarAviso('<b>' + escP(CARTA[id] ? CARTA[id].nombre : 'Este producto') + ' tiene promoción</b>'
      + '<small><strong>' + escP(p.nombre) + ':</strong> ' + escP(p.texto) + '.</small>'
      + '<small class="' + (f ? 'falta' : 'ok') + '">' + escP(f ? mayus(f) + '.' : 'Se aplica sola en el ticket.') + '</small>'
      + (ids.length > 1 ? '<small>Y ' + (ids.length - 1) + ' promoción' + (ids.length > 2 ? 'es' : '') + ' más; se aplica la que más descuenta.</small>' : ''));
  }

  /* ── PAQUETES DE SESIONES (módulo «paquetes») ──
     Si el cliente elegido tiene sesiones de un servicio, el renglón de ese
     servicio ofrece usarlas: va en $0 y el servidor descuenta la sesión al
     cobrar. Cambiar de cliente las quita. */
  var CON_PAQUETES = <?= !empty($conPaquetes) ? 'true' : 'false' ?>;
  var PAQ = {};   // producto_id → {id, quedan, paquete}
  function opcionPaquete(l, i){
    var p = PAQ[l.id];
    if (!CON_PAQUETES || !p) return '';
    var quedan = p.quedan - (l.paquete ? l.cantidad : 0);
    return '<button type="button" class="lf-paq' + (l.paquete ? ' on' : '') + '" data-paq="' + i + '">'
      + (l.paquete ? '✓ Sesión de paquete · le quedarán ' + Math.max(0, quedan)
                   : 'Usar sesión de su paquete (le quedan ' + p.quedan + ')') + '</button>';
  }
  function cargarPaquetes(cliente){
    PAQ = {};
    if (!CON_PAQUETES || !cliente) { soltarPaquetes(); return; }
    fetch('/caja/paquetes?cliente=' + encodeURIComponent(cliente), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){ PAQ = (d && d.paquetes) || {}; soltarPaquetes(true); pintar(); })
      .catch(function(){ PAQ = {}; });
  }
  /* Quita las sesiones marcadas que ya no corresponden (otro cliente). */
  function soltarPaquetes(conservarValidas){
    var cambio = false;
    lineas.forEach(function(l){
      if (l.paquete && !(conservarValidas && PAQ[l.id] && PAQ[l.id].id === l.paquete)) {
        l.precio = l.precioAntes != null ? l.precioAntes : (CARTA[l.id] ? CARTA[l.id].precio : 0);
        l.paquete = null; cambio = true;
      }
    });
    if (cambio) pintar();
  }
  window.lfCajaCliente = function(id){ cargarPaquetes(id); };

  /* El aviso flotante de Caja (promoción, existencias). Uno a la vez. */
  function mostrarAviso(html, clase){
    var viejo = document.querySelector('.lf-promo-aviso');
    if (viejo) viejo.remove();
    var t = document.createElement('div');
    t.className = 'lf-promo-aviso' + (clase ? ' ' + clase : '');
    t.setAttribute('role', clase === 'mal' ? 'alert' : 'status');
    var ico = clase === 'mal' ? ICO_ALERTA : (clase === 'info' ? ICO_INFO : ICO_PROMO);
    t.innerHTML = '<span class="ico">' + ico + '</span><div class="txt">' + html
      + '</div><button type="button" class="x" aria-label="Cerrar"><span class="lf-signo">&times;</span></button>';
    document.body.appendChild(t);
    var fuera = function(){ t.classList.add('va'); setTimeout(function(){ t.remove(); }, 350); };
    t.querySelector('.x').addEventListener('click', fuera);
    setTimeout(fuera, 6500);
  }

  function mensajeCodigo(c){
    var m = $('promoMsg');
    if (!m) return;
    m.textContent = c && c.texto ? c.mensaje : '';
    m.className = !c || !c.texto ? '' : (c.estado === 'ok' ? 'ok' : 'mal');
  }

  function sinPromos(){
    lineas.forEach(function(l){ l.desc = 0; l.promo = null; l.quitada = null; });
  }

  function pedirPromos(){
    if (!HAY_PROMOS) return;
    clearTimeout(promo.t);
    promo.esperando = lineas.length > 0;
    if (!lineas.length) { sinPromos(); mensajeCodigo(null); calcular(); return; }
    calcular();
    promo.t = setTimeout(function(){
      var n = ++promo.n;
      var fd = new FormData();
      fd.append('token', document.querySelector('#ticket input[name="token"]').value);
      fd.append('lineas', JSON.stringify(lineas.map(function(l){
        return {id:l.id, cantidad:l.cantidad, precio:l.precio}; })));
      fd.append('promo_codigo', $('promoCodigo') ? $('promoCodigo').value : '');
      fd.append('promo_quitadas', JSON.stringify(promo.quitadas));
      fetch('/caja/promociones', { method:'POST', body:fd, credentials:'same-origin',
                                   headers:{'Accept':'application/json'} })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (n !== promo.n) return;          // ya se pidió otra más nueva
          // Para saber cuáles se acaban de aplicar y hacerlas notar.
          var antes = lineas.map(function(l){ return l.promo ? l.promo.id : 0; });
          sinPromos();
          if (d && d.ok) {
            d.lineas.forEach(function(r){
              var l = lineas[r.i];
              if (!l) return;
              l.desc = +r.descuento || 0;
              l.promo = r.promo_id ? {id:r.promo_id, nombre:r.nombre} : null;
              l.quitada = r.quitada || null;
              l.nueva = !!l.promo && l.promo.id !== antes[r.i];
            });
            mensajeCodigo(d.codigo);
          }
          promo.esperando = false;
          pintarPromos(); calcular();
        })
        .catch(function(){
          /* Sin respuesta se cobra sin promociones. Si el servidor sí las
             tiene, al cobrar lo dice y se vuelve a revisar. */
          if (n !== promo.n) return;
          sinPromos();
          promo.esperando = false;
          pintarPromos(); calcular();
        });
    }, 250);
  }

  $('lista').addEventListener('click', function(e){
    var q = e.target.closest('[data-promo-quitar]'), v = e.target.closest('[data-promo-volver]');
    if (!q && !v) return;
    if (q) {
      var id = +q.dataset.promoQuitar;
      if (promo.quitadas.indexOf(id) === -1) promo.quitadas.push(id);
    } else {
      var id2 = +v.dataset.promoVolver;
      promo.quitadas = promo.quitadas.filter(function(x){ return x !== id2; });
    }
    $('promoQuitadas').value = JSON.stringify(promo.quitadas);
    pedirPromos();
  });
  if ($('promoCodigo')) $('promoCodigo').addEventListener('input', function(){
    this.value = this.value.toUpperCase();
    pedirPromos();
  });

  /* ══════════════════════════════════════════════════════
     CATÁLOGO: CATEGORÍA, BÚSQUEDA Y PÁGINAS, SIN RECARGAR
     Antes cada categoría era una página nueva y el ticket que se iba
     armando se perdía. Ahora todas las tarjetas ya están en la página y
     aquí solo se muestran u ocultan; el ticket ni se entera.
     Las 21 por página se conservan: con el catálogo completo a la vista
     el ticket quedaría perdido al fondo de la pantalla.
     ══════════════════════════════════════════════════════ */
  (function(){
    var POR_PAG = 21;
    var todas = Array.prototype.slice.call(document.querySelectorAll('.lf-serv'));
    var area = <?= json_encode((string)$area) ?>;
    var q = <?= json_encode(mb_strtolower(trim((string)$buscar))) ?>;
    var actual = 1, visibles = [];
    var caja = $('pagServ'), vacio = $('catVacio'), campoQ = $('buscaCat');

    function filtrar(){
      visibles = todas.filter(function(t){
        return (area === '' || t.dataset.area === area)
            && (q === '' || (t.dataset.b || '').indexOf(q) !== -1);
      });
      actual = 1;
      pinta();
    }
    function pinta(){
      var total = Math.max(1, Math.ceil(visibles.length / POR_PAG));
      if (actual > total) actual = total;
      todas.forEach(function(t){ t.style.display = 'none'; });
      visibles.slice((actual-1)*POR_PAG, actual*POR_PAG)
              .forEach(function(t){ t.style.display = ''; });
      if (vacio) vacio.hidden = visibles.length > 0;
      if (caja) caja.hidden = total <= 1;
      $('servPag').textContent = actual;
      $('servTot').textContent = total;
      $('servAnt').disabled = actual === 1;
      $('servSig').disabled = actual === total;
    }
    /* La dirección se mantiene al día para poder recargar o compartir,
       pero sin recargar ahora. */
    function direccion(){
      var p = [];
      if (area !== '') p.push('area=' + encodeURIComponent(area));
      if (q !== '') p.push('q=' + encodeURIComponent(campoQ ? campoQ.value.trim() : q));
      try { history.replaceState(history.state, '', '/caja' + (p.length ? '?' + p.join('&') : '')); }
      catch (e) {}
    }

    $('servAnt').addEventListener('click', function(){ if (actual>1){ actual--; pinta(); } });
    $('servSig').addEventListener('click', function(){
      if (actual < Math.ceil(visibles.length / POR_PAG)){ actual++; pinta(); } });

    /* Categorías. preventDefault ANTES de que el navegador de secciones
       del armazón vea el clic: si no, pediría la página entera. */
    var fila = document.querySelector('.lf-cat-fila');
    if (fila) fila.addEventListener('click', function(ev){
      var p = ev.target.closest('.lf-pill');
      if (!p || !fila.contains(p)) return;
      ev.preventDefault();
      area = p.dataset.area || '';
      fila.querySelectorAll('.lf-pill').forEach(function(x){ x.classList.toggle('active', x === p); });
      filtrar(); direccion();
    });

    /* Búsqueda: filtra al escribir; Enter ya no recarga. */
    if (campoQ) {
      campoQ.addEventListener('input', function(){
        q = campoQ.value.trim().toLowerCase();
        filtrar(); direccion();
      });
      var fq = $('formBuscaCat');
      if (fq) fq.addEventListener('submit', function(ev){ ev.preventDefault(); });

      /* Atajos: Enter agrega el primer producto que se ve, Esc limpia. Así
         un servicio de siempre se cobra escribiendo tres letras. */
      campoQ.addEventListener('keydown', function(ev){
        if (ev.key === 'Enter') {
          ev.preventDefault();
          // Un código exacto (lo que manda un lector de barras) gana al primero de la lista.
          var exacto = campoQ.value.trim().toLowerCase();
          var porCodigo = exacto && document.querySelector('.lf-serv[data-codigo="' + exacto.replace(/"/g, '') + '"]');
          if (porCodigo) { porCodigo.click(); campoQ.value = ''; q = ''; filtrar(); direccion(); return; }
          var primero = visibles[(actual - 1) * POR_PAG];
          if (primero) { primero.click(); campoQ.select(); }
        } else if (ev.key === 'Escape' && campoQ.value) {
          ev.preventDefault();
          campoQ.value = ''; q = ''; filtrar(); direccion();
        }
      });
    }

    /* «/» lleva al buscador desde cualquier lado de Caja (si no se está
       escribiendo en otro campo). Una sola vez: la navegación sin recarga
       vuelve a correr este script. */
    if (!window.lfCajaAtajo) {
      window.lfCajaAtajo = true;
      document.addEventListener('keydown', function(ev){
        if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
        var t = document.activeElement;
        if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;
        var c = document.getElementById('buscaCat');
        if (!c || !c.offsetParent) return;
        ev.preventDefault();
        c.focus(); c.select();
      });

      /* LECTOR DE CÓDIGO DE BARRAS sin tener el foco en el buscador: el
         lector "teclea" el código muy rápido y cierra con Enter. Si lo
         tecleado así coincide con el código de un producto, se agrega. */
      var lector = '', ultima = 0;
      document.addEventListener('keydown', function(ev){
        var t = document.activeElement;
        if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) { lector = ''; return; }
        if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
        var ahora = Date.now();
        if (ahora - ultima > 60) lector = '';
        ultima = ahora;
        if (ev.key === 'Enter') {
          var cod = lector.trim().toLowerCase();
          lector = '';
          if (cod.length < 3) return;
          var tarjeta = document.querySelector('.lf-serv[data-codigo="' + cod.replace(/"/g, '') + '"]');
          if (tarjeta) { ev.preventDefault(); tarjeta.click(); }
          return;
        }
        if (ev.key.length === 1) lector += ev.key;
      });
    }

    filtrar();
  })();

  /* Los botones de método. El texto del botón de cobrar cambia según
     el elegido: con el mismo texto, el cajero cree que ya cobró cuando
     el cliente se va a pagar a otro lado. */
  (function(){
    /* Qué se ve según el método y "Ya me pagaron":
         · método de liga SIN marcar → no hay anticipo (el cliente todavía no
           pagó; anotarlo dejaría la venta liquidada sin que entre un peso)
         · método de liga CON "Ya me pagaron" → sin liga, anticipo = total,
           y aparece la referencia para cuadrar el depósito
         · efectivo → como siempre */
    function aplicarSinLiga(){
      var act = document.querySelector('.lf-metodos .m.on');
      var linea = !!(act && act.dataset.linea);
      var wrap = $('cajaSinLiga'), sl = $('sinLiga'), rf = $('cajaRef'), caja = $('cajaAnticipo');
      if (wrap) wrap.hidden = !linea;
      var omitir = linea && sl && sl.checked;
      if (caja) caja.hidden = linea && !omitir;
      if (rf) { rf.hidden = !omitir; if (rf.hidden) $('refTransf').value = ''; }
      /* El banco: solo con tarjeta o SPEI ya pagados (en efectivo, en
         tienda o con liga no hay banco que anotar). */
      var bc = $('cajaBanco');
      if (bc) {
        var tipo = act ? act.dataset.linea : '';
        var conBanco = omitir && (tipo === 'tarjeta' || tipo === 'spei');
        bc.hidden = !conBanco;
        if (!conBanco) $('bancoPago').value = '';
        else $('lblBanco').textContent = tipo === 'spei' ? 'Banco desde el que transfirió' : 'Banco de la tarjeta';
      }
      if (linea && !omitir) { $('anticipo').dataset.manual = ''; $('anticipo').value = '0'; }
      calcular();
    }
    /* Al soltar una venta ampliada se vuelve a decidir qué se ve. */
    document.addEventListener('lf:ticket-cambio', aplicarSinLiga);
    if ($('sinLiga')) $('sinLiga').addEventListener('change', function(){
      $('anticipo').dataset.manual = '';
      aplicarSinLiga();
    });
    /* Si el cajero escribe un anticipo a mano, deja de seguir al total. */
    $('anticipo').addEventListener('input', function(){ this.dataset.manual = '1'; this.dataset.sigue = ''; });

    document.querySelectorAll('.lf-metodos .m').forEach(function(b){
      b.addEventListener('click', function(){
        document.querySelectorAll('.lf-metodos .m').forEach(function(x){
          x.classList.remove('on'); });
        b.classList.add('on');
        $('comoPaga').value = b.dataset.metodo;
        /* Con un pago en línea el anticipo no aplica: el cliente todavía
           no ha pagado nada. Dejarlo a la vista invita a escribir ahí el
           total y entonces la venta queda liquidada sin que haya entrado
           un peso. */
        $('anticipo').dataset.manual = '';
        $('anticipo').value = '0';
        aplicarSinLiga();
        /* "Paga con" solo tiene sentido en efectivo: en una
           transferencia nadie entrega cambio. */
        var pc = $('cajaPagaCon');
        if (pc) {
          pc.hidden = (b.dataset.metodo !== 'efectivo');
          if (pc.hidden) { $('pagaCon').value = ''; $('verCambio').textContent = ''; }
        }
        calcular();
      });
    });
  })();

  document.querySelectorAll('.lf-serv').forEach(function(b){
    b.addEventListener('click', function(){
      var id = +b.dataset.id;
      if (!cabeUnaMas(id)) return;
      var y = lineas.filter(function(l){ return l.id === id; })[0];
      if (y) y.cantidad = Math.round((y.cantidad + 1) * 1000) / 1000;
      else lineas.push({id:id, nombre:b.dataset.nombre, precio:+b.dataset.precio, cantidad:1});
      pintar();
      if (!y) avisarPromo(id);
      // Por kilo: el cursor va a la cantidad, lista para escribir «0.5».
      if (!y && CARTA[id] && CARTA[id].frac) {
        var q = document.querySelector('.lf-cant-in[data-cant="' + (lineas.length - 1) + '"]');
        if (q) { q.focus(); q.select(); }
      }
    });
  });

  $('lista').addEventListener('click', function(e){
    var pq = e.target.closest('[data-paq]');
    if (pq) {
      var lp = lineas[+pq.dataset.paq], p = PAQ[lp && lp.id];
      if (!lp || !p) return;
      if (lp.paquete) { lp.precio = lp.precioAntes != null ? lp.precioAntes : lp.precio; lp.paquete = null; }
      else if (lp.cantidad > p.quedan) { mostrarAviso('<b>No alcanzan las sesiones</b><small>Al paquete le quedan ' + p.quedan + '.</small>', 'mal'); return; }
      else { lp.precioAntes = lp.precio; lp.precio = 0; lp.paquete = p.id; }
      pintar();
      return;
    }
    var mas = e.target.closest('[data-mas]'), menos = e.target.closest('[data-menos]');
    if (mas || menos) {
      var l = lineas[+(mas || menos).dataset[mas ? 'mas' : 'menos']];
      if (!l) return;
      if (mas && !cabeUnaMas(l.id)) return;
      l.cantidad = Math.max(1, l.cantidad + (mas ? 1 : -1));
      pintar();
      return;
    }
    var b = e.target.closest('.lf-quitar');
    if (!b) return;
    lineas.splice(+b.dataset.i, 1);
    pintar();
  });

  /* La cantidad escrita (por kilo, litro o metro). Igual que el precio: al
     escribir no se repinta, para no perder el foco; al salir del campo sí,
     para acomodar el número y lo que diga de las existencias. */
  $('lista').addEventListener('input', function(e){
    var q = e.target.closest('.lf-cant-in');
    if (!q) return;
    var l = lineas[+q.dataset.cant], v = parseFloat(q.value);
    if (!l) return;
    l.cantidad = v > 0 ? Math.round(v * 1000) / 1000 : 0.001;
    calcular();
    pedirPromos();
  });
  $('lista').addEventListener('change', function(e){
    var q = e.target.closest('.lf-cant-in');
    if (!q) return;
    var l = lineas[+q.dataset.cant];
    if (l && !(parseFloat(q.value) > 0)) l.cantidad = 1;   // vacío o cero: vuelve a 1
    pintar();
  });
  $('lista').addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target.closest('.lf-cant-in')) e.target.blur();   // Enter confirma
  });

  // Cambiar el precio no repinta la lista: perdería el foco a media escritura.
  $('lista').addEventListener('input', function(e){
    var i = e.target.closest('.lf-precio');
    if (!i) return;
    lineas[+i.dataset.i].precio = Math.max(0, parseFloat(i.value) || 0);
    calcular();
    pedirPromos();   // un precio cambiado a mano deja de llevar promoción
  });

  ['anticipo','gastos','descVenta'].forEach(function(id){
    $(id).addEventListener('input', calcular);
  });

  /* El cambio, mientras escribe. Es la cuenta que el cajero hace de
     cabeza cada venta y donde mas se equivoca con prisa. */
  (function(){
    var i = $('pagaCon'), out = $('verCambio');
    if (!i || !out) return;
    function ver(){
      var da = parseFloat(i.value) || 0;
      var ant = parseFloat($('anticipo').value) || 0;
      var cobra = ant > 0 ? ant : (parseFloat($('btnTexto').dataset.total) || 0);
      if (!da) { out.textContent = ''; out.className = 'cambio'; return; }
      if (da < cobra) {
        out.textContent = 'Faltan ' + pesos(cobra - da);
        out.className = 'cambio falta';
      } else {
        out.textContent = 'Cambio ' + pesos(da - cobra);
        out.className = 'cambio ok';
      }
    }
    i.addEventListener('input', ver);
    document.addEventListener('lf-recalcular', ver);
  })();

  document.querySelectorAll('input[name="metodo"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('input[name="metodo"]').forEach(function(o){
        o.parentNode.classList.toggle('active', o.checked); });
    });
  });

  // Cliente
  var t = null;
  $('buscaCliente').addEventListener('input', function(){
    var q = this.value.trim();
    $('cliente_id').value = '';
    if (window.lfCajaCliente) window.lfCajaCliente('');
    clearTimeout(t);
    if (q.length < 2) { $('sugerencias').hidden = true; return; }
    t = setTimeout(function(){
      fetch('/caja/clientes?q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(cs){
          var s = $('sugerencias');
          s.innerHTML = '';
          cs.forEach(function(c){
            var d = document.createElement('button');
            d.type = 'button';
            d.innerHTML = '<b>' + c.nombre + '</b><small>' + (c.compras>0
              ? c.compras + ' compra' + (c.compras==1?'':'s') : 'Cliente nuevo') + '</small>';
            d.addEventListener('click', function(){
              $('cliente_id').value = c.id;
              $('buscaCliente').value = c.nombre;
              s.hidden = true;
              if (window.lfCajaCliente) window.lfCajaCliente(c.id);
            });
            s.appendChild(d);
          });
          s.hidden = cs.length === 0;
        });
    }, 220);
  });

  /* ══════════════════════════════════════════════════════
     EL TICKET SOBREVIVE AL CAMBIO DE CATEGORÍA
     Las categorías de arriba son enlaces: cambiar de una a otra carga
     la pantalla otra vez y el ticket —productos, precios, IVA, cliente,
     método— se borraba. Se guarda en la pestaña (sessionStorage) cada vez
     que algo cambia y se restaura al cargar. Se borra al cobrar con
     éxito, para que la siguiente venta empiece limpia.
     ══════════════════════════════════════════════════════ */
  /* EL TICKET ES DE ESTA CUENTA.
     La clave era fija, así que al cerrar sesión y entrar con otra
     cuenta en el mismo navegador aparecía el ticket a medias de la
     anterior: conceptos de otra empresa listos para cobrar. Con el
     usuario dentro de la clave, cada quien ve el suyo y nadie hereda
     el de nadie. Al salir se borran todos, de todas formas. */
  var CLAVE_TICKET = 'lf-caja-ticket-' + (document.body.dataset.usuario || '0');
  var ticketListo = false;     // no se guarda nada antes de restaurar
  var ticketCerrado = false;   // ya se cobró: no se vuelve a guardar

  window.lfGuardarTicket = function(){
    if (!ticketListo || ticketCerrado) return;
    try {
      sessionStorage.setItem(CLAVE_TICKET, JSON.stringify({
        lineas: lineas,
        iva: $('ivaPct').value, gastos: $('gastos').value,
        anticipo: $('anticipo').value, manual: $('anticipo').dataset.manual || '',
        sigue: $('anticipo').dataset.sigue || '',
        clienteId: $('cliente_id').value, cliente: $('buscaCliente').value,
        esp: $('selEspecialista') ? $('selEspecialista').value : '',
        desc: $('descVenta') ? $('descVenta').value : '',
        metodo: $('comoPaga').value,
        sinLiga: $('sinLiga') ? $('sinLiga').checked : null,
        ref: $('refTransf') ? $('refTransf').value : '',
        banco: $('bancoPago') ? $('bancoPago').value : '',
        quitadas: promo.quitadas,
        codigo: $('promoCodigo') ? $('promoCodigo').value : '',
        cotizacion: $('cotizacionId').value || 0, cita: $('citaId').value || 0
      }));
    } catch (e) { /* sin sessionStorage se opera igual, solo que sin guardar */ }
  };

  /* Lo que se va a registrar, leído de la pantalla, para el resumen de
     confirmación. */
  window.lfResumenTicket = function(){
    var tt = totales(), desc = tt.desc, cap = tt.total;
    var ant = parseFloat($('anticipo').value) || 0;
    if (ant > cap) ant = cap;
    var act = document.querySelector('.lf-metodos .m.on');
    var esLinea = !!(act && act.dataset.linea);
    var sl = $('sinLiga');
    var se = $('selEspecialista');
    var v = window.lfVentaElegida && window.lfVentaElegida();
    var ivaPct = parseFloat($('ivaPct').value) || 0;
    var cobra = (esLinea && !(sl && sl.checked)) ? 0 : ant;
    var pc = $('cajaPagaCon') && !$('cajaPagaCon').hidden ? (parseFloat($('pagaCon').value) || 0) : 0;
    return {
      lineas: lineas.map(function(l){ return {nombre:l.nombre, cantidad:l.cantidad, precio:l.precio,
                                              u: CARTA[l.id] && CARTA[l.id].frac ? CARTA[l.id].u : '',
                                              desc:l.desc || 0, promo:l.promo ? l.promo.nombre : '',
                                              paquete: !!l.paquete}; }),
      descuento: desc,
      total: cap, anticipo: ant, saldo: cap - ant,
      // Lo que el resumen de confirmación también dice.
      ivaPct: ivaPct, iva: ivaPct > 0 ? cap - cap / (1 + ivaPct / 100) : 0,
      pagaCon: pc, cambio: pc > 0 ? pc - cobra : 0,
      gastos: parseFloat($('gastos').value) || 0,
      descripcion: ($('descVenta').value || '').trim(),
      liga: esLinea && !(sl && sl.checked),
      metodo: (act ? act.textContent.trim() : 'Efectivo')
              + ($('bancoPago') && $('bancoPago').value.trim() && !$('cajaBanco').hidden
                 ? ' · ' + $('bancoPago').value.trim() : ''),
      cliente: ($('buscaCliente').value || '').trim(),
      esp: (se && se.value) ? se.options[se.selectedIndex].text : '',
      ampliar: v ? v.folio : ''
    };
  };

  /* Venta cobrada: se olvida el ticket y no se vuelve a guardar. */
  window.lfTicketCobrado = function(){
    ticketCerrado = true;
    try { sessionStorage.removeItem(CLAVE_TICKET); } catch (e) {}
  };

  function restaurarTicket(){
    var g = null;
    try {
      /* Si se acaba de cobrar (?ok=…), el ticket de antes ya no vale. */
      if (/[?&]ok=/.test(location.search)) { sessionStorage.removeItem(CLAVE_TICKET); }
      else g = JSON.parse(sessionStorage.getItem(CLAVE_TICKET) || 'null');
    } catch (e) { g = null; }

    /* Solo el cliente (llega desde su ficha, sin renglones). */
    if (g && g.clienteId && !(g.lineas && g.lineas.length)) {
      $('cliente_id').value = g.clienteId;
      $('buscaCliente').value = g.cliente || '';
    }
    if (g && g.lineas && g.lineas.length) {
      lineas = g.lineas.map(function(l){
        return {id:l.id, nombre:l.nombre, precio:+l.precio, cantidad:+l.cantidad || 1,
                paquete: l.paquete || null, precioAntes: l.precioAntes != null ? +l.precioAntes : null};
      });
      $('cotizacionId').value = +g.cotizacion || '';
      $('citaId').value = +g.cita || '';
      /* El IVA vuelve como interruptor, con la tasa de hoy. */
      $('conIva').checked = parseFloat(g.iva) > 0;
      $('ivaPct').value = $('conIva').checked ? TASA_IVA : 0;
      $('gastos').value = g.gastos;
      $('cliente_id').value = g.clienteId || '';
      $('buscaCliente').value = g.cliente || '';
      if ($('selEspecialista')) $('selEspecialista').value = g.esp || '';
      if ($('descVenta')) $('descVenta').value = g.desc || '';
      /* Si traía descripción o gastos, «Más datos» se abre para que se vea. */
      if ((g.desc || '').trim() || parseFloat(g.gastos) > 0) $('masDatos').open = true;

      /* El método: se "toca" su botón para que todo lo que depende de él
         (anticipo, casilla de "Ya me pagaron", referencia) se acomode. */
      var bm = document.querySelector('.lf-metodos .m[data-metodo="' + (g.metodo || '') + '"]');
      if (bm) bm.click();
      if ($('sinLiga') && g.sinLiga !== null) $('sinLiga').checked = !!g.sinLiga;
      if ($('refTransf')) $('refTransf').value = g.ref || '';
      if ($('bancoPago')) $('bancoPago').value = g.banco || '';
      /* Las promociones quitadas y el código; el descuento se vuelve a
         pedir al servidor al pintar (pudo cambiar desde que se guardó). */
      promo.quitadas = (g.quitadas || []).map(Number);
      $('promoQuitadas').value = JSON.stringify(promo.quitadas);
      if ($('promoCodigo')) $('promoCodigo').value = g.codigo || '';
      $('anticipo').value = g.anticipo;
      $('anticipo').dataset.manual = g.manual || '';
      if ($('sinLiga')) $('sinLiga').dispatchEvent(new Event('change'));
      /* `change` en la casilla reinicia el anticipo; se vuelve a poner. */
      $('anticipo').value = g.anticipo;
      $('anticipo').dataset.manual = g.manual || '';
      $('anticipo').dataset.sigue = g.sigue || '';
    }
    ticketListo = true;
  }

  /* Cualquier cambio en el formulario guarda el ticket. */
  ['ticket'].forEach(function(id){
    var fm = $(id);
    if (!fm) return;
    fm.addEventListener('input',  function(){ window.lfGuardarTicket(); });
    fm.addEventListener('change', function(){ window.lfGuardarTicket(); });
    fm.addEventListener('click',  function(){ setTimeout(window.lfGuardarTicket, 0); });
  });
  /* Y siempre que se recalcula (agregar, quitar, cambiar un precio). */
  document.addEventListener('lf-recalcular', function(){ window.lfGuardarTicket(); });

  /* LO QUE LLEGA ARMADO (cotización, cita o cliente desde su ficha): se
     guarda como el ticket del navegador y se restaura igual que siempre.
     La dirección se limpia: recargar no debe volver a cargarlo encima. */
  var PRECARGA = <?= json_encode($precarga ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  if (PRECARGA) {
    try { sessionStorage.setItem(CLAVE_TICKET, JSON.stringify(PRECARGA)); } catch (e) {}
    try { history.replaceState(null, '', '/caja'); } catch (e) {}
  }
  restaurarTicket();
  if (PRECARGA && PRECARGA.aviso) mostrarAviso('<b>' + escP(PRECARGA.aviso) + '</b>', 'info');
  if (CON_PAQUETES && $('cliente_id').value) cargarPaquetes($('cliente_id').value);
  pintar();
})();
</script>

<?php /* ═══════════ CONFIRMAR ANTES DE REGISTRAR ═══════════ */ ?>
<div class="lf-modal" id="modalConfirma" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="cTitulo">
    <header>
      <div>
        <h2 id="cTitulo">Revisa antes de registrar</h2>
        <p>Una vez registrada, la venta no puede eliminarse. Cualquier cancelación requiere un proceso adicional.</p>
      </div>
      <button type="button" class="cerrar" id="cCerrar" aria-label="Cerrar"><span class="lf-signo">&times;</span></button>
    </header>
    <div class="cuerpo" id="cCuerpo"></div>
    <footer>
      <button type="button" class="btn btn-secondary" id="cVolver">Revisar</button>
      <button type="button" class="btn btn-primary" id="cOk">Confirmar y registrar</button>
    </footer>
  </div>
</div>

<?php /* ═══════════ EL MODAL DE COBRO ═══════════ */ ?>
<div class="lf-modal" id="modalCobro" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="mTitulo">
    <header>
      <div>
        <h2 id="mTitulo">Cobro</h2>
        <p id="mSub"></p>
      </div>
      <button type="button" class="cerrar" id="mCerrar" aria-label="Cerrar"><span class="lf-signo">&times;</span></button>
    </header>
    <div class="cuerpo" id="mCuerpo"></div>
    <footer id="mPie"></footer>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('modalCobro');
  if (!modal) return;
  var cuerpo = document.getElementById('mCuerpo'),
      titulo = document.getElementById('mTitulo'),
      sub    = document.getElementById('mSub'),
      pie    = document.getElementById('mPie'),
      /* El formulario del TICKET, por su id. Antes se tomaba el primer
         <form> de la página, que en Caja es el buscador del catálogo: el
         cobro nunca se enviaba por detrás, el modal no abría y la liga
         salía como un bloque arriba de la página. */
      form   = document.getElementById('ticket'),
      reloj  = null;

  function abrir(){ modal.hidden = false; document.body.classList.add('lf-modal-abierto'); }
  function cerrar(){
    modal.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
    if (reloj) { clearInterval(reloj); reloj = null; }
  }
  document.getElementById('mCerrar').addEventListener('click', cerrar);
  modal.addEventListener('click', function(e){ if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.hidden) cerrar();
  });

  function esc(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function money(n){ return '$' + (+n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }

  function copiable(valor, id, grande){
    return '<div class="dato' + (grande ? ' grande' : '') + '">' + esc(valor) + '</div>'
      + '<div class="copiar"><input readonly id="' + id + '" value="' + esc(valor) + '">'
      + '<button type="button" class="btn btn-secondary btn-sm" data-copia="' + id + '">Copiar</button></div>';
  }

  /* Pregunta cada 4 segundos si ya pagó. Así el cajero ve el aviso en el
     momento en que entra el dinero, sin recargar ni ir a otra pantalla. */
  function vigilar(id){
    var espera = document.getElementById('mEspera');
    if (reloj) clearInterval(reloj);
    reloj = setInterval(function(){
      fetch('/caja/estado/' + id, { headers: {'Accept':'application/json'}, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d || !d.pagado) return;
          clearInterval(reloj); reloj = null;
          if (espera) {
            espera.className = 'espera ok';
            espera.innerHTML = d.estado === 'por_aprobar'
              ? '<b>El cliente ya pagó.</b> Falta aprobarlo para que entre al corte.'
              : '<b>¡Pagado!</b> El abono ya quedó aplicado.';
          }
        })
        .catch(function(){ /* si falla la red se vuelve a intentar solo */ });
    }, 4000);
  }

  /* El boton de confirmar a mano.
     No siempre llega el aviso del proveedor: el pago en tienda tarda
     horas y a veces el cliente ensena el comprobante en el mostrador.
     Sin esto el cajero se queda esperando con el cliente enfrente. */
  function confirmar(l){
    return '<button type="button" class="btn btn-secondary" data-confirmar="'
         + l.id + '">Ya me pagó</button>';
  }

  function pintar(d){
    var l = d.liga || {}, modo = d.modo;

    if (modo === 'cobrado') {
      var v = d.venta;
      titulo.textContent = 'Cobrado';
      sub.textContent = 'Venta ' + v.codigo;

      /* El CAMBIO va primero y en grande. Es lo unico que el cajero
         necesita en este segundo: tiene la mano en el cajon y al
         cliente esperando. Lo cobrado ya lo sabia. */
      var html = '';
      if (v.cambio > 0) {
        html += '<p class="etiqueta">Cambio</p>'
             +  '<p class="ok-grande">' + money(v.cambio) + '</p>'
             +  '<p class="msg centro">Cobró ' + money(v.cobrado)
             +  ' de ' + money(v.paga_con) + '</p>';
      } else {
        html += '<p class="ok-grande">' + money(v.cobrado) + '</p>'
             +  '<p class="msg centro">Listo, la venta quedó registrada.</p>';
      }
      if (!v.en_corte) {
        html += '<div class="espera"><b>Sin caja abierta.</b> Esta venta no entra al '
             +  'corte del turno.</div>';
      }
      cuerpo.innerHTML = html;
      pie.innerHTML = '<a class="btn btn-secondary" href="/ventas/' + v.id
        + '/ticket?auto=1" target="_blank">Imprimir ticket</a>'
        + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      return;
    }

    sub.textContent = 'Venta ' + (d.venta ? d.venta.codigo : '') + ' · ' + money(l.monto);

    /* Cada servicio devuelve lo suyo, asi que no hace falta adivinar:
       el modo que se pidio es el que llego. */
    var aviso = '';

    if (modo === 'tarjeta') {
      titulo.textContent = 'Pago con tarjeta';
      cuerpo.innerHTML =
        '<p class="msg">Que escanee el código con su teléfono, o ábrele la página '
        + 'para que capture los datos de su tarjeta.</p>'
        + '<div class="qr" id="mQr"></div>'
        + copiable(l.liga, 'mLiga')
        + '<div class="espera" id="mEspera"><span class="giro"></span>'
        + 'Esperando a que pague. Esto se actualiza solo.</div>' + aviso;
      cargarQr(l.liga);
      pie.innerHTML = '<a class="btn btn-secondary" href="' + esc(l.liga)
        + '" target="_blank" rel="noopener">Abrir la página</a>' + confirmar(l)
        + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      vigilar(l.id);

    } else if (modo === 'spei') {
      titulo.textContent = 'Transferencia SPEI';
      cuerpo.innerHTML =
        '<p class="msg">Que transfiera desde su banco a esta CLABE, por '
        + '<b>' + money(l.monto) + '</b> exactos.</p>'
        + copiable(l.clabe, 'mClabe', true)
        + '<p class="aviso">Una cantidad distinta no se asocia sola y hay que buscarla a mano.</p>'
        + '<div class="espera" id="mEspera"><span class="giro"></span>'
        + 'Esperando el depósito. En cuanto llegue, aparece aquí.</div>' + aviso;
      pie.innerHTML = confirmar(l) + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      vigilar(l.id);

    } else if (modo === 'efectivo') {
      titulo.textContent = 'Pago en tienda';
      cuerpo.innerHTML =
        '<p class="msg">Dale el comprobante. Puede pagar en OXXO y tiendas participantes '
        + 'con este código.</p>'
        + copiable(l.ref, 'mRef', true)
        + '<p class="aviso">El comprobante trae el código de barras, los pasos y la lista '
        + 'de tiendas. Imprímelo o mándaselo.</p>'
        + '<div class="espera" id="mEspera"><span class="giro"></span>'
        + 'El pago en tienda puede tardar unas horas en reflejarse.</div>' + aviso;
      pie.innerHTML = '<a class="btn btn-secondary" href="' + esc(l.doc)
        + '" target="_blank">Ver el comprobante</a>' + confirmar(l)
        + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      vigilar(l.id);
    }
  }

  /* El QR se pide al servidor, que ya sabe dibujarlo. Meter un generador
     en el navegador sería repetir trescientas líneas que ya existen. */
  function cargarQr(url){
    var c = document.getElementById('mQr');
    if (!c) return;
    c.innerHTML = '<span class="giro"></span>';
    fetch('/qr?t=' + encodeURIComponent(url), { credentials:'same-origin' })
      .then(function(r){ return r.text(); })
      .then(function(svg){ c.innerHTML = svg; })
      .catch(function(){ c.innerHTML = ''; });
  }

  document.addEventListener('click', function(e){
    var c = e.target.closest('[data-copia]');
    if (c) {
      var i = document.getElementById(c.dataset.copia);
      navigator.clipboard.writeText(i.value).then(function(){
        var t = c.textContent; c.textContent = 'Copiado';
        setTimeout(function(){ c.textContent = t; }, 1600);
      }).catch(function(){ i.select(); });
      return;
    }
    if (e.target.closest('[data-seguir]')) { location.href = '/caja'; return; }

    var cf = e.target.closest('[data-confirmar]');
    if (cf) {
      if (!window.confirm('¿El cliente ya pagó? El abono se aplica de inmediato y '
                        + 'queda anotado como confirmación manual.')) return;
      cf.disabled = true; cf.textContent = 'Aplicando…';
      var fd = new FormData();
      fd.append('token', <?= json_encode($token) ?>);
      fd.append('id', cf.dataset.confirmar);
      fetch('/ligas/confirmar', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d.ok) { cf.disabled = false; cf.textContent = 'Ya me pagó';
                       alert(d.error || 'No se pudo.'); return; }
          var esp = document.getElementById('mEspera');
          if (esp) { esp.className = 'espera ok';
                     esp.innerHTML = '<b>Pago aplicado.</b> Confirmado a mano.'; }
          cf.remove();
          if (reloj) { clearInterval(reloj); reloj = null; }
        })
        .catch(function(){ cf.disabled = false; cf.textContent = 'Ya me pagó';
                           alert('No se pudo conectar.'); });
    }
  });

  /* El envío ya no recarga: se manda, se recibe y se abre el modal.
     Esta función es el envío de verdad; solo se llega aquí DESPUÉS de la
     confirmación (ver más abajo). */
  function enviar(){
    var btn = document.getElementById('btnCobrar');
    if (btn) { btn.disabled = true; btn.classList.add('cargando'); }

    var datos = new FormData(form);
    datos.append('json', '1');
    var recibido = false;   // ¿ya contestó el servidor? Si sí, la venta YA existe.
    fetch(form.action, { method:'POST', body:datos,
                         headers:{'Accept':'application/json'}, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        recibido = true;
        if (btn) { btn.disabled = false; btn.classList.remove('cargando'); }
        if (!d.ok) { alert(d.error || 'No se pudo cobrar.'); return; }

        /* La venta ya existe: el ticket guardado en la pestaña ya no vale,
           o la siguiente venta arrancaría con los mismos productos. */
        if (window.lfTicketCobrado) window.lfTicketCobrado();

        /* SE AGREGO A UNA VENTA QUE YA EXISTIA.
           No hay nada que cobrar aqui —se abrio saldo— asi que no hay
           modal de cambio ni de liga: se va a la venta, que es donde
           esta lo que acaba de pasar y desde donde se cobra. */
        if (d.ampliada) {
          location.href = '/ventas/' + d.venta.id;
          return;
        }

        /* EFECTIVO SIN CAMBIO: NI SIQUIERA ABRE EL MODAL.
           Si pagó justo, no hay nada que decirle al cajero y un modal
           que solo pide cerrarse es un clic de más en la operación que
           más se repite en el día. Se avisa arriba y a la siguiente. */
        if (d.modo === 'cobrado' && !(d.venta.cambio > 0) && d.venta.en_corte) {
          location.href = '/caja?ok=' + encodeURIComponent(d.venta.codigo) + '&v=' + (+d.venta.id || '');
          return;
        }
        pintar(d);
        abrir();
      })
      .catch(function(){
        if (btn) { btn.disabled = false; btn.classList.remove('cargando'); }
        /* Si falló la CONEXIÓN, se manda como siempre para no dejar al
           cajero sin poder cobrar. Pero si el servidor ya contestó, la
           venta YA se registró y el error fue al mostrarla: reenviar el
           formulario crearía una segunda venta. */
        if (recibido) {
          alert('La venta se registró, pero hubo un problema al mostrar el cobro. '
              + 'Revísala en Ventas y no la cobres otra vez.');
          return;
        }
        form.submit();
      });
  }

  /* ══════════════════════════════════════════════════════
     CONFIRMAR ANTES DE REGISTRAR
     Un Enter de más, o un toque sin querer, registraba una venta a medias
     —sin cliente, sin especialista, con saldo— y deshacerla es trabajo
     aparte. Ahora:
       · Enter dentro de un campo ya NO envía el formulario;
       · enviar abre un resumen y avisa de lo que falta;
       · el foco cae en "Revisar", no en "Confirmar": un segundo Enter
         seguido tampoco la registra.
     ══════════════════════════════════════════════════════ */
  var conf = document.getElementById('modalConfirma');
  var confCuerpo = document.getElementById('cCuerpo');
  var pendiente = null;

  function cerrarConf(){
    conf.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
    pendiente = null;
  }

  function abrirConf(r){
    var av = [];
    if (!r.ampliar) {
      if (!r.cliente) av.push('No elegiste cliente: la venta saldrá como <b>Público general</b>.');
      if (!r.esp)     av.push('Sin <b>especialista</b>: la comisión quedará por asignar.');
      if (!r.liga && r.saldo > 0.009)
        av.push('Quedará un <b>saldo de ' + money(r.saldo) + '</b> por cobrar.');
      if (!r.liga && r.anticipo <= 0)
        av.push('<b>No se cobra nada hoy</b>: se registra solo la venta.');
      if (!r.liga && r.pagaCon > 0 && r.cambio < -0.009)
        av.push('Lo que entrega (' + money(r.pagaCon) + ') <b>no alcanza</b>: faltan ' + money(-r.cambio) + '.');
    }
    /* Cada producto con su precio por pieza cuando son varias: "10 ×" solo
       con el total no deja ver si el precio quedó bien. */
    var items = r.lineas.slice(0, 6).map(function(l){
      // Por kilo: «0.5 kg × Queso» y el precio por kilo, aunque sea menos de uno.
      var cuanto = l.u ? (l.cantidad + ' ' + esc(l.u) + ' × ') : (l.cantidad > 1 ? l.cantidad + ' × ' : '');
      return '<li><span>' + cuanto + esc(l.nombre)
           + (l.u ? '<small class="lf-conf-pu">' + money(l.precio) + '/' + esc(l.u) + '</small>'
                  : (l.cantidad > 1 ? '<small class="lf-conf-pu">' + money(l.precio) + ' c/u</small>' : ''))
           + (l.promo ? '<small class="lf-conf-promo">' + esc(l.promo) + ' · −' + money(l.desc) + '</small>' : '')
           + (l.paquete ? '<small class="lf-conf-promo">Sesión de su paquete</small>' : '')
           + '</span><b class="lf-mono">' + money(l.precio * l.cantidad - (l.desc || 0)) + '</b></li>';
    }).join('');
    if (r.lineas.length > 6) items += '<li class="mas">y ' + (r.lineas.length - 6) + ' más…</li>';

    var filas = [];
    if (r.ampliar) {
      filas.push(['Se agrega a la venta', esc(r.ampliar)]);
    } else {
      filas.push(['Cliente', r.cliente ? esc(r.cliente) : '<i>Público general</i>']);
      filas.push(['Especialista', r.esp ? esc(r.esp) : '<i>Sin asignar</i>']);
      filas.push(['Forma de pago', esc(r.metodo)]);
      filas.push(r.liga ? ['Se genera una liga por', '<b class="lf-mono">' + money(r.total) + '</b>']
                        : ['Se cobra hoy', '<b class="lf-mono">' + money(r.anticipo) + '</b>']);
      if (!r.liga && r.saldo > 0.009) filas.push(['Queda debiendo', '<b class="lf-mono">' + money(r.saldo) + '</b>']);
      if (!r.liga && r.pagaCon > 0 && r.cambio >= -0.009) {
        filas.push(['Entrega', '<span class="lf-mono">' + money(r.pagaCon) + '</span>']);
        filas.push(['Cambio', '<b class="lf-mono lf-conf-cambio">' + money(r.cambio) + '</b>']);
      }
    }
    if (r.gastos > 0) filas.push(['Gastos de operación', '<span class="lf-mono">' + money(r.gastos) + '</span>']);
    if (r.descripcion) filas.push(['Descripción', esc(r.descripcion.length > 140
                                                       ? r.descripcion.slice(0, 140) + '…' : r.descripcion)]);

    confCuerpo.innerHTML =
        (av.length ? '<div class="lf-conf-av">' + av.map(function(a){ return '<p>' + a + '</p>'; }).join('') + '</div>' : '')
      + '<ul class="lf-conf-items">' + items + '</ul>'
      + (r.descuento > 0.004 ? '<div class="lf-conf-desc"><span>Descuento por promociones</span>'
           + '<b class="lf-mono">−' + money(r.descuento) + '</b></div>' : '')
      /* Con o sin IVA: no cambia el total, pero sí los reportes, la factura
         y la comisión, y es fácil olvidar encenderlo. */
      + '<div class="lf-conf-iva"><span>' + (r.ivaPct > 0 ? 'IVA incluido (' + r.ivaPct + '%)' : 'Sin IVA') + '</span>'
      +   '<span class="lf-mono">' + (r.ivaPct > 0 ? money(r.iva) : '—') + '</span></div>'
      + '<div class="lf-conf-total"><span>Total</span><b class="lf-mono">' + money(r.total) + '</b></div>'
      + '<dl class="lf-conf-datos">' + filas.map(function(f){
          return '<div><dt>' + f[0] + '</dt><dd>' + f[1] + '</dd></div>'; }).join('') + '</dl>';

    document.getElementById('cOk').textContent = r.liga ? 'Confirmar y generar liga' : 'Confirmar y registrar';
    conf.hidden = false;
    document.body.classList.add('lf-modal-abierto');
    /* El foco va a "Revisar" a propósito: ver nota arriba. */
    document.getElementById('cVolver').focus();
  }

  if (conf) {
    document.getElementById('cVolver').addEventListener('click', cerrarConf);
    document.getElementById('cCerrar').addEventListener('click', cerrarConf);
    conf.addEventListener('click', function(e){ if (e.target === conf) cerrarConf(); });
    document.getElementById('cOk').addEventListener('click', function(){
      cerrarConf();
      enviar();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && !conf.hidden) cerrarConf();
    });
  }

  if (form) {
    /* Enter dentro de un campo (precio, IVA, anticipo, referencia…) ya no
       manda el formulario. En un área de texto y en botones sí hace lo
       suyo: salto de línea, o el clic del botón enfocado. */
    form.addEventListener('keydown', function(e){
      if (e.key !== 'Enter') return;
      var t = e.target;
      if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON') return;
      e.preventDefault();
    });

    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      if (!conf || !window.lfResumenTicket) { enviar(); return; }   // sin resumen, como antes
      var r = window.lfResumenTicket();
      if (!r.lineas.length) return;
      abrirConf(r);
    });
  }
})();
</script>
