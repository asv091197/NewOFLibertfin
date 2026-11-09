<?php
/* Las tres pestañas de Auditoría. Son enlaces: la navegación parcial del
   armazón las carga sin recargar la página.
     Incoherencias    lo capturado contra las reglas del propio sistema
     Contra un Excel  lo capturado contra la hoja que se lleva aparte
     Bitácora         quién cambió qué, y cuándo */
$pestanas = [
    'incoherencias' => ['/auditoria',          'Incoherencias'],
    'excel'         => ['/auditoria/excel',    'Contra un Excel'],
    'bitacora'      => ['/auditoria/bitacora', 'Bitácora'],
];
?>
<div class="lf-pills" style="margin-bottom:18px" role="tablist">
  <?php foreach ($pestanas as $k => $p): ?>
    <a class="lf-pill <?= $actual === $k ? 'active' : '' ?>" role="tab"
       aria-selected="<?= $actual === $k ? 'true' : 'false' ?>" href="<?= $p[0] ?>"><?= $p[1] ?></a>
  <?php endforeach; ?>
</div>
