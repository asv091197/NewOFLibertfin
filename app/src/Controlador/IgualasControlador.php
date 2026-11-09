<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Igualas;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Igualas (módulo «igualas»): los cobros que se repiten cada mes.
 * Ver Servicio\Igualas para cómo y cuándo se generan las ventas.
 */
final class IgualasControlador
{
    public function index()
    {
        if (!Modulos::exigir('igualas')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        Igualas::alDia($db);
        $srv = new Igualas($db);
        $editar = Peticion::entero('editar');

        $especialistas = [];
        try {
            $especialistas = $db->query("SELECT id, nombre FROM comision_colaboradores WHERE COALESCE(activo,1) = 1 ORDER BY nombre")
                                ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $pre = null;
        if ($c = Peticion::entero('cliente')) {
            $st = $db->prepare("SELECT id, nombre FROM clientes WHERE id = ?");
            $st->execute([$c]);
            $pre = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        Plantilla::pagina('igualas/index', [
            'titulo'       => 'Igualas',
            'icono'        => 'repetir',
            'subtitulo'    => 'Cobros que se repiten cada mes',
            'igualas'      => $srv->listar(),
            'historial'    => $srv->historial(),
            'mensual'      => $srv->mensual(),
            'edit'         => $editar ? $srv->una($editar) : null,
            'clientePre'   => $pre,
            'abrir'        => $editar > 0 || Peticion::texto('nueva') !== '' || $pre,
            'servicios'    => $db->query("SELECT id, nombre, COALESCE(NULLIF(subprecio,0), precio) AS precio FROM productos
                                          WHERE COALESCE(activo,1) = 1 ORDER BY nombre")->fetchAll(\PDO::FETCH_ASSOC),
            'especialistas'=> $especialistas,
            'clientes'     => $this->nombresClientes($db),
            'puedeEditar'  => Permisos::puede('editar.igualas'),
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        if (!Modulos::exigir('igualas')) return;
        $id = (int)($_POST['id'] ?? 0);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        try {
            (new Igualas($db))->guardar($_POST, $id, $_SESSION['usuario_nombre'] ?? '');
            \LibertyFin\Servicio\Auditoria::anota('iguala.guardar', 'cliente ' . (int)($_POST['cliente_id'] ?? 0), null,
                \LibertyFin\Dominio\Dinero::pesos((float)($_POST['monto'] ?? 0)) . ' al mes, día ' . (int)($_POST['dia'] ?? 1), $db);
            // Si ya le toca este mes, que se genere ahora y no mañana.
            unset($_SESSION['lf_igualas_' . ($_SESSION['empresa_db'] ?? '')]);
            $n = Igualas::alDia($db);
            $this->volver(($id ? 'Iguala actualizada.' : 'Iguala creada.')
                . ($n ? ' Se generó el cargo de este mes: ya está en Cobranza.' : ''), 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error', $id ? ['editar' => $id] : ['nueva' => 1]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] igualas/guardar: ' . $e->getMessage());
            $this->volver('No se pudo guardar la iguala.', 'error');
        }
    }

    public function alternar()
    {
        if (!Modulos::exigir('igualas')) return;
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        try {
            $on = (new Igualas(Conexion::de($_SESSION['empresa_db'])))->alternar((int)($_POST['id'] ?? 0));
            $this->volver($on ? 'Iguala reanudada. Los meses de pausa no se cobran.' : 'Iguala pausada: no se generan más cargos.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        }
    }

    /** «Revisar ahora»: genera lo que toque sin esperar a mañana. */
    public function generar()
    {
        if (!Modulos::exigir('igualas')) return;
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        $hechas = (new Igualas($db))->generarPendientes();
        $_SESSION['lf_igualas_' . ($_SESSION['empresa_db'] ?? '')] = date('Y-m-d');
        if ($hechas) {
            \LibertyFin\Servicio\Auditoria::anota('iguala.generar', count($hechas) . ' cargos', null, null, $db);
        }
        $this->volver($hechas ? 'Se generaron ' . count($hechas) . (count($hechas) === 1 ? ' cargo' : ' cargos') . '. Están en Cobranza.'
                              : 'Todo al día: no hay cargos pendientes de generar.', 'ok');
    }

    /** Los clientes de las igualas, para el buscador del formulario. */
    private function nombresClientes($db)
    {
        return $db->query("SELECT id, nombre FROM clientes WHERE COALESCE(activo,1) = 1 ORDER BY nombre LIMIT 3000")
                  ->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function volver($texto, $tipo, array $q = [])
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /igualas' . ($q ? '?' . http_build_query($q) : '')); exit;
    }
}
