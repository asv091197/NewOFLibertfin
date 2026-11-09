<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Recalcula lo devengado de TODAS las ventas, y lo puede deshacer.
 *
 * POR QUÉ HACE FALTA
 *
 * Lo devengado de cada pago (pago_comisiones) solo se recalcula cuando algo
 * cambia DENTRO del sistema: un pago, una comisión. Si una venta se corrige
 * directo en la base —"normalizar" su total, cancelar o mover pagos— nadie
 * lo recalcula y la comisión queda repartida según cómo estaba antes. Pasó
 * con la venta 20260825123905: sus pagos de agosto quedaron en $0 y el de
 * octubre se llevó los $2,160 completos, así que octubre salía con una
 * venta en negativo y agosto con comisiones de menos.
 *
 * QUÉ HACE
 *
 * Por cada venta: guarda sus renglones de pago_comisiones, la recalcula con
 * SincronizarComisiones (la misma cuenta de siempre) y compara. Si quedó
 * igual, no deja rastro. Si cambió, guarda cómo estaba —para poder
 * deshacer— y qué se movió de un mes a otro.
 *
 * DESHACER, SIN BORRAR LO NUEVO
 *
 * Se regresa cada venta a como estaba, SOLO si sigue exactamente como la
 * dejó el recálculo (se compara una huella). Si después entró un pago o se
 * asignó una comisión, el sistema ya la volvió a calcular con eso, y
 * regresarla borraría lo nuevo: esa venta se deja como está y se avisa.
 *
 * No toca venta_comisiones (quién cobra y cuánto, si liquida) ni el
 * pendiente de los gastos que cambian después de asignar: solo reparte lo
 * devengado entre los pagos que la venta tiene hoy.
 */
