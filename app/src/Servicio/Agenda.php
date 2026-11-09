<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Agenda de citas: quién viene, a qué, con quién y a qué hora.
 *
 * LA DURACIÓN
 *
 * Cada servicio puede tener su duración (Productos → «Duración»). La
 * cita la toma de ahí y se puede cambiar; sin ella, dura un intervalo de
 * la agenda (30 min de fábrica).
 *
 * SIN EMPALMES
 *
 * Un especialista no puede tener dos citas al mismo tiempo. Las
 * canceladas y las de quien no vino no ocupan lugar. Las citas sin
 * especialista («quien esté») no chocan con nadie.
 *
 * DE CITA A VENTA
 *
 * «Cobrar» abre Caja con el servicio, el cliente y el especialista ya
 * puestos. Al registrarse la venta, la cita queda atendida y ligada a
 * ella (CajaControlador::despuesDeCobrar).
 *
 * EL RECORDATORIO
 *
 * Es un enlace de WhatsApp con el mensaje escrito: lo manda una persona
 * desde su teléfono. No hay envío automático (necesitaría la API de
 * WhatsApp Business, que es de paga y se contrata aparte).
 */
final class Agenda
{
    const ESTADOS = [
        'agendada'   => 'Agendada',
        'confirmada' => 'Confirmada',
        'atendida'   => 'Atendida',
        'no_vino'    => 'No vino',
        'cancelada'  => 'Cancelada',
    ];
    /** Las que ya no ocupan el lugar. */
    const LIBERAN = ['cancelada', 'no_vino'];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // ════════════════════════════════════════════════════════
    //  EL HORARIO
    // ════════════════════════════════════════════════════════

    /** ['inicio' => '09:00', 'fin' => '20:00', 'intervalo' => 30] */
    public function horario()
    {
        $c = new \LibertyFin\Datos\ConfigRepo($this->db);
        $ini = (string)$c->valorDe('agenda.inicio', '09:00');
        $fin = (string)$c->valorDe('agenda.fin', '20:00');
        $int = (int)$c->valorDe('agenda.intervalo', '30');
        if (!preg_match('/^\d{2}:\d{2}$/', $ini)) $ini = '09:00';
        if (!preg_match('/^\d{2}:\d{2}$/', $fin) || $fin <= $ini) $fin = '20:00';
        if (!in_array($int, [15, 20, 30, 60], true)) $int = 30;
        return ['inicio' => $ini, 'fin' => $fin, 'intervalo' => $int];
    }

