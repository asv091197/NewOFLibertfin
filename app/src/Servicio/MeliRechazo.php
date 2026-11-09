<?php
namespace LibertyFin\Servicio;

/**
 * Mercado Libre rechazó la publicación. Lleva las fotos que YA se
 * subieron, para que al corregir el formulario no se suban otra vez.
 */
final class MeliRechazo extends \InvalidArgumentException
{
    private $fotos;

    public function __construct($mensaje, array $fotos = [])
    {
        parent::__construct((string)$mensaje);
        $this->fotos = $fotos;
    }

    public function fotos() { return $this->fotos; }
}
