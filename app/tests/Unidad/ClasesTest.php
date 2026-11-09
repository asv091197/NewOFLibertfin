<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Unidad;

use PHPUnit\Framework\TestCase;

/**
 * Toda clase que el código nombra existe.
 *
 * `php -l` solo revisa la sintaxis: `new LibertyFinApiLlaveRepo()` (al
 * que se le cayeron las diagonales) o un `catch (Throwable $e)` dentro de
 * un namespace sin su `\` son PHP válido. El primero truena con un 500 en
 * cuanto alguien abre esa pantalla; el segundo es peor: no truena, pero
 * el catch nunca atrapa nada y el error se le escapa al usuario.
 *
 * Esta prueba lee cada archivo de src/ y public/ y comprueba que cada
 * nombre que aparece en `new X`, `X::`, `catch (X …)` e `instanceof X`
 * corresponde a una clase o interfaz que existe.
 */
final class ClasesTest extends TestCase
{
    const IGNORAR = ['self', 'static', 'parent', 'class'];

    /**
     * Clases de extensiones de PHP que el servidor necesita pero que un
     * equipo de desarrollo puede no tener encendidas: soap (recargas de
     * Emida) y zip (leer y escribir Excel). Ver README → Requisitos.
     */
    const DE_EXTENSIONES = ['SoapClient' => 'soap', 'SoapFault' => 'soap', 'ZipArchive' => 'zip'];

    public function testTodaClaseNombradaExiste(): void
    {
        $faltan = [];
        $raiz = LF_RAIZ;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS));
        $archivos = array_merge(iterator_to_array($it, false), [new \SplFileInfo($raiz . '/public/index.php')]);
        foreach ($archivos as $f) {
            if (substr($f->getPathname(), -4) !== '.php') continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
            foreach ($this->referencias((string)file_get_contents($f->getPathname())) as [$clase, $linea]) {
                if (isset(self::DE_EXTENSIONES[$clase]) && !extension_loaded(self::DE_EXTENSIONES[$clase])) continue;
                if (!class_exists($clase) && !interface_exists($clase) && !trait_exists($clase)) {
                    $faltan[] = "$rel:$linea  $clase";
                }
            }
        }
        $this->assertSame([], $faltan, "Clases que no existen:\n" . implode("\n", $faltan));
    }

    /** @return array<array{string,int}> [nombre completo, línea] */
    private function referencias(string $codigo): array
    {
        $t = array_values(array_filter(token_get_all($codigo), function ($x) {
            return !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $ns = '';
        $usos = [];
        $refs = [];
        $nombre = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $profundidad = 0;

        for ($i = 0, $n = count($t); $i < $n; $i++) {
            $x = $t[$i];
            if ($x === '{') $profundidad++;
            if ($x === '}') $profundidad--;
            if (!is_array($x)) continue;

            if ($x[0] === T_NAMESPACE && isset($t[$i + 1]) && is_array($t[$i + 1]) && in_array($t[$i + 1][0], $nombre, true)) {
                $ns = $t[$i + 1][1];
            } elseif ($x[0] === T_USE && $profundidad === 0 && isset($t[$i + 1]) && is_array($t[$i + 1])
                      && in_array($t[$i + 1][0], $nombre, true)) {
                // use A\B\C;  ·  use A\B\C as D;
                $completo = ltrim($t[$i + 1][1], '\\');
                $alias = substr($completo, (int)strrpos('\\' . $completo, '\\'));
                if (isset($t[$i + 2]) && is_array($t[$i + 2]) && $t[$i + 2][0] === T_AS) $alias = $t[$i + 3][1];
                $usos[strtolower($alias)] = $completo;
            } elseif ($x[0] === T_NEW || $x[0] === T_INSTANCEOF) {
                $s = $t[$i + 1] ?? null;
                if (is_array($s) && in_array($s[0], $nombre, true)) $refs[] = [$s[1], $s[2]];
            } elseif ($x[0] === T_DOUBLE_COLON) {
                $p = $t[$i - 1] ?? null;
                if (is_array($p) && in_array($p[0], $nombre, true)) $refs[] = [$p[1], $p[2]];
            } elseif ($x[0] === T_CATCH) {
                for ($j = $i + 2; $j < $n && $t[$j] !== ')'; $j++) {
                    if (is_array($t[$j]) && in_array($t[$j][0], $nombre, true)) $refs[] = [$t[$j][1], $t[$j][2]];
                    if (is_array($t[$j]) && $t[$j][0] === T_VARIABLE) break;
                }
            }
        }

        $r = [];
        foreach ($refs as [$crudo, $linea]) {
            if (in_array(strtolower($crudo), self::IGNORAR, true)) continue;
            $r[] = [$this->resolver($crudo, $ns, $usos), $linea];
        }
        return $r;
    }

    private function resolver(string $crudo, string $ns, array $usos): string
    {
        if ($crudo[0] === '\\') return ltrim($crudo, '\\');
        $primero = strtolower(explode('\\', $crudo)[0]);
        if (isset($usos[$primero])) {
            $resto = substr($crudo, strlen($primero));
            return $usos[$primero] . $resto;
        }
        return $ns !== '' ? $ns . '\\' . $crudo : $crudo;
    }
}
