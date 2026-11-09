<?php
namespace LibertyFin\Vista;

/**
 * Generador de códigos QR, en SVG.
 *
 * POR QUÉ ESTÁ ESCRITO AQUÍ Y NO ES UNA LIBRERÍA
 *
 * Las librerías de QR son buenas, pero todas llegan por Composer, y eso
 * convierte "copiar una carpeta al servidor" en "instalar dependencias y
 * mantener un vendor/". Para un sistema que vive en hostings compartidos
 * eso cuesta más de lo que ahorra.
 *
 * Y la alternativa fácil —pedirle la imagen a un servicio externo— es
 * peor: la dirección de pago del cliente viajaría a un tercero.
 *
 * Esto hace modo BYTE, corrección M, versiones 1 a 10. Alcanza para
 * cualquier dirección de pago (hasta 213 caracteres).
 */
final class Qr
{
    /** Palabras de código totales por versión. */
    const TOTAL = [1=>26,2=>44,3=>70,4=>100,5=>134,6=>172,7=>196,8=>242,9=>292,10=>346];

    /** Nivel M: [palabras de corrección por bloque, [[bloques, datos], …]] */
    const ECC = [
        1  => [10, [[1,16]]],
        2  => [16, [[1,28]]],
        3  => [26, [[1,44]]],
        4  => [18, [[2,32]]],
        5  => [24, [[2,43]]],
        6  => [16, [[4,27]]],
        7  => [18, [[4,31]]],
        8  => [22, [[2,38],[2,39]]],
        9  => [22, [[3,36],[2,37]]],
        10 => [26, [[4,43],[1,44]]],
    ];

