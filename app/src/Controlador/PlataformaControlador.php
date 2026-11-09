<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * Cuentas de plataforma y suspensión de empresas · solo superadmin.
 *
 * SOBRE BORRAR: UNA EMPRESA QUE OPERÓ NO SE BORRA
 *
 * Las razones, en orden:
 *
 *  1. Las ventas y las facturas hay que conservarlas cinco años. Borrar
 *     la base de una empresa destruye la única copia de eso.
 *  2. La bitácora existe para contestar "¿quién hizo esto?". Si se puede
 *     borrar, deja de contestarlo justo cuando alguien tiene motivo para
 *     querer que no conteste.
 *  3. Un botón de borrar es irreversible con un solo clic, y el error
 *     que evita —ver un renglón de más— no se parece en tamaño al que
 *     provoca.
 *
 * Para eso está SUSPENDER: la empresa no entra, sus datos quedan enteros,
 * y se puede deshacer. Resuelve los casos reales —dejó de pagar, se fue,
 * hay una disputa— sin destruir nada.
 *
 * LA EXCEPCIÓN: LA QUE NUNCA OPERÓ
 *
 * Los tres motivos de arriba hablan de una empresa con movimientos. Una
 * creada por equivocación, o creada bien pero cuyo correo de bienvenida
 * nunca llegó y se volvió a dar de alta, no tiene ventas que conservar ni
 * bitácora que contestar nada: no es un registro, es un renglón de más.
 * Y dejarla ahí tampoco es gratis —aparece en las listas, se migra, se
 * respalda, y alguien un día intenta ayudar a un cliente que no existe.
 *
 * Así que se puede eliminar, con tres candados: el sistema CUENTA los
 * movimientos y se niega si hay alguno (no basta con que alguien asegure
 * que no se usó), hay que escribir el nombre de la empresa, y la BASE DE
 * DATOS NO SE BORRA: queda huérfana en el hosting y se da de baja a mano.
 * Ver Servicio\AccesosEmpresa.
 *
 * Si alguna vez hay que borrar una que sí operó, por una ley de
 * protección de datos, eso es un procedimiento con respaldo previo y dos
 * personas, no un botón.
 */
