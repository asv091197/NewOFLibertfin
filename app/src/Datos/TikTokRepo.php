<?php
namespace LibertyFin\Datos;

/**
 * Lo de TikTok Shop que vive en la base de la EMPRESA.
 *
 *   lf_tt_cuenta      un renglón (id = 1): la tienda conectada, su llave y su
 *                     shop_cipher, las credenciales PROPIAS de la empresa si
 *                     usa una app «Seller in-house» (vacías = las globales),
 *                     el almacén de TikTok que corresponde a la sucursal y
 *                     cómo se sincroniza. Todo lo secreto va cifrado.
 *   lf_tt_productos   cada SKU de cada producto de la tienda, y con qué
 *                     producto de LibertyFin se cuenta.
 *   lf_tt_ordenes     cada orden que ya movió existencias: lo que impide
 *                     descontar dos veces cuando el aviso llega repetido.
 *
 * Las crea la migración 33. Es el mismo diseño que ShopifyRepo.
 */
final class TikTokRepo extends Repo
{
    const ESTADOS = [
        'visible' => 'Activo', 'oculto' => 'Borrador', 'revision' => 'En revisión',
        'rechazado' => 'Rechazado', 'archivado' => 'Desactivado', 'ausente' => 'Borrado',
    ];

    /** Estado del producto en TikTok → el nuestro. */
    const DE_TIKTOK = [
        'ACTIVATE' => 'visible', 'LIVE' => 'visible', 'DRAFT' => 'oculto', 'PENDING' => 'revision',
        'FAILED' => 'rechazado', 'SELLER_DEACTIVATED' => 'archivado', 'PLATFORM_DEACTIVATED' => 'archivado',
        'FREEZE' => 'archivado', 'DELETED' => 'ausente',
    ];

    // ── La cuenta ───────────────────────────────────────────────

    public function cuenta()
    {
        return $this->uno("SELECT * FROM lf_tt_cuenta WHERE id = 1");
    }

