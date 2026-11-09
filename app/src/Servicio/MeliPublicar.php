<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Publicar en Mercado Libre un producto de LibertyFin.
 *
 * LOS TRES PASOS
 *
 *  1. Categoría. Mercado Libre la sugiere a partir del nombre
 *     (domain_discovery). Se elige una; tiene que ser una hoja del árbol,
 *     porque en una categoría «padre» no se puede publicar.
 *  2. Datos. Título, precio, cantidad, condición, tipo, envío, garantía,
 *     fotos y descripción. Casi todo sale del producto.
 *  3. Ficha técnica. Lo que exige ESA categoría (marca, modelo, volumen…).
 *     Cambia de una categoría a otra, así que se pregunta a Mercado Libre
 *     en el momento y el formulario se arma con lo que contesta.
 *
 * Al publicar, la publicación nace VINCULADA al producto (vinculo
 * 'publicado'): desde ahí se comporta igual que una traída con «Traer
 * publicaciones».
 *
 * LO QUE NO HACE
 *
 *  · Variaciones: un producto, una publicación.
 *  · Catálogo: hay categorías que obligan a publicar en el catálogo de
 *    Mercado Libre. Mercado Libre lo rechaza y se dice tal cual.
 *  · Precio: se manda al publicar y después no se toca. Lo único que se
 *    sincroniza es la existencia.
 */
final class MeliPublicar
{
    /** Los que se preguntan siempre que la categoría los tenga, aunque no sean obligatorios. */
    const SIEMPRE = ['BRAND', 'MODEL', 'GTIN', 'EMPTY_GTIN_REASON', 'SELLER_SKU'];

    /** Los que se resuelven con otros campos del formulario. */
    const APARTE = ['ITEM_CONDITION'];

    const TIPOS     = ['gold_special' => 'Clásica', 'gold_pro' => 'Premium (meses sin intereses)'];
    const GARANTIAS = ['' => 'Sin garantía', 'Garantía del vendedor' => 'Garantía del vendedor',
                       'Garantía de fábrica' => 'Garantía de fábrica'];
    const ENVIOS    = ['me2' => 'Mercado Envíos', 'not_specified' => 'Lo acuerdo con el comprador'];
    const MAX_FOTOS = 6;
    const MAX_TITULO = 60;

    private $db;
    private $srv;

    /** @param MeliInventario|null $srv */
    public function __construct(PDO $db, $srv = null)
    {
        $this->db  = $db;
        $this->srv = $srv ?: new MeliInventario($db);
    }

    public function srv() { return $this->srv; }

