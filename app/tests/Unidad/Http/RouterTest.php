<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Http;

use LibertyFin\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/ventas', ['C', 'index']);
        $r->get('/ventas/{id}', ['C', 'ver']);
        $r->post('/ventas/{id}/pagar', ['C', 'pagar']);
        $r->post('/pagadetodo/{k}/pago-clabe', ['P', 'pagoClabe']);
        return $r;
    }

    public function testDespachaConParametrosNumericos(): void
    {
        $h = $this->router()->despachar('GET', '/ventas/137?x=1');
        $this->assertSame(['C', 'ver'], $h['destino']);
        $this->assertSame([137], $h['args']);
        $this->assertSame('/ventas/{id}', $h['patron']);
    }

    public function testUnIdConTextoNoCoincide(): void
    {
        // Así un id no puede traer texto que termine en una consulta.
        $this->assertNull($this->router()->despachar('GET', "/ventas/1 OR 1=1"));
        $this->assertNull($this->router()->despachar('GET', '/ventas/abc'));
    }

    public function testElMetodoImporta(): void
    {
        $this->assertNull($this->router()->despachar('GET', '/ventas/5/pagar'));
        $this->assertNotNull($this->router()->despachar('POST', '/ventas/5/pagar'));
    }

    public function testIgnoraDiagonalesSobrantes(): void
    {
        $this->assertSame(['C', 'index'], $this->router()->despachar('GET', '/ventas/')['destino']);
    }

    public function testElSecretoDeLosAvisosAceptaLetras(): void
    {
        // Con solo dígitos la ruta nunca coincidía y el proveedor revertía el cobro.
        $h = $this->router()->despachar('POST', '/pagadetodo/a1B2-c3_d4/pago-clabe');
        $this->assertSame(['a1B2-c3_d4'], $h['args']);
    }
}