final class PlataformaControlador
{
    public function index()
    {
        $principal = $this->principal();
        $repo = new AutenticacionRepo($principal);
        $plat = new \LibertyFin\Datos\PlataformaRepo($principal);

        Plantilla::pagina('plataforma/index', [
            'titulo'    => 'Cuentas de plataforma',
            'icono'     => 'cliente',
            'subtitulo' => 'Superadministración',
            'usuarios'  => $repo->listaPlataforma(),
            'empresas'  => $plat->empresas(),
            'roles'     => array_filter(Permisos::rolesQuePuedeAsignar(),
                              function ($r) { return ($r['nivel'] ?? '') === 'plataforma'; }),
            'editando'  => \LibertyFin\Http\Peticion::entero('editar'),
            'global'    => (new \LibertyFin\Datos\AjustesPlataformaRepo($principal))->estado(),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardarUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $repo = new AutenticacionRepo($principal);
        $repo->asegurarPlataforma();

        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $user   = strtolower(trim($_POST['username'] ?? ''));
        $email  = trim($_POST['email'] ?? '');
        $rol    = $_POST['rol'] ?? '';

        if (mb_strlen($nombre) < 3)  $this->a('Escribe el nombre completo.', 'error');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $user)) {
            $this->a('El usuario son de 3 a 30 letras, números, punto, guion o guion bajo.', 'error');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->a('El correo no es válido.', 'error');
        }
        // Solo roles de plataforma, y solo los que este rol puede dar.
        if (!Permisos::puedeAsignar($rol) || !Permisos::esPlataforma($rol)) {
            $this->a('Ese rol no se puede crear aquí.', 'error');
        }

        try {
            $st = $principal->prepare(
                "SELECT id FROM usuarios_plataforma WHERE username = ?" . ($id ? " AND id <> ?" : ""));
            $st->execute($id ? [$user, $id] : [$user]);
            if ($st->fetch()) $this->a('Ya existe una cuenta con ese usuario.', 'error');

            if ($id) {
                $principal->prepare("
                    UPDATE usuarios_plataforma SET username=?, nombre=?, email=?, rol=? WHERE id=?
                ")->execute([$user, $nombre, $email ?: null, $rol, $id]);
                Auditoria::anota('usuario.editar', 'plataforma · ' . $nombre, null, $rol, $principal);
                $this->a('Cuenta actualizada.', 'ok');
            }

            $clave = self::clave();
            $principal->prepare("
                INSERT INTO usuarios_plataforma (username, password, nombre, email, rol, activo, creado_en)
                VALUES (?,?,?,?,?,1,NOW())
            ")->execute([$user, password_hash($clave, PASSWORD_DEFAULT), $nombre, $email ?: null, $rol]);
            Auditoria::anota('usuario.crear', 'plataforma · ' . $nombre, null, $rol, $principal);

            $this->a('Cuenta creada. Usuario ' . $user . ' · contraseña ' . $clave
                . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] usuario plataforma: ' . $e->getMessage());
            $this->a('No se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    /** Activa o desactiva una cuenta. Nunca se borra. */
    public function alternarUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
            $this->a('No puedes desactivarte a ti mismo.', 'error');
        }
        try {
            // Y no puede quedar la plataforma sin un superadministrador
            // activo: sería un sistema al que nadie puede entrar a
            // arreglarlo, y arreglarlo requeriría entrar.
            $st = $principal->prepare("SELECT rol, activo FROM usuarios_plataforma WHERE id = ?");
            $st->execute([$id]);
            $u = $st->fetch();
            if (!$u) $this->a('Esa cuenta no existe.', 'error');

            if ($u['rol'] === 'superadmin' && $u['activo']) {
                $n = (int)$principal->query("
                    SELECT COUNT(*) FROM usuarios_plataforma
                    WHERE rol = 'superadmin' AND activo = 1")->fetchColumn();
                if ($n <= 1) {
                    $this->a('Es el único superadministrador activo. '
                           . 'Crea otro antes de desactivar este.', 'error');
                }
            }
            $principal->prepare("UPDATE usuarios_plataforma SET activo = 1 - activo WHERE id = ?")
                      ->execute([$id]);
            Auditoria::anota('usuario.alternar', 'plataforma #' . $id,
                $u['activo'] ? 'activo' : 'inactivo', $u['activo'] ? 'inactivo' : 'activo', $principal);
            $this->a('Cuenta ' . ($u['activo'] ? 'desactivada' : 'activada') . '.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar plataforma: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }

    public function claveUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        try {
            $clave = self::clave();
            $principal->prepare("UPDATE usuarios_plataforma SET password = ? WHERE id = ?")
                      ->execute([password_hash($clave, PASSWORD_DEFAULT), (int)($_POST['id'] ?? 0)]);
            Auditoria::anota('usuario.clave', 'plataforma #' . (int)($_POST['id'] ?? 0),
                null, null, $principal);
            $this->a('Contraseña nueva: ' . $clave . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] clave plataforma: ' . $e->getMessage());
            $this->a('No se pudo restablecer.', 'error');
        }
    }

    /**
     * Suspende o reactiva una empresa.
     *
     * Suspender NO borra: la base queda entera, sus ventas y su bitácora
     * siguen ahí, y se puede deshacer con otro clic. Lo único que cambia
     * es que su gente no entra.
     */
    public function suspenderEmpresa()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        try {
            $id = (int)($_POST['id'] ?? 0);
            $st = $principal->prepare("SELECT nombre_empresa, activo FROM empresas WHERE id = ?");
            $st->execute([$id]);
            $e = $st->fetch();
            if (!$e) $this->a('Esa empresa no existe.', 'error');

            $principal->prepare("UPDATE empresas SET activo = 1 - activo WHERE id = ?")->execute([$id]);
            Auditoria::anota('empresa.datos', $e['nombre_empresa'],
                $e['activo'] ? 'activa' : 'suspendida',
                $e['activo'] ? 'suspendida' : 'activa', $principal);
            $this->a($e['nombre_empresa'] . ' quedó '
                . ($e['activo'] ? 'suspendida. Sus datos siguen enteros.' : 'activa otra vez.'), 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] suspender: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }

    /**
     * Contraseña nueva para el administrador de una empresa, y correo.
     *
     * Lo que resuelve: la empresa está bien creada pero su gente no entra
     * porque el correo de bienvenida nunca llegó —se fue a no deseado, el
     * proveedor bloqueó la cuenta, lo borraron. La contraseña original no
     * se puede recuperar porque está guardada como hash, así que se genera
     * otra. La anterior deja de servir, que además es lo que conviene: si
     * viajó en un correo perdido, no se sabe quién más lo vio.
     */
    public function accesosEmpresa()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        try {
            $principal = $this->principal();
            $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                 . ($_SERVER['HTTP_HOST'] ?? 'libertyfin.com.mx') . '/login';
            $r = (new \LibertyFin\Servicio\AccesosEmpresa($principal))
                 ->regenerar((int)($_POST['id'] ?? 0), $url);

            Auditoria::anota('empresa.accesos', $r['empresa'],
                'contraseña anterior', 'contraseña nueva para ' . $r['usuario'], $principal);

            // La contraseña se enseña SIEMPRE, salga o no el correo. Si se
            // enseñara solo cuando el correo falla, quien aprueba se
            // quedaría sin nada que dictar el día que el correo diga que
            // salió y acabe en no deseado — que es exactamente lo que pasó.
            $this->a($r['empresa'] . ' · usuario ' . $r['usuario']
                . ' · contraseña ' . $r['clave'] . ' — anótala, no se vuelve a mostrar. '
                . ($r['correo_ok']
                   ? 'También se la mandamos a ' . $r['email'] . '.'
                   : 'EL CORREO NO SALIÓ (' . $r['correo_error'] . '): entrégasela tú.'), 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] accesos empresa: ' . $e->getMessage());
            $this->a('No se pudieron regenerar los accesos: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Elimina una empresa que nunca operó.
     *
     * Las guardas —contar movimientos, exigir el nombre escrito, no tocar
     * la base de datos— viven en el servicio, no aquí: así valen igual
     * desde esta pantalla que desde la terminal.
     */
    public function eliminarEmpresa()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        try {
            $principal = $this->principal();
            $r = (new \LibertyFin\Servicio\AccesosEmpresa($principal))
                 ->eliminar((int)($_POST['id'] ?? 0), $_POST['confirmacion'] ?? '');
            $this->a($r['empresa'] . ' se eliminó. Su base ' . $r['base']
                . ' sigue en el hosting y no estorba; si quieres el espacio, '
                . 'bórrala en cPanel → Bases de datos MySQL.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] eliminar empresa: ' . $e->getMessage());
            $this->a($e->getMessage(), 'error');
        }
    }

    /** Sin l, I, 1, O ni 0: estas claves se dictan por teléfono. */
    private static function clave($largo = 14)
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $r = '';
        for ($i = 0; $i < $largo; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
        return $r;
    }

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /plataforma'); exit;
    }

    /**
     * Apaga o enciende algo para TODAS las empresas.
     *
     * Apagar exige un motivo porque lo van a ver los afectados. Sin él,
     * lo único que sabrían es que una función desapareció.
     */
    public function alternarGlobal()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $clave = $_POST['clave'] ?? '';

        $validas = [];
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::SECCIONES as $k => $_) $validas[] = 'seccion.' . $k;
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::METODOS as $k => $_)   $validas[] = 'metodo.' . $k;
        if (!in_array($clave, $validas, true)) $this->a('Eso no se puede apagar.', 'error');

        try {
            $ahora = (new \LibertyFin\Datos\AjustesPlataformaRepo($principal))->alternar(
                $clave, $_POST['nota'] ?? '', $_SESSION['usuario_nombre'] ?? '');
            // La sesión de quien lo cambió se actualiza de inmediato; las
            // demás lo toman al volver a entrar.
            $_SESSION['lf_global'][$clave] = $ahora;
            Auditoria::anota('seccion.alternar', 'global · ' . $clave,
                $ahora ? 'apagado' : 'encendido', $ahora ? 'encendido' : 'apagado', $principal);
            $this->a($ahora
                ? 'Encendido para todas las empresas.'
                : 'Apagado para todas. Las que lo tenían encendido dejan de verlo al recargar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] global: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }
}