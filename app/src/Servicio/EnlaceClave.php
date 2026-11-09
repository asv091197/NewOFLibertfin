<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use PDO;

/**
 * El enlace para que el administrador de una empresa nueva cree su
 * contraseña.
 *
 * Antes, al aprobar una solicitud se generaba una contraseña y viajaba en
 * claro por correo: se quedaba en la bandeja para siempre. Ahora viaja un
 * enlace que:
 *
 *   · sirve UNA sola vez,
 *   · vence a las HORAS horas,
 *   · se guarda solo como hash (sha256): quien lea la tabla no puede usarlo.
 *
 * El usuario se crea con una contraseña aleatoria que nadie conoce; hasta
 * que use el enlace, no hay forma de entrar con esa cuenta.
 */
final class EnlaceClave
{
    const HORAS = 72;

    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    private function asegurar()
    {
        $this->principal->exec("
            CREATE TABLE IF NOT EXISTS enlaces_clave (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                empresa_id INT NOT NULL,
                usuario_id INT NOT NULL,
                creado_en  DATETIME NOT NULL,
                expira_en  DATETIME NOT NULL,
                usado_en   DATETIME NULL,
                KEY ix_ec_usuario (empresa_id, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Crea un enlace nuevo y devuelve el token en claro (no se guarda). */
    public function crear($empresaId, $usuarioId)
    {
        $this->asegurar();
        // Un enlace vivo por usuario: el anterior deja de servir.
        $this->principal->prepare("
            UPDATE enlaces_clave SET usado_en = NOW()
            WHERE empresa_id = ? AND usuario_id = ? AND usado_en IS NULL
        ")->execute([(int)$empresaId, (int)$usuarioId]);

        $token = bin2hex(random_bytes(32));
        $this->principal->prepare("
            INSERT INTO enlaces_clave (token_hash, empresa_id, usuario_id, creado_en, expira_en)
            VALUES (?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL " . (int)self::HORAS . " HOUR))
        ")->execute([hash('sha256', $token), (int)$empresaId, (int)$usuarioId]);
        return $token;
    }

    /** La dirección completa que se manda por correo. */
    public static function url($token)
    {
        $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'libertyfin.com.mx')
             . '/crear-clave?t=' . rawurlencode($token);
    }

    /**
     * ¿El enlace sirve? Devuelve ['base', 'usuario_id', 'username', 'nombre',
     * 'empresa'] o null si no existe, ya se usó o venció.
     */
    public function buscar($token)
    {
        $token = (string)$token;
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $this->asegurar();

        $st = $this->principal->prepare("
            SELECT ec.usuario_id, e.nombre_base_datos AS base, e.nombre_empresa AS empresa
            FROM enlaces_clave ec JOIN empresas e ON e.id = ec.empresa_id
            WHERE ec.token_hash = ? AND ec.usado_en IS NULL AND ec.expira_en > NOW()");
        $st->execute([hash('sha256', $token)]);
        $f = $st->fetch();
        if (!$f || !preg_match('/^[A-Za-z0-9_]+$/', (string)$f['base'])) return null;

        $st = Conexion::de($f['base'])->prepare("SELECT username, nombre FROM usuarios WHERE id = ?");
        $st->execute([(int)$f['usuario_id']]);
        $u = $st->fetch();
        if (!$u) return null;

        return ['base' => $f['base'], 'usuario_id' => (int)$f['usuario_id'],
                'username' => $u['username'], 'nombre' => $u['nombre'], 'empresa' => $f['empresa']];
    }

    /**
     * Guarda la contraseña y gasta el enlace.
     * @throws \InvalidArgumentException con un mensaje apto para mostrar
     */
    public function usar($token, $clave, $confirmacion)
    {
        $e = $this->buscar($token);
        if (!$e) {
            throw new \InvalidArgumentException(
                'Este enlace ya no es válido: se usó o venció. Pide uno nuevo a soporte.');
        }
        if ((string)$clave !== (string)$confirmacion) {
            throw new \InvalidArgumentException('Las contraseñas no coinciden');
        }
        UsuarioRepo::revisarClave($clave, $e['username']);

        // Primero se gasta, de forma atómica: dos envíos a la vez no pueden
        // usar el mismo enlace.
        $hash = hash('sha256', (string)$token);
        $st = $this->principal->prepare("
            UPDATE enlaces_clave SET usado_en = NOW()
            WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()");
        $st->execute([$hash]);
        if ($st->rowCount() !== 1) {
            throw new \InvalidArgumentException('Este enlace ya se usó.');
        }

        try {
            Conexion::de($e['base'])->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                ->execute([password_hash((string)$clave, PASSWORD_DEFAULT), $e['usuario_id']]);
        } catch (\Throwable $ex) {
            // No se guardó: el enlace vuelve a servir para reintentar.
            $this->principal->prepare("UPDATE enlaces_clave SET usado_en = NULL WHERE token_hash = ?")
                            ->execute([$hash]);
            throw $ex;
        }
        return $e;
    }
}
