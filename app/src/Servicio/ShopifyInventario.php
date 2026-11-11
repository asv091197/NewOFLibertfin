<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ShopifyRepo;
use PDO;

/**
 * Shopify ⇄ Inventario de UNA empresa. Mismas reglas que TnInventario:
 *
 *  Shopify → LibertyFin   Cuando una orden queda PAGADA se descuenta de la
 *                         sucursal elegida. Si después se cancela, la
 *                         mercancía regresa.
 *
 *  LibertyFin → Shopify   Cualquier movimiento marca sus variantes como
 *                         pendientes y se manda la existencia nueva a LA
 *                         ubicación de Shopify elegida en Ajustes. Nace
 *                         APAGADO: encenderlo sobreescribe la existencia de
 *                         esa ubicación con la de LibertyFin.
 *
 * No crea ventas en LibertyFin (las cobra Shopify, no la caja) y no toca
 * órdenes anteriores a la conexión.
 */
final class ShopifyInventario
{
    const AL_VUELO = 8;

    private $db;
    private $repo;
    private $api;
    private $cuenta = null;

    /** @param Shopify|null $api */
    public function __construct(PDO $db, $api = null)
    {
        $this->db   = $db;
        $this->repo = new ShopifyRepo($db);
        $this->api  = $api ?: new Shopify();
    }

    public function repo() { return $this->repo; }
    public function api()  { return $this->api; }

    public function cuenta()
    {
        if ($this->cuenta === null) $this->cuenta = $this->repo->cuenta() ?: [];
        return $this->cuenta ?: null;
    }

    public function olvidarCuenta() { $this->cuenta = null; }

    public function conectada()
    {
        $c = $this->cuenta();
        return $c && $c['estado'] === 'conectada' && !empty($c['access_token']);
    }

