<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

final class LoginControlador
{
    public function mostrar()
    {
        // Ya tiene sesión: el botón "Iniciar sesión" de la landing lo
        // manda directo al sistema. `/` es la landing, no el panel.
        if (Autenticar::sesionValida()) { header('Location: /panel'); exit; }
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        // "Cancelar" en la pregunta de la otra sesión: se vuelve al formulario.
        if (isset($_GET['cancelar'])) {
            unset($_SESSION['lf_login_pendiente']);
            header('Location: /login'); exit;
        }

        // Si la sesión se cerró porque la cuenta se abrió en otro lado, se
        // dice. Solo se da por leído en una vista normal: una consulta de
        // fondo (el chat, la campana) que rebota aquí no debe gastarlo.
        $error = $_SESSION['lf_error'] ?? null;
        $motivo = $_SESSION['lf_motivo_salida'] ?? null;
        if (!$error && $motivo && $motivo[0] === 'otra-sesion') {
            $error = 'Se inició sesión con tu cuenta en otro dispositivo'
                   . (!empty($motivo[1]) ? ' (' . $motivo[1] . ')' : '')
                   . '. Si no fuiste tú, entra y cambia tu contraseña.';
        }
        $deFondo = ($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1' || !empty($_SERVER['HTTP_X_LF_PARCIAL'])
                || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if (!$deFondo) unset($_SESSION['lf_motivo_salida']);

        Plantilla::pagina('login', [
            'titulo'    => 'Entrar',
            'error'     => $error,
            'usuario'   => $_SESSION['lf_usuario'] ?? '',
            'bloqueo'   => Autenticar::bloqueoRestante(),
            // La cuenta está abierta en otro dispositivo: se pregunta.
            'pendiente' => \LibertyFin\Servicio\SesionUnica::pendiente(),
        ], 'layout-limpio');
        unset($_SESSION['lf_error'], $_SESSION['lf_usuario']);
    }

    public function entrar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.', '');
        }

        $id = trim($_POST['usuario'] ?? '');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            (new \LibertyFin\Datos\AutenticacionRepo($principal))->prepararIndice();
            $r = (new Autenticar($principal))->entrar($id, $_POST['clave'] ?? '');
            // La cuenta está abierta en otro dispositivo: la pantalla de
            // entrar pregunta si se cierra (ver confirmar()).
            if ($r === 'confirmar') { header('Location: /login'); exit; }
            header('Location: /panel'); exit;
        } catch (\RuntimeException $e) {
            return $this->fallo($e->getMessage(), $id);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] login: ' . $e->getMessage());
            return $this->fallo('No se pudo conectar. Inténtalo en un momento.', $id);
        }
    }

    /** "Cerrarla y entrar aquí": la otra sesión de la cuenta sale. */
    public function confirmar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.', '');
        }
        try {
            (new Autenticar(Conexion::de($GLOBALS['lf_bd_principal'])))->confirmar();
            header('Location: /panel'); exit;
        } catch (\RuntimeException $e) {
            return $this->fallo($e->getMessage(), '');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] login (confirmar): ' . $e->getMessage());
            return $this->fallo('No se pudo conectar. Inténtalo en un momento.', '');
        }
    }

    /**
     * La página pregunta si su sesión sigue abierta. Llegar aquí ya es
     * la respuesta: si la cuenta se abrió en otro dispositivo, el portero
     * (public/index.php) contesta antes, con `sesion_cerrada`.
     */
    public function pulso()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"ok":true}';
        exit;
    }

    public function salir()
    {
        // La cuenta queda libre: entrar desde otro lado ya no pregunta.
        \LibertyFin\Servicio\SesionUnica::cerrar();
        // Y este navegador deja de recibir avisos de escritorio: quien sale
        // de una computadora prestada no debe seguir viendo sus tickets.
        \LibertyFin\Servicio\Push::soltarNavegador(\LibertyFin\Servicio\SesionUnica::navegador());
        Autenticar::salir();
        session_start();
        $_SESSION['lf_error'] = 'Sesión cerrada.';
        header('Location: /login'); exit;
    }

    private function fallo($mensaje, $usuario)
    {
        $_SESSION['lf_error']   = $mensaje;
        $_SESSION['lf_usuario'] = $usuario;
        header('Location: /login'); exit;
    }

    /** Qué hacer si no recuerdas la contraseña. Pública. */
    public function ayudaAcceso()
    {
        Plantilla::pagina('ayuda-acceso', ['titulo' => 'Recuperar el acceso'], 'layout-limpio');
    }
}
