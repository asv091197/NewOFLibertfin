<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Capturar una entrada o una salida con la pistola, sin prisa.
 *
 * CÓMO SE USA EN EL MOSTRADOR
 *
 * Llega el proveedor con veinte cajas. Se abre una captura, se van
 * pasando una por una con el lector, y a la mitad entra un cliente.
 * Se PAUSA, se cobra, se vuelve, y se sigue donde iba. Al terminar se
 * finaliza y hasta entonces se mueve la existencia.
 *
 * ── LO QUE NO SE HACE, Y POR QUÉ ──
 *
 * Cada escaneo NO toca el inventario. Si lo tocara, una captura
 * abandonada a media tarde dejaría diez cajas contadas que nadie
 * verificó, y nadie se enteraría de que quedó a medias: el inventario
 * diría que están y en el piso no estarían.
 *
 * Así, lo escaneado vive aparte hasta el final. Se aplica todo junto o
 * no se aplica nada, y una captura abandonada no deja rastro.
 *
 * ── UNA POR PERSONA ──
 *
 * Cada quien tiene la suya. Dos personas pueden estar capturando a la
 * vez en la misma sucursal sin pisarse, que es lo normal cuando llega
 * mercancía y alguien más está dando salidas.
 */
final class Captura
{
    /** Qué se puede capturar así. El traspaso no: ése pide destino. */
    const TIPOS = [
        'entrada' => 'Entrada',
        'salida'  => 'Salida',
        'merma'   => 'Merma',
    ];

    private $db;
    private $inv;

    public function __construct(PDO $db)
    {
        $this->db  = $db;
        $this->inv = new Inventario($db);
    }

    // ═════════════════════════════════════════════════════════
    //  ABRIR, PAUSAR, SEGUIR
    // ═════════════════════════════════════════════════════════

