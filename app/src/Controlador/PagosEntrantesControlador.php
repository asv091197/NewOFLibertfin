<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\LigaRepo;
use LibertyFin\Datos\RutaLigaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\RegistrarPago;

/**
 * Los servicios que PAGA DE TODO nos llama a nosotros.
 *
 * ESTA ES LA MITAD QUE FALTABA
 *
 * La integración tiene dos direcciones y solo estaba escrita una:
 *
 *   nosotros → ellos    generar la liga, la CLABE o la referencia
 *   ellos → nosotros    "este cliente está pagando, ¿lo autorizo?"
 *                       "ya pagó, aquí está el folio"
 *                       "hubo un problema, cancélalo"
 *
 * Sin esta mitad, de los cuatro métodos de cobro solo la tarjeta podía
 * llegar a confirmarse —porque esa sí tiene servicio de consulta—. SPEI
 * y efectivo en tienda se quedaban girando en "esperando el depósito"
 * para siempre, aunque el cliente ya hubiera pagado.
 *
 * El código viejo lo tenía al revés: consultaba `/Service/ConsultaReferencia`
 * como si fuera del proveedor. Esa dirección es ESTA, la nuestra.
 *
 * LAS SEIS PUERTAS
 *
 *   GET  consulta-referencia?r=   ¿esta referencia es válida y cuánto debe?
 *   POST pago-referencia          pagaron en la tienda, autorízalo
 *   GET  consulta-clabe?r=        ¿esta CLABE es válida y cuánto debe?
 *   POST pago-clabe               llegó el SPEI, autorízalo
 *   POST cancela-pago             deshaz el último pago
 *   POST pago-liga                aviso de la liga de tarjeta
 *
 * CÓMO SE PROTEGEN
 *
 * El proveedor no manda credenciales: pega a la URL que le diste de
 * alta. Por eso la URL lleva un secreto dentro, que vive en
 * config/integraciones.php y no en el repositorio. Sin él, cualquiera
 * que adivine una referencia podría marcar ventas como pagadas
 * escribiendo una dirección en el navegador.
 *
 * REGLAS QUE NO SE NEGOCIAN
 *
 *   1. Responder SIEMPRE HTTP 200 con el JSON que la documentación pide.
 *      Un 500 hace que el proveedor dé el pago por fallido y lo cancele
 *      con el cliente ya pagado y el ticket en la mano.
 *   2. Idempotencia. El proveedor reintenta. El mismo número de
 *      transacción no puede abonar dos veces.
 *   3. Los montos viajan en CENTAVOS, como entero.
 *
 * PAGOS DE PLAN
 *
 * Desde que existe la pestaña Plan, hay ligas que NO cuelgan de una
 * venta: cuelgan de un `pagos_plan` (base principal). Esas llegan aquí
 * igual —el proveedor no distingue— y se reconocen porque traen
 * `venta_id` en NULL. Cuando el aviso confirma el cobro, además de
 * marcar la liga hay que aprobar el pago del plan: `pagos_plan` no se
 * entera solo, y sin eso el plan se queda en `por_pagar` aunque el
 * dinero ya haya entrado. De eso se encarga `aprobarPlanSiAplica()`.
 */
final class PagosEntrantesControlador
{
    /** Los códigos de la documentación. Son los que el proveedor entiende. */
    const OK            = 0;    // operación exitosa
    const SIN_ADEUDO    = 13;   // ya está pagada
    const SIN_VIGENCIA  = 14;   // se le pasó la fecha
    const MAL_FORMATO   = 15;   // la referencia no tiene forma de referencia
    const MONTO_INVALIDO= 30;   // el importe no corresponde
    const NO_RECONOCIDA = 40;   // no sabemos qué es esto
    const ERROR_SISTEMA = 50;   // reventó algo de nuestro lado
    const FUERA_PERIODO = 60;   // la cancelación llegó tarde

    /**
     * ¿El aviso llegó con `secreto_webhook_prueba`? Solo se anota en el
     * registro.
     *
     * NO SE RECHAZA NINGÚN AVISO POR AMBIENTE. Se probó: el proveedor
     * registra UN juego de direcciones por cuenta (la pestaña EndPoint del
     * panel), así que pagos reales y del emulador llegan por la misma
     * puerta y con el mismo secreto. Rechazar por "ambiente" rebotaba
     * depósitos reales. La separación de verdad está en las credenciales
     * con que se genera el cobro (LigaPago::bloqueoSandbox).
     */
    private $avisoDePrueba = false;

    /**
     * LAS DIRECCIONES DEL SISTEMA ANTERIOR.
     *
     * La cuenta de PRODUCCIÓN de Paga de Todo sigue avisando a las rutas
     * que dejó dadas de alta el sistema viejo (liber/.htaccess). Sin
     * ellas el aviso caía al login, el proveedor no recibía su JSON y
     * rebotaba el SPEI sin dejar una sola línea en el registro.
     *
     * No llevan secreto: las protege la IP de origen (IPS_PROVEEDOR).
     * Cuando el proveedor cambie sus direcciones a /pagadetodo/SECRETO/...
     * se pueden quitar de aquí y de public/index.php.
     */
    const RUTAS_ANTERIORES = [
        '/Service/ConsultaClabe',      '/Service/PagoClabe',
        '/Service/ConsultaReferencia', '/Service/PagoReferencia',
        '/Service/PagoLinea',          '/Service/EntregarPagoLiga',
        '/Service/CancelaPago',
    ];

    /**
     * Desde dónde llama Paga de Todo. La lista la mandó el proveedor.
     * Si agrega una, va en `ips_proveedor` de config/integraciones.php,
     * sin tocar código.
     */
    const IPS_PROVEEDOR = [
        '208.98.35.191',  '13.86.1.59',     '13.89.114.191',  '52.141.210.15',
        '52.141.212.172', '52.154.152.87',  '52.154.153.183', '52.154.154.122',
        '52.154.154.185', '52.154.154.232', '52.154.154.248', '52.154.154.66',
        '52.154.154.73',  '52.173.139.138', '52.173.140.232', '52.173.141.204',
        '52.173.141.231', '52.176.61.128',  '52.242.208.16',
    ];

