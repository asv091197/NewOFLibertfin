<?php
namespace LibertyFin\Servicio;

/**
 * Correo saliente por SMTP.
 *
 * POR QUÉ NO USA mail()
 *
 * `mail()` entrega al agente local del servidor, que casi siempre manda
 * sin autenticar desde el dominio del hosting. Ese correo llega a spam
 * o no llega. Un aviso de "tu cuenta está lista" que no llega es peor
 * que no mandarlo: el cliente espera algo que nunca va a ver.
 *
 * Aquí se habla SMTP directo con autenticación, que es lo que hace que
 * el correo se acepte.
 *
 * NO USA UNA LIBRERÍA a propósito. PHPMailer haría esto mejor, pero
 * agregarla obliga a Composer y a un vendor/ que hay que mantener. Son
 * ciento y pico de líneas para EHLO, STARTTLS, AUTH y DATA, y a cambio
 * el sistema sigue siendo copiar una carpeta al servidor.
 */
final class Correo
{
    private $cfg;
    private $ultimoError = '';

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    public function error() { return $this->ultimoError; }

    public function listo()
    {
        foreach (['host','usuario','clave'] as $c) {
            if (trim((string)($this->cfg[$c] ?? '')) === '') return false;
        }
        return true;
    }

    /**
     * Manda un correo.
     *
     * Devuelve true o false, nunca lanza: que un aviso no salga no puede
     * tumbar la operación que lo disparó. Si se cae el SMTP, la venta ya
     * se cobró y el ticket ya se creó.
     */
    public function enviar($para, $asunto, $html, $texto = '')
    {
        $this->ultimoError = '';
        if (!$this->listo()) { $this->ultimoError = 'SMTP sin configurar'; return false; }
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            $this->ultimoError = 'Destinatario no válido'; return false;
        }

        $host   = (string)$this->cfg['host'];
        $puerto = (int)($this->cfg['puerto'] ?? 587);
        $desde  = (string)($this->cfg['desde'] ?? $this->cfg['usuario']);
        $nombre = (string)($this->cfg['nombre'] ?? 'LibertyFin');

