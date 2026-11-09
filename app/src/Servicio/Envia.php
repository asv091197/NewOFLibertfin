<?php
namespace LibertyFin\Servicio;

/**
 * Envia.com: cotizar, generar guías, rastrear y cancelar.
 *
 * QUÉ ES
 *
 * Una sola integración para más de cien paqueterías —DHL, FedEx, Estafeta,
 * Paquetexpress, Redpack—. En vez de hablar con cada una, se le pide a
 * Envia que cotice con todas y se elige la que convenga.
 *
 * ──────────────────────────────────────────────────────────────────────
 * EL DETALLE QUE ROMPE A QUIEN NO LO SABE
 *
 * Esta API contesta **HTTP 200 con el error adentro**. Una petición mal
 * formada, un código postal inexistente o un token vencido no dan 400 ni
 * 401: dan 200 con `{"meta":"error", ...}`. Quien revise solo el código
 * HTTP va a dar por buena una cotización que no existe y va a guardar
 * una guía que nunca se generó.
 *
 * Por eso aquí NADA se da por bueno por el 200: se revisa `meta` en cada
 * respuesta, y `meta === 'error'` es un fallo con todas sus letras.
 * ──────────────────────────────────────────────────────────────────────
 *
 * LOS DOS TOKENS
 *
 * El de Sandbox y el de producción son llaves distintas y no se pueden
 * intercambiar: una llave de pruebas no autentica en producción ni al
 * revés. Se guardan por separado para que cambiar de modo no obligue a
 * recordar cuál estaba puesta.
 */
final class Envia
{
    const PROD     = 'https://api.envia.com';
    const SANDBOX  = 'https://api-test.envia.com';

    /**
     * El catálogo de códigos postales. Va aparte y NO lleva token: es
     * público. Se usa para traducir un CP a su ciudad y a su estado.
     */
    const GEOCODES = 'https://geocodes.envia.com';

    /** El servicio de consultas: qué paqueterías atienden qué. */
    const CONSULTAS         = 'https://queries.envia.com';
    const CONSULTAS_SANDBOX = 'https://queries.test.envia.com';

    /**
     * Cómo sale impresa la etiqueta.
     *
     * `/ship/generate/` EXIGE el bloque `settings`, aunque los ejemplos
     * de la documentación no lo traigan y `/ship/rate/` no lo pida. Sin
     * él contesta «Required property missing: settings».
     *
     * El formato es tipo-de-papel + medida: STOCK es rollo adhesivo
     * para impresora térmica, PAPER es hoja suelta para una normal.
     * Cada paquetería acepta unos y otros no, por eso se puede cambiar
     * desde config/integraciones.php sin tocar código.
     */
    const ETIQUETA_FORMATO = 'PDF';          // PDF · ZPL (térmica Zebra) · PNG
    const ETIQUETA_TAMANO  = 'STOCK_4X6';    // STOCK_4X6 · PAPER_LETTER · …

    /** Tipos de bulto que acepta la API. */
    const TIPOS = [
        'box'      => 'Caja',
        'envelope' => 'Sobre',
        'pallet'   => 'Tarima',
    ];

    /**
     * `shipment.type`: qué clase de envío es.
     *
     *   1 · Paquetería normal (parcel). Una caja, un sobre.
     *   2 · LTL, carga consolidada. Tarimas.
     *   3 · FTL, camión completo.
     *
     * Esto NO es decorativo y se aprendió a la mala: si mandas una
     * tarima, Envia convierte el envío a LTL por su cuenta y entonces
     * **exige** que le digas con qué paquetería, cosa que con paquetería
     * normal es opcional. El error que devuelve habla de un `carrier`
     * faltante y no menciona la tarima, así que desde afuera parece que
     * la petición está mal armada.
     */
    const ENVIO_NORMAL = 1;
    const ENVIO_LTL    = 2;

    /** Qué clase de envío son estos bultos. */
    public static function tipoDeEnvio(array $paquetes)
    {
        foreach ($paquetes as $p) {
            if (($p['tipo'] ?? 'box') === 'pallet') return self::ENVIO_LTL;
        }
        return self::ENVIO_NORMAL;
    }

    private $cfg;
    private $ultimoError = '';
    private $ultimoFueRed = false;
    private $espia = null;
    private $carriersCache = [];

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    /** Lo arma desde config/integraciones.php, o null si no está puesto. */
    public static function deConfig()
    {
        $c = Integraciones::de('envia');
        return $c ? new self($c) : null;
    }

    public function error() { return $this->ultimoError; }
    public function sandbox() { return !empty($this->cfg['sandbox']); }

    /**
     * ¿El último fallo fue por no poder llegar, y no por el dato?
     *
     * Son dos cosas distintas y se tratan distinto. «Este CP no existe»
     * es un dato malo y hay que corregirlo. «No pude preguntar» es que
     * se cayó la red, y eso no puede dejar a nadie sin poder guardar su
     * propio domicilio.
     */
    public function fueDeRed() { return $this->ultimoFueRed; }

    /** Para `bin/revisar-envios.php`: enseña lo que va y lo que vuelve. */
    public function espiar(callable $f) { $this->espia = $f; return $this; }

    /**
     * El token del modo en curso.
     *
     * Si falta el de pruebas se cae al de producción a propósito, para
     * que alguien que solo tiene uno pueda probar; pero se avisa, porque
     * mandar la llave de producción a Sandbox nunca va a autenticar y el
     * error que devuelve no dice que ese sea el problema.
     */
    public function token()
    {
        if ($this->sandbox()) {
            $t = trim((string)($this->cfg['token_prueba'] ?? ''));
            if ($t !== '') return $t;
        }
        return trim((string)($this->cfg['token'] ?? ''));
    }

    public function listo() { return $this->token() !== ''; }

