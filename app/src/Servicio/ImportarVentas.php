<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Trae a LibertyFin la hoja de ventas que se llevaba en Excel.
 *
 * QUÉ CREA POR CADA RENGLÓN
 *
 *   una venta        con el folio, la fecha y el total de la hoja
 *   un renglón       el servicio vendido
 *   un cobro         el anticipo, con su subfolio -01
 *   un gasto         si el renglón traía solicitud de gastos
 *   comisiones       las cuatro, con los montos EXACTOS de la hoja
 *
 * Y de paso da de alta lo que falte: clientes, servicios, áreas y
 * colaboradores.
 *
 * PRIMERO SE MIRA, DESPUÉS SE APLICA
 *
 * `analizar()` no escribe nada: lee, valida y devuelve el plan. La
 * pantalla lo enseña y alguien decide. Importar setenta y nueve ventas
 * a ciegas y descubrir después que la mitad entró mal es un día entero
 * de borrar a mano.
 *
 * CÓMO SE RESPETAN LOS MONTOS DE LA HOJA
 *
 * LibertyFin no guarda la comisión como un número suelto: guarda un
 * porcentaje y una base, y recalcula. Si se metiera el monto a pelo, la
 * primera resincronización lo pisaría con otro número.
 *
 * La base de LibertyFin es  precio − costo − gasto.
 * La de la hoja es          anticipo − IVA − comisión bancaria − gastos.
 *
 * Así que se mapea pieza por pieza, y no por casualidad: la comisión
 * bancaria ES un costo y la solicitud de gastos ES un gasto de
 * operación. Con eso la base coincide al centavo y el porcentaje
 * guardado (monto ÷ base) devuelve el mismo monto que traía el Excel,
 * aunque se recalcule mil veces.
 *
 * SE PUEDE VOLVER A SUBIR EL MISMO ARCHIVO
 *
 * El folio manda. Si una venta ya existe, se salta y se dice. Subir dos
 * veces agosto no duplica nada.
 */
final class ImportarVentas
{
    /**
     * Los nombres de columna que se aceptan, en orden de preferencia.
     *
     * SE BUSCA POR NOMBRE, NO POR POSICIÓN. Las hojas de agosto y
     * septiembre NO tienen las mismas columnas: septiembre agrega
     * FACTURA y SUMA COMI, quita FORMA D PAGO y renombra RESTANTES y
     * REAL BANCO. Leer por número de columna funciona con una y mete
     * los importes en el campo equivocado con la otra.
     */
    const COLUMNAS = [
        'folio'     => ['FOLIO'],
        'fecha'     => ['FECHA'],
        'cliente'   => ['CLIENTE'],
        'area'      => ['AREA', 'ÁREA'],
        'servicio'  => ['SERVICIO'],
        'forma'     => ['FORMA'],
        'total'     => ['COSTO TOAL DE VENTA', 'COSTO TOTAL DE VENTA', 'COSTO TOTAL', 'TOTAL'],
        'anticipo'  => ['MONTO ANTICIPO', 'ANTICIPO'],
        'restante'  => ['RESTANTES', 'PENDIENTES', 'RESTANTE', 'PENDIENTE'],
        'iva'       => ['IVA'],
        'banca'     => ['COM. BANCA', 'COM BANCA', 'COMISION BANCARIA'],
        'neto'      => ['REAL BANCO', 'REAL'],
        'recibo'    => ['RECIBO PAGO', 'RECIBO'],
        'solGastos' => ['SOL. GASTOS', 'SOL GASTOS'],
        'gastos'    => ['CANTIDAD'],
        'base'      => ['A COMISIONAR'],
        'vendedor'  => ['VENDEDOR'],
    ];

    /**
     * Los cuatro conceptos, en el orden en que vienen después de
     * VENDEDOR. Los encabezados mienten —dicen VENDEDOR2, GERENTE,
     * ADMIN, ÁREA2— pero lo que hay debajo son los importes del
     * vendedor, el gerente, la administración y el área.
     */
    const CONCEPTOS = ['Vendedor', 'Gerente', 'Administración', 'Área'];

    /** Los campos que se pueden corregir desde la pantalla. */
    /** Lo que lleva en el título el archivo de ejemplo. Ver ejemplo(). */
    const MARCA_EJEMPLO = 'ARCHIVO DE EJEMPLO';

    const AJUSTABLES = ['folio','fecha','cliente','area','servicio','forma',
                        'total','anticipo','vendedor','com0','com1','com2','com3'];

    private $db;
    private $error = '';
    private $ajustes = [];
    /** Solo para comparar (Auditoría): ver analizar(). */
    private $comparar = false;

    public function __construct(PDO $db) { $this->db = $db; }

    public function error() { return $this->error; }

    // ════════════════════════════════════════════════════════
    //  MIRAR
    // ════════════════════════════════════════════════════════

    /**
     * Lee las hojas y devuelve el plan. NO ESCRIBE NADA.
     *
     * @param array $hojas  lo que entrega LectorHoja
     * @param array $opc    hojas => cuáles importar
     */
    public function analizar(array $hojas, array $opc = [])
    {
        $soloEstas = $opc['hojas'] ?? null;
        // Los ajustes que alguien escribió en la pantalla de revisión,
        // guardados por hoja y fila. Ver `ajustar()` más abajo.
        $this->ajustes = is_array($opc['ajustes'] ?? null) ? $opc['ajustes'] : [];
        // COMPARAR: la misma lectura, pero sin saltarse lo que ya está en el
        // sistema, que es justo lo que Auditoría quiere revisar contra la
        // hoja. Tampoco busca qué daría de alta: no se va a importar nada.
        $this->comparar = !empty($opc['comparar']);

        $plan = [
            'renglones' => [], 'listas' => 0, 'abonos' => 0, 'conAviso' => 0,
            'repetidas' => 0, 'malas' => 0, 'sinFolio' => 0,
            'total' => 0.0, 'cobrado' => 0.0, 'comisiones' => 0.0,
            'nuevos' => ['clientes' => [], 'servicios' => [], 'areas' => [], 'colaboradores' => []],
            'hojas' => [], 'ajustados' => 0, 'excluidos' => 0,
            'ejemplo' => false,
        ];

        $vistos = [];                       // folios dentro del propio archivo
        foreach ($hojas as $nombre => $filas) {
            if ($soloEstas && !in_array($nombre, $soloEstas, true)) continue;
            $plan['hojas'][] = $nombre;

            $enc = $this->encabezado($filas);
            if (!$enc) {
                $plan['renglones'][] = [
                    'hoja' => $nombre, 'fila' => 0, 'estado' => 'mala',
                    'avisos' => ['No encontré el encabezado. Debe traer al menos FOLIO, '
                               . 'CLIENTE, SERVICIO y COSTO TOTAL DE VENTA.'],
                ];
                $plan['malas']++;
                continue;
            }
            list($col, $iEnc, $iCom) = $enc;
            // El archivo de ejemplo se deja revisar pero no guardar: ver ejemplo().
            for ($i = 0; $i < $iEnc; $i++) {
                foreach ((array)($filas[$i] ?? []) as $c) {
                    if (is_string($c) && mb_stripos($c, self::MARCA_EJEMPLO) !== false) $plan['ejemplo'] = true;
                }
            }

            foreach ($filas as $n => $f) {
                if ($n <= $iEnc) continue;
                $r = $this->renglon($nombre, $n, $f, $col, $iCom, $vistos);
                if ($r === null) {
                    // Fila vacía o del bloque de totales. Se cuentan las
                    // que traían algo escrito, para que nadie se quede
                    // con la duda de qué pasó con ellas.
                    if (array_filter((array)$f, function ($c) { return $c !== null && $c !== ''; })) {
                        $plan['sinFolio']++;
                    }
                    continue;
                }

                if (!empty($r['ajustado']))  $plan['ajustados']++;
                if ($r['estado'] === 'excluida') { $plan['excluidos']++; $plan['renglones'][] = $r; continue; }
                if ($r['estado'] === 'mala')        $plan['malas']++;
                elseif ($r['estado'] === 'repetida') $plan['repetidas']++;
                else {
                    if ($r['tipo'] === 'abono') $plan['abonos']++; else $plan['listas']++;
                    if ($r['avisos']) $plan['conAviso']++;
                    $plan['total']      += $r['total'];
                    $plan['cobrado']    += $r['anticipo'];
                    $plan['comisiones'] += array_sum(array_column($r['comisiones'], 'monto'));
                    if (!$this->comparar) $this->anotarNuevos($plan['nuevos'], $r);
                }
                $plan['renglones'][] = $r;
            }
        }

        foreach ($plan['nuevos'] as $k => $v) $plan['nuevos'][$k] = array_values(array_unique($v));
        $plan['total']      = Dinero::centavos($plan['total']);
        $plan['cobrado']    = Dinero::centavos($plan['cobrado']);
        $plan['comisiones'] = Dinero::centavos($plan['comisiones']);
        return $plan;
    }

