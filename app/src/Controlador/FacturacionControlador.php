<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Facturapi;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Facturación CFDI.
 *
 * La pantalla solo existe si hay credenciales de Facturapi, igual que
 * Recargas con Emida. Y aunque existan, timbrar necesita tres cosas más
 * que el negocio tiene que haber llenado: sus datos fiscales, los del
 * cliente, y que la venta esté cobrada — o, si no lo está, que se emita
 * como PPD y se manden complementos de pago conforme se cobre.
 */
final class FacturacionControlador
{
    private const USOS_VALIDOS = [
        'G01' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'G02' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'G03' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I01' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I02' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I03' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I04' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I05' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I06' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I07' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I08' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'D01' => ['605','606','607','608','611','612','614','615','625'],
        'D02' => ['605','606','607','608','611','612','614','615','625'],
        'D03' => ['605','606','607','608','611','612','614','615','625'],
        'D04' => ['605','606','607','608','611','612','614','615','625'],
        'D05' => ['605','606','607','608','611','612','614','615','625'],
        'D06' => ['605','606','607','608','611','612','614','615','625'],
        'D07' => ['605','606','607','608','611','612','614','615','625'],
        'D08' => ['605','606','607','608','611','612','614','615','625'],
        'D09' => ['605','606','607','608','611','612','614','615','625'],
        'D10' => ['605','606','607','608','611','612','614','615','625'],
    ];

    private const TOLERANCIA      = 0.05;

    /**
     * Listado principal de facturación.
     */
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $cfg  = new ConfigRepo($db);
        $repo = new VentaRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $fiscales = [];
        foreach (['tipo_persona','rfc_fiscal','cp_fiscal','razon_social','regimen_sat'] as $c) {
            $fiscales[$c] = $cfg->valorDe('fiscal.' . $c, '');
        }
        $faltan = [];
        if (trim($fiscales['rfc_fiscal']) === '')   $faltan[] = 'tu RFC';
        if (trim($fiscales['cp_fiscal']) === '')    $faltan[] = 'tu código postal fiscal';
        if (trim($fiscales['regimen_sat']) === '')  $faltan[] = 'tu régimen fiscal';
        if (trim($fiscales['razon_social']) === '') $faltan[] = 'tu razón social';

        $cuenta = new CuentaRepo($db);
        $docs   = $cuenta->estadoDocumentacion();

        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        $totalV = $repo->cuantas($desde, $hasta, []);
        $cfgFac = Integraciones::de('facturapi');

