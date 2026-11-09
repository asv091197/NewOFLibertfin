<?php
namespace LibertyFin\Vista;

/**
 * Código de barras Code128, en SVG.
 *
 * Es el que leen las cajas de OXXO y tiendas de conveniencia para los
 * pagos por referencia. Mucho más simple que un QR: una tabla de 107
 * patrones, un cambio de juego de caracteres y una suma de control.
 *
 * Se usa el juego C cuando hay pares de dígitos, porque codifica dos por
 * símbolo: una referencia de 15 dígitos pasa de 15 barras a 8, y cabe en
 * un ticket angosto sin apretarse.
 */
final class Barras
{
    /** Anchos de las barras y espacios de cada símbolo, 0 a 106. */
    const PATRONES = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];

    const INICIO_B = 104;
    const INICIO_C = 105;
    const CAMBIO_C = 99;
    const CAMBIO_B = 100;
    const FIN      = 106;

    /**
     * El SVG del código.
     *
     * @param string $texto   lo que se codifica
     * @param int    $alto    alto de las barras en píxeles
     * @param bool   $rotulo  si se escribe el texto debajo
     */
    public static function svg($texto, $alto = 56, $rotulo = true)
    {
        $texto = (string)$texto;
        if ($texto === '') return null;

        $codigos = self::codificar($texto);
        if ($codigos === null) return null;

        // Suma de control: el inicio por 1, y cada símbolo por su
        // posición. Sin ella el lector pita pero no acepta.
        $suma = $codigos[0];
        for ($i = 1; $i < count($codigos); $i++) $suma += $codigos[$i] * $i;
        $codigos[] = $suma % 103;
        $codigos[] = self::FIN;

        $barras = '';
        $x = 0;
        foreach ($codigos as $c) {
            $p = self::PATRONES[$c];
            $negro = true;
            for ($i = 0; $i < strlen($p); $i++) {
                $w = (int)$p[$i];
                if ($negro) $barras .= 'M' . $x . ' 0h' . $w . 'v' . $alto . 'h-' . $w . 'z';
                $x += $w;
                $negro = !$negro;
            }
        }

        $margen = 10;
        $altoTotal = $alto + ($rotulo ? 16 : 0);
        $ancho = $x + $margen * 2;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $ancho . ' '
             . ($altoTotal + 4) . '" width="100%" height="' . ($altoTotal + 4)
             . '" shape-rendering="crispEdges" role="img" aria-label="Código de barras ' 
             . htmlspecialchars($texto, ENT_QUOTES) . '">'
             . '<rect width="' . $ancho . '" height="' . ($altoTotal + 4) . '" fill="#fff"/>'
             . '<g transform="translate(' . $margen . ',2)">'
             . '<path d="' . $barras . '" fill="#000"/></g>';

        if ($rotulo) {
            $svg .= '<text x="' . ($ancho / 2) . '" y="' . ($alto + 16)
                 . '" text-anchor="middle" font-family="ui-monospace,monospace" font-size="12"'
                 . ' letter-spacing="2" fill="#000">'
                 . htmlspecialchars($texto, ENT_QUOTES) . '</text>';
        }
        return $svg . '</svg>';
    }

    /**
     * Elige el juego de caracteres.
     *
     * Con puros dígitos y longitud par, el juego C de principio a fin.
     * Si no, se empieza en B y se salta a C en las tiras largas de
     * dígitos, que es donde vale la pena.
     */
    private static function codificar($texto)
    {
        $soloDigitos = ctype_digit($texto);

        if ($soloDigitos && strlen($texto) % 2 === 0) {
            $c = [self::INICIO_C];
            for ($i = 0; $i < strlen($texto); $i += 2) {
                $c[] = (int)substr($texto, $i, 2);
            }
            return $c;
        }

        // Juego B, y a C cuando vienen seis o más dígitos seguidos.
        $c = [self::INICIO_B];
        $modo = 'B';
        $i = 0;
        $n = strlen($texto);
        while ($i < $n) {
            $seguidos = 0;
            while ($i + $seguidos < $n && ctype_digit($texto[$i + $seguidos])) $seguidos++;

            if ($seguidos >= 6) {
                $pares = intdiv($seguidos, 2);
                if ($modo !== 'C') { $c[] = self::CAMBIO_C; $modo = 'C'; }
                for ($k = 0; $k < $pares; $k++) { $c[] = (int)substr($texto, $i, 2); $i += 2; }
                continue;
            }

            if ($modo !== 'B') { $c[] = self::CAMBIO_B; $modo = 'B'; }
            $ch = ord($texto[$i]);
            if ($ch < 32 || $ch > 126) return null;   // fuera del juego B
            $c[] = $ch - 32;
            $i++;
        }
        return $c;
    }
}
