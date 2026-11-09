<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Servicio\Trabajos;
use LibertyFin\Vista\Plantilla;

/**
 * Seguimiento de trabajos (módulo «seguimiento»): el tablero de lo que
 * se vendió y falta entregar. Ver Servicio\Trabajos.
 */
final class SeguimientoControlador
{
    public function index()
    {
        if (!Modulos::exigir('seguimiento')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        $desde = Modulos::deSesion()->desde('seguimiento');
        $f = ['desde' => $desde, 'responsable' => Peticion::entero('resp'), 'q' => Peticion::texto('q')];
        Plantilla::pagina('seguimiento/index', [
            'titulo'      => 'Trabajos',
            'icono'       => 'tablero',
            'subtitulo'   => 'En qué va lo que se vendió',
            'tablero'     => (new Trabajos($db))->tablero($f),
            'filtros'     => $f,
            'equipo'      => $this->equipo($db),
            'puedeEditar' => Permisos::puede('editar.seguimiento'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Desde el tablero. Responde JSON si lo pide el tablero (mover sin recargar). */
    public function guardar()
    {
        $this->guardarYVolver((int)($_POST['venta_id'] ?? 0), '/seguimiento');
    }

    /** Desde el detalle de una venta. */
    public function guardarDesdeVenta($id)
    {
        $this->guardarYVolver((int)$id, '/ventas/' . (int)$id);
    }

    private function guardarYVolver($ventaId, $volverA)
    {
        $json = !empty($_SERVER['HTTP_X_LF_JSON']);
        if (!Modulos::deSesion()->activo('seguimiento')) $this->responder($json, $volverA, 'El seguimiento de trabajos está apagado.', 'error');
        if (!$this->tokenValido()) $this->responder($json, $volverA, 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        $d = array_intersect_key($_POST, array_flip(['estado', 'responsable_id', 'entrega', 'nota']));
        try {
            $e = (new Trabajos($db))->guardar($ventaId, $d, $_SESSION['usuario_nombre'] ?? '');
            $this->responder($json, $volverA, 'Trabajo: ' . mb_strtolower(Trabajos::ESTADOS[$e]) . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->responder($json, $volverA, $e->getMessage(), 'error');
        }
    }

    private function equipo($db)
    {
        try {
            return $db->query("SELECT id, nombre FROM comision_colaboradores WHERE COALESCE(activo,1) = 1 ORDER BY nombre")
                      ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function responder($json, $a, $texto, $tipo)
    {
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $tipo === 'ok', 'texto' => $texto], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $a); exit;
    }
}
