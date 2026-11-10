<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\TiendaNubeRepo;
use PDO;

/**
 * Tienda Nube ⇄ Inventario de UNA empresa. Mismas reglas que MeliInventario:
 *
 *  Tienda Nube → LibertyFin   Cuando una orden queda PAGADA se descuenta de la
 *                             sucursal elegida, con un movimiento «Tienda Nube».
 *                             Si después se cancela, la mercancía regresa.
 *
 *  LibertyFin → Tienda Nube   Cualquier movimiento marca sus variantes como
 *                             pendientes y se manda la existencia nueva. Nace
 *                             APAGADO: encenderlo sobreescribe la existencia de
 *                             la tienda con la de LibertyFin.
 *
 * No crea ventas en LibertyFin (las cobra Tienda Nube, no la caja) y no toca
 * órdenes anteriores a la conexión.
 *
 * Tienda Nube también descuenta su propia existencia al vender; LibertyFin
 * descuenta la suya. Con «Mandar existencias» encendido, la de LibertyFin
 * manda y se vuelve a escribir allá, así que las dos quedan iguales.
 */
final class TnInventario
{
    const AL_VUELO = 8;

    private $db;
    private $repo;
    private $api;
    private $cuenta = null;

    /** @param TiendaNube|null $api */
    public function __construct(PDO $db, $api = null)
    {
        $this->db   = $db;
        $this->repo = new TiendaNubeRepo($db);
        $this->api  = $api ?: new TiendaNube();
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

    public function tiendaId()
    {
        $c = $this->cuenta();
        return $c ? (string)$c['store_id'] : '';
    }

    public function idioma()
    {
        $c = $this->cuenta();
        return $c && !empty($c['idioma']) ? (string)$c['idioma'] : 'es';
    }

    /** Módulo Tienda Nube Y Inventario encendidos (ver MeliInventario::encendido). */
    public function encendido()
    {
        return self::ajuste($this->db, 'modulo.tiendanube') === 'si'
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
     * La llave de la tienda. No caduca: si Tienda Nube la rechaza (401) es
     * que desinstalaron la aplicación, y eso lo marca quien recibe el 401.
     * @return string la llave, o '' si no hay tienda conectada
     */
    public function token()
    {
        $c = $this->cuenta();
        if (!$c || $c['estado'] !== 'conectada') return '';
        $t = TiendaNube::descifrar($c['access_token']);
        if ($t === '' && !empty($c['access_token'])) {
            $this->repo->marcarVencida('No se pudo leer la llave guardada (¿cambió la llave de cifrado?). Vuelve a conectar.');
            $this->cuenta = null;
        }
        return $t;
    }

    /** Si la última llamada fue un 401, la tienda quitó el permiso: se marca para reconectar. */
    private function revisarAutorizacion()
    {
        if (!$this->api->fueAutorizacion()) return false;
        $this->repo->marcarVencida($this->api->error());
        Auditoria::anota('tn.vencida', 'Tienda Nube', null, $this->api->error(), $this->db);
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
        $lista = $this->api->productos($token, $this->tiendaId());
        if ($lista === null) {
            $this->revisarAutorizacion();
            throw new \RuntimeException($this->api->error() ?: 'No se pudieron leer los productos.');
        }
        $vistas = [];
        $renglones = 0;
        foreach ($lista as $p) {
            foreach (self::renglonesDe($p, $this->idioma()) as $r) {
                $this->repo->guardarVariante($r);
                $vistas[] = $r['variante_tn'];
                $renglones++;
            }
        }
        if ($vistas) $this->repo->marcarAusentes($vistas);
        $vinculadas = $this->autoVincular();
        $this->repo->anotarError(null);
        return ['productos' => count($lista), 'renglones' => $renglones, 'vinculadas' => $vinculadas];
    }

    /** Un renglón por variante. stock_tn = null es «existencia infinita» (no lleva control allá). */
    public static function renglonesDe(array $p, $idioma = 'es')
    {
        $titulo = TiendaNube::texto($p['name'] ?? '', $idioma);
        $imagen = '';
        foreach ((array)($p['images'] ?? []) as $im) { if (!empty($im['src'])) { $imagen = (string)$im['src']; break; } }
        $permalink = (string)($p['canonical_url'] ?? '');
        $estado = !empty($p['published']) ? 'visible' : 'oculto';

        $r = [];
        foreach ((array)($p['variants'] ?? []) as $v) {
            if (empty($v['id'])) continue;
            $nombre = [];
            foreach ((array)($v['values'] ?? []) as $x) {
                $t = TiendaNube::texto($x, $idioma);
                if ($t !== '') $nombre[] = $t;
            }
            $controla = !array_key_exists('stock_management', $v) || !empty($v['stock_management']);
            $stock = $v['stock'] ?? null;
            $r[] = [
                'producto_tn' => (string)$p['id'],
                'variante_tn' => (string)$v['id'],
                'titulo'      => $titulo,
                'variante'    => implode(' · ', $nombre),
                'sku'         => trim((string)($v['sku'] ?? '')),
                'estado_tn'   => $estado,
                'permalink'   => $permalink,
                'imagen'      => $imagen,
                'precio'      => (float)($v['promotional_price'] ?? 0) > 0 ? (float)$v['promotional_price'] : (float)($v['price'] ?? 0),
                'stock_tn'    => $controla && $stock !== null && $stock !== '' ? (int)$stock : null,
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
    //  LIBERTYFIN → TIENDA NUBE
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
        if ($token === '') return $r;
        $tienda = $this->tiendaId();

        $filas = $this->repo->pendientes($tope + 1);
        $r['quedan'] = count($filas) > $tope;
        foreach (array_slice($filas, 0, $tope) as $f) {
            $cant = $this->cantidadAPublicar($f['producto_id']);
            if ($this->api->ponerExistencia($token, $tienda, $f['producto_tn'], $f['variante_tn'], $cant)) {
                $this->repo->anotarEnviado($f['id'], $cant);
                $r['enviadas']++;
            } else {
                $err = $this->api->error();
                // Un 404 (lo borraron allá) no se arregla reintentando.
                $this->repo->anotarFallo($f['id'], $err, $this->api->http() !== 404);
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
     * Lo llama Servicio\Inventario en CADA movimiento, dentro de la
     * transacción de quien mueve. Barato y nunca falla hacia afuera; no
     * llama a Tienda Nube aquí: solo marca, y al terminar la petición se
     * manda (ver MeliInventario::marcar).
     */
    public static function marcar(PDO $db, $productoId)
    {
        $llave = spl_object_hash($db);
        try {
            if (!isset(self::$manda[$llave])) {
                $f = $db->query("SELECT estado, enviar_existencias FROM lf_tn_cuenta WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                self::$manda[$llave] = $f && $f['estado'] === 'conectada' && (int)$f['enviar_existencias'] === 1
                                       && self::ajuste($db, 'modulo.tiendanube') === 'si'
                                       && TiendaNube::activa();
            }
            if (!self::$manda[$llave]) return;
            (new TiendaNubeRepo($db))->marcarPendiente($productoId);
        } catch (\Throwable $e) {
            self::$manda[$llave] = false;   // sin tablas: esta empresa no usa Tienda Nube
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
                error_log('[LibertyFin] Tienda Nube, al cerrar: ' . $e->getMessage());
            }
        });
    }

    // ════════════════════════════════════════════════════════
    //  TIENDA NUBE → LIBERTYFIN
    // ════════════════════════════════════════════════════════

    public function procesarOrdenId($ordenId)
    {
        $token = $this->token();
        if ($token === '') return ['estado' => 'sin_cuenta'];
        $o = $this->api->orden($token, $this->tiendaId(), $ordenId);
        if (!is_array($o)) {
            $this->revisarAutorizacion();
            error_log('[LibertyFin] orden TN ' . $ordenId . ': ' . $this->api->error());
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

    /** Pagada en Tienda Nube: payment_status «paid» y no cancelada. */
    public static function pagada(array $o)
    {
        return ($o['payment_status'] ?? '') === 'paid' && !self::cancelada($o);
    }

    public static function cancelada(array $o)
    {
        return ($o['status'] ?? '') === 'cancelled'
            || in_array($o['payment_status'] ?? '', ['voided', 'refunded'], true);
    }

    /**
     * Aplica UNA orden. Idempotente:
     *   pagada     → se descuenta (una vez)
     *   cancelada  → si se había descontado, regresa (una vez)
     *   lo demás   → se anota y se espera
     */
    public function procesarOrden(array $o)
    {
        $ordenId = (string)($o['id'] ?? '');
        if ($ordenId === '') return ['estado' => 'error', 'nota' => 'orden sin id'];

        $c = $this->cuenta();
        $estadoTn  = trim((string)($o['status'] ?? '') . ' / ' . (string)($o['payment_status'] ?? ''), ' /');
        $fecha     = !empty($o['created_at']) ? date('Y-m-d H:i:s', strtotime($o['created_at'])) : null;
        $comprador = (string)($o['customer']['name'] ?? ($o['contact_name'] ?? ''));
        $total     = (float)($o['total'] ?? 0);
        $numero    = (string)($o['number'] ?? '');

        if (!$this->encendido()) return ['estado' => 'apagado', 'nueva' => false, 'nota' => 'Módulo apagado'];
        $inv = $this->inventario();
        $suc = $this->sucursal();

        $this->db->beginTransaction();
        try {
            $fila = $this->repo->tomarOrden($ordenId);
            $aplicado = $fila['aplicado'] ? (array)json_decode($fila['aplicado'], true) : null;
            $antes = (string)$fila['estado'];
            $res = ['estado' => $antes, 'nueva' => false, 'nota' => (string)$fila['nota']];

            $guardar = function ($estado, $nota) use ($ordenId, $numero, $estadoTn, $comprador, $total, $fecha, &$aplicado) {
                $this->repo->guardarOrden($ordenId, [
                    'numero' => $numero, 'estado' => $estado, 'estado_tn' => $estadoTn, 'comprador' => $comprador,
                    'total' => $total, 'fecha_tn' => $fecha, 'aplicado' => $aplicado, 'nota' => $nota,
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
                    $inv->moverPorTiendaNube($l['producto_id'], $l['sucursal_id'], (float)$l['cantidad'],
                        'Orden Tienda Nube #' . ($numero ?: $ordenId) . ' cancelada');
                }
                $guardar('devuelta', 'Cancelada en Tienda Nube: la mercancía regresó.');
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
            return [null, 'Descontar ventas está apagado en Ajustes de Tienda Nube.'];
        }
        if (!$inv->activo()) return [null, 'El inventario está apagado (Ajustes → Inventario).'];

        $aplicado = []; $faltan = [];
        $quien = trim((string)($o['customer']['name'] ?? ''));
        $nota  = 'Orden Tienda Nube #' . $numero . ($quien !== '' ? ' · ' . $quien : '');
        foreach ((array)($o['products'] ?? []) as $l) {
            $var  = (string)($l['variant_id'] ?? '');
            $cant = (float)($l['quantity'] ?? 0);
            if ($cant <= 0) continue;

            $fila = $var !== '' ? $this->repo->porVariante($var) : null;
            $pid  = $fila ? (int)$fila['producto_id'] : 0;
            if (!$pid) $pid = $this->porSku((string)($l['sku'] ?? ''));
            if (!$pid) { $faltan[] = (string)($l['name'] ?? $var); continue; }

            $inv->moverPorTiendaNube($pid, $suc, -$cant, $nota);
            $aplicado[] = ['producto_id' => $pid, 'sucursal_id' => $suc, 'cantidad' => $cant];
        }
        $txt = $faltan ? 'Sin vincular: ' . implode(', ', array_slice($faltan, 0, 3)) . (count($faltan) > 3 ? '…' : '') : '';
        return [$aplicado ?: null, $txt];
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

    /** Aviso «cambió este producto»: se refresca lo que dice Tienda Nube. */
    public function refrescarProducto($productoTn)
    {
        $token = $this->token();
        if ($token === '') return false;
        $p = $this->api->producto($token, $this->tiendaId(), $productoTn);
        if (!is_array($p)) {
            if ($this->api->http() === 404) { $this->repo->marcarProductoAusente($productoTn); return true; }
            $this->revisarAutorizacion();
            return false;
        }
        foreach (self::renglonesDe($p, $this->idioma()) as $r) $this->repo->guardarVariante($r);
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
            $c = $this->cuenta();
            throw new \InvalidArgumentException(($c && $c['estado'] === 'vencida')
                ? 'Tienda Nube ya no acepta la llave de la tienda (se desinstaló la aplicación). Vuelve a conectar.'
                : 'No hay una tienda de Tienda Nube conectada' . ($c && $c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '.'));
        }
        return $t;
    }
}
