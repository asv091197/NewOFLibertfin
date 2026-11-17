<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Servicio;

use LibertyFin\Servicio\TikTok;
use LibertyFin\Servicio\TikTokInventario;
use PHPUnit\Framework\TestCase;

/** Las piezas de TikTok Shop que no llaman a TikTok ni tocan la base. */
final class TikTokTest extends TestCase
{
    protected function setUp(): void
    {
        TikTok::usarConfig(['activo' => true, 'app_key' => 'global', 'app_secret' => 'secretoGlobal',
                            'service_id' => '7000', 'llave_cifrado' => 'llave-de-prueba']);
    }

    protected function tearDown(): void
    {
        TikTok::usarConfig([]);
    }

    // ── La firma ───────────────────────────────────────────────

    public function testFirmaConRutaParametrosOrdenadosYCuerpo(): void
    {
        // ruta + nombrevalor ordenados + cuerpo, envuelto en el secreto.
        // `sign` y `access_token` NO entran aunque vengan en el query.
        $q = ['timestamp' => 1700000000, 'shop_cipher' => 'ROW_x', 'app_key' => 'abc', 'page_size' => 50,
              'sign' => 'viejo', 'access_token' => 'nunca'];
        $firma = TikTok::firmar('/order/202309/orders/search', $q, json_encode(['update_time_ge' => 1]), 'secreto123');
        $this->assertSame('999ec2defc50f281b4eeb3771fb830e875a81fcfeb1fe084ab014d4ce898e94f', $firma);
    }

