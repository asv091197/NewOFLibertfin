<?php
namespace LibertyFin\Api\Controlador;

use LibertyFin\Api\Contexto;
use LibertyFin\Api\Formato;
use LibertyFin\Api\Respuesta;
use LibertyFin\Datos\CobranzaRepo;

/**
 * GET /api/v1/cobranza — las ventas con saldo, de la más urgente a la menos.
 *
 * Los días se cuentan desde el último abono, igual que en la pantalla:
 * quien abona seguido no aparece como urgente aunque deba mucho.
 */
final class CobranzaControlador
{
    /** Cómo se llama cada tramo en la API → cómo lo llama CobranzaRepo. */
    const TRAMOS = ['todos' => 'todos', 'hasta_30' => 't30', 'de_31_a_60' => 't60', 'mas_de_60' => 't60mas'];
    const ORDENES = ['dias', 'saldo', 'cliente'];

    public function listar(Contexto $c)
    {
        $e = $c->entrada;
        $tramo = self::TRAMOS[$e->opcion('tramo', array_keys(self::TRAMOS), 'todos')];
        $orden = $e->opcion('orden', self::ORDENES, 'dias');
        $buscar = $e->texto('q');

        $repo = new CobranzaRepo($c->db);
        $filas = $repo->listado($orden, $tramo, $buscar, $e->porPagina(), $c->desfase());
        $r = $repo->resumen();

        $meta = $c->paginacion($repo->cuantas($tramo, $buscar));
        $meta['resumen'] = [
            'por_cobrar' => Formato::dinero($r['total'] ?? 0),
            'ventas'     => (int)($r['ventas'] ?? 0),
            'clientes'   => (int)($r['clientes'] ?? 0),
            'hasta_30'   => Formato::dinero($r['t30'] ?? 0),
            'de_31_a_60' => Formato::dinero($r['t60'] ?? 0),
            'mas_de_60'  => Formato::dinero($r['t60mas'] ?? 0),
        ];
        return Respuesta::lista(array_map([Formato::class, 'saldoAbierto'], $filas), $meta);
    }
}