    /** El producto, si se puede publicar. @throws \InvalidArgumentException */
    public function producto($id)
    {
        $st = $this->db->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1");
        $st->execute([(int)$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new \InvalidArgumentException('Ese producto no existe o está desactivado.');
        if (!(int)($p['lleva_inventario'] ?? 0)) {
            throw new \InvalidArgumentException('«' . $p['nombre'] . '» no lleva existencias. Márcalo con «Lleva existencias» en Productos: sin eso no hay qué sincronizar.');
        }
        return $p;
    }

    /** La publicación con la que ya está vinculado, o null. */
    public function yaPublicado($productoId)
    {
        $st = $this->db->prepare("SELECT * FROM lf_meli_publicaciones
                                   WHERE producto_id = ? AND estado_ml <> 'ausente' ORDER BY id LIMIT 1");
        $st->execute([(int)$productoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ════════════════════════════════════════════════════════
    //  PASOS 1 Y 3: LO QUE SE LE PREGUNTA A MERCADO LIBRE
    // ════════════════════════════════════════════════════════

    public function sugerencias($texto)
    {
        $token = $this->token();
        $r = [];
        foreach ($this->srv->api()->sugerirCategorias($token, $texto) as $s) {
            $r[] = ['id' => (string)$s['category_id'], 'nombre' => (string)($s['category_name'] ?? ''),
                    'dominio' => (string)($s['domain_name'] ?? '')];
        }
        return $r;
    }

    /**
     * La categoría y su ficha técnica, ya lista para el formulario.
     * @return array ['id','ruta','atributos' => [...]]
     */
    public function categoria($id)
    {
        if (!preg_match('/^[A-Z]{3}\d+$/', (string)$id)) throw new \InvalidArgumentException('Esa categoría no es válida.');
        $token = $this->token();
        $api = $this->srv->api();
        $c = $api->categoria($token, $id);
        if (!is_array($c)) throw new \RuntimeException('No se pudo leer la categoría: ' . $api->error());
        if (isset($c['settings']['listing_allowed']) && !$c['settings']['listing_allowed']) {
            throw new \InvalidArgumentException('En «' . ($c['name'] ?? $id) . '» no se puede publicar directamente: elige una categoría más específica.');
        }
        $ruta = [];
        foreach ((array)($c['path_from_root'] ?? []) as $n) $ruta[] = (string)($n['name'] ?? '');
        $attrs = $api->atributosDeCategoria($token, $id);
        if (!is_array($attrs)) throw new \RuntimeException('No se pudo leer la ficha técnica: ' . $api->error());
        return ['id' => (string)$id, 'ruta' => implode(' › ', array_filter($ruta)) ?: (string)($c['name'] ?? $id),
                'atributos' => self::normalizar($attrs)];
    }

    /**
     * De la lista cruda de Mercado Libre, lo que se pregunta y cómo.
     *
     * Se pregunta lo obligatorio (required, catalog_required,
     * conditional_required) y los de SIEMPRE. Lo oculto, de solo lectura o
     * fijo no se pregunta: Mercado Libre lo pone.
     */
    public static function normalizar(array $crudos)
    {
        $r = [];
        foreach ($crudos as $a) {
            $id = (string)($a['id'] ?? '');
            if ($id === '' || in_array($id, self::APARTE, true)) continue;
            $t = (array)($a['tags'] ?? []);
            if (!empty($t['hidden']) || !empty($t['read_only']) || !empty($t['fixed'])) continue;
            $obligatorio = !empty($t['required']) || !empty($t['catalog_required']);
            if (!$obligatorio && empty($t['conditional_required']) && !in_array($id, self::SIEMPRE, true)) continue;

            $vt = (string)($a['value_type'] ?? 'string');
            $valores = [];
            foreach ((array)($a['values'] ?? []) as $v) {
                if (isset($v['id'], $v['name'])) $valores[] = ['id' => (string)$v['id'], 'nombre' => (string)$v['name']];
            }
            $unidades = [];
            foreach ((array)($a['allowed_units'] ?? []) as $u) {
                if (isset($u['id'])) $unidades[] = (string)$u['id'];
            }
            if ($vt === 'number_unit')            $tipo = 'numero_unidad';
            elseif ($vt === 'number')             $tipo = 'numero';
            elseif (($vt === 'list' || $vt === 'boolean') && $valores) $tipo = 'lista';
            else                                   $tipo = 'texto';   // string, o lista sin valores: texto libre

            $r[$id] = [
                'id'          => $id,
                'nombre'      => (string)($a['name'] ?? $id),
                'tipo'        => $tipo,
                // En un texto los valores son solo sugerencias (marcas conocidas…).
                'valores'     => $valores,
                'unidades'    => $unidades,
                'unidad'      => (string)($a['default_unit'] ?? ($unidades[0] ?? '')),
                'obligatorio' => $obligatorio && $id !== 'GTIN',   // GTIN se cambia por EMPTY_GTIN_REASON
                'ayuda'       => (string)($a['hint'] ?? ($a['tooltip'] ?? '')),
                'max'         => (int)($a['value_max_length'] ?? 255) ?: 255,
            ];
        }
        // Lo obligatorio primero, y dentro, en el orden de Mercado Libre.
        uasort($r, function ($x, $y) { return (int)$y['obligatorio'] - (int)$x['obligatorio']; });
        return $r;
    }

    /** Lo que ya se sabe del producto, para no preguntarlo. */
    public static function prellenado(array $producto)
    {
        return array_filter([
            'BRAND'      => trim((string)($producto['marca'] ?? '')),
            'GTIN'       => trim((string)($producto['codigo_barras'] ?? '')),
            'SELLER_SKU' => trim((string)($producto['codigo'] ?? '')),
        ], 'strlen');
    }

    // ════════════════════════════════════════════════════════
    //  ARMAR Y PUBLICAR
    // ════════════════════════════════════════════════════════

    /**
     * Valida el formulario y arma el cuerpo de POST /items. Sin fotos:
     * esas se suben después, solo si todo lo demás está bien.
     *
     * @return array [$cuerpo, $errores] — $errores: [campo => mensaje]
     */
    public static function armar(array $f, array $atributos, $moneda = 'MXN')
    {
        $e = [];
        $titulo = trim(preg_replace('/\s+/', ' ', (string)($f['titulo'] ?? '')));
        if ($titulo === '') $e['titulo'] = 'Escribe el título.';
        elseif (mb_strlen($titulo) > self::MAX_TITULO) $e['titulo'] = 'Máximo ' . self::MAX_TITULO . ' letras (lleva ' . mb_strlen($titulo) . ').';

        $precio = round((float)str_replace([',', '$', ' '], '', (string)($f['precio'] ?? '')), 2);
        if ($precio <= 0) $e['precio'] = 'El precio tiene que ser mayor a cero.';

        $cantidad = (int)($f['cantidad'] ?? 0);
        if ($cantidad < 1) $e['cantidad'] = 'Mercado Libre pide al menos 1 pieza para publicar.';

        $condicion = ($f['condicion'] ?? 'new') === 'used' ? 'used' : 'new';
        $tipo  = isset(self::TIPOS[$f['tipo'] ?? '']) ? $f['tipo'] : 'gold_special';
        $envio = isset(self::ENVIOS[$f['envio'] ?? '']) ? $f['envio'] : 'me2';

        // ── Ficha técnica ──
        $vals = (array)($f['attr'] ?? []);
        $uni  = (array)($f['attr_unidad'] ?? []);
        $lista = [];
        foreach ($atributos as $id => $a) {
            $v = trim((string)($vals[$id] ?? ''));
            if ($v === '') {
                if ($a['obligatorio']) $e['attr_' . $id] = 'Mercado Libre lo pide en esta categoría.';
                continue;
            }
            if ($a['tipo'] === 'lista') {
                $ok = false;
                foreach ($a['valores'] as $x) if ($x['id'] === $v) { $ok = true; break; }
                if (!$ok) { $e['attr_' . $id] = 'Elige una opción de la lista.'; continue; }
                $lista[] = ['id' => $id, 'value_id' => $v];
            } elseif ($a['tipo'] === 'numero_unidad' || $a['tipo'] === 'numero') {
                $n = str_replace(',', '.', $v);
                if (!is_numeric($n) || (float)$n < 0) { $e['attr_' . $id] = 'Escribe un número.'; continue; }
                $u = (string)($uni[$id] ?? $a['unidad']);
                if ($a['tipo'] === 'numero_unidad') {
                    if ($a['unidades'] && !in_array($u, $a['unidades'], true)) $u = $a['unidad'];
                    $lista[] = ['id' => $id, 'value_name' => $n . ' ' . $u];
                } else {
                    $lista[] = ['id' => $id, 'value_name' => $n];
                }
            } else {
                $lista[] = ['id' => $id, 'value_name' => mb_substr($v, 0, $a['max'])];
            }
        }
        // GTIN: si la categoría lo exige, va el código o el motivo de no tenerlo.
        if (isset($atributos['GTIN']) && isset($atributos['EMPTY_GTIN_REASON'])
            && trim((string)($vals['GTIN'] ?? '')) === '' && trim((string)($vals['EMPTY_GTIN_REASON'] ?? '')) === '') {
            $e['attr_GTIN'] = 'Escribe el código de barras, o abajo elige por qué no tiene.';
        }

        // ── Garantía ──
        $terminos = [];
        $g = (string)($f['garantia'] ?? '');
        if (isset(self::GARANTIAS[$g]) && $g !== '') {
            $tiempo = trim((string)($f['garantia_tiempo'] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $tiempo)) $e['garantia_tiempo'] = 'Escribe cuánto dura la garantía.';
            $unidad = in_array($f['garantia_unidad'] ?? '', ['días', 'meses', 'años'], true) ? $f['garantia_unidad'] : 'días';
            $terminos[] = ['id' => 'WARRANTY_TYPE', 'value_name' => $g];
            $terminos[] = ['id' => 'WARRANTY_TIME', 'value_name' => $tiempo . ' ' . $unidad];
        }

        $cuerpo = [
            'title'              => $titulo,
            'category_id'        => (string)($f['categoria'] ?? ''),
            'price'              => $precio,
            'currency_id'        => $moneda,
            'available_quantity' => $cantidad,
            'buying_mode'        => 'buy_it_now',
            'listing_type_id'    => $tipo,
            'condition'          => $condicion,
            'attributes'         => $lista,
            'shipping'           => ['mode' => $envio, 'local_pick_up' => false,
                                     'free_shipping' => $envio === 'me2' && !empty($f['envio_gratis'])],
        ];
        if ($terminos) $cuerpo['sale_terms'] = $terminos;
        return [$cuerpo, $e];
    }

    /**
     * Sube las fotos, publica, pone la descripción y vincula.
     *
     * @param array $fotos  [['ruta' => archivo, 'mime' => tipo]] — o ids ya subidos en $f['fotos_ml']
     * @return array ['item' => respuesta, 'aviso' => texto extra]
     * @throws \InvalidArgumentException con lo que dijo Mercado Libre
     */
    public function publicar(array $producto, array $cuerpo, array $fotos, $descripcion, array $fotosYaSubidas = [])
    {
        $token = $this->token();
        $api = $this->srv->api();

        $ids = array_values(array_filter($fotosYaSubidas, function ($x) { return preg_match('/^[A-Za-z0-9_-]{4,80}$/', (string)$x); }));
        foreach ($fotos as $f) {
            if (count($ids) >= self::MAX_FOTOS) break;
            $id = $api->subirFoto($token, $f['ruta'], $f['mime']);
            if ($id === '') throw new \InvalidArgumentException('No se pudo subir una foto: ' . $api->error());
            $ids[] = $id;
        }
        if (!$ids) throw new \InvalidArgumentException('Agrega al menos una foto.');
        $cuerpo['pictures'] = array_map(function ($id) { return ['id' => $id]; }, $ids);

        $item = $api->crearPublicacion($token, $cuerpo);
        // Productos de usuario (el modelo nuevo de Mercado Libre): en
        // algunas categorías no se manda `title` sino `family_name`.
        if (!is_array($item) && stripos($api->error(), 'family_name') !== false) {
            $cuerpo['family_name'] = $cuerpo['title'];
            unset($cuerpo['title']);
            $item = $api->crearPublicacion($token, $cuerpo);
        }
        if (!is_array($item) || empty($item['id'])) {
            // Las fotos ya subidas se regresan para no subirlas otra vez al corregir.
            throw new MeliRechazo(self::traducir($api->error()), $ids);
        }

        $aviso = '';
        $descripcion = trim((string)$descripcion);
        if ($descripcion !== '' && !$api->ponerDescripcion($token, $item['id'], $descripcion)) {
            $aviso = ' La descripción no se guardó (' . $api->error() . '): agrégala en Mercado Libre.';
        }

        // Nace vinculada. Sin pendiente: la cantidad que se acaba de mandar ya es la buena.
        $repo = $this->srv->repo();
        foreach (MeliInventario::renglonesDe($item) as $r) {
            $repo->guardarPublicacion($r);
            $fila = $repo->porItem($r['item_id'], $r['variacion_id']);
            if ($fila) $repo->vincular($fila['id'], (int)$producto['id'], 'publicado', false);
        }
        return ['item' => $item, 'aviso' => $aviso, 'fotos' => $ids];
    }

    /** Lo que dice Mercado Libre, con las causas más comunes en palabras. */
    public static function traducir($error)
    {
        $t = strtolower((string)$error);
        $mapa = [
            'catalog'            => 'Esta categoría obliga a publicar en el catálogo de Mercado Libre. Termina la publicación allá, y luego dale «Traer publicaciones».',
            'shipping.mode'      => 'Tu cuenta no tiene ese tipo de envío. Prueba con «Lo acuerdo con el comprador» o activa Mercado Envíos en tu cuenta.',
            'me2'                => 'Tu cuenta no tiene Mercado Envíos activo para esto. Prueba con «Lo acuerdo con el comprador».',
            'listing_type'       => 'Ese tipo de publicación no está disponible para tu cuenta o esta categoría. Prueba con el otro.',
            'seller.unable_to_list' => 'Mercado Libre no deja publicar a esta cuenta todavía: entra a Mercado Libre y revisa si te pide completar datos.',
            'pictures'           => 'Mercado Libre no aceptó las fotos: usa JPG o PNG de al menos 500 px por lado.',
        ];
        foreach ($mapa as $clave => $txt) {
            if (strpos($t, $clave) !== false) return $txt . ' (Detalle: ' . $error . ')';
        }
        return 'Mercado Libre no aceptó la publicación: ' . $error;
    }

    private function token()
    {
        $t = $this->srv->token();
        if ($t === '') {
            throw new \InvalidArgumentException('No hay una cuenta de Mercado Libre conectada, o su autorización venció. Conéctala en Mercado Libre → Conectar.');
        }
        return $t;
    }
}