        Plantilla::pagina('facturacion/index', [
            'titulo'     => 'Facturación',
            'icono'      => 'serv',
            'subtitulo'  => Fechas::rotulo($desde, $hasta),
            'sandbox'    => !empty($cfgFac['sandbox']),
            'faltan'     => $faltan,
            'docs'       => $docs,
            'ventas'     => $repo->listado($desde, $hasta, [], $porPag, ($pagina-1)*$porPag),
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($totalV / $porPag)),
            'totalV'     => $totalV,
            'desde'      => $desde, 'hasta' => $hasta,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

  /**
     * Pantalla de complementos de pago de una venta PPD.
     *
     * Lista los abonos no cancelados y, por cada uno, si ya tiene
     * complemento timbrado o si se puede emitir ahora.
     */
    public function pagos($id)
    {
        $id   = (int) $id;
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            $this->aviso('Venta no encontrada.', 'error');
        }

        $cfgFac = Integraciones::de('facturapi');

        Plantilla::pagina('facturacion/pagos', [
            'titulo'    => 'Complementos de pago',
            'icono'     => 'serv',
            'subtitulo' => $venta['codigo_venta'] . ' · '
                         . ($venta['cliente'] ?: 'Público general'),
            'sandbox'   => !empty($cfgFac['sandbox']),
            'venta'     => $venta,
            'pagos'     => $repo->pagosConComplemento($id),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Timbra la factura de una venta.
     *
     * Reglas:
     *   - Venta liquidada al 100% -> PUE (Payment_form real, método PUE).
     *   - Venta con saldo         -> PPD con forma "99 Por definir". El
     *     SAT entonces espera complementos de pago por cada abono.
     *
     * Sobre el IVA: se detecta si `venta_detalles.precio_unitario` trae el
     * IVA dentro comparando la suma de las líneas contra `ventas.total`.
     */
    public function timbrar($id)
    {
        $id   = (int) $id;
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);
        $cfg  = new ConfigRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            $this->aviso('Venta no encontrada.', 'error');
        }

        $usoOverride = strtoupper(trim((string) Peticion::texto('uso', '')));

        // ── Candados ────────────────────────────────────────────────────
        if (empty($venta['cliente_id'])) {
            $this->aviso('No se puede facturar: la venta no tiene cliente. '
                       . 'El CFDI necesita el RFC de quien recibe.', 'error');
        }
        if (!empty($venta['factura_uuid'])) {
            $this->aviso('Esta venta ya fue facturada.', 'error');
        }

        $docs = (new CuentaRepo($db))->estadoDocumentacion();
        if (($docs['estado'] ?? '') !== 'aprobada') {
            $this->aviso('La documentación del negocio todavía no está aprobada.', 'error');
        }

        // PUE vs PPD según el saldo. Con saldo -> PPD.
        $saldo = round((float) $venta['saldo'], 2);
        $esPpd = $saldo > 0.01;

        // ── Credenciales del PAC ────────────────────────────────────────
        $fac = Integraciones::de('facturapi');
        if (empty($fac['activo'])) {
            $this->aviso('La facturación con Facturapi está apagada. Actívala en '
                       . 'config/integraciones.php (`activo => true`).', 'error');
        }
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            $nombre = $sandbox ? 'llave_prueba (o llave)' : 'llave';
            $this->aviso("No hay credenciales de Facturapi configuradas. "
                       . "Llena `$nombre` en config/integraciones.php.", 'error');
        }

        // ── Datos fiscales del emisor ───────────────────────────────────
        $emisor = [
            'rfc'     => strtoupper(trim((string) $cfg->valorDe('fiscal.rfc_fiscal', ''))),
            'razon'   => strtoupper(trim((string) $cfg->valorDe('fiscal.razon_social', ''))),
            'regimen' => trim((string) $cfg->valorDe('fiscal.regimen_sat', '')),
            'cp'      => trim((string) $cfg->valorDe('fiscal.cp_fiscal', '')),
        ];
        foreach ($emisor as $campo => $valor) {
            if ($valor === '') {
                $this->aviso("Faltan los datos fiscales del negocio ($campo). "
                           . "Complétalos en Mi cuenta.", 'error');
            }
        }

        // ── Datos del receptor ──────────────────────────────────────────
        $r = $repo->datosFiscalesCliente((int) $venta['cliente_id']);
        if (!$r || trim((string) $r['rfc']) === '') {
            $this->aviso('El cliente no tiene RFC capturado. El CFDI 4.0 lo exige.', 'error');
        }
        if (trim((string) $r['cp']) === '') {
            $this->aviso('El cliente no tiene código postal fiscal. El CFDI 4.0 lo exige.', 'error');
        }

        // ── Uso CFDI compatible con el régimen ──────────────────────────
        [$usoCfdi, $ajusteUso] = $this->usoValido(
            $usoOverride !== '' ? $usoOverride : (string) ($r['uso_cfdi'] ?? ''),
            (string) ($r['regimen'] ?? ''),
            (string) $r['rfc']
        );
        if ($usoOverride !== '' && $usoCfdi !== $usoOverride) {
            $ajusteUso = "el uso $usoOverride no aplica con el régimen "
                       . trim((string) $r['regimen']) . "; se usó $usoCfdi";
        }

        $regimenReceptor = trim((string) ($r['regimen'] ?: '616'));

         // ── Conceptos ───────────────────────────────────────────────────
        $lineas = $repo->lineas($id);
        if (!$lineas) {
            $this->aviso('La venta no tiene productos capturados: nada que facturar.', 'error');
        }

        $total = (float) $venta['total'];

        $sumLineas = 0.0;
        foreach ($lineas as $ln) {
            $sumLineas += ((float) $ln['cantidad'] * (float) $ln['precio_unitario'])
                        - (float) $ln['descuento']
                        - (float) ($ln['descuento_promocion'] ?? 0);
        }

        if (abs($sumLineas - $total) <= self::TOLERANCIA) {
            $divisor = 1.16;
        } elseif (abs($sumLineas * 1.16 - $total) <= self::TOLERANCIA) {
            $divisor = 1.0;
        } else {
            $this->aviso(
                'Las líneas de la venta no cuadran con el total '
                . '(líneas: ' . number_format($sumLineas, 2)
                . ', total: ' . number_format($total, 2) . '). No se timbró.',
                'error'
            );
        }

        $items       = [];
        $sumSubtotal = 0.0;

        foreach ($lineas as $ln) {
            $cant = (float) $ln['cantidad'];
            if ($cant <= 0) continue;

            $pu   = round(((float) $ln['precio_unitario']) / $divisor, 6);
            // `descuento_promocion` no existe en todas las bases: la promoción ya va en `descuento`.
            $desc = round((((float) $ln['descuento']) + ((float) ($ln['descuento_promocion'] ?? 0))) / $divisor, 6);

            $descripcion = trim((string) ($ln['producto'] ?? ''));
            if ($descripcion === '') {
                $descripcion = 'Producto ' . (string) ($ln['producto_id'] ?? '');
            }

            $claveSat = trim((string) ($ln['clave_sat'] ?? ''));
            if ($claveSat === '') {
                $this->aviso('«' . $descripcion . '» no tiene clave SAT. '
                           . 'Captúrala en Productos para poder facturar.', 'error');
            }

            $item = [
                'quantity' => $cant,
                'product'  => [
                    'description'  => $descripcion,
                    'product_key'  => $claveSat,
                    'unit_key'     => $this->unidadSat($ln['unidad_medida'] ?? null),
                    'price'        => $pu,
                    'tax_included' => false,
                    'taxes'        => [['type' => 'IVA', 'rate' => 0.16]],
                ],
            ];

            if ($desc > 0.005) $item['discount'] = $desc;
            if (!empty($ln['codigo'])) $item['product']['sku'] = (string) $ln['codigo'];

            $items[]      = $item;
            $sumSubtotal += ($cant * $pu) - $desc;
        }

        if (!$items) {
            $this->aviso('La venta no tiene líneas facturables.', 'error');
        }

        $subtotal   = round($sumSubtotal, 2);
        $cpReceptor = trim((string) $r['cp']);

        // ── Candado final: el CFDI debe sumar lo mismo que se cobró ─────
        $totalCfdi = round($subtotal * 1.16, 2);
        if (abs($totalCfdi - $total) > self::TOLERANCIA) {
            $this->aviso(
                'El CFDI calculado (' . number_format($totalCfdi, 2)
                . ') no coincide con el total de la venta (' . number_format($total, 2)
                . '). No se timbró.',
                'error'
            );
        }

        $address = ['zip' => $cpReceptor, 'country' => 'MEX'];
        if (!empty($r['calle_numero']))     $address['street']       = trim($r['calle_numero']);
        if (!empty($r['numero_interior']))  $address['exterior']     = trim($r['numero_interior']);
        if (!empty($r['colonia']))          $address['neighborhood'] = trim($r['colonia']);
        if (!empty($r['ciudad']))           $address['city']         = trim($r['ciudad']);
        if (!empty($r['estado_direccion'])) $address['state']        = trim($r['estado_direccion']);

        $payload = [
            'customer' => [
                'legal_name' => strtoupper(trim((string) ($r['razon_social'] ?: $venta['cliente']))),
                'tax_id'     => strtoupper(trim((string) $r['rfc'])),
                'tax_system' => $regimenReceptor,
                'address'    => $address,
                'email'      => !empty($r['email']) ? $r['email'] : null,
            ],
            'items'          => $items,
            'use'            => $usoCfdi,
            'payment_form'   => $esPpd
                ? '99'
                : $this->formaPagoSat((string) ($venta['metodo_pago'] ?? '')),
            'payment_method' => $esPpd ? 'PPD' : 'PUE',
        ];

        // ── Envío al PAC ────────────────────────────────────────────────
        $cli = new Facturapi($clave, (string) ($fac['organizacion'] ?? ''));
        $res = $cli->crearFactura($payload);

        if ($res['error'] !== '') {
            error_log("Facturapi cURL (venta {$id}): " . $res['error']);
            $this->aviso('Error de red al contactar al PAC. Intenta de nuevo.', 'error');
        }
        if ($res['http'] < 200 || $res['http'] >= 300 || empty($res['json']['id'])) {
            $msg = $res['json']['message'] ?? 'Error desconocido al timbrar.';
            error_log("Facturapi HTTP {$res['http']} (venta {$id}): " . $res['body']);
            $this->aviso('El PAC rechazó el timbrado: ' . $msg, 'error');
        }

        // ── Persistencia ────────────────────────────────────────────────
        $d = $res['json'];

        $totalPac = isset($d['total']) && is_numeric($d['total'])
            ? round((float) $d['total'], 2)
            : $total;
        $alertaTotal = '';
        if (abs($totalPac - $total) > self::TOLERANCIA) {
            error_log("ALERTA: CFDI de la venta {$id} timbrado por $totalPac "
                    . "pero la venta es por $total (UUID " . ($d['uuid'] ?? '?') . ").");
            $alertaTotal = ' ATENCIÓN: el PAC timbró ' . number_format($totalPac, 2)
                         . ' pero la venta es de ' . number_format($total, 2)
                         . '. Cancela y vuelve a timbrar.';
        }
        $subtotalPac = round($totalPac / 1.16, 2);
        $ivaPac      = round($totalPac - $subtotalPac, 2);

        $repo->marcarFacturada($id, [
            'facturapi_id'           => (string) $d['id'],
            'factura_uuid'           => (string) ($d['uuid'] ?? ''),
            'factura_folio'          => (string) ($d['folio_number'] ?? ''),
            'urlfacturacion'         => (string) ($d['verification_url'] ?? ''),
            'factura_fecha_timbrado' => (string) ($d['created_at'] ?? date('Y-m-d H:i:s')),
            'factura_subtotal'       => $subtotalPac,
            'factura_iva'            => $ivaPac,
            'factura_total'          => $totalPac,
            'factura_rfc_receptor'   => $payload['customer']['tax_id'],
            'factura_razon_social'   => $payload['customer']['legal_name'],
            'factura_uso_cfdi'       => $usoCfdi,
            'factura_metodo_pago'    => $esPpd ? 'PPD' : 'PUE',
            'factura_forma_pago'     => $payload['payment_form'],
            'factura_cp_receptor'    => $cpReceptor,
            'factura_email_receptor' => $payload['customer']['email'] ?? null,
        ]);

        try {
            $repo->guardarFiscalesCliente((int) $venta['cliente_id'], [
                'rfc'          => $payload['customer']['tax_id'],
                'razon_social' => $payload['customer']['legal_name'],
                'regimen'      => $payload['customer']['tax_system'],
                'uso_cfdi'     => $usoCfdi,
                'cp'           => $cpReceptor,
            ]);
        } catch (\Throwable $e) {
            error_log("No se pudieron guardar fiscales del cliente "
                    . "{$venta['cliente_id']}: " . $e->getMessage());
        }

        $extra = $ajusteUso !== null
            ? " Se ajustó el uso CFDI ($ajusteUso). Corrige el régimen/uso del cliente."
            : '';
        $ppd = $esPpd
            ? ' Se emitió como PPD: emite el complemento de pago por cada abono.'
            : '';
        $this->aviso(
            'Venta facturada' . ($sandbox ? ' (PRUEBAS, sin validez fiscal)' : '')
            . '. UUID: ' . ($d['uuid'] ?? 'pendiente') . $ppd . $extra . $alertaTotal,
            $alertaTotal === '' ? 'ok' : 'error'
        );
    }

