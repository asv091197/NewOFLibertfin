<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Los mismos filtros de producto en Productos, Existencias y Conteo físico.
 *
 * POR QUÉ UNA SOLA CLASE
 *
 * Se pidió que las tres pantallas filtren igual: por marca, por área y por
 * tipo de producto. Si cada una armara su propio WHERE, a los dos meses
 * una incluiría las subáreas y otra no, y «Bebidas» daría 40 productos en
 * Productos y 12 en el conteo. Aquí vive la regla una vez.
 *
 *   marca  texto exacto (la lista sale de las marcas que ya existen)
 *   area   id de categoría; incluye sus subcategorías
 *   tipo   `productos.tipo_producto` («Estandar», «Servicio»…)
 */
final class FiltrosProducto
{
    /** Lo que llegó por GET, limpio. Sin filtro = cadena vacía / 0. */
    public static function deLaPeticion(?array $get = null)
    {
        $g = $get ?? $_GET;
        return [
            'marca' => mb_substr(trim((string)($g['marca'] ?? '')), 0, 100),
            'area'  => max(0, (int)($g['area'] ?? 0)),
            'tipo'  => mb_substr(trim((string)($g['tipo'] ?? '')), 0, 50),
        ];
    }

    /** ¿Hay algún filtro puesto? */
    public static function hay(array $f)
    {
        return ($f['marca'] ?? '') !== '' || (int)($f['area'] ?? 0) > 0 || ($f['tipo'] ?? '') !== '';
    }

    /**
     * Las condiciones para un WHERE, con sus parámetros.
     *
     * @param string $p alias de la tabla productos en la consulta
     * @return array [string[] condiciones, array parámetros]
     */
    public static function sql(array $f, $p = 'p')
    {
        $w = []; $par = [];
        if (($f['marca'] ?? '') !== '') {
            $w[] = "$p.marca = ?";
            $par[] = $f['marca'];
        }
        if ((int)($f['area'] ?? 0) > 0) {
            // El área y sus subáreas: quien elige «Bebidas» espera ver
            // también los refrescos que viven en «Bebidas → Refrescos».
            $w[] = "($p.categoria_id = ? OR $p.categoria_id IN (SELECT id FROM categorias WHERE padre_id = ?))";
            $par[] = (int)$f['area'];
            $par[] = (int)$f['area'];
        }
        if (($f['tipo'] ?? '') !== '') {
            $w[] = "COALESCE(NULLIF($p.tipo_producto,''),'Estandar') = ?";
            $par[] = $f['tipo'];
        }
        return [$w, $par];
    }

    /**
     * Qué ofrecer en cada selector.
     *
     * Solo lo que existe: una marca que ningún producto usa no sirve de
     * filtro, solo de lista vacía.
     *
     * @param bool $soloInventario  solo los productos que llevan existencias
     */
    public static function opciones(PDO $db, $soloInventario = false)
    {
        $w = "COALESCE(activo,1) = 1" . ($soloInventario ? " AND lleva_inventario = 1" : '');
        $r = ['marcas' => [], 'areas' => [], 'tipos' => []];
        try {
            $r['marcas'] = $db->query("SELECT DISTINCT marca FROM productos
                                        WHERE $w AND marca IS NOT NULL AND marca <> ''
                                        ORDER BY marca")->fetchAll(PDO::FETCH_COLUMN);
            $r['tipos'] = $db->query("SELECT DISTINCT COALESCE(NULLIF(tipo_producto,''),'Estandar') AS t
                                       FROM productos WHERE $w ORDER BY t")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] filtros de producto: ' . $e->getMessage());
        }
        try {
            $r['areas'] = (new \LibertyFin\Datos\ServicioRepo($db))->categoriasEnArbol();
        } catch (\Throwable $e) {
            $r['areas'] = [];
        }
        return $r;
    }

    /** «Estandar» se lee «Estándar»: es el valor que dejó el sistema anterior. */
    public static function rotuloTipo($t)
    {
        $t = trim((string)$t);
        return ($t === '' || strcasecmp($t, 'Estandar') === 0) ? 'Estándar' : $t;
    }
}
