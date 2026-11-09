<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\CrearEmpresa;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Avisos;
use LibertyFin\Vista\Plantilla;

/**
 * Mantenimiento · para el rol de soporte.
 *
 * Dos cosas: apagar secciones que una empresa no usa, y ver el
 * diagnóstico de su base sin tener que pedir accesos ni abrir un
 * cliente de MySQL.
 *
 * Soporte NO mueve dinero. Puede mirar todo y cambiar qué se ve, pero
 * no cobra, no cancela pagos ni asigna comisiones. Si además pudiera,
 * no habría forma de saber si un descuadre lo causó la empresa o quien
 * vino a ayudar.
 */
final class MantenimientoControlador
{
    public function index()
    {
        // Un rol de plataforma no tiene base de empresa. Las secciones
        // apagables y el diagnóstico son POR empresa: se miran desde la
        // ficha de cada una, no aquí.
        $db   = !empty($_SESSION['empresa_db']) ? Conexion::de($_SESSION['empresa_db']) : null;
        $repo = $db ? new ConfigRepo($db) : null;

        // Quien solo valida documentos no necesita el diagnóstico, ni las
        // secciones, ni el alta de empresas: cargarlos sería trabajo y
        // superficie de más para alguien que viene a otra cosa.
        $todo = Permisos::puede('diagnostico') && $repo !== null;

        Plantilla::pagina('mantenimiento/index', [
            'todo'         => $todo,
            'titulo'       => 'Mantenimiento',
            'icono'        => 'alerta',
            'subtitulo'    => $_SESSION['empresa_nombre'] ?? '',
            'secciones'    => $todo ? $repo->secciones() : [],
            'diagnostico'  => $todo ? $repo->diagnostico() : [],
            'integraciones'=> Permisos::puede('diagnostico') ? Integraciones::estado() : [],
            'roles'        => Permisos::ROLES,
            'esquema'      => $todo ? [
                'actual'   => Migraciones::versionDe($db),
                'ultima'   => Migraciones::VERSION,
                'que_hace' => Migraciones::DESCRIPCIONES,
            ] : ['actual'=>0,'ultima'=>0,'que_hace'=>[]],
            'empresas'     => $todo ? $this->estadoDeLasEmpresas() : [],
            'porRevisar'   => $this->documentosPorRevisar(),
            'pagosPlan'    => $this->pagosPorRevisar(),
            'solicitudes'  => Permisos::puede('alta.empresas') ? $this->solicitudes() : [],
            'puedeAlta'    => Permisos::puede('alta.empresas'),
            'correoListo'  => Avisos::activos(),
            'sinEmpresa'   => $repo === null,
            'correoPrueba' => $_SESSION['lf_correo_prueba'] ?? null,
            // El resultado del último «Poner todas al día», con el error
            // de cada base si lo hubo. Se lee una vez y se borra.
            'migDetalle'   => $_SESSION['lf_mig_detalle'] ?? null,
            'miCorreo'     => $_SESSION['usuario_email'] ?? '',
            'altaLista'    => Integraciones::activa('cpanel'),
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_mig_detalle']);
    }

    public function secciones()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('Las secciones se apagan desde la ficha de cada empresa.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            (new ConfigRepo($db))->alternarSeccion($_POST['seccion'] ?? '');
            Auditoria::anota('seccion.alternar', (string)($_POST['seccion'] ?? ''), null, 'alternada');
            $this->volver('Sección actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] mantenimiento: ' . $e->getMessage());
            $this->volver('No se pudo cambiar la sección.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /mantenimiento'); exit;
    }

    /**
     * En qué versión de esquema está cada empresa.
     *
     * Solo lee: abre cada base, pregunta su versión y cierra. Si una no
     * responde se reporta como inalcanzable en vez de tumbar la pantalla.
     */
    private function estadoDeLasEmpresas()
    {
        $r = [];
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            foreach ((new AutenticacionRepo($principal))->empresas() as $e) {
                $base = $e['nombre_base_datos'];
                $fila = ['nombre' => $e['nombre_empresa'], 'base' => $base,
                         'activo' => !empty($e['activo']), 'version' => null, 'error' => null];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
                    $fila['error'] = 'nombre de base inválido';
                } else {
                    try { $fila['version'] = Migraciones::versionDe(Conexion::de($base)); }
                    catch (\Throwable $ex) { $fila['error'] = 'no responde'; }
                }
                $r[] = $fila;
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] estado de empresas: ' . $e->getMessage());
        }
        return $r;
    }

