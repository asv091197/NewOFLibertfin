<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ReporteRepo;
use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Los reportes que se pueden sacar, y cómo se arman.
 *
 * UN SOLO LUGAR DEFINE CADA REPORTE
 *
 * El mismo arreglo alimenta la tabla en pantalla, el Excel y la hoja
 * imprimible. Si cada salida armara lo suyo, en dos meses el PDF diría
 * una cosa y el Excel otra, y nadie sabría cuál creer.
 */
final class Reportes
{
    const TIPOS = [
        'area' => [
            'rotulo' => 'Por área',
            'nota'   => 'El resultado de cada área en el periodo: todo lo que entró, sus gastos, '
                      . 'las comisiones que generó y el IVA que venía en lo cobrado (no es de la empresa). Suma lo mismo que «Queda» (sin los gastos '
                      . 'generales, que no son de ningún área). «Cobrado» junta lo de las ventas '
                      . 'nuevas y lo de ventas anteriores (anticipos, abonos y liquidaciones que '
                      . 'llegaron en el periodo). El área sale '
                      . 'del producto contratado, no del cliente; una venta con productos de dos '
                      . 'áreas reparte su dinero entre las dos. Cada cobro, y si fue abono o '
                      . 'liquidación, está en «Detalle de pagos».',
        ],
        'colaborador' => [
            'rotulo' => 'Por colaborador',
            // Va por la fecha del PAGO que generó cada comisión, igual que
            // "Entraron": un abono de este mes a una venta de antes, con su
            // comisión, cuenta aquí.
            'nota'   => 'Comisiones generadas por los cobros del periodo, por colaborador, '
                      . 'aunque la venta sea de un mes anterior.',
        ],
        'servicio' => [
            'rotulo' => 'Por producto',
            'nota'   => 'Qué se vende más y qué deja más. No son lo mismo. «De ventas anteriores» '
                      . 'es lo que entró en el periodo por abonos y liquidaciones de ventas de antes, '
                      . 'repartido por producto; los productos que solo tuvieron eso salen resaltados.',
        ],
        'cliente' => [
            'rotulo' => 'Por cliente',
            'nota'   => 'Lo que cada cliente compró en el periodo, lo que ya pagó de eso y lo que '
                      . 'debe. «De ventas anteriores» es lo que pagó en el periodo de compras de '
                      . 'antes; quien solo abonó a una venta vieja sale resaltado. Cada cobro, y si '
                      . 'fue abono o liquidación, está en «Detalle de pagos».',
        ],
        'cobranza' => [
            'rotulo' => 'Cobranza',
            'nota'   => 'Lo que falta por cobrar, de lo más viejo a lo más nuevo. Al final, marcadas, '
                      . 'las ventas de antes que abonaron en el periodo y todavía deben.',
        ],
        'metodo' => [
            'rotulo' => 'Por forma de pago',
            'nota'   => 'Cómo entra el dinero. Sirve para cuadrar contra el banco.',
        ],
        'dia' => [
            'rotulo' => 'Día por día',
            'nota'   => 'Lo cobrado cada día del periodo.',
        ],
        // "Detalle de pagos" se llamaba al que hoy es "Detalle de ventas", y
        // no cumplía lo que decía: era una VENTA por renglón, filtrada por la
        // fecha de la venta, así que el abono que entraba este mes sobre una
        // venta del mes pasado no salía en ninguna tabla. Ahora el nombre es
        // de quien de verdad lista los cobros.
        'pagos' => [
            'rotulo' => 'Detalle de pagos',
            'nota'   => 'Cada cobro que entró en el periodo, uno por renglón, aunque la venta '
                      . 'sea de un mes anterior. Suma lo mismo que «Entraron». Es el que se audita.',
        ],
        'detalle' => [
            'rotulo' => 'Detalle de ventas',
            'nota'   => 'Una venta por renglón: las hechas en el periodo y, al final y marcadas, las de '
                      . 'antes que tuvieron anticipos, abonos o liquidaciones en él. «Cobrado en el '
                      . 'periodo» es lo que entró de cada una: sumado da lo mismo que «Entraron». '
                      . 'Gastos, comisión, IVA cobrado y «Queda» también son del periodo (el gasto cuenta en el mes '
                      . 'de la venta; la comisión, en el del cobro que la generó): el total da lo mismo '
                      . 'que el resultado de arriba. Cada cobro, uno por uno, está en «Detalle de pagos».',
        ],
        'desglose' => [
            'rotulo' => 'Desglose por área',
            'nota'   => 'Una tabla por área, con el producto y el especialista de cada '
                      . 'renglón. Contabilidad se parte en personas físicas y morales. Cada área '
                      . 'trae también, marcadas, las ventas de antes con cobros en el periodo. '
                      . 'Gastos, comisión, IVA cobrado y «Queda» son del periodo, igual que en el Detalle de ventas.',
        ],
    ];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Arma un reporte.
     * @return array  ['titulo','nota','columnas','filas','totales']
     */
    public function armar($tipo, $desde, $hasta)
    {
        $r = new ReporteRepo($this->db);
        $L = Libro::class;

        switch ($tipo) {
            case 'area':
                // EL RESULTADO DE CADA ÁREA EN EL PERIODO, con el criterio
                // del resumen: lo que entró (de ventas nuevas y de ventas
                // anteriores), sus gastos y TODAS las comisiones que generó.
                // Antes "Utilidad" era solo de las ventas nuevas y lo de
                // ventas anteriores iba aparte sin restarse: el área se veía
                // mejor de lo que fue y las filas no sumaban "Queda".
                // Son columnas y no subrenglones: así el Excel se puede
                // sumar, filtrar y ordenar.
                //
                // SIN "Cobros anteriores" NI "Liquidaciones". Eran dos conteos
                // lado a lado ("2" y "1") que se leían como tres cosas, cuando
                // la liquidación es UNO de esos cobros. Lo que importa aquí es
                // el dinero; cada cobro, con su estado, está en el Detalle de
                // pagos.
                $f   = $r->porArea($desde, $hasta);
                $res = $r->resultado($desde, $hasta);
                $margen = function ($queda, $cobrado) {
                    return $cobrado > 0 ? round($queda / $cobrado, 4) : '';
                };
                return $this->envolver($tipo, $desde, $hasta,
                    [['Área', Libro::TEXTO, 30], ['Ventas nuevas', Libro::NUMERO, 10],
                     ['Vendido', Libro::MONEDA, 15],
                     ['Cobrado', Libro::MONEDA, 15],
                     ['De ventas nuevas', Libro::MONEDA, 15],
                     ['De ventas anteriores', Libro::MONEDA, 15],
                     ['Gastos', Libro::MONEDA, 14], ['Comisiones', Libro::MONEDA, 14],
                     ['IVA cobrado', Libro::MONEDA, 13],
                     ['Queda', Libro::MONEDA, 15], ['Margen', Libro::PORCENT, 10]],
                    array_map(function ($x) use ($margen) {
                        $mAnt = (float)($x['de_anteriores'] ?? 0);
                        return [$x['area'],
                                (int)$x['ventas'] ?: '',
                                (int)$x['ventas'] ? $x['vendido'] : '',
                                $x['cobrado_total'],
                                (float)$x['cobrado'] ?: '',
                                $mAnt > 0 ? $mAnt : '',
                                $x['gastos'], $x['comisiones_total'],
                                (float)$x['iva'] ?: '', $x['queda'],
                                $margen($x['queda'], $x['cobrado_total'])];
                    }, $f),
                    // EL TOTAL SALE DEL RESUMEN, no de sumar la columna: un
                    // cobro de una venta con dos áreas cuenta en las dos filas
                    // y cada área redondea su parte, así que la suma podía
                    // diferir en un cobro o un centavo. Los gastos generales
                    // (renta, nómina) no son de ningún área: no van aquí.
                    $this->sumar($f, ['ventas'],
                        function ($t) use ($res, $margen) {
                            $queda = round($res['cobrado'] - $res['operacion'] - $res['comisiones']
                                         - $res['iva_cobrado'], 2);
                            return ['TOTAL', (int)$t['ventas'], $res['vendido'],
                                $res['cobrado'], round($res['cobrado'] - $res['de_anteriores'], 2),
                                $res['de_anteriores'],
                                $res['operacion'], $res['comisiones'], $res['iva_cobrado'], $queda,
                                $margen($queda, $res['cobrado'])]; }));

            case 'colaborador':
                $f = $r->porColaborador($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Colaborador', Libro::TEXTO, 28], ['Área', Libro::TEXTO, 24],
                     ['Pagos', Libro::NUMERO, 10], ['Comisión', Libro::MONEDA, 15]],
                    array_map(function ($x) {
                        return [$x['nombre'] ?: 'POR ASIGNAR', $x['area'],
                                (int)$x['ventas'], $x['devengado']];
                    }, $f),
                    $this->sumar($f, ['ventas','devengado'],
                        function ($t) { return ['TOTAL', '', (int)$t['ventas'], $t['devengado']]; }));

            case 'servicio':
                // Lo vendido en el periodo y, aparte, lo que entró por ventas
                // anteriores de cada producto. Un producto que en el periodo
                // solo recibió abonos de ventas viejas también sale, marcado.
                $f   = $r->porServicio($desde, $hasta);
                $ant = $r->servicioAnteriores($desde, $hasta);
                $filas = [];
                foreach ($f as $x) {
                    $a = $ant['filas'][(int)$x['producto_id']] ?? null;
                    unset($ant['filas'][(int)$x['producto_id']]);
                    // Sin el conteo de "Cobros anteriores": un cobro de una
                    // venta con dos productos contaba en los dos, y la suma de
                    // la columna no daba los cobros que hubo.
                    $filas[] = [$x['nombre'], (int)$x['veces'], $x['facturado'],
                                $a ? (float)$a['monto'] : ''];
                }
                foreach ($ant['filas'] as $a) {
                    $filas[] = [$a['nombre'], '', '', (float)$a['monto'],
                                '_origen' => 'anterior'];
                }
                return $this->envolver($tipo, $desde, $hasta,
                    [['Producto', Libro::TEXTO, 44], ['Veces', Libro::NUMERO, 10],
                     ['Vendido', Libro::MONEDA, 16],
                     ['De ventas anteriores', Libro::MONEDA, 16]],
                    $filas,
                    // El TOTAL sale del resumen, no de sumar la columna: cada
                    // producto redondea su parte de la venta y la suma podía
                    // diferir en un centavo de lo vendido.
                    $this->sumar($f, ['veces'],
                        function ($t) use ($r, $desde, $hasta) {
                            $res = $r->resultado($desde, $hasta);
                            return ['TOTAL', (int)$t['veces'], $res['vendido'],
                                $res['de_anteriores']]; }));

            case 'cliente':
                // Igual: sus compras del periodo y lo que pagó de compras de
                // antes. Quien solo abonó a una venta vieja también sale.
                $f     = $r->porCliente($desde, $hasta);
                $todos = $r->clienteAnteriores($desde, $hasta);
                $ant   = $todos;
                $filas = [];
                foreach ($f as $x) {
                    $a = $ant[(int)$x['cliente_id']] ?? null;
                    unset($ant[(int)$x['cliente_id']]);
                    // Sin "Cobros anteriores" ni "Liquidaciones": dos conteos
                    // lado a lado ("2" y "1") se leían como tres cosas, cuando
                    // la liquidación es uno de esos cobros. Queda el dinero.
                    $filas[] = [$x['cliente'], $x['area'] ?? '', (int)$x['compras'],
                                $x['vendido'], $x['cobrado'], $x['vendido'] - $x['cobrado'],
                                $a ? (float)$a['monto'] : ''];
                }
                foreach ($ant as $a) {
                    $filas[] = [$a['cliente'], '', '', '', '', '', (float)$a['monto'],
                                '_origen' => 'anterior'];
                }
                $tAnt = 0.0;
                foreach ($todos as $a) $tAnt += (float)$a['monto'];
                return $this->envolver($tipo, $desde, $hasta,
                    [['Cliente', Libro::TEXTO, 34], ['Área', Libro::TEXTO, 24],
                     ['Compras', Libro::NUMERO, 10], ['Vendido', Libro::MONEDA, 15],
                     ['Cobrado', Libro::MONEDA, 15], ['Debe', Libro::MONEDA, 15],
                     ['De ventas anteriores', Libro::MONEDA, 15]],
                    $filas,
                    $this->sumar($f, ['compras','vendido','cobrado'],
                        function ($t) use ($tAnt) { return ['TOTAL', '', (int)$t['compras'], $t['vendido'],
                            $t['cobrado'], $t['vendido'] - $t['cobrado'], round($tAnt, 2)]; }));

            case 'cobranza':
                // Las ventas del periodo que deben y, al final, las de antes
                // que abonaron en el periodo y todavía deben.
                $f = $r->cobranza($desde, $hasta);
                $rep = $this->envolver($tipo, $desde, $hasta,
                    [['Folio', Libro::TEXTO, 18], ['Cliente', Libro::TEXTO, 30],
                     ['Fecha', Libro::FECHA, 12], ['Origen', Libro::TEXTO, 17],
                     ['Días', Libro::NUMERO, 8],
                     ['Total', Libro::MONEDA, 14], ['Cobrado', Libro::MONEDA, 14],
                     ['Cobrado en el periodo', Libro::MONEDA, 15],
                     ['Debe', Libro::MONEDA, 14]],
                    array_map(function ($x) {
                        return [$x['codigo_venta'], $x['cliente'], $x['fecha'],
                                $x['anterior'] ? 'Venta anterior' : 'Venta del periodo',
                                (int)$x['dias'],
                                $x['total'], $x['cobrado'], (float)$x['cobrado_periodo'], $x['saldo'],
                                '_venta' => (int)$x['venta_id'],
                                '_origen' => $x['anterior'] ? 'anterior' : ''];
                    }, $f),
                    $this->sumar($f, ['total','cobrado','cobrado_periodo','saldo'],
                        function ($t) { return ['TOTAL', '', '', '', '', $t['total'],
                            $t['cobrado'], $t['cobrado_periodo'], $t['saldo']]; }));
                return $rep;

            case 'metodo':
                $f = $r->porMetodo($desde, $hasta);
                $gran = array_sum(array_column($f, 'monto'));
                return $this->envolver($tipo, $desde, $hasta,
                    [['Forma de pago', Libro::TEXTO, 24], ['Cobros', Libro::NUMERO, 10],
                     ['Monto', Libro::MONEDA, 16], ['Parte', Libro::PORCENT, 10]],
                    array_map(function ($x) use ($gran) {
                        return [ucfirst($x['metodo']), (int)$x['cobros'], $x['monto'],
                                $gran > 0 ? $x['monto'] / $gran : 0];
                    }, $f),
                    $this->sumar($f, ['cobros','monto'],
                        function ($t) { return ['TOTAL', (int)$t['cobros'], $t['monto'], 1]; }));

            case 'dia':
                $f = $r->porDia($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Fecha', Libro::FECHA, 14], ['Cobros', Libro::NUMERO, 10],
                     ['Cobrado', Libro::MONEDA, 16]],
                    array_map(function ($x) {
                        return [$x['dia'], (int)$x['pagos'], $x['cobrado']];
                    }, $f),
                    $this->sumar($f, ['pagos','cobrado'],
                        function ($t) { return ['TOTAL', (int)$t['pagos'], $t['cobrado']]; }));

