<?php
namespace LibertyFin\Datos;

/**
 * De qué empresa es cada tienda de TikTok Shop.
 *
 * Vive en la base PRINCIPAL, igual que `lf_ruta_shopify` y por lo mismo:
 * los avisos de TikTok llegan sin sesión y solo traen el `shop_id`. Con
 * él se sabe la empresa, y con la empresa, con qué secreto revisar la
 * firma (el suyo si usa una app «Seller in-house», o el global).
 *
 * También impide que la MISMA tienda quede conectada a dos empresas: cada
 * venta descontaría existencias en las dos.
 */
final class RutaTikTokRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_tiktok (
                    shop_id    VARCHAR(32) NOT NULL PRIMARY KEY,
                    empresa_db VARCHAR(64) NOT NULL,
                    nombre     VARCHAR(120) NULL,
                    creado_en  DATETIME NOT NULL,
                    KEY ix_rtt_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_tiktok: ' . $e->getMessage());
        }
    }

    public function buscar($shopId)
    {
        $this->asegurar();
        try {
            return $this->uno("SELECT * FROM lf_ruta_tiktok WHERE shop_id = ?", [(string)$shopId]);
        } catch (\Throwable $e) { return null; }
    }

    /** @return string|null la base que ya tiene esa tienda, si es otra. */
    public function deOtra($shopId, $empresaDb)
    {
        $f = $this->buscar($shopId);
        return ($f && $f['empresa_db'] !== (string)$empresaDb) ? $f['empresa_db'] : null;
    }

    public function apuntar($shopId, $empresaDb, $nombre = '')
    {
        $this->asegurar();
        // Una empresa tiene UNA tienda: si antes tenía otra, esa se suelta.
        $this->db->prepare("DELETE FROM lf_ruta_tiktok WHERE empresa_db = ? AND shop_id <> ?")
                 ->execute([(string)$empresaDb, (string)$shopId]);
        $this->db->prepare("
            INSERT INTO lf_ruta_tiktok (shop_id, empresa_db, nombre, creado_en) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db), nombre = VALUES(nombre)
        ")->execute([(string)$shopId, mb_substr((string)$empresaDb, 0, 64), mb_substr((string)$nombre, 0, 120)]);
    }

    public function soltar($empresaDb)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_tiktok WHERE empresa_db = ?")->execute([(string)$empresaDb]);
    }

    /** Todas, para la tarea periódica. Solo bases con nombre limpio: van a un DSN. */
    public function todas()
    {
        $this->asegurar();
        try {
            $r = $this->todos("SELECT * FROM lf_ruta_tiktok ORDER BY empresa_db");
        } catch (\Throwable $e) { return []; }
        return array_values(array_filter($r, function ($f) {
            return (bool)preg_match('/^[A-Za-z0-9_]+$/', (string)$f['empresa_db']);
        }));
    }
}
