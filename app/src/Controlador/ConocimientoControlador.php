<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\BaseConocimientoRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Base de conocimientos.
 *
 * Tres cosas en una tabla: artículos, plantillas de respuesta y errores
 * conocidos. Son lo mismo —texto que alguien escribió una vez para no
 * volver a escribirlo— y separarlos en tres tablas obliga a buscar tres
 * veces.
 */
final class ConocimientoControlador
{
    public function index()
    {
        $repo = new BaseConocimientoRepo($this->principal());

        $q    = trim(Peticion::texto('q', ''));
        $tipo = Peticion::opcion('tipo', array_keys(BaseConocimientoRepo::TIPOS), '');
        $area = Peticion::opcion('area', array_keys(BaseConocimientoRepo::AREAS), '');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 12;
        $todas  = $repo->buscar($q, $tipo, $area, 500);

        Plantilla::pagina('conocimiento/index', [
            'titulo'    => 'Base de conocimientos',
            'icono'     => 'serv',
            'subtitulo' => 'Soporte',
            'filas'     => array_slice($todas, ($pagina-1)*$porPag, $porPag),
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil(count($todas) / $porPag)),
            'totalC'    => count($todas),
            'cifras'    => $repo->cifras(),
            'editando'  => Peticion::entero('editar') ? $repo->uno(Peticion::entero('editar')) : null,
            'abrir'     => Peticion::entero('ver') ? $repo->ver(Peticion::entero('ver')) : null,
            'q' => $q, 'tipo' => $tipo, 'area' => $area,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        try {
            (new BaseConocimientoRepo($this->principal()))->guardar(
                (int)($_POST['id'] ?? 0), $_POST,
                $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
            $this->a('Guardado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] conocimiento: ' . $e->getMessage());
            $this->a('No se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    public function alternar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        try {
            (new BaseConocimientoRepo($this->principal()))->alternar((int)($_POST['id'] ?? 0));
            $this->a('Estado cambiado.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] conocimiento alternar: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /conocimiento'); exit;
    }
}
