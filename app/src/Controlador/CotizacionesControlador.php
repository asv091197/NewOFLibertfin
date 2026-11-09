<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Cotizaciones;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Cotizaciones (módulo «cotizaciones»). La lógica vive en
 * Servicio\Cotizaciones; aquí solo se arma la pantalla y se responde.
 */
final class CotizacionesControlador
{
    public function index()
    {
        if (!Modulos::exigir('cotizaciones')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Cotizaciones($db);
        $estado = Peticion::opcion('estado', array_merge(['', 'abiertas', 'vencida'], array_keys(Cotizaciones::ESTADOS)), '');
        $q = Peticion::texto('q');
        $editar = Peticion::entero('editar');
        $edit = $editar ? $srv->una($editar) : null;
        if ($edit && !in_array($edit['estado'], Cotizaciones::ABIERTAS, true)) $edit = null;

        Plantilla::pagina('cotizaciones/index', [
            'titulo'     => 'Cotizaciones',
            'icono'      => 'doc',
            'subtitulo'  => 'Presupuestos que se vuelven venta',
            'lista'      => $srv->listar(['estado' => $estado, 'q' => $q]),
            'resumen'    => $srv->resumen(),
            'estado'     => $estado,
            'buscar'     => $q,
            'edit'       => $edit,
            'abrir'      => (bool)$edit || Peticion::texto('nueva') !== '',
            'clientePre' => $this->clientePre($db),
            'catalogo'   => $this->catalogo($db),
            'puedeEditar'=> Permisos::puede('editar.cotizaciones'),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ver($id)
    {
        if (!Modulos::exigir('cotizaciones')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        $c = (new Cotizaciones($db))->una($id);
        if (!$c) { $this->noExiste(); return; }
        Plantilla::pagina('cotizaciones/ver', [
            'titulo'     => 'Cotización ' . $c['folio'],
            'icono'      => 'doc',
            'subtitulo'  => $c['cliente_nombre'],
            'cot'        => $c,
            // Para proponerlo al mandar la cotización por correo.
            'correoCliente' => $this->correoDe($db, $c['cliente_id'] ?? 0),
            'puedeEditar'=> Permisos::puede('editar.cotizaciones'),
            'puedeCobrar'=> Permisos::puede('cobrar'),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        if (!Modulos::exigir('cotizaciones')) return;
        $id = (int)($_POST['id'] ?? 0);
        if (!$this->tokenValido()) $this->volver('/cotizaciones', 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        $lineas = json_decode((string)($_POST['lineas'] ?? '[]'), true);
        try {
            $nuevo = (new Cotizaciones($db))->guardar([
                'cliente_id'     => $_POST['cliente_id'] ?? 0,
                'cliente_nombre' => $_POST['cliente_nombre'] ?? '',
                'vigencia'       => $_POST['vigencia'] ?? '',
                'notas'          => $_POST['notas'] ?? '',
                'lineas'         => is_array($lineas) ? $lineas : [],
            ], $id, $_SESSION['usuario_nombre'] ?? '');
            $this->volver('/cotizaciones/' . $nuevo, $id ? 'Cotización actualizada.' : 'Cotización creada. Imprímela o cóbrala cuando el cliente acepte.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cotizaciones?' . ($id ? 'editar=' . $id : 'nueva=1'), $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cotizaciones/guardar: ' . $e->getMessage());
            $this->volver('/cotizaciones', 'No se pudo guardar la cotización.', 'error');
        }
    }

    public function estado($id)
    {
        if (!Modulos::exigir('cotizaciones')) return;
        if (!$this->tokenValido()) $this->volver('/cotizaciones/' . (int)$id, 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        $nuevo = (string)($_POST['estado'] ?? '');
        try {
            $c = (new Cotizaciones($db))->estado($id, $nuevo);
            \LibertyFin\Servicio\Auditoria::anota('cotizacion.estado', 'cotización ' . $c['folio'],
                Cotizaciones::ESTADOS[$c['estado']], Cotizaciones::ESTADOS[$nuevo] ?? $nuevo, $db);
            $txt = ['enviada' => 'Marcada como enviada.', 'rechazada' => 'Marcada como rechazada.', 'borrador' => 'De vuelta a borrador: ya se puede editar.'];
            $this->volver('/cotizaciones/' . (int)$id, $txt[$nuevo] ?? 'Listo.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cotizaciones/' . (int)$id, $e->getMessage(), 'error');
        }
    }

    /** El correo del cliente, si lo tiene. Se propone al enviar. */
    private function correoDe($db, $clienteId)
    {
        if (!$clienteId) return '';
        try {
            $st = $db->prepare("SELECT email FROM clientes WHERE id = ?");
            $st->execute([(int)$clienteId]);
            return trim((string)$st->fetchColumn());
        } catch (\Throwable $e) { return ''; }
    }

    /**
     * Manda la cotización al correo del cliente.
     *
     * Dos cosas que podrían fallar y que se revisan ANTES de intentar:
     * que el cliente tenga correo, y que el correo saliente esté
     * configurado. Las dos dan un mensaje que dice qué hacer, en vez de
     * «no se pudo enviar», que no dice nada.
     */
    public function enviar($id)
    {
        if (!Modulos::exigir('cotizaciones')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        $volverA = '/cotizaciones/' . (int)$id;
        if (!$this->tokenValido()) $this->volver($volverA, 'No se pudo verificar el formulario.', 'error');

        $c = (new Cotizaciones($db))->una($id);
        if (!$c) { $this->noExiste(); return; }

        if (!\LibertyFin\Servicio\Avisos::activos()) {
            $this->volver($volverA, 'El correo saliente no está configurado. '
                . 'Revísalo con php app/bin/revisar-correo.php', 'error');
        }

        // A dónde: lo que se escriba gana sobre lo que tenga el cliente,
        // porque a veces la cotización la pide el contador y no quien
        // está dado de alta.
        $para = trim((string)($_POST['para'] ?? ''));
        $nombre = 'cliente';
        if ($c['cliente_id']) {
            $st = $db->prepare("SELECT nombre, email FROM clientes WHERE id = ?");
            $st->execute([(int)$c['cliente_id']]);
            $cl = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            if ($para === '') $para = trim((string)($cl['email'] ?? ''));
            $nombre = (string)($cl['nombre'] ?? 'cliente');
        }
        if ($para === '') {
            $this->volver($volverA, 'Ese cliente no tiene correo. Escríbelo en su ficha, '
                . 'o escribe a dónde mandarla.', 'error');
        }
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            $this->volver($volverA, 'El correo «' . $para . '» no es válido.', 'error');
        }

        $empresa = 'LibertyFin';
        try {
            $e = (new \LibertyFin\Datos\EmpresaRepo(Conexion::de($GLOBALS['lf_bd_principal'])))
                 ->uno($_SESSION['empresa_id'] ?? 0);
            if (!empty($e['nombre_empresa'])) $empresa = $e['nombre_empresa'];
        } catch (\Throwable $ex) { /* sale con el nombre genérico */ }

        $ok = \LibertyFin\Servicio\Avisos::cotizacion($para, $nombre, $empresa, $c,
                  (string)($_POST['nota'] ?? ''));

        if ($ok) {
            // Queda anotado a dónde se mandó: dentro de un mes, «yo sí se
            // la mandé» se contesta con un renglón y no con la memoria.
            try {
                (new Cotizaciones($db))->anotarEnvio((int)$id, $para);
                \LibertyFin\Servicio\Auditoria::anota('cotizacion.enviar', 'cotización ' . ($c['folio'] ?? $id),
                    null, 'enviada a ' . $para, $db);
            } catch (\Throwable $ex) { error_log('[LibertyFin] anotar envío: ' . $ex->getMessage()); }
            $this->volver($volverA, 'Cotización enviada a ' . $para . '.', 'ok');
        }
        $this->volver($volverA, 'No se pudo enviar el correo. '
            . 'Para ver por qué: php app/bin/revisar-correo.php', 'error');
    }

    /** Hoja suelta para imprimir o guardar como PDF. */
    public function imprimir($id)
    {
        if (!Modulos::exigir('cotizaciones')) return;
        $db = Conexion::de($_SESSION['empresa_db']);
        $c = (new Cotizaciones($db))->una($id);
        if (!$c) { $this->noExiste(); return; }
        $empresa = null; $cliente = null;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $empresa = (new \LibertyFin\Datos\EmpresaRepo($principal))->uno($_SESSION['empresa_id'] ?? 0);
        } catch (\Throwable $e) { /* sale sin membrete */ }
        if ($c['cliente_id']) {
            $st = $db->prepare("SELECT nombre, telefono, email, rfc FROM clientes WHERE id = ?");
            $st->execute([(int)$c['cliente_id']]);
            $cliente = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $logo = '';
        try { $logo = (string)(new \LibertyFin\Datos\ConfigRepo($db))->valorDe('marca.logo', ''); } catch (\Throwable $e) {}
        Plantilla::parcial('cotizaciones/imprimir', ['cot' => $c, 'empresa' => $empresa, 'cliente' => $cliente, 'logo' => $logo]);
    }

    // ────────────────────────────────────────────────────────

    /** «Nueva cotización» desde la ficha de un cliente. */
    private function clientePre($db)
    {
        $id = Peticion::entero('cliente');
        if (!$id) return null;
        $st = $db->prepare("SELECT id, nombre FROM clientes WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Los productos activos con su precio, para armar los renglones. */
    private function catalogo($db)
    {
        return $db->query("SELECT id, nombre, codigo, COALESCE(NULLIF(subprecio,0), precio) AS precio
                           FROM productos WHERE COALESCE(activo,1) = 1 ORDER BY nombre LIMIT 2000")
                  ->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function noExiste()
    {
        http_response_code(404);
        Plantilla::pagina('errores/404', ['titulo' => 'No encontrada', 'icono' => 'alerta', 'subtitulo' => '']);
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