    /**
     * Dónde está el encabezado y qué columna es cada cosa.
     *
     * No se asume que esté en la fila 1: estas hojas traen un título
     * encima ("PAGOS DE CLIENTES"). Se busca la primera fila que tenga
     * las columnas imprescindibles.
     */
    private function encabezado(array $filas)
    {
        foreach ($filas as $i => $f) {
            if ($i > 12 || !is_array($f)) continue;
            $norm = [];
            foreach ($f as $j => $c) {
                $t = self::clave($c);
                if ($t !== '') $norm[$t] = $j;
            }
            $col = [];
            foreach (self::COLUMNAS as $campo => $alias) {
                foreach ($alias as $a) {
                    $k = self::clave($a);
                    if (isset($norm[$k])) { $col[$campo] = $norm[$k]; break; }
                }
            }
            foreach (['folio', 'cliente', 'servicio', 'total'] as $obligatoria) {
                if (!isset($col[$obligatoria])) { $col = []; break; }
            }
            if ($col) {
                // Los cuatro importes van justo después de VENDEDOR.
                $iCom = isset($col['vendedor']) ? $col['vendedor'] + 1 : null;
                return [$col, $i, $iCom];
            }
        }
        return null;
    }

    /** Un renglón leído, validado y listo para aplicar (o no). */
    private function renglon($hoja, $n, $f, array $col, $iCom, array &$vistos)
    {
        if (!is_array($f)) return null;
        $g = function ($campo) use ($f, $col) {
            if (!isset($col[$campo])) return null;
            return $f[$col[$campo]] ?? null;
        };

        // ── LOS AJUSTES VAN AQUÍ, ANTES DE VALIDAR ──
        //
        // Lo que alguien corrigió en la pantalla entra como si viniera
        // del archivo, y después pasa por las MISMAS comprobaciones:
        // que el anticipo quepa en el total, que la base cuadre, que el
        // folio no choque. Si se aplicaran al final, un ajuste podría
        // colar una venta imposible saltándose la revisión que es justo
        // lo que esta pantalla existe para hacer.
        //
        // El archivo sigue siendo la fuente. El ajuste es una capa
        // encima, guardada aparte y visible en la pantalla.
        $ajuste = $this->ajustes[$hoja . '|' . ($n + 1)] ?? null;
        $g = $this->conAjuste($g, $ajuste);

        $folio   = preg_replace('/\D/', '', (string)$g('folio'));
        $cliente = LectorHoja::limpiar($g('cliente'));
        $total   = self::numero($g('total'));

        // SIN FOLIO NO ES UNA VENTA.
        //
        // Al pie de cada hoja hay un bloque de totales y subtotales con
        // texto en columnas que aquí significan otra cosa: "SPEI" cae
        // donde va el cliente y el gran total cae donde va el importe.
        // Marcarlos como errores llenaba la pantalla de veintiocho
        // fallos falsos que escondían los tres de verdad.
        //
        // El folio es lo único con lo que se puede identificar una
        // venta, cobrarla después o no duplicarla al reimportar. Sin él
        // no hay nada que hacer, así que se saltan en silencio y se
        // cuentan aparte.
        if ($folio === '') {
            // Si NADIE tocó este renglón, es del bloque de totales y se
            // salta callado. Pero si alguien lo editó y lo dejó sin
            // folio, verlo desaparecer de la lista parecería que se
            // guardó bien. Se queda, marcado.
            if (!$ajuste) return null;
            return ['hoja' => $hoja, 'fila' => $n + 1, 'estado' => 'mala',
                    'folio' => '', 'folioOriginal' => '', 'tipo' => 'venta',
                    'cliente' => $cliente ?: '', 'area' => '', 'servicio' => '',
                    'forma' => '', 'total' => 0.0, 'anticipo' => 0.0, 'restante' => 0.0,
                    'iva' => 0.0, 'banca' => 0.0, 'gastos' => 0.0, 'base' => 0.0,
                    'vendedor' => null, 'comisiones' => [], 'fecha' => null,
                    'recibo' => null, 'solGastos' => null, 'folioVenta' => null,
                    'ajustado' => array_values(array_diff(array_keys($ajuste), ['_ignorado'])),
                    'avisos' => ['Le quitaste el folio. Sin folio no se puede guardar: '
                               . 'ponle uno o sácalo con «No importar».']];
        }

        $r = [
            'hoja' => $hoja, 'fila' => $n + 1, 'estado' => 'lista', 'avisos' => [],
            'folio' => $folio, 'folioOriginal' => trim((string)$g('folio')),
            'cliente' => $cliente ?: 'Público general',
            'area' => LectorHoja::limpiar($g('area')) ?: 'General',
            'servicio' => LectorHoja::limpiar($g('servicio')) ?: 'Servicio',
            'forma' => LectorHoja::limpiar($g('forma')) ?: '',
            'recibo' => LectorHoja::limpiar($g('recibo')),
            'solGastos' => LectorHoja::limpiar($g('solGastos')),
            'total' => self::numero($g('total')) ?: 0.0,
            'anticipo' => self::numero($g('anticipo')) ?: 0.0,
            'restante' => self::numero($g('restante')) ?: 0.0,
            'iva' => self::numero($g('iva')) ?: 0.0,
            'banca' => self::numero($g('banca')) ?: 0.0,
            'gastos' => self::numero($g('gastos')) ?: 0.0,
            'base' => self::numero($g('base')),
            'vendedor' => LectorHoja::limpiar($g('vendedor')),
            'comisiones' => [],
            'tipo' => 'venta',        // o 'abono', si el folio ya salió
            'folioVenta' => null,
            'ajustado' => $ajuste
                        ? array_values(array_diff(array_keys($ajuste), ['_ignorado'])) : [],
        ];

        if (!empty($ajuste['_ignorado'])) {
            $rot = ['total'=>'el total','anticipo'=>'lo que cobra','fecha'=>'la fecha',
                    'com0'=>'la comisión del vendedor','com1'=>'la del gerente',
                    'com2'=>'la de administración','com3'=>'la del área'];
            $cuales = array_map(function ($k) use ($rot) { return $rot[$k] ?? $k; },
                                (array)$ajuste['_ignorado']);
            $r['avisos'][] = 'No entendí lo que escribiste en ' . implode(' y ', $cuales)
                           . ': se dejó lo del archivo.';
        }

        // Excluir un renglón es una decisión, no un error: se enseña
        // tachado en vez de desaparecer, para que se vea que fue a
        // propósito y se pueda deshacer.
        if (!empty($ajuste['excluir'])) {
            $r['estado'] = 'excluida';
            $r['avisos'][] = 'Lo sacaste a mano. No entra.';
            return $r;
        }

        // ── Fecha ──
        // Manda la columna Fecha, no la que lleva el folio dentro. El
        // folio es cuándo se capturó; la fecha, cuándo fue la venta, y
        // es la que decide en qué mes y en qué corte cae. En este
        // archivo difieren en 66 de 79 renglones.
        $r['fecha'] = self::fecha($g('fecha'));
        if (!$r['fecha'] && strlen($folio) >= 8) {
            $r['fecha'] = substr($folio, 0, 4) . '-' . substr($folio, 4, 2) . '-' . substr($folio, 6, 2);
            $r['avisos'][] = 'Sin fecha: se tomó la del folio.';
        }
        if (!$r['fecha']) {
            $r['estado'] = 'mala';
            $r['avisos'][] = 'No tiene fecha ni folio del que sacarla.';
            return $r;
        }

        // ── Folio ──
        if ($folio === '') {
            $r['estado'] = 'mala';
            $r['avisos'][] = 'No tiene folio.';
            return $r;
        }
        if (strlen($folio) > 20) {
            $folio = substr($folio, 0, 20);
            $r['folio'] = $folio;
            $r['avisos'][] = 'El folio se recortó a 20 dígitos, que es lo que cabe.';
        }
        // ── ¿Venta o abono? ──
        //
        // ESTO ES LO QUE DE VERDAD SIGNIFICA UN FOLIO REPETIDO.
        //
        // Cuando el mismo folio vuelve a aparecer SIN total, no es una
        // venta duplicada: es otro pago de la misma. Así se lleva la
        // hoja, y tiene sentido —el total ya quedó escrito arriba, no
        // hay por qué repetirlo—:
        //
        //   fila 15  folio ...757  total 32500  anticipo  5000
        //   fila 19  folio ...757  total   —    anticipo  5000
        //   fila 35  folio ...757  total   —    anticipo 22500
        //                          5000 + 5000 + 22500 = 32500
        //
        // Son tres renglones de UNA venta de $32,500 que se fue
        // cobrando en tres partes. Meterlas como tres ventas inflaría
        // las ventas del mes a $70,000 cuando se vendió la mitad.
        //
        // Entran como cobros de la primera, con su subfolio -02 y -03.
        //
        // Si el folio se repite PERO el renglón trae su propio total,
        // entonces sí son dos ventas distintas a las que les tocó el
        // mismo folio. A esa se le pega una letra —no un guion, que el
        // guion ya significa subfolio de cobro—.
        $primero = $vistos[$folio]['total'] ?? null;
        if ($primero !== null) {
            if ($r['total'] <= 0) {
                $r['tipo'] = 'abono';
                $r['folioVenta'] = $folio;
                $r['total'] = 0.0;
                // EL NÚMERO SE FIJA AQUÍ, NO AL GUARDAR.
                //
                // Si se pidiera el siguiente libre al escribir, volver a
                // subir el mismo archivo le daría otro número al mismo
                // cobro y entraría por segunda vez: la venta acabaría
                // cobrada de más. Con el número atado a su sitio en el
                // archivo, el subfolio es siempre el mismo y basta con
                // mirar si ya está.
                //
                // El cobro de la venta es el -01, así que el primer
                // abono es el -02.
                $vistos[$folio]['cobros']++;
                $r['cobroNum'] = $vistos[$folio]['cobros'] + 1;
                $r['folioCobro'] = Folio::formar($folio, $r['cobroNum']);
                $r['avisos'][] = 'Es otro cobro de la venta ' . $folio
                               . ' (fila ' . $vistos[$folio]['fila'] . '), con el folio '
                               . $r['folioCobro'] . '.';
            } else {
                $letra = chr(65 + min(25, $vistos[$folio]['ventas']));
                $vistos[$folio]['ventas']++;
                $r['avisos'][] = 'El folio ' . $folio . ' ya se usó en la fila '
                               . $vistos[$folio]['fila'] . ' para otra venta distinta. '
                               . 'Esta entra como ' . $folio . $letra . '.';
                $folio = substr($folio, 0, 19) . $letra;
                $r['folio'] = $folio;
                $vistos[$folio] = ['total' => $r['total'], 'fila' => $n + 1, 'ventas' => 1, 'cobros' => 0];
            }
        } else {
            // Primera vez que se ve este folio: es la venta.
            // Si le falta el total —pasa— se deduce de lo cobrado más
            // lo que queda a deber, que es la misma suma.
            if ($r['total'] <= 0 && ($r['anticipo'] + $r['restante']) > 0) {
                $r['total'] = Dinero::centavos($r['anticipo'] + $r['restante']);
                $r['avisos'][] = 'Sin total en la hoja: se tomó anticipo + pendiente = '
                               . Dinero::pesos($r['total']) . '.';
            }
            $vistos[$folio] = ['total' => $r['total'], 'fila' => $n + 1, 'ventas' => 1, 'cobros' => 0];
        }

        if ($this->comparar) {
            // Ni venta ni cobro se saltan por existir: se van a comparar.
        } elseif (($r['tipo'] ?? 'venta') === 'venta') {
            if ($this->yaExiste($folio)) {
                $r['estado'] = 'repetida';
                $r['avisos'][] = 'Ya está en el sistema. Se salta.';
                return $r;
            }
        } elseif ($this->cobroExiste($r['folioCobro'])) {
            // Mismo cobro, mismo subfolio: ya entró. Sin esto, volver a
            // subir el archivo abonaba otra vez y la venta quedaba
            // cobrada por encima de su total.
            $r['estado'] = 'repetida';
            $r['avisos'][] = 'El cobro ' . $r['folioCobro'] . ' ya está en el sistema. Se salta.';
            return $r;
        }

        // ── Dinero ──
        if ($r['tipo'] === 'abono') {
            if ($r['anticipo'] <= 0) {
                $r['estado'] = 'mala';
                $r['avisos'][] = 'Dice ser otro cobro de ' . $folio . ' pero no trae importe.';
            }
            // Un abono no vuelve a validar el total: no tiene.
            $r['comisiones'] = $this->comisiones($f, $iCom, $r, $ajuste);
            list($r['metodo'], $r['banco']) = self::formaDePago($r['forma']);
            if ($r['metodo'] === null) $r['metodo'] = 'efectivo';
            return $r;
        }

        if ($r['total'] <= 0) {
            $r['estado'] = 'mala';
            $r['avisos'][] = 'El total de la venta es cero o no es un número.';
            return $r;
        }
        if ($r['anticipo'] < 0 || $r['anticipo'] > $r['total'] + 0.02) {
            $r['estado'] = 'mala';
            $r['avisos'][] = 'El anticipo (' . Dinero::pesos($r['anticipo'])
                           . ') no cabe en el total (' . Dinero::pesos($r['total']) . ').';
            return $r;
        }
        // Total = anticipo + restante. Cuando no cuadra, alguien tocó una
        // celda y las otras dos se quedaron como estaban.
        if (abs(($r['anticipo'] + $r['restante']) - $r['total']) > 0.02) {
            $r['avisos'][] = 'El total no es anticipo + restante ('
                           . Dinero::pesos($r['anticipo']) . ' + ' . Dinero::pesos($r['restante'])
                           . ' = ' . Dinero::pesos($r['anticipo'] + $r['restante'])
                           . '). Entra con el total de la hoja y el saldo sale del cobro.';
        }

        // ── La base comisionable ──
        $calc = Dinero::centavos($r['anticipo'] - $r['iva'] - $r['banca'] - $r['gastos']);
        if ($r['base'] === null) {
            $r['base'] = $calc;
        } elseif (abs($r['base'] - $calc) > 0.02) {
            $r['avisos'][] = 'La base a comisionar dice ' . Dinero::pesos($r['base'])
                           . ' pero anticipo − IVA − banca − gastos da ' . Dinero::pesos($calc)
                           . '. Se usa la de la hoja.';
        }
        if ($r['gastos'] > $r['anticipo'] + 0.02) {
            $r['avisos'][] = 'Los gastos (' . Dinero::pesos($r['gastos'])
                           . ') pasan del anticipo. Se registran igual, pero revísalo.';
        }

        // ── Comisiones ──
        $r['comisiones'] = $this->comisiones($f, $iCom, $r, $ajuste);

        // ── Forma de pago ──
        list($r['metodo'], $r['banco']) = self::formaDePago($r['forma']);
        if ($r['anticipo'] > 0 && $r['metodo'] === null) {
            $r['metodo'] = 'efectivo';
            $r['avisos'][] = 'No entendí la forma de pago "' . $r['forma'] . '": entra como efectivo.';
        }
        return $r;
    }

