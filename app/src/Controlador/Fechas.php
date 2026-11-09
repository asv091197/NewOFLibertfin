<?php
namespace LibertyFin\Controlador;

/** Rótulo del periodo en palabras. Estaba copiado en cada controlador. */
final class Fechas
{
    /**
     * ¿Se está buscando sin acotar al periodo?
     *
     * Quien escribe un nombre quiere ver TODO lo de esa persona, no solo lo
     * del mes que casualmente trae el filtro: con el mes por defecto,
     * buscar a un cliente que compró hace tres meses devolvía "nada" y
     * parecía que no existía. Para acotar se marca "Solo este periodo"
     * (`periodo=1`).
     */
    public static function buscandoTodo($buscar)
    {
        return trim((string)$buscar) !== ''
            && \LibertyFin\Http\Peticion::texto('periodo') !== '1';
    }

    /** El rango a consultar: todo el historial al buscar, si no el elegido. */
    public static function rango($buscar, $desde, $hasta)
    {
        return self::buscandoTodo($buscar) ? ['1970-01-01', '2099-12-31'] : [$desde, $hasta];
    }

    public static function rotulo($desde, $hasta)
    {
        $m = ['','enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
        $a = strtotime($desde); $b = strtotime($hasta);
        if (date('Y-m', $a) === date('Y-m', $b)) {
            return ucfirst($m[(int)date('n', $a)]) . ' ' . date('Y', $a);
        }
        return ucfirst($m[(int)date('n', $a)]) . ' — ' . $m[(int)date('n', $b)] . ' ' . date('Y', $b);
    }
}
