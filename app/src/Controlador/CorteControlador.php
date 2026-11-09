<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CajaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class CorteControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new CajaRepo($db);
        $suc  = (int)($_SESSION['sucursal_id'] ?? 0);
        $usr  = (int)($_SESSION['usuario_id'] ?? 0);

        $p = Peticion::opcion('t', ['turno','historial','cajas'], 'turno');
        // La vista de todas las cajas es del que responde por el dinero.
        if ($p === 'cajas' && !\LibertyFin\Dominio\Permisos::puede('ver.usuarios')) $p = 'turno';

        if ($p === 'cajas') {
            Plantilla::pagina('corte/cajas', [
                'titulo'    => 'Corte de caja',
                'icono'     => 'caja',
                'subtitulo' => 'Cajeros y sus cajas · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
                'pestana'   => 'cajas',
                'cajeros'   => $repo->porSucursal($suc),
                'yo'        => $usr,
                'puedeVerComo' => \LibertyFin\Dominio\Permisos::puede('ver.usuarios')
                                  && !\LibertyFin\Servicio\Suplantar::activa(),
                'token'     => $_SESSION['lf_token'] ?? '',
                'aviso'     => $_SESSION['lf_aviso'] ?? null,
            ]);
            unset($_SESSION['lf_aviso']);
            return;
        }

        if ($p === 'historial') {
            $pagina  = max(1, Peticion::entero('p', 1));
            $porPag  = Peticion::POR_PAGINA;
            $totalC  = $repo->cuantosCortes($suc);
            Plantilla::pagina('corte/historial', [
                'titulo'    => 'Corte de caja',
                'icono'     => 'caja',
                'subtitulo' => 'Historial · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
                'pestana'   => 'historial',
                'cortes'    => $repo->historialCompleto($suc, $porPag, ($pagina-1)*$porPag),
                'pagina'    => $pagina,
                'paginas'   => max(1, (int)ceil($totalC / $porPag)),
                'totalC'    => $totalC,
                'resumen'   => $repo->resumenHistorial($suc),
                'aviso'     => $_SESSION['lf_aviso'] ?? null,
            ]);
            unset($_SESSION['lf_aviso']);
            return;
        }

        // El turno sale de la base, no de la sesión: ver Servicio\Caja.
        $caja = \LibertyFin\Servicio\Caja::abierta($db);
        $mov = $cobros = [];
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        $totalC = 0;
        if ($caja) {
            $desde  = \LibertyFin\Servicio\Caja::desde($caja);
            $mov    = $repo->movimiento($caja['id'], $desde);
            // Paginados: un turno con cien cobros desplegaba cien
            // renglones y dejaba la columna de al lado minúscula.
            $totalC = $repo->cuantosCobros($caja['id']);
            $cobros = $repo->cobrosDelTurno($caja['id'], $porPag, ($pagina - 1) * $porPag);
        }

        Plantilla::pagina('corte/index', [
            'titulo'    => 'Corte de caja',
            'icono'     => 'caja',
            'subtitulo' => $caja ? 'Caja abierta · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz')
                                 : 'Sin caja abierta',
            'pestana'   => 'turno',
            'caja'      => $caja,
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil($totalC / $porPag)),
            'totalC'    => $totalC,
            'mov'       => $mov,
            'cobros'    => $cobros,
            'historial' => $repo->historial($suc, 8),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function abrir()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new CajaRepo($db);
        $suc  = \LibertyFin\Servicio\Caja::sucursal();
        $usr  = (int)($_SESSION['usuario_id'] ?? 0);

        if ($ya = $repo->abierta($usr, $suc)) {
            $this->volver('Ya tienes la caja ' . (int)$ya['id'] . ' abierta desde el '
                . date('d/m/Y H:i', strtotime(\LibertyFin\Servicio\Caja::desde($ya)))
                . '. Ciérrala antes de abrir otra.', 'error');
        }

        $monto = (float)($_POST['monto'] ?? 0);
        if ($monto < 0) $this->volver('El fondo no puede ser negativo.', 'error');

        try {
            $id = $repo->abrir($suc, $usr, $monto, $_POST['nota'] ?? '');
            // Se recuerda YA. Antes el turno solo quedaba apuntado
            // después de volver a /corte, y entre una cosa y otra las
            // ventas se guardaban sin caja.
            $nueva = $repo->porId($id);
            if ($nueva) \LibertyFin\Servicio\Caja::recordar($nueva);
            Auditoria::anota('caja.abrir', 'caja ' . $id, null,
                'fondo ' . Dinero::pesos($monto));
            $this->volver('Caja abierta con un fondo de ' . Dinero::pesos($monto)
                . '. Ya puedes cobrar: las ventas entran a este turno.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] abrir caja: ' . $e->getMessage());
            $this->volver('No se pudo abrir la caja.', 'error');
        }
    }

    public function cerrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new CajaRepo($db);
        $caja = \LibertyFin\Servicio\Caja::abierta($db);
        if (!$caja) $this->volver('No hay ninguna caja abierta.', 'error');

        $desde = \LibertyFin\Servicio\Caja::desde($caja);
        $mov   = $repo->movimiento($caja['id'], $desde);

        // Lo esperado en el cajón: fondo inicial más lo cobrado EN EFECTIVO.
        // Transferencias y tarjeta no pasan por ahí.
        $esperado = Dinero::centavos((float)$caja['monto_apertura'] + (float)($mov['efectivo'] ?? 0));
        $contado  = (float)($_POST['contado'] ?? 0);
        if ($contado < 0) $this->volver('El conteo no puede ser negativo.', 'error');

        $dif = Dinero::centavos($contado - $esperado);
        if (abs($dif) > 0.009 && trim($_POST['nota'] ?? '') === '') {
            $this->volver('Hay una diferencia de ' . Dinero::pesos($dif)
                . '. Escribe a qué se debe antes de cerrar.', 'error');
        }

        try {
            $repo->cerrar($caja['id'], $contado, $esperado, $_POST['nota'] ?? '');
            Auditoria::anota('caja.cerrar', 'caja ' . $caja['id'],
                'esperado $' . number_format($esperado, 2),
                'contado $' . number_format($contado, 2)
                . (abs($contado - $esperado) > 0.009
                   ? ' · diferencia $' . number_format($contado - $esperado, 2) : ' · cuadró'));
            \LibertyFin\Servicio\Caja::olvidar();
            $this->volver(abs($dif) <= 0.009
                ? 'Caja cerrada. Cuadró exacto.'
                : 'Caja cerrada con una diferencia de ' . Dinero::pesos($dif) . '.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cerrar caja: ' . $e->getMessage());
            $this->volver('No se pudo cerrar la caja.', 'error');
        }
    }

    /**
     * Cerrar la caja de OTRO.
     *
     * Pasa de verdad: el cajero se fue sin cerrar y el turno queda
     * abierto toda la noche, llevándose las ventas del día siguiente al
     * corte de ayer. El administrador lo cierra con lo que haya en el
     * cajón y anota por qué.
     *
     * Siempre pide nota, aunque cuadre: el que cierra no es el que contó,
     * y dentro de un mes la única forma de saber qué pasó es haberlo
     * escrito.
     */
    public function cerrarAjena()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volverCajas('No se pudo verificar el formulario.', 'error');
        if (!\LibertyFin\Dominio\Permisos::puede('ver.usuarios')) {
            $this->volverCajas('Solo un administrador puede cerrar la caja de alguien más.', 'error');
        }

        $repo = new CajaRepo($db);
        $caja = $repo->porId((int)($_POST['caja_id'] ?? 0));
        if (!$caja || $caja['estado'] !== 'abierta') $this->volverCajas('Esa caja ya no está abierta.', 'error');

        $nota = trim((string)($_POST['nota'] ?? ''));
        if (mb_strlen($nota) < 5) {
            $this->volverCajas('Escribe por qué la cierras tú: es lo único que va a quedar.', 'error');
        }
        $contado = (float)($_POST['contado'] ?? 0);
        if ($contado < 0) $this->volverCajas('El conteo no puede ser negativo.', 'error');

        try {
            $desde    = \LibertyFin\Servicio\Caja::desde($caja);
            $mov      = $repo->movimiento($caja['id'], $desde);
            $esperado = Dinero::centavos((float)$caja['monto_apertura'] + (float)($mov['efectivo'] ?? 0));
            $quien    = $_SESSION['usuario_nombre'] ?? 'un administrador';

            $repo->cerrar($caja['id'], $contado, $esperado, 'Cerrada por ' . $quien . ': ' . $nota);
            Auditoria::anota('caja.cerrar', 'caja ' . $caja['id'] . ' de otro usuario',
                'esperado $' . number_format($esperado, 2),
                'contado $' . number_format($contado, 2) . ' · ' . $nota, $db);

            $dif = Dinero::centavos($contado - $esperado);
            $this->volverCajas('Caja ' . (int)$caja['id'] . ' cerrada. '
                . (abs($dif) <= 0.009 ? 'Cuadró exacto.'
                   : 'Diferencia de ' . Dinero::pesos($dif) . '.'), 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cerrar caja ajena: ' . $e->getMessage());
            $this->volverCajas('No se pudo cerrar.', 'error');
        }
    }

    /** Ver el sistema como otro usuario. La contraseña es la PROPIA. */
    public function verComo()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volverCajas('No se pudo verificar el formulario.', 'error');
        try {
            $otro = \LibertyFin\Servicio\Suplantar::entrar($db,
                        (int)($_POST['usuario_id'] ?? 0), (string)($_POST['clave'] ?? ''));
            $_SESSION['lf_aviso'] = ['texto' => 'Estás viendo el sistema como ' . $otro['nombre']
                . '. Lo que hagas queda anotado a tu nombre. Usa «Volver a mi cuenta» cuando termines.',
                'tipo' => 'ok'];
            header('Location: /panel'); exit;
        } catch (\InvalidArgumentException $e) {
            $this->volverCajas($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ver como: ' . $e->getMessage());
            $this->volverCajas('No se pudo cambiar de cuenta.', 'error');
        }
    }

    /** Volver a la cuenta propia. No pide contraseña: ya se verificó al entrar. */
    public function volverYo()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        \LibertyFin\Servicio\Suplantar::salir($db);
        $_SESSION['lf_aviso'] = ['texto' => 'Volviste a tu cuenta.', 'tipo' => 'ok'];
        header('Location: /panel'); exit;
    }

    private function volverCajas($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /corte?t=cajas'); exit;
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /corte'); exit;
    }
}