    /**
     * Emite el complemento de pago (CFDI tipo "P") de UN abono.
     *
     * Requisitos:
     *   - La venta debe estar facturada como PPD (el complemento se liga
     *     al UUID de esa factura; sin ella no hay a qué referenciarlo).
     *   - El pago no puede estar cancelado ni tener ya un complemento.
     *
     * El SAT exige tres cosas explícitas por cada parcialidad: cuánto se
     * cobró, qué saldo había ANTES y qué saldo queda DESPUÉS. Se calculan
     * sumando los pagos anteriores (por fecha y luego por id) en vez de
     * leer un cache: si se emite el complemento de un pago viejo hay que
     * reflejar el estado de entonces, no el de hoy.
     */
    public function timbrarComplemento($ventaId, $pagoId)
    {
        $ventaId = (int) $ventaId;
        $pagoId  = (int) $pagoId;

        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($ventaId);
        if (!$venta) {
            $this->aviso('Venta no encontrada.', 'error');
        }
        if (empty($venta['factura_uuid'])) {
            $this->aviso('Primero hay que facturar la venta como PPD. '
                       . 'El complemento se liga al folio fiscal de esa factura.', 'error');
        }
        if (($venta['factura_metodo_pago'] ?? '') !== 'PPD') {
            $this->aviso('Esta venta se facturó PUE: no admite complementos de pago.', 'error');
        }

        $pago = $repo->pago($pagoId);
        if (!$pago || (int) $pago['venta_id'] !== $ventaId) {
            $this->aviso('Pago no encontrado.', 'error');
        }
        if (!empty($pago['cancelado'])) {
            $this->aviso('Este pago está cancelado: no se puede complementar.', 'error');
        }
        if (!empty($pago['cfdi_complemento_uuid'])) {
            $this->aviso('Este pago ya tiene su complemento: '
                       . $pago['cfdi_complemento_uuid'], 'error');
        }

        // ── Credenciales del PAC ───────────────────────────────────────
        $fac = Integraciones::de('facturapi');
        if (empty($fac['activo'])) {
            $this->aviso('Facturapi está apagado.', 'error');
        }
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            $this->aviso('Facturapi sin credenciales configuradas.', 'error');
        }

