<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Servicio;

use LibertyFin\Controlador\PagosEntrantesControlador;
use LibertyFin\Servicio\LigaPago;
use PHPUnit\Framework\TestCase;

/** Las piezas de los cobros en línea que no llaman al proveedor. */
final class LigaPagoTest extends TestCase
{
    public function testNormalizaElMetodo(): void
    {
        // `todos` era el alias viejo de tarjeta: no debe llegar a la base.
        $this->assertSame('tarjeta', LigaPago::normalizar('todos'));
        $this->assertSame('spei', LigaPago::normalizar('spei'));
        $this->assertSame('tarjeta', LigaPago::normalizar('inventado'));
    }

    public function testSoloLaLigaDeTarjetaSeConsulta(): void
    {
        // SPEI y tienda los avisa el proveedor; preguntarles sumaba fallos.
        $this->assertTrue(LigaPago::consultable('tarjeta'));
        $this->assertFalse(LigaPago::consultable('spei'));
        $this->assertFalse(LigaPago::consultable('efectivo'));
    }

    public function testLaSemillaTieneQuinceDigitosYNoSeRepite(): void
    {
        $vistas = [];
        for ($i = 0; $i < 200; $i++) {
            $s = LigaPago::semilla(137);
            $this->assertMatchesRegularExpression('/^9\d{14}$/', $s);
            $vistas[$s] = true;
        }
        // Reintentar el cobro de una venta no puede chocar con el intento anterior.
        $this->assertGreaterThan(190, count($vistas));
    }

    public function testLaSemillaIgnoraUnPrefijoInvalido(): void
    {
        $this->assertStringStartsWith('9', LigaPago::semilla(1, '0'));
        $this->assertStringStartsWith('3', LigaPago::semilla(1, '3'));
    }

    public function testReconoceLasRutasDelSistemaAnterior(): void
    {
        // Las que la cuenta de producción del proveedor todavía tiene dadas de alta.
        $this->assertTrue(PagosEntrantesControlador::esRutaAnterior('/Service/PagoClabe'));
        $this->assertTrue(PagosEntrantesControlador::esRutaAnterior('Service/ConsultaClabe/'));
        $this->assertFalse(PagosEntrantesControlador::esRutaAnterior('/service/pagoclabe'));
        $this->assertFalse(PagosEntrantesControlador::esRutaAnterior('/ventas'));
    }
}
