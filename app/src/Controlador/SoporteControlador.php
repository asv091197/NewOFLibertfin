<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PlataformaRepo;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Servicio\CrearEmpresa;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Panel de soporte · todas las empresas.
 *
 * Es de LECTURA salvo cuatro acciones: restablecer una contraseña,
 * activar o desactivar una cuenta, reenviarle los accesos al
 * administrador de una empresa, y eliminar una empresa que nunca operó.
 *
 * LAS DOS ÚLTIMAS ESTÁN AQUÍ Y NO SOLO EN /plataforma
 *
 * Vivían únicamente en Cuentas, que es de superadmin. Pero quien da de
 * alta empresas y atiende al cliente que no puede entrar es soporte, y
 * mandarlo a pedirle a otra persona que apriete un botón por él es cómo
 * las cosas se quedan sin hacer. Soporte ya puede crear empresas
 * (`alta.empresas`) y restablecer la contraseña de cualquiera
 * (`clave.ajena`); reenviar accesos es exactamente eso.
 *
 * Eliminar sí destruye algo, y por eso la guarda no es el permiso: es
 * que el sistema CUENTA los movimientos y se niega si hay uno solo. Una
 * empresa vacía que se creó por error no es un registro que proteger.
 * Ver Servicio\AccesosEmpresa.
 *
 * No se pueden cambiar roles administrativos desde aquí a propósito: si
 * soporte pudiera volver admin a cualquiera, bastaría comprometer una
 * cuenta de soporte para tener acceso total a todos los clientes. El
 * límite no es desconfianza en la persona, es el tamaño del daño si esa
 * cuenta se pierde.
 */
final class SoporteControlador
{
    public function index()
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $repo = new PlataformaRepo($principal);

        $buscar = trim(Peticion::texto('q', ''));
        $lista  = $repo->empresas($buscar);

        // El pulso se pide solo si son pocas: abrir cien bases para pintar
        // una lista es cambiar una pantalla lenta por una inservible.
        $conPulso = count($lista) <= 40;
        foreach ($lista as &$e) {
            $e['pulso'] = $conPulso ? $repo->pulso($e['nombre_base_datos']) : null;
        }
        unset($e);