    /**
     * Envuelve el lector de celdas para que devuelva el valor ajustado.
     *
     * Se queda con una función en vez de reescribir el array de la
     * fila: así da igual en qué columna esté cada cosa —que entre las
     * dos hojas no coincide— y el resto del código no se entera de que
     * hubo un ajuste.
     */
    private function conAjuste(callable $g, $ajuste)
    {
        if (!$ajuste) return $g;
        return function ($campo) use ($g, $ajuste) {
            if (!array_key_exists($campo, $ajuste)) return $g($campo);
            $v = $ajuste[$campo];
            // Vacío quiere decir "borra lo que traía", no "usa el
            // original": si alguien limpia el vendedor es porque esa
            // venta no lleva vendedor.
            return $v === '' ? null : $v;
        };
    }

    /**
     * Limpia y guarda lo que alguien corrigió en la pantalla.
     *
     * Solo se admiten los campos de AJUSTABLES y solo con la forma que
     * les toca. Lo que llega del navegador lo escribe quien lo manda,
     * así que un "total" con letras dentro no puede acabar en la base.
     *
     * @param array $previos  lo que ya se había ajustado
     * @param array $crudo    lo que llega del formulario
     * @return array          ajustes por 'HOJA|fila'
     */
    public static function ajustar(array $previos, array $crudo)
    {
        foreach ($crudo as $llave => $campos) {
            if (!is_array($campos) || !preg_match('/^.+\|\d+$/', (string)$llave)) continue;
            $limpio = [];
            foreach ($campos as $k => $v) {
                if ($k === 'excluir') { if ($v) $limpio['excluir'] = true; continue; }
                if ($k === 'restaurar') continue;
                if (!in_array($k, self::AJUSTABLES, true)) continue;
                $v = LectorHoja::limpiar(is_scalar($v) ? (string)$v : '');
                if ($v === null) { $limpio[$k] = ''; continue; }   // borrado a propósito
                if (in_array($k, ['total','anticipo','com0','com1','com2','com3'], true)) {
                    $n = self::numero($v);
                    // NO SE TRAGA EN SILENCIO. Quien escribió "doscientos"
                    // en un importe creería que se guardó, y al volver a
                    // ver el número de antes pensaría que la pantalla
                    // falla. Se descarta y se dice cuál se descartó.
                    if ($n === null) { $limpio['_ignorado'][] = $k; continue; }
                    $limpio[$k] = (string)round($n, 2);
                } elseif ($k === 'fecha') {
                    $f = self::fecha($v);
                    if ($f === null) { $limpio['_ignorado'][] = $k; continue; }
                    $limpio[$k] = $f;
                } else {
                    $limpio[$k] = mb_substr($v, 0, 255);
                }
            }
            // Pedir "restaurar" quita el ajuste entero y vuelve a lo
            // que dice el archivo.
            if (!empty($campos['restaurar'])) { unset($previos[$llave]); continue; }
            // Si lo ÚNICO que quedó fue el reporte de lo descartado, no
            // hay ajuste que guardar: solo algo que avisar.
            if ($limpio && $limpio !== ['_ignorado' => $limpio['_ignorado'] ?? null]) {
                $previos[$llave] = $limpio;
            } elseif (!empty($limpio['_ignorado'])) {
                $previos[$llave] = ['_ignorado' => $limpio['_ignorado']];
            }
            else unset($previos[$llave]);
        }
        return $previos;
    }