        // 465 es TLS desde el primer byte; 587 empieza en claro y sube con
        // STARTTLS. Confundirlos da un timeout sin explicación.
        $directo = $puerto === 465;
        $destino = ($directo ? 'ssl://' : 'tcp://') . $host . ':' . $puerto;

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]]);
        $s = @stream_socket_client($destino, $errno, $errstr, 15,
                                   STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            $this->ultimoError = 'No se pudo conectar a ' . $host . ':' . $puerto
                               . ' — ' . ($errstr ?: 'sin detalle');
            return false;
        }
        stream_set_timeout($s, 15);

        $leer = function () use ($s) {
            $r = '';
            while (($l = fgets($s, 1024)) !== false) {
                $r .= $l;
                // Las respuestas multilínea traen "250-" y terminan en "250 ".
                if (strlen($l) < 4 || $l[3] !== '-') break;
            }
            return $r;
        };
        $decir = function ($cmd) use ($s, $leer) { fwrite($s, $cmd . "\r\n"); return $leer(); };
        $codigo = function ($r) { return (int)substr((string)$r, 0, 3); };

        try {
            if ($codigo($leer()) !== 220) throw new \RuntimeException('El servidor no saludó');

            $yo = $_SERVER['SERVER_NAME'] ?? 'libertyfin';
            if ($codigo($decir('EHLO ' . $yo)) !== 250) throw new \RuntimeException('EHLO rechazado');

            if (!$directo) {
                if ($codigo($decir('STARTTLS')) !== 220) throw new \RuntimeException('STARTTLS rechazado');
                if (!@stream_socket_enable_crypto($s, true,
                        STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('No se pudo cifrar la conexión');
                }
                // Después de cifrar hay que volver a presentarse: lo de
                // antes se dijo en claro y el servidor lo descarta.
                if ($codigo($decir('EHLO ' . $yo)) !== 250) throw new \RuntimeException('EHLO tras TLS rechazado');
            }

            if ($codigo($decir('AUTH LOGIN')) !== 334) throw new \RuntimeException('No acepta AUTH LOGIN');
            if ($codigo($decir(base64_encode((string)$this->cfg['usuario']))) !== 334) {
                throw new \RuntimeException('Usuario rechazado');
            }
            if ($codigo($decir(base64_encode((string)$this->cfg['clave']))) !== 235) {
                throw new \RuntimeException('Contraseña rechazada');
            }

            if ($codigo($decir('MAIL FROM:<' . $desde . '>')) !== 250) {
                throw new \RuntimeException('Remitente rechazado');
            }
            if (!in_array($codigo($decir('RCPT TO:<' . $para . '>')), [250, 251], true)) {
                throw new \RuntimeException('Destinatario rechazado por el servidor');
            }
            if ($codigo($decir('DATA')) !== 354) throw new \RuntimeException('DATA rechazado');

            $limite = 'lf' . bin2hex(random_bytes(8));
            $cab = [
                'From: =?UTF-8?B?' . base64_encode($nombre) . '?= <' . $desde . '>',
                'To: <' . $para . '>',
                'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $limite . '"',
            ];
            $cuerpo = implode("\r\n", $cab) . "\r\n\r\n"
                . '--' . $limite . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($texto ?: strip_tags($html))) . "\r\n"
                . '--' . $limite . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html)) . "\r\n"
                . '--' . $limite . "--\r\n";

            // Un punto solo al principio de una línea termina el mensaje:
            // se duplica, como manda el protocolo.
            $cuerpo = preg_replace('/^\./m', '..', $cuerpo);

            fwrite($s, $cuerpo . "\r\n.\r\n");
            if ($codigo($leer()) !== 250) throw new \RuntimeException('El servidor no aceptó el mensaje');

            $decir('QUIT');
            fclose($s);
            return true;

        } catch (\Throwable $e) {
            $this->ultimoError = $e->getMessage();
            @fclose($s);
            error_log('[LibertyFin] correo a ' . $para . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Arma el HTML con la plantilla de la marca.
     * Estilos en línea: los clientes de correo tiran el <style>.
     */
    public static function plantilla($titulo, $cuerpoHtml, $boton = null)
    {
        $b = '';
        if ($boton) {
            $b = '<tr><td style="padding:8px 32px 28px"><a href="' . htmlspecialchars($boton[1], ENT_QUOTES)
               . '" style="display:inline-block;padding:13px 28px;background:#27ae60;color:#fff;'
               . 'text-decoration:none;border-radius:99px;font-weight:700;font-size:14px">'
               . htmlspecialchars($boton[0], ENT_QUOTES) . '</a></td></tr>';
        }
        return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:24px;'
            . 'background:#f1f4f2;font-family:system-ui,-apple-system,sans-serif">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            . 'style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;'
            . 'box-shadow:0 2px 10px rgba(0,0,0,.07)">'
            . '<tr><td style="padding:26px 32px 18px;border-bottom:1px solid #e6ebe8">'
            . '<span style="display:inline-block;width:34px;height:34px;border-radius:11px;'
            . 'background:#27ae60;color:#fff;text-align:center;line-height:34px;'
            . 'font-weight:800;font-size:16px">L</span>'
            . '<span style="margin-left:11px;font-size:17px;font-weight:700;color:#1b2420;'
            . 'vertical-align:middle">LibertyFin</span></td></tr>'
            . '<tr><td style="padding:26px 32px 6px"><h1 style="margin:0 0 14px;font-size:19px;'
            . 'color:#1b2420">' . htmlspecialchars($titulo, ENT_QUOTES) . '</h1>'
            . '<div style="font-size:14px;line-height:1.65;color:#43504a">' . $cuerpoHtml . '</div>'
            . '</td></tr>' . $b
            . '<tr><td style="padding:16px 32px 24px;border-top:1px solid #e6ebe8;'
            . 'font-size:11.5px;color:#6d7a74;line-height:1.55">'
            . 'Este correo se envió automáticamente desde LibertyFin. '
            . 'Si no esperabas recibirlo, ignóralo.</td></tr></table></body></html>';
    }
}
