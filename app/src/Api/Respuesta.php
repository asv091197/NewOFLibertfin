<?php
namespace LibertyFin\Api;

/**
 * Lo que contesta la API. Siempre JSON, siempre con la misma forma:
 *
 *   éxito  {"datos": …, "meta": {…}}      meta solo en listas
 *   error  {"error": {"codigo": "…", "mensaje": "…", "detalles": {…}}}
 *
 * Los controladores DEVUELVEN una Respuesta en vez de imprimir: así se
 * pueden probar sin servidor, y una sola pieza (Puerta) decide cómo sale.
 */
final class Respuesta
{
    public $estado;
    public $cuerpo;
    public $encabezados = [];

    public function __construct($estado, array $cuerpo)
    {
        $this->estado = (int)$estado;
        $this->cuerpo = $cuerpo;
    }

    public static function dato($datos, $estado = 200)
    {
        return new self($estado, ['datos' => $datos]);
    }

    public static function lista(array $items, array $meta)
    {
        return new self(200, ['datos' => array_values($items), 'meta' => $meta]);
    }

    public static function error($estado, $codigo, $mensaje, array $detalles = [])
    {
        $e = ['codigo' => (string)$codigo, 'mensaje' => (string)$mensaje];
        if ($detalles) $e['detalles'] = $detalles;
        return new self($estado, ['error' => $e]);
    }

    public function con($nombre, $valor)
    {
        $this->encabezados[$nombre] = (string)$valor;
        return $this;
    }

    public function json()
    {
        // ZERO_FRACTION: un importe de 100 sale como 100.0, igual que 99.5.
        // Así todos los importes son del mismo tipo para quien los lee.
        return json_encode($this->cuerpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function enviar()
    {
        if (!headers_sent()) {
            http_response_code($this->estado);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach ($this->encabezados as $k => $v) header($k . ': ' . $v);
        }
        echo $this->json();
    }
}
