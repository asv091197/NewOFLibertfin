<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Servicio;

use LibertyFin\Servicio\Folio;
use PHPUnit\Framework\TestCase;

/** Los subfolios de cobro: 20261016154138-01, -02, … */
final class FolioTest extends TestCase
{
    public function testFormaElSubfolioConDosDigitos(): void
    {
        $this->assertSame('20261016154138-01', Folio::formar('20261016154138', 1));
        $this->assertSame('20261016154138-12', Folio::formar('20261016154138', 12));
    }

    public function testPasaATresDigitosDespuesDe99(): void
    {
        $this->assertSame('V1-100', Folio::formar('V1', 100));
    }

    public function testNuncaFormaElCeroONegativos(): void
    {
        $this->assertSame('V1-01', Folio::formar('V1', 0));
        $this->assertSame('V1-01', Folio::formar('V1', -3));
    }

    public function testRaizYDeteccion(): void
    {
        $this->assertSame('20261016154138', Folio::raiz('20261016154138-02'));
        $this->assertSame('20261016154138', Folio::raiz('20261016154138'));
        $this->assertTrue(Folio::esSubfolio('20261016154138-02'));
        $this->assertFalse(Folio::esSubfolio('20261016154138'));
    }

    public function testDeRespaldoUsaLaPosicion(): void
    {
        $this->assertSame('V9-03', Folio::deRespaldo('V9', 3));
    }
}
