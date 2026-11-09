<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CobranzaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class CobranzaControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        // Que los cargos de igualas que ya tocan estén en la lista.
        \LibertyFin\Servicio\Igualas::alDia($db);
        $repo = new CobranzaRepo($db);

        $tramo  = Peticion::opcion('tramo', ['t30','t60','t60mas'], 'todos');
        $orden  = Peticion::opcion('orden', ['dias','saldo','cliente'], 'dias');
        $buscar = Peticion::texto('q');
        $vista  = Peticion::opcion('ver', ['ventas','clientes'], 'ventas');

        $pagina  = max(1, Peticion::entero('p', 1));
        $porPag  = Peticion::POR_PAGINA;
        $desfase = ($pagina - 1) * $porPag;
        $total   = $vista === 'clientes' ? 0 : $repo->cuantas($tramo, $buscar);
        $resumen = $repo->resumen();

        Plantilla::pagina('cobranza/index', [
            'titulo'    => 'Cobranza',
            'icono'     => 'reloj',
            'subtitulo' => (int)($resumen['ventas'] ?? 0) . ' ventas con saldo en '
                         . (int)($resumen['clientes'] ?? 0) . ' clientes',
            'resumen'   => $resumen,
            'filas'     => $vista === 'clientes' ? []
                         : $repo->listado($orden, $tramo, $buscar, $porPag, $desfase),
            'clientes'  => $vista === 'clientes' ? $repo->porCliente($porPag, $desfase) : [],
            'pagina'    => $pagina,
            'paginas'   => $vista === 'clientes'
                         ? max(1, (int)ceil(($resumen['clientes'] ?? 0) / $porPag))
                         : max(1, (int)ceil($total / $porPag)),
            'total'     => $total,
            'tramo'     => $tramo, 'orden' => $orden, 'buscar' => $buscar, 'vista' => $vista,
        ]);
    }
}
