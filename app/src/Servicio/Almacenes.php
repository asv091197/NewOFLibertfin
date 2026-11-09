<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Los lugares donde está la mercancía DENTRO de una sucursal.
 *
 * POR QUÉ NO BASTABA LA SUCURSAL
 *
 * Hasta aquí la existencia era un número por tienda. Pero en el
 * mostrador y en la bodega de esa misma tienda no hay lo mismo, y la
 * pregunta de todos los días —«¿hay que bajar más refresco?»— no se
 * podía contestar: el sistema decía «hay 40» sin decir que 38 estaban
 * arriba y 2 en el anaquel.
 *
 * Cada sucursal nace con dos almacenes, Piso y Bodega, y se pueden
 * agregar más (cámara fría, mostrador 2, el camión).
 *
 * EL PRINCIPAL
 *
 * Uno de ellos es el principal: ahí entra y de ahí sale lo que no diga a
 * qué almacén va. Es lo que hace que todo lo anterior siga funcionando
 * sin cambiarle una línea — una venta descuenta del piso, que es donde
 * de verdad estaba la pieza que se llevó el cliente.
 *
 * NO SE BORRAN
 *
 * Un almacén se desactiva, no se borra: sus movimientos siguen en la
 * bitácora y un renglón que apunta a un almacén que ya no existe deja de
 * poder contestar «¿de dónde salió esto?». Y uno con existencia ni
 * siquiera se desactiva: primero se traspasa lo que tiene.
 */
