<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Ayuda · la puerta de entrada de los tickets.
 *
 * Hasta ahora los tickets existían pero nadie de la empresa podía abrir
 * uno: la única bandeja era la de soporte, y los tickets aparecían por
 * arte de magia. Si la única puerta fuera el correo, media queja se
 * pierde y la otra media llega sin folio ni contexto.
 *
 * Aquí cada quien ve SOLO los tickets de su empresa. Y no puede cambiar
 * estado, prioridad ni asignación: si el cliente pudiera marcar su
 * propio ticket como crítico, en dos semanas todo sería crítico y la
 * prioridad dejaría de significar nada.
 */
final class AyudaControlador
{
    /**
     * El tutorial completo, paso a paso. Lo ve cualquiera con sesión.
     *
     * Se le pasan los módulos encendidos y las secciones apagadas para que
     * los atajos «Ir a…» de cada capítulo solo aparezcan si esa persona
     * puede entrar ahí: un botón que lleva a «Esta sección no es para tu
     * rol» enseña lo contrario de lo que se quiere.
     */
    public function tutorial()
    {
        $modulos = []; $apagadas = [];
        try {
            $cfg = new \LibertyFin\Datos\ConfigRepo(Conexion::de($_SESSION['empresa_db'] ?? ''));
            foreach (\LibertyFin\Datos\ConfigRepo::APAGABLES as $k => $_) {
                if (!$cfg->seccionActiva($k)) $apagadas[$k] = true;
            }
            $modulos = (new \LibertyFin\Servicio\Modulos($cfg))->activos();
        } catch (\Throwable $e) { /* sin empresa: el tutorial se lee igual, sin atajos de módulos */ }

        Plantilla::pagina('ayuda/tutorial', [
            'titulo'    => 'Tutorial',
            'icono'     => 'libro',
            'subtitulo' => 'Aprende LibertyFin paso a paso',
            'modulos'   => $modulos,
            'apagadas'  => $apagadas,
        ]);
    }

    public function index()
    {
        $repo = new TicketRepo($this->principal());
        // Que el cliente vea el estado al día antes de escribir.
        $repo->vencerSolos();
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);

        $ver    = Peticion::entero('ver');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;

        // Las dos se calculan UNA vez. Antes esto terminaba con
        //     ['mensajes' => []] + $this->conversacion(...)
        // y el `+` de arreglos conserva la clave de la IZQUIERDA, así que
        // los mensajes llegaban siempre vacíos: el cliente abría su
        // ticket y no veía ninguna respuesta de soporte.
        $abierto  = $this->miTicket($repo, $ver, $emp);
        $mensajes = $abierto ? $this->mensajesVisibles($repo, $ver) : [];
        // Quien abrió el ticket lo está viendo: lo de soporte ya no es novedad.
        if ($abierto && $mensajes && $this->esCreador($abierto)) {
            $repo->marcarVistoCliente($ver, max(array_column($mensajes, 'id')));
        }

        // Solo los reportes que abrió esta persona, no los de toda la empresa.
        $mios = ['empresa' => $emp, 'creador' => (int)($_SESSION['usuario_id'] ?? 0)];
        Plantilla::pagina('ayuda/index', [
            'titulo'     => 'Ayuda',
            'icono'      => 'alerta',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            'tickets'    => ($emp && $mios['creador']) ? $repo->bandeja($mios, $porPag,
                                                  ($pagina-1)*$porPag) : [],
            'pagina'     => $pagina,
            'paginas'    => ($emp && $mios['creador']) ? max(1, (int)ceil(
                                count($repo->bandeja($mios, 500)) / $porPag)) : 1,
            'abierto'    => $abierto,
            'mensajes'   => $mensajes,
            // La de soporte, solo si esa persona eligió mostrarla.
            'fotos'      => $abierto ? $repo->fotos($mensajes, $_SESSION['empresa_db'] ?? null, true) : [],
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Un ticket, solo si es de MI empresa.
     * Sin esta comprobación, cambiar el número en la URL dejaría leer
     * las conversaciones de otros clientes.
     */
    /**
     * El ticket, solo si lo abrió QUIEN PREGUNTA (y es de su empresa).
     *
     * Antes bastaba con que fuera de la misma empresa: cualquier usuario
     * veía los reportes de todos sus compañeros, con sus mensajes y
     * capturas. Cada quien ve los suyos. Todo lo del chat del cliente pasa
     * por aquí (ver, mensajes, responder, adjuntar, "escribiendo").
     */
    private function miTicket(TicketRepo $repo, $id, $empresaId)
    {
        if (!$id || !$empresaId) return null;
        $t = $repo->uno($id);
        return ($t && (int)$t['empresa_id'] === $empresaId && $this->esCreador($t)) ? $t : null;
    }

    /**
     * Los mensajes que el cliente SÍ puede ver.
     *
     * Las notas internas quedan fuera: están escritas para el equipo de
     * soporte, con lenguaje y detalles que no son para el cliente.
     */
    private function mensajesVisibles(TicketRepo $repo, $id)
    {
        return array_values(array_filter($repo->mensajes($id), function ($x) {
            return empty($x['interno']);
        }));
    }

    public function crear()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $adjunto = null;
        try {
            $datos = $_POST;
            // La empresa se toma de la sesión, nunca del formulario.
            $datos['empresa_id'] = (int)($_SESSION['empresa_id'] ?? 0);
            // Y la prioridad la pone soporte al leerlo, no quien reporta.
            $datos['prioridad'] = 'normal';

            // La evidencia va con el reporte, no después: una captura junto
            // a la descripción ahorra la primera pregunta de soporte.
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }

            $repoT = new TicketRepo($this->principal());
            $r = $repoT->crear(
                $datos, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '', $adjunto);
            // Aviso de escritorio a soporte (a quien lo tenga activado),
            // después de contestar: el cliente no espera a eso.
            $nuevoT = $repoT->uno($r['id']);
            if ($nuevoT) {
                \LibertyFin\Servicio\Avisos::despues(function () use ($nuevoT) {
                    \LibertyFin\Servicio\Push::aSoporte($nuevoT, true);
                });
            }
            // Directo a SU conversación: ahí ve su primer mensaje y puede
            // agregar algo. Antes volvía a la lista y parecía que el reporte
            // no tenía chat.
            $this->a('Listo, tu reporte quedó con el folio ' . $r['folio']
                . '. Te contestamos aquí mismo; si cierras esta pantalla, te avisamos cuando respondamos.',
                'ok', $r['id']);
        } catch (\InvalidArgumentException $e) {
            // Si el reporte no se creó, el archivo subido sobra.
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            error_log('[LibertyFin] ayuda/crear: ' . $e->getMessage());
            $this->a('No se pudo enviar el reporte.', 'error');
        }
    }