        // ── Receptor: mismos datos fiscales que la factura PPD ────────
        $r = $repo->datosFiscalesCliente((int) $venta['cliente_id']);
        if (!$r || trim((string) $r['rfc']) === '') {
            $this->aviso('El cliente no tiene RFC capturado.', 'error');
        }
        if (trim((string) $r['cp']) === '') {
            $this->aviso('El cliente no tiene código postal fiscal.', 'error');
        }
        $regimenReceptor = trim((string) ($r['regimen'] ?: '616'));

        // ── Saldos ANTES / DESPUÉS de este abono ───────────────────────
        $fechaPago    = (string) $pago['fecha_pago'];
        $fechaHora    = (string) ($pago['creado_en'] ?: ($fechaPago . ' 00:00:00'));
        $pagadoAntes  = $repo->pagadoAntesDe($ventaId, $pagoId, $fechaPago);
        $parcialidad  = $repo->parcialidadDe($ventaId, $pagoId, $fechaPago);
        $monto        = round((float) $pago['monto'], 2);
        $saldoAntes   = round((float) $venta['total'] - $pagadoAntes, 2);
        if ($saldoAntes < 0) $saldoAntes = 0.0;
        $saldoDespues = round(max(0.0, $saldoAntes - $monto), 2);

        // El monto incluye IVA (es lo que el cliente pagó): se desglosa.
        $base = round($monto / 1.16, 2);

