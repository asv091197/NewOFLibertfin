<?php
namespace LibertyFin\Datos;

/**
 * Lo de Mercado Libre que vive en la base de la EMPRESA.
 *
 *   lf_meli_cuenta          un renglón (id = 1): la cuenta de vendedor
 *                           conectada, sus llaves (cifradas) y cómo se
 *                           sincroniza.
 *   lf_meli_publicaciones   cada publicación —o cada variación— y con qué
 *                           producto de LibertyFin se cuenta.
 *   lf_meli_ordenes         cada orden que ya movió existencias. Es lo que
 *                           impide descontar dos veces la misma venta
 *                           cuando el aviso llega repetido.
 *
 * Las crea la migración 30.
 */
final class MeliRepo extends Repo
{
    /** Los estados de la publicación en Mercado Libre, como se leen en pantalla. */
    const ESTADOS_ML = [
        'active'       => 'Activa',
        'paused'       => 'Pausada',
        'closed'       => 'Finalizada',
        'under_review' => 'En revisión',
        'inactive'     => 'Inactiva',
    ];

    // ── La cuenta ───────────────────────────────────────────────

    public function cuenta()
    {
        return $this->uno("SELECT * FROM lf_meli_cuenta WHERE id = 1");
    }

    /** La cuenta, bloqueada para cambiarla (dentro de una transacción). */
    public function cuentaParaCambiar()
    {
        return $this->uno("SELECT * FROM lf_meli_cuenta WHERE id = 1 FOR UPDATE");
    }

