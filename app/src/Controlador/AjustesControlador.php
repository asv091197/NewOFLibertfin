<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\AjustesRepo;
use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * Ajustes: sucursales, áreas, colaboradores y categorías en una sección
 * con pestañas.
 *
 * En el sistema anterior eran cuatro pantallas de entre 700 y 1,500
 * líneas. Son tablas chicas que se tocan una vez al mes y siempre juntas:
 * darles una pantalla completa a cada una solo obliga a navegar de más.
 */
final class AjustesControlador
{
    const PESTANAS = [
        'empresa'      => 'Empresa',
        'caja'         => 'Caja',
        'modulos'      => 'Módulos',
        'inventario'   => 'Inventario',
        'sucursales'   => 'Sucursales',
        'comisiones'   => 'Áreas y colaboradores',
        'categorias'   => 'Categorías',
        'api'          => 'API',
        // 'integraciones' => 'Integraciones',  // oculta a propósito; la vista se conserva
        // y vuelve con solo quitar el comentario.
    ];

    public function index($pestana = null)
    {
        $this->soloAdmin();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);

        $p = Peticion::opcion('t', array_keys(self::PESTANAS), 'empresa');
        $editar = Peticion::entero('editar');

        $datos = [
            'titulo'     => 'Ajustes',
            'icono'      => 'serv',
            'subtitulo'  => self::PESTANAS[$p],
            'pestana'    => $p,
            'pestanas'   => self::PESTANAS,
            'editar'     => $editar,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
            'sucursales' => [], 'areas' => [], 'colaboradores' => [], 'categorias' => [],
            'empresa' => null, 'integraciones' => [], 'caja' => [], 'inventario' => [],
            'llaves' => [], 'llaveNueva' => null, 'rolesApi' => [],
        ];
        if ($p === 'integraciones') {
            $datos['integraciones'] = \LibertyFin\Servicio\Integraciones::estado();
        }
        if ($p === 'empresa') {
            // Va en ConfigRepo, no en AjustesRepo: son repositorios
            // distintos y `$repo` aqui es el de ajustes.
            $datos['aprobacion'] = (new ConfigRepo($db))->modoAprobacion();
            $datos['modosAprobacion'] = \LibertyFin\Datos\ConfigRepo::APROBACION;
            $datos['hayLigas'] = \LibertyFin\Servicio\Integraciones::activa('spei');
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $datos['empresa'] = (new \LibertyFin\Datos\EmpresaRepo($principal))
                                ->uno($_SESSION['empresa_id'] ?? 0);
            $cfg = new \LibertyFin\Datos\ConfigRepo($db);
            $datos['marca'] = [
                'color' => $cfg->valorDe('marca.color', '#27ae60'),
                'logo'  => $cfg->valorDe('marca.logo', ''),
            ];
        }
        if ($p === 'caja') $datos['caja'] = (new ConfigRepo($db))->caja();
        if ($p === 'inventario') $datos['inventario'] = (new ConfigRepo($db))->inventario();
        if ($p === 'modulos') {
            $mod = new \LibertyFin\Servicio\Modulos(new ConfigRepo($db));
            $datos['modulos'] = [];
            foreach (\LibertyFin\Servicio\Modulos::CATALOGO as $k => $m) {
                $datos['modulos'][$k] = $m + [
                    'encendido' => $mod->encendido($k),
                    'enPlan'    => \LibertyFin\Servicio\Modulos::enPlan($k),
                    'planes'    => \LibertyFin\Servicio\Modulos::planesCon($k),
                ];
            }
        }
        if ($p === 'sucursales') $datos['sucursales'] = $repo->sucursales();
        if ($p === 'comisiones') {
            $datos['areas'] = $repo->areas();
            $datos['colaboradores'] = $repo->colaboradores();
        }
        if ($p === 'categorias') $datos['categorias'] = $repo->categorias();
        if ($p === 'api') {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $datos['llaves'] = (new \LibertyFin\Api\LlaveRepo($principal))->deEmpresa($_SESSION['empresa_id'] ?? 0);
            // La llave recién creada se enseña UNA vez: después solo queda su huella.
            $datos['llaveNueva'] = $_SESSION['lf_api_llave_nueva'] ?? null;
            unset($_SESSION['lf_api_llave_nueva']);
            foreach (\LibertyFin\Api\LlaveRepo::ROLES as $rol) {
                $datos['rolesApi'][$rol] = \LibertyFin\Dominio\Permisos::ROLES[$rol]['rotulo'];
            }
        }

