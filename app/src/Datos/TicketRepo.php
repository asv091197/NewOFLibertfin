<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Tickets de soporte.
 *
 * VIVEN EN LA BASE PRINCIPAL, no en la de cada empresa.
 *
 * Un ticket habla DE una empresa, no pertenece A una empresa. Si cada
 * base tuviera los suyos, la bandeja de un agente sería la unión de
 * treinta consultas, no se podría ordenar por antigüedad real, y el
 * reporte de "problemas más frecuentes" sería imposible.
 *
 * Además: cuando una empresa se da de baja, sus tickets son justo lo que
 * hay que conservar para entender por qué se fue.
 */
final class TicketRepo
{
    private $db;
    public function __construct(PDO $principal) { $this->db = $principal; }

    const ESTADOS = [
        'abierto'   => 'Abierto',
        'en_curso'  => 'En curso',
        'esperando' => 'Esperando al cliente',
        'resuelto'  => 'Resuelto',
        'cerrado'   => 'Cerrado',
    ];

    /**
     * Prioridad: [nombre, cuándo usarla]. Ordena la bandeja (lo urgente
     * primero); ya no lleva un tiempo de respuesta comprometido.
     */
    const PRIORIDADES = [
        'critica' => ['Crítica', 'No puede cobrar ni facturar'],
        'alta'    => ['Alta',    'Una función clave no sirve'],
        'normal'  => ['Normal',  'Molesta pero hay cómo seguir'],
        'baja'    => ['Baja',    'Duda o mejora'],
    ];

    const CATEGORIAS = [
        'acceso'      => 'No puedo entrar',
        'cobro'       => 'Problemas al cobrar',
        'cifras'      => 'Números que no cuadran',
        'facturacion' => 'Facturación y CFDI',
        'catalogo'    => 'Productos y precios',
        'usuarios'    => 'Usuarios y permisos',
        'recargas'    => 'Recargas',
        'otro'        => 'Otro',
    ];

    /**
     * Súbelo al cambiar lo que `asegurar()` crea o agrega: así cada sesión
     * vuelve a revisar una vez.
     */
    const ESQUEMA = 5;

