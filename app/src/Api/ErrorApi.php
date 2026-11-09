<?php
namespace LibertyFin\Api;

/**
 * Un error que la API sabe explicar.
 *
 * Lleva el código HTTP, un código corto y estable para que el programa
 * del otro lado decida qué hacer (`saldo_insuficiente`, `no_autorizado`)
 * y un mensaje en español para la persona que lo lea en un registro.
 *
 * Cualquier otra excepción se contesta como 500 sin detalles: lo que
 * pasó adentro se queda en el log del servidor, no viaja al cliente.
 */
final class ErrorApi extends \RuntimeException
{
    private $estado;
    private $codigo;
    private $detalles;

    public function __construct($estado, $codigo, $mensaje, array $detalles = [])
    {
        parent::__construct($mensaje);
        $this->estado = (int)$estado;
        $this->codigo = (string)$codigo;
        $this->detalles = $detalles;
    }

    public function estado()   { return $this->estado; }
    public function codigo()   { return $this->codigo; }
    public function detalles() { return $this->detalles; }

    public function respuesta()
    {
        return Respuesta::error($this->estado, $this->codigo, $this->getMessage(), $this->detalles);
    }
}
