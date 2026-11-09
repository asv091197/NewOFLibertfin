<?php
namespace LibertyFin\Api;

use LibertyFin\Datos\Repo;

/**
 * Que un cobro mandado dos veces se aplique una.
 *
 * El cliente de la API manda la cabecera `Idempotency-Key` con un valor
 * propio por operación. Si la petición se repite con la misma clave —la
 * red se cortó y su programa reintentó—, recibe la respuesta guardada en
 * vez de un segundo abono.
 *
 *   1. reservar()  aparta la clave ANTES de cobrar. Si dos reintentos
 *                  llegan a la vez, solo uno la consigue (índice único).
 *   2. completar() guarda la respuesta.
 *   3. liberar()   si el cobro falló, suelta la clave para reintentar.
 *
 * Vive en la base de la empresa (migración 24).
 */
final class IdempotenciaRepo extends Repo
{
    /** Cuánto se recuerda una clave. */
    const DIAS = 30;

    public static function claveValida($clave)
    {
        return (bool)preg_match('/^[A-Za-z0-9_\-.:]{1,100}$/', (string)$clave);
    }

    /**
     * Aparta la clave. Devuelve null si es nueva (adelante) o la fila que
     * ya existía (con su estado y su respuesta).
     */
    public function reservar($llaveId, $clave, $huella)
    {
        // De vez en cuando se barren las viejas: no hace falta un cron.
        if (random_int(1, 200) === 1) {
            $this->db->exec("DELETE FROM lf_api_idempotencia WHERE creado_en < NOW() - INTERVAL " . self::DIAS . " DAY");
        }
        $st = $this->db->prepare("
            INSERT IGNORE INTO lf_api_idempotencia (llave_id, clave, huella, estado, creado_en)
            VALUES (?, ?, ?, 'procesando', NOW())");
        $st->execute([(int)$llaveId, (string)$clave, (string)$huella]);
        if ($st->rowCount() === 1) return null;
        return $this->uno("SELECT * FROM lf_api_idempotencia WHERE llave_id = ? AND clave = ?",
            [(int)$llaveId, (string)$clave]);
    }

    public function completar($llaveId, $clave, Respuesta $r)
    {
        $this->db->prepare("
            UPDATE lf_api_idempotencia SET estado = 'listo', http_estado = ?, respuesta = ?
            WHERE llave_id = ? AND clave = ?")
            ->execute([$r->estado, $r->json(), (int)$llaveId, (string)$clave]);
    }

    public function liberar($llaveId, $clave)
    {
        $this->db->prepare("DELETE FROM lf_api_idempotencia WHERE llave_id = ? AND clave = ? AND estado = 'procesando'")
            ->execute([(int)$llaveId, (string)$clave]);
    }
}
