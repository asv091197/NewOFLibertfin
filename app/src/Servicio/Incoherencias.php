<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Detector de incoherencias.
 *
 * Revisa lo que YA está capturado y señala lo que no cuadra, para auditar
 * sin recorrer venta por venta. Es de SOLO LECTURA: no corrige nada, a
 * propósito. Si se arreglara en silencio, el mismo error volvería a
 * aparecer sin que nadie supiera por qué; quien audita decide.
 *
 * Cada regla es una invariante que el propio sistema ya declara en otro
 * lado (el tope de 100% de AsignarComision, la fórmula de Dominio\Comision,
 * "cobrar de más" de Dominio\Ticket...). Aquí solo se comprueba que lo
 * guardado la cumpla. Para agregar una regla: una entrada en REGLAS y un
 * método con el mismo nombre en consultaDe(). Nada más.
 *
 * Severidad:
 *   alta   el dinero no cuadra: hay que revisarlo.
 *   media  algo está mal capturado o es raro; probablemente un error.
 *   baja   puede ser legítimo (datos históricos, por ejemplo); mirar de paso.
 */
final class Incoherencias
{
    const SEVERIDADES = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];

    /** unidad: cómo se muestran 'actual' y 'esperado' (pesos | pct | texto). */
    const REGLAS = [
        'comisiones_sobre_100' => [
            'titulo' => 'Comisiones de un producto pasan de 100%',
            'severidad' => 'alta', 'unidad' => 'pct',
            'ayuda' => 'Las comisiones vigentes de una misma línea suman más de 100%.',
        ],
        'comision_monto' => [
            'titulo' => 'El monto de la comisión no es base × porcentaje',
            'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'Lo guardado difiere más de $0.05 de monto_base × porcentaje. '
                     . 'En datos viejos suele ser un porcentaje recortado a 2 decimales.',
        ],
        'devengado_de_mas' => [
            'titulo' => 'Se devengó más de lo asignado',
            'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'La suma de lo devengado por los pagos supera la comisión asignada.',
        ],
        'cobrado_de_mas' => [
            'titulo' => 'Se cobró más que el total de la venta',
            'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'Los pagos vigentes suman más que el total de la venta.',
        ],
        'pago_no_positivo' => [
            'titulo' => 'Pago en cero o negativo',
            'severidad' => 'alta', 'unidad' => 'pesos',
            'ayuda' => 'Un pago vigente con monto menor o igual a cero.',
        ],
        'comision_huerfana' => [
            'titulo' => 'Comisión apunta a algo que ya no existe',
            'severidad' => 'alta', 'unidad' => 'texto',
            'ayuda' => 'La comisión vigente depende de una línea o de un colaborador que ya no está.',
        ],
        'cabecera_descuadrada' => [
            'titulo' => 'Subtotal, IVA y total no cuadran',
            'severidad' => 'media', 'unidad' => 'pesos',
            'ayuda' => 'Subtotal − descuento + IVA debería dar el total de la venta.',
        ],
        'cancelada_con_movimientos' => [
            'titulo' => 'Venta cancelada con pagos o comisiones vigentes',
            'severidad' => 'media', 'unidad' => 'texto',
            'ayuda' => 'La venta está cancelada pero conserva pagos o comisiones sin cancelar.',
        ],
        'venta_sin_lineas' => [
            'titulo' => 'Venta sin productos',
            'severidad' => 'media', 'unidad' => 'texto',
            'ayuda' => 'Una venta vigente que no tiene ninguna línea.',
        ],
        'posible_duplicado' => [
            'titulo' => 'Posible venta duplicada',
            'severidad' => 'media', 'unidad' => 'texto',
            'ayuda' => 'Mismo cliente, sucursal, día y total que otra venta vigente anterior.',
        ],
        'pago_antes_de_venta' => [
            'titulo' => 'Pago con fecha anterior a la venta',
            'severidad' => 'baja', 'unidad' => 'texto',
            'ayuda' => 'Puede ser legítimo en ventas importadas con fechas históricas.',
        ],
    ];

    /** Lo máximo que se trae por regla: con más que esto se avisa que hay más. */
    const TOPE_POR_REGLA = 1000;

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @param array $f  desde, hasta (Y-m-d), regla, severidad, sucursal, usuario, q
     * @return array ['hallazgos' => [...], 'resumen' => [regla => n],
     *                'truncadas' => [regla, ...]]
     */
    public function buscar(array $f)
    {
        list($donde, $params) = $this->condiciones($f);

        $hallazgos = []; $resumen = []; $truncadas = [];
        foreach (self::REGLAS as $clave => $regla) {
            if (!empty($f['regla'])     && $f['regla'] !== $clave) continue;
            if (!empty($f['severidad']) && $f['severidad'] !== $regla['severidad']) continue;

            $sql = $this->consultaDe($clave, $donde) . ' LIMIT ' . (self::TOPE_POR_REGLA + 1);
            try {
                $st = $this->db->prepare($sql);
                $st->execute($params);
                $filas = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Una regla que falla (tabla o columna que esta empresa aún no
                // tiene) no tumba a las demás: se anota y se sigue.
                error_log('[LibertyFin] incoherencias · ' . $clave . ': ' . $e->getMessage());
                continue;
            }
            if (count($filas) > self::TOPE_POR_REGLA) {
                $truncadas[] = $clave;
                $filas = array_slice($filas, 0, self::TOPE_POR_REGLA);
            }
            $resumen[$clave] = count($filas);
            foreach ($filas as $r) {
                $hallazgos[] = [
                    'regla'     => $clave,
                    'titulo'    => $regla['titulo'],
                    'severidad' => $regla['severidad'],
                    'unidad'    => $regla['unidad'],
                    'venta_id'  => (int)$r['venta_id'],
                    'codigo'    => $r['codigo'],
                    'fecha'     => $r['fecha'],
                    'usuario_id'  => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
                    'sucursal_id' => $r['sucursal_id'] !== null ? (int)$r['sucursal_id'] : null,
                    'actual'    => $r['actual'] !== null ? (float)$r['actual'] : null,
                    'esperado'  => $r['esperado'] !== null ? (float)$r['esperado'] : null,
                    'nota'      => (string)$r['nota'],
                ];
            }
        }

        // Lo más grave primero; a igual gravedad, lo más reciente.
        $peso = ['alta' => 0, 'media' => 1, 'baja' => 2];
        usort($hallazgos, function ($a, $b) use ($peso) {
            if ($a['severidad'] !== $b['severidad']) return $peso[$a['severidad']] - $peso[$b['severidad']];
            $c = strcmp((string)$b['fecha'], (string)$a['fecha']);
            return $c !== 0 ? $c : ($b['venta_id'] - $a['venta_id']);
        });

        return ['hallazgos' => $hallazgos, 'resumen' => $resumen, 'truncadas' => $truncadas];
    }

    /** El filtro común: siempre sobre la venta (alias `v`). */
    private function condiciones(array $f)
    {
        $w = []; $p = [];
        if (!empty($f['desde'])) { $w[] = 'v.fecha >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta'])) { $w[] = 'v.fecha < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $f['hasta']; }
        if (!empty($f['sucursal'])) { $w[] = 'v.sucursal_id = ?'; $p[] = (int)$f['sucursal']; }
        if (!empty($f['usuario']))  { $w[] = 'v.usuario_id = ?';  $p[] = (int)$f['usuario']; }
        if (!empty($f['q'])) {
            // Se escapan % y _ para que se busque el texto, no un patrón.
            $w[] = 'v.codigo_venta LIKE ?';
            $p[] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        return [$w ? ' AND ' . implode(' AND ', $w) : '', $p];
    }

    /**
     * El SQL de cada regla. Todas devuelven las mismas columnas:
     * venta_id, codigo, fecha, usuario_id, sucursal_id, actual, esperado, nota.
     * `{W}` es el filtro común (ya con su AND).
     */
    private function consultaDe($regla, $w)
    {
        $cols = "v.id AS venta_id, v.codigo_venta AS codigo, v.fecha AS fecha,
                 v.usuario_id AS usuario_id, v.sucursal_id AS sucursal_id";
        $grupo = "v.id, v.codigo_venta, v.fecha, v.usuario_id, v.sucursal_id";

        switch ($regla) {
            case 'comisiones_sobre_100':
                return "
                    SELECT $cols,
                           SUM(vc.porcentaje_regla) AS actual, 100 AS esperado,
                           COALESCE(MAX(p.nombre), 'Producto') AS nota
                    FROM venta_comisiones vc
                    JOIN ventas v ON v.id = vc.venta_id
                    LEFT JOIN venta_detalles vd ON vd.id = vc.venta_detalle_id
                    LEFT JOIN productos p ON p.id = vd.producto_id
                    WHERE vc.cancelada = 0 AND v.estado <> 'cancelada' $w
                    GROUP BY $grupo, vc.venta_detalle_id
                    HAVING SUM(vc.porcentaje_regla) > 100.0001
                    ORDER BY v.fecha DESC";

            case 'comision_monto':
                return "
                    SELECT $cols,
                           vc.monto_comision AS actual,
                           ROUND(vc.monto_base * vc.porcentaje_regla / 100, 2) AS esperado,
                           CONCAT(vc.colaborador_nombre, ' · ', TRIM(TRAILING '.' FROM
                                  TRIM(TRAILING '0' FROM vc.porcentaje_regla)), '%') AS nota
                    FROM venta_comisiones vc
                    JOIN ventas v ON v.id = vc.venta_id
                    WHERE vc.cancelada = 0 AND v.estado <> 'cancelada'
                      AND ABS(vc.monto_comision - ROUND(vc.monto_base * vc.porcentaje_regla / 100, 2)) > 0.05
                      $w
                    ORDER BY v.fecha DESC";

            case 'devengado_de_mas':
                return "
                    SELECT $cols,
                           SUM(pc.monto) AS actual, vc.monto_comision AS esperado,
                           vc.colaborador_nombre AS nota
                    FROM pago_comisiones pc
                    JOIN venta_comisiones vc ON vc.id = pc.venta_comision_id
                    JOIN ventas v ON v.id = vc.venta_id
                    WHERE vc.cancelada = 0 AND v.estado <> 'cancelada' $w
                    GROUP BY $grupo, vc.id, vc.monto_comision, vc.colaborador_nombre
                    HAVING SUM(pc.monto) > vc.monto_comision + 0.02
                    ORDER BY v.fecha DESC";

            case 'cobrado_de_mas':
                return "
                    SELECT $cols, pg.cobrado AS actual, v.total AS esperado,
                           CONCAT(pg.n, IF(pg.n = 1, ' pago', ' pagos')) AS nota
                    FROM ventas v
                    JOIN ( SELECT venta_id, SUM(monto) AS cobrado, COUNT(*) AS n
                           FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                      ON pg.venta_id = v.id
                    WHERE v.estado <> 'cancelada' AND pg.cobrado > v.total + 0.01 $w
                    ORDER BY v.fecha DESC";

            case 'pago_no_positivo':
                return "
                    SELECT $cols, p.monto AS actual, NULL AS esperado,
                           CONCAT('Pago del ', p.fecha_pago) AS nota
                    FROM venta_pagos p
                    JOIN ventas v ON v.id = p.venta_id
                    WHERE p.cancelado = 0 AND p.monto <= 0 AND v.estado <> 'cancelada' $w
                    ORDER BY v.fecha DESC";

            case 'comision_huerfana':
                return "
                    SELECT $cols, NULL AS actual, NULL AS esperado,
                           CASE WHEN vd.id IS NULL
                                THEN CONCAT(vc.colaborador_nombre, ': la línea de la venta ya no existe')
                                ELSE CONCAT(vc.colaborador_nombre, ': el colaborador ya no existe')
                           END AS nota
                    FROM venta_comisiones vc
                    JOIN ventas v ON v.id = vc.venta_id
                    LEFT JOIN venta_detalles vd ON vd.id = vc.venta_detalle_id
                    LEFT JOIN comision_colaboradores cc ON cc.id = vc.colaborador_id
                    WHERE vc.cancelada = 0 AND v.estado <> 'cancelada'
                      AND ( vd.id IS NULL OR (vc.colaborador_id IS NOT NULL AND cc.id IS NULL) )
                      $w
                    ORDER BY v.fecha DESC";

            case 'cabecera_descuadrada':
                return "
                    SELECT $cols, v.total AS actual,
                           ROUND(v.subtotal - v.descuento + v.iva, 2) AS esperado,
                           'Subtotal − descuento + IVA ≠ total' AS nota
                    FROM ventas v
                    WHERE v.estado <> 'cancelada'
                      AND ABS(v.subtotal - v.descuento + v.iva - v.total) > 0.02 $w
                    ORDER BY v.fecha DESC";

            case 'cancelada_con_movimientos':
                return "
                    SELECT $cols, NULL AS actual, NULL AS esperado,
                           CONCAT(
                             (SELECT COUNT(*) FROM venta_pagos p WHERE p.venta_id = v.id AND p.cancelado = 0),
                             ' pagos y ',
                             (SELECT COUNT(*) FROM venta_comisiones c WHERE c.venta_id = v.id AND c.cancelada = 0),
                             ' comisiones vigentes') AS nota
                    FROM ventas v
                    WHERE v.estado = 'cancelada'
                      AND ( EXISTS (SELECT 1 FROM venta_pagos p WHERE p.venta_id = v.id AND p.cancelado = 0)
                         OR EXISTS (SELECT 1 FROM venta_comisiones c WHERE c.venta_id = v.id AND c.cancelada = 0) )
                      $w
                    ORDER BY v.fecha DESC";

            case 'venta_sin_lineas':
                return "
                    SELECT $cols, NULL AS actual, NULL AS esperado, 'No tiene productos' AS nota
                    FROM ventas v
                    WHERE v.estado <> 'cancelada'
                      AND NOT EXISTS (SELECT 1 FROM venta_detalles vd WHERE vd.venta_id = v.id)
                      $w
                    ORDER BY v.fecha DESC";

            case 'posible_duplicado':
                return "
                    SELECT $cols, NULL AS actual, NULL AS esperado,
                           CONCAT('Igual a ', MIN(d.codigo_venta)) AS nota
                    FROM ventas v
                    JOIN ventas d ON d.id < v.id
                                 AND d.cliente_id  = v.cliente_id
                                 AND d.sucursal_id = v.sucursal_id
                                 AND d.total       = v.total
                                 AND DATE(d.fecha) = DATE(v.fecha)
                                 AND d.estado <> 'cancelada'
                    WHERE v.estado <> 'cancelada' AND v.cliente_id IS NOT NULL $w
                    GROUP BY $grupo
                    ORDER BY v.fecha DESC";

            case 'pago_antes_de_venta':
                return "
                    SELECT $cols, NULL AS actual, NULL AS esperado,
                           CONCAT('Pago del ', p.fecha_pago, ' · venta del ', DATE(v.fecha)) AS nota
                    FROM venta_pagos p
                    JOIN ventas v ON v.id = p.venta_id
                    WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                      AND p.fecha_pago < DATE(v.fecha) $w
                    ORDER BY v.fecha DESC";
        }
        throw new \InvalidArgumentException('Regla desconocida: ' . $regla);
    }
}
