<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Lo que hay que atender hoy, en un solo lugar.
 *
 * ── NO SE GUARDAN ──
 *
 * Esto no es una bandeja de mensajes: es una lista de SITUACIONES.
 * «Quedan 2 refrescos», «la caja de ayer sigue abierta», «dejaste una
 * captura a medias». Ninguna es un aviso que alguien mandó; todas son
 * cosas que están pasando ahora mismo y que se pueden mirar en la base.
 *
 * Por eso no hay tabla de notificaciones. Se calculan al pedirlas, y
 * desaparecen solas cuando se arregla lo que las causó. Guardarlas
 * traería el problema de siempre: avisos de algo que ya se resolvió,
 * que hay que ir a marcar como leídos uno por uno, y que acaban
 * ignorándose todos.
 *
 * ── CADA FUENTE EN SU PROPIO try ──
 *
 * Si la base no tiene todavía las tablas de envíos o de capturas, la
 * campana tiene que seguir enseñando lo demás. Una fuente rota se
 * salta en silencio; no vale la pena tirar toda la lista por una.
 */
final class Notificaciones
{
    /** Cuánto se guarda el conteo en la sesión, en segundos. */
    const CACHE = 120;

    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Todo lo que hay que atender, lo más urgente primero.
     *
     * @return array [['clase','icono','texto','detalle','url','urgente'], ...]
     */
    public function todas($sucursalId)
    {
        $r = [];
        foreach (['inventario', 'capturas', 'cajas', 'envios'] as $fuente) {
            try {
                foreach ($this->$fuente((int)$sucursalId) as $n) $r[] = $n;
            } catch (\Throwable $e) {
                // Base sin migrar o tabla ausente: se salta esta fuente.
                continue;
            }
        }
        // Lo urgente arriba. Dentro de cada grupo se respeta el orden en
        // que lo puso cada fuente, que ya viene pensado.
        usort($r, function ($a, $b) {
            return ($b['urgente'] ? 1 : 0) <=> ($a['urgente'] ? 1 : 0);
        });
        return $r;
    }

    /**
     * Cuántas hay, con memoria corta.
     *
     * La campana sale en TODAS las pantallas, así que sin esto cada
     * clic en el menú dispararía cuatro consultas. Dos minutos es poco
     * para que algo se quede sin verse y mucho para que se note.
     */
    public function cuantas($sucursalId)
    {
        $llave = 'lf_noti_' . (int)$sucursalId;
        $c = $_SESSION[$llave] ?? null;
        if (is_array($c) && (time() - ($c['cuando'] ?? 0)) < self::CACHE) {
            return (int)$c['n'];
        }
        $todas = $this->todas($sucursalId);
        $_SESSION[$llave] = ['n' => count($todas), 'cuando' => time()];
        return count($todas);
    }

    /** Se llama cuando algo cambió y el número ya no vale. */
    public static function olvidar($sucursalId = null)
    {
        if ($sucursalId !== null) { unset($_SESSION['lf_noti_' . (int)$sucursalId]); return; }
        foreach (array_keys($_SESSION) as $k) {
            if (strncmp($k, 'lf_noti_', 8) === 0) unset($_SESSION[$k]);
        }
    }

    // ═════════════════════════════════════════════════════════
    //  LAS FUENTES
    // ═════════════════════════════════════════════════════════

    /** Lo que se está acabando o se va a echar a perder. */
    private function inventario($suc)
    {
        $a = new Alertas($this->db);
        // Si en Ajustes se apagaron los dos avisos, no hay nada que
        // decir: alguien decidió que no quería que le avisaran.
        if ($a->nivelStock() === 'no' && $a->nivelCaducidad() === 'no') return [];

        $c = $a->contar($suc);
        $r = [];

        if ($c['agotados'] > 0) {
            $r[] = $this->n('inventario', 'bolsa', true,
                $c['agotados'] . ($c['agotados'] === 1 ? ' producto agotado' : ' productos agotados'),
                'No se pueden vender hasta que entren.',
                '/inventario?t=alertas&s=' . $suc);
        }
        if ($c['caducados'] > 0) {
            $r[] = $this->n('inventario', 'alerta', true,
                $c['caducados'] . ($c['caducados'] === 1 ? ' producto caducado' : ' productos caducados'),
                'Siguen en existencia. Hay que darlos de baja.',
                '/inventario?t=alertas&s=' . $suc);
        }
        if ($c['por_caducar'] > 0) {
            $r[] = $this->n('inventario', 'reloj', false,
                $c['por_caducar'] . ' por caducar',
                'Caducan pronto. Conviene moverlos o rebajarlos.',
                '/inventario?t=alertas&s=' . $suc);
        }
        if ($c['bajos'] > 0) {
            $r[] = $this->n('inventario', 'bolsa', false,
                $c['bajos'] . ($c['bajos'] === 1 ? ' producto por acabarse' : ' productos por acabarse'),
                'Están en su mínimo o abajo.',
                '/inventario?t=alertas&s=' . $suc);
        }
        return $r;
    }

