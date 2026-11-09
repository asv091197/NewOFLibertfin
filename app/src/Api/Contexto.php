<?php
namespace LibertyFin\Api;

use LibertyFin\Dominio\Permisos;
use PDO;

/**
 * Quién llama y sobre qué empresa. Lo arma la Puerta y lo recibe cada
 * controlador de la API: con esto no necesitan la sesión.
 */
final class Contexto
{
    /** @var PDO la base de la empresa */
    public $db;
    /** @var array la fila de lf_api_llaves */
    public $llave;
    /** @var array id, nombre_empresa, nombre_base_datos… */
    public $empresa;
    /** @var Entrada */
    public $entrada;
    /** @var int peticiones por minuto permitidas */
    public $porMinuto;

    public function __construct(PDO $db, array $llave, array $empresa, Entrada $entrada, $porMinuto)
    {
        $this->db = $db;
        $this->llave = $llave;
        $this->empresa = $empresa;
        $this->entrada = $entrada;
        $this->porMinuto = (int)$porMinuto;
    }

    public function rol() { return (string)$this->llave['rol']; }

    public function puede($permiso) { return Permisos::puede($permiso, $this->rol()); }

    /** Los datos de paginación de una lista. */
    public function paginacion($total)
    {
        $por = $this->entrada->porPagina();
        return ['pagina' => $this->entrada->pagina(), 'por_pagina' => $por,
                'total' => (int)$total, 'paginas' => max(1, (int)ceil($total / $por))];
    }

    public function desfase()
    {
        return ($this->entrada->pagina() - 1) * $this->entrada->porPagina();
    }
}
