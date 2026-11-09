<?php
namespace LibertyFin\Dominio;

/**
 * Los impuestos de UN producto: IVA e IEPS.
 *
 * POR QUÉ NO BASTABA Iva
 *
 * `Iva` es la regla de la EMPRESA: un porcentaje y un modo, igual para
 * todo lo que se vende. Para un despacho que factura servicios está
 * bien. Para una tienda no: en el mismo mostrador conviven el refresco
 * (IVA 16 % y además IEPS), el cuaderno (IVA 16 %) y la tortilla (tasa
 * cero). Cobrarle IVA a la tortilla no es un redondeo: es una factura
 * mal timbrada.
 *
 * Así que cada producto puede decir lo suyo, y el que no diga nada sigue
 * usando la de la empresa — que es exactamente como se comportaba todo
 * antes de esto. Un producto con `iva_tasa` en NULL no es un producto
 * sin IVA: es uno que no ha opinado.
 *
 * EL IEPS VA ANTES QUE EL IVA, Y NO ES UN DETALLE
 *
 * El IVA se calcula sobre la base MÁS el IEPS, no sobre la base sola.
 * Un refresco de $100 con IEPS 8 % e IVA 16 % no son $124:
 *
 *     base  100.00
 *     IEPS    8.00   (100 × 8 %)
 *     IVA     17.28  (108 × 16 %)   ← sobre 108, no sobre 100
 *     total 125.28
 *
 * Hacerlo al revés da $124.00 y descuadra la factura por $1.28 en cada
 * renglón. Es el tipo de error que nadie ve hasta que el SAT lo ve.
 */
final class Impuesto
{
    /** Las tasas de IEPS que de verdad se usan, para el selector. */
    const TASAS_IEPS = [
        '3'   => '3 % · seguros',
        '6'   => '6 % · plaguicidas',
        '7'   => '7 % · alimentos de alta densidad calórica',
        '8'   => '8 % · bebidas saborizadas, botanas',
        '9'   => '9 % · plaguicidas',
        '25'  => '25 % · bebidas con hasta 14° de alcohol',
        '26.5'=> '26.5 % · bebidas de 14° a 20°',
        '30'  => '30 % · bebidas de más de 20°',
        '30.4'=> '30.4 % · tabaco labrado',
        '53'  => '53 % · bebidas con más de 20° de alcohol',
        '160' => '160 % · tabacos',
    ];

    /** Las de IVA en México. Cero es una tasa, no una ausencia. */
    const TASAS_IVA = [
        '16' => '16 % · general',
        '8'  => '8 % · región fronteriza',
        '0'  => '0 % · tasa cero (alimentos, medicinas)',
    ];

    private $iva;     // porcentaje
    private $ieps;    // porcentaje
    private $modo;    // Iva::INCLUIDO | Iva::SUMAR

    public function __construct($iva = 0.0, $ieps = 0.0, $modo = Iva::INCLUIDO)
    {
        foreach ([$iva, $ieps] as $p) {
            if ((float)$p < 0 || (float)$p > 200) {
                throw new \InvalidArgumentException('La tasa debe estar entre 0 y 200');
            }
        }
        $this->iva  = (float)$iva;
        $this->ieps = (float)$ieps;
        $this->modo = ($modo === Iva::SUMAR) ? Iva::SUMAR : Iva::INCLUIDO;
    }

    /**
     * Lo que paga este producto, con la regla de la empresa de respaldo.
     *
     * @param array $p        el renglón de `productos`
     * @param Iva   $empresa  la regla de la empresa (ConfigRepo)
     */
    public static function deProducto(array $p, Iva $empresa)
    {
        // Un producto sin las columnas nuevas —una venta vieja, una
        // consulta que no las pidió— se comporta como siempre: la regla
        // de la empresa. Ausencia no es exención.
        $aplicaIva = array_key_exists('iva_aplica', $p) ? !empty($p['iva_aplica']) : true;

        $iva = 0.0;
        if ($aplicaIva) {
            $tasa = $p['iva_tasa'] ?? null;
            // NULL = "la de la empresa". 0 = "tasa cero", que es una
            // decisión y se respeta. Por eso no se usa ?: aquí.
            $iva = ($tasa === null || $tasa === '') ? $empresa->porcentaje() : (float)$tasa;
        }

        $ieps = 0.0;
        if (!empty($p['ieps_aplica'])) {
            $t = $p['ieps_tasa'] ?? null;
            $ieps = ($t === null || $t === '') ? 0.0 : (float)$t;
        }

        return new self($iva, $ieps, $empresa->modo());
    }

    /** La regla de la empresa, sin IEPS. Para servicios. */
    public static function deEmpresa(Iva $empresa)
    {
        return new self($empresa->porcentaje(), 0.0, $empresa->modo());
    }

    public function iva()     { return $this->iva; }
    public function ieps()    { return $this->ieps; }
    public function modo()    { return $this->modo; }
    public function lleva()   { return $this->iva > 0 || $this->ieps > 0; }
    public function llevaIeps() { return $this->ieps > 0; }

    /**
     * Cuánto multiplica a la base para llegar al total.
     * IEPS primero, IVA sobre lo que resulte.
     */
    public function factor()
    {
        return (1 + $this->ieps / 100) * (1 + $this->iva / 100);
    }

    /**
     * Reparte un importe en base, IEPS, IVA y total.
     *
     * En modo INCLUIDO el importe YA trae los impuestos y el total no se
     * mueve: se reparte. En modo SUMAR el importe ES la base y el total
     * crece.
     *
     * El IVA se calcula como total − base − ieps y no multiplicando otra
     * vez, para que las tres partes sumen EXACTAMENTE el total. Con dos
     * redondeos independientes sobran o faltan centavos, y un ticket que
     * no cuadra por un centavo es un ticket que alguien va a reclamar.
     */
    public function repartir($importe)
    {
        $importe = round((float)$importe, 2);
        if (!$this->lleva()) {
            return ['base' => $importe, 'ieps' => 0.0, 'iva' => 0.0, 'total' => $importe];
        }

        if ($this->modo === Iva::SUMAR) {
            $base  = $importe;
            $ieps  = round($base * ($this->ieps / 100), 2);
            $total = round(($base + $ieps) * (1 + $this->iva / 100), 2);
        } else {
            $total = $importe;
            $base  = round($total / $this->factor(), 2);
            $ieps  = round($base * ($this->ieps / 100), 2);
        }
        return ['base'  => $base,
                'ieps'  => $ieps,
                'iva'   => round($total - $base - $ieps, 2),
                'total' => $total];
    }

    /** La base de un importe: lo que queda al quitarle los impuestos. */
    public function quitar($importe)
    {
        $r = $this->repartir($importe);
        return $r['base'];
    }

    /** Cómo se lee en un ticket: «IVA 16 %», «IVA 16 % + IEPS 8 %», «Exento». */
    public function rotulo()
    {
        if (!$this->lleva()) return 'Exento';
        $p = [];
        if ($this->iva > 0)  $p[] = 'IVA ' . self::pct($this->iva) . ' %';
        if ($this->ieps > 0) $p[] = 'IEPS ' . self::pct($this->ieps) . ' %';
        return implode(' + ', $p);
    }

    /** 16 → «16», 26.5 → «26.5». Sin ceros que nadie escribió. */
    private static function pct($n)
    {
        return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    }
}