    public function base()
    {
        $b = trim((string)($this->cfg['url'] ?? ''));
        if ($b !== '' && filter_var($b, FILTER_VALIDATE_URL)) return rtrim($b, '/');
        return $this->sandbox() ? self::SANDBOX : self::PROD;
    }

    // ════════════════════════════════════════════════════════
    //  LO QUE SE USA
    // ════════════════════════════════════════════════════════

    /**
     * Cuánto cuesta mandarlo, con cada paquetería.
     *
     * Sin `carrier` cotiza con todas las que apliquen a la ruta, que es
     * lo que casi siempre se quiere: el mostrador necesita comparar.
     *
     * @return array [['carrier','service','descripcion','precio','moneda','dias'], ...]
     */
    public function cotizar(array $origen, array $destino, array $paquetes, $carrier = '')
    {
        if (($queja = $this->revisarTodo($origen, $destino, $paquetes)) !== '') {
            $this->ultimoError = $queja;
            return null;
        }
        $tipo   = self::tipoDeEnvio($paquetes);
        $cuerpo = [
            'origin'      => self::direccion($origen),
            'destination' => self::direccion($destino),
            'packages'    => self::paquetes($paquetes),
            'shipment'    => ['type' => $tipo],
        ];
        if ($carrier !== '') $cuerpo['shipment']['carrier'] = $carrier;

        $r = $this->llamar('/ship/rate/', $cuerpo);

        // Sin paquetería elegida, hay rutas y tipos de envío donde Envia
        // la exige —el LTL siempre—. En vez de rendirse, se le pregunta
        // una por una a las que atienden esa ruta y se juntan los
        // precios: para el mostrador el resultado es el mismo, una lista
        // para comparar.
        if ($r === null && $carrier === '' && $this->pedíaCarrier()) {
            return $this->cotizarUnaPorUna($cuerpo, $tipo, $destino);
        }
        if ($r === null) return null;

        return self::leerOpciones($r);
    }

    /** ¿El último error fue «me falta el carrier»? */
    private function pedíaCarrier()
    {
        return stripos($this->ultimoError, 'carrier') !== false;
    }

    /**
     * Le pregunta a cada paquetería por separado y junta las respuestas.
     *
     * Son varias llamadas, así que se topa: más de ocho y la pantalla se
     * queda pensando demasiado para lo que vale. Si una falla, se la
     * salta en silencio —no atiende esa ruta— y se sigue con las demás;
     * solo si fallan TODAS se devuelve el error.
     */
    private function cotizarUnaPorUna(array $cuerpo, $tipo, array $destino)
    {
        $pais = mb_strtoupper(mb_substr(trim((string)($destino['pais'] ?? 'MX')), 0, 2)) ?: 'MX';
        $lista = $this->carriers($pais, $tipo);
        if (!$lista) {
            $this->ultimoError = 'Para este envío Envia pide elegir la paquetería, y no se '
                . 'pudo obtener la lista de las disponibles. ' . $this->ultimoError;
            return null;
        }

        $todas = []; $ultimo = ''; $hubo = false;
        foreach (array_slice($lista, 0, 8) as $c) {
            $cuerpo['shipment']['carrier'] = $c;
            $r = $this->llamar('/ship/rate/', $cuerpo);
            if ($r === null) { $ultimo = $this->ultimoError; continue; }
            $hubo = true;
            foreach (self::leerOpciones($r) as $o) $todas[] = $o;
        }

        if (!$hubo) {
            $this->ultimoError = $ultimo ?: 'Ninguna paquetería contestó esta ruta.';
            return null;
        }
        $this->ultimoError = '';

        // Sin repetidos: hay paqueterías que contestan también por sus
        // hermanas, y la misma opción dos veces en pantalla hace dudar
        // de toda la lista.
        $vistas = []; $limpias = [];
        foreach ($todas as $o) {
            $llave = $o['carrier'] . '|' . $o['service'] . '|' . $o['precio'];
            if (isset($vistas[$llave])) continue;
            $vistas[$llave] = 1;
            $limpias[] = $o;
        }
        usort($limpias, function ($a, $b) { return $a['precio'] <=> $b['precio']; });
        return $limpias;
    }

