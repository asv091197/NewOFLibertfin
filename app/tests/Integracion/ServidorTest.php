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
