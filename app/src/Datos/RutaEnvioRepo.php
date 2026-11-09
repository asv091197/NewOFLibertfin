<?php
namespace LibertyFin\Datos;

/**
 * De qué empresa es cada guía.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * Los avisos de la paquetería llegan sin sesión y sin cookie: quien
 * llama es un servidor de Envia, no una persona. Traen el número de
 * guía y nada más. Pero cada empresa tiene su propia base, y una cuenta
 * de Envia es de toda la instalación, así que con el puro número no hay
 * forma de saber en qué base está ese envío.
 *
 * Esta tabla vive en la base PRINCIPAL y guarda la respuesta: guía →
 * base de la empresa. Se escribe al generar la guía, que es cuando sí
 * se sabe. Si por lo que sea falta el renglón, el controlador recorre
 * las bases una por una —caro, pero exhaustivo— y al encontrarla la
 * apunta aquí para la próxima.
 *
 * Es el mismo arreglo que ya usa `lf_ruta_cobros` para los pagos.
 */
final class RutaEnvioRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_envios (
                    guia       VARCHAR(80) NOT NULL PRIMARY KEY,
                    empresa_db VARCHAR(64) NOT NULL,
                    envio_id   INT NULL,
                    creado_en  DATETIME NOT NULL,
                    KEY ix_re_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_envios: ' . $e->getMessage());
        }
    }

    /** Apunta una guía a su empresa. Se llama al generarla. */
    public function apuntar($guia, $empresaDb, $envioId = null)
    {
        $guia = trim((string)$guia);
        if ($guia === '' || trim((string)$empresaDb) === '') return;
        $this->asegurar();
        try {
            $this->db->prepare("
                INSERT INTO lf_ruta_envios (guia, empresa_db, envio_id, creado_en)
                VALUES (?,?,?,NOW())
                ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db),
                                        envio_id   = VALUES(envio_id)
            ")->execute([mb_substr($guia, 0, 80), mb_substr((string)$empresaDb, 0, 64),
                         $envioId ? (int)$envioId : null]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] apuntar guía ' . $guia . ': ' . $e->getMessage());
        }
    }

    public function buscar($guia)
    {
        $guia = trim((string)$guia);
        if ($guia === '') return null;
        try {
            return $this->uno("SELECT * FROM lf_ruta_envios WHERE guia = ?", [$guia]);
        } catch (\Throwable $e) {
            return null;   // la tabla puede no existir todavía
        }
    }

    /** Las bases de las empresas activas, para el barrido de último recurso. */
    public function basesDeEmpresas()
    {
        try {
            $r = $this->todos("
                SELECT DISTINCT nombre_base_datos FROM empresas
                 WHERE COALESCE(activo,1) = 1 AND nombre_base_datos <> ''");
        } catch (\Throwable $e) { return []; }

        $bases = [];
        foreach ($r as $f) {
            $b = (string)$f['nombre_base_datos'];
            // El nombre va a ir en un DSN: si trae algo raro, se deja fuera.
            if (preg_match('/^[A-Za-z0-9_]+$/', $b)) $bases[] = $b;
        }
        return $bases;
    }

    /**
     * Los avisos ya atendidos, para no repetir trabajo.
     *
     * Envia reintenta cuando no contestamos a tiempo, así que el mismo
     * aviso puede llegar dos o tres veces. Cada uno trae su `X-Webhook-Id`
     * y aquí se guarda: el segundo con el mismo id se contesta «ok» y se
     * ignora. Sin esto, un reintento podría revivir un envío cancelado.
     */
    public function yaVisto($avisoId)
    {
        $avisoId = trim((string)$avisoId);
        if ($avisoId === '') return false;
        $this->asegurarAvisos();
        try {
            $st = $this->db->prepare("INSERT IGNORE INTO lf_avisos_envia (aviso_id, visto_en)
                                      VALUES (?, NOW())");
            $st->execute([mb_substr($avisoId, 0, 100)]);
            // Si no insertó, es porque ya estaba: aviso repetido.
            return $st->rowCount() === 0;
        } catch (\Throwable $e) {
            return false;   // ante la duda, se atiende: perder uno es peor
        }
    }

    /** Tira los apuntes viejos: no sirven y la tabla no debe crecer sola. */
    public function limpiarAvisos($dias = 30)
    {
        try {
            $this->db->prepare("DELETE FROM lf_avisos_envia
                                 WHERE visto_en < DATE_SUB(NOW(), INTERVAL ? DAY)")
                     ->execute([(int)$dias]);
        } catch (\Throwable $e) { /* no es grave */ }
    }

    private function asegurarAvisos()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_avisos_envia (
                    aviso_id VARCHAR(100) NOT NULL PRIMARY KEY,
                    visto_en DATETIME NOT NULL,
                    KEY ix_ae_fecha (visto_en)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_avisos_envia: ' . $e->getMessage());
        }
    }
}