    /**
     * Capturas y conteos que alguien dejó a medias.
     *
     * Es el aviso que más falta hacía: una captura pausada no se ve en
     * ningún lado si no entras a Inventario, y quien la dejó se va a su
     * casa sin aplicarla.
     */
    private function capturas($suc)
    {
        $r = [];

        $st = $this->db->prepare("
            SELECT c.id, c.tipo, c.estado, c.usuario_nombre, c.creado_en,
                   COALESCE(SUM(l.cantidad),0) AS piezas
              FROM lf_inventario_capturas c
              LEFT JOIN lf_inventario_captura_lineas l ON l.captura_id = c.id
             WHERE c.sucursal_id = ? AND c.estado IN ('abierta','pausada')
             GROUP BY c.id ORDER BY c.id DESC LIMIT 5");
        $st->execute([(int)$suc]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $rot = Captura::TIPOS[$c['tipo']] ?? $c['tipo'];
            $r[] = $this->n('captura', 'caja', false,
                $rot . ' a medias' . ($c['piezas'] > 0
                    ? ' · ' . Captura::num($c['piezas']) . ' piezas' : ''),
                'La empezó ' . ($c['usuario_nombre'] ?: 'alguien') . ' y no la ha aplicado. '
                . 'Las existencias no se han movido.',
                '/inventario/captura?s=' . $suc);
        }

        try {
            $st = $this->db->prepare("
                SELECT id, estado, usuario_nombre FROM lf_inventario_conteos
                 WHERE sucursal_id = ? AND estado IN ('abierto','pausado')
                 ORDER BY id DESC LIMIT 3");
            $st->execute([(int)$suc]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $r[] = $this->n('conteo', 'lista', false,
                    'Conteo físico sin terminar',
                    'Lo empezó ' . ($c['usuario_nombre'] ?: 'alguien') . '.',
                    '/inventario?t=conteo&s=' . $suc);
            }
        } catch (\Throwable $e) { /* sin tabla de conteos: se omite */ }

        return $r;
    }

    /**
     * Cajas que se quedaron abiertas de un día anterior.
     *
     * Es urgente de verdad: mientras siga abierta, las ventas de hoy
     * entran al corte de ayer y el arqueo nunca va a cuadrar.
     */
    private function cajas($suc)
    {
        $st = $this->db->prepare("
            SELECT c.id, c.fecha_apertura, u.nombre
              FROM caja c
              LEFT JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.estado = 'abierta' AND c.sucursal_id = ?
               AND DATE(c.fecha_apertura) < CURDATE()
             ORDER BY c.fecha_apertura LIMIT 5");
        $st->execute([(int)$suc]);

        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $dias = max(1, (int)floor((time() - strtotime($c['fecha_apertura'])) / 86400));
            $r[] = $this->n('caja', 'cobro', true,
                'Caja abierta desde hace ' . $dias . ($dias === 1 ? ' día' : ' días'),
                'Es de ' . ($c['nombre'] ?: 'alguien') . '. Mientras no se cierre, lo de hoy '
                . 'entra a ese corte.',
                '/corte?t=cajas');
        }
        return $r;
    }

    /** Paquetes que llegaron, y los que llevan mucho sin moverse. */
    private function envios($suc)
    {
        $r = [];

        $st = $this->db->prepare("
            SELECT COUNT(*) FROM lf_envios
             WHERE estado = 'entregado'
               AND rastreado_en >= DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $st->execute();
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $r[] = $this->n('envio', 'camion', false,
                $n . ($n === 1 ? ' paquete entregado' : ' paquetes entregados'),
                'Llegaron en los últimos dos días.',
                '/envios?estado=entregado');
        }

        // Una guía generada hace una semana que la paquetería nunca
        // recogió. Es dinero gastado en una guía que no sirvió.
        $st = $this->db->prepare("
            SELECT COUNT(*) FROM lf_envios
             WHERE estado = 'generado'
               AND creado_en < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $st->execute();
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $r[] = $this->n('envio', 'camion', false,
                $n . ($n === 1 ? ' guía sin recoger' : ' guías sin recoger'),
                'Tienen más de una semana y la paquetería no las ha movido.',
                '/envios?estado=generado');
        }
        return $r;
    }

    private function n($clase, $icono, $urgente, $texto, $detalle, $url)
    {
        return ['clase' => $clase, 'icono' => $icono, 'urgente' => (bool)$urgente,
                'texto' => $texto, 'detalle' => $detalle, 'url' => $url];
    }
}