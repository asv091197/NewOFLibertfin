<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Datos\ServicioRepo;
use LibertyFin\Servicio\Almacenes;
use LibertyFin\Servicio\Captura;
use LibertyFin\Servicio\Conteo;
use LibertyFin\Servicio\Inventario;

/**
 * Inventario y Productos de la versión 35.
 *
 *  · Cada movimiento dice qué había, qué quedó en el almacén y cuánto hay
 *    en toda la sucursal: contar el piso con mercancía en la bodega se
 *    leía como un conteo mal hecho.
 *  · Un traspaso, una captura con pistola o un conteo se ven como UN
 *    documento.
 *  · Marca, área y tipo filtran igual en las tres pantallas.
 *  · Productos con tres estados, duplicar, borrar (solo inactivos) y
 *    etiquetas o atributos.
 */
final class InventarioV35Test extends BaseDePrueba
{
    private static $piso = 0;
    private static $bodega = 0;

    protected function setUp(): void
    {
        $_SESSION = ['usuario_id' => 1, 'usuario_nombre' => 'Administración', 'sucursal_id' => 1];
        if (!self::$piso) {
            $al = new Almacenes($this->empresa());
            $al->asegurar(1);
            self::$piso   = (int)$al->principal(1)['id'];
            foreach ($al->deSucursal(1) as $a) if (!$a['es_principal']) self::$bodega = (int)$a['id'];
        }
    }

    private function inv(): Inventario
    {
        return new Inventario($this->empresa(), ['activo' => 'si', 'sin_existencia' => 'avisar']);
    }

