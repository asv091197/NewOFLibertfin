<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaMeliRepo;
use LibertyFin\Servicio\MeliInventario;
use LibertyFin\Servicio\MercadoLibre;

/**
 * Los avisos de Mercado Libre: «cambió la orden X», «cambió la
 * publicación Y».
 *
 * ── QUIÉN LLAMA ──
 *
 * Un servidor de Mercado Libre, sin sesión ni cookie. Se da de alta UNA
 * dirección para toda la aplicación (Notificaciones → URL de retorno de
 * notificaciones) con los temas `orders_v2` e `items`.
 *
 * ── NO TRAE FIRMA, Y NO HACE FALTA ──
 *
 * Mercado Libre no firma sus avisos. Lo que los hace seguros es que el
 * aviso no trae datos: solo dice QUÉ cambió («/orders/2000001234»). Lo
 * que haya cambiado se le pregunta a Mercado Libre con la llave de la
 * cuenta, así que un aviso inventado a lo más hace que preguntemos por una
 * orden que, si no es del vendedor, Mercado Libre se niega a dar.
 *
 * ── LAS REGLAS DE SIEMPRE ──
 *
 * 1. Contestar 200 YA. Mercado Libre espera respuesta en medio segundo;
 *    si no la tiene, reintenta y, tras muchos fallos, deja de mandar.
 * 2. Ser idempotente. Llegan repetidos; MeliInventario::procesarOrden
 *    solo descuenta una vez por orden.
 * 3. Saber de qué empresa es: `lf_ruta_meli` (base principal), por el
 *    `user_id` del vendedor.
 */
final class AvisosMeliControlador
{
    public function recibir()
    {
        $cuerpo = (string)file_get_contents('php://input');
        $this->contestar();
        try {
            $this->atender($cuerpo);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso de Mercado Libre: ' . $e->getMessage());
        }
        exit;
    }

    private function atender($cuerpo)
    {
        $j = json_decode($cuerpo, true);
        if (!is_array($j)) return;
        if (!MercadoLibre::activa()) return;

        // De nuestra aplicación, o no es para nosotros.
        $app = (string)($j['application_id'] ?? '');
        if ($app !== '' && $app !== MercadoLibre::appId()) return;

        $tema    = (string)($j['topic'] ?? '');
        $recurso = (string)($j['resource'] ?? '');
        $userId  = (string)($j['user_id'] ?? '');
        if ($userId === '' || $recurso === '') return;

        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $ruta = (new RutaMeliRepo($principal))->buscar($userId);
        if (!$ruta) return;   // una cuenta que ya nadie tiene conectada

        $db  = Conexion::de($ruta['empresa_db']);
        $srv = new MeliInventario($db);
        $c   = $srv->cuenta();
        // El índice puede haberse quedado viejo: manda lo que diga la empresa.
        if (!$c || (string)$c['meli_user_id'] !== $userId || !$srv->conectada()) return;

        if ($tema === 'orders_v2' || $tema === 'orders') {
            if (!preg_match('~^/orders/(\d+)~', $recurso, $m)) return;
            $r = $srv->procesarOrdenId($m[1]);
            if (!empty($r['nueva'])) {
                error_log('[LibertyFin] orden ML ' . $m[1] . ' → ' . $r['estado'] . ' (' . $ruta['empresa_db'] . ')');
            }
        } elseif ($tema === 'items') {
            if (!preg_match('~^/items/([A-Z]{3}\d+)~', $recurso, $m)) return;
            $srv->refrescarPublicacion($m[1]);
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
