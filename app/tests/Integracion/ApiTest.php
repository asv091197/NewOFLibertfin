<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Api\Entrada;
use LibertyFin\Api\LlaveRepo;
use LibertyFin\Api\Puerta;
use LibertyFin\Api\Respuesta;

/**
 * La API de punta a punta, sin servidor web: se arma la petición, pasa
 * por la Puerta (llave, ritmo, ruta, empresa, permiso) y se revisa la
 * respuesta. La prueba con servidor de verdad está en ServidorTest.
 */
final class ApiTest extends BaseDePrueba
{
    private static $llaves = [];

    protected function setUp(): void
    {
        if (!self::$llaves) {
            $repo = new LlaveRepo($this->principal());
            foreach (LlaveRepo::ROLES as $rol) {
                self::$llaves[$rol] = $repo->crear(self::EMPRESA_ID, "Pruebas $rol", $rol)['token'];
            }
        }
    }

    private function llamar(string $metodo, string $uri, ?string $rol = 'admin', $cuerpo = null,
                            array $enc = [], array $cfg = ['por_minuto' => 10000]): Respuesta
    {
        if ($rol !== null) $enc['Authorization'] = 'Bearer ' . (self::$llaves[$rol] ?? $rol);
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        $crudo = is_array($cuerpo) ? json_encode($cuerpo) : (string)$cuerpo;
        return (new Puerta($cfg))->atender($metodo, $uri, new Entrada($query, $crudo, $enc));
    }

    private function error(Respuesta $r): string { return $r->cuerpo['error']['codigo'] ?? '(sin error)'; }

    // ── La llave ──

    public function testSinLlaveEs401(): void
    {
        $r = $this->llamar('GET', '/api/v1/yo', null);
        $this->assertSame(401, $r->estado);
        $this->assertSame('sin_llave', $this->error($r));
        $this->assertArrayHasKey('WWW-Authenticate', $r->encabezados);
        $this->assertArrayHasKey('X-Request-Id', $r->encabezados);
    }

    public function testUnaLlaveInventadaEs401(): void
    {
        $r = $this->llamar('GET', '/api/v1/yo', 'lfk_' . str_repeat('0', 48));
        $this->assertSame(401, $r->estado);
        $this->assertSame('llave_invalida', $this->error($r));
    }

    public function testAceptaLaCabeceraXApiKey(): void
    {
        $r = $this->llamar('GET', '/api/v1/yo', null, null, ['X-Api-Key' => self::$llaves['admin']]);
        $this->assertSame(200, $r->estado);
    }

    public function testUnaLlaveRevocadaDejaDeServir(): void
    {
        $repo = new LlaveRepo($this->principal());
        $n = $repo->crear(self::EMPRESA_ID, 'Para revocar', 'cajero');
        $this->assertSame(200, $this->llamar('GET', '/api/v1/yo', $n['token'])->estado);
        $this->assertTrue($repo->revocar($n['id'], self::EMPRESA_ID));
        $this->assertSame(401, $this->llamar('GET', '/api/v1/yo', $n['token'])->estado);
    }

    public function testNoSePuedeRevocarLaLlaveDeOtraEmpresa(): void
    {
        $repo = new LlaveRepo($this->principal());
        $n = $repo->crear(self::EMPRESA_ID, 'Ajena', 'cajero');
        $this->assertFalse($repo->revocar($n['id'], 999));
    }

    public function testLaLlaveNoSeGuardaEnClaro(): void
    {
        $filas = $this->principal()->query("SELECT * FROM lf_api_llaves")->fetchAll();
        foreach ($filas as $f) {
            foreach ($f as $v) {
                foreach (self::$llaves as $token) $this->assertNotSame($token, $v);
            }
        }
    }

    // ── Quién soy ──

    public function testYoDiceEmpresaRolYQuePuede(): void
    {
        $r = $this->llamar('GET', '/api/v1/yo', 'inventario');
        $this->assertSame(200, $r->estado);
        $d = $r->cuerpo['datos'];
        $this->assertSame('Estética de Prueba', $d['empresa']['nombre']);
        $this->assertSame('inventario', $d['llave']['rol']);
        $this->assertContains('GET /api/v1/productos', $d['puede']);
        $this->assertNotContains('GET /api/v1/ventas', $d['puede']);
        $this->assertFalse($d['comisiones_visibles']);
    }

