<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Compras;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Compras a proveedor (módulo «compras», que requiere Inventario).
 * Ver Servicio\Compras.
 */
final class ComprasControlador
{
    public function index()
    {
        if (!Modulos::exigir('compras')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Compras($db);
        $inv = new Inventario($db);
        $estado = Peticion::opcion('estado', array_merge([''], array_keys(Compras::ESTADOS)), '');
        $suc = Peticion::entero('s') ?: $inv->sucursalActual();

        $proveedores = [];
        try { $proveedores = (new \LibertyFin\Datos\GastoRepo($db))->proveedoresActivos(); } catch (\Throwable $e) {}

        Plantilla::pagina('compras/index', [
            'titulo'      => 'Compras',
            'icono'       => 'camion',
            'subtitulo'   => 'Pedidos a proveedor',
            'lista'       => $srv->listar(['estado' => $estado, 'q' => Peticion::texto('q')]),
            'estado'      => $estado,
            'buscar'      => Peticion::texto('q'),
            'sucursales'  => $inv->sucursales(),
            'sucursal'    => $suc,
            'productos'   => $inv->listado($suc),
            'sugerido'    => Peticion::texto('sugerir') !== '' ? $srv->sugerido($suc) : [],
            'proveedores' => $proveedores,
            'abrir'       => Peticion::texto('nueva') !== '' || Peticion::texto('sugerir') !== '',
            'puedeEditar' => Permisos::puede('editar.compras'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ver($id)
    {
        if (!Modulos::exigir('compras')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        $c = (new Compras($db))->una($id);
        if (!$c) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo' => 'No encontrada', 'icono' => 'alerta', 'subtitulo' => '']);
            return;
        }
        Plantilla::pagina('compras/ver', [
            'titulo'      => 'Compra ' . $c['folio'],
            'icono'       => 'camion',
            'subtitulo'   => $c['proveedor_nombre'] . ' · ' . ($c['sucursal'] ?: ''),
            'compra'      => $c,
            'puedeEditar' => Permisos::puede('editar.compras'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        if (!Modulos::exigir('compras')) return;
        if (!$this->tokenValido()) $this->volver('/compras', 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        $lineas = [];
        foreach ((array)($_POST['producto'] ?? []) as $i => $pid) {
            $lineas[] = ['producto_id' => $pid, 'cantidad' => $_POST['cantidad'][$i] ?? 0, 'costo' => $_POST['costo'][$i] ?? 0];
        }
        try {
            $id = (new Compras($db))->guardar([
                'proveedor_id'     => $_POST['proveedor_id'] ?? 0,
                'proveedor_nombre' => $_POST['proveedor_nombre'] ?? '',
                'sucursal_id'      => $_POST['sucursal_id'] ?? 0,
                'notas'            => $_POST['notas'] ?? '',
                'lineas'           => $lineas,
            ], $_SESSION['usuario_nombre'] ?? '');
            $this->volver('/compras/' . $id, 'Pedido registrado. Cuando llegue la mercancía, dale «Recibir».', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/compras?nueva=1', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] compras/guardar: ' . $e->getMessage());
            $this->volver('/compras', 'No se pudo guardar el pedido.', 'error');
        }
    }

    public function recibir($id)
    {
        if (!Modulos::exigir('compras')) return;
        if (!$this->tokenValido()) $this->volver('/compras/' . (int)$id, 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        try {
            $r = (new Compras($db))->recibir($id, [
                'actualizar_costo' => !empty($_POST['actualizar_costo']),
                'gasto'            => !empty($_POST['gasto']),
                'metodo_pago'      => $_POST['metodo_pago'] ?? '',
            ], $_SESSION['usuario_nombre'] ?? '');
            \LibertyFin\Servicio\Auditoria::anota('compra.recibir', 'compra ' . $r['folio'], 'pedida',
                'recibida · ' . $r['renglones'] . ' productos' . ($r['gasto_id'] ? ' · con gasto' : ''), $db);
            $this->volver('/compras/' . (int)$id, 'Mercancía recibida: ' . $r['renglones'] . ($r['renglones'] === 1 ? ' producto entró' : ' productos entraron')
                . ' al inventario' . ($r['gasto_id'] ? ' y el gasto quedó registrado' : '') . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/compras/' . (int)$id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] compras/recibir: ' . $e->getMessage());
            $this->volver('/compras/' . (int)$id, 'No se pudo recibir la compra. No se movió nada.', 'error');
        }
    }

    public function cancelar($id)
    {
        if (!Modulos::exigir('compras')) return;
        if (!$this->tokenValido()) $this->volver('/compras/' . (int)$id, 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        try {
            $c = (new Compras($db))->cancelar($id);
            \LibertyFin\Servicio\Auditoria::anota('compra.cancelar', 'compra ' . $c['folio'], 'pedida', 'cancelada', $db);
            $this->volver('/compras/' . (int)$id, 'Pedido cancelado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/compras/' . (int)$id, $e->getMessage(), 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function volver($a, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $a); exit;
    }
}