    /**
     * ¿Se rechaza este pago porque la liga se creó en modo Sandbox?
     *
     * EN SANDBOX NO ENTRA DINERO REAL.
     *
     * El Sandbox de Paga de Todo genera CLABEs que aceptan transferencias
     * reales, y su aviso es idéntico al del emulador: misma IP, misma
     * dirección, mismos campos. No hay forma de distinguirlos. Lo que sí
     * se sabe es cómo se creó la liga (`pruebas`, que se guarda al
     * generarla), así que en una liga de prueba se rechaza TODO pago. En
     * SPEI eso hace que el proveedor cancele la transferencia y el
     * cliente reciba su dinero de vuelta.
     *
     * Para probar con el emulador: `aceptar_pagos_prueba` => true en
     * config/integraciones.php, y de vuelta a false al terminar. Las
     * ligas de producción (`pruebas` = 0) nunca pasan por aquí.
     */
    private function rechazaPrueba(array $l)
    {
        if (empty($l['pruebas'])) return false;
        $cfg = Integraciones::de('spei') ?: [];
        if (!empty($cfg['aceptar_pagos_prueba'])) return false;

        error_log('[LibertyFin] pagadetodo RECHAZA ' . ($l['referencia'] ?? '?')
            . ' · la liga se creó en modo Sandbox y no se aceptan pagos en ligas de prueba: '
            . 'si era una transferencia real, el proveedor se la regresa al cliente. Para '
            . 'probar con el emulador pon `aceptar_pagos_prueba` => true en config/integraciones.php.');
        return true;
    }

    public static function esRutaAnterior($ruta)
    {
        return in_array('/' . trim((string)$ruta, '/'), self::RUTAS_ANTERIORES, true);
    }

    /** Las IPs del proveedor: las de fábrica más las de la configuración. */
    private static function ipsProveedor(array $cfg)
    {
        $propias = $cfg['ips_proveedor'] ?? [];
        if (is_string($propias)) $propias = preg_split('/[\s,]+/', $propias, -1, PREG_SPLIT_NO_EMPTY);
        return array_merge(self::IPS_PROVEEDOR, array_map('trim', (array)$propias));
    }

    // ════════════════════════════════════════════════════════════
    //  CONSULTA · ¿es válida y cuánto debe?
    // ════════════════════════════════════════════════════════════

    /** Pago en tienda: el cajero del OXXO escaneó el código. */
    public function consultaReferencia() { $this->consultar('referencia'); }

    /** SPEI: el banco va a depositar a la CLABE. */
    public function consultaClabe() { $this->consultar('clabe'); }

    private function consultar($campo)
    {
        $this->abrirPuerta();

        $valor = trim((string)($_GET['r'] ?? $_GET['referencia'] ?? $_GET['clabe'] ?? ''));
        $limpio = preg_replace('/\D/', '', $valor);
        if ($limpio === '' || strlen($limpio) > 30) {
            $this->consultaJson(self::MAL_FORMATO,
                'Referencia con error de formato', 0, $valor, '', $campo);
        }

        $hallado = $this->ubicar($limpio);
        if (!$hallado) {
            $this->consultaJson(self::NO_RECONOCIDA,
                'Adquiriente inválido', 0, $limpio, '', $campo);
        }
        list($db, $l) = $hallado;
        if ($this->rechazaPrueba($l)) {
            $this->consultaJson(self::NO_RECONOCIDA,
                'Adquiriente inválido', 0, $limpio, '', $campo);
        }

        if ($l['estado'] === 'pagada') {
            $this->consultaJson(self::SIN_ADEUDO,
                'Referencia sin adeudo', 0, $limpio, '', $campo);
        }
        if (!empty($l['vence']) && strtotime($l['vence']) < strtotime('today')) {
            $this->consultaJson(self::SIN_VIGENCIA,
                'Referencia fuera de vigencia', 0, $limpio, '', $campo);
        }

        // LO QUE SE COBRA ES EL SALDO DE LA VENTA, no el monto que se
        // guardó al generar el cobro. Entre una cosa y otra el cliente
        // pudo haber abonado en el mostrador, y cobrarle otra vez el
        // total es la forma más rápida de tener que devolver dinero.
        $saldo = $this->saldoDe($db, $l);
        if ($saldo <= 0.009) {
            $this->consultaJson(self::SIN_ADEUDO,
                'Referencia sin adeudo', 0, $limpio, '', $campo);
        }

        $this->consultaJson(self::OK, 'Operación exitosa',
            (int)round($saldo * 100), $limpio, (string)$this->siguienteTransaccion($l), $campo);
    }