    /**
     * Los cuatro importes de comisión que trae el renglón.
     *
     * LA HOJA NO ES CONSISTENTE y hay que decirlo en vez de inventar:
     *
     *  · El área va al 30%, al 41% o al 50% según el renglón, sin una
     *    regla que se pueda deducir.
     *  · En quince renglones hay un solo importe —un tercio de la base—
     *    en la columna de administración, sin vendedor. No es el 3% de
     *    administración: es otra cosa que alguien puso ahí.
     *
     * Por eso se respeta el MONTO tal como viene y el porcentaje se
     * deduce de él (monto ÷ base), en vez de aplicar un porcentaje fijo
     * y que salgan números que nadie reconoce.
     */
    private function comisiones($f, $iCom, array $r, $ajuste = null)
    {
        $base = (float)$r['base'];
        $out = [];
        foreach (self::CONCEPTOS as $k => $concepto) {
            // Lo corregido a mano gana sobre lo del archivo. Un importe
            // puesto en cero BORRA esa comisión, que es lo que alguien
            // quiere decir al escribir cero.
            if ($ajuste !== null && array_key_exists('com' . $k, $ajuste)) {
                $monto = self::numero($ajuste['com' . $k]);
            } elseif ($iCom === null) {
                continue;
            } else {
                $monto = self::numero($f[$iCom + $k] ?? null);
            }
            if ($monto === null || abs($monto) < 0.005) continue;
            // Sin base no hay porcentaje que valga: se guarda en cero y
            // el monto queda anotado para que alguien lo revise.
            $pct = $base > 0 ? round($monto / $base * 100, 4) : 0;
            $out[] = [
                'concepto' => $concepto,
                'colaborador' => $concepto === 'Vendedor' ? $r['vendedor'] : null,
                'monto' => Dinero::centavos($monto),
                'porcentaje' => $pct,
            ];
        }
        return $out;
    }

