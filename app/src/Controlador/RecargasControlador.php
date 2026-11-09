<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\EmidaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Emida;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Recargas y pago de servicios.
 *
 * EL ORDEN IMPORTA Y NO ES EL OBVIO
 *
 *   1. Se elige un PRODUCTO del catálogo, no una compañía y un monto.
 *      Cada combinación es un identificador distinto para Emida, y
 *      mandar otra cosa da el código 51.
 *   2. Se envía al proveedor.
 *   3. Y solo si sale, se registra el cobro.
 *
 * NADA A MANO
 *
 * El catálogo se baja solo: la primera vez al entrar, y después por
 * detrás cuando tiene más de CATALOGO_HORAS. Solo AGREGA y actualiza: lo
 * que no venga en la descarga no se apaga (la conexión puede traer menos
 * productos que el portal de Emida). El saldo también se consulta solo
 * (por detrás, para que la página no espere al proveedor).
 */
final class RecargasControlador
{
    /** Cada cuánto se vuelve a bajar el catálogo. */
    const CATALOGO_HORAS = 12;
    /** Si falló bajarlo, no se reintenta solo antes de esto (segundos). */
    const REINTENTO = 600;
    /** El saldo consultado vale este rato (segundos): no se pregunta en cada vuelta. */
    const SALDO_VIGENCIA = 60;
    /** Tarjetas por página del catálogo. */
    const POR_PAGINA = 18;

    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new EmidaRepo($db);
        $cfg  = Integraciones::de('emida');
        $api  = new Emida($cfg);

        // Sin catálogo no se puede vender nada: se baja ahora, sin pedirlo.
        // Si falla, no se reintenta en cada vuelta (cada intento espera al
        // proveedor): se dice por qué y queda un «Reintentar».
        $error = null;
        if ($repo->cuantos() === 0) {
            $ultimo = (int)($_SESSION['lf_emida_intento'] ?? 0);
            if (time() - $ultimo > self::REINTENTO) {
                $_SESSION['lf_emida_intento'] = time();
                $r = $this->bajarCatalogo($db);
                if (!$r['ok']) $_SESSION['lf_emida_error'] = $r['error'];
                else unset($_SESSION['lf_emida_error']);
            }
            $error = $_SESSION['lf_emida_error'] ?? null;
        }
        $actualizado = $repo->cuandoSeActualizo();

