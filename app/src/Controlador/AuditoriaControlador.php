<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * La bitácora.
 *
 * Es de solo lectura, y a propósito no tiene forma de borrar ni editar
 * un renglón. Una bitácora que se puede limpiar no sirve para lo único
 * que existe: contestar "¿quién hizo esto?" cuando alguien lo niega.
 */
final class AuditoriaControlador
{
    public function index()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        $desde = Peticion::fecha('desde', date('Y-m-d', strtotime('-30 days')));
        $hasta = Peticion::fecha('hasta', date('Y-m-d'));

        $filtros = [
            'accion'  => Peticion::opcion('accion', array_keys(Auditoria::ACCIONES), ''),
            'usuario' => Peticion::entero('usuario'),
            'q'       => trim(Peticion::texto('q', '')),
            'desde'   => $desde, 'hasta' => $hasta,
        ];

        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 30;
        $total  = Auditoria::cuantos($db, $filtros);

        Plantilla::pagina('auditoria/index', [
            'titulo'     => 'Auditoría',
            'icono'      => 'alerta',
            'subtitulo'  => 'Bitácora · ' . number_format($total) . ' movimientos',
            'filas'      => Auditoria::listado($db, $filtros, $porPag, ($pagina - 1) * $porPag),
            'porUsuario' => Auditoria::porUsuario($db, $desde, $hasta),
            'porAccion'  => Auditoria::porAccion($db, $desde, $hasta),
            'usuarios'   => (new UsuarioRepo($db))->todos_(),
            'filtros'    => $filtros,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($total / $porPag)),
            'desde'      => $desde, 'hasta' => $hasta,
        ]);
    }
}