final class RecalcularComisiones
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // Las tablas (comision_recalculos y comision_recalculo_ventas) las crea
    // Servicio\Migraciones, versión 18, como el resto del esquema.

    /**
     * Recalcula todas las ventas (no canceladas) con comisiones.
     * @return array ['id' => int, 'revisadas' => int, 'cambiadas' => int, 'errores' => int]
     */
    public function todas()
    {
        // Con muchas ventas tarda unos segundos: que el hosting no lo corte.
        if (function_exists('set_time_limit')) @set_time_limit(300);

        $this->db->prepare("
            INSERT INTO comision_recalculos (creado_en, usuario_id, usuario_nombre)
            VALUES (NOW(), ?, ?)")
            ->execute([($_SESSION['usuario_id'] ?? 0) ?: null, $_SESSION['usuario_nombre'] ?? '']);
        $id = (int)$this->db->lastInsertId();

        $ventas = $this->db->query("
            SELECT v.id FROM ventas v
            WHERE v.estado <> 'cancelada'
              AND ( EXISTS (SELECT 1 FROM venta_comisiones vc WHERE vc.venta_id = v.id)
                 OR EXISTS (SELECT 1 FROM pago_comisiones pc WHERE pc.venta_id = v.id) )
            ORDER BY v.id")->fetchAll(PDO::FETCH_COLUMN);

        $sinc = new SincronizarComisiones($this->db);
        $guardar = $this->db->prepare("
            INSERT INTO comision_recalculo_ventas
                (recalculo_id, venta_id, antes, despues, meses, huella, filas_antes)
            VALUES (?,?,?,?,?,?,?)");
        $cambiadas = 0; $errores = 0;
        foreach ($ventas as $ventaId) {
            $antes = $this->filas($ventaId);
            // Una venta con datos rotos no detiene a las demás: paraVenta
            // deshace lo suyo si falla, y esa venta se queda como estaba.
            try {
                $sinc->paraVenta($ventaId);
            } catch (\Throwable $e) {
                error_log('[LibertyFin] recalcular comisiones, venta ' . $ventaId . ': ' . $e->getMessage());
                $errores++;
                continue;
            }
            $despues = $this->filas($ventaId);
            if (self::huella($antes) === self::huella($despues)) continue;

            $guardar->execute([
                $id, (int)$ventaId,
                round(array_sum(array_column($antes, 'monto')), 2),
                round(array_sum(array_column($despues, 'monto')), 2),
                json_encode(self::meses($antes, $despues)),
                self::huella($despues),
                json_encode($antes, JSON_UNESCAPED_UNICODE),
            ]);
            $cambiadas++;
        }

        $this->db->prepare("UPDATE comision_recalculos SET ventas_revisadas = ?, ventas_cambiadas = ? WHERE id = ?")
                 ->execute([count($ventas), $cambiadas, $id]);
        return ['id' => $id, 'revisadas' => count($ventas), 'cambiadas' => $cambiadas, 'errores' => $errores];
    }

    /**
     * Regresa las ventas de un recálculo a como estaban.
     * @return array ['regresadas' => int, 'saltadas' => int]
     */
    public function deshacer($recalculoId)
    {
        $st = $this->db->prepare("SELECT * FROM comision_recalculos WHERE id = ?");
        $st->execute([(int)$recalculoId]);
        $r = $st->fetch();
        if (!$r) throw new \InvalidArgumentException('Ese recálculo no existe');
        if ($r['deshecho_en']) throw new \InvalidArgumentException('Ese recálculo ya se deshizo');

        // Las columnas que hoy tiene pago_comisiones: solo esas se vuelven a
        // escribir (y así ningún nombre de columna sale de lo guardado).
        $cols = $this->db->query("SHOW COLUMNS FROM pago_comisiones")->fetchAll(PDO::FETCH_COLUMN);

        $st = $this->db->prepare("SELECT * FROM comision_recalculo_ventas WHERE recalculo_id = ? AND deshecha = 0");
        $st->execute([(int)$recalculoId]);
        $regresadas = 0; $saltadas = 0;
        foreach ($st->fetchAll() as $v) {
            if (self::huella($this->filas($v['venta_id'])) !== $v['huella']) { $saltadas++; continue; }
            $filas = json_decode((string)$v['filas_antes'], true) ?: [];

            $this->db->beginTransaction();
            try {
                $this->db->prepare("DELETE FROM pago_comisiones WHERE venta_id = ?")->execute([(int)$v['venta_id']]);
                foreach ($filas as $f) {
                    $f = array_intersect_key($f, array_flip($cols));
                    if (!$f) continue;
                    $this->db->prepare("INSERT INTO pago_comisiones (`" . implode('`,`', array_keys($f)) . "`)
                                        VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")
                             ->execute(array_values($f));
                }
                $this->db->prepare("UPDATE comision_recalculo_ventas SET deshecha = 1 WHERE id = ?")
                         ->execute([(int)$v['id']]);
                $this->db->commit();
                $regresadas++;
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        $this->db->prepare("UPDATE comision_recalculos SET deshecho_en = NOW(), deshecho_por = ? WHERE id = ?")
                 ->execute([$_SESSION['usuario_nombre'] ?? '', (int)$recalculoId]);
        return ['regresadas' => $regresadas, 'saltadas' => $saltadas];
    }

    /**
     * El último recálculo de los últimos 30 días QUE CAMBIÓ ALGO y no se ha
     * deshecho, con sus ventas, para la tarjeta de Comisiones. Si se vuelve a
     * recalcular y nada cambia, ese intento vacío no esconde el Deshacer del
     * anterior. Al deshacer uno, aparece el de antes: se deshacen en orden,
     * como una pila. null si no hay (o si las tablas no existen aún).
     */
    public function ultimo()
    {
        try {
            $r = $this->db->query("
                SELECT * FROM comision_recalculos
                WHERE creado_en >= NOW() - INTERVAL 30 DAY
                  AND ventas_cambiadas > 0 AND deshecho_en IS NULL
                ORDER BY id DESC LIMIT 1")->fetch();
        } catch (\Throwable $e) { return null; }
        if (!$r) return null;
        $st = $this->db->prepare("
            SELECT cv.venta_id, cv.antes, cv.despues, cv.meses, cv.deshecha, cv.huella, cv.filas_antes,
                   v.codigo_venta AS folio, COALESCE(c.nombre, 'Público general') AS cliente
            FROM comision_recalculo_ventas cv
            INNER JOIN ventas v   ON v.id = cv.venta_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE cv.recalculo_id = ?
            ORDER BY ABS(cv.despues - cv.antes) DESC, cv.venta_id");
        $st->execute([(int)$r['id']]);
        $r['ventas'] = $st->fetchAll();
        foreach ($r['ventas'] as &$x) {
            $x['meses'] = json_decode((string)$x['meses'], true) ?: [];
            // QUÉ CAMBIÓ DENTRO DE LA VENTA, cuando el total y los meses quedan
            // igual: entre qué personas o entre qué pagos se reacomodó. Se
            // compara lo guardado con lo de hoy, solo si la venta sigue como
            // la dejó el recálculo (si no, "hoy" ya trae otros cambios).
            $antes = json_decode((string)$x['filas_antes'], true) ?: [];
            $ahora = $this->filas($x['venta_id']);
            $x['al_dia']   = self::huella($ahora) === $x['huella'];
            $x['personas'] = $x['al_dia'] ? self::diferencias($antes, $ahora, 'colaborador_nombre') : [];
            $x['pagos']    = $x['al_dia'] ? count(self::diferencias($antes, $ahora, 'pago_id')) : 0;
            unset($x['filas_antes'], $x['huella']);
        }
        unset($x);
        return $r;
    }

    /** Cuánto cambió cada valor de `$campo` (persona, pago…): [valor => diferencia]. */
    private static function diferencias(array $antes, array $despues, $campo)
    {
        $d = [];
        foreach ($antes as $f)   { $k = (string)($f[$campo] ?? ''); $d[$k] = ($d[$k] ?? 0) - (float)$f['monto']; }
        foreach ($despues as $f) { $k = (string)($f[$campo] ?? ''); $d[$k] = ($d[$k] ?? 0) + (float)$f['monto']; }
        return array_filter(array_map(function ($x) { return round($x, 2); }, $d),
                            function ($x) { return abs($x) >= 0.005; });
    }

    private function filas($ventaId)
    {
        $st = $this->db->prepare("SELECT * FROM pago_comisiones WHERE venta_id = ? ORDER BY id");
        $st->execute([(int)$ventaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lo que importa de lo devengado: qué pago, qué comisión, cuánto y de qué fecha. */
    private static function huella(array $filas)
    {
        $l = array_map(function ($f) {
            return (int)$f['pago_id'] . '|' . (int)$f['venta_comision_id'] . '|'
                 . number_format((float)$f['monto'], 2, '.', '') . '|' . substr((string)$f['fecha_pago'], 0, 10);
        }, $filas);
        sort($l);
        return md5(implode("\n", $l));
    }

    /** Cuánto cambió cada mes: ['2026-08' => 1440.0, '2026-10' => -1440.0]. */
    private static function meses(array $antes, array $despues)
    {
        $m = [];
        foreach ($antes as $f)   { $k = substr((string)$f['fecha_pago'], 0, 7); $m[$k] = ($m[$k] ?? 0) - (float)$f['monto']; }
        foreach ($despues as $f) { $k = substr((string)$f['fecha_pago'], 0, 7); $m[$k] = ($m[$k] ?? 0) + (float)$f['monto']; }
        $m = array_filter(array_map(function ($x) { return round($x, 2); }, $m), function ($x) { return abs($x) >= 0.005; });
        ksort($m);
        return $m;
    }
}
