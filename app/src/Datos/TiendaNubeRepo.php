<?php
namespace LibertyFin\Datos;

/**
 * Lo de Tienda Nube que vive en la base de la EMPRESA.
 *
 *   lf_tn_cuenta      un renglón (id = 1): la tienda conectada, su llave
 *                     (cifrada) y cómo se sincroniza.
 *   lf_tn_productos   cada VARIANTE de cada producto de la tienda, y con qué
 *                     producto de LibertyFin se cuenta. En Tienda Nube todo
 *                     producto tiene al menos una variante.
 *   lf_tn_ordenes     cada orden que ya movió existencias: lo que impide
 *                     descontar dos veces cuando el aviso llega repetido.
 *
 * Las crea la migración 31. Es el mismo diseño que MeliRepo.
 */
final class TiendaNubeRepo extends Repo
{
    const ESTADOS = ['visible' => 'Visible', 'oculto' => 'Oculto', 'ausente' => 'Borrado'];

    // ── La cuenta ───────────────────────────────────────────────

    public function cuenta()
    {
        return $this->uno("SELECT * FROM lf_tn_cuenta WHERE id = 1");
    }

    public function guardarConexion(array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_tn_cuenta
                (id, store_id, nombre, url, idioma, moneda, access_token, estado,
                 conectado_por, conectado_en, actualizado_en, ultimo_error)
            VALUES (1,?,?,?,?,?,?,'conectada',?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                store_id = VALUES(store_id), nombre = VALUES(nombre), url = VALUES(url),
                idioma = VALUES(idioma), moneda = VALUES(moneda), access_token = VALUES(access_token),
                estado = 'conectada', conectado_por = VALUES(conectado_por),
                conectado_en = NOW(), actualizado_en = NOW(), ultimo_error = NULL, ordenes_desde = NULL
        ")->execute([
            (string)$d['store_id'], mb_substr((string)($d['nombre'] ?? ''), 0, 120),
            mb_substr((string)($d['url'] ?? ''), 0, 255) ?: null,
            mb_substr((string)($d['idioma'] ?? 'es'), 0, 5), mb_substr((string)($d['moneda'] ?? ''), 0, 3) ?: null,
            (string)$d['access_token'], mb_substr((string)($d['conectado_por'] ?? ''), 0, 100),
        ]);
    }

    /** La llave ya no vale (desinstalaron la app o la cambiaron): hay que reconectar. */
    public function marcarVencida($motivo)
    {
        $this->db->prepare("
            UPDATE lf_tn_cuenta SET estado = 'vencida', access_token = NULL,
                   ultimo_error = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([mb_substr((string)$motivo, 0, 255)]);
    }

    public function desconectar()
    {
        $this->db->exec("UPDATE lf_tn_cuenta SET estado = 'desconectada', access_token = NULL,
                                actualizado_en = NOW() WHERE id = 1");
    }

    public function anotarError($motivo)
    {
        $this->db->prepare("UPDATE lf_tn_cuenta SET ultimo_error = ? WHERE id = 1")
                 ->execute([$motivo === '' || $motivo === null ? null : mb_substr((string)$motivo, 0, 255)]);
    }

    public function anotarAvisos($ok, $nota = null)
    {
        $this->db->prepare("UPDATE lf_tn_cuenta SET avisos_ok = ?, avisos_nota = ? WHERE id = 1")
                 ->execute([$ok ? 1 : 0, $nota === null || $nota === '' ? null : mb_substr((string)$nota, 0, 255)]);
    }

    public function anotarSincronizado($ordenesHasta = null)
    {
        if ($ordenesHasta !== null) {
            $this->db->prepare("UPDATE lf_tn_cuenta SET sincronizado_en = NOW(), ordenes_desde = ? WHERE id = 1")
                     ->execute([(string)$ordenesHasta]);
        } else {
            $this->db->exec("UPDATE lf_tn_cuenta SET sincronizado_en = NOW() WHERE id = 1");
        }
    }

    public function guardarAjustes(array $d)
    {
        $this->db->prepare("
            UPDATE lf_tn_cuenta SET sucursal_id = ?, reserva = ?, enviar_existencias = ?,
                   descontar_ventas = ?, actualizado_en = NOW()
             WHERE id = 1
        ")->execute([
            $d['sucursal_id'] ?: null, (float)$d['reserva'],
            !empty($d['enviar_existencias']) ? 1 : 0, !empty($d['descontar_ventas']) ? 1 : 0,
        ]);
    }

    // ── Productos (cada variante es un renglón) ─────────────────

    /** Guarda lo que dice Tienda Nube SIN tocar el vínculo: volver a importar no deshace lo emparejado a mano. */
    public function guardarVariante(array $p)
    {
        $this->db->prepare("
            INSERT INTO lf_tn_productos
                (producto_tn, variante_tn, titulo, variante, sku, estado_tn, permalink, imagen,
                 precio, stock_tn, actualizado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                titulo = VALUES(titulo), variante = VALUES(variante), sku = VALUES(sku),
                estado_tn = VALUES(estado_tn), permalink = VALUES(permalink), imagen = VALUES(imagen),
                precio = VALUES(precio), stock_tn = VALUES(stock_tn), actualizado_en = NOW()
        ")->execute([
            (string)$p['producto_tn'], (string)$p['variante_tn'],
            mb_substr((string)$p['titulo'], 0, 255), mb_substr((string)$p['variante'], 0, 160),
            mb_substr((string)$p['sku'], 0, 100) ?: null, mb_substr((string)$p['estado_tn'], 0, 20),
            mb_substr((string)$p['permalink'], 0, 255) ?: null, mb_substr((string)$p['imagen'], 0, 255) ?: null,
            round((float)$p['precio'], 2), $p['stock_tn'] === null ? null : (int)$p['stock_tn'],
        ]);
    }

    /** Lo que ya no vino al importar: se borró en Tienda Nube. */
    public function marcarAusentes(array $vistas)
    {
        if (!$vistas) return 0;
        $m = implode(',', array_fill(0, count($vistas), '?'));
        $st = $this->db->prepare("UPDATE lf_tn_productos SET estado_tn = 'ausente', pendiente = 0
                                   WHERE variante_tn NOT IN ($m) AND estado_tn <> 'ausente'");
        $st->execute(array_map('strval', array_values($vistas)));
        return $st->rowCount();
    }

    public function marcarProductoAusente($productoTn)
    {
        $this->db->prepare("UPDATE lf_tn_productos SET estado_tn = 'ausente', pendiente = 0 WHERE producto_tn = ?")
                 ->execute([(string)$productoTn]);
    }

    public function variante($id)
    {
        return $this->uno("SELECT * FROM lf_tn_productos WHERE id = ?", [(int)$id]);
    }

    public function porVariante($varianteTn)
    {
        return $this->uno("SELECT * FROM lf_tn_productos WHERE variante_tn = ?", [(string)$varianteTn]);
    }

    public function listar(array $f, $sucursalId, $tope = 300)
    {
        $w = ["tp.estado_tn <> 'ausente'"]; $p = [(int)$sucursalId];
        if (($f['ver'] ?? '') === 'sin') $w[] = 'tp.producto_id IS NULL';
        if (($f['ver'] ?? '') === 'con') $w[] = 'tp.producto_id IS NOT NULL';
        if (($f['ver'] ?? '') === 'error') $w[] = "tp.error IS NOT NULL AND tp.error <> ''";
        if (($f['ver'] ?? '') === 'difiere') {
            $w[] = 'tp.producto_id IS NOT NULL AND (tp.stock_tn IS NULL OR tp.stock_tn <> FLOOR(COALESCE(ps.stock,0)))';
        }
        if (!empty($f['q'])) {
            $w[] = '(tp.titulo LIKE ? OR tp.sku LIKE ? OR tp.producto_tn LIKE ? OR pr.nombre LIKE ?)';
            $l = '%' . $f['q'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        return $this->todos("
            SELECT tp.*, pr.nombre AS producto, pr.codigo AS producto_codigo, pr.unidad_medida,
                   COALESCE(ps.stock, 0) AS existencia
              FROM lf_tn_productos tp
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
              FROM lf_tn_productos WHERE estado_tn <> 'ausente'");
        return array_map('intval', $f ?: ['total' => 0, 'vinculadas' => 0, 'pendientes' => 0, 'errores' => 0]);
    }

    public function vincular($id, $productoId, $como = 'manual', $pendiente = false)
    {
        $this->db->prepare("UPDATE lf_tn_productos SET producto_id = ?, vinculo = ?, error = NULL, pendiente = ? WHERE id = ?")
                 ->execute([$productoId ?: null, $productoId ? $como : null, $pendiente ? 1 : 0, (int)$id]);
    }

    public function sinVincularConSku()
    {
        return $this->todos("SELECT id, sku FROM lf_tn_productos
                              WHERE producto_id IS NULL AND sku IS NOT NULL AND sku <> '' AND estado_tn <> 'ausente'");
    }

    public function marcarPendiente($productoId)
    {
        $this->db->prepare("UPDATE lf_tn_productos SET pendiente = 1 WHERE producto_id = ? AND estado_tn <> 'ausente'")
                 ->execute([(int)$productoId]);
    }

    public function marcarTodasPendientes()
    {
        $st = $this->db->prepare("UPDATE lf_tn_productos SET pendiente = 1
                                   WHERE producto_id IS NOT NULL AND estado_tn <> 'ausente'");
        $st->execute();
        return $st->rowCount();
    }

    public function pendientes($tope)
    {
        return $this->todos("SELECT * FROM lf_tn_productos WHERE pendiente = 1 AND producto_id IS NOT NULL
                              ORDER BY id LIMIT " . max(1, (int)$tope));
    }

    public function anotarEnviado($id, $cantidad)
    {
        $this->db->prepare("UPDATE lf_tn_productos SET pendiente = 0, stock_tn = ?, enviado_stock = ?,
                                   enviado_en = NOW(), error = NULL WHERE id = ?")
                 ->execute([(int)$cantidad, (int)$cantidad, (int)$id]);
    }

    public function anotarFallo($id, $error, $reintentar)
    {
        $this->db->prepare("UPDATE lf_tn_productos SET error = ?, pendiente = ? WHERE id = ?")
                 ->execute([mb_substr((string)$error, 0, 255), $reintentar ? 1 : 0, (int)$id]);
    }

    // ── Órdenes ─────────────────────────────────────────────────

    /** Toma la orden para trabajarla, DENTRO de una transacción (ver MeliRepo::tomarOrden). */
    public function tomarOrden($ordenId)
    {
        $this->db->prepare("INSERT IGNORE INTO lf_tn_ordenes (orden_id, estado, creado_en) VALUES (?, 'nueva', NOW())")
                 ->execute([(string)$ordenId]);
        return $this->uno("SELECT * FROM lf_tn_ordenes WHERE orden_id = ? FOR UPDATE", [(string)$ordenId]);
    }

    public function guardarOrden($ordenId, array $d)
    {
        $this->db->prepare("
            UPDATE lf_tn_ordenes SET numero = ?, estado = ?, estado_tn = ?, comprador = ?, total = ?,
                   fecha_tn = ?, aplicado = ?, nota = ?, actualizado_en = NOW()
             WHERE orden_id = ?
        ")->execute([
            mb_substr((string)$d['numero'], 0, 20) ?: null, (string)$d['estado'], mb_substr((string)$d['estado_tn'], 0, 40),
            mb_substr((string)$d['comprador'], 0, 120), round((float)$d['total'], 2),
            $d['fecha_tn'] ?: null, $d['aplicado'] !== null ? json_encode($d['aplicado']) : null,
            mb_substr((string)$d['nota'], 0, 255) ?: null, (string)$ordenId,
        ]);
    }

    public function ordenes($tope = 100)
    {
        return $this->todos("SELECT * FROM lf_tn_ordenes ORDER BY COALESCE(fecha_tn, creado_en) DESC LIMIT "
                            . max(1, min(500, (int)$tope)));
    }
}