        // ── Dirección del receptor ─────────────────────────────────────
        $address = ['zip' => trim((string) $r['cp']), 'country' => 'MEX'];
        if (!empty($r['calle_numero']))     $address['street']       = trim($r['calle_numero']);
        if (!empty($r['numero_interior']))  $address['exterior']     = trim($r['numero_interior']);
        if (!empty($r['colonia']))          $address['neighborhood'] = trim($r['colonia']);
        if (!empty($r['ciudad']))           $address['city']         = trim($r['ciudad']);
        if (!empty($r['estado_direccion'])) $address['state']        = trim($r['estado_direccion']);

        // ── Payload tipo "P" ───────────────────────────────────────────
        // OJO: la forma exacta de `complements[].data[].related_documents[]`
        // hay que confirmarla contra el sandbox de Facturapi antes de la
        // llave real. Un campo mal nombrado aquí solo lo detecta el PAC al
        // rechazar el timbrado.
        $payload = [
            'type'     => 'P',
            'customer' => [
                'legal_name' => strtoupper(trim((string) ($r['razon_social'] ?: $venta['cliente']))),
                'tax_id'     => strtoupper(trim((string) $r['rfc'])),
                'tax_system' => $regimenReceptor,
                'address'    => $address,
                'email'      => !empty($r['email']) ? $r['email'] : null,
            ],
            'complements' => [[
                'type' => 'pago',
                'data' => [[
                    'payment_form' => $this->formaPagoSat((string) ($pago['metodo_pago'] ?? '')),
                    'date' => date('c', strtotime($fechaHora)),
                    'related_documents' => [[
                        'uuid'         => (string) $venta['factura_uuid'],
                        'amount'       => $monto,
                        'installment'  => $parcialidad,
                        'last_balance' => $saldoAntes,
                        'taxes'        => [[
                            'type'        => 'IVA',
                            'rate'        => 0.16,
                            'base'        => $base,
                            'withholding' => false,
                        ]],
                    ]],
                ]],
            ]],
        ];