    // ── Rutas y permisos ──

    public function testRutaInexistenteEs404(): void
    {
        $this->assertSame(404, $this->llamar('GET', '/api/v1/inventado')->estado);
        $this->assertSame(404, $this->llamar('GET', '/api/v2/ventas')->estado);
    }

    public function testMetodoEquivocadoEs405ConAllow(): void
    {
        $r = $this->llamar('DELETE', '/api/v1/ventas');
        $this->assertSame(405, $r->estado);
        $this->assertSame('GET', $r->encabezados['Allow']);
    }

    public function testElRolDeLaLlaveMandaComoEnPantalla(): void
    {
        // El cajero no ve cobranza; inventario no ve ventas ni abona.
        $this->assertSame('sin_permiso', $this->error($this->llamar('GET', '/api/v1/cobranza', 'cajero')));
        $this->assertSame(403, $this->llamar('GET', '/api/v1/ventas', 'inventario')->estado);
        $v = $this->crearVenta(100);
        $this->assertSame(403, $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'inventario', ['monto' => 10])->estado);
    }

    // ── Ventas ──

    public function testListarVentasConFiltroYPaginacion(): void
    {
        $cliente = $this->crearCliente('Listado ' . bin2hex(random_bytes(3)));
        $con = $this->crearVenta(500, $cliente);
        $this->crearVenta(300, $cliente, 'cancelada');

        $r = $this->llamar('GET', '/api/v1/ventas?estado=con_saldo&por_pagina=100');
        $this->assertSame(200, $r->estado);
        $ids = array_column($r->cuerpo['datos'], 'id');
        $this->assertContains($con, $ids);
        foreach ($r->cuerpo['datos'] as $v) $this->assertSame('con_saldo', $v['estado']);

        $p = $this->llamar('GET', '/api/v1/ventas?por_pagina=1&pagina=1')->cuerpo['meta'];
        $this->assertSame(1, $p['por_pagina']);
        $this->assertGreaterThanOrEqual(2, $p['total']);
        $this->assertSame($p['total'], $p['paginas']);
    }

    public function testParametrosInvalidosSon422(): void
    {
        $this->assertSame(422, $this->llamar('GET', '/api/v1/ventas?pagina=abc')->estado);
        $this->assertSame(422, $this->llamar('GET', '/api/v1/ventas?estado=debe')->estado);
        $this->assertSame(422, $this->llamar('GET', '/api/v1/ventas?desde=2026-10-20&hasta=2026-10-01')->estado);
    }

    public function testVerUnaVentaConSusPagos(): void
    {
        $v = $this->crearVenta(1000, $this->crearCliente());
        $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 250, 'metodo' => 'efectivo']);

        $r = $this->llamar('GET', "/api/v1/ventas/$v");
        $this->assertSame(200, $r->estado);
        $d = $r->cuerpo['datos'];
        $this->assertSame(1000.0, $d['total']);
        $this->assertSame(750.0, $d['saldo']);
        $this->assertSame('con_saldo', $d['estado']);
        $this->assertCount(1, $d['pagos']);
        $this->assertArrayHasKey('comision', $d['pagos'][0]);

        // El cajero ve el cobro, no la comisión.
        $c = $this->llamar('GET', "/api/v1/ventas/$v", 'cajero')->cuerpo['datos'];
        $this->assertArrayNotHasKey('comision', $c['pagos'][0]);
    }

    public function testUnaVentaQueNoExisteEs404(): void
    {
        $this->assertSame('venta_no_encontrada', $this->error($this->llamar('GET', '/api/v1/ventas/99999999')));
    }

    // ── Abonos ──

    public function testAbonarDevuelve201ConSubfolio(): void
    {
        $v = $this->crearVenta(1000);
        $r = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'cajero',
            ['monto' => 400.5, 'metodo' => 'transferencia', 'referencia' => 'SPEI 123', 'banco' => 'BBVA']);
        $this->assertSame(201, $r->estado, json_encode($r->cuerpo));
        $this->assertSame("/api/v1/ventas/$v", $r->encabezados['Location']);
        $d = $r->cuerpo['datos'];
        $this->assertMatchesRegularExpression('/-01$/', $d['pago']['folio']);
        $this->assertSame(400.5, $d['pago']['monto']);
        $this->assertSame('BBVA', $d['pago']['banco']);
        $this->assertSame(599.5, $d['venta']['saldo']);
    }

    public function testElReenvioConLaMismaClaveNoCobraDosVeces(): void
    {
        $v = $this->crearVenta(1000);
        $clave = 'prueba-' . bin2hex(random_bytes(6));
        $cuerpo = ['monto' => 1000, 'metodo' => 'efectivo'];

        $uno = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', $cuerpo, ['Idempotency-Key' => $clave]);
        $dos = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', $cuerpo, ['Idempotency-Key' => $clave]);

        $this->assertSame(201, $uno->estado);
        // Aunque el primero liquidó la venta, el reenvío recibe SU respuesta.
        $this->assertSame(201, $dos->estado);
        $this->assertSame('true', $dos->encabezados['Idempotent-Replayed']);
        $this->assertSame($uno->cuerpo['datos']['pago']['id'], $dos->cuerpo['datos']['pago']['id']);

        $n = (int)$this->empresa()->query("SELECT COUNT(*) FROM venta_pagos WHERE venta_id = $v")->fetchColumn();
        $this->assertSame(1, $n);
    }

    public function testLaMismaClaveConOtroCuerpoEsConflicto(): void
    {
        $v = $this->crearVenta(1000);
        $clave = 'prueba-' . bin2hex(random_bytes(6));
        $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 100], ['Idempotency-Key' => $clave]);
        $r = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 200], ['Idempotency-Key' => $clave]);
        $this->assertSame('idempotencia_en_conflicto', $this->error($r));
    }

    public function testUnAbonoRechazadoSueltaLaClave(): void
    {
        $v = $this->crearVenta(100);
        $clave = 'prueba-' . bin2hex(random_bytes(6));
        $r = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 500], ['Idempotency-Key' => $clave]);
        $this->assertSame('abono_mayor_al_saldo', $this->error($r));
        $this->assertSame(100.0, $r->cuerpo['error']['detalles']['saldo']);
        // Corregido el monto, la misma clave sirve.
        $r = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 500], ['Idempotency-Key' => $clave]);
        $this->assertSame(422, $r->estado);
    }

    public function testReglasDelAbono(): void
    {
        $v = $this->crearVenta(100);
        $casos = [
            [['monto' => '50'], 'monto_invalido'],
            [['monto' => 0], 'monto_invalido'],
            [['monto' => 10.555], 'monto_invalido'],
            [['monto' => 10, 'metodo' => 'bitcoin'], 'metodo_no_disponible'],
            [['monto' => 10, 'fecha' => '2999-01-01'], 'fecha_invalida'],
            [['monto' => 10, 'fecha' => '2026-13-01'], 'fecha_invalida'],
            [['monto' => 10, 'referencia' => ['no' => 'texto']], 'campo_invalido'],
        ];
        foreach ($casos as [$cuerpo, $codigo]) {
            $r = $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', $cuerpo);
            $this->assertSame(422, $r->estado, json_encode($cuerpo));
            $this->assertSame($codigo, $this->error($r), json_encode($cuerpo));
        }
        $this->assertSame('json_invalido', $this->error($this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', '{monto:')));
        // Nada de lo anterior dejó un cobro.
        $this->assertSame(0, (int)$this->empresa()->query("SELECT COUNT(*) FROM venta_pagos WHERE venta_id = $v")->fetchColumn());
    }

    public function testNoSeAbonaAVentasCanceladasNiLiquidadas(): void
    {
        $c = $this->crearVenta(100, null, 'cancelada');
        $this->assertSame('venta_cancelada', $this->error($this->llamar('POST', "/api/v1/ventas/$c/abonos", 'admin', ['monto' => 10])));
        $l = $this->crearVenta(100);
        $this->llamar('POST', "/api/v1/ventas/$l/abonos", 'admin', ['monto' => 100]);
        $r = $this->llamar('POST', "/api/v1/ventas/$l/abonos", 'admin', ['monto' => 1]);
        $this->assertSame(409, $r->estado);
        $this->assertSame('venta_liquidada', $this->error($r));
    }

    public function testElAbonoQuedaEnLaBitacoraComoApi(): void
    {
        $v = $this->crearVenta(100);
        $this->llamar('POST', "/api/v1/ventas/$v/abonos", 'admin', ['monto' => 10]);
        $quien = $this->empresa()->query("SELECT usuario_nombre FROM lf_auditoria
            WHERE accion = 'pago.registrar' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('API · Pruebas admin', $quien);
    }

    // ── Clientes, productos, cobranza ──

    public function testClientes(): void
    {
        $nombre = 'Buscable ' . bin2hex(random_bytes(3));
        $id = $this->crearCliente($nombre);
        $this->crearVenta(800, $id);

        $r = $this->llamar('GET', '/api/v1/clientes?q=' . urlencode($nombre));
        $this->assertSame(1, $r->cuerpo['meta']['total']);
        $this->assertSame(800.0, $r->cuerpo['datos'][0]['saldo']);
        $this->assertSame(1, $r->cuerpo['datos'][0]['compras']);

        $uno = $this->llamar('GET', "/api/v1/clientes/$id", 'cajero');
        $this->assertSame($nombre, $uno->cuerpo['datos']['nombre']);
        $this->assertArrayHasKey('direccion', $uno->cuerpo['datos']);
        $this->assertSame(404, $this->llamar('GET', '/api/v1/clientes/99999999')->estado);
    }

    public function testProductos(): void
    {
        $nombre = 'Corte ' . bin2hex(random_bytes(3));
        $this->crearProducto($nombre, 250);
        $r = $this->llamar('GET', '/api/v1/productos?q=' . urlencode($nombre), 'inventario');
        $this->assertSame(200, $r->estado);
        $this->assertSame(250.0, $r->cuerpo['datos'][0]['precio']);
    }

    public function testCobranzaConResumen(): void
    {
        $this->crearVenta(700, $this->crearCliente());
        $r = $this->llamar('GET', '/api/v1/cobranza?tramo=hasta_30&orden=saldo');
        $this->assertSame(200, $r->estado);
        $this->assertGreaterThan(0, $r->cuerpo['meta']['resumen']['por_cobrar']);
        foreach ($r->cuerpo['datos'] as $s) $this->assertLessThanOrEqual(30, $s['dias_sin_abono']);
        $this->assertSame(422, $this->llamar('GET', '/api/v1/cobranza?tramo=t30')->estado);
    }

    // ── La empresa y el ritmo ──

    public function testEmpresaSuspendidaOVencida(): void
    {
        $pri = $this->principal();
        try {
            $pri->exec("UPDATE empresas SET activo = 0 WHERE id = " . self::EMPRESA_ID);
            $this->assertSame('empresa_suspendida', $this->error($this->llamar('GET', '/api/v1/yo')));

            $pri->exec("UPDATE empresas SET activo = 1, fecha_vencimiento = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                        WHERE id = " . self::EMPRESA_ID);
            $r = $this->llamar('GET', '/api/v1/yo');
            $this->assertSame(402, $r->estado);
            $this->assertSame('suscripcion_vencida', $this->error($r));
        } finally {
            $pri->exec("UPDATE empresas SET activo = 1, fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
                        WHERE id = " . self::EMPRESA_ID);
        }
    }

    public function testLimiteDePeticionesPorMinuto(): void
    {
        $token = (new LlaveRepo($this->principal()))->crear(self::EMPRESA_ID, 'Ritmo', 'cajero')['token'];
        $cfg = ['por_minuto' => 3];
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->llamar('GET', '/api/v1/yo', $token, null, [], $cfg)->estado);
        }
        $r = $this->llamar('GET', '/api/v1/yo', $token, null, [], $cfg);
        $this->assertSame(429, $r->estado);
        $this->assertSame('60', $r->encabezados['Retry-After']);
    }
}
