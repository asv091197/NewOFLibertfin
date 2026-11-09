<?php
namespace LibertyFin\Api;

use LibertyFin\Datos\Repo;

/**
 * Las llaves de la API. Viven en la base PRINCIPAL.
 *
 * Una petición a la API no trae sesión: lo único que dice de qué empresa
 * es la llave. Si las llaves vivieran en la base de cada empresa, habría
 * que abrir todas las bases para encontrar una (como hace el login). En
 * la principal es una sola consulta.
 *
 * De la llave se guarda solo su huella (SHA-256), nunca el texto. Quien
 * se lleve una copia de la base no puede usar ninguna llave. Por eso el
 * administrador la ve UNA sola vez, al crearla.
 *
 *   lfk_ + 48 caracteres hexadecimales  (192 bits al azar)
 *
 * Con esa cantidad de azar no hace falta un hash lento como el de las
 * contraseñas: nadie la adivina probando. SHA-256 basta y permite
 * buscarla por índice.
 */
final class LlaveRepo extends Repo
{
    const PREFIJO = 'lfk_';
    /** Los roles de empresa que puede llevar una llave. */
    const ROLES = ['admin', 'cajero', 'inventario'];

    private static $asegurada = false;

    public function asegurar()
    {
        if (self::$asegurada) return;
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS lf_api_llaves (
                id                INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id        INT NOT NULL,
                nombre            VARCHAR(80) NOT NULL,
                prefijo           VARCHAR(16) NOT NULL,
                huella            CHAR(64) NOT NULL,
                rol               VARCHAR(20) NOT NULL,
                creada_por        INT NULL,
                creada_por_nombre VARCHAR(160) NULL,
                creada_en         DATETIME NOT NULL,
                ultimo_uso        DATETIME NULL,
                ultima_ip         VARCHAR(45) NULL,
                ventana_inicio    DATETIME NULL,
                ventana_usos      INT NOT NULL DEFAULT 0,
                revocada_en       DATETIME NULL,
                UNIQUE KEY ux_api_llave_huella (huella),
                KEY ix_api_llave_empresa (empresa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$asegurada = true;
    }

    public static function huella($token)
    {
        return hash('sha256', (string)$token);
    }

    /** ¿Tiene forma de llave? Se revisa antes de ir a la base. */
    public static function formaValida($token)
    {
        return (bool)preg_match('/^lfk_[a-f0-9]{48}$/', (string)$token);
    }

    /**
     * Crea una llave y devuelve el texto completo. Es la única vez que
     * existe en claro: no se puede recuperar después.
     *
     * @return array ['id' => int, 'token' => string, 'prefijo' => string]
     */
    public function crear($empresaId, $nombre, $rol, $creadorId = null, $creadorNombre = null)
    {
        $nombre = trim((string)$nombre);
        if ($nombre === '') throw new \InvalidArgumentException('Ponle un nombre a la llave: para qué sistema es.');
        if (mb_strlen($nombre) > 80) throw new \InvalidArgumentException('El nombre de la llave es demasiado largo.');
        if (!in_array($rol, self::ROLES, true)) throw new \InvalidArgumentException('Ese rol no existe.');

        $this->asegurar();
        $token = self::PREFIJO . bin2hex(random_bytes(24));
        $prefijo = substr($token, 0, 12);
        $this->db->prepare("
            INSERT INTO lf_api_llaves
                (empresa_id, nombre, prefijo, huella, rol, creada_por, creada_por_nombre, creada_en)
            VALUES (?,?,?,?,?,?,?, NOW())")
            ->execute([(int)$empresaId, $nombre, $prefijo, self::huella($token), $rol,
                       $creadorId ?: null, $creadorNombre ? mb_substr((string)$creadorNombre, 0, 160) : null]);
        return ['id' => (int)$this->db->lastInsertId(), 'token' => $token, 'prefijo' => $prefijo];
    }

    /** La llave vigente que corresponde a ese texto, o null. */
    public function porToken($token)
    {
        if (!self::formaValida($token)) return null;
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_api_llaves WHERE huella = ? AND revocada_en IS NULL",
            [self::huella($token)]);
    }

    /** Las llaves de una empresa, sin la huella. */
    public function deEmpresa($empresaId)
    {
        $this->asegurar();
        return $this->todos("
            SELECT id, nombre, prefijo, rol, creada_por_nombre, creada_en,
                   ultimo_uso, ultima_ip, revocada_en
            FROM lf_api_llaves WHERE empresa_id = ?
            ORDER BY revocada_en IS NULL DESC, creada_en DESC", [(int)$empresaId]);
    }

    /** Revoca una llave de ESA empresa. Devuelve si había algo que revocar. */
    public function revocar($id, $empresaId)
    {
        $this->asegurar();
        $st = $this->db->prepare("
            UPDATE lf_api_llaves SET revocada_en = NOW()
            WHERE id = ? AND empresa_id = ? AND revocada_en IS NULL");
        $st->execute([(int)$id, (int)$empresaId]);
        return $st->rowCount() > 0;
    }

    /**
     * Anota el uso y devuelve cuántas peticiones lleva en el minuto.
     *
     * Ventana fija de un minuto, en la misma fila: una sola escritura por
     * petición, sin tablas aparte. Las asignaciones de un UPDATE de MySQL
     * se evalúan en orden, así que `ventana_usos` se calcula con la
     * `ventana_inicio` anterior y después se renueva la ventana.
     */
    public function contarUso($id, $ip)
    {
        $this->db->prepare("
            UPDATE lf_api_llaves SET
                ventana_usos   = IF(ventana_inicio IS NULL OR ventana_inicio <= NOW() - INTERVAL 1 MINUTE,
                                    1, ventana_usos + 1),
                ventana_inicio = IF(ventana_inicio IS NULL OR ventana_inicio <= NOW() - INTERVAL 1 MINUTE,
                                    NOW(), ventana_inicio),
                ultimo_uso     = NOW(),
                ultima_ip      = ?
            WHERE id = ?")->execute([mb_substr((string)$ip, 0, 45), (int)$id]);
        return (int)$this->valor("SELECT ventana_usos FROM lf_api_llaves WHERE id = ?", [(int)$id]);
    }
}
