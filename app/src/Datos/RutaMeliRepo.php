<?php
namespace LibertyFin\Datos;

/**
 * De qué empresa es cada cuenta de Mercado Libre.
 *
 * Vive en la base PRINCIPAL, igual que `lf_ruta_envios` y
 * `lf_ruta_cobros`, y por lo mismo: los avisos de Mercado Libre llegan
 * sin sesión y solo traen el `user_id` del vendedor. Sin este índice
 * habría que abrir todas las bases para saber de quién es una venta.
 *
 * También impide que la MISMA cuenta de vendedor quede conectada a dos
 * empresas: cada venta descontaría existencias en las dos.
 */
final class RutaMeliRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_meli (
                    meli_user_id VARCHAR(20) NOT NULL PRIMARY KEY,
                    empresa_db   VARCHAR(64) NOT NULL,
                    nickname     VARCHAR(100) NULL,
                    creado_en    DATETIME NOT NULL,
                    KEY ix_rm_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_meli: ' . $e->getMessage());
        }
    }

    public function buscar($userId)
    {
        $this->asegurar();
        try {
            return $this->uno("SELECT * FROM lf_ruta_meli WHERE meli_user_id = ?", [(string)$userId]);
        } catch (\Throwable $e) { return null; }
    }

    /** @return string|null la base que ya tiene esa cuenta, si es otra. */
    public function deOtra($userId, $empresaDb)
    {
        $f = $this->buscar($userId);
        return ($f && $f['empresa_db'] !== (string)$empresaDb) ? $f['empresa_db'] : null;
    }

    public function apuntar($userId, $empresaDb, $nickname = '')
    {
        $this->asegurar();
        // Una empresa tiene UNA cuenta: si antes tenía otra, esa se suelta.
        $this->db->prepare("DELETE FROM lf_ruta_meli WHERE empresa_db = ? AND meli_user_id <> ?")
                 ->execute([(string)$empresaDb, (string)$userId]);
        $this->db->prepare("
            INSERT INTO lf_ruta_meli (meli_user_id, empresa_db, nickname, creado_en) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db), nickname = VALUES(nickname)
        ")->execute([(string)$userId, mb_substr((string)$empresaDb, 0, 64), mb_substr((string)$nickname, 0, 100)]);
    }

    public function soltar($empresaDb)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_meli WHERE empresa_db = ?")->execute([(string)$empresaDb]);
    }

    /** Todas, para la tarea periódica. Solo bases con nombre limpio: van a un DSN. */
    public function todas()
    {
        $this->asegurar();
        try {
            $r = $this->todos("SELECT * FROM lf_ruta_meli ORDER BY empresa_db");
        } catch (\Throwable $e) { return []; }
        return array_values(array_filter($r, function ($f) {
            return (bool)preg_match('/^[A-Za-z0-9_]+$/', (string)$f['empresa_db']);
        }));
    }
}