    public function guardarHorario($inicio, $fin, $intervalo)
    {
        $inicio = substr(trim((string)$inicio), 0, 5);
        $fin = substr(trim((string)$fin), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $inicio) || !preg_match('/^\d{2}:\d{2}$/', $fin)) {
            throw new \InvalidArgumentException('Escribe la hora de apertura y de cierre.');
        }
        if ($fin <= $inicio) throw new \InvalidArgumentException('La hora de cierre debe ser después de la de apertura.');
        $intervalo = (int)$intervalo;
        if (!in_array($intervalo, [15, 20, 30, 60], true)) $intervalo = 30;
        $c = new \LibertyFin\Datos\ConfigRepo($this->db);
        $c->guardar('agenda.inicio', $inicio);
        $c->guardar('agenda.fin', $fin);
        $c->guardar('agenda.intervalo', (string)$intervalo);
    }

    /** Los especialistas: los colaboradores activos (los mismos de Comisiones y de Caja). */
    public function especialistas()
    {
        try {
            return $this->db->query("SELECT id, nombre FROM comision_colaboradores WHERE COALESCE(activo,1) = 1 ORDER BY nombre")
                            ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Los servicios que se agendan: los activos, con su duración. */
    public function servicios()
    {
        return $this->db->query("SELECT id, nombre, duracion_min, COALESCE(NULLIF(subprecio,0), precio) AS precio
                                 FROM productos WHERE COALESCE(activo,1) = 1 ORDER BY nombre")
                        ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    /** Las citas de un día (Y-m-d), en orden. */
    public function dia($fecha)
    {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha) ? $fecha : date('Y-m-d');
        $st = $this->db->prepare("
            SELECT c.*, cc.nombre AS especialista, v.codigo_venta
            FROM lf_citas c
            LEFT JOIN comision_colaboradores cc ON cc.id = c.colaborador_id
            LEFT JOIN ventas v ON v.id = c.venta_id
            WHERE c.inicio >= ? AND c.inicio < ?
            ORDER BY c.inicio, c.id");
        $st->execute([$fecha . ' 00:00:00', date('Y-m-d', strtotime($fecha . ' +1 day')) . ' 00:00:00']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cuántas citas vivas hay en cada día de un rango (para el calendario chico). */
    public function cuantasPorDia($desde, $hasta)
    {
        $st = $this->db->prepare("SELECT inicio, estado FROM lf_citas WHERE inicio >= ? AND inicio < ?");
        $st->execute([$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00']);
        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['estado'], self::LIBERAN, true)) continue;
            $d = substr($c['inicio'], 0, 10);
            $r[$d] = ($r[$d] ?? 0) + 1;
        }
        return $r;
    }

    public function una($id)
    {
        $st = $this->db->prepare("SELECT c.*, cc.nombre AS especialista FROM lf_citas c
                                  LEFT JOIN comision_colaboradores cc ON cc.id = c.colaborador_id WHERE c.id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Las próximas y las últimas de un cliente, para su ficha. */
    public function delCliente($clienteId, $tope = 8)
    {
        $st = $this->db->prepare("SELECT c.id, c.inicio, c.fin, c.servicio, c.estado, cc.nombre AS especialista
                                  FROM lf_citas c LEFT JOIN comision_colaboradores cc ON cc.id = c.colaborador_id
                                  WHERE c.cliente_id = ? ORDER BY c.inicio DESC LIMIT " . max(1, (int)$tope));
        $st->execute([(int)$clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ════════════════════════════════════════════════════════
    //  ESCRIBIR
    // ════════════════════════════════════════════════════════

    /**
     * Agenda o mueve una cita.
     *
     * @param array $d cliente_id | cliente_nombre, telefono, colaborador_id,
     *                 producto_id, fecha (Y-m-d), hora (H:i), duracion (min), notas
     * @return int el id
     */
    public function guardar(array $d, $id = 0, $usuario = '')
    {
        $id = (int)$id;
        if ($id) {
            $antes = $this->una($id);
            if (!$antes) throw new \InvalidArgumentException('Esa cita no existe.');
            if ($antes['estado'] === 'atendida') throw new \InvalidArgumentException('La cita ya se atendió y no se puede mover.');
        }

        $clienteId = (int)($d['cliente_id'] ?? 0) ?: null;
        $nombre = mb_substr(trim((string)($d['cliente_nombre'] ?? '')), 0, 200);
        $tel = mb_substr(preg_replace('/[^\d+ ]/', '', (string)($d['telefono'] ?? '')), 0, 30);
        if ($clienteId) {
            $st = $this->db->prepare("SELECT nombre, telefono FROM clientes WHERE id = ?");
            $st->execute([$clienteId]);
            $cl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cl) { $clienteId = null; }
            else { $nombre = (string)$cl['nombre']; if (trim($tel) === '') $tel = (string)$cl['telefono']; }
        }
        if ($nombre === '') throw new \InvalidArgumentException('Escribe el nombre del cliente.');

        $fecha = (string)($d['fecha'] ?? '');
        $hora = substr((string)($d['hora'] ?? ''), 0, 5);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            throw new \InvalidArgumentException('Elige el día y la hora.');
        }

        $productoId = (int)($d['producto_id'] ?? 0) ?: null;
        $servicio = mb_substr(trim((string)($d['servicio'] ?? '')), 0, 255);
        $durProducto = null;
        if ($productoId) {
            $st = $this->db->prepare("SELECT nombre, duracion_min FROM productos WHERE id = ?");
            $st->execute([$productoId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $productoId = null; }
            else { $servicio = (string)$p['nombre']; $durProducto = (int)$p['duracion_min'] ?: null; }
        }
        $dur = (int)($d['duracion'] ?? 0) ?: ($durProducto ?: $this->horario()['intervalo']);
        if ($dur < 5 || $dur > 720) throw new \InvalidArgumentException('La duración debe ser de 5 minutos a 12 horas.');

        $colab = (int)($d['colaborador_id'] ?? 0) ?: null;
        $inicio = $fecha . ' ' . $hora . ':00';
        $fin = date('Y-m-d H:i:s', strtotime($inicio) + $dur * 60);
        $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null;

        if ($colab) $this->sinEmpalme($colab, $inicio, $fin, $id);

        if ($id) {
            $this->db->prepare("UPDATE lf_citas SET cliente_id = ?, cliente_nombre = ?, telefono = ?, colaborador_id = ?,
                                       producto_id = ?, servicio = ?, inicio = ?, fin = ?, notas = ? WHERE id = ?")
                     ->execute([$clienteId, $nombre, $tel ?: null, $colab, $productoId, $servicio ?: null, $inicio, $fin, $notas, $id]);
            return $id;
        }
        $this->db->prepare("INSERT INTO lf_citas (cliente_id, cliente_nombre, telefono, colaborador_id, producto_id, servicio,
                                                  inicio, fin, estado, notas, creado_por, creado_en)
                            VALUES (?,?,?,?,?,?,?,?,'agendada',?,?,NOW())")
                 ->execute([$clienteId, $nombre, $tel ?: null, $colab, $productoId, $servicio ?: null, $inicio, $fin, $notas,
                            mb_substr((string)$usuario, 0, 100) ?: null]);
        return (int)$this->db->lastInsertId();
    }

    /** Lanza si el especialista ya tiene algo en ese horario. */
    private function sinEmpalme($colab, $inicio, $fin, $exceptoId)
    {
        $st = $this->db->prepare("
            SELECT c.inicio, c.fin, c.cliente_nombre, cc.nombre AS especialista
            FROM lf_citas c LEFT JOIN comision_colaboradores cc ON cc.id = c.colaborador_id
            WHERE c.colaborador_id = ? AND c.id <> ? AND c.estado NOT IN ('cancelada','no_vino')
              AND c.inicio < ? AND c.fin > ?
            ORDER BY c.inicio LIMIT 1");
        $st->execute([(int)$colab, (int)$exceptoId, $fin, $inicio]);
        if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new \InvalidArgumentException(($o['especialista'] ?: 'Ese especialista') . ' ya tiene cita de '
                . date('H:i', strtotime($o['inicio'])) . ' a ' . date('H:i', strtotime($o['fin']))
                . ' con ' . $o['cliente_nombre'] . '. Elige otra hora u otra persona.');
        }
    }

    /** Confirmada, no vino, cancelada o de vuelta a agendada. */
    public function estado($id, $estado)
    {
        $c = $this->una($id);
        if (!$c) throw new \InvalidArgumentException('Esa cita no existe.');
        if (!isset(self::ESTADOS[$estado]) || $estado === 'atendida') {
            throw new \InvalidArgumentException('Ese estado no existe.');
        }
        if ($c['estado'] === 'atendida') throw new \InvalidArgumentException('La cita ya se atendió (venta ligada).');
        // Volver a ocupar el lugar: que no choque con lo que se agendó mientras tanto.
        if (in_array($c['estado'], self::LIBERAN, true) && !in_array($estado, self::LIBERAN, true) && $c['colaborador_id']) {
            $this->sinEmpalme($c['colaborador_id'], $c['inicio'], $c['fin'], $c['id']);
        }
        $this->db->prepare("UPDATE lf_citas SET estado = ? WHERE id = ?")->execute([$estado, (int)$id]);
        return $c;
    }

    /** La venta que la cobró. */
    public function atendida($id, $ventaId)
    {
        $st = $this->db->prepare("UPDATE lf_citas SET estado = 'atendida', venta_id = ?
                                  WHERE id = ? AND estado NOT IN ('cancelada','atendida')");
        $st->execute([(int)$ventaId, (int)$id]);
        return $st->rowCount() > 0;
    }

    // ════════════════════════════════════════════════════════
    //  EL RECORDATORIO
    // ════════════════════════════════════════════════════════

    /** El enlace de WhatsApp con el mensaje escrito, o null sin teléfono. */
    public static function whatsapp(array $c, $empresa = '')
    {
        $num = preg_replace('/\D/', '', (string)($c['telefono'] ?? ''));
        if (strlen($num) < 10) return null;
        if (strlen($num) === 10) $num = '52' . $num;     // celular de México sin lada de país
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                  'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $t = strtotime($c['inicio']);
        $cuando = $dias[(int)date('w', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int)date('n', $t)] . ' a las ' . date('H:i', $t);
        $nombre = trim(explode(' ', trim((string)$c['cliente_nombre']))[0]);
        $msg = 'Hola ' . $nombre . ', te recordamos tu cita'
             . (!empty($c['servicio']) ? ' de ' . $c['servicio'] : '')
             . ' el ' . $cuando
             . (!empty($c['especialista']) ? ' con ' . $c['especialista'] : '')
             . (trim((string)$empresa) !== '' ? ' en ' . trim($empresa) : '') . '. '
             . '¿Nos confirmas tu asistencia?';
        return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
    }
}
