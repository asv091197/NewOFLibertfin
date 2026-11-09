<?php
namespace LibertyFin\Api;

/**
 * Lo que trae la petición: parámetros de la URL, cuerpo JSON y cabeceras.
 *
 * A diferencia de Http\Peticion, que en las pantallas rellena con el valor
 * por omisión cuando algo viene mal, aquí un valor inválido es un 422 que
 * dice qué parámetro falló. Un programa que manda `pagina=abc` necesita
 * saberlo; en una pantalla bastaba con mostrar la primera página.
 */
final class Entrada
{
    const POR_PAGINA = 25;
    const POR_PAGINA_MAX = 100;

    private $query;
    private $crudo;
    private $encabezados;
    private $json = null;

    public function __construct(array $query, $crudo = '', array $encabezados = [])
    {
        $this->query = $query;
        $this->crudo = (string)$crudo;
        $this->encabezados = array_change_key_case($encabezados, CASE_LOWER);
    }

    public static function deLaPeticion()
    {
        $enc = [];
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $enc[str_replace('_', '-', strtolower(substr($k, 5)))] = $v;
            }
        }
        // Apache con PHP por CGI/FastCGI no pasa «Authorization»; la regla
        // de public/.htaccess la copia y a veces llega con este nombre.
        if (empty($enc['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $enc['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return new self($_GET, (string)file_get_contents('php://input'), $enc);
    }

    public function encabezado($nombre)
    {
        $v = $this->encabezados[strtolower($nombre)] ?? '';
        return trim((string)$v);
    }

    public function texto($clave, $max = 100)
    {
        $v = trim((string)($this->query[$clave] ?? ''));
        if (mb_strlen($v) > $max) {
            throw $this->invalido($clave, "no puede pasar de $max caracteres");
        }
        return $v;
    }

    public function pagina()
    {
        return $this->entero('pagina', 1, 1, PHP_INT_MAX);
    }

    public function porPagina()
    {
        return $this->entero('por_pagina', self::POR_PAGINA, 1, self::POR_PAGINA_MAX);
    }

    /** Fecha Y-m-d o cadena vacía. */
    public function fecha($clave)
    {
        $v = $this->texto($clave, 10);
        if ($v === '') return '';
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw $this->invalido($clave, 'debe ser una fecha AAAA-MM-DD');
        }
        return $v;
    }

    /** Un valor de una lista cerrada. */
    public function opcion($clave, array $validas, $porDefecto = '')
    {
        $v = $this->texto($clave, 40);
        if ($v === '') return $porDefecto;
        if (!in_array($v, $validas, true)) {
            throw $this->invalido($clave, 'debe ser uno de: ' . implode(', ', $validas));
        }
        return $v;
    }

    /** El cuerpo JSON como arreglo. Un cuerpo vacío es un objeto vacío. */
    public function cuerpo()
    {
        if ($this->json !== null) return $this->json;
        if (trim($this->crudo) === '') return $this->json = [];
        $d = json_decode($this->crudo, true);
        // Un objeto, no una lista ni un valor suelto. (Sin array_is_list:
        // es de PHP 8.1 y el servidor puede tener 8.0.)
        $esLista = is_array($d) && $d !== [] && array_keys($d) === range(0, count($d) - 1);
        if (!is_array($d) || $esLista) {
            throw new ErrorApi(400, 'json_invalido', 'El cuerpo debe ser un objeto JSON válido.');
        }
        return $this->json = $d;
    }

    /** Lo que identifica al cuerpo, para reconocer un reenvío idéntico. */
    public function huella()
    {
        return hash('sha256', json_encode($this->cuerpo()));
    }

    private function entero($clave, $porDefecto, $min, $max)
    {
        if (!isset($this->query[$clave]) || $this->query[$clave] === '') return $porDefecto;
        $v = (string)$this->query[$clave];
        if (!ctype_digit($v) || (int)$v < $min || (int)$v > $max) {
            throw $this->invalido($clave, $max === PHP_INT_MAX
                ? "debe ser un número entero desde $min"
                : "debe ser un número entero de $min a $max");
        }
        return (int)$v;
    }

    private function invalido($clave, $porque)
    {
        return new ErrorApi(422, 'parametro_invalido', "El parámetro «{$clave}» {$porque}.",
            ['parametro' => $clave]);
    }
}