    /**
     * Las credenciales de la app «Seller in-house» de esta empresa. El
     * secreto llega YA cifrado. Cambiarlas tira la conexión: la llave que
     * había la dio la otra app.
     */
    public function guardarCredenciales($appKey, $appSecretCifrado, $serviceId)
    {
        $this->db->prepare("
            INSERT INTO lf_tt_cuenta (id, app_key, app_secret, service_id, estado, actualizado_en)
            VALUES (1, ?, ?, ?, 'desconectada', NOW())
            ON DUPLICATE KEY UPDATE
                estado = IF(lf_tt_cuenta.app_key <=> VALUES(app_key), lf_tt_cuenta.estado, 'desconectada'),
                access_token = IF(lf_tt_cuenta.app_key <=> VALUES(app_key), lf_tt_cuenta.access_token, NULL),
                refresh_token = IF(lf_tt_cuenta.app_key <=> VALUES(app_key), lf_tt_cuenta.refresh_token, NULL),
                app_key = VALUES(app_key), app_secret = VALUES(app_secret), service_id = VALUES(service_id),
                actualizado_en = NOW()
        ")->execute([
            mb_substr((string)$appKey, 0, 64) ?: null, (string)$appSecretCifrado ?: null,
            mb_substr((string)$serviceId, 0, 64) ?: null,
        ]);
    }

    /** Volver a la app global (ISV). Igual que arriba, tira la conexión. */
    public function borrarCredenciales()
    {
        $this->db->exec("UPDATE lf_tt_cuenta SET app_key = NULL, app_secret = NULL, service_id = NULL,
                                estado = 'desconectada', access_token = NULL, refresh_token = NULL,
                                actualizado_en = NOW() WHERE id = 1");
    }

    /** Reconectar la MISMA tienda conserva el almacén elegido en Ajustes. */
    public function guardarConexion(array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_tt_cuenta
                (id, shop_id, shop_cipher, nombre, region, vendedor, access_token, refresh_token, expira_en,
                 refresh_expira_en, estado, almacen_id, almacen_nombre, conectado_por, conectado_en, actualizado_en, ultimo_error)
            VALUES (1,?,?,?,?,?,?,?,?,?,'conectada',?,?,?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                shop_cipher = VALUES(shop_cipher), nombre = VALUES(nombre), region = VALUES(region),
                vendedor = VALUES(vendedor), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token),
                expira_en = VALUES(expira_en), refresh_expira_en = VALUES(refresh_expira_en), estado = 'conectada',
                almacen_id = IF(lf_tt_cuenta.shop_id = VALUES(shop_id) AND lf_tt_cuenta.almacen_id IS NOT NULL,
                                lf_tt_cuenta.almacen_id, VALUES(almacen_id)),
                almacen_nombre = IF(lf_tt_cuenta.shop_id = VALUES(shop_id) AND lf_tt_cuenta.almacen_id IS NOT NULL,
                                lf_tt_cuenta.almacen_nombre, VALUES(almacen_nombre)),
                shop_id = VALUES(shop_id), conectado_por = VALUES(conectado_por),
                conectado_en = NOW(), actualizado_en = NOW(), ultimo_error = NULL, ordenes_desde = NULL
        ")->execute([
            (string)$d['shop_id'], (string)$d['shop_cipher'], mb_substr((string)($d['nombre'] ?? ''), 0, 120),
            mb_substr((string)($d['region'] ?? ''), 0, 8) ?: null, mb_substr((string)($d['vendedor'] ?? ''), 0, 120) ?: null,
            (string)$d['access_token'], ($d['refresh_token'] ?? '') !== '' ? (string)$d['refresh_token'] : null,
            $d['expira_en'] ?? null, $d['refresh_expira_en'] ?? null,
            ($d['almacen_id'] ?? '') !== '' ? (string)$d['almacen_id'] : null,
            mb_substr((string)($d['almacen_nombre'] ?? ''), 0, 120) ?: null,
            mb_substr((string)($d['conectado_por'] ?? ''), 0, 100),
        ]);
    }

    public function guardarLlave($accessToken, $refreshToken, $expiraEn, $refreshExpiraEn)
    {
        $this->db->prepare("UPDATE lf_tt_cuenta SET access_token = ?, refresh_token = COALESCE(?, refresh_token),
                                   expira_en = ?, refresh_expira_en = COALESCE(?, refresh_expira_en), actualizado_en = NOW()
                             WHERE id = 1")
                 ->execute([(string)$accessToken, $refreshToken !== '' && $refreshToken !== null ? (string)$refreshToken : null,
                            $expiraEn, $refreshExpiraEn]);
    }

    /** La llave ya no vale (quitaron el permiso o caducó la renovación): hay que reconectar. */
    public function marcarVencida($motivo)
    {
        $this->db->prepare("
            UPDATE lf_tt_cuenta SET estado = 'vencida', access_token = NULL, refresh_token = NULL,
                   ultimo_error = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([mb_substr((string)$motivo, 0, 255)]);
    }

    public function desconectar()
    {
        $this->db->exec("UPDATE lf_tt_cuenta SET estado = 'desconectada', access_token = NULL, refresh_token = NULL,
                                actualizado_en = NOW() WHERE id = 1");
    }

    public function anotarError($motivo)
    {
        $this->db->prepare("UPDATE lf_tt_cuenta SET ultimo_error = ? WHERE id = 1")
                 ->execute([$motivo === '' || $motivo === null ? null : mb_substr((string)$motivo, 0, 255)]);
    }

    /** Llegó un aviso válido: así la pantalla sabe que los avisos sí están configurados. */
    public function anotarAviso()
    {
        $this->db->exec("UPDATE lf_tt_cuenta SET aviso_en = NOW() WHERE id = 1");
    }

    public function anotarSincronizado($ordenesHasta = null)
    {
        if ($ordenesHasta !== null) {
            $this->db->prepare("UPDATE lf_tt_cuenta SET sincronizado_en = NOW(), ordenes_desde = ? WHERE id = 1")
                     ->execute([(string)$ordenesHasta]);
        } else {
            $this->db->exec("UPDATE lf_tt_cuenta SET sincronizado_en = NOW() WHERE id = 1");
        }
    }

    public function guardarAjustes(array $d)
    {
        $this->db->prepare("
            UPDATE lf_tt_cuenta SET sucursal_id = ?, almacen_id = ?, almacen_nombre = ?, reserva = ?,
                   enviar_existencias = ?, descontar_ventas = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([
            $d['sucursal_id'] ?: null,
            ($d['almacen_id'] ?? '') !== '' ? (string)$d['almacen_id'] : null,
            mb_substr((string)($d['almacen_nombre'] ?? ''), 0, 120) ?: null,
            (float)$d['reserva'],
            !empty($d['enviar_existencias']) ? 1 : 0, !empty($d['descontar_ventas']) ? 1 : 0,
        ]);
    }

    // ── Productos (cada SKU es un renglón) ──────────────────────

    /** Guarda lo que dice TikTok SIN tocar el vínculo: volver a importar no deshace lo emparejado a mano. */
    public function guardarSku(array $p)
    {
        $this->db->prepare("
            INSERT INTO lf_tt_productos
                (producto_tt, sku_tt, titulo, variante, sku, estado_tt, imagen, precio, stock_tt, actualizado_en)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                producto_tt = VALUES(producto_tt), titulo = VALUES(titulo), variante = VALUES(variante),
                sku = VALUES(sku), estado_tt = VALUES(estado_tt), imagen = VALUES(imagen),
                precio = VALUES(precio), stock_tt = VALUES(stock_tt), actualizado_en = NOW()
        ")->execute([
            (string)$p['producto_tt'], (string)$p['sku_tt'],
            mb_substr((string)$p['titulo'], 0, 255), mb_substr((string)$p['variante'], 0, 160),
            mb_substr((string)$p['sku'], 0, 100) ?: null, mb_substr((string)$p['estado_tt'], 0, 20),
            mb_substr((string)$p['imagen'], 0, 255) ?: null,
            round((float)$p['precio'], 2), $p['stock_tt'] === null ? null : (int)$p['stock_tt'],
        ]);
    }

    /** Lo que ya no vino al importar: se borró en TikTok. */
    public function marcarAusentes(array $vistos)
    {
        if (!$vistos) return 0;
        $m = implode(',', array_fill(0, count($vistos), '?'));
        $st = $this->db->prepare("UPDATE lf_tt_productos SET estado_tt = 'ausente', pendiente = 0
                                   WHERE sku_tt NOT IN ($m) AND estado_tt <> 'ausente'");
        $st->execute(array_map('strval', array_values($vistos)));
        return $st->rowCount();
    }

    public function sku($id)
    {
        return $this->uno("SELECT * FROM lf_tt_productos WHERE id = ?", [(int)$id]);
    }

    public function porSkuTikTok($skuTt)
    {
        return $this->uno("SELECT * FROM lf_tt_productos WHERE sku_tt = ?", [(string)$skuTt]);
    }

    public function listar(array $f, $sucursalId, $tope = 300)
    {
        $w = ["tp.estado_tt <> 'ausente'"]; $p = [(int)$sucursalId];
        if (($f['ver'] ?? '') === 'sin') $w[] = 'tp.producto_id IS NULL';
        if (($f['ver'] ?? '') === 'con') $w[] = 'tp.producto_id IS NOT NULL';
        if (($f['ver'] ?? '') === 'error') $w[] = "tp.error IS NOT NULL AND tp.error <> ''";
        if (($f['ver'] ?? '') === 'difiere') {
            $w[] = 'tp.producto_id IS NOT NULL AND (tp.stock_tt IS NULL OR tp.stock_tt <> FLOOR(COALESCE(ps.stock,0)))';
        }
        if (!empty($f['q'])) {
            $w[] = '(tp.titulo LIKE ? OR tp.sku LIKE ? OR tp.producto_tt LIKE ? OR pr.nombre LIKE ?)';
            $l = '%' . $f['q'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        return $this->todos("
            SELECT tp.*, pr.nombre AS producto, pr.codigo AS producto_codigo, pr.unidad_medida,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_tt_productos tp
              LEFT JOIN productos pr ON pr.id = tp.producto_id
              LEFT JOIN producto_sucursal ps ON ps.producto_id = tp.producto_id AND ps.sucursal_id = ?
             WHERE " . implode(' AND ', $w) . "
             ORDER BY (tp.producto_id IS NULL) DESC, tp.titulo, tp.variante
             LIMIT " . max(1, min(1000, (int)$tope)), $p);
    }

    public function resumen()
    {
        $f = $this->uno("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN producto_id IS NOT NULL THEN 1 ELSE 0 END) AS vinculadas,
                   SUM(CASE WHEN pendiente = 1 THEN 1 ELSE 0 END) AS pendientes,
                   SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS errores
              FROM lf_tt_productos WHERE estado_tt <> 'ausente'");
        return array_map('intval', $f ?: ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0]);
    }

    public function vincular($id, $productoId, $como = 'manual', $pendiente = false)
    {
        $this->db->prepare("UPDATE lf_tt_productos SET producto_id = ?, vinculo = ?, error = NULL, pendiente = ? WHERE id = ?")
                 ->execute([$productoId ?: null, $productoId ? $como : null, $pendiente ? 1 : 0, (int)$id]);
    }

    public function sinVincularConSku()
    {
        return $this->todos("SELECT id, sku FROM lf_tt_productos
                              WHERE producto_id IS NULL AND sku IS NOT NULL AND sku <> '' AND estado_tt <> 'ausente'");
    }

    public function marcarPendiente($productoId)
    {
        $this->db->prepare("UPDATE lf_tt_productos SET pendiente = 1 WHERE producto_id = ? AND estado_tt <> 'ausente'")
                 ->execute([(int)$productoId]);
    }

    public function marcarTodasPendientes()
    {
        $st = $this->db->prepare("UPDATE lf_tt_productos SET pendiente = 1
                                   WHERE producto_id IS NOT NULL AND estado_tt <> 'ausente'");
        $st->execute();
        return $st->rowCount();
    }

    public function pendientes($tope)
    {
        return $this->todos("SELECT * FROM lf_tt_productos WHERE pendiente = 1 AND producto_id IS NOT NULL
                              ORDER BY id LIMIT " . max(1, (int)$tope));
    }

    public function anotarEnviado($id, $cantidad)
    {
        $this->db->prepare("UPDATE lf_tt_productos SET pendiente = 0, stock_tt = ?, enviado_stock = ?,
                                   enviado_en = NOW(), error = NULL WHERE id = ?")
                 ->execute([(int)$cantidad, (int)$cantidad, (int)$id]);
    }

    public function anotarFallo($id, $error, $reintentar)
    {
        $this->db->prepare("UPDATE lf_tt_productos SET error = ?, pendiente = ? WHERE id = ?")
                 ->execute([mb_substr((string)$error, 0, 255), $reintentar ? 1 : 0, (int)$id]);
    }

    // ── Órdenes ─────────────────────────────────────────────────

    /** Toma la orden para trabajarla, DENTRO de una transacción (ver ShopifyRepo::tomarOrden). */
    public function tomarOrden($ordenId)
    {
        $this->db->prepare("INSERT IGNORE INTO lf_tt_ordenes (orden_id, estado, creado_en) VALUES (?, 'nueva', NOW())")
                 ->execute([(string)$ordenId]);
        return $this->uno("SELECT * FROM lf_tt_ordenes WHERE orden_id = ? FOR UPDATE", [(string)$ordenId]);
    }

    public function guardarOrden($ordenId, array $d)
    {
        $this->db->prepare("
            UPDATE lf_tt_ordenes SET estado = ?, estado_tt = ?, total = ?, fecha_tt = ?, aplicado = ?, nota = ?,
                   actualizado_en = NOW()
             WHERE orden_id = ?
        ")->execute([
            (string)$d['estado'], mb_substr((string)$d['estado_tt'], 0, 40), round((float)$d['total'], 2),
            $d['fecha_tt'] ?: null, $d['aplicado'] !== null ? json_encode($d['aplicado']) : null,
            mb_substr((string)$d['nota'], 0, 255) ?: null, (string)$ordenId,
        ]);
    }

    public function ordenes($tope = 100)
    {
        return $this->todos("SELECT * FROM lf_tt_ordenes ORDER BY COALESCE(fecha_tt, creado_en) DESC LIMIT "
                            . max(1, min(500, (int)$tope)));
    }
}
