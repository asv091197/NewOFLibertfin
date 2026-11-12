<?php
/**
 * Punto de entrada único.
 *
 * Todo pasa por aquí. Nada más en public/ es ejecutable, así que no
 * existe la posibilidad de abrir un .env, un log o un include suelto
 * por URL: no viven en la raíz del sitio.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Router;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

// LF_CONFIG permite apuntar a otro archivo: lo usan las pruebas para
// levantar el sistema contra una base desechable. En el hosting no se
// define y se lee config/config.php como siempre.
$archivoCfg = getenv('LF_CONFIG') ?: $raiz . '/config/config.php';
$cfg = is_readable($archivoCfg)
     ? require $archivoCfg
     : ['bd' => ['host'=>'localhost','usuario'=>'','clave'=>'','principal'=>''], 'depurar' => true];

if (!empty($cfg['depurar'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }

// ── Zona horaria ──
// PHP y MySQL tienen que coincidir. Si no, date() arma el folio con la
// hora de México y NOW() guarda UTC: seis horas de diferencia que, en
// una venta de las 18:00 de fin de mes, la mandan al mes siguiente.
// Pasó de verdad: INITME Solutions, 31 de agosto a las 18:09, quedó
// registrada el 1 de septiembre.
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);
Plantilla::base($raiz . '/src/Vista/plantillas');
\LibertyFin\Servicio\Integraciones::cargar($raiz);
\LibertyFin\Servicio\Archivos::destino($raiz . '/public/assets/subidas');
$GLOBALS['lf_bd_principal'] = $cfg['bd']['principal'] ?? '';
// Landing, términos y privacidad (config.php → sitio), para el registro.
$GLOBALS['lf_sitio'] = (array)($cfg['sitio'] ?? []);

// ── La API ──
// Todo lo que empieza con /api/ se atiende aquí y termina aquí: sin
// sesión, sin cookie, sin redirigir al login. Se identifica con una llave
// que el administrador crea en Ajustes → API. Ver src/Api/Puerta.php y
// docs/api/README.md.
$rutaApi = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($rutaApi === '/api' || strncmp($rutaApi, '/api/', 5) === 0) {
    (new \LibertyFin\Api\Puerta((array)($cfg['api'] ?? [])))
        ->atender($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/')
        ->enviar();
    exit;
}

// ── Cookie de sesión ──
// httponly: el JavaScript no puede leerla, así que un XSS no se lleva la
// sesión. samesite Lax: no viaja desde otro sitio, que es la defensa base
// contra envíos cruzados. secure solo si hay HTTPS, o en local no entra.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// ── La landing y las puertas de entrada ──
//
// libertyfin.com.mx abre la página pública, con sesión o sin ella. El
// sistema vive en /panel, y ahí cae quien inicia sesión.
//
// Los botones de la landing y los enlaces del sistema anterior apuntan a
// /Login, /Inicio, pages/registro…: se mandan a su lugar en vez de
// terminar en un 404 o, peor, otra vez en la landing.
$rutaActual = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $alias = [
        '/login'          => '/login',
        '/ingresar'       => '/login',
        '/entrar'         => '/login',
        '/iniciar'        => '/login',
        '/iniciar-sesion' => '/login',
        '/inicio'         => '/panel',
        '/dashboard'      => '/panel',
        '/pages/registro' => '/registro',
    ];
    $minusculas = strtolower($rutaActual);
    if (isset($alias[$minusculas]) && $rutaActual !== $alias[$minusculas]) {
        header('Location: ' . $alias[$minusculas], true, 302);
        exit;
    }

    if ($rutaActual === '/') {
        $archivo = $raiz . '/src/Vista/plantillas/index.html';
        if (!is_file($archivo)) {
            // Sin landing, al menos que se pueda entrar al sistema.
            header('Location: /login', true, 302);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        readfile($archivo);
        exit;
    }
}
// ── Rutas ──
$r = new Router();

// Públicas
$r->get('/login',  ['LibertyFin\Controlador\LoginControlador', 'mostrar']);
$r->post('/login', ['LibertyFin\Controlador\LoginControlador', 'entrar']);
// "Cerrarla y entrar aquí", cuando la cuenta está abierta en otro dispositivo.
$r->post('/login/confirmar', ['LibertyFin\Controlador\LoginControlador', 'confirmar']);
// ¿Sigue abierta esta sesión? Lo pregunta cada página sola (ver SesionUnica).
$r->get('/sesion/pulso',      ['LibertyFin\Controlador\LoginControlador', 'pulso']);
// Avisos de escritorio aunque LibertyFin esté cerrado (ver Servicio\Push).
$r->get('/push/llave',         ['LibertyFin\Controlador\PushControlador', 'llave']);
$r->post('/push/suscribir',    ['LibertyFin\Controlador\PushControlador', 'suscribir']);
$r->post('/push/quitar',       ['LibertyFin\Controlador\PushControlador', 'quitar']);
$r->post('/push/probar',       ['LibertyFin\Controlador\PushControlador', 'probar']);
$r->post('/push/pendiente',    ['LibertyFin\Controlador\PushControlador', 'pendiente']);
$r->get('/salir',  ['LibertyFin\Controlador\LoginControlador', 'salir']);
$r->get('/ayuda-acceso', ['LibertyFin\Controlador\LoginControlador', 'ayudaAcceso']);
// El registro existe siempre: es a donde lleva la landing. Lo que guarda es
// una SOLICITUD, que se ve en Mantenimiento aunque cPanel no esté
// configurado; sin cPanel solo no se puede APROBAR (lo dice al intentarlo).
// Antes la ruta dependía de cPanel y, sin él, la landing llevaba a un 404.
$r->get('/registro',  ['LibertyFin\Controlador\RegistroControlador', 'formulario']);
$r->post('/registro', ['LibertyFin\Controlador\RegistroControlador', 'enviar']);
// El enlace del correo "tu cuenta está lista": el administrador de una
// empresa recién aprobada crea aquí su contraseña (ver Servicio\EnlaceClave).
$r->get('/crear-clave',  ['LibertyFin\Controlador\CrearClaveControlador', 'formulario']);
$r->post('/crear-clave', ['LibertyFin\Controlador\CrearClaveControlador', 'guardar']);

// ── Los avisos de Paga de Todo ──
// Son públicas porque el proveedor pega desde sus servidores: no trae
// cookie, ni sesión, ni usuario. Lo que las protege es el secreto que va
// en la URL (?k=...), guardado en config/integraciones.php.
//
// Las direcciones que hay que dar de alta en el panel del Sandbox son
// estas mismas con el secreto pegado. Ver LEEME.md.
//
// SE REGISTRAN SIEMPRE, aunque la integración esté apagada.
//
// Antes colgaban de `activa('spei')`: con una credencial de menos en
// config/integraciones.php las rutas NI EXISTÍAN, el proveedor recibía
// un 404 de la página de error genérica y no se escribía una sola
// línea en el registro. Desde fuera parecía que el proveedor no estaba
// llamando. Y como no recibía un 200, daba cada cobro por no
// autorizado y lo revertía: al cliente le regresaba su dinero sin que
// nadie supiera por qué.
//
// Ahora la ruta siempre responde. Si falta configuración lo dice en el
// registro, con el nombre de lo que falta.
{
    $pdt = 'LibertyFin\\Controlador\\PagosEntrantesControlador';
    // CON EL SECRETO EN LA RUTA. Es la forma recomendada: el proveedor
    // AÑADE «/?r=REFERENCIA» a la URL que se le da de alta, y pegado
    // detrás de un «?k=SECRETO» rompe el secreto y la referencia a la
    // vez. En la ruta no estorba.
    $r->get   ('/pagadetodo/{k}/consulta-referencia', [$pdt, 'consultaReferencia']);
    $r->post  ('/pagadetodo/{k}/pago-referencia',     [$pdt, 'pagoReferencia']);
    $r->get   ('/pagadetodo/{k}/consulta-clabe',      [$pdt, 'consultaClabe']);
    $r->post  ('/pagadetodo/{k}/pago-clabe',          [$pdt, 'pagoClabe']);
    $r->post  ('/pagadetodo/{k}/pago-liga',           [$pdt, 'pagoLiga']);
    $r->post  ('/pagadetodo/{k}/cancela-pago',        [$pdt, 'cancelaPago']);
    $r->delete('/pagadetodo/{k}/cancela-pago',        [$pdt, 'cancelaPago']);

    // Y las de siempre, para lo que ya esté dado de alta con ?k=
    $r->get ('/pagadetodo/consulta-referencia', [$pdt, 'consultaReferencia']);
    $r->post('/pagadetodo/pago-referencia',     [$pdt, 'pagoReferencia']);
    $r->get ('/pagadetodo/consulta-clabe',      [$pdt, 'consultaClabe']);
    $r->post('/pagadetodo/pago-clabe',          [$pdt, 'pagoClabe']);
    $r->post('/pagadetodo/pago-liga',           [$pdt, 'pagoLiga']);
    // La cancelación llega por DELETE o por POST: el proveedor decide.
    $r->post  ('/pagadetodo/cancela-pago', [$pdt, 'cancelaPago']);
    $r->delete('/pagadetodo/cancela-pago', [$pdt, 'cancelaPago']);

    // Y LAS DEL SISTEMA ANTERIOR, que la cuenta de producción del
    // proveedor todavía tiene dadas de alta. Sin secreto: las protege la
    // IP de origen. Ver PagosEntrantesControlador::RUTAS_ANTERIORES.
    $r->get   ('/Service/ConsultaClabe',      [$pdt, 'consultaClabe']);
    $r->post  ('/Service/PagoClabe',          [$pdt, 'pagoClabe']);
    $r->get   ('/Service/ConsultaReferencia', [$pdt, 'consultaReferencia']);
    $r->post  ('/Service/PagoReferencia',     [$pdt, 'pagoReferencia']);
    $r->post  ('/Service/PagoLinea',          [$pdt, 'pagoLiga']);
    $r->post  ('/Service/EntregarPagoLiga',   [$pdt, 'pagoLiga']);
    $r->post  ('/Service/CancelaPago',        [$pdt, 'cancelaPago']);
    $r->delete('/Service/CancelaPago',        [$pdt, 'cancelaPago']);
}

// Privadas
// La raíz lleva a un panel u otro según el nivel del rol. Un rol de
// plataforma en el panel de empresa vería las ventas de quien le prestó
// la sesión, y las leería como del cliente que llamó.
if (\LibertyFin\Dominio\Permisos::esPlataforma($_SESSION['usuario_rol'] ?? '')) {
    $r->get('/panel', ['LibertyFin\Controlador\SoporteControlador', 'panel']);
} else {
    $r->get('/panel', ['LibertyFin\Controlador\PanelControlador', 'index']);
}
$r->get('/ventas',  ['LibertyFin\Controlador\VentasControlador', 'index']);
// Traer a LibertyFin la hoja de ventas que se llevaba en Excel.
$r->get ('/importar',          ['LibertyFin\Controlador\ImportarControlador', 'index']);
$r->post('/importar/analizar', ['LibertyFin\Controlador\ImportarControlador', 'analizar']);
$r->post('/importar/ajustar',  ['LibertyFin\Controlador\ImportarControlador', 'ajustar']);
$r->post('/importar/aplicar',  ['LibertyFin\Controlador\ImportarControlador', 'aplicar']);
$r->get ('/importar/cancelar', ['LibertyFin\Controlador\ImportarControlador', 'cancelar']);
$r->get ('/importar/ejemplo',  ['LibertyFin\Controlador\ImportarControlador', 'ejemplo']);
$r->get('/ventas/{id}',               ['LibertyFin\Controlador\VentasControlador', 'ver']);
$r->get('/ventas/{id}/ticket',        ['LibertyFin\Controlador\VentasControlador', 'ticket']);
$r->post('/ventas/{id}/pagar',        ['LibertyFin\Controlador\VentasControlador', 'pagar']);
$r->post('/ventas/{id}/cancelar-pago',['LibertyFin\Controlador\VentasControlador', 'cancelarPago']);
$r->post('/ventas/{id}/comision',        ['LibertyFin\Controlador\VentasControlador', 'asignarComision']);
$r->post('/ventas/{id}/quitar-comision', ['LibertyFin\Controlador\VentasControlador', 'quitarComision']);
$r->get('/caja',          ['LibertyFin\Controlador\CajaControlador', 'index']);
$r->get('/caja/clientes', ['LibertyFin\Controlador\CajaControlador', 'clientes']);
// Las ventas a las que se les puede agregar algo, para no abrir otro
// folio cuando el cliente se acuerda de un servicio mas.
$r->get('/caja/ventas-abiertas', ['LibertyFin\Controlador\CajaControlador', 'ventasAbiertas']);
$r->get('/qr',              ['LibertyFin\Controlador\CajaControlador', 'qr']);
$r->get('/caja/estado/{id}', ['LibertyFin\Controlador\CajaControlador', 'estado']);
$r->post('/caja/cobrar',  ['LibertyFin\Controlador\CajaControlador', 'cobrar']);
// El descuento de las promociones mientras se arma el ticket. Lo calcula el
// servidor, el mismo que lo vuelve a calcular al cobrar.
$r->post('/caja/promociones', ['LibertyFin\Controlador\CajaControlador', 'promociones']);
$r->get('/comisiones',            ['LibertyFin\Controlador\ComisionesControlador', 'index']);
$r->get('/comisiones/colaborador/{id}', ['LibertyFin\Controlador\ComisionesControlador', 'colaborador']);
$r->post('/comisiones/reasignar', ['LibertyFin\Controlador\ComisionesControlador', 'reasignar']);
$r->get('/comisiones/lote',      ['LibertyFin\Controlador\ComisionesControlador', 'lote']);
$r->post('/comisiones/lote',     ['LibertyFin\Controlador\ComisionesControlador', 'asignarLote']);
$r->post('/comisiones/recalcular', ['LibertyFin\Controlador\ComisionesControlador', 'recalcular']);
$r->post('/comisiones/recalcular/deshacer', ['LibertyFin\Controlador\ComisionesControlador', 'deshacerRecalculo']);
$r->get('/clientes',  ['LibertyFin\Controlador\ClientesControlador',  'index']);
$r->get('/cobranza',  ['LibertyFin\Controlador\CobranzaControlador',  'index']);
$r->get('/gastos',          ['LibertyFin\Controlador\GastosControlador', 'index']);
$r->post('/gastos/guardar', ['LibertyFin\Controlador\GastosControlador', 'guardar']);
$r->post('/gastos/borrar',  ['LibertyFin\Controlador\GastosControlador', 'borrar']);
$r->post('/gastos/proveedor',          ['LibertyFin\Controlador\GastosControlador', 'guardarProveedor']);
$r->post('/gastos/proveedor/alternar', ['LibertyFin\Controlador\GastosControlador', 'alternarProveedor']);
$r->get('/servicios', ['LibertyFin\Controlador\ServiciosControlador', 'index']);
$r->post('/clientes/guardar',   ['LibertyFin\Controlador\ClientesControlador',  'guardar']);
$r->post('/servicios/guardar',  ['LibertyFin\Controlador\ServiciosControlador', 'guardar']);
$r->post('/servicios/alternar', ['LibertyFin\Controlador\ServiciosControlador', 'alternar']);
$r->get('/servicios/claves-sat', ['LibertyFin\Controlador\ServiciosControlador', 'clavesSat']);
$r->post('/servicios/promociones/guardar',  ['LibertyFin\Controlador\ServiciosControlador', 'guardarPromocion']);
$r->post('/servicios/promociones/alternar', ['LibertyFin\Controlador\ServiciosControlador', 'alternarPromocion']);
$r->post('/servicios/promociones/borrar',   ['LibertyFin\Controlador\ServiciosControlador', 'borrarPromocion']);
// ── Núcleo: cancelar una venta y la ficha del cliente (con su expediente) ──
$r->post('/ventas/{id}/cancelar',        ['LibertyFin\Controlador\VentasControlador', 'cancelar']);
$r->post('/ventas/{id}/trabajo',         ['LibertyFin\Controlador\SeguimientoControlador', 'guardarDesdeVenta']);
$r->get('/clientes/{id}',                ['LibertyFin\Controlador\ExpedienteControlador', 'ver']);
$r->post('/clientes/{id}/ficha',         ['LibertyFin\Controlador\ExpedienteControlador', 'ficha']);
$r->post('/clientes/{id}/nota',          ['LibertyFin\Controlador\ExpedienteControlador', 'nota']);
$r->post('/clientes/{id}/nota/{nid}/borrar', ['LibertyFin\Controlador\ExpedienteControlador', 'borrarNota']);
$r->post('/clientes/{id}/archivo',       ['LibertyFin\Controlador\ExpedienteControlador', 'subir']);
$r->get('/clientes/{id}/archivo/{aid}',  ['LibertyFin\Controlador\ExpedienteControlador', 'bajar']);
$r->post('/clientes/{id}/archivo/{aid}/borrar', ['LibertyFin\Controlador\ExpedienteControlador', 'borrarArchivo']);
// ── Módulos (Servicio\Modulos) ──
$r->get('/cotizaciones',                 ['LibertyFin\Controlador\CotizacionesControlador', 'index']);
$r->post('/cotizaciones/guardar',        ['LibertyFin\Controlador\CotizacionesControlador', 'guardar']);
$r->get('/cotizaciones/{id}',            ['LibertyFin\Controlador\CotizacionesControlador', 'ver']);
$r->post('/cotizaciones/{id}/estado',    ['LibertyFin\Controlador\CotizacionesControlador', 'estado']);
$r->get('/cotizaciones/{id}/imprimir',   ['LibertyFin\Controlador\CotizacionesControlador', 'imprimir']);
// El aviso de la paquetería. Lo llama un servidor de Envia, sin sesión:
// su puerta es la firma del cuerpo, que revisa el controlador.
$r->post('/envia/aviso',                 ['LibertyFin\Controlador\AvisosEnviaControlador', 'recibir']);
$r->get('/envios',                       ['LibertyFin\Controlador\EnviosControlador', 'index']);
$r->get('/envios/nuevo',                 ['LibertyFin\Controlador\EnviosControlador', 'nuevo']);
$r->post('/envios/cotizar',              ['LibertyFin\Controlador\EnviosControlador', 'cotizar']);
$r->post('/envios/generar',              ['LibertyFin\Controlador\EnviosControlador', 'generar']);
$r->get('/envios/cp',                    ['LibertyFin\Controlador\EnviosControlador', 'cp']);
$r->get('/envios/sucursales',            ['LibertyFin\Controlador\EnviosControlador', 'sucursalesEnvia']);
$r->get('/envios/origenes',              ['LibertyFin\Controlador\EnviosControlador', 'origenes']);
$r->post('/envios/origenes',             ['LibertyFin\Controlador\EnviosControlador', 'guardarOrigen']);
$r->get('/envios/{id}',                  ['LibertyFin\Controlador\EnviosControlador', 'ver']);
$r->post('/envios/{id}/rastrear',        ['LibertyFin\Controlador\EnviosControlador', 'rastrear']);
$r->post('/envios/{id}/cancelar',        ['LibertyFin\Controlador\EnviosControlador', 'cancelar']);
// ── Mercado Libre ──
// El aviso lo llama un servidor de Mercado Libre, sin sesión: no trae
// datos, solo QUÉ cambió, y se le vuelve a preguntar con la llave de la
// cuenta (ver AvisosMeliControlador).
$r->post('/mercadolibre/aviso',          ['LibertyFin\Controlador\AvisosMeliControlador', 'recibir']);
$r->get('/mercadolibre',                 ['LibertyFin\Controlador\MercadoLibreControlador', 'index']);
$r->post('/mercadolibre/conectar',       ['LibertyFin\Controlador\MercadoLibreControlador', 'conectar']);
// A donde regresa Mercado Libre al autorizar. Tiene que ser EXACTAMENTE la
// dirección dada de alta en la aplicación (config/integraciones.php → retorno).
$r->get('/mercadolibre/retorno',         ['LibertyFin\Controlador\MercadoLibreControlador', 'retorno']);
$r->post('/mercadolibre/desconectar',    ['LibertyFin\Controlador\MercadoLibreControlador', 'desconectar']);
$r->post('/mercadolibre/ajustes',        ['LibertyFin\Controlador\MercadoLibreControlador', 'ajustes']);
$r->post('/mercadolibre/importar',       ['LibertyFin\Controlador\MercadoLibreControlador', 'importar']);
$r->post('/mercadolibre/vincular',       ['LibertyFin\Controlador\MercadoLibreControlador', 'vincular']);
$r->post('/mercadolibre/sincronizar',    ['LibertyFin\Controlador\MercadoLibreControlador', 'sincronizar']);
$r->get('/mercadolibre/publicar',        ['LibertyFin\Controlador\MercadoLibreControlador', 'publicar']);
$r->post('/mercadolibre/publicar',       ['LibertyFin\Controlador\MercadoLibreControlador', 'publicarGuardar']);
// Tienda Nube: igual que Mercado Libre. El aviso lo llama un servidor suyo,
// sin sesión, y viene FIRMADO con el secreto de la aplicación (ver AvisosTnControlador).
$r->post('/tiendanube/aviso',            ['LibertyFin\Controlador\AvisosTnControlador', 'recibir']);
$r->get('/tiendanube',                   ['LibertyFin\Controlador\TiendaNubeControlador', 'index']);
$r->post('/tiendanube/conectar',         ['LibertyFin\Controlador\TiendaNubeControlador', 'conectar']);
// A donde regresa Tienda Nube al autorizar: la «URL de redirección» del portal de socios.
$r->get('/tiendanube/retorno',           ['LibertyFin\Controlador\TiendaNubeControlador', 'retorno']);
$r->post('/tiendanube/desconectar',      ['LibertyFin\Controlador\TiendaNubeControlador', 'desconectar']);
$r->post('/tiendanube/ajustes',          ['LibertyFin\Controlador\TiendaNubeControlador', 'ajustes']);
$r->post('/tiendanube/avisos',           ['LibertyFin\Controlador\TiendaNubeControlador', 'avisos']);
$r->post('/tiendanube/importar',         ['LibertyFin\Controlador\TiendaNubeControlador', 'importar']);
$r->post('/tiendanube/vincular',         ['LibertyFin\Controlador\TiendaNubeControlador', 'vincular']);
$r->post('/tiendanube/sincronizar',      ['LibertyFin\Controlador\TiendaNubeControlador', 'sincronizar']);
$r->get('/tiendanube/publicar',          ['LibertyFin\Controlador\TiendaNubeControlador', 'publicar']);
$r->post('/tiendanube/publicar',         ['LibertyFin\Controlador\TiendaNubeControlador', 'publicarGuardar']);
// Shopify: igual que Tienda Nube. El aviso lo llama un servidor suyo, sin
// sesión, FIRMADO con el secreto de la aplicación (ver AvisosShopifyControlador).
$r->post('/shopify/aviso',               ['LibertyFin\Controlador\AvisosShopifyControlador', 'recibir']);
$r->get('/shopify',                      ['LibertyFin\Controlador\ShopifyControlador', 'index']);
$r->post('/shopify/conectar',            ['LibertyFin\Controlador\ShopifyControlador', 'conectar']);
// A donde regresa Shopify al autorizar: tiene que estar en «Allowed redirection URL(s)» de la app.
$r->get('/shopify/retorno',              ['LibertyFin\Controlador\ShopifyControlador', 'retorno']);
$r->post('/shopify/desconectar',         ['LibertyFin\Controlador\ShopifyControlador', 'desconectar']);
$r->post('/shopify/ajustes',             ['LibertyFin\Controlador\ShopifyControlador', 'ajustes']);
$r->post('/shopify/avisos',              ['LibertyFin\Controlador\ShopifyControlador', 'avisos']);
$r->post('/shopify/importar',            ['LibertyFin\Controlador\ShopifyControlador', 'importar']);
$r->post('/shopify/vincular',            ['LibertyFin\Controlador\ShopifyControlador', 'vincular']);
$r->post('/shopify/sincronizar',         ['LibertyFin\Controlador\ShopifyControlador', 'sincronizar']);
$r->get('/shopify/publicar',             ['LibertyFin\Controlador\ShopifyControlador', 'publicar']);
$r->post('/shopify/publicar',            ['LibertyFin\Controlador\ShopifyControlador', 'publicarGuardar']);
$r->get('/igualas',                      ['LibertyFin\Controlador\IgualasControlador', 'index']);
$r->post('/igualas/guardar',             ['LibertyFin\Controlador\IgualasControlador', 'guardar']);
$r->post('/igualas/alternar',            ['LibertyFin\Controlador\IgualasControlador', 'alternar']);
$r->post('/igualas/generar',             ['LibertyFin\Controlador\IgualasControlador', 'generar']);
$r->get('/seguimiento',                  ['LibertyFin\Controlador\SeguimientoControlador', 'index']);
$r->post('/seguimiento/guardar',         ['LibertyFin\Controlador\SeguimientoControlador', 'guardar']);
$r->get('/compras',                      ['LibertyFin\Controlador\ComprasControlador', 'index']);
$r->post('/compras/guardar',             ['LibertyFin\Controlador\ComprasControlador', 'guardar']);
$r->get('/compras/{id}',                 ['LibertyFin\Controlador\ComprasControlador', 'ver']);
$r->post('/compras/{id}/recibir',        ['LibertyFin\Controlador\ComprasControlador', 'recibir']);
$r->post('/compras/{id}/cancelar',       ['LibertyFin\Controlador\ComprasControlador', 'cancelar']);
$r->post('/inventario/conteo',           ['LibertyFin\Controlador\InventarioControlador', 'conteo']);
// Capturar entradas y salidas con la pistola, en modo concentrado.
$r->get('/inventario/captura',           ['LibertyFin\Controlador\InventarioControlador', 'captura']);
$r->post('/inventario/captura',          ['LibertyFin\Controlador\InventarioControlador', 'capturaAccion']);
$r->get('/agenda',                       ['LibertyFin\Controlador\AgendaControlador', 'index']);
$r->post('/agenda/guardar',              ['LibertyFin\Controlador\AgendaControlador', 'guardar']);
$r->post('/agenda/estado',               ['LibertyFin\Controlador\AgendaControlador', 'estado']);
$r->post('/agenda/horario',              ['LibertyFin\Controlador\AgendaControlador', 'horario']);
$r->get('/caja/paquetes',                ['LibertyFin\Controlador\CajaControlador', 'paquetes']);
$r->get('/bienvenida',         ['LibertyFin\Controlador\BienvenidaControlador', 'index']);
$r->post('/bienvenida',        ['LibertyFin\Controlador\BienvenidaControlador', 'guardar']);
$r->get('/inventario',         ['LibertyFin\Controlador\InventarioControlador', 'index']);
$r->post('/inventario/mover',  ['LibertyFin\Controlador\InventarioControlador', 'mover']);
$r->get('/reportes',     ['LibertyFin\Controlador\ReportesControlador', 'index']);
// Ligas de pago: solo con credenciales de SPEI.
if (\LibertyFin\Servicio\Integraciones::activa('spei')) {
    $r->get('/ligas',          ['LibertyFin\Controlador\LigasControlador', 'index']);
    $r->post('/ligas/generar', ['LibertyFin\Controlador\LigasControlador', 'generar']);
    $r->post('/ligas/revisar', ['LibertyFin\Controlador\LigasControlador', 'revisar']);
    $r->get('/ligas/{id}/documento', ['LibertyFin\Controlador\LigasControlador', 'documento']);
    $r->post('/ligas/confirmar', ['LibertyFin\Controlador\LigasControlador', 'confirmar']);
$r->post('/ligas/aprobar', ['LibertyFin\Controlador\LigasControlador', 'aprobar']);
}

// Facturación: igual que recargas, solo existe con credenciales.
if (\LibertyFin\Servicio\Integraciones::activa('facturapi')) {
    $r->get('/facturacion',              ['LibertyFin\Controlador\FacturacionControlador', 'index']);
    $r->get('/facturacion/{id}/timbrar', ['LibertyFin\Controlador\FacturacionControlador', 'timbrar']);
    $r->get('/facturacion/{id}/descargar', ['LibertyFin\Controlador\FacturacionControlador', 'descargarCfdi']);
    $r->get('/facturacion/{id}/pagos', ['LibertyFin\Controlador\FacturacionControlador', 'pagos']);
    $r->post('/facturacion/{id}/complemento/{pagoId}', ['LibertyFin\Controlador\FacturacionControlador', 'timbrarComplemento']);
    $r->get('/facturacion/complemento/{pagoId}/descargar', ['LibertyFin\Controlador\FacturacionControlador', 'descargarComplemento']);
}

// Recargas: la ruta solo existe si hay credenciales. Sin ellas, 404.
if (\LibertyFin\Servicio\Integraciones::activa('emida')) {
    $r->get('/recargas',          ['LibertyFin\Controlador\RecargasControlador', 'index']);
    $r->get('/recargas/saldo',    ['LibertyFin\Controlador\RecargasControlador', 'saldo']);
    $r->get('/recargas/productos',['LibertyFin\Controlador\RecargasControlador', 'productos']);
    $r->post('/recargas/ocultos', ['LibertyFin\Controlador\RecargasControlador', 'mostrarOcultos']);
    $r->post('/recargas/catalogo',['LibertyFin\Controlador\RecargasControlador', 'sincronizar']);
    $r->post('/recargas/vender',  ['LibertyFin\Controlador\RecargasControlador', 'vender']);
}
$r->get('/reportes/excel',    ['LibertyFin\Controlador\ReportesControlador', 'excel']);
$r->get('/reportes/imprimir', ['LibertyFin\Controlador\ReportesControlador', 'imprimir']);
$r->get('/ayuda',                 ['LibertyFin\Controlador\AyudaControlador', 'index']);
$r->get('/tutorial',              ['LibertyFin\Controlador\AyudaControlador', 'tutorial']);
$r->post('/ayuda/crear',          ['LibertyFin\Controlador\AyudaControlador', 'crear']);
$r->post('/ayuda/{id}/responder', ['LibertyFin\Controlador\AyudaControlador', 'responder']);
// El chat: mensajes nuevos de un ticket y novedades de soporte (JSON).
$r->get('/ayuda/novedades',       ['LibertyFin\Controlador\AyudaControlador', 'novedades']);
$r->get('/ayuda/{id}/mensajes',   ['LibertyFin\Controlador\AyudaControlador', 'mensajes']);
$r->post('/ayuda/{id}/escribiendo', ['LibertyFin\Controlador\AyudaControlador', 'escribiendo']);
$r->post('/ayuda/{id}/adjunto',     ['LibertyFin\Controlador\AyudaControlador', 'adjunto']);
$r->get('/plataforma',            ['LibertyFin\Controlador\PlataformaControlador', 'index']);
$r->post('/plataforma/usuario',   ['LibertyFin\Controlador\PlataformaControlador', 'guardarUsuario']);
$r->post('/plataforma/alternar',  ['LibertyFin\Controlador\PlataformaControlador', 'alternarUsuario']);
$r->post('/plataforma/clave',     ['LibertyFin\Controlador\PlataformaControlador', 'claveUsuario']);
$r->post('/plataforma/empresa',   ['LibertyFin\Controlador\PlataformaControlador', 'suspenderEmpresa']);
$r->post('/plataforma/global',    ['LibertyFin\Controlador\PlataformaControlador', 'alternarGlobal']);
$r->get('/informes',              ['LibertyFin\Controlador\InformesControlador', 'index']);
$r->get('/conocimiento',          ['LibertyFin\Controlador\ConocimientoControlador', 'index']);
$r->post('/conocimiento/guardar', ['LibertyFin\Controlador\ConocimientoControlador', 'guardar']);
$r->post('/conocimiento/alternar',['LibertyFin\Controlador\ConocimientoControlador', 'alternar']);
// AUDITORÍA: una sección con tres pestañas. Incoherencias va primero porque
// es la que contesta "¿qué está mal?"; la comparación contra un Excel revisa
// lo capturado contra la hoja que se llevaba aparte; la Bitácora dice quién
// hizo qué. /incoherencias se queda para no romper enlaces guardados.
$r->get('/auditoria',               ['LibertyFin\Controlador\IncoherenciasControlador', 'index']);
$r->get('/auditoria/excel',         ['LibertyFin\Controlador\CompararExcelControlador', 'index']);
$r->post('/auditoria/excel',        ['LibertyFin\Controlador\CompararExcelControlador', 'subir']);
$r->get('/auditoria/excel/quitar',  ['LibertyFin\Controlador\CompararExcelControlador', 'quitar']);
$r->get('/auditoria/bitacora',      ['LibertyFin\Controlador\AuditoriaControlador', 'index']);
$r->get('/incoherencias',           ['LibertyFin\Controlador\IncoherenciasControlador', 'aAuditoria']);
$r->get('/tickets',               ['LibertyFin\Controlador\TicketsControlador', 'index']);
$r->post('/tickets/crear',        ['LibertyFin\Controlador\TicketsControlador', 'crear']);
$r->get('/tickets/{id}',          ['LibertyFin\Controlador\TicketsControlador', 'ver']);
$r->post('/tickets/{id}/responder',['LibertyFin\Controlador\TicketsControlador', 'responder']);
$r->get('/tickets/{id}/mensajes', ['LibertyFin\Controlador\TicketsControlador', 'mensajes']);
// Lo que espera respuesta de soporte (JSON): cuenta del menú y avisos.
$r->get('/tickets/novedades',     ['LibertyFin\Controlador\TicketsControlador', 'novedades']);
$r->post('/tickets/{id}/escribiendo', ['LibertyFin\Controlador\TicketsControlador', 'escribiendo']);
$r->post('/tickets/{id}/adjunto',     ['LibertyFin\Controlador\TicketsControlador', 'adjunto']);
$r->post('/tickets/{id}/cambiar', ['LibertyFin\Controlador\TicketsControlador', 'cambiar']);
$r->get('/soporte',               ['LibertyFin\Controlador\SoporteControlador', 'index']);
$r->get('/soporte/{id}',          ['LibertyFin\Controlador\SoporteControlador', 'ficha']);
$r->post('/soporte/restablecer',  ['LibertyFin\Controlador\SoporteControlador', 'restablecer']);
$r->post('/soporte/alternar',     ['LibertyFin\Controlador\SoporteControlador', 'alternar']);
$r->post('/soporte/ajuste',       ['LibertyFin\Controlador\SoporteControlador', 'alternarAjuste']);
$r->get('/mantenimiento',           ['LibertyFin\Controlador\MantenimientoControlador', 'index']);
$r->post('/mantenimiento/secciones',['LibertyFin\Controlador\MantenimientoControlador', 'secciones']);
$r->post('/mantenimiento/migrar',   ['LibertyFin\Controlador\MantenimientoControlador', 'migrar']);
$r->post('/mantenimiento/revisar',  ['LibertyFin\Controlador\MantenimientoControlador', 'revisar']);
$r->post('/mantenimiento/pago',     ['LibertyFin\Controlador\MantenimientoControlador', 'revisarPago']);
$r->post('/mantenimiento/correo',   ['LibertyFin\Controlador\MantenimientoControlador', 'probarCorreo']);
$r->post('/mantenimiento/empresa/aprobar',  ['LibertyFin\Controlador\MantenimientoControlador', 'aprobarEmpresa']);
$r->post('/mantenimiento/empresa/rechazar', ['LibertyFin\Controlador\MantenimientoControlador', 'rechazarEmpresa']);
$r->get('/ajustes',          ['LibertyFin\Controlador\AjustesControlador', 'index']);
$r->post('/ajustes/guardar', ['LibertyFin\Controlador\AjustesControlador', 'guardar']);
$r->post('/ajustes/alternar',['LibertyFin\Controlador\AjustesControlador', 'alternar']);
$r->post('/ajustes/aprobacion',['LibertyFin\Controlador\AjustesControlador', 'aprobacion']);
// Las llaves de la API: Ajustes → API. La API misma vive en src/Api.
$r->post('/ajustes/api/crear',  ['LibertyFin\Controlador\AjustesControlador', 'crearLlave']);
$r->post('/ajustes/api/revocar',['LibertyFin\Controlador\AjustesControlador', 'revocarLlave']);
$r->get('/usuarios',             ['LibertyFin\Controlador\UsuariosControlador', 'index']);
$r->post('/usuarios/guardar',    ['LibertyFin\Controlador\UsuariosControlador', 'guardar']);
$r->post('/usuarios/restablecer',['LibertyFin\Controlador\UsuariosControlador', 'restablecer']);
$r->post('/usuarios/alternar',   ['LibertyFin\Controlador\UsuariosControlador', 'alternar']);
$r->get('/cuenta',               ['LibertyFin\Controlador\UsuariosControlador', 'miCuenta']);
$r->post('/cuenta/clave',        ['LibertyFin\Controlador\UsuariosControlador', 'cambiarClave']);
$r->post('/cuenta/foto',         ['LibertyFin\Controlador\UsuariosControlador', 'guardarFoto']);
$r->post('/cuenta/foto-publica', ['LibertyFin\Controlador\UsuariosControlador', 'fotoPublica']);
$r->post('/cuenta/apariencia',   ['LibertyFin\Controlador\UsuariosControlador', 'apariencia']);
$r->post('/cuenta/fiscales',     ['LibertyFin\Controlador\UsuariosControlador', 'guardarFiscales']);
$r->post('/cuenta/comercio',     ['LibertyFin\Controlador\UsuariosControlador', 'guardarComercio']);
$r->post('/cuenta/documento',    ['LibertyFin\Controlador\UsuariosControlador', 'subirDocumento']);
$r->post('/cuenta/plan',         ['LibertyFin\Controlador\UsuariosControlador', 'solicitarPlan']);
$r->post('/cuenta/plan/comprobante', ['LibertyFin\Controlador\UsuariosControlador', 'comprobantePlan']);
$r->get('/cuenta/plan/estado/{id}', ['LibertyFin\Controlador\UsuariosControlador', 'estadoPlan']);
$r->get('/cuenta/plan/liga/{id}/documento', ['LibertyFin\Controlador\UsuariosControlador', 'documentoPlan']);
$r->post('/guia/vista',          ['LibertyFin\Controlador\UsuariosControlador', 'guiaVista']);
$r->get('/guia',                 ['LibertyFin\Controlador\UsuariosControlador', 'verGuia']);
$r->get('/corte',        ['LibertyFin\Controlador\CorteControlador', 'index']);
$r->post('/corte/abrir', ['LibertyFin\Controlador\CorteControlador', 'abrir']);
$r->post('/corte/cerrar',['LibertyFin\Controlador\CorteControlador', 'cerrar']);
$r->post('/corte/cerrar-ajena', ['LibertyFin\Controlador\CorteControlador', 'cerrarAjena']);
// Cambio de cuenta: el admin escribe la SUYA y entra como el cajero.
$r->post('/corte/ver-como',     ['LibertyFin\Controlador\CorteControlador', 'verComo']);
$r->post('/corte/volver-yo',    ['LibertyFin\Controlador\CorteControlador', 'volverYo']);

// ── Qué permiso pide cada ruta ──
// El router ya no solo dice si la ruta existe: dice quién puede entrar.
// Esconder el enlace del menú es cortesía; esto es la puerta.
$permisos = [
  // La raíz no lleva permiso: cada rol va a su propio panel.
  '/ventas'                 => 'ver.ventas',
  '/importar'               => 'importar.ventas',
  '/importar/analizar'      => 'importar.ventas',
  '/importar/ajustar'       => 'importar.ventas',
  '/importar/aplicar'       => 'importar.ventas',
  '/importar/cancelar'      => 'importar.ventas',
  '/importar/ejemplo'       => 'importar.ventas',
  '/ventas/{id}'            => 'ver.ventas',
  '/ventas/{id}/ticket'     => 'ver.ventas',
  '/ventas/{id}/pagar'      => 'abonar',
  '/ventas/{id}/cancelar-pago' => 'cancelar.pago',
  '/ventas/{id}/comision'   => 'asignar.comision',
  '/ventas/{id}/quitar-comision' => 'quitar.comision',
  '/caja'                   => 'cobrar',
  '/caja/clientes'          => 'cobrar',
  '/caja/ventas-abiertas'   => 'cobrar',
  '/caja/cobrar'            => 'cobrar',
  '/caja/promociones'       => 'cobrar',
  '/caja/estado/{id}'       => 'cobrar',
  '/qr'                     => 'cobrar',
  '/cobranza'               => 'ver.cobranza',
  '/corte'                  => 'ver.corte',
  '/corte/abrir'            => 'abrir.caja',
  '/corte/cerrar'           => 'cerrar.caja',
  '/corte/cerrar-ajena'     => 'ver.usuarios',
  // Entrar como otro es cosa de quien administra, no de quien cobra.
  '/corte/ver-como'         => 'ver.usuarios',
  // `/corte/volver-yo` NO aparece en esta lista a propósito: quien está
  // suplantando tiene los permisos del suplantado, así que pedirle el de
  // administrador para salir lo dejaría encerrado en la otra cuenta.
  '/clientes'               => 'ver.clientes',
  '/clientes/guardar'       => 'editar.clientes',
  '/comisiones'             => 'ver.comisiones',
  '/comisiones/colaborador/{id}' => 'ver.comisiones',
  '/comisiones/reasignar'   => 'asignar.comision',
  '/comisiones/lote'        => 'asignar.comision',
  '/comisiones/recalcular'  => 'asignar.comision',
  '/comisiones/recalcular/deshacer' => 'asignar.comision',
  '/gastos'                 => 'ver.gastos',
  '/gastos/guardar'         => 'editar.gastos',
  '/gastos/borrar'          => 'borrar.gastos',
  '/gastos/proveedor'       => 'editar.gastos',
  '/gastos/proveedor/alternar' => 'editar.gastos',
  '/servicios'              => 'ver.servicios',
  '/servicios/guardar'      => 'editar.servicios',
  '/servicios/alternar'     => 'editar.servicios',
  '/servicios/claves-sat'   => 'editar.servicios',
  '/servicios/promociones/guardar'  => 'editar.servicios',
  '/servicios/promociones/alternar' => 'editar.servicios',
  '/servicios/promociones/borrar'   => 'editar.servicios',
  // La configuración inicial cambia ajustes de la empresa: solo administración.
  '/bienvenida'             => 'editar.ajustes',
  '/ventas/{id}/cancelar'   => 'cancelar.venta',
  '/ventas/{id}/trabajo'    => 'editar.seguimiento',
  '/clientes/{id}'          => 'ver.clientes',
  '/clientes/{id}/ficha'    => 'editar.expediente',
  '/clientes/{id}/nota'     => 'editar.expediente',
  '/clientes/{id}/nota/{nid}/borrar' => 'editar.expediente',
  '/clientes/{id}/archivo'  => 'editar.expediente',
  '/clientes/{id}/archivo/{aid}' => 'ver.expediente',
  '/clientes/{id}/archivo/{aid}/borrar' => 'editar.expediente',
  '/cotizaciones'           => 'ver.cotizaciones',
  '/cotizaciones/guardar'   => 'editar.cotizaciones',
  '/cotizaciones/{id}'      => 'ver.cotizaciones',
  '/cotizaciones/{id}/estado' => 'editar.cotizaciones',
  '/cotizaciones/{id}/imprimir' => 'ver.cotizaciones',
  '/envios'                 => 'ver.envios',
  '/envios/nuevo'           => 'editar.envios',
  '/envios/cotizar'         => 'editar.envios',
  '/envios/generar'         => 'editar.envios',
  '/envios/cp'              => 'ver.envios',
  '/envios/sucursales'      => 'ver.envios',
  // El domicilio de salida se captura una vez y sale en todas las
  // guías: lo toca quien configura, no quien despacha.
  '/envios/origenes'        => 'editar.ajustes',
  '/envios/{id}'            => 'ver.envios',
  '/envios/{id}/rastrear'   => 'ver.envios',
  '/envios/{id}/cancelar'   => 'editar.envios',
  '/mercadolibre'             => 'ver.mercadolibre',
  // Conectar, desconectar y decidir de dónde sale lo que se vende allá
  // compromete la cuenta de vendedor de la empresa: es de administrador.
  '/mercadolibre/conectar'    => 'editar.ajustes',
  '/mercadolibre/retorno'     => 'editar.ajustes',
  '/mercadolibre/desconectar' => 'editar.ajustes',
  '/mercadolibre/ajustes'     => 'editar.ajustes',
  '/mercadolibre/importar'    => 'editar.mercadolibre',
  '/mercadolibre/vincular'    => 'editar.mercadolibre',
  '/mercadolibre/sincronizar' => 'editar.mercadolibre',
    '/mercadolibre/publicar'    => 'editar.mercadolibre',
  '/tiendanube'               => 'ver.tiendanube',
  // Conectar la tienda de la empresa es de administrador, como en Mercado Libre.
  '/tiendanube/conectar'      => 'editar.ajustes',
  '/tiendanube/retorno'       => 'editar.ajustes',
  '/tiendanube/desconectar'   => 'editar.ajustes',
  '/tiendanube/ajustes'       => 'editar.ajustes',
  '/tiendanube/avisos'        => 'editar.ajustes',
  '/tiendanube/importar'      => 'editar.tiendanube',
  '/tiendanube/vincular'      => 'editar.tiendanube',
  '/tiendanube/sincronizar'   => 'editar.tiendanube',
  '/tiendanube/publicar'      => 'editar.tiendanube',
  '/igualas'                => 'ver.igualas',
  '/igualas/guardar'        => 'editar.igualas',
  '/igualas/alternar'       => 'editar.igualas',
  '/igualas/generar'        => 'editar.igualas',
  '/seguimiento'            => 'ver.seguimiento',
  '/seguimiento/guardar'    => 'editar.seguimiento',
  '/compras'                => 'ver.compras',
  '/compras/guardar'        => 'editar.compras',
  '/compras/{id}'           => 'ver.compras',
  '/compras/{id}/recibir'   => 'editar.compras',
  '/compras/{id}/cancelar'  => 'editar.compras',
  '/inventario/conteo'      => 'editar.inventario',
  '/inventario/captura'     => 'editar.inventario',
  '/agenda'                 => 'ver.agenda',
  '/agenda/guardar'         => 'editar.agenda',
  '/agenda/estado'          => 'editar.agenda',
  '/agenda/horario'         => 'editar.ajustes',
  '/caja/paquetes'          => 'cobrar',
  '/inventario'             => 'ver.inventario',
  '/inventario/mover'       => 'editar.inventario',
  '/reportes'               => 'ver.reportes',
  '/reportes/excel'         => 'ver.reportes',
  '/reportes/imprimir'      => 'ver.reportes',
  '/ajustes'                => 'ver.ajustes',
  '/ajustes/guardar'        => 'editar.ajustes',
  '/ajustes/alternar'       => 'editar.ajustes',
  '/ajustes/aprobacion'     => 'editar.ajustes',
  '/ajustes/api/crear'      => 'editar.ajustes',
  '/ajustes/api/revocar'    => 'editar.ajustes',
  '/usuarios'               => 'ver.usuarios',
  '/usuarios/guardar'       => 'editar.usuarios',
  '/usuarios/restablecer'   => 'editar.usuarios',
  '/usuarios/alternar'      => 'editar.usuarios',
  '/ligas'                  => 'ver.ligas',
  '/ligas/generar'          => 'cobrar',
  '/ligas/revisar'          => 'ver.ligas',
  '/ligas/{id}/documento'   => 'ver.ligas',
  '/ligas/aprobar'          => 'cobrar',
  '/ligas/confirmar'        => 'cobrar',
  '/facturacion'            => 'ver.facturacion',
  '/facturacion/{id}/timbrar' => 'timbrar',
  // Estas cuatro no estaban en la tabla y quedaban abiertas a CUALQUIERA
  // con sesión: un cajero podía timbrar un complemento de pago (cuesta un
  // folio) o descargar CFDI. Lo encontró herramientas/revisar-rutas.php.
  '/facturacion/{id}/descargar'                 => 'ver.facturacion',
  '/facturacion/{id}/pagos'                     => 'ver.facturacion',
  '/facturacion/{id}/complemento/{pagoId}'      => 'timbrar',
  '/facturacion/complemento/{pagoId}/descargar' => 'ver.facturacion',
  '/recargas'               => 'ver.recargas',
  '/recargas/saldo'         => 'ver.recargas',
  '/recargas/catalogo'      => 'ver.recargas',
  '/recargas/productos'     => 'ver.recargas',
  '/recargas/ocultos'       => 'editar.ajustes',
  '/recargas/vender'        => 'vender.recarga',
  '/ayuda'                  => 'abrir.ticket',
  '/tutorial'               => 'abrir.ticket',
  '/ayuda/crear'            => 'abrir.ticket',
  '/ayuda/{id}/responder'   => 'abrir.ticket',
  '/ayuda/novedades'        => 'abrir.ticket',
  '/ayuda/{id}/mensajes'    => 'abrir.ticket',
  '/ayuda/{id}/escribiendo' => 'abrir.ticket',
  '/ayuda/{id}/adjunto'     => 'abrir.ticket',
  '/plataforma'             => 'usuarios.plataforma',
  '/plataforma/usuario'     => 'usuarios.plataforma',
  '/plataforma/alternar'    => 'usuarios.plataforma',
  '/plataforma/clave'       => 'usuarios.plataforma',
  '/plataforma/empresa'     => 'suspender.empresa',
  '/plataforma/global'      => 'ajustes.globales',
  '/informes'               => 'ver.informes',
  '/conocimiento'           => 'ver.conocimiento',
  '/conocimiento/guardar'   => 'editar.conocimiento',
  '/conocimiento/alternar'  => 'editar.conocimiento',
  '/auditoria'              => 'ver.auditoria',
  '/auditoria/excel'        => 'ver.auditoria',
  '/auditoria/excel/quitar' => 'ver.auditoria',
  '/auditoria/bitacora'     => 'ver.auditoria',
  '/incoherencias'          => 'ver.auditoria',
  '/tickets'                => 'ver.tickets',
  '/tickets/crear'          => 'ver.tickets',
  '/tickets/{id}'           => 'ver.tickets',
  '/tickets/{id}/responder' => 'ver.tickets',
  '/tickets/{id}/mensajes'  => 'ver.tickets',
  '/tickets/novedades'      => 'ver.tickets',
  '/tickets/{id}/escribiendo' => 'ver.tickets',
  '/tickets/{id}/adjunto'     => 'ver.tickets',
  '/tickets/{id}/cambiar'   => 'ver.tickets',
  '/soporte'                => 'ver.empresas',
  '/soporte/{id}'           => 'ver.empresas',
  '/soporte/restablecer'    => 'clave.ajena',
  '/soporte/alternar'       => 'bloquear.cuenta',
  '/soporte/ajuste'         => 'ajustes.empresa',
  '/mantenimiento'          => 'ver.mantenimiento',
  '/mantenimiento/secciones'=> 'secciones',
  '/mantenimiento/migrar'   => 'secciones',
  '/mantenimiento/revisar'  => 'revisar.docs',
  '/mantenimiento/pago'     => 'revisar.docs',
  '/mantenimiento/correo'   => 'diagnostico',
  '/mantenimiento/empresa/aprobar'  => 'alta.empresas',
  '/mantenimiento/empresa/rechazar' => 'alta.empresas',
  // El perfil y la clave son de cada quien, sin permiso. Los datos
  // fiscales, el alta de comercio y los documentos comprometen a la
  // empresa entera: esos sí son de administrador.
  '/cuenta/fiscales'  => 'editar.empresa',
  '/cuenta/comercio'  => 'editar.empresa',
  '/cuenta/documento' => 'editar.empresa',
  '/cuenta/plan'      => 'editar.empresa',
  '/cuenta/plan/comprobante' => 'editar.empresa',
  // El pago del plan: las mismas personas que pueden pedirlo.
  '/cuenta/plan/estado/{id}'          => 'editar.empresa',
  '/cuenta/plan/liga/{id}/documento'  => 'editar.empresa',
];

$publicas = ['/login', '/login/confirmar', '/salir', '/registro', '/ayuda-acceso', '/crear-clave',
    // La llama el navegador al despertar por un aviso, aunque no haya
    // sesión: se identifica con su propia suscripción (ver PushControlador).
    '/push/pendiente',
    // Los avisos del proveedor de pago. No pueden pedir sesión: quien
    // llama es un servidor de Paga de Todo, no una persona con cookie.
    // Su puerta es el secreto de la URL, que revisa el controlador.
    '/pagadetodo/consulta-referencia', '/pagadetodo/pago-referencia',
    '/pagadetodo/consulta-clabe',      '/pagadetodo/pago-clabe',
    '/pagadetodo/cancela-pago',        '/pagadetodo/pago-liga',
    // Los avisos de la paquetería, por lo mismo: quien llama es un
    // servidor de Envia. Su puerta es la firma HMAC del cuerpo.
    '/envia/aviso',
    // Los de Mercado Libre: un servidor suyo, sin sesión. No traen datos,
    // solo qué cambió; se le vuelve a preguntar con la llave de la cuenta.
    '/mercadolibre/aviso',
    // Los de Shopify: un servidor suyo, sin sesión. Su puerta es la firma
    // HMAC del cuerpo, que revisa el controlador (y contesta 401 si no vale).
    '/shopify/aviso',
];
$ruta     = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Los avisos del proveedor NUNCA piden sesión, lleven el secreto en la
// ruta o en `?k=`. Comparar la ruta exacta dejaba fuera la forma nueva
// —/pagadetodo/SECRETO/pago-referencia— y la mandaba al login: el
// proveedor recibía una redirección, no un 200, y revertía el cobro.
$esAviso = strncmp($ruta, '/pagadetodo/', 12) === 0
        || \LibertyFin\Controlador\PagosEntrantesControlador::esRutaAnterior($ruta);

// El portero: una sola línea decide quién pasa, en vez de repetir la
// comprobación al inicio de cada archivo como hacía el sistema anterior.
if (!$esAviso && !in_array($ruta, $publicas, true) && !Autenticar::sesionValida()) {
    header('Location: /login'); exit;
}

// UNA SESIÓN POR CUENTA (ver Servicio\SesionUnica). Si la cuenta se abrió
// en otro dispositivo, esta sesión se cierra aquí. No se destruye: se
// vacía, y queda solo el motivo para que la pantalla de entrar lo diga.
// Una consulta de fondo (el chat, la campana) recibe JSON en vez de la
// página de entrar, y el navegador manda a la persona a /login.
if (!in_array($ruta, $publicas, true) && !\LibertyFin\Servicio\SesionUnica::vigente()) {
    $_SESSION = ['lf_motivo_salida' => ['otra-sesion', \LibertyFin\Servicio\SesionUnica::$desplazadaPor]];
    $deFondo = ($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1' || !empty($_SERVER['HTTP_X_LF_PARCIAL'])
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($deFondo) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'sesion_cerrada' => true,
            'error' => 'Se inició sesión con tu cuenta en otro dispositivo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: /login'); exit;
}

$hallazgo = $r->despachar($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

// La bitácora escribe en la base de la empresa de la sesión. Se fija una
// vez aquí para que ningún controlador tenga que acordarse.
// SI EL CÓDIGO AVANZÓ MIENTRAS HABÍA SESIONES ABIERTAS.
//
// Las migraciones se aplican al entrar. Quien ya estaba dentro cuando
// se subió una actualización sigue con la base vieja, y una pantalla
// que usa una columna nueva revienta con "Unknown column" sin decir
// qué hacer.
//
// Esto lo nota comparando con lo que se guardó al entrar: cero
// consultas de más en el caso normal, y se arregla solo en cuanto el
// código cambia.
if (!empty($_SESSION['empresa_db'])
    && ($_SESSION['lf_esquema'] ?? 0) < \LibertyFin\Servicio\Migraciones::VERSION) {
    try {
        \LibertyFin\Servicio\Migraciones::aplicar(
            \LibertyFin\Datos\Conexion::de($_SESSION['empresa_db']));
        $_SESSION['lf_esquema'] = \LibertyFin\Servicio\Migraciones::VERSION;
    } catch (\Throwable $e) {
        error_log('[LibertyFin] migración en caliente: ' . $e->getMessage());
    }
}

// Un usuario de plataforma no tiene base de empresa: su bitácora se
// escribe en la base de la empresa sobre la que actúa, no aquí.
if (!empty($_SESSION['empresa_db'])) {
    try { \LibertyFin\Servicio\Auditoria::en(\LibertyFin\Datos\Conexion::de($_SESSION['empresa_db'])); }
    catch (\Throwable $e) { /* sin bitácora se opera igual */ }
}

