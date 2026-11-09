<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PlataformaRepo;
use LibertyFin\Datos\BaseConocimientoRepo;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Avisos;
use LibertyFin\Vista\Plantilla;

/**
 * Tickets de soporte.
 *
 * La bandeja ordena por prioridad y luego por antigüedad, no al revés:
 * un ticket crítico de hace diez minutos va antes que uno bajo de hace
 * tres días. Ordenar solo por fecha hace que lo urgente espere su turno.
 */
final class TicketsControlador
{
    public function index()
    {
        $repo = new TicketRepo($this->principal());
        // Los que llevan días esperando o resueltos vencen solos (ver TicketRepo).
        $repo->vencerSolos();

        $filtros = [
            'estado'    => Peticion::opcion('estado',
                            array_merge(['activos'], array_keys(TicketRepo::ESTADOS)), 'activos'),
            'prioridad' => Peticion::opcion('prioridad',
                            array_keys(TicketRepo::PRIORIDADES), ''),
            'q'         => trim(Peticion::texto('q', '')),
        ];
        if (!empty($_GET['mios'])) $filtros['asignado'] = $_SESSION['usuario_id'] ?? 0;

        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 20;
        $totalT = count($repo->bandeja($filtros, 500));

        Plantilla::pagina('tickets/index', [
            'titulo'     => 'Tickets',
            'icono'      => 'alerta',
            'subtitulo'  => 'Soporte',
            'tickets'    => $repo->bandeja($filtros, $porPag, ($pagina - 1) * $porPag),
            'cifras'     => $repo->cifras(),
            'categorias' => $repo->porCategoria(),
            'filtros'    => $filtros,
            'mios'       => !empty($_GET['mios']),
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($totalT / $porPag)),
            'totalT'     => $totalT,
            'empresas'   => (new PlataformaRepo($this->principal()))->empresas(),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ver($id)
    {
        $repo = new TicketRepo($this->principal());
        $repo->vencerSolos();
        $t = $repo->uno($id);
        if (!$t) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrado','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        $mensajes = $repo->mensajes($id);
        $this->leyendo($repo, $t, $mensajes);
        Plantilla::pagina('tickets/detalle', [
            'titulo'    => $t['folio'],
            'icono'     => 'alerta',
            'subtitulo' => $t['asunto'],
            't'         => $t,
            'mensajes'  => $mensajes,
            // La foto de cada quien: la del cliente sale de su empresa. Aquí
            // dentro el equipo se ve entre sí aunque no la muestre al cliente.
            'fotos'     => $repo->fotos($mensajes, $t['nombre_base_datos'] ?? null, false),
            'eventos'   => $repo->eventos($id),
            // Las plantillas se cargan aquí y no en otra pantalla: una
            // respuesta guardada que hay que ir a buscar a otro lado no se
            // usa, y entonces da igual tenerla.
            'plantillas'=> (new BaseConocimientoRepo($this->principal()))->plantillas(),
            'ayuda'     => (new BaseConocimientoRepo($this->principal()))
                             ->buscar('', 'error', $t['categoria'], 4),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function crear()
    {
        if (!$this->token()) $this->a('/tickets', 'No se pudo verificar el formulario.', 'error');
        $adjunto = null;
        try {
            // La evidencia va con el ticket desde que se abre.
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            $r = (new TicketRepo($this->principal()))->crear(
                $_POST, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '', $adjunto);
            $this->a('/tickets/' . $r['id'], 'Ticket ' . $r['folio'] . ' creado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            $this->a('/tickets', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            error_log('[LibertyFin] ticket crear: ' . $e->getMessage());
            $this->a('/tickets', 'No se pudo crear el ticket.', 'error');
        }
    }

    public function responder($id)
    {
        if (!$this->token()) $this->a('/tickets/' . $id, 'No se pudo verificar el formulario.', 'error');
        try {
            // El adjunto: ya subido mientras escribía (solo llega su
            // clave), o con el mensaje, como antes.
            $adjunto = null;
            $previo = trim((string)($_POST['adjunto_previo'] ?? ''));
            if ($previo !== '') {
                $adjunto = \LibertyFin\Servicio\AdjuntoPrevio::ruta($id, $previo);
                if (!$adjunto) $this->a('/tickets/' . $id, 'El archivo ya no está disponible. Adjúntalo de nuevo.', 'error');
            } elseif (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            $repo = new TicketRepo($this->principal());
            $nuevo = $repo->responder(
                $id, $_POST['cuerpo'] ?? '', !empty($_POST['interno']), $adjunto,
                $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
            if ($previo !== '') \LibertyFin\Servicio\AdjuntoPrevio::usado($previo);

            // EL CORREO "RESPONDIMOS TU TICKET":
            //   · solo a quien ABRIÓ el ticket. Antes iba al administrador de
            //     la empresa, que no siempre es parte de la conversación.
            //   · una vez por tanda: si ya tenía una respuesta sin leer, ese
            //     correo ya salió; y nunca si tiene el chat abierto.
            //   · una nota interna nunca: el cliente ni siquiera la ve.
            //   · se manda DESPUÉS de contestar: el chat no espera al SMTP.
            $aviso = '';
            if (empty($_POST['interno']) && $nuevo && $repo->debeAvisarCliente($id, $nuevo)) {
                $t = $repo->uno($id);
                $para = $t ? $repo->correoDelCreador($t) : '';
                if ($para) {
                    $cuerpo = trim((string)($_POST['cuerpo'] ?? '')) ?: 'Adjuntó un archivo.';
                    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                         . ($_SERVER['HTTP_HOST'] ?? '') . '/ayuda?ver=' . (int)$id;
                    Avisos::despues(function () use ($para, $t, $cuerpo, $url) {
                        Avisos::ticketRespondido($para, $t['folio'], $t['asunto'], $cuerpo, $url);
                    });
                    $aviso = ' Se le avisa por correo.';
                }
            }
            // Y el aviso de escritorio, a quien abrió el reporte (si lo
            // activó). Se manda siempre: si tiene LibertyFin a la vista, su
            // navegador no lo muestra (lf-sw.js). Nunca por una nota
            // interna: el cliente ni la ve.
            if (empty($_POST['interno']) && $nuevo) {
                $tp = $repo->uno($id);
                if ($tp) {
                    Avisos::despues(function () use ($tp) { \LibertyFin\Servicio\Push::aCliente($tp); });
                }
            }
            if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
                $this->json(['ok' => true, 'mensaje' => 'Respuesta agregada.' . $aviso, 'id' => (int)$nuevo]);
            }
            $this->a('/tickets/' . $id, 'Respuesta agregada.' . $aviso, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a('/tickets/' . $id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ticket responder: ' . $e->getMessage());
            $this->a('/tickets/' . $id, 'No se pudo agregar la respuesta.', 'error');
        }
    }

    public function cambiar($id)
    {
        if (!$this->token()) $this->a('/tickets/' . $id, 'No se pudo verificar el formulario.', 'error');
        $repo = new TicketRepo($this->principal());
        try {
            if (!empty($_POST['asignarme'])) {
                $repo->asignar($id, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '',
                               $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                $this->a('/tickets/' . $id, 'Te lo asignaste.', 'ok');
            }
            if (!empty($_POST['soltar'])) {
                $repo->asignar($id, null, null,
                               $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                $this->a('/tickets/' . $id, 'Ticket sin asignar.', 'ok');
            }
            foreach (['estado','prioridad','categoria'] as $campo) {
                if (!empty($_POST[$campo])) {
                    $repo->cambiar($id, $campo, $_POST[$campo],
                                   $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                }
            }
            $this->a('/tickets/' . $id, 'Ticket actualizado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a('/tickets/' . $id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ticket cambiar: ' . $e->getMessage());
            $this->a('/tickets/' . $id, 'No se pudo actualizar.', 'error');
        }
    }

    /**
     * EL CHAT · los mensajes nuevos de un ticket, para soporte (JSON).
     * Incluye las notas internas: aquí sí se ven.
     */
    public function mensajes($id)
    {
        $repo = new TicketRepo($this->principal());
        $t = $repo->uno($id);
        if (!$t) $this->json(['ok' => false, 'error' => 'Ese ticket no existe.'], 404);
        $desde = Peticion::entero('desde');
        // Solo cuenta como "en la conversación" y como leído si la pestaña
        // está A LA VISTA (`oculta` lo dice el chat): una en segundo plano
        // sigue preguntando sin que nadie la lea.
        $viendo = Peticion::entero('oculta') !== 1;
        if ($viendo) $this->leyendo($repo, $t, []);
        // En vivo: espera hasta 20 s a que haya algo nuevo (ver AyudaControlador::esperar).
        if (Peticion::entero('esperar') === 1) {
            AyudaControlador::esperar($repo, $id, $desde, true, 'cliente', Peticion::texto('escribe'), 'El cliente');
        }
        $nuevos = $repo->mensajesDesde($id, $desde, true);
        $fotos  = $nuevos ? $repo->fotos($nuevos, $t['nombre_base_datos'] ?? null, false) : [];
        // Leído: lo que se acaba de entregar y lo que ya tenía en pantalla
        // (`visto`, al volver a la pestaña), nunca más allá del último.
        if ($viendo) {
            $this->leyendo($repo, $t, $nuevos, min(Peticion::entero('visto'), $repo->ultimoId($id, true)));
        }
        $esc    = $repo->escribiendoAhora($id);
        $this->json([
            'ok'       => true,
            'estado'   => $t['estado'],
            'cerrado'  => $t['estado'] === 'cerrado',
            // El cliente está tecleando.
            'escribiendo' => $esc['cliente'] !== null ? ($esc['cliente'] ?: 'El cliente') : null,
            'mensajes' => array_map(function ($m) use ($fotos) {
                return TicketRepo::aJson($m, $fotos, 'soporte');
            }, $nuevos),
        ]);
    }

    /**
     * Sube el adjunto ANTES de enviar la respuesta (ver
     * Servicio\AdjuntoPrevio): el chat lo manda en cuanto se pega o elige.
     */
    public function adjunto($id)
    {
        if (!$this->token()) $this->json(['ok' => false, 'error' => 'No se pudo verificar el formulario.'], 403);
        if (!(new TicketRepo($this->principal()))->uno($id)) {
            $this->json(['ok' => false, 'error' => 'Ese ticket no existe.'], 404);
        }
        $this->json(\LibertyFin\Servicio\AdjuntoPrevio::subir((int)$id));
    }

    /**
     * Soporte está tecleando en este ticket. Las notas internas no se
     * avisan: el chat no llama aquí si está marcada "Nota interna".
     */
    public function escribiendo($id)
    {
        if (!$this->token()) $this->json(['ok' => false], 403);
        (new TicketRepo($this->principal()))->escribiendo($id, 'soporte', $_SESSION['usuario_nombre'] ?? '');
        $this->json(['ok' => true]);
    }

    /**
     * Quien ATIENDE el ticket lo tiene abierto: está en la conversación (no
     * hace falta correo) y leyó lo que se le muestra. Si lo mira otro de
     * soporte no cuenta: el aviso es para quien lo atiende.
     */
    private function leyendo(TicketRepo $repo, array $t, array $mensajes, $hasta = 0)
    {
        $tope = max((int)$hasta, $mensajes ? max(array_column($mensajes, 'id')) : 0);
        // Lo que tiene enfrente, cualquiera de soporte, ya lo leyó: deja de
        // salirle en la campana aunque todavía no conteste.
        $yo = (int)($_SESSION['usuario_id'] ?? 0);
        if ($tope) $repo->marcarLeido($t['id'], $yo, $tope);

        // Y quien ATIENDE el ticket, además, está en la conversación: con
        // eso se decide si hace falta mandarle correo.
        if ((int)($t['asignado_a'] ?? 0) !== $yo) return;
        $repo->marcarActivo($t['id'], 'soporte');
        if ($tope) $repo->marcarVistoSoporte($t['id'], $tope);
    }

    /**
     * LAS NOVEDADES DE SOPORTE (JSON): los tickets que esperan respuesta de
     * quien pregunta —los suyos y los que nadie ha tomado—. Lo consulta cada
     * página de soporte para la cuenta del menú y el aviso.
     */
    public function novedades()
    {
        $yo = (int)($_SESSION['usuario_id'] ?? 0);
        $f  = (new TicketRepo($this->principal()))->novedadesSoporte($yo);
        $this->json([
            'ok'        => true,
            'esperando' => count($f),
            'tickets'   => array_map(function ($t) use ($yo) {
                $txt = trim(preg_replace('/\s+/u', ' ', (string)$t['cuerpo']));
                return [
                    'id'         => (int)$t['id'],
                    'folio'      => $t['folio'],
                    'asunto'     => $t['asunto'],
                    'empresa'    => $t['nombre_empresa'] ?? '',
                    'mensaje_id' => (int)$t['mensaje_id'],
                    'autor'      => $t['autor'] ?: 'El cliente',
                    'extracto'   => mb_strlen($txt) > 120 ? mb_substr($txt, 0, 117) . '…' : $txt,
                    'mio'        => (int)$t['asignado_a'] === $yo,
                    // Nadie lo ha tomado: cualquiera de soporte puede.
                    'libre'      => empty($t['asignado_a']),
                    // Es el primer mensaje: un ticket recién abierto.
                    'nuevo'      => !empty($t['es_primero']),
                    'prioridad'  => $t['prioridad'] ?? 'normal',
                    // Segundos desde el mensaje, con el reloj de la base:
                    // así no importa la zona horaria del navegador.
                    'hace'       => max(0, (int)($t['hace'] ?? 0)),
                ];
            }, $f),
        ]);
    }

    private function json(array $datos, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($ruta, $texto, $tipo)
    {
        // Enviado desde el chat (sin recargar): JSON y sin aviso en la sesión.
        if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
            $this->json(['ok' => $tipo === 'ok', 'mensaje' => $texto]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $ruta); exit;
    }
}
