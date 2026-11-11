<?php
namespace LibertyFin\Datos;

/**
 * Lo de Shopify que vive en la base de la EMPRESA.
 *
 *   lf_shop_cuenta      un renglón (id = 1): la tienda conectada, su llave
 *                       (cifrada), la ubicación de Shopify que corresponde a
 *                       la sucursal, y cómo se sincroniza.
 *   lf_shop_productos   cada VARIANTE de cada producto de la tienda, con su
 *                       «inventory item» (donde vive la existencia) y con
 *                       qué producto de LibertyFin se cuenta.
 *   lf_shop_ordenes     cada orden que ya movió existencias: lo que impide
 *                       descontar dos veces cuando el aviso llega repetido.
 *
 * Las crea la migración 32. Es el mismo diseño que TiendaNubeRepo.
 */
final class ShopifyRepo extends Repo
{
    const ESTADOS = ['visible' => 'Activo', 'oculto' => 'Borrador', 'archivado' => 'Archivado', 'ausente' => 'Borrado'];

    // ── La cuenta ───────────────────────────────────────────────

    public function cuenta()
    {
        return $this->uno("SELECT * FROM lf_shop_cuenta WHERE id = 1");
    }

    /** Reconectar la MISMA tienda conserva la ubicación elegida en Ajustes. */
    public function guardarConexion(array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_shop_cuenta
                (id, tienda, nombre, url, moneda, access_token, refresh_token, expira_en, estado,
                 location_id, location_nombre, conectado_por, conectado_en, actualizado_en, ultimo_error)
            VALUES (1,?,?,?,?,?,?,?,'conectada',?,?,?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                tienda = VALUES(tienda), nombre = VALUES(nombre), url = VALUES(url), moneda = VALUES(moneda),
                access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expira_en = VALUES(expira_en),
                estado = 'conectada',
                location_id = IF(lf_shop_cuenta.tienda = VALUES(tienda) AND lf_shop_cuenta.location_id IS NOT NULL,
                                 lf_shop_cuenta.location_id, VALUES(location_id)),
                location_nombre = IF(lf_shop_cuenta.tienda = VALUES(tienda) AND lf_shop_cuenta.location_id IS NOT NULL,
                                 lf_shop_cuenta.location_nombre, VALUES(location_nombre)),
                conectado_por = VALUES(conectado_por),
                conectado_en = NOW(), actualizado_en = NOW(), ultimo_error = NULL, ordenes_desde = NULL
        ")->execute([
            (string)$d['tienda'], mb_substr((string)($d['nombre'] ?? ''), 0, 120),
            mb_substr((string)($d['url'] ?? ''), 0, 255) ?: null, mb_substr((string)($d['moneda'] ?? ''), 0, 3) ?: null,
            (string)$d['access_token'], ($d['refresh_token'] ?? '') !== '' ? (string)$d['refresh_token'] : null,
            $d['expira_en'] ?? null,
            ($d['location_id'] ?? '') !== '' ? (string)$d['location_id'] : null,
            mb_substr((string)($d['location_nombre'] ?? ''), 0, 120) ?: null,
            mb_substr((string)($d['conectado_por'] ?? ''), 0, 100),
        ]);
    }

    /** La llave renovada (solo para llaves que caducan). */
    public function guardarLlave($accessToken, $refreshToken, $expiraEn)
    {
        $this->db->prepare("UPDATE lf_shop_cuenta SET access_token = ?, refresh_token = COALESCE(?, refresh_token),
                                   expira_en = ?, actualizado_en = NOW() WHERE id = 1")
                 ->execute([(string)$accessToken, $refreshToken !== '' && $refreshToken !== null ? (string)$refreshToken : null, $expiraEn]);
    }

    /** La llave ya no vale (desinstalaron la app o la cambiaron): hay que reconectar. */
    public function marcarVencida($motivo)
    {
        $this->db->prepare("
            UPDATE lf_shop_cuenta SET estado = 'vencida', access_token = NULL, refresh_token = NULL,
                   ultimo_error = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([mb_substr((string)$motivo, 0, 255)]);
    }

    public function desconectar()
    {
        $this->db->exec("UPDATE lf_shop_cuenta SET estado = 'desconectada', access_token = NULL, refresh_token = NULL,
                                actualizado_en = NOW() WHERE id = 1");
    }

    public function anotarError($motivo)
    {
        $this->db->prepare("UPDATE lf_shop_cuenta SET ultimo_error = ? WHERE id = 1")
                 ->execute([$motivo === '' || $motivo === null ? null : mb_substr((string)$motivo, 0, 255)]);
    }

    public function anotarAvisos($ok, $nota = null)
    {
        $this->db->prepare("UPDATE lf_shop_cuenta SET avisos_ok = ?, avisos_nota = ? WHERE id = 1")
                 ->execute([$ok ? 1 : 0, $nota === null || $nota === '' ? null : mb_substr((string)$nota, 0, 255)]);
    }

    public function anotarSincronizado($ordenesHasta = null)
    {
        if ($ordenesHasta !== null) {
            $this->db->prepare("UPDATE lf_shop_cuenta SET sincronizado_en = NOW(), ordenes_desde = ? WHERE id = 1")
                     ->execute([(string)$ordenesHasta]);
        } else {
            $this->db->exec("UPDATE lf_shop_cuenta SET sincronizado_en = NOW() WHERE id = 1");
        }
    }

    public function guardarAjustes(array $d)
    {
        $this->db->prepare("
            UPDATE lf_shop_cuenta SET sucursal_id = ?, location_id = ?, location_nombre = ?, reserva = ?,
                   enviar_existencias = ?, descontar_ventas = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([
            $d['sucursal_id'] ?: null,
            ($d['location_id'] ?? '') !== '' ? (string)$d['location_id'] : null,
            mb_substr((string)($d['location_nombre'] ?? ''), 0, 120) ?: null,
            (float)$d['reserva'],
            !empty($d['enviar_existencias']) ? 1 : 0, !empty($d['descontar_ventas']) ? 1 : 0,
        ]);
    }

    // ── Productos (cada variante es un renglón) ─────────────────

    /** Guarda lo que dice Shopify SIN tocar el vínculo: volver a importar no deshace lo emparejado a mano. */
    public function guardarVariante(array $p)
    {
        $this->db->prepare("
            INSERT INTO lf_shop_productos
                (producto_shop, variante_shop, inventario_shop, titulo, variante, sku, estado_shop, permalink, imagen,
                 precio, stock_shop, actualizado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                producto_shop = VALUES(producto_shop), inventario_shop = VALUES(inventario_shop),
                titulo = VALUES(titulo), variante = VALUES(variante), sku = VALUES(sku),
                estado_shop = VALUES(estado_shop), permalink = VALUES(permalink), imagen = VALUES(imagen),
                precio = VALUES(precio), stock_shop = VALUES(stock_shop), actualizado_en = NOW()
        ")->execute([
            (string)$p['producto_shop'], (string)$p['variante_shop'], (string)$p['inventario_shop'],
            mb_substr((string)$p['titulo'], 0, 255), mb_substr((string)$p['variante'], 0, 160),
            mb_substr((string)$p['sku'], 0, 100) ?: null, mb_substr((string)$p['estado_shop'], 0, 20),
            mb_substr((string)$p['permalink'], 0, 255) ?: null, mb_substr((string)$p['imagen'], 0, 255) ?: null,
            round((float)$p['precio'], 2), $p['stock_shop'] === null ? null : (int)$p['stock_shop'],
        ]);
    }

    /** Lo que ya no vino al importar: se borró en Shopify. */
    public function marcarAusentes(array $vistas)
    {
        if (!$vistas) return 0;
        $m = implode(',', array_fill(0, count($vistas), '?'));
        $st = $this->db->prepare("UPDATE lf_shop_productos SET estado_shop = 'ausente', pendiente = 0
                                   WHERE variante_shop NOT IN ($m) AND estado_shop <> 'ausente'");
        $st->execute(array_map('strval', array_values($vistas)));
        return $st->rowCount();
    }

    public function marcarProductoAusente($productoShop)
    {
        $this->db->prepare("UPDATE lf_shop_productos SET estado_shop = 'ausente', pendiente = 0 WHERE producto_shop = ?")
                 ->execute([(string)$productoShop]);
    }

    /** Las variantes de un producto que ya no vinieron en su aviso (se borró una variante). */
    public function marcarVariantesAusentes($productoShop, array $vistas)
    {
        $p = [(string)$productoShop];
        $extra = '';
        if ($vistas) {
            $extra = ' AND variante_shop NOT IN (' . implode(',', array_fill(0, count($vistas), '?')) . ')';
            $p = array_merge($p, array_map('strval', array_values($vistas)));
        }
        $this->db->prepare("UPDATE lf_shop_productos SET estado_shop = 'ausente', pendiente = 0
                             WHERE producto_shop = ? AND estado_shop <> 'ausente'" . $extra)->execute($p);
    }

    public function variante($id)
    {
        return $this->uno("SELECT * FROM lf_shop_productos WHERE id = ?", [(int)$id]);
    }

    public function porVariante($varianteShop)
    {
        return $this->uno("SELECT * FROM lf_shop_productos WHERE variante_shop = ?", [(string)$varianteShop]);
    }

    public function listar(array $f, $sucursalId, $tope = 300)
    {
        $w = ["sp.estado_shop <> 'ausente'"]; $p = [(int)$sucursalId];
        if (($f['ver'] ?? '') === 'sin') $w[] = 'sp.producto_id IS NULL';
        if (($f['ver'] ?? '') === 'con') $w[] = 'sp.producto_id IS NOT NULL';
        if (($f['ver'] ?? '') === 'error') $w[] = "sp.error IS NOT NULL AND sp.error <> ''";
        if (($f['ver'] ?? '') === 'difiere') {
            $w[] = 'sp.producto_id IS NOT NULL AND (sp.stock_shop IS NULL OR sp.stock_shop <> FLOOR(COALESCE(ps.stock,0)))';
        }
        if (!empty($f['q'])) {
            $w[] = '(sp.titulo LIKE ? OR sp.sku LIKE ? OR sp.producto_shop LIKE ? OR pr.nombre LIKE ?)';
            $l = '%' . $f['q'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        return $this->todos("
            SELECT sp.*, pr.nombre AS producto, pr.codigo AS producto_codigo, pr.unidad_medida,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_shop_productos sp
              LEFT JOIN productos pr ON pr.id = sp.producto_id
              LEFT JOIN producto_sucursal ps ON ps.producto_id = sp.producto_id AND ps.sucursal_id = ?
             WHERE " . implode(' AND ', $w) . "
             ORDER BY (sp.producto_id IS NULL) DESC, sp.titulo, sp.variante
             LIMIT " . max(1, min(1000, (int)$tope)), $p);
    }

    public function resumen()
    {
        $f = $this->uno("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN producto_id IS NOT NULL THEN 1 ELSE 0 END) AS vinculadas,
                   SUM(CASE WHEN pendiente = 1 THEN 1 ELSE 0 END) AS pendientes,
                   SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS errores
              FROM lf_shop_productos WHERE estado_shop <> 'ausente'");
        return array_map('intval', $f ?: ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0]);
    }

    public function vincular($id, $productoId, $como = 'manual', $pendiente = false)
    {
        $this->db->prepare("UPDATE lf_shop_productos SET producto_id = ?, vinculo = ?, error = NULL, pendiente = ? WHERE id = ?")
                 ->execute([$productoId ?: null, $productoId ? $como : null, $pendiente ? 1 : 0, (int)$id]);
    }

    public function sinVincularConSku()
    {
        return $this->todos("SELECT id, sku FROM lf_shop_productos
                              WHERE producto_id IS NULL AND sku IS NOT NULL AND sku <> '' AND estado_shop <> 'ausente'");
    }

    public function marcarPendiente($productoId)
    {
        $this->db->prepare("UPDATE lf_shop_productos SET pendiente = 1 WHERE producto_id = ? AND estado_shop <> 'ausente'")
                 ->execute([(int)$productoId]);
    }

    public function marcarTodasPendientes()
    {
        $st = $this->db->prepare("UPDATE lf_shop_productos SET pendiente = 1
                                   WHERE producto_id IS NOT NULL AND estado_shop <> 'ausente'");
        $st->execute();
        return $st->rowCount();
    }

    public function pendientes($tope)
    {
        return $this->todos("SELECT * FROM lf_shop_productos WHERE pendiente = 1 AND producto_id IS NOT NULL
                              ORDER BY id LIMIT " . max(1, (int)$tope));
    }

    public function anotarEnviado($id, $cantidad)
    {
        $this->db->prepare("UPDATE lf_shop_productos SET pendiente = 0, stock_shop = ?, enviado_stock = ?,
                                   enviado_en = NOW(), error = NULL WHERE id = ?")
                 ->execute([(int)$cantidad, (int)$cantidad, (int)$id]);
    }

    public function anotarFallo($id, $error, $reintentar)
    {
        $this->db->prepare("UPDATE lf_shop_productos SET error = ?, pendiente = ? WHERE id = ?")
                 ->execute([mb_substr((string)$error, 0, 255), $reintentar ? 1 : 0, (int)$id]);
    }

    // ── Órdenes ─────────────────────────────────────────────────

    /** Toma la orden para trabajarla, DENTRO de una transacción (ver MeliRepo::tomarOrden). */
    public function tomarOrden($ordenId)
    {
        $this->db->prepare("INSERT IGNORE INTO lf_shop_ordenes (orden_id, estado, creado_en) VALUES (?, 'nueva', NOW())")
                 ->execute([(string)$ordenId]);
        return $this->uno("SELECT * FROM lf_shop_ordenes WHERE orden_id = ? FOR UPDATE", [(string)$ordenId]);
    }

    public function guardarOrden($ordenId, array $d)
    {
        $this->db->prepare("
            UPDATE lf_shop_ordenes SET numero = ?, estado = ?, estado_shop = ?, comprador = ?, total = ?,
                   fecha_shop = ?, aplicado = ?, nota = ?, actualizado_en = NOW()
             WHERE orden_id = ?
        ")->execute([
            mb_substr((string)$d['numero'], 0, 20) ?: null, (string)$d['estado'], mb_substr((string)$d['estado_shop'], 0, 40),
            mb_substr((string)$d['comprador'], 0, 120), round((float)$d['total'], 2),
            $d['fecha_shop'] ?: null, $d['aplicado'] !== null ? json_encode($d['aplicado']) : null,
            mb_substr((string)$d['nota'], 0, 255) ?: null, (string)$ordenId,
        ]);
    }

    public function ordenes($tope = 100)
    {
        return $this->todos("SELECT * FROM lf_shop_ordenes ORDER BY COALESCE(fecha_shop, creado_en) DESC LIMIT "
                            . max(1, min(500, (int)$tope)));
    }
}
