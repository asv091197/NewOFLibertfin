<?php
namespace LibertyFin\Api;

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Router;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Migraciones;

/**
 * La entrada de la API: todo lo que llega a /api/.
 *
 * La API es la otra puerta del mismo sistema. Usa los mismos servicios y
 * repositorios que las pantallas (RegistrarPago, VentaRepo, CobranzaRepo,
 * Permisos), así que una regla de negocio vive en un solo lugar y no se
 * puede saltar entrando por aquí. Lo que cambia es cómo se identifica
 * quien llama y cómo se contesta:
 *
 *   pantallas  sesión con cookie, HTML, redirige al login
 *   API        llave en la cabecera, JSON, nunca redirige
 *
 * El orden de las revisiones importa:
 *
 *   1. la llave         401  sin llave o llave inválida/revocada
 *   2. el ritmo         429  más de N peticiones por minuto con esa llave
 *   3. la ruta          404 / 405
 *   4. la empresa       403 suspendida · 402 suscripción vencida
 *   5. el permiso       403  el rol de la llave no puede eso
 *
 * Cada respuesta lleva X-Request-Id; el mismo folio queda en el log del
 * servidor cuando algo truena, para encontrar el error sin adivinar.
 */
final class Puerta
{
    /** Peticiones por minuto con una llave, si config.php no dice otra cosa. */
    const POR_MINUTO = 120;

    /**
     * Las rutas de la v1 y el permiso que pide cada una. Los permisos son
     * los MISMOS de las pantallas (Dominio\Permisos): un cajero ve por la
     * API lo mismo que ve en el sistema, ni más ni menos.
     */
    const RUTAS = [
        ['GET',  '/api/v1/yo',                 ['YoControlador', 'ver'],             null],
        ['GET',  '/api/v1/ventas',             ['VentasControlador', 'listar'],      'ver.ventas'],
        ['GET',  '/api/v1/ventas/{id}',        ['VentasControlador', 'ver'],         'ver.ventas'],
        ['POST', '/api/v1/ventas/{id}/abonos', ['VentasControlador', 'abonar'],      'abonar'],
        ['GET',  '/api/v1/clientes',           ['ClientesControlador', 'listar'],    'ver.clientes'],
        ['GET',  '/api/v1/clientes/{id}',      ['ClientesControlador', 'ver'],       'ver.clientes'],
        ['GET',  '/api/v1/productos',          ['ProductosControlador', 'listar'],   'ver.servicios'],
        ['GET',  '/api/v1/cobranza',           ['CobranzaControlador', 'listar'],    'ver.cobranza'],
    ];

    private $porMinuto;

    public function __construct(array $cfg = [])
    {
        $this->porMinuto = max(1, (int)($cfg['por_minuto'] ?? self::POR_MINUTO));
    }