    private function anotarNuevos(array &$n, array $r)
    {
        if ($r['cliente'] && !$this->existe('clientes', 'nombre', $r['cliente'])) {
            $n['clientes'][] = $r['cliente'];
        }
        if (!$this->existe('comision_areas', 'nombre', $r['area'])) $n['areas'][] = $r['area'];
        $srv = self::nombreServicio($r['area'], $r['servicio']);
        if (!$this->existe('productos', 'nombre', $srv)) $n['servicios'][] = $srv;
        if ($r['vendedor'] && !$this->existe('comision_colaboradores', 'nombre', $r['vendedor'])) {
            $n['colaboradores'][] = $r['vendedor'];
        }
    }

    // ════════════════════════════════════════════════════════
    //  APLICAR
    // ════════════════════════════════════════════════════════

    /**
     * Escribe el plan. Solo los renglones en estado `lista`.
     *
     * CADA VENTA EN SU PROPIA TRANSACCIÓN, no las setenta y nueve en
     * una. Si el renglón 60 truena, los 59 anteriores ya están dentro y
     * se reanuda desde ahí volviendo a subir el archivo —los que ya
     * entraron se saltan solos por folio—. Con una transacción única,
     * un fallo al final tira el trabajo entero.
     */
    public function aplicar(array $plan, array $ctx = [])
    {
        $res = ['ventas' => 0, 'abonos' => 0, 'cobros' => 0, 'comisiones' => 0, 'gastos' => 0,
                'saltadas' => 0, 'fallidas' => 0, 'errores' => [],
                'creados' => ['clientes' => 0, 'servicios' => 0, 'areas' => 0, 'colaboradores' => 0],
                'total' => 0.0, 'cobrado' => 0.0];

        foreach ($plan['renglones'] as $r) {
            if (($r['estado'] ?? '') !== 'lista') { $res['saltadas']++; continue; }
            try {
                if (($r['tipo'] ?? 'venta') === 'abono') {
                    $this->abonar($r, $ctx, $res);
                    $res['cobrado'] += $r['anticipo'];
                    continue;
                }
                $this->guardar($r, $ctx, $res);
                $res['ventas']++;
                $res['total']   += $r['total'];
                $res['cobrado'] += $r['anticipo'];
            } catch (\Throwable $e) {
                $res['fallidas']++;
                $res['errores'][] = $r['hoja'] . ' fila ' . $r['fila'] . ' (' . $r['folio'] . '): '
                                  . $e->getMessage();
                error_log('[LibertyFin] importar fila ' . $r['fila'] . ': ' . $e->getMessage());
            }
        }
        $res['total']   = Dinero::centavos($res['total']);
        $res['cobrado'] = Dinero::centavos($res['cobrado']);

        Auditoria::anota('venta.importar', count($plan['renglones']) . ' renglones', null,
            $res['ventas'] . ' ventas por ' . Dinero::pesos($res['total'])
            . ($res['abonos'] ? ' · ' . $res['abonos'] . ' abonos' : '')
            . ' · ' . $res['saltadas'] . ' saltadas'
            . ($res['fallidas'] ? ' · ' . $res['fallidas'] . ' fallidas' : ''));
        return $res;
    }

    private function guardar(array $r, array $ctx, array &$res)
    {
        $clienteId = $this->clienteId($r['cliente'], $res);
        $areaId    = $this->areaId($r['area'], $res);
        $productoId= $this->servicioId($r['area'], $r['servicio'], $r['total'], $res);

        $this->db->beginTransaction();
        try {
            // ── La venta ──
            // El IVA viaja aparte porque la hoja lo trae desglosado; el
            // total es lo que paga el cliente, con IVA dentro.
            $this->db->prepare("
                INSERT INTO ventas
                    (codigo_venta, cliente_id, usuario_id, sucursal_id, caja_id,
                     subtotal, descuento, iva, total, metodo_pago, estado,
                     observaciones, fecha, iva_modo)
                VALUES (?,?,?,?,NULL,?,0,?,?,?,'completada',?,?, 'incluido')
            ")->execute([
                $r['folio'], $clienteId,
                $ctx['usuario_id'] ?? null, $ctx['sucursal_id'] ?? 0,
                Dinero::centavos($r['total'] - $r['iva']), $r['iva'], $r['total'],
                $r['metodo'] ?: 'efectivo',
                $this->nota($r),
                $r['fecha'] . ' 12:00:00',
            ]);
            $ventaId = (int)$this->db->lastInsertId();

            // ── El renglón ──
            $this->db->prepare("
                INSERT INTO venta_detalles
                    (venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?,?,1,?,0,?)
            ")->execute([$ventaId, $productoId, $r['total'], $r['total']]);
            $detalleId = (int)$this->db->lastInsertId();

            // ── El gasto ──
            if ($r['gastos'] > 0.005) {
                $this->db->prepare("
                    INSERT INTO gastos (venta_id, concepto, monto, tipo, categoria, fecha)
                    VALUES (?,?,?,'manual','Gasto de operación',?)
                ")->execute([$ventaId,
                    'Solicitud ' . ($r['solGastos'] ?: 'de gastos'), $r['gastos'],
                    $r['fecha'] . ' 12:00:00']);
                $res['gastos']++;
            }

            // ── El cobro ──
            if ($r['anticipo'] > 0.005) {
                $tipo = abs($r['anticipo'] - $r['total']) < 0.02 ? 'liquidacion' : 'anticipo';
                $this->db->prepare("
                    INSERT INTO venta_pagos
                        (venta_id, folio, monto, tipo, metodo_pago, banco, referencia,
                         fecha_pago, cancelado, usuario_id)
                    VALUES (?,?,?,?,?,?,?,?,0,?)
                ")->execute([
                    $ventaId, Folio::formar($r['folio'], 1), $r['anticipo'], $tipo,
                    $r['metodo'] ?: 'efectivo', $r['banco'],
                    $r['recibo'] ?: null,
                    $r['fecha'] . ' 12:00:00',
                    $ctx['usuario_id'] ?? null,
                ]);
                $res['cobros']++;
            }