    /**
     * La respuesta de consulta, con los seis campos que pide la doc.
     *
     * `parcial` va siempre en true: el emisor acepta abonos. Mandarlo en
     * false obliga al cliente a pagar el total exacto o nada.
     */
    private function consultaJson($codigo, $mensaje, $centavos, $valor, $transaccion, $campo)
    {
        $this->json([
            'codigo'      => (int)$codigo,
            'mensaje'     => (string)$mensaje,
            'monto'       => (string)(int)$centavos,
            $campo        => (string)$valor,
            'transaccion' => (string)$transaccion,
            'parcial'     => true,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    //  PAGO · ya cobraron, autorízalo
    // ════════════════════════════════════════════════════════════

    public function pagoReferencia() { $this->autorizar('referencia'); }
    public function pagoClabe()      { $this->autorizar('clabe'); }

    private function autorizar($campo)
    {
        $this->abrirPuerta();
        $d = $this->cuerpo();

        $valor = trim((string)($d['referencia'] ?? $d['clabe'] ?? ''));
        $limpio = preg_replace('/\D/', '', $valor);
        $transaccion = trim((string)($d['transaccion'] ?? ''));
        // Llega en centavos. Convertirlo mal aquí abona cien veces de más.
        $monto = round(((float)($d['monto'] ?? 0)) / 100, 2);

        if ($limpio === '') {
            $this->pagoJson(self::MAL_FORMATO, '', 'Referencia con error de formato', $transaccion);
        }

        $hallado = $this->ubicar($limpio);
        if (!$hallado) {
            $this->pagoJson(self::NO_RECONOCIDA, '', 'Adquiriente inválido', $transaccion);
        }
        list($db, $l) = $hallado;
        if ($this->rechazaPrueba($l)) {
            $this->pagoJson(self::NO_RECONOCIDA, '', 'Adquiriente inválido', $transaccion);
        }
        $repo = new LigaRepo($db);

        // IDEMPOTENCIA. El proveedor reintenta cuando no le contestamos a
        // tiempo, y si no se revisara esto la venta quedaría abonada dos
        // veces con un solo pago del cliente.
        //
        // En una venta se busca la transacción EN SUS ABONOS, no en el
        // estado de la liga: con pagos parciales la liga sigue abierta y
        // `yaEntro` (que exige `pagada`) no veía el abono anterior.
        if (!empty($l['venta_id'])
            && ($abono = $repo->abonoDeTransaccion((int)$l['venta_id'], $transaccion))) {
            $this->pagoJson(self::OK, (string)($l['autorizacion'] ?? ''),
                'Operación exitosa', $transaccion,
                date('Y-m-d', strtotime($abono['fecha_pago'])));
        }
        if ($ya = $repo->yaEntro($l['referencia'], $transaccion)) {
            // Antes de responder OK hay que asegurarse de que el pago del
            // plan también quedó aprobado en la vuelta anterior. Si por lo
            // que sea la liga quedó `pagada` pero `pagos_plan` no, esta
            // es la última oportunidad de reconciliarlo.
            $this->aprobarPlanSiAplica($l);
            $this->pagoJson(self::OK, (string)$ya['autorizacion'],
                'Operación exitosa', $transaccion,
                $ya['pagado_en'] ? date('Y-m-d', strtotime($ya['pagado_en'])) : '');
        }
        if ($l['estado'] === 'pagada') {
            $this->aprobarPlanSiAplica($l);
            $this->pagoJson(self::SIN_ADEUDO, '', 'Referencia sin adeudo', $transaccion);
        }
        if (!empty($l['vence']) && strtotime($l['vence']) < strtotime('today')) {
            $this->pagoJson(self::SIN_VIGENCIA, '', 'Referencia fuera de vigencia', $transaccion);
        }

        $saldo = $this->saldoDe($db, $l);
        if ($saldo <= 0.009) {
            $this->pagoJson(self::SIN_ADEUDO, '', 'Referencia sin adeudo', $transaccion);
        }
        // SPEI deposita lo que el cliente quiera. Si mandó de más, se
        // rechaza: aceptarlo dejaría la venta sobrepagada y RegistrarPago
        // tiraría de todos modos.
        if ($monto > $saldo + 0.009) {
            $this->pagoJson(self::MONTO_INVALIDO, '', 'Monto inválido', $transaccion);
        }
        if ($monto <= 0) {
            $this->pagoJson(self::MONTO_INVALIDO, '', 'Monto inválido', $transaccion);
        }

        $autorizacion = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);

        try {
            $repo->anotarAviso($l['id'], $transaccion, $autorizacion, $monto);

            // ─────────────────────────────────────────────────────
            // LA REVISIÓN ES PARA VENTAS, NO PARA PLANES.
            //
            // En una venta el dinero ya entró y alguien revisa antes de
            // abonarla. En un pago de plan no hay venta que abonar: el
            // plan se activa solo. Si aquí se mandara a `por_aprobar`,
            // nadie lo aprobaría —el panel de ligas es de ventas, y el
            // de planes mira `pagos_plan`, no la liga— y el plan se
            // quedaría en `por_pagar` igual que antes del arreglo.
            // ─────────────────────────────────────────────────────
            if (!empty($l['venta_id']) && (new ConfigRepo($db))->exigeAprobacion()) {
                $repo->marcarRevisada($l['id'], 'por_aprobar');
                $this->pagoJson(self::OK, $autorizacion, 'Operación exitosa', $transaccion);
            }

            $pagoId = null;
            $resta  = 0.0;
            if (!empty($l['venta_id'])) {
                $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                    'monto'      => $monto,
                    'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                    // La transacción va en el abono: es lo que reconoce un
                    // reintento del proveedor (ver abonoDeTransaccion).
                    'referencia' => $this->rotulo($l) . ' ' . $l['referencia']
                                  . ($transaccion !== '' ? ' · trx ' . $transaccion : ''),
                    'fecha'      => date('Y-m-d'),
                    'usuario_id' => null,   // lo registró el proveedor, no una persona
                ]);
                $pagoId = $res['pago_id'] ?? null;
                $resta  = (float)($res['saldo'] ?? 0);
            }

            // UN PAGO PARCIAL NO CIERRA LA LIGA. El abono ya descontó lo
            // pagado; la liga sigue `pendiente` para aceptar el resto con
            // la misma CLABE o referencia. Antes se marcaba `pagada` con
            // cualquier monto: la Caja decía "pagado" con saldo pendiente
            // y el siguiente depósito rebotaba con "Referencia sin adeudo".
            if ($resta > 0.01) {
                error_log('[LibertyFin] pago entrante parcial ' . $l['referencia']
                    . ' · abonado ' . $monto . ' · resta ' . $resta);
            } else {
                $repo->marcarPagada($l['id'], $pagoId);
            }

            // ─────────────────────────────────────────────────────
            // SI ERA UN PAGO DE PLAN, APROBARLO TAMBIÉN.
            //
            // La liga ya quedó `pagada`, pero `pagos_plan` vive en la
            // base principal y no se entera solo. Sin esta llamada, SPEI
            // y tienda dejaban la liga en `pagada` y el plan en
            // `por_pagar` indefinidamente.
            //
            // `aprobarPorPago` es idempotente: si ya estaba aprobado,
            // devuelve null y no hace nada. El proveedor puede reintentar.
            // ─────────────────────────────────────────────────────
            $this->aprobarPlanSiAplica($l);

            Auditoria::anota('pago.registrar',
                $this->rotulo($l) . ' ' . $l['referencia'], 'esperando',
                Dinero::pesos($monto) . ' · autorización ' . $autorizacion, $db);

            $this->pagoJson(self::OK, $autorizacion, 'Operación exitosa', $transaccion);
        } catch (\InvalidArgumentException $e) {
            // El saldo ya no da. No es una falla del sistema: es que esa
            // venta cambió. Se contesta lo que la doc pide para el caso.
            error_log('[LibertyFin] pago entrante ' . $limpio . ': ' . $e->getMessage());
            $this->pagoJson(self::SIN_ADEUDO, '', $e->getMessage(), $transaccion);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pago entrante ' . $limpio . ': ' . $e->getMessage());
            $this->pagoJson(self::ERROR_SISTEMA, '', 'Error de sistema', $transaccion);
        }
    }