    /**
     * Qué paqueterías atienden un país y un tipo de envío.
     *
     * Vive en el servicio de consultas, que es otro dominio. Se pide una
     * vez por petición: la lista no cambia de un minuto a otro.
     */
    public function carriers($pais = 'MX', $tipo = self::ENVIO_NORMAL, $internacional = 0)
    {
        $llave = $pais . '/' . (int)$internacional . '/' . (int)$tipo;
        if (isset($this->carriersCache[$llave])) return $this->carriersCache[$llave];

        $base = trim((string)($this->cfg['url_consultas'] ?? ''));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            $base = $this->sandbox() ? self::CONSULTAS_SANDBOX : self::CONSULTAS;
        }
        $url  = rtrim($base, '/') . '/available-carrier/' . rawurlencode($pais) . '/'
              . (int)$internacional . '/' . (int)$tipo;
        $this->ver('>>>', $url);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token()],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res === false) return $this->carriersCache[$llave] = [];
        $this->ver('<<<', mb_substr((string)$res, 0, 600));

        $j = json_decode((string)$res, true);
        $filas = (is_array($j) && isset($j['data'])) ? (array)$j['data'] : [];

        // El nombre de la clave cambia según el endpoint, así que se
        // acepta cualquiera de las que usa Envia en vez de apostar.
        $r = [];
        foreach ($filas as $f) {
            if (is_string($f)) { $r[] = strtolower($f); continue; }
            foreach (['carrier', 'carrier_name', 'name', 'code'] as $k) {
                if (!empty($f[$k]) && is_string($f[$k])) { $r[] = strtolower($f[$k]); break; }
            }
        }
        return $this->carriersCache[$llave] = array_values(array_unique(array_filter($r)));
    }

    /**
     * Las sucursales de una paquetería donde se puede dejar un paquete.
     *
     * Hacen falta para los servicios «ocurre», donde el paquete no se
     * recoge: lo lleva el negocio a un mostrador de la paquetería, y
     * hay que decirle a cuál.
     *
     * @return array [['codigo','nombre','direccion','ciudad','estado','cp'], ...]
     */
    public function sucursales($carrier, $pais = 'MX', $cp = '')
    {
        $carrier = strtolower(trim((string)$carrier));
        if ($carrier === '') { $this->ultimoError = 'Falta la paquetería.'; return []; }

        $q = '/branch?carrier=' . rawurlencode($carrier)
           . '&country_code=' . rawurlencode(strtoupper($pais));
        if (trim((string)$cp) !== '') $q .= '&postal_code=' . rawurlencode(trim((string)$cp));

        $j = $this->consulta('GET', $q);
        if (!is_array($j)) return [];

        $filas = isset($j['data']) ? (array)$j['data']
               : ((isset($j[0]) && is_array($j[0])) ? $j : []);

        $r = [];
        foreach ($filas as $f) {
            if (!is_array($f)) continue;
            // El nombre de la clave no está documentado, así que se
            // aceptan las que usa Envia en sus distintos endpoints.
            $codigo = '';
            foreach (['code', 'branch_code', 'branchCode', 'id', 'branch_id'] as $k) {
                if (isset($f[$k]) && $f[$k] !== '' && !is_array($f[$k])) {
                    $codigo = (string)$f[$k]; break;
                }
            }
            if ($codigo === '') continue;
            $r[] = [
                'codigo'    => $codigo,
                'nombre'    => (string)($f['name'] ?? $f['branch_name'] ?? $f['description'] ?? $codigo),
                'direccion' => trim((string)($f['address'] ?? $f['street'] ?? '')
                             . ' ' . (string)($f['number'] ?? '')),
                'ciudad'    => (string)($f['city'] ?? $f['locality'] ?? ''),
                'estado'    => (string)($f['state'] ?? ''),
                'cp'        => (string)($f['postal_code'] ?? $f['postalCode'] ?? $f['zip_code'] ?? ''),
            ];
        }
        if (!$r && $this->ultimoError === '') {
            $this->ultimoError = 'Esa paquetería no devolvió sucursales para esta zona.';
        }
        return $r;
    }

    /**
     * Qué clases de aviso se pueden pedir.
     *
     * La que interesa es la de rastreo (`tracking.simple`, tipo 3), que
     * además viene firmada. Las viejas —tipos 1 y 2— no traen firma y
     * no se pueden verificar, así que no conviene usarlas.
     */
    public function tiposDeAviso()
    {
        $j = $this->consulta('GET', '/webhook-types');
        if (!is_array($j)) return [];
        $filas = isset($j['data']) ? (array)$j['data']
               : ((isset($j[0]) && is_array($j[0])) ? $j : []);
        $r = [];
        foreach ($filas as $f) {
            if (!is_array($f) || empty($f['id'])) continue;
            $r[(int)$f['id']] = trim((string)($f['name'] ?? $f['description'] ?? ''));
        }
        return $r;
    }

    /** Los avisos ya registrados. */
    public function avisos()
    {
        $j = $this->consulta('GET', '/webhooks');
        if (!is_array($j)) return [];
        return isset($j['data']) ? (array)$j['data']
             : ((isset($j[0]) && is_array($j[0])) ? $j : []);
    }

    /**
     * Registra una dirección para recibir avisos.
     *
     * `tipo` 3 es el de rastreo firmado. Envia exige HTTPS.
     */
    public function registrarAviso($url, $tipo = 3)
    {
        $url = trim((string)$url);
        if (strncasecmp($url, 'https://', 8) !== 0) {
            $this->ultimoError = 'La dirección del aviso tiene que empezar con https://';
            return null;
        }
        $j = $this->consulta('POST', '/webhooks',
                             ['type_id' => (int)$tipo, 'url' => $url, 'active' => 1]);
        if (!is_array($j)) return null;
        return (array)($j['data'] ?? $j);
    }

    /** Apaga un aviso sin borrarlo. */
    public function apagarAviso($id)
    {
        return $this->consulta('PUT', '/webhooks/' . (int)$id, ['active' => 0]) !== null;
    }

    /**
     * Una llamada al servicio de consultas.
     *
     * Vive en otro dominio que el de envíos y usa más verbos que POST,
     * por eso no reusa `llamar()`.
     */
    private function consulta($verbo, $ruta, array $cuerpo = null)
    {
        $this->ultimoError = '';
        if (!$this->listo()) { $this->ultimoError = 'Falta el token de Envia.'; return null; }

        $base = trim((string)($this->cfg['url_consultas'] ?? ''));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            $base = $this->sandbox() ? self::CONSULTAS_SANDBOX : self::CONSULTAS;
        }
        $url = rtrim($base, '/') . $ruta;
        $this->ver('>>>', $verbo . ' ' . $url . ($cuerpo ? ' ' . json_encode($cuerpo) : ''));

        $ch = curl_init($url);
        $op = [
            CURLOPT_CUSTOMREQUEST  => $verbo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token(),
                                       'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($cuerpo !== null) $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, $op);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            $this->ultimoError = 'No se pudo conectar: ' . ($err ?: 'sin detalle');
            return null;
        }
        $this->ver('<<<', 'HTTP ' . $http . ' ' . mb_substr((string)$res, 0, 800));

        $j = json_decode((string)$res, true);
        if (!is_array($j)) {
            $this->ultimoError = 'Contestó algo que no es JSON (HTTP ' . $http . ').';
            return null;
        }
        // La regla de siempre: el error puede venir dentro de un 200.
        if (($j['meta'] ?? '') === 'error' || isset($j['error'])) {
            $this->ultimoError = self::mensajeDeError($j['error'] ?? [], $http);
            return null;
        }
        return $j;
    }

    /**
     * Los estados de un país, con la clave que usa Envia.
     *
     * Ésta es LA lista buena: la de ellos. Sirve para salir de dudas
     * cuando el catálogo de códigos postales no reconoce un CP y hay
     * que escribir la clave a mano.
     *
     * @return array ['CX' => 'Ciudad de México', ...]
     */
    public function estados($pais = 'MX')
    {
        $base = trim((string)($this->cfg['url_consultas'] ?? ''));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            $base = $this->sandbox() ? self::CONSULTAS_SANDBOX : self::CONSULTAS;
        }
        $url = rtrim($base, '/') . '/state?country_code=' . rawurlencode(strtoupper($pais));
        $this->ver('>>>', $url);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token()],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            $this->ultimoError = 'No se pudo pedir la lista de estados: ' . ($err ?: 'sin detalle');
            return [];
        }
        $this->ver('<<<', mb_substr((string)$res, 0, 800));

        $j = json_decode((string)$res, true);
        if (!is_array($j)) { $this->ultimoError = 'La lista de estados no vino en JSON.'; return []; }
        if (($j['meta'] ?? '') === 'error' || isset($j['error'])) {
            $this->ultimoError = self::mensajeDeError($j['error'] ?? [], 200);
            return [];
        }

        // Igual que el catálogo de CPs: puede venir envuelto en `data` o
        // como arreglo pelón. Se aceptan las dos.
        $filas = isset($j['data']) ? (array)$j['data']
               : ((isset($j[0]) && is_array($j[0])) ? $j : []);

        $r = [];
        foreach ($filas as $f) {
            if (!is_array($f)) continue;
            // La clave puede ser texto o el objeto anidado del catálogo.
            $clave = self::claveEstado($f['code'] ?? $f);
            if ($clave === '' || mb_strlen($clave) !== 2) {
                foreach (['state_code', 'code_2', 'abbreviation'] as $k) {
                    if (!empty($f[$k]) && is_string($f[$k]) && mb_strlen(trim($f[$k])) === 2) {
                        $clave = mb_strtoupper(trim($f[$k])); break;
                    }
                }
            }
            $nombre = '';
            foreach (['name', 'state_name', 'description'] as $k) {
                if (!empty($f[$k]) && is_string($f[$k])) { $nombre = $f[$k]; break; }
            }
            if ($clave !== '' && mb_strlen($clave) === 2) $r[$clave] = $nombre ?: $clave;
        }
        ksort($r);
        if (!$r) {
            $this->ultimoError = 'Envia contestó la lista de estados en un formato que no '
                . 'se reconoce. Córrelo con --crudo para ver qué mandó.';
        }
        return $r;
    }

    /** Las opciones de una respuesta de /ship/rate/. */
    private static function leerOpciones(array $r)
    {
        $op = [];
        foreach ($r as $f) {
            $op[] = [
                'carrier'     => (string)($f['carrier'] ?? ''),
                'service'     => (string)($f['service'] ?? ''),
                'descripcion' => (string)($f['serviceDescription'] ?? ($f['service'] ?? '')),
                'precio'      => round((float)($f['totalPrice'] ?? 0), 2),
                'moneda'      => (string)($f['currency'] ?? 'MXN'),
                'dias'        => (string)($f['deliveryEstimate'] ?? ''),
            ];
        }
        // De más barata a más cara: es el orden en que se decide.
        usort($op, function ($a, $b) { return $a['precio'] <=> $b['precio']; });
        return $op;
    }

    /**
     * Genera la guía. Devuelve con qué rastrearla y dónde está el PDF.
     *
     * `carrier` y `service` son los de la cotización elegida: mandar otros
     * cotiza distinto de lo que se le dijo al cliente.
     */
    /**
     * Cómo se le dice a Envia de qué sucursal sale el paquete.
     *
     * NO ESTÁ DOCUMENTADO en ningún lado: ni en la guía, ni en la
     * referencia, ni en el centro de ayuda. Lo único que se sabe es el
     * error que devuelve cuando falta («Origin branch code is required
     * for branch to home service»).
     *
     * Así que en vez de apostar por un nombre y que falle para siempre,
     * se prueban los que usa Envia en otros lados, en orden, hasta que
     * uno pase. El que funcionó se recuerda durante la sesión para no
     * repetir el tanteo en cada guía.
     *
     * El día que lo documenten, se borra la lista y se deja el bueno.
     */
    const CAMPOS_SUCURSAL = ['originBranch', 'originBranchCode', 'branch', 'originBranchId'];

    public function generar(array $origen, array $destino, array $paquetes, $carrier,
                            $service, $sucursal = '')
    {
        $carrier = trim((string)$carrier);
        $service = trim((string)$service);
        if ($carrier === '' || $service === '') {
            $this->ultimoError = 'Falta elegir la paquetería y el servicio.';
            return null;
        }
        if (($queja = $this->revisarTodo($origen, $destino, $paquetes)) !== '') {
            $this->ultimoError = $queja;
            return null;
        }
        $cuerpo = [
            'origin'      => self::direccion($origen),
            'destination' => self::direccion($destino),
            'packages'    => self::paquetes($paquetes),
            // El mismo tipo con que se cotizó: una tarima es LTL también
            // a la hora de generar, y con `type` equivocado el precio de
            // la guía no sería el que se le enseñó al cliente.
            'shipment'    => ['type' => self::tipoDeEnvio($paquetes),
                              'carrier' => $carrier, 'service' => $service],
            'settings'    => $this->ajustesEtiqueta(),
        ];

        $sucursal = trim((string)$sucursal);
        if ($sucursal === '') {
            $r = $this->llamar('/ship/generate/', $cuerpo);
        } else {
            $r = $this->generarConSucursal($cuerpo, $sucursal);
        }
        if ($r === null) return null;

        $f = $r[0] ?? null;
        if (!$f) { $this->ultimoError = 'Envia no devolvió la guía.'; return null; }

        // `trackingNumbers` es un arreglo: con varios bultos vienen
        // varios números. Se guardan todos y el primero es el principal,
        // que es el que se le dicta al cliente.
        $nums = array_values(array_filter((array)($f['trackingNumbers'] ?? [])));
        if (!$nums && !empty($f['trackingNumber'])) $nums = [(string)$f['trackingNumber']];

        return [
            'envio_id'   => (string)($f['shipmentId'] ?? ''),
            'carrier'    => (string)($f['carrier'] ?? $carrier),
            'service'    => (string)($f['service'] ?? $service),
            'guias'      => $nums,
            'guia'       => $nums[0] ?? '',
            'etiqueta'   => (string)($f['label'] ?? ''),
            'url_rastreo'=> (string)($f['trackUrl'] ?? ''),
            'precio'     => round((float)($f['totalPrice'] ?? 0), 2),
            'moneda'     => (string)($f['currency'] ?? 'MXN'),
        ];
    }

    /**
     * Genera probando cómo se llama el campo de la sucursal.
     *
     * Se intenta con cada nombre de CAMPOS_SUCURSAL hasta que uno pase.
     * Dos cuidados importantes:
     *
     *  · Solo se reintenta cuando el error habla de la sucursal. Si el
     *    rechazo es por otra cosa —saldo, dirección, servicio— se corta
     *    enseguida: repetir cuatro veces un error que no tiene que ver
     *    solo haría perder tiempo y llamadas.
     *
     *  · Se para al primer éxito. Generar una guía CUESTA, así que bajo
     *    ninguna circunstancia se puede dejar que dos intentos creen dos
     *    guías cobradas por el mismo envío.
     */
    private function generarConSucursal(array $cuerpo, $sucursal)
    {
        // Si ya se supo cuál era en esta sesión, se va directo.
        $sabido = $_SESSION['lf_envia_campo_sucursal'] ?? '';
        $orden  = self::CAMPOS_SUCURSAL;
        if ($sabido !== '' && in_array($sabido, $orden, true)) {
            $orden = array_merge([$sabido], array_diff($orden, [$sabido]));
        }

        $ultimo = '';
        foreach ($orden as $campo) {
            $intento = $cuerpo;
            $intento['shipment'][$campo] = $sucursal;

            $r = $this->llamar('/ship/generate/', $intento);
            if ($r !== null) {
                $_SESSION['lf_envia_campo_sucursal'] = $campo;
                return $r;                      // listo, y NO se intenta más
            }
            $ultimo = $this->ultimoError;

            // ¿El rechazo sigue siendo por la sucursal? Si no, no tiene
            // caso probar otro nombre.
            if (stripos($ultimo, 'branch') === false && stripos($ultimo, 'sucursal') === false) {
                return null;
            }
        }

        $this->ultimoError = 'No se pudo decirle a Envia de qué sucursal sale el paquete. '
            . 'Se probaron ' . count(self::CAMPOS_SUCURSAL) . ' formas y ninguna la aceptó. '
            . 'Último error: ' . $ultimo;
        return null;
    }

    /**
     * Dónde va el paquete.
     * @return array|null [['guia','estado','carrier','eventos'=>[...]], ...]
     */
    public function rastrear(array $guias)
    {
        $guias = array_values(array_filter(array_map('strval', $guias)));
        if (!$guias) { $this->ultimoError = 'No hay números de guía que rastrear.'; return null; }

        $r = $this->llamar('/ship/generaltrack/', ['trackingNumbers' => $guias]);
        if ($r === null) return null;

        $out = [];
        foreach ($r as $f) {
            $ev = [];
            foreach ((array)($f['events'] ?? $f['history'] ?? []) as $e) {
                $ev[] = [
                    'cuando'      => (string)($e['date'] ?? $e['datetime'] ?? ''),
                    'descripcion' => (string)($e['description'] ?? $e['status'] ?? ''),
                    'donde'       => (string)($e['location'] ?? ''),
                ];
            }
            $out[] = [
                'guia'    => (string)($f['trackingNumber'] ?? ($guias[0] ?? '')),
                'carrier' => (string)($f['carrier'] ?? ''),
                'estado'  => (string)($f['status'] ?? $f['statusDescription'] ?? ''),
                'eventos' => $ev,
            ];
        }
        return $out;
    }

    /**
     * Cancela una guía.
     *
     * Solo sirve mientras la paquetería no haya escaneado el paquete;
     * después ya está en camino y el reembolso lo decide el carrier. Por
     * eso el error de "ya no se puede" se devuelve tal cual: es
     * información, no una falla del sistema.
     */
    public function cancelar($envioId, $carrier = '', $guia = '')
    {
        $envioId = trim((string)$envioId);
        if ($envioId === '') { $this->ultimoError = 'Falta el identificador del envío.'; return false; }

        // Los TRES datos. La documentación enseña solo `shipmentId`,
        // pero el servicio pide además la paquetería y el número de
        // guía, y los reclama de uno en uno: quitas uno y contesta
        // «Required property missing» por el siguiente. Se mandan los
        // tres juntos para no ir descubriéndolos a tropezones.
        $cuerpo  = ['shipmentId' => $envioId];
        $carrier = strtolower(trim((string)$carrier));
        $guia    = trim((string)$guia);
        if ($carrier !== '') $cuerpo['carrier'] = $carrier;
        if ($guia !== '')    $cuerpo['trackingNumber'] = $guia;

        return $this->llamar('/ship/cancel/', $cuerpo) !== null;
    }

    // ════════════════════════════════════════════════════════
    //  ARMAR LO QUE LA API ESPERA
    // ════════════════════════════════════════════════════════

    /**
     * Una dirección, con los nombres que usa Envia.
     *
     * ── EL ESTADO ──
     *
     * `state` va en el CÓDIGO DE DOS LETRAS DE ENVIA, no en el nombre:
     * «JA», no «Jalisco». Y ojo, son códigos propios de Envia, no los
     * del SAT ni los de la ISO: la Ciudad de México es **CX**, no CMX
     * ni DF. Por eso aquí no hay una tabla de estados escrita a mano
     * —estaría mal tarde o temprano—: el código se saca del CP con
     * `cp()`, que pregunta al catálogo de Envia.
     *
     * Con el nombre completo la cotización no da error: devuelve una
     * lista VACÍA, sin decir por qué. Es el error más caro de encontrar
     * de toda esta API, así que lo que no sea un código de dos letras
     * se rechaza aquí mismo con un mensaje que diga qué pasó.
     */
    public static function direccion(array $d)
    {
        return [
            'name'       => mb_substr(trim((string)($d['nombre'] ?? '')), 0, 60),
            'company'    => mb_substr(trim((string)($d['empresa'] ?? '')), 0, 60) ?: null,
            'email'      => trim((string)($d['email'] ?? '')) ?: null,
            'phone'      => preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? '')),
            'street'     => mb_substr(trim((string)($d['calle'] ?? '')), 0, 120),
            'number'     => mb_substr(trim((string)($d['numero'] ?? '')), 0, 20) ?: null,
            'district'   => mb_substr(trim((string)($d['colonia'] ?? '')), 0, 80) ?: null,
            'city'       => mb_substr(trim((string)($d['ciudad'] ?? '')), 0, 80),
            'state'      => mb_strtoupper(trim((string)($d['estado'] ?? ''))),
            'country'    => mb_strtoupper(mb_substr(trim((string)($d['pais'] ?? 'MX')), 0, 2)),
            'postalCode' => preg_replace('/\D/', '', (string)($d['cp'] ?? '')),
            'reference'  => mb_substr(trim((string)($d['referencia'] ?? '')), 0, 120) ?: null,
        ];
    }

    /**
     * Qué le falta a una dirección para poder cotizar.
     *
     * Se revisa ANTES de llamar, porque el error que devuelve Envia por
     * una dirección incompleta es una lista vacía de opciones, y de ahí
     * nadie deduce que faltaba la colonia.
     *
     * @return string '' si está bien, o qué falta.
     */
    public static function revisar(array $d, $quien)
    {
        $faltan = [];
        foreach (['nombre' => 'el nombre', 'calle' => 'la calle',
                  'ciudad' => 'la ciudad', 'cp' => 'el código postal'] as $campo => $como) {
            if (trim((string)($d[$campo] ?? '')) === '') $faltan[] = $como;
        }
        $estado = mb_strtoupper(trim((string)($d['estado'] ?? '')));
        if ($estado === '') {
            $faltan[] = 'el estado';
        } elseif (mb_strlen($estado) !== 2) {
            return 'El estado ' . $quien . ' dice «' . $d['estado'] . '». Envia necesita '
                 . 'su clave de dos letras (Jalisco es JA, la Ciudad de México es CX). '
                 . 'Escribe el código postal y deja que se llene solo.';
        }
        if (!$faltan) return '';
        return 'A la dirección ' . $quien . ' le falta ' . self::enumerar($faltan) . '.';
    }

    /** Las dos direcciones y los bultos, de una vez. */
    private function revisarTodo(array $origen, array $destino, array $paquetes)
    {
        if (!$paquetes) return 'No hay ningún paquete que enviar.';
        foreach ($paquetes as $p) {
            if ((float)($p['peso'] ?? 0) <= 0) {
                return 'Un paquete va sin peso. Sin peso ninguna paquetería cotiza.';
            }
        }
        $q = self::revisar($origen, 'de donde sale');
        if ($q !== '') return $q;
        return self::revisar($destino, 'a donde va');
    }

    private static function enumerar(array $x)
    {
        if (count($x) === 1) return $x[0];
        $ultimo = array_pop($x);
        return implode(', ', $x) . ' y ' . $ultimo;
    }

    /**
     * Qué ciudad y qué estado son un código postal.
     *
     * Este catálogo es público: NO lleva token y vive en otro dominio,
     * así que funciona aunque las credenciales todavía no estén puestas.
     * Es lo que evita que alguien teclee «Jalisco» y se quede sin saber
     * por qué no sale ninguna paquetería.
     *
     * @return array|null ['cp','ciudad','estado'] o null si no existe.
     */
    public function cp($codigo, $pais = 'MX')
    {
        $codigo = trim((string)$codigo);
        $pais   = strtoupper(substr(trim((string)$pais) ?: 'MX', 0, 2));
        $this->ultimoFueRed = false;
        if ($codigo === '') { $this->ultimoError = 'Falta el código postal.'; return null; }

        $base = trim((string)($this->cfg['url_geocodes'] ?? ''));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) $base = self::GEOCODES;
        $url = rtrim($base, '/') . '/zipcode/' . rawurlencode($pais) . '/' . rawurlencode($codigo);
        $this->ver('>>>', $url);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            $this->ultimoFueRed = true;
            $this->ultimoError = 'No se pudo consultar el código postal: ' . ($err ?: 'sin detalle');
            return null;
        }
        $this->ver('<<<', mb_substr((string)$res, 0, 400));

        $j = json_decode((string)$res, true);
        // Una respuesta que no es JSON tampoco es «este CP no existe»:
        // es el catálogo contestando cualquier cosa. Cuenta como red.
        if (!is_array($j)) {
            $this->ultimoFueRed = true;
            $this->ultimoError = 'El catálogo de códigos postales contestó algo que no se entiende.';
            return null;
        }

        // ── OJO CON LA FORMA DE LA RESPUESTA ──
        //
        // La documentación promete {"success":true,"data":{…}} con
        // `city` y `state` planos. Lo que el servicio devuelve DE VERDAD
        // es un arreglo pelón, sin envoltura, y con otros nombres:
        //
        //   [{"zip_code":"64000",
        //     "country":{"name":"México","code":"MX"},
        //     "state":{"name":"Nuevo León","iso_code":"MX-NLE",
        //              "code":{"2digit":"NL","3digit":"NLE"}},
        //     "locality":"Monterrey",
        //     "suburbs":["Centro","Obrera", …]}]
        //
        // Creyéndole a la documentación, TODOS los códigos postales
        // salían «no encontrado», porque nunca había un `success`. Así
        // que aquí se aceptan las dos formas: la real primero.
        $fila = null;
        if (isset($j[0]) && is_array($j[0])) {
            $fila = $j[0];                                   // lo que manda de verdad
        } elseif (!empty($j['data'])) {
            $d = $j['data'];
            $fila = (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : (array)$d;
        } elseif (isset($j['zip_code']) || isset($j['zipcode'])) {
            $fila = $j;
        }

        if (!$fila) {
            $suyo = trim((string)($j['message'] ?? $j['error'] ?? ''));
            $this->ultimoError = 'Envia no reconoce el código postal ' . $codigo
                . ($suyo !== '' ? ' — contestó: «' . $suyo . '»' : ' y no dijo por qué') . '.';
            return null;
        }

        $estado = self::claveEstado($fila['state'] ?? null);
        if ($estado === '') {
            $this->ultimoError = 'El catálogo encontró el código postal ' . $codigo
                . ' pero no traía la clave del estado.';
            return null;
        }

        $colonias = [];
        foreach ((array)($fila['suburbs'] ?? $fila['settlements'] ?? []) as $s) {
            if (is_string($s) && trim($s) !== '') $colonias[] = trim($s);
            elseif (is_array($s) && !empty($s['name'])) $colonias[] = (string)$s['name'];
        }

        $paisR = $fila['country'] ?? null;
        return [
            'cp'       => (string)($fila['zip_code'] ?? $fila['zipcode'] ?? $codigo),
            'ciudad'   => (string)($fila['locality'] ?? $fila['city'] ?? ''),
            'estado'   => $estado,
            'pais'     => mb_strtoupper((string)(is_array($paisR)
                            ? ($paisR['code'] ?? $pais) : ($paisR ?: $pais))),
            // Las colonias de ese CP. Es lo que evita que alguien
            // escriba el municipio donde va la colonia.
            'colonias' => array_values(array_unique($colonias)),
        ];
    }

    /**
     * La clave de dos letras del estado, venga como venga.
     *
     * Puede llegar como texto («NL») o como el objeto anidado que usa el
     * catálogo: `{"code":{"2digit":"NL","3digit":"NLE"}}`. Se busca
     * siempre la de DOS dígitos, que es la que pide el API de envíos: la
     * de tres («NLE») se parece bastante como para colarse sin que nadie
     * lo note, y con ella la cotización sale vacía.
     */
    private static function claveEstado($e)
    {
        if (is_string($e)) return mb_strtoupper(trim($e));
        if (!is_array($e)) return '';

        // Puede llegar el renglón entero (`{"code":{"2digit":"NL"}}`) o
        // ya el pedazo de dentro (`{"2digit":"NL"}`). Se prueban los dos
        // niveles para no depender de quién llama.
        foreach ([$e['code'] ?? null, $e] as $c) {
            if (is_array($c)) {
                foreach (['2digit', '2Digit', 'two_digit'] as $k) {
                    if (!empty($c[$k])) return mb_strtoupper(trim((string)$c[$k]));
                }
            } elseif (is_string($c) && mb_strlen(trim($c)) === 2) {
                return mb_strtoupper(trim($c));
            }
        }
        // Último recurso: de «MX-NLE» no se puede sacar la de dos letras
        // sin inventar, así que mejor nada que algo equivocado.
        return '';
    }

    /**
     * El bloque `settings` que pide `/ship/generate/`.
     *
     * Si la paquetería no acepta este tamaño, el error de Envia lo va a
     * decir con todas sus letras y se cambia en integraciones.php:
     *
     *     'formato_etiqueta' => 'PDF',
     *     'tamano_etiqueta'  => 'PAPER_LETTER',
     */
    private function ajustesEtiqueta()
    {
        $formato = strtoupper(trim((string)($this->cfg['formato_etiqueta'] ?? '')));
        $tamano  = strtoupper(trim((string)($this->cfg['tamano_etiqueta'] ?? '')));
        return [
            'printFormat' => $formato ?: self::ETIQUETA_FORMATO,
            'printSize'   => $tamano  ?: self::ETIQUETA_TAMANO,
        ];
    }

    /** Los bultos. Un envío sin paquetes no se cotiza. */
    public static function paquetes(array $lista)
    {
        $r = [];
        foreach ($lista as $p) {
            $tipo = (string)($p['tipo'] ?? 'box');
            if (!isset(self::TIPOS[$tipo])) $tipo = 'box';
            $r[] = [
                'type'          => $tipo,
                'content'       => mb_substr(trim((string)($p['contenido'] ?? 'Mercancía')), 0, 60),
                'amount'        => max(1, (int)($p['cantidad'] ?? 1)),
                'declaredValue' => round((float)($p['valor'] ?? 0), 2),
                'weight'        => round((float)($p['peso'] ?? 1), 3),
                'weightUnit'    => 'KG',
                'lengthUnit'    => 'CM',
                'dimensions'    => [
                    'length' => round((float)($p['largo'] ?? 1), 2),
                    'width'  => round((float)($p['ancho'] ?? 1), 2),
                    'height' => round((float)($p['alto']  ?? 1), 2),
                ],
            ];
        }
        return $r;
    }

    // ════════════════════════════════════════════════════════

    /**
     * Una llamada. Devuelve `data` o null, y deja el motivo en error().
     *
     * Aquí vive la regla de arriba: el 200 no basta. Se revisa `meta`.
     */
    private function llamar($ruta, array $cuerpo)
    {
        $this->ultimoError = '';
        if (!$this->listo()) {
            $this->ultimoError = 'Falta el token de Envia en config/integraciones.php';
            return null;
        }

        $url  = $this->base() . $ruta;
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->ver('>>>', $ruta . ' ' . $json);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 40),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            $this->ultimoError = 'No se pudo conectar con Envia: ' . ($err ?: 'sin detalle');
            $this->ver('!!!', $this->ultimoError);
            return null;
        }
        $this->ver('<<<', 'HTTP ' . $http . ' ' . mb_substr((string)$res, 0, 1200));

        $j = json_decode((string)$res, true);
        if (!is_array($j)) {
            $this->ultimoError = 'Envia contestó algo que no es JSON (HTTP ' . $http . '): '
                               . mb_substr(trim(strip_tags((string)$res)), 0, 160);
            return null;
        }

        // ── La regla ──
        // `meta` es lo que dice si salió bien, no el código HTTP.
        if (($j['meta'] ?? '') === 'error' || isset($j['error'])) {
            $e = $j['error'] ?? [];
            $this->ultimoError = self::mensajeDeError($e, $http);
            return null;
        }
        if (!isset($j['data'])) {
            $this->ultimoError = 'Envia no devolvió datos (HTTP ' . $http . ').';
            return null;
        }

        $data = $j['data'];
        // Algunas respuestas traen un objeto y no un arreglo. Se
        // normaliza para que quien llama no tenga que preguntarlo.
        return (is_array($data) && array_keys($data) !== range(0, count($data) - 1))
             ? [$data] : (array)$data;
    }

    /** El error de Envia, en una frase que se pueda enseñar. */
    private static function mensajeDeError($e, $http)
    {
        if (is_string($e) && $e !== '') return self::traducir($e) ?: $e;
        $e = (array)$e;
        $partes = array_filter([
            (string)($e['message'] ?? ''),
            (string)($e['description'] ?? ''),
        ]);
        $txt = $partes ? implode(' · ', array_unique($partes)) : 'Envia rechazó la petición';

        // Si es uno de los conocidos, se enseña la explicación en vez
        // del texto de ellos: «Origin branch code is required for branch
        // to home service» no le dice a nadie qué hacer a continuación.
        $claro = self::traducir($txt);
        if ($claro !== '') return $claro;

        $cod = (string)($e['code'] ?? '');
        return $txt . ($cod !== '' ? ' (código ' . $cod . ')' : '') . ' · HTTP ' . $http;
    }

    /**
     * Los errores de Envia que no se explican solos.
     *
     * Son frases en inglés, pensadas para quien programó la
     * integración, no para quien está en el mostrador con un paquete en
     * la mano. Aquí se cambian por lo que hay que HACER.
     */
    /**
     * ¿El último fallo fue «esta paquetería solo cotiza»?
     *
     * Sirve para no volver a ofrecer esa opción como si fuera buena: es
     * un rechazo de política, no un error pasajero, y repetirlo hace
     * perder el tiempo al del mostrador.
     */
    public function fueSoloCotiza()
    {
        $t = mb_strtolower($this->ultimoError);
        return mb_strpos($t, 'solo deja cotizar') !== false
            || mb_strpos($t, 'quote_only') !== false;
    }

    private static function traducir($txt)
    {
        $t = mb_strtolower($txt);

        if (mb_strpos($t, 'quote_only') !== false || mb_strpos($t, 'quoting only') !== false) {
            return 'Esa paquetería solo deja COTIZAR, no generar guías. Pasa seguido en '
                 . 'modo de pruebas, donde casi ninguna permite crear envíos de verdad. '
                 . 'Prueba con otra de la lista; si todas lo dicen, es que en Sandbox tu '
                 . 'cuenta no tiene ninguna habilitada para generar, y hay que pasar a '
                 . 'producción (`sandbox => false` y el token de producción) para hacerlo.';
        }
        if (mb_strpos($t, 'branch code') !== false) {
            return 'Ese servicio no recoge en tu domicilio: tú tendrías que llevar el '
                 . 'paquete a una sucursal de la paquetería, y hay que decir a cuál. '
                 . 'Por ahora elige otro servicio de la lista —uno que pase por el '
                 . 'paquete— o cotiza con otra paquetería.';
        }
        if (mb_strpos($t, 'required property missing: settings') !== false) {
            return 'Falta el formato de impresión de la etiqueta. Revisa '
                 . '`formato_etiqueta` y `tamano_etiqueta` en config/integraciones.php.';
        }
        if (mb_strpos($t, 'printsize') !== false || mb_strpos($t, 'printformat') !== false) {
            return 'Esa paquetería no imprime en ese tamaño de etiqueta. Cambia '
                 . '`tamano_etiqueta` en config/integraciones.php (prueba PAPER_LETTER). '
                 . 'Envia dijo: ' . $txt;
        }
        if (mb_strpos($t, 'unauthorized') !== false || mb_strpos($t, 'invalid token') !== false) {
            return 'Envia no aceptó el token. Si estás en modo de pruebas tiene que ser el '
                 . 'de Sandbox, y en producción el de producción: no se pueden intercambiar.';
        }
        if (mb_strpos($t, 'insufficient') !== false || mb_strpos($t, 'balance') !== false) {
            return 'No hay saldo suficiente en la cuenta de Envia para pagar esta guía. '
                 . 'Envia dijo: ' . $txt;
        }
        return '';
    }

    private function ver($quien, $que)
    {
        if ($this->espia) call_user_func($this->espia, $quien, (string)$que);
    }
}