            // ── Las comisiones ──
            //
            // La base de LibertyFin es precio − costo − gasto. Se mapea:
            //   precio = anticipo − IVA      lo que de verdad entró
            //   costo  = comisión bancaria   porque eso es un costo
            //   gasto  = la solicitud        porque eso es un gasto
            // y el resultado es, al centavo, la base de la hoja.
            $this->insertarComisiones($ventaId, $detalleId, $r, $ctx, $res, $areaId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Fuera de la transacción: reparte lo devengado entre los cobros.
        // Si truena, la venta queda bien y lo devengado se arregla con
        // «Recalcular comisiones»; meterlo dentro tiraría la venta entera.
        try {
            (new SincronizarComisiones($this->db))->paraVenta($ventaId);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] devengar al importar venta ' . $ventaId . ': ' . $e->getMessage());
        }
    }

    /**
     * Otro cobro de una venta que ya entró.
     *
     * Lleva su propio subfolio —-02, -03— y sus propias comisiones, que
     * la hoja calcula por cobro y no por venta. Ese es justo el caso
     * para el que existen los subfolios: la clienta que deja su parte
     * cada mes.
     */
    private function abonar(array $r, array $ctx, array &$res)
    {
        $st = $this->db->prepare("SELECT id, codigo_venta, total FROM ventas WHERE codigo_venta = ? LIMIT 1");
        $st->execute([$r['folioVenta']]);
        $v = $st->fetch();
        if (!$v) {
            throw new \RuntimeException('No encontré la venta ' . $r['folioVenta']
                . ' a la que pertenece este cobro.');
        }
        $ventaId = (int)$v['id'];

        $st = $this->db->prepare("SELECT id FROM venta_detalles WHERE venta_id = ? ORDER BY id LIMIT 1");
        $st->execute([$ventaId]);
        $detalleId = (int)$st->fetchColumn();

        $this->db->beginTransaction();
        try {
            if ($r['gastos'] > 0.005) {
                $this->db->prepare("
                    INSERT INTO gastos (venta_id, concepto, monto, tipo, categoria, fecha)
                    VALUES (?,?,?,'manual','Gasto de operación',?)
                ")->execute([$ventaId, 'Solicitud ' . ($r['solGastos'] ?: 'de gastos'),
                              $r['gastos'], $r['fecha'] . ' 12:00:00']);
                $res['gastos']++;
            }

            // El número viene del análisis para que sea siempre el mismo.
            $folio = $r['folioCobro'] ?? Folio::siguiente($this->db, $ventaId, $v['codigo_venta']);
            $this->db->prepare("
                INSERT INTO venta_pagos
                    (venta_id, folio, monto, tipo, metodo_pago, banco, referencia,
                     fecha_pago, cancelado, usuario_id)
                VALUES (?,?,?,'abono',?,?,?,?,0,?)
            ")->execute([
                $ventaId, $folio, $r['anticipo'],
                $r['metodo'] ?: 'efectivo', $r['banco'], $r['recibo'] ?: null,
                $r['fecha'] . ' 12:00:00', $ctx['usuario_id'] ?? null,
            ]);
            $res['cobros']++;
            $res['abonos']++;

            // Las comisiones de ESTE cobro, con su propia base.
            $this->insertarComisiones($ventaId, $detalleId, $r, $ctx, $res);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        try {
            (new SincronizarComisiones($this->db))->paraVenta($ventaId);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] devengar abono importado: ' . $e->getMessage());
        }
    }

    private function insertarComisiones($ventaId, $detalleId, array $r, array $ctx, array &$res, $areaId = null)
    {
        if (!$r['comisiones']) return;
        if ($areaId === null) $areaId = $this->areaId($r['area'], $res);

        $precioCom = Dinero::centavos($r['anticipo'] - $r['iva']);
        $ins = $this->db->prepare("
            INSERT INTO venta_comisiones
                (venta_id, venta_detalle_id, area_id, area_nombre, regla_id, concepto,
                 colaborador_id, colaborador_nombre, porcentaje_regla, porcentaje_reparto,
                 costo_unitario, gasto_operacion, precio_unitario, descuento_linea,
                 cantidad, monto_base, monto_comision, usuario_id)
            VALUES (?,?,?,?,NULL,?,?,?,?,100.00,?,?,?,0,1,?,?,?)
        ");
        foreach ($r['comisiones'] as $c) {
            $colId = null;
            $nombre = $c['colaborador'];
            if ($nombre) {
                $colId = $this->colaboradorId($nombre, $areaId, $res);
            } else {
                // Gerencia, administración y área no son personas en la
                // hoja: son bolsas. Se guardan con el nombre del concepto
                // y SIN colaborador, para no inventar gente que no
                // existe. Se les pone persona después, desde Comisiones.
                $nombre = $c['concepto'] === 'Área' ? 'Área ' . $r['area'] : $c['concepto'];
            }
            $ins->execute([
                $ventaId, $detalleId, $areaId, $r['area'], $c['concepto'],
                $colId, $nombre, $c['porcentaje'],
                $r['banca'], $r['gastos'], $precioCom,
                $r['base'], $c['monto'],
                $ctx['usuario_id'] ?? null,
            ]);
            $res['comisiones']++;
        }
    }

    private function nota(array $r)
    {
        $n = ['Importado de hoja de cálculo (' . $r['hoja'] . ', fila ' . $r['fila'] . ')'];
        if ($r['folioOriginal'] !== $r['folio']) $n[] = 'Folio original: ' . $r['folioOriginal'];
        if ($r['forma']) $n[] = 'Forma: ' . $r['forma'];
        if ($r['banca'] > 0.005) $n[] = 'Comisión bancaria: ' . Dinero::pesos($r['banca']);
        if ($r['solGastos']) $n[] = 'Solicitud de gastos: ' . $r['solGastos'];
        return mb_substr(implode(' · ', $n), 0, 600);
    }

    // ── Catálogos ──────────────────────────────────────────

    private function clienteId($nombre, array &$res)
    {
        $id = $this->buscar('clientes', 'nombre', $nombre);
        if ($id) return $id;
        $this->db->prepare("INSERT INTO clientes (nombre, tipo, activo) VALUES (?,'normal',1)")
                 ->execute([mb_substr($nombre, 0, 255)]);
        $res['creados']['clientes']++;
        return $this->recordar('clientes', 'nombre', $nombre, $this->db->lastInsertId());
    }

    private function areaId($nombre, array &$res)
    {
        $id = $this->buscar('comision_areas', 'nombre', $nombre);
        if ($id) return $id;
        $this->db->prepare("INSERT INTO comision_areas (nombre, activo) VALUES (?,1)")
                 ->execute([mb_substr($nombre, 0, 100)]);
        $res['creados']['areas']++;
        return $this->recordar('comision_areas', 'nombre', $nombre, $this->db->lastInsertId());
    }

    private function colaboradorId($nombre, $areaId, array &$res)
    {
        $id = $this->buscar('comision_colaboradores', 'nombre', $nombre);
        if ($id) return $id;
        $this->db->prepare("INSERT INTO comision_colaboradores (nombre, area_id, activo) VALUES (?,?,1)")
                 ->execute([mb_substr($nombre, 0, 100), $areaId]);
        $res['creados']['colaboradores']++;
        return $this->recordar('comision_colaboradores', 'nombre', $nombre, $this->db->lastInsertId());
    }

    private function servicioId($area, $servicio, $precio, array &$res)
    {
        $nombre = self::nombreServicio($area, $servicio);
        $id = $this->buscar('productos', 'nombre', $nombre);
        if ($id) return $id;

        // El código tiene que ser único. Se arma del área y el servicio
        // y, si ya está tomado, se le pega un consecutivo.
        $base = 'IMP-' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '',
                self::sinAcentos($area) . substr(self::sinAcentos($servicio), 0, 10)));
        $codigo = mb_substr($base, 0, 50);
        $n = 1;
        while ($this->buscar('productos', 'codigo', $codigo)) {
            $codigo = mb_substr($base, 0, 46) . '-' . (++$n);
        }
        $this->db->prepare("
            INSERT INTO productos (codigo, nombre, precio, costo, stock, activo, tipo_producto, unidad_medida)
            VALUES (?,?,?,0,0,1,'Servicio','servicio')
        ")->execute([$codigo, mb_substr($nombre, 0, 255), max(0, (float)$precio)]);
        $res['creados']['servicios']++;
        $this->recordar('productos', 'codigo', $codigo, $this->db->lastInsertId());
        return $this->recordar('productos', 'nombre', $nombre, $this->db->lastInsertId());
    }

    /** "LEGAL · SERV. LEGALES": el área distingue dos servicios con el mismo nombre. */
    public static function nombreServicio($area, $servicio)
    {
        $a = LectorHoja::limpiar($area);
        $s = LectorHoja::limpiar($servicio) ?: 'Servicio';
        return $a ? ($a . ' · ' . $s) : $s;
    }

    // ── Cacharros ──────────────────────────────────────────

    private $cache = [];

    private function buscar($tabla, $campo, $valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') return null;
        $k = $tabla . '|' . $campo . '|' . mb_strtolower($valor);
        if (array_key_exists($k, $this->cache)) return $this->cache[$k];
        try {
            $st = $this->db->prepare("SELECT id FROM `$tabla` WHERE LOWER(`$campo`) = LOWER(?) LIMIT 1");
            $st->execute([$valor]);
            $id = $st->fetchColumn();
            return $this->cache[$k] = ($id ? (int)$id : null);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] buscar ' . $tabla . ': ' . $e->getMessage());
            return $this->cache[$k] = null;
        }
    }

    private function existe($tabla, $campo, $valor) { return $this->buscar($tabla, $campo, $valor) !== null; }

    /**
     * Apunta en la caché lo que se acaba de crear.
     *
     * SIN ESTO SE DUPLICA TODO. `buscar()` guarda también los "no
     * existe", y como la caché no se enteraba de los INSERT, la
     * segunda venta del mismo cliente volvía a leer null y lo creaba
     * otra vez. En este archivo salían 75 clientes donde hay 49, y un
     * área nueva por cada renglón.
     */
    private function recordar($tabla, $campo, $valor, $id)
    {
        $this->cache[$tabla . '|' . $campo . '|' . mb_strtolower(trim((string)$valor))] = (int)$id;
        return (int)$id;
    }

    private function cobroExiste($folio)
    {
        if (!$folio) return false;
        try {
            $st = $this->db->prepare("SELECT 1 FROM venta_pagos WHERE folio = ? LIMIT 1");
            $st->execute([$folio]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) { return false; }
    }

    private function yaExiste($folio)
    {
        try {
            $st = $this->db->prepare("SELECT 1 FROM ventas WHERE codigo_venta = ? LIMIT 1");
            $st->execute([$folio]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) { return false; }
    }

    /** Para comparar encabezados: sin acentos, sin puntos, sin espacios. */
    private static function clave($s)
    {
        $s = self::sinAcentos(LectorHoja::limpiar($s) ?? '');
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $s));
    }

    private static function sinAcentos($s)
    {
        return strtr((string)$s, [
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        ]);
    }

    /**
     * Un importe. Acepta "$1,234.56" y "1 234,56".
     *
     * Quien exporta a CSV desde Excel en español saca los importes con
     * coma decimal. Leerlos como punto convierte 1.234,56 en 1.23.
     */
    public static function numero($v)
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = trim(str_replace(["\xC2\xA0", ' ', '$'], '', (string)$v));
        if ($s === '' || $s === '-') return null;
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            // El separador decimal es el ÚLTIMO que aparece. Y el de
            // los miles se quita PRIMERO: al revés, "1.234,56" pasaba
            // por 1.234.56 y acababa en 123456 — cien veces de más.
            $s = (strrpos($s, ',') > strrpos($s, '.'))
               ? str_replace(',', '.', str_replace('.', '', $s))
               : str_replace(',', '', $s);
        } elseif (strpos($s, ',') !== false) {
            // Coma sola: decimal si deja uno o dos dígitos detrás.
            $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    /** Una fecha, venga como venga. */
    public static function fecha($v)
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) {            // serie de Excel en un CSV
            if ($v < 1 || $v > 2958465) return null;
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int)$v . ' days')->format('Y-m-d');
        }
        $s = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return $m[1] . '-' . $m[2] . '-' . $m[3];
        // dd/mm/aaaa, que es como lo escribe todo el mundo aquí.
        if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})~', $s, $m)) {
            $a = (int)$m[3]; if ($a < 100) $a += 2000;
            return sprintf('%04d-%02d-%02d', $a, (int)$m[2], (int)$m[1]);
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    /**
     * La forma de pago de la hoja, al método de LibertyFin.
     *
     * "SPEI AFIRME" y "SPEI BMX" traen el banco pegado. El método es
     * transferencia en los dos; el banco se guarda aparte, que para eso
     * existe la columna desde la migración 14.
     */
    public static function formaDePago($forma)
    {
        $f = strtoupper(self::sinAcentos(LectorHoja::limpiar($forma) ?? ''));
        if ($f === '') return [null, null];

        $banco = null;
        if (preg_match('/\b(AFIRME|BMX|BANAMEX|BBVA|BANORTE|SANTANDER|HSBC|SCOTIABANK|AZTECA|INBURSA)\b/', $f, $m)) {
            $banco = $m[1] === 'BMX' ? 'BANAMEX' : $m[1];
        }
        if (strpos($f, 'EFECTIVO') !== false)                       return ['efectivo', null];
        if (strpos($f, 'SPEI') !== false
            || strpos($f, 'TRANSFER') !== false
            || strpos($f, 'DEPOSITO') !== false)                    return ['transferencia', $banco];
        if (strpos($f, 'TARJETA') !== false
            || strpos($f, 'TDC') !== false || strpos($f, 'TDD') !== false) return ['tarjeta', $banco];
        return [null, $banco];
    }

    // ════════════════════════════════════════════════════════
    //  EL ARCHIVO DE EJEMPLO
    // ════════════════════════════════════════════════════════

    /**
     * Una hoja de ejemplo con datos inventados, para ver cómo se llena.
     *
     * Trae lo que una hoja de verdad trae y que hay que saber hacer: un
     * título encima del encabezado, dos meses en dos hojas, una venta que
     * se cobra en dos partes (el abono va en la hoja del mes siguiente con
     * el mismo folio y SIN total), IVA, comisión del banco, gastos y las
     * cuatro comisiones después de VENDEDOR. Al pie, el bloque de totales,
     * que el importador salta.
     *
     * Lleva MARCA_EJEMPLO en el título: si alguien lo sube, se revisa igual
     * que uno de verdad pero no se deja guardar. Probar el formato no debe
     * meter ventas inventadas en una empresa real.
     *
     * Los encabezados son los que COLUMNAS reconoce; si ahí cambia un
     * nombre, este archivo tiene que cambiar con él.
     */
    public static function ejemplo()
    {
        $T = Libro::TEXTO; $F = Libro::FECHA; $M = Libro::MONEDA;
        $cols = [
            ['FOLIO', $T, 17], ['FECHA', $F, 12], ['CLIENTE', $T, 34], ['ÁREA', $T, 15],
            ['SERVICIO', $T, 28], ['FORMA', $T, 22], ['COSTO TOTAL DE VENTA', $M, 15],
            ['MONTO ANTICIPO', $M, 14], ['RESTANTES', $M, 13], ['IVA', $M, 11], ['COM. BANCA', $M, 11],
            ['REAL BANCO', $M, 13], ['RECIBO PAGO', $T, 12], ['SOL. GASTOS', $T, 16], ['CANTIDAD', $M, 11],
            ['A COMISIONAR', $M, 13], ['VENDEDOR', $T, 16],
            // Las cuatro comisiones van justo después de VENDEDOR, en este
            // orden. El nombre del encabezado no importa: cuenta la posición.
            ['COM. VENDEDOR', $M, 13], ['COM. GERENTE', $M, 13], ['COM. ADMINISTRACIÓN', $M, 15], ['COM. ÁREA', $M, 11],
        ];

        // Un renglón: lo que se cobró y de qué. Lo demás se calcula igual que
        // en una hoja bien llevada: base = cobro − IVA − banco − gastos, y las
        // comisiones como porcentaje de la base (10%, 3%, 2% y 5%).
        $renglon = function ($folio, $fecha, $cliente, $area, $servicio, $forma, $total, $cobro,
                             $restante, $conIva, $pctBanco, $solGastos, $gastos, $vendedor, $recibo) {
            $iva   = $conIva ? round($cobro - $cobro / 1.16, 2) : 0;
            $banco = round($cobro * $pctBanco / 100, 2);
            $base  = round($cobro - $iva - $banco - $gastos, 2);
            return [$folio, $fecha, $cliente, $area, $servicio, $forma, $total, $cobro, $restante,
                    $iva, $banco, round($cobro - $banco, 2), $recibo, $solGastos, $gastos, $base, $vendedor,
                    round($base * .10, 2), round($base * .03, 2), round($base * .02, 2), round($base * .05, 2)];
        };
        $totales = function (array $filas) {
            $t = ['TOTAL'];
            foreach ([6, 7, 8, 9, 10, 11, 14, 15, 17, 18, 19, 20] as $i) {
                $t[$i] = round(array_sum(array_map(function ($f) use ($i) { return (float)$f[$i]; }, $filas)), 2);
            }
            return $t;
        };

        $sep = [
            $renglon('20260903101500', '2026-09-03', 'Laura Méndez Ruiz', 'CONTABILIDAD', 'Declaración anual',
                     'EFECTIVO', 1160, 1160, 0, true, 0, '', 0, 'Ana Torres', 'R-0901'),
            // Esta se cobra en dos partes: la mitad hoy y el resto en octubre.
            $renglon('20260908121000', '2026-09-08', 'Comercializadora Sol del Norte SA de CV', 'LEGALES',
                     'Constitución de empresa', 'TRANSFERENCIA BBVA', 9280, 4640, 4640, true, 0,
                     'Notaría', 1500, 'Luis Ramírez', 'R-0902'),
            $renglon('20260915170500', '2026-09-15', 'Pedro Salinas Vega', 'MARKETING', 'Logo y manual de marca',
                     'TARJETA BANORTE', 3480, 3480, 0, true, 3, '', 0, 'Ana Torres', 'R-0903'),
            $renglon('20260922093000', '2026-09-22', 'Laura Méndez Ruiz', 'CONTABILIDAD', 'Contabilidad mensual',
                     'EFECTIVO', 1500, 1500, 0, false, 0, '', 0, 'Sofía Herrera', 'R-0904'),
        ];
        $oct = [
            $renglon('20261002110000', '2026-10-02', 'Grupo Ferretero Alameda', 'CONTABILIDAD', 'Contabilidad mensual',
                     'SPEI BANORTE', 2320, 2320, 0, true, 0, '', 0, 'Sofía Herrera', 'R-1001'),
            // EL ABONO: mismo folio que la de septiembre, total VACÍO. Así se
            // sabe que es otro cobro de esa venta y no una venta nueva.
            $renglon('20260908121000', '2026-10-06', 'Comercializadora Sol del Norte SA de CV', 'LEGALES',
                     'Constitución de empresa', 'TRANSFERENCIA BBVA', '', 4640, 0, true, 0, '', 0,
                     'Luis Ramírez', 'R-1002'),
            $renglon('20261014160000', '2026-10-14', 'Pedro Salinas Vega', 'MARKETING', 'Campaña en redes (1 mes)',
                     'EFECTIVO', 4500, 2000, 2500, false, 0, 'Pauta en Meta', 800, 'Ana Torres', 'R-1003'),
            $renglon('20261020130000', '2026-10-20', 'Mariana Ortiz Lugo', 'LEGALES', 'Registro de marca',
                     'TARJETA BBVA', 5800, 5800, 0, true, 3, 'Derechos IMPI', 2800, 'Luis Ramírez', 'R-1004'),
        ];

        $sub = 'Datos inventados para ver el formato. Cada renglón es un cobro; si un folio se repite '
             . 'SIN total, es otro abono de esa venta. Si lo subes a Importar se revisa, pero no se guarda.';
        $l = new Libro();
        $l->hoja('Septiembre', $cols, $sep, ['titulo' => 'PAGOS DE CLIENTES · SEPTIEMBRE 2026 · ' . self::MARCA_EJEMPLO,
                                              'subtitulo' => $sub, 'totales' => $totales($sep)]);
        $l->hoja('Octubre', $cols, $oct, ['titulo' => 'PAGOS DE CLIENTES · OCTUBRE 2026 · ' . self::MARCA_EJEMPLO,
                                           'subtitulo' => $sub, 'totales' => $totales($oct)]);
        return $l;
    }
}
