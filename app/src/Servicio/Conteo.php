<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Un inventario físico que se puede dejar a medias y retomar.
 *
 * POR QUÉ NO BASTABA `conteoGeneral()`
 *
 * El conteo que ya existía era de un solo golpe: se abría la pantalla,
 * se capturaba todo y se mandaba. Eso describe un almacén chico y una
 * tarde libre. Un inventario de tienda de verdad se hace entre clientes,
 * en varias sentadas, a veces entre dos personas, y a media captura
 * llega alguien a pagar. Con el formulario de un solo golpe, cerrar la
 * pestaña tiraba el trabajo de dos horas.
 *
 * Aquí el conteo es una SESIÓN: se abre, se van guardando renglones
 * conforme se cuentan, se pausa, se reanuda, y solo al finalizar se
 * tocan las existencias.
 *
 * NADA SE MUEVE HASTA EL FINAL, Y ES A PROPÓSITO
 *
 * Mientras el conteo está abierto, la tienda sigue vendiendo. Si cada
 * renglón ajustara la existencia al capturarlo, una venta hecha entre
 * «conté 12» y «guardé 12» se perdería: el conteo la pisaría. Al
 * finalizar, cada renglón se vuelve a comparar contra lo que hay EN ESE
 * MOMENTO, así que lo que se vendió durante el conteo no se borra.
 *
 * Por eso también se guarda `esperado`: lo que el sistema creía cuando
 * se contó. Sirve para enseñar la diferencia mientras se cuenta, pero no
 * es lo que se usa para ajustar.
 */
final class Conteo
{
    const ESTADOS = [
        'abierto'   => 'En curso',
        'pausado'   => 'Pausado',
        'cerrado'   => 'Finalizado',
        'cancelado' => 'Cancelado',
    ];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // ════════════════════════════════════════════════════════
    //  LA SESIÓN
    // ════════════════════════════════════════════════════════

