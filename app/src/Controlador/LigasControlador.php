<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\LigaRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\LigaPago;
use LibertyFin\Servicio\RegistrarPago;
use LibertyFin\Vista\Plantilla;

/**
 * Ligas de pago.
 *
 * Una liga pide el dinero; no lo cobra. El abono se aplica cuando el
 * proveedor confirma, y solo entonces entra al corte, a los reportes y
 * a las comisiones.
 */
final class LigasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');

        $estado = Peticion::opcion('estado',
                    ['', 'pendientes', 'por_aprobar', 'pagada', 'vencidas'], 'pendientes');
        $buscar = trim(Peticion::texto('q', ''));
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 20;
        $total  = $repo->cuantas($estado, $buscar);

        Plantilla::pagina('ligas/index', [
            'titulo'    => 'Ligas de pago',
            'icono'     => 'cobro',
            'subtitulo' => $cfg && !empty($cfg['sandbox']) ? 'Modo de pruebas' : 'Cobro en línea',
            'ligas'     => $repo->listado($estado, $buscar, $porPag, ($pagina-1)*$porPag),
            'cifras'    => $repo->cifras(),
            'metodos'   => LigaPago::METODOS,
            'listo'     => $cfg !== null && (new LigaPago($cfg))->listo(),
            'sandbox'   => $cfg && !empty($cfg['sandbox']),
            'pendientes'=> (new VentaRepo($db))->conSaldo(30),
            'estado' => $estado, 'q' => $buscar,
            'total' => $total, 'pagina' => $pagina,
            'paginas' => max(1, (int)ceil($total / $porPag)),
            'reciente'  => $_SESSION['lf_liga'] ?? null,
            'exige'     => (new \LibertyFin\Datos\ConfigRepo($db))->exigeAprobacion(),
            'aprobar'   => $repo->listado('por_aprobar', '', 50),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_liga']);
    }

    /** Genera una liga para una venta con saldo, o por un monto suelto. */
    /** Genera una liga para una venta con saldo, o por un monto suelto. */
    public function generar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');
        if (!$cfg) $this->a('Faltan las credenciales de SPEI en config/integraciones.php.', 'error');

        $ventaId = (int)($_POST['venta'] ?? 0);
        $venta   = null;
        $cliente = trim($_POST['cliente'] ?? '');
        $desc    = trim($_POST['descripcion'] ?? '');

        if ($ventaId) {
            $venta = (new VentaRepo($db))->detalle($ventaId);
            if (!$venta) $this->a('Esa venta no existe.', 'error');
            // El monto sale del SALDO de la venta, no del formulario: así
            // no se puede generar una liga por más de lo que debe, ni por
            // una venta que ya se liquidó.
            $monto   = round((float)$venta['saldo'], 2);
            $cliente = $venta['cliente'] ?: 'Público general';
            $desc    = $desc ?: ('Venta ' . $venta['codigo_venta']);
            if ($monto <= 0.01) $this->a('Esa venta ya está liquidada.', 'error');
        } else {
            $monto = round((float)($_POST['monto'] ?? 0), 2);
            if ($monto <= 0) $this->a('Escribe el monto a cobrar.', 'error');
            if ($desc === '') $this->a('Escribe de qué es el cobro.', 'error');
        }

        // ⟵ AJUSTE: el método se normaliza al nombre canónico.
        //
        // El `<select>` de Ligas ofrece `todos` —alias viejo de tarjeta—
        // como opción por omisión. Guardarlo así en la base dejaba dos
        // valores distintos para la MISMA forma de pago: `tarjeta` desde
        // Caja y `todos` desde aquí. Luego las consultas por método
        // había que escribirlas incluyendo los dos.
        $metodo = LigaPago::normalizar($_POST['metodo'] ?? 'tarjeta');
        $api = new LigaPago($cfg);

        // ⟵ AJUSTE: la semilla sale de LigaPago::semilla().
        //
        // Caja, Ligas y Plan usan la misma función. Da 15 dígitos únicos
        // en cada intento (ver su comentario): reintentar el cobro de una
        // venta ya no choca con la referencia del intento anterior.
        $semilla = \LibertyFin\Servicio\LigaPago::semilla($ventaId);

        $r = $api->generar([
            'monto' => $monto, 'descripcion' => $desc, 'metodo' => $metodo,
            'referencia' => $semilla, 'id' => $semilla,
            'cliente' => $cliente ?: 'Publico general',
            'dias' => (int)($_POST['dias'] ?? 0) ?: null,
        ]);
        if (!$r) $this->a('No se generó la liga: ' . $api->error(), 'error');

        try {
            $id = $repo->crear([
                'referencia' => $r['referencia'], 'venta_id' => $ventaId ?: null,
                'cliente' => $cliente, 'monto' => $monto, 'metodo' => $metodo,
                'descripcion' => $desc, 'liga' => $r['liga'], 'clabe' => $r['clabe'],
                'barras' => $r['barras'], 'remota' => $r['remota'] ?? null,
                'imagen' => $r['imagen'] ?? null,
                'formato' => $r['formato'] ?? null,
                'vence' => $r['vence'], 'pruebas' => $r['pruebas'],
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
            ]);
            // En qué base vive este cobro, con todos los números con que
            // el proveedor puede avisar, para no recorrer todas las bases.
            \LibertyFin\Servicio\Cobros::apuntarCobro($r, $metodo);
            Auditoria::anota('pago.registrar', 'liga de pago · ' . $desc,
                null, Dinero::pesos($monto) . ' · ' . $metodo);
            $_SESSION['lf_liga'] = $repo->porId($id);
            $this->a('Liga generada. Compártela con el cliente.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar liga: ' . $e->getMessage());
            $this->a('La liga se generó pero no se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Pregunta al proveedor si ya pagaron.
     *
     * Y si pagaron, aplica el abono. Es el único lugar donde una liga se
     * convierte en dinero.
     */
    public function revisar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');
        if (!$cfg) $this->a('Faltan las credenciales de SPEI.', 'error');

        $api = new LigaPago($cfg);
        $una = (int)($_POST['id'] ?? 0);
        $lista = $una ? array_filter([$repo->porId($una)]) : $repo->porRevisar(25);
        if (!$lista) $this->a('No hay ligas pendientes que revisar.', 'ok');

        $exige = (new \LibertyFin\Datos\ConfigRepo($db))->exigeAprobacion();
        $cobradas = 0; $revisadas = 0; $fallos = 0; $porAprobar = 0; $avisan = 0;
        foreach ($lista as $l) {
            if ($l['estado'] === 'pagada') continue;

            // SPEI Y TIENDA NO SE CONSULTAN. El proveedor no tiene
            // servicio para preguntarles: avisa él cuando el dinero
            // entra. Antes se les preguntaba igual y cada vuelta sumaba
            // un fallo, así que el aviso decía "no se pudieron
            // consultar" de cobros que estaban perfectamente bien.
            if (!LigaPago::consultable($l['metodo'])) { $avisan++; continue; }

            $r = $api->estado($l['referencia'], $l['metodo'], $l['remota'] ?? '');
            $revisadas++;
            if ($r === null) { $fallos++; continue; }

            if (!$r['pagado']) { $repo->marcarRevisada($l['id']); continue; }

            // Pagaron. Si el negocio exige aprobacion, aqui se detiene:
            // queda marcada como confirmada por el proveedor pero el
            // abono no entra hasta que alguien la apruebe.
            if ($exige) {
                $repo->marcarRevisada($l['id'], 'por_aprobar');
                $porAprobar++;
                continue;
            }

            // Ahora si entra el dinero.
            try {
                $pagoId = null;
                if ($l['venta_id']) {
                    $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                        'monto'      => $l['monto'],
                        'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                        'referencia' => 'Liga ' . $l['referencia'],
                        'fecha'      => date('Y-m-d'),
                        'usuario_id' => $_SESSION['usuario_id'] ?? null,
                    ]);
                    $pagoId = $res['pago_id'] ?? null;
                }
                $repo->marcarPagada($l['id'], $pagoId);
                Auditoria::anota('pago.registrar',
                    'liga cobrada · ' . $l['referencia'], 'pendiente', Dinero::pesos($l['monto']));
                $cobradas++;
            } catch (\Throwable $e) {
                error_log('[LibertyFin] abonar liga ' . $l['referencia'] . ': ' . $e->getMessage());
                $fallos++;
            }
        }

        if ($porAprobar) {
            $this->a($porAprobar . ' pago' . ($porAprobar==1?'':'s') . ' confirmado'
                . ($porAprobar==1?'':'s') . ' por el proveedor, esperando tu aprobación. '
                . 'El abono entra cuando lo apruebes.', 'ok');
        }
        $cola = $avisan
            ? ' ' . $avisan . ' de SPEI o tienda no se consultan: el proveedor avisa cuando '
              . 'entra el dinero.'
            : '';
        $this->a($cobradas
            ? $cobradas . ' liga' . ($cobradas==1?'':'s') . ' cobrada'
              . ($cobradas==1?'':'s') . '. El abono ya está aplicado.' . $cola
            : $revisadas . ' revisada' . ($revisadas==1?'':'s')
              . ($revisadas ? ', ninguna pagada todavía.' : '.')
              . ($fallos ? ' ' . $fallos . ' no se pudieron consultar: ' . $api->error() : '')
              . $cola,
            $fallos && !$cobradas ? 'error' : 'ok');
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ligas'); exit;
    }

    /**
     * El comprobante que el cliente se lleva para pagar en tienda.
     *
     * Se imprime o se manda por mensaje. Lleva el codigo de barras, la
     * referencia escrita por si el escaner falla, los pasos y las
     * tiendas donde se puede pagar.
     */
    public function documento($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $l = (new LigaRepo($db))->porId($id);
        if (!$l) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo' => 'No existe'], 'layout-limpio');
            return;
        }
        $cfg = Integraciones::de('spei') ?: [];

        Plantilla::pagina('ligas/documento', [
            'titulo'   => 'Ficha de pago',
            'l'        => $l,
            'empresa'  => $_SESSION['empresa_nombre'] ?? 'LibertyFin',
            // El nombre con el que el convenio aparece en la caja de la
            // tienda. Casi nunca es el nombre comercial, y si el cliente
            // dice el equivocado el cajero no lo encuentra.
            'convenio' => trim((string)($cfg['nombre_convenio'] ?? ''))
                          ?: ($_SESSION['empresa_nombre'] ?? 'LibertyFin'),
            'tiendas'  => self::tiendas($cfg),
        ], 'layout-limpio');
    }

    /**
     * Donde se puede pagar.
     *
     * Sale de la configuracion porque depende del convenio de cada
     * proveedor: no todos aceptan las mismas cadenas, y poner una lista
     * fija manda gente a una tienda donde la van a rechazar.
     */
    private static function tiendas(array $cfg)
    {
        $propias = array_filter(array_map('trim',
            explode(',', (string)($cfg['tiendas'] ?? ''))));
        return $propias ?: [
            'OXXO', '7-Eleven', 'Farmacias Guadalajara', 'Farmacias Benavides',
            'Circle K', 'Waldos', 'Del Sol', 'Woolworth',
        ];
    }

    /**
     * Aprueba un pago que el proveedor ya confirmo.
     *
     * Solo existe cuando el negocio pidio revisar los pagos. Es el
     * momento en que el dinero entra de verdad: antes de esto la venta
     * sigue con saldo aunque el cliente ya haya pagado.
     */
    public function aprobar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $l    = $repo->porId((int)($_POST['id'] ?? 0));
        if (!$l) $this->a('Esa liga no existe.', 'error');
        if ($l['estado'] === 'pagada') $this->a('Ese pago ya estaba aplicado.', 'ok');
        if ($l['estado'] !== 'por_aprobar') {
            $this->a('Ese pago todavía no lo confirma el proveedor.', 'error');
        }
        try {
            $pagoId = null;
            if ($l['venta_id']) {
                $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                    'monto'      => $l['monto'],
                    'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                    'referencia' => 'Liga ' . $l['referencia'],
                    'fecha'      => date('Y-m-d'),
                    'usuario_id' => $_SESSION['usuario_id'] ?? null,
                ]);
                $pagoId = $res['pago_id'] ?? null;
            }
            $repo->marcarPagada($l['id'], $pagoId);
            Auditoria::anota('pago.registrar', 'pago aprobado · ' . $l['referencia'],
                'por aprobar', Dinero::pesos($l['monto']));
            $this->a('Pago aprobado. El abono ya está aplicado.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] aprobar pago: ' . $e->getMessage());
            $this->a('No se pudo aplicar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Confirmar un cobro a mano, desde el modal de la caja.
     *
     * PARA QUE, SI EL SISTEMA LO DETECTA SOLO
     *
     * Porque no siempre lo detecta. El pago en tienda tarda horas, el
     * SPEI de un banco chico puede tardar, y a veces el cliente ensena
     * el comprobante en el mostrador. Obligar al cajero a esperar lo
     * deja con el cliente enfrente sin poder cerrar la venta.
     *
     * Queda anotado como confirmacion manual, con quien la hizo: si
     * despues no aparece el deposito, se sabe a quien preguntarle.
     */
    public function confirmar()
    {
        if (!$this->token()) $this->aJson(['ok'=>false,'error'=>'No se pudo verificar el formulario.']);

        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $l    = $repo->porId((int)($_POST['id'] ?? 0));
        if (!$l) $this->aJson(['ok'=>false,'error'=>'Ese cobro no existe.']);
        if ($l['estado'] === 'pagada') $this->aJson(['ok'=>true,'ya'=>true]);

        try {
            $pagoId = null;
            if ($l['venta_id']) {
                $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                    'monto'      => $l['monto'],
                    'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                    'referencia' => 'Cobro ' . $l['referencia'] . ' (confirmado a mano)',
                    'fecha'      => date('Y-m-d'),
                    'usuario_id' => $_SESSION['usuario_id'] ?? null,
                ]);
                $pagoId = $res['pago_id'] ?? null;
            }
            $repo->marcarPagada($l['id'], $pagoId);
            Auditoria::anota('pago.registrar',
                'confirmado a mano · ' . $l['referencia'], 'esperando',
                Dinero::pesos($l['monto']));
            $this->aJson(['ok' => true, 'monto' => (float)$l['monto']]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] confirmar a mano: ' . $e->getMessage());
            $this->aJson(['ok'=>false,'error'=>'No se pudo aplicar: ' . $e->getMessage()]);
        }
    }

    private function aJson(array $d)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
