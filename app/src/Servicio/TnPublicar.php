<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Publicar un producto de LibertyFin en la tienda de Tienda Nube.
 *
 * Mucho más simple que Mercado Libre: no hay categoría obligatoria ni ficha
 * técnica. Se manda nombre, descripción, precio, existencia, SKU, código
 * de barras, marca y fotos, y el producto nace vinculado.
 */
final class TnPublicar
{
    const MAX_FOTOS  = 9;
    const MAX_NOMBRE = 120;

    private $db;
    private $srv;

    /** @param TnInventario|null $srv */
    public function __construct(PDO $db, $srv = null)
    {
        $this->db  = $db;
        $this->srv = $srv ?: new TnInventario($db);
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
        $st = $this->db->prepare("SELECT * FROM lf_tn_productos
                                   WHERE producto_id = ? AND estado_tn <> 'ausente' ORDER BY id LIMIT 1");
        $st->execute([(int)$productoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Valida el formulario y arma el cuerpo de POST /products.
     * @return array [$cuerpo, $errores]
     */
    public static function armar(array $f, $idioma = 'es')
    {
        $e = [];
        $nombre = trim(preg_replace('/\s+/', ' ', (string)($f['nombre'] ?? '')));
        if ($nombre === '') $e['nombre'] = 'Escribe el nombre.';
        elseif (mb_strlen($nombre) > self::MAX_NOMBRE) $e['nombre'] = 'Máximo ' . self::MAX_NOMBRE . ' letras.';

        $num = function ($k) use ($f) { return round((float)str_replace([',', '$', ' '], '', (string)($f[$k] ?? '')), 2); };
        $precio = $num('precio');
        if ($precio <= 0) $e['precio'] = 'El precio tiene que ser mayor a cero.';
        $promo = $num('precio_promocional');
        if ($promo < 0 || ($promo > 0 && $promo >= $precio)) $e['precio_promocional'] = 'Tiene que ser menor que el precio, o déjalo vacío.';

        $cantidad = (int)($f['cantidad'] ?? 0);
        if ($cantidad < 0) $e['cantidad'] = 'La cantidad no puede ser negativa.';

        $peso = trim((string)($f['peso'] ?? ''));
        if ($peso !== '' && (!is_numeric(str_replace(',', '.', $peso)) || (float)str_replace(',', '.', $peso) < 0)) {
            $e['peso'] = 'Escribe el peso en kilos, por ejemplo 0.5.';
        }

        $variante = [
            'price' => number_format($precio, 2, '.', ''),
            'stock' => max(0, $cantidad),
        ];
        if ($promo > 0) $variante['promotional_price'] = number_format($promo, 2, '.', '');
        $sku = trim((string)($f['sku'] ?? ''));
        if ($sku !== '') $variante['sku'] = mb_substr($sku, 0, 100);
        $barras = preg_replace('/\s+/', '', (string)($f['codigo_barras'] ?? ''));
        if ($barras !== '') $variante['barcode'] = mb_substr($barras, 0, 48);
        if ($peso !== '') $variante['weight'] = number_format((float)str_replace(',', '.', $peso), 3, '.', '');

        $cuerpo = [
            'name'      => [$idioma => $nombre],
            'published' => !empty($f['publicado']),
            'variants'  => [$variante],
        ];
        $desc = trim((string)($f['descripcion'] ?? ''));
        if ($desc !== '') $cuerpo['description'] = [$idioma => self::html($desc)];
        $marca = trim((string)($f['marca'] ?? ''));
        if ($marca !== '') $cuerpo['brand'] = mb_substr($marca, 0, 100);

        return [$cuerpo, $e];
    }

    /** La descripción es HTML en Tienda Nube: el texto se escapa y cada párrafo va en <p>. */
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
     * Crea el producto, sube las fotos y lo deja vinculado.
     * @param array $fotos [['ruta','mime']]
     * @return array ['producto' => respuesta, 'aviso' => texto extra]
     */
    public function publicar(array $producto, array $cuerpo, array $fotos)
    {
        $token = $this->srv->token();
        if ($token === '') throw new \InvalidArgumentException('No hay una tienda de Tienda Nube conectada. Conéctala en Tienda Nube → Conectar.');
        $api = $this->srv->api();
        $tienda = $this->srv->tiendaId();

        $p = $api->crearProducto($token, $tienda, $cuerpo);
        if (!is_array($p) || empty($p['id'])) {
            throw new \InvalidArgumentException('Tienda Nube no aceptó el producto: ' . $api->error());
        }

        // Las fotos van después: si una falla, el producto ya existe y se avisa.
        $aviso = '';
        $fallas = 0;
        foreach (array_slice($fotos, 0, self::MAX_FOTOS) as $i => $f) {
            $im = $api->subirFoto($token, $tienda, $p['id'], $f['ruta'], $f['mime'], $i + 1);
            if (is_array($im)) { if ($i === 0 && !empty($im['src'])) $p['images'][] = $im; }
            else $fallas++;
        }
        if ($fallas) $aviso = ' ' . $fallas . ' foto(s) no se subieron (' . $api->error() . '): agrégalas en Tienda Nube.';

        // Nace vinculado. Sin pendiente: la cantidad que se acaba de mandar ya es la buena.
        $repo = $this->srv->repo();
        foreach (TnInventario::renglonesDe($p, $this->srv->idioma()) as $r) {
            $repo->guardarVariante($r);
            $fila = $repo->porVariante($r['variante_tn']);
            if ($fila) $repo->vincular($fila['id'], (int)$producto['id'], 'publicado', false);
        }
        return ['producto' => $p, 'aviso' => $aviso];
    }
}
