<?php
namespace LibertyFin\Dominio;

/**
 * La regla del IVA. UN solo lugar.
 *
 * Hoy esta lógica vive repartida en caja.php, iva_venta.php,
 * ajustar_venta.php y guardar_comision_producto.php, y cada copia la
 * resuelve distinto. De ahí salieron los tres errores que corregimos:
 * la base de Francisco Flores inflada a 672.80, la comisión de Reyna
 * calculada sobre 579.98 en vez de 499.98, y el doble descuento de
 * Leopoldo.
 *
 * Dos modos, y la diferencia importa:
 *
 *   INCLUIDO  el precio capturado YA trae el impuesto adentro.
 *             El total no se mueve; se reparte.
 *                 base = total / factor        iva = total - base
 *
 *   SUMAR     el precio capturado ES la base y el IVA se suma encima.
 *             El total SÍ cambia.
 *                 iva = base * pct             total = base + iva
 */
final class Iva
{
    public const INCLUIDO = 'incluido';
    public const SUMAR    = 'sumar';

    private $porcentaje;
    private $modo;

    public function __construct($porcentaje = 0.0, $modo = self::INCLUIDO)
    {
        $p = (float)$porcentaje;
        if ($p < 0 || $p > 100) {
            throw new \InvalidArgumentException('El IVA debe estar entre 0 y 100');
        }
        $this->porcentaje = $p;
        $this->modo = ($modo === self::SUMAR) ? self::SUMAR : self::INCLUIDO;
    }

    /**
     * Reconstruye la regla a partir de una venta ya guardada.
     *
     * El factor se deduce de la propia venta (total / base) en vez de
     * confiar en la columna `iva`, porque hay ventas viejas con el
     * total correcto y el iva en 0. Leopoldo y Elvira eran así.
     */
    public static function desdeVenta(array $v)
    {
        $modo  = (isset($v['iva_modo']) && $v['iva_modo'] === self::SUMAR)
               ? self::SUMAR : self::INCLUIDO;
        $total = (float)($v['total'] ?? 0);
        $base  = (float)($v['subtotal'] ?? 0) - (float)($v['descuento'] ?? 0);

        if ($base <= 0 || $total <= 0) return new self(0, $modo);

        $factor = $total / $base;
        // Fuera de este rango la cabecera está descuadrada. Devolver 0 es
        // más seguro que inventar una base: así el llamador no aplica
        // ninguna corrección en vez de aplicar una equivocada.
        if ($factor <= 1.0 || $factor >= 2.0) return new self(0, $modo);

        return new self(round(($factor - 1) * 100, 2), $modo);
    }

    public function porcentaje() { return $this->porcentaje; }
    public function modo()       { return $this->modo; }
    public function lleva()      { return $this->porcentaje > 0; }

    /** Cuánto hay que dividir un precio para quitarle el impuesto. */
    public function factor()
    {
        return ($this->modo === self::INCLUIDO && $this->porcentaje > 0)
             ? 1 + ($this->porcentaje / 100)
             : 1.0;
    }

    /**
     * Quita el IVA de un importe.
     *
     * Es la operación que faltaba en guardar_comision_producto.php.
     * En modo SUMAR devuelve lo mismo que entró: ahí el precio ya es
     * la base.
     */
    public function quitar($importe)
    {
        return round((float)$importe / $this->factor(), 2);
    }

    /** Reparte un total entre base e impuesto, sin mover el total. */
    public function repartir($total)
    {
        $total = round((float)$total, 2);
        if (!$this->lleva()) return ['base' => $total, 'iva' => 0.0, 'total' => $total];

        if ($this->modo === self::SUMAR) {
            // Aquí el argumento ES la base y el total crece.
            $iva = round($total * ($this->porcentaje / 100), 2);
            return ['base' => $total, 'iva' => $iva, 'total' => round($total + $iva, 2)];
        }
        $base = round($total / $this->factor(), 2);
        return ['base' => $base, 'iva' => round($total - $base, 2), 'total' => $total];
    }
}
