<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Dominio;

use LibertyFin\Dominio\Dinero;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DineroTest extends TestCase
{
    public function testRedondeaACentavos(): void
    {
        $this->assertSame(10.13, Dinero::centavos(10.125000001));
        $this->assertSame(0.0, Dinero::centavos('basura'));
        // El caso que motivó la clase: comisiones con doce decimales.
        $this->assertSame(86.47, Dinero::centavos(86.469999999999));
    }

    public function testPesosConSeparadorDeMiles(): void
    {
        $this->assertSame('$1,234.56', Dinero::pesos(1234.56));
        $this->assertSame('$0.00', Dinero::pesos(null));
        $this->assertSame('$1,235', Dinero::pesos(1234.56, 0));
    }

    public function testCortoParaTarjetas(): void
    {
        $this->assertSame('$181,098', Dinero::corto(181098));
        $this->assertSame('$1.3M', Dinero::corto(1250000));
    }

    #[DataProvider('casosMicro')]
    public function testMicroCabeEnUnEje(float $valor, string $esperado): void
    {
        $this->assertSame($esperado, Dinero::micro($valor));
    }

    public static function casosMicro(): array
    {
        return [
            'cero'              => [0, '$0'],
            'menos de mil'      => [950, '$950'],
            'miles con decimal' => [1250, '$1.3k'],
            'miles exactos'     => [3000, '$3k'],
            'cientos de miles'  => [181098, '$181k'],
            // El borde que salía como "$1,000k".
            'casi un millón'    => [999500, '$1.0M'],
            'negativo'          => [-1500, '-$1.5k'],
        ];
    }

    public function testPorcentajeSinDividirEntreCero(): void
    {
        $this->assertSame(0.0, Dinero::pct(50, 0));
        $this->assertSame(33.3, Dinero::pct(1, 3));
        $this->assertSame(100.0, Dinero::pct(745.42, 745.42));
    }
}
