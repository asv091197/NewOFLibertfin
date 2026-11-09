<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Encuentra a un usuario entre las bases de todas las empresas.
 *
 * El sistema anterior abría una conexión a CADA base en cada intento de
 * ingreso: con cinco empresas son cinco conexiones, y un intento fallido
 * las gasta todas.
 *
 * Aquí se mantiene un índice en la base principal que dice en qué empresa
 * vive cada usuario. La primera vez se busca recorriendo; de ahí en
 * adelante es una consulta. Si el índice falla o está desactualizado, se
 * vuelve a recorrer: nunca deja a nadie fuera.
 */
final class AutenticacionRepo
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Crea el índice si no existe. Se llama una vez, al arrancar. */
    public function prepararIndice()
    {
        try {
            $this->principal->exec("
                CREATE TABLE IF NOT EXISTS usuarios_indice (
                    identificador VARCHAR(190) NOT NULL,
                    empresa_id    INT NOT NULL,
                    visto         DATETIME NOT NULL,
                    PRIMARY KEY (identificador),
                    KEY ix_ui_empresa (empresa_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (\Throwable $e) {
            // Sin permiso de CREATE seguimos funcionando: solo más lento.
            error_log('[LibertyFin] no se pudo crear usuarios_indice: ' . $e->getMessage());
            return false;
        }
    }

    public function empresas()
    {
        $st = $this->principal->query("
            SELECT id, nombre_empresa, nombre_base_datos, activo, fecha_vencimiento, plan
            FROM empresas");
        return $st->fetchAll();
    }

    /**
     * Busca al usuario. Devuelve ['empresa'=>..., 'usuario'=>...] o null.
     * NO verifica la contraseña: eso es trabajo del servicio.
     */
    public function buscar($identificador)
    {
        $identificador = trim($identificador);
        if ($identificador === '') return null;

        // 1 · Por el índice
        $empresaId = null;
        try {
            $st = $this->principal->prepare(
                "SELECT empresa_id FROM usuarios_indice WHERE identificador = ?");
            $st->execute([mb_strtolower($identificador)]);
            $empresaId = $st->fetchColumn() ?: null;
        } catch (\Throwable $e) { /* sin índice, se recorre */ }

        $empresas = $this->empresas();
        if ($empresaId) {
            foreach ($empresas as $e) {
                if ((int)$e['id'] === (int)$empresaId) {
                    $u = $this->enBase($e['nombre_base_datos'], $identificador);
                    if ($u) return ['empresa' => $e, 'usuario' => $u];
                    break;   // el índice quedó viejo: se recorre
                }
            }
        }

        // 2 · Recorriendo
        foreach ($empresas as $e) {
            $u = $this->enBase($e['nombre_base_datos'], $identificador);
            if ($u) {
                $this->recordar($identificador, $e['id']);
                return ['empresa' => $e, 'usuario' => $u];
            }
        }
        return null;
    }

    private function enBase($base, $identificador)
    {
        // El nombre de la base entra en el DSN, no como parámetro: se valida.
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            error_log('[LibertyFin] nombre de base inválido: ' . $base);
            return null;
        }
        try {
            $db = Conexion::de($base);
            $st = $db->prepare("
                SELECT id, username, password, nombre, rol, sucursal_id, email
                FROM usuarios WHERE username = ? OR email = ? LIMIT 1");
            $st->execute([$identificador, $identificador]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] no se pudo consultar ' . $base . ': ' . $e->getMessage());
            return null;
        }
    }

    private function recordar($identificador, $empresaId)
    {
        try {
            $this->principal->prepare("
                INSERT INTO usuarios_indice (identificador, empresa_id, visto)
                VALUES (?,?,NOW())
                ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id), visto = NOW()
            ")->execute([mb_strtolower(trim($identificador)), (int)$empresaId]);
        } catch (\Throwable $e) { /* el índice es una comodidad, no un requisito */ }
    }

    public function sucursal($base, $sucursalId)
    {
        if (!$sucursalId) return null;
        try {
            $db = Conexion::de($base);
            $st = $db->prepare("SELECT id, nombre, es_matriz FROM sucursales WHERE id = ?");
            $st->execute([(int)$sucursalId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    // ── USUARIOS DE PLATAFORMA ──────────────────────────────────
    //
    // Soporte, validación y superadministración NO pertenecen a ninguna
    // empresa. Tenerlos dentro de la base de una —como estaban— provoca
    // tres cosas malas:
    //
    //   · Aparecen en la lista de "Equipo" de esa empresa, que no los
    //     contrató y no debería administrarlos.
    //   · Su administrador puede bloquearlos o cambiarles la contraseña.
    //   · Si esa empresa se da de baja, soporte se queda sin cuentas.
    //
    // Por eso viven en la base principal, en su propia tabla.

    public function asegurarPlataforma()
    {
        try {
            $this->principal->exec("
                CREATE TABLE IF NOT EXISTS usuarios_plataforma (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(60) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    nombre VARCHAR(160) NOT NULL,
                    email VARCHAR(160) NULL,
                    rol VARCHAR(30) NOT NULL DEFAULT 'soporte',
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    creado_en DATETIME NOT NULL,
                    ultimo_acceso DATETIME NULL,
                    UNIQUE KEY ix_up_user (username),
                    KEY ix_up_activo (activo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] usuarios_plataforma: ' . $e->getMessage());
        }
    }

    // ── Mi cuenta, para una cuenta de plataforma ──
    //
    // Soporte, validación y superadmin no pertenecen a ninguna empresa, así
    // que su foto y su contraseña no pueden ir en la tabla `usuarios` de una
    // empresa: van aquí, en la suya. Antes "Mi cuenta" solo sabía guardar en
    // la base de una empresa y a estas cuentas les decía que no tenían una.

    /**
     * Las columnas `foto` y `foto_publica` se agregan solas la primera vez
     * que hacen falta.
     *
     * `foto_publica`: si el CLIENTE ve la foto de esta persona en los
     * tickets. Nace apagada: enseñarle a un cliente la foto de alguien del
     * equipo lo decide esa persona, no el sistema.
     */
    private function asegurarFotoPlataforma()
    {
        static $listo = false;
        if ($listo) return true;
        try {
            $st = $this->principal->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios_plataforma'
                  AND COLUMN_NAME IN ('foto','foto_publica')");
            $hay = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('foto', $hay, true)) {
                $this->principal->exec("ALTER TABLE usuarios_plataforma ADD COLUMN foto VARCHAR(255) NULL AFTER email");
            }
            if (!in_array('foto_publica', $hay, true)) {
                $this->principal->exec("ALTER TABLE usuarios_plataforma
                                        ADD COLUMN foto_publica TINYINT(1) NOT NULL DEFAULT 0 AFTER foto");
            }
            return $listo = true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] foto de plataforma: ' . $e->getMessage());
            return false;
        }
    }

    /** ¿El cliente ve la foto de esta persona en los tickets? */
    public function fotoPublicaPlataforma($id)
    {
        if (!$this->asegurarFotoPlataforma()) return false;
        try {
            $st = $this->principal->prepare("SELECT foto_publica FROM usuarios_plataforma WHERE id = ?");
            $st->execute([(int)$id]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) { return false; }
    }

    public function guardarFotoPublica($id, $si)
    {
        if (!$this->asegurarFotoPlataforma()) {
            throw new \RuntimeException('No se pudo preparar la tabla de cuentas de plataforma');
        }
        $this->principal->prepare("UPDATE usuarios_plataforma SET foto_publica = ? WHERE id = ?")
                        ->execute([$si ? 1 : 0, (int)$id]);
        return true;
    }

    /**
     * Fotos de varias cuentas de plataforma: [id => ['foto', 'publica']].
     * Para los tickets, donde cada mensaje lleva la foto de quien lo escribió.
     */
    public function fotosPlataforma(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->asegurarFotoPlataforma()) return [];
        try {
            $st = $this->principal->prepare("
                SELECT id, COALESCE(foto,'') AS foto, foto_publica FROM usuarios_plataforma
                WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $r = [];
            foreach ($st->fetchAll() as $f) {
                $r[(int)$f['id']] = ['foto' => (string)$f['foto'], 'publica' => (bool)$f['foto_publica']];
            }
            return $r;
        } catch (\Throwable $e) { return []; }
    }

    public function fotoPlataforma($id)
    {
        if (!$this->asegurarFotoPlataforma()) return '';
        try {
            $st = $this->principal->prepare("SELECT COALESCE(foto,'') FROM usuarios_plataforma WHERE id = ?");
            $st->execute([(int)$id]);
            return (string)$st->fetchColumn();
        } catch (\Throwable $e) { return ''; }
    }

    public function guardarFotoPlataforma($id, $ruta)
    {
        if (!$this->asegurarFotoPlataforma()) {
            throw new \RuntimeException('No se pudo preparar la tabla de cuentas de plataforma');
        }
        $this->principal->prepare("UPDATE usuarios_plataforma SET foto = ? WHERE id = ?")
                        ->execute([$ruta ?: null, (int)$id]);
        return true;
    }

    /**
     * APARIENCIA de una cuenta de plataforma: tema (light, dark, auto) y
     * color. Se guarda en la cuenta y no en el navegador, para que siga a
     * la persona a cualquier equipo. Las columnas se agregan solas.
     */
    const TEMAS = ['light', 'dark', 'auto'];

    private function asegurarApariencia()
    {
        static $listo = false;
        if ($listo) return true;
        try {
            $st = $this->principal->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios_plataforma'
                  AND COLUMN_NAME IN ('tema','color')");
            $hay = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tema', $hay, true)) {
                $this->principal->exec("ALTER TABLE usuarios_plataforma ADD COLUMN tema VARCHAR(8) NULL");
            }
            if (!in_array('color', $hay, true)) {
                $this->principal->exec("ALTER TABLE usuarios_plataforma ADD COLUMN color CHAR(7) NULL");
            }
            return $listo = true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] apariencia de plataforma: ' . $e->getMessage());
            return false;
        }
    }

    /** ['tema' => 'light'|'dark'|'auto'|'', 'color' => '#rrggbb'|'']; '' = sin elegir. */
    public function apariencia($id)
    {
        $vacia = ['tema' => '', 'color' => ''];
        if (!$this->asegurarApariencia()) return $vacia;
        try {
            $st = $this->principal->prepare("
                SELECT COALESCE(tema,'') AS tema, COALESCE(color,'') AS color
                FROM usuarios_plataforma WHERE id = ?");
            $st->execute([(int)$id]);
            $f = $st->fetch();
            if (!$f) return $vacia;
            return [
                'tema'  => in_array($f['tema'], self::TEMAS, true) ? $f['tema'] : '',
                'color' => preg_match('/^#[0-9a-f]{6}$/i', $f['color']) ? strtolower($f['color']) : '',
            ];
        } catch (\Throwable $e) { return $vacia; }
    }

    /** null = no se toca ese dato (el botón de la barra solo cambia el tema). */
    public function guardarApariencia($id, $tema, $color)
    {
        if (!$this->asegurarApariencia()) {
            throw new \RuntimeException('No se pudo preparar la tabla de cuentas de plataforma');
        }
        $sets = []; $p = [];
        if ($tema !== null)  { $sets[] = 'tema = ?';  $p[] = $tema ?: null; }
        if ($color !== null) { $sets[] = 'color = ?'; $p[] = $color ?: null; }
        if (!$sets) return true;
        $p[] = (int)$id;
        $this->principal->prepare("UPDATE usuarios_plataforma SET " . implode(', ', $sets) . " WHERE id = ?")
                        ->execute($p);
        return true;
    }

    /**
     * GUÍA DE PRIMER USO de una cuenta de plataforma. En una empresa vive en
     * `usuarios.guia_vista_en` (UsuarioRepo); estas cuentas no tienen esa
     * tabla, así que va en la suya. La columna se agrega sola y nace vacía:
     * cada cuenta de soporte la ve una vez.
     */
    private function asegurarGuia()
    {
        static $listo = false;
        if ($listo) return true;
        try {
            $st = $this->principal->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios_plataforma'
                  AND COLUMN_NAME = 'guia_vista_en'");
            if (!(int)$st->fetchColumn()) {
                $this->principal->exec("ALTER TABLE usuarios_plataforma
                                        ADD COLUMN guia_vista_en DATETIME NULL DEFAULT NULL");
            }
            return $listo = true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guía de plataforma: ' . $e->getMessage());
            return false;
        }
    }

    /** ¿Ya la vio? Si no se puede saber, se da por vista: mejor no insistir. */
    public function vioGuiaPlataforma($id)
    {
        if (!$this->asegurarGuia()) return true;
        try {
            $st = $this->principal->prepare("SELECT guia_vista_en FROM usuarios_plataforma WHERE id = ?");
            $st->execute([(int)$id]);
            return $st->fetchColumn() !== null;
        } catch (\Throwable $e) { return true; }
    }

    public function marcarGuiaPlataforma($id)
    {
        if (!$this->asegurarGuia()) return false;
        $this->principal->prepare("
            UPDATE usuarios_plataforma SET guia_vista_en = NOW()
            WHERE id = ? AND guia_vista_en IS NULL")->execute([(int)$id]);
        return true;
    }

    /** Las mismas reglas que en una empresa: ver UsuarioRepo::cambiarClave. */
    public function cambiarClavePlataforma($id, $actual, $nueva)
    {
        $st = $this->principal->prepare("SELECT username, password FROM usuarios_plataforma WHERE id = ?");
        $st->execute([(int)$id]);
        $u = $st->fetch();
        if (!$u || !password_verify((string)$actual, (string)$u['password'])) {
            throw new \InvalidArgumentException('La contraseña actual no es correcta');
        }
        UsuarioRepo::revisarClave($nueva, $u['username']);
        if (password_verify((string)$nueva, (string)$u['password'])) {
            throw new \InvalidArgumentException('La nueva contraseña es igual a la anterior');
        }
        $this->principal->prepare("UPDATE usuarios_plataforma SET password = ? WHERE id = ?")
                        ->execute([password_hash($nueva, PASSWORD_DEFAULT), (int)$id]);
        return true;
    }

    /** Busca en la tabla de plataforma. Se consulta ANTES que las empresas. */
    public function usuarioPlataforma($identificador)
    {
        $this->asegurarPlataforma();
        try {
            $st = $this->principal->prepare("
                SELECT * FROM usuarios_plataforma
                WHERE (username = ? OR email = ?) LIMIT 1");
            $st->execute([$identificador, $identificador]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    public function marcarAccesoPlataforma($id)
    {
        try {
            $this->principal->prepare(
                "UPDATE usuarios_plataforma SET ultimo_acceso = NOW() WHERE id = ?")
                ->execute([(int)$id]);
        } catch (\Throwable $e) { /* no es grave */ }
    }

    public function listaPlataforma()
    {
        $this->asegurarPlataforma();
        try {
            return $this->principal->query("
                SELECT id, username, nombre, email, rol, activo, creado_en, ultimo_acceso
                FROM usuarios_plataforma ORDER BY activo DESC, nombre")->fetchAll();
        } catch (\Throwable $e) { return []; }
    }
}