        Plantilla::pagina('recargas/index', [
            'titulo'      => 'Recargas y servicios',
            'icono'       => 'bolsa',
            'subtitulo'   => !empty($cfg['sandbox']) ? 'Modo de pruebas' : 'En producción',
            'sandbox'     => !empty($cfg['sandbox']),
            'cifrado'     => $api->cifrado(),
            'aceptado'    => !empty($cfg['acepto_sin_cifrar']),
            // Todo el catálogo: se busca y se filtra en el navegador, sin
            // recargar, igual que los productos en Caja.
            // La primera página. Las demás, la búsqueda y las categorías se
            // piden por detrás (productos()): sin recargar y sin tope, el
            // catálogo completo esté del tamaño que esté.
            'productos'   => $repo->productos('', '', '', self::POR_PAGINA, 0),
            'totalCatalogo' => $repo->cuantos(),
            'porPagina'   => self::POR_PAGINA,
            'categorias'  => $repo->categorias(),
            // Productos que una descarga dejó de traer: se avisa y se pueden
            // volver a mostrar (los administradores).
            'ocultos'     => $repo->ocultos(),
            'puedeAjustes'=> \LibertyFin\Dominio\Permisos::puede('editar.ajustes'),
            'actualizado' => $actualizado,
            // Viejo: se vuelve a bajar por detrás al abrir la página.
            'refrescar'   => $actualizado && strtotime($actualizado) < time() - self::CATALOGO_HORAS * 3600,
            'errorCatalogo' => $error,
            'hoy'         => $repo->cifrasDelDia(),
            'transacciones' => $repo->delDia(),
            'saldo'       => $this->saldoGuardado(),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Bajar el catálogo. Por detrás (lo pide la página cuando está viejo)
     * contesta JSON; desde el botón «Reintentar», vuelve a la página.
     */
    public function sincronizar()
    {
        $json = !empty($_SERVER['HTTP_X_LF_JSON']);
        if (!$this->token()) {
            if ($json) $this->json(['ok' => false, 'mensaje' => 'No se pudo verificar la sesión.']);
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        $_SESSION['lf_emida_intento'] = time();
        $r = $this->bajarCatalogo(Conexion::de($_SESSION['empresa_db']));
        if ($r['ok']) unset($_SESSION['lf_emida_error']); else $_SESSION['lf_emida_error'] = $r['error'];
        $repo = new EmidaRepo(Conexion::de($_SESSION['empresa_db']));
        $msj = $r['ok']
            ? 'Emida mandó ' . number_format($r['n']) . ' productos. En el catálogo hay '
              . number_format($repo->cuantos()) . ' a la venta'
              . ($repo->ocultos() ? ' y ' . number_format($repo->ocultos()) . ' ocultos.' : '.')
            : $r['error'];
        if ($json) $this->json(['ok' => $r['ok'], 'n' => $r['n'], 'mensaje' => $msj]);
        $this->volver($msj, $r['ok'] ? 'ok' : 'error');
    }

    /**
     * Una página del catálogo (búsqueda, categoría, página), en JSON con
     * las tarjetas ya hechas. La pantalla la pide sin recargar.
     */
    public function productos()
    {
        $repo = new EmidaRepo(Conexion::de($_SESSION['empresa_db']));
        $q   = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
        $cat = mb_substr(trim((string)($_GET['cat'] ?? '')), 0, 80);
        $total = $repo->cuantos($q, $cat);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina = min($paginas, max(1, (int)($_GET['p'] ?? 1)));
        ob_start();
        Plantilla::parcial('recargas/tarjetas', ['productos' => $repo->productos($q, $cat, '', self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA)]);
        $html = ob_get_clean();
        $this->json(['ok' => true, 'html' => $html, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas]);
    }

    /** Vuelve a mostrar los productos que una descarga apagó. Solo administradores. */
    public function mostrarOcultos()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        if (!\LibertyFin\Dominio\Permisos::puede('editar.ajustes')) $this->volver('Solo un administrador puede hacerlo.', 'error');
        $n = (new EmidaRepo(Conexion::de($_SESSION['empresa_db'])))->mostrarOcultos();
        if ($n) Auditoria::anota('ajustes.cambiar', 'catálogo de Emida (ocultos)', null, $n . ' productos vueltos a mostrar');
        $this->volver($n ? number_format($n) . ' productos vuelven a estar en el catálogo.' : 'No había productos ocultos.', 'ok');
    }

    /** El saldo con el proveedor, en JSON. La página lo pide sola al abrir. */
    public function saldo()
    {
        $g = $_SESSION['lf_emida_saldo'] ?? null;
        if ($g && time() - $g['cuando'] < self::SALDO_VIGENCIA && empty($_GET['fresco'])) {
            $this->json(['ok' => true, 'saldo' => $g['saldo'], 'cuando' => date('H:i', $g['cuando'])]);
        }
        ob_start();
        $r = (new Emida(Integraciones::de('emida')))->saldo();
        ob_end_clean();
        if (!$r['ok'] || $r['saldo'] === null) {
            $this->json(['ok' => false, 'mensaje' => $r['error'] ?? 'El proveedor no dijo el saldo.']);
        }
        $_SESSION['lf_emida_saldo'] = ['saldo' => (float)$r['saldo'], 'cuando' => time()];
        $this->json(['ok' => true, 'saldo' => (float)$r['saldo'], 'cuando' => date('H:i')]);
    }

    /** Vende un producto del catálogo. */
    public function vender()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new EmidaRepo($db);

        $p = $repo->porId($_POST['producto'] ?? '');
        if (!$p) $this->volver('Ese producto ya no está en el catálogo.', 'error');

        $cuenta = preg_replace('/[^0-9]/', '', (string)($_POST['cuenta'] ?? ''));
        if (strlen($cuenta) < 6) {
            $this->volver('Escribe el número o la referencia completa.', 'error');
        }

        if ((float)$p['monto'] > 0) {
            $monto = (float)$p['monto'];
        } else {
            $monto = round((float)($_POST['monto'] ?? 0), 2);
            $min = (float)$p['monto_min'];
            $max = (float)$p['monto_max'];
            if ($min > 0 && $monto < $min) {
                $this->volver('El mínimo de este servicio es ' . Dinero::pesos($min) . '.', 'error');
            }
            if ($max > 0 && $monto > $max) {
                $this->volver('El máximo de este servicio es ' . Dinero::pesos($max) . '.', 'error');
            }
            if ($monto <= 0) $this->volver('Escribe el monto a pagar.', 'error');
        }

        $salesId = date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 4);

        $repo->registrar([
            'sales_id' => $salesId, 'producto_id' => $p['producto_id'],
            'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
            'comision' => $p['comision'], 'estado' => 'pendiente',
            'usuario_id' => $_SESSION['usuario_id'] ?? null,
            'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
        ]);

        ob_start();
        $api = new Emida(Integraciones::de('emida'));
        $r = $api->recargar($cuenta, $p['producto_id'], $monto, $salesId);
        ob_end_clean();
        // Se movió (o pudo moverse) el saldo: la próxima vez se pregunta de nuevo.
        unset($_SESSION['lf_emida_saldo']);

        if (!empty($r['incierta'])) {
            $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
                'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
                'comision' => $p['comision'], 'estado' => 'incierta',
                'mensaje' => $r['error']]);
            error_log('[LibertyFin] recarga incierta ' . $salesId . ' ' . $cuenta);
            $this->volver($r['error'], 'error');
        }

        if (!$r['ok']) {
            $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
                'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
                'comision' => $p['comision'], 'estado' => 'fallida',
                'codigo' => $r['codigo'] ?? null, 'h2h' => $r['h2h'] ?? null,
                'mensaje' => $r['error']]);
            $this->volver($r['error'], 'error');
        }

        $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
            'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
            'comision' => $p['comision'], 'estado' => 'exitosa',
            'folio' => $r['folio'] ?? null, 'mensaje' => !empty($r['duplicada']) ? 'duplicada' : '']);

        Auditoria::anota('pago.registrar', 'recarga ' . $p['nombre'] . ' · ' . $cuenta,
            null, Dinero::pesos($monto));

        $this->volver(
            (!empty($r['duplicada'])
                ? 'Esa misma operación ya se había hecho hace unos minutos, no se cobró dos veces. '
                : $p['nombre'] . ' aplicado a ' . $cuenta . ' por ' . Dinero::pesos($monto) . '. ')
            . (!empty($r['folio']) ? 'Folio ' . $r['folio'] . '.' : ''), 'ok');
    }

    /** @return array ['ok', 'n', 'error'] */
    private function bajarCatalogo($db)
    {
        ob_start();
        $r = (new Emida(Integraciones::de('emida')))->catalogo();
        ob_end_clean();
        if (!$r['ok']) return ['ok' => false, 'n' => 0, 'error' => 'No se pudo traer el catálogo del proveedor: ' . $r['error']];
        if (empty($r['productos'])) {
            return ['ok' => false, 'n' => 0, 'error' => 'El proveedor respondió, pero sin productos. '
                . 'Puede que la cuenta no tenga ninguno asignado todavía.'];
        }
        try {
            // Agregar y actualizar, nunca quitar: lo que no venga en esta
            // descarga se queda como estaba (ver EmidaRepo::guardarCatalogo).
            $n = (new EmidaRepo($db))->guardarCatalogo($r['productos'], false);
            Auditoria::anota('ajustes.cambiar', 'catálogo de Emida', null, $n . ' productos');
            return ['ok' => true, 'n' => $n, 'error' => null];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] catálogo emida: ' . $e->getMessage());
            return ['ok' => false, 'n' => 0, 'error' => 'No se pudo guardar el catálogo.'];
        }
    }

    /** El último saldo consultado en esta sesión, si sigue vigente. */
    private function saldoGuardado()
    {
        $g = $_SESSION['lf_emida_saldo'] ?? null;
        return ($g && time() - $g['cuando'] < self::SALDO_VIGENCIA) ? $g : null;
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function json(array $d)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /recargas'); exit;
    }
}