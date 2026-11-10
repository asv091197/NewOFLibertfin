<?php
namespace LibertyFin\Datos;

/**
 * De qué empresa es cada tienda de Tienda Nube.
 *
 * Vive en la base PRINCIPAL, como `lf_ruta_meli` y por lo mismo: los
 * avisos de Tienda Nube llegan sin sesión y solo traen el `store_id`.
 * También impide que la MISMA tienda quede conectada a dos empresas: cada
 * venta descontaría existencias en las dos.
 */
final class RutaTiendaNubeRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_tn (
                    store_id   VARCHAR(20) NOT NULL PRIMARY KEY,
                    empresa_db VARCHAR(64) NOT NULL,
                    nombre     VARCHAR(120) NULL,
                    creado_en  DATETIME NOT NULL,
                    KEY ix_rtn_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_tn: ' . $e->getMessage());
        }
    }

    public function buscar($storeId)
    {
        $this->asegurar();
        try {
            return $this->uno("SELECT * FROM lf_ruta_tn WHERE store_id = ?", [(string)$storeId]);
        } catch (\Throwable $e) { return null; }
    }

    /** @return string|null la base que ya tiene esa tienda, si es otra. */
    public function deOtra($storeId, $empresaDb)
    {
        $f = $this->buscar($storeId);
        return ($f && $f['empresa_db'] !== (string)$empresaDb) ? $f['empresa_db'] : null;
    }

    public function apuntar($storeId, $empresaDb, $nombre = '')
    {
        $this->asegurar();
        // Una empresa tiene UNA tienda: si antes tenía otra, esa se suelta.
        $this->db->prepare("DELETE FROM lf_ruta_tn WHERE empresa_db = ? AND store_id <> ?")
                 ->execute([(string)$empresaDb, (string)$storeId]);
        $this->db->prepare("
            INSERT INTO lf_ruta_tn (store_id, empresa_db, nombre, creado_en) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db), nombre = VALUES(nombre)
        ")->execute([(string)$storeId, mb_substr((string)$empresaDb, 0, 64), mb_substr((string)$nombre, 0, 120)]);
    }

    public function soltar($empresaDb)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_tn WHERE empresa_db = ?")->execute([(string)$empresaDb]);
    }

    public function soltarTienda($storeId)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_tn WHERE store_id = ?")->execute([(string)$storeId]);
    }

    /** Todas, para la tarea periódica. Solo bases con nombre limpio: van a un DSN. */
    public function todas()
    {
        $this->asegurar();
        try {
            $r = $this->todos("SELECT * FROM lf_ruta_tn ORDER BY empresa_db");
        } catch (\Throwable $e) { return []; }
        return array_values(array_filter($r, function ($f) {
            return (bool)preg_match('/^[A-Za-z0-9_]+$/', (string)$f['empresa_db']);
        }));
    }
}
