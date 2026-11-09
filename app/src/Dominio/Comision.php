<?php
namespace LibertyFin\Dominio;

/**
 * La regla de comisión. UN solo lugar.
 *
 * Hoy el cálculo aparece en 14 archivos. El bueno vive en
 * includes/comisiones_devengadas.php; los demás lo repiten con
 * variaciones, y de ahí salieron los descuadres.
 *
 * Dos cifras, y confundirlas fue la mitad de los problemas de hoy:
 *
 *   ASIGNADA   lo que se pagaría si el cliente liquida todo.
 *   DEVENGADA  lo que ya se ganó, en proporción a lo cobrado.
 *
 * El gasto de operación se resta COMPLETO, no prorrateado: el primer
 * pago lo absorbe entero. Así lo hace el Excel y así lo documenta
 * reportes.php.
 */
final class Comision
{
    private $precioSinIva;   // precio de la línea, ya sin impuesto
    private $cantidad;
    private $descuento;      // descuento de la línea, sin impuesto
    private $costo;          // costo unitario, nunca trae IVA
    private $gasto;          // gasto de operación de la venta, completo

    public function __construct($precioSinIva, $cantidad = 1, $descuento = 0, $costo = 0, $gasto = 0)
    {
        $this->precioSinIva = (float)$precioSinIva;
        $this->cantidad     = (float)$cantidad;
        $this->descuento    = (float)$descuento;
        $this->costo        = (float)$costo;
        $this->gasto        = (float)$gasto;
    }

    /**
     * Arma la regla desde los datos crudos, quitando el IVA por el camino.
     * Este constructor es el que evita repetir la división en cada archivo.
     */
    public static function desdeLinea(array $venta, array $detalle, $gastoOperacion = 0)
    {
        $iva = Iva::desdeVenta($venta);
        return new self(
            $iva->quitar($detalle['precio_unitario'] ?? 0),
            $detalle['cantidad'] ?? 1,
            $iva->quitar($detalle['descuento'] ?? 0),
            $detalle['costo'] ?? 0,
            $gastoOperacion
        );
    }

    /** Valor de la línea sin impuesto ni descuento de la línea. */
    public function valorLinea()
    {
        return round($this->precioSinIva * $this->cantidad - $this->descuento, 2);
    }

    /** Base si el cliente liquida todo. */
    public function baseAsignada()
    {
        $util = $this->valorLinea() - ($this->costo * $this->cantidad);
        return max(0, round($util - $this->gasto, 2));
    }

    /**
     * Base ya ganada, según lo cobrado.
     * El gasto se resta completo, igual que en la asignada.
     */
    public function baseDevengada($cobrado)
    {
        $linea = $this->valorLinea();
        if ($linea <= 0) return 0.0;
        $prop  = min(1, max(0, (float)$cobrado / $linea));
        $util  = $linea * $prop - ($this->costo * $this->cantidad);
        return max(0, round($util - $this->gasto, 2));
    }

    public function asignada($pct)             { return round($this->baseAsignada() * ((float)$pct / 100), 2); }
    public function devengada($pct, $cobrado)  { return round($this->baseDevengada($cobrado) * ((float)$pct / 100), 2); }
    public function pendiente($pct, $cobrado)  { return round($this->asignada($pct) - $this->devengada($pct, $cobrado), 2); }
}
