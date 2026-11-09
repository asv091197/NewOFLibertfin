<?php
namespace LibertyFin\Datos;

/**
 * El catálogo de Emida, guardado en la base de la empresa.
 *
 * POR QUÉ SE GUARDA Y NO SE PIDE CADA VEZ
 *
 * Son más de mil productos. Pedirlos al proveedor en cada carga de la
 * pantalla de recargas la haría tardar segundos, dependería de que su
 * servidor conteste, y no vendería nada si no contesta.
 *
 * Así que se baja una vez, se guarda, y se refresca cuando alguien lo
 * pide. Los precios de Emida cambian poco; lo que cambia es qué
 * productos están disponibles, y eso tampoco a cada minuto.
 *
 * SOBRE LA IMAGEN
 *
 * Emida manda la URL de la imagen dentro de <Reference1><URLImage>, y
 * muchas veces manda "NA". Dropbox con ?dl=0 no carga dentro de un <img>;
 * se convierte a ?raw=1 en normalizarImagen().
 */
final class EmidaRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_emida_productos (
                    producto_id VARCHAR(30) NOT NULL PRIMARY KEY,
                    nombre VARCHAR(220) NOT NULL,
                    categoria VARCHAR(80) NULL,
                    carrier VARCHAR(80) NULL,
                    comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                    monto DECIMAL(12,2) NOT NULL DEFAULT 0,
                    monto_min DECIMAL(12,2) NOT NULL DEFAULT 0,
                    monto_max DECIMAL(12,2) NOT NULL DEFAULT 0,
                    tipo VARCHAR(12) NOT NULL DEFAULT 'directa',
                    imagen VARCHAR(500) NULL,
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    actualizado DATETIME NULL,
                    KEY ix_ep_cat (categoria),
                    KEY ix_ep_carrier (carrier),
                    KEY ix_ep_activo (activo, categoria)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Migración suave: bases creadas antes de que existiera la
            // columna. Si ya está, el ALTER falla y se ignora.
            try {
                $this->db->exec("ALTER TABLE lf_emida_productos
                                 ADD COLUMN imagen VARCHAR(500) NULL AFTER tipo");
            } catch (\Throwable $e) { /* ya existe */ }

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_emida_transacciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sales_id VARCHAR(30) NOT NULL,
                    producto_id VARCHAR(30) NOT NULL,
                    producto_nombre VARCHAR(220) NULL,
                    cuenta VARCHAR(40) NOT NULL,
                    monto DECIMAL(12,2) NOT NULL,
                    comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                    folio_proveedor VARCHAR(80) NULL,
                    codigo VARCHAR(10) NULL,
                    h2h VARCHAR(10) NULL,
                    mensaje VARCHAR(300) NULL,
                    venta_id INT NULL,
                    usuario_id INT NULL,
                    usuario_nombre VARCHAR(160) NULL,
                    creado_en DATETIME NOT NULL,
                    UNIQUE KEY ix_et_sales (sales_id),
                    KEY ix_et_fecha (creado_en),
                    KEY ix_et_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tablas emida: ' . $e->getMessage());
        }
    }

    /**
     * Guarda el catálogo recibido.
     *
     * Nunca borra. Con $reemplazar, marca todo como inactivo y revive lo
     * que llegó: un producto que el proveedor dejó de ofrecer deja de
     * venderse pero no desaparece, y las transacciones viejas siguen
     * sabiendo qué se vendió.
     *
     * Sin $reemplazar (lo que usa la descarga automática) solo agrega y
     * actualiza. El catálogo que entrega la conexión con Emida puede traer
     * menos productos que el del portal: reemplazar con él apagaba todos
     * los demás.
     */
    public function guardarCatalogo(array $productos, $reemplazar = true)
    {
        $this->asegurar();
        if (!$productos) return 0;

        $this->db->beginTransaction();
        try {
            if ($reemplazar) $this->db->exec("UPDATE lf_emida_productos SET activo = 0");
            $st = $this->db->prepare("
                INSERT INTO lf_emida_productos
                    (producto_id, nombre, categoria, carrier, comision, monto,
                     monto_min, monto_max, tipo, imagen, activo, actualizado)
                VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())
                ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), categoria=VALUES(categoria), carrier=VALUES(carrier),
                    comision=VALUES(comision), monto=VALUES(monto), monto_min=VALUES(monto_min),
                    monto_max=VALUES(monto_max), tipo=VALUES(tipo), imagen=VALUES(imagen),
                    activo=1, actualizado=NOW()");
            $n = 0;
            foreach ($productos as $p) {
                $st->execute([
                    $p['producto_id'], mb_substr($p['nombre'], 0, 220),
                    mb_substr((string)$p['categoria'], 0, 80), mb_substr((string)$p['carrier'], 0, 80),
                    $p['comision'], $p['monto'], $p['monto_min'], $p['monto_max'], $p['tipo'],
                    mb_substr((string)($p['imagen'] ?? ''), 0, 500),
                ]);
                $n++;
            }
            $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function productos($buscar = '', $categoria = '', $carrier = '', $limite = 60, $desfase = 0)
    {
        $this->asegurar();
        list($w, $p) = $this->filtro($buscar, $categoria, $carrier);
        return $this->todos("SELECT * FROM lf_emida_productos WHERE " . implode(' AND ', $w)
            . " ORDER BY categoria, carrier, monto
               LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantos($buscar = '', $categoria = '', $carrier = '')
    {
        $this->asegurar();
        list($w, $p) = $this->filtro($buscar, $categoria, $carrier);
        return (int)$this->valor("SELECT COUNT(*) FROM lf_emida_productos WHERE "
            . implode(' AND ', $w), $p);
    }

    /**
     * El WHERE de la búsqueda. Por palabras: «telcel 100» encuentra
     * «Recarga Telcel $100» (cada palabra en el nombre, la compañía o la
     * categoría). El identificador, escrito completo, también.
     */
    private function filtro($buscar, $categoria, $carrier)
    {
        $w = ['activo = 1']; $p = [];
        $buscar = trim((string)$buscar);
        if ($buscar !== '') {
            $y = [];
            foreach (array_slice(preg_split('/\s+/', $buscar), 0, 6) as $pal) {
                $y[] = '(nombre LIKE ? OR carrier LIKE ? OR categoria LIKE ?)';
                $l = '%' . $pal . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
            }
            $w[] = '((' . implode(' AND ', $y) . ') OR producto_id = ?)';
            $p[] = $buscar;
        }
        if ($categoria !== '') { $w[] = 'categoria = ?'; $p[] = $categoria; }
        if ($carrier !== '')   { $w[] = 'carrier = ?';   $p[] = $carrier; }
        return [$w, $p];
    }

    /** Cuántos productos hay apagados (dejaron de llegar en una descarga). */
    public function ocultos()
    {
        $this->asegurar();
        return (int)$this->valor("SELECT COUNT(*) FROM lf_emida_productos WHERE activo = 0");
    }

    /** Vuelve a mostrar todos los apagados. Devuelve cuántos. */
    public function mostrarOcultos()
    {
        $this->asegurar();
        $st = $this->db->prepare("UPDATE lf_emida_productos SET activo = 1 WHERE activo = 0");
        $st->execute();
        return $st->rowCount();
    }

    /** Un producto por su identificador de Emida. */
    public function porId($productoId)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_emida_productos WHERE producto_id = ? AND activo = 1",
            [(string)$productoId]);
    }

    public function categorias()
    {
        $this->asegurar();
        return $this->todos("SELECT categoria, COUNT(*) n FROM lf_emida_productos
            WHERE activo = 1 AND categoria <> '' GROUP BY categoria ORDER BY categoria");
    }

    public function carriers($categoria = '')
    {
        $this->asegurar();
        $w = "activo = 1 AND carrier <> ''"; $p = [];
        if ($categoria !== '') { $w .= " AND categoria = ?"; $p[] = $categoria; }
        return $this->todos("SELECT carrier, COUNT(*) n FROM lf_emida_productos
            WHERE {$w} GROUP BY carrier ORDER BY carrier", $p);
    }

    public function cuandoSeActualizo()
    {
        $this->asegurar();
        return $this->valor("SELECT MAX(actualizado) FROM lf_emida_productos");
    }

    // ── Transacciones ───────────────────────────────────────────

    public function registrar(array $d)
    {
        $this->asegurar();
        $this->db->prepare("
            INSERT INTO lf_emida_transacciones
                (sales_id, producto_id, producto_nombre, cuenta, monto, comision,
                 estado, folio_proveedor, codigo, h2h, mensaje, usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE estado=VALUES(estado), folio_proveedor=VALUES(folio_proveedor),
                                    codigo=VALUES(codigo), h2h=VALUES(h2h), mensaje=VALUES(mensaje)
        ")->execute([
            $d['sales_id'], $d['producto_id'], $d['producto_nombre'] ?? null, $d['cuenta'],
            $d['monto'], $d['comision'] ?? 0, $d['estado'], $d['folio'] ?? null,
            $d['codigo'] ?? null, $d['h2h'] ?? null, mb_substr((string)($d['mensaje'] ?? ''), 0, 300),
            $d['usuario_id'] ?? null, $d['usuario_nombre'] ?? null,
        ]);
        return true;
    }

    public function delDia($fecha = null)
    {
        $this->asegurar();
        $f = $fecha ?: date('Y-m-d');
        return $this->todos("SELECT * FROM lf_emida_transacciones
            WHERE DATE(creado_en) = ? ORDER BY creado_en DESC", [$f]);
    }

    public function cifrasDelDia($fecha = null)
    {
        $this->asegurar();
        $f = $fecha ?: date('Y-m-d');
        return $this->uno("
            SELECT COUNT(*) AS cuantas,
                   SUM(estado = 'exitosa') AS exitosas,
                   COALESCE(SUM(CASE WHEN estado = 'exitosa' THEN monto END),0) AS vendido,
                   COALESCE(SUM(CASE WHEN estado = 'exitosa' THEN comision END),0) AS comision
            FROM lf_emida_transacciones WHERE DATE(creado_en) = ?", [$f]) ?: [];
    }

    /**
     * Lee un catálogo pegado desde la pantalla del proveedor.
     *
     * Acepta columnas separadas por tabulador —que es lo que sale al
     * copiar de una tabla web— o por coma. Se salta los renglones que
     * no empiezan con un identificador numérico, y junta los nombres
     * que la tabla parte en dos renglones.
     *
     * La imagen se detecta buscando cualquier celda que empiece con
     * http:// o https://, porque en el portal la columna de la imagen
     * no siempre cae en la misma posición.
     */
    public static function leerPegado($texto)
    {
        $lineas = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$texto) as $cruda) {
            if (trim($cruda) === '') continue;
            if (preg_match('/^\s*\d{4,}\s*[\t,]/', $cruda) || !$lineas) {
                $lineas[] = rtrim($cruda);
            } else {
                $lineas[count($lineas) - 1] .= ' ' . trim($cruda);
            }
        }

        $productos = [];
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $c = strpos($linea, "\t") !== false
               ? preg_split('/\t+/', $linea)
               : str_getcsv($linea);
            $c = array_map('trim', $c);
            if (count($c) < 4) continue;

            $id = preg_replace('/\D/', '', $c[0]);
            if ($id === '' || strlen($id) < 4) continue;

            $num = function ($v) {
                $v = preg_replace('/[^0-9.]/', '', (string)$v);
                return $v === '' ? 0.0 : (float)$v;
            };
            $nombre = preg_replace('/\s+/', ' ', (string)($c[1] ?? ''));

            $monto = $num($c[5] ?? '');
            $min   = $num($c[6] ?? '');
            $max   = $num($c[7] ?? '');
            $tipo  = stripos((string)($c[8] ?? ''), 'consulta') !== false ? 'consulta'
                   : (($min > 0 || $max > 0 || $monto <= 0) ? 'consulta' : 'directa');

            // La imagen: cualquier celda que sea una URL.
            $imagen = '';
            foreach ($c as $celda) {
                if (preg_match('#^https?://#i', trim($celda))) {
                    $imagen = trim($celda);
                    break;
                }
            }
            $imagen = self::normalizarImagen($imagen);

            $productos[] = [
                'producto_id' => $id,
                'nombre'      => mb_substr($nombre, 0, 220),
                'categoria'   => mb_substr((string)($c[2] ?? ''), 0, 80),
                'carrier'     => mb_substr((string)($c[3] ?? ''), 0, 80),
                'comision'    => $num($c[4] ?? ''),
                'monto'       => $monto,
                'monto_min'   => $min,
                'monto_max'   => $max,
                'tipo'        => $tipo,
                'imagen'      => mb_substr($imagen, 0, 500),
            ];
        }
        return $productos;
    }

    /**
     * Las URLs de Dropbox que trae Emida vienen con ?dl=0, que abre la
     * página de descarga en vez de servir la imagen. Con ?raw=1 sí
     * carga dentro de un <img>. También se limpia el "NA".
     */
    public static function normalizarImagen($url)
    {
        $url = trim((string)$url);
        if ($url === '' || strtoupper($url) === 'NA') return '';

        if (stripos($url, 'dropbox.com') !== false) {
            $url = preg_replace('/[?&]dl=0\b/', '?raw=1', $url);
            $url = preg_replace('/[?&]raw=0\b/', '?raw=1', $url);
            if (strpos($url, '?') === false) $url .= '?raw=1';
        }
        return $url;
    }
}