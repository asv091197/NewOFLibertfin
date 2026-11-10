<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaTiendaNubeRepo;
use LibertyFin\Servicio\TiendaNube;
use LibertyFin\Servicio\TnInventario;

/**
 * Los avisos de Tienda Nube: {"store_id": 123, "event": "order/paid", "id": 456}.
 *
 * ── QUIÉN LLAMA ──
 * Un servidor de Tienda Nube, sin sesión. Los avisos se dan de alta en
 * cada tienda al conectar (TiendaNube::asegurarAvisos). También llegan aquí
 * los obligatorios de privacidad (store/redact, customers/redact,
 * customers/data_request) si se apuntan a esta dirección en el portal:
 * LibertyFin no guarda datos de compradores de Tienda Nube más allá del
 * nombre en la orden, así que se contestan 200 y no hay nada que hacer.
 *
 * ── VIENEN FIRMADOS ──
 * Cabecera x-linkedstore-hmac-sha256 = HMAC-SHA256 del cuerpo con el
 * secreto de la aplicación. Sin firma válida no se hace nada. Aun así el
 * aviso no trae datos: la orden se le vuelve a pedir a Tienda Nube.
 *
 * ── LAS REGLAS DE SIEMPRE ──
 * 1. Contestar 2xx YA: Tienda Nube espera 3 segundos y luego reintenta.
 * 2. Ser idempotente: llegan repetidos y desordenados.
 * 3. Saber de qué empresa es: `lf_ruta_tn` (base principal), por store_id.
 */
final class AvisosTnControlador
{
    public function recibir()
    {
        $cuerpo = (string)file_get_contents('php://input');
        $firma  = (string)($_SERVER['HTTP_X_LINKEDSTORE_HMAC_SHA256'] ?? '');
        $this->contestar();
        try {
            $this->atender($cuerpo, $firma);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso de Tienda Nube: ' . $e->getMessage());
        }
        exit;
    }

    private function atender($cuerpo, $firma)
    {
        if (!TiendaNube::activa()) return;
        if (!TiendaNube::firmaValida($cuerpo, $firma)) {
            error_log('[LibertyFin] aviso de Tienda Nube con firma inválida: se ignora.');
            return;
        }
        $j = json_decode($cuerpo, true);
        if (!is_array($j)) return;

        $evento = (string)($j['event'] ?? '');
        $tienda = (string)($j['store_id'] ?? '');
        $id     = (string)($j['id'] ?? '');
        if ($tienda === '' || $evento === '') return;

        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $rutas = new RutaTiendaNubeRepo($principal);
        $ruta  = $rutas->buscar($tienda);
        if (!$ruta) return;

        $db  = Conexion::de($ruta['empresa_db']);
        $srv = new TnInventario($db);
        $c   = $srv->cuenta();
        if (!$c || (string)$c['store_id'] !== $tienda) return;

        if ($evento === 'app/uninstalled') {
            // Quitaron LibertyFin desde su panel: la llave ya no sirve.
            $srv->repo()->marcarVencida('Se desinstaló la aplicación desde Tienda Nube. Vuelve a conectar para seguir sincronizando.');
            $rutas->soltarTienda($tienda);
            \LibertyFin\Servicio\Auditoria::anota('tn.desinstalada', 'Tienda Nube', $c['nombre'] ?: $tienda, null, $db);
            return;
        }
        if (!$srv->conectada() || $id === '' || !ctype_digit($id)) return;

        if (strncmp($evento, 'order/', 6) === 0) {
            $r = $srv->procesarOrdenId($id);
            if (!empty($r['nueva'])) {
                error_log('[LibertyFin] orden TN ' . $id . ' → ' . $r['estado'] . ' (' . $ruta['empresa_db'] . ')');
            }
        } elseif ($evento === 'product/updated') {
            $srv->refrescarProducto($id);
        } elseif ($evento === 'product/deleted') {
            $srv->repo()->marcarProductoAusente($id);
        }
    }

    private function contestar()
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        $txt = '{"ok":true}';
        header('Content-Length: ' . strlen($txt));
        header('Connection: close');
        echo $txt;
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        @ob_end_flush(); @flush();
    }
}
