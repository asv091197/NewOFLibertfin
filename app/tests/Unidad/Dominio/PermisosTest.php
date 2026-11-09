<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Dominio;

use LibertyFin\Dominio\Permisos;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de fondo de la matriz de permisos. Si alguien cambia la
 * matriz y rompe una de estas, la prueba lo dice antes de subir.
 */
final class PermisosTest extends TestCase
{
    public function testElCajeroCobraPeroNoVeComisiones(): void
    {
        $this->assertTrue(Permisos::puede('cobrar', 'cajero'));
        $this->assertTrue(Permisos::puede('abonar', 'cajero'));
        $this->assertFalse(Permisos::puede('ver.comisiones', 'cajero'));
        $this->assertFalse(Permisos::puede('cancelar.pago', 'cajero'));
    }

    public function testInventarioNoMueveDinero(): void
    {
        foreach (['cobrar', 'abonar', 'cancelar.pago', 'ver.ventas', 'ver.cobranza'] as $p) {
            $this->assertFalse(Permisos::puede($p, 'inventario'), $p);
        }
    }

    public function testNingunRolDePlataformaTienePermisosDeEmpresa(): void
    {
        $deEmpresa = ['ver.ventas', 'cobrar', 'abonar', 'ver.reportes', 'ver.cobranza', 'cancelar.pago'];
        foreach (['superadmin', 'soporte', 'validador'] as $rol) {
            $this->assertTrue(Permisos::esPlataforma($rol));
            foreach ($deEmpresa as $p) {
                $this->assertFalse(Permisos::puede($p, $rol), "$rol no debería poder $p");
            }
        }
    }

    public function testSoporteVeYNoMueve(): void
    {
        $this->assertTrue(Permisos::puede('ver.empresas', 'soporte'));
        $this->assertFalse(Permisos::puede('usuarios.plataforma', 'soporte'));
        $this->assertFalse(Permisos::puede('ajustes.globales', 'soporte'));
    }

    public function testUnPermisoQueNoExisteNoSeConcede(): void
    {
        $this->assertFalse(Permisos::puede('permiso.inventado', 'admin'));
        $this->assertFalse(Permisos::puede('ver.ventas', 'rol-inventado'));
        $this->assertFalse(Permisos::puede('ver.ventas', ''));
    }

    public function testCadaPermisoNombraSoloRolesQueExisten(): void
    {
        foreach (Permisos::MATRIZ as $permiso => $roles) {
            foreach ($roles as $rol) {
                $this->assertArrayHasKey($rol, Permisos::ROLES, "$permiso nombra el rol desconocido $rol");
            }
        }
    }
}