    /**
     * Abre un conteo. Si ya hay uno sin terminar en ese almacén, lo
     * devuelve en vez de abrir otro: dos conteos simultáneos del mismo
     * anaquel terminan peleándose, y el segundo borra el primero.
     */
    public function abrir($sucursalId, $almacenId = null, $nombre = '', $alcance = 'general')
    {
        $suc = (int)$sucursalId;
        $alm = (int)($almacenId ?: (new Almacenes($this->db))->principal($suc)['id']);

        if ($abierto = $this->enCurso($suc, $alm)) return $abierto;

        $nombre = mb_substr(trim((string)$nombre), 0, 120) ?: ('Conteo del ' . date('d/m/Y'));
        $this->db->prepare("
            INSERT INTO lf_inventario_conteos
                (sucursal_id, almacen_id, nombre, alcance, estado, usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?,?, 'abierto', ?,?, NOW())")
            ->execute([$suc, $alm, $nombre, in_array($alcance, ['general','parcial'], true) ? $alcance : 'general',
                       $_SESSION['usuario_id'] ?? null, $_SESSION['usuario_nombre'] ?? null]);
        return $this->porId((int)$this->db->lastInsertId());
    }

    /**
     * El conteo sin terminar de un almacén, si lo hay.
     *
     * Devuelve lo MISMO que porId() —con el nombre del almacén y de la
     * sucursal— y no la fila pelada. Los dos alimentan la misma pantalla,
     * y que uno trajera menos columnas que el otro dejaba el encabezado
     * sin el nombre del almacén según por dónde se hubiera llegado.
     */
    public function enCurso($sucursalId, $almacenId = null)
    {
        $p = [(int)$sucursalId];
        $w = '';
        if ($almacenId) { $w = ' AND almacen_id = ?'; $p[] = (int)$almacenId; }
        $id = $this->valor("
            SELECT id FROM lf_inventario_conteos
             WHERE sucursal_id = ?" . $w . " AND estado IN ('abierto','pausado')
             ORDER BY id DESC LIMIT 1", $p);
        return $id ? $this->porId((int)$id) : null;
    }

    public function porId($id)
    {
        return $this->uno("
            SELECT c.*, a.nombre AS almacen, s.nombre AS sucursal
              FROM lf_inventario_conteos c
              LEFT JOIN lf_almacenes a ON a.id = c.almacen_id
              LEFT JOIN sucursales s   ON s.id = c.sucursal_id
             WHERE c.id = ?", [(int)$id]);
    }

    /** Los últimos conteos de una sucursal, para la lista. */
    public function recientes($sucursalId, $tope = 20)
    {
        return $this->todos("
            SELECT c.*, a.nombre AS almacen,
                   (SELECT COUNT(*) FROM lf_inventario_conteo_lineas l
                     WHERE l.conteo_id = c.id AND l.contado IS NOT NULL) AS capturados
              FROM lf_inventario_conteos c
              LEFT JOIN lf_almacenes a ON a.id = c.almacen_id
             WHERE c.sucursal_id = ?
             ORDER BY c.id DESC LIMIT " . max(1, min(100, (int)$tope)), [(int)$sucursalId]);
    }

    public function pausar($id)
    {
        $c = $this->abiertoO($id);
        if ($c['estado'] === 'pausado') return $c;
        $this->db->prepare("UPDATE lf_inventario_conteos SET estado='pausado', pausado_en=NOW() WHERE id=?")
                 ->execute([(int)$id]);
        return $this->porId($id);
    }

    public function reanudar($id)
    {
        $c = $this->abiertoO($id);
        $this->db->prepare("UPDATE lf_inventario_conteos SET estado='abierto', pausado_en=NULL WHERE id=?")
                 ->execute([(int)$id]);
        return $this->porId($id);
    }

    /** Se abandona sin tocar existencias. Queda el registro de que se intentó. */
    public function cancelar($id, $motivo = '')
    {
        $this->abiertoO($id);
        $this->db->prepare("UPDATE lf_inventario_conteos
                            SET estado='cancelado', cerrado_en=NOW(), nota=? WHERE id=?")
                 ->execute([mb_substr(trim((string)$motivo), 0, 255) ?: null, (int)$id]);
        return true;
    }

    // ════════════════════════════════════════════════════════
    //  CONTAR
    // ════════════════════════════════════════════════════════

    /**
     * Guarda lo contado de un producto. Se llama una vez por renglón,
     * conforme se cuenta, para que nada dependa de llegar al final.
     *
     * Con `null` se borra la captura: contar «0» y «no lo he contado» son
     * cosas distintas, y confundirlas ajustaría a cero lo que nadie miró.
     */
    public function capturar($conteoId, $productoId, $contado)
    {
        $c = $this->abiertoO($conteoId);
        $productoId = (int)$productoId;

        $lleva = $this->valor("SELECT 1 FROM productos WHERE id = ? AND lleva_inventario = 1", [$productoId]);
        if (!$lleva) throw new \InvalidArgumentException('Ese producto no lleva existencias.');

        if ($contado === null || trim((string)$contado) === '') {
            $this->db->prepare("DELETE FROM lf_inventario_conteo_lineas WHERE conteo_id=? AND producto_id=?")
                     ->execute([(int)$conteoId, $productoId]);
            return ['contado' => null, 'esperado' => $this->esperado($c, $productoId), 'diferencia' => null];
        }

        $n = str_replace(',', '', trim((string)$contado));
        if (!is_numeric($n) || (float)$n < 0) {
            throw new \InvalidArgumentException('Lo contado debe ser un número de cero en adelante.');
        }
        $n = round((float)$n, 3);
        $esperado = $this->esperado($c, $productoId);

        $this->db->prepare("
            INSERT INTO lf_inventario_conteo_lineas
                (conteo_id, producto_id, esperado, contado, contado_en, usuario_id)
            VALUES (?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE esperado=VALUES(esperado), contado=VALUES(contado),
                                    contado_en=NOW(), usuario_id=VALUES(usuario_id)")
            ->execute([(int)$conteoId, $productoId, $esperado, $n, $_SESSION['usuario_id'] ?? null]);

        return ['contado' => $n, 'esperado' => $esperado, 'diferencia' => round($n - $esperado, 3)];
    }

    /** Lo capturado hasta ahora, con lo que el sistema cree que hay AHORA. */
    public function lineas($conteoId, $soloDiferencias = false)
    {
        $c = $this->porId($conteoId);
        if (!$c) return [];
        $w = $soloDiferencias ? ' AND ABS(COALESCE(l.contado,0) - COALESCE(pa.stock,0)) >= 0.0005' : '';
        return $this->todos("
            SELECT l.*, p.nombre AS producto, p.codigo, p.codigo_barras, p.unidad_medida, p.costo,
                   COALESCE(pa.stock,0) AS ahora
              FROM lf_inventario_conteo_lineas l
              JOIN productos p ON p.id = l.producto_id
              LEFT JOIN lf_producto_almacen pa
                     ON pa.producto_id = l.producto_id AND pa.almacen_id = ?
             WHERE l.conteo_id = ?" . $w . "
             ORDER BY p.nombre", [(int)$c['almacen_id'], (int)$conteoId]);
    }

    /** Cuántos van, cuántos faltan y cuánto cuesta la diferencia. */
    public function avance($conteoId)
    {
        $c = $this->porId($conteoId);
        if (!$c) return ['capturados'=>0,'total'=>0,'diferencias'=>0,'valor'=>0.0];

        $total = (int)$this->valor("SELECT COUNT(*) FROM productos WHERE activo=1 AND lleva_inventario=1");
        $f = $this->uno("
            SELECT COUNT(*) AS capturados,
                   SUM(CASE WHEN ABS(l.contado - COALESCE(pa.stock,0)) >= 0.0005 THEN 1 ELSE 0 END) AS dif,
                   COALESCE(SUM((l.contado - COALESCE(pa.stock,0)) * COALESCE(p.costo,0)),0) AS valor
              FROM lf_inventario_conteo_lineas l
              JOIN productos p ON p.id = l.producto_id
              LEFT JOIN lf_producto_almacen pa
                     ON pa.producto_id = l.producto_id AND pa.almacen_id = ?
             WHERE l.conteo_id = ? AND l.contado IS NOT NULL", [(int)$c['almacen_id'], (int)$conteoId]);
        return ['capturados' => (int)($f['capturados'] ?? 0), 'total' => $total,
                'diferencias' => (int)($f['dif'] ?? 0), 'valor' => round((float)($f['valor'] ?? 0), 2)];
    }

    /**
     * Finaliza: ajusta las existencias a lo contado y cierra.
     *
     * Cada renglón se compara contra lo que hay EN ESE MOMENTO, no contra
     * el `esperado` que se guardó al contarlo. Así, lo que se vendió
     * mientras el conteo estaba abierto no se borra: si conté 12, se
     * vendieron 2 y al cerrar hay 10, el ajuste es 0 y no +2.
     *
     * Los renglones sin capturar NO se tocan. Un conteo a medias corrige
     * lo que se contó y deja el resto como estaba, que es lo honesto.
     *
     * @return array ['ajustados' => n, 'sin_cambio' => n, 'valor' => $]
     */
    public function finalizar($conteoId, $nota = '')
    {
        $c = $this->abiertoO($conteoId);
        $inv = new Inventario($this->db);
        $nota = mb_substr(trim((string)$nota), 0, 255);
        $rot  = 'Conteo #' . (int)$conteoId . ($nota !== '' ? ' · ' . $nota : '');

        $lineas = $this->todos("
            SELECT producto_id, contado FROM lf_inventario_conteo_lineas
             WHERE conteo_id = ? AND contado IS NOT NULL", [(int)$conteoId]);
        if (!$lineas) {
            throw new \InvalidArgumentException('No capturaste ningún producto: no hay nada que ajustar.');
        }

        $contados = [];
        foreach ($lineas as $l) $contados[(int)$l['producto_id']] = (float)$l['contado'];

        $r = $inv->conteoGeneral((int)$c['sucursal_id'], $contados, $rot, (int)$c['almacen_id']);

        $this->db->prepare("UPDATE lf_inventario_conteos
                            SET estado='cerrado', cerrado_en=NOW(), nota=? WHERE id=?")
                 ->execute([$nota ?: null, (int)$conteoId]);

        return ['ajustados' => $r['diferencias'], 'sin_cambio' => $r['contados'] - $r['diferencias'],
                'contados' => $r['contados']];
    }

    // ════════════════════════════════════════════════════════

    /** Lo que el sistema cree que hay de ese producto en ese almacén. */
    private function esperado(array $conteo, $productoId)
    {
        return round((float)$this->valor(
            "SELECT COALESCE(stock,0) FROM lf_producto_almacen WHERE producto_id=? AND almacen_id=?",
            [(int)$productoId, (int)$conteo['almacen_id']]), 3);
    }

    /** El conteo, si todavía se puede tocar. */
    private function abiertoO($id)
    {
        $c = $this->porId($id);
        if (!$c) throw new \InvalidArgumentException('Ese conteo no existe.');
        if (!in_array($c['estado'], ['abierto', 'pausado'], true)) {
            throw new \InvalidArgumentException(
                'Ese conteo ya está ' . mb_strtolower(self::ESTADOS[$c['estado']] ?? $c['estado']) . '.');
        }
        return $c;
    }

    private function todos($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

    private function uno($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }

    private function valor($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
}