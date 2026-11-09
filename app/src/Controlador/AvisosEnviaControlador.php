<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\EnvioRepo;
use LibertyFin\Datos\RutaEnvioRepo;
use LibertyFin\Servicio\Envios;
use LibertyFin\Servicio\Integraciones;

/**
 * Los avisos que manda Envia cuando un paquete se mueve.
 *
 * Sin esto, el estado de un envío solo cambia si alguien se acuerda de
 * apretar «Actualizar estado». Con esto, la paquetería avisa sola y el
 * listado está al día sin que nadie haga nada.
 *
 * ── QUIÉN LLAMA AQUÍ ──
 *
 * Un servidor de Envia, no una persona. No hay sesión, no hay cookie y
 * no hay usuario. Por eso esta ruta es pública, y por eso lo primero
 * que se hace es comprobar que el aviso sea de verdad de ellos.
 *
 * ── LAS TRES COSAS QUE HAY QUE HACER BIEN ──
 *
 * 1. CONTESTAR RÁPIDO. Envia espera un 2xx en segundos; si tarda, da el
 *    aviso por perdido y lo reintenta. Así que se contesta primero y se
 *    trabaja después.
 *
 * 2. NO REPETIR. Como reintenta, el mismo aviso llega varias veces.
 *    Cada uno trae su `X-Webhook-Id` y se lleva cuenta de los vistos.
 *
 * 3. SABER DE QUIÉN ES. El aviso trae el número de guía y nada más,
 *    pero cada empresa tiene su propia base. Para eso está
 *    `lf_ruta_envios`, que se escribe al generar la guía.
 */
final class AvisosEnviaControlador
{
    /** Lo que firma Envia: ts + "." + evento + "." + cuerpo. */
    const PREFIJO_FIRMA = 'v1=';

    /** Más de esto y el aviso se considera viejo (segundos). */
    const VENTANA = 300;

    public function recibir()
    {
        $cuerpo = file_get_contents('php://input');
        $cab    = self::cabeceras();

        // ── 1 · Contestar YA ──
        // El trabajo va después de cerrar la respuesta. Un aviso que se
        // atiende en tres segundos pero contesta al final es un aviso
        // que Envia va a reintentar de todas formas.
        $this->contestar(200, ['received' => true]);

        try {
            $this->atender($cuerpo, $cab);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso de Envia: ' . $e->getMessage());
        }
        exit;
    }

    private function atender($cuerpo, array $cab)
    {
        $cfg = Integraciones::de('envia') ?: [];

        // ── 2 · ¿De verdad es de Envia? ──
        $queja = self::revisarFirma($cuerpo, $cab, $cfg);
        if ($queja !== '') {
            error_log('[LibertyFin] aviso de Envia rechazado: ' . $queja);
            return;
        }

        $j = json_decode((string)$cuerpo, true);
        if (!is_array($j)) { error_log('[LibertyFin] aviso de Envia: no es JSON'); return; }

        $d    = (array)($j['data'] ?? []);
        $guia = trim((string)($d['tracking_number'] ?? $d['trackingNumber'] ?? ''));
        if ($guia === '') { error_log('[LibertyFin] aviso de Envia sin número de guía'); return; }

        $estadoTxt = trim((string)($d['status_description'] ?? $d['status'] ?? ''));
        $donde     = trim((string)($d['location'] ?? ''));

        $principal = null;
        try { $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }
        catch (\Throwable $e) { error_log('[LibertyFin] aviso: sin base principal'); return; }

        $rutas = new RutaEnvioRepo($principal);

        // ── 3 · ¿Ya lo habíamos atendido? ──
        $avisoId = $cab['x-webhook-id'] ?? '';
        if ($avisoId !== '' && $rutas->yaVisto($avisoId)) return;   // repetido

        // ── 4 · ¿De qué empresa es esta guía? ──
        list($db, $envio) = $this->ubicar($rutas, $guia);
        if (!$db || !$envio) {
            error_log('[LibertyFin] aviso de Envia: guía ' . $guia . ' no está en ninguna base');
            return;
        }

        // Un envío cancelado no vuelve a moverse. Si llega un aviso
        // tardío de antes de la cancelación, revivirlo sería mentir.
        if ($envio['estado'] === 'cancelado') return;

        $nuevo = Envios::estadoDe($estadoTxt, $envio['estado']);
        $texto = $estadoTxt . ($donde !== '' ? ' · ' . $donde : '');

        try {
            (new EnvioRepo($db))->anotarRastreo($envio['id'], $nuevo, $texto);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso: anotar ' . $guia . ': ' . $e->getMessage());
            return;
        }

        error_log('[LibertyFin] envío ' . $guia . ' → ' . $nuevo . ' (' . $estadoTxt . ')');

        // De vez en cuando se tiran los apuntes viejos. Va aquí y no en
        // una tarea aparte porque esta instalación no tiene una.
        if (random_int(1, 200) === 1) $rutas->limpiarAvisos();
    }