    /** Atiende la petición y devuelve la respuesta, sin imprimirla. */
    public function atender($metodo, $uri, ?Entrada $entrada = null)
    {
        $folio = bin2hex(random_bytes(6));
        try {
            $r = $this->procesar(strtoupper((string)$metodo), (string)$uri, $entrada ?: Entrada::deLaPeticion());
        } catch (ErrorApi $e) {
            $r = $e->respuesta();
            if ($e->estado() === 401) $r->con('WWW-Authenticate', 'Bearer realm="LibertyFin"');
            if ($e->estado() === 429) $r->con('Retry-After', '60');
            if ($e->estado() === 405) $r->con('Allow', implode(', ', $e->detalles()['permitidos'] ?? []));
        } catch (\Throwable $e) {
            error_log("[LibertyFin][API $folio] " . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            $r = Respuesta::error(500, 'error_interno',
                "Ocurrió un error en el servidor. Quedó registrado con el folio $folio.");
        }
        return $r->con('X-Request-Id', $folio);
    }

    private function procesar($metodo, $uri, Entrada $entrada)
    {
        // 1. La llave.
        $token = $this->token($entrada);
        if ($token === '') {
            throw new ErrorApi(401, 'sin_llave',
                'Falta la llave de la API. Mándala en la cabecera «Authorization: Bearer lfk_…».');
        }
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $llaves = new LlaveRepo($principal);
        $llave = $llaves->porToken($token);
        if (!$llave) {
            throw new ErrorApi(401, 'llave_invalida', 'La llave no existe o fue revocada.');
        }

        // 2. El ritmo.
        $usos = $llaves->contarUso($llave['id'], $_SERVER['REMOTE_ADDR'] ?? '');
        if ($usos > $this->porMinuto) {
            throw new ErrorApi(429, 'demasiadas_peticiones',
                "Esta llave pasó de {$this->porMinuto} peticiones por minuto. Espera un minuto y reintenta.");
        }

        // 3. La ruta.
        list($destino, $args, $permiso) = $this->ruta($metodo, $uri);

        // 4. La empresa: la misma que en el sistema. Suspendida no entra;
        //    con la suscripción vencida tampoco (en pantalla solo puede pagar).
        $empresa = $this->empresa($principal, (int)$llave['empresa_id']);
        $db = Conexion::de($empresa['nombre_base_datos']);
        if (!Migraciones::alDia($db)) Migraciones::aplicar($db);

        // Lo que los servicios leen de la sesión, sin abrir una sesión:
        // quién hizo el cambio en la bitácora es «API · nombre de la llave».
        $_SESSION = [
            'usuario_id'     => null,
            'usuario_nombre' => 'API · ' . $llave['nombre'],
            'usuario_rol'    => $llave['rol'],
            'empresa_id'     => (int)$empresa['id'],
            'empresa_db'     => $empresa['nombre_base_datos'],
            'empresa_nombre' => $empresa['nombre_empresa'],
            'via_api'        => true,
        ];
        Auditoria::en($db);

        $ctx = new Contexto($db, $llave, $empresa, $entrada, $this->porMinuto);

        // 5. El permiso.
        if ($permiso !== null && !$ctx->puede($permiso)) {
            throw new ErrorApi(403, 'sin_permiso',
                "El rol de esta llave ({$llave['rol']}) no puede hacer eso.", ['permiso' => $permiso]);
        }

        $clase = __NAMESPACE__ . '\\Controlador\\' . $destino[0];
        return call_user_func_array([new $clase(), $destino[1]], array_merge([$ctx], $args));
    }

    /** La llave: Authorization: Bearer …, o X-Api-Key si el hosting se come la primera. */
    private function token(Entrada $e)
    {
        $auth = $e->encabezado('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return $m[1];
        return $e->encabezado('X-Api-Key');
    }

    private function ruta($metodo, $uri)
    {
        $r = new Router();
        $permisos = [];
        foreach (self::RUTAS as $x) {
            if ($x[0] === 'GET')  $r->get($x[1], $x[2]);
            if ($x[0] === 'POST') $r->post($x[1], $x[2]);
            $permisos[$x[1]] = $x[3];
        }
        $h = $r->despachar($metodo === 'HEAD' ? 'GET' : $metodo, $uri);
        if ($h !== null) return [$h['destino'], $h['args'], $permisos[$h['patron']]];

        // ¿Existe con otro método? Entonces es 405, no 404.
        $otros = [];
        foreach (['GET', 'POST'] as $m) {
            if ($m !== $metodo && $r->despachar($m, $uri) !== null) $otros[] = $m;
        }
        if ($otros) {
            throw new ErrorApi(405, 'metodo_no_permitido',
                "Esa ruta no acepta $metodo.", ['permitidos' => $otros]);
        }
        throw new ErrorApi(404, 'ruta_no_encontrada', 'Esa ruta no existe en la API. Ver docs/api.');
    }

    private function empresa(\PDO $principal, $id)
    {
        $st = $principal->prepare("
            SELECT id, nombre_empresa, nombre_base_datos, activo, fecha_vencimiento
            FROM empresas WHERE id = ?");
        $st->execute([$id]);
        $e = $st->fetch();
        if (!$e || empty($e['activo'])) {
            throw new ErrorApi(403, 'empresa_suspendida', 'La cuenta de esta empresa está suspendida.');
        }
        if (!empty($e['fecha_vencimiento']) && strtotime($e['fecha_vencimiento']) < strtotime('today')) {
            throw new ErrorApi(402, 'suscripcion_vencida',
                'La suscripción venció el ' . date('d/m/Y', strtotime($e['fecha_vencimiento']))
                . '. Renueva el plan desde Mi cuenta para volver a usar la API.');
        }
        if (trim((string)$e['nombre_base_datos']) === '') {
            throw new ErrorApi(403, 'empresa_suspendida', 'Esta empresa no tiene base de datos asignada.');
        }
        return $e;
    }
}
