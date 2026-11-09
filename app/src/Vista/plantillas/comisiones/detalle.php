<?php
/**
 * Fragmento (no página): las ventas de un colaborador en el periodo.
 * Lo carga el panel expandible de la tarjeta "Por colaborador" y se
 * vuelve a pedir, con otro ?p=, cada vez que se cambia de página.
 *
 * Espera: $d (resultado de ComisionRepo::detalleColaborador) y $enlace
 * (función que recibe el número de página y devuelve la URL).
 */
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Dinero as D;
?>
<?php if (!$d['filas']): ?>
  <p class="lf-det-vacio"><?= $d['q'] !== ''
      ? 'Sin resultados para «' . P::e($d['q']) . '».'
      : 'No hay ventas con comisión en este periodo.' ?></p>
<?php else: ?>
  <div class="table-responsive lf-cards">
    <table class="table table-hover">
      <thead><tr>
        <th>Venta</th><th>Área</th>
        <th class="text-end">%</th>
        <th class="text-end">Devengado</th>
        <th class="text-end">Por liberar</th>
      </tr></thead>
      <tbody>
      <?php foreach ($d['filas'] as $f): ?>
        <tr>
          <td data-label="Venta">
            <a data-lf-fila href="/ventas/<?= (int)$f['venta_id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($f['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($f['codigo_venta']) ?> · <?= date('d M Y', strtotime($f['fecha'])) ?></span>
          </td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($f['area_servicio']) ?></span></td>
          <td data-label="%" class="text-end lf-mono"><?= preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format((float)$f['porcentaje'], 4, '.', '')) ?>%</td>
          <td data-label="Devengado" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($f['devengado']) ?></td>
          <td data-label="Por liberar" class="text-end lf-mono"
              <?= (float)$f['pendiente'] > 0.01 ? 'style="color:var(--lf-amb)"' : 'style="color:var(--lf-tinta-4)"' ?>>
            <?= (float)$f['pendiente'] > 0.01 ? D::pesos($f['pendiente']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<div class="lf-det-pie">
  <span><?= $d['q'] !== '' ? (int)$d['total'] . ' de ' . (int)$d['total_todos'] : (int)$d['total'] ?>
    venta<?= $d['total_todos'] == 1 && $d['q'] === '' ? '' : 's' ?> ·
    devengado <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($d['devengado']) ?></b></span>
  <?php P::parcial('parciales/paginacion', [
      'pagina' => $d['pagina'], 'paginas' => $d['paginas'], 'enlace' => $enlace]); ?>
</div>
