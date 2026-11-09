<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Api;

use LibertyFin\Api\Formato;
use LibertyFin\Api\IdempotenciaRepo;
use LibertyFin\Api\LlaveRepo;
use LibertyFin\Api\Respuesta;
use PHPUnit\Framework\TestCase;

final class FormatoTest extends TestCase
{
    public function testEstadoDeLaVenta(): void
    {
        $this->assertSame('cancelada', Formato::estadoVenta(['estado' => 'cancelada', 'saldo' => 500]));
        $this->assertSame('con_saldo', Formato::estadoVenta(['estado' => 'completada', 'saldo' => 0.02]));
        $this->assertSame('liquidada', Formato::estadoVenta(['estado' => 'completada', 'saldo' => 0.005]));
    }

    public function testFechasEnIso8601ConZona(): void
    {
        $this->assertSame('2026-10-16T15:41:38-06:00', Formato::fechaHora('2026-10-16 15:41:38'));
        $this->assertNull(Formato::fechaHora('0000-00-00 00:00:00'));
        $this->assertNull(Formato::fechaHora(null));
        $this->assertSame('2026-10-16', Formato::dia('2026-10-16 10:00:00'));
    }

    public function testElPagoSoloLlevaComisionSiSePuedeVer(): void
    {
        $p = ['id' => '7', 'folio' => 'V1-01', 'fecha_pago' => '2026-10-16', 'monto' => '1500.00',
              'tipo' => 'abono', 'metodo_pago' => 'efectivo', 'banco' => '', 'referencia' => null,
              'cancelado' => '0', 'comision' => '150.00'];
        $this->assertArrayNotHasKey('comision', Formato::pago($p));
        $this->assertSame(150.0, Formato::pago($p, true)['comision']);
        $this->assertSame(1500.0, Formato::pago($p)['monto']);
        $this->assertNull(Formato::pago($p)['banco']);
        $this->assertArrayNotHasKey('motivo_cancelacion', Formato::pago($p));
    }

    public function testElSaldoNuncaSaleNegativo(): void
    {
        $v = ['id' => 1, 'codigo_venta' => 'V1', 'fecha' => '2026-10-16 10:00:00', 'cliente' => null,
              'total' => 100, 'cobrado' => 100.01, 'saldo' => -0.01, 'estado' => 'completada'];
        $this->assertSame(0.0, Formato::ventaResumen($v)['saldo']);
        $this->assertNull(Formato::ventaResumen($v)['cliente']);
    }

    public function testLosImportesSalenConDecimal(): void
    {
        $json = Respuesta::dato(['total' => Formato::dinero('100')])->json();
        $this->assertSame('{"datos":{"total":100.0}}', $json);
    }

    public function testLaFormaDeLaLlave(): void
    {
        $this->assertTrue(LlaveRepo::formaValida('lfk_' . str_repeat('a1', 24)));
        $this->assertFalse(LlaveRepo::formaValida('lfk_corta'));
        $this->assertFalse(LlaveRepo::formaValida('sk_' . str_repeat('a1', 24)));
        $this->assertFalse(LlaveRepo::formaValida('lfk_' . str_repeat('A1', 24)));
        $this->assertSame(64, strlen(LlaveRepo::huella('lfk_x')));
    }

    public function testLaClaveDeIdempotencia(): void
    {
        $this->assertTrue(IdempotenciaRepo::claveValida('pedido-2026-10-16:0001'));
        $this->assertTrue(IdempotenciaRepo::claveValida('3f1c0a7e-5b0c-4a7e-9f1d-2b8c0e6a9d11'));
        $this->assertFalse(IdempotenciaRepo::claveValida(''));
        $this->assertFalse(IdempotenciaRepo::claveValida(str_repeat('a', 101)));
        $this->assertFalse(IdempotenciaRepo::claveValida("abc'; DROP"));
    }
}
