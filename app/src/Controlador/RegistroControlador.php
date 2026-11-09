<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\CrearEmpresa;
use LibertyFin\Vista\Plantilla;

/**
 * Registro de empresas · pantalla PÚBLICA. Es a donde lleva la landing.
 *
 * Es la única ruta sin sesión además del login, así que se trata como
 * hostil: tope por correo, tope por IP y nada que cree recursos de golpe.
 * Lo que se guarda es una SOLICITUD; la base se crea al aprobarla.
 *
 * La landing puede mandar el plan y el código de distribuidor en la
 * dirección: /registro?plan=profesional&d=DIST123. Llegan ya puestos.
 */
final class RegistroControlador
{
    /** Lo que se le devuelve al formulario si algo falla, para no reescribirlo. */
    const CAMPOS = ['nombre', 'apellido', 'nombre_empresa', 'email_admin', 'telefono',
                    'giro', 'giro_otro', 'empleados', 'no_distribuidor', 'plan'];

    public function formulario()
    {
        if (!empty($_SESSION['logged_in'])) { header('Location: /panel'); exit; }
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        $datos = $_SESSION['lf_registro'] ?? [];
        // Lo que trae el enlace de la landing, si no hay algo ya escrito.
        if (!isset($datos['plan']))            $datos['plan'] = self::plan($_GET['plan'] ?? '');
        if (!isset($datos['no_distribuidor'])) {
            $datos['no_distribuidor'] = mb_substr(trim((string)($_GET['d'] ?? $_GET['distribuidor'] ?? '')), 0, 50);
        }

        Plantilla::pagina('registro/index', [
            'titulo'  => 'Crea tu cuenta',
            'cuerpo'  => 'lf-rg-cuerpo',
            'error'   => $_SESSION['lf_error'] ?? null,
            'ok'      => $_SESSION['lf_ok'] ?? null,
            // «previo» y no «datos»: Plantilla::pagina() recibe sus variables en
            // $datos y extract(EXTR_SKIP) se saltaba esta; la vista veía todo.
            'previo'  => $datos,
            'planes'  => self::planes(),
            'sitio'   => $GLOBALS['lf_sitio'] ?? [],
        ], 'layout-limpio');
        unset($_SESSION['lf_error'], $_SESSION['lf_ok'], $_SESSION['lf_registro']);
    }

    public function enviar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.');
        }
        // Campo trampa: invisible para una persona, un robot lo llena.
        if (trim((string)($_POST['sitio_web'] ?? '')) !== '') {
            $_SESSION['lf_ok'] = 'Recibimos tu solicitud.';
            header('Location: /registro'); exit;
        }

        $nombre   = trim(preg_replace('/\s+/', ' ', (string)($_POST['nombre'] ?? '')));
        $apellido = trim(preg_replace('/\s+/', ' ', (string)($_POST['apellido'] ?? '')));
        if ($nombre === '' || $apellido === '') return $this->fallo('Escribe tu nombre y tu apellido.');

        $giro = (string)($_POST['giro'] ?? '');
        if (!in_array($giro, CrearEmpresa::GIROS, true)) return $this->fallo('Elige el giro de tu negocio.');
        if ($giro === 'Otro') {
            $giro = trim((string)($_POST['giro_otro'] ?? ''));
            if ($giro === '') return $this->fallo('Escribe a qué se dedica tu negocio.');
        }
        if (!isset(CrearEmpresa::EMPLEADOS[(string)($_POST['empleados'] ?? '')])) {
            return $this->fallo('Elige cuántas personas trabajan en tu negocio.');
        }

        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            (new CrearEmpresa($principal))->solicitar([
                'nombre_empresa'  => $_POST['nombre_empresa'] ?? '',
                'nombre_contacto' => $nombre . ' ' . $apellido,
                'email_admin'     => $_POST['email_admin'] ?? '',
                'telefono'        => $_POST['telefono'] ?? '',
                'giro_comercial'  => $giro,
                'empleados'       => $_POST['empleados'] ?? '',
                'no_distribuidor' => $_POST['no_distribuidor'] ?? '',
                'plan'            => self::plan($_POST['plan'] ?? ''),
            ], $_SERVER['REMOTE_ADDR'] ?? '');
            $_SESSION['lf_ok'] = ['nombre' => $nombre, 'email' => trim((string)($_POST['email_admin'] ?? ''))];
            header('Location: /registro'); exit;
        } catch (\InvalidArgumentException $e) {
            return $this->fallo($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[LibertyFin] registro: ' . $e->getMessage());
            return $this->fallo('No se pudo enviar. Inténtalo en un momento.');
        }
    }

    /** Los planes que se pueden elegir: [clave => nombre]. La prueba, primero. */
    private static function planes()
    {
        $r = ['prueba' => 'Prueba gratis'];
        $c = \LibertyFin\Datos\PlanRepo::catalogo();
        foreach (($c['planes'] ?? []) as $k => $p) $r[$k] = $p['nombre'];
        return $r;
    }

    /** Solo un plan que exista; cualquier otra cosa, la prueba. */
    private static function plan($p)
    {
        $p = strtolower(trim((string)$p));
        return isset(self::planes()[$p]) ? $p : 'prueba';
    }

    private function fallo($mensaje)
    {
        $_SESSION['lf_error'] = $mensaje;
        // Se devuelve lo capturado para no obligar a rellenar todo.
        $_SESSION['lf_registro'] = array_intersect_key($_POST, array_flip(self::CAMPOS));
        header('Location: /registro'); exit;
    }
}
