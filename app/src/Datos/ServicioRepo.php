<?php
namespace LibertyFin\Datos;

/** Catálogo de servicios y su desempeño. */
final class ServicioRepo extends Repo
{
    /**
     * Los tres estados de un producto.
     *
     *   activo    se vende
     *   agotado   sigue en el catálogo, pero no hay: se marca a mano, o
     *             sale solo si lleva existencias y están en cero
     *   inactivo  apagado: no sale en Caja. Solo uno inactivo se puede borrar.
     */
    const ESTADOS = ['activo' => 'Activo', 'agotado' => 'Agotado', 'inactivo' => 'Inactivo'];

    /** Lo que se puede pedir en el filtro de estado. '' = activos y agotados. */
    const FILTRO_ESTADO = ['' => 'Activos y agotados', 'activo' => 'Solo activos', 'agotado' => 'Agotados',
                           'inactivo' => 'Inactivos', 'todos' => 'Todos'];

    /** Cuántos atributos o etiquetas puede tener un producto. */
    const MAX_ATRIBUTOS = 30;

    private static $v35 = null;

    /**
     * ¿La base ya tiene lo de la versión 35 (agotado, borrado, atributos)?
     *
     * Productos tiene que abrir aunque la migración no haya corrido: sin
     * esto, una base atrasada dejaba la pantalla en blanco por una columna.
     */
    private function hayV35()
    {
        if (self::$v35 === null) {
            try {
                self::$v35 = (int)$this->valor("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'eliminado_en'") > 0;
            } catch (\Throwable $e) {
                self::$v35 = false;
            }
        }
        return self::$v35;
    }

    /**
     * El estado de cada producto, en SQL.
     *
     * Agotado es lo marcado a mano o, con Inventario encendido, lo que
     * lleva existencias y ya no tiene. Calcularlo y no guardarlo hace que
     * un producto deje de estar agotado en cuanto entra mercancía, sin que
     * nadie tenga que acordarse de desmarcarlo.
     *
     * Lo que está en PREVENTA no se agota por estar en cero: se vende justo
     * porque todavía no llega. (La columna `preventa` es de la versión 34;
     * con la 35 aplicada, ya existe.)
     */
    private function estadoSql($conInventario)
    {
        $agot = [];
        if ($this->hayV35()) $agot[] = 'COALESCE(p.agotado,0) = 1';
        if ($conInventario)  $agot[] = '(COALESCE(p.lleva_inventario,0) = 1 AND COALESCE(p.stock,0) <= 0'
                                     . ($this->hayV35() ? ' AND COALESCE(p.preventa,0) = 0' : '') . ')';
        return "CASE WHEN COALESCE(p.activo,1) = 0 THEN 'inactivo'"
             . ($agot ? " WHEN " . implode(' OR ', $agot) . " THEN 'agotado'" : '')
             . " ELSE 'activo' END";
    }

