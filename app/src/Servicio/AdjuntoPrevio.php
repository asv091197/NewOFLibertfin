<?php
namespace LibertyFin\Servicio;

/**
 * El adjunto de un mensaje del chat, subido ANTES de enviar.
 *
 * Al pegar o elegir una imagen, el chat la sube en ese momento, mientras
 * la persona termina de escribir. Al dar Enviar, el mensaje solo dice
 * cuál era: sale al instante aunque la imagen pese.
 *
 * Lo subido se apunta en la SESIÓN de quien lo subió y para ESE ticket.
 * Nadie puede adjuntar a su mensaje un archivo que subió otra persona, ni
 * pasar uno de un ticket a otro: el mensaje solo manda una clave al azar,
 * y la ruta se busca aquí.
 *
 * Lo que se sube y nunca se envía (se quitó la imagen, se cerró la
 * pestaña) se borra del disco en cuanto se acumulan más de MAX.
 */
final class AdjuntoPrevio
{
    const MAX = 8;

    /** Sube `$_FILES['adjunto']`. Devuelve la respuesta para el chat. */
    public static function subir($ticketId)
    {
        if (empty($_FILES['adjunto']['name'])) {
            return ['ok' => false, 'error' => 'No llegó ningún archivo.'];
        }
        try {
            $ruta = Archivos::documento($_FILES['adjunto'], 'ticket');
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] adjunto previo: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo subir el archivo.'];
        }

        $clave = bin2hex(random_bytes(12));
        $lista = $_SESSION['lf_adj_previos'] ?? [];
        $lista[$clave] = ['ruta' => $ruta, 'ticket' => (int)$ticketId];
        while (count($lista) > self::MAX) {
            $viejo = array_shift($lista);
            Archivos::borrar($viejo['ruta'] ?? '');
        }
        $_SESSION['lf_adj_previos'] = $lista;
        return ['ok' => true, 'clave' => $clave];
    }

    /** La ruta de lo subido, si es de esta sesión y de este ticket. */
    public static function ruta($ticketId, $clave)
    {
        $a = $_SESSION['lf_adj_previos'][(string)$clave] ?? null;
        return ($a && (int)$a['ticket'] === (int)$ticketId) ? $a['ruta'] : null;
    }

    /** Ya se usó en un mensaje: deja de estar pendiente (el archivo se queda). */
    public static function usado($clave)
    {
        unset($_SESSION['lf_adj_previos'][(string)$clave]);
    }
}
