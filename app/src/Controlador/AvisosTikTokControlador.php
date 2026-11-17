<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaTikTokRepo;
use LibertyFin\Servicio\TikTok;
use LibertyFin\Servicio\TikTokInventario;

/**
 * Los avisos (webhooks) de TikTok Shop.
 *
 * ── QUIÉN LLAMA ──
 * Un servidor de TikTok, sin sesión. A diferencia de Shopify, los avisos
 * NO se dan de alta por API: se configuran una vez en la app del Partner
 * Center («Webhooks»), apuntando a /tiktok/aviso. Con app pública se hace
 * una vez para todos; con «Seller in-house», cada vendedor en su app.
 *
 * El cuerpo es JSON: {"type": 1, "shop_id": "…", "timestamp": …,
 * "data": {"order_id": "…", "order_status": "…"}}. Solo se usa el id: se le
 * vuelve a preguntar a TikTok, así un aviso viejo o repetido no pisa algo
 * más nuevo.
 *
 * ── LA FIRMA ──
 * Cabecera Authorization = HMAC-SHA256(app_key + cuerpo) con el app_secret.
 * Como cada empresa puede tener SU app, primero se busca de quién es la
 * tienda (lf_ruta_tiktok, por shop_id) y luego se revisa con SUS
 * credenciales. Hasta que la firma vale, el cuerpo no se usa para nada más.
 *
 * ── LAS REGLAS DE SIEMPRE ──
 * 1. Contestar 2xx YA: TikTok reintenta si tarda.
 * 2. Ser idempotente: llegan repetidos y desordenados.
 */
final class AvisosTikTokControlador
{
    /** Avisos de pedido: los que traen data.order_id. */
    const TIPOS_ORDEN = [1, 2, 11];

    /** Aviso de producto (cambió de estado, lo aprobaron o rechazaron). */
    const TIPO_PRODUCTO = 5;

    public function recibir()
    {
        $cuerpo = (string)file_get_contents('php://input');
        $firma  = self::cabeceraAuthorization();
        $j = json_decode($cuerpo, true);
        $shopId = is_array($j) ? (string)($j['shop_id'] ?? '') : '';

        if (!TikTok::activa() || $shopId === '' || !ctype_digit($shopId)) {
            $this->contestar(401, '{"ok":false}');
            exit;
        }
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $ruta = (new RutaTikTokRepo($principal))->buscar($shopId);
        if (!$ruta) {
            // Una tienda que no conoce nadie: se contesta 200 para que TikTok
            // no reintente para siempre, y no se hace nada.
            $this->contestar(200, '{"ok":true}');
            exit;
        }

        $db  = Conexion::de($ruta['empresa_db']);
        $srv = new TikTokInventario($db);
        $c   = $srv->cuenta();
        if (!$c || (string)$c['shop_id'] !== $shopId) { $this->contestar(200, '{"ok":true}'); exit; }

        $cred = TikTok::credenciales($c);
        if (!TikTok::firmaAvisoValida($cuerpo, $firma, $cred['app_key'], $cred['app_secret'])) {
            error_log('[LibertyFin] aviso de TikTok Shop con firma inválida (' . $ruta['empresa_db'] . '): se rechaza.');
            $this->contestar(401, '{"ok":false}');
            exit;
        }
        $this->contestar(200, '{"ok":true}');
        try {
            $srv->repo()->anotarAviso();
            $this->atender($srv, $j, $ruta['empresa_db']);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso de TikTok Shop: ' . $e->getMessage());
        }
        exit;
    }

    private function atender(TikTokInventario $srv, array $j, $empresaDb)
    {
        if (!$srv->conectada()) return;
        $tipo  = (int)($j['type'] ?? 0);
        $datos = (array)($j['data'] ?? []);

        $orden = (string)($datos['order_id'] ?? '');
        if ($orden !== '' && ctype_digit($orden) && (in_array($tipo, self::TIPOS_ORDEN, true) || $tipo === 0)) {
            $r = $srv->procesarOrdenId($orden);
            if (!empty($r['nueva'])) {
                error_log('[LibertyFin] orden TikTok ' . $orden . ' → ' . $r['estado'] . ' (' . $empresaDb . ')');
            }
            return;
        }
        $producto = (string)($datos['product_id'] ?? '');
        if ($tipo === self::TIPO_PRODUCTO && $producto !== '' && ctype_digit($producto)) {
            $srv->refrescarProducto($producto);
        }
    }

    /**
     * Apache con PHP por CGI/FastCGI a veces se come «Authorization» (ver
     * Api\Entrada::deLaPeticion): se busca en los tres lugares donde llega.
     */
    private static function cabeceraAuthorization()
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
        }
        if (function_exists('apache_request_headers')) {
            foreach ((array)apache_request_headers() as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) return (string)$v;
            }
        }
        return '';
    }

    private function contestar($codigo, $txt)
    {
        http_response_code((int)$codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($txt));
        header('Connection: close');
        echo $txt;
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        @ob_end_flush(); @flush();
    }
}
