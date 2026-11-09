<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Servicio\Arranque;
use LibertyFin\Vista\Plantilla;

/**
 * La bienvenida: las preguntas del primer ingreso (ver Servicio\Arranque).
 *
 * Después de contestarlas el sistema entra con una animación y, al
 * terminar, arranca la guía: primero se configura, luego se recorre.
 */
final class BienvenidaControlador
{
    public function index()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $arr = new Arranque($db);
        $repite = isset($_GET['repetir']);
        // Fuera de su momento no se ve: ni otra vez sola ni para quien no toca.
        if (!$arr->pendiente() && !$repite) {
            unset($_SESSION['lf_bienvenida']);
            header('Location: /panel'); exit;
        }
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
        $cfg = new ConfigRepo($db);

        Plantilla::pagina('bienvenida/index', [
            'titulo'    => 'Bienvenida',
            'cuerpo'    => 'lf-bv-cuerpo',
            'inicial'   => $arr->respuestasIniciales(),
            'areasYa'   => $db->query("SELECT nombre FROM categorias ORDER BY nombre")->fetchAll(\PDO::FETCH_COLUMN),
            'marca'     => ['color' => $cfg->valorDe('marca.color', ''), 'logo' => $cfg->valorDe('marca.logo', '')],
            'error'     => $_SESSION['lf_error'] ?? null,
        ], 'layout-limpio');
        unset($_SESSION['lf_error']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('La sesión expiró. Intenta de nuevo.');
        }
        $arr = new Arranque($db);

        if (!empty($_POST['saltar'])) {
            $arr->saltar();
            $this->entrar(false);
        }

        try {
            $arr->aplicar($_POST);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[LibertyFin] bienvenida: ' . $e->getMessage());
            $this->volver('No se pudo guardar. Intenta de nuevo.');
        }

        // La marca: lo mismo que Ajustes → Empresa. Si falla, la configuración
        // ya quedó; solo se pierde el logo o el color, que se ponen después.
        $cfg = new ConfigRepo($db);
        $color = trim((string)($_POST['marca_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            try {
                $cfg->guardar('marca.color', $color);
                $_SESSION['lf_marca_color'] = $color;
            } catch (\Throwable $e) { error_log('[LibertyFin] bienvenida/color: ' . $e->getMessage()); }
        }
        if (!empty($_FILES['logo']['name'])) {
            try {
                $antes = $cfg->valorDe('marca.logo', '');
                $ruta  = \LibertyFin\Servicio\Archivos::imagen($_FILES['logo'], 'logo');
                $cfg->guardar('marca.logo', $ruta);
                $_SESSION['lf_marca_logo'] = $ruta;
                if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
            } catch (\Throwable $e) {
                error_log('[LibertyFin] bienvenida/logo: ' . $e->getMessage());
                $_SESSION['lf_aviso_logo'] = 'El logotipo no se pudo guardar; súbelo en Ajustes → Empresa.';
            }
        }
        $this->entrar(true);
    }

    /** Al sistema, con la entrada animada y luego la guía. */
    private function entrar($configuro)
    {
        unset($_SESSION['lf_bienvenida']);
        $_SESSION['lf_entrada'] = $configuro ? 'configurado' : 'saltado';
        header('Location: /panel'); exit;
    }

    private function volver($mensaje)
    {
        $_SESSION['lf_error'] = $mensaje;
        header('Location: /bienvenida' . (isset($_GET['repetir']) || !empty($_POST['repite']) ? '?repetir=1' : '')); exit;
    }
}
