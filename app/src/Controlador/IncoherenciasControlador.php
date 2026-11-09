<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Incoherencias;
use LibertyFin\Vista\Plantilla;

/**
 * Incoherencias: lo que está capturado y no cuadra.
 *
 * Es de solo lectura, igual que la bitácora. Señala; no corrige.
 */
final class IncoherenciasControlador
{
    const POR_PAGINA = 40;

    /**
     * /incoherencias ahora es una pestaña de Auditoría (/auditoria). Se
     * conserva la dirección vieja, con sus filtros, para no romper enlaces
     * guardados.
     */
    public function aAuditoria()
    {
        $q = (string)($_SERVER['QUERY_STRING'] ?? '');
        header('Location: /auditoria' . ($q !== '' ? '?' . $q : ''), true, 301);
        exit;
    }

    public function index()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        $filtros = [
            'desde'     => Peticion::fecha('desde', date('Y-m-d', strtotime('-90 days'))),
            'hasta'     => Peticion::fecha('hasta', date('Y-m-d')),
            'regla'     => Peticion::opcion('regla', array_keys(Incoherencias::REGLAS), ''),
            'severidad' => Peticion::opcion('severidad', array_keys(Incoherencias::SEVERIDADES), ''),
            'sucursal'  => Peticion::entero('sucursal'),
            'usuario'   => Peticion::entero('usuario'),
            'q'         => Peticion::texto('q', ''),
        ];

        // Se buscan TODAS las reglas (con el resto de los filtros) para poder
        // mostrar cuántos hallazgos tiene cada una, y luego se recorta a la
        // regla elegida. Así el resumen no se queda en cero al filtrar.
        $todo = (new Incoherencias($db))->buscar(array_merge($filtros, ['regla' => '']));
        $lista = $filtros['regla'] === ''
            ? $todo['hallazgos']
            : array_values(array_filter($todo['hallazgos'], function ($h) use ($filtros) {
                return $h['regla'] === $filtros['regla'];
            }));

        $repo      = new UsuarioRepo($db);
        $usuarios  = $repo->todos_();
        $sucursales = $repo->sucursales();
        $nombreU = []; foreach ($usuarios as $u)    $nombreU[(int)$u['id']] = $u['nombre'];
        $nombreS = []; foreach ($sucursales as $s)  $nombreS[(int)$s['id']] = $s['nombre'];
        foreach ($lista as &$h) {
            $h['usuario']  = $nombreU[$h['usuario_id']]  ?? '';
            $h['sucursal'] = $nombreS[$h['sucursal_id']] ?? '';
        }
        unset($h);

        if (Peticion::texto('formato') === 'csv') {
            $this->csv($lista);
            return;
        }

        $total  = count($lista);
        $pagina = max(1, Peticion::entero('p', 1));
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina = min($pagina, $paginas);

        $porSeveridad = ['alta' => 0, 'media' => 0, 'baja' => 0];
        foreach ($todo['hallazgos'] as $h) $porSeveridad[$h['severidad']]++;

        Plantilla::pagina('incoherencias/index', [
            'titulo'     => 'Auditoría',
            'icono'      => 'alerta',
            'subtitulo'  => 'Incoherencias · ' . ($total === 0 ? 'nada que revisar' : number_format($total) . ' por revisar'),
            'filas'      => array_slice($lista, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
            'resumen'    => $todo['resumen'],
            'truncadas'  => $todo['truncadas'],
            'porSeveridad' => $porSeveridad,
            'usuarios'   => $usuarios,
            'sucursales' => $sucursales,
            'filtros'    => $filtros,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => $paginas,
        ]);
    }

    /** Lo mismo que se ve en pantalla, para abrirlo en Excel. */
    private function csv(array $filas)
    {
        $nombre = 'incoherencias-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");   // para que Excel respete los acentos
        fputcsv($f, ['Severidad', 'Regla', 'Venta', 'Fecha', 'Hay', 'Debería haber',
                     'Detalle', 'Capturó', 'Sucursal']);
        foreach ($filas as $h) {
            fputcsv($f, [
                Incoherencias::SEVERIDADES[$h['severidad']],
                $h['titulo'], self::sinFormula($h['codigo']), substr((string)$h['fecha'], 0, 10),
                $h['actual'] === null ? '' : number_format($h['actual'], 4, '.', ''),
                $h['esperado'] === null ? '' : number_format($h['esperado'], 4, '.', ''),
                self::sinFormula($h['nota']), self::sinFormula($h['usuario']), self::sinFormula($h['sucursal']),
            ]);
        }
        fclose($f);
        exit;
    }

    /**
     * Excel ejecuta como fórmula lo que empieza con = + - @. Un nombre de
     * colaborador o de producto capturado a mano no debe poder hacerlo.
     */
    private static function sinFormula($t)
    {
        $t = (string)$t;
        return ($t !== '' && strpos("=+-@\t\r", $t[0]) !== false) ? "'" . $t : $t;
    }
}
