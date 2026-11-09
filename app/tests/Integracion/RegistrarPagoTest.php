<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Datos\VentaRepo;
use LibertyFin\Servicio\RegistrarPago;

/**
 * Abonos: lo que más dinero cuesta si se rompe.
 */
final class RegistrarPagoTest extends BaseDePrueba
{
    private function abonar(int $venta, float $monto, string $metodo = 'efectivo'): array
    {
        return (new RegistrarPago($this->empresa()))->abonar($venta, ['monto' => $monto, 'metodo' => $metodo]);
    }

    public function testUnAbonoBajaElSaldoYLlevaSubfolio(): void
    {
        $v = $this->crearVenta(1000, $this->crearCliente());
        $r = $this->abonar($v, 400);

        $this->assertSame('abono', $r['tipo']);
        $this->assertSame(600.0, $r['saldo']);
        $this->assertMatchesRegularExpression('/-01$/', $r['folio']);

        $d = (new VentaRepo($this->empresa()))->detalle($v);
        $this->assertEqualsWithDelta(400, (float)$d['cobrado'], 0.001);
        $this->assertEqualsWithDelta(600, (float)$d['saldo'], 0.001);
    }

    public function testElUltimoAbonoEsLiquidacionYSubeElSubfolio(): void
    {
        $v = $this->crearVenta(1000);
        $this->abonar($v, 400);
        $r = $this->abonar($v, 600);
        $this->assertSame('liquidacion', $r['tipo']);
        $this->assertSame(0.0, $r['saldo']);
        $this->assertMatchesRegularExpression('/-02$/', $r['folio']);
    }

    public function testNoSePuedeAbonarMasQueElSaldo(): void
    {
        // El caso de Izol Nieto: $864.69 sobre una venta de $745.42.
        $v = $this->crearVenta(745.42);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supera el saldo');
        $this->abonar($v, 864.69);
    }

    public function testNoSeAbonaAUnaVentaLiquidada(): void
    {
        $v = $this->crearVenta(100);
        $this->abonar($v, 100);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('liquidada');
        $this->abonar($v, 1);
    }

    public function testNoSeAbonaAUnaVentaCancelada(): void
    {
        $v = $this->crearVenta(100, null, 'cancelada');
        $this->expectException(\InvalidArgumentException::class);
        $this->abonar($v, 10);
    }

    public function testElMontoDebeSerPositivo(): void
    {
        $v = $this->crearVenta(100);
        $this->expectException(\InvalidArgumentException::class);
        $this->abonar($v, 0);
    }

    public function testCancelarUnPagoDevuelveElSaldoYNoReutilizaElSubfolio(): void
    {
        $v = $this->crearVenta(1000);
        $r1 = $this->abonar($v, 300);
        (new RegistrarPago($this->empresa()))->cancelar($r1['pago_id'], 'Se capturó dos veces');

        $d = (new VentaRepo($this->empresa()))->detalle($v);
        $this->assertEqualsWithDelta(1000, (float)$d['saldo'], 0.001);

        // El siguiente cobro es -02: un subfolio cancelado conserva su número.
        $r2 = $this->abonar($v, 300);
        $this->assertMatchesRegularExpression('/-02$/', $r2['folio']);
    }

    public function testCancelarPideMotivo(): void
    {
        $v = $this->crearVenta(100);
        $r = $this->abonar($v, 50);
        $this->expectException(\InvalidArgumentException::class);
        (new RegistrarPago($this->empresa()))->cancelar($r['pago_id'], '  ');
    }
}
