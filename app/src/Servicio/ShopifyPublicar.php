<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Publicar un producto de LibertyFin en la tienda de Shopify.
 *
 * Igual de simple que Tienda Nube (sin categoría obligatoria), pero en
 * pasos porque así es la API de Shopify:
 *   1. productCreate: nombre, descripción, marca, estado → nace con UNA variante
 *   2. productVariantsBulkUpdate: precio, precio de comparación, SKU, código de barras, peso
 *   3. la existencia en la ubicación elegida
 *   4. las fotos
 * Y el producto nace vinculado.
 */
final class ShopifyPublicar
{
    const MAX_FOTOS  = 10;
    const MAX_NOMBRE = 255;

    private $db;
    private $srv;

    /** @param ShopifyInventario|null $srv */
    public function __construct(PDO $db, $srv = null)
    {
        $this->db  = $db;
        $this->srv = $srv ?: new ShopifyInventario($db);
    }

    public function srv() { return $this->srv; }

    /** @throws \InvalidArgumentException */
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

    public function yaPublicado($productoId)
    {
        $st = $this->db->prepare("SELECT * FROM lf_shop_productos
                                   WHERE producto_id = ? AND estado_shop <> 'ausente' ORDER BY id LIMIT 1");
        $st->execute([(int)$productoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Valida el formulario y arma lo que se manda.
     * @return array [['producto' => ProductCreateInput, 'variante' => ProductVariantsBulkInput, 'cantidad' => n], $errores]
     */
    public static function armar(array $f)
    {
        $e = [];
        $nombre = trim(preg_replace('/\s+/', ' ', (string)($f['nombre'] ?? '')));
        if ($nombre === '') $e['nombre'] = 'Escribe el nombre.';
        elseif (mb_strlen($nombre) > self::MAX_NOMBRE) $e['nombre'] = 'Máximo ' . self::MAX_NOMBRE . ' letras.';

        $num = function ($k) use ($f) { return round((float)str_replace([',', '$', ' '], '', (string)($f[$k] ?? '')), 2); };
        $precio = $num('precio');
        if ($precio <= 0) $e['precio'] = 'El precio tiene que ser mayor a cero.';
        // En Shopify la oferta se escribe al revés: `price` es lo que se cobra
        // y `compareAtPrice` el precio tachado. El formulario pide «precio» y
        // «precio de oferta» como en Tienda Nube, y aquí se acomodan.
        $promo = $num('precio_promocional');
        if ($promo < 0 || ($promo > 0 && $promo >= $precio)) $e['precio_promocional'] = 'Tiene que ser menor que el precio, o déjalo vacío.';

        $cantidad = (int)($f['cantidad'] ?? 0);
        if ($cantidad < 0) $e['cantidad'] = 'La cantidad no puede ser negativa.';

        $peso = trim((string)($f['peso'] ?? ''));
        if ($peso !== '' && (!is_numeric(str_replace(',', '.', $peso)) || (float)str_replace(',', '.', $peso) < 0)) {
            $e['peso'] = 'Escribe el peso en kilos, por ejemplo 0.5.';
        }

        $item = ['tracked' => true];
        $sku = trim((string)($f['sku'] ?? ''));
        if ($sku !== '') $item['sku'] = mb_substr($sku, 0, 100);
        if ($peso !== '') {
            $item['measurement'] = ['weight' => ['unit' => 'KILOGRAMS', 'value' => (float)str_replace(',', '.', $peso)]];
        }
        $variante = [
            'price'         => number_format($promo > 0 ? $promo : $precio, 2, '.', ''),
            'inventoryItem' => $item,
        ];
        if ($promo > 0) $variante['compareAtPrice'] = number_format($precio, 2, '.', '');
        $barras = preg_replace('/\s+/', '', (string)($f['codigo_barras'] ?? ''));
        if ($barras !== '') $variante['barcode'] = mb_substr($barras, 0, 48);

        $producto = [
            'title'  => $nombre,
            'status' => !empty($f['publicado']) ? 'ACTIVE' : 'DRAFT',
        ];
        $desc = trim((string)($f['descripcion'] ?? ''));
        if ($desc !== '') $producto['descriptionHtml'] = self::html($desc);
        $marca = trim((string)($f['marca'] ?? ''));
        if ($marca !== '') $producto['vendor'] = mb_substr($marca, 0, 100);

        return [['producto' => $producto, 'variante' => $variante, 'cantidad' => max(0, $cantidad)], $e];
    }

    /** La descripción es HTML: el texto se escapa y cada párrafo va en <p>. */
    private static function html($texto)
    {
        $parrafos = preg_split('/\n\s*\n/', str_replace("\r", '', $texto));
        $html = '';
        foreach ($parrafos as $p) {
            $p = trim($p);
            if ($p !== '') $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return $html;
    }

    /**
     * Crea el producto, le pone precio y existencia, sube las fotos y lo deja vinculado.
     * @param array $fotos [['ruta','mime']]
     * @return array ['producto' => ['id','permalink',...], 'aviso' => texto extra]
     */
    public function publicar(array $producto, array $datos, array $fotos)
    {
        $token = $this->srv->token();
        if ($token === '') throw new \InvalidArgumentException('No hay una tienda de Shopify conectada. Conéctala en Shopify → Conectar.');
        $ubicacion = $this->srv->ubicacion();
        if ($ubicacion === '') throw new \InvalidArgumentException('Elige primero en Shopify → Ajustes la ubicación que surte tu tienda.');
        $api = $this->srv->api();
        $tienda = $this->srv->tiendaId();

        $p = $api->crearProducto($token, $tienda, $datos['producto']);
        if (!$p) throw new \InvalidArgumentException('Shopify no aceptó el producto: ' . $api->error());

        // Lo que falle de aquí en adelante no deshace el producto: se avisa.
        $avisos = [];
        if ($datos['producto']['status'] === 'ACTIVE' && !$api->publicarEnTiendaOnline($token, $tienda, $p['id'])) {
            $avisos[] = 'No se publicó en el canal Tienda online (' . $api->error() . '): márcalo en Shopify → producto → Canales de ventas.';
        }
        if (!$api->actualizarVariante($token, $tienda, $p['id'], $p['variante'], $datos['variante'])) {
            $avisos[] = 'El precio o el SKU no se guardaron (' . $api->error() . '): corrígelos en Shopify.';
        }
        $stockOk = $p['inventario'] !== ''
            && $api->ponerExistencia($token, $tienda, $p['inventario'], $ubicacion, $datos['cantidad'], true);
        if (!$stockOk) $avisos[] = 'La existencia no se mandó (' . $api->error() . '): sale en la siguiente sincronización.';

        $fallas = 0;
        foreach (array_slice($fotos, 0, self::MAX_FOTOS) as $i => $f) {
            if (!$api->subirFoto($token, $tienda, $p['id'], $f['ruta'], $f['mime'], $i + 1)) $fallas++;
        }
        if ($fallas) $avisos[] = $fallas . ' foto(s) no se subieron (' . $api->error() . '): agrégalas en Shopify.';

        // Nace vinculado. Pendiente solo si la existencia no se pudo mandar.
        $repo = $this->srv->repo();
        $vs = $api->productoVariantes($token, $tienda, $p['id'], $ubicacion);
        foreach ((array)$vs as $v) {
            $r = ShopifyInventario::renglonDe($v);
            if (!$r) continue;
            $repo->guardarVariante($r);
            $fila = $repo->porVariante($r['variante_shop']);
            if ($fila) $repo->vincular($fila['id'], (int)$producto['id'], 'publicado', !$stockOk);
            if (!empty($r['permalink'])) $p['permalink'] = $r['permalink'];
        }
        if (!$vs) {
            // No se pudo releer: se guarda lo que ya se sabe para que quede vinculado igual.
            $repo->guardarVariante([
                'producto_shop' => $p['id'], 'variante_shop' => $p['variante'], 'inventario_shop' => $p['inventario'],
                'titulo' => $datos['producto']['title'], 'variante' => '', 'sku' => (string)($datos['variante']['inventoryItem']['sku'] ?? ''),
                'estado_shop' => $datos['producto']['status'] === 'ACTIVE' ? 'visible' : 'oculto',
                'permalink' => $p['permalink'], 'imagen' => '', 'precio' => (float)$datos['variante']['price'],
                'stock_shop' => $stockOk ? $datos['cantidad'] : null,
            ]);
            $fila = $repo->porVariante($p['variante']);
            if ($fila) $repo->vincular($fila['id'], (int)$producto['id'], 'publicado', !$stockOk);
        }
        return ['producto' => $p, 'aviso' => $avisos ? ' ' . implode(' ', $avisos) : ''];
    }
}