        // ── Envío al PAC ──────────────────────────────────────────────
        $cli = new Facturapi($clave, (string) ($fac['organizacion'] ?? ''));
        $res = $cli->crearFactura($payload);

        if ($res['error'] !== '') {
            error_log("Facturapi cURL complemento (venta {$ventaId}, pago {$pagoId}): "
                    . $res['error']);
            $this->aviso('Error de red al contactar al PAC.', 'error');
        }
        if ($res['http'] < 200 || $res['http'] >= 300 || empty($res['json']['id'])) {
            $msg = $res['json']['message'] ?? 'Error desconocido al timbrar el complemento.';
            error_log("Facturapi HTTP {$res['http']} complemento (pago {$pagoId}): "
                    . $res['body']);
            $this->aviso('El PAC rechazó el complemento: ' . $msg, 'error');
        }

        $d = $res['json'];
        $repo->marcarComplementoPago($pagoId, [
            'id'   => (string) $d['id'],
            'uuid' => (string) ($d['uuid'] ?? ''),
        ]);

        $this->aviso(
            'Complemento de pago emitido (parcialidad ' . $parcialidad . '). '
            . 'Saldo restante: ' . number_format($saldoDespues, 2) . '.',
            'ok'
        );
    }

    /**
     * Descarga el XML o PDF de una venta ya facturada (CFDI de ingreso).
     */
    public function descargarCfdi($id)
    {
        $id = (int) $id;

        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Venta no encontrada.');
        }
        if (empty($venta['factura_uuid'])) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Esta venta no está facturada.');
        }
        $facturapiId = trim((string) ($venta['facturapi_receipt_id'] ?? ''));
        if ($facturapiId === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('La venta está marcada como facturada pero no tiene ID del PAC guardado.');
        }

        $this->soltarArchivoPac($facturapiId, 'cfdi-' . $venta['factura_uuid']);
    }

    /**
     * Descarga el XML o PDF de un complemento de pago ya timbrado.
     */
    public function descargarComplemento($pagoId)
    {
        $pagoId = (int) $pagoId;
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $pago = $repo->pago($pagoId);
        if (!$pago || empty($pago['cfdi_complemento_uuid'])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Complemento no encontrado o no timbrado.');
        }
        $facturapiId = trim((string) $pago['cfdi_complemento_id']);
        if ($facturapiId === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('El complemento no tiene id del PAC guardado.');
        }

        $this->soltarArchivoPac($facturapiId, 'complemento-' . $pago['cfdi_complemento_uuid']);
    }

    /**
     * Común a descargarCfdi() y descargarComplemento(): valida el tipo,
     * pide el archivo a Facturapi y lo suelta como attachment.
     */
    private function soltarArchivoPac(string $facturapiId, string $nombreBase): void
    {
        $tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'pdf')));
        if (!in_array($tipo, ['xml', 'pdf'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('El tipo debe ser xml o pdf.');
        }

        $fac = Integraciones::de('facturapi');
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Facturapi sin credenciales configuradas.');
        }

        $cli = new Facturapi($clave, (string) ($fac['organizacion'] ?? ''));
        $res = $cli->descargarFactura($facturapiId, $tipo);

        if ($res['error'] !== '') {
            error_log("Facturapi cURL descarga ({$facturapiId}, {$tipo}): " . $res['error']);
            http_response_code(502);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Error de red al contactar al PAC.');
        }
        if ($res['http'] !== 200) {
            error_log("Facturapi HTTP {$res['http']} descarga ({$facturapiId}, {$tipo}): "
                    . substr($res['body'], 0, 500));
            http_response_code(
                $res['http'] >= 400 && $res['http'] < 600 ? $res['http'] : 502
            );
            header('Content-Type: text/plain; charset=UTF-8');
            $msg = $res['json']['message']
                 ?? "El PAC rechazó la descarga (HTTP {$res['http']}).";
            exit($msg);
        }

        $mime = $tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=UTF-8';
        $name = $nombreBase . '.' . $tipo;

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($res['body']));
        header('Cache-Control: no-cache, must-revalidate');
        echo $res['body'];
        exit;
    }

    /**
     * Traduce `productos.unidad_medida` a la clave SAT del catálogo
     * c_ClaveUnidad.
     *
     * El negocio solo captura tres valores: pieza, kilo y litro. Cualquier
     * otra cosa (vacío, texto desconocido) cae a H87 (Pieza), que es la
     * clave comodín aceptada por el SAT.
     */
    private function unidadSat(?string $unidadProducto): string
    {
        $u = strtolower(trim((string) $unidadProducto));

        static $mapa = [
            'pieza' => 'H87',   // Pieza
            'kilo'  => 'KGM',   // Kilogramo
            'litro' => 'LTR',   // Litro
        ];

        return $mapa[$u] ?? 'H87';
    }

    /**
     * Devuelve un uso CFDI válido para el régimen del receptor.
     */
    private function usoValido(string $uso, string $regimen, string $rfc): array
    {
        $uso     = strtoupper(trim($uso));
        $regimen = trim($regimen);
        $rfc     = strtoupper(trim($rfc));

        if ($rfc === 'XAXX010101000' || $rfc === 'XEXX010101000' || $regimen === '616') {
            $motivo = ($uso !== '' && $uso !== 'S01') ? 'régimen 616 exige S01' : null;
            return ['S01', $motivo];
        }

        if ($regimen === '605') {
            if (preg_match('/^D(0[1-9]|10)$/', $uso)) {
                return [$uso, null];
            }
            $motivo = ($uso === '' || $uso === 'S01')
                ? null
                : "régimen 605 solo admite D01-D10 o S01";
            return ['S01', $motivo];
        }

        if ($uso === '') {
            return ['G03', 'el cliente no tenía uso CFDI capturado'];
        }

        $validos = self::USOS_VALIDOS[$uso] ?? null;
        if ($validos === null) {
            return in_array($regimen, self::USOS_VALIDOS['G03'], true)
                ? ['G03', "el uso $uso no aplica a este tipo de comprobante"]
                : ['S01', "el uso $uso no es válido para el régimen $regimen"];
        }
        if (!in_array($regimen, $validos, true)) {
            $alt = in_array($regimen, self::USOS_VALIDOS['G03'], true) ? 'G03' : 'S01';
            return [$alt, "el uso $uso no es válido con el régimen $regimen"];
        }
        return [$uso, null];
    }

    /**
     * Catálogo c_FormaPago del SAT, desde el enum `ventas.metodo_pago`.
     * También lo usa el complemento para el campo del abono.
     */
    private function formaPagoSat(string $metodoVenta): string
    {
        return match (strtolower(trim($metodoVenta))) {
            'efectivo', 'efectivoref'      => '01',
            'cheque'                       => '02',
            'transferencia', 'spei', 'paypal' => '03',
            'tarjeta', 'tc', 'cai'         => '04',
            default                        => '03',
        };
    }

    private function aviso(string $texto, string $tipo): void
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /facturacion');
        exit;
    }
}