    /**
     * La respuesta de autorización, con los ocho campos de la doc.
     *
     * Los tres de SMS y ticket se mandan vacíos a propósito: el proveedor
     * los trae "en desarrollo" y poner texto ahí no imprime nada.
     */
    private function pagoJson($codigo, $autorizacion, $mensaje, $transaccion, $fecha = '')
    {
        $this->json([
            'codigo'           => (int)$codigo,
            'autorizacion'     => (string)$autorizacion,
            'mensaje'          => (string)$mensaje,
            'transaccion'      => (string)$transaccion,
            'fecha'            => $fecha !== '' ? $fecha : date('Y-m-d'),
            'notificacion_sms' => '',
            'mensaje_sms'      => '',
            'mensaje_ticket'   => '',
        ]);
    }

    // ════════════════════════════════════════════════════════════
    //  CANCELACIÓN · deshaz el último pago
    // ════════════════════════════════════════════════════════════

    /**
     * El proveedor no pudo cerrar la operación y pide deshacerla.
     *
     * Pasa cuando el punto de venta falla al registrar su transacción
     * después de que ya autorizamos. Solo se puede el MISMO día, y solo
     * deshace UN pago: el cliente puede volver a intentar.
     */
    public function cancelaPago()
    {
        $this->abrirPuerta();
        $d = $this->cuerpo();

        $valor  = preg_replace('/\D/', '',
            (string)($d['referencia'] ?? $d['clabe'] ?? ''));
        $transaccion = trim((string)($d['transaccion'] ?? ''));

        $hallado = $this->ubicar($valor);
        if (!$hallado) $this->json(['codigo' => self::NO_RECONOCIDA,
                                    'mensaje' => 'Operación no encontrada']);
        list($db, $l) = $hallado;

        // UN ABONO PARCIAL deja la liga abierta, así que no se puede mirar
        // solo su estado: se busca el abono de esa transacción en la venta.
        if ($l['estado'] !== 'pagada' && !empty($l['venta_id']) && $transaccion !== '') {
            $repo  = new LigaRepo($db);
            $abono = $repo->abonoDeTransaccion((int)$l['venta_id'], $transaccion);
            if ($abono) {
                if (date('Y-m-d', strtotime($abono['fecha_pago'])) !== date('Y-m-d')) {
                    $this->json(['codigo' => self::FUERA_PERIODO,
                                 'mensaje' => 'Cancelación fuera de periodo']);
                }
                try {
                    (new RegistrarPago($db))->cancelar((int)$abono['id'],
                        'Cancelado por el proveedor de pago', null);
                    Auditoria::anota('pago.cancelar',
                        $this->rotulo($l) . ' ' . $l['referencia'],
                        Dinero::pesos($abono['monto']), 'cancelado por el proveedor', $db);
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] cancelar entrante parcial: ' . $e->getMessage());
                    $this->json(['codigo' => self::ERROR_SISTEMA, 'mensaje' => 'Error de sistema']);
                }
            }
        }

        // YA CANCELADO TAMBIÉN RESPONDE 0. Lo pide la documentación, y
        // tiene sentido: el proveedor reintenta y lo que quiere saber es
        // si el pago está deshecho, no si fue él quien lo deshizo.
        if ($l['estado'] !== 'pagada') {
            $this->json(['codigo' => self::OK, 'mensaje' => 'Cancelación exitosa']);
        }
        if (!empty($l['pagado_en'])
            && date('Y-m-d', strtotime($l['pagado_en'])) !== date('Y-m-d')) {
            $this->json(['codigo' => self::FUERA_PERIODO,
                         'mensaje' => 'Cancelación fuera de periodo']);
        }
        if ($transaccion !== '' && (string)$l['transaccion'] !== $transaccion) {
            $this->json(['codigo' => self::NO_RECONOCIDA,
                         'mensaje' => 'Operación no encontrada']);
        }

        try {
            $repo = new LigaRepo($db);
            // El abono se cancela de forma lógica, no se borra: el rastro
            // de que entró y se deshizo es justo lo que hace falta
            // cuando alguien pregunte por qué el corte no cuadra.
            if (!empty($l['pago_id'])) {
                (new RegistrarPago($db))->cancelar((int)$l['pago_id'],
                    'Cancelado por el proveedor de pago', null);
            }
            $repo->marcarCancelada($l['id']);
            Auditoria::anota('pago.cancelar',
                $this->rotulo($l) . ' ' . $l['referencia'],
                Dinero::pesos($l['pagado_monto'] ?? $l['monto']),
                'cancelado por el proveedor', $db);
            $this->json(['codigo' => self::OK, 'mensaje' => 'Cancelación exitosa']);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelar entrante: ' . $e->getMessage());
            $this->json(['codigo' => self::ERROR_SISTEMA, 'mensaje' => 'Error de sistema']);
        }
    }

    // ════════════════════════════════════════════════════════════
    //  LIGA DE TARJETA · el aviso del cobro
    // ════════════════════════════════════════════════════════════

    /**
     * El aviso de la liga de tarjeta.
     *
     * Trae otra forma: no pregunta nada, informa. El cuerpo viene con
     * `reference`, `response` (approved/denied/error) y los datos de la
     * tarjeta, y espera {"code":"00"} de vuelta.
     *
     * No es indispensable —la tarjeta sí se puede consultar— pero sin
     * esto el abono tarda hasta que alguien abre la pantalla, y el
     * cliente ya se fue creyendo que pagó.
     */
