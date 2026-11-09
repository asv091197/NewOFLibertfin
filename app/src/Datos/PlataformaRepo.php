<?php
namespace LibertyFin\Datos;

use LibertyFin\Servicio\Migraciones;
use PDO;

/**
 * La vista de TODAS las empresas, para soporte.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * Soporte entra con una sesión de empresa, así que ve una sola: la de
 * quien le prestó su cuenta. Para contestarle a un cliente "¿por qué mi
 * reporte sale en ceros?" hacía falta pedirle accesos, entrar como él y
 * mirar a mano — y eso no escala ni es correcto.
 *
 * Aquí se abre cada base, se le preguntan sus cifras y se cierra. Nada se
 * escribe: esto es de LECTURA.
 *
 * SOBRE EL COSTO
 *
 * Una consulta por empresa. Con decenas está bien; con cientos habría que
 * guardar un resumen en la base principal y refrescarlo por cron. Se hace
 * así porque hoy son decenas, y adelantarse a un problema que no existe
 * cuesta más de lo que ahorra.
 */
final class PlataformaRepo
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Las empresas con lo mínimo, sin abrir sus bases. */
    public function empresas($buscar = '')
    {
        $sql = "SELECT id, nombre_empresa, giro_comercial, rfc, telefono,
                       nombre_contacto, email_admin, usuario_admin,
                       nombre_base_datos, activo, plan, fecha_vencimiento,
                       no_distribuidor
                FROM empresas";
        $p = [];
        if ($buscar !== '') {
            $sql .= " WHERE nombre_empresa LIKE ? OR email_admin LIKE ?
                          OR rfc LIKE ? OR nombre_base_datos LIKE ?
                          OR nombre_contacto LIKE ?";
            $l = '%' . $buscar . '%';
            $p = [$l, $l, $l, $l, $l];
        }
        $sql .= " ORDER BY activo DESC, nombre_empresa";
        $st = $this->principal->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    /**
     * La ficha de una empresa: todo lo que sirve para contestarle.
     *
     * Cada bloque va en su propio try. Una tabla que no existe en esa
     * empresa no puede dejar la ficha en blanco: se reporta ese dato como
     * desconocido y lo demás se muestra igual.
     */
    public function ficha($empresaId)
    {
        $st = $this->principal->prepare("SELECT * FROM empresas WHERE id = ?");
        $st->execute([(int)$empresaId]);
        $e = $st->fetch();
        if (!$e) return null;

        $f = ['empresa' => $e, 'error' => null, 'esquema' => null,
              'usuarios' => [], 'cifras' => [], 'senales' => [],
              'sucursales' => [], 'docs' => null];

        $base = $e['nombre_base_datos'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $f['error'] = 'El nombre de la base no es válido';
            return $f;
        }

        try { $db = Conexion::de($base); }
        catch (\Throwable $ex) {
            $f['error'] = 'No se pudo abrir su base de datos';
            return $f;
        }

        try { $f['esquema'] = Migraciones::versionDe($db); } catch (\Throwable $ex) {}

        try {
            $f['usuarios'] = $this->filas($db, "
                SELECT u.id, u.username, u.nombre, u.email, u.rol, u.activo,
                       u.fecha_creacion, s.nombre AS sucursal,
                       (SELECT COUNT(*) FROM ventas v
                        WHERE v.usuario_id = u.id AND v.estado <> 'cancelada') AS ventas
                FROM usuarios u
                LEFT JOIN sucursales s ON s.id = u.sucursal_id
                ORDER BY u.activo DESC, u.nombre");
        } catch (\Throwable $ex) {}

        try {
            $f['sucursales'] = $this->filas($db,
                "SELECT id, nombre, es_matriz, activo FROM sucursales ORDER BY es_matriz DESC, nombre");
        } catch (\Throwable $ex) {}

        try {
            $f['cifras'] = $this->fila($db, "
                SELECT
                  (SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada') AS ventas,
                  (SELECT MAX(fecha) FROM ventas WHERE estado <> 'cancelada') AS ultima_venta,
                  (SELECT COUNT(*) FROM clientes) AS clientes,
                  (SELECT COUNT(*) FROM productos WHERE activo = 1) AS servicios,
                  (SELECT COUNT(*) FROM caja WHERE estado = 'abierta') AS cajas_abiertas,
                  (SELECT COALESCE(SUM(p.monto),0) FROM venta_pagos p
                     INNER JOIN ventas v ON v.id = p.venta_id
                     WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                       AND p.fecha_pago >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS cobrado_mes,
                  (SELECT COALESCE(SUM(v.total - COALESCE(pg.c,0)),0)
                     FROM ventas v
                     LEFT JOIN (SELECT venta_id, SUM(monto) c FROM venta_pagos
                                WHERE cancelado = 0 GROUP BY venta_id) pg ON pg.venta_id = v.id
                     WHERE v.estado <> 'cancelada' AND v.total - COALESCE(pg.c,0) > 0.01) AS por_cobrar");
        } catch (\Throwable $ex) {}

        // Las señales que suelen estar detrás de una llamada de soporte.
        // Cada una dice qué SÍNTOMA produce, no solo que hay un número.
        $senales = [
            ['ventas_sin_area', 'Ventas sin área',
             "SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada'
                AND (area_nombre IS NULL OR area_nombre = '')",
             'Sus reportes por área salen vacíos'],
            ['desfasadas', 'Fechas desfasadas',
             "SELECT COUNT(*) FROM ventas WHERE codigo_venta REGEXP '^[0-9]{14}\$'
                AND ABS(TIMESTAMPDIFF(MINUTE, STR_TO_DATE(codigo_venta,'%Y%m%d%H%i%s'), fecha)) > 5",
             'Ventas contadas en el mes equivocado'],
            ['sobrecobro', 'Cobrado mayor al total',
             "SELECT COUNT(*) FROM ventas v
                LEFT JOIN (SELECT venta_id, SUM(monto) c FROM venta_pagos
                           WHERE cancelado = 0 GROUP BY venta_id) p ON p.venta_id = v.id
                WHERE v.estado <> 'cancelada' AND COALESCE(p.c,0) - v.total > 0.01",
             'Alguien cobró de más'],
            ['sin_dueno', 'Comisiones sin dueño',
             "SELECT COUNT(*) FROM venta_comisiones
                WHERE cancelada = 0 AND colaborador_nombre = 'POR ASIGNAR'",
             'Cuentan en el total y no se pagan'],
            ['sin_cliente', 'Ventas sin cliente',
             "SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada' AND cliente_id IS NULL",
             'No se pueden perseguir ni facturar'],
            ['sin_precio', 'Productos en cero',
             "SELECT COUNT(*) FROM productos
                WHERE activo = 1 AND COALESCE(NULLIF(subprecio,0), precio, 0) <= 0",
             'Hay que ponerles precio al vender'],
        ];
        foreach ($senales as $s) {
            try { $v = (int)$this->valor($db, $s[2]); }
            catch (\Throwable $ex) { $v = null; }
            $f['senales'][$s[0]] = ['rotulo' => $s[1], 'valor' => $v, 'porque' => $s[3]];
        }

        try {
            $f['docs'] = (new CuentaRepo($db))->estadoDocumentacion();
        } catch (\Throwable $ex) {}

        try {
            $cfg = new ConfigRepo($db);
            $f['marca'] = ['color' => $cfg->valorDe('marca.color', ''),
                           'logo'  => $cfg->valorDe('marca.logo', '')];
            $f['secciones'] = $cfg->secciones();
            $f['metodos']   = $cfg->metodos();
        } catch (\Throwable $ex) {}

        return $f;
    }

    /** El resumen de la plataforma: lo que va arriba del panel. */
    public function resumen()
    {
        $r = ['empresas' => 0, 'activas' => 0, 'por_vencer' => 0, 'vencidas' => 0,
              'atrasadas' => 0, 'con_problemas' => 0];
        try {
            $f = $this->principal->query("
                SELECT COUNT(*) total,
                       SUM(activo = 1) activas,
                       SUM(activo = 1 AND fecha_vencimiento IS NOT NULL
                           AND fecha_vencimiento BETWEEN CURDATE()
                           AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)) por_vencer,
                       SUM(fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()) vencidas
                FROM empresas")->fetch();
            $r['empresas']   = (int)($f['total'] ?? 0);
            $r['activas']    = (int)($f['activas'] ?? 0);
            $r['por_vencer'] = (int)($f['por_vencer'] ?? 0);
            $r['vencidas']   = (int)($f['vencidas'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] resumen plataforma: ' . $e->getMessage());
        }
        return $r;
    }

    /** Estado rápido de cada empresa, para la lista. Un solo viaje por base. */
    public function pulso($base)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) return null;
        try {
            $db = Conexion::de($base);
            $f = $this->fila($db, "
                SELECT (SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada') AS ventas,
                       (SELECT MAX(fecha) FROM ventas WHERE estado <> 'cancelada') AS ultima,
                       (SELECT COUNT(*) FROM usuarios WHERE activo = 1) AS usuarios,
                       (SELECT COUNT(*) FROM caja WHERE estado = 'abierta') AS cajas");
            $f['esquema'] = Migraciones::versionDe($db);
            return $f;
        } catch (\Throwable $e) { return null; }
    }

    // ── Ayudantes ──
    private function filas(PDO $db, $sql) { return $db->query($sql)->fetchAll(); }
    private function fila(PDO $db, $sql)  { return $db->query($sql)->fetch() ?: []; }
    private function valor(PDO $db, $sql) { return $db->query($sql)->fetchColumn(); }
}
