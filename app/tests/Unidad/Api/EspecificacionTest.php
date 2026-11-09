<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad\Api;

use LibertyFin\Api\Puerta;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * La documentación de la API dice lo mismo que el código.
 *
 * Quien integra otro sistema programa contra docs/api, no contra src/.
 * Una ruta nueva sin documentar no la usa nadie; una ruta documentada
 * que no existe le cuesta a alguien una tarde. Y un código de error que
 * la API contesta pero la guía no menciona es un caso que el otro
 * sistema no sabe manejar.
 */
final class EspecificacionTest extends TestCase
{
    private const PREFIJO = '/api/v1';

    private static function docs(): string
    {
        return dirname(LF_RAIZ) . '/docs/api';
    }

    /** @return array<string, mixed> */
    private static function openapi(): array
    {
        return Yaml::parseFile(self::docs() . '/openapi.yaml');
    }

    /** @return list<string> «GET /ventas/{id}» */
    private static function rutasDelCodigo(): array
    {
        $r = [];
        foreach (Puerta::RUTAS as [$metodo, $ruta]) {
            $r[] = $metodo . ' ' . substr($ruta, strlen(self::PREFIJO));
        }
        sort($r);
        return $r;
    }

    /** @return list<string> */
    private static function rutasDocumentadas(array $spec): array
    {
        $r = [];
        foreach ($spec['paths'] as $ruta => $operaciones) {
            foreach (array_keys($operaciones) as $metodo) {
                if (in_array($metodo, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $r[] = strtoupper($metodo) . ' ' . $ruta;
                }
            }
        }
        sort($r);
        return $r;
    }

    /** Los códigos que algún punto de src/Api/ puede contestar. */
    private static function codigosDeError(): array
    {
        $codigos = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(LF_RAIZ . '/src/Api'));
        foreach ($it as $archivo) {
            if ($archivo->getExtension() !== 'php') continue;
            $codigo = (string) file_get_contents($archivo->getPathname());
            preg_match_all("/(?:new ErrorApi|Respuesta::error)\(\s*\d+\s*,\s*'([a-z_]+)'/", $codigo, $m);
            foreach ($m[1] as $c) $codigos[$c] = true;
        }
        ksort($codigos);
        return array_keys($codigos);
    }

    public function testLaEspecificacionEsOpenApiValidaEnLoBasico(): void
    {
        $spec = self::openapi();
        $this->assertMatchesRegularExpression('/^3\.\d+\.\d+$/', (string) $spec['openapi']);
        $this->assertStringEndsWith(self::PREFIJO, $spec['servers'][0]['url']);
        $this->assertArrayHasKey('llave', $spec['components']['securitySchemes']);
    }

    public function testDocumentaExactamenteLasRutasQueExisten(): void
    {
        $this->assertSame(
            self::rutasDelCodigo(),
            self::rutasDocumentadas(self::openapi()),
            'docs/api/openapi.yaml y Puerta::RUTAS no coinciden'
        );
    }

    public function testNingunaReferenciaApuntaANada(): void
    {
        $spec = self::openapi();
        $refs = [];
        array_walk_recursive($spec, function ($v, $k) use (&$refs) {
            if ($k === '$ref') $refs[] = $v;
        });
        $this->assertNotEmpty($refs);

        foreach (array_unique($refs) as $ref) {
            $nodo = $spec;
            foreach (explode('/', substr($ref, 2)) as $parte) {
                $this->assertIsArray($nodo, "$ref no existe");
                $this->assertArrayHasKey($parte, $nodo, "$ref no existe");
                $nodo = $nodo[$parte];
            }
        }
    }

    public function testCadaCodigoDeErrorEstaDocumentado(): void
    {
        $guia = (string) file_get_contents(self::docs() . '/README.md');
        $spec = (string) file_get_contents(self::docs() . '/openapi.yaml');
        $codigos = self::codigosDeError();
        $this->assertGreaterThan(10, count($codigos), 'No se encontraron los códigos de error en src/Api');

        foreach ($codigos as $c) {
            $this->assertStringContainsString("`$c`", $guia, "docs/api/README.md no menciona el error «{$c}»");
            $this->assertStringContainsString($c, $spec, "docs/api/openapi.yaml no menciona el error «{$c}»");
        }
    }
}