    /** El WHERE del catálogo: estado, búsqueda y marca, área y tipo. */
    private function filtrosCatalogo($buscar, array $f)
    {
        $w = []; $p = [];
        if ($this->hayV35()) $w[] = 'p.eliminado_en IS NULL';
        $est = (string)($f['estado'] ?? '');
        $expr = $this->estadoSql(!empty($f['inventario']));
        if ($est === '')               $w[] = 'COALESCE(p.activo,1) = 1';
        elseif ($est !== 'todos')      { $w[] = "($expr) = ?"; $p[] = $est; }
        if ($buscar !== '') {
            $l = '%' . $buscar . '%';
            $o = ['p.nombre LIKE ?', 'p.codigo LIKE ?', 'p.codigo_barras LIKE ?'];
            array_push($p, $l, $l, $l);
            // También por sus etiquetas: buscar «rojo» encuentra lo que
            // tiene el atributo Color: Rojo.
            if ($this->hayV35()) {
                $o[] = 'EXISTS (SELECT 1 FROM lf_producto_atributos pa
                                 WHERE pa.producto_id = p.id AND (pa.nombre LIKE ? OR pa.valor LIKE ?))';
                array_push($p, $l, $l);
            }
            $w[] = '(' . implode(' OR ', $o) . ')';
        }
        list($wf, $pf) = \LibertyFin\Servicio\FiltrosProducto::sql($f, 'p');
        return [array_merge($w, $wf) ?: ['1 = 1'], array_merge($p, $pf)];
    }

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

    /**
     * @param array $f  estado ('' activos y agotados, 'activo', 'agotado',
     *                  'inactivo', 'todos'), inventario (bool: Inventario
     *                  encendido) y marca, área y tipo (ver FiltrosProducto)
     */
    public function catalogo($desde, $hasta, $buscar = '', $limite = 10, $desfase = 0, array $f = [])
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Las DOS fechas del subquery de ventas van primero; detrás, las
        // del WHERE exterior.
        list($w, $pw) = $this->filtrosCatalogo($buscar, $f);
        $p = array_merge([$a, $b], $pw);
        $estado = $this->estadoSql(!empty($f['inventario']));
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.imagen, p.marca,
                   COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
                   COALESCE(NULLIF(p.tipo_producto,''),'Estandar') AS tipo_producto,
                   COALESCE(p.lleva_inventario,0) AS lleva_inventario, COALESCE(p.stock,0) AS stock,
                   $estado AS estado,
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
        ] + $this->impuestos($d) + $this->caducidad($d) + $this->tipo($d);
    }

    /**
     * El tipo de producto (`tipo_producto`): «Estándar», «Servicio»,
     * «Granel»… Es por lo que filtran Productos, Existencias y Conteo.
     * Ya existía en la base del sistema anterior; solo le faltaba campo.
     * Si el formulario no lo trae, no se toca.
     */
    private function tipo(array $d)
    {
        if (!array_key_exists('tipo_producto', $d)) return [];
        $t = mb_substr(trim((string)$d['tipo_producto']), 0, 50);
        if ($t === '' || strcasecmp($t, 'Estándar') === 0) $t = 'Estandar';
        return ['tipo_producto' => $t];
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

    public function cuantos($buscar = '', array $f = [])
    {
        list($w, $p) = $this->filtrosCatalogo($buscar, $f);
        return (int)$this->valor("SELECT COUNT(*) FROM productos p WHERE " . implode(' AND ', $w), $p);
    }

    /**
     * Cuántos hay en cada estado (sin contar los borrados), para las
     * pastillas del filtro. Respeta la búsqueda y la marca/área/tipo.
     *
     * @return array ['activo' => n, 'agotado' => n, 'inactivo' => n]
     */
    public function porEstado($buscar = '', array $f = [])
    {
        list($w, $p) = $this->filtrosCatalogo($buscar, ['estado' => 'todos'] + $f);
        $expr = $this->estadoSql(!empty($f['inventario']));
        $r = ['activo' => 0, 'agotado' => 0, 'inactivo' => 0];
        foreach ($this->todos("SELECT $expr AS e, COUNT(*) AS n FROM productos p
                               WHERE " . implode(' AND ', $w) . " GROUP BY e", $p) as $x) {
            $r[$x['e']] = (int)$x['n'];
        }
        return $r;
    }

    // ─────────────────────────────────────────────────────────────
    // ESTADO, DUPLICAR Y BORRAR
    // ─────────────────────────────────────────────────────────────

    /**
     * Pone el estado: activo, agotado o inactivo.
     *
     * Agotado a mano es para lo que no lleva existencias —el platillo del
     * día que se acabó—; lo que sí las lleva se agota solo en cero. Al
     * volver a activo se quita la marca.
     */
    public function cambiarEstado($id, $estado)
    {
        if (!isset(self::ESTADOS[$estado])) throw new \InvalidArgumentException('Ese estado no existe');
        $activo = $estado === 'inactivo' ? 0 : 1;
        if ($this->hayV35()) {
            $this->db->prepare("UPDATE productos SET activo = ?, agotado = ? WHERE id = ?")
                     ->execute([$activo, $estado === 'agotado' ? 1 : 0, (int)$id]);
        } else {
            if ($estado === 'agotado') {
                throw new \InvalidArgumentException('Para marcar agotado falta poner la base al día (Mantenimiento)');
            }
            $this->db->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$activo, (int)$id]);
        }
        return $estado;
    }

    /** El estado de un producto, como lo lee la pantalla. */
    public function estadoDe($id, $conInventario = false)
    {
        return (string)$this->valor("SELECT " . $this->estadoSql($conInventario) . " FROM productos p WHERE p.id = ?",
                                    [(int)$id]) ?: 'activo';
    }

    /**
     * Una copia del producto, lista para editar.
     *
     * Se copia todo lo que lo describe —precio, costo, área, marca,
     * impuestos, unidad, etiquetas, mínimos por sucursal— y NADA de lo que
     * es suyo: ni SKU ni código de barras (son únicos), ni existencias, ni
     * ventas, ni sus publicaciones en tiendas en línea. La imagen se copia
     * como archivo aparte, para que cambiarle la foto a uno no se la quite
     * al otro.
     *
     * @return int el id de la copia
     */
    public function duplicar($id)
    {
        $o = $this->uno("SELECT * FROM productos WHERE id = ?", [(int)$id]);
        if (!$o) throw new \InvalidArgumentException('Ese producto no existe');

        $fuera = ['id', 'codigo', 'codigo_barras', 'stock', 'fecha_creacion', 'fecha_actualizacion',
                  'facturapi_producto_id', 'eliminado_en', 'agotado', 'imagen'];
        $c = array_diff_key($o, array_flip($fuera));
        $c['codigo']  = $this->sugerirSku($o['categoria_id'] ?? null);
        $c['nombre']  = mb_substr(trim($o['nombre']) . ' (copia)', 0, 255);
        $c['activo']  = 1;
        $c['stock']   = 0;
        $img = \LibertyFin\Servicio\Archivos::copiar($o['imagen'] ?? '', 'serv');
        if ($img) $c['imagen'] = $img;

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $cols = array_keys($c);
            $this->db->prepare("INSERT INTO productos (`" . implode('`,`', $cols) . "`)
                                VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
                     ->execute(array_values($c));
            $nuevo = (int)$this->db->lastInsertId();

            // Los mínimos por sucursal, sin existencia: la copia nace en cero.
            try {
                $this->db->prepare("INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo, activo)
                                    SELECT ?, sucursal_id, 0, stock_minimo, activo FROM producto_sucursal
                                     WHERE producto_id = ?")->execute([$nuevo, (int)$id]);
            } catch (\Throwable $e) { /* sin la tabla, sin mínimos */ }

            if ($this->hayV35()) {
                $this->db->prepare("INSERT INTO lf_producto_atributos (producto_id, nombre, valor, color, orden)
                                    SELECT ?, nombre, valor, color, orden FROM lf_producto_atributos
                                     WHERE producto_id = ?")->execute([$nuevo, (int)$id]);
            }
            if ($propia) $this->db->commit();
            return $nuevo;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            if ($img) \LibertyFin\Servicio\Archivos::borrar($img);
            throw $e;
        }
    }

    /**
     * Borra un producto. SOLO si está inactivo.
     *
     * No se quita la fila: las ventas, los movimientos de inventario y las
     * compras viejas la siguen leyendo, y una venta que apunta a un
     * producto que no existe pierde su renglón. Se marca como borrado y
     * desaparece de todas las listas.
     *
     * Su SKU y su código de barras se liberan (el SKU se renombra): sin
     * eso, dar de alta otra vez «PAN-0001» chocaba con un producto que ya
     * nadie ve.
     */
    public function borrar($id)
    {
        if (!$this->hayV35()) {
            throw new \InvalidArgumentException('Para borrar productos falta poner la base al día (Mantenimiento)');
        }
        $p = $this->uno("SELECT id, codigo, nombre, activo, eliminado_en FROM productos WHERE id = ?", [(int)$id]);
        if (!$p || $p['eliminado_en']) throw new \InvalidArgumentException('Ese producto no existe');
        if ((int)$p['activo'] === 1) {
            throw new \InvalidArgumentException('Solo se puede borrar un producto inactivo. Desactívalo primero.');
        }
        $codigo = mb_substr('BORRADO-' . (int)$p['id'] . '-' . $p['codigo'], 0, 50);
        $this->db->prepare("UPDATE productos SET eliminado_en = NOW(), activo = 0, agotado = 0,
                                   codigo = ?, codigo_barras = NULL WHERE id = ?")
                 ->execute([$codigo, (int)$id]);
        return $p;
    }

    // ─────────────────────────────────────────────────────────────
    // ETIQUETAS Y ATRIBUTOS
    // ─────────────────────────────────────────────────────────────

    /** Las etiquetas y atributos de un producto, en orden. */
    public function atributos($id)
    {
        $r = $this->atributosDe([(int)$id]);
        return $r[(int)$id] ?? [];
    }

    /** @return array [producto_id => [['nombre','valor','color'], …]] */
    public function atributosDe(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->hayV35()) return [];
        $r = [];
        foreach ($this->todos("SELECT producto_id, nombre, valor, color FROM lf_producto_atributos
                                WHERE producto_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                                ORDER BY producto_id, orden, id", $ids) as $a) {
            $r[(int)$a['producto_id']][] = $a;
        }
        return $r;
    }

    /**
     * Guarda las etiquetas y atributos del formulario.
     *
     * Cada renglón es un nombre y, si se quiere, un valor y un color:
     *   «Nuevo»                       una etiqueta sola
     *   «Talla» · «M»                 un atributo de texto
     *   «Color» · «Rojo» · #d93025    un atributo con su color
     *
     * Se reemplazan todos de una vez. Solo si el formulario habló de ellos
     * (`atributos_en_form`): guardar un producto desde otra pantalla no
     * puede borrarle las etiquetas.
     */
    public function guardarAtributos($id, array $d)
    {
        if (empty($d['atributos_en_form']) || !$this->hayV35()) return 0;
        $nom = (array)($d['atr_nombre'] ?? []);
        $val = (array)($d['atr_valor'] ?? []);
        $col = (array)($d['atr_color'] ?? []);
        $usa = (array)($d['atr_usa_color'] ?? []);
        $filas = [];
        foreach ($nom as $i => $n) {
            $n = mb_substr(trim((string)$n), 0, 60);
            $v = mb_substr(trim((string)($val[$i] ?? '')), 0, 120);
            if ($n === '' && $v === '') continue;
            if ($n === '') { $n = $v; $v = ''; }     // solo escribieron el valor: es una etiqueta
            $c = strtolower(trim((string)($col[$i] ?? '')));
            $c = (!empty($usa[$i]) && preg_match('/^#[0-9a-f]{6}$/', $c)) ? $c : null;
            $filas[] = [$n, $v !== '' ? $v : null, $c];
            if (count($filas) >= self::MAX_ATRIBUTOS) break;
        }
        $this->db->prepare("DELETE FROM lf_producto_atributos WHERE producto_id = ?")->execute([(int)$id]);
        $ins = $this->db->prepare("INSERT INTO lf_producto_atributos (producto_id, nombre, valor, color, orden)
                                   VALUES (?,?,?,?,?)");
        foreach ($filas as $k => $f) $ins->execute([(int)$id, $f[0], $f[1], $f[2], $k]);
        return count($filas);
    }

    /** Los nombres de atributo que ya se usan, para sugerirlos al escribir. */
    public function nombresDeAtributos()
    {
        if (!$this->hayV35()) return [];
        try {
            return array_column($this->todos("SELECT nombre, COUNT(*) n FROM lf_producto_atributos
                                               GROUP BY nombre ORDER BY n DESC, nombre LIMIT 60"), 'nombre');
        } catch (\Throwable $e) {
            return [];
        }
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