    public function testElOrdenDeLosParametrosNoCambiaLaFirma(): void
    {
        $a = TikTok::firmar('/x', ['b' => 2, 'a' => 1], '', 's');
        $b = TikTok::firmar('/x', ['a' => 1, 'b' => 2], '', 's');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, TikTok::firmar('/x', ['a' => 1, 'b' => 3], '', 's'));
    }

    public function testFirmaDelAviso(): void
    {
        $cuerpo = json_encode(['type' => 1, 'shop_id' => '7494']);
        $ok = '6cfe2d5aa38ca497ec6d7a967e91ced47f2253673776af8ae356350014c983c7';
        $this->assertTrue(TikTok::firmaAvisoValida($cuerpo, $ok, 'abc', 'secreto123'));
        $this->assertTrue(TikTok::firmaAvisoValida($cuerpo, strtoupper($ok), 'abc', 'secreto123'));
        // Otro cuerpo, otro secreto u otra app: no pasa.
        $this->assertFalse(TikTok::firmaAvisoValida($cuerpo . ' ', $ok, 'abc', 'secreto123'));
        $this->assertFalse(TikTok::firmaAvisoValida($cuerpo, $ok, 'abc', 'otro'));
        $this->assertFalse(TikTok::firmaAvisoValida($cuerpo, $ok, 'xyz', 'secreto123'));
        // Sin firma o sin secreto nunca pasa.
        $this->assertFalse(TikTok::firmaAvisoValida($cuerpo, '', 'abc', 'secreto123'));
        $this->assertFalse(TikTok::firmaAvisoValida($cuerpo, $ok, 'abc', ''));
    }

    // ── Credenciales: propias (in-house) o globales (ISV) ─────────

    public function testSinCredencialesPropiasUsaLasGlobales(): void
    {
        $c = TikTok::credenciales(['app_key' => null, 'app_secret' => null]);
        $this->assertFalse($c['propias']);
        $this->assertSame('global', $c['app_key']);
        $this->assertSame('secretoGlobal', $c['app_secret']);
        $this->assertSame('global', TikTok::credenciales(null)['app_key']);
    }

    public function testConCredencialesPropiasUsaLasDeLaEmpresa(): void
    {
        $c = TikTok::credenciales(['app_key' => 'propia', 'app_secret' => TikTok::cifrar('secretoPropio'), 'service_id' => '42']);
        $this->assertTrue($c['propias']);
        $this->assertSame('propia', $c['app_key']);
        $this->assertSame('secretoPropio', $c['app_secret']);
        $this->assertSame('42', $c['service_id']);
    }

    public function testSiElSecretoPropioNoSeDescifraNoLoUsa(): void
    {
        // Cambió la llave de cifrado: no se manda un secreto basura a TikTok.
        $c = TikTok::credenciales(['app_key' => 'propia', 'app_secret' => 'v1:basura']);
        $this->assertFalse($c['propias']);
        $this->assertSame('global', $c['app_key']);
    }

    public function testInHouseSinAppGlobal(): void
    {
        TikTok::usarConfig(['activo' => true, 'llave_cifrado' => 'k']);
        $this->assertTrue(TikTok::activa());
        $this->assertFalse(TikTok::hayAppGlobal());
        TikTok::usarConfig(['activo' => true]);
        $this->assertFalse(TikTok::activa(), 'sin llave_cifrado ni app_secret no hay con qué cifrar');
    }

    public function testCifrarYDescifrar(): void
    {
        $g = TikTok::cifrar('hola');
        $this->assertStringStartsWith('v1:', $g);
        $this->assertNotSame($g, TikTok::cifrar('hola'), 'cada cifrado lleva su propio IV');
        $this->assertSame('hola', TikTok::descifrar($g));
        $this->assertSame('', TikTok::descifrar('texto plano'));
    }

    // ── Autorizar y llaves ─────────────────────────────────────

    public function testUrlDeAutorizar(): void
    {
        $a = TikTok::urlAutorizar('7000', 'abc');
        $this->assertSame('https://services.tiktokshop.com/open/authorize?service_id=7000&state=abc', $a['url']);
        $this->assertSame('abc', $a['state']);

        TikTok::usarConfig(['activo' => true, 'llave_cifrado' => 'k',
                            'url_autorizar' => 'https://services.us.tiktokshop.com/open/authorize?service_id={service_id}']);
        $this->assertSame('https://services.us.tiktokshop.com/open/authorize?service_id=9&state=xyz',
                          TikTok::urlAutorizar('9', 'xyz')['url']);
    }

    public function testFechaDeExpiracion(): void
    {
        $this->assertNull(TikTok::fechaDeExpiracion(0));
        // Fecha Unix: tal cual.
        $this->assertSame(date('Y-m-d H:i:s', 1900000000), TikTok::fechaDeExpiracion(1900000000));
        // Segundos que faltan: se suman a hoy.
        $f = strtotime((string)TikTok::fechaDeExpiracion(3600));
        $this->assertEqualsWithDelta(time() + 3600, $f, 5);
    }

    // ── Órdenes ────────────────────────────────────────────────

    public function testOrdenPlana(): void
    {
        $o = TikTok::orden_([
            'id' => '5770001', 'status' => 'AWAITING_SHIPMENT', 'create_time' => 1700000000,
            'payment' => ['total_amount' => '299.50'],
            'line_items' => [
                ['sku_id' => '17', 'seller_sku' => 'GOR-01', 'product_name' => 'Gorra', 'sku_name' => 'Roja'],
                ['sku_id' => '17', 'seller_sku' => 'GOR-01', 'product_name' => 'Gorra', 'sku_name' => 'Roja'],
                ['sku_id' => '18', 'seller_sku' => 'PLA-01', 'product_name' => 'Playera', 'quantity' => 3,
                 'display_status' => 'CANCELLED'],
            ],
        ]);
        $this->assertSame('5770001', $o['id']);
        $this->assertSame('AWAITING_SHIPMENT', $o['estado']);
        $this->assertSame(299.5, $o['total']);
        $this->assertCount(3, $o['lineas']);
        // Sin `quantity`, cada renglón es UNA pieza.
        $this->assertSame(1.0, $o['lineas'][0]['cantidad']);
        $this->assertSame('Gorra · Roja', $o['lineas'][0]['nombre']);
        $this->assertSame(3.0, $o['lineas'][2]['cantidad']);
        $this->assertTrue($o['lineas'][2]['cancelada']);
    }

    public function testCuandoSeDescuentaYCuandoRegresa(): void
    {
        // Se descuenta al quedar «por enviar», no antes: ON_HOLD aún se puede cancelar.
        foreach (['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'] as $e) {
            $this->assertTrue(TikTokInventario::pagada(['estado' => $e]), $e);
        }
        foreach (['UNPAID', 'ON_HOLD', 'CANCELLED', ''] as $e) {
            $this->assertFalse(TikTokInventario::pagada(['estado' => $e]), $e);
        }
        $this->assertTrue(TikTokInventario::cancelada(['estado' => 'CANCELLED']));
        $this->assertFalse(TikTokInventario::cancelada(['estado' => 'COMPLETED']));
    }

    // ── Productos ──────────────────────────────────────────────

    public function testUnRenglonPorSkuConLaExistenciaDelAlmacenElegido(): void
    {
        $p = [
            'id' => '1729', 'title' => 'Gorra', 'status' => 'ACTIVATE',
            'main_images' => [['urls' => ['https://img/1.jpg']]],
            'skus' => [
                ['id' => '17', 'seller_sku' => ' GOR-01 ', 'price' => ['sale_price' => '199.00'],
                 'sales_attributes' => [['name' => 'Color', 'value_name' => 'Roja']],
                 'inventory' => [['warehouse_id' => 'W1', 'quantity' => 4], ['warehouse_id' => 'W2', 'quantity' => 9]]],
                ['id' => '18', 'seller_sku' => 'GOR-02', 'price' => ['tax_exclusive_price' => '150'],
                 'inventory' => [['warehouse_id' => 'W2', 'quantity' => 1]]],
            ],
        ];
        $r = TikTokInventario::renglonesDe($p, 'W1');
        $this->assertCount(2, $r);
        $this->assertSame('1729', $r[0]['producto_tt']);
        $this->assertSame('17', $r[0]['sku_tt']);
        $this->assertSame('GOR-01', $r[0]['sku']);
        $this->assertSame('Roja', $r[0]['variante']);
        $this->assertSame('visible', $r[0]['estado_tt']);
        $this->assertSame(199.0, $r[0]['precio']);
        $this->assertSame(4, $r[0]['stock_tt']);
        // No está dado de alta en W1: sin existencia en ese almacén.
        $this->assertNull($r[1]['stock_tt']);
        // Sin almacén elegido, ningún número es «el» de la sucursal.
        $this->assertNull(TikTokInventario::renglonesDe($p, '')[0]['stock_tt']);
    }
}
