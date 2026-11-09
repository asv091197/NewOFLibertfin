<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Dominio;

use LibertyFin\Dominio\Comision;
use PHPUnit\Framework\TestCase;

/**
 * La regla de comisión: ASIGNADA si el cliente liquida, DEVENGADA en
 * proporción a lo cobrado, con el gasto de operación restado completo.
 */
final class ComisionTest extends TestCase
{
    private function regla(): Comision
    {
        // $1,000 sin IVA, costo $200, gasto de operación $100.
        return new Comision(1000, 1, 0, 200, 100);
    }

    public function testAsignadaSobreLaUtilidadMenosElGasto(): void
    {
        $this->assertSame(700.0, $this->regla()->baseAsignada());
        $this->assertSame(70.0, $this->regla()->asignada(10));
    }

    public function testDevengadaEnProporcionALoCobrado(): void
    {
        $r = $this->regla();
        // Cobrado la mitad: 500 - 200 de costo - 100 de gasto = 200.
        $this->assertSame(200.0, $r->baseDevengada(500));
        $this->assertSame(20.0, $r->devengada(10, 500));
        $this->assertSame(50.0, $r->pendiente(10, 500));
    }

    public function testElPrimerPagoAbsorbeElGastoCompleto(): void
    {
        // Con poco cobrado, costo y gasto se comen la base: nunca negativa.
        $this->assertSame(0.0, $this->regla()->devengada(10, 100));
    }

    public function testLiquidadaDevengaLoMismoQueLaAsignada(): void
    {
        $r = $this->regla();
        $this->assertSame($r->asignada(12.5), $r->devengada(12.5, 1000));
        // Cobrar de más no da más comisión.
        $this->assertSame($r->asignada(12.5), $r->devengada(12.5, 5000));
    }

    public function testDesdeLineaQuitaElIva(): void
    {
        $venta = ['subtotal' => 1000, 'descuento' => 0, 'total' => 1160];
        $r = Comision::desdeLinea($venta, ['precio_unitario' => 1160, 'cantidad' => 1, 'costo' => 0]);
        $this->assertSame(1000.0, $r->valorLinea());
    }
}
