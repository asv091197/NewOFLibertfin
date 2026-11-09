<?php
namespace LibertyFin\Datos;

/**
 * El directorio de cobros: qué referencia vive en qué base.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * LibertyFin tiene una base por empresa, pero las credenciales de Paga
 * de Todo son UNAS para toda la instalación. Cuando el proveedor avisa
 * "pagaron la referencia 774433000005000000012345830010" llega sin
 * sesión, sin cookie y sin empresa: solo con el número.
 *
 * Sin este directorio habría que abrir las treinta bases y preguntarle a
 * cada una si conoce esa referencia. Con treinta empresas son treinta
 * conexiones por aviso; y el proveedor espera respuesta en segundos o da
 * el pago por fallido y lo cancela.
 *
 * Aquí se apunta, en la base PRINCIPAL, a qué base pertenece cada
 * referencia. Una fila por cobro, dos columnas, una búsqueda directa.
 *
 * NO GUARDA DINERO. El monto, el estado y el abono viven en la base de
 * la empresa. Esto es un índice, nada más: si se perdiera, se reconstruye
 * recorriendo las bases.
 */
final class RutaLigaRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ruta_cobros (
                    referencia VARCHAR(32) NOT NULL PRIMARY KEY,
                    empresa_db VARCHAR(64) NOT NULL,
                    empresa_id INT NULL,
                    metodo VARCHAR(16) NULL,
                    creado_en DATETIME NOT NULL,
                    KEY ix_rc_empresa (empresa_db)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ruta_cobros: ' . $e->getMessage());
        }
    }

    /**
     * Apunta una referencia.
     *
     * Si ya existía se actualiza en vez de reventar: al reintentar un
     * cobro el proveedor devuelve la MISMA referencia, y eso es
     * deliberado, no un error.
     */
    public function apuntar($referencia, $empresaDb, $empresaId = null, $metodo = null)
    {
        $referencia = preg_replace('/\D/', '', (string)$referencia);
        if ($referencia === '' || trim((string)$empresaDb) === '') return false;
        $this->asegurar();
        try {
            $this->db->prepare("
                INSERT INTO lf_ruta_cobros (referencia, empresa_db, empresa_id, metodo, creado_en)
                VALUES (?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE empresa_db = VALUES(empresa_db),
                                        empresa_id = VALUES(empresa_id),
                                        metodo     = VALUES(metodo)
            ")->execute([$referencia, (string)$empresaDb,
                         $empresaId ? (int)$empresaId : null, $metodo]);
            return true;
        } catch (\Throwable $e) {
            // Que no se pueda apuntar NO debe impedir cobrar. El cobro ya
            // existe; lo que se pierde es la detección automática, y para
            // eso queda el botón de "ya me pagó".
            error_log('[LibertyFin] apuntar cobro ' . $referencia . ': ' . $e->getMessage());
            return false;
        }
    }

    /** @return array|null  ['empresa_db' => ..., 'empresa_id' => ..., 'metodo' => ...] */
    public function buscar($referencia)
    {
        $referencia = preg_replace('/\D/', '', (string)$referencia);
        if ($referencia === '') return null;
        $this->asegurar();
        try {
            return $this->uno(
                "SELECT * FROM lf_ruta_cobros WHERE referencia = ?", [$referencia]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] buscar cobro: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Las bases donde buscar cuando el directorio no sabe.
     *
     * Pasa con los cobros generados ANTES de que existiera esta tabla.
     * Recorrerlas es caro, por eso es el último recurso y no el primero.
     */
    public function basesDeEmpresas($tope = 60)
    {
        try {
            return array_column($this->todos("
                SELECT nombre_base_datos FROM empresas
                WHERE activo = 1 AND nombre_base_datos <> ''
                ORDER BY id DESC LIMIT " . (int)$tope), 'nombre_base_datos');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] listar bases: ' . $e->getMessage());
            return [];
        }
    }
}
