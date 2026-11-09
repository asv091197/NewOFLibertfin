<?php
namespace LibertyFin\Datos;

/**
 * Usuarios de la empresa.
 *
 * La contraseña NUNCA sale de aquí. Ningún método la devuelve, ninguna
 * vista la recibe. Lo único que existe es cambiarla, y siempre pasa por
 * password_hash().
 */
final class UsuarioRepo extends Repo
{
    /**
     * Los roles salen de Dominio\Permisos, no de una lista aparte.
     * Tener dos listas es garantía de que un día alguien agrega un rol
     * en una y no en la otra.
     */
    const ROLES_FUENTE = \LibertyFin\Dominio\Permisos::class;
    const CLAVE_MINIMA = 8;

    public function todos_()
    {
        return $this->todos("
            SELECT u.id, u.username, u.nombre, u.email, u.rol, u.sucursal_id,
                   COALESCE(u.activo,1) AS activo,
                   s.nombre AS sucursal,
                   (SELECT COUNT(*) FROM ventas v WHERE v.usuario_id = u.id
                      AND v.estado <> 'cancelada') AS ventas
            FROM usuarios u
            LEFT JOIN sucursales s ON s.id = u.sucursal_id
            ORDER BY COALESCE(u.activo,1) DESC, u.nombre");
    }

    public function uno_($id)
    {
        return $this->uno("
            SELECT id, username, nombre, email, rol, sucursal_id, COALESCE(activo,1) AS activo
            FROM usuarios WHERE id = ?", [(int)$id]);
    }

    public function sucursales()
    {
        // La matriz primero: es la que se propone al dar de alta.
        return $this->todos("SELECT id, nombre FROM sucursales ORDER BY COALESCE(es_matriz,0) DESC, nombre");
    }

    /**
     * Requisitos de la contraseña.
     *
     * Ocho caracteres y que no sea el nombre de usuario. Deliberadamente
     * NO se exige mayúscula, número y símbolo: esa regla produce
     * "Empresa2026!" pegado en un post-it, que es peor que una frase larga
     * que la persona sí recuerda.
     */
    public static function revisarClave($clave, $username = '')
    {
        $clave = (string)$clave;
        if (mb_strlen($clave) < self::CLAVE_MINIMA) {
            throw new \InvalidArgumentException(
                'La contraseña necesita al menos ' . self::CLAVE_MINIMA . ' caracteres');
        }
        if ($username !== '' && mb_strtolower($clave) === mb_strtolower($username)) {
            throw new \InvalidArgumentException('La contraseña no puede ser el nombre de usuario');
        }
        $obvias = ['12345678','password','contrasena','qwertyui','admin123','11111111'];
        if (in_array(mb_strtolower($clave), $obvias, true)) {
            throw new \InvalidArgumentException('Esa contraseña es demasiado común');
        }
        return $clave;
    }

    private function limpiar(array $d, $idActual = null)
    {
        $username = mb_strtolower(trim($d['username'] ?? ''));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            throw new \InvalidArgumentException(
                'El usuario debe tener de 3 a 40 caracteres: letras, números, punto, guion o guion bajo');
        }
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');

        $rol = $d['rol'] ?? '';
        if (!isset(\LibertyFin\Dominio\Permisos::ROLES[$rol])) {
            throw new \InvalidArgumentException('Ese rol no existe');
        }
        // Se comprueba AQUÍ y no solo en el desplegable. Esconder una
        // opción del formulario no impide nada: el POST se escribe a
        // mano en diez segundos.
        if (!\LibertyFin\Dominio\Permisos::puedeAsignar($rol)) {
            throw new \InvalidArgumentException(
                'No puedes asignar el rol "' . \LibertyFin\Dominio\Permisos::rotulo($rol)
                . '". Tu rol solo puede crear: '
                . implode(', ', array_map(function ($r) { return $r['rotulo']; },
                    \LibertyFin\Dominio\Permisos::rolesQuePuedeAsignar())));
        }
        // La columna `rol` es un ENUM en el esquema original. MySQL NO
        // falla al escribir un valor fuera de la lista en modo relajado:
        // guarda cadena vacía. En modo estricto sí falla, pero con un
        // "Data truncated" que no le dice nada a nadie.
        //
        // Se comprueba antes y se explica qué hacer.
        if (!$this->rolCabe($rol)) {
            throw new \InvalidArgumentException(
                'La base de datos todavía no acepta el rol "' . $rol . '". '
                . 'Cierra sesión y vuelve a entrar: las migraciones se aplican al entrar. '
                . 'Si sigue igual, corre esto en MySQL: '
                . 'ALTER TABLE usuarios MODIFY COLUMN rol '
                . "ENUM('admin','cajero','inventario','soporte','superadmin','validador') "
                . "NOT NULL DEFAULT 'cajero';");
        }

        $email = trim($d['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }

        $sql = "SELECT id FROM usuarios WHERE username = ?";
        $p = [$username];
        if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe un usuario llamado ' . $username);
        }
        // `usuarios.sucursal_id` no admite vacío: sin sucursal elegida, la
        // matriz. Antes se mandaba NULL y en MySQL estricto el alta fallaba.
        $suc = (int)($d['sucursal_id'] ?? 0);
        if (!$suc || !$this->valor("SELECT id FROM sucursales WHERE id = ?", [$suc])) {
            $suc = (int)$this->valor("SELECT id FROM sucursales ORDER BY COALESCE(es_matriz,0) DESC, id LIMIT 1");
        }
        return [$username, $nombre, $email ?: null, $rol, $suc ?: null];
    }

    /** Los usuarios activos de la empresa: los que cuentan para el plan. */
    public function activos()
    {
        return (int)$this->valor("SELECT COUNT(*) FROM usuarios WHERE COALESCE(activo,1) = 1");
    }

    public function crear(array $d)
    {
        list($username, $nombre, $email, $rol, $suc) = $this->limpiar($d);
        $clave = self::revisarClave($d['clave'] ?? '', $username);
        $this->db->prepare("
            INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id)
            VALUES (?,?,?,?,?,?)
        ")->execute([$username, password_hash($clave, PASSWORD_DEFAULT),
                     $nombre, $email, $rol, $suc]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        list($username, $nombre, $email, $rol, $suc) = $this->limpiar($d, $id);
        $this->db->prepare("
            UPDATE usuarios SET username = ?, nombre = ?, email = ?, rol = ?, sucursal_id = ?
            WHERE id = ?
        ")->execute([$username, $nombre, $email, $rol, $suc, (int)$id]);
        return (int)$id;
    }

    /** Restablecer: lo hace un administrador, sin pedir la anterior. */
    public function restablecerClave($id, $clave)
    {
        $u = $this->uno_($id);
        if (!$u) throw new \InvalidArgumentException('Ese usuario no existe');
        self::revisarClave($clave, $u['username']);
        $this->db->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                 ->execute([password_hash($clave, PASSWORD_DEFAULT), (int)$id]);
        return $u['nombre'];
    }

    /** Cambiarla uno mismo: hay que saber la anterior. */
    public function cambiarClave($id, $actual, $nueva)
    {
        $hash = $this->valor("SELECT password FROM usuarios WHERE id = ?", [(int)$id]);
        if (!$hash || !password_verify((string)$actual, (string)$hash)) {
            throw new \InvalidArgumentException('La contraseña actual no es correcta');
        }
        $u = $this->uno_($id);
        self::revisarClave($nueva, $u['username']);
        if (password_verify((string)$nueva, (string)$hash)) {
            throw new \InvalidArgumentException('La nueva contraseña es igual a la anterior');
        }
        $this->db->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                 ->execute([password_hash($nueva, PASSWORD_DEFAULT), (int)$id]);
        return true;
    }

    /**
     * Activa o desactiva. Nunca se borra: las ventas apuntan al usuario
     * que las hizo, y perder eso es perder el rastro de quién cobró.
     */
    public function alternar($id, $yo)
    {
        if ((int)$id === (int)$yo) {
            throw new \InvalidArgumentException('No puedes desactivar tu propia cuenta');
        }
        $u = $this->uno_($id);
        if (!$u) throw new \InvalidArgumentException('Ese usuario no existe');

        // Sin administradores activos nadie puede volver a entrar a configurar.
        if ($u['rol'] === 'admin' && $u['activo']) {
            $otros = (int)$this->valor("
                SELECT COUNT(*) FROM usuarios
                WHERE rol = 'admin' AND COALESCE(activo,1) = 1 AND id <> ?", [(int)$id]);
            if ($otros === 0) {
                throw new \InvalidArgumentException(
                    'Es el único administrador activo. Nombra a otro antes de desactivarlo.');
            }
        }
        $this->db->prepare("UPDATE usuarios SET activo = 1 - COALESCE(activo,1) WHERE id = ?")
                 ->execute([(int)$id]);
        return (int)$this->valor("SELECT COALESCE(activo,1) FROM usuarios WHERE id = ?", [(int)$id]);
    }

    /**
     * ¿Existe la columna `foto`? Se pregunta una vez.
     * La agrega 14_fotos.sql; sin ella el sistema funciona igual.
     */
    private function tieneFoto()
    {
        static $t = null;
        if ($t !== null) return $t;
        try {
            $t = (bool)$this->valor("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'foto'");
        } catch (\Throwable $e) { $t = false; }
        return $t;
    }

    public function foto($id)
    {
        if (!$this->tieneFoto()) return '';
        return (string)$this->valor("SELECT COALESCE(foto,'') FROM usuarios WHERE id = ?", [(int)$id]);
    }

    public function guardarFoto($id, $ruta)
    {
        if (!$this->tieneFoto()) {
            throw new \InvalidArgumentException(
                'Falta correr 14_fotos.sql para poder guardar fotos de perfil');
        }
        $this->db->prepare("UPDATE usuarios SET foto = ? WHERE id = ?")
                 ->execute([$ruta ?: null, (int)$id]);
        return true;
    }

    /** ¿Este usuario ya vio la guía? Sin la columna, se asume que sí. */
    public function vioGuia($id)
    {
        try {
            $v = $this->valor("SELECT guia_vista_en FROM usuarios WHERE id = ?", [(int)$id]);
            return $v !== null && $v !== false;
        } catch (\Throwable $e) { return true; }
    }

    /**
     * Marca la guía como vista. Solo si sigue en NULL: así la fecha es la
     * de la PRIMERA vez que la cerró, no la de la última.
     */
    public function marcarGuia($id)
    {
        try {
            $this->db->prepare("
                UPDATE usuarios SET guia_vista_en = NOW()
                WHERE id = ? AND guia_vista_en IS NULL")->execute([(int)$id]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * ¿La columna `rol` acepta ese valor?
     *
     * Si es ENUM, se mira su lista. Si alguien ya la pasó a VARCHAR,
     * cabe cualquier cosa y no hay nada que comprobar.
     */
    private function rolCabe($rol)
    {
        static $tipo = null;
        if ($tipo === null) {
            try {
                $tipo = (string)$this->valor("
                    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                      AND COLUMN_NAME = 'rol'");
            } catch (\Throwable $e) { $tipo = ''; }
        }
        if (stripos($tipo, 'enum') !== 0) return true;   // no es ENUM: cabe
        return stripos($tipo, "'" . $rol . "'") !== false;
    }

    /**
     * Un usuario por su id.
     *
     * `Repo::uno()` es protegido y recibe SQL: no es un buscador por id.
     * Llamarlo desde un controlador rompía con "Call to protected
     * method", y peor, solo al llegar a esa línea.
     */
    public function porId($id)
    {
        return $this->uno("
            SELECT u.*, s.nombre AS sucursal
            FROM usuarios u
            LEFT JOIN sucursales s ON s.id = u.sucursal_id
            WHERE u.id = ?", [(int)$id]);
    }

    /**
     * Restablece una contraseña generando una nueva.
     *
     * A diferencia de `restablecerClave()`, que recibe la clave que el
     * administrador escribió, esta la INVENTA. La usa soporte, que no
     * debería estar eligiendo contraseñas para nadie.
     *
     * Se devuelve una sola vez y no se guarda en claro: quien restablece
     * la entrega y ahí termina.
     */
    public function restablecerGenerando($id)
    {
        $u = $this->porId($id);
        if (!$u) throw new \InvalidArgumentException('Ese usuario no existe');

        // Sin l, I, 1, O ni 0: esta clave casi siempre se dicta por
        // teléfono, y esos caracteres no se distinguen en una llamada.
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $clave = '';
        for ($i = 0; $i < 12; $i++) $clave .= $abc[random_int(0, strlen($abc) - 1)];

        $this->db->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                 ->execute([password_hash($clave, PASSWORD_DEFAULT), (int)$id]);
        return $clave;
    }
}