    public function guardarConexion(array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_meli_cuenta
                (id, meli_user_id, nickname, site_id, access_token, refresh_token, expira_en,
                 estado, conectado_por, conectado_en, actualizado_en, ultimo_error)
            VALUES (1,?,?,?,?,?,?,'conectada',?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                meli_user_id = VALUES(meli_user_id), nickname = VALUES(nickname),
                site_id = VALUES(site_id), access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token), expira_en = VALUES(expira_en),
                estado = 'conectada', conectado_por = VALUES(conectado_por),
                conectado_en = NOW(), actualizado_en = NOW(), ultimo_error = NULL,
                ordenes_desde = NULL
        ")->execute([
            (string)$d['meli_user_id'], mb_substr((string)($d['nickname'] ?? ''), 0, 100),
            mb_substr((string)($d['site_id'] ?? ''), 0, 5),
            (string)$d['access_token'], (string)$d['refresh_token'], (string)$d['expira_en'],
            mb_substr((string)($d['conectado_por'] ?? ''), 0, 100),
        ]);
    }

    public function guardarLlaves($access, $refresh, $expira)
    {
        $this->db->prepare("
            UPDATE lf_meli_cuenta SET access_token = ?, refresh_token = ?, expira_en = ?,
                   estado = 'conectada', ultimo_error = NULL, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([(string)$access, (string)$refresh, (string)$expira]);
    }

    /** La autorización se cayó: hay que reconectar. Las llaves se tiran, ya no sirven. */
    public function marcarVencida($motivo)
    {
        $this->db->prepare("
            UPDATE lf_meli_cuenta SET estado = 'vencida', access_token = NULL, refresh_token = NULL,
                   ultimo_error = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([mb_substr((string)$motivo, 0, 255)]);
    }

    public function desconectar()
    {
        $this->db->exec("
            UPDATE lf_meli_cuenta SET estado = 'desconectada', access_token = NULL,
                   refresh_token = NULL, expira_en = NULL, actualizado_en = NOW()
             WHERE id = 1");
    }

    public function anotarError($motivo)
    {
        $this->db->prepare("UPDATE lf_meli_cuenta SET ultimo_error = ? WHERE id = 1")
                 ->execute([$motivo === '' || $motivo === null ? null : mb_substr((string)$motivo, 0, 255)]);
    }

    public function anotarSincronizado($ordenesHasta = null)
    {
        if ($ordenesHasta !== null) {
            $this->db->prepare("UPDATE lf_meli_cuenta SET sincronizado_en = NOW(), ordenes_desde = ? WHERE id = 1")
                     ->execute([(string)$ordenesHasta]);
        } else {
            $this->db->exec("UPDATE lf_meli_cuenta SET sincronizado_en = NOW() WHERE id = 1");
        }
    }

    public function guardarAjustes(array $d)
    {
        $this->db->prepare("
            UPDATE lf_meli_cuenta SET sucursal_id = ?, reserva = ?, enviar_existencias = ?,
                   descontar_ventas = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([
            $d['sucursal_id'] ?: null, (float)$d['reserva'],
            !empty($d['enviar_existencias']) ? 1 : 0, !empty($d['descontar_ventas']) ? 1 : 0,
        ]);
    }

    // ── Publicaciones ───────────────────────────────────────────

    /**
     * Guarda lo que dice Mercado Libre de una publicación o variación,
     * SIN tocar el vínculo: volver a importar no puede deshacer lo que
     * alguien emparejó a mano.
     */
    public function guardarPublicacion(array $p)
    {
        $this->db->prepare("
            INSERT INTO lf_meli_publicaciones
                (item_id, variacion_id, titulo, variacion, sku, estado_ml, logistica, permalink,
                 imagen, precio, stock_ml, actualizado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                titulo = VALUES(titulo), variacion = VALUES(variacion), sku = VALUES(sku),
                estado_ml = VALUES(estado_ml), logistica = VALUES(logistica),
                permalink = VALUES(permalink), imagen = VALUES(imagen), precio = VALUES(precio),
                stock_ml = VALUES(stock_ml), actualizado_en = NOW()
        ")->execute([
            (string)$p['item_id'], (int)$p['variacion_id'],
            mb_substr((string)$p['titulo'], 0, 255), mb_substr((string)$p['variacion'], 0, 160),
            mb_substr((string)$p['sku'], 0, 100) ?: null, mb_substr((string)$p['estado_ml'], 0, 20),
            mb_substr((string)$p['logistica'], 0, 30) ?: null, mb_substr((string)$p['permalink'], 0, 255) ?: null,
            mb_substr((string)$p['imagen'], 0, 255) ?: null, round((float)$p['precio'], 2), (int)$p['stock_ml'],
        ]);
    }

    /** Lo que ya no vino al importar: la publicación se borró en Mercado Libre. */
    public function marcarAusentes(array $vistos)
    {
        if (!$vistos) return 0;
        $m = implode(',', array_fill(0, count($vistos), '?'));
        $st = $this->db->prepare("UPDATE lf_meli_publicaciones SET estado_ml = 'ausente', pendiente = 0
                                   WHERE item_id NOT IN ($m) AND estado_ml <> 'ausente'");
        $st->execute(array_values($vistos));
        return $st->rowCount();
    }

    public function publicacion($id)
    {
        return $this->uno("SELECT * FROM lf_meli_publicaciones WHERE id = ?", [(int)$id]);
    }

    public function porItem($itemId, $variacionId = 0)
    {
        return $this->uno("SELECT * FROM lf_meli_publicaciones WHERE item_id = ? AND variacion_id = ?",
                          [(string)$itemId, (int)$variacionId]);
    }

    public function deItem($itemId)
    {
        return $this->todos("SELECT * FROM lf_meli_publicaciones WHERE item_id = ? ORDER BY variacion_id",
                            [(string)$itemId]);
    }

    /**
     * Para la pantalla. La existencia de LibertyFin viene en la misma
     * consulta para poder comparar renglón por renglón.
     */
    public function listar(array $f, $sucursalId, $tope = 300)
    {
        $w = ["mp.estado_ml <> 'ausente'"]; $p = [(int)$sucursalId];
        if (($f['ver'] ?? '') === 'sin') $w[] = 'mp.producto_id IS NULL';
        if (($f['ver'] ?? '') === 'con') $w[] = 'mp.producto_id IS NOT NULL';
        if (($f['ver'] ?? '') === 'error') $w[] = "mp.error IS NOT NULL AND mp.error <> ''";
        if (($f['ver'] ?? '') === 'difiere') {
            $w[] = 'mp.producto_id IS NOT NULL AND mp.stock_ml <> FLOOR(COALESCE(ps.stock,0))';
        }
        if (!empty($f['q'])) {
            $w[] = '(mp.titulo LIKE ? OR mp.sku LIKE ? OR mp.item_id LIKE ? OR pr.nombre LIKE ?)';
            $l = '%' . $f['q'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        return $this->todos("
            SELECT mp.*, pr.nombre AS producto, pr.codigo AS producto_codigo, pr.unidad_medida,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_meli_publicaciones mp
              LEFT JOIN productos pr ON pr.id = mp.producto_id
              LEFT JOIN producto_sucursal ps ON ps.producto_id = mp.producto_id AND ps.sucursal_id = ?
             WHERE " . implode(' AND ', $w) . "
             ORDER BY (mp.producto_id IS NULL) DESC, mp.titulo, mp.variacion
             LIMIT " . max(1, min(1000, (int)$tope)), $p);
    }

    public function resumen()
    {
        $f = $this->uno("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN producto_id IS NOT NULL THEN 1 ELSE 0 END) AS vinculadas,
                   SUM(CASE WHEN pendiente = 1 THEN 1 ELSE 0 END) AS pendientes,
                   SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS errores
              FROM lf_meli_publicaciones WHERE estado_ml <> 'ausente'");
        return array_map('intval', $f ?: ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0]);
    }

    public function vincular($id, $productoId, $como = 'manual', $pendiente = false)
    {
        $this->db->prepare("
            UPDATE lf_meli_publicaciones SET producto_id = ?, vinculo = ?, error = NULL,
                   pendiente = ? WHERE id = ?
        ")->execute([$productoId ?: null, $productoId ? $como : null, $pendiente ? 1 : 0, (int)$id]);
    }

    /** Las que tienen SKU y no tienen producto: candidatas a emparejar solas. */
    public function sinVincularConSku()
    {
        return $this->todos("SELECT id, sku FROM lf_meli_publicaciones
                              WHERE producto_id IS NULL AND sku IS NOT NULL AND sku <> ''
                                AND estado_ml <> 'ausente'");
    }

    /** Un producto se movió: sus publicaciones tienen que enterarse. */
    public function marcarPendiente($productoId)
    {
        $this->db->prepare("UPDATE lf_meli_publicaciones SET pendiente = 1
                             WHERE producto_id = ? AND estado_ml NOT IN ('closed','ausente')
                               AND COALESCE(logistica,'') <> 'fulfillment'")
                 ->execute([(int)$productoId]);
    }

    public function marcarTodasPendientes()
    {
        $st = $this->db->prepare("UPDATE lf_meli_publicaciones SET pendiente = 1
                                   WHERE producto_id IS NOT NULL AND estado_ml NOT IN ('closed','ausente')
                                     AND COALESCE(logistica,'') <> 'fulfillment'");
        $st->execute();
        return $st->rowCount();
    }

    /** Las publicaciones (item_id) con algo pendiente de mandar. */
    public function itemsPendientes($tope)
    {
        return array_column($this->todos("
            SELECT item_id, MIN(id) AS primero FROM lf_meli_publicaciones
             WHERE pendiente = 1 AND producto_id IS NOT NULL
             GROUP BY item_id ORDER BY primero LIMIT " . max(1, (int)$tope)), 'item_id');
    }

    public function anotarEnviado($id, $cantidad)
    {
        $this->db->prepare("
            UPDATE lf_meli_publicaciones SET pendiente = 0, stock_ml = ?, enviado_stock = ?,
                   enviado_en = NOW(), error = NULL WHERE id = ?
        ")->execute([(int)$cantidad, (int)$cantidad, (int)$id]);
    }

    /**
     * No se pudo mandar. Se queda pendiente salvo que el error sea de los
     * que no se arreglan reintentando (Full, publicación finalizada): esos
     * repetirían el mismo rechazo cada minuto para siempre.
     */
    public function anotarFallo($id, $error, $reintentar)
    {
        $this->db->prepare("UPDATE lf_meli_publicaciones SET error = ?, pendiente = ? WHERE id = ?")
                 ->execute([mb_substr((string)$error, 0, 255), $reintentar ? 1 : 0, (int)$id]);
    }

    public function anotarStockMl($itemId, $variacionId, $stock, $estado = null)
    {
        $this->db->prepare("UPDATE lf_meli_publicaciones SET stock_ml = ?, estado_ml = COALESCE(?, estado_ml),
                                   actualizado_en = NOW()
                             WHERE item_id = ? AND variacion_id = ?")
                 ->execute([(int)$stock, $estado, (string)$itemId, (int)$variacionId]);
    }

    // ── Órdenes ─────────────────────────────────────────────────

    /**
     * Toma la orden para trabajarla. Va DENTRO de una transacción: el
     * FOR UPDATE hace que si el aviso y la revisión periódica llegan al
     * mismo tiempo, el segundo espere al primero y lea lo que dejó, en
     * vez de descontar otra vez.
     */
    public function tomarOrden($ordenId)
    {
        $this->db->prepare("INSERT IGNORE INTO lf_meli_ordenes (orden_id, estado, creado_en) VALUES (?, 'nueva', NOW())")
                 ->execute([(string)$ordenId]);
        return $this->uno("SELECT * FROM lf_meli_ordenes WHERE orden_id = ? FOR UPDATE", [(string)$ordenId]);
    }

    public function guardarOrden($ordenId, array $d)
    {
        $this->db->prepare("
            UPDATE lf_meli_ordenes SET estado = ?, estado_ml = ?, comprador = ?, total = ?,
                   fecha_ml = ?, aplicado = ?, nota = ?, actualizado_en = NOW()
             WHERE orden_id = ?
        ")->execute([
            (string)$d['estado'], mb_substr((string)$d['estado_ml'], 0, 30),
            mb_substr((string)$d['comprador'], 0, 120), round((float)$d['total'], 2),
            $d['fecha_ml'] ?: null, $d['aplicado'] !== null ? json_encode($d['aplicado']) : null,
            mb_substr((string)$d['nota'], 0, 255) ?: null, (string)$ordenId,
        ]);
    }

    public function ordenes($tope = 100)
    {
        return $this->todos("SELECT * FROM lf_meli_ordenes ORDER BY COALESCE(fecha_ml, creado_en) DESC LIMIT "
                            . max(1, min(500, (int)$tope)));
    }
}
