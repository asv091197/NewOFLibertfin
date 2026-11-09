<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Registro de quién cambió qué.
 *
 * DOS REGLAS QUE MANDAN SOBRE TODO LO DEMÁS
 *
 *  1. Auditar NUNCA puede tumbar la operación. Si la tabla no existe, si
 *     el disco está lleno, si el JSON no cabe: se anota en el log del
 *     servidor y la venta se cobra igual. Un sistema donde no se puede
 *     cobrar porque falló la bitácora es peor que uno sin bitácora.
 *
 *  2. No se registra todo. Solo lo que alguien podría querer negar o
 *     necesitar reconstruir: cancelar un pago, mover una comisión,
 *     cambiar un precio, tocar una cuenta ajena. Auditar cada lectura
 *     llena la tabla de ruido y esconde justo lo que importa.
 *
 * Se guarda el ANTES y el DESPUÉS, no solo el después: "cambió el precio
 * a $999" no sirve si nadie recuerda cuánto era.
 */
final class Auditoria
{
    /** Lo que se audita, y cómo se lee. */
    const ACCIONES = [
        'venta.ampliar'     => 'Agregó servicios a una venta',
        'venta.importar'    => 'Importó ventas de una hoja de cálculo',
        'venta.cancelar'    => 'Canceló una venta',
        'ajustes.modulo'    => 'Encendió o apagó un módulo',
        'inventario.conteo' => 'Capturó un conteo general de inventario',
        'compra.recibir'    => 'Recibió una compra a proveedor',
        'compra.cancelar'   => 'Canceló una compra a proveedor',
        'cotizacion.estado' => 'Cambió el estado de una cotización',
        'iguala.guardar'    => 'Creó o cambió una iguala',
        'iguala.generar'    => 'Generó los cargos de igualas del mes',
        'expediente.archivo'=> 'Subió o borró un archivo del expediente',
        'pago.cancelar'     => 'Canceló un pago',
        'pago.registrar'    => 'Registró un pago',
        'comision.asignar'  => 'Asignó una comisión',
        'comision.quitar'   => 'Quitó una comisión',
        'comision.reasignar'=> 'Reasignó una comisión',
        'comision.lote'     => 'Asignó comisiones en lote',
        'comision.recalcular' => 'Recalculó las comisiones de todas las ventas',
        'comision.deshacer' => 'Deshizo un recálculo de comisiones',
        'servicio.precio'   => 'Cambió un precio',
        'servicio.alternar' => 'Activó o desactivó un servicio',
        'promocion.guardar' => 'Creó o cambió una promoción',
        'promocion.estado'  => 'Pausó o reanudó una promoción',
        'promocion.borrar'  => 'Borró una promoción',
        'gasto.borrar'      => 'Borró un gasto',
        'caja.abrir'        => 'Abrió la caja',
        'caja.cerrar'       => 'Cerró la caja',
        'usuario.crear'     => 'Creó un usuario',
        'usuario.editar'    => 'Editó un usuario',
        'usuario.rol'       => 'Cambió un rol',
        'usuario.clave'     => 'Restableció una contraseña',
        'usuario.alternar'  => 'Bloqueó o desbloqueó una cuenta',
        'ajustes.cambiar'   => 'Cambió la configuración',
        'empresa.datos'     => 'Cambió los datos de la empresa',
        'doc.revisar'       => 'Revisó un documento',
        'empresa.alta'      => 'Dio de alta una empresa',
        'seccion.alternar'  => 'Encendió o apagó una sección',
        'sesion.reemplazar' => 'Cerró su sesión en otro dispositivo al entrar',
    ];

    private static $db = null;

    /** La base donde se escribe. Suele ser la de la empresa. */
    public static function en(PDO $db) { self::$db = $db; }

