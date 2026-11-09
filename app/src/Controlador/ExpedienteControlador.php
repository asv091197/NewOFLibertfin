<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Expediente;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * La ficha de un cliente (/clientes/{id}).
 *
 * Siempre existe: sus números, sus ventas y los botones para venderle.
 * Cada módulo encendido le agrega lo suyo: el expediente (ficha, notas,
 * archivos), sus paquetes, sus citas, sus igualas y sus cotizaciones.
 */
final class ExpedienteControlador
{
    public function ver($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $exp = new Expediente($db);
        $cli = $exp->cliente($id);
        if (!$cli) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo' => 'No encontrado', 'icono' => 'alerta', 'subtitulo' => '']);
            return;
        }
        $mods = [];
        try { $mods = Modulos::deSesion()->activos(); } catch (\Throwable $e) {}
        $conExp = !empty($mods['expediente']) && Permisos::puede('ver.expediente');

        $extra = function ($k, callable $f) use ($mods) {
            if (empty($mods[$k])) return null;
            try { return $f(); } catch (\Throwable $e) { error_log('[LibertyFin] ficha de cliente · ' . $k . ': ' . $e->getMessage()); return null; }
        };

        Plantilla::pagina('clientes/ficha', [
            'titulo'       => $cli['nombre'],
            'icono'        => 'cliente',
            'subtitulo'    => 'Ficha del cliente',
            'cli'          => $cli,
            'numeros'      => $exp->numeros($id),
            'ventas'       => $exp->ventas($id),
            'mods'         => $mods,
            'conExp'       => $conExp,
            'puedeExp'     => $conExp && Permisos::puede('editar.expediente'),
            'ficha'        => $conExp ? $exp->ficha($id) : null,
            'notas'        => $conExp ? $exp->notas($id) : [],
            'archivos'     => $conExp ? $exp->archivos($id) : [],
            'paquetes'     => $extra('paquetes', function () use ($db, $id) { return (new \LibertyFin\Servicio\Paquetes($db))->delCliente($id, false); }),
            'citas'        => $extra('agenda', function () use ($db, $id) { return (new \LibertyFin\Servicio\Agenda($db))->delCliente($id); }),
            'igualas'      => $extra('igualas', function () use ($db, $id) { return (new \LibertyFin\Servicio\Igualas($db))->delCliente($id); }),
            'cotizaciones' => $extra('cotizaciones', function () use ($db, $id) { return (new \LibertyFin\Servicio\Cotizaciones($db))->delCliente($id); }),
            'puedeCobrar'  => Permisos::puede('cobrar'),
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ficha($id)
    {
        $exp = $this->preparar($id);
        $exp->guardarFicha($id, $_POST['texto'] ?? '', $_SESSION['usuario_nombre'] ?? '');
        $this->volver($id, 'Ficha guardada.', 'ok');
    }

    public function nota($id)
    {
        $exp = $this->preparar($id);
        try {
            $exp->agregarNota($id, $_POST['texto'] ?? '', $_SESSION['usuario_nombre'] ?? '');
            $this->volver($id, 'Nota agregada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        }
    }

    public function borrarNota($id, $nid)
    {
        $exp = $this->preparar($id);
        $this->volver($id, $exp->borrarNota($id, $nid) ? 'Nota borrada.' : 'Esa nota ya no existe.', 'ok');
    }

    public function subir($id)
    {
        $exp = $this->preparar($id);
        try {
            $exp->subir($id, $_FILES['archivo'] ?? [], $_SESSION['usuario_nombre'] ?? '');
            \LibertyFin\Servicio\Auditoria::anota('expediente.archivo', 'cliente ' . (int)$id, null,
                'subió ' . mb_substr((string)($_FILES['archivo']['name'] ?? ''), 0, 120));
            $this->volver($id, 'Archivo guardado en el expediente.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] expediente/subir: ' . $e->getMessage());
            $this->volver($id, 'No se pudo guardar el archivo.', 'error');
        }
    }

    /** Entrega el archivo: solo por aquí, nunca por una ruta pública. */
    public function bajar($id, $aid)
    {
        if (!$this->moduloActivo()) { http_response_code(404); exit; }
        $a = (new Expediente(Conexion::de($_SESSION['empresa_db'])))->archivo($id, $aid);
        if (!$a) { http_response_code(404); echo 'No encontrado'; exit; }
        $enLinea = in_array($a['mime'], ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true) && empty($_GET['bajar']);
        $nombre = preg_replace('/[^\w.\- ]+/u', '_', $a['nombre']);
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($a['ruta']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        // A las imágenes, nada más que ellas mismas. Al PDF no: con esa
        // política el visor del navegador sale en blanco.
        if (strpos($a['mime'], 'image/') === 0) {
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($a['nombre']));
        readfile($a['ruta']);
        exit;
    }

    public function borrarArchivo($id, $aid)
    {
        $exp = $this->preparar($id);
        $a = $exp->borrarArchivo($id, $aid);
        if ($a) \LibertyFin\Servicio\Auditoria::anota('expediente.archivo', 'cliente ' . (int)$id, $a['nombre'], 'borrado');
        $this->volver($id, $a ? 'Archivo borrado.' : 'Ese archivo ya no existe.', 'ok');
    }

    /** Lo común de lo que escribe: módulo encendido, formulario válido y cliente que existe. */
    private function preparar($id)
    {
        if (!$this->moduloActivo()) $this->volver($id, 'El expediente de clientes está apagado (Ajustes → Módulos).', 'error');
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');
        $exp = new Expediente(Conexion::de($_SESSION['empresa_db']));
        if (!$exp->cliente($id)) { header('Location: /clientes'); exit; }
        return $exp;
    }

    private function moduloActivo()
    {
        try { return Modulos::deSesion()->activo('expediente'); } catch (\Throwable $e) { return false; }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function volver($id, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /clientes/' . (int)$id); exit;
    }
}
