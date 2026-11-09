<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\EmpresaRepo;
use LibertyFin\Datos\PlanRepo;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class UsuariosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new UsuarioRepo($db);
        $this->soloAdmin();

        $editar = Peticion::entero('editar');
        Plantilla::pagina('usuarios/index', [
            'titulo'     => 'Usuarios',
            'icono'      => 'cliente',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            // Cuántos usuarios activos permite el plan y cuántos hay.
            'cupo'       => $this->cupo($repo),
            'usuarios'   => $repo->todos_(),
            'sucursales' => $repo->sucursales(),
            'editando'   => $editar ? $repo->uno_($editar) : null,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();

        $repo = new UsuarioRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) {
                $antes = $repo->porId($id);
                $repo->actualizar($id, $_POST);
                $m = 'Usuario actualizado.';
                // El cambio de ROL va aparte: es el único de esta pantalla
                // que cambia lo que esa persona puede hacer.
                if ($antes && ($antes['rol'] ?? '') !== ($_POST['rol'] ?? '')) {
                    Auditoria::anota('usuario.rol', $antes['nombre'],
                        $antes['rol'], $_POST['rol'] ?? '');
                } else {
                    Auditoria::anota('usuario.editar', $antes['nombre'] ?? ('usuario ' . $id));
                }
            } else {
                // El plan incluye N usuarios activos: uno más no se da de alta.
                $this->revisarCupo($repo);
                $repo->crear($_POST);
                $m = 'Usuario dado de alta.';
                Auditoria::anota('usuario.crear', trim($_POST['nombre'] ?? ''),
                    null, $_POST['rol'] ?? '');
            }
            $this->volver('/usuarios', $m, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar usuario: ' . $e->getMessage());
            // Un error de base de datos aquí casi siempre es de esquema, y
            // el mensaje genérico obliga a ir a buscar el log del servidor.
            $this->volver('/usuarios',
                'No se pudo guardar el usuario: ' . $e->getMessage(), 'error');
        }
    }

    /** Un administrador le pone contraseña nueva a otro. */
    public function restablecer()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $n = (new UsuarioRepo($db))->restablecerClave(
                (int)($_POST['id'] ?? 0), $_POST['clave'] ?? '');
            // La contraseña NO se registra, ni la vieja ni la nueva. Lo que
            // importa es quién la cambió y a quién; guardarla convertiría la
            // bitácora en el peor lugar donde buscar contraseñas.
            Auditoria::anota('usuario.clave', $n);
            $this->volver('/usuarios',
                'Contraseña de ' . $n . ' restablecida. Dísela en persona, no por escrito.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] restablecer: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo restablecer la contraseña.', 'error');
        }
    }

    public function alternar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $repo = new UsuarioRepo($db);
            // Reactivar a alguien también ocupa un lugar del plan.
            $u = $repo->uno_((int)($_POST['id'] ?? 0));
            if ($u && empty($u['activo'])) $this->revisarCupo($repo);
            $a = $repo->alternar(
                (int)($_POST['id'] ?? 0), $_SESSION['usuario_id'] ?? 0);
            Auditoria::anota('usuario.alternar', 'usuario ' . (int)($_POST['id'] ?? 0),
                $a ? 'inactivo' : 'activo', $a ? 'activo' : 'inactivo');
            $this->volver('/usuarios', $a ? 'Usuario activado.'
                : 'Usuario desactivado. Ya no puede entrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar usuario: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo cambiar el estado.', 'error');
        }
    }

    // ── Mi cuenta · sin rol de administrador ──

    const PESTANAS = [
        'perfil'    => 'Mi perfil',
        'plan'      => 'Plan',
        'fiscales'  => 'Datos fiscales',
        'comercio'  => 'Formulario de alta',
        'documentos'=> 'Documentos',
    ];

    public function miCuenta()
    {
        // Sin empresa no hay plan, ni datos fiscales, ni documentos: esas
        // pestañas son de una empresa y esta cuenta no pertenece a
        // ninguna. Le queda su perfil, que es lo que venía a ver.
        if (empty($_SESSION['empresa_db'])) { $this->miCuentaPlataforma(); return; }
        $db   = Conexion::de($_SESSION['empresa_db']);
        // Un cajero solo ve su perfil: lo fiscal y el alta de comercio
        // comprometen a la empresa entera.
        $suyas = \LibertyFin\Dominio\Permisos::puede('editar.empresa')
               ? self::PESTANAS : ['perfil' => self::PESTANAS['perfil']];
        $p = Peticion::opcion('t', array_keys($suyas), 'perfil');
        $foto = (new UsuarioRepo($db))->foto($_SESSION['usuario_id'] ?? 0);
        $_SESSION['lf_foto'] = $foto;

        $datos = [
            'titulo'    => 'Mi cuenta',
            'icono'     => 'cliente',
            'subtitulo' => $suyas[$p],
            'pestana'   => $p,
            'pestanas'  => $suyas,
            'foto'      => $foto,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'empresa'   => null, 'fiscales' => [], 'comercio' => [],
            'documentos'=> [], 'estadoDocs' => [],
            'catalogo'  => null, 'pagosPlan' => [], 'reciente' => null,
        ];

        $cuenta = new CuentaRepo($db);
        $cfg    = new ConfigRepo($db);

        // El estado de la documentación se muestra en TODAS las pestañas:
        // es lo que bloquea poder cobrar de verdad, y esconderlo en una
        // sola hace que se olvide.
        $datos['estadoDocs'] = $cuenta->estadoDocumentacion();

        if ($p === 'plan') {
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
                $datos['empresa'] = (new EmpresaRepo($principal))->uno($_SESSION['empresa_id'] ?? 0);

                // Contratar o renovar: el catálogo sale de config/planes.php
                // y los pagos de la base principal. Sin catálogo, la
                // pestaña sigue como antes (plan actual y "escríbenos").
                $datos['catalogo']  = PlanRepo::catalogo();
                $datos['pagosPlan'] = $datos['catalogo']
                    ? (new PlanRepo($principal))->deEmpresa($_SESSION['empresa_id'] ?? 0) : [];
            } catch (\Throwable $e) {
                error_log('[LibertyFin] plan: ' . $e->getMessage());
            }

            // ─────────────────────────────────────────────────────
            // COBRO EN LÍNEA RECIÉN GENERADO
            //
            // solicitarPlan() deja la liga / CLABE / código de barras
            // en `$_SESSION['lf_liga']` y redirige aquí. Sin esta
            // lectura, la pestaña Plan entra al `if (!empty($reciente))`
            // con null, cae al último `else` de la vista y el usuario
            // solo ve el aviso de texto — sin la liga, sin la CLABE,
            // sin nada accionable.
            //
            // La liga se conserva hasta 24 h para que el polling del
            // modal de pago confirmado siga funcionando si el usuario
            // recarga mientras espera. Cuando estadoPlan() confirma el
            // pago, la borra desde ahí: en la siguiente carga ya no
            // hace falta.
            // ─────────────────────────────────────────────────────
            $datos['reciente'] = $_SESSION['lf_liga'] ?? null;
            if (!empty($_SESSION['lf_liga']['creado_en'])
                && (time() - (int)$_SESSION['lf_liga']['creado_en']) > 86400) {
                unset($_SESSION['lf_liga']);
                $datos['reciente'] = null;
            }
        }
        if ($p === 'fiscales') {
            foreach (EmpresaRepo::FISCALES as $c) $datos['fiscales'][$c] = $cfg->valorDe('fiscal.' . $c, '');
        }
        if ($p === 'comercio')   $datos['comercio'] = $cuenta->comercio();
        if ($p === 'documentos') $datos['documentos'] = $cuenta->documentos();

        Plantilla::pagina('usuarios/cuenta', $datos);
        unset($_SESSION['lf_aviso']);
    }

    /** Datos fiscales. Viven en la base de la empresa. */
    public function guardarFiscales()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=fiscales');

        $rfc = mb_strtoupper(trim($_POST['rfc_fiscal'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            $this->volver('/cuenta?t=fiscales', 'El RFC no tiene el formato correcto.', 'error');
        }
        $cp = preg_replace('/\D/', '', (string)($_POST['cp_fiscal'] ?? ''));
        if ($cp !== '' && strlen($cp) !== 5) {
            $this->volver('/cuenta?t=fiscales', 'El código postal son 5 dígitos.', 'error');
        }
        $reg = trim($_POST['regimen_sat'] ?? '');
        if ($reg !== '' && !isset(CuentaRepo::REGIMENES[$reg])) {
            $this->volver('/cuenta?t=fiscales', 'Ese régimen fiscal no existe.', 'error');
        }

        try {
            $cfg = new ConfigRepo($db);
            $cfg->guardar('fiscal.tipo_persona', trim($_POST['tipo_persona'] ?? ''));
            $cfg->guardar('fiscal.rfc_fiscal',   $rfc);
            $cfg->guardar('fiscal.cp_fiscal',    $cp);
            $cfg->guardar('fiscal.razon_social', trim($_POST['razon_social'] ?? ''));
            $cfg->guardar('fiscal.regimen_sat',  $reg);
            $this->volver('/cuenta?t=fiscales', 'Datos fiscales guardados.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] fiscales: ' . $e->getMessage());
            $this->volver('/cuenta?t=fiscales', 'No se pudieron guardar.', 'error');
        }
    }

    /** El alta de comercio para procesar pagos. */
    public function guardarComercio()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=comercio');
        try {
            (new CuentaRepo($db))->guardarComercio($_POST, $_SESSION['usuario_id'] ?? 0);
            $this->volver('/cuenta?t=comercio', 'Datos de comercio guardados.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=comercio', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] comercio: ' . $e->getMessage());
            $this->volver('/cuenta?t=comercio', 'No se pudieron guardar.', 'error');
        }
    }

    /** La empresa elige un plan para pagar. */
    public function solicitarPlan()
    {
        if (empty($_SESSION['empresa_id'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta?t=plan');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $clave   = (string)($_POST['plan'] ?? '');
            $periodo = ($_POST['periodo'] ?? '') === 'anual' ? 'anual' : 'mensual';
            $repo    = new PlanRepo($principal);

            $repo->solicitar((int)$_SESSION['empresa_id'], $clave, $periodo);

            // ─────────────────────────────────────────────────────
            // POR QUÉ SE LEE DE LA BASE Y NO DEL RETORNO
            //
            // `solicitar` crea el pago pero no lo devuelve: en el
            // código original se llamaba sin asignar, así que
            // devuelve void. Antes se intentaba usar su retorno y,
            // como era null, el bloque de la liga nunca corría y el
            // usuario acababa viendo el aviso manual aunque hubiera
            // pedido pagar con tarjeta.
            //
            // Se lee el pago más reciente que siga en `por_pagar`:
            // es el que se acaba de crear.
            // ─────────────────────────────────────────────────────
            $pago = null;
            foreach ($repo->deEmpresa((int)$_SESSION['empresa_id']) as $p) {
                if (($p['estado'] ?? '') !== 'por_pagar') continue;
                if ($pago === null || (int)$p['id'] > (int)$pago['id']) $pago = $p;
            }

            // ¿Pidió una forma de pago en línea?
            $forma = self::formaEnLinea($_POST['como_paga'] ?? '');
            $aviso = 'Listo. Haz la transferencia con la referencia que aparece abajo y sube tu comprobante.';
            $tipo  = 'ok';

            if ($forma !== '' && $pago && !empty($pago['id'])) {
                $g = $this->generarLigaPlan($principal, $repo, $pago, $forma);
                if ($g) {
                    // Se deja la liga en la sesión: la pestaña Plan la
                    // enseña al volver. Mismo patrón que usa Caja con
                    // `$_SESSION['lf_liga']`.
                    //
                    // `liga_id` es el id de la fila en `ligas`: es el que
                    // necesita el botón "Ver comprobante" de la vista
                    // (ruta `/ligas/<id>/documento`) y el polling del
                    // modal de pago confirmado.
                    //
                    // `creado_en` marca la antigüedad: miCuenta() deja
                    // caducar la liga a las 24 h para no arrastrar un
                    // modal de un pago viejo.
                    $_SESSION['lf_liga'] = [
                        'pago_id'    => (int)$pago['id'],
                        'liga_id'    => $g['liga_id'] ?? null,
                        'creado_en'  => time(),
                        'metodo'     => $forma,
                        'monto'      => (float)$pago['monto'],
                        'descripcion'=> $pago['nombre_plan'] ?? '',
                        'referencia' => $g['referencia'] ?? '',
                        'liga'       => $g['liga']     ?? null,
                        'clabe'      => $g['clabe']    ?? null,
                        'barras'     => $g['barras']   ?? null,
                        'imagen'     => $g['imagen']   ?? null,
                        'vence'      => $g['vence']    ?? null,
                        'pruebas'    => $g['pruebas']  ?? false,
                        'empresa'    => $_SESSION['empresa_nombre'] ?? '',
                    ];
                    $aviso = 'Listo. ' . self::textoDePago($forma);
                } else {
                    // La solicitud SÍ quedó. Decir "no se pudo" dejaría
                    // creer que no hay nada y volvería a intentarlo.
                    $aviso = 'Registramos tu solicitud, pero no se pudo generar el cobro automático. '
                           . 'Usa la referencia de abajo para transferir, o inténtalo de nuevo.';
                    $tipo  = 'error';
                }
            }

            Auditoria::anota('plan.solicitar', $clave . ' · ' . $periodo, null, $forma ?: 'por pagar');
            $this->volver('/cuenta?t=plan', $aviso, $tipo);
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=plan', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] solicitar plan: ' . $e->getMessage());
            $this->volver('/cuenta?t=plan', 'No se pudo registrar la solicitud.', 'error');
        }
    }

    private function generarLigaPlan($principal, PlanRepo $repo, array $pago, $forma)
    {
        $monto = round((float)($pago['monto'] ?? 0), 2);
        if ($monto <= 0.01) return null;

        $api = new \LibertyFin\Servicio\LigaPago(\LibertyFin\Servicio\Integraciones::de('spei'));

        // LA SEMILLA SALE DE LigaPago::semilla(), CON PREFIJO "8".
        //
        // Antes se armaba aquí a mano:
        //
        //     '8' . str_pad((string)$pago['id'], 7, '0', STR_PAD_LEFT) . date('ymdHi')
        //
        // Son 18 caracteres. `generar()` recorta el Id a los últimos 10
        // —el proveedor pide Numérico(10)— y esos últimos diez eran SOLO
        // la fecha: el id del pago se perdía en el recorte. Dos pagos en
        // el mismo minuto compartían Id, y la Reference, recortada a 15
        // de esos mismos 18, arrastraba la cola del id.
        //
        // El proveedor contestaba el código 15 —"El formato del ID es
        // incorrecto"— y el mapa de mensajes lo traducía como "este
        // comercio no está vinculado", que mandaba a revisar el
        // `BusinessID`, que estaba bien.
        $semilla = \LibertyFin\Servicio\LigaPago::semilla((int)$pago['id'], '8');

        $descripcion = 'Plan ' . ($pago['nombre_plan'] ?? '')
                     . ' · ' . (int)($pago['meses'] ?? 1) . ' mes'
                     . ((int)($pago['meses'] ?? 1) === 1 ? '' : 'es');

        $g = $api->generar([
            'monto'       => $monto,
            'metodo'      => $forma,
            'descripcion' => $descripcion,
            'referencia'  => $semilla,
            'id'          => $semilla,
            'cliente'     => $_SESSION['empresa_nombre'] ?? 'Empresa',
            'correo'      => $_SESSION['usuario_email'] ?? '',
        ]);
        if (!$g) {
            error_log('[LibertyFin] plan liga: ' . $api->error());
            return null;
        }

        try {
            // PlanRepo guarda los datos de la liga en el pago: así la
            // pestaña Plan los enseña sin volver a llamar al proveedor.
            // Si el repo no soporta el método, el apunte del directorio
            // de cobros (abajo) ya deja la referencia rastreable.
            if (method_exists($repo, 'guardarLiga')) {
                $repo->guardarLiga((int)$pago['id'], [
                    'referencia' => $g['referencia'] ?? $semilla,
                    'liga'       => $g['liga']     ?? null,
                    'clabe'      => $g['clabe']    ?? null,
                    'barras'     => $g['barras']   ?? null,
                    'imagen'     => $g['imagen']   ?? null,
                    'formato'    => $g['formato']  ?? null,
                    'vence'      => $g['vence']    ?? null,
                    'metodo'     => $forma,
                    'pruebas'    => $g['pruebas']  ?? false,
                ]);
            }

            // ─────────────────────────────────────────────────────
            // REGISTRO EN EL DIRECTORIO DE LIGAS
            //
            // Mismo patrón que CajaControlador::conLiga: el cobro del
            // plan entra a `ligas` para que aparezca junto a los de la
            // Caja, con la misma trazabilidad (estado, avisos del
            // proveedor, reintentos, documento descargable).
            //
            // `venta_id` va en null: este cobro no cuelga de una venta,
            // cuelga de un pago de plan. Quien quiera el detalle lo
            // tiene en PlanRepo::deEmpresa(), referenciado por el
            // mismo `referencia` que aquí se guarda.
            //
            // EL ID DE LA LIGA SE DEVUELVE: es el que necesita el
            // polling del modal de pago confirmado, y el botón "Ver
            // comprobante" de la pestaña Plan.
            // ─────────────────────────────────────────────────────
            $ligaId = (int)(new \LibertyFin\Datos\LigaRepo($principal))->crear([
                'referencia'     => $g['referencia'] ?? $semilla,
                'venta_id'       => null,
                'cliente'        => $_SESSION['empresa_nombre'] ?? 'Empresa',
                'monto'          => $monto,
                'metodo'         => $forma,
                'descripcion'    => $descripcion,
                'liga'           => $g['liga']     ?? null,
                'clabe'          => $g['clabe']    ?? null,
                'barras'         => $g['barras']   ?? null,
                'remota'         => $g['remota']   ?? null,
                'imagen'         => $g['imagen']   ?? null,
                'formato'        => $g['formato']  ?? null,
                'vence'          => $g['vence']    ?? null,
                'pruebas'        => $g['pruebas']  ?? false,
                'usuario_id'     => $_SESSION['usuario_id']     ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
                'usuario_correo' => $_SESSION['usuario_email'] ?? null,
            ]);

            // Sin esto, el aviso del proveedor ("ya pagó") llega sin
            // sesión y sin empresa: no sabría en qué base buscar el cobro
            // para marcar el plan como pagado. Van todos los números con
            // que puede avisar (CLABE, su referencia, la nuestra).
            \LibertyFin\Servicio\Cobros::apuntarCobro(
                $g + ['referencia' => $semilla], $forma);

            // Se devuelve el id para que solicitarPlan() lo meta en la
            // sesión y la vista pueda armar el enlace al comprobante.
            $g['liga_id'] = $ligaId;
            return $g;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar liga plan: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ¿Ya se pagó un cobro de plan? Lo consulta el modal de la pestaña
     * Plan igual que el de Caja, pero las dos patas —liga y pago— viven
     * en la base PRINCIPAL.
     *
     * Cuando el proveedor confirma el cargo:
     *   · aprueba el pago del plan (PlanRepo::aprobarPorPago), que mueve
     *     el plan y el vencimiento dentro de una transacción;
     *   · marca la liga como pagada en el directorio de cobros.
     *
     * Con SPEI y tienda no hay nada que consultar: el proveedor avisa
     * llamando a /pagadetodo/pago-clabe y /pagadetodo/pago-referencia,
     * que ya dejan la liga marcada. Aquí se relee la fila y ya.
     */
    public function estadoPlan($id)
    {
        if (empty($_SESSION['empresa_id'])) {
            $this->json(['ok' => false, 'error' => 'Sin empresa'], 403);
        }

        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $ligaRepo  = new \LibertyFin\Datos\LigaRepo($principal);

        $l = $ligaRepo->porId((int)$id);
        if (!$l) $this->json(['ok' => false, 'error' => 'No existe'], 404);

        // ─────────────────────────────────────────────────────
        // LA LIGA ES DE LA EMPRESA QUE LA MIRÓ
        //
        // El id de la liga viaja en la URL; sin esta comprobación,
        // cualquier sesión podría consultar (y con eso aprobar) el
        // cobro de otra empresa con solo cambiar el número. Se
        // compara contra el nombre de la empresa en sesión, que es
        // lo que se guardó al crear la liga desde la pestaña Plan.
        // ─────────────────────────────────────────────────────
        $mia = (string)($_SESSION['empresa_nombre'] ?? '');
        if ($mia !== '' && ($l['cliente_nombre'] ?? '') !== $mia) {
            $this->json(['ok' => false, 'error' => 'No es tuya'], 403);
        }

        // Ya resuelto en una vuelta anterior del modal. Se enriquece
        // con el plan y el vencimiento para que el modal tenga algo
        // que mostrar: en esa vuelta no hay `$aprobado`, hay que
        // releerlo de `pagos_plan` y `empresas`.
        if ($l['estado'] === 'pagada') {
            unset($_SESSION['lf_liga']);   // el modal ya lo va a mostrar
            $this->json([
                'ok'     => true,
                'pagado' => true,
                'estado' => 'pagada',
                'plan'   => $this->datosPlanDeLiga($principal, $l),
            ]);
        }
        if ($l['estado'] === 'por_aprobar') {
            unset($_SESSION['lf_liga']);
            $this->json([
                'ok'     => true,
                'pagado' => true,
                'estado' => 'por_aprobar',
                'plan'   => null,
            ]);
        }

        // Solo la tarjeta se consulta. SPEI y tienda avisan por webhook.
        $consultable = \LibertyFin\Servicio\LigaPago::consultable($l['metodo']);

        // No le pegamos al proveedor en cada vuelta: cada diez segundos
        // basta y evita castigar su servicio.
        $hace = $l['revisado_en'] ? (time() - strtotime($l['revisado_en'])) : 999;
        if ($consultable && $hace >= 10) {
            $cfg = \LibertyFin\Servicio\Integraciones::de('spei');
            if ($cfg) {
                $api = new \LibertyFin\Servicio\LigaPago($cfg);
                $r = $api->estado($l['referencia'], $l['metodo'], $l['remota'] ?? '');

                if ($r && !empty($r['pagado'])) {
                    // ─────────────────────────────────────────────
                    // AQUÍ ESTÁ LA DIFERENCIA CON CAJA
                    //
                    // En Caja el pago se abona a una venta
                    // (RegistrarPago::abonar). Aquí no hay venta: hay
                    // un pago de plan en `pagos_plan` que debe pasar a
                    // `aprobado` y mover el plan y el vencimiento de la
                    // empresa. Todo en la base principal, que es donde
                    // viven los planes y los pagos de plan.
                    // ─────────────────────────────────────────────
                    $aprobado = null;
                    try {
                        $aprobado = (new PlanRepo($principal))
                            ->aprobarPorPago($l['referencia'], $_SESSION['usuario_id'] ?? null);
                    } catch (\Throwable $e) {
                        error_log('[LibertyFin] aprobar plan por liga: ' . $e->getMessage());
                    }

                    // Sin pago_id: ese campo apunta al abono de una venta y
                    // aquí no hay venta, hay un pago de plan (ya aprobado
                    // arriba). Omitirlo es un error fatal en PHP 8.
                    $ligaRepo->marcarPagada($l['id'], null);

                    // El modal ya lo va a mostrar; en la siguiente carga
                    // no hace falta.
                    unset($_SESSION['lf_liga']);

                    $this->json([
                        'ok'      => true,
                        'pagado'  => true,
                        'estado'  => 'pagada',
                        // Si `aprobarPorPago` devolvió null (porque el
                        // webhook ya lo había aprobado), se relee de la
                        // base para no dejar el modal sin datos.
                        'plan'    => $aprobado ? [
                            'nombre' => $aprobado['nombre_plan'] ?? '',
                            'vence'  => $aprobado['vence_nuevo'] ?? null,
                        ] : $this->datosPlanDeLiga($principal, $l),
                    ]);
                }

                // No pagó todavía: se sella la consulta para no repetirla
                // antes de diez segundos.
                $ligaRepo->marcarRevisada($l['id']);
            }
        }

        // `consulta` le dice al modal de qué va la espera: si estamos
        // preguntando, o si toca esperar a que el proveedor avise. Con
        // eso el usuario sabe si vale la pena quedarse mirando.
        $this->json([
            'ok'       => true,
            'pagado'   => false,
            'estado'   => 'pendiente',
            'consulta' => $consultable,
        ]);
    }

    /**
     * El plan y el vencimiento que quedaron tras aprobar un pago de plan.
     *
     * Se usa cuando el modal pregunta por una liga ya pagada: en esa
     * vuelta no hay `$aprobado` (eso solo existe la primera vez), así que
     * hay que releerlo de `pagos_plan` y de `empresas`. El vencimiento
     * real vive en `empresas.fecha_vencimiento`, no en el pago.
     *
     * Devuelve null si no hay nada que mostrar: el modal se abre igual,
     * solo sin las dos líneas del resumen.
     */
    private function datosPlanDeLiga($principal, array $liga)
    {
        $ref = trim((string)($liga['referencia'] ?? ''));
        if ($ref === '') return null;
        try {
            $st = $principal->prepare(
                "SELECT nombre_plan FROM pagos_plan
                  WHERE referencia = ? AND estado = 'aprobado'
                  ORDER BY id DESC LIMIT 1");
            $st->execute([$ref]);
            $nombre = (string)$st->fetchColumn();
            if ($nombre === '') return null;

            $st = $principal->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
            $st->execute([(int)($_SESSION['empresa_id'] ?? 0)]);
            $vence = $st->fetchColumn();

            return ['nombre' => $nombre, 'vence' => $vence ?: null];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] datos plan de liga: ' . $e->getMessage());
            return null;
        }
    }

    /** Mismo mapa que usa la Caja: '' = no eligió pagar en línea. */
    private static function formaEnLinea($como)
    {
        $mapa = ['_tarjeta' => 'tarjeta', '_spei' => 'spei', '_tienda' => 'efectivo'];
        return $mapa[$como] ?? '';
    }

    private static function textoDePago($forma)
    {
        return [
            'tarjeta'  => 'Pasa la tarjeta o comparte la liga: el cargo se procesa al momento.',
            'spei'     => 'Transfiere con la CLABE que aparece abajo. El plan se activa al validar el pago.',
            'efectivo' => 'Muestra la referencia en tienda. El plan se activa al validar el pago.',
        ][$forma] ?? 'Puedes pagar con la referencia de abajo.';
    }

    /** Sube el comprobante de la transferencia de un plan. */
    public function comprobantePlan()
    {
        if (empty($_SESSION['empresa_id'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta?t=plan');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $ruta = \LibertyFin\Servicio\Archivos::documento($_FILES['archivo'] ?? [], 'pagoplan');
            $pago = (new PlanRepo($principal))->comprobante(
                (int)($_POST['id'] ?? 0), (int)$_SESSION['empresa_id'], $ruta);
            Auditoria::anota('plan.comprobante', $pago['referencia'] ?? '', null, 'en revisión');
            $this->volver('/cuenta?t=plan',
                'Recibimos tu comprobante. Lo revisamos en un día hábil y te avisamos aquí.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=plan', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] comprobante plan: ' . $e->getMessage());
            $this->volver('/cuenta?t=plan', 'No se pudo subir el comprobante.', 'error');
        }
    }

    /** Sube un documento para revisión. */
    public function subirDocumento()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=documentos');
        $tipo = $_POST['tipo'] ?? '';
        try {
            $meta = [
                'nombre' => $_FILES['archivo']['name'] ?? null,
                'bytes'  => $_FILES['archivo']['size'] ?? null,
            ];
            $ruta = \LibertyFin\Servicio\Archivos::documento($_FILES['archivo'] ?? [], 'doc');
            $meta['mime'] = \LibertyFin\Servicio\Archivos::ultimoMime();
            (new CuentaRepo($db))->guardarDocumento($tipo, $ruta, $meta, $_SESSION['usuario_id'] ?? 0);
            $this->volver('/cuenta?t=documentos',
                'Documento subido. Un administrador de LibertyFin lo revisa en 24 a 72 horas.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=documentos', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] documento: ' . $e->getMessage());
            $this->volver('/cuenta?t=documentos', 'No se pudo subir el documento.', 'error');
        }
    }

    public function cambiarClave()
    {
        // Soporte, validación y superadmin no tienen empresa: su cuenta vive
        // en la base principal y ahí se cambia.
        $plataforma = empty($_SESSION['empresa_db']) && !empty($_SESSION['plataforma']);
        if (empty($_SESSION['empresa_db']) && !$plataforma) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta');
        try {
            if ($plataforma) {
                $this->cuentasPlataforma()->cambiarClavePlataforma(
                    (int)($_SESSION['usuario_id'] ?? 0),
                    $_POST['actual'] ?? '', $_POST['nueva'] ?? '');
            } else {
                (new UsuarioRepo(Conexion::de($_SESSION['empresa_db'])))->cambiarClave(
                    (int)($_SESSION['usuario_id'] ?? 0),
                    $_POST['actual'] ?? '', $_POST['nueva'] ?? '');
            }

            // Se cierra la sesión a propósito: si alguien cambió la
            // contraseña porque sospecha que se la sabían, dejar la sesión
            // viva no sirve de nada.
            Autenticar::salir();
            session_start();
            $_SESSION['lf_error'] = 'Contraseña cambiada. Entra de nuevo.';
            header('Location: /login'); exit;
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cambiarClave: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo cambiar la contraseña.', 'error');
        }
    }

    private function soloAdmin()
    {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            http_response_code(403);
            Plantilla::pagina('errores/404', ['titulo'=>'Sin permiso','icono'=>'alerta','subtitulo'=>'']);
            exit;
        }
    }

    /**
     * Usuarios activos contra los que incluye el plan. max = 0 es «sin
     * tope» (periodo de prueba o plan que no está en el catálogo).
     */
    private function cupo(UsuarioRepo $repo)
    {
        $emp = (int)($_SESSION['empresa_id'] ?? 0);
        try {
            $pr = new PlanRepo(Conexion::de($GLOBALS['lf_bd_principal'] ?? ''));
            return [
                'activos' => $repo->activos(),
                'max'     => $pr->usuariosPermitidos($emp),
                'plan'    => $pr->nombrePlan($emp),
            ];
        } catch (\Throwable $e) {
            // Sin poder leer el plan no se bloquea a nadie: la pantalla de
            // usuarios no debe caerse por eso.
            error_log('[LibertyFin] cupo de usuarios: ' . $e->getMessage());
            return ['activos' => 0, 'max' => 0, 'plan' => ''];
        }
    }

    /** Lanza el aviso si ya no cabe otro usuario activo en el plan. */
    private function revisarCupo(UsuarioRepo $repo)
    {
        $c = $this->cupo($repo);
        if ($c['max'] > 0 && $c['activos'] >= $c['max']) {
            throw new \InvalidArgumentException(sprintf(
                'Tu plan %s incluye %d usuario%s activo%s y ya %s. '
                . 'Desactiva a alguien que ya no lo use o cambia de plan en Mi cuenta → Plan.',
                $c['plan'] !== '' ? $c['plan'] : 'actual',
                $c['max'], $c['max'] === 1 ? '' : 's', $c['max'] === 1 ? '' : 's',
                $c['activos'] === 1 ? 'tienes 1' : 'tienes ' . $c['activos']));
        }
    }

    private function token($destino = '/usuarios')
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver($destino, 'No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($destino, $texto, $tipo)
    {
        // Subida sin recargar: se contesta en JSON y NO se deja aviso en la
        // sesión, que si no saldría de nuevo en la siguiente carga.
        if (($_SERVER['HTTP_X_LF_AJAX'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $tipo === 'ok', 'texto' => $texto]);
            exit;
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $destino); exit;
    }

    /**
     * Contesta JSON y termina.
     *
     * Vive aquí porque las respuestas del modal de plan (estadoPlan)
     * las lee JavaScript, no un navegador que recarga. Si el mismo
     * método tiene que poder contestar HTML y JSON según quién
     * pregunte, esta es la puerta JSON; para HTML está volver().
     */
    private function json(array $cuerpo, $codigo = 200)
    {
        http_response_code((int)$codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Foto de perfil. Cada quien la suya, sin pedir permiso a nadie. */
    public function guardarFoto()
    {
        // Una cuenta de plataforma guarda su foto en la base principal.
        $plataforma = empty($_SESSION['empresa_db']) && !empty($_SESSION['plataforma']);
        if (empty($_SESSION['empresa_db']) && !$plataforma) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta');
        $id = (int)($_SESSION['usuario_id'] ?? 0);
        if ($plataforma) {
            $cp = $this->cuentasPlataforma();
            $leer    = function () use ($cp, $id) { return $cp->fotoPlataforma($id); };
            $guardar = function ($ruta) use ($cp, $id) { return $cp->guardarFotoPlataforma($id, $ruta); };
        } else {
            $repo = new UsuarioRepo(Conexion::de($_SESSION['empresa_db']));
            $leer    = function () use ($repo, $id) { return $repo->foto($id); };
            $guardar = function ($ruta) use ($repo, $id) { return $repo->guardarFoto($id, $ruta); };
        }
        try {
            if (!empty($_POST['quitar'])) {
                $antes = $leer();
                $guardar('');
                unset($_SESSION['lf_foto']);
                if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
                $this->volver('/cuenta', 'Foto quitada.', 'ok');
            }
            $antes = $leer();
            $ruta  = \LibertyFin\Servicio\Archivos::imagen($_FILES['foto'] ?? [], 'perfil');
            $guardar($ruta);
            $_SESSION['lf_foto'] = $ruta;
            if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
            $this->volver('/cuenta', 'Foto actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] foto: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo guardar la foto.', 'error');
        }
    }

    /** Marca la guía como vista. De autoservicio: cada quien la suya. */
    public function guiaVista()
    {
        // Cerrada: deja de salir en esta sesión (layout la muestra mientras siga).
        unset($_SESSION['lf_mostrar_guia']);
        try {
            // Soporte no tiene empresa: la marca va en su propia cuenta.
            if (!empty($_SESSION['plataforma'])) {
                $this->cuentasPlataforma()->marcarGuiaPlataforma($_SESSION['usuario_id'] ?? 0);
            } else {
                $db = Conexion::de($_SESSION['empresa_db']);
                (new UsuarioRepo($db))->marcarGuia($_SESSION['usuario_id'] ?? 0);
            }
        } catch (\Throwable $e) { /* que no se marque es molesto, no grave */ }
        http_response_code(204);
        exit;
    }

    /** Volver a verla desde Mi cuenta. */
    public function verGuia()
    {
        $_SESSION['lf_mostrar_guia'] = true;
        header('Location: /panel'); exit;
    }

    /**
     * Tema y color de una cuenta de soporte. Llega de Mi cuenta (los dos)
     * o del botón de la barra (solo el tema): lo que no viene no se toca.
     * El color solo puede ser uno de los ya probados (Widget::COLORES).
     */
    public function apariencia()
    {
        $json = ($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1';
        $responder = function ($ok, $texto) use ($json) {
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => $ok, 'mensaje' => $texto]);
                exit;
            }
            $this->volver('/cuenta', $texto, $ok ? 'ok' : 'error');
        };
        if (empty($_SESSION['plataforma'])) $responder(false, 'Esta opción es de las cuentas de soporte.');
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $responder(false, 'No se pudo verificar el formulario.');
        }

        $tema = isset($_POST['tema']) ? (string)$_POST['tema'] : null;
        if ($tema !== null && !in_array($tema, \LibertyFin\Datos\AutenticacionRepo::TEMAS, true)) {
            $responder(false, 'Ese tema no existe.');
        }
        $color = isset($_POST['color']) ? strtolower(trim((string)$_POST['color'])) : null;
        if ($color !== null && $color !== '' && !isset(\LibertyFin\Vista\Widget::COLORES[$color])) {
            $responder(false, 'Elige uno de los colores de la lista.');
        }

        try {
            $this->cuentasPlataforma()->guardarApariencia((int)($_SESSION['usuario_id'] ?? 0), $tema, $color);
            if ($tema !== null) $_SESSION['lf_tema'] = $tema;
            if ($color !== null) {
                if ($color === '') unset($_SESSION['lf_marca_color']);
                else $_SESSION['lf_marca_color'] = $color;
            }
            $responder(true, 'Guardado. Se verá así en cualquier equipo donde entres.');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] apariencia: ' . $e->getMessage());
            $responder(false, 'No se pudo guardar.');
        }
    }

    public function fotoPublica()
    {
        $json = ($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1';
        $responder = function ($ok, $texto) use ($json) {
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => $ok, 'mensaje' => $texto]);
                exit;
            }
            $this->volver('/cuenta', $texto, $ok ? 'ok' : 'error');
        };
        if (empty($_SESSION['plataforma'])) $responder(false, 'Esta opción es de las cuentas de soporte.');
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $responder(false, 'No se pudo verificar el formulario.');
        }
        try {
            $si = !empty($_POST['publica']);
            $this->cuentasPlataforma()->guardarFotoPublica((int)($_SESSION['usuario_id'] ?? 0), $si);
            $responder(true, $si ? 'Los clientes verán tu foto en los tickets.'
                                 : 'Los clientes verán solo tu inicial.');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] foto pública: ' . $e->getMessage());
            $responder(false, 'No se pudo guardar.');
        }
    }

    /** Las cuentas de plataforma viven en la base principal. */
    private function cuentasPlataforma()
    {
        return new \LibertyFin\Datos\AutenticacionRepo(Conexion::de($GLOBALS['lf_bd_principal'] ?? ''));
    }

    /**
     * El comprobante imprimible de un cobro de plan en efectivo en tienda.
     * Mismo armado de plantilla que LigasControlador::documento() para que
     * la ficha se vea idéntica: una es el cobro del plan, la otra el de la
     * venta, pero las dos son "cómo pagar en tienda".
     */
    public function documentoPlan($id)
    {
        if (empty($_SESSION['empresa_id'])) {
            http_response_code(404);
            Plantilla::pagina('errores/404',
                ['titulo' => 'No existe', 'icono' => 'alerta', 'subtitulo' => '']);
            return;
        }

        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $l = (new \LibertyFin\Datos\LigaRepo($principal))->porId((int)$id);
        if (!$l) {
            http_response_code(404);
            Plantilla::pagina('errores/404',
                ['titulo' => 'No existe', 'icono' => 'alerta', 'subtitulo' => '']);
            return;
        }

        // La liga es de la empresa que la pidió. El nombre con el que se
        // creó se guardó en `cliente_nombre`. Sin esta comprobación,
        // cambiar el id en la URL mostraría el comprobante de otra empresa
        // —cobro de plan ajeno, con su referencia y su monto— con solo
        // probar números. Mismo criterio que estadoPlan().
        $mia = (string)($_SESSION['empresa_nombre'] ?? '');
        if ($mia !== '' && ($l['cliente_nombre'] ?? '') !== $mia) {
            http_response_code(403);
            Plantilla::pagina('errores/404',
                ['titulo' => 'Sin permiso', 'icono' => 'alerta', 'subtitulo' => '']);
            return;
        }

        $cfg = \LibertyFin\Servicio\Integraciones::de('spei') ?: [];

        // Mismo armado de tiendas que LigasControlador::tiendas(): la lista
        // depende del convenio del proveedor. Poner una fija manda gente a
        // una cadena donde la van a rechazar.
        $tiendas = array_filter(array_map('trim',
            explode(',', (string)($cfg['tiendas'] ?? ''))));
        if (!$tiendas) {
            $tiendas = ['OXXO', '7-Eleven', 'Farmacias Guadalajara',
                        'Farmacias Benavides', 'Circle K', 'Waldos',
                        'Del Sol', 'Woolworth'];
        }

        Plantilla::pagina('ligas/documento', [
            'titulo'   => 'Ficha de pago',
            'l'        => $l,
            'empresa'  => $_SESSION['empresa_nombre'] ?? 'LibertyFin',
            'convenio' => trim((string)($cfg['nombre_convenio'] ?? ''))
                          ?: ($_SESSION['empresa_nombre'] ?? 'LibertyFin'),
            'tiendas'  => $tiendas,
        ], 'layout-limpio');
    }

    /** Mi cuenta para un rol de plataforma: solo perfil y contraseña. */
    private function miCuentaPlataforma()
    {
        $foto = ''; $fotoPublica = false; $apariencia = null;
        if (!empty($_SESSION['plataforma'])) {
            try {
                $cp = $this->cuentasPlataforma();
                $foto = $cp->fotoPlataforma((int)($_SESSION['usuario_id'] ?? 0));
                $fotoPublica = $cp->fotoPublicaPlataforma((int)($_SESSION['usuario_id'] ?? 0));
                $apariencia = $cp->apariencia((int)($_SESSION['usuario_id'] ?? 0));
            } catch (\Throwable $e) { $foto = ''; }
        }
        Plantilla::pagina('usuarios/cuenta', [
            'titulo'    => 'Mi cuenta',
            'icono'     => 'cliente',
            'subtitulo' => 'Mi perfil',
            'pestana'   => 'perfil',
            'pestanas'  => ['perfil' => 'Mi perfil'],
            'foto'      => $foto,
            'fotoPublica' => $fotoPublica,
            'apariencia'  => $apariencia,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'empresa'   => null, 'fiscales' => [], 'comercio' => [], 'documentos' => [],
            'estadoDocs'=> ['estado' => 'aprobada', 'faltan' => [], 'aprobados' => 0, 'total' => 0],
            'reciente'  => null,
        ]);
        unset($_SESSION['lf_aviso']);
    }
}