// ── Suscripción vencida: lo único que se puede hacer es pagar ──
//
// Antes, una suscripción vencida impedía iniciar sesión, y quien tenía que
// pagarla se quedaba afuera sin saber cómo. Ahora entra, pero solo ve
// Mi cuenta → Plan (y salir). Quien no puede pagar —un cajero— ve un aviso
// para que le pida al administrador renovar.
//
// Se evalúa en cada petición, no solo al entrar: una sesión que ya estaba
// abierta cuando venció también queda detenida, y en cuanto se aprueba el
// pago el acceso regresa solo (ver Suscripcion::estado).
if (!in_array($ruta, $publicas, true) && !empty($_SESSION['empresa_id'])
    && \LibertyFin\Servicio\Suscripcion::vencida()) {
    $libres = ['/salir', '/cuenta/plan', '/cuenta/plan/comprobante'];
    $enPlan = ($ruta === '/cuenta' && ($_GET['t'] ?? '') === 'plan');
    if (!in_array($ruta, $libres, true) && !$enPlan) {
        $susc = \LibertyFin\Servicio\Suscripcion::estado();
        if (\LibertyFin\Dominio\Permisos::puede('editar.empresa')) {
            $_SESSION['lf_aviso'] = ['tipo' => 'error', 'texto' =>
                'Tu suscripción venció el ' . $susc['fecha'] . '. Renueva tu plan para seguir usando LibertyFin.'];
            header('Location: /cuenta?t=plan'); exit;
        }
        http_response_code(402);
        Plantilla::pagina('errores/vencida', [
            'titulo' => 'Suscripción vencida', 'fecha' => $susc['fecha'],
            'empresa' => $_SESSION['empresa_nombre'] ?? '',
        ], 'layout-limpio');
        exit;
    }
}

