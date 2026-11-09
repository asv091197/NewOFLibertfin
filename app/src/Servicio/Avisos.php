<?php
namespace LibertyFin\Servicio;

/**
 * Los avisos por correo.
 *
 * QUÉ SE AVISA Y QUÉ NO
 *
 * Solo lo que la persona no puede saber de otra forma y necesita saber
 * pronto. Un aviso por cada venta convierte el correo en ruido, y a las
 * dos semanas nadie lee ninguno — incluido el que sí importaba.
 *
 * Por eso son cinco y no veinte:
 *   · Tu cuenta está lista        (no hay forma de enterarse si no)
 *   · Tu solicitud se rechazó     (con el motivo, o no sabe qué corregir)
 *   · Un documento se rechazó     (igual)
 *   · Te contestaron tu ticket    (si no, tiene que entrar a revisar)
 *   · Tu suscripción vence        (una sola vez, no todos los días)
 *
 * Ninguno tumba nada si falla: `enviar()` devuelve false y se sigue.
 */
final class Avisos
{
    private static function correo()
    {
        $cfg = Integraciones::de('smtp');
        return $cfg ? new Correo($cfg) : null;
    }

    public static function activos() { return Integraciones::activa('smtp'); }

    /**
     * Manda un aviso DESPUÉS de contestarle al navegador.
     *
     * Hablar con el SMTP tarda de uno a varios segundos, y en el chat eso
     * se sentía: el mensaje no salía hasta que el correo terminaba. Aquí
     * primero se entrega la respuesta y luego, ya sin nadie esperando, se
     * manda el correo. Se suelta la sesión antes, para no frenar las demás
     * pantallas del usuario mientras tanto.
     *
     * Con PHP-FPM o LiteSpeed la respuesta sale al momento; con mod_php no
     * hay cómo cortarla antes y simplemente tarda lo mismo que antes.
     */
    public static function despues(callable $enviar)
    {
        register_shutdown_function(function () use ($enviar) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            @ignore_user_abort(true);
            @set_time_limit(60);
            try { $enviar(); }
            catch (\Throwable $e) { error_log('[LibertyFin] aviso diferido: ' . $e->getMessage()); }
        });
    }

    /** La cuenta quedó lista. Lleva la contraseña, una sola vez. */
    public static function cuentaCreada($para, $nombre, $empresa, $usuario, $clave, $url)
    {
        $c = self::correo();
        if (!$c) return false;

        // La contraseña va en el correo porque es la única forma de
        // entregarla sin una llamada. Por eso se pide cambiarla al entrar:
        // un correo se queda en la bandeja para siempre.
        $html = Correo::plantilla('Tu cuenta de LibertyFin está lista',
            '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES) . ',</p>'
          . '<p>Ya puedes entrar a <b>' . htmlspecialchars($empresa, ENT_QUOTES) . '</b>.</p>'
          . '<table cellpadding="0" cellspacing="0" style="margin:18px 0;background:#f6f8f7;'
          . 'border-radius:10px;padding:4px 16px;width:100%">'
          . '<tr><td style="padding:9px 0;color:#6d7a74;font-size:13px">Usuario</td>'
          . '<td style="padding:9px 0;text-align:right;font-family:ui-monospace,monospace;'
          . 'font-weight:700">' . htmlspecialchars($usuario, ENT_QUOTES) . '</td></tr>'
          . '<tr><td style="padding:9px 0;color:#6d7a74;font-size:13px">Contraseña</td>'
          . '<td style="padding:9px 0;text-align:right;font-family:ui-monospace,monospace;'
          . 'font-weight:700">' . htmlspecialchars($clave, ENT_QUOTES) . '</td></tr></table>'
          . '<p style="font-size:13px;color:#6d7a74">Cámbiala en cuanto entres: este correo se '
          . 'queda en tu bandeja y cualquiera que lo vea podría usarla.</p>',
            ['Entrar a LibertyFin', $url]);

        return $c->enviar($para, 'Tu cuenta de LibertyFin está lista', $html);
    }

    /** La solicitud se rechazó. El motivo es lo único que tiene. */
    public static function solicitudRechazada($para, $nombre, $motivo)
    {
        $c = self::correo();
        if (!$c) return false;
        $html = Correo::plantilla('Sobre tu solicitud de registro',
            '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES) . ',</p>'
          . '<p>Revisamos tu solicitud y por ahora no podemos darla de alta:</p>'
          . '<p style="padding:13px 16px;background:#fbf3e0;border-radius:10px;'
          . 'color:#8a6410;margin:16px 0">' . nl2br(htmlspecialchars($motivo, ENT_QUOTES)) . '</p>'
          . '<p>Si crees que hay un error o ya lo corregiste, respóndenos este correo.</p>');
        return $c->enviar($para, 'Sobre tu solicitud de registro en LibertyFin', $html);
    }

    /** Un documento se rechazó, con el motivo. */
    public static function documentoRechazado($para, $documento, $motivo, $url)
    {
        $c = self::correo();
        if (!$c) return false;
        $html = Correo::plantilla('Un documento necesita corrección',
            '<p>Revisamos <b>' . htmlspecialchars($documento, ENT_QUOTES) . '</b> y hay que '
          . 'volver a subirlo:</p>'
          . '<p style="padding:13px 16px;background:#fbf3e0;border-radius:10px;'
          . 'color:#8a6410;margin:16px 0">' . nl2br(htmlspecialchars($motivo, ENT_QUOTES)) . '</p>'
          . '<p style="font-size:13px;color:#6d7a74">Mientras la documentación no esté aprobada '
          . 'puedes registrar ventas, pero no cobrar con tarjeta ni facturar.</p>',
            ['Subir el documento', $url]);
        return $c->enviar($para, 'Un documento necesita corrección', $html);
    }

    /** Alguien contestó un ticket. */
    public static function ticketRespondido($para, $folio, $asunto, $respuesta, $url)
    {
        $c = self::correo();
        if (!$c) return false;
        $corte = mb_substr($respuesta, 0, 400);
        $html = Correo::plantilla('Respondimos tu ticket',
            '<p style="color:#6d7a74;font-size:13px;font-family:ui-monospace,monospace">'
          . htmlspecialchars($folio, ENT_QUOTES) . '</p>'
          . '<p style="font-weight:600;margin-bottom:14px">'
          . htmlspecialchars($asunto, ENT_QUOTES) . '</p>'
          . '<p style="padding:14px 16px;background:#f6f8f7;border-radius:10px;'
          . 'border-left:3px solid #27ae60">'
          . nl2br(htmlspecialchars($corte, ENT_QUOTES))
          . (mb_strlen($respuesta) > 400 ? '…' : '') . '</p>',
            ['Ver el ticket', $url]);
        return $c->enviar($para, 'Respondimos tu ticket ' . $folio, $html);
    }

    /**
     * A quien ATIENDE un ticket: el cliente contestó. Una sola vez por
     * tanda (ver TicketRepo::debeAvisarSoporte) y SIN el texto del mensaje:
     * el correo puede reenviarse o leerse en otro lado, y la conversación
     * se lee dentro de la plataforma.
     */
    public static function ticketClienteRespondio($para, $folio, $asunto, $empresa, $url)
    {
        $c = self::correo();
        if (!$c) return false;
        $html = Correo::plantilla('El cliente respondió',
            '<p style="color:#6d7a74;font-size:13px;font-family:ui-monospace,monospace">'
          . htmlspecialchars($folio, ENT_QUOTES)
          . ($empresa !== '' ? ' · ' . htmlspecialchars($empresa, ENT_QUOTES) : '') . '</p>'
          . '<p style="font-weight:600;margin-bottom:14px">' . htmlspecialchars($asunto, ENT_QUOTES) . '</p>'
          . '<p>Hay una respuesta nueva en un ticket que atiendes. Entra para leerla y contestar.</p>',
            ['Abrir el ticket', $url]);
        return $c->enviar($para, 'El cliente respondió el ticket ' . $folio, $html);
    }

    /** La suscripción está por vencer. Una sola vez, no todos los días. */
    public static function porVencer($para, $nombre, $empresa, $dias, $fecha)
    {
        $c = self::correo();
        if (!$c) return false;
        $html = Correo::plantilla(
            $dias < 0 ? 'Tu suscripción venció' : 'Tu suscripción está por vencer',
            '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES) . ',</p>'
          . '<p>La suscripción de <b>' . htmlspecialchars($empresa, ENT_QUOTES) . '</b> '
          . ($dias < 0
                ? 'venció el ' . htmlspecialchars($fecha, ENT_QUOTES) . '.'
                : 'vence el ' . htmlspecialchars($fecha, ENT_QUOTES)
                  . ', en ' . (int)$dias . ' día' . ($dias == 1 ? '' : 's') . '.') . '</p>'
          . '<p style="font-size:13px;color:#6d7a74">Tu sistema sigue funcionando: no cortamos '
          . 'el acceso de golpe. Escríbenos para renovar.</p>');
        return $c->enviar($para,
            $dias < 0 ? 'Tu suscripción de LibertyFin venció'
                      : 'Tu suscripción de LibertyFin vence pronto', $html);
    }

    /**
     * Se revisó el comprobante de un plan. Aprobado: dice hasta cuándo
     * queda pagado. Rechazado: el motivo, que es lo único que sirve para
     * corregirlo.
     */
    public static function pagoPlanRevisado($para, $nombre, $plan, $aprobado, $detalle, $url)
    {
        $c = self::correo();
        if (!$c) return false;
        $n = htmlspecialchars($nombre ?: 'hola', ENT_QUOTES);
        $p = htmlspecialchars($plan, ENT_QUOTES);
        if ($aprobado) {
            $html = Correo::plantilla('Recibimos tu pago',
                '<p>Hola ' . $n . ',</p>'
              . '<p>Verificamos tu transferencia: el plan <b>' . $p . '</b> quedó activo.</p>'
              . '<p style="padding:13px 16px;background:#eaf6ee;border-radius:10px;'
              . 'color:#1d7a43;margin:16px 0">Vigente hasta el <b>'
              . htmlspecialchars($detalle, ENT_QUOTES) . '</b>.</p>'
              . '<p style="font-size:13px;color:#6d7a74">Gracias por seguir con nosotros.</p>',
                ['Ver mi plan', $url]);
            return $c->enviar($para, 'Recibimos tu pago de LibertyFin', $html);
        }
        $html = Correo::plantilla('No pudimos validar tu pago',
            '<p>Hola ' . $n . ',</p>'
          . '<p>Revisamos el comprobante de tu plan <b>' . $p . '</b> y hay que volver a subirlo:</p>'
          . '<p style="padding:13px 16px;background:#fbf3e0;border-radius:10px;'
          . 'color:#8a6410;margin:16px 0">' . nl2br(htmlspecialchars($detalle, ENT_QUOTES)) . '</p>'
          . '<p style="font-size:13px;color:#6d7a74">Tu plan sigue como estaba. En cuanto subas '
          . 'un comprobante correcto lo revisamos de nuevo.</p>',
            ['Subir el comprobante', $url]);
        return $c->enviar($para, 'No pudimos validar tu pago de LibertyFin', $html);
    }

    /** Prueba: se manda a uno mismo para comprobar el SMTP. */
    public static function prueba($para)
    {
        $c = self::correo();
        if (!$c) return ['ok' => false, 'error' => 'SMTP sin configurar'];
        $ok = $c->enviar($para, 'Prueba de correo de LibertyFin',
            Correo::plantilla('El correo funciona',
                '<p>Si estás leyendo esto, la configuración de SMTP es correcta y los avisos '
              . 'de LibertyFin van a llegar.</p>'
              . '<p style="font-size:13px;color:#6d7a74">Enviado el '
              . date('d/m/Y \a \l\a\s H:i') . '.</p>'));
        return ['ok' => $ok, 'error' => $ok ? '' : $c->error()];
    }
}
