<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ClienteRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ClientesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ClienteRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $buscar = Peticion::texto('q');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;

        // Al buscar, la lista mira todo el historial y no solo el periodo.
        $todo = Fechas::buscandoTodo($buscar);
        list($d1, $h1) = Fechas::rango($buscar, $desde, $hasta);
        $total = $repo->cuantos($d1, $h1, $buscar);
        $editar = Peticion::entero('editar');

        Plantilla::pagina('clientes/index', [
            'titulo'     => 'Clientes',
            'icono'      => 'cliente',
            'subtitulo'  => ($todo ? 'Búsqueda en todo el historial' : Fechas::rotulo($desde, $hasta)) . ' · ' . number_format($total) . ($todo ? ' encontrados' : ' con actividad'),
            'todo'       => $todo,
            'soloPeriodo' => Peticion::texto('periodo') === '1',
            'resumen'    => $repo->resumen($desde, $hasta),
            'clientes'   => $repo->listado($d1, $h1, $buscar, $porPag, ($pagina-1)*$porPag),
            'top'        => $repo->masFacturan($desde, $hasta, 5),
            'antiguedad' => $repo->antiguedad(),
            'areas'      => $repo->areasUsadas(),
            'desde'      => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'editando'   => $editar ? $repo->uno_($editar) : null,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($total / $porPag)),
            'total'      => $total,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Alta y edición. Un solo método: la diferencia es si trae id. */
    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new ClienteRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) { $repo->actualizar($id, $_POST); $msg = 'Cliente actualizado.'; }
            else     { $repo->crear($_POST);           $msg = 'Cliente dado de alta.'; }
            $this->volver($msg, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar cliente: ' . $e->getMessage());
            $this->volver('No se pudo guardar el cliente.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /clientes'); exit;
    }
}