// ── El permiso, antes de ejecutar nada ──
if ($hallazgo !== null && !in_array($ruta, $publicas, true)) {
    $patron = $hallazgo['patron'] ?? $ruta;
    $necesita = $permisos[$patron] ?? null;
    // Una sesión de plataforma en una ruta de empresa: se detiene aquí
    // con una explicación, en vez de dejar que reviente adentro con
    // "1046 No database selected".
    if ($necesita !== null && empty($_SESSION['empresa_db'])
        && \LibertyFin\Dominio\Permisos::necesitaEmpresa($necesita)) {
        http_response_code(403);
        Plantilla::pagina('errores/sin-empresa', [
            'titulo' => 'Esta pantalla es de una empresa', 'icono' => 'alerta', 'subtitulo' => '',
        ]);
        exit;
    }

    if ($necesita !== null && !\LibertyFin\Dominio\Permisos::puede($necesita)) {
        http_response_code(403);
        Plantilla::pagina('errores/403', [
            'titulo' => 'Sin permiso', 'icono' => 'alerta', 'subtitulo' => '',
            'permiso' => $necesita,
        ]);
        exit;
    }
}

if ($hallazgo === null) {
    http_response_code(404);
    Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
    exit;
}

try {
    list($clase, $metodo) = $hallazgo['destino'];
    call_user_func_array([new $clase(), $metodo], $hallazgo['args']);
} catch (Throwable $e) {
    error_log('[LibertyFin] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());

    // UN AVISO DE PAGO NUNCA SE CONTESTA CON 500.
    //
    // Paga de Todo lee un 500 como "el emisor no lo autorizó" y cancela
    // la operación. El cliente ya pagó en la tienda y ya tiene su
    // ticket: deshacerlo del lado del proveedor deja un cobro que la
    // tienda hizo y que nadie reconoce. Se contesta el código 50 que su
    // documentación pide para esto, con HTTP 200, y el error queda en
    // el log para revisarlo con calma.
    if ($esAviso) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['codigo' => 50, 'mensaje' => 'Error de sistema',
                          'code' => '99', 'message' => 'Error interno.']);
        exit;
    }

    http_response_code(500);
    if (!empty($cfg['depurar'])) {
        echo '<pre style="padding:20px;font:13px ui-monospace">'
           . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo 'Ocurrió un error. Ya quedó registrado.';
    }
}