    private function producto(string $nombre, array $d = []): int
    {
        $id = $this->crearProducto($nombre, 50);
        $this->empresa()->prepare("UPDATE productos SET lleva_inventario = 1, costo = 20, marca = ?,
                                          tipo_producto = ?, categoria_id = ? WHERE id = ?")
             ->execute([$d['marca'] ?? null, $d['tipo'] ?? 'Estandar', $d['area'] ?? null, $id]);
        return $id;
    }

    private function ultimo(int $pid): array
    {
        $st = $this->empresa()->prepare("SELECT * FROM lf_inventario_movimientos WHERE producto_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$pid]);
        return $st->fetch();
    }

    public function testLaBaseTieneLasColumnasDeLaVersion35(): void
    {
        $cols = function (string $t) {
            return $this->empresa()->query("SHOW COLUMNS FROM $t")->fetchAll(\PDO::FETCH_COLUMN);
        };
        foreach (['anterior', 'existencia_sucursal', 'lote'] as $c) {
            $this->assertContains($c, $cols('lf_inventario_movimientos'));
        }
        foreach (['agotado', 'eliminado_en'] as $c) $this->assertContains($c, $cols('productos'));
        $this->assertContains('color', $cols('lf_producto_atributos'));
    }

    /** El caso que se reportó: contar el piso con mercancía en la bodega. */
    public function testElConteoDelPisoDiceQueHabiaYCuantoHayEnLaSucursal(): void
    {
        $inv = $this->inv();
        $p = $this->producto('Refresco 600');
        $inv->registrar('entrada', $p, 1, 10, ['almacen_id' => self::$piso]);
        $inv->registrar('entrada', $p, 1, 5,  ['almacen_id' => self::$bodega]);

        $r = $inv->registrar('conteo', $p, 1, 12, ['almacen_id' => self::$piso]);
        $this->assertEqualsWithDelta(12, $r['existencia'], 0.001, 'el piso queda en lo contado');
        $this->assertEqualsWithDelta(2, $r['cambio'], 0.001);
        $this->assertEqualsWithDelta(17, $inv->totalEnSucursal($p, 1), 0.001, 'la bodega no se toca');

        $m = $this->ultimo($p);
        $this->assertSame('conteo', $m['tipo']);
        $this->assertEqualsWithDelta(10, (float)$m['anterior'], 0.001);
        $this->assertEqualsWithDelta(12, (float)$m['existencia'], 0.001);
        $this->assertEqualsWithDelta(17, (float)$m['existencia_sucursal'], 0.001);
        $this->assertEqualsWithDelta(10, Inventario::habia($m), 0.001);

        // Un renglón viejo, sin `anterior`, se deduce.
        $m['anterior'] = null;
        $this->assertEqualsWithDelta(10, Inventario::habia($m), 0.001);
    }

    public function testUnTraspasoSeVeEnteroDeUnLadoAlOtro(): void
    {
        $inv = $this->inv();
        $p = $this->producto('Agua 1L');
        $inv->registrar('entrada', $p, 1, 20, ['almacen_id' => self::$bodega]);
        $r = $inv->traspasarAlmacen($p, self::$bodega, self::$piso, 6, 'Para el anaquel');
        $this->assertEqualsWithDelta(14, $r['existencia'], 0.001);
        $this->assertEqualsWithDelta(6, $r['destino'], 0.001);

        $llega = $inv->movimiento((int)$this->ultimo($p)['id']);
        $grupo = $inv->grupoDe($llega);
        $this->assertCount(2, $grupo, 'las dos mitades del traspaso');
        $this->assertSame($grupo[0]['lote'], $grupo[1]['lote']);
        $this->assertStringStartsWith('traspaso:', (string)$grupo[0]['lote']);

        $pares = Inventario::traspasosEnPares($grupo);
        $this->assertCount(1, $pares);
        $this->assertEqualsWithDelta(20, Inventario::habia($pares[0]['sale']), 0.001, 'había en la bodega');
        $this->assertEqualsWithDelta(14, (float)$pares[0]['sale']['existencia'], 0.001, 'quedó en la bodega');
        $this->assertEqualsWithDelta(0, Inventario::habia($pares[0]['llega']), 0.001, 'había en el piso');
        $this->assertEqualsWithDelta(6, (float)$pares[0]['llega']['existencia'], 0.001, 'quedó en el piso');

        // Las dos mitades se leen igual: de dónde a dónde.
        $this->assertSame('Bodega → Piso', Inventario::rutaAlmacen($pares[0]['sale']));
        $this->assertSame('Bodega → Piso', Inventario::rutaAlmacen($pares[0]['llega']));
        $this->assertStringStartsWith('Traspaso del', Inventario::documento($llega));
    }

    public function testElResumenDesglosaLoQueEntroYSalio(): void
    {
        $inv = $this->inv();
        $p = $this->producto('Jabón desglose');
        $inv->registrar('entrada', $p, 1, 30);
        $inv->registrar('salida',  $p, 1, 4, ['nota' => 'Uso interno']);
        $inv->registrar('merma',   $p, 1, 1, ['motivo' => 'danado']);

        $r = $inv->resumenMovimientos(['producto' => $p]);
        $this->assertEqualsWithDelta(30, $r['entrada']['entro'], 0.001);
        $this->assertEqualsWithDelta(4, $r['salida']['salio'], 0.001);
        $this->assertEqualsWithDelta(1, $r['merma']['salio'], 0.001);

        $lista = $inv->movimientos(['producto' => $p]);
        $this->assertCount(3, $lista);
        $this->assertSame('Piso', $lista[0]['almacen']);
    }

    public function testMarcaAreaYTipoFiltranIgualQueEnProductos(): void
    {
        $db = $this->empresa();
        $db->exec("INSERT INTO categorias (nombre) VALUES ('Bebidas filtro')");
        $padre = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO categorias (nombre, padre_id) VALUES ('Refrescos filtro', ?)")->execute([$padre]);
        $hija = (int)$db->lastInsertId();

        $a = $this->producto('Cola filtro', ['marca' => 'MarcaFiltro', 'area' => $hija]);
        $b = $this->producto('Té filtro', ['marca' => 'OtraFiltro', 'area' => $padre, 'tipo' => 'Granel']);
        $this->producto('Pan filtro', ['marca' => 'MarcaFiltro']);

        $ids = function (array $f) {
            return array_map('intval', array_column($this->inv()->listado(1, 'filtro', false, $f, 500, 'nombre'), 'id'));
        };
        $this->assertEqualsCanonicalizing([$a, $b], $ids(['area' => $padre]), 'el área incluye sus subáreas');
        $this->assertSame([$a], $ids(['area' => $hija]));
        $this->assertCount(2, $ids(['marca' => 'MarcaFiltro']));
        $this->assertSame([$b], $ids(['tipo' => 'Granel']));

        // Productos filtra con la misma regla.
        $repo = new ServicioRepo($db);
        $cat = $repo->catalogo(date('Y-m-01'), date('Y-m-t'), 'filtro', 50, 0, ['area' => $padre, 'estado' => 'todos']);
        $this->assertEqualsCanonicalizing([$a, $b], array_map('intval', array_column($cat, 'id')));
    }

    public function testElConteoSePuedeVerConSusDiferenciasYQuitarUnRenglon(): void
    {
        $inv = $this->inv();
        $p1 = $this->producto('Galleta conteo');
        $p2 = $this->producto('Chicle conteo');
        $p3 = $this->producto('Dulce conteo');
        $inv->registrar('entrada', $p1, 1, 8);
        $inv->registrar('entrada', $p2, 1, 3);

        $cn = new Conteo($this->empresa());
        foreach ($this->empresa()->query("SELECT id FROM lf_inventario_conteos WHERE estado IN ('abierto','pausado')")
                 ->fetchAll(\PDO::FETCH_COLUMN) as $viejo) {
            $cn->cancelar((int)$viejo);
        }
        $c = $cn->abrir(1, self::$piso, 'Prueba de conteo');
        $cn->capturar($c['id'], $p1, '6');
        $cn->capturar($c['id'], $p2, '3');
        $cn->capturar($c['id'], $p3, '1');
        $cn->capturar($c['id'], $p3, null);         // se borró la casilla: sale del conteo

        $d = $cn->detalle($c['id']);
        $this->assertTrue($d['abierto']);
        $this->assertSame(2, $d['totales']['productos']);
        $this->assertSame(1, $d['totales']['diferencias']);

        $r = $cn->finalizar($c['id']);
        $this->assertSame(2, $r['contados']);
        $this->assertSame(1, $r['ajustados']);

        $d = $cn->detalle($c['id']);
        $this->assertFalse($d['abierto']);
        $porId = array_column($d['lineas'], null, 'producto_id');
        $this->assertEqualsWithDelta(8, (float)$porId[$p1]['sistema'], 0.001);
        $this->assertEqualsWithDelta(-2, (float)$porId[$p1]['diferencia'], 0.001);
        $this->assertEqualsWithDelta(-40, (float)$porId[$p1]['valor_dif'], 0.001, '2 piezas × $20');

        $m = $this->ultimo($p1);
        $this->assertSame('conteo:' . $c['id'], $m['lote']);
        $this->assertSame('Conteo #' . $c['id'], Inventario::documento($m));
        $this->assertCount(2, $inv->grupoDe($inv->movimiento((int)$m['id'])));
    }

    public function testUnaCapturaConPistolaSeVeComoUnSoloDocumento(): void
    {
        $p1 = $this->producto('Caja pistola A');
        $p2 = $this->producto('Caja pistola B');
        $cod = $this->empresa()->query("SELECT codigo FROM productos WHERE id = $p1")->fetchColumn();

        $cap = new Captura($this->empresa());
        $this->empresa()->exec("UPDATE lf_inventario_capturas SET estado = 'cancelada' WHERE estado IN ('abierta','pausada')");
        $c = $cap->abrir(1, 'entrada', self::$bodega);
        $this->assertTrue($cap->escanear($c['id'], $cod, 2)['ok']);
        $this->assertTrue($cap->escanear($c['id'], $cod, 1)['ok']);
        $this->assertTrue($cap->escanear($c['id'], 'Caja pistola B', 4)['ok'], 'por nombre, si es uno solo');
        // Parte de un nombre que coincide con dos: lo dice.
        $r = $cap->escanear($c['id'], 'Caja pistola', 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('varios', $r['texto']);

        $this->assertTrue($cap->finalizar($c['id'])['ok']);
        $m = $this->ultimo($p1);
        $this->assertSame('captura:' . $c['id'], $m['lote']);
        $this->assertSame('Entrada con pistola #' . $c['id'], Inventario::documento($m));
        $inv = $this->inv();
        $grupo = $inv->grupoDe($inv->movimiento((int)$m['id']));
        $this->assertEqualsCanonicalizing([$p1, $p2], array_map('intval', array_column($grupo, 'producto_id')));
        $this->assertEqualsWithDelta(3, (float)$this->ultimo($p1)['existencia'], 0.001);
        $this->assertSame('Bodega', $grupo[0]['almacen']);
    }

    public function testElBuscadorDeLaCapturaEncuentraPorNombre(): void
    {
        $this->producto('Sabritas buscador');
        $r = $this->inv()->sugerencias('sabritas busc', 1);
        $this->assertCount(1, $r);
        $this->assertSame('Sabritas buscador', $r[0]['nombre']);
    }

    public function testProductosConTresEstadosDuplicarYBorrar(): void
    {
        $db = $this->empresa();
        $repo = new ServicioRepo($db);
        $id = $repo->crear(['nombre' => 'Playera estados', 'precio' => 199, 'costo' => 80, 'marca' => 'Marca E',
                            'tipo_producto' => 'Ropa']);
        $this->assertSame('Ropa', $db->query("SELECT tipo_producto FROM productos WHERE id = $id")->fetchColumn());

        // Etiquetas y atributos.
        $n = $repo->guardarAtributos($id, ['atributos_en_form' => 1,
            'atr_nombre'    => ['Talla', 'Color', 'Nuevo', ''],
            'atr_valor'     => ['M', 'Rojo', '', ''],
            'atr_color'     => ['#000000', '#D93025', '#27ae60', '#27ae60'],
            'atr_usa_color' => ['', '1', '', '']]);
        $this->assertSame(3, $n);
        $atr = $repo->atributos($id);
        $this->assertSame('Talla', $atr[0]['nombre']);
        $this->assertNull($atr[0]['color']);
        $this->assertSame('#d93025', $atr[1]['color']);
        $this->assertNull($atr[2]['valor'], 'una etiqueta sola no lleva valor');
        // El buscador encuentra por etiqueta.
        $this->assertNotEmpty(array_filter($repo->catalogo(date('Y-m-01'), date('Y-m-t'), 'Rojo', 50, 0),
            function ($p) use ($id) { return (int)$p['id'] === $id; }));

        // Agotado a mano.
        $repo->cambiarEstado($id, 'agotado');
        $this->assertSame('agotado', $repo->estadoDe($id));
        $agot = array_column($repo->catalogo(date('Y-m-01'), date('Y-m-t'), 'Playera estados', 50, 0,
                                             ['estado' => 'agotado']), 'id');
        $this->assertContains($id, array_map('intval', $agot));

        // Con existencias en cero se lee agotado sin marcarlo (con Inventario encendido).
        $repo->cambiarEstado($id, 'activo');
        $db->exec("UPDATE productos SET lleva_inventario = 1, stock = 0 WHERE id = $id");
        $this->assertSame('activo', $repo->estadoDe($id, false));
        $this->assertSame('agotado', $repo->estadoDe($id, true));
        // En preventa no se agota por estar en cero: se vende porque no ha llegado.
        $db->exec("UPDATE productos SET preventa = 1 WHERE id = $id");
        $this->assertSame('activo', $repo->estadoDe($id, true));
        $db->exec("UPDATE productos SET preventa = 0 WHERE id = $id");

        // Duplicar: otro SKU, sin código de barras ni existencias, con sus etiquetas.
        $db->exec("UPDATE productos SET codigo_barras = '7501234567890', stock = 9 WHERE id = $id");
        $copia = $repo->duplicar($id);
        $c = $repo->porId($copia);
        $o = $repo->porId($id);
        $this->assertNotSame($o['codigo'], $c['codigo']);
        $this->assertSame('Playera estados (copia)', $c['nombre']);
        $this->assertNull($c['codigo_barras']);
        $this->assertEqualsWithDelta(0, (float)$c['stock'], 0.001);
        $this->assertSame('Marca E', $c['marca']);
        $this->assertCount(3, $repo->atributos($copia));

        // Borrar: solo inactivo.
        try {
            $repo->borrar($copia);
            $this->fail('No debe borrar un producto activo');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('inactivo', $e->getMessage());
        }
        $repo->cambiarEstado($copia, 'inactivo');
        $repo->borrar($copia);
        $todos = array_map('intval', array_column($repo->catalogo(date('Y-m-01'), date('Y-m-t'), 'Playera', 50, 0,
                                                                  ['estado' => 'todos']), 'id'));
        $this->assertNotContains($copia, $todos, 'el borrado sale de todas las listas');
        $this->assertContains($id, $todos);
        // Su SKU queda libre.
        $repo->crear(['nombre' => 'Reusa el SKU', 'precio' => 10, 'codigo' => $c['codigo']]);
    }

    /**
     * Si la base todavía no tiene las columnas nuevas, mover existencias
     * tiene que seguir funcionando: corre dentro de cada venta.
     */
    public function testSinLasColumnasNuevasElMovimientoSeAnotaIgual(): void
    {
        $db = $this->empresa();
        $p = $this->producto('Sin v35');
        $ref = new \ReflectionProperty(Inventario::class, 'sinV35');   // PHP 8.1+: sin setAccessible
        $db->exec("ALTER TABLE lf_inventario_movimientos DROP COLUMN existencia_sucursal");
        // El aviso al log es lo esperado aquí; que no ensucie la salida.
        $log = ini_set('error_log', sys_get_temp_dir() . '/lf-pruebas-v35.log');
        try {
            $r = $this->inv()->registrar('entrada', $p, 1, 7);
            $this->assertEqualsWithDelta(7, $r['existencia'], 0.001);
            $this->assertSame('entrada', $this->ultimo($p)['tipo']);
        } finally {
            $db->exec("ALTER TABLE lf_inventario_movimientos ADD COLUMN existencia_sucursal DECIMAL(12,3) NULL DEFAULT NULL");
            $ref->setValue(null, false);
            ini_set('error_log', (string)$log);
        }
    }
}
