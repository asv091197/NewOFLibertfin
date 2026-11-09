<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\GastoRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class GastosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new GastoRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $cat    = Peticion::opcion('cat', GastoRepo::CATEGORIAS, '');
        $buscar = Peticion::texto('q');
        $editar = Peticion::entero('editar');
        $vista  = Peticion::opcion('t', ['generales','operacion','proveedores'], 'generales');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        // Al buscar, el listado mira todo el historial y no solo el periodo.
        $todo = $vista === 'generales' && Fechas::buscandoTodo($buscar);
        list($d1, $h1) = $todo ? Fechas::rango($buscar, $desde, $hasta) : [$desde, $hasta];
        $totalG = $vista === 'generales' ? $repo->cuantos($d1, $h1, $cat, $buscar) : 0;

        Plantilla::pagina('gastos/index', [
            'titulo'    => 'Gastos',
            'icono'     => 'baja',
            'subtitulo' => $todo ? 'Búsqueda en todo el historial' : Fechas::rotulo($desde, $hasta),
            'todo'      => $todo,
            'soloPeriodo' => Peticion::texto('periodo') === '1',
            'resumen'   => $repo->resumen($desde, $hasta),
            'vista'     => $vista,
            'gastos'    => $vista === 'generales'
                         ? $repo->listado($d1, $h1, $cat, $buscar, $porPag, ($pagina-1)*$porPag) : [],
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil($totalG / $porPag)),
            'totalG'    => $totalG,
            'operacion' => $vista === 'operacion' ? $repo->operacion($desde, $hasta) : [],
            'proveedores'   => $vista === 'proveedores' ? $repo->proveedores() : [],
            'prov_editando' => ($vista === 'proveedores' && $editar) ? $repo->proveedor($editar) : null,
            'prov_lista'    => $vista === 'generales' ? $repo->proveedoresActivos() : [],
            'categorias'=> $repo->porCategoria($desde, $hasta),
            'editando'  => $editar ? $repo->uno_($editar) : null,
            'abrir'     => $editar > 0 || Peticion::texto('nuevo') !== '',
            'desde'     => $desde, 'hasta' => $hasta, 'cat' => $cat, 'buscar' => $buscar,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token();
        $repo = new GastoRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) { $repo->actualizar($id, $_POST); $m = 'Gasto actualizado.'; }
            else {
                $repo->crear($_POST, $_SESSION['usuario_id'] ?? null, $_SESSION['sucursal_id'] ?? null);
                $m = 'Gasto registrado.';
            }
            $this->volver($m, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar gasto: ' . $e->getMessage());
            $this->volver('No se pudo guardar el gasto.', 'error');
        }
    }

    public function borrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver('Solo un administrador puede borrar un gasto.', 'error');
        }
        $this->token();
        try {
            $g = (new GastoRepo($db))->porId((int)($_POST['id'] ?? 0));
            (new GastoRepo($db))->borrar((int)($_POST['id'] ?? 0));
            // Se guarda el gasto COMPLETO en 'antes'. Un gasto borrado no
            // se puede volver a mirar: si no queda aquí, no queda en ningún
            // lado, y "¿quién borró los $16,025?" se vuelve incontestable.
            Auditoria::anota('gasto.borrar',
                $g ? ($g['concepto'] . ' · $' . $g['monto']) : ('gasto ' . (int)($_POST['id'] ?? 0)),
                $g, null);
            $this->volver('Gasto eliminado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] borrar gasto: ' . $e->getMessage());
            $this->volver('No se pudo eliminar el gasto.', 'error');
        }
    }

    private function token()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /gastos'); exit;
    }

    public function guardarProveedor()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token();
        try {
            (new GastoRepo($db))->guardarProveedor((int)($_POST['id'] ?? 0), $_POST);
            $this->volverA('proveedores', 'Proveedor guardado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volverA('proveedores', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar proveedor: ' . $e->getMessage());
            $this->volverA('proveedores', 'No se pudo guardar el proveedor.', 'error');
        }
    }

    public function alternarProveedor()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token();
        try {
            (new GastoRepo($db))->alternarProveedor((int)($_POST['id'] ?? 0));
            $this->volverA('proveedores', 'Estado cambiado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volverA('proveedores', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar proveedor: ' . $e->getMessage());
            $this->volverA('proveedores', 'No se pudo cambiar el estado.', 'error');
        }
    }

    private function volverA($pestana, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /gastos?t=' . $pestana); exit;
    }
}
