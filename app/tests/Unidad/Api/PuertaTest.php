<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Api;

use LibertyFin\Api\Puerta;
use LibertyFin\Dominio\Permisos;
use PHPUnit\Framework\TestCase;

/**
 * Las rutas de la API piden permisos que existen.
 *
 * Un permiso mal escrito en Puerta::RUTAS no da error: Permisos::puede()
 * contesta "no" y la ruta queda cerrada para todos. Peor sería una ruta
 * nueva con `null` por descuido: quedaría abierta a cualquier llave.
 */
final class PuertaTest extends TestCase
{
    public function testCadaRutaPideUnPermisoDeLaMatriz(): void
    {
        foreach (Puerta::RUTAS as [$metodo, $ruta, $destino, $permiso]) {
            if ($ruta === '/api/v1/yo') {
                // La única sin permiso: cualquier llave puede preguntar quién es.
                $this->assertNull($permiso);
                continue;
            }
            $this->assertNotNull($permiso, "$metodo $ruta no pide permiso");
            $this->assertArrayHasKey($permiso, Permisos::MATRIZ, "$metodo $ruta pide «{$permiso}», que no existe");
        }
    }

    public function testCadaRutaApuntaAUnControladorQueExiste(): void
    {
        foreach (Puerta::RUTAS as [$metodo, $ruta, [$clase, $accion]]) {
            $completa = 'LibertyFin\\Api\\Controlador\\' . $clase;
            $this->assertTrue(class_exists($completa), "$metodo $ruta: no existe $completa");
            $this->assertTrue(method_exists($completa, $accion), "$metodo $ruta: no existe $completa::$accion");
        }
    }

    public function testLaApiSoloOfreceLoQueLaEmpresaTieneEnPantalla(): void
    {
        // Ningún rol de plataforma (soporte, superadmin) puede algo por la API:
        // las llaves son de una empresa.
        foreach (Puerta::RUTAS as [, , , $permiso]) {
            if ($permiso === null) continue;
            foreach (['superadmin', 'soporte', 'validador'] as $rol) {
                $this->assertFalse(Permisos::puede($permiso, $rol), "$rol no debería poder $permiso");
            }
        }
    }
}
