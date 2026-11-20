<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Api\LlaveRepo;

/**
 * El sistema levantado con un servidor web de verdad (`php -S`), entrando
 * por public/index.php como en el hosting.
 *
 * Comprueba lo que las pruebas sin servidor no ven: que /api/ se atiende
 * antes que la sesión y el login, que la cabecera Authorization llega, y
 * que las pantallas siguen funcionando igual que antes de la API.
 */
final class ServidorTest extends BaseDePrueba
{
    private static $proceso = null;
    private static $base = '';
    private static $config = '';
    private static $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bd = self::configuracion();

        self::$config = tempnam(sys_get_temp_dir(), 'lfcfg') . '.php';
        file_put_contents(self::$config, '<?php return ' . var_export([
            'bd'       => ['host' => $bd['host'], 'puerto' => $bd['puerto'], 'usuario' => $bd['usuario'],
                           'clave' => $bd['clave'], 'principal' => self::PRINCIPAL],
            'depurar'  => (bool)getenv('LF_PRUEBAS_DEPURAR'),
            'zona'     => 'America/Mexico_City',
            'zona_sql' => '-06:00',
            'api'      => ['por_minuto' => 10000],
        ], true) . ';');

        $puerto = random_int(18000, 18999);
        self::$base = "http://127.0.0.1:$puerto";
        $env = getenv() + ['LF_CONFIG' => self::$config];
        $env['LF_CONFIG'] = self::$config;
        self::$proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', LF_RAIZ . '/public', LF_RAIZ . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/lf-servidor.log', 'a'],
             2 => ['file', sys_get_temp_dir() . '/lf-servidor.log', 'a']],
            $tuberias, LF_RAIZ, $env, ['bypass_shell' => true]);

        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.1);
            if ($s) { fclose($s); break; }
            usleep(100000);
        }

        self::$token = (new LlaveRepo(\LibertyFin\Datos\Conexion::de(self::PRINCIPAL)))
            ->crear(self::EMPRESA_ID, 'Servidor', 'admin')['token'];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proceso)) {
            proc_terminate(self::$proceso);
            proc_close(self::$proceso);
        }
        if (self::$config && is_file(self::$config)) unlink(self::$config);
    }

    /** @return array [estado, cabeceras (minúsculas), cuerpo] */
    private function pedir(string $metodo, string $ruta, array $cabeceras = [], ?string $cuerpo = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $metodo, 'header' => implode("\r\n", $cabeceras),
            'content' => $cuerpo ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]);
        $cuerpo = (string)@file_get_contents(self::$base . $ruta, false, $ctx);
        $enc = [];
        $estado = 0;
        foreach ($http_response_header ?? [] as $l) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $l, $m)) $estado = (int)$m[1];
            elseif (strpos($l, ':') !== false) {
                [$k, $v] = explode(':', $l, 2);
                $k = strtolower(trim($k));
                // Set-Cookie puede venir varias veces (el login renueva la sesión).
                $enc[$k] = $k === 'set-cookie' && isset($enc[$k]) ? $enc[$k] . "\n" . trim($v) : trim($v);
            }
        }
        return [$estado, $enc, $cuerpo];
    }

    public function testLaApiSinLlaveContestaJsonNoElLogin(): void
    {
        [$estado, $enc, $cuerpo] = $this->pedir('GET', '/api/v1/yo');
        $this->assertSame(401, $estado);
        $this->assertStringStartsWith('application/json', $enc['content-type'] ?? '');
        $this->assertSame('sin_llave', json_decode($cuerpo, true)['error']['codigo']);
    }

    public function testLaCabeceraAuthorizationLlega(): void
    {
        [$estado, , $cuerpo] = $this->pedir('GET', '/api/v1/yo', ['Authorization: Bearer ' . self::$token]);
        $this->assertSame(200, $estado, $cuerpo);
        $this->assertSame('Estética de Prueba', json_decode($cuerpo, true)['datos']['empresa']['nombre']);
    }

    public function testUnAbonoDePuntaAPunta(): void
    {
        $v = $this->crearVenta(300);
        [$estado, $enc, $cuerpo] = $this->pedir('POST', "/api/v1/ventas/$v/abonos",
            ['Authorization: Bearer ' . self::$token, 'Content-Type: application/json',
             'Idempotency-Key: servidor-' . $v],
            json_encode(['monto' => 300, 'metodo' => 'efectivo']));
        $this->assertSame(201, $estado, $cuerpo);
        $this->assertSame('liquidada', json_decode($cuerpo, true)['datos']['venta']['estado']);
        $this->assertSame("/api/v1/ventas/$v", $enc['location'] ?? '');
    }

    /**
     * Lo que hace un administrador: entra, abre Ajustes → API, crea una
     * llave, la usa y la revoca. Pasa por el login, la sesión, el token
     * del formulario y el portero de verdad.
     */
    public function testElAdministradorCreaUsaYRevocaUnaLlave(): void
    {
        $this->empresa()->prepare("UPDATE usuarios SET password = ? WHERE username = 'admin'")
            ->execute([password_hash('Clave-de-prueba-1', PASSWORD_DEFAULT)]);

        // Entrar.
        [, $enc, $html] = $this->pedir('GET', '/login');
        $cookie = $this->cookie($enc);
        $csrf = $this->campo($html, 'token');
        [$estado, $enc] = $this->pedir('POST', '/login', ['Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['token' => $csrf, 'usuario' => 'admin', 'clave' => 'Clave-de-prueba-1']));
        $this->assertSame(302, $estado);
        $this->assertSame('/panel', $enc['location'] ?? '');
        $cookie = $this->cookie($enc) ?: $cookie;

        // La pestaña API.
        [$estado, $enc, $html] = $this->pedir('GET', '/ajustes?t=api', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $estado, 'redirige a ' . ($enc['location'] ?? '?') . ' · ' . substr(strip_tags($html), 0, 600));
        $this->assertStringContainsString('Crear una llave', $html);
        $csrf = $this->campo($html, 'token');

        // Crear.
        [$estado, $enc] = $this->pedir('POST', '/ajustes/api/crear', ['Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['token' => $csrf, 'nombre' => 'Tienda en línea', 'rol' => 'cajero']));
        $this->assertSame(302, $estado);
        $this->assertSame('/ajustes?t=api', $enc['location'] ?? '');

        [, , $html] = $this->pedir('GET', '/ajustes?t=api', ['Cookie: ' . $cookie]);
        $this->assertMatchesRegularExpression('/id="llaveNueva"[^>]*value="(lfk_[a-f0-9]{48})"/', $html);
        preg_match('/id="llaveNueva"[^>]*value="(lfk_[a-f0-9]{48})"/', $html, $m);
        $token = $m[1];

        // Se muestra una sola vez.
        [, , $otraVez] = $this->pedir('GET', '/ajustes?t=api', ['Cookie: ' . $cookie]);
        $this->assertStringNotContainsString($token, $otraVez);

        // Usarla.
        [$estado, , $cuerpo] = $this->pedir('GET', '/api/v1/yo', ['Authorization: Bearer ' . $token]);
        $this->assertSame(200, $estado);
        $this->assertSame('cajero', json_decode($cuerpo, true)['datos']['llave']['rol']);

        // Revocarla.
        $id = (int)$this->principal()->query("SELECT id FROM lf_api_llaves WHERE nombre = 'Tienda en línea'
            ORDER BY id DESC LIMIT 1")->fetchColumn();
        [$estado] = $this->pedir('POST', '/ajustes/api/revocar', ['Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['token' => $csrf, 'id' => $id]));
        $this->assertSame(302, $estado);
        [$estado] = $this->pedir('GET', '/api/v1/yo', ['Authorization: Bearer ' . $token]);
        $this->assertSame(401, $estado);
    }

    private function cookie(array $enc): string
    {
        // La última PHPSESSID que mandó el servidor.
        return preg_match_all('/(PHPSESSID=[^;\s]+)/', $enc['set-cookie'] ?? '', $m) ? end($m[1]) : '';
    }

    private function campo(string $html, string $nombre): string
    {
        $this->assertMatchesRegularExpression('/name="' . $nombre . '" value="([^"]+)"/', $html);
        preg_match('/name="' . $nombre . '" value="([^"]+)"/', $html, $m);
        return html_entity_decode($m[1]);
    }

    /** Entra como el administrador y devuelve la cookie de la sesión. */
    private function entrar(): string
    {
        $this->empresa()->prepare("UPDATE usuarios SET password = ? WHERE username = 'admin'")
            ->execute([password_hash('Clave-de-prueba-1', PASSWORD_DEFAULT)]);
        [, $enc, $html] = $this->pedir('GET', '/login');
        $cookie = $this->cookie($enc);
        $token = $this->campo($html, 'token');
        [$estado, $enc] = $this->pedir('POST', '/login', ['Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['token' => $token, 'usuario' => 'admin', 'clave' => 'Clave-de-prueba-1']));
        $this->assertSame(302, $estado);
        $cookie = $this->cookie($enc) ?: $cookie;
        // Si otra prueba dejó la cuenta abierta, el login pregunta si se
        // cierra la otra sesión: se contesta que sí, como lo haría una persona.
        if (($enc['location'] ?? '') === '/login') {
            [, $enc2, $html] = $this->pedir('GET', '/login', ['Cookie: ' . $cookie]);
            $cookie = $this->cookie($enc2) ?: $cookie;
            [$estado, $enc] = $this->pedir('POST', '/login/confirmar', ['Cookie: ' . $cookie,
                'Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['token' => $this->campo($html, 'token')]));
            $this->assertSame(302, $estado);
            $cookie = $this->cookie($enc) ?: $cookie;
        }
        $this->assertSame('/panel', $enc['location'] ?? '');
        return $cookie;
    }

    /** Una pantalla que abre bien: 200 y sin avisos de PHP en el cuerpo. */
    private function abre(string $cookie, string $ruta, string $debeDecir = ''): string
    {
        [$estado, $enc, $html] = $this->pedir('GET', $ruta, ['Cookie: ' . $cookie]);
        $this->assertSame(200, $estado, $ruta . ' → ' . ($enc['location'] ?? '') . ' · ' . substr(strip_tags($html), 0, 400));
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:|Uncaught /', $html, $ruta);
        if ($debeDecir !== '') $this->assertStringContainsString($debeDecir, $html, $ruta);
        return $html;
    }

    private function mandar(string $cookie, string $ruta, array $datos): array
    {
        return $this->pedir('POST', $ruta, ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'],
                            http_build_query($datos));
    }

    /**
     * Inventario y Productos de la versión 35, de punta a punta: cada
     * pantalla y cada hoja para PDF abre sin errores, el buscador de la
     * captura contesta, y duplicar, desactivar y borrar un producto
     * funcionan con el formulario de verdad.
     */
    public function testInventarioYProductosDePuntaAPunta(): void
    {
        $db = $this->empresa();
        $db->exec("INSERT INTO lf_ajustes (clave, valor, actualizado) VALUES ('inventario.activo', 'si', NOW())
                   ON DUPLICATE KEY UPDATE valor = 'si'");
        $_SESSION = ['usuario_id' => 1, 'usuario_nombre' => 'Administración', 'sucursal_id' => 1];
        $al = new \LibertyFin\Servicio\Almacenes($db);
        $al->asegurar(1);
        $piso = (int)$al->principal(1)['id'];
        $bodega = 0;
        foreach ($al->deSucursal(1) as $a) if (!$a['es_principal']) $bodega = (int)$a['id'];

        $pid = $this->crearProducto('Pantalla refresco', 25);
        $db->exec("UPDATE productos SET lleva_inventario = 1, costo = 10, marca = 'MarcaPantalla' WHERE id = $pid");
        $inv = new \LibertyFin\Servicio\Inventario($db, ['activo' => 'si', 'sin_existencia' => 'avisar']);
        $inv->registrar('entrada', $pid, 1, 12, ['almacen_id' => $bodega, 'costo' => '10']);
        $entrada = (int)$db->query("SELECT MAX(id) FROM lf_inventario_movimientos")->fetchColumn();
        $inv->traspasarAlmacen($pid, $bodega, $piso, 5);
        $traspaso = (int)$db->query("SELECT MAX(id) FROM lf_inventario_movimientos")->fetchColumn();
        $cn = new \LibertyFin\Servicio\Conteo($db);
        foreach ($db->query("SELECT id FROM lf_inventario_conteos WHERE estado IN ('abierto','pausado')")
                    ->fetchAll(\PDO::FETCH_COLUMN) as $v) $cn->cancelar((int)$v);
        $c1 = $cn->abrir(1, $piso, 'Cerrado');
        $cn->capturar($c1['id'], $pid, '4');
        $cn->finalizar($c1['id']);
        $c2 = $cn->abrir(1, $piso, 'Abierto');
        $cn->capturar($c2['id'], $pid, '3');

        $cookie = $this->entrar();
        $html = $this->abre($cookie, '/inventario', 'Reporte PDF');
        $this->assertStringContainsString('Bodega', $html, 'columna por almacén');
        $this->abre($cookie, '/inventario?marca=MarcaPantalla', 'Pantalla refresco');
        $this->abre($cookie, '/inventario?t=movimientos', 'Entradas y salidas desglosadas');
        $this->abre($cookie, '/inventario?t=movimientos&tipo=traspaso', 'Bodega → Piso');
        $html = $this->abre($cookie, '/inventario?t=conteo', 'Hoja para contar');
        $this->abre($cookie, '/inventario?t=conteo&marca=MarcaPantalla', 'Pantalla refresco');
        $this->abre($cookie, "/inventario/movimiento?id=$traspaso", 'Llegó a');
        $this->abre($cookie, "/inventario/movimiento?id=$entrada", 'Descargar PDF');
        $this->abre($cookie, '/inventario/conteo/ver?id=' . $c1['id'], 'Valor de la diferencia');
        $this->abre($cookie, '/inventario/conteo/ver?id=' . $c2['id'], 'Seguir contando');
        $this->abre($cookie, '/inventario/imprimir?que=existencias&auto=1', 'Reporte de existencias');
        $this->abre($cookie, '/inventario/imprimir?que=movimientos', 'Movimientos de inventario');
        $this->abre($cookie, "/inventario/imprimir?que=movimiento&id=$traspaso", 'Se fueron');
        $this->abre($cookie, "/inventario/imprimir?que=movimiento&id=$entrada", 'Importe');
        $this->abre($cookie, '/inventario/imprimir?que=conteo&id=' . $c1['id'], 'Sistema decía');
        $this->abre($cookie, '/inventario/imprimir?que=conteo&hoja=1&id=' . $c2['id'], 'Hoja de conteo');

        // Borrar la casilla de un renglón ya contado lo quita del conteo.
        $token = $this->campo($html, 'token');
        [$estado] = $this->mandar($cookie, '/inventario/conteo', ['token' => $token, 'sucursal_id' => 1,
            'conteo_id' => $c2['id'], 'accion' => 'guardar', 'contado' => [$pid => ''], 'f_marca' => 'MarcaPantalla']);
        $this->assertSame(302, $estado);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM lf_inventario_conteo_lineas WHERE conteo_id = "
                                              . (int)$c2['id'] . " AND contado IS NOT NULL")->fetchColumn());

        // El buscador de la captura.
        [$estado, , $json] = $this->pedir('GET', '/inventario/buscar?s=1&q=Pantalla', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $estado);
        $this->assertSame($pid, json_decode($json, true)['productos'][0]['id'] ?? null);

        // Abrir una captura y ver su pantalla, con «Regresar» y el buscador.
        $db->exec("UPDATE lf_inventario_capturas SET estado = 'cancelada' WHERE estado IN ('abierta','pausada')");
        [$estado, $enc] = $this->mandar($cookie, '/inventario/captura', ['token' => $token, 'accion' => 'iniciar',
            'tipo' => 'entrada', 'sucursal_id' => 1]);
        $this->assertSame(302, $estado);
        $this->abre($cookie, $enc['location'] ?? '/inventario/captura?s=1', 'Regresar');

        // Productos: lista, filtros, edición con etiquetas, duplicar, desactivar y borrar.
        $this->abre($cookie, '/servicios', 'Duplicar');
        $this->abre($cookie, '/servicios?estado=inactivo&marca=MarcaPantalla');
        $html = $this->abre($cookie, "/servicios?editar=$pid", 'Etiquetas y atributos');
        $token = $this->campo($html, 'token');
        [$estado, $enc] = $this->mandar($cookie, '/servicios/duplicar', ['token' => $token, 'id' => $pid]);
        $this->assertSame(302, $estado);
        $this->assertMatchesRegularExpression('#^/servicios\?editar=(\d+)$#', $enc['location'] ?? '');
        $copia = (int)substr($enc['location'], strlen('/servicios?editar='));
        $this->abre($cookie, "/servicios?editar=$copia", 'Pantalla refresco (copia)');

        [$estado] = $this->mandar($cookie, '/servicios/estado', ['token' => $token, 'id' => $copia, 'estado' => 'inactivo']);
        $this->assertSame(302, $estado);
        $this->abre($cookie, '/servicios?estado=inactivo', 'Borrar');
        [$estado] = $this->mandar($cookie, '/servicios/borrar', ['token' => $token, 'id' => $copia]);
        $this->assertSame(302, $estado);
        $this->assertNotNull($db->query("SELECT eliminado_en FROM productos WHERE id = $copia")->fetchColumn() ?: null);
        $this->abre($cookie, '/caja');
    }

    public function testLasPantallasSiguenIgual(): void
    {
        // El login abre.
        [$estado, , $cuerpo] = $this->pedir('GET', '/login');
        $this->assertSame(200, $estado);
        $this->assertStringContainsString('<form', $cuerpo);

        // Sin sesión, una pantalla privada manda al login (el portero sigue ahí).
        [$estado, $enc] = $this->pedir('GET', '/ventas');
        $this->assertSame(302, $estado);
        $this->assertSame('/login', $enc['location'] ?? '');
    }
}
