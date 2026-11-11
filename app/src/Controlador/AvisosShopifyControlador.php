<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaShopifyRepo;
use LibertyFin\Servicio\Shopify;
use LibertyFin\Servicio\ShopifyInventario;

/**
 * Los avisos (webhooks) de Shopify.
 *
 * ── QUIÉN LLAMA ──
 * Un servidor de Shopify, sin sesión. Los avisos se dan de alta en cada
 * tienda al conectar (Shopify::asegurarAvisos). Las cabeceras dicen qué es:
 *   X-Shopify-Topic        orders/paid, products/update, app/uninstalled…
 *   X-Shopify-Shop-Domain  mitienda.myshopify.com
 *   X-Shopify-Hmac-Sha256  la firma
 * El cuerpo es la orden o el producto en formato REST; solo se usa su `id`
 * y se le vuelve a preguntar a Shopify, así un aviso viejo o repetido no
 * pisa algo más nuevo.
 *
 * También llegan aquí los obligatorios de privacidad (customers/data_request,
 * customers/redact, shop/redact), que se apuntan a esta dirección en la
 * configuración de la aplicación. LibertyFin no guarda datos de compradores
 * de Shopify, así que se contesta 200 y no hay nada que hacer.
 *
 * ── LA FIRMA SE REVISA ANTES DE CONTESTAR ──
 * A diferencia de Tienda Nube, Shopify exige un 401 cuando la firma no
 * vale (lo revisa al aprobar la aplicación). Revisarla es barato, así que
 * se hace primero y luego se contesta.
 *
 * ── LAS REGLAS DE SIEMPRE ──
 * 1. Contestar 2xx YA: Shopify espera 5 segundos y luego reintenta.
 * 2. Ser idempotente: llegan repetidos y desordenados.
 * 3. Saber de qué empresa es: `lf_ruta_shopify` (base principal), por dominio.
 */
final class AvisosShopifyControlador
{
    public function recibir()
    {
        $cuerpo = (string)file_get_contents('php://input');
        $firma  = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
        if (!Shopify::activa() || !Shopify::firmaValida($cuerpo, $firma)) {
            error_log('[LibertyFin] aviso de Shopify con firma inválida: se rechaza.');
            $this->contestar(401, '{"ok":false}');
            exit;
        }
        $tema   = strtolower(trim((string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '')));
        $tienda = strtolower(trim((string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '')));
        $this->contestar(200, '{"ok":true}');
        try {
            $this->atender($tema, $tienda, $cuerpo);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aviso de Shopify: ' . $e->getMessage());
        }
        exit;
    }

    private function atender($tema, $tienda, $cuerpo)
    {
        if ($tema === '' || !Shopify::tiendaValida($tienda)) return;
        if (in_array($tema, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) return;

        $j = json_decode($cuerpo, true);
        if (!is_array($j)) return;

        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $rutas = new RutaShopifyRepo($principal);
        $ruta  = $rutas->buscar($tienda);
        if (!$ruta) return;

        $db  = Conexion::de($ruta['empresa_db']);
        $srv = new ShopifyInventario($db);
        $c   = $srv->cuenta();
        if (!$c || strtolower((string)$c['tienda']) !== $tienda) return;

        if ($tema === 'app/uninstalled') {
            // Quitaron LibertyFin desde su panel: la llave ya no sirve.
            $srv->repo()->marcarVencida('Se desinstaló la aplicación desde Shopify. Vuelve a conectar para seguir sincronizando.');
            $rutas->soltarTienda($tienda);
            \LibertyFin\Servicio\Auditoria::anota('shopify.desinstalada', 'Shopify', $c['nombre'] ?: $tienda, null, $db);
            return;
        }

        $id = (string)($j['id'] ?? '');
        if (!$srv->conectada() || $id === '' || !ctype_digit($id)) return;

        if (strncmp($tema, 'orders/', 7) === 0) {
            $r = $srv->procesarOrdenId($id);
            if (!empty($r['nueva'])) {
                error_log('[LibertyFin] orden Shopify ' . $id . ' → ' . $r['estado'] . ' (' . $ruta['empresa_db'] . ')');
            }
        } elseif ($tema === 'products/update' || $tema === 'products/create') {
            $srv->refrescarProducto($id);
        } elseif ($tema === 'products/delete') {
            $srv->repo()->marcarProductoAusente($id);
        }
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