public function pagoLiga()
{
    $this->abrirPuerta();
    $d = $this->cuerpo();

    $ref       = preg_replace('/\D/', '', (string)($d['reference'] ?? ''));
    $respuesta = strtolower(trim((string)($d['response'] ?? '')));

    // Traza de entrada: qué nos llegó, en una sola línea.
    error_log(sprintf(
        '[LibertyFin] pagoLiga ← ref=%s response=%s auth=%s folio=%s amount=%s',
        $ref !== '' ? $ref : '(vacía)',
        $respuesta !== '' ? $respuesta : '(vacío)',
        (string)($d['auth'] ?? ''),
        (string)($d['foliocpagos'] ?? ''),
        (string)($d['amount'] ?? '')
    ));

    if ($ref === '') {
        error_log('[LibertyFin] pagoLiga ✗ sin referencia en el cuerpo');
        $this->json(['code' => '21', 'message' => 'Referencia obligatoria.']);
    }

    $hallado = $this->ubicar($ref);
    if (!$hallado) {
        error_log('[LibertyFin] pagoLiga ✗ referencia no ubicada: ' . $ref);
        $this->json(['code' => '99', 'message' => 'Referencia no encontrada.']);
    }
    list($db, $l) = $hallado;
    if ($this->rechazaPrueba($l)) {
        $this->json(['code' => '99', 'message' => 'Referencia no encontrada.']);
    }

    error_log(sprintf(
        '[LibertyFin] pagoLiga · ubicada id=%s estado=%s venta_id=%s monto_liga=%s',
        (string)$l['id'],
        (string)$l['estado'],
        var_export($l['venta_id'] ?? null, true),
        (string)$l['monto']
    ));

    // Solo `approved` mueve dinero. `denied` y `error` se anotan para
    // que en la pantalla se vea que el intento existió y falló.
    if ($respuesta !== 'approved') {
        error_log(sprintf(
            '[LibertyFin] pagoLiga · intento NO aprobado (response=%s) → marcarRevisada id=%s',
            $respuesta !== '' ? $respuesta : '(vacío)',
            (string)$l['id']
        ));
        (new LigaRepo($db))->marcarRevisada($l['id']);
        $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
    }

    if ($l['estado'] === 'pagada') {
        error_log(sprintf(
            '[LibertyFin] pagoLiga · id=%s ya estaba pagada → reconciliar plan',
            (string)$l['id']
        ));
        $this->aprobarPlanSiAplica($l);
        $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
    }

    // Aquí el monto viene en PESOS y con comas ("1,156.00").
    $monto = (float)str_replace([',', '$', ' '], '', (string)($d['amount'] ?? 0));
    if ($monto <= 0) {
        error_log(sprintf(
            '[LibertyFin] pagoLiga · monto del aviso inválido (%s) → uso monto de liga %s',
            (string)($d['amount'] ?? ''),
            (string)$l['monto']
        ));
        $monto = (float)$l['monto'];
    }

    $saldo = $this->saldoDe($db, $l);
    if ($saldo > 0 && $monto > $saldo) {
        error_log(sprintf(
            '[LibertyFin] pagoLiga · monto %s > saldo %s → recorto al saldo',
            $monto, $saldo
        ));
        $monto = $saldo;
    }

    error_log(sprintf(
        '[LibertyFin] pagoLiga · a aplicar monto=%.2f saldo=%.2f venta_id=%s',
        $monto, $saldo, var_export($l['venta_id'] ?? null, true)
    ));

    try {
        $repo = new LigaRepo($db);
        $repo->anotarAviso($l['id'], (string)($d['foliocpagos'] ?? ''),
            (string)($d['auth'] ?? ''), $monto);

        // Mismo criterio que en autorizar(): la revisión es para ventas.
        if (!empty($l['venta_id']) && (new ConfigRepo($db))->exigeAprobacion()) {
            error_log(sprintf(
                '[LibertyFin] pagoLiga · venta %s exige aprobación → por_aprobar',
                (string)$l['venta_id']
            ));
            $repo->marcarRevisada($l['id'], 'por_aprobar');
            $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
        }

        $pagoId = null;
        if (!empty($l['venta_id']) && $monto > 0) {
            $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                'monto'      => $monto,
                'metodo'     => 'tarjeta',
                'referencia' => 'Liga ' . $l['referencia'],
                'fecha'      => date('Y-m-d'),
                'usuario_id' => null,
            ]);
            $pagoId = $res['pago_id'] ?? null;
            error_log(sprintf(
                '[LibertyFin] pagoLiga · abono registrado venta=%s pago_id=%s',
                (string)$l['venta_id'], var_export($pagoId, true)
            ));
        } else {
            error_log('[LibertyFin] pagoLiga · sin venta_id → es pago de plan');
        }
        $repo->marcarPagada($l['id'], $pagoId);

        $this->aprobarPlanSiAplica($l);

        Auditoria::anota('pago.registrar', 'liga cobrada · ' . $l['referencia'],
            'esperando', Dinero::pesos($monto), $db);

        error_log(sprintf(
            '[LibertyFin] pagoLiga ✓ id=%s ref=%s monto=%.2f',
            (string)$l['id'], $l['referencia'], $monto
        ));
    } catch (\Throwable $e) {
        // Se contesta 00 de todos modos: el cobro con el banco ya se hizo.
        error_log(sprintf(
            '[LibertyFin] pagoLiga ✗ EXCEPCIÓN ref=%s id=%s monto=%.2f: %s @ %s:%d',
            $ref, (string)$l['id'], $monto,
            $e->getMessage(), $e->getFile(), $e->getLine()
        ));
        error_log('[LibertyFin] pagoLiga · trace: ' . $e->getTraceAsString());
    }
    $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
}
    // ════════════════════════════════════════════════════════════
    //  LO COMPARTIDO
    // ════════════════════════════════════════════════════════════

    /**
     * Si esta liga es de un pago de plan, apruébalo.
     *
     * ────────────────────────────────────────────────────────────
     * POR QUÉ EXISTE ESTE MÉTODO
     *
     * Las ligas de venta traen `venta_id`; las de plan lo traen en
     * NULL (así las creó UsuariosControlador::generarLigaPlan). Cuando
     * el aviso del proveedor confirma que entró el dinero, además de
     * marcar la liga hay que aprobar el pago del plan: `pagos_plan`
     * vive en la base principal y no se entera solo de que ya entró
     * el cobro.
     *
     * Sin esto, SPEI y tienda dejaban la liga en `pagada` y el plan en
     * `por_pagar` para siempre, porque el único camino que llamaba a
     * `aprobarPorPago` era el modal de la pestaña Plan —y el modal de
     * SPEI ni siquiera consulta—.
     *
     * `aprobarPorPago` es idempotente: si el pago ya estaba aprobado,
     * o la fila ya no está en `por_pagar`/`en_revision`, devuelve null
     * y no hace nada. El proveedor reintenta; con esto no suma meses
     * de más.
     *
     * Va en try/catch y NO propaga: al proveedor hay que contestarle
     * que recibimos el aviso aunque la aprobación del plan falle. Un
     * 500 aquí haría que reintente un cobro que la liga ya tiene como
     * pagado.
     * ────────────────────────────────────────────────────────────
     */
    private function aprobarPlanSiAplica(array $l)
    {
        if (!empty($l['venta_id']))   return;   // era una venta, no un plan
        if (empty($l['referencia']))  return;

        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $aprobado  = (new \LibertyFin\Datos\PlanRepo($principal))
                ->aprobarPorPago($l['referencia'], null);   // null = automático

            if ($aprobado) {
                $nombrePlan = (string)($aprobado['nombre_plan'] ?? $aprobado['plan'] ?? '');

                error_log(sprintf(
                    '[LibertyFin] webhook: plan %s aprobado · empresa %d · vence %s',
                    $nombrePlan !== '' ? $nombrePlan : '?',
                    (int)($aprobado['empresa_id'] ?? 0),
                    $aprobado['vence_nuevo'] ?? '?'
                ));

                // ─────────────────────────────────────────────────
                // AVISO DE PAGO CONFIRMADO
                //
                // El destinatario es el correo del usuario que CREÓ la
                // liga, guardado en `ligas.usuario_correo` cuando el
                // admin estaba logueado en la pestaña Plan. Aquí no hay
                // sesión —el proveedor pega sin cookies—, así que la
                // única forma de tener ese correo es haberlo persistido
                // al crear la liga (ver UsuariosControlador::generarLigaPlan).
                //
                // Envuelto en su propio try/catch: el pago YA quedó
                // aprobado. Un SMTP caído no puede hacer que le
                // contestemos 500 al proveedor por un correo que no salió.
                // ─────────────────────────────────────────────────
                $destino = (string)($l['usuario_correo'] ?? '');
                if ($destino === '') {
                    $destino = (string)($aprobado['email_admin'] ?? '');
                }
                $nombre  = (string)($l['usuario_nombre']
                                    ?? $aprobado['nombre_contacto'] ?? '');
                $vence   = !empty($aprobado['vence_nuevo'])
                           ? date('d/m/Y', strtotime($aprobado['vence_nuevo']))
                           : '';

                $enviado = false;
                $motivo  = '';
                if ($destino === '') {
                    $motivo = 'sin correo en la liga ni en el pago del plan';
                } else {
                    try {
                        $enviado = \LibertyFin\Servicio\Avisos::pagoPlanRevisado(
                            $destino, $nombre, $nombrePlan, true, $vence,
                            (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                                . ($_SERVER['HTTP_HOST'] ?? '') . '/cuenta?t=plan');
                        if (!$enviado) {
                            $motivo = \LibertyFin\Servicio\Avisos::activos()
                                ? 'el envío devolvió false'
                                : 'SMTP no configurado';
                        }
                    } catch (\Throwable $e) {
                        $motivo = 'excepción: ' . $e->getMessage();
                    }
                }

                // Una sola línea, siempre, con el desenlace del correo.
                // Cuando alguien pregunte "¿le llegó?", se busca la
                // referencia en el log y se sabe de inmediato.
                error_log(sprintf(
                    '[LibertyFin] aviso pago plan webhook ref=%s plan="%s" para=%s — %s%s',
                    (string)($l['referencia'] ?? ''),
                    $nombrePlan,
                    $destino !== '' ? $destino : '(sin correo)',
                    $enviado ? 'correo ENVIADO' : 'correo NO enviado',
                    $enviado ? '' : ' (' . ($motivo ?: 'motivo desconocido') . ')'
                ));
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] webhook → aprobar plan: ' . $e->getMessage());
        }
    }

    /**
     * El secreto de la URL.
     *
     * Es lo único que separa estos endpoints de cualquiera con un
     * navegador. Se compara con hash_equals para que no se pueda adivinar
     * midiendo cuánto tarda en fallar.
     */
    private function abrirPuerta()
    {
        // ── ANTES QUE NADA, QUEDA ESCRITO QUE LLAMARON ──
        //
        // Esto es lo primero de lo primero, y va antes de cualquier
        // comprobación a propósito. El rechazo salía MUDO: si el
        // secreto no cuadraba se contestaba 403 y no se escribía una
        // sola línea. Visto desde fuera era idéntico a que el
        // proveedor no estuviera llamando —«no me aparecen los logs»—
        // cuando en realidad llamaba y se le cerraba la puerta en la
        // cara.
        //
        // Y eso no es un detalle de registro: cuando el proveedor no
        // recibe un 200 da el cobro por no autorizado y LO REVIERTE.
        // El cliente ve que le regresan su dinero y nadie sabe por qué.
        // Un aviso de pago que se rechaza tiene que dejar rastro
        // SIEMPRE, aunque se rechace.
        $este = $this->huella();
        error_log('[LibertyFin] pagadetodo ENTRA ' . $este);

        if (!Integraciones::activa('spei')) {
            // La integración apagada no debería ni llegar aquí —las
            // rutas se registran igual para poder decirlo—.
            error_log('[LibertyFin] pagadetodo RECHAZA ' . $este
                . ' · la integración `spei` está apagada o le faltan credenciales. '
                . 'Revisa config/integraciones.php.');
            http_response_code(503);
            $this->json(['codigo' => self::ERROR_SISTEMA, 'mensaje' => 'Servicio no configurado']);
        }

        $cfg = Integraciones::de('spei') ?: [];

        // LAS DIRECCIONES DEL SISTEMA ANTERIOR no traen secreto: pasa
        // solo quien llama desde una IP de Paga de Todo. REMOTE_ADDR y no
        // X-Forwarded-For: esa cabecera la escribe quien quiera.
        if (self::esRutaAnterior(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            if (!in_array($ip, self::ipsProveedor($cfg), true)) {
                error_log('[LibertyFin] pagadetodo RECHAZA ' . $este
                    . ' · dirección del sistema anterior llamada desde una IP que no es de '
                    . 'Paga de Todo. Si es suya, agrégala a `ips_proveedor` en config/integraciones.php.');
                http_response_code(403);
                $this->json(['codigo' => self::NO_RECONOCIDA, 'mensaje' => 'No autorizado']);
            }
            error_log('[LibertyFin] pagadetodo ABRE ' . $este
                . ' · dirección del sistema anterior, IP de Paga de Todo');
            return;
        }

        $esperado = trim((string)($cfg['secreto_webhook'] ?? ''));
        // El del panel del Sandbox. Igual al de producción no separa nada,
        // así que se ignora.
        $dePrueba = trim((string)($cfg['secreto_webhook_prueba'] ?? ''));
        if ($dePrueba === $esperado) $dePrueba = '';

        if ($esperado === '') {
            // Sin secreto configurado NO se abre. Dejarlo pasar sería
            // publicar un botón de "marcar como pagada" en internet.
            error_log('[LibertyFin] pagadetodo RECHAZA ' . $este
                . ' · falta `secreto_webhook` en config/integraciones.php. '
                . 'Genéralo con: php -r "echo bin2hex(random_bytes(24));" '
                . 'y pégalo también al final de las URLs del panel del proveedor.');
            http_response_code(503);
            $this->json(['codigo' => self::ERROR_SISTEMA,
                         'mensaje' => 'Servicio no configurado']);
        }

        $dado = $this->secretoDeLaLlamada();
        if ($dePrueba !== '' && hash_equals($dePrueba, $dado)) {
            $this->avisoDePrueba = true;
        } elseif (!hash_equals($esperado, $dado)) {
            // Se dice QUÉ pasó sin escribir ninguno de los dos secretos
            // en un archivo de registro que cualquiera puede leer.
            $porque = $dado === ''
                ? 'la llamada vino SIN `?k=`: la URL del panel del proveedor '
                  . 'no lleva el secreto pegado al final'
                : 'el `?k=` no coincide con `secreto_webhook` ('
                  . strlen($dado) . ' caracteres recibidos, '
                  . strlen($esperado) . ' esperados)';
            error_log('[LibertyFin] pagadetodo RECHAZA ' . $este . ' · ' . $porque
                . '. Mientras no cuadre, el proveedor revierte cada cobro.');
            http_response_code(403);
            $this->json(['codigo' => self::NO_RECONOCIDA, 'mensaje' => 'No autorizado']);
        }

        error_log('[LibertyFin] pagadetodo ABRE ' . $este
            . ($this->avisoDePrueba ? ' · con secreto_webhook_prueba' : ''));
    }

    /**
     * De dónde sale el secreto, y por qué de tres sitios.
     *
     * ESTE ERA EL FALLO QUE REVERTÍA LOS COBROS.
     *
     * La documentación del proveedor dice, literalmente, que
     * «Cobroscontarjeta.com le agregará [/?r=REFERENCIA] a su url».
     * AGREGA. Así que si en su panel está registrado
     *
     *     https://midominio.com/pagadetodo/consulta-referencia?k=SECRETO
     *
     * lo que acaba llamando es
     *
     *     https://midominio.com/pagadetodo/consulta-referencia?k=SECRETO/?r=000123
     *
     * y PHP lee `k` = «SECRETO/?r=000123» y `r` NO EXISTE. Se rompen
     * las dos cosas a la vez: el secreto no cuadra —sobra media URL
     * pegada— y la referencia se pierde. El proveedor no recibe un 200,
     * da el cobro por no autorizado y lo revierte. El cliente ve que le
     * regresan su dinero.
     *
     * Se arregla por los dos lados:
     *
     *   · El secreto puede ir en la RUTA, que es lo recomendado ahora:
     *     /pagadetodo/SECRETO/consulta-referencia. Ahí el proveedor
     *     puede pegar lo que quiera al final sin romper nada.
     *
     *   · Y si ya quedó registrado con `?k=`, se recupera: se corta el
     *     secreto en la primera barra y se rescata la referencia que
     *     venía pegada. Así lo que ya está dado de alta funciona sin
     *     tener que volver a tocar el panel del proveedor.
     */
    private function secretoDeLaLlamada()
    {
        // 1 · En la ruta: /pagadetodo/<secreto>/<lo-que-sea>
        $camino = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $trozos = explode('/', $camino);
        if (count($trozos) >= 3 && $trozos[0] === 'pagadetodo') {
            return $trozos[1];
        }

        // 2 · En la cabecera, para quien pueda mandarla.
        if (!empty($_SERVER['HTTP_X_LF_SECRETO'])) return (string)$_SERVER['HTTP_X_LF_SECRETO'];

        // 3 · En `?k=`, rescatando lo que el proveedor le pegó detrás.
        $k = (string)($_GET['k'] ?? '');
        if ($k !== '' && strpos($k, '/') !== false) {
            list($limpio, $cola) = explode('/', $k, 2);
            // La referencia venía dentro: se devuelve a su sitio para
            // que el resto del controlador la encuentre donde espera.
            if (preg_match('/[?&]r=([^&]+)/', $cola, $m) && !isset($_GET['r'])) {
                $_GET['r'] = urldecode($m[1]);
                error_log('[LibertyFin] pagadetodo · la referencia venía pegada al secreto '
                    . '(el proveedor añade /?r=). Rescatada: ' . $_GET['r']
                    . '. Cambia la URL del panel a /pagadetodo/SECRETO/... para evitarlo.');
            }
            return $limpio;
        }
        return $k;
    }

    /**
     * Cómo identificar esta llamada en el registro.
     *
     * Sin el secreto dentro: el log lo lee cualquiera con acceso al
     * servidor, y ahí no pinta nada una credencial.
     */
    private function huella()
    {
        $q = $_GET;
        if (isset($q['k'])) $q['k'] = '(secreto de ' . strlen((string)$q['k']) . ' car.)';
        return ($_SERVER['REQUEST_METHOD'] ?? '?') . ' '
             . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '?')
             . ($q ? '?' . http_build_query($q) : '')
             . ' desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?');
    }

    /**
     * En qué base vive este cobro.
     *
     * Primero el directorio de la base principal, que es una búsqueda
     * directa. Si no está —cobros de antes de que existiera— se recorren
     * las bases activas. Eso es caro, por eso es el último recurso.
     *
     * @return array|null  [PDO, fila del cobro]
     */
    private function ubicar($referencia)
    {
        $referencia = preg_replace('/\D/', '', (string)$referencia);
        if ($referencia === '') return null;

        $principal = null;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sin base principal para ubicar cobros');
        }

        if ($principal) {
            // 1) Ruta conocida → empresa_db (camino rápido)
            $ruta = (new RutaLigaRepo($principal))->buscar($referencia);
            if ($ruta && !empty($ruta['empresa_db'])) {
                try {
                    $db = Conexion::de($ruta['empresa_db']);
                    $l  = (new LigaRepo($db))->porCualquiera($referencia);
                    if ($l) return [$db, $l];
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] abrir ' . $ruta['empresa_db'] . ': ' . $e->getMessage());
                }
            }

            // 2) NUEVO: la liga puede vivir en la PROPIA base principal
            //    (empresas sin base dedicada, cobros antiguos, mono-inquilino).
            try {
                $l = (new LigaRepo($principal))->porCualquiera($referencia);
                if ($l) return [$principal, $l];
            } catch (\Throwable $e) {
                error_log('[LibertyFin] liga en principal ' . $referencia . ': ' . $e->getMessage());
            }
        }

        if (!$principal) return null;

        // 3) Último recurso: recorrer bases de empresas (caro, pero exhaustivo)
        foreach ((new RutaLigaRepo($principal))->basesDeEmpresas() as $base) {
            try {
                $db = Conexion::de($base);
                $l  = (new LigaRepo($db))->porCualquiera($referencia);
                if ($l) {
                    (new RutaLigaRepo($principal))->apuntar(
                        $l['referencia'], $base, null, $l['metodo']);
                    return [$db, $l];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * Lo que de verdad se debe.
     *
     * Sale de la VENTA, no del monto con el que se generó el cobro: en
     * el rato que el cliente tardó en ir a la tienda pudo haber abonado
     * en el mostrador, y cobrarle el total otra vez obliga a devolverle.
     */
    private function saldoDe(\PDO $db, array $l)
    {
        if (empty($l['venta_id'])) return Dinero::centavos($l['monto']);
        try {
            $v = (new \LibertyFin\Datos\VentaRepo($db))->detalle((int)$l['venta_id']);
            if (!$v) return Dinero::centavos($l['monto']);
            if (($v['estado'] ?? '') === 'cancelada') return 0.0;
            return Dinero::centavos($v['saldo']);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] saldo de venta ' . $l['venta_id'] . ': ' . $e->getMessage());
            return Dinero::centavos($l['monto']);
        }
    }

    /** Un consecutivo para la transacción que el proveedor pide en la consulta. */
    private function siguienteTransaccion(array $l)
    {
        return (int)$l['id'] * 100 + (int)date('s');
    }

    private function rotulo(array $l)
    {
        return $l['metodo'] === 'spei' ? 'SPEI'
             : ($l['metodo'] === 'efectivo' ? 'Pago en tienda' : 'Liga');
    }

    /**
     * El cuerpo de la petición.
     *
     * Acepta JSON y formulario: la documentación dice JSON, pero el
     * Sandbox manda formulario en algunas pruebas y rechazarlo deja al
     * emulador sin poder probar nada.
     */
    private function cuerpo()
    {
        $crudo = file_get_contents('php://input');
        $d = json_decode((string)$crudo, true);
        if (!is_array($d)) $d = $_POST;
        // Todo lo que entra queda anotado. Cuando un pago no cuadra, esto
        // es lo único que dice qué llegó de verdad.
        error_log('[LibertyFin] aviso de pago: ' . mb_substr((string)$crudo, 0, 600));
        return is_array($d) ? $d : [];
    }

    private function json(array $d)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}