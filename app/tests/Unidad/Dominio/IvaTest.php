<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Dominio;

use LibertyFin\Dominio\Iva;
use PHPUnit\Framework\TestCase;

final class IvaTest extends TestCase
{
    public function testRechazaPorcentajesImposibles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Iva(120);
    }

    public function testIncluidoQuitaElImpuesto(): void
    {
        $iva = new Iva(16, Iva::INCLUIDO);
        $this->assertSame(100.0, $iva->quitar(116));
        $this->assertSame(['base' => 100.0, 'iva' => 16.0, 'total' => 116.0], $iva->repartir(116));
    }

    public function testSumarAgregaElImpuestoALaBase(): void
    {
        $iva = new Iva(16, Iva::SUMAR);
        // Con SUMAR el precio capturado ya es la base: no se le quita nada.
        $this->assertSame(100.0, $iva->quitar(100));
        $this->assertSame(['base' => 100.0, 'iva' => 16.0, 'total' => 116.0], $iva->repartir(100));
    }

    public function testSinIvaNoTocaElImporte(): void
    {
        $iva = new Iva(0);
        $this->assertFalse($iva->lleva());
        $this->assertSame(['base' => 250.0, 'iva' => 0.0, 'total' => 250.0], $iva->repartir(250));
    }

    public function testDeduceElPorcentajeDeLaVenta(): void
    {
        $iva = Iva::desdeVenta(['subtotal' => 1000, 'descuento' => 0, 'total' => 1160]);
        $this->assertSame(16.0, $iva->porcentaje());
    }

    public function testUnaCabeceraDescuadradaNoInventaImpuesto(): void
    {
        // total menor que la base: no hay forma sana de sacar el IVA.
        $this->assertSame(0.0, Iva::desdeVenta(['subtotal' => 1000, 'total' => 900])->porcentaje());
        // total del doble o más: tampoco.
        $this->assertSame(0.0, Iva::desdeVenta(['subtotal' => 100, 'total' => 250])->porcentaje());
        $this->assertSame(0.0, Iva::desdeVenta([])->porcentaje());
    }
}
