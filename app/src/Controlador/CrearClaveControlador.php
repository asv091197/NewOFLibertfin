<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\EnlaceClave;
use LibertyFin\Vista\Plantilla;

/**
 * /crear-clave?t=… · pantalla PÚBLICA a la que lleva el correo de "tu cuenta
 * está lista". El administrador de una empresa recién aprobada elige aquí
 * su contraseña. Ver Servicio\EnlaceClave.
 */
final class CrearClaveControlador
{
    public function formulario()
    {
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
        $t = (string)($_GET['t'] ?? '');

        $enlace = null;
        try {
            $enlace = (new EnlaceClave(Conexion::de($GLOBALS['lf_bd_principal'] ?? '')))->buscar($t);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] crear-clave: ' . $e->getMessage());
        }

        Plantilla::pagina('crear-clave', [
            'titulo' => 'Crea tu contraseña',
            't'      => $t,
            'enlace' => $enlace,
            'error'  => $_SESSION['lf_error'] ?? null,
        ], 'layout-limpio');
        unset($_SESSION['lf_error']);
    }

    public function guardar()
    {
        $t = (string)($_POST['t'] ?? '');
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.', $t);
        }
        try {
            (new EnlaceClave(Conexion::de($GLOBALS['lf_bd_principal'] ?? '')))
                ->usar($t, $_POST['clave'] ?? '', $_POST['confirmar'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return $this->fallo($e->getMessage(), $t);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] crear-clave (guardar): ' . $e->getMessage());
            return $this->fallo('No se pudo guardar. Inténtalo en un momento.', $t);
        }
        // Al login, con un aviso.
        $_SESSION['lf_ok_login'] = 'Tu contraseña quedó guardada. Ya puedes entrar.';
        header('Location: /login'); exit;
    }

    private function fallo($mensaje, $t)
    {
        $_SESSION['lf_error'] = $mensaje;
        header('Location: /crear-clave?t=' . rawurlencode($t)); exit;
    }
}