    /** «mitienda.myshopify.com» */
    public function tiendaId()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['tienda'] : '';
    }

    public function ubicacion()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['location_id'] : '';
    }

    /** Módulo Shopify Y Inventario encendidos (ver MeliInventario::encendido). */
    public function encendido()
    {
        return self::ajuste($this->db, 'modulo.shopify') === 'si'
            && self::ajuste($this->db, 'inventario.activo') === 'si';
    }

    /** Leído directo de lf_ajustes: la tarea periódica recorre varias bases (ver MeliInventario::ajuste). */
    private static function ajuste(PDO $db, $clave)
    {
        try {
            $st = $db->prepare("SELECT valor FROM lf_ajustes WHERE clave = ?");
            $st->execute([(string)$clave]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\Throwable $e) { return null; }
    }

    private function inventario()
    {
        return new Inventario($this->db, [
            'activo'         => self::ajuste($this->db, 'inventario.activo') ?: 'no',
            'sin_existencia' => self::ajuste($this->db, 'inventario.sin_existencia') ?: 'avisar',
        ]);
    }

    public function sucursal()
    {
        $c = $this->cuenta();
        if ($c && (int)$c['sucursal_id'] > 0) return (int)$c['sucursal_id'];
        $id = $this->db->query("SELECT id FROM sucursales WHERE COALESCE(activo,1) = 1
                                 ORDER BY COALESCE(es_matriz,0) DESC, id LIMIT 1")->fetchColumn();
        return (int)$id;
    }

    /**
     * La llave de la tienda. La offline de siempre no caduca; si Shopify la
     * dio con fecha de caducidad, se renueva cinco minutos antes.
     * @return string la llave, o '' si no hay tienda conectada
     */
    public function token()
    {
        $c = $this->cuenta();
        if (!$c || $c['estado'] !== 'conectada') return '';
        $t = Shopify::descifrar($c['access_token']);
        if ($t === '' && !empty($c['access_token'])) {
            $this->repo->marcarVencida('No se pudo leer la llave guardada (¿cambió la llave de cifrado?). Vuelve a conectar.');
            $this->cuenta = null;
            return '';
        }
        if (!empty($c['expira_en']) && !empty($c['refresh_token']) && strtotime((string)$c['expira_en']) - 300 < time()) {
            $nueva = $this->api->renovar($c['tienda'], Shopify::descifrar($c['refresh_token']));
            if (!$nueva) {
                $this->repo->marcarVencida('No se pudo renovar la llave de Shopify: ' . $this->api->error());
                $this->cuenta = null;
                return '';
            }
            $this->repo->guardarLlave(Shopify::cifrar($nueva['access_token']),
                $nueva['refresh_token'] !== '' ? Shopify::cifrar($nueva['refresh_token']) : null, $nueva['expira_en']);
            $this->cuenta = null;
            return $nueva['access_token'];
        }
        return $t;
    }

    /** Si la última llamada fue un 401, la tienda quitó el permiso: se marca para reconectar. */
    private function revisarAutorizacion()
    {
        if (!$this->api->fueAutorizacion()) return false;
        $this->repo->marcarVencida($this->api->error());
        Auditoria::anota('shopify.vencida', 'Shopify', null, $this->api->error(), $this->db);
        $this->cuenta = null;
        return true;
    }

    // ════════════════════════════════════════════════════════
    //  PRODUCTOS
    // ════════════════════════════════════════════════════════

    /**
     * Trae todas las variantes de la tienda y empareja por SKU.
     * @return array ['productos' => n, 'renglones' => n, 'vinculadas' => n]
     */
    public function importar()
    {
        $token = $this->exigirToken();
        $lista = $this->api->variantes($token, $this->tiendaId(), $this->exigirUbicacion());
        if ($lista === null) {
            $this->revisarAutorizacion();
            throw new \RuntimeException($this->api->error() ?: 'No se pudieron leer los productos.');
        }
        $vistas = [];
        $productos = [];
        foreach ($lista as $v) {
            $r = self::renglonDe($v);
            if (!$r) continue;
            $this->repo->guardarVariante($r);
            $vistas[] = $r['variante_shop'];
            $productos[$r['producto_shop']] = true;
        }
        if ($vistas) $this->repo->marcarAusentes($vistas);
        $vinculadas = $this->autoVincular();
        $this->repo->anotarError(null);
        return ['productos' => count($productos), 'renglones' => count($vistas), 'vinculadas' => $vinculadas];
    }

    /**
     * Un renglón por variante (con su producto en ['product']).
     * stock_shop = null: no lleva control de existencias, o no está dada de
     * alta en la ubicación elegida.
     */
    public static function renglonDe(array $v)
    {
        if (empty($v['legacyResourceId']) || empty($v['product']['legacyResourceId'])) return null;
        $p = $v['product'];
        $estados = ['ACTIVE' => 'visible', 'DRAFT' => 'oculto', 'ARCHIVED' => 'archivado'];

        // «Default Title» es el nombre que Shopify le pone a la variante única.
        $nombre = [];
        foreach ((array)($v['selectedOptions'] ?? []) as $o) {
            $val = trim((string)($o['value'] ?? ''));
            if ($val !== '' && $val !== 'Default Title') $nombre[] = $val;
        }

        $item = (array)($v['inventoryItem'] ?? []);
        $stock = null;
        if (!empty($item['tracked']) && !empty($item['inventoryLevel'])) {
            foreach ((array)($item['inventoryLevel']['quantities'] ?? []) as $q) {
                if (($q['name'] ?? '') === 'available') $stock = (int)$q['quantity'];
            }
        }
        $precio  = (float)($v['price'] ?? 0);
        return [
            'producto_shop'   => (string)$p['legacyResourceId'],
            'variante_shop'   => (string)$v['legacyResourceId'],
            'inventario_shop' => (string)($item['legacyResourceId'] ?? ''),
            'titulo'          => (string)($p['title'] ?? ''),
            'variante'        => implode(' · ', $nombre),
            'sku'             => trim((string)($v['sku'] ?? '')),
            'estado_shop'     => $estados[(string)($p['status'] ?? '')] ?? 'oculto',
            'permalink'       => (string)($p['onlineStoreUrl'] ?? ''),
            'imagen'          => (string)($p['featuredMedia']['preview']['image']['url'] ?? ''),
            'precio'          => $precio,
            'stock_shop'      => $stock,
        ];
    }

    /** Empareja solo lo que no admite duda: SKU igual al SKU o código de barras de UN producto. */
    public function autoVincular()
    {
        $n = 0;
        $enviar = $this->enviaExistencias();
        foreach ($this->repo->sinVincularConSku() as $p) {
            $pid = $this->porSku($p['sku']);
            if ($pid) {
                $this->repo->vincular($p['id'], $pid, 'auto', $enviar);
                $n++;
            }
        }
        return $n;
    }

    public function vincular($varianteId, $productoId)
    {
        $p = $this->repo->variante($varianteId);
        if (!$p) throw new \InvalidArgumentException('Ese producto de la tienda no existe. Vuelve a traer los productos.');
        $productoId = (int)$productoId;
        if ($productoId) {
            $st = $this->db->prepare("SELECT lleva_inventario FROM productos WHERE id = ? AND activo = 1");
            $st->execute([$productoId]);
            $lleva = $st->fetchColumn();
            if ($lleva === false) throw new \InvalidArgumentException('Ese producto no existe o está desactivado.');
            if (!(int)$lleva) {
                throw new \InvalidArgumentException('Ese producto no lleva existencias. Márcalo en Productos con «Lleva existencias».');
            }
        }
        $this->repo->vincular($varianteId, $productoId, 'manual', $productoId && $this->enviaExistencias());
        return $p;
    }

    // ════════════════════════════════════════════════════════
    //  LIBERTYFIN → SHOPIFY
    // ════════════════════════════════════════════════════════

    public function enviaExistencias()
    {
        $c = $this->cuenta();
        return $c && (int)$c['enviar_existencias'] === 1;
    }

    /** Lo que hay en la sucursal elegida menos la reserva, en piezas enteras y nunca negativo. */
    public function cantidadAPublicar($productoId)
    {
        $st = $this->db->prepare("SELECT COALESCE(stock,0) FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $st->execute([(int)$productoId, $this->sucursal()]);
        $hay = (float)$st->fetchColumn();
        $c = $this->cuenta();
        return max(0, (int)floor($hay - (float)($c['reserva'] ?? 0) + 0.0005));
    }

    /** @return array ['enviadas' => n, 'fallas' => n, 'quedan' => bool] */
    public function empujarPendientes($tope = 50)
    {
        $r = ['enviadas' => 0, 'fallas' => 0, 'quedan' => false];
        if (!$this->conectada() || !$this->enviaExistencias() || !$this->encendido()) return $r;
        $token = $this->token();
        if ($token === '' || $this->ubicacion() === '') return $r;
        $tienda = $this->tiendaId();
        $ubicacion = $this->ubicacion();

        $filas = $this->repo->pendientes($tope + 1);
        $r['quedan'] = count($filas) > $tope;
        foreach (array_slice($filas, 0, $tope) as $f) {
            if ((string)$f['inventario_shop'] === '') {
                $this->repo->anotarFallo($f['id'], 'Sin «inventory item»: vuelve a traer los productos.', false);
                $r['fallas']++;
                continue;
            }
            $cant = $this->cantidadAPublicar($f['producto_id']);
            // stock_shop NULL = no lleva control allá o no está en la ubicación: se activa primero.
            if ($this->api->ponerExistencia($token, $tienda, $f['inventario_shop'], $ubicacion, $cant, $f['stock_shop'] === null)) {
                $this->repo->anotarEnviado($f['id'], $cant);
                $r['enviadas']++;
            } else {
                $this->repo->anotarFallo($f['id'], $this->api->error(), $this->api->http() !== 404);
                $r['fallas']++;
                if ($this->revisarAutorizacion()) break;
            }
        }
        return $r;
    }

    // ── El enganche con Inventario ──────────────────────────────

    private static $manda = [];
    private static $alCerrar = [];

    /**
     * Para que la llame Servicio\Inventario en CADA movimiento, dentro de la
     * transacción de quien mueve. Barato y nunca falla hacia afuera; no
     * llama a Shopify aquí: solo marca, y al terminar la petición se manda
     * (ver TnInventario::marcar).
     */
    public static function marcar(PDO $db, $productoId)
    {
        $llave = spl_object_hash($db);
        try {
            if (!isset(self::$manda[$llave])) {
                $f = $db->query("SELECT estado, enviar_existencias FROM lf_shop_cuenta WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                self::$manda[$llave] = $f && $f['estado'] === 'conectada' && (int)$f['enviar_existencias'] === 1
                                       && self::ajuste($db, 'modulo.shopify') === 'si'
                                       && Shopify::activa();
            }
            if (!self::$manda[$llave]) return;
            (new ShopifyRepo($db))->marcarPendiente($productoId);
        } catch (\Throwable $e) {
            self::$manda[$llave] = false;   // sin tablas: esta empresa no usa Shopify
            return;
        }

        if (isset(self::$alCerrar[$llave])) return;
        self::$alCerrar[$llave] = true;
        register_shutdown_function(function () use ($db) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try {
                if ($db->inTransaction()) return;
                (new self($db))->empujarPendientes(self::AL_VUELO);
            } catch (\Throwable $e) {
                error_log('[LibertyFin] Shopify, al cerrar: ' . $e->getMessage());
            }
        });
    }

    // ════════════════════════════════════════════════════════
    //  SHOPIFY → LIBERTYFIN
    // ════════════════════════════════════════════════════════

    public function procesarOrdenId($ordenId)
    {
        $token = $this->token();
        if ($token === '') return ['estado' => 'sin_cuenta'];
        $o = $this->api->orden($token, $this->tiendaId(), $ordenId);
        if (!is_array($o)) {
            $this->revisarAutorizacion();
            error_log('[LibertyFin] orden Shopify ' . $ordenId . ': ' . $this->api->error());
            return ['estado' => 'error', 'nota' => $this->api->error()];
        }
        return $this->procesarOrden($o);
    }

    /** Las órdenes que cambiaron desde la última vez: recupera los avisos que no llegaron. */
    public function revisarOrdenes()
    {
        $token = $this->exigirToken();
        $c = $this->cuenta();
        $desde = $c['ordenes_desde'] ?: $c['conectado_en'];
        $desde = date('Y-m-d H:i:s', max(strtotime((string)$desde) - 3600, strtotime('-30 days')));
        $inicio = date('Y-m-d H:i:s');

        $n = ['revisadas' => 0, 'aplicadas' => 0, 'devueltas' => 0];
        foreach ($this->api->ordenesDesde($token, $this->tiendaId(), $desde) as $o) {
            $r = $this->procesarOrden($o);
            $n['revisadas']++;
            if ($r['estado'] === 'aplicada' && !empty($r['nueva'])) $n['aplicadas']++;
            if ($r['estado'] === 'devuelta' && !empty($r['nueva'])) $n['devueltas']++;
        }
        if ($this->api->error() === '') $this->repo->anotarSincronizado($inicio);
        else $this->revisarAutorizacion();
        return $n;
    }

    /**
     * Pagada en Shopify. PARTIALLY_REFUNDED también: se devolvió PARTE del
     * dinero, pero la orden sigue y lo enviado ya salió.
     */
    public static function pagada(array $o)
    {
        return in_array($o['financiero'] ?? '', ['PAID', 'PARTIALLY_REFUNDED'], true) && !self::cancelada($o);
    }

    public static function cancelada(array $o)
    {
        return !empty($o['cancelada']) || in_array($o['financiero'] ?? '', ['REFUNDED', 'VOIDED'], true);
    }

    /**
     * Aplica UNA orden (ya en el formato plano de Shopify::orden). Idempotente:
     *   pagada     → se descuenta (una vez)
     *   cancelada  → si se había descontado, regresa (una vez)
     *   lo demás   → se anota y se espera
     */
    public function procesarOrden(array $o)
    {
        $ordenId = (string)($o['id'] ?? '');
        if ($ordenId === '') return ['estado' => 'error', 'nota' => 'orden sin id'];

        $c = $this->cuenta();
        $estadoShop = trim(($o['cancelada'] ? 'cancelada / ' : '') . strtolower((string)($o['financiero'] ?? '')), ' /');
        $fecha      = !empty($o['creada']) ? date('Y-m-d H:i:s', strtotime($o['creada'])) : null;
        $total      = (float)($o['total'] ?? 0);
        $numero     = (string)($o['numero'] ?? '');

        if (!$this->encendido()) return ['estado' => 'apagado', 'nueva' => false, 'nota' => 'Módulo apagado'];
        $inv = $this->inventario();
        $suc = $this->sucursal();

        $this->db->beginTransaction();
        try {
            $fila = $this->repo->tomarOrden($ordenId);
            $aplicado = $fila['aplicado'] ? (array)json_decode($fila['aplicado'], true) : null;
            $antes = (string)$fila['estado'];
            $res = ['estado' => $antes, 'nueva' => false, 'nota' => (string)$fila['nota']];

            $guardar = function ($estado, $nota) use ($ordenId, $numero, $estadoShop, $total, $fecha, &$aplicado) {
                $this->repo->guardarOrden($ordenId, [
                    'numero' => $numero, 'estado' => $estado, 'estado_shop' => $estadoShop, 'comprador' => '',
                    'total' => $total, 'fecha_shop' => $fecha, 'aplicado' => $aplicado, 'nota' => $nota,
                ]);
            };

            if ($antes === 'nueva' && $fecha && $c && strtotime($fecha) < strtotime((string)$c['conectado_en'])) {
                $guardar('anterior', 'De antes de conectar la tienda: no se movió nada.');
                $res = ['estado' => 'anterior', 'nueva' => true, 'nota' => ''];
            } elseif (self::pagada($o) && $aplicado === null && in_array($antes, ['nueva', 'esperando'], true)) {
                list($aplicado, $nota) = $this->descontar($o, $inv, $suc, $numero ?: $ordenId, $c);
                $estado = $aplicado ? 'aplicada' : 'sin_vincular';
                $guardar($estado, $nota);
                $res = ['estado' => $estado, 'nueva' => true, 'nota' => $nota];
            } elseif (self::cancelada($o) && $aplicado && $antes === 'aplicada') {
                foreach ($aplicado as $l) {
                    self::mover($inv, $l['producto_id'], $l['sucursal_id'], (float)$l['cantidad'],
                        'Orden Shopify #' . ($numero ?: $ordenId) . ' cancelada');
                }
                $guardar('devuelta', 'Cancelada en Shopify: la mercancía regresó.');
                $res = ['estado' => 'devuelta', 'nueva' => true, 'nota' => ''];
            } elseif ($antes === 'nueva' || $antes === 'esperando') {
                $estado = self::cancelada($o) ? 'cancelada' : 'esperando';
                $guardar($estado, $estado === 'cancelada' ? 'Se canceló antes de pagarse.' : 'Esperando a que se pague.');
                $res = ['estado' => $estado, 'nueva' => $antes === 'nueva', 'nota' => ''];
            } else {
                $guardar($antes, (string)$fila['nota']);
            }
            $this->db->commit();
            return $res;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function descontar(array $o, Inventario $inv, $suc, $numero, $c)
    {
        if ($c && (int)$c['descontar_ventas'] !== 1) {
            return [null, 'Descontar ventas está apagado en Ajustes de Shopify.'];
        }
        if (!$inv->activo()) return [null, 'El inventario está apagado (Ajustes → Inventario).'];

        $aplicado = []; $faltan = [];
        $nota = 'Orden Shopify #' . $numero;
        foreach ((array)($o['lineas'] ?? []) as $l) {
            $var  = (string)$l['variante'];
            $cant = (float)$l['cantidad'];
            if ($cant <= 0) continue;

            $fila = $var !== '' ? $this->repo->porVariante($var) : null;
            $pid  = $fila ? (int)$fila['producto_id'] : 0;
            if (!$pid) $pid = $this->porSku((string)$l['sku']);
            if (!$pid) { $faltan[] = $l['nombre'] ?: $var; continue; }

            if (!self::mover($inv, $pid, $suc, -$cant, $nota)) { $faltan[] = ($l['nombre'] ?: $var) . ' (no lleva existencias)'; continue; }
            $aplicado[] = ['producto_id' => $pid, 'sucursal_id' => $suc, 'cantidad' => $cant];
        }
        $txt = $faltan ? 'Sin vincular: ' . implode(', ', array_slice($faltan, 0, 3)) . (count($faltan) > 3 ? '…' : '') : '';
        return [$aplicado ?: null, $txt];
    }

    /** Mueve existencias por una orden de Shopify (ver TnInventario::mover). */
    private static function mover(Inventario $inv, $pid, $suc, $cant, $nota)
    {
        if (method_exists($inv, 'moverPorShopify')) {
            $inv->moverPorShopify($pid, $suc, $cant, $nota);
            return true;
        }
        if (method_exists($inv, 'moverPorMercadoLibre')) {
            $inv->moverPorMercadoLibre($pid, $suc, $cant, $nota);
            return true;
        }
        try {
            $inv->registrar($cant < 0 ? 'salida' : 'entrada', $pid, $suc, abs($cant), ['nota' => $nota]);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    private function porSku($sku)
    {
        $sku = trim((string)$sku);
        if ($sku === '') return 0;
        $st = $this->db->prepare("SELECT id FROM productos WHERE (codigo = ? OR codigo_barras = ?)
                                   AND activo = 1 AND lleva_inventario = 1 LIMIT 2");
        $st->execute([$sku, $sku]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    /** Aviso «cambió este producto»: se refresca lo que dice Shopify. */
    public function refrescarProducto($productoShop)
    {
        $token = $this->token();
        if ($token === '' || $this->ubicacion() === '') return false;
        $vs = $this->api->productoVariantes($token, $this->tiendaId(), $productoShop, $this->ubicacion());
        if (!is_array($vs)) {
            if ($this->api->http() === 404) { $this->repo->marcarProductoAusente($productoShop); return true; }
            $this->revisarAutorizacion();
            return false;
        }
        $vistas = [];
        foreach ($vs as $v) {
            $r = self::renglonDe($v);
            if (!$r) continue;
            $this->repo->guardarVariante($r);
            $vistas[] = $r['variante_shop'];
        }
        $this->repo->marcarVariantesAusentes($productoShop, $vistas);
        return true;
    }

    /** Órdenes primero (bajan existencias), luego mandar lo pendiente. */
    public function sincronizar($tope = 200)
    {
        $o = $this->revisarOrdenes();
        $e = $this->empujarPendientes($tope);
        return $o + $e;
    }

    private function exigirToken()
    {
        $t = $this->token();
        if ($t === '') {
            $c = $this->cuenta() ?: $this->repo->cuenta();
            throw new \InvalidArgumentException(($c && $c['estado'] === 'vencida')
                ? 'Shopify ya no acepta la llave de la tienda (se desinstaló la aplicación). Vuelve a conectar.'
                : 'No hay una tienda de Shopify conectada' . ($c && $c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '.'));
        }
        return $t;
    }

    private function exigirUbicacion()
    {
        $u = $this->ubicacion();
        if ($u === '') throw new \InvalidArgumentException('Elige en Ajustes la ubicación de Shopify que surte tu tienda.');
        return $u;
    }
}
