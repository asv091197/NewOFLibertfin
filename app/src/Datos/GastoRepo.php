<?php
namespace LibertyFin\Datos;

/**
 * Gastos.
 *
 * Hay dos clases y no se mezclan:
 *
 *   DE OPERACION · cuelgan de una venta y se restan de la utilidad antes
 *     de comisionar. Se capturan en Caja o en el detalle de la venta, no
 *     aquí.
 *
 *   GENERALES · renta, nómina, servicios. No pertenecen a ninguna venta y
 *     no tocan comisiones. Son los de esta pantalla.
 *
 * Confundirlos sería repetir el error que ya vimos: un gasto general
 * restado a una venta le baja la comisión a alguien sin razón.
 */
final class GastoRepo extends Repo
{
    const CATEGORIAS = ['Renta','Nómina','Servicios','Insumos','Transporte',
                        'Marketing','Mantenimiento','Impuestos','Otros'];

    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT COUNT(*) AS cuantos, COALESCE(SUM(monto),0) AS total
            FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?", [$a, $b]) ?: [];

        // Los de operación se reportan aparte para que nadie los sume.
        $r['operacion'] = (float)$this->valor("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id IS NOT NULL AND fecha >= ? AND fecha < ?", [$a, $b]);
        return $r;
    }

    public function listado($desde, $hasta, $categoria = '', $buscar = '', $limite = 10, $desfase = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['g.venta_id IS NULL', 'g.fecha >= ?', 'g.fecha < ?'];
        $p = [$a, $b];
        if ($categoria !== '') { $w[] = 'g.categoria = ?'; $p[] = $categoria; }
        if ($buscar !== '')    { $w[] = '(g.concepto LIKE ? OR g.descripcion LIKE ?)';
                                 $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT g.*, u.nombre AS usuario
            FROM gastos g
            LEFT JOIN usuarios u ON u.id = g.usuario_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY g.fecha DESC, g.id DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantos($desde, $hasta, $categoria = '', $buscar = '')
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['g.venta_id IS NULL', 'g.fecha >= ?', 'g.fecha < ?']; $p = [$a, $b];
        if ($categoria !== '') { $w[] = 'g.categoria = ?'; $p[] = $categoria; }
        if ($buscar !== '')    { $w[] = '(g.concepto LIKE ? OR g.descripcion LIKE ?)';
                                 $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return (int)$this->valor("SELECT COUNT(*) FROM gastos g WHERE " . implode(' AND ', $w), $p);
    }

    public function porCategoria($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(categoria,''),'Otros') AS categoria,
                   SUM(monto) AS monto, COUNT(*) AS cuantos
            FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?
            GROUP BY COALESCE(NULLIF(categoria,''),'Otros')
            ORDER BY monto DESC", [$a, $b]);
    }

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM gastos WHERE id = ? AND venta_id IS NULL", [(int)$id]);
    }

    private function limpiar(array $d)
    {
        $concepto = trim($d['concepto'] ?? '');
        if ($concepto === '') throw new \InvalidArgumentException('El concepto es obligatorio');

        $monto = round((float)($d['monto'] ?? 0), 2);
        if ($monto <= 0) throw new \InvalidArgumentException('El monto debe ser mayor a cero');

        $cat = $d['categoria'] ?? 'Otros';
        if (!in_array($cat, self::CATEGORIAS, true)) $cat = 'Otros';

        $fecha = $d['fecha'] ?? date('Y-m-d');
        $f = \DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$f || $f->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException('La fecha no es válida');
        }
        // Un gasto con fecha futura es siempre un dedazo.
        if (strtotime($fecha) > strtotime('today')) {
            throw new \InvalidArgumentException('No se puede registrar un gasto con fecha futura');
        }

        $metodo = in_array($d['metodo_pago'] ?? '', ['efectivo','transferencia','tarjeta'], true)
                ? $d['metodo_pago'] : 'efectivo';

        return [$concepto, $cat, $monto, $fecha, $metodo,
                trim($d['descripcion'] ?? '') ?: null,
                trim($d['proveedor'] ?? '') ?: null,
                trim($d['numero_referencia'] ?? '') ?: null];
    }

    public function crear(array $d, $usuarioId, $sucursalId)
    {
        list($con, $cat, $monto, $fecha, $met, $desc, $prov, $ref) = $this->limpiar($d);
        $this->db->prepare("
            INSERT INTO gastos (concepto, categoria, monto, tipo, origen, usuario_id,
                                sucursal_id, metodo_pago, descripcion, fecha, proveedor,
                                numero_referencia)
            VALUES (?,?,?,'manual','general',?,?,?,?,?,?,?)
        ")->execute([$con, $cat, $monto, $usuarioId ?: null, $sucursalId ?: null,
                     $met, $desc, $fecha . ' ' . date('H:i:s'), $prov, $ref]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        if (!$this->uno_($id)) {
            throw new \InvalidArgumentException('Ese gasto no existe o pertenece a una venta');
        }
        list($con, $cat, $monto, $fecha, $met, $desc, $prov, $ref) = $this->limpiar($d);
        $this->db->prepare("
            UPDATE gastos SET concepto = ?, categoria = ?, monto = ?, metodo_pago = ?,
                   descripcion = ?, fecha = ?, proveedor = ?, numero_referencia = ?
            WHERE id = ? AND venta_id IS NULL
        ")->execute([$con, $cat, $monto, $met, $desc,
                     $fecha . ' ' . date('H:i:s'), $prov, $ref, (int)$id]);
        return (int)$id;
    }

    /**
     * Solo se borran los generales. Uno de operación cambia la base de
     * comisión de su venta, y eso no se toca desde aquí.
     */
    public function borrar($id)
    {
        if (!$this->uno_($id)) {
            throw new \InvalidArgumentException(
                'Ese gasto pertenece a una venta. Quítalo desde el detalle de la venta.');
        }
        $this->db->prepare("DELETE FROM gastos WHERE id = ? AND venta_id IS NULL")
                 ->execute([(int)$id]);
        return true;
    }

    /**
     * Los gastos de operación del periodo, con su venta.
     *
     * No se pueden editar desde aquí, pero SÍ se tienen que ver: si la
     * pantalla solo muestra los generales y la empresa no usa esa figura,
     * queda una lista vacía junto a una cifra de $47,361, y eso parece un
     * error del sistema aunque sea correcto.
     */
    public function operacion($desde, $hasta, $tope = 100)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT g.id, g.concepto, g.monto, g.fecha, g.venta_id,
                   v.codigo_venta, v.total AS venta_total,
                   COALESCE(c.nombre,'Público general') AS cliente
            FROM gastos g
            INNER JOIN ventas v   ON v.id = g.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            WHERE g.fecha >= ? AND g.fecha < ?
              AND g.tipo = 'manual' AND g.categoria <> 'Costo de venta'
            ORDER BY g.monto DESC
            LIMIT " . (int)$tope, [$a, $b]);
    }

    // ─────────────────────────────────────────────────────────────
    // PROVEEDORES
    //
    // Viven aquí y no en su propia sección porque solo existen para una
    // cosa: saber a quién se le paga. Un proveedor sin gastos asociados
    // es un dato muerto.
    // ─────────────────────────────────────────────────────────────

    public function proveedores()
    {
        return $this->todos("
            SELECT p.*, COALESCE(p.activo,1) AS activo,
                   COALESCE(g.cuantos,0) AS gastos,
                   COALESCE(g.monto,0)   AS pagado,
                   g.ultimo
            FROM proveedores p
            LEFT JOIN ( SELECT proveedor, COUNT(*) cuantos, SUM(monto) monto,
                               MAX(fecha) ultimo
                        FROM gastos WHERE proveedor IS NOT NULL AND proveedor <> ''
                        GROUP BY proveedor ) g ON g.proveedor = p.nombre
            ORDER BY COALESCE(p.activo,1) DESC, p.nombre");
    }

    public function proveedor($id)
    {
        return $this->uno("SELECT * FROM proveedores WHERE id = ?", [(int)$id]);
    }

    /** Solo los activos, para el desplegable del formulario de gasto. */
    public function proveedoresActivos()
    {
        return $this->todos("
            SELECT id, nombre FROM proveedores
            WHERE COALESCE(activo,1) = 1 ORDER BY nombre");
    }

    public function guardarProveedor($id, array $d)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');

        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }
        $email = trim($d['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }

        $sql = "SELECT id FROM proveedores WHERE nombre = ?";
        $p = [$nombre];
        if ($id) { $sql .= " AND id <> ?"; $p[] = (int)$id; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe un proveedor con ese nombre');
        }

        $tel = preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? ''));
        $vals = [$nombre, trim($d['contacto'] ?? '') ?: null, $tel ?: null,
                 $email ?: null, trim($d['direccion'] ?? '') ?: null, $rfc ?: null];

        if ($id) {
            // Si cambia el nombre, los gastos que lo referencian por texto
            // se quedarían huérfanos. Se arrastra el cambio.
            $antes = $this->valor("SELECT nombre FROM proveedores WHERE id = ?", [(int)$id]);
            $vals[] = (int)$id;
            $this->db->prepare("
                UPDATE proveedores SET nombre = ?, contacto = ?, telefono = ?, email = ?,
                       direccion = ?, rfc = ?, fecha_actualizacion = NOW()
                WHERE id = ?")->execute($vals);
            if ($antes && $antes !== $nombre) {
                $this->db->prepare("UPDATE gastos SET proveedor = ? WHERE proveedor = ?")
                         ->execute([$nombre, $antes]);
            }
            return (int)$id;
        }
        $this->db->prepare("
            INSERT INTO proveedores (nombre, contacto, telefono, email, direccion, rfc,
                                     activo, fecha_creacion)
            VALUES (?,?,?,?,?,?,1,NOW())")->execute($vals);
        return (int)$this->db->lastInsertId();
    }

    public function alternarProveedor($id)
    {
        if (!$this->proveedor($id)) throw new \InvalidArgumentException('Ese proveedor no existe');
        $this->db->prepare("UPDATE proveedores SET activo = 1 - COALESCE(activo,1) WHERE id = ?")
                 ->execute([(int)$id]);
        return true;
    }

    /** Un gasto por su id. */
    public function porId($id)
    {
        return $this->uno("SELECT * FROM gastos WHERE id = ?", [(int)$id]);
    }
}
