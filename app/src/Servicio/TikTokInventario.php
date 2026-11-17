<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\TikTokRepo;
use PDO;

/**
 * TikTok Shop ⇄ Inventario de UNA empresa. Mismas reglas que ShopifyInventario:
 *
 *  TikTok → LibertyFin   Cuando una orden pasa a «por enviar»
 *                        (AWAITING_SHIPMENT: pagada y fuera de la ventana de
 *                        arrepentimiento) se descuenta de la sucursal elegida.
 *                        Si después se cancela, la mercancía regresa.
 *
 *  LibertyFin → TikTok   Cualquier movimiento marca sus SKU como pendientes y
 *                        se manda la existencia nueva al ALMACÉN de TikTok
 *                        elegido en Ajustes. Nace APAGADO: encenderlo
 *                        sobreescribe la existencia de ese almacén.
 *
 * No crea ventas en LibertyFin (las cobra TikTok, no la caja), no toca
 * órdenes anteriores a la conexión y no publica productos: se dan de alta en
 * TikTok (que los revisa) y aquí solo se vinculan.
 */
final class TikTokInventario
{
    const AL_VUELO = 8;

    /** Se renueva la llave cuando le queda menos que esto (TikTok la da por unos días). */
    const RENOVAR_ANTES = 86400;

    private $db;
    private $repo;
    private $api;
    private $cuenta = null;

    /** @param TikTok|null $api si no se da, se arma con las credenciales de la empresa */
    public function __construct(PDO $db, $api = null)
    {
        $this->db   = $db;
        $this->repo = new TikTokRepo($db);
        $this->api  = $api;
    }

    public function repo() { return $this->repo; }

    public function api()
    {
        if ($this->api === null) $this->api = new TikTok(TikTok::credenciales($this->cuentaSegura()));
        return $this->api;
    }

    public function cuenta()
    {
        if ($this->cuenta === null) $this->cuenta = $this->repo->cuenta() ?: [];
        return $this->cuenta ?: null;
    }

    /** cuenta() que no truena si la tabla aún no existe (para armar la API). */
    private function cuentaSegura()
    {
        try { return $this->cuenta(); } catch (\Throwable $e) { return null; }
    }

    public function olvidarCuenta() { $this->cuenta = null; }

    public function conectada()
    {
        $c = $this->cuenta();
        return $c && $c['estado'] === 'conectada' && !empty($c['access_token']);
    }

    /** ¿Usa una app «Seller in-house» propia (y no la global)? */
    public function credencialesPropias()
    {
        return TikTok::credenciales($this->cuentaSegura())['propias'];
    }

