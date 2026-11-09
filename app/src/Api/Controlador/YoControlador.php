<?php
namespace LibertyFin\Api\Controlador;

use LibertyFin\Api\Contexto;
use LibertyFin\Api\Formato;
use LibertyFin\Api\Puerta;
use LibertyFin\Api\Respuesta;

/**
 * GET /api/v1/yo
 *
 * Lo primero que prueba quien integra: ¿mi llave sirve, de qué empresa
 * es y qué puede hacer? Contestar eso aquí ahorra adivinarlo a base de
 * errores 403.
 */
final class YoControlador
{
    public function ver(Contexto $c)
    {
        $puede = [];
        foreach (Puerta::RUTAS as $r) {
            if ($r[3] === null || $c->puede($r[3])) $puede[] = $r[0] . ' ' . $r[1];
        }
        return Respuesta::dato([
            'empresa' => ['id' => (int)$c->empresa['id'], 'nombre' => (string)$c->empresa['nombre_empresa']],
            'llave'   => [
                'nombre'    => (string)$c->llave['nombre'],
                'prefijo'   => (string)$c->llave['prefijo'],
                'rol'       => $c->rol(),
                'creada_en' => Formato::fechaHora($c->llave['creada_en']),
            ],
            'puede'               => $puede,
            'limite_por_minuto'   => $c->porMinuto,
            'comisiones_visibles' => $c->puede('ver.comisiones'),
        ]);
    }
}
