<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Preventas;
use LibertyFin\Vista\Plantilla;

/**
 * Preventas: lo que se vendió antes de tenerlo y falta por entregar.
 *
 * Aquí se ve quién espera qué, cuánto hay ya en existencia para
 * entregarle, y se marca la entrega (que es cuando las piezas salen del
 * inventario). Ver Servicio\Preventas.
 */
final class PreventasControlador
{
    public function index()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $pv = new Preventas($db);

        // Con el módulo apagado la pantalla sigue abriendo MIENTRAS quede algo
        // por entregar: apagarlo no puede dejar mercancía cobrada sin
        // entregar. Sin pendientes, el aviso de «módulo apagado».
        if (!$pv->activa() && !$pv->hayPendientes()) {
            if (!\LibertyFin\Servicio\Modulos::exigir('preventa')) return;
        }

        $datos = [
            'titulo'      => 'Preventas',
            'icono'       => 'reloj',
            'subtitulo'   => 'Lo vendido que falta por entregar',
            'disponible'  => $pv->disponible(),
            'moduloApagado' => !$pv->activa(),
            'puedeEditar' => Permisos::puede('entregar.preventa'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
            // Sin filtro, lo pendiente; «estado=» (vacío) es «Todas».
            'estado'      => isset($_GET['estado'])
                             ? Peticion::opcion('estado', array_merge([''], array_keys(Preventas::ESTADOS)), 'pendiente')
                             : 'pendiente',
            'buscar'      => Peticion::texto('q'),
            'producto'    => Peticion::entero('producto'),
            'resumen'     => [],
            'lista'       => [],
        ];
        unset($_SESSION['lf_aviso']);

        if ($datos['disponible']) {
            $datos['resumen'] = $pv->resumenPorProducto();
            $datos['lista']   = $pv->listado([
                'estado'      => $datos['estado'],
                'q'           => $datos['buscar'],
                'producto_id' => $datos['producto'],
            ]);
        }
        Plantilla::pagina('preventas/index', $datos);
    }

    /** Llegó la mercancía: se entrega y salen las piezas del inventario. */
    public function entregar($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        try {
            $r = (new Preventas($db))->entregar((int)$id, $_SESSION['usuario_id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] entregar preventa ' . (int)$id . ': ' . $e->getMessage());
            $this->volver('No se pudo registrar la entrega.', 'error');
        }

        // Si la venta todavía debe, se manda a cobrar el saldo: es justo el
        // momento en que el cliente está en el mostrador.
        if ((float)$r['saldo'] > 0.005) {
            $_SESSION['lf_aviso'] = ['tipo' => 'ok', 'texto' => 'Entregado. La venta ' . $r['codigo_venta']
                . ' todavía debe ' . \LibertyFin\Dominio\Dinero::pesos($r['saldo']) . ': cóbralo aquí.'];
            header('Location: /ventas/' . (int)$r['venta_id']); exit;
        }
        $this->volver('Entregado: ' . \LibertyFin\Servicio\Inventario::num($r['cantidad']) . ' ' . $r['producto']
            . ' de la venta ' . $r['codigo_venta'] . '.', 'ok');
    }

    /** Cambia la fecha estimada de llegada de un producto. */
    public function llegada()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        $producto = (int)($_POST['producto_id'] ?? 0);
        if (!$producto) $this->volver('Falta el producto.', 'error');
        try {
            (new Preventas($db))->cambiarLlegada($producto, $_POST['llegada'] ?? '');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] fecha de preventa ' . $producto . ': ' . $e->getMessage());
            $this->volver('No se pudo cambiar la fecha.', 'error');
        }
        $this->volver('Fecha de llegada actualizada.', 'ok');
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $vuelta = $_POST['volver'] ?? '';
        // Solo rutas de esta misma pantalla: nada de redirigir a donde diga el formulario.
        if (!is_string($vuelta) || strpos($vuelta, '/preventas') !== 0) $vuelta = '/preventas';
        header('Location: ' . $vuelta); exit;
    }
}