    /** Pone al día todas las bases que estén atrasadas. */
    public function migrar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        $hechas = 0; $fallaron = 0; $detalle = [];
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            foreach ((new AutenticacionRepo($principal))->empresas() as $e) {
                $base   = $e['nombre_base_datos'];
                $quien  = $e['nombre_empresa'] ?: $base;
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
                    $fallaron++;
                    $detalle[] = ['empresa' => $quien, 'base' => (string)$base, 'de' => null,
                                  'a' => null, 'error' => 'El nombre de la base tiene caracteres raros.'];
                    continue;
                }
                try {
                    $db    = Conexion::de($base);
                    $antes = Migraciones::versionDe($db);
                    if (Migraciones::alDia($db)) {
                        $detalle[] = ['empresa' => $quien, 'base' => $base, 'de' => $antes,
                                      'a' => $antes, 'error' => ''];
                        continue;
                    }
                    Migraciones::aplicar($db);
                    $ahora = Migraciones::versionDe($db);
                    $falla = Migraciones::fallo();
                    if (Migraciones::alDia($db)) {
                        $hechas++;
                        $detalle[] = ['empresa' => $quien, 'base' => $base, 'de' => $antes,
                                      'a' => $ahora, 'error' => ''];
                    } else {
                        $fallaron++;
                        // Aquí está lo que antes solo iba al log: en qué
                        // migración se atoró y qué dijo MySQL.
                        $detalle[] = ['empresa' => $quien, 'base' => $base, 'de' => $antes,
                            'a' => $ahora,
                            'error' => $falla
                                ? 'Se atoró en la migración ' . $falla['version'] . ': ' . $falla['mensaje']
                                : 'Quedó en la versión ' . $ahora . ' sin decir por qué.'];
                    }
                } catch (\Throwable $ex) {
                    error_log('[LibertyFin] migrar ' . $base . ': ' . $ex->getMessage());
                    $fallaron++;
                    $detalle[] = ['empresa' => $quien, 'base' => $base, 'de' => null,
                                  'a' => null, 'error' => $ex->getMessage()];
                }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migrar: ' . $e->getMessage());
            $this->volver('No se pudo leer la lista de empresas: ' . $e->getMessage(), 'error');
        }

        // Se guarda para pintarlo al volver. Es de una sola lectura: no
        // tiene caso que el resultado de hace media hora siga en pantalla.
        $_SESSION['lf_mig_detalle'] = $detalle;

        if (!$hechas && !$fallaron) {
            $this->volver('No había ninguna base atrasada. Si esperabas un cambio, '
                . 'revisa que el servidor ya tenga el Migraciones.php nuevo: '
                . 'el código de aquí va en la versión ' . Migraciones::VERSION . '.', 'ok');
        }
        $this->volver($hechas . ' empresa' . ($hechas==1?'':'s') . ' al día'
            . ($fallaron ? ', ' . $fallaron . ' con problema (abajo dice por qué).' : '.'),
            $fallaron ? 'error' : 'ok');
    }

    /**
     * Documentos esperando revisión, de TODAS las empresas.
     *
     * Soporte no entra empresa por empresa a buscarlos: si hubiera que
     * hacerlo, nadie los revisaría y quedarían en "pendiente" para
     * siempre — que es exactamente lo que pasaba antes de esta pantalla.
     */
    private function documentosPorRevisar()
    {
        $r = [];
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            foreach ((new AutenticacionRepo($principal))->empresas() as $e) {
                $base = $e['nombre_base_datos'];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) continue;
                try {
                    foreach ((new CuentaRepo(Conexion::de($base)))->porRevisar() as $d) {
                        $d['empresa'] = $e['nombre_empresa'];
                        $d['base']    = $base;
                        $r[] = $d;
                    }
                } catch (\Throwable $ex) { /* una base caída no tumba la bandeja */ }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] bandeja: ' . $e->getMessage());
        }
        // Lo más viejo primero: es lo que lleva más tiempo esperando.
        usort($r, function ($a, $b) { return (int)$b['horas'] - (int)$a['horas']; });
        return $r;
    }

    /** Pagos de plan con comprobante esperando, de todas las empresas. */
    private function pagosPorRevisar()
    {
        if (!Permisos::puede('revisar.docs')) return [];
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            return (new \LibertyFin\Datos\PlanRepo($principal))->porRevisar();
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pagos de plan: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Aprueba o rechaza el comprobante de un plan. Al aprobar, el plan de
     * la empresa cambia y su vencimiento se extiende (ver PlanRepo).
     */
    public function revisarPago()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $decision  = $_POST['decision'] ?? '';
            $pago = (new \LibertyFin\Datos\PlanRepo($principal))->resolver(
                (int)($_POST['id'] ?? 0), $decision, $_POST['motivo'] ?? '',
                $_SESSION['usuario_id'] ?? 0);
            Auditoria::anota('plan.revisar', $pago['referencia'] ?? '', 'en revisión',
                $decision . ($decision === 'aprobado' ? ' · vence ' . ($pago['vence_nuevo'] ?? '') : ''),
                $principal);
            // Sin aviso, la empresa se entera solo si vuelve a entrar a
            // Plan. Si el correo falla el pago ya quedó resuelto: solo se
            // dice que no se mandó, para avisar a mano.
            $aprobado = $decision === 'aprobado';
            $enviado = false;
            if (!empty($pago['email_admin'])) {
                $enviado = Avisos::pagoPlanRevisado(
                    $pago['email_admin'], $pago['nombre_contacto'] ?? '', $pago['nombre_plan'],
                    $aprobado,
                    $aprobado ? date('d/m/Y', strtotime($pago['vence_nuevo'])) : ($_POST['motivo'] ?? ''),
                    (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                        . ($_SERVER['HTTP_HOST'] ?? '') . '/cuenta?t=plan');
            }
            $nota = $enviado ? ' Se avisó por correo.'
                  : (Avisos::activos() ? ' No se pudo mandar el correo: avísale tú.'
                                       : ' El correo no está configurado: avísale tú.');
            $this->volver(($aprobado
                ? 'Pago aprobado. El plan ' . $pago['nombre_plan'] . ' vence el '
                    . date('d/m/Y', strtotime($pago['vence_nuevo'])) . '.'
                : 'Pago rechazado. La empresa verá el motivo.') . $nota, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] revisar pago: ' . $e->getMessage());
            $this->volver('No se pudo registrar la revisión del pago.', 'error');
        }
    }

    public function revisar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        $base = $_POST['base'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver('Base no válida.', 'error');
        }
        try {
            $dbEmp = Conexion::de($base);
            $tipo = (new CuentaRepo($dbEmp))->revisar(
                (int)($_POST['id'] ?? 0), $_POST['decision'] ?? '',
                $_POST['motivo'] ?? '', $_SESSION['usuario_id'] ?? 0);
            // Se escribe en la base de ESA empresa, no en la de quien revisa:
            // la bitácora tiene que quedar donde el cliente pueda verla.
            Auditoria::anota('doc.revisar', $tipo, 'pendiente',
                ($_POST['decision'] ?? '') . ' · ' . trim($_POST['motivo'] ?? ''), $dbEmp);

            // Rechazar sin avisar es igual que no revisar: el negocio
            // sigue esperando sin saber que tiene que corregir algo.
            if (($_POST['decision'] ?? '') === 'rechazado') {
                $c = (new \LibertyFin\Datos\CuentaRepo($dbEmp))->comercio();
                $correo = $c['titular_correo'] ?? '';
                if ($correo) {
                    Avisos::documentoRechazado($correo,
                        \LibertyFin\Datos\CuentaRepo::DOCUMENTOS[$tipo][0] ?? $tipo,
                        $_POST['motivo'] ?? '',
                        (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                            . ($_SERVER['HTTP_HOST'] ?? '') . '/cuenta?t=documentos');
                }
            }
            $this->volver(($_POST['decision'] === 'aprobado' ? 'Aprobado' : 'Rechazado')
                . ': ' . $tipo . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] revisar: ' . $e->getMessage());
            $this->volver('No se pudo registrar la revisión.', 'error');
        }
    }

    private function solicitudes()
    {
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            return (new CrearEmpresa($principal))->pendientes();
        } catch (\Throwable $e) {
            error_log('[LibertyFin] solicitudes: ' . $e->getMessage());
            return [];
        }
    }

    /** Aprueba una solicitud y crea la empresa. */
    public function aprobarEmpresa()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $r = (new CrearEmpresa($principal))->aprobar(
                (int)($_POST['id'] ?? 0), dirname(__DIR__, 2), $_SESSION['usuario_id'] ?? 0);
            Auditoria::anota('empresa.alta', $r['base'], null, 'creada', $principal);

            // El correo es lo único que le dice al cliente que ya puede
            // entrar. Si falla, la empresa quedó creada igual y el aviso
            // se lo da quien aprobó, a mano.
            $correoOk = Avisos::cuentaCreada(
                $r['email'] ?? '', $r['contacto'] ?? '', $r['empresa'] ?? '',
                $r['usuario'], $r['clave'],
                (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                    . ($_SERVER['HTTP_HOST'] ?? 'libertyfin.com.mx') . '/login');
            // La contraseña se muestra UNA vez. No se guarda en claro en
            // ningún lado: quien aprueba la entrega y se acabó.
            $this->volver('Empresa creada. Base ' . $r['base']
                . ' · usuario ' . $r['usuario']
                . ' · contraseña ' . $r['clave']
                . ' — anótala, no se vuelve a mostrar.'
                . ($correoOk ? ' Ya le mandamos el correo.'
                             : ' EL CORREO NO SALIÓ: entrégasela tú.'), 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aprobar empresa: ' . $e->getMessage());
            $this->volver('No se pudo crear la empresa: ' . $e->getMessage(), 'error');
        }
    }

    public function rechazarEmpresa()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $sol = (new CrearEmpresa($principal))->rechazar(
                (int)($_POST['id'] ?? 0), $_POST['motivo'] ?? '', $_SESSION['usuario_id'] ?? 0);
            if (is_array($sol) && !empty($sol['email_admin'])) {
                Avisos::solicitudRechazada($sol['email_admin'], $sol['nombre_contacto'],
                    $_POST['motivo'] ?? '');
            }
            $this->volver('Solicitud rechazada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] rechazar empresa: ' . $e->getMessage());
            $this->volver('No se pudo rechazar.', 'error');
        }
    }
}