    /**
     * La captura que esta persona dejó a medias, si la hay.
     *
     * Busca la abierta Y la pausada: para quien vuelve del mostrador
     * son lo mismo —«lo que estaba haciendo»— y distinguirlas aquí solo
     * serviría para que una de las dos se perdiera de vista.
     */
    public function mia($sucursalId, $usuarioId)
    {
        $st = $this->db->prepare("
            SELECT * FROM lf_inventario_capturas
             WHERE sucursal_id = ? AND usuario_id = ?
               AND estado IN ('abierta','pausada')
             ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$sucursalId, (int)$usuarioId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ? $this->porId($c['id']) : null;
    }

    public function porId($id)
    {
        $st = $this->db->prepare("
            SELECT c.*, a.nombre AS almacen
              FROM lf_inventario_capturas c
              LEFT JOIN lf_almacenes a ON a.id = c.almacen_id
             WHERE c.id = ?");
        $st->execute([(int)$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $c['tipo_rotulo'] = self::TIPOS[$c['tipo']] ?? $c['tipo'];
        $c['almacen']     = $c['almacen'] ?: 'Piso';
        $c['lineas']      = $this->lineas($c['id']);
        $c['piezas']      = 0.0;
        foreach ($c['lineas'] as $l) $c['piezas'] += (float)$l['cantidad'];
        return $c;
    }

    /** Lo capturado, lo último arriba: es lo que se acaba de escanear. */
    public function lineas($capturaId)
    {
        $st = $this->db->prepare("
            SELECT l.*, p.nombre, p.codigo, p.codigo_barras, p.unidad_medida,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_inventario_captura_lineas l
              JOIN productos p ON p.id = l.producto_id
              LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id
                    AND ps.sucursal_id = (SELECT sucursal_id FROM lf_inventario_capturas
                                           WHERE id = l.captura_id)
             WHERE l.captura_id = ?
             ORDER BY l.agregado_en DESC, l.id DESC");
        $st->execute([(int)$capturaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Abre una captura. Si esta persona ya tenía una, la devuelve.
     *
     * No se abre una segunda a propósito: quien dejó algo a medias casi
     * siempre quiere seguir con eso, no empezar de cero y que lo de
     * antes se quede olvidado en una tabla.
     */
    public function abrir($sucursalId, $tipo, $almacenId = null, $motivo = '', $nota = '')
    {
        $tipo = isset(self::TIPOS[$tipo]) ? $tipo : 'entrada';

        $ya = $this->mia($sucursalId, (int)($_SESSION['usuario_id'] ?? 0));
        if ($ya) return $ya;

        $this->db->prepare("
            INSERT INTO lf_inventario_capturas
                (sucursal_id, almacen_id, tipo, estado, motivo, nota,
                 usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?, 'abierta', ?,?,?,?, NOW())
        ")->execute([
            (int)$sucursalId,
            $almacenId ? (int)$almacenId : null,
            $tipo,
            ($tipo === 'merma' && $motivo !== '') ? mb_substr($motivo, 0, 20) : null,
            mb_substr(trim((string)$nota), 0, 255),
            (int)($_SESSION['usuario_id'] ?? 0) ?: null,
            mb_substr((string)($_SESSION['usuario_nombre'] ?? ''), 0, 100),
        ]);
        return $this->porId((int)$this->db->lastInsertId());
    }

    public function pausar($id)
    {
        $this->db->prepare("UPDATE lf_inventario_capturas
                               SET estado = 'pausada', pausado_en = NOW()
                             WHERE id = ? AND estado = 'abierta'")->execute([(int)$id]);
    }

    public function reanudar($id)
    {
        $this->db->prepare("UPDATE lf_inventario_capturas
                               SET estado = 'abierta', pausado_en = NULL
                             WHERE id = ? AND estado = 'pausada'")->execute([(int)$id]);
    }

    public function cancelar($id)
    {
        $this->db->prepare("UPDATE lf_inventario_capturas
                               SET estado = 'cancelada', cerrado_en = NOW()
                             WHERE id = ? AND estado IN ('abierta','pausada')")
                 ->execute([(int)$id]);
    }

    // ═════════════════════════════════════════════════════════
    //  ESCANEAR
    // ═════════════════════════════════════════════════════════

    /**
     * Mete lo que se acaba de leer con la pistola.
     *
     * El mismo código dos veces SUMA en vez de repetir el renglón: con
     * la pistola se pasan cinco cajas iguales seguidas, y cinco
     * renglones de «1» en pantalla son imposibles de revisar.
     *
     * @return array ['ok'=>bool, 'texto'=>string, 'producto'=>?array]
     */
    public function escanear($id, $codigo, $cantidad = 1)
    {
        $c = $this->porId($id);
        if (!$c) return ['ok' => false, 'texto' => 'Esa captura ya no existe.'];
        if ($c['estado'] !== 'abierta') {
            return ['ok' => false, 'texto' => 'La captura está pausada. Dale a «Seguir» primero.'];
        }

        $codigo = trim((string)$codigo);
        if ($codigo === '') return ['ok' => false, 'texto' => 'No se leyó nada.'];

        $p = $this->inv->buscarProducto($codigo);
        if (!$p) {
            return ['ok' => false,
                    'texto' => 'No encontré ningún producto con «' . $codigo . '». '
                             . 'Revisa que esté dado de alta y que lleve inventario.'];
        }

        $cant = (float)$cantidad;
        if ($cant <= 0) $cant = 1;

        $this->db->prepare("
            INSERT INTO lf_inventario_captura_lineas
                (captura_id, producto_id, cantidad, agregado_en)
            VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad),
                                    agregado_en = NOW()
        ")->execute([(int)$id, (int)$p['id'], $cant]);

        $st = $this->db->prepare("SELECT cantidad FROM lf_inventario_captura_lineas
                                   WHERE captura_id = ? AND producto_id = ?");
        $st->execute([(int)$id, (int)$p['id']]);
        $acum = (float)$st->fetchColumn();

        return ['ok' => true, 'producto' => $p,
                'cantidad' => $acum,
                'texto' => $p['nombre'] . ' · ' . self::num($acum)];
    }

    /** Corrige un renglón a mano. Con 0 o menos, lo quita. */
    public function ajustar($id, $productoId, $cantidad)
    {
        $cant = (float)$cantidad;
        if ($cant <= 0) {
            $this->db->prepare("DELETE FROM lf_inventario_captura_lineas
                                 WHERE captura_id = ? AND producto_id = ?")
                     ->execute([(int)$id, (int)$productoId]);
            return;
        }
        $this->db->prepare("UPDATE lf_inventario_captura_lineas
                               SET cantidad = ? WHERE captura_id = ? AND producto_id = ?")
                 ->execute([$cant, (int)$id, (int)$productoId]);
    }

    // ═════════════════════════════════════════════════════════
    //  FINALIZAR
    // ═════════════════════════════════════════════════════════

    /**
     * Aplica todo de una vez.
     *
     * Va en una transacción: o se mueven todos los renglones o no se
     * mueve ninguno. Una captura aplicada a medias es lo peor que
     * podría pasar aquí, porque nadie sabría hasta dónde llegó.
     *
     * @return array ['ok'=>bool, 'texto'=>string, 'movidos'=>int]
     */
    public function finalizar($id)
    {
        $c = $this->porId($id);
        if (!$c) return ['ok' => false, 'texto' => 'Esa captura ya no existe.', 'movidos' => 0];
        if (!in_array($c['estado'], ['abierta', 'pausada'], true)) {
            return ['ok' => false, 'texto' => 'Esta captura ya estaba cerrada.', 'movidos' => 0];
        }
        if (!$c['lineas']) {
            return ['ok' => false, 'texto' => 'No capturaste nada todavía.', 'movidos' => 0];
        }

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $movidos = 0;
            foreach ($c['lineas'] as $l) {
                $cant = (float)$l['cantidad'];
                if ($cant <= 0) continue;

                $d = ['nota' => $this->nota($c), 'almacen_id' => $c['almacen_id']];
                if ($c['tipo'] === 'merma') $d['motivo'] = (string)($c['motivo'] ?: 'otro');
                if ($l['costo'] !== null && $c['tipo'] === 'entrada') $d['costo'] = $l['costo'];

                // Pasa por `registrar`, no por un atajo: ahí viven las
                // revisiones que importan —que el producto lleve
                // inventario, que la sucursal exista, que la merma traiga
                // motivo— y saltárselas aquí sería abrir una puerta
                // trasera al inventario.
                $this->inv->registrar($c['tipo'], (int)$l['producto_id'],
                                      (int)$c['sucursal_id'], $cant, $d);
                $movidos++;
            }

            $this->db->prepare("UPDATE lf_inventario_capturas
                                   SET estado = 'aplicada', cerrado_en = NOW()
                                 WHERE id = ?")->execute([(int)$id]);

            if ($propia) $this->db->commit();
            // Las existencias acaban de cambiar y la captura dejó de
            // estar pendiente: los dos números guardados ya no valen.
            Alertas::olvidar((int)$c['sucursal_id']);
            Notificaciones::olvidar((int)$c['sucursal_id']);

            return ['ok' => true, 'movidos' => $movidos,
                    'texto' => $c['tipo_rotulo'] . ' aplicada: ' . $movidos
                             . ($movidos === 1 ? ' producto' : ' productos') . ', '
                             . self::num($c['piezas']) . ' en total.'];
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            error_log('[LibertyFin] finalizar captura ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'movidos' => 0,
                    'texto' => 'No se pudo aplicar: ' . $e->getMessage()
                             . ' · No se movió nada, la captura sigue completa.'];
        }
    }

    /** Las últimas, para la pestaña. */
    public function recientes($sucursalId, $tope = 15)
    {
        $st = $this->db->prepare("
            SELECT c.*, COUNT(l.id) AS renglones, COALESCE(SUM(l.cantidad),0) AS piezas
              FROM lf_inventario_capturas c
              LEFT JOIN lf_inventario_captura_lineas l ON l.captura_id = c.id
             WHERE c.sucursal_id = ?
             GROUP BY c.id
             ORDER BY c.id DESC
             LIMIT " . max(1, min(50, (int)$tope)));
        $st->execute([(int)$sucursalId]);
        $r = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($r as &$f) $f['tipo_rotulo'] = self::TIPOS[$f['tipo']] ?? $f['tipo'];
        return $r;
    }

    private function nota(array $c)
    {
        $n = 'Captura #' . $c['id'];
        if (trim((string)$c['nota']) !== '') $n .= ' · ' . $c['nota'];
        return mb_substr($n, 0, 200);
    }

    /** Sin decimales cuando no hacen falta: «3», no «3.000». */
    public static function num($v)
    {
        $v = (float)$v;
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}