    public function asegurar()
    {
        // YA REVISADO EN ESTA SESIÓN. Revisar cuesta tres CREATE TABLE y
        // una consulta a information_schema —lenta en un hosting
        // compartido—, y el chat pregunta varias veces por minuto: cada
        // mensaje esperaba eso de más. La estructura no cambia entre una
        // pregunta y otra; basta con revisarla una vez por sesión.
        if ((int)($_SESSION['lf_tickets_esquema'] ?? 0) === self::ESQUEMA) return;

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folio VARCHAR(20) NOT NULL,
                empresa_id INT NULL,
                asunto VARCHAR(220) NOT NULL,
                categoria VARCHAR(30) NOT NULL DEFAULT 'otro',
                prioridad VARCHAR(20) NOT NULL DEFAULT 'normal',
                estado VARCHAR(20) NOT NULL DEFAULT 'abierto',
                asignado_a INT NULL,
                asignado_nombre VARCHAR(160) NULL,
                creado_por INT NULL,
                creado_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                primera_respuesta_en DATETIME NULL,
                resuelto_en DATETIME NULL,
                cerrado_en DATETIME NULL,
                UNIQUE KEY ix_t_folio (folio),
                KEY ix_t_estado (estado),
                KEY ix_t_empresa (empresa_id),
                KEY ix_t_asignado (asignado_a)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_mensajes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                cuerpo TEXT NOT NULL,
                interno TINYINT(1) NOT NULL DEFAULT 0,
                adjunto VARCHAR(300) NULL,
                autor_id INT NULL,
                autor_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_tm_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_eventos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                que VARCHAR(40) NOT NULL,
                antes VARCHAR(160) NULL,
                despues VARCHAR(160) NULL,
                quien_id INT NULL,
                quien_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_te_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Hasta qué mensaje leyó cada persona de soporte cada ticket: la
        // campana avisa de lo que TÚ no has leído (ver marcarLeido).
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_lecturas (
                ticket_id INT NOT NULL,
                usuario_id INT NOT NULL,
                visto INT NOT NULL DEFAULT 0,
                PRIMARY KEY (ticket_id, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // DE QUÉ TABLA ES EL AUTOR. `autor_id` solo no basta: soporte vive en
        // `usuarios_plataforma` y el cliente en el `usuarios` de su empresa,
        // y los ids se repiten. Sin esto, al cliente un mensaje de soporte
        // podía salirle como "Tú", y no había forma de saber de quién era la
        // foto. Los mensajes viejos quedan en NULL: sin foto y como antes.
        //
        // Y en el ticket:
        //   creado_tipo    quién lo abrió (igual que autor_tipo): para avisarle
        //                  a ESA persona cuando soporte conteste.
        //   visto_cliente  el último mensaje que el cliente ya vio. Lo que
        //                  soporte escriba después es una novedad: se le avisa
        //                  dentro de la plataforma y se le abre el chat.
        static $columnas = false;
        if (!$columnas) {
            try {
                $st = $this->db->query("
                    SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND ((TABLE_NAME = 'ticket_mensajes' AND COLUMN_NAME = 'autor_tipo')
                        OR (TABLE_NAME = 'tickets' AND COLUMN_NAME IN
                            ('creado_tipo','visto_cliente','escribe_cliente','escribe_soporte',
                             'cliente_activo_en','visto_soporte'))
                        OR (TABLE_NAME IN ('ticket_mensajes','tickets')
                            AND COLUMN_NAME IN ('cuerpo','asunto')
                            AND CHARACTER_SET_NAME <> 'utf8mb4'))");
                $hay = $st->fetchAll(PDO::FETCH_COLUMN);
                // EMOJIS. Ocupan 4 bytes y el `utf8` viejo de MySQL solo
                // guarda 3: un 😀 haría fallar el mensaje entero. Las tablas
                // nuevas ya nacen en utf8mb4; las de instalaciones viejas se
                // convierten una vez (aparecen aquí solo si NO son utf8mb4).
                if (in_array('ticket_mensajes.cuerpo', $hay, true)) {
                    $this->db->exec("ALTER TABLE ticket_mensajes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                }
                if (in_array('tickets.asunto', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                }
                // Quién está DENTRO de la conversación ahora (su chat preguntó
                // hace poco) y hasta dónde leyó soporte: con eso se decide si
                // un correo hace falta o si la otra persona ya lo está viendo.
                if (!in_array('tickets.cliente_activo_en', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets
                                     ADD COLUMN cliente_activo_en DATETIME NULL,
                                     ADD COLUMN soporte_activo_en DATETIME NULL");
                }
                if (!in_array('tickets.visto_soporte', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets ADD COLUMN visto_soporte INT NOT NULL DEFAULT 0");
                }
                // "Está escribiendo…": cuándo tecleó cada lado por última vez
                // y quién. Se apaga solo a los pocos segundos y al enviar.
                if (!in_array('tickets.escribe_cliente', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets
                                     ADD COLUMN escribe_cliente DATETIME NULL,
                                     ADD COLUMN escribe_cliente_nombre VARCHAR(160) NULL");
                }
                if (!in_array('tickets.escribe_soporte', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets
                                     ADD COLUMN escribe_soporte DATETIME NULL,
                                     ADD COLUMN escribe_soporte_nombre VARCHAR(160) NULL");
                }
                if (!in_array('ticket_mensajes.autor_tipo', $hay, true)) {
                    $this->db->exec("ALTER TABLE ticket_mensajes
                                     ADD COLUMN autor_tipo VARCHAR(12) NULL AFTER autor_nombre");
                }
                if (!in_array('tickets.creado_tipo', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets ADD COLUMN creado_tipo VARCHAR(12) NULL AFTER creado_nombre");
                }
                if (!in_array('tickets.visto_cliente', $hay, true)) {
                    $this->db->exec("ALTER TABLE tickets ADD COLUMN visto_cliente INT NOT NULL DEFAULT 0");
                }
                $columnas = true;
                if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lf_tickets_esquema'] = self::ESQUEMA;
            } catch (\Throwable $e) {
                error_log('[LibertyFin] columnas de tickets: ' . $e->getMessage());
            }
        }
    }

    // ── El chat: mensajes nuevos, visto y novedades ─────────────

    /** Los mensajes de un ticket posteriores a `$desdeId`. */
    public function mensajesDesde($ticketId, $desdeId, $conInternos)
    {
        $st = $this->db->prepare("
            SELECT * FROM ticket_mensajes
            WHERE ticket_id = ? AND id > ?" . ($conInternos ? "" : " AND interno = 0") . "
            ORDER BY id");
        $st->execute([(int)$ticketId, (int)$desdeId]);
        return $st->fetchAll();
    }

    /**
     * "Está escribiendo…": este lado ('cliente' o 'soporte') está tecleando.
     * El chat lo avisa cada pocos segundos mientras se escribe.
     */
    public function escribiendo($ticketId, $lado, $nombre)
    {
        if (!in_array($lado, ['cliente', 'soporte'], true)) return;
        try {
            $this->db->prepare("UPDATE tickets SET escribe_{$lado} = NOW(), escribe_{$lado}_nombre = ? WHERE id = ?")
                     ->execute([mb_substr((string)$nombre, 0, 160), (int)$ticketId]);
        } catch (\Throwable $e) { /* sin la columna todavía: sin indicador */ }
    }

    /**
     * Quién de cada lado tecleó en los últimos segundos: ['cliente' =>
     * nombre|null, 'soporte' => nombre|null]. Se mide con el reloj de la
     * base, el mismo que lo anotó.
     */
    public function escribiendoAhora($ticketId)
    {
        try {
            $st = $this->db->prepare("
                SELECT CASE WHEN escribe_cliente IS NOT NULL
                             AND TIMESTAMPDIFF(SECOND, escribe_cliente, NOW()) <= 7
                            THEN COALESCE(escribe_cliente_nombre, '') END AS cliente,
                       CASE WHEN escribe_soporte IS NOT NULL
                             AND TIMESTAMPDIFF(SECOND, escribe_soporte, NOW()) <= 7
                            THEN COALESCE(escribe_soporte_nombre, '') END AS soporte
                FROM tickets WHERE id = ?");
            $st->execute([(int)$ticketId]);
            $f = $st->fetch() ?: [];
            return ['cliente' => $f['cliente'] ?? null, 'soporte' => $f['soporte'] ?? null];
        } catch (\Throwable $e) {
            return ['cliente' => null, 'soporte' => null];
        }
    }

    /**
     * El pulso del chat en UNA consulta ligera: el último mensaje y quién
     * está tecleando. Es lo que se pregunta en cada vuelta de la espera.
     */
    public function pulso($ticketId, $conInternos)
    {
        try {
            $st = $this->db->prepare("
                SELECT (SELECT COALESCE(MAX(m.id),0) FROM ticket_mensajes m
                        WHERE m.ticket_id = t.id" . ($conInternos ? "" : " AND m.interno = 0") . ") AS ultimo,
                       CASE WHEN t.escribe_cliente IS NOT NULL
                             AND TIMESTAMPDIFF(SECOND, t.escribe_cliente, NOW()) <= 7
                            THEN COALESCE(t.escribe_cliente_nombre, '') END AS cliente,
                       CASE WHEN t.escribe_soporte IS NOT NULL
                             AND TIMESTAMPDIFF(SECOND, t.escribe_soporte, NOW()) <= 7
                            THEN COALESCE(t.escribe_soporte_nombre, '') END AS soporte
                FROM tickets t WHERE t.id = ?");
            $st->execute([(int)$ticketId]);
            $f = $st->fetch() ?: [];
            return ['ultimo' => (int)($f['ultimo'] ?? 0),
                    'cliente' => $f['cliente'] ?? null, 'soporte' => $f['soporte'] ?? null];
        } catch (\Throwable $e) {
            return ['ultimo' => $this->ultimoId($ticketId, $conInternos), 'cliente' => null, 'soporte' => null];
        }
    }

    /** El id del último mensaje (con o sin notas internas). Barato: para esperar. */
    public function ultimoId($ticketId, $conInternos)
    {
        $st = $this->db->prepare("SELECT COALESCE(MAX(id),0) FROM ticket_mensajes
                                  WHERE ticket_id = ?" . ($conInternos ? "" : " AND interno = 0"));
        $st->execute([(int)$ticketId]);
        return (int)$st->fetchColumn();
    }

    /** Este lado tiene la conversación abierta ahora mismo. */
    public function marcarActivo($ticketId, $lado)
    {
        if (!in_array($lado, ['cliente', 'soporte'], true)) return;
        try {
            $this->db->prepare("UPDATE tickets SET {$lado}_activo_en = NOW() WHERE id = ?")
                     ->execute([(int)$ticketId]);
        } catch (\Throwable $e) { /* sin la columna todavía */ }
    }

    /** Quien atiende el ticket ya leyó hasta este mensaje. Nunca retrocede. */
    public function marcarVistoSoporte($ticketId, $hastaId)
    {
        try {
            $this->db->prepare("UPDATE tickets SET visto_soporte = GREATEST(visto_soporte, ?) WHERE id = ?")
                     ->execute([(int)$hastaId, (int)$ticketId]);
        } catch (\Throwable $e) { /* sin la columna todavía */ }
    }

    /**
     * Lo que ESTA persona de soporte ya leyó del ticket (para la campana).
     *
     * Va aparte de `visto_soporte`, que es de quien atiende el ticket y
     * sirve para decidir el correo: aquí cada quien lleva lo suyo. Si un
     * compañero abre un ticket sin asignar, a ti te sigue saliendo.
     */
    public function marcarLeido($ticketId, $usuarioId, $hastaId)
    {
        if (!$usuarioId || !$hastaId) return;
        try {
            $this->db->prepare("
                INSERT INTO ticket_lecturas (ticket_id, usuario_id, visto) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE visto = GREATEST(visto, VALUES(visto))
            ")->execute([(int)$ticketId, (int)$usuarioId, (int)$hastaId]);
        } catch (\Throwable $e) { /* sin la tabla todavía */ }
    }

    /**
     * ¿Hace falta avisarle por correo al CLIENTE de este mensaje de soporte?
     *
     * Solo una vez por tanda: si ya tenía un mensaje de soporte sin leer, el
     * correo de ese ya salió. Y no si tiene el chat abierto (preguntó en el
     * último minuto y medio): lo está viendo en vivo.
     */
    public function debeAvisarCliente($ticketId, $mensajeId)
    {
        try {
            $st = $this->db->prepare("
                SELECT (t.cliente_activo_en IS NOT NULL
                        AND TIMESTAMPDIFF(SECOND, t.cliente_activo_en, NOW()) <= 90) AS en_linea,
                       (SELECT COUNT(*) FROM ticket_mensajes m
                        WHERE m.ticket_id = t.id AND m.interno = 0 AND m.autor_tipo = 'plataforma'
                          AND m.id > t.visto_cliente AND m.id < ?) AS sin_leer_antes
                FROM tickets t WHERE t.id = ?");
            $st->execute([(int)$mensajeId, (int)$ticketId]);
            $f = $st->fetch();
            return $f && !(int)$f['en_linea'] && !(int)$f['sin_leer_antes'];
        } catch (\Throwable $e) { return true; }
    }

    /**
     * ¿Hace falta avisarle a quien ATIENDE el ticket de este mensaje del
     * cliente? Igual: una vez por tanda y no si tiene la conversación abierta.
     */
    public function debeAvisarSoporte($ticketId, $mensajeId)
    {
        try {
            $st = $this->db->prepare("
                SELECT t.asignado_a,
                       (t.soporte_activo_en IS NOT NULL
                        AND TIMESTAMPDIFF(SECOND, t.soporte_activo_en, NOW()) <= 90) AS en_linea,
                       (SELECT COUNT(*) FROM ticket_mensajes m
                        WHERE m.ticket_id = t.id AND m.interno = 0 AND m.autor_tipo = 'empresa'
                          AND m.id > t.visto_soporte AND m.id < ?) AS sin_leer_antes
                FROM tickets t WHERE t.id = ?");
            $st->execute([(int)$mensajeId, (int)$ticketId]);
            $f = $st->fetch();
            return $f && (int)$f['asignado_a'] > 0 && !(int)$f['en_linea'] && !(int)$f['sin_leer_antes'];
        } catch (\Throwable $e) { return false; }
    }

    /**
     * El correo de quien ABRIÓ el ticket (un usuario de la empresa), de la
     * base de su empresa. Solo a esa persona se le avisa: ni al
     * administrador ni a otros de la empresa, que no son parte de la
     * conversación.
     */
    public function correoDelCreador(array $t)
    {
        if (!in_array($t['creado_tipo'] ?? null, [null, '', 'empresa'], true)) return '';
        if (empty($t['nombre_base_datos']) || empty($t['creado_por'])) return '';
        try {
            $st = Conexion::de($t['nombre_base_datos'])->prepare("SELECT email FROM usuarios WHERE id = ?");
            $st->execute([(int)$t['creado_por']]);
            $e = trim((string)$st->fetchColumn());
            return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
        } catch (\Throwable $e) { return ''; }
    }

    /** El correo de quien ATIENDE el ticket (cuenta de plataforma). */
    public function correoDelAgente(array $t)
    {
        if (empty($t['asignado_a'])) return '';
        try {
            $st = $this->db->prepare("SELECT email FROM usuarios_plataforma WHERE id = ? AND activo = 1");
            $st->execute([(int)$t['asignado_a']]);
            $e = trim((string)$st->fetchColumn());
            return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
        } catch (\Throwable $e) { return ''; }
    }

    /**
     * LO NUEVO PARA SOPORTE: tickets abiertos o en curso cuyo último
     * mensaje visible es del cliente y que esta persona NO ha leído. En
     * cuanto abre el ticket deja de salir, aunque todavía no conteste; si
     * el cliente vuelve a escribir, sale otra vez. De ellos, los que
     * atiende esta persona y los que nadie ha tomado (cualquiera de
     * soporte puede). Los de otro agente no: no son suyos.
     */
    public function novedadesSoporte($usuarioId)
    {
        $this->asegurar();
        try {
            $st = $this->db->prepare("
                SELECT t.id, t.folio, t.asunto, t.asignado_a, t.prioridad, e.nombre_empresa,
                       m.id AS mensaje_id, m.autor_nombre AS autor, m.cuerpo,
                       TIMESTAMPDIFF(SECOND, m.creado_en, NOW()) AS hace,
                       (SELECT MIN(p.id) FROM ticket_mensajes p WHERE p.ticket_id = t.id) = m.id AS es_primero
                FROM tickets t
                LEFT JOIN empresas e ON e.id = t.empresa_id
                INNER JOIN ( SELECT ticket_id, MAX(id) AS ultimo FROM ticket_mensajes
                             WHERE interno = 0 GROUP BY ticket_id ) u ON u.ticket_id = t.id
                INNER JOIN ticket_mensajes m ON m.id = u.ultimo
                LEFT JOIN ticket_lecturas l ON l.ticket_id = t.id AND l.usuario_id = ?
                WHERE t.estado IN ('abierto', 'en_curso')
                  AND (m.autor_tipo = 'empresa'
                       OR (m.autor_tipo IS NULL AND m.autor_id = t.creado_por))
                  AND (t.asignado_a = ? OR t.asignado_a IS NULL)
                  AND m.id > COALESCE(l.visto, 0)
                ORDER BY m.id DESC
                LIMIT 20");
            $st->execute([(int)$usuarioId, (int)$usuarioId]);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Los reportes de esta persona que siguen sin solucionarse (ni
     * resueltos ni cerrados), el más movido primero, con cuántas respuestas
     * de soporte no ha leído en cada uno. Son los que se alternan en el chat.
     */
    public function activosCliente($empresaId, $usuarioId)
    {
        try {
            // Con el último mensaje visible (qué dice, de quién y hace
            // cuánto): la lista del chat flotante es como la de una app de
            // mensajes.
            $st = $this->db->prepare("
                SELECT t.id, t.folio, t.asunto, t.estado,
                       (SELECT COUNT(*) FROM ticket_mensajes m
                        WHERE m.ticket_id = t.id AND m.interno = 0 AND m.autor_tipo = 'plataforma'
                          AND m.id > t.visto_cliente) AS sin_leer,
                       u.cuerpo AS ultimo, u.autor_tipo AS ultimo_tipo,
                       TIMESTAMPDIFF(SECOND, u.creado_en, NOW()) AS hace
                FROM tickets t
                LEFT JOIN ticket_mensajes u ON u.id = (SELECT MAX(x.id) FROM ticket_mensajes x
                                                       WHERE x.ticket_id = t.id AND x.interno = 0)
                WHERE t.empresa_id = ? AND t.creado_por = ?
                  AND (t.creado_tipo = 'empresa' OR t.creado_tipo IS NULL)
                  AND t.estado NOT IN ('resuelto', 'cerrado')
                ORDER BY COALESCE(u.id, 0) DESC, t.id DESC
                LIMIT 15");
            $st->execute([(int)$empresaId, (int)$usuarioId]);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    /**
     * El reporte más reciente de esta persona que sigue vivo (no cerrado):
     * el que abre la burbuja del chat cuando se cerró.
     */
    public function activoCliente($empresaId, $usuarioId)
    {
        try {
            $st = $this->db->prepare("
                SELECT t.id, t.folio, t.asunto
                FROM tickets t
                WHERE t.empresa_id = ? AND t.creado_por = ?
                  AND (t.creado_tipo = 'empresa' OR t.creado_tipo IS NULL)
                  AND t.estado <> 'cerrado'
                ORDER BY (SELECT MAX(m.id) FROM ticket_mensajes m WHERE m.ticket_id = t.id) DESC, t.id DESC
                LIMIT 1");
            $st->execute([(int)$empresaId, (int)$usuarioId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** El cliente ya vio hasta este mensaje. Nunca retrocede. */
    public function marcarVistoCliente($ticketId, $hastaId)
    {
        try {
            $this->db->prepare("UPDATE tickets SET visto_cliente = GREATEST(visto_cliente, ?) WHERE id = ?")
                     ->execute([(int)$hastaId, (int)$ticketId]);
        } catch (\Throwable $e) { /* sin la columna todavía: no pasa nada */ }
    }

    /**
     * Lo que soporte le contestó a ESTA persona y todavía no ve: de los
     * tickets que ella abrió, los mensajes de soporte (no las notas
     * internas) posteriores a lo último que vio.
     *
     * @return array ['sin_leer' => n, 'tickets' => [[id, folio, asunto,
     *               mensaje_id, autor, cuerpo, creado_en, nuevos]]]
     */
    public function novedadesCliente($empresaId, $usuarioId)
    {
        $this->asegurar();
        try {
            $st = $this->db->prepare("
                SELECT t.id, t.folio, t.asunto, t.estado,
                       m.id AS mensaje_id, m.autor_nombre AS autor, m.cuerpo, m.creado_en,
                       x.nuevos
                FROM tickets t
                INNER JOIN ( SELECT mm.ticket_id, MAX(mm.id) AS ultimo, COUNT(*) AS nuevos
                             FROM ticket_mensajes mm
                             INNER JOIN tickets tt ON tt.id = mm.ticket_id
                             WHERE tt.empresa_id = ? AND mm.interno = 0
                               AND mm.autor_tipo = 'plataforma' AND mm.id > tt.visto_cliente
                             GROUP BY mm.ticket_id ) x ON x.ticket_id = t.id
                INNER JOIN ticket_mensajes m ON m.id = x.ultimo
                WHERE t.empresa_id = ? AND t.creado_por = ?
                  AND (t.creado_tipo = 'empresa' OR t.creado_tipo IS NULL)
                ORDER BY m.id DESC
                LIMIT 10");
            $st->execute([(int)$empresaId, (int)$empresaId, (int)$usuarioId]);
            $filas = $st->fetchAll();
        } catch (\Throwable $e) {
            return ['sin_leer' => 0, 'tickets' => []];
        }
        $n = 0;
        foreach ($filas as $f) $n += (int)$f['nuevos'];
        return ['sin_leer' => $n, 'tickets' => $filas];
    }

    /**
     * Un mensaje listo para el chat (JSON). El texto va tal cual: quien lo
     * pinta lo pone como texto, nunca como HTML.
     */
    public static function aJson(array $m, array $fotos, $quienLee)
    {
        $nombre = (string)($m['autor_nombre'] ?? '');
        $mio = self::esMio($m);
        return [
            'id'      => (int)$m['id'],
            'autor'   => $mio && $quienLee === 'cliente' ? 'Tú' : ($nombre !== '' ? $nombre : 'LibertyFin'),
            'inicial' => mb_strtoupper(mb_substr(trim($nombre), 0, 1) ?: '?'),
            'foto'    => $fotos[(int)$m['id']] ?? '',
            'tipo'    => (string)($m['autor_tipo'] ?? ''),
            'mio'     => $mio,
            'interno' => !empty($m['interno']),
            'cuerpo'  => (string)$m['cuerpo'],
            'adjunto' => (string)($m['adjunto'] ?? ''),
            'fecha'   => date('d/m/Y H:i', strtotime($m['creado_en'])),
        ];
    }

    /**
     * La fecha de un mensaje, para las vistas: "09/10/2026 10:24". El día va
     * en su propio <span>: en el celular, si es de hoy, se ve solo la hora,
     * como en cualquier chat (lo mismo hace lf-chat.js con los que llegan).
     */
    public static function fechaMsj($creadoEn)
    {
        $ts  = strtotime((string)$creadoEn);
        $dia = date('d/m/Y', $ts);
        return '<span class="fecha"><span class="dia' . ($dia === date('d/m/Y') ? ' hoy' : '') . '">'
             . $dia . ' </span>' . date('H:i', $ts) . '</span>';
    }

    /** 'plataforma' (soporte, validación, superadmin) o 'empresa' (el cliente). */
    private static function tipoAutor()
    {
        return !empty($_SESSION['plataforma']) ? 'plataforma' : 'empresa';
    }

    /**
     * ¿Este mensaje lo escribí yo? Por tipo Y id: el id solo se repite entre
     * soporte y empresas. Los mensajes viejos (sin tipo) se comparan por id,
     * como antes.
     */
    public static function esMio(array $m)
    {
        $yo = (int)($_SESSION['usuario_id'] ?? 0);
        if (!$yo || (int)($m['autor_id'] ?? 0) !== $yo) return false;
        return empty($m['autor_tipo']) || $m['autor_tipo'] === self::tipoAutor();
    }

    /**
     * La foto de quien escribió cada mensaje: [id del mensaje => ruta].
     *
     * La del cliente sale de la base de SU empresa. La de soporte, de su
     * cuenta de plataforma, y si `$paraCliente` solo cuando esa persona
     * eligió mostrarla (Mi cuenta → "Mostrar mi foto a los clientes").
     * Se busca al pintar, no se copia al mensaje: quitar la foto o dejar de
     * mostrarla aplica también a lo ya escrito.
     *
     * @param string|null $baseEmpresa  la base de la empresa del ticket
     */
    public function fotos(array $mensajes, $baseEmpresa, $paraCliente)
    {
        $plat = []; $emp = [];
        foreach ($mensajes as $m) {
            $id = (int)($m['autor_id'] ?? 0);
            if (!$id) continue;
            if (($m['autor_tipo'] ?? '') === 'plataforma') $plat[] = $id;
            if (($m['autor_tipo'] ?? '') === 'empresa')    $emp[]  = $id;
        }

        $fp = $plat ? (new AutenticacionRepo($this->db))->fotosPlataforma($plat) : [];

        $fe = [];
        $emp = array_values(array_unique($emp));
        if ($emp && $baseEmpresa) {
            try {
                $st = Conexion::de($baseEmpresa)->prepare("
                    SELECT id, COALESCE(foto,'') AS foto FROM usuarios
                    WHERE id IN (" . implode(',', array_fill(0, count($emp), '?')) . ")");
                $st->execute($emp);
                foreach ($st->fetchAll() as $f) $fe[(int)$f['id']] = (string)$f['foto'];
            } catch (\Throwable $e) { /* sin columna foto o sin base: sin fotos */ }
        }

        $r = [];
        foreach ($mensajes as $m) {
            $id = (int)($m['autor_id'] ?? 0);
            $foto = '';
            if (($m['autor_tipo'] ?? '') === 'plataforma' && isset($fp[$id])) {
                if (!$paraCliente || $fp[$id]['publica']) $foto = $fp[$id]['foto'];
            } elseif (($m['autor_tipo'] ?? '') === 'empresa') {
                $foto = $fe[$id] ?? '';
            }
            if ($foto !== '') $r[(int)$m['id']] = $foto;
        }
        return $r;
    }

    // ── Crear y responder ───────────────────────────────────────

    /**
     * @param string|null $adjunto  la evidencia (captura o PDF), ya subida.
     *                              Va en el primer mensaje: es lo que se ve
     *                              junto a la descripción del problema.
     */
    public function crear(array $d, $usuarioId, $usuarioNombre, $adjunto = null)
    {
        $this->asegurar();
        $asunto = trim($d['asunto'] ?? '');
        if (mb_strlen($asunto) < 6) {
            throw new \InvalidArgumentException('Escribe un asunto que diga de qué se trata');
        }
        $cuerpo = trim($d['cuerpo'] ?? '');
        if (mb_strlen($cuerpo) < 10) {
            throw new \InvalidArgumentException('Describe el problema: qué pasó y qué esperaba');
        }
        $prio = $d['prioridad'] ?? 'normal';
        if (!isset(self::PRIORIDADES[$prio])) $prio = 'normal';
        $cat = $d['categoria'] ?? 'otro';
        if (!isset(self::CATEGORIAS[$cat])) $cat = 'otro';

        $folio = 'T' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $this->db->prepare("
            INSERT INTO tickets (folio, empresa_id, asunto, categoria, prioridad, estado,
                                 creado_por, creado_nombre, creado_tipo, creado_en)
            VALUES (?,?,?,?,?, 'abierto', ?,?,?, NOW())
        ")->execute([$folio, ((int)($d['empresa_id'] ?? 0)) ?: null, $asunto, $cat, $prio,
                     $usuarioId ?: null, $usuarioNombre, self::tipoAutor()]);
        $id = (int)$this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO ticket_mensajes (ticket_id, cuerpo, interno, adjunto, autor_id, autor_nombre, autor_tipo, creado_en)
            VALUES (?,?,0,?,?,?,?,NOW())
        ")->execute([$id, $cuerpo, $adjunto ?: null, $usuarioId ?: null, $usuarioNombre, self::tipoAutor()]);

        $this->evento($id, 'creado', null, $folio, $usuarioId, $usuarioNombre);
        return ['id' => $id, 'folio' => $folio];
    }

    public function responder($ticketId, $cuerpo, $interno, $adjunto, $usuarioId, $usuarioNombre)
    {
        // Cerrado es cerrado también aquí, no solo en la pantalla del
        // cliente (que esconde la caja): si vuelve a pasar, va uno nuevo.
        if (self::tipoAutor() !== 'plataforma') {
            $st = $this->db->prepare("SELECT estado FROM tickets WHERE id = ?");
            $st->execute([(int)$ticketId]);
            if ($st->fetchColumn() === 'cerrado') {
                throw new \InvalidArgumentException('Este reporte está cerrado. Si vuelve a pasar, abre uno nuevo.');
            }
        }
        $cuerpo = trim((string)$cuerpo);
        // Una captura pegada puede ir sola, sin texto. Se le pone uno para
        // que la lista, el aviso y el correo no muestren un mensaje vacío.
        if ($cuerpo === '' && $adjunto) $cuerpo = 'Adjuntó un archivo.';
        if ($cuerpo === '') throw new \InvalidArgumentException('Escribe la respuesta');

        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');

        $this->db->prepare("
            INSERT INTO ticket_mensajes (ticket_id, cuerpo, interno, adjunto,
                                         autor_id, autor_nombre, autor_tipo, creado_en)
            VALUES (?,?,?,?,?,?,?,NOW())
        ")->execute([(int)$ticketId, $cuerpo, $interno ? 1 : 0, $adjunto ?: null,
                     $usuarioId ?: null, $usuarioNombre, self::tipoAutor()]);
        $nuevoId = (int)$this->db->lastInsertId();

        // Una nota interna NO cuenta como primera respuesta: el cliente no
        // la ve, así que para él nadie le ha contestado todavía. Y lo que
        // escribe el CLIENTE tampoco: antes contaba, y un ticket al que solo
        // él le había agregado algo salía como "respondido" y "en curso".
        $deSoporte = self::tipoAutor() === 'plataforma';
        // Ya envió: deja de "estar escribiendo".
        try {
            $lado = $deSoporte ? 'soporte' : 'cliente';
            $this->db->prepare("UPDATE tickets SET escribe_{$lado} = NULL WHERE id = ?")
                     ->execute([(int)$ticketId]);
        } catch (\Throwable $e) { /* sin la columna todavía */ }
        if (!$interno && $deSoporte && empty($t['primera_respuesta_en'])) {
            $this->db->prepare("UPDATE tickets SET primera_respuesta_en = NOW() WHERE id = ?")
                     ->execute([(int)$ticketId]);
        }
        if (!$interno && $deSoporte && $t['estado'] === 'abierto') {
            $this->cambiar($ticketId, 'estado', 'en_curso', $usuarioId, $usuarioNombre);
        }
        // Si soporte esperaba al cliente y el cliente contestó, vuelve a
        // ser turno de soporte. Y si lo habíamos dado por RESUELTO y el
        // cliente escribe, se REABRE: es lo que le prometemos en su
        // pantalla. Antes se quedaba en Resuelto, fuera de la bandeja de
        // activos y de la campana, y el mensaje se podía perder.
        if (!$deSoporte && in_array($t['estado'], ['esperando', 'resuelto'], true)) {
            $this->cambiar($ticketId, 'estado', 'en_curso', $usuarioId, $usuarioNombre);
        }
        // Quien escribe está en la conversación.
        $this->marcarActivo($ticketId, $deSoporte ? 'soporte' : 'cliente');
        return $nuevoId;
    }

    // ── Cambios de estado ───────────────────────────────────────

    public function cambiar($ticketId, $campo, $valor, $usuarioId, $usuarioNombre)
    {
        $permitidos = [
            'estado'    => self::ESTADOS,
            'prioridad' => array_map(function ($p) { return $p[0]; }, self::PRIORIDADES),
            'categoria' => self::CATEGORIAS,
        ];
        if (!isset($permitidos[$campo])) throw new \InvalidArgumentException('Campo no válido');
        if (!isset($permitidos[$campo][$valor])) throw new \InvalidArgumentException('Valor no válido');

        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');
        if ($t[$campo] === $valor) return true;

        $extra = '';
        if ($campo === 'estado' && $valor === 'resuelto') $extra = ', resuelto_en = NOW()';
        if ($campo === 'estado' && $valor === 'cerrado')  $extra = ', cerrado_en = NOW()';
        // Reabrir limpia las marcas: si no, el tiempo de resolución
        // quedaría contado desde la primera vez y saldría falso.
        if ($campo === 'estado' && in_array($valor, ['abierto','en_curso','esperando'], true)) {
            $extra = ', resuelto_en = NULL, cerrado_en = NULL';
        }

        $this->db->prepare("UPDATE tickets SET `$campo` = ? {$extra} WHERE id = ?")
                 ->execute([$valor, (int)$ticketId]);
        $this->evento($ticketId, $campo, $t[$campo], $valor, $usuarioId, $usuarioNombre);
        return true;
    }

    public function asignar($ticketId, $aId, $aNombre, $usuarioId, $usuarioNombre)
    {
        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');
        $this->db->prepare("UPDATE tickets SET asignado_a = ?, asignado_nombre = ? WHERE id = ?")
                 ->execute([$aId ?: null, $aNombre ?: null, (int)$ticketId]);
        $this->evento($ticketId, 'asignado', $t['asignado_nombre'], $aNombre,
                      $usuarioId, $usuarioNombre);
        return true;
    }

    // ── Vencen solos ────────────────────────────────────────────

    /**
     * Sin esto un ticket no se cerraba nunca: se quedaba en Esperando o en
     * Resuelto para siempre, y el cliente podía seguir escribiendo en él.
     *
     *   Esperando al cliente → Resuelto, a los DIAS_ESPERANDO días sin
     *     actividad. Se le deja dicho en el chat; si escribe, se reabre.
     *   Resuelto → Cerrado, a los DIAS_RESUELTO días sin actividad. Ese ya
     *     no se reabre: si vuelve a pasar, va un reporte nuevo.
     *
     * "Sin actividad" cuenta desde lo último que pasó: el cambio de estado
     * o un mensaje que el cliente ve. Si soporte le escribe un recordatorio,
     * el plazo vuelve a empezar.
     */
    const DIAS_ESPERANDO = 3;
    const DIAS_RESUELTO  = 7;

    /**
     * No hay tareas programadas en el hosting: corre de paso al abrir el
     * panel, la bandeja o un ticket, como mucho una vez cada diez minutos
     * por sesión. Cada ticket se "toma" con un UPDATE condicionado, así
     * que dos sesiones a la vez no lo cambian (ni lo avisan) dos veces.
     */
    public function vencerSolos()
    {
        if (time() - (int)($_SESSION['lf_tk_vencer'] ?? 0) < 600) return;
        $_SESSION['lf_tk_vencer'] = time();
        $this->asegurar();
        try {
            $ultimoMsj = "COALESCE((SELECT MAX(m.creado_en) FROM ticket_mensajes m
                                    WHERE m.ticket_id = t.id AND m.interno = 0), t.creado_en)";

            $st = $this->db->query("
                SELECT t.id FROM tickets t
                WHERE t.estado = 'esperando'
                  AND GREATEST(
                        COALESCE((SELECT MAX(e.creado_en) FROM ticket_eventos e
                                  WHERE e.ticket_id = t.id AND e.que = 'estado'), t.creado_en),
                        {$ultimoMsj}
                      ) < NOW() - INTERVAL " . (int)self::DIAS_ESPERANDO . " DAY
                LIMIT 50");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $tomar = $this->db->prepare("
                    UPDATE tickets SET estado = 'resuelto', resuelto_en = NOW()
                    WHERE id = ? AND estado = 'esperando'");
                $tomar->execute([(int)$id]);
                if ($tomar->rowCount() !== 1) continue;
                $this->evento($id, 'estado', 'esperando', 'resuelto', null, 'Automático');
                $this->db->prepare("
                    INSERT INTO ticket_mensajes (ticket_id, cuerpo, interno, adjunto,
                                                 autor_id, autor_nombre, autor_tipo, creado_en)
                    VALUES (?, ?, 0, NULL, NULL, 'Soporte LibertyFin', 'plataforma', NOW())")
                    ->execute([(int)$id,
                        'Lo marcamos como resuelto porque no tuvimos respuesta en '
                        . self::DIAS_ESPERANDO . ' días. Si sigue pasando, escríbenos aquí y lo reabrimos.']);
            }

            $st = $this->db->query("
                SELECT t.id FROM tickets t
                WHERE t.estado = 'resuelto'
                  AND GREATEST(COALESCE(t.resuelto_en, t.creado_en), {$ultimoMsj})
                      < NOW() - INTERVAL " . (int)self::DIAS_RESUELTO . " DAY
                LIMIT 100");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $tomar = $this->db->prepare("
                    UPDATE tickets SET estado = 'cerrado', cerrado_en = NOW()
                    WHERE id = ? AND estado = 'resuelto'");
                $tomar->execute([(int)$id]);
                if ($tomar->rowCount() === 1) {
                    $this->evento($id, 'estado', 'resuelto', 'cerrado', null, 'Automático');
                }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tickets que vencen solos: ' . $e->getMessage());
        }
    }

    private function evento($ticketId, $que, $antes, $despues, $quienId, $quienNombre)
    {
        $this->db->prepare("
            INSERT INTO ticket_eventos (ticket_id, que, antes, despues, quien_id, quien_nombre, creado_en)
            VALUES (?,?,?,?,?,?,NOW())
        ")->execute([(int)$ticketId, $que, $antes, $despues, $quienId ?: null, $quienNombre]);
    }

    // ── Leer ────────────────────────────────────────────────────

    public function uno($id)
    {
        $this->asegurar();
        $st = $this->db->prepare("
            SELECT t.*, e.nombre_empresa, e.email_admin, e.nombre_base_datos
            FROM tickets t
            LEFT JOIN empresas e ON e.id = t.empresa_id
            WHERE t.id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public function mensajes($ticketId)
    {
        $st = $this->db->prepare("
            SELECT * FROM ticket_mensajes WHERE ticket_id = ? ORDER BY creado_en, id");
        $st->execute([(int)$ticketId]);
        return $st->fetchAll();
    }

    public function eventos($ticketId)
    {
        $st = $this->db->prepare("
            SELECT * FROM ticket_eventos WHERE ticket_id = ? ORDER BY creado_en DESC, id DESC");
        $st->execute([(int)$ticketId]);
        return $st->fetchAll();
    }

    /**
     * La bandeja.
     *
     * `min_respuesta`: cuánto tardó la PRIMERA respuesta si ya la hubo, y
     * cuánto lleva esperando si todavía no.
     */
    public function bandeja($filtros = [], $limite = 40, $desfase = 0)
    {
        $this->asegurar();
        $w = []; $p = [];
        if (!empty($filtros['estado'])) {
            if ($filtros['estado'] === 'activos') {
                $w[] = "t.estado IN ('abierto','en_curso','esperando')";
            } else { $w[] = 't.estado = ?'; $p[] = $filtros['estado']; }
        }
        if (!empty($filtros['prioridad'])) { $w[] = 't.prioridad = ?'; $p[] = $filtros['prioridad']; }
        if (!empty($filtros['empresa']))   { $w[] = 't.empresa_id = ?'; $p[] = (int)$filtros['empresa']; }
        // Solo los que abrió esta persona (lo que ve el cliente en Ayuda).
        if (!empty($filtros['creador'])) {
            $w[] = "t.creado_por = ? AND (t.creado_tipo IS NULL OR t.creado_tipo IN ('', 'empresa'))";
            $p[] = (int)$filtros['creador'];
        }
        if (!empty($filtros['asignado']))  { $w[] = 't.asignado_a = ?'; $p[] = (int)$filtros['asignado']; }
        if (!empty($filtros['q'])) {
            $w[] = '(t.asunto LIKE ? OR t.folio LIKE ? OR e.nombre_empresa LIKE ?)';
            $l = '%' . $filtros['q'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $st = $this->db->prepare("
            SELECT t.*, e.nombre_empresa,
                   TIMESTAMPDIFF(MINUTE, t.creado_en,
                       COALESCE(t.primera_respuesta_en, NOW())) AS min_respuesta,
                   (SELECT COUNT(*) FROM ticket_mensajes m WHERE m.ticket_id = t.id) AS mensajes
            FROM tickets t
            LEFT JOIN empresas e ON e.id = t.empresa_id
            {$where}
            ORDER BY FIELD(t.prioridad,'critica','alta','normal','baja'), t.creado_en
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase);
        $st->execute($p);
        return $st->fetchAll();
    }

    public function cifras()
    {
        $this->asegurar();
        $f = $this->db->query("
            SELECT COUNT(*) total,
                   SUM(estado IN ('abierto','en_curso','esperando')) activos,
                   SUM(estado = 'abierto') sin_tocar,
                   SUM(estado = 'resuelto') resueltos,
                   SUM(prioridad = 'critica' AND estado IN ('abierto','en_curso','esperando')) criticos,
                   AVG(CASE WHEN primera_respuesta_en IS NOT NULL
                       THEN TIMESTAMPDIFF(MINUTE, creado_en, primera_respuesta_en) END) min_respuesta,
                   AVG(CASE WHEN resuelto_en IS NOT NULL
                       THEN TIMESTAMPDIFF(HOUR, creado_en, resuelto_en) END) horas_resolucion
            FROM tickets")->fetch();
        return $f ?: [];
    }

    /** De qué se queja la gente. Ordena por lo que más se repite. */
    public function porCategoria()
    {
        $this->asegurar();
        return $this->db->query("
            SELECT categoria, COUNT(*) cuantos,
                   SUM(estado IN ('abierto','en_curso','esperando')) abiertos,
                   AVG(CASE WHEN resuelto_en IS NOT NULL
                       THEN TIMESTAMPDIFF(HOUR, creado_en, resuelto_en) END) horas
            FROM tickets GROUP BY categoria ORDER BY cuantos DESC")->fetchAll();
    }
}
