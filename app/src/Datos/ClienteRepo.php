<?php
namespace LibertyFin\Datos;

/**
 * Clientes.
 *
 * La pregunta que responde esta pantalla no es "quién me compra más",
 * sino "a quién le hablo primero". Por eso la antigüedad del saldo pesa
 * tanto como el monto: $18,000 parados 60 días son peor noticia que
 * $30,000 abonados la semana pasada.
 */
final class ClienteRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT COUNT(DISTINCT v.cliente_id) AS activos,
                   COUNT(DISTINCT CASE WHEN x.compras >= 2 THEN v.cliente_id END) AS recurrentes
            FROM ventas v
            LEFT JOIN ( SELECT cliente_id, COUNT(*) compras FROM ventas
                        WHERE estado <> 'cancelada' GROUP BY cliente_id ) x
                   ON x.cliente_id = v.cliente_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
              AND v.cliente_id IS NOT NULL
        ", [$a, $b]) ?: [];

        // Nuevo = su primera venta cae dentro del periodo.
        $r['nuevos'] = (int)$this->valor("
            SELECT COUNT(*) FROM (
                SELECT cliente_id, MIN(fecha) primera FROM ventas
                WHERE estado <> 'cancelada' AND cliente_id IS NOT NULL
                GROUP BY cliente_id
            ) p WHERE p.primera >= ? AND p.primera < ?", [$a, $b]);

        $s = $this->uno("
            SELECT COUNT(*) AS cuantos, COALESCE(SUM(saldo),0) AS monto FROM (
                SELECT v.cliente_id, SUM(v.total - COALESCE(pg.cobrado,0)) AS saldo
                FROM ventas v
                LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                            WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
                WHERE v.estado <> 'cancelada' AND v.cliente_id IS NOT NULL
                GROUP BY v.cliente_id
                HAVING saldo > 0.01
            ) x") ?: [];
        $r['con_saldo'] = (int)($s['cuantos'] ?? 0);
        $r['saldo']     = (float)($s['monto'] ?? 0);
        return $r;
    }

    public function listado($desde, $hasta, $buscar = '', $limite = 25, $desfase = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['v.fecha >= ?', 'v.fecha < ?', "v.estado <> 'cancelada'"];
        $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(c.nombre LIKE ? OR c.telefono LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT c.id, c.nombre, c.telefono,
                   COUNT(DISTINCT v.id)                    AS compras,
                   COALESCE(SUM(v.total),0)                AS facturado,
                   COALESCE(SUM(pg.cobrado),0)             AS cobrado,
                   COALESCE(SUM(v.total - COALESCE(pg.cobrado,0)),0) AS saldo,
                   MAX(v.fecha)                            AS ultima,
                   -- El área de un cliente no es un dato suyo: es un
                   -- resumen de lo que ha contratado. Se toma la de mayor
                   -- peso en dinero, y lo escrito a mano manda solo si
                   -- nunca ha comprado nada.
                   COALESCE(
                       (SELECT cat.nombre
                        FROM venta_detalles d2
                        INNER JOIN ventas v2    ON v2.id = d2.venta_id
                        LEFT  JOIN productos p2 ON p2.id = d2.producto_id
                        LEFT  JOIN categorias cat ON cat.id = p2.categoria_id
                        WHERE v2.cliente_id = c.id AND v2.estado <> 'cancelada'
                          AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                        GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1),
                       MAX(c.area), MAX(v.area_nombre)) AS area
            FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE " . implode(' AND ', $w) . "
            GROUP BY c.id, c.nombre, c.telefono
            ORDER BY facturado DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantos($desde, $hasta, $buscar = '')
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['v.fecha >= ?', 'v.fecha < ?', "v.estado <> 'cancelada'"];
        $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(c.nombre LIKE ? OR c.telefono LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return (int)$this->valor("
            SELECT COUNT(DISTINCT c.id) FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            WHERE " . implode(' AND ', $w), $p);
    }

    public function masFacturan($desde, $hasta, $tope = 5)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT c.nombre, COALESCE(SUM(v.total),0) AS monto
            FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY c.id, c.nombre
            ORDER BY monto DESC
            LIMIT " . (int)$tope, [$a, $b]);
    }

    /**
     * Antigüedad del saldo, en tramos.
     * Se mide desde el último abono, o desde la venta si nunca hubo uno.
     */
    public function antiguedad()
    {
        return $this->uno("
            SELECT
              COALESCE(SUM(CASE WHEN dias <= 30 THEN saldo END),0)              AS t30,
              COALESCE(SUM(CASE WHEN dias > 30 AND dias <= 60 THEN saldo END),0) AS t60,
              COALESCE(SUM(CASE WHEN dias > 60 THEN saldo END),0)               AS t60mas,
              COUNT(CASE WHEN dias > 60 THEN 1 END)                             AS n60mas,
              COALESCE(SUM(saldo),0)                                            AS total
            FROM (
              SELECT v.total - COALESCE(pg.cobrado,0) AS saldo,
                     DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias
              FROM ventas v
              LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                          FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                     ON pg.venta_id = v.id
              WHERE v.estado <> 'cancelada'
                AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ) x") ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // ALTA Y EDICION
    // ─────────────────────────────────────────────────────────────

    /** Catálogo SAT c_RegimenFiscal. Se guarda la clave. */
    const REGIMENES = [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Régimen de Enajenación o Adquisición de Bienes',
        '608' => 'Demás ingresos',
        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '615' => 'Régimen de los ingresos por obtención de premios',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621' => 'Incorporación Fiscal',
        '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626' => 'Régimen Simplificado de Confianza',
    ];

    /** Catálogo SAT c_UsoCFDI (sin pagos ni nómina: no son de venta). Se guarda la clave. */
    const USOS_CFDI = [
        'G01' => 'Adquisición de mercancías',
        'G02' => 'Devoluciones, descuentos o bonificaciones',
        'G03' => 'Gastos en general',
        'I01' => 'Construcciones',
        'I02' => 'Mobiliario y equipo de oficina por inversiones',
        'I03' => 'Equipo de transporte',
        'I04' => 'Equipo de cómputo y accesorios',
        'I05' => 'Dados, troqueles, moldes, matrices y herramental',
        'I06' => 'Comunicaciones telefónicas',
        'I07' => 'Comunicaciones satelitales',
        'I08' => 'Otra maquinaria y equipo',
        'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
        'D02' => 'Gastos médicos por incapacidad o discapacidad',
        'D03' => 'Gastos funerales',
        'D04' => 'Donativos',
        'D05' => 'Intereses reales por créditos hipotecarios (casa habitación)',
        'D06' => 'Aportaciones voluntarias al SAR',
        'D07' => 'Primas por seguros de gastos médicos',
        'D08' => 'Gastos de transportación escolar obligatoria',
        'D09' => 'Depósitos en cuentas para el ahorro, primas de planes de pensiones',
        'D10' => 'Pagos por servicios educativos (colegiaturas)',
        'S01' => 'Sin efectos fiscales',
    ];

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM clientes WHERE id = ?", [(int)$id]);
    }

    /**
     * Valida y normaliza lo que llega del formulario.
     * @throws \InvalidArgumentException con un mensaje apto para mostrar
     */
    private function limpiar(array $d, $idActual = null)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');
        if (mb_strlen($nombre) > 150) throw new \InvalidArgumentException('El nombre es demasiado largo');

        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }
        $email = trim($d['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }
        // Del teléfono se guarda solo lo que sirve para marcar.
        $tel = preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? ''));

        // Lo fiscal es opcional; si viene, tiene que ser del catálogo del SAT.
        $cp = trim($d['codigo_postal'] ?? '');
        if ($cp !== '' && !preg_match('/^\d{5}$/', $cp)) {
            throw new \InvalidArgumentException('El código postal son 5 dígitos');
        }
        $reg = trim($d['regimen_fiscal'] ?? '');
        if ($reg !== '' && !isset(self::REGIMENES[$reg])) {
            throw new \InvalidArgumentException('El régimen fiscal no es válido');
        }
        $uso = mb_strtoupper(trim($d['uso_cfdi'] ?? ''));
        if ($uso !== '' && !isset(self::USOS_CFDI[$uso])) {
            throw new \InvalidArgumentException('El uso de CFDI no es válido');
        }

        // Mismo nombre dos veces es casi siempre captura duplicada, y fue
        // justo lo que llenó julio de basura.
        $sql = "SELECT id FROM clientes WHERE nombre = ?";
        $p = [$nombre];
        if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe un cliente con ese nombre');
        }

        return [$nombre, $rfc ?: null, $email ?: null, $tel ?: null,
                trim($d['direccion'] ?? '') ?: null,
                trim($d['area'] ?? '') ?: null,
                $cp ?: null, $reg ?: null, $uso ?: null];
    }

    /**
     * ¿Existe la columna `area`? Se pregunta una vez y se recuerda.
     *
     * La agrega 12_area_cliente.sql. Mientras no se corra, el sistema
     * funciona igual: simplemente no guarda el área. Preferible a
     * reventar con "Unknown column" en una instalación sin migrar.
     */
    private function tieneArea()
    {
        static $tiene = null;
        if ($tiene !== null) return $tiene;
        try {
            $tiene = (bool)$this->valor("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes'
                  AND COLUMN_NAME = 'area'");
        } catch (\Throwable $e) { $tiene = false; }
        return $tiene;
    }

    public function crear(array $d)
    {
        list($nombre, $rfc, $email, $tel, $dir, $area, $cp, $reg, $uso) = $this->limpiar($d);
        if ($this->tieneArea()) {
            $this->db->prepare("
                INSERT INTO clientes (nombre, rfc, area, email, telefono, direccion,
                                      codigo_postal, regimen_fiscal, uso_cfdi, activo, fecha_creacion)
                VALUES (?,?,?,?,?,?,?,?,?,1,NOW())
            ")->execute([$nombre, $rfc, $area, $email, $tel, $dir, $cp, $reg, $uso]);
        } else {
            $this->db->prepare("
                INSERT INTO clientes (nombre, rfc, email, telefono, direccion,
                                      codigo_postal, regimen_fiscal, uso_cfdi, activo, fecha_creacion)
                VALUES (?,?,?,?,?,?,?,?,1,NOW())
            ")->execute([$nombre, $rfc, $email, $tel, $dir, $cp, $reg, $uso]);
        }
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        list($nombre, $rfc, $email, $tel, $dir, $area, $cp, $reg, $uso) = $this->limpiar($d, $id);
        if ($this->tieneArea()) {
            $this->db->prepare("
                UPDATE clientes SET nombre = ?, rfc = ?, area = ?, email = ?,
                       telefono = ?, direccion = ?,
                       codigo_postal = ?, regimen_fiscal = ?, uso_cfdi = ? WHERE id = ?
            ")->execute([$nombre, $rfc, $area, $email, $tel, $dir, $cp, $reg, $uso, (int)$id]);
        } else {
            $this->db->prepare("
                UPDATE clientes SET nombre = ?, rfc = ?, email = ?, telefono = ?, direccion = ?,
                       codigo_postal = ?, regimen_fiscal = ?, uso_cfdi = ?
                WHERE id = ?
            ")->execute([$nombre, $rfc, $email, $tel, $dir, $cp, $reg, $uso, (int)$id]);
        }
        return (int)$id;
    }

    /** Las áreas que ya se usan, para sugerirlas en el formulario. */
    public function areasUsadas()
    {
        $r = $this->todos("
            -- Las áreas que existen de verdad: las categorías con
            -- productos, más las que quedaron escritas en ventas viejas.
            SELECT area FROM (
                SELECT nombre AS area FROM categorias WHERE nombre <> ''
                UNION
                SELECT DISTINCT area_nombre FROM ventas
                WHERE area_nombre IS NOT NULL AND area_nombre <> ''
            ) a ORDER BY area");
        return array_column($r, 'area');
    }
}
