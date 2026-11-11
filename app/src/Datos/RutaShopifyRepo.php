<?php
namespace LibertyFin\Datos;

/**
 * De qué empresa es cada tienda de Shopify.
 *
 * Vive en la base PRINCIPAL, como `lf_ruta_tn` y por lo mismo: los avisos
 * de Shopify llegan sin sesión y solo traen el dominio de la tienda
 * (X-Shopify-Shop-Domain). También impide que la MISMA tienda quede
 * conectada a dos empresas: cada venta descontaría existencias en las dos.
 */
final class RutaShopifyRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_shopify (
                    tienda     VARCHAR(120) NOT NULL PRIMARY KEY,
                    empresa_db VARCHAR(64) NOT NULL,
                    nombre     VARCHAR(120) NULL,
                    creado_en  DATETIME NOT NULL,
                    KEY ix_rsh_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_shopify: ' . $e->getMessage());
        }
    }

    public function buscar($tienda)
    {
        $this->asegurar();
        try {
            return $this->uno("SELECT * FROM lf_ruta_shopify WHERE tienda = ?", [strtolower((string)$tienda)]);
        } catch (\Throwable $e) { return null; }
    }

    /** @return string|null la base que ya tiene esa tienda, si es otra. */
    public function deOtra($tienda, $empresaDb)
    {
        $f = $this->buscar($tienda);
        return ($f && $f['empresa_db'] !== (string)$empresaDb) ? $f['empresa_db'] : null;
    }

    public function apuntar($tienda, $empresaDb, $nombre = '')
    {
        $this->asegurar();
        $tienda = strtolower((string)$tienda);
        // Una empresa tiene UNA tienda: si antes tenía otra, esa se suelta.
        $this->db->prepare("DELETE FROM lf_ruta_shopify WHERE empresa_db = ? AND tienda <> ?")
                 ->execute([(string)$empresaDb, $tienda]);
        $this->db->prepare("
            INSERT INTO lf_ruta_shopify (tienda, empresa_db, nombre, creado_en) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db), nombre = VALUES(nombre)
        ")->execute([$tienda, mb_substr((string)$empresaDb, 0, 64), mb_substr((string)$nombre, 0, 120)]);
    }

    public function soltar($empresaDb)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_shopify WHERE empresa_db = ?")->execute([(string)$empresaDb]);
    }

    public function soltarTienda($tienda)
    {
        $this->asegurar();
        $this->db->prepare("DELETE FROM lf_ruta_shopify WHERE tienda = ?")->execute([strtolower((string)$tienda)]);
    }

    /** Todas, para la tarea periódica. Solo bases con nombre limpio: van a un DSN. */
    public function todas()
    {
        $this->asegurar();
        try {
            $r = $this->todos("SELECT * FROM lf_ruta_shopify ORDER BY empresa_db");
        } catch (\Throwable $e) { return []; }
        return array_values(array_filter($r, function ($f) {
            return (bool)preg_match('/^[A-Za-z0-9_]+$/', (string)$f['empresa_db']);
        }));
    }
}
