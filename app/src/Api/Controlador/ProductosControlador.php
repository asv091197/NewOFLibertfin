<?php
namespace LibertyFin\Api\Controlador;

use LibertyFin\Api\Consultas;
use LibertyFin\Api\Contexto;
use LibertyFin\Api\Formato;
use LibertyFin\Api\Respuesta;

/** GET /api/v1/productos — el catálogo activo, con el precio que cobra Caja. */
final class ProductosControlador
{
    public function listar(Contexto $c)
    {
        $q = new Consultas($c->db);
        $buscar = $c->entrada->texto('q');
        $filas = $q->productos($buscar, $c->entrada->porPagina(), $c->desfase());
        return Respuesta::lista(array_map([Formato::class, 'producto'], $filas),
            $c->paginacion($q->cuantosProductos($buscar)));
    }
}
