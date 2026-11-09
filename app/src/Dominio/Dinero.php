<?php
namespace LibertyFin\Dominio;

/**
 * Importes en pesos.
 *
 * Existe por una razón concreta: en esta base hubo comisiones con doce
 * decimales y pagos de un centavo metidos a mano para cerrar ventas que
 * no cuadraban por redondeo. Centralizar formato y redondeo evita que
 * cada archivo lo resuelva por su cuenta.
 */
final class Dinero
{
    /** Redondeo a centavos. Todo importe que se guarde pasa por aquí. */
    public static function centavos($v) { return round((float)$v, 2); }

    /** $1,234.56 */
    public static function pesos($v, $dec = 2) { return '$' . number_format((float)$v, $dec); }

    /** $1,235 — para cifras grandes en tarjetas, donde los centavos estorban */
    public static function corto($v)
    {
        $v = (float)$v;
        if (abs($v) >= 1000000) return '$' . number_format($v / 1000000, 1) . 'M';
        return '$' . number_format($v, 0);
    }

    /**
     * Todavia mas corto, para rotulos de eje.
     *
     * `corto()` deja $181,098: ocho caracteres. En la grafica del panel
     * eso va dentro de una columna que mide un septimo del ancho, y en
     * un telefono son unos 45 pixeles. El numero se salia de su columna
     * y se encimaba con el de al lado.
     *
     *     $0        $0
     *     $181,098  $181k
     *     $1,250    $1.2k
     *
     * Se usa SOLO donde el ancho manda. Un importe que el usuario va a
     * cuadrar contra su cajon se escribe completo, siempre: redondear
     * $181,098 a $181k en un corte seria mentir para que quepa.
     */
    public static function micro($v)
    {
        $v = (float)$v;
        $a = abs($v);
        $s = $v < 0 ? '-' : '';
        // El corte va en 999,500 y no en 1,000,000: por encima de eso la
        // division entre mil redondea a 1,000 y salia `$1,000k`, que
        // ademas de feo es un caracter mas de los que caben.
        if ($a >= 999500) return $s . '$' . number_format($a / 1000000, 1) . 'M';
        if ($a >= 100000) return $s . '$' . number_format($a / 1000, 0) . 'k';
        if ($a >= 1000)    return $s . '$' . rtrim(rtrim(number_format($a / 1000, 1), '0'), '.') . 'k';
        return $s . '$' . number_format($a, 0);
    }

    /** Porcentaje de a sobre b, sin dividir entre cero. */
    public static function pct($a, $b)
    {
        $b = (float)$b;
        return $b == 0 ? 0.0 : round(((float)$a / $b) * 100, 1);
    }
}