final class Almacenes
{
    const TIPOS = [
        'piso'   => 'Piso de venta',
        'bodega' => 'Bodega',
        'otro'   => 'Otro',
    ];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /** Los almacenes activos de una sucursal, el principal primero. */
    public function deSucursal($sucursalId, $incluirInactivos = false)
    {
        $w = $incluirInactivos ? '' : ' AND activo = 1';
        return $this->todos("
            SELECT * FROM lf_almacenes
             WHERE sucursal_id = ?" . $w . "
             ORDER BY es_principal DESC, nombre", [(int)$sucursalId]);
    }

    /** Todos, con el nombre de su sucursal. Para los selectores de traspaso. */
    public function todosConSucursal()
    {
        return $this->todos("
            SELECT a.*, s.nombre AS sucursal
              FROM lf_almacenes a
              JOIN sucursales s ON s.id = a.sucursal_id
             WHERE a.activo = 1 AND COALESCE(s.activo,1) = 1
             ORDER BY COALESCE(s.es_matriz,0) DESC, s.nombre, a.es_principal DESC, a.nombre");
    }

    public function porId($id)
    {
        return $this->uno("SELECT * FROM lf_almacenes WHERE id = ?", [(int)$id]);
    }

    /**
     * El almacén principal de una sucursal.
     *
     * Si no hay ninguno —una sucursal creada después de la migración— se
     * crea Piso al vuelo. Es preferible a reventar en medio de una venta
     * por una tabla que a nadie se le ocurrió llenar.
     */
    public function principal($sucursalId)
    {
        $sucursalId = (int)$sucursalId;
        $a = $this->uno("SELECT * FROM lf_almacenes WHERE sucursal_id = ? AND es_principal = 1 AND activo = 1
                         ORDER BY id LIMIT 1", [$sucursalId]);
        if ($a) return $a;

        $a = $this->uno("SELECT * FROM lf_almacenes WHERE sucursal_id = ? AND activo = 1 ORDER BY id LIMIT 1",
                        [$sucursalId]);
        if ($a) {
            $this->db->prepare("UPDATE lf_almacenes SET es_principal = 1 WHERE id = ?")->execute([$a['id']]);
            $a['es_principal'] = 1;
            return $a;
        }
        return $this->porId($this->crear($sucursalId, 'Piso', 'piso', true));
    }

    /**
     * Le da a una sucursal sus dos almacenes si todavía no tiene ninguno.
     *
     * Hace falta porque la migración que los creó corre ANTES de que
     * exista la sucursal Matriz: el alta de una empresa carga el esquema
     * —que al final migra— y recién después inserta la sucursal. Sin
     * esto, una empresa recién creada nacía sin almacenes y el primero
     * aparecía solo cuando alguien movía existencia.
     *
     * También se llama al dar de alta una sucursal nueva, por lo mismo.
     *
     * @return int cuántos creó
     */
    public function asegurar($sucursalId)
    {
        $sucursalId = (int)$sucursalId;
        if (!$sucursalId) return 0;
        if ($this->uno("SELECT id FROM lf_almacenes WHERE sucursal_id = ?", [$sucursalId])) return 0;

        $n = 0;
        foreach ([['Piso', 'piso', true], ['Bodega', 'bodega', false]] as $a) {
            try { $this->crear($sucursalId, $a[0], $a[1], $a[2]); $n++; }
            catch (\Throwable $e) { error_log('[LibertyFin] almacén ' . $a[0] . ': ' . $e->getMessage()); }
        }
        return $n;
    }

    /** @return int el id del almacén nuevo */
    public function crear($sucursalId, $nombre, $tipo = 'bodega', $principal = false)
    {
        $sucursalId = (int)$sucursalId;
        $nombre = mb_substr(trim((string)$nombre), 0, 100);
        if ($nombre === '') throw new \InvalidArgumentException('Escribe el nombre del almacén');
        if (!isset(self::TIPOS[$tipo])) $tipo = 'otro';
        if (!$this->uno("SELECT id FROM sucursales WHERE id = ?", [$sucursalId])) {
            throw new \InvalidArgumentException('Esa sucursal no existe');
        }
        if ($this->uno("SELECT id FROM lf_almacenes WHERE sucursal_id = ? AND nombre = ?", [$sucursalId, $nombre])) {
            throw new \InvalidArgumentException('Ya hay un almacén llamado «' . $nombre . '» en esa sucursal');
        }
        if ($principal) $this->db->prepare("UPDATE lf_almacenes SET es_principal = 0 WHERE sucursal_id = ?")
                                 ->execute([$sucursalId]);
        $this->db->prepare("
            INSERT INTO lf_almacenes (sucursal_id, nombre, tipo, es_principal, activo, creado_en)
            VALUES (?,?,?,?,1,NOW())")->execute([$sucursalId, $nombre, $tipo, $principal ? 1 : 0]);
        return (int)$this->db->lastInsertId();
    }

    public function renombrar($id, $nombre, $tipo = null)
    {
        $a = $this->porId($id);
        if (!$a) throw new \InvalidArgumentException('Ese almacén no existe');
        $nombre = mb_substr(trim((string)$nombre), 0, 100);
        if ($nombre === '') throw new \InvalidArgumentException('Escribe el nombre del almacén');
        if ($this->uno("SELECT id FROM lf_almacenes WHERE sucursal_id = ? AND nombre = ? AND id <> ?",
                       [$a['sucursal_id'], $nombre, (int)$id])) {
            throw new \InvalidArgumentException('Ya hay otro almacén con ese nombre en la sucursal');
        }
        $t = (isset(self::TIPOS[$tipo])) ? $tipo : $a['tipo'];
        $this->db->prepare("UPDATE lf_almacenes SET nombre = ?, tipo = ? WHERE id = ?")
                 ->execute([$nombre, $t, (int)$id]);
        return true;
    }

    /** El principal de la sucursal pasa a ser este. */
    public function hacerPrincipal($id)
    {
        $a = $this->porId($id);
        if (!$a) throw new \InvalidArgumentException('Ese almacén no existe');
        if (!$a['activo']) throw new \InvalidArgumentException('Un almacén apagado no puede ser el principal');
        $this->db->prepare("UPDATE lf_almacenes SET es_principal = 0 WHERE sucursal_id = ?")
                 ->execute([$a['sucursal_id']]);
        $this->db->prepare("UPDATE lf_almacenes SET es_principal = 1 WHERE id = ?")->execute([(int)$id]);
        return true;
    }

    /**
     * Apaga un almacén. No se borra, y no se apaga si todavía tiene algo:
     * esa mercancía existe de verdad y apagarla la haría desaparecer de
     * los totales sin que nadie la moviera.
     */
    public function apagar($id)
    {
        $a = $this->porId($id);
        if (!$a) throw new \InvalidArgumentException('Ese almacén no existe');
        if ($a['es_principal']) {
            throw new \InvalidArgumentException(
                'Es el almacén principal de su sucursal. Haz principal a otro antes de apagar este.');
        }
        $hay = (float)$this->valor("SELECT COALESCE(SUM(ABS(stock)),0) FROM lf_producto_almacen WHERE almacen_id = ?",
                                   [(int)$id]);
        if ($hay > 0.0005) {
            throw new \InvalidArgumentException(
                'Todavía tiene existencia. Tráspasala a otro almacén antes de apagarlo.');
        }
        $this->db->prepare("UPDATE lf_almacenes SET activo = 0 WHERE id = ?")->execute([(int)$id]);
        return true;
    }

    public function encender($id)
    {
        $this->db->prepare("UPDATE lf_almacenes SET activo = 1 WHERE id = ?")->execute([(int)$id]);
        return true;
    }

    /**
     * Qué hay de un producto en cada almacén de una sucursal.
     * @return array [almacen_id => ['nombre','tipo','es_principal','existencia','minimo']]
     */
    public function existenciaDe($productoId, $sucursalId)
    {
        $r = [];
        foreach ($this->todos("
            SELECT a.id, a.nombre, a.tipo, a.es_principal,
                   COALESCE(pa.stock,0) AS existencia, COALESCE(pa.stock_minimo,0) AS minimo
              FROM lf_almacenes a
              LEFT JOIN lf_producto_almacen pa ON pa.almacen_id = a.id AND pa.producto_id = ?
             WHERE a.sucursal_id = ? AND a.activo = 1
             ORDER BY a.es_principal DESC, a.nombre", [(int)$productoId, (int)$sucursalId]) as $f) {
            $r[(int)$f['id']] = ['nombre' => $f['nombre'], 'tipo' => $f['tipo'],
                                 'es_principal' => (int)$f['es_principal'],
                                 'existencia' => (float)$f['existencia'], 'minimo' => (float)$f['minimo']];
        }
        return $r;
    }

    /** Cuánto vale y cuántos productos tiene cada almacén, para la pantalla. */
    public function resumen($sucursalId)
    {
        return $this->todos("
            SELECT a.id, a.nombre, a.tipo, a.es_principal,
                   COUNT(CASE WHEN pa.stock > 0 THEN 1 END) AS productos,
                   COALESCE(SUM(CASE WHEN pa.stock > 0 THEN pa.stock * COALESCE(p.costo,0) END),0) AS valor,
                   COALESCE(SUM(CASE WHEN pa.stock > 0 THEN pa.stock END),0) AS piezas
              FROM lf_almacenes a
              LEFT JOIN lf_producto_almacen pa ON pa.almacen_id = a.id
              LEFT JOIN productos p ON p.id = pa.producto_id AND p.activo = 1 AND p.lleva_inventario = 1
             WHERE a.sucursal_id = ? AND a.activo = 1
             GROUP BY a.id, a.nombre, a.tipo, a.es_principal
             ORDER BY a.es_principal DESC, a.nombre", [(int)$sucursalId]);
    }

    private function todos($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

    private function uno($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }

    private function valor($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
}