            case 'pagos':
                return $this->pagos($r->pagosDelPeriodo($desde, $hasta), $desde, $hasta);

            case 'desglose':
                // Para el Excel y la impresion se aplanan las tablas en
                // una sola, con un renglon de titulo entre cada area:
                // asi cabe en una hoja y sigue siendo el mismo dato.
                $d = $this->desglose($desde, $hasta, 'servicio');
                $filas = []; $cols = $d['tablas'] ? $d['tablas'][0]['columnas'] : [];
                foreach ($d['tablas'] as $t) {
                    $filas[] = [mb_strtoupper($t['titulo'])];
                    foreach ($t['filas'] as $x) $filas[] = $x;
                    $filas[] = $t['totales'];
                    $filas[] = [''];
                }
                return $this->envolver('desglose', $desde, $hasta, $cols, $filas,
                    array_fill(0, count($cols), ''));

            default: // detalle
                // Las ventas del periodo y, al final y marcadas, las de antes
                // con cobros en el periodo. "Cobrado en el periodo" es lo que
                // entró de cada una: sumado da lo mismo que "Entraron".
                $f = $r->detalle($desde, $hasta);
                // GASTOS, COMISIÓN Y "QUEDA" SON DEL PERIODO, como en el
                // resumen: el gasto cuenta en el mes de la venta (en una venta
                // anterior ya contó en su mes) y la comisión en el del cobro
                // que la generó. Antes eran de toda la venta y el TOTAL daba
                // otra utilidad que "Queda" ($40,284.54 contra $38,299.54 en
                // octubre de 2026). La venta completa está en su detalle.
                foreach ($f as &$x) {
                    $x['gastos_periodo'] = $x['anterior'] ? 0.0 : (float)$x['gastos'];
                    // El IVA de lo cobrado no es de la empresa (ver resultado()).
                    $x['queda_periodo']  = (float)$x['cobrado_periodo'] - $x['gastos_periodo']
                                         - (float)$x['comision_periodo'] - (float)$x['iva_periodo'];
                }
                unset($x);
                $rep = $this->envolver('detalle', $desde, $hasta,
                    [['Folio', Libro::TEXTO, 18], ['Cliente', Libro::TEXTO, 30],
                     ['Área', Libro::TEXTO, 24], ['Fecha', Libro::FECHA, 13],
                     ['Origen', Libro::TEXTO, 17],
                     ['Base', Libro::MONEDA, 13], ['IVA', Libro::MONEDA, 12],
                     ['Total', Libro::MONEDA, 14], ['Cobrado', Libro::MONEDA, 14],
                     ['Cobrado en el periodo', Libro::MONEDA, 15],
                     ['Debe', Libro::MONEDA, 13], ['Gastos', Libro::MONEDA, 13],
                     ['Comisión', Libro::MONEDA, 13], ['IVA cobrado', Libro::MONEDA, 13],
                     ['Queda', Libro::MONEDA, 14]],
                    array_map(function ($x) {
                        return [$x['folio'], $x['cliente'], $x['area'], $x['fecha'],
                                $x['anterior'] ? ($x['liquidada'] ? 'Venta anterior · liquidada' : 'Venta anterior')
                                               : 'Venta del periodo',
                                $x['subtotal'], $x['iva'], $x['total'], $x['cobrado'],
                                (float)$x['cobrado_periodo'],
                                $x['saldo'], $x['gastos_periodo'], (float)$x['comision_periodo'],
                                (float)$x['iva_periodo'], round($x['queda_periodo'], 2),
                                '_venta'  => (int)$x['venta_id'],
                                '_origen' => $x['anterior'] ? 'anterior' : ''];
                    }, $f),
                    $this->sumar($f, ['subtotal','iva','total','cobrado','cobrado_periodo','saldo',
                                      'gastos_periodo','comision_periodo','iva_periodo','queda_periodo'],
                        function ($t) { return ['TOTAL', '', '', '', '',
                            $t['subtotal'], $t['iva'], $t['total'], $t['cobrado'], $t['cobrado_periodo'],
                            $t['saldo'], $t['gastos_periodo'], $t['comision_periodo'],
                            round($t['iva_periodo'], 2), round($t['queda_periodo'], 2)]; }));
                $rep['partes'] = $this->partesDe($f, $desde, $hasta);
                return $rep;
        }
    }

    /**
     * El resumen "de dónde vino" para las tablas de VENTAS (detalle de
     * ventas, cobranza): lo que entró en el periodo de ventas del periodo y
     * de ventas anteriores, con cuántos cobros y cuántas quedaron pagadas.
     * Es la misma forma que el del Detalle de pagos, así que se pinta igual
     * en pantalla, en la hoja impresa y en el Excel.
     */
    private function partesDe(array $f, $desde, $hasta)
    {
        $p = [
            'periodo'   => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas del periodo'],
            'anterior'  => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas ' . self::rotuloAntes($desde, $hasta)],
            'posterior' => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas con fecha posterior'],
        ];
        foreach ($f as $x) {
            $k = !empty($x['anterior']) ? 'anterior' : 'periodo';
            $p[$k]['monto']  += (float)($x['cobrado_periodo'] ?? 0);
            $p[$k]['cobros'] += (int)($x['cobros_periodo'] ?? 0);
            if (!empty($x['liquidada'])) $p[$k]['liquidadas'] += 1;
        }
        foreach ($p as $k => $v) $p[$k]['monto'] = round($v['monto'], 2);
        return $p;
    }

    /**
     * Cómo llamar a las ventas de ANTES del periodo, en pantalla, en la hoja
     * impresa y en el Excel. Una sola regla para las tres salidas.
     *
     * El corte es "antes del primer día del periodo". Con un mes completo (o
     * varios) eso es "de meses anteriores", que es como se dice en la
     * oficina; con un rango a mitad de mes —del 15 al 31— una venta del día
     * 3 también cuenta, y llamarla "de meses anteriores" sería falso.
     * Entonces se dice la fecha de corte.
     */
    public static function rotuloAntes($desde, $hasta)
    {
        $mesesCompletos = substr((string)$desde, 8, 2) === '01'
                       && (string)$hasta === date('Y-m-t', strtotime((string)$hasta));
        return $mesesCompletos
             ? 'de meses anteriores'
             : 'anteriores al ' . date('d/m/Y', strtotime((string)$desde));
    }

    /**
     * El "Detalle de pagos": un COBRO por renglón, por la fecha en que entró.
     *
     * Cada renglón se lee como "pago de tal venta": la venta (folio, fecha,
     * de qué periodo es), su total, lo que se pagó en ese cobro, su estado
     * (anticipo, abono o liquidación) y lo que le faltaba DESPUÉS de ese pago.
     *
     * Además trae dos claves que no son columnas:
     *
     *   '_venta'   el id de la venta, para que la pantalla pueda abrirla.
     *   '_origen'  'anterior' si la venta es de antes del periodo,
     *              'posterior' si es de después (fecha movida a mano),
     *              '' si es del periodo.
     *
     * El Excel, la impresión y el filtro de columnas recorren las filas por
     * número de columna, así que esas claves no salen como columnas; la hoja
     * impresa usa '_origen' para resaltar el renglón.
     *
     * 'partes' dice cuánto entró por ventas del periodo y cuánto por ventas
     * de otros: es la respuesta corta a "¿y de dónde salió esto?".
     *
     * Los cobros guardados antes de que existieran los subfolios no tienen
     * uno: se arma con su posición y se marca con ~, igual que en el detalle
     * de la venta, para no hacer pasar por folio un número calculado.
     */
    private function pagos(array $f, $desde, $hasta)
    {
        // Lo que dice la columna "Origen". Es texto de verdad, no una marca de
        // pantalla: así sale igual en la hoja impresa y se puede filtrar en el
        // Excel, que era justo donde no se distinguía.
        $origenes = ['' => 'Venta del periodo', 'anterior' => 'Venta anterior',
                     'posterior' => 'Venta posterior'];
        $partes = [
            'periodo'   => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas del periodo'],
            'anterior'  => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas ' . self::rotuloAntes($desde, $hasta)],
            'posterior' => ['monto' => 0.0, 'cobros' => 0, 'liquidadas' => 0,
                            'rotulo' => 'De ventas con fecha posterior'],
        ];
        $filas = [];
        foreach ($f as $x) {
            $cobro = trim((string)($x['folio'] ?? ''));
            if ($cobro === '') {
                $cobro = Folio::deRespaldo($x['codigo_venta'], (int)$x['posicion']) . '~';
            }
            // Se compara como texto Y-m-d: así lo traen $desde y $hasta.
            $fv     = substr((string)$x['fecha_venta'], 0, 10);
            $origen = $fv < $desde ? 'anterior' : ($fv > $hasta ? 'posterior' : '');
            $parte  = $origen ?: 'periodo';
            $partes[$parte]['monto']  += (float)$x['monto'];
            $partes[$parte]['cobros'] += 1;

            // EL ESTADO SALE DEL SALDO, NO DEL TIPO GUARDADO.
            //
            // El tipo guardado se decide una sola vez, al capturar el pago, con
            // los pagos que había en ese momento: si después se captura uno con
            // fecha anterior o se cancela otro, ya no corresponde. Además, una
            // venta cobrada completa en caja se guardaba como "liquidación", y
            // se confundía con el pago que cierra el saldo de una venta vieja.
            //
            // Ahora, con lo que la venta tenía antes de este pago y lo que le
            // falta después:
            //   primer pago,  la deja en cero  ->  Pago completo (de contado)
            //   primer pago,  deja saldo       ->  Anticipo
            //   pago posterior, deja saldo     ->  Abono
            //   pago posterior, la deja en 0   ->  Liquidación (cierra la venta)
            $falta   = max(0, (float)$x['le_falta']);
            $primero = (int)$x['pagos_antes'] === 0;
            $liquida = $falta <= 0.005;
            $estado  = $liquida ? ($primero ? 'Pago completo' : 'Liquidación')
                                : ($primero ? 'Anticipo' : 'Abono');
            // Cuántas ventas quedaron pagadas con un cobro del periodo, por origen.
            if ($liquida) $partes[$parte]['liquidadas'] += 1;

            $filas[] = [
                $x['fecha_pago'], $cobro, $x['codigo_venta'], $fv, $origenes[$origen],
                $x['cliente'], $x['area'],
                ucfirst((string)($x['metodo_pago'] ?: 'sin método')),
                $x['total'],
                $x['monto'],
                $estado,
                $falta,
                '_venta'  => (int)$x['venta_id'],
                '_origen' => $origen,
            ];
        }
        foreach ($partes as $k => $p) $partes[$k]['monto'] = round($p['monto'], 2);

        $n   = count($filas);
        $rep = $this->envolver('pagos', $desde, $hasta,
            [['Fecha',          Libro::FECHA,  12],
             ['Cobro',          Libro::TEXTO,  21],
             ['Venta',          Libro::TEXTO,  17],
             ['Fecha de venta', Libro::FECHA,  14],
             ['Origen',         Libro::TEXTO,  17],
             ['Cliente',        Libro::TEXTO,  30],
             ['Área',           Libro::TEXTO,  22],
             ['Forma',          Libro::TEXTO,  14],
             // De la venta, no del cobro: no se suma abajo, porque dos cobros
             // de la misma venta la contarían dos veces.
             ['Total venta',    Libro::MONEDA, 14],
             ['Monto',          Libro::MONEDA, 14],
             // Pago completo, anticipo, abono o liquidación: qué fue este pago
             // para su venta.
             // Se deriva del saldo de "Le falta" para que nunca se contradigan.
             ['Estado',         Libro::TEXTO,  13],
             // Lo que la venta quedó debiendo después de este pago. Tampoco
             // se suma: es un saldo de la venta en ese momento, no dinero.
             ['Le falta',       Libro::MONEDA, 13]],
            $filas,
            ['TOTAL', $n . ' cobro' . ($n === 1 ? '' : 's'), '', '', '', '', '', '', '',
             round(array_sum(array_column($f, 'monto')), 2), '', '']);
        $rep['partes'] = $partes;
        return $rep;
    }

    private function envolver($tipo, $desde, $hasta, $columnas, $filas, $totales)
    {
        return [
            'tipo'      => $tipo,
            'titulo'    => self::TIPOS[$tipo]['rotulo'] ?? 'Reporte',
            'nota'      => self::TIPOS[$tipo]['nota'] ?? '',
            'periodo'   => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'columnas'  => $columnas,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    private function sumar(array $f, array $campos, callable $armar)
    {
        $t = [];
        foreach ($campos as $c) $t[$c] = array_sum(array_column($f, $c));
        $t['saldo'] = isset($t['saldo']) ? $t['saldo']
                    : (isset($t['total'], $t['cobrado']) ? $t['total'] - $t['cobrado'] : 0);
        return $armar($t);
    }

    /** Todos los reportes del periodo, para pintarlos de una vez. */
    public function todos($desde, $hasta)
    {
        $r = [];
        foreach (array_keys(self::TIPOS) as $t) $r[$t] = $this->armar($t, $desde, $hasta);
        return $r;
    }

    /** Un .xlsx con todos los reportes del periodo, uno por hoja. */
    public function libroCompleto($desde, $hasta)
    {
        $l = new Libro();
        foreach ($this->todos($desde, $hasta) as $rep) {
            $l->hoja($rep['titulo'], $rep['columnas'], $rep['filas'], [
                'titulo'    => $rep['titulo'],
                'subtitulo' => $rep['periodo'] . ' · ' . $rep['nota'] . self::resumenPartes($rep),
                'totales'   => $rep['totales'],
            ]);
        }
        return $l;
    }

    /**
     * El resumen "de dónde vino" en una línea, para el Excel: cuánto entró
     * por ventas del periodo y cuánto por ventas de otro. En pantalla y en la
     * hoja impresa se pinta como tarjetas; aquí va en el subtítulo de la hoja.
     */
    public static function resumenPartes(array $rep)
    {
        if (empty($rep['partes'])) return '';
        $t = [];
        foreach ($rep['partes'] as $k => $p) {
            // "Posterior" solo si hay: es raro (fecha movida a mano). "Anterior"
            // se dice aunque sea cero: es justo la pregunta.
            if ($k === 'posterior' && empty($p['cobros'])) continue;
            $t[] = $p['rotulo'] . ': ' . Dinero::pesos($p['monto'])
                 . ' (' . self::cuentaPartes($p, $k) . ')';
        }
        return $t ? ' · ' . implode(' · ', $t) : '';
    }

    /**
     * "2 cobros, 1 fue liquidación": lo que va debajo de cada monto del
     * resumen por origen, igual en pantalla, en la hoja impresa y en el Excel.
     *
     * Las ventas liquidadas solo se dicen para ventas de OTRO periodo: ahí son
     * ventas viejas que quedaron pagadas, el dato que se busca. En las del
     * periodo casi todas son ventas cobradas completas en caja, y decirlo
     * ahí las haría pasar por ventas nuevas.
     */
    public static function cuentaPartes(array $p, $k)
    {
        $n = (int)($p['cobros'] ?? 0);
        $l = (int)($p['liquidadas'] ?? 0);
        $t = $n . ' cobro' . ($n === 1 ? '' : 's');
        // "2 cobros, 1 fue liquidación" y no "2 cobros · 1 venta liquidada":
        // así se leía como tres cosas, cuando la liquidación es UNO de los
        // cobros (el que dejó la venta pagada).
        if ($k !== 'periodo' && $l > 0) {
            $t .= $n === 1 ? ' (liquidación)'
                : ', ' . $l . ($l === 1 ? ' fue liquidación' : ' fueron liquidaciones');
        }
        return $t;
    }

    /**
     * El desglose: una tabla por área, con el detalle de cada renglón.
     *
     * LAS DOS FORMAS DE AGRUPAR, Y POR QUE ESTAN LAS DOS
     *
     * Una venta hecha en Administracion que incluye un servicio
     * contable pertenece a las dos areas: a Administracion porque de
     * ahi salio, a Contabilidad porque de eso fue el trabajo.
     *
     * No hay una respuesta correcta: son dos preguntas distintas.
     *
     *   "¿Cuanto vendio cada equipo?"      -> agrupar por ORIGEN
     *   "¿Cuanto trabajo hay de cada tipo?" -> agrupar por SERVICIO
     *
     * Por eso se eligen, en vez de que el sistema decida por ti. Y es
     * el mismo dinero contado de dos maneras: el total general no
     * cambia al cambiar la agrupacion. Si cambiara, una de las dos
     * estaria mal.
     *
     * Agrupando por SERVICIO, contabilidad se parte en dos tablas:
     * personas fisicas y personas morales. Agrupando por ORIGEN no se
     * parte, porque ahi la pregunta es de que equipo salio la venta y
     * el tipo de persona no viene al caso.
     *
     * @param string $por  'servicio' u 'origen'
     */
    public function desglose($desde, $hasta, $por = 'servicio')
    {
        // Se recuerda por periodo: la pantalla pide el desglose y ademas
        // `todos()` lo arma para la pestana, asi que sin esto la consulta
        // mas pesada del reporte corria dos veces en cada carga.
        static $cache = [];
        $k = $desde . '|' . $hasta;
        if (!isset($cache[$k])) {
            $cache[$k] = (new ReporteRepo($this->db))->detallePorLinea($desde, $hasta);
        }
        $filas = $cache[$k];
        // Gastos, comisión y «queda» DEL PERIODO, como en el resumen y en el
        // Detalle de ventas: el gasto cuenta en el mes de la venta (en una
        // venta anterior ya contó en su mes) y la comisión en el del cobro.
        foreach ($filas as &$f) {
            $f['gastos_periodo'] = !empty($f['anterior']) ? 0.0 : (float)$f['gastos'];
            $f['queda_periodo']  = (float)$f['cobrado_periodo'] - $f['gastos_periodo']
                                 - (float)$f['comision_periodo'] - (float)$f['iva_periodo'];
        }
        unset($f);
        $campo = ($por === 'origen') ? 'area_origen' : 'area_servicio';

        $grupos = [];
        foreach ($filas as $f) {
            $g = $f[$campo] ?: 'Sin area';
            // La division de contabilidad solo aplica agrupando por
            // servicio: por origen no significa nada.
            if ($por !== 'origen' && !empty($f['tipo_persona'])) {
                $g .= ' · ' . $f['tipo_persona'];
            }
            $grupos[$g][] = $f;
        }
        // De mayor a menor dinero POR ÁREA COMPLETA: lo que más pesa, arriba.
        // Y las partes de un área, juntas y en orden (físicas, morales, sin
        // clasificar). Antes cada tabla se ordenaba sola por su dinero y otra
        // área se metía entre "Personas físicas" y "Personas morales".
        $areaDe = function ($k) { return explode(' · ', $k)[0]; };
        $parteDe = function ($k) { $p = explode(' · ', $k); return $p[1] ?? ''; };
        $dineroArea = [];
        foreach ($grupos as $k => $gf) {
            $dineroArea[$areaDe($k)] = ($dineroArea[$areaDe($k)] ?? 0)
                                     + array_sum(array_column($gf, 'cobrado_periodo'));
        }
        $ordenParte = ['Personas fisicas' => 1, 'Personas morales' => 2, 'Sin clasificar' => 3];
        uksort($grupos, function ($x, $y) use ($areaDe, $parteDe, $dineroArea, $ordenParte) {
            $ax = $areaDe($x); $ay = $areaDe($y);
            if ($ax !== $ay) return ($dineroArea[$ay] <=> $dineroArea[$ax]) ?: strcmp($ax, $ay);
            return ($ordenParte[$parteDe($x)] ?? 9) <=> ($ordenParte[$parteDe($y)] ?? 9);
        });

        // "Origen" y "Cobrado en el periodo": cada área trae también las
        // líneas de ventas de ANTES del periodo que tuvieron cobros en él,
        // marcadas, con lo que entró de cada una.
        $cols = [
            ['Folio',        Libro::TEXTO,  17],
            ['Cliente',      Libro::TEXTO,  28],
            ['Área',         Libro::TEXTO,  24],
            ['Producto',     Libro::TEXTO,  38],
            ['Descripción',  Libro::TEXTO,  34],
            ['Especialista', Libro::TEXTO,  22],
            ['Fecha',        Libro::FECHA,  12],
            ['Origen',       Libro::TEXTO,  16],
            ['Cant.',        Libro::NUMERO,  8],
            ['Total',        Libro::MONEDA, 14],
            ['Cobrado',      Libro::MONEDA, 14],
            ['Cobrado en el periodo', Libro::MONEDA, 15],
            ['Debe',         Libro::MONEDA, 13],
            ['Gastos',       Libro::MONEDA, 12],
            ['Comisión',     Libro::MONEDA, 13],
            ['IVA cobrado',  Libro::MONEDA, 13],
            ['Queda',        Libro::MONEDA, 14],
            ['Forma',        Libro::TEXTO,  14],
        ];

        $tablas = [];
        foreach ($grupos as $nombre => $gf) {
            $tablas[] = [
                'titulo'   => $nombre,
                'columnas' => $cols,
                'filas'    => array_map(function ($f) use ($por) {
                    return [
                        $f['folio'], $f['cliente'],
                        // Se muestra la OTRA area: agrupando por servicio
                        // interesa de donde salio, y al reves.
                        $por === 'origen' ? $f['area_servicio'] : $f['area_origen'],
                        $f['producto'], $f['producto_desc'], $f['especialista'],
                        $f['fecha'],
                        !empty($f['anterior']) ? (!empty($f['liquidada']) ? 'Anterior · liquidada' : 'Venta anterior')
                                               : 'Del periodo',
                        $f['cantidad'],
                        $f['total_linea'], $f['cobrado'], (float)$f['cobrado_periodo'], $f['saldo'],
                        $f['gastos_periodo'], (float)$f['comision_periodo'],
                        (float)$f['iva_periodo'], round($f['queda_periodo'], 2),
                        ucfirst((string)$f['metodo']),
                        // No son columnas: la pantalla los usa para abrir la
                        // venta y para resaltar las de otro periodo.
                        '_venta'  => (int)$f['venta_id'],
                        '_origen' => !empty($f['anterior']) ? 'anterior' : '',
                    ];
                }, $gf),
                'totales'  => ['TOTAL ' . mb_strtoupper($nombre), '', '', '', '', '', '', '', '',
                    array_sum(array_column($gf, 'total_linea')),
                    array_sum(array_column($gf, 'cobrado')),
                    array_sum(array_column($gf, 'cobrado_periodo')),
                    array_sum(array_column($gf, 'saldo')),
                    array_sum(array_column($gf, 'gastos_periodo')),
                    array_sum(array_column($gf, 'comision_periodo')),
                    round(array_sum(array_column($gf, 'iva_periodo')), 2),
                    round(array_sum(array_column($gf, 'queda_periodo')), 2),
                    ''],
            ];
        }
        return [
            'por'     => $por,
            'rotulo'  => $por === 'origen' ? 'de donde salió la venta' : 'del producto contratado',
            'tablas'  => $tablas,
            'cuantas' => count($tablas),
            // Lo que entró en el periodo de estas ventas: suma lo mismo que «Entraron».
            'total'   => array_sum(array_column($filas, 'cobrado_periodo')),
        ];
    }

    /**
     * Una tabla por colaborador, con el detalle de cada comision.
     *
     * Contesta la pregunta que llega cada quincena: "por que me toco
     * esto". El resumen da el total; esto da los renglones.
     */
    public function desgloseComisiones($desde, $hasta)
    {
        static $cache = [];
        $k = $desde . '|' . $hasta;
        if (!isset($cache[$k])) {
            $cache[$k] = (new ReporteRepo($this->db))->comisionesDetalle($desde, $hasta);
        }
        $filas = $cache[$k];

        $grupos = [];
        foreach ($filas as $f) $grupos[$f['colaborador'] ?: 'POR ASIGNAR'][] = $f;
        uasort($grupos, function ($a, $b) {
            return array_sum(array_column($b, 'comision'))
               <=> array_sum(array_column($a, 'comision'));
        });

        $cols = [
            ['Folio',        Libro::TEXTO,  17],
            ['Cliente',      Libro::TEXTO,  30],
            ['Área',         Libro::TEXTO,  24],
            ['Fecha de pago',Libro::FECHA,  13],
            ['Tipo',         Libro::TEXTO,  12],
            ['Forma',        Libro::TEXTO,  14],
            ['Total venta',  Libro::MONEDA, 14],
            ['Cobrado',      Libro::MONEDA, 14],
            ['% comisión',   Libro::PORCENT,11],
            ['Sobre',        Libro::PORCENT,10],
            ['Comisión',     Libro::MONEDA, 14],
        ];

        $tablas = [];
        foreach ($grupos as $quien => $gf) {
            $tablas[] = [
                'titulo'   => $quien,
                'columnas' => $cols,
                'filas'    => array_map(function ($f) {
                    return [$f['folio'], $f['cliente'], $f['area'], $f['fecha_pago'],
                            ucfirst((string)$f['tipo_pago']), ucfirst((string)$f['metodo']),
                            $f['total_venta'], $f['cobrado'],
                            (float)$f['porcentaje'] / 100,
                            (float)$f['proporcion'],
                            $f['comision'],
                            '_venta' => (int)$f['venta_id']];
                }, $gf),
                'totales'  => ['TOTAL ' . mb_strtoupper($quien), '', '', '', '', '',
                    '', array_sum(array_column($gf, 'cobrado')), '', '',
                    array_sum(array_column($gf, 'comision'))],
                'pagos'    => count($gf),
                'monto'    => array_sum(array_column($gf, 'comision')),
            ];
        }
        return ['tablas' => $tablas, 'cuantas' => count($tablas),
                'total' => array_sum(array_column($filas, 'comision')),
                'pagos' => count($filas)];
    }
}
