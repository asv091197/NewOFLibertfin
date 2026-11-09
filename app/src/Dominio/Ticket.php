<?php
namespace LibertyFin\Dominio;

/**
 * El ticket antes de cobrarse.
 *
 * Reúne líneas, descuento, IVA y gastos de operación, y responde la única
 * pregunta que importa al cobrar: cuánto es el total y cuánto queda a deber.
 *
 * No toca la base. Se puede probar sin servidor.
 */
final class Ticket
{
    private $lineas = [];
    private $iva;
    private $gastos = 0.0;

    public function __construct(Iva $iva = null) { $this->iva = $iva ?: new Iva(0); }

    public function agregar($productoId, $nombre, $precio, $cantidad = 1, $descuento = 0, $costo = 0)
    {
        $this->lineas[] = [
            'producto_id' => (int)$productoId,
            'nombre'      => (string)$nombre,
            'precio'      => Dinero::centavos($precio),
            'cantidad'    => (float)$cantidad,
            'descuento'   => Dinero::centavos($descuento),
            'costo'       => Dinero::centavos($costo),
        ];
        return $this;
    }

    public function gastosOperacion($monto) { $this->gastos = Dinero::centavos($monto); return $this; }
    public function lineas() { return $this->lineas; }
    public function vacio()  { return count($this->lineas) === 0; }

    /** Suma de las líneas tal como se capturaron. */
    public function subtotalCapturado()
    {
        $t = 0.0;
        foreach ($this->lineas as $l) $t += $l['precio'] * $l['cantidad'] - $l['descuento'];
        return Dinero::centavos($t);
    }

    /**
     * El cierre del ticket.
     *
     * En modo INCLUIDO el total es lo capturado y el IVA se extrae de ahí.
     * En modo SUMAR lo capturado es la base y el total crece.
     */
    public function totales()
    {
        $r = $this->iva->repartir($this->subtotalCapturado());
        return [
            'subtotal'  => $r['base'],
            'iva'       => $r['iva'],
            'total'     => $r['total'],
            'gastos'    => $this->gastos,
            'iva_modo'  => $this->iva->modo(),
            'iva_pct'   => $this->iva->porcentaje(),
        ];
    }

    /**
     * Valida el anticipo contra el total.
     * Cobrar de más fue justo lo que dejó a Izol Nieto con un pago mayor
     * a su venta; aquí no puede pasar.
     */
    public function validarAnticipo($anticipo)
    {
        $t = $this->totales()['total'];
        $a = Dinero::centavos($anticipo);
        if ($a < 0)  throw new \InvalidArgumentException('El anticipo no puede ser negativo');
        if ($a > $t) throw new \InvalidArgumentException(
            'El anticipo (' . Dinero::pesos($a) . ') no puede ser mayor al total (' . Dinero::pesos($t) . ')');
        return $a;
    }

    public function saldo($anticipo)
    {
        return Dinero::centavos($this->totales()['total'] - Dinero::centavos($anticipo));
    }

    /** Tipo de pago según cuánto cubre: sirve para etiquetar el movimiento. */
    public function tipoPago($anticipo)
    {
        return $this->saldo($anticipo) <= 0.01 ? 'liquidacion' : 'anticipo';
    }
}
