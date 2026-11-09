<?php
namespace LibertyFin\Datos;

/**
 * Catálogos de la empresa: sucursales, áreas de comisión, colaboradores
 * y categorías de servicio.
 *
 * Son tablas chicas que se tocan poco y siempre juntas. En el sistema
 * anterior cada una tenía su pantalla de 700 a 1,500 líneas; aquí viven
 * en una sola sección con pestañas.
 */
final class AjustesRepo extends Repo
{
    // ── SUCURSALES ──────────────────────────────────────────────

    public function sucursales()
    {
        return $this->todos("
            SELECT s.*, COALESCE(s.activo,1) AS activo,
                   (SELECT COUNT(*) FROM usuarios u WHERE u.sucursal_id = s.id) AS usuarios,
                   (SELECT COUNT(*) FROM ventas v WHERE v.sucursal_id = s.id
                      AND v.estado <> 'cancelada') AS ventas
            FROM sucursales s ORDER BY COALESCE(s.activo,1) DESC, s.nombre");
    }

    public function guardarSucursal($id, array $d)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');
        $p = [$nombre, trim($d['direccion'] ?? '') ?: null,
              trim($d['telefono'] ?? '') ?: null, trim($d['responsable'] ?? '') ?: null];

        if ($id) {
            $p[] = (int)$id;
            $this->db->prepare("
                UPDATE sucursales SET nombre = ?, direccion = ?, telefono = ?, responsable = ?
                WHERE id = ?")->execute($p);
            return (int)$id;
        }
        $this->db->prepare("
            INSERT INTO sucursales (nombre, direccion, telefono, responsable, activo, fecha_creacion)
            VALUES (?,?,?,?,1,NOW())")->execute($p);
        $nueva = (int)$this->db->lastInsertId();

        // Estrena Piso y Bodega. Si falla, la sucursal ya quedó guardada y
        // el primer movimiento de existencia le crea el principal: no vale
        // la pena tumbar el alta por esto.
        try { (new \LibertyFin\Servicio\Almacenes($this->db))->asegurar($nueva); }
        catch (\Throwable $e) { error_log('[LibertyFin] almacenes de sucursal nueva: ' . $e->getMessage()); }

        return $nueva;
    }

    /** No se borra una sucursal con ventas: perdería el rastro de dónde se hicieron. */
    public function alternarSucursal($id)
    {
        $s = $this->uno("SELECT COALESCE(activo,1) AS activo FROM sucursales WHERE id = ?", [(int)$id]);
        if (!$s) throw new \InvalidArgumentException('Esa sucursal no existe');
        if ($s['activo']) {
            $otras = (int)$this->valor("
                SELECT COUNT(*) FROM sucursales WHERE COALESCE(activo,1) = 1 AND id <> ?", [(int)$id]);
            if ($otras === 0) {
                throw new \InvalidArgumentException('Es la única sucursal activa');
            }
        }
        $this->db->prepare("UPDATE sucursales SET activo = 1 - COALESCE(activo,1) WHERE id = ?")
                 ->execute([(int)$id]);
        return true;
    }

    // ── ÁREAS DE COMISIÓN ───────────────────────────────────────

    public function areas()
    {
        return $this->todos("
            SELECT a.*, COALESCE(a.activo,1) AS activo,
                   (SELECT COUNT(*) FROM comision_colaboradores c
                      WHERE c.area_id = a.id AND c.activo = 1) AS colaboradores,
                   (SELECT COALESCE(SUM(vc.monto_comision),0) FROM venta_comisiones vc
                      WHERE vc.area_id = a.id AND vc.cancelada = 0) AS asignado
            FROM comision_areas a ORDER BY a.nombre");
    }

    public function guardarArea($id, array $d)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre del área es obligatorio');
        $sql = "SELECT id FROM comision_areas WHERE nombre = ?";
        $p = [$nombre];
        if ($id) { $sql .= " AND id <> ?"; $p[] = (int)$id; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe un área con ese nombre');
        }
        if ($id) {
            $this->db->prepare("UPDATE comision_areas SET nombre = ? WHERE id = ?")
                     ->execute([$nombre, (int)$id]);
            return (int)$id;
        }
        $this->db->prepare("INSERT INTO comision_areas (nombre) VALUES (?)")->execute([$nombre]);
        return (int)$this->db->lastInsertId();
    }

    // ── COLABORADORES ───────────────────────────────────────────

    public function colaboradores()
    {
        return $this->todos("
            SELECT c.id, c.nombre, c.activo, c.area_id, a.nombre AS area,
                   -- Ventas, no comisiones: con varios productos una
                   -- misma venta puede darle dos.
                   (SELECT COUNT(DISTINCT vc.venta_id) FROM venta_comisiones vc
                      WHERE vc.colaborador_id = c.id AND vc.cancelada = 0) AS ventas,
                   (SELECT COALESCE(SUM(pc.monto),0) FROM pago_comisiones pc
                      WHERE pc.colaborador_id = c.id) AS devengado,
                   (SELECT vc2.porcentaje_regla FROM venta_comisiones vc2
                      WHERE vc2.colaborador_id = c.id AND vc2.cancelada = 0
                      GROUP BY vc2.porcentaje_regla ORDER BY COUNT(*) DESC LIMIT 1) AS pct_usual
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            ORDER BY c.activo DESC, a.nombre, c.nombre");
    }

    public function guardarColaborador($id, array $d)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');
        // EL ÁREA SE PUEDE ESCRIBIR, NO SOLO ELEGIR.
        //
        // Antes era un desplegable y punto: quien iba a dar de alta a su
        // primer colaborador se topaba con una lista vacía, tenía que
        // salirse a crear el área, volver y empezar otra vez. Lo que
        // estaba haciendo —dar de alta a una persona— quedaba a medias
        // por un dato que se escribe en tres segundos.
        //
        // Ahora se escribe el nombre. Si ya existe se usa esa; si no, se
        // crea. Es lo mismo que ya hacía la ficha de cliente con su
        // campo de área, así que además deja de haber dos formas de
        // hacer lo mismo según la pantalla.
        $area = (int)($d['area_id'] ?? 0);
        $nombreArea = trim((string)($d['area_nombre'] ?? ''));

        if ($nombreArea !== '') {
            $yaEsta = $this->valor(
                "SELECT id FROM comision_areas WHERE LOWER(nombre) = LOWER(?) LIMIT 1",
                [$nombreArea]);
            // Se compara sin distinguir mayúsculas: «Legal» y «LEGAL» son
            // la misma área, y tener las dos parte los reportes en dos.
            $area = $yaEsta ? (int)$yaEsta : $this->guardarArea(null, ['nombre' => $nombreArea]);
        }

        if (!$area || !$this->valor("SELECT id FROM comision_areas WHERE id = ?", [$area])) {
            throw new \InvalidArgumentException('Escribe a qué área pertenece');
        }
        $sql = "SELECT id FROM comision_colaboradores WHERE nombre = ? AND area_id = ?";
        $p = [$nombre, $area];
        if ($id) { $sql .= " AND id <> ?"; $p[] = (int)$id; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ese colaborador ya está en esa área');
        }
        if ($id) {
            $this->db->prepare("
                UPDATE comision_colaboradores SET nombre = ?, area_id = ? WHERE id = ?")
                ->execute([$nombre, $area, (int)$id]);
            return (int)$id;
        }
        $this->db->prepare("
            INSERT INTO comision_colaboradores (nombre, area_id, activo) VALUES (?,?,1)")
            ->execute([$nombre, $area]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Se desactiva, no se borra. Sus comisiones ya asignadas siguen
     * contando: el trabajo lo hizo aunque ya no esté.
     */
    public function alternarColaborador($id)
    {
        $c = $this->uno("SELECT nombre, activo FROM comision_colaboradores WHERE id = ?", [(int)$id]);
        if (!$c) throw new \InvalidArgumentException('Ese colaborador no existe');
        if ($c['nombre'] === 'POR ASIGNAR') {
            throw new \InvalidArgumentException(
                'POR ASIGNAR es del sistema: marca las comisiones sin dueño');
        }
        $this->db->prepare("UPDATE comision_colaboradores SET activo = 1 - activo WHERE id = ?")
                 ->execute([(int)$id]);
        return true;
    }

    // ── CATEGORÍAS DE SERVICIO ──────────────────────────────────

    public function categorias()
    {
        return $this->todos("
            SELECT c.id, c.nombre, c.descripcion, COALESCE(c.activo,1) AS activo,
                   (SELECT COUNT(*) FROM productos p
                      WHERE p.categoria_id = c.id AND p.activo = 1) AS servicios
            FROM categorias c ORDER BY c.nombre");
    }

    public function guardarCategoria($id, array $d)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');
        $sql = "SELECT id FROM categorias WHERE nombre = ?";
        $p = [$nombre];
        if ($id) { $sql .= " AND id <> ?"; $p[] = (int)$id; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe una categoría con ese nombre');
        }
        $desc = trim($d['descripcion'] ?? '') ?: null;
        if ($id) {
            $this->db->prepare("UPDATE categorias SET nombre = ?, descripcion = ? WHERE id = ?")
                     ->execute([$nombre, $desc, (int)$id]);
            return (int)$id;
        }
        $this->db->prepare("INSERT INTO categorias (nombre, descripcion) VALUES (?,?)")
                 ->execute([$nombre, $desc]);
        return (int)$this->db->lastInsertId();
    }
}