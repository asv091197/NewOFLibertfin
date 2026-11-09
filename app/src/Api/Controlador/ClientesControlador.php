<?php
namespace LibertyFin\Api\Controlador;

use LibertyFin\Api\Consultas;
use LibertyFin\Api\Contexto;
use LibertyFin\Api\ErrorApi;
use LibertyFin\Api\Formato;
use LibertyFin\Api\Respuesta;

/**
 *   GET /api/v1/clientes        todos los clientes, con su saldo abierto
 *   GET /api/v1/clientes/{id}   uno, con su dirección
 */
final class ClientesControlador
{
    public function listar(Contexto $c)
    {
        $q = new Consultas($c->db);
        $buscar = $c->entrada->texto('q');
        $filas = $q->clientes($buscar, $c->entrada->porPagina(), $c->desfase());
        return Respuesta::lista(array_map([Formato::class, 'cliente'], $filas),
            $c->paginacion($q->cuantosClientes($buscar)));
    }

    public function ver(Contexto $c, $id)
    {
        $fila = (new Consultas($c->db))->cliente($id);
        if (!$fila) throw new ErrorApi(404, 'cliente_no_encontrado', 'No existe un cliente con ese id.');
        $d = Formato::cliente($fila);
        $d['direccion'] = ($fila['direccion'] ?? '') !== '' ? (string)$fila['direccion'] : null;
        return Respuesta::dato($d);
    }
}
