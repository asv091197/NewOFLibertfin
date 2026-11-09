<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\MeliRepo;
use PDO;

/**
 * Mercado Libre ⇄ Inventario de UNA empresa.
 *
 * ──────────────────────────────────────────────────────────────────────
 * LAS DOS DIRECCIONES
 *
 *  Mercado Libre → LibertyFin   Se vende algo allá: cuando la orden queda
 *                               PAGADA se descuenta de la sucursal elegida,
 *                               con un movimiento «Mercado Libre» en la
 *                               bitácora. Si después se cancela, la
 *                               mercancía regresa.
 *
 *  LibertyFin → Mercado Libre   Cualquier movimiento —una venta en Caja,
 *                               una entrada, un conteo— marca sus
 *                               publicaciones como pendientes, y se manda
 *                               la existencia nueva. Así no se vende allá
 *                               lo que ya se vendió en el mostrador.
 *
 * La segunda nace APAGADA. Encenderla sobreescribe la cantidad publicada
 * en Mercado Libre con la de LibertyFin, y si los vínculos están mal o el
 * inventario no está al día, eso deja publicaciones en cero. Primero se
 * revisan los vínculos en pantalla —se ve la existencia de los dos lados—
 * y luego se enciende.
 * ──────────────────────────────────────────────────────────────────────
 *
 * QUÉ NO HACE, A PROPÓSITO
 *
 *  · No crea ventas en LibertyFin. Una venta de Mercado Libre la cobra
 *    Mercado Libre y llega por Mercado Pago, no a la caja: meterla en
 *    Ventas descuadraría el corte y Cobranza. Lo que sí toca es la
 *    existencia, que es lo que se comparte de verdad.
 *  · No mueve existencias de publicaciones en Mercado Libre Full. Esa
 *    mercancía ya está en una bodega de Mercado Libre, no en la tienda.
 *  · No toca órdenes anteriores a la conexión: conectar la cuenta no debe
 *    descontar las ventas del año pasado.
 */
final class MeliInventario
{
    /** Cuántas publicaciones se mandan al terminar una petición. El resto, la tarea periódica. */
    const AL_VUELO = 8;

    private $db;
    private $repo;
    private $api;
    private $cuenta = null;

    /** @param MercadoLibre|null $api */
    public function __construct(PDO $db, $api = null)
    {
        $this->db   = $db;
        $this->repo = new MeliRepo($db);
        $this->api  = $api ?: new MercadoLibre();
    }

    public function repo() { return $this->repo; }
    public function api()  { return $this->api; }

    /** La cuenta tal como está en la base (sin descifrar). null si nunca se conectó. */
    public function cuenta()
    {
        if ($this->cuenta === null) $this->cuenta = $this->repo->cuenta() ?: [];
        return $this->cuenta ?: null;
    }

    public function conectada()
    {
        $c = $this->cuenta();
        return $c && $c['estado'] === 'conectada' && !empty($c['refresh_token']);
    }

    /**
     * ¿La empresa tiene el módulo encendido? Con la cuenta conectada pero
     * el módulo apagado (Ajustes → Módulos) no se mueve nada: apagarlo es
     * la forma de pausar la sincronización sin perder la conexión.
     */
    public function encendido()
    {
        // Igual que Modulos::activo(): sin Inventario encendido no hay nada
        // que descontar. Inventario también se apaga desde su propia pestaña,
        // que no toca `modulo.mercadolibre`, por eso se revisan los dos.
        return self::ajuste($this->db, 'modulo.mercadolibre') === 'si'
            && self::ajuste($this->db, 'inventario.activo') === 'si';
    }

