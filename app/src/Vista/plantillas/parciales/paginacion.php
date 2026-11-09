<?php
/**
 * Paginación. Un solo parcial para todas las tablas.
 *
 * Espera: $pagina, $paginas y $enlace (una función que recibe el número
 * de página y devuelve la URL).
 */
use LibertyFin\Vista\Plantilla as P;
if (($paginas ?? 1) <= 1) return;
?>
<nav class="pagination" aria-label="Paginación">
  <span class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
    <a class="page-link" href="<?= P::e($enlace(max(1, $pagina - 1))) ?>" aria-label="Anterior">&lsaquo;</a>
  </span>
  <span class="page-item disabled"><span class="page-link"><?= (int)$pagina ?> de <?= (int)$paginas ?></span></span>
  <span class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>">
    <a class="page-link" href="<?= P::e($enlace(min($paginas, $pagina + 1))) ?>" aria-label="Siguiente">&rsaquo;</a>
  </span>
</nav>