    const ALINEACION = [
        1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30],
        6=>[6,34], 7=>[6,22,38], 8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50],
    ];

    /** Formato para nivel M, por máscara. */
    const FORMATO = [
        '101010000010010','101000100100101','101111001111100','101101101001011',
        '100010111111001','100000011001110','100111110010111','100101010100000',
    ];

    /** Información de versión, solo a partir de la 7. */
    const VERSION_INFO = [
        7=>'000111110010010100', 8=>'001000010110111100',
        9=>'001001101010011001', 10=>'001010010011010011',
    ];

    private static $exp = [], $log = [];

    /**
     * Devuelve el SVG del código.
     * @return string|null  null si el texto no cabe en la versión 10
     */
    public static function svg($texto, $tam = 190, $margen = 4)
    {
        $m = self::matriz($texto);
        if ($m === null) return null;

        $n = count($m);
        $lado = $n + $margen * 2;
        $d = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if (!$m[$y][$x]) { $x++; continue; }
                $ancho = 1;
                while ($x + $ancho < $n && $m[$y][$x + $ancho]) $ancho++;
                // Se dibujan tiras horizontales en vez de un rectángulo
                // por módulo: con 50x50 serían 2,500 nodos, y así son
                // unos cientos.
                $d .= 'M' . ($x + $margen) . ' ' . ($y + $margen) . 'h' . $ancho . 'v1h-' . $ancho . 'z';
                $x += $ancho;
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '"'
             . ' width="' . (int)$tam . '" height="' . (int)$tam . '"'
             . ' shape-rendering="crispEdges" role="img" aria-label="Código QR">'
             . '<rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/>'
             . '<path d="' . $d . '" fill="#000"/></svg>';
    }

    // ══════════════════════════════════════════════════════════
    // El armado
    // ══════════════════════════════════════════════════════════

    private static function matriz($texto)
    {
        $bytes = array_values(unpack('C*', (string)$texto));
        $largo = count($bytes);

        // La versión más chica donde quepa. Entre más chica, más grandes
        // los módulos y más fácil de leer para una cámara.
        $version = null;
        foreach (self::ECC as $v => $e) {
            if ($largo <= self::capacidad($v)) { $version = $v; break; }
        }
        if ($version === null) return null;

        $bits = self::datos($bytes, $version);
        $cw   = self::conCorreccion($bits, $version);
        return self::dibujar($cw, $version);
    }

    private static function capacidad($v)
    {
        list($eccPorBloque, $grupos) = self::ECC[$v];
        $datos = 0; $bloques = 0;
        foreach ($grupos as $g) { $datos += $g[0] * $g[1]; $bloques += $g[0]; }
        // 4 bits de modo + 8 o 16 de longitud, redondeado a bytes
        $cabecera = ($v >= 10) ? 3 : 2;
        return $datos - $cabecera;
    }

    /** Modo byte, longitud, datos, terminador y relleno. */
    private static function datos(array $bytes, $version)
    {
        list($eccPorBloque, $grupos) = self::ECC[$version];
        $datosTotales = 0;
        foreach ($grupos as $g) $datosTotales += $g[0] * $g[1];

        $b = '0100';                                   // modo byte
        $bitsLargo = ($version >= 10) ? 16 : 8;
        $b .= str_pad(decbin(count($bytes)), $bitsLargo, '0', STR_PAD_LEFT);
        foreach ($bytes as $x) $b .= str_pad(decbin($x), 8, '0', STR_PAD_LEFT);

        $max = $datosTotales * 8;
        $b .= str_repeat('0', min(4, $max - strlen($b)));      // terminador
        while (strlen($b) % 8) $b .= '0';                      // a byte completo

        // Relleno alterno, como manda el estándar.
        $relleno = ['11101100', '00010001']; $i = 0;
        while (strlen($b) < $max) { $b .= $relleno[$i % 2]; $i++; }

        $cw = [];
        for ($i = 0; $i < strlen($b); $i += 8) $cw[] = bindec(substr($b, $i, 8));
        return $cw;
    }

    /** Parte en bloques, calcula su corrección y los intercala. */
    private static function conCorreccion(array $datos, $version)
    {
        list($eccPorBloque, $grupos) = self::ECC[$version];
        self::tablasGalois();

        $bloquesDatos = []; $bloquesEcc = []; $i = 0;
        foreach ($grupos as $g) {
            for ($k = 0; $k < $g[0]; $k++) {
                $b = array_slice($datos, $i, $g[1]);
                $i += $g[1];
                $bloquesDatos[] = $b;
                $bloquesEcc[]   = self::reedSolomon($b, $eccPorBloque);
            }
        }

        // Intercalado: primera palabra de cada bloque, luego la segunda…
        $salida = [];
        $maxD = max(array_map('count', $bloquesDatos));
        for ($c = 0; $c < $maxD; $c++) {
            foreach ($bloquesDatos as $b) if (isset($b[$c])) $salida[] = $b[$c];
        }
        for ($c = 0; $c < $eccPorBloque; $c++) {
            foreach ($bloquesEcc as $b) if (isset($b[$c])) $salida[] = $b[$c];
        }
        return $salida;
    }

    private static function tablasGalois()
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;   // polinomio del QR
        }
    }

    private static function reedSolomon(array $datos, $n)
    {
        // Polinomio generador
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $nuevo = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $nuevo[$j]     ^= $c;
                $nuevo[$j + 1] ^= self::mul($c, self::$exp[$i]);
            }
            $g = $nuevo;
        }

        $r = array_merge($datos, array_fill(0, $n, 0));
        for ($i = 0; $i < count($datos); $i++) {
            $coef = $r[$i];
            if ($coef === 0) continue;
            foreach ($g as $j => $c) $r[$i + $j] ^= self::mul($c, $coef);
        }
        return array_slice($r, count($datos));
    }

    private static function mul($a, $b)
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    // ══════════════════════════════════════════════════════════
    // El dibujo
    // ══════════════════════════════════════════════════════════

    private static function dibujar(array $cw, $version)
    {
        $n = 17 + $version * 4;
        $m = array_fill(0, $n, array_fill(0, $n, null));   // null = libre

        self::patrones($m, $n, $version);

        // Los datos se escriben en zigzag, de abajo a la derecha hacia
        // arriba, saltando la columna 6 que es la de tiempo.
        $bits = '';
        foreach ($cw as $c) $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        $p = 0; $arriba = true;
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) $col = 5;
            for ($f = 0; $f < $n; $f++) {
                $fila = $arriba ? ($n - 1 - $f) : $f;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if ($m[$fila][$c] !== null) continue;
                    $m[$fila][$c] = ($p < strlen($bits)) ? (int)$bits[$p] : 0;
                    $p++;
                }
            }
            $arriba = !$arriba;
        }

        // La máscara que menos penalización deja.
        $mejor = null; $mejorPena = PHP_INT_MAX;
        for ($mk = 0; $mk < 8; $mk++) {
            $c = self::aplicarMascara($m, $n, $mk, $version);
            $pena = self::penalizacion($c, $n);
            if ($pena < $mejorPena) { $mejorPena = $pena; $mejor = $c; }
        }
        return $mejor;
    }

    private static function patrones(array &$m, $n, $version)
    {
        $buscador = function (&$m, $x, $y) {
            for ($i = -1; $i <= 7; $i++) for ($j = -1; $j <= 7; $j++) {
                $a = $y + $i; $b = $x + $j;
                if ($a < 0 || $b < 0 || $a >= count($m) || $b >= count($m)) continue;
                $borde = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                      || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6));
                $centro = ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$a][$b] = ($borde || $centro) ? 1 : 0;
            }
        };
        $buscador($m, 0, 0);
        $buscador($m, $n - 7, 0);
        $buscador($m, 0, $n - 7);

        // Tiempo
        for ($i = 8; $i < $n - 8; $i++) {
            $m[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $m[$i][6] = ($i % 2 === 0) ? 1 : 0;
        }

        // Alineación, sin pisar los buscadores
        $a = self::ALINEACION[$version];
        foreach ($a as $fy) foreach ($a as $fx) {
            if (($fx === 6 && $fy === 6) || ($fx === 6 && $fy === $n - 7)
                || ($fx === $n - 7 && $fy === 6)) continue;
            for ($i = -2; $i <= 2; $i++) for ($j = -2; $j <= 2; $j++) {
                $m[$fy + $i][$fx + $j] = (max(abs($i), abs($j)) !== 1) ? 1 : 0;
            }
        }

        // Módulo siempre oscuro
        $m[$n - 8][8] = 1;

        // Reservas de formato
        for ($i = 0; $i < 9; $i++) {
            if ($m[8][$i] === null) $m[8][$i] = 0;
            if ($m[$i][8] === null) $m[$i][8] = 0;
        }
        for ($i = $n - 8; $i < $n; $i++) {
            if ($m[8][$i] === null) $m[8][$i] = 0;
            if ($m[$i][8] === null) $m[$i][8] = 0;
        }
        // Y de versión, a partir de la 7
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) {
                $m[$i][$n - 11 + $j] = 0;
                $m[$n - 11 + $j][$i] = 0;
            }
        }
    }

    private static function esFuncion($f, $c, $n, $version)
    {
        if ($f === 6 || $c === 6) return true;
        if ($f < 9 && $c < 9) return true;
        if ($f < 9 && $c >= $n - 8) return true;
        if ($f >= $n - 8 && $c < 9) return true;
        if ($version >= 7) {
            if ($f < 6 && $c >= $n - 11 && $c < $n - 8) return true;
            if ($c < 6 && $f >= $n - 11 && $f < $n - 8) return true;
        }
        $a = self::ALINEACION[$version];
        foreach ($a as $fy) foreach ($a as $fx) {
            if (($fx === 6 && $fy === 6) || ($fx === 6 && $fy === $n - 7)
                || ($fx === $n - 7 && $fy === 6)) continue;
            if (abs($f - $fy) <= 2 && abs($c - $fx) <= 2) return true;
        }
        return false;
    }

    private static function aplicarMascara(array $m, $n, $mk, $version)
    {
        for ($f = 0; $f < $n; $f++) for ($c = 0; $c < $n; $c++) {
            if (self::esFuncion($f, $c, $n, $version)) continue;
            $x = false;
            switch ($mk) {
                case 0: $x = (($f + $c) % 2 === 0); break;
                case 1: $x = ($f % 2 === 0); break;
                case 2: $x = ($c % 3 === 0); break;
                case 3: $x = (($f + $c) % 3 === 0); break;
                case 4: $x = ((intdiv($f, 2) + intdiv($c, 3)) % 2 === 0); break;
                case 5: $x = ((($f * $c) % 2) + (($f * $c) % 3) === 0); break;
                case 6: $x = (((($f * $c) % 2) + (($f * $c) % 3)) % 2 === 0); break;
                case 7: $x = (((($f + $c) % 2) + (($f * $c) % 3)) % 2 === 0); break;
            }
            if ($x) $m[$f][$c] ^= 1;
        }

        // Formato
        $fmt = self::FORMATO[$mk];
        for ($i = 0; $i < 15; $i++) {
            $b = (int)$fmt[$i];
            if ($i < 6)       { $m[8][$i] = $b; }
            elseif ($i === 6) { $m[8][7] = $b; }
            elseif ($i === 7) { $m[8][8] = $b; }
            elseif ($i === 8) { $m[7][8] = $b; }
            else              { $m[14 - $i][8] = $b; }

            // La segunda copia del formato lleva SIETE bits en la tira
            // vertical de abajo, no ocho: el octavo lugar lo ocupa el
            // módulo que siempre va oscuro. Escribir ahí lo borraba, y el
            // lector interpretaba otro nivel de corrección.
            if ($i < 7)  { $m[$n - 1 - $i][8] = $b; }
            else         { $m[8][$n - 15 + $i] = $b; }
        }

        if ($version >= 7) {
            $vi = self::VERSION_INFO[$version];
            for ($i = 0; $i < 18; $i++) {
                $b = (int)$vi[17 - $i];
                $m[intdiv($i, 3)][$n - 11 + ($i % 3)] = $b;
                $m[$n - 11 + ($i % 3)][intdiv($i, 3)] = $b;
            }
        }
        return $m;
    }

    /** Las cuatro reglas de penalización del estándar. */
    private static function penalizacion(array $m, $n)
    {
        $p = 0;
        // 1 · cinco o más iguales seguidos
        for ($f = 0; $f < $n; $f++) {
            for ($d = 0; $d < 2; $d++) {
                $run = 1;
                for ($i = 1; $i < $n; $i++) {
                    $a = $d ? $m[$i][$f] : $m[$f][$i];
                    $b = $d ? $m[$i-1][$f] : $m[$f][$i-1];
                    if ($a === $b) { $run++; }
                    else { if ($run >= 5) $p += 3 + ($run - 5); $run = 1; }
                }
                if ($run >= 5) $p += 3 + ($run - 5);
            }
        }
        // 2 · bloques de 2x2
        for ($f = 0; $f < $n - 1; $f++) for ($c = 0; $c < $n - 1; $c++) {
            $v = $m[$f][$c];
            if ($v === $m[$f][$c+1] && $v === $m[$f+1][$c] && $v === $m[$f+1][$c+1]) $p += 3;
        }
        // 3 · patrón parecido al buscador
        $pat = [1,0,1,1,1,0,1,0,0,0,0];
        for ($f = 0; $f < $n; $f++) for ($c = 0; $c <= $n - 11; $c++) {
            $okH = true; $okV = true;
            for ($i = 0; $i < 11; $i++) {
                if ($m[$f][$c+$i] !== $pat[$i]) $okH = false;
                if ($m[$c+$i][$f] !== $pat[$i]) $okV = false;
            }
            if ($okH) $p += 40;
            if ($okV) $p += 40;
        }
        // 4 · desbalance entre claro y oscuro
        $osc = 0;
        for ($f = 0; $f < $n; $f++) $osc += array_sum($m[$f]);
        $pct = $osc * 100 / ($n * $n);
        $p += (int)(abs($pct - 50) / 5) * 10;
        return $p;
    }
}