        Plantilla::pagina('soporte/index', [
            'titulo'    => 'Empresas',
            'icono'     => 'cliente',
            'subtitulo' => count($lista) . ' registradas',
            'empresas'  => $lista,
            'resumen'   => $repo->resumen(),
            'buscar'    => $buscar,
            'conPulso'  => $conPulso,
            'ultima'    => Migraciones::VERSION,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ficha($id)
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $f = (new PlataformaRepo($principal))->ficha($id);
        if (!$f) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        Plantilla::pagina('soporte/ficha', [
            'titulo'    => $f['empresa']['nombre_empresa'],
            'icono'     => 'cliente',
            'subtitulo' => 'Ficha técnica',
            'f'         => $f,
            'ultima'    => Migraciones::VERSION,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Restablece la contraseña de un usuario de cualquier empresa.
     * La nueva se muestra una vez y no se guarda en claro.
     */
    public function restablecer()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $base = $_POST['base'] ?? '';
        $emp  = (int)($_POST['empresa'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver($emp, 'Base no válida.', 'error');
        }
        try {
            $repo  = new UsuarioRepo(Conexion::de($base));
            $clave = $repo->restablecerGenerando((int)($_POST['id'] ?? 0));
            $this->volver($emp, 'Contraseña nueva: ' . $clave
                . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($emp, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/restablecer: ' . $e->getMessage());
            $this->volver($emp, 'No se pudo restablecer.', 'error');
        }
    }

    public function alternar()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $base = $_POST['base'] ?? '';
        $emp  = (int)($_POST['empresa'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver($emp, 'Base no válida.', 'error');
        }
        try {
            // El segundo argumento es quién lo hace, para que el repo
            // impida que alguien se desactive a sí mismo. Soporte no
            // pertenece a esa empresa, así que va en cero: ningún usuario
            // de ahí puede coincidir.
            (new UsuarioRepo(Conexion::de($base)))->alternar((int)($_POST['id'] ?? 0), 0);
            $this->volver($emp, 'Estado de la cuenta cambiado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($emp, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/alternar: ' . $e->getMessage());
            $this->volver($emp, 'No se pudo cambiar.', 'error');
        }
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    /**
     * Contraseña nueva para el administrador de una empresa, y correo.
     *
     * Distinto de `restablecer()`, que es de la ficha y cambia la de UN
     * usuario cualquiera sin avisarle a nadie. Esto es para el caso de
     * "la empresa está bien creada pero su gente nunca recibió el
     * correo": va al administrador, se le manda, y la contraseña sale
     * también en pantalla por si el correo vuelve a fallar.
     */
    public function accesos()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $id = (int)($_POST['id'] ?? 0);
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                 . ($_SERVER['HTTP_HOST'] ?? 'libertyfin.com.mx') . '/login';
            $r = (new \LibertyFin\Servicio\AccesosEmpresa($principal))->regenerar($id, $url);

            \LibertyFin\Servicio\Auditoria::anota('empresa.accesos', $r['empresa'],
                'contraseña anterior', 'contraseña nueva para ' . $r['usuario'], $principal);

            $this->volver($id, $r['empresa'] . ' · usuario ' . $r['usuario']
                . ' · contraseña ' . $r['clave'] . ' — anótala, no se vuelve a mostrar. '
                . ($r['correo_ok'] ? 'También se la mandamos a ' . $r['email'] . '.'
                                   : 'EL CORREO NO SALIÓ (' . $r['correo_error'] . '): entrégasela tú.'),
                'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/accesos: ' . $e->getMessage());
            $this->volver($id, 'No se pudieron regenerar los accesos: ' . $e->getMessage(), 'error');
        }
    }

    /** Elimina una empresa que nunca operó. Las guardas van en el servicio. */
    public function eliminar()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $id = (int)($_POST['id'] ?? 0);
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $r = (new \LibertyFin\Servicio\AccesosEmpresa($principal))
                 ->eliminar($id, $_POST['confirmacion'] ?? '');
            // A la LISTA, no a la ficha: la ficha que se pediría ya no existe.
            $this->volver(null, $r['empresa'] . ' se eliminó. Su base ' . $r['base']
                . ' sigue en el hosting; si quieres el espacio, bórrala en '
                . 'cPanel → Bases de datos MySQL.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/eliminar: ' . $e->getMessage());
            $this->volver($id, $e->getMessage(), 'error');
        }
    }

    private function volver($empresaId, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . ($empresaId ? '/soporte/' . (int)$empresaId : '/soporte'));
        exit;
    }

    /**
     * El panel de soporte.
     *
     * Es la primera pantalla de un rol de plataforma, en lugar del panel
     * de empresa: ese muestra las ventas de UNA empresa —la de quien
     * prestó la sesión— y para soporte eso no significa nada.
     *
     * Lo que sí significa algo es qué está esperando: tickets sin
     * responder, documentos sin revisar, empresas sin dar de alta y
     * cuáles están a punto de vencer.
     */
    public function panel()
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $plat = new PlataformaRepo($principal);
        $tk   = new TicketRepo($principal);
        $yo   = (int)($_SESSION['usuario_id'] ?? 0);
        // Los que llevan días esperando o resueltos vencen solos (ver TicketRepo).
        $tk->vencerSolos();

        $activos = $tk->bandeja(['estado' => 'activos'], 200);
        $sinAsignar = array_values(array_filter($activos, function ($t) {
            return empty($t['asignado_a']);
        }));
        $mios = array_values(array_filter($activos, function ($t) use ($yo) {
            return (int)$t['asignado_a'] === $yo && $yo > 0;
        }));

        // Documentos y solicitudes esperando, solo si le toca a este rol.
        $docs = \LibertyFin\Dominio\Permisos::puede('revisar.docs')
              ? $this->contarDocumentos() : null;
        $altas = \LibertyFin\Dominio\Permisos::puede('alta.empresas')
               ? count((new CrearEmpresa($principal))->pendientes()) : null;

        Plantilla::pagina('soporte/panel', [
            'titulo'     => 'Panel de soporte',
            'icono'      => 'panel',
            'subtitulo'  => \LibertyFin\Dominio\Permisos::rotulo($_SESSION['usuario_rol'] ?? ''),
            'resumen'    => $plat->resumen(),
            'cifras'     => $tk->cifras(),
            'activos'    => $activos,
            'sinAsignar' => $sinAsignar,
            'mios'       => $mios,
            'categorias' => $tk->porCategoria(),
            'docs'       => $docs,
            'altas'      => $altas,
            'alertas'    => $this->empresasConProblemas($plat),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    private function contarDocumentos()
    {
        $n = 0;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            foreach ((new \LibertyFin\Datos\AutenticacionRepo($principal))->empresas() as $e) {
                $base = $e['nombre_base_datos'];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) continue;
                try { $n += count((new \LibertyFin\Datos\CuentaRepo(Conexion::de($base)))->porRevisar()); }
                catch (\Throwable $ex) { /* una base caída no cuenta */ }
            }
        } catch (\Throwable $e) { return null; }
        return $n;
    }

    /**
     * Empresas que van a llamar pronto.
     *
     * No lista todas: solo las que tienen una señal concreta —esquema
     * atrasado, suscripción vencida, caja sin cerrar desde hace días—
     * porque esas son las que generan la llamada.
     */
    private function empresasConProblemas(PlataformaRepo $plat)
    {
        $r = [];
        try {
            foreach ($plat->empresas() as $e) {
                if (empty($e['activo'])) continue;
                $venc = !empty($e['fecha_vencimiento']) ? strtotime($e['fecha_vencimiento']) : null;
                $dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
                $pu = $plat->pulso($e['nombre_base_datos']);

                $porque = [];
                if ($dias !== null && $dias < 0)  $porque[] = 'venció hace ' . abs($dias) . ' días';
                elseif ($dias !== null && $dias < 15) $porque[] = 'vence en ' . $dias . ' días';
                if ($pu === null)                 $porque[] = 'su base no responde';
                elseif ($pu['esquema'] < \LibertyFin\Servicio\Migraciones::VERSION)
                                                  $porque[] = 'esquema v' . $pu['esquema'];
                if ($pu && (int)$pu['cajas'] > 0 && !empty($pu['ultima'])
                    && strtotime($pu['ultima']) < strtotime('-2 days'))
                                                  $porque[] = 'caja abierta sin ventas hace días';

                if ($porque) {
                    $r[] = ['id' => $e['id'], 'nombre' => $e['nombre_empresa'],
                            'porque' => $porque];
                }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alertas: ' . $e->getMessage());
        }
        return array_slice($r, 0, 8);
    }

    /**
     * Apaga o enciende una sección o un método en UNA empresa.
     *
     * Se hace desde la ficha y no desde Mantenimiento porque ahí sí se
     * sabe de qué empresa se habla. Antes Mantenimiento las apagaba en
     * la empresa de la sesión, que para un rol de plataforma no existe.
     */
    public function alternarAjuste()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $base = $_POST['base'] ?? '';
        $emp  = (int)($_POST['empresa'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver($emp, 'Base no válida.', 'error');
        }
        try {
            $db  = Conexion::de($base);
            $cfg = new \LibertyFin\Datos\ConfigRepo($db);
            $que = $_POST['que'] ?? '';
            $k   = $_POST['clave'] ?? '';

            if ($que === 'seccion')      $cfg->alternarSeccion($k);
            elseif ($que === 'metodo')   $cfg->alternarMetodo($k);
            else $this->volver($emp, 'Eso no se puede cambiar.', 'error');

            // La bitácora va en la base de ESA empresa: es su historial,
            // y ahí es donde su administrador va a buscar por qué
            // desapareció una sección.
            \LibertyFin\Servicio\Auditoria::anota('seccion.alternar',
                $que . ' · ' . $k, null, 'alternado por soporte', $db);

            $this->volver($emp, 'Listo. Su gente lo ve al recargar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($emp, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ajuste empresa: ' . $e->getMessage());
            $this->volver($emp, 'No se pudo cambiar.', 'error');
        }
    }
}