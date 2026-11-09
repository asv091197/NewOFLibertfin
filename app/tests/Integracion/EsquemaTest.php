<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Servicio\Migraciones;

/**
 * Una empresa nueva arranca con el esquema completo y al día.
 *
 * Si alguien cambia config/esquema.sql o agrega una migración que no
 * corre en MySQL, esto falla aquí y no en el alta del próximo cliente.
 */
final class EsquemaTest extends BaseDePrueba
{
    public function testElEsquemaCargaSinErrores(): void
    {
        $this->assertSame([], self::erroresEsquema());
    }

    public function testLasMigracionesQuedanAlDia(): void
    {
        $this->assertTrue(Migraciones::alDia($this->empresa()));
        $this->assertSame(Migraciones::VERSION, Migraciones::versionDe($this->empresa()));
    }

    public function testAplicarDeNuevoNoHaceNada(): void
    {
        $this->assertSame([], Migraciones::aplicar($this->empresa()));
    }

    public function testExistenLasTablasDelNucleo(): void
    {
        $hay = $this->empresa()->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['ventas', 'venta_pagos', 'clientes', 'productos', 'venta_comisiones',
                  'pago_comisiones', 'lf_ajustes', 'lf_auditoria', 'lf_ligas_pago'] as $t) {
            $this->assertContains($t, $hay, "falta la tabla $t");
        }
    }
}