    public function shopId()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['shop_id'] : '';
    }

    public function cipher()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['shop_cipher'] : '';
    }

    public function almacen()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['almacen_id'] : '';
    }

    /** Módulo TikTok Shop Y Inventario encendidos (ver ShopifyInventario::encendido). */
    public function encendido()
    {
        return self::ajuste($this->db, 'modulo.tiktok') === 'si'
            && self::ajuste($this->db, 'inventario.activo') === 'si';
    }

    /** Leído directo de lf_ajustes: la tarea periódica recorre varias bases. */
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
     * La llave de la tienda. TikTok la da por unos días: cuando le queda
     * menos de RENOVAR_ANTES se renueva con el refresh_token. Si la
     * renovación falla porque TikTok ya no la acepta, hay que reconectar.
     * @return string la llave, o '' si no hay tienda conectada
     */
    public function token()
    {
        $c = $this->cuenta();
        if (!$c || $c['estado'] !== 'conectada') return '';
        $t = TikTok::descifrar($c['access_token']);
        if ($t === '' && !empty($c['access_token'])) {
            $this->repo->marcarVencida('No se pudo leer la llave guardada (¿cambió la llave de cifrado?). Vuelve a conectar.');
            $this->cuenta = null;
            return '';
        }
        if (!empty($c['expira_en']) && strtotime((string)$c['expira_en']) - self::RENOVAR_ANTES < time()) {
            $nueva = !empty($c['refresh_token']) ? $this->api()->renovar(TikTok::descifrar($c['refresh_token'])) : null;
            if (!$nueva) {
                $vencida = strtotime((string)$c['expira_en']) <= time();
                // Si todavía no caduca y fue un tropiezo de red, se sigue con la que hay.
                if (!$vencida && !$this->api()->fueAutorizacion()) return $t;
                $this->repo->marcarVencida('No se pudo renovar la llave de TikTok Shop: '
                    . ($this->api()->error() ?: 'no hay con qué renovarla') . '. Vuelve a conectar.');
                Auditoria::anota('tiktok.vencida', 'TikTok Shop', null, $this->api()->error(), $this->db);
                $this->cuenta = null;
                return '';
            }
            $this->repo->guardarLlave(TikTok::cifrar($nueva['access_token']),
                $nueva['refresh_token'] !== '' ? TikTok::cifrar($nueva['refresh_token']) : null,
                $nueva['expira_en'], $nueva['refresh_expira_en']);
            $this->cuenta = null;
            return $nueva['access_token'];
        }
        return $t;
    }

    /** Si la última llamada dijo «la llave no vale», se marca para reconectar. */
    private function revisarAutorizacion()
    {
        if (!$this->api()->fueAutorizacion()) return false;
        $this->repo->marcarVencida($this->api()->error());
        Auditoria::anota('tiktok.vencida', 'TikTok Shop', null, $this->api()->error(), $this->db);
        $this->cuenta = null;
        return true;
    }

    // ════════════════════════════════════════════════════════
    //  PRODUCTOS
    // ════════════════════════════════════════════════════════

    /**
     * Trae todos los productos de la tienda y empareja por SKU.
     * @return array ['productos' => n, 'renglones' => n, 'vinculadas' => n]
     */
    public function importar()
    {
        $token = $this->exigirToken();
        $lista = $this->api()->productos($token, $this->cipher());
        if ($lista === null) {
            $this->revisarAutorizacion();
            throw new \RuntimeException($this->api()->error() ?: 'No se pudieron leer los productos.');
        }
        $almacen = $this->almacen();
        $vistos = [];
        $productos = [];
        foreach ($lista as $p) {
            foreach (self::renglonesDe($p, $almacen) as $r) {
                $this->repo->guardarSku($r);
                $vistos[] = $r['sku_tt'];
                $productos[$r['producto_tt']] = true;
            }
        }
        if ($vistos) $this->repo->marcarAusentes($vistos);
        $vinculadas = $this->autoVincular();
        $this->repo->anotarError(null);
        return ['productos' => count($productos), 'renglones' => count($vistos), 'vinculadas' => $vinculadas];
    }

    /**
     * Un renglón por SKU del producto.
     * stock_tt = null: el SKU no tiene existencia dada de alta en ese almacén
     * (o no se ha elegido almacén).
     */
    public static function renglonesDe(array $p, $almacen)
    {
        if (empty($p['id'])) return [];
        $estado = TikTokRepo::DE_TIKTOK[strtoupper((string)($p['status'] ?? ''))] ?? 'oculto';
        $imagen = (string)($p['main_images'][0]['urls'][0] ?? ($p['main_images'][0]['thumb_urls'][0] ?? ''));
        $r = [];
        foreach ((array)($p['skus'] ?? []) as $s) {
            if (empty($s['id'])) continue;
            $nombre = [];
            foreach ((array)($s['sales_attributes'] ?? []) as $a) {
                $v = trim((string)($a['value_name'] ?? ''));
                if ($v !== '') $nombre[] = $v;
            }
            $stock = null;
            foreach ((array)($s['inventory'] ?? []) as $i) {
                if ((string)$almacen !== '' && (string)($i['warehouse_id'] ?? '') === (string)$almacen) {
                    $stock = (int)($i['quantity'] ?? 0);
                }
            }
            $precio = $s['price']['sale_price'] ?? ($s['price']['tax_exclusive_price'] ?? ($s['price']['original_price'] ?? 0));
            $r[] = [
                'producto_tt' => (string)$p['id'],
                'sku_tt'      => (string)$s['id'],
                'titulo'      => (string)($p['title'] ?? ''),
                'variante'    => implode(' · ', $nombre),
                'sku'         => trim((string)($s['seller_sku'] ?? '')),
                'estado_tt'   => $estado,
                'imagen'      => $imagen,
                'precio'      => (float)$precio,
                'stock_tt'    => $stock,
            ];
        }
        return $r;
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

    public function vincular($skuId, $productoId)
    {
        $p = $this->repo->sku($skuId);
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
        $this->repo->vincular($skuId, $productoId, 'manual', $productoId && $this->enviaExistencias());
        return $p;
    }

    // ════════════════════════════════════════════════════════
    //  LIBERTYFIN → TIKTOK
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
        if ($token === '' || $this->almacen() === '') return $r;
        $cipher  = $this->cipher();
        $almacen = $this->almacen();

        $filas = $this->repo->pendientes($tope + 1);
        $r['quedan'] = count($filas) > $tope;
        foreach (array_slice($filas, 0, $tope) as $f) {
            $cant = $this->cantidadAPublicar($f['producto_id']);
            if ($this->api()->ponerExistencia($token, $cipher, $f['producto_tt'], $f['sku_tt'], $almacen, $cant)) {
                $this->repo->anotarEnviado($f['id'], $cant);
                $r['enviadas']++;
            } else {
                $this->repo->anotarFallo($f['id'], $this->api()->error(), $this->api()->http() !== 404);
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
     * La llama Servicio\Inventario en CADA movimiento, dentro de la
     * transacción de quien mueve. Barato y nunca falla hacia afuera; no
     * llama a TikTok aquí: solo marca, y al terminar la petición se manda.
     */
    public static function marcar(PDO $db, $productoId)
    {
        $llave = spl_object_hash($db);
        try {
            if (!isset(self::$manda[$llave])) {
                $f = $db->query("SELECT estado, enviar_existencias FROM lf_tt_cuenta WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                self::$manda[$llave] = $f && $f['estado'] === 'conectada' && (int)$f['enviar_existencias'] === 1
                                       && self::ajuste($db, 'modulo.tiktok') === 'si'
                                       && TikTok::activa();
            }
            if (!self::$manda[$llave]) return;
            (new TikTokRepo($db))->marcarPendiente($productoId);
        } catch (\Throwable $e) {
            self::$manda[$llave] = false;   // sin tablas: esta empresa no usa TikTok Shop
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
                error_log('[LibertyFin] TikTok Shop, al cerrar: ' . $e->getMessage());
            }
        });
    }

    // ════════════════════════════════════════════════════════
    //  TIKTOK → LIBERTYFIN
    // ════════════════════════════════════════════════════════

    public function procesarOrdenId($ordenId)
    {
        $token = $this->token();
        if ($token === '') return ['estado' => 'sin_cuenta'];
        $o = $this->api()->orden($token, $this->cipher(), $ordenId);
        if (!is_array($o)) {
            $this->revisarAutorizacion();
            error_log('[LibertyFin] orden TikTok ' . $ordenId . ': ' . $this->api()->error());
            return ['estado' => 'error', 'nota' => $this->api()->error()];
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
        foreach ($this->api()->ordenesDesde($token, $this->cipher(), $desde) as $o) {
            $r = $this->procesarOrden($o);
            $n['revisadas']++;
            if ($r['estado'] === 'aplicada' && !empty($r['nueva'])) $n['aplicadas']++;
            if ($r['estado'] === 'devuelta' && !empty($r['nueva'])) $n['devueltas']++;
        }
        if ($this->api()->error() === '') $this->repo->anotarSincronizado($inicio);
        else $this->revisarAutorizacion();
        return $n;
    }

    /** Pagada y ya sin ventana de arrepentimiento (ver TikTok::PAGADAS). */
    public static function pagada(array $o)
    {
        return in_array($o['estado'] ?? '', TikTok::PAGADAS, true);
    }

    public static function cancelada(array $o)
    {
        return ($o['estado'] ?? '') === 'CANCELLED';
    }

    /**
     * Aplica UNA orden (ya en el formato plano de TikTok::orden_). Idempotente:
     *   pagada     → se descuenta (una vez)
     *   cancelada  → si se había descontado, regresa (una vez)
     *   lo demás   → se anota y se espera (UNPAID, ON_HOLD)
     */
    public function procesarOrden(array $o)
    {
        $ordenId = (string)($o['id'] ?? '');
        if ($ordenId === '') return ['estado' => 'error', 'nota' => 'orden sin id'];

        $c = $this->cuenta();
        $estadoTt = strtolower((string)($o['estado'] ?? ''));
        $fecha    = !empty($o['creada']) ? (string)$o['creada'] : null;
        $total    = (float)($o['total'] ?? 0);

        if (!$this->encendido()) return ['estado' => 'apagado', 'nueva' => false, 'nota' => 'Módulo apagado'];
        $inv = $this->inventario();
        $suc = $this->sucursal();

        $this->db->beginTransaction();
        try {
            $fila = $this->repo->tomarOrden($ordenId);
            $aplicado = $fila['aplicado'] ? (array)json_decode($fila['aplicado'], true) : null;
            $antes = (string)$fila['estado'];
            $res = ['estado' => $antes, 'nueva' => false, 'nota' => (string)$fila['nota']];

            $guardar = function ($estado, $nota) use ($ordenId, $estadoTt, $total, $fecha, &$aplicado) {
                $this->repo->guardarOrden($ordenId, [
                    'estado' => $estado, 'estado_tt' => $estadoTt, 'total' => $total,
                    'fecha_tt' => $fecha, 'aplicado' => $aplicado, 'nota' => $nota,
                ]);
            };

            if ($antes === 'nueva' && $fecha && $c && strtotime($fecha) < strtotime((string)$c['conectado_en'])) {
                $guardar('anterior', 'De antes de conectar la tienda: no se movió nada.');
                $res = ['estado' => 'anterior', 'nueva' => true, 'nota' => ''];
            } elseif (self::pagada($o) && $aplicado === null && in_array($antes, ['nueva', 'esperando'], true)) {
                list($aplicado, $nota) = $this->descontar($o, $inv, $suc, $ordenId, $c);
                $estado = $aplicado ? 'aplicada' : 'sin_vincular';
                $guardar($estado, $nota);
                $res = ['estado' => $estado, 'nueva' => true, 'nota' => $nota];
            } elseif (self::cancelada($o) && $aplicado && $antes === 'aplicada') {
                foreach ($aplicado as $l) {
                    self::mover($inv, $l['producto_id'], $l['sucursal_id'], (float)$l['cantidad'],
                        'Orden TikTok #' . $ordenId . ' cancelada');
                }
                $guardar('devuelta', 'Cancelada en TikTok Shop: la mercancía regresó.');
                $res = ['estado' => 'devuelta', 'nueva' => true, 'nota' => ''];
            } elseif ($antes === 'nueva' || $antes === 'esperando') {
                $estado = self::cancelada($o) ? 'cancelada' : 'esperando';
                $guardar($estado, $estado === 'cancelada' ? 'Se canceló antes de pagarse.'
                    : (($o['estado'] ?? '') === 'ON_HOLD' ? 'Pagada, en periodo de cancelación de TikTok.' : 'Esperando a que se pague.'));
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

    private function descontar(array $o, Inventario $inv, $suc, $ordenId, $c)
    {
        if ($c && (int)$c['descontar_ventas'] !== 1) {
            return [null, 'Descontar ventas está apagado en Ajustes de TikTok Shop.'];
        }
        if (!$inv->activo()) return [null, 'El inventario está apagado (Ajustes → Inventario).'];

        // Se juntan por SKU: TikTok manda un renglón por pieza.
        $porSku = [];
        foreach ((array)($o['lineas'] ?? []) as $l) {
            if (!empty($l['cancelada']) || (float)$l['cantidad'] <= 0) continue;
            $k = (string)$l['sku_id'] !== '' ? 'id:' . $l['sku_id'] : 'sku:' . $l['sku'];
            if (!isset($porSku[$k])) $porSku[$k] = $l + ['total' => 0.0];
            $porSku[$k]['total'] += (float)$l['cantidad'];
        }

        $aplicado = []; $faltan = [];
        $nota = 'Orden TikTok #' . $ordenId;
        foreach ($porSku as $l) {
            $fila = (string)$l['sku_id'] !== '' ? $this->repo->porSkuTikTok($l['sku_id']) : null;
            $pid  = $fila ? (int)$fila['producto_id'] : 0;
            if (!$pid) $pid = $this->porSku((string)$l['sku']);
            $quien = $l['nombre'] ?: ($l['sku'] ?: $l['sku_id']);
            if (!$pid) { $faltan[] = $quien; continue; }

            if (!self::mover($inv, $pid, $suc, -$l['total'], $nota)) { $faltan[] = $quien . ' (no lleva existencias)'; continue; }
            $aplicado[] = ['producto_id' => $pid, 'sucursal_id' => $suc, 'cantidad' => $l['total']];
        }
        $txt = $faltan ? 'Sin vincular: ' . implode(', ', array_slice($faltan, 0, 3)) . (count($faltan) > 3 ? '…' : '') : '';
        return [$aplicado ?: null, $txt];
    }

    /** Mueve existencias por una orden de TikTok (salida o entrada, con su nota). */
    private static function mover(Inventario $inv, $pid, $suc, $cant, $nota)
    {
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

    /** Aviso «cambió este producto»: se refresca lo que dice TikTok. */
    public function refrescarProducto($productoTt)
    {
        $token = $this->token();
        if ($token === '') return false;
        $p = $this->api()->producto($token, $this->cipher(), $productoTt);
        if (!is_array($p)) {
            if ($this->api()->http() === 404) {
                $p = ['id' => (string)$productoTt, 'status' => 'DELETED', 'skus' => []];
            } else {
                $this->revisarAutorizacion();
                return false;
            }
        }
        foreach (self::renglonesDe($p, $this->almacen()) as $r) $this->repo->guardarSku($r);
        if (($p['status'] ?? '') === 'DELETED') {
            $this->db->prepare("UPDATE lf_tt_productos SET estado_tt = 'ausente', pendiente = 0 WHERE producto_tt = ?")
                     ->execute([(string)$productoTt]);
        }
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
                ? 'TikTok Shop ya no acepta la llave de la tienda. Vuelve a conectar.'
                : 'No hay una tienda de TikTok Shop conectada' . ($c && $c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '.'));
        }
        return $t;
    }
}
