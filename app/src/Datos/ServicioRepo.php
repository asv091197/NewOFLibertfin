<?php
namespace LibertyFin\Datos;

/** Catálogo de servicios y su desempeño. */
final class ServicioRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = ['activos' => (int)$this->valor("SELECT COUNT(*) FROM productos WHERE activo = 1")];

        $r['mas_vendido'] = $this->uno("
            SELECT p.nombre, COUNT(*) AS veces, COALESCE(SUM(vd.subtotal),0) AS ingreso
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY p.id, p.nombre ORDER BY veces DESC LIMIT 1", [$a, $b]);

        $r['area_top'] = $this->uno("
            SELECT COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   COALESCE(SUM(pg.cobrado),0) AS cobrado
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY area ORDER BY cobrado DESC LIMIT 1", [$a, $b]);

        $r['sin_ventas'] = (int)$this->valor("
            SELECT COUNT(*) FROM productos p
            WHERE p.activo = 1 AND NOT EXISTS (
                SELECT 1 FROM venta_detalles vd
                INNER JOIN ventas v ON v.id = vd.venta_id
                WHERE vd.producto_id = p.id
                  AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                  AND v.estado <> 'cancelada')");
        return $r;
    }

    public function catalogo($desde, $hasta, $buscar = '', $limite = 10, $desfase = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Solo DOS fechas: las del subquery de ventas. El WHERE exterior
        // filtra por p.activo, que no lleva marcador.
        $w = ['p.activo = 1']; $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.imagen, p.marca,
                   COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
                   cat.nombre AS categoria,
                   COALESCE(x.veces,0)   AS ventas,
                   COALESCE(x.ingreso,0) AS ingreso
            FROM productos p
            LEFT JOIN categorias cat ON cat.id = p.categoria_id
            LEFT JOIN (
                SELECT vd.producto_id, COUNT(*) veces, SUM(vd.subtotal) ingreso
                FROM venta_detalles vd
                INNER JOIN ventas v ON v.id = vd.venta_id
                WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
                GROUP BY vd.producto_id
            ) x ON x.producto_id = p.id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY ingreso DESC, p.nombre
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function masFacturan($desde, $hasta, $tope = 5)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.nombre, SUM(vd.subtotal) AS monto
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY p.id, p.nombre ORDER BY monto DESC LIMIT " . (int)$tope, [$a, $b]);
    }

    /** Cobrado por área. Cobrado, no vendido. */
    public function porArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   COALESCE(SUM(pg.cobrado),0) AS monto
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY area HAVING monto > 0 ORDER BY monto DESC", [$a, $b]);
    }

    // ─────────────────────────────────────────────────────────────
    // ALTA Y EDICION
    // ─────────────────────────────────────────────────────────────

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM productos WHERE id = ?", [(int)$id]);
    }

    public function categorias()
    {
        return $this->todos("SELECT id, nombre FROM categorias ORDER BY nombre");
    }

    private function limpiar(array $d, $idActual = null)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');

        // El SKU se puede dejar en blanco y sale solo.
        //
        // Antes era obligatorio, y el resultado previsible: para dar de
        // alta rápido la gente escribía «1», «2», «aaa». Un identificador
        // que se inventa con prisa no identifica nada, y además choca con
        // el de otro y el alta falla justo cuando hay fila en la caja.
        $codigo = mb_strtoupper(trim($d['codigo'] ?? ''));
        if ($codigo === '') $codigo = $this->sugerirSku($d['categoria_id'] ?? null);

        // Lo barato primero. Buscar duplicados es una consulta a la base;
        // rechazar un precio en cero no cuesta nada. Validar en ese orden
        // evita ir a la base para descubrir algo que ya se sabía.
        $precio = round((float)($d['precio'] ?? 0), 2);
        // Cero es válido: lo que se cotiza por caso lleva el precio en cero
        // y se fija al vender.
        if ($precio < 0) throw new \InvalidArgumentException('El precio no puede ser negativo');
        $costo = round((float)($d['costo'] ?? 0), 2);
        if ($costo < 0) throw new \InvalidArgumentException('El costo no puede ser negativo');
        if ($costo > $precio) {
            throw new \InvalidArgumentException('El costo no puede ser mayor al precio');
        }

        // El código identifica al servicio en toda la operación: repetirlo
        // hace imposible saber cuál se vendió.
        $sql = "SELECT id FROM productos WHERE codigo = ?";
        $p = [$codigo];
        if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya hay un servicio con el código ' . $codigo);
        }

        // Clave SAT: opcional, pero si viene son los 8 dígitos del catálogo.
        $sat = trim($d['clave_sat'] ?? '');
        if ($sat !== '' && !preg_match('/^\d{8}$/', $sat)) {
            throw new \InvalidArgumentException('La clave SAT son 8 dígitos');
        }

        $cat = (int)($d['categoria_id'] ?? 0) ?: null;

        // ── Código de barras ──
        // Aparte del SKU: el SKU lo pone el negocio, el código de barras
        // viene impreso del fabricante. Compartiendo campo, dar de alta un
        // producto nuevo escaneándolo chocaba con el SKU de otro.
        $barras = preg_replace('/\s+/', '', (string)($d['codigo_barras'] ?? ''));
        if ($barras !== '') {
            if (!preg_match('/^[0-9A-Za-z\-]{4,64}$/', $barras)) {
                throw new \InvalidArgumentException('El código de barras no se ve bien: solo números y letras');
            }
            $sql = "SELECT id FROM productos WHERE codigo_barras = ?";
            $p = [$barras];
            if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
            if ($this->valor($sql . " LIMIT 1", $p)) {
                throw new \InvalidArgumentException('Ya hay un producto con el código de barras ' . $barras);
            }
        }

        return [
            'codigo'      => $codigo,
            'nombre'      => $nombre,
            'descripcion' => trim($d['descripcion'] ?? '') ?: null,
            'precio'      => $precio,
            'costo'       => $costo,
            'categoria_id'=> $cat,
            // La marca ya existía en la base desde el sistema anterior,
            // pero no tenía campo en el formulario: al editar cualquier
            // producto se guardaba vacía y se perdía la que traía.
            'marca'       => mb_substr(trim((string)($d['marca'] ?? '')), 0, 100) ?: null,
            'clave_sat'   => $sat ?: null,
            'codigo_barras' => $barras ?: null,
        ] + $this->impuestos($d) + $this->caducidad($d);
    }

    /**
     * Las categorías en dos niveles, para el selector.
     *
     * Devuelve las de primer nivel con sus hijas adentro. El formulario
     * las pinta como grupos, que es como la gente las piensa: «Bebidas →
     * Refrescos», no una lista plana de cuarenta renglones.
     */
    public function categoriasEnArbol()
    {
        try {
            $todas = $this->todos("SELECT id, nombre, padre_id FROM categorias ORDER BY nombre");
        } catch (\Throwable $e) {
            // Base sin migrar: se cae a la lista plana de siempre.
            return array_map(function ($c) { return $c + ['padre_id' => null, 'hijas' => []]; },
                             $this->categorias());
        }
        $padres = []; $hijas = [];
        foreach ($todas as $c) {
            if ($c['padre_id']) $hijas[(int)$c['padre_id']][] = $c;
            else $padres[(int)$c['id']] = $c + ['hijas' => []];
        }
        foreach ($hijas as $pid => $lista) {
            // Una hija cuyo padre ya no existe no se pierde: sube a
            // primer nivel. Desaparecerla del selector haría imposible
            // sacar de ahí a los productos que la usan.
            if (isset($padres[$pid])) $padres[$pid]['hijas'] = $lista;
            else foreach ($lista as $h) $padres[(int)$h['id']] = $h + ['hijas' => []];
        }
        return array_values($padres);
    }

    /**
     * IVA e IEPS del formulario.
     *
     * La tasa vacía se guarda como NULL, que quiere decir «la de la
     * empresa» — distinto de 0, que quiere decir «tasa cero». Son dos
     * cosas distintas y la factura las trata distinto.
     */
    private function impuestos(array $d)
    {
        // Si el formulario no habló de impuestos, NO se tocan esas
        // columnas. Las casillas no se mandan cuando están apagadas, así
        // que sin esta marca un alta hecha por código —una importación,
        // la API, una prueba— dejaba `iva_aplica` en 0 y el producto
        // nacía exento sin que nadie lo decidiera. Un producto exento por
        // descuido es una factura mal timbrada, y se nota tarde.
        if (empty($d['impuestos_en_form'])) return [];

        $tasa = function ($v) {
            $v = trim((string)$v);
            if ($v === '') return null;
            $n = (float)str_replace(',', '.', $v);
            if ($n < 0 || $n > 200) throw new \InvalidArgumentException('Esa tasa de impuesto no existe');
            return round($n, 2);
        };
        $ieps = !empty($d['ieps_aplica']) ? 1 : 0;
        $t    = $tasa($d['ieps_tasa'] ?? '');
        if ($ieps && $t === null) {
            throw new \InvalidArgumentException('Si el producto lleva IEPS, dice a qué tasa');
        }
        return [
            'iva_aplica'  => !empty($d['iva_aplica']) ? 1 : 0,
            'iva_tasa'    => $tasa($d['iva_tasa'] ?? ''),
            'ieps_aplica' => $ieps,
            'ieps_tasa'   => $ieps ? $t : null,
        ];
    }

    /** Perecedero: caducidad y con cuántos días avisar. */
    private function caducidad(array $d)
    {
        // Igual que con los impuestos: sin la marca del formulario no se
        // toca la caducidad. Guardar un producto desde otra pantalla no
        // puede borrarle la fecha que alguien capturó aquí.
        if (empty($d['caducidad_en_form'])) return [];

        $es = !empty($d['es_perecedero']) ? 1 : 0;
        $f  = trim((string)($d['fecha_caducidad'] ?? ''));
        if ($f !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new \InvalidArgumentException('La fecha de caducidad no se entiende');
        }
        $dias = trim((string)($d['dias_alerta_caducidad'] ?? ''));
        if ($dias !== '' && (!ctype_digit($dias) || (int)$dias > 3650)) {
            throw new \InvalidArgumentException('Los días de aviso son un número de días');
        }
        return [
            'es_perecedero'         => $es,
            // Sin la marca, la fecha no se guarda: dejarla colgando haría
            // que el producto apareciera en los avisos de caducidad
            // después de que alguien decidió que no es perecedero.
            'fecha_caducidad'       => $es ? ($f ?: null) : null,
            'dias_alerta_caducidad' => ($es && $dias !== '') ? (int)$dias : null,
        ];
    }

    /**
     * Un SKU que no existe todavía.
     *
     * Tres letras del área y un consecutivo: PAN-0001, ABA-0002. Se
     * reintenta por si dos personas dan de alta al mismo tiempo; a la
     * décima se cae a algo aleatorio, que es feo pero único — y un alta
     * que termina con un SKU feo es mejor que una que no termina.
     */
    private function sugerirSku($categoriaId)
    {
        $pre = 'SKU';
        if ($categoriaId) {
            $n = (string)$this->valor("SELECT nombre FROM categorias WHERE id = ?", [(int)$categoriaId]);
            $n = preg_replace('/[^A-Za-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $n) ?: $n);
            if (strlen($n) >= 2) $pre = mb_strtoupper(substr($n, 0, 3));
        }
        for ($i = 0; $i < 10; $i++) {
            $ultimo = (string)$this->valor(
                "SELECT codigo FROM productos WHERE codigo LIKE ? ORDER BY LENGTH(codigo) DESC, codigo DESC LIMIT 1",
                [$pre . '-%']);
            $n = $ultimo && preg_match('/-(\d+)$/', $ultimo, $m) ? (int)$m[1] + 1 : 1;
            $try = $pre . '-' . str_pad((string)($n + $i), 4, '0', STR_PAD_LEFT);
            if (!$this->valor("SELECT id FROM productos WHERE codigo = ? LIMIT 1", [$try])) return $try;
        }
        return $pre . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    public function guardarImagen($id, $ruta)
    {
        $this->db->prepare("UPDATE productos SET imagen = ? WHERE id = ?")
                 ->execute([$ruta ?: null, (int)$id]);
        return true;
    }

    public function imagenDe($id)
    {
        return (string)$this->valor("SELECT COALESCE(imagen,'') FROM productos WHERE id = ?", [(int)$id]);
    }

    public function crear(array $d)
    {
        $c = $this->limpiar($d);
        // `subprecio` es el precio de venta y `precio` queda de respaldo:
        // se guardan iguales para que ambas lecturas den lo mismo.
        $c['subprecio'] = $c['precio'];
        $cols = array_keys($c);
        $this->db->prepare("
            INSERT INTO productos (`" . implode('`,`', $cols) . "`, stock, stock_minimo, activo)
            VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ", 0, 0, 1)
        ")->execute(array_values($c));
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        $c = $this->limpiar($d, $id);
        $c['subprecio'] = $c['precio'];
        $sets = [];
        foreach (array_keys($c) as $k) $sets[] = "`$k` = ?";
        $this->db->prepare("
            UPDATE productos SET " . implode(', ', $sets) . ", fecha_actualizacion = NOW()
            WHERE id = ?
        ")->execute(array_merge(array_values($c), [(int)$id]));
        return (int)$id;
    }

    /** El SKU que se le propondría a un producto nuevo de esta área. */
    public function skuSugerido($categoriaId = null) { return $this->sugerirSku($categoriaId); }

    /**
     * Activa o desactiva. No se borra nunca: un servicio borrado deja
     * ventas apuntando a un producto que ya no existe.
     */
    public function alternar($id)
    {
        $this->db->prepare("UPDATE productos SET activo = 1 - activo WHERE id = ?")
                 ->execute([(int)$id]);
        return (int)$this->valor("SELECT activo FROM productos WHERE id = ?", [(int)$id]);
    }

    public function cuantos($buscar = '')
    {
        $w = ['p.activo = 1']; $p = [];
        if ($buscar !== '') { $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
                              $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return (int)$this->valor("SELECT COUNT(*) FROM productos p WHERE " . implode(' AND ', $w), $p);
    }

    /** Un servicio por su id. */
    public function porId($id)
    {
        return $this->uno("
            SELECT p.*, COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
                   c.nombre AS categoria
            FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.id = ?", [(int)$id]);
    }
}