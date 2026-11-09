<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PlataformaRepo;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Informes de plataforma.
 *
 * Contesta preguntas sobre el NEGOCIO de LibertyFin, no sobre el de un
 * cliente: cuántas empresas hay y cómo van, qué tanto trabaja soporte, y
 * de qué se queja la gente.
 *
 * Esa última es la que importa de verdad: un problema que aparece en
 * treinta tickets se arregla una vez en el producto, no treinta veces en
 * la bandeja.
 */
final class InformesControlador
{
    public function index()
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $plat = new PlataformaRepo($principal);
        $tk   = new TicketRepo($principal);

        $empresas = $plat->empresas();
        $conPulso = count($empresas) <= 60;

        // El retrato de la base instalada: cuántas al día, cuántas
        // atrasadas, cuántas sin usar.
        $esquemas = []; $activas = 0; $dormidas = 0; $sinResponder = 0;
        foreach ($empresas as $e) {
            if (empty($e['activo'])) continue;
            if (!$conPulso) continue;
            $pu = $plat->pulso($e['nombre_base_datos']);
            if ($pu === null) { $sinResponder++; continue; }
            $v = (int)$pu['esquema'];
            $esquemas[$v] = ($esquemas[$v] ?? 0) + 1;
            // "Dormida" = tiene usuarios pero nadie ha vendido en 30 días.
            if (empty($pu['ultima']) || strtotime($pu['ultima']) < strtotime('-30 days')) $dormidas++;
            else $activas++;
        }
        ksort($esquemas);

        Plantilla::pagina('informes/index', [
            'titulo'     => 'Informes',
            'icono'      => 'pct',
            'subtitulo'  => 'Plataforma',
            'resumen'    => $plat->resumen(),
            'cifras'     => $tk->cifras(),
            'categorias' => $tk->porCategoria(),
            'esquemas'   => $esquemas,
            'ultima'     => Migraciones::VERSION,
            'activas'    => $activas,
            'dormidas'   => $dormidas,
            'sinResponder' => $sinResponder,
            'conPulso'   => $conPulso,
            'agentes'    => $this->porAgente($tk),
        ]);
    }

    /**
     * Cuánto lleva cada agente y cómo le va.
     *
     * Se cuenta lo RESUELTO, no lo asignado: acumular tickets sin
     * cerrarlos no es trabajo hecho, y medir por asignación premia
     * justamente eso.
     */
    private function porAgente(TicketRepo $tk)
    {
        $todos = $tk->bandeja([], 500);
        $r = [];
        foreach ($todos as $t) {
            $k = $t['asignado_nombre'] ?: '(sin asignar)';
            if (!isset($r[$k])) $r[$k] = ['nombre' => $k, 'activos' => 0, 'resueltos' => 0,
                                          'min' => []];
            if (in_array($t['estado'], ['resuelto','cerrado'], true)) $r[$k]['resueltos']++;
            else $r[$k]['activos']++;
            if ($t['primera_respuesta_en']) $r[$k]['min'][] = (int)$t['min_respuesta'];
        }
        foreach ($r as &$a) {
            $a['promedio'] = $a['min'] ? array_sum($a['min']) / count($a['min']) : null;
            unset($a['min']);
        }
        unset($a);
        uasort($r, function ($x, $y) { return $y['resueltos'] - $x['resueltos']; });
        return $r;
    }
}