    /**
     * En qué base vive esta guía.
     *
     * Primero el apunte, que es lo normal y cuesta una consulta. Si no
     * está —una guía vieja, o un apunte que se perdió— se recorren las
     * bases. Eso es caro, así que al encontrarla se deja apuntada.
     */
    private function ubicar(RutaEnvioRepo $rutas, $guia)
    {
        $apunte = $rutas->buscar($guia);
        if ($apunte && !empty($apunte['empresa_db'])) {
            try {
                $db = Conexion::de($apunte['empresa_db']);
                $e  = (new EnvioRepo($db))->porGuia($guia);
                if ($e) return [$db, $e];
            } catch (\Throwable $ex) {
                error_log('[LibertyFin] abrir ' . $apunte['empresa_db'] . ': ' . $ex->getMessage());
            }
        }

        foreach ($rutas->basesDeEmpresas() as $base) {
            try {
                $db = Conexion::de($base);
                $e  = (new EnvioRepo($db))->porGuia($guia);
                if ($e) { $rutas->apuntar($guia, $base, $e['id']); return [$db, $e]; }
            } catch (\Throwable $ex) { continue; }
        }
        return [null, null];
    }

    /**
     * ¿El aviso viene firmado por Envia?
     *
     * La firma es HMAC-SHA256 de `timestamp.evento.cuerpo` con el
     * secreto de la cuenta. Se compara con hash_equals para no filtrar
     * por tiempo cuál byte falló.
     *
     * Hay dos casos en que NO se puede verificar, y se tratan distinto:
     *
     *  · Sin secreto configurado: no hay con qué comparar. Se acepta y
     *    se avisa al log, porque exigirlo rompería a quien ya tenga
     *    webhooks andando. Se recomienda ponerlo.
     *  · Con secreto configurado pero sin firma en el aviso: se
     *    RECHAZA. Si alguien configuró el secreto, es que espera avisos
     *    firmados, y uno sin firma es sospechoso.
     *
     * @return string '' si pasa, o el motivo del rechazo.
     */
    public static function revisarFirma($cuerpo, array $cab, array $cfg)
    {
        $secreto = trim((string)($cfg['secreto_webhook'] ?? ''));
        $firma   = trim((string)($cab['x-webhook-signature'] ?? ''));

        if ($secreto === '') {
            if (empty($_SESSION['lf_envia_sin_secreto'])) {
                $_SESSION['lf_envia_sin_secreto'] = 1;
                error_log('[LibertyFin] los avisos de Envia llegan SIN verificar: falta '
                    . '`secreto_webhook` en el bloque `envia` de config/integraciones.php');
            }
            return '';
        }
        if ($firma === '') return 'hay secreto configurado pero el aviso no trae firma';

        $ts = trim((string)($cab['x-webhook-timestamp'] ?? ''));
        if ($ts === '') return 'el aviso no trae marca de tiempo';

        // Un aviso viejo reenviado por alguien más no debe valer, aunque
        // su firma sea buena. El timestamp viene en milisegundos.
        $edad = abs(time() - (int)round(((float)$ts) / 1000));
        if ($edad > self::VENTANA) return 'el aviso trae ' . $edad . ' segundos de viejo';

        $evento   = trim((string)($cab['x-webhook-event'] ?? ''));
        $esperado = hash_hmac('sha256', $ts . '.' . $evento . '.' . $cuerpo, $secreto);

        $dada = $firma;
        if (strncmp($dada, self::PREFIJO_FIRMA, strlen(self::PREFIJO_FIRMA)) === 0) {
            $dada = substr($dada, strlen(self::PREFIJO_FIRMA));
        }
        if (!hash_equals($esperado, $dada)) return 'la firma no coincide';
        return '';
    }

    /** Las cabeceras en minúsculas, venga el servidor como venga. */
    private static function cabeceras()
    {
        $r = [];
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) $r[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) !== 0) continue;
            $r[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        return $r;
    }

    private function contestar($codigo, array $cuerpo)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        $txt = json_encode($cuerpo);
        header('Content-Length: ' . strlen($txt));
        header('Connection: close');
        echo $txt;
        // Se cierra la conexión y el trabajo sigue en el fondo: Envia
        // ya tiene su respuesta y no va a reintentar.
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        @ob_end_flush(); @flush();
    }
}