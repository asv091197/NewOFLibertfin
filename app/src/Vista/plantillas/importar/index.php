<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>


<?php /* ═══════════ DESPUÉS DE GUARDAR ═══════════ */ if ($resultado): $r = $resultado; ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header">Se guardó</header>
  <div class="card-body">
    <div class="lf-stats">
      <div class="stat">
        <div class="stat-value"><?= (int)$r['ventas'] ?></div>
        <div class="stat-meta">ventas · <?= P::e(D::pesos($r['total'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['cobros'] ?></div>
        <div class="stat-meta">cobros · <?= P::e(D::pesos($r['cobrado'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['comisiones'] ?></div>
        <div class="stat-meta">comisiones</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['gastos'] ?></div>
        <div class="stat-meta">gastos de operación</div>
      </div>
    </div>

    <?php $c = $r['creados']; if (array_sum($c)): ?>
      <p style="margin-top:16px;font-size:13px;color:var(--lf-tinta-3)">
        Se dieron de alta
        <?php $partes = [];
          foreach (['clientes'=>'cliente','servicios'=>'servicio',
                    'areas'=>'área','colaboradores'=>'colaborador'] as $k => $sing) {
            if ($c[$k]) $partes[] = '<b>' . (int)$c[$k] . '</b> ' . $sing . ($c[$k]==1 ? '' : 's');
          }
          echo implode(', ', $partes); ?>.
      </p>
    <?php endif; ?>

    <?php if ($r['abonos']): ?>
      <p style="margin-top:8px;font-size:13px;color:var(--lf-tinta-3)">
        <b><?= (int)$r['abonos'] ?></b>
        <?= $r['abonos']==1 ? 'renglón era otro cobro' : 'renglones eran otros cobros' ?>
        de una venta que ya estaba: <?= $r['abonos']==1 ? 'entró' : 'entraron' ?>
        como abono con su subfolio, no como venta nueva.
      </p>
    <?php endif; ?>

    <?php if ($r['saltadas']): ?>
      <p style="margin-top:8px;font-size:13px;color:var(--lf-tinta-4)">
        <?= (int)$r['saltadas'] ?> renglones se saltaron: o ya estaban, o traían algún problema.
      </p>
    <?php endif; ?>

    <?php if ($r['fallidas']): ?>
      <div class="alert alert-danger" style="margin-top:14px;display:block">
        <b><?= (int)$r['fallidas'] ?> no se pudieron guardar.</b>
        <?php /* Cada venta va en su propia transacción, así que lo demás
                 sí entró. Volver a subir el mismo archivo reanuda: lo
                 que ya está se salta solo por folio. */ ?>
        Lo demás sí quedó guardado. Puedes volver a subir el mismo archivo:
        lo que ya entró se salta solo.
        <ul style="margin:10px 0 0;padding-left:18px;font-size:12px">
          <?php foreach (array_slice($r['errores'], 0, 10) as $e): ?>
            <li><?= P::e($e) ?></li>
          <?php endforeach; ?>
          <?php if (count($r['errores']) > 10): ?>
            <li>… y <?= count($r['errores']) - 10 ?> más, en la bitácora.</li>
          <?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm" href="/ventas">Ver las ventas</a>
      <a class="btn btn-secondary btn-sm" href="/comisiones">Ver las comisiones</a>
      <a class="btn btn-secondary btn-sm" href="/importar">Importar otra hoja</a>
    </div>
  </div>
</section>


<?php /* ═══════════ REVISIÓN, ANTES DE GUARDAR ═══════════ */ elseif ($plan): $p = $plan; ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
    <span>Esto es lo que va a entrar</span>
    <span class="badge bg-secondary lf-mono"><?= P::e($p['archivo']) ?></span>
  </header>
  <div class="card-body">
    <div class="lf-stats">
      <div class="stat">
        <div class="stat-value"><?= (int)$p['listas'] ?></div>
        <div class="stat-meta">ventas · <?= P::e(D::pesos($p['total'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$p['abonos'] ?></div>
        <div class="stat-meta">abonos a ventas de la misma hoja</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= P::e(D::corto($p['cobrado'])) ?></div>
        <div class="stat-meta">cobrado</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= P::e(D::corto($p['comisiones'])) ?></div>
        <div class="stat-meta">en comisiones</div>
      </div>
    </div>

    <?php $n = $p['nuevos']; if (array_filter($n)): ?>
      <div style="margin-top:16px;padding:12px 14px;border-radius:var(--lf-r);
                  background:var(--lf-vidrio);font-size:13px">
        <b>Se van a dar de alta</b>
        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:16px">
          <?php foreach (['clientes'=>'Clientes','servicios'=>'Servicios',
                          'areas'=>'Áreas','colaboradores'=>'Colaboradores'] as $k => $rot):
                  if (!$n[$k]) continue; ?>
            <div style="min-width:170px">
              <div style="color:var(--lf-tinta-3);font-size:11.5px;text-transform:uppercase;
                          letter-spacing:.04em"><?= $rot ?> · <?= count($n[$k]) ?></div>
              <div style="font-size:12px;margin-top:3px;color:var(--lf-tinta-2)">
                <?= P::e(implode(', ', array_slice($n[$k], 0, 4)))
                  . (count($n[$k]) > 4 ? ' y ' . (count($n[$k]) - 4) . ' más' : '') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php /* Los problemas van ARRIBA del botón, no en una pestaña aparte:
             confirmar sin haberlos visto no debería ser posible. */ ?>
    <?php if ($p['malas'] || $p['repetidas'] || $p['sinFolio']): ?>
      <div style="margin-top:14px;font-size:13px;color:var(--lf-tinta-3);
                  display:flex;flex-wrap:wrap;gap:18px">
        <?php if ($p['malas']): ?>
          <span><b style="color:var(--lf-rojo)"><?= (int)$p['malas'] ?></b>
            no se pueden guardar</span>
        <?php endif; ?>
        <?php if ($p['repetidas']): ?>
          <span><b><?= (int)$p['repetidas'] ?></b> ya estaban en el sistema</span>
        <?php endif; ?>
        <?php if ($p['sinFolio']): ?>
          <span><b><?= (int)$p['sinFolio'] ?></b> sin folio (totales de la hoja): se ignoran</span>
        <?php endif; ?>
        <?php if ($p['excluidos']): ?>
          <span><b><?= (int)$p['excluidos'] ?></b> los sacaste tú</span>
        <?php endif; ?>
        <?php if ($p['ajustados']): ?>
          <span style="color:var(--lf-brand-2)"><b><?= (int)$p['ajustados'] ?></b> corregidos a mano</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($p['ejemplo'])): ?>
      <div class="alert alert-warning" style="display:block;margin:18px 0 0">
        <b>Es la hoja de ejemplo.</b> Así se ve la revisión de un archivo bien llenado, pero sus datos
        son inventados y no se guarda. Llena tu propia hoja con el mismo formato y súbela.
      </div>
    <?php endif; ?>
    <form method="post" action="/importar/aplicar" style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-primary" type="submit"
              <?= ($p['listas'] + $p['abonos']) && empty($p['ejemplo']) ? '' : 'disabled' ?>>
        Guardar <?= (int)($p['listas'] + $p['abonos']) ?> renglones
      </button>
      <a class="btn btn-secondary" href="/importar/cancelar">Cancelar</a>
    </form>
    <p style="margin-top:10px;font-size:12px;color:var(--lf-tinta-4)">
      Hasta que des Guardar no se ha escrito nada.
    </p>
  </div>
</section>

<?php /* ── Renglón por renglón, y se puede corregir ──

     El archivo casi nunca viene perfecto: un folio con un dedazo, una
     comisión que se olvidaron de llenar. Mandar a la gente de vuelta a
     Excel a corregir, re-exportar y volver a subir por eso es absurdo.

     Lo que se escribe aquí NO reemplaza al archivo: se guarda como una
     corrección encima, y vuelve a pasar por las mismas validaciones.
     Por eso al guardar cambios la pantalla se recarga: lo corregido se
     revisa igual que lo demás.                                      */ ?>
<form method="post" action="/importar/ajustar" id="formAjustes">
<input type="hidden" name="token" value="<?= P::e($token) ?>">
<section class="card">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span>Detalle</span>
    <div class="lf-pills" style="margin:0">
      <button type="button" class="lf-pill active" data-filtro="">Todos</button>
      <?php if ($p['conAviso']): ?>
        <button type="button" class="lf-pill" data-filtro="aviso">Con aviso · <?= (int)$p['conAviso'] ?></button>
      <?php endif; ?>
      <?php if ($p['malas'] + $p['repetidas']): ?>
        <button type="button" class="lf-pill" data-filtro="problema">Problema · <?= (int)($p['malas']+$p['repetidas']) ?></button>
      <?php endif; ?>
      <?php if ($p['ajustados']): ?>
        <button type="button" class="lf-pill" data-filtro="ajustado">Corregidos · <?= (int)$p['ajustados'] ?></button>
      <?php endif; ?>
    </div>
  </header>
  <div class="table-responsive">
    <table class="table" id="tablaImport">
      <thead><tr>
        <th>Fila</th><th>Folio</th><th>Fecha</th><th>Cliente</th><th>Servicio</th>
        <th class="text-end">Total</th><th class="text-end">Cobra</th>
        <th class="text-end">Comisión</th><th>Qué pasa</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($p['renglones'] as $r):
        $problema = in_array($r['estado'], ['mala','repetida'], true);
        $fuera = $r['estado'] === 'excluida';
        $ajust = !empty($r['ajustado']);
        $clase = $ajust ? 'ajustado' : ($problema ? 'problema' : ($r['avisos'] ? 'aviso' : ''));
        $com = array_sum(array_column($r['comisiones'] ?? [], 'monto'));
        $llave = $r['hoja'] . '|' . $r['fila'];
        $cm = array_column($r['comisiones'] ?? [], 'monto', 'concepto'); ?>
        <tr data-clase="<?= $clase ?>" data-llave="<?= P::e($llave) ?>"
            style="<?= $fuera ? 'opacity:.4;text-decoration:line-through' : ($problema ? 'opacity:.62' : '') ?>
                   <?= $ajust ? 'border-left:3px solid var(--lf-brand)' : '' ?>">
          <td data-label="Fila" class="lf-mono" style="font-size:11.5px;white-space:nowrap">
            <?= P::e($r['hoja']) ?> · <?= (int)$r['fila'] ?></td>
          <td data-label="Folio" class="lf-mono" style="font-size:11.5px">
            <?= P::e($r['folio'] ?? '—') ?>
            <?php if (($r['tipo'] ?? '') === 'abono'): ?>
              <span class="badge bg-secondary" style="font-size:10px">abono</span>
            <?php endif; ?>
          </td>
          <td data-label="Fecha" class="lf-mono" style="font-size:11.5px">
            <?= $r['fecha'] ?? '' ? date('d/m/Y', strtotime($r['fecha'])) : '—' ?></td>
          <td data-label="Cliente"><?= P::e($r['cliente'] ?? '') ?></td>
          <td data-label="Servicio" style="font-size:12px;color:var(--lf-tinta-3)">
            <?= P::e(($r['area'] ?? '') . ' · ' . ($r['servicio'] ?? '')) ?></td>
          <td data-label="Total" class="text-end">
            <?= ($r['total'] ?? 0) > 0 ? P::e(D::pesos($r['total'])) : '—' ?></td>
          <td data-label="Cobra" class="text-end"><?= P::e(D::pesos($r['anticipo'] ?? 0)) ?></td>
          <td data-label="Comisión" class="text-end">
            <?= $com > 0 ? P::e(D::pesos($com)) : '—' ?></td>
          <td data-label="Qué pasa" style="font-size:11.5px">
            <?php if ($r['estado'] === 'mala'): ?>
              <b style="color:var(--lf-rojo)">No entra.</b>
            <?php elseif ($r['estado'] === 'repetida'): ?>
              <b style="color:var(--lf-tinta-4)">Ya estaba.</b>
            <?php endif; ?>
            <?= P::e(implode(' ', $r['avisos'] ?? [])) ?>
          </td>
          <td data-label="" style="white-space:nowrap">
            <?php if ($r['folio']): ?>
              <button type="button" class="lf-btn-ghost lf-ajustar" title="Corregir este renglón"
                      aria-label="Corregir">✎</button>
            <?php endif; ?>
          </td>
        </tr>

        <?php /* La fila de corrección: oculta hasta que alguien toca el
                 lápiz. Va dentro del mismo formulario, así que se pueden
                 corregir varias y mandarlas de una vez. */ ?>
        <?php if ($r['folio']): ?>
        <tr class="lf-edicion" hidden>
          <td colspan="10">
            <div class="lf-edit-caja">
              <div class="lf-edit-rejilla">
                <label>Folio
                  <input type="text" name="aj[<?= P::e($llave) ?>][folio]"
                         value="<?= P::e($r['folioOriginal'] ?? $r['folio']) ?>" inputmode="numeric"></label>
                <label>Fecha
                  <input type="date" name="aj[<?= P::e($llave) ?>][fecha]"
                         value="<?= P::e($r['fecha'] ?? '') ?>"></label>
                <label style="grid-column:span 2">Cliente
                  <input type="text" name="aj[<?= P::e($llave) ?>][cliente]"
                         value="<?= P::e($r['cliente'] ?? '') ?>"></label>
                <label>Área
                  <input type="text" name="aj[<?= P::e($llave) ?>][area]"
                         value="<?= P::e($r['area'] ?? '') ?>" list="lfAreas"></label>
                <label>Servicio
                  <input type="text" name="aj[<?= P::e($llave) ?>][servicio]"
                         value="<?= P::e($r['servicio'] ?? '') ?>"></label>
                <label>Forma de pago
                  <input type="text" name="aj[<?= P::e($llave) ?>][forma]"
                         value="<?= P::e($r['forma'] ?? '') ?>"></label>
                <label>Total de la venta
                  <input type="text" name="aj[<?= P::e($llave) ?>][total]" inputmode="decimal"
                         value="<?= $r['total'] > 0 ? number_format($r['total'],2,'.','') : '' ?>"
                         <?= ($r['tipo'] ?? '') === 'abono' ? 'disabled placeholder="(es un abono)"' : '' ?>></label>
                <label>Lo que cobra
                  <input type="text" name="aj[<?= P::e($llave) ?>][anticipo]" inputmode="decimal"
                         value="<?= number_format($r['anticipo'] ?? 0,2,'.','') ?>"></label>
                <label>Vendedor
                  <input type="text" name="aj[<?= P::e($llave) ?>][vendedor]"
                         value="<?= P::e($r['vendedor'] ?? '') ?>" list="lfVendedores"></label>
              </div>

              <div class="lf-edit-com">
                <span class="rot">Comisiones · base <?= P::e(D::pesos($r['base'] ?? 0)) ?></span>
                <?php foreach (['Vendedor','Gerente','Administración','Área'] as $k => $c): ?>
                  <label><?= P::e($c) ?>
                    <input type="text" name="aj[<?= P::e($llave) ?>][com<?= $k ?>]" inputmode="decimal"
                           value="<?= isset($cm[$c]) ? number_format($cm[$c],2,'.','') : '' ?>"
                           placeholder="0.00"></label>
                <?php endforeach; ?>
              </div>

              <div class="lf-edit-pie">
                <label class="lf-check">
                  <input type="checkbox" name="aj[<?= P::e($llave) ?>][excluir]" value="1"
                         <?= $fuera ? 'checked' : '' ?>>
                  <span>No importar este renglón</span>
                </label>
                <?php if ($ajust): ?>
                  <label class="lf-check">
                    <input type="checkbox" name="aj[<?= P::e($llave) ?>][restaurar]" value="1">
                    <span>Volver a lo que dice el archivo</span>
                  </label>
                <?php endif; ?>
                <span class="fuente">De <?= P::e($r['hoja']) ?>, fila <?= (int)$r['fila'] ?></span>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php /* Listas para que no haya que teclear áreas ni vendedores que
           ya existen, y no se creen por un acento de diferencia. */ ?>
  <datalist id="lfAreas">
    <?php foreach (array_unique(array_filter(array_column($p['renglones'],'area'))) as $a): ?>
      <option value="<?= P::e($a) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <datalist id="lfVendedores">
    <?php foreach (array_unique(array_filter(array_column($p['renglones'],'vendedor'))) as $v): ?>
      <option value="<?= P::e($v) ?>"></option>
    <?php endforeach; ?>
  </datalist>
</section>

<?php /* La barra solo aparece cuando hay algo que guardar. Una barra
         fija que no hace nada es ruido permanente. */ ?>
<div class="lf-barra-ajustes" id="barraAjustes" hidden>
  <span id="cuentaAjustes">Tienes cambios sin revisar</span>
  <button class="btn btn-primary btn-sm" type="submit">Revisar con mis cambios</button>
  <button class="btn btn-secondary btn-sm" type="button" id="deshacerAjustes">Deshacer</button>
</div>
</form>

<script>
(function () {
  var tabla = document.getElementById('tablaImport');
  if (!tabla) return;
  var form   = document.getElementById('formAjustes'),
      barra  = document.getElementById('barraAjustes'),
      cuenta = document.getElementById('cuentaAjustes');

  /* ── Filtros ── */
  document.querySelectorAll('[data-filtro]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      var f = b.dataset.filtro;
      tabla.querySelectorAll('tbody tr[data-llave]').forEach(function (tr) {
        var pasa = f === '' || tr.dataset.clase === f;
        tr.hidden = !pasa;
        /* La fila de corrección viaja con la suya: dejarla suelta
           enseñaría un formulario sin saber de qué renglón es. */
        var ed = tr.nextElementSibling;
        if (ed && ed.classList.contains('lf-edicion') && !ed.dataset.abierta) ed.hidden = true;
        else if (ed && ed.classList.contains('lf-edicion')) ed.hidden = !pasa;
      });
    });
  });

  /* ── Abrir y cerrar la corrección ── */
  tabla.addEventListener('click', function (e) {
    var b = e.target.closest('.lf-ajustar');
    if (!b) return;
    var fila = b.closest('tr'), ed = fila.nextElementSibling;
    if (!ed || !ed.classList.contains('lf-edicion')) return;
    var abrir = ed.hidden;
    ed.hidden = !abrir;
    if (abrir) { ed.dataset.abierta = '1'; } else { delete ed.dataset.abierta; }
    b.classList.toggle('on', abrir);
    if (abrir) { var i = ed.querySelector('input'); if (i) i.focus(); }
  });

  /* ── Qué cambió de verdad ──
     Se compara contra el valor con el que nació el campo, no contra
     "lo tocaste": entrar a un campo y salir sin escribir no es un
     cambio, y marcarlo como tal haría que la barra mintiera. */
  form.querySelectorAll('.lf-edicion input').forEach(function (i) {
    i.dataset.inicial = (i.type === 'checkbox') ? (i.checked ? '1' : '') : i.value;
  });

  function cambiados() {
    var filas = {};
    form.querySelectorAll('.lf-edicion input').forEach(function (i) {
      var ahora = (i.type === 'checkbox') ? (i.checked ? '1' : '') : i.value;
      if (ahora === i.dataset.inicial) return;
      var tr = i.closest('tr').previousElementSibling;
      if (tr) filas[tr.dataset.llave] = true;
    });
    return Object.keys(filas).length;
  }

  function refrescar() {
    var n = cambiados();
    barra.hidden = n === 0;
    cuenta.textContent = n === 1 ? 'Corregiste 1 renglón'
                                 : 'Corregiste ' + n + ' renglones';
  }
  form.addEventListener('input', refrescar);
  form.addEventListener('change', refrescar);

  document.getElementById('deshacerAjustes').addEventListener('click', function () {
    form.querySelectorAll('.lf-edicion input').forEach(function (i) {
      if (i.type === 'checkbox') i.checked = i.dataset.inicial === '1';
      else i.value = i.dataset.inicial;
    });
    refrescar();
  });

  /* Irse con correcciones sin revisar las pierde. */
  window.addEventListener('beforeunload', function (e) {
    if (cambiados() > 0 && !form.dataset.enviando) { e.preventDefault(); e.returnValue = ''; }
  });
  form.addEventListener('submit', function () { form.dataset.enviando = '1'; });
})();
</script>


<?php /* ═══════════ SUBIR ═══════════ */ else: ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header">Sube la hoja</header>
  <div class="card-body">
    <?php if (!$puedeXlsx): ?>
      <div class="alert alert-warning" style="display:block;margin-bottom:14px">
        Este servidor no puede abrir archivos <code>.xlsx</code>.
        Abre la hoja en Excel y guárdala como <b>CSV</b>; así sí entra.
      </div>
    <?php endif; ?>

    <?php /* El ejemplo: datos inventados con el formato que se espera. Subido
             tal cual se revisa como uno de verdad, pero no se guarda. */ ?>
    <div class="lf-imp-ejemplo">
      <span class="ic"><?= W::icono('baja','17px') ?></span>
      <span style="flex:1;min-width:220px">
        <b>¿Primera vez? Baja la hoja de ejemplo.</b>
        <small>Datos inventados con el formato que se espera: dos meses, una venta que se cobra en
          dos partes, IVA, comisión del banco, gastos y las cuatro comisiones. Súbela tal cual para ver
          cómo se revisa (no se guarda) y llena la tuya igual.</small>
      </span>
      <a class="btn btn-secondary btn-sm" href="/importar/ejemplo" data-completo download>
        <?= W::icono('baja','14px') ?>Descargar ejemplo</a>
    </div>

    <form method="post" action="/importar/analizar" enctype="multipart/form-data">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <span class="lf-file">
        <input type="file" name="hoja" id="inpHoja" required
               accept=".xlsx,.xlsm,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        <label class="bt" for="inpHoja">Elegir archivo</label>
        <span class="n" data-vacio="Ningún archivo elegido">Ningún archivo elegido</span>
      </span>
      <p style="margin:12px 0 0;font-size:12px;color:var(--lf-tinta-4)">
        Hasta <?= (int)$maxMb ?> MB. Se aceptan <code>.xlsx</code> y <code>.csv</code>.
        Todas las hojas del libro se leen.
      </p>
      <?php if (\LibertyFin\Dominio\Permisos::puede('ver.auditoria')): ?>
      <p style="margin:6px 0 0;font-size:12px;color:var(--lf-tinta-4)">
        ¿Solo quieres ver en qué no coincide la hoja con lo que ya está capturado, sin meter nada?
        Usa <a href="/auditoria/excel">Auditoría · Contra un Excel</a>.
      </p>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" type="submit" style="margin-top:14px">
        Revisar
      </button>
    </form>
  </div>
</section>

<section class="card">
  <header class="card-header">Qué espera encontrar</header>
  <div class="card-body" style="font-size:13px;color:var(--lf-tinta-2);line-height:1.65">
    <p style="margin:0 0 12px">
      Las columnas se buscan <b>por nombre</b>, no por posición, así que no
      importa el orden ni que una hoja tenga columnas que otra no.
      Tampoco importa que el encabezado no esté en la primera fila.
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:22px;margin-bottom:14px">
      <div>
        <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
                    color:var(--lf-tinta-3);margin-bottom:5px">Imprescindibles</div>
        <code style="font-size:12px">FOLIO · CLIENTE · SERVICIO · COSTO TOTAL DE VENTA</code>
      </div>
      <div>
        <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
                    color:var(--lf-tinta-3);margin-bottom:5px">Si están, se usan</div>
        <code style="font-size:12px">FECHA · ÁREA · FORMA · MONTO ANTICIPO · RESTANTES ·
          IVA · COM. BANCA · RECIBO PAGO · SOL. GASTOS · CANTIDAD · A COMISIONAR ·
          VENDEDOR</code>
      </div>
    </div>

    <p style="margin:0 0 10px">
      Después de <b>VENDEDOR</b> van los cuatro importes de comisión, en este
      orden: vendedor, gerente, administración y área. Se guardan
      <b>tal como vienen</b> — el porcentaje se deduce del monto, no al revés.
    </p>

    <ul style="margin:0;padding-left:18px">
      <li style="margin-bottom:6px">
        <b>La fecha</b> que vale es la de la columna <code>FECHA</code>, no la que
        lleva el folio dentro. El folio es cuándo se capturó.</li>
      <li style="margin-bottom:6px">
        <b>Un folio repetido sin total</b> es otro cobro de esa misma venta,
        y entra como abono con su subfolio. Si trae su propio total, son dos
        ventas distintas y a la segunda se le pega una letra.</li>
      <li style="margin-bottom:6px">
        <b>Los renglones sin folio</b> —los totales del pie de la hoja— se ignoran.</li>
      <li>
        <b>Subir dos veces el mismo archivo no duplica nada</b>: lo que ya
        entró se reconoce por el folio y se salta.</li>
    </ul>
  </div>
</section>

<?php endif; ?>
