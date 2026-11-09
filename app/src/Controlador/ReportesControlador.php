<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ReporteRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ReportesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ReporteRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $tipo  = Peticion::opcion('tipo',
                    array_keys(\LibertyFin\Servicio\Reportes::TIPOS), 'area');

        Plantilla::pagina('reportes/index', [
            'titulo'       => 'Reportes',
            'icono'        => 'pct',
            'subtitulo'    => Fechas::rotulo($desde, $hasta),
            'r'            => $repo->resultado($desde, $hasta),
            'meses'        => $repo->porMes(6),
            'areas'        => $repo->porArea($desde, $hasta),
            'servicios'    => $repo->porServicio($desde, $hasta, 10),
            'metodos'      => $repo->porMetodo($desde, $hasta),
            // Los ocho reportes: la lista para las pestañas y el que se
            // está viendo. Salen del mismo sitio que el Excel y la hoja
            // imprimible, para que no puedan decir cosas distintas.
            'tipos'        => \LibertyFin\Servicio\Reportes::TIPOS,
            'tipo'         => $tipo,
            // LOS OCHO DE UNA VEZ, para cambiar de pestaña sin recargar.
            //
            // Son ocho consultas de agregado contra el mismo periodo que
            // ya se está consultando para las cifras de arriba. A cambio,
            // comparar "por área" con "por colaborador" deja de costar
            // dos viajes al servidor y la pérdida del lugar en la página.
            'reportes'     => (new \LibertyFin\Servicio\Reportes($db))->todos($desde, $hasta),
            'desglose'     => (new \LibertyFin\Servicio\Reportes($db))->desglose(
                                $desde, $hasta, Peticion::opcion('por', ['servicio','origen'], 'servicio')),
            'por'          => Peticion::opcion('por', ['servicio','origen'], 'servicio'),
            // El porque de cada comision, una tabla por colaborador.
            'comisiones'   => (new \LibertyFin\Servicio\Reportes($db))
                                ->desgloseComisiones($desde, $hasta),
            'desde'        => $desde, 'hasta' => $hasta,
        ]);
    }

    /**
     * Descarga el periodo en Excel, un reporte por hoja.
     *
     * Reemplaza al CSV. Un CSV manda "$1,234.00" como texto: Excel no lo
     * suma, no lo ordena y no lo grafica, y quien lo recibe termina
     * reescribiéndolo a mano. Aquí los montos son números con formato de
     * moneda, las fechas son fechas, y la fila de encabezados queda
     * congelada.
     */
    public function excel()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $libro = (new \LibertyFin\Servicio\Reportes($db))->libroCompleto($desde, $hasta);
        $tmp = tempnam(sys_get_temp_dir(), 'lf') . '.xlsx';
        $libro->guardar($tmp);

        $nombre = 'libertyfin-reportes-' . date('Ymd', strtotime($desde))
                . '-' . date('Ymd', strtotime($hasta)) . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * La hoja imprimible, que el navegador convierte en PDF.
     *
     * POR QUÉ NO SE ESCRIBE UN PDF
     *
     * Generar PDF en PHP sin librerías significa dibujar texto a mano,
     * sin acentos decentes ni control de saltos de página. Con una hoja
     * HTML y `@media print`, el navegador lo convierte en un PDF que se
     * ve bien, respeta los acentos, repite el encabezado en cada página y
     * numera. Y se imprime directo, que es lo que casi siempre quieren.
     */
    /**
     * La hoja imprimible, que el navegador convierte en PDF.
     *
     * POR QUE NO SE ESCRIBE UN PDF
     *
     * Generar PDF en PHP sin librerias significa dibujar texto a mano,
     * sin acentos decentes ni control de saltos de pagina. Con una hoja
     * HTML y `@media print`, el navegador lo convierte en un PDF que se
     * ve bien, respeta los acentos, repite el encabezado en cada pagina
     * y numera. Y se imprime directo, que es lo que casi siempre
     * quieren.
     *
     * QUE SE IMPRIME
     *
     *   tipo=X            un reporte
     *   todos=1           los ocho
     *   tipos=a,b,c       solo esos
     *   tipo=desglose     todas las tablas del desglose
     *     + areas=a|b     solo esas areas
     *
     * El filtro existe porque imprimir todo para leer una tabla gasta
     * papel y esconde lo que se buscaba. Quien imprime casi siempre
     * quiere una cosa concreta.
     */
    public function imprimir()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $srv   = new \LibertyFin\Servicio\Reportes($db);
        $todosLosTipos = array_keys(\LibertyFin\Servicio\Reportes::TIPOS);

        $tipo = Peticion::opcion('tipo', $todosLosTipos, 'area');
        $reps = [];
        $filtradas = null;

        $por = Peticion::opcion('por', ['servicio', 'origen'], 'servicio');

        // El desglose se imprime como VARIOS reportes, uno por area, no
        // como una tabla gigante con renglones de titulo en medio. Asi
        // cada area empieza en su hoja, con su encabezado repetido, y se
        // puede arrancar la de Legal y mandarla sola.
        $armarDesglose = function () use ($srv, $desde, $hasta, $por) {
            $d = $srv->desglose($desde, $hasta, $por);
            $r = [];
            foreach ($d['tablas'] as $t) {
                $r[] = [
                    'tipo'     => 'desglose',
                    'titulo'   => $t['titulo'],
                    'nota'     => 'Agrupado por el area ' . $d['rotulo'] . '.',
                    'periodo'  => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
                    'columnas' => $t['columnas'],
                    'filas'    => $t['filas'],
                    'totales'  => $t['totales'],
                ];
            }
            return $r;
        };

        $detalleComisiones = function () use ($srv, $desde, $hasta) {
            $r = [];
            foreach ($srv->desgloseComisiones($desde, $hasta)['tablas'] as $t) {
                $r[] = [
                    'tipo' => 'comisiones', 'titulo' => 'Comisiones de ' . $t['titulo'],
                    'nota' => 'Cada renglón es un pago. La comisión sale del porcentaje '
                            . 'aplicado sobre lo cobrado en ese pago, no sobre la venta completa.',
                    'periodo' => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
                    'columnas' => $t['columnas'], 'filas' => $t['filas'], 'totales' => $t['totales'],
                ];
            }
            return $r;
        };

        if ($tipo === 'desglose') {
            $d = $srv->desglose($desde, $hasta, $por);

            // `areas` llega como lista separada por |. Se comparan contra
            // los titulos reales: un titulo que ya no existe —porque
            // cambio el periodo— simplemente no aparece, en vez de dejar
            // la hoja en blanco sin explicar por que.
            $pedidas = array_filter(array_map('trim',
                explode('|', Peticion::texto('areas', ''))));
            $filtradas = $pedidas ? count($pedidas) : null;

            foreach ($d['tablas'] as $t) {
                if ($pedidas && !in_array($t['titulo'], $pedidas, true)) continue;
                $reps[] = [
                    'tipo'     => 'desglose',
                    'titulo'   => $t['titulo'],
                    'nota'     => 'Agrupado por el área ' . $d['rotulo'] . '.',
                    'periodo'  => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
                    'columnas' => $t['columnas'],
                    'filas'    => $t['filas'],
                    'totales'  => $t['totales'],
                ];
            }
        } elseif ($tipo === 'colaborador' && Peticion::texto('detalle', '') === '1') {
            // El resumen y, debajo, una tabla por colaborador con el
            // porque de cada comision.
            $reps[] = $srv->armar('colaborador', $desde, $hasta);
            foreach ($srv->desgloseComisiones($desde, $hasta)['tablas'] as $t) {
                $reps[] = [
                    'tipo' => 'comisiones', 'titulo' => 'Comisiones de ' . $t['titulo'],
                    'nota' => 'Cada renglón es un pago. La comisión sale del porcentaje '
                            . 'aplicado sobre lo cobrado en ese pago, no sobre la venta completa.',
                    'periodo' => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
                    'columnas' => $t['columnas'], 'filas' => $t['filas'], 'totales' => $t['totales'],
                ];
            }
        } elseif (Peticion::texto('todos', '') === '1' || Peticion::texto('tipos', '') !== '') {
            $pedidos = Peticion::texto('tipos', '') !== ''
                     ? array_values(array_intersect(
                         array_map('trim', explode(',', Peticion::texto('tipos', ''))),
                         $todosLosTipos))
                     : $todosLosTipos;
            foreach ($pedidos as $t) {
                // Al imprimir todo se incluye el desglose, en tablas
                // separadas. Saltarselo dejaba fuera justo el reporte
                // que mas se usa.
                if ($t === 'desglose') {
                    foreach ($armarDesglose() as $x) $reps[] = $x;
                    continue;
                }
                $reps[] = $srv->armar($t, $desde, $hasta);
                // "Incluir el detalle" del colaborador: debajo del resumen,
                // una tabla por colaborador con el porque de cada comision.
                if ($t === 'colaborador' && Peticion::texto('detalle', '') === '1') {
                    foreach ($detalleComisiones() as $x) $reps[] = $x;
                }
            }
        } else {
            $reps[] = $srv->armar($tipo, $desde, $hasta);
        }

        // `ocultar[clave]=Col|Col` llega por elemento: la clave es el tipo
        // de reporte, o el titulo del area en el desglose. Lo que se oculta
        // en uno no toca a los demas. Un elemento sin entrada sale completo.
        $ocultar = isset($_GET['ocultar']) && is_array($_GET['ocultar']) ? $_GET['ocultar'] : [];
        if ($ocultar) {
            foreach ($reps as &$rep) {
                $clave = $rep['tipo'] === 'desglose' ? $rep['titulo'] : $rep['tipo'];
                // Desde "Qué imprimir" la clave 'desglose' vale para todas
                // las areas; una clave por area, si existe, manda.
                $lista = $ocultar[$clave] ?? ($rep['tipo'] === 'desglose' ? ($ocultar['desglose'] ?? '') : '');
                $fuera = array_filter(array_map('trim', explode('|', (string)$lista)));
                if (!$fuera) continue;
                $quedan = [];
                foreach ($rep['columnas'] as $j => $c) {
                    if (!in_array($c[0], $fuera, true)) $quedan[] = $j;
                }
                if (!$quedan) continue;
                $rep['columnas'] = array_values(array_intersect_key($rep['columnas'], array_flip($quedan)));
                $rep['filas'] = array_map(function ($f) use ($quedan) {
                    $n = [];
                    foreach ($quedan as $j) $n[] = $f[$j] ?? null;
                    // Las claves de texto ('_origen', '_venta') no son
                    // columnas: se conservan, para que la hoja siga
                    // resaltando los cobros de ventas de otro periodo
                    // aunque se haya ocultado la columna "Origen".
                    foreach ($f as $k => $v) if (is_string($k)) $n[$k] = $v;
                    return $n;
                }, $rep['filas']);
                $tot = [];
                foreach ($quedan as $j) $tot[] = $rep['totales'][$j] ?? '';
                $rep['totales'] = $tot;
            }
            unset($rep);
        }

        Plantilla::pagina('reportes/imprimir', [
            'titulo'    => 'Reportes',
            'reportes'  => $reps,
            'periodo'   => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'empresa'   => $_SESSION['empresa_nombre'] ?? 'LibertyFin',
            'auto'      => Peticion::texto('auto', '') === '1',
            'filtradas' => $filtradas,
        ], 'layout-limpio');
    }
}