    /**
     * Un valor de lf_ajustes leído DIRECTO, sin ConfigRepo.
     *
     * ConfigRepo guarda lo leído en propiedades estáticas, compartidas por
     * todas las bases de la petición. En la web da igual —hay una sola
     * empresa—, pero la tarea periódica recorre varias en el mismo
     * proceso, y la segunda leería los ajustes de la primera.
     */
    private static function ajuste(PDO $db, $clave)
    {
        try {
            $st = $db->prepare("SELECT valor FROM lf_ajustes WHERE clave = ?");
            $st->execute([(string)$clave]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\Throwable $e) { return null; }
    }

    /** El Inventario de ESTA base, con sus ajustes leídos de ella (ver ajuste()). */
    private function inventario()
    {
        return new Inventario($this->db, [
            'activo'         => self::ajuste($this->db, 'inventario.activo') ?: 'no',
            'sin_existencia' => self::ajuste($this->db, 'inventario.sin_existencia') ?: 'avisar',
        ]);
    }

    /** La sucursal de donde sale lo que se vende en Mercado Libre. Sin elegir, la matriz. */
    public function sucursal()
    {
        $c = $this->cuenta();
        if ($c && (int)$c['sucursal_id'] > 0) return (int)$c['sucursal_id'];
        $id = $this->db->query("SELECT id FROM sucursales WHERE COALESCE(activo,1) = 1
                                 ORDER BY COALESCE(es_matriz,0) DESC, id LIMIT 1")->fetchColumn();
        return (int)$id;
    }

    // ════════════════════════════════════════════════════════
    //  LA LLAVE VIGENTE
    // ════════════════════════════════════════════════════════

    /**
     * Un access_token que sirva AHORA. Si está por caducar, se renueva.
     *
     * El candado es por base: dos empresas pueden renovar a la vez, pero
     * dos peticiones de la misma no. Ver MercadoLibre, «el refresh se gasta».
     *
     * @return string el token, o '' si la cuenta ya no está autorizada
     */
    public function token()
    {
        $c = $this->cuenta();
        if (!$c || $c['estado'] !== 'conectada') return '';

        if (!empty($c['access_token']) && strtotime((string)$c['expira_en']) - MercadoLibre::MARGEN > time()) {
            $t = MercadoLibre::descifrar($c['access_token']);
            if ($t !== '') return $t;
        }

        $nombre = 'lf_meli_' . substr(sha1((string)$this->db->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
        $tengo = (int)$this->db->query("SELECT GET_LOCK(" . $this->db->quote($nombre) . ", 15)")->fetchColumn();
        if ($tengo !== 1) return '';
        try {
            // Releer: mientras se esperaba el candado, otro pudo renovar.
            $this->cuenta = null;
            $c = $this->cuenta();
            if (!$c || $c['estado'] !== 'conectada') return '';
            if (!empty($c['access_token']) && strtotime((string)$c['expira_en']) - MercadoLibre::MARGEN > time()) {
                return MercadoLibre::descifrar($c['access_token']);
            }

            $refresh = MercadoLibre::descifrar($c['refresh_token']);
            if ($refresh === '') {
                $this->repo->marcarVencida('No se pudieron leer las llaves guardadas (¿cambió la llave de cifrado?). Vuelve a conectar.');
                $this->cuenta = null;
                return '';
            }
            $n = $this->api->renovar($refresh);
            if (!$n) {
                if ($this->api->fueAutorizacion()) {
                    $this->repo->marcarVencida($this->api->error());
                    \LibertyFin\Servicio\Auditoria::anota('meli.vencida', 'Mercado Libre', null, $this->api->error(), $this->db);
                } else {
                    $this->repo->anotarError($this->api->error());   // de red: se reintenta luego
                }
                $this->cuenta = null;
                return '';
            }
            $this->repo->guardarLlaves(MercadoLibre::cifrar($n['access_token']),
                                       MercadoLibre::cifrar($n['refresh_token'] ?: $refresh), $n['expira_en']);
            $this->cuenta = null;
            return $n['access_token'];
        } finally {
            $this->db->query("SELECT RELEASE_LOCK(" . $this->db->quote($nombre) . ")");
        }
    }

    // ════════════════════════════════════════════════════════
    //  PUBLICACIONES
    // ════════════════════════════════════════════════════════

    /**
     * Trae todas las publicaciones del vendedor y empareja por SKU.
     *
     * Una publicación con variaciones se guarda como un renglón POR
     * VARIACIÓN: la talla M y la talla G son productos distintos en el
     * inventario, aunque en Mercado Libre sean una sola publicación.
     *
     * @return array ['publicaciones' => n, 'renglones' => n, 'vinculadas' => n]
     */
    public function importar()
    {
        $token = $this->exigirToken();
        $c = $this->cuenta();
        $ids = $this->api->idsDePublicaciones($token, $c['meli_user_id']);
        if ($ids === null) throw new \RuntimeException($this->api->error() ?: 'No se pudieron leer las publicaciones.');

        $renglones = 0;
        foreach (array_chunk($ids, 100) as $lote) {
            foreach ($this->api->publicaciones($token, $lote) as $item) {
                foreach (self::renglonesDe($item) as $r) {
                    $this->repo->guardarPublicacion($r);
                    $renglones++;
                }
            }
        }
        if ($ids) $this->repo->marcarAusentes($ids);
        $vinculadas = $this->autoVincular();
        $this->repo->anotarError(null);
        return ['publicaciones' => count($ids), 'renglones' => $renglones, 'vinculadas' => $vinculadas];
    }

    /** Lo que se guarda de una publicación: uno por variación, o uno solo. */
    public static function renglonesDe(array $item)
    {
        $base = [
            'item_id'   => (string)$item['id'],
            'titulo'    => (string)($item['title'] ?? ''),
            'estado_ml' => (string)($item['status'] ?? ''),
            'logistica' => (string)($item['shipping']['logistic_type'] ?? ''),
            'permalink' => (string)($item['permalink'] ?? ''),
            'imagen'    => (string)($item['thumbnail'] ?? ''),
            'precio'    => (float)($item['price'] ?? 0),
        ];
        $vars = (array)($item['variations'] ?? []);
        if (!$vars) {
            return [$base + [
                'variacion_id' => 0, 'variacion' => '',
                'sku'          => self::skuDe($item),
                'stock_ml'     => (int)($item['available_quantity'] ?? 0),
            ]];
        }
        $r = [];
        foreach ($vars as $v) {
            $nombre = [];
            foreach ((array)($v['attribute_combinations'] ?? []) as $a) {
                if (!empty($a['value_name'])) $nombre[] = $a['value_name'];
            }
            $r[] = array_merge($base, [
                'variacion_id' => (int)($v['id'] ?? 0),
                'variacion'    => implode(' · ', $nombre),
                'sku'          => self::skuDe($v),
                'stock_ml'     => (int)($v['available_quantity'] ?? 0),
                'precio'       => (float)($v['price'] ?? $base['precio']),
            ]);
        }
        return $r;
    }

    /**
     * El SKU del vendedor. Mercado Libre lo tuvo en dos lugares: el viejo
     * `seller_custom_field` y el atributo SELLER_SKU, que es el actual.
     * Las cuentas con años tienen de los dos, así que se miran ambos.
     */
    public static function skuDe(array $x)
    {
        foreach ((array)($x['attributes'] ?? []) as $a) {
            if (($a['id'] ?? '') === 'SELLER_SKU' && trim((string)($a['value_name'] ?? '')) !== '') {
                return trim((string)$a['value_name']);
            }
        }
        return trim((string)($x['seller_custom_field'] ?? ''));
    }

    /**
     * Empareja solo lo que no admite duda: el SKU de Mercado Libre igual
     * al SKU o al código de barras de UN producto que lleva existencias.
     * Si coincide con dos, no se elige: se deja para que lo decida una
     * persona. Adivinar es cómo se descuenta del producto equivocado.
     */
    public function autoVincular()
    {
        $n = 0;
        $enviar = $this->enviaExistencias();
        $st = $this->db->prepare("SELECT id FROM productos
                                   WHERE (codigo = ? OR codigo_barras = ?) AND activo = 1 AND lleva_inventario = 1
                                   LIMIT 2");
        foreach ($this->repo->sinVincularConSku() as $p) {
            $st->execute([$p['sku'], $p['sku']]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                $this->repo->vincular($p['id'], (int)$ids[0], 'auto', $enviar);
                $n++;
            }
        }
        return $n;
    }

    public function vincular($publicacionId, $productoId)
    {
        $p = $this->repo->publicacion($publicacionId);
        if (!$p) throw new \InvalidArgumentException('Esa publicación no existe. Vuelve a importar.');
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
        $this->repo->vincular($publicacionId, $productoId, 'manual', $productoId && $this->enviaExistencias());
        return $p;
    }

    // ════════════════════════════════════════════════════════
    //  LIBERTYFIN → MERCADO LIBRE
    // ════════════════════════════════════════════════════════

    public function enviaExistencias()
    {
        $c = $this->cuenta();
        return $c && (int)$c['enviar_existencias'] === 1;
    }

    /**
     * Cuánto se publica de un producto: lo que hay en la sucursal elegida,
     * menos la reserva, en piezas enteras y nunca negativo.
     *
     * La reserva es el colchón para no vender en línea la última pieza que
     * alguien tiene en la mano en el mostrador.
     */
    public function cantidadAPublicar($productoId)
    {
        $st = $this->db->prepare("SELECT COALESCE(stock,0) FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $st->execute([(int)$productoId, $this->sucursal()]);
        $hay = (float)$st->fetchColumn();
        $c = $this->cuenta();
        return max(0, (int)floor($hay - (float)($c['reserva'] ?? 0) + 0.0005));
    }

    /**
     * Manda a Mercado Libre la existencia de lo pendiente.
     * @return array ['enviadas' => n, 'fallas' => n, 'quedan' => bool]
     */
    public function empujarPendientes($tope = 50)
    {
        $r = ['enviadas' => 0, 'fallas' => 0, 'quedan' => false];
        if (!$this->conectada() || !$this->enviaExistencias() || !$this->encendido()) return $r;
        $token = $this->token();
        if ($token === '') return $r;

        $items = $this->repo->itemsPendientes($tope + 1);
        $r['quedan'] = count($items) > $tope;
        foreach (array_slice($items, 0, $tope) as $itemId) {
            $filas = array_filter($this->repo->deItem($itemId), function ($f) {
                return (int)$f['pendiente'] === 1 && $f['producto_id'];
            });
            if (!$filas) continue;

            $cant = [];
            foreach ($filas as $f) $cant[(int)$f['variacion_id']] = $this->cantidadAPublicar($f['producto_id']);

            if ($this->api->ponerExistencia($token, $itemId, $cant)) {
                foreach ($filas as $f) $this->repo->anotarEnviado($f['id'], $cant[(int)$f['variacion_id']]);
                $r['enviadas'] += count($filas);
            } else {
                $err = $this->api->error();
                // Lo que no se arregla reintentando se deja de reintentar.
                $sinRemedio = stripos($err, 'Full') !== false || stripos($err, 'closed') !== false
                           || stripos($err, 'finaliz') !== false || $this->api->http() === 404;
                foreach ($filas as $f) $this->repo->anotarFallo($f['id'], $err, !$sinRemedio);
                $r['fallas'] += count($filas);
                if ($this->api->fueAutorizacion()) break;
            }
        }
        return $r;
    }

    // ── El enganche con Inventario ──────────────────────────────

    /** Por base: ¿esta empresa manda existencias a Mercado Libre? Una consulta por petición. */
    private static $manda = [];
    private static $alCerrar = [];

    /**
     * Lo llama Servicio\Inventario en CADA movimiento, dentro de la
     * transacción de quien mueve (una venta, una entrada…).
     *
     * Por eso es barato y no puede fallar hacia afuera: una empresa sin
     * Mercado Libre —o con la base sin migrar— no hace nada más que una
     * consulta la primera vez, y un error aquí nunca tumba una venta.
     *
     * No llama a Mercado Libre aquí: hacerlo dentro de la transacción de
     * una venta la dejaría esperando a un servidor ajeno con las filas
     * bloqueadas. Solo marca, y al terminar la petición se manda.
     */
    public static function marcar(PDO $db, $productoId)
    {
        $llave = spl_object_hash($db);
        try {
            if (!isset(self::$manda[$llave])) {
                $f = $db->query("SELECT estado, enviar_existencias FROM lf_meli_cuenta WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                self::$manda[$llave] = $f && $f['estado'] === 'conectada' && (int)$f['enviar_existencias'] === 1
                                       && self::ajuste($db, 'modulo.mercadolibre') === 'si'
                                       && MercadoLibre::activa();
            }
            if (!self::$manda[$llave]) return;
            (new MeliRepo($db))->marcarPendiente($productoId);
        } catch (\Throwable $e) {
            self::$manda[$llave] = false;   // sin tablas: esta empresa no usa Mercado Libre
            return;
        }

        if (isset(self::$alCerrar[$llave])) return;
        self::$alCerrar[$llave] = true;
        register_shutdown_function(function () use ($db) {
            // Primero se le contesta a quien hizo la venta; lo de
            // Mercado Libre corre después, sin que nadie espere.
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try {
                if ($db->inTransaction()) return;   // algo se cayó a medias: que lo haga la tarea
                (new self($db))->empujarPendientes(self::AL_VUELO);
            } catch (\Throwable $e) {
                error_log('[LibertyFin] Mercado Libre, al cerrar: ' . $e->getMessage());
            }
        });
    }

    // ════════════════════════════════════════════════════════
    //  MERCADO LIBRE → LIBERTYFIN
    // ════════════════════════════════════════════════════════

    /** Un aviso dijo «cambió esta orden»: se pide entera y se procesa. */
    public function procesarOrdenId($ordenId)
    {
        $token = $this->token();
        if ($token === '') return ['estado' => 'sin_cuenta'];
        $o = $this->api->orden($token, $ordenId);
        if (!is_array($o)) {
            error_log('[LibertyFin] orden ML ' . $ordenId . ': ' . $this->api->error());
            return ['estado' => 'error', 'nota' => $this->api->error()];
        }
        return $this->procesarOrden($o);
    }

    /**
     * Revisa las órdenes que cambiaron desde la última vez. Es lo que
     * recupera los avisos que no llegaron.
     */
    public function revisarOrdenes()
    {
        $token = $this->exigirToken();
        $c = $this->cuenta();
        // Desde la última revisión, con una hora de traslape: el reloj de
        // Mercado Libre y el nuestro no tienen por qué coincidir al segundo.
        $desde = $c['ordenes_desde'] ?: $c['conectado_en'];
        $desde = date('Y-m-d H:i:s', max(strtotime((string)$desde) - 3600, strtotime('-30 days')));
        $inicio = date('Y-m-d H:i:s');

        $n = ['revisadas' => 0, 'aplicadas' => 0, 'devueltas' => 0];
        foreach ($this->api->ordenesDesde($token, $c['meli_user_id'], $desde) as $o) {
            $r = $this->procesarOrden($o);
            $n['revisadas']++;
            if ($r['estado'] === 'aplicada' && !empty($r['nueva'])) $n['aplicadas']++;
            if ($r['estado'] === 'devuelta' && !empty($r['nueva'])) $n['devueltas']++;
        }
        if ($this->api->error() === '') $this->repo->anotarSincronizado($inicio);
        return $n;
    }

    /**
     * Aplica UNA orden. Idempotente: se puede llamar diez veces con la
     * misma y solo la primera mueve existencias.
     *
     *   pagada     → se descuenta (una vez)
     *   cancelada  → si se había descontado, regresa (una vez)
     *   lo demás   → se anota y se espera
     *
     * @return array ['estado' => ..., 'nueva' => bool, 'nota' => ...]
     */
    public function procesarOrden(array $o)
    {
        $ordenId = (string)($o['id'] ?? '');
        if ($ordenId === '') return ['estado' => 'error', 'nota' => 'orden sin id'];

        $c = $this->cuenta();
        $estadoMl  = (string)($o['status'] ?? '');
        $fecha     = !empty($o['date_created']) ? date('Y-m-d H:i:s', strtotime($o['date_created'])) : null;
        $comprador = (string)($o['buyer']['nickname'] ?? '');
        $total     = (float)($o['total_amount'] ?? 0);

        if (!$this->encendido()) return ['estado' => 'apagado', 'nueva' => false, 'nota' => 'Módulo apagado'];
        $inv = $this->inventario();
        $suc = $this->sucursal();

        $this->db->beginTransaction();
        try {
            $fila = $this->repo->tomarOrden($ordenId);
            $aplicado = $fila['aplicado'] ? (array)json_decode($fila['aplicado'], true) : null;
            $antes = (string)$fila['estado'];
            $res = ['estado' => $antes, 'nueva' => false, 'nota' => (string)$fila['nota']];

            $guardar = function ($estado, $nota) use ($ordenId, $estadoMl, $comprador, $total, $fecha, &$aplicado) {
                $this->repo->guardarOrden($ordenId, [
                    'estado' => $estado, 'estado_ml' => $estadoMl, 'comprador' => $comprador,
                    'total' => $total, 'fecha_ml' => $fecha, 'aplicado' => $aplicado, 'nota' => $nota,
                ]);
            };

            if ($antes === 'nueva' && $fecha && $c && strtotime($fecha) < strtotime((string)$c['conectado_en'])) {
                // De antes de conectar: esa venta ya estaba reflejada (o no) a mano.
                $guardar('anterior', 'De antes de conectar la cuenta: no se movió nada.');
                $res = ['estado' => 'anterior', 'nueva' => true, 'nota' => ''];
            } elseif ($estadoMl === 'paid' && $aplicado === null && in_array($antes, ['nueva', 'esperando'], true)) {
                list($aplicado, $nota) = $this->descontar($o, $inv, $suc, $ordenId, $c);
                $estado = $aplicado ? 'aplicada' : 'sin_vincular';
                $guardar($estado, $nota);
                $res = ['estado' => $estado, 'nueva' => true, 'nota' => $nota];
            } elseif (in_array($estadoMl, ['cancelled', 'invalid'], true) && $aplicado && $antes === 'aplicada') {
                foreach ($aplicado as $l) {
                    $inv->moverPorMercadoLibre($l['producto_id'], $l['sucursal_id'], (float)$l['cantidad'],
                        'Orden ML #' . $ordenId . ' cancelada');
                }
                $guardar('devuelta', 'Cancelada en Mercado Libre: la mercancía regresó.');
                $res = ['estado' => 'devuelta', 'nueva' => true, 'nota' => ''];
            } elseif ($antes === 'nueva' || $antes === 'esperando') {
                $estado = in_array($estadoMl, ['cancelled', 'invalid'], true) ? 'cancelada' : 'esperando';
                $guardar($estado, $estado === 'cancelada' ? 'Se canceló antes de pagarse.' : 'Esperando a que se pague.');
                $res = ['estado' => $estado, 'nueva' => $antes === 'nueva', 'nota' => ''];
            } else {
                // Ya estaba resuelta; solo se refresca lo que diga Mercado Libre.
                $guardar($antes, (string)$fila['nota']);
            }
            $this->db->commit();
            return $res;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Las líneas de la orden que se pueden descontar.
     * @return array [lo aplicado (o null si nada), la nota]
     */
    private function descontar(array $o, Inventario $inv, $suc, $ordenId, $c)
    {
        if ($c && (int)$c['descontar_ventas'] !== 1) {
            return [null, 'Descontar ventas está apagado en Ajustes de Mercado Libre.'];
        }
        if (!$inv->activo()) return [null, 'El inventario está apagado (Ajustes → Inventario).'];

        $aplicado = []; $faltan = []; $full = 0;
        $quien = trim((string)($o['buyer']['nickname'] ?? ''));
        $nota  = 'Orden ML #' . $ordenId . ($quien !== '' ? ' · ' . $quien : '');
        foreach ((array)($o['order_items'] ?? []) as $l) {
            $item  = (string)($l['item']['id'] ?? '');
            $var   = (int)($l['item']['variation_id'] ?? 0);
            $cant  = (float)($l['quantity'] ?? 0);
            if ($item === '' || $cant <= 0) continue;

            $pub = $this->repo->porItem($item, $var) ?: ($var ? $this->repo->porItem($item, 0) : null);
            if ($pub && ($pub['logistica'] ?? '') === 'fulfillment') { $full++; continue; }

            $pid = $pub ? (int)$pub['producto_id'] : 0;
            if (!$pid) $pid = $this->porSku((string)($l['item']['seller_sku'] ?? $l['item']['seller_custom_field'] ?? ''));
            if (!$pid) { $faltan[] = (string)($l['item']['title'] ?? $item); continue; }

            $inv->moverPorMercadoLibre($pid, $suc, -$cant, $nota);
            $aplicado[]= ['producto_id' => $pid, 'sucursal_id' => $suc, 'cantidad' => $cant];
        }

        $nota = [];
        if ($faltan) $nota[] = 'Sin vincular: ' . implode(', ', array_slice($faltan, 0, 3)) . (count($faltan) > 3 ? '…' : '');
        if ($full)   $nota[] = $full . ' en Full (no se descuenta de la tienda)';
        return [$aplicado ?: null, implode(' · ', $nota)];
    }

    private function porSku($sku)
    {
        $sku = trim($sku);
        if ($sku === '') return 0;
        $st = $this->db->prepare("SELECT id FROM productos WHERE (codigo = ? OR codigo_barras = ?)
                                   AND activo = 1 AND lleva_inventario = 1 LIMIT 2");
        $st->execute([$sku, $sku]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    /** Un aviso de «cambió esta publicación»: solo se refresca lo que dice Mercado Libre. */
    public function refrescarPublicacion($itemId)
    {
        $token = $this->token();
        if ($token === '') return false;
        $item = $this->api->publicacion($token, $itemId);
        if (!is_array($item)) return false;
        foreach (self::renglonesDe($item) as $r) {
            if ($this->repo->porItem($r['item_id'], $r['variacion_id'])) {
                $this->repo->anotarStockMl($r['item_id'], $r['variacion_id'], $r['stock_ml'], $r['estado_ml']);
            } else {
                $this->repo->guardarPublicacion($r);
            }
        }
        return true;
    }

    /** Todo: órdenes primero (bajan existencias), luego mandar lo pendiente. */
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
                ? 'La autorización de Mercado Libre venció. Vuelve a conectar la cuenta.'
                : 'No hay una cuenta de Mercado Libre conectada' . ($c && $c['ultimo_error'] ? ': ' . $c['ultimo_error'] : '.'));
        }
        return $t;
    }
}
