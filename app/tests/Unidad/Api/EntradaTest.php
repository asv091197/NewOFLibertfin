<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Api;

use LibertyFin\Api\Entrada;
use LibertyFin\Api\ErrorApi;
use PHPUnit\Framework\TestCase;

final class EntradaTest extends TestCase
{
    private function falla(callable $f, int $estado, string $codigo): void
    {
        try {
            $f();
            $this->fail('Se esperaba un ErrorApi');
        } catch (ErrorApi $e) {
            $this->assertSame($estado, $e->estado());
            $this->assertSame($codigo, $e->codigo());
        }
    }

    public function testPaginacionPorOmision(): void
    {
        $e = new Entrada([]);
        $this->assertSame(1, $e->pagina());
        $this->assertSame(25, $e->porPagina());
    }

    public function testPaginacionInvalidaEsUn422(): void
    {
        $this->falla(fn() => (new Entrada(['pagina' => 'abc']))->pagina(), 422, 'parametro_invalido');
        $this->falla(fn() => (new Entrada(['pagina' => '0']))->pagina(), 422, 'parametro_invalido');
        $this->falla(fn() => (new Entrada(['por_pagina' => '500']))->porPagina(), 422, 'parametro_invalido');
        $this->assertSame(100, (new Entrada(['por_pagina' => '100']))->porPagina());
    }

    public function testFechas(): void
    {
        $this->assertSame('2026-10-16', (new Entrada(['desde' => '2026-10-16']))->fecha('desde'));
        $this->assertSame('', (new Entrada([]))->fecha('desde'));
        $this->falla(fn() => (new Entrada(['desde' => '2026-02-30']))->fecha('desde'), 422, 'parametro_invalido');
        $this->falla(fn() => (new Entrada(['desde' => '16/10/2026']))->fecha('desde'), 422, 'parametro_invalido');
    }

    public function testOpcionDeListaCerrada(): void
    {
        $this->assertSame('dias', (new Entrada([]))->opcion('orden', ['dias', 'saldo'], 'dias'));
        $this->falla(fn() => (new Entrada(['orden' => 'DROP']))->opcion('orden', ['dias', 'saldo']),
            422, 'parametro_invalido');
    }

    public function testElCuerpoDebeSerUnObjeto(): void
    {
        $this->assertSame(['monto' => 5], (new Entrada([], '{"monto":5}'))->cuerpo());
        $this->assertSame([], (new Entrada([], ''))->cuerpo());
        $this->falla(fn() => (new Entrada([], '{monto: 5'))->cuerpo(), 400, 'json_invalido');
        $this->falla(fn() => (new Entrada([], '[1,2]'))->cuerpo(), 400, 'json_invalido');
        $this->falla(fn() => (new Entrada([], '"hola"'))->cuerpo(), 400, 'json_invalido');
    }

    public function testLasCabecerasNoDistinguenMayusculas(): void
    {
        $e = new Entrada([], '', ['Idempotency-Key' => ' abc ']);
        $this->assertSame('abc', $e->encabezado('idempotency-key'));
    }

    public function testLaHuellaReconoceElMismoCuerpo(): void
    {
        $a = new Entrada([], '{"monto": 5, "metodo": "efectivo"}');
        $b = new Entrada([], '{"monto":5,"metodo":"efectivo"}');
        $c = new Entrada([], '{"monto":6,"metodo":"efectivo"}');
        $this->assertSame($a->huella(), $b->huella());
        $this->assertNotSame($a->huella(), $c->huella());
    }
}