        Plantilla::pagina('ajustes/index', $datos);
        unset($_SESSION['lf_aviso']);
    }

    /** Un solo guardar para las cuatro tablas: la diferencia es el `que`. */
    public function guardar()
    {
        $this->soloAdmin(); $this->token();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);
        $que  = $_POST['que'] ?? '';
        $id   = (int)($_POST['id'] ?? 0);

        // Caja: cómo arranca cada venta. Cada opción se guarda al elegirla.
        if ($que === 'caja') {
            try {
                $cfg = new ConfigRepo($db);
                $antes = $cfg->caja();
                $cfg->guardarCaja($_POST);
                $ahora = $cfg->caja();
                $cambio = array_diff_assoc($ahora, $antes);
                if ($cambio) {
                    \LibertyFin\Servicio\Auditoria::anota('ajustes.cambiar', 'Caja',
                        json_encode(array_intersect_key($antes, $cambio)), json_encode($cambio));
                }
                $this->volver('caja', 'Guardado.', 'ok');
            } catch (\InvalidArgumentException $e) {
                $this->volver('caja', $e->getMessage(), 'error');
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/caja: ' . $e->getMessage());
                $this->volver('caja', 'No se pudo guardar.', 'error');
            }
        }

        // Módulos: encender o apagar uno. Cambia el menú: se recarga.
        if ($que === 'modulo') {
            try {
                $clave = (string)($_POST['clave'] ?? '');
                $on = !empty($_POST['activo']);
                $cambios = (new \LibertyFin\Servicio\Modulos(new ConfigRepo($db)))->poner($clave, $on);
                if ($cambios) {
                    \LibertyFin\Servicio\Auditoria::anota('ajustes.modulo', implode(', ', $cambios),
                        $on ? 'apagado' : 'encendido', $on ? 'encendido' : 'apagado');
                }
                $nombre = \LibertyFin\Servicio\Modulos::CATALOGO[$clave]['nombre'] ?? 'Módulo';
                $extra = count($cambios) > 1 ? ' (también ' . implode(', ', array_map(function ($k) {
                    return \LibertyFin\Servicio\Modulos::CATALOGO[$k]['nombre']; }, array_slice($cambios, 1))) . ')' : '';
                // Sin recargar: el menú nuevo y cómo quedó cada módulo (encender
                // uno puede encender o apagar otros), y la página los pone.
                $mod = new \LibertyFin\Servicio\Modulos(new ConfigRepo($db));
                $estado = [];
                foreach (\LibertyFin\Servicio\Modulos::CATALOGO as $k => $_) $estado[$k] = $mod->encendido($k);
                $this->volver('modulos', $nombre . ($on ? ' encendido' : ' apagado') . $extra . '.', 'ok',
                              ['menu' => $this->menuHtml(), 'modulos' => $estado]);
            } catch (\InvalidArgumentException $e) {
                $this->volver('modulos', $e->getMessage(), 'error');
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/modulo: ' . $e->getMessage());
                $this->volver('modulos', 'No se pudo guardar.', 'error');
            }
        }

        // Inventario: encenderlo y qué hacer si no alcanzan. Igual que Caja.
        if ($que === 'inventario') {
            try {
                $cfg = new ConfigRepo($db);
                $antes = $cfg->inventario();
                $cfg->guardarInventario($_POST);
                $ahora = $cfg->inventario();
                $cambio = array_diff_assoc($ahora, $antes);
                if ($cambio) {
                    \LibertyFin\Servicio\Auditoria::anota('ajustes.cambiar', 'Inventario',
                        json_encode(array_intersect_key($antes, $cambio)), json_encode($cambio));
                }
                // Encenderlo o apagarlo cambia el menú: va el menú nuevo, sin recargar.
                $this->volver('inventario', 'Guardado.', 'ok', isset($cambio['activo']) ? ['menu' => $this->menuHtml()] : []);
            } catch (\InvalidArgumentException $e) {
                $this->volver('inventario', $e->getMessage(), 'error');
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/inventario: ' . $e->getMessage());
                $this->volver('inventario', 'No se pudo guardar.', 'error');
            }
        }

        $mapa = [
            'sucursal'    => ['guardarSucursal',    'sucursales', 'Sucursal'],
            'area'        => ['guardarArea',        'comisiones', 'Área'],
            'colaborador' => ['guardarColaborador', 'comisiones', 'Colaborador'],
            'categoria'   => ['guardarCategoria',   'categorias', 'Categoría'],
        ];
        // La empresa se guarda en DOS lugares: los datos fiscales en la base
        // principal y la marca en la de la empresa. Se hacen por separado a
        // propósito: si la principal no responde, el color y el logo deben
        // guardarse igual. Antes un solo try envolvía todo y un fallo de
        // conexión se llevaba los tres cambios con un mensaje genérico.
        if ($que === 'empresa') {
            $hecho = []; $fallo = [];

            // 1 · Datos fiscales, en la base principal
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
                (new \LibertyFin\Datos\EmpresaRepo($principal))
                    ->actualizar($_SESSION['empresa_id'] ?? 0, $_POST);
                $_SESSION['empresa_nombre'] = trim($_POST['nombre_empresa'] ?? '');
                $hecho[] = 'datos';
            } catch (\InvalidArgumentException $e) {
                $fallo[] = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/empresa datos: ' . $e->getMessage());
                $fallo[] = empty($GLOBALS['lf_bd_principal'])
                    ? 'Falta `bd.principal` en config/config.php: sin eso no se pueden guardar los datos fiscales'
                    : 'No se pudieron guardar los datos fiscales';
            }

            // 2 · Color de la marca, en la base de la empresa
            $color = trim($_POST['marca_color'] ?? '');
            if ($color !== '') {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $fallo[] = 'El color debe ser un hexadecimal de 6 dígitos';
                } else {
                    try {
                        (new \LibertyFin\Datos\ConfigRepo($db))->guardar('marca.color', $color);
                        $_SESSION['lf_marca_color'] = $color;
                        $hecho[] = 'color';
                    } catch (\Throwable $e) {
                        error_log('[LibertyFin] ajustes/color: ' . $e->getMessage());
                        $fallo[] = 'No se pudo guardar el color';
                    }
                }
            }

            // 3 · Logotipo
            try {
                $cfg = new \LibertyFin\Datos\ConfigRepo($db);
                if (!empty($_POST['quitar_logo'])) {
                    $antes = $cfg->valorDe('marca.logo', '');
                    $cfg->guardar('marca.logo', '');
                    unset($_SESSION['lf_marca_logo']);
                    if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
                    $hecho[] = 'logo quitado';
                } elseif (!empty($_FILES['logo']['name'])) {
                    $antes = $cfg->valorDe('marca.logo', '');
                    $ruta  = \LibertyFin\Servicio\Archivos::imagen($_FILES['logo'], 'logo');
                    $cfg->guardar('marca.logo', $ruta);
                    $_SESSION['lf_marca_logo'] = $ruta;
                    if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
                    $hecho[] = 'logotipo';
                }
            } catch (\InvalidArgumentException $e) {
                $fallo[] = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/logo: ' . $e->getMessage());
                $fallo[] = 'No se pudo guardar el logotipo: ' . $e->getMessage();
            }

            if ($fallo) {
                $this->volver('empresa',
                    ($hecho ? 'Se guardó ' . implode(' y ', $hecho) . '. Pero: ' : '')
                    . implode('. ', $fallo), 'error');
            }
            \LibertyFin\Servicio\Auditoria::anota('empresa.datos',
                $_SESSION['empresa_nombre'] ?? '', null, implode(', ', $hecho));
            $this->volver('empresa', 'Guardado: ' . implode(', ', $hecho ?: ['nada que cambiar']) . '.', 'ok');
        }

        if (!isset($mapa[$que])) $this->volver('sucursales', 'Petición no válida.', 'error');
        list($metodo, $pestana, $rotulo) = $mapa[$que];

        // Un cambio de catálogo: sucursales, áreas, colaboradores,
        // categorías. Se registra qué se tocó, no el contenido: el valor
        // nuevo ya está en su tabla y ahí se puede mirar.
        \LibertyFin\Servicio\Auditoria::anota('ajustes.cambiar',
            $rotulo . (empty($_POST['id']) ? ' nuevo' : ' #' . (int)$_POST['id']));

        try {
            $repo->$metodo($id, $_POST);
            $this->volver($pestana, $rotulo . ($id ? ' actualizada.' : ' dada de alta.'), 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($pestana, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ajustes/' . $que . ': ' . $e->getMessage());
            $this->volver($pestana, 'No se pudo guardar.', 'error');
        }
    }

    public function alternar()
    {
        $this->soloAdmin(); $this->token();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);
        $que  = $_POST['que'] ?? '';
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($que === 'sucursal')    { $repo->alternarSucursal($id);    $p = 'sucursales'; }
            elseif ($que === 'colaborador') { $repo->alternarColaborador($id); $p = 'comisiones'; }
            else $this->volver('sucursales', 'Petición no válida.', 'error');
            $this->volver($p, 'Estado cambiado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($que === 'sucursal' ? 'sucursales' : 'comisiones', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ajustes/alternar: ' . $e->getMessage());
            $this->volver('sucursales', 'No se pudo cambiar el estado.', 'error');
        }
    }

    /**
     * Crea una llave de la API.
     *
     * La llave completa se guarda en la sesión solo para enseñarla en la
     * siguiente pantalla, una vez. En la base queda su huella: si alguien
     * la pierde, no se recupera, se revoca y se crea otra.
     */
    public function crearLlave()
    {
        $this->soloAdmin(); $this->token();
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $rol = (string)($_POST['rol'] ?? '');
            $n = (new \LibertyFin\Api\LlaveRepo($principal))->crear($_SESSION['empresa_id'] ?? 0, $nombre, $rol,
                $_SESSION['usuario_id'] ?? null, $_SESSION['usuario_nombre'] ?? null);
            $_SESSION['lf_api_llave_nueva'] = ['token' => $n['token'], 'nombre' => $nombre, 'rol' => $rol];
            Auditoria::anota('ajustes.cambiar', 'Llave de la API «' . $nombre . '»', null,
                'creada · rol ' . $rol . ' · ' . $n['prefijo'] . '…');
            $this->volver('api', 'Llave creada. Cópiala ahora: no se vuelve a mostrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('api', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] crear llave de la API: ' . $e->getMessage());
            $this->volver('api', 'No se pudo crear la llave.', 'error');
        }
    }

    /** Revoca una llave: deja de servir al instante. No se puede deshacer. */
    public function revocarLlave()
    {
        $this->soloAdmin(); $this->token();
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $ok = (new \LibertyFin\Api\LlaveRepo($principal))
                ->revocar((int)($_POST['id'] ?? 0), $_SESSION['empresa_id'] ?? 0);
            if (!$ok) $this->volver('api', 'Esa llave no existe o ya estaba revocada.', 'error');
            Auditoria::anota('ajustes.cambiar', 'Llave de la API #' . (int)$_POST['id'], 'vigente', 'revocada');
            $this->volver('api', 'Llave revocada. Los sistemas que la usaban ya no pueden entrar.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] revocar llave de la API: ' . $e->getMessage());
            $this->volver('api', 'No se pudo revocar la llave.', 'error');
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

    private function token()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('sucursales', 'No se pudo verificar el formulario.', 'error');
        }
    }

    /**
     * Devuelve al usuario a su pestaña con un aviso.
     *
     * Si el formulario se envió por detrás —ver `data-guardar` en el
     * layout— se contesta JSON y nadie recarga nada. Elegir "los pagos
     * se aplican solos" no debería costar una página entera: se perdía
     * el scroll, la pestaña abierta y el sitio donde ibas leyendo para
     * cambiar una palabra.
     *
     * El redirect se queda para quien no tenga JavaScript. Es el mismo
     * método, la misma validación y el mismo mensaje: lo único que
     * cambia es el envoltorio.
     */
    /** @param bool $recargar  el cambio se ve fuera del formulario (el menú): recargar la página */
    /**
     * @param array|bool $extra  lo que viaja con la respuesta por detrás
     *                           (menu, modulos…); true = que la página se recargue
     */
    private function volver($pestana, $texto, $tipo, $extra = [])
    {
        if ($this->porDetras()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $tipo !== 'error', 'mensaje' => $texto, 'tipo' => $tipo, 'pestana' => $pestana,
                              'recargar' => $extra === true] + (is_array($extra) ? $extra : []),
                             JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ajustes?t=' . $pestana); exit;
    }

    /** El menú lateral ya con los cambios, para ponerlo sin recargar. */
    private function menuHtml()
    {
        $nivel = ob_get_level();
        try {
            ob_start();
            Plantilla::parcial('parciales/sidebar', ['activo' => 'serv']);
            return ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $nivel) ob_end_clean();
            error_log('[LibertyFin] menú para ajustes: ' . $e->getMessage());
            return null;
        }
    }

    /** ¿Lo pidió el navegador por detrás, sin recargar? */
    private function porDetras()
    {
        return !empty($_SERVER['HTTP_X_LF_JSON'])
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    }

    /**
     * Cambia si los pagos en linea se aplican solos o esperan aprobacion.
     *
     * OJO CON `volver()`: pide TRES argumentos y el primero es la
     * pestaña. Estas llamadas pasaban solo dos —texto y tipo— y PHP 8
     * tira ArgumentCountError en vez de rellenar con null, así que la
     * pantalla reventaba justo al intentar avisar de un error. La
     * pestaña de este ajuste es `empresa`, que es donde vive el
     * interruptor.
     */
    public function aprobacion()
    {
        $this->soloAdmin();
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('empresa', 'No se pudo verificar el formulario.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        try {
            $antes = (new ConfigRepo($db))->modoAprobacion();
            (new ConfigRepo($db))->fijarAprobacion($_POST['modo'] ?? 'auto');
            \LibertyFin\Servicio\Auditoria::anota('ajustes.cambiar',
                'aprobación de pagos', $antes, $_POST['modo'] ?? 'auto');
            $this->volver('empresa', ($_POST['modo'] ?? '') === 'revisar'
                ? 'Los pagos en línea van a esperar tu aprobación.'
                : 'Los pagos en línea se van a aplicar solos al confirmarse.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('empresa', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aprobación: ' . $e->getMessage());
            $this->volver('empresa', 'No se pudo cambiar el modo de aprobación.', 'error');
        }
    }
}