    /**
     * Anota un cambio.
     *
     * @param string $accion  una clave de ACCIONES
     * @param string $sobre   qué se tocó, legible: "venta 137", "Gisselle González"
     * @param mixed  $antes   cómo estaba
     * @param mixed  $despues cómo quedó
     */
    public static function anota($accion, $sobre, $antes = null, $despues = null, PDO $db = null)
    {
        $conexion = $db ?: self::$db;
        if (!$conexion) return false;

        try {
            $conexion->prepare("
                INSERT INTO lf_auditoria
                    (accion, sobre, antes, despues, usuario_id, usuario_nombre,
                     usuario_rol, ip, creado_en)
                VALUES (?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                (string)$accion,
                mb_substr((string)$sobre, 0, 200),
                self::texto($antes),
                self::texto($despues),
                ($_SESSION['usuario_id'] ?? 0) ?: null,
                mb_substr((string)($_SESSION['usuario_nombre'] ?? ''), 0, 160),
                mb_substr((string)($_SESSION['usuario_rol'] ?? ''), 0, 40),
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
            return true;
        } catch (\Throwable $e) {
            // Se anota el fallo y se sigue. Ver la regla 1.
            error_log('[LibertyFin] auditoría (' . $accion . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Como `anota`, con el DETALLE de una operación en lote aparte: la
     * lista de lo que tocó, para desplegarla en la bitácora.
     *
     * `antes` y `despues` se recortan a mil caracteres y ahí no cabe la
     * lista de 300 ventas de una asignación en lote. El detalle va en su
     * propia columna, sin recortar.
     *
     * Si la columna todavía no existe (la base se pone al día al entrar,
     * y quien ya tenía sesión abierta no ha entrado), se anota sin
     * detalle. Ver la regla 1.
     */
    public static function anotaConDetalle($accion, $sobre, $antes, $despues, array $detalle, PDO $db = null)
    {
        $conexion = $db ?: self::$db;
        if (!$conexion) return false;

        $json = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) return self::anota($accion, $sobre, $antes, $despues, $db);

        try {
            $conexion->prepare("
                INSERT INTO lf_auditoria
                    (accion, sobre, antes, despues, detalle, usuario_id, usuario_nombre,
                     usuario_rol, ip, creado_en)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                (string)$accion,
                mb_substr((string)$sobre, 0, 200),
                self::texto($antes),
                self::texto($despues),
                $json,
                ($_SESSION['usuario_id'] ?? 0) ?: null,
                mb_substr((string)($_SESSION['usuario_nombre'] ?? ''), 0, 160),
                mb_substr((string)($_SESSION['usuario_rol'] ?? ''), 0, 40),
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] auditoría con detalle (' . $accion . '): ' . $e->getMessage());
            return self::anota($accion, $sobre, $antes, $despues, $db);
        }
    }

    /**
     * Convierte un valor a algo guardable y legible.
     * Un arreglo grande se recorta: la bitácora es para entender, no para
     * volcar el estado completo de la base.
     */
    private static function texto($v)
    {
        if ($v === null) return null;
        if (is_scalar($v)) return mb_substr((string)$v, 0, 500);
        $j = json_encode($v, JSON_UNESCAPED_UNICODE);
        return mb_substr((string)$j, 0, 1000);
    }

    /** Un resumen de lo que cambió entre dos arreglos, solo lo distinto. */
    public static function diferencia(array $antes, array $despues, array $campos = [])
    {
        $a = []; $d = [];
        $llaves = $campos ?: array_keys($despues);
        foreach ($llaves as $k) {
            $va = $antes[$k] ?? null;
            $vd = $despues[$k] ?? null;
            if ((string)$va === (string)$vd) continue;
            $a[$k] = $va; $d[$k] = $vd;
        }
        return [$a, $d];
    }

    // ── Lectura ─────────────────────────────────────────────────

    public static function listado(PDO $db, $filtros = [], $limite = 50, $desfase = 0)
    {
        $w = []; $p = [];
        if (!empty($filtros['accion']))  { $w[] = 'accion = ?';     $p[] = $filtros['accion']; }
        if (!empty($filtros['usuario'])) { $w[] = 'usuario_id = ?'; $p[] = (int)$filtros['usuario']; }
        if (!empty($filtros['desde']))   { $w[] = 'creado_en >= ?'; $p[] = $filtros['desde'] . ' 00:00:00'; }
        if (!empty($filtros['hasta']))   { $w[] = 'creado_en < ?';  $p[] = date('Y-m-d', strtotime($filtros['hasta'] . ' +1 day')) . ' 00:00:00'; }
        if (!empty($filtros['q'])) {
            $w[] = '(sobre LIKE ? OR usuario_nombre LIKE ?)';
            $l = '%' . $filtros['q'] . '%'; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        try {
            $st = $db->prepare("SELECT * FROM lf_auditoria {$where}
                ORDER BY creado_en DESC, id DESC
                LIMIT " . (int)$limite . " OFFSET " . (int)$desfase);
            $st->execute($p);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    public static function cuantos(PDO $db, $filtros = [])
    {
        $w = []; $p = [];
        if (!empty($filtros['accion']))  { $w[] = 'accion = ?';     $p[] = $filtros['accion']; }
        if (!empty($filtros['usuario'])) { $w[] = 'usuario_id = ?'; $p[] = (int)$filtros['usuario']; }
        if (!empty($filtros['desde']))   { $w[] = 'creado_en >= ?'; $p[] = $filtros['desde'] . ' 00:00:00'; }
        if (!empty($filtros['hasta']))   { $w[] = 'creado_en < ?';  $p[] = date('Y-m-d', strtotime($filtros['hasta'] . ' +1 day')) . ' 00:00:00'; }
        if (!empty($filtros['q'])) {
            $w[] = '(sobre LIKE ? OR usuario_nombre LIKE ?)';
            $l = '%' . $filtros['q'] . '%'; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM lf_auditoria {$where}");
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    /** Quién hizo más movimientos, y de qué tipo. */
    public static function porUsuario(PDO $db, $desde, $hasta)
    {
        try {
            $st = $db->prepare("
                SELECT usuario_id, usuario_nombre, usuario_rol, COUNT(*) AS cuantos,
                       MAX(creado_en) AS ultimo
                FROM lf_auditoria
                WHERE creado_en >= ? AND creado_en < ?
                GROUP BY usuario_id, usuario_nombre, usuario_rol
                ORDER BY cuantos DESC LIMIT 12");
            $st->execute([$desde . ' 00:00:00',
                          date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00']);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    public static function porAccion(PDO $db, $desde, $hasta)
    {
        try {
            $st = $db->prepare("
                SELECT accion, COUNT(*) AS cuantos FROM lf_auditoria
                WHERE creado_en >= ? AND creado_en < ?
                GROUP BY accion ORDER BY cuantos DESC");
            $st->execute([$desde . ' 00:00:00',
                          date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00']);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }
}