    public function responder($id)
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $repo = new TicketRepo($this->principal());
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);
        $t    = $this->miTicket($repo, $id, $emp);
        if (!$t) $this->a('Ese ticket no es tuyo.', 'error');
        try {
            // El adjunto: ya subido mientras escribía (solo se manda su
            // clave), o con el mensaje, como antes.
            $adjunto = null;
            $previo = trim((string)($_POST['adjunto_previo'] ?? ''));
            if ($previo !== '') {
                $adjunto = \LibertyFin\Servicio\AdjuntoPrevio::ruta($id, $previo);
                if (!$adjunto) $this->a('El archivo ya no está disponible. Adjúntalo de nuevo.', 'error', $id);
            } elseif (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            // `interno` va en false SIEMPRE: el cliente no escribe notas
            // internas, y dejar que llegue por POST sería regalárselas.
            $nuevo = $repo->responder($id, $_POST['cuerpo'] ?? '', false, $adjunto,
                $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
            if ($previo !== '') \LibertyFin\Servicio\AdjuntoPrevio::usado($previo);

            // UN aviso a quien ATIENDE el ticket —solo a esa persona, no a
            // todo soporte— y solo con el primer mensaje de la tanda y si no
            // tiene la conversación abierta. Sin el texto del mensaje: se lee
            // dentro de la plataforma.
            if ($nuevo && $repo->debeAvisarSoporte($id, $nuevo)) {
                $para = $repo->correoDelAgente($t);
                if ($para) {
                    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                         . ($_SERVER['HTTP_HOST'] ?? '') . '/tickets/' . (int)$id;
                    // Después de contestar: el chat no espera al correo.
                    \LibertyFin\Servicio\Avisos::despues(function () use ($para, $t, $url) {
                        \LibertyFin\Servicio\Avisos::ticketClienteRespondio($para, $t['folio'], $t['asunto'],
                            $t['nombre_empresa'] ?? '', $url);
                    });
                }
            }
            // Y el aviso de escritorio (a quien lo tenga activado). Se manda
            // SIEMPRE: si la persona tiene LibertyFin a la vista, su
            // navegador no lo muestra (lf-sw.js), y eso se sabe al instante.
            // Decidirlo aquí con "estuvo en el chat hace menos de 90 s" lo
            // frenaba justo después de salir del chat.
            if ($nuevo) {
                \LibertyFin\Servicio\Avisos::despues(function () use ($t) {
                    \LibertyFin\Servicio\Push::aSoporte($t);
                });
            }
            // El chat recibe el id del mensaje para cambiar el "enviando…"
            // por el mensaje de verdad.
            if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
                $this->json(['ok' => true, 'mensaje' => 'Respuesta enviada.', 'id' => (int)$nuevo]);
            }
            $this->a('Respuesta enviada.', 'ok', $id);
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error', $id);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ayuda/responder: ' . $e->getMessage());
            $this->a('No se pudo enviar.', 'error', $id);
        }
    }

    /**
     * EL CHAT · los mensajes nuevos de un ticket (JSON).
     *
     * Lo pide la conversación cada pocos segundos con `?desde=` el último
     * que ya tiene. Si quien pregunta es quien abrió el ticket, lo que se
     * le entrega queda como visto.
     */
    public function mensajes($id)
    {
        $repo = new TicketRepo($this->principal());
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);
        $t    = $this->miTicket($repo, (int)$id, $emp);
        if (!$t) $this->json(['ok' => false, 'error' => 'Ese ticket no es tuyo.'], 404);
        $creador = $this->esCreador($t);
        // Quien abrió el ticket tiene el chat abierto: no hace falta correo.
        // Pero SOLO si la pestaña está a la vista (`oculta` lo dice el
        // chat). Una pestaña en segundo plano sigue preguntando sin que
        // nadie la lea: si contara, no se avisaría aunque la persona se
        // hubiera ido, y sus mensajes quedarían como leídos sin verlos.
        $viendo = $creador && Peticion::entero('oculta') !== 1;
        if ($viendo) $repo->marcarActivo($id, 'cliente');

        $desde = Peticion::entero('desde');
        // EN VIVO: con `esperar=1` la respuesta no sale hasta que haya algo
        // nuevo —un mensaje, o soporte empezó o dejó de escribir— o pasen
        // 20 s. Llega en menos de un segundo en vez de esperar la siguiente
        // pregunta.
        if (Peticion::entero('esperar') === 1) {
            self::esperar($repo, $id, $desde, false, 'soporte', Peticion::texto('escribe'), 'Soporte');
        }

        $nuevos = $repo->mensajesDesde($id, $desde, false);
        $fotos  = $nuevos ? $repo->fotos($nuevos, $_SESSION['empresa_db'] ?? null, true) : [];
        // Leído: lo que se le acaba de entregar y lo que ya tenía en
        // pantalla (`visto`, al volver a la pestaña), nunca más allá del
        // último mensaje que existe.
        if ($viendo) {
            $hasta = max($nuevos ? max(array_column($nuevos, 'id')) : 0,
                         min(Peticion::entero('visto'), $repo->ultimoId($id, false)));
            if ($hasta) $repo->marcarVistoCliente($id, $hasta);
        }
        $esc = $repo->escribiendoAhora($id);
        $this->json([
            'ok'       => true,
            'estado'   => $t['estado'],
            'cerrado'  => $t['estado'] === 'cerrado',
            // Soporte está tecleando: "Ana está escribiendo…".
            'escribiendo' => $esc['soporte'] !== null ? ($esc['soporte'] ?: 'Soporte') : null,
            'mensajes' => array_map(function ($m) use ($fotos) {
                return TicketRepo::aJson($m, $fotos, 'cliente');
            }, $nuevos),
        ]);
    }

    /**
     * Espera, sin bloquear al usuario, a que haya algo nuevo en el ticket.
     *
     * Se suelta la sesión antes: PHP la tiene bloqueada mientras un pedido
     * la usa, y una espera de 20 s dejaría congeladas las demás pantallas
     * del mismo usuario. Pregunta cada 0.35 s con una consulta ligera.
     *
     * @param string $ladoOtro   'soporte' o 'cliente': de quién importa el "escribiendo".
     * @param string $conoce     lo que el chat ya sabe que escribe el otro ('' = nadie).
     * @param string $porDefecto nombre a mostrar si no se guardó uno.
     */
    public static function esperar(TicketRepo $repo, $id, $desde, $conInternos, $ladoOtro, $conoce, $porDefecto)
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(45);
        $fin = microtime(true) + 20;
        do {
            $p = $repo->pulso($id, $conInternos);
            if ($p['ultimo'] > (int)$desde) return;
            $ahora = $p[$ladoOtro] !== null ? ($p[$ladoOtro] ?: $porDefecto) : '';
            if ($ahora !== (string)$conoce) return;
            usleep(350000);
        } while (microtime(true) < $fin);
    }

    /**
     * Sube el adjunto ANTES de enviar el mensaje (ver Servicio\AdjuntoPrevio):
     * el chat lo manda en cuanto se pega o elige la imagen.
     */
    public function adjunto($id)
    {
        if (!$this->token()) $this->json(['ok' => false, 'error' => 'No se pudo verificar el formulario.'], 403);
        $repo = new TicketRepo($this->principal());
        if (!$this->miTicket($repo, (int)$id, (int)($_SESSION['empresa_id'] ?? 0))) {
            $this->json(['ok' => false, 'error' => 'Ese ticket no es tuyo.'], 404);
        }
        $this->json(\LibertyFin\Servicio\AdjuntoPrevio::subir((int)$id));
    }

    /** El cliente está tecleando en este ticket (lo avisa el chat). */
    public function escribiendo($id)
    {
        if (!$this->token()) $this->json(['ok' => false], 403);
        $repo = new TicketRepo($this->principal());
        if (!$this->miTicket($repo, (int)$id, (int)($_SESSION['empresa_id'] ?? 0))) {
            $this->json(['ok' => false], 404);
        }
        $repo->escribiendo($id, 'cliente', $_SESSION['usuario_nombre'] ?? '');
        $this->json(['ok' => true]);
    }

    /**
     * LAS NOVEDADES · lo que soporte le contestó a quien pregunta y todavía
     * no ve (JSON). Lo consulta cada página, cada tanto, para avisarle dentro
     * de la plataforma y abrirle el chat.
     */
    public function novedades()
    {
        $emp = (int)($_SESSION['empresa_id'] ?? 0);
        if (!$emp) $this->json(['ok' => true, 'sin_leer' => 0, 'tickets' => [], 'activo' => null]);
        $repo = new TicketRepo($this->principal());
        $yo   = (int)($_SESSION['usuario_id'] ?? 0);
        $n    = $repo->novedadesCliente($emp, $yo);
        // Los reportes de esta persona sin solucionar: los que se alternan
        // dentro del chat. El primero es el que abre la burbuja.
        $act  = array_map(function ($a) {
            $txt = trim(preg_replace('/\s+/u', ' ', (string)($a['ultimo'] ?? '')));
            return ['id' => (int)$a['id'], 'folio' => $a['folio'], 'asunto' => $a['asunto'],
                    'estado' => $a['estado'], 'sin_leer' => (int)$a['sin_leer'],
                    // El último mensaje, para la lista del chat flotante.
                    'ultimo' => mb_strlen($txt) > 70 ? mb_substr($txt, 0, 67) . '…' : $txt,
                    'de_soporte' => ($a['ultimo_tipo'] ?? '') === 'plataforma',
                    'hace'   => $a['hace'] !== null ? max(0, (int)$a['hace']) : null];
        }, $repo->activosCliente($emp, $yo));
        $this->json([
            'ok'       => true,
            'activos'  => $act,
            'activo'   => $act ? $act[0] : null,
            'sin_leer' => $n['sin_leer'],
            'tickets'  => array_map(function ($t) {
                $txt = trim(preg_replace('/\s+/u', ' ', (string)$t['cuerpo']));
                return [
                    'id'         => (int)$t['id'],
                    'folio'      => $t['folio'],
                    'asunto'     => $t['asunto'],
                    'mensaje_id' => (int)$t['mensaje_id'],
                    'autor'      => $t['autor'] ?: 'Soporte',
                    'extracto'   => mb_strlen($txt) > 120 ? mb_substr($txt, 0, 117) . '…' : $txt,
                    'nuevos'     => (int)$t['nuevos'],
                ];
            }, $n['tickets']),
        ]);
    }

    /** ¿Quien está viendo es quien abrió el ticket? Ver TicketRepo::esMio. */
    private function esCreador(array $t)
    {
        return (int)($t['creado_por'] ?? 0) === (int)($_SESSION['usuario_id'] ?? 0)
            && in_array($t['creado_tipo'] ?? null, [null, '', 'empresa'], true);
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

    private function a($texto, $tipo, $ver = null)
    {
        // Enviado desde el chat (sin recargar): se contesta en JSON y no se
        // deja aviso en la sesión, que saldría en la siguiente página.
        if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
            $this->json(['ok' => $tipo === 'ok', 'mensaje' => $texto]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ayuda' . ($ver ? '?ver=' . (int)$ver : '')); exit;
    }
}
