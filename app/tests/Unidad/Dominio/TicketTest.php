<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Dominio;

use LibertyFin\Dominio\Iva;
use LibertyFin\Dominio\Ticket;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    public function testSumaLineasConCantidadYDescuento(): void
    {
        $t = (new Ticket())
            ->agregar(1, 'Corte', 250, 2)
            ->agregar(2, 'Tinte', 650, 1, 50);
        $this->assertSame(1100.0, $t->subtotalCapturado());
        $this->assertSame(1100.0, $t->totales()['total']);
    }

    public function testConIvaIncluidoSeparaBaseEImpuesto(): void
    {
        $t = (new Ticket(new Iva(16)))->agregar(1, 'Servicio', 116);
        $tot = $t->totales();
        $this->assertSame(100.0, $tot['subtotal']);
        $this->assertSame(16.0, $tot['iva']);
        $this->assertSame(116.0, $tot['total']);
    }

    public function testElAnticipoNoPuedeSuperarElTotal(): void
    {
        $t = (new Ticket())->agregar(1, 'Corte', 745.42);
        $this->expectException(\InvalidArgumentException::class);
        // El caso de Izol Nieto: un pago de $864.69 sobre una venta de $745.42.
        $t->validarAnticipo(864.69);
    }

    public function testElAnticipoNoPuedeSerNegativo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Ticket())->agregar(1, 'Corte', 100)->validarAnticipo(-1);
    }

    public function testSaldoYTipoDePago(): void
    {
        $t = (new Ticket())->agregar(1, 'Corte', 1000);
        $this->assertSame(600.0, $t->saldo(400));
        $this->assertSame('anticipo', $t->tipoPago(400));
        $this->assertSame('liquidacion', $t->tipoPago(1000));
    }
}
