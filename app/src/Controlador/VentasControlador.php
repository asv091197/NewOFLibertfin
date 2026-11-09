<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Servicio\AsignarComision;
use LibertyFin\Servicio\RegistrarPago;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * Ventas.
 *
 * Compárese con ventas_lista.php del sistema anterior: 2,662 líneas con
 * 12 consultas y 320 bloques HTML mezclados. Aquí el controlador decide,
 * el repositorio consulta y la plantilla pinta.
 */
final class VentasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        // Por defecto, el mes en curso. Nunca "todo": eso fue lo que hacía
        // que el histórico mostrara meses que nadie quería ver.
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        // El estado es el de la insignia de cada fila: liquidada, debe o
        // cancelada (ver VentaRepo::ESTADOS). Un enlace guardado con los
        // valores de antes se traduce en vez de ignorarse.
        $estado = Peticion::opcion('estado',
            array_merge(array_keys(VentaRepo::ESTADOS), ['completada', 'pendiente']));
        $estado = ['completada' => 'liquidada', 'pendiente' => 'debe'][$estado] ?? $estado;
        $filtros = [
            'estado' => $estado,
            'buscar' => Peticion::texto('q'),
        ];

        $pagina  = max(1, Peticion::entero('p', 1));
        $porPag  = Peticion::POR_PAGINA;
        $desfase = ($pagina - 1) * $porPag;

        // Al buscar se mira todo el historial, no solo el periodo del filtro.
        $todo = Fechas::buscandoTodo($filtros['buscar']);
        list($d1, $h1) = Fechas::rango($filtros['buscar'], $desde, $hasta);

        $resumen = $repo->resumen($d1, $h1, $filtros);
        $ventas  = $repo->listado($d1, $h1, $filtros, $porPag, $desfase);
        $total   = $repo->cuantas($d1, $h1, $filtros);
        $saldos  = $repo->saldosAbiertos(5);
        $meses   = $repo->cobradoPorMes(6);

        Plantilla::pagina('ventas/index', [
            'titulo'   => 'Ventas',
            'icono'    => 'venta',
            'subtitulo'=> ($todo ? 'Búsqueda en todo el historial' : Fechas::rotulo($desde, $hasta))
                          . ' · ' . number_format($total) . ' ventas',
            'todo'     => $todo,
            'soloPeriodo' => Peticion::texto('periodo') === '1',
            'resumen'  => $resumen,
            'ventas'   => $ventas,
            'saldos'   => $saldos,
            'meses'    => $meses,
            'desde'    => $desde,
            'hasta'    => $hasta,
            'filtros'  => $filtros,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / $porPag)),
            'total'    => $total,
        ]);
    }


    /** Detalle de una venta: pagos, gastos, IVA y comisiones en una pantalla. */
    public function ver($id)
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }

        Plantilla::pagina('ventas/detalle', [
            'titulo'     => 'Venta ' . $venta['codigo_venta'],
            'icono'      => 'venta',
            'subtitulo'  => ($venta['cliente'] ?: 'Público general') . ' · '
                          . date('d/m/Y H:i', strtotime($venta['fecha'])),
            'venta'      => $venta,
            'lineas'     => $repo->lineas($id),
            // Qué promoción tuvo cada producto, para decirlo junto al descuento.
            'promosVenta'=> (new \LibertyFin\Servicio\Promociones($db))->deVenta($id),
            'metodos'    => (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(),
            'pagos'      => $repo->pagos($id),
            'gastos'     => $repo->gastos($id),
            'comisiones' => $repo->comisiones($id),
            // La base comisionable de cada producto, con su parte del gasto.
            'bases'      => ((new AsignarComision($db))->lineas($id) ?: ['lineas' => []])['lineas'],
            'catalogo'   => (new ComisionRepo($db))->catalogo(),
            'sugeridos'  => (new ComisionRepo($db))->porcentajesUsados(),
            'nueva'      => Peticion::entero('nueva') === 1,
            // Con «Seguimiento de trabajos» encendido: en qué va el trabajo.
            'trabajo'    => $venta['estado'] !== 'cancelada' ? $this->trabajo($db, $id) : null,
            // Con «Envíos» encendido: las guías de esta venta.
            'envios'      => $this->envios($db, $id),
            'puedeEnviar' => $this->moduloEnvios(),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** El seguimiento de la venta y el equipo, o null con el módulo apagado. */
    private function trabajo($db, $id)
    {
        try {
            if (!\LibertyFin\Servicio\Modulos::deSesion()->activo('seguimiento')) return null;
            return [
                't'      => (new \LibertyFin\Servicio\Trabajos($db))->deVenta($id),
                'equipo' => $db->query("SELECT id, nombre FROM comision_colaboradores WHERE COALESCE(activo,1) = 1 ORDER BY nombre")
                               ->fetchAll(\PDO::FETCH_ASSOC),
                'puede'  => \LibertyFin\Dominio\Permisos::puede('editar.seguimiento'),
            ];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] seguimiento en venta ' . $id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Las guías de esta venta, o [] con el módulo apagado.
     *
     * Va en su propio try: una venta tiene que poder abrirse aunque la
     * base todavía no tenga la tabla de envíos. Esa pantalla enseña
     * cobros y comisiones, y dejarla caída por un módulo que ni está
     * encendido sería cambiar un hueco por una pared.
     */
    private function envios($db, $id)
    {
        try {
            if (!\LibertyFin\Servicio\Modulos::deSesion()->activo('envios')) return [];
            return (new \LibertyFin\Datos\EnvioRepo($db))->deVenta($id);
        } catch (\Throwable $e) {
            // Una vez por sesión, no una por venta abierta: con la base
            // atrasada, cada visita a una venta escribiría un renglón y
            // el log se vuelve inservible justo cuando hace falta.
            if (empty($_SESSION['lf_esq_envios_venta'])) {
                $_SESSION['lf_esq_envios_venta'] = 1;
                error_log('[LibertyFin] envíos en venta ' . $id . ': ' . $e->getMessage()
                    . ' · si dice que falta la tabla, aplica config/actualizar-v28.sql');
            }
            return [];
        }
    }

    private function moduloEnvios()
    {
        try {
            return \LibertyFin\Servicio\Modulos::deSesion()->activo('envios')
                && \LibertyFin\Dominio\Permisos::puede('editar.envios');
        } catch (\Throwable $e) { return false; }
    }

    /** Registra un abono. */
    public function pagar($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $r = (new RegistrarPago($db))->abonar($id, [
                'monto'      => $_POST['monto'] ?? 0,
                'metodo'      => in_array($_POST['metodo'] ?? '',
                                    (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(), true)
                                    ? $_POST['metodo'] : 'efectivo',
                'referencia' => trim($_POST['referencia'] ?? ''),
                // En efectivo no hay banco, aunque llegue algo escrito.
                'banco'      => ($_POST['metodo'] ?? '') === 'efectivo'
                                    ? '' : mb_substr(trim($_POST['banco'] ?? ''), 0, 100),
                'fecha'      => Peticion::fecha('fecha', '') ?: ($_POST['fecha'] ?? ''),
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
            ]);
            Auditoria::anota('pago.registrar', 'venta ' . $id,
                null, ($_POST['metodo'] ?? '') . ' $' . ($_POST['monto'] ?? 0));
            $this->volver($id, $r['tipo'] === 'liquidacion'
                ? 'Abono registrado. La venta queda liquidada.'
                : 'Abono registrado. Queda un saldo de ' . \LibertyFin\Dominio\Dinero::pesos($r['saldo']) . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pagar: ' . $e->getMessage());
            $this->volver($id, 'No se pudo registrar el abono.', 'error');
        }
    }

    /** Cancela un pago. Solo admin: mueve dinero ya registrado. */
    public function cancelarPago($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede cancelar un pago.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            (new RegistrarPago($db))->cancelar(
                (int)($_POST['pago'] ?? 0), $_POST['motivo'] ?? '', $_SESSION['usuario_id'] ?? null);
            // Cancelar un pago mueve dinero hacia atrás y recalcula
            // comisiones: de todo lo que hace el sistema, es lo que más
            // falta hace poder reconstruir después.
            Auditoria::anota('pago.cancelar',
                'venta ' . $id . ' · pago ' . (int)($_POST['pago'] ?? 0),
                'activo', 'cancelado · ' . trim($_POST['motivo'] ?? ''));
            $this->volver($id, 'Pago cancelado. Las comisiones ya se recalcularon.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelarPago: ' . $e->getMessage());
            $this->volver($id, 'No se pudo cancelar el pago.', 'error');
        }
    }

    /**
     * Cancela la venta completa: cobros, comisiones, inventario, paquetes y
     * promociones (ver Servicio\CancelarVenta). Solo admin; pide el motivo.
     */
    public function cancelar($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');
        try {
            $r = (new \LibertyFin\Servicio\CancelarVenta($db))->cancelar($id, $_POST['motivo'] ?? '');
            $msg = 'Venta ' . $r['folio'] . ' cancelada.';
            if ($r['cobros']) {
                $msg .= ' Se cancelaron ' . $r['cobros'] . ' cobro' . ($r['cobros'] === 1 ? '' : 's') . ' por '
                      . \LibertyFin\Dominio\Dinero::pesos($r['devuelto']) . ': devuélvele ese dinero al cliente.';
            }
            if ($r['facturada']) $msg .= ' Tiene factura: cancélala también en Facturación.';
            $this->volver($id, $msg, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelar venta ' . $id . ': ' . $e->getMessage());
            $this->volver($id, 'No se pudo cancelar la venta.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($id, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ventas/' . (int)$id); exit;
    }

    /** Asigna una comisión a la venta. */
    public function asignarComision($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede asignar comisiones.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $r = (new AsignarComision($db))->asignar(
                $id, (int)($_POST['colaborador'] ?? 0), $_POST['pct'] ?? 0,
                (int)($_POST['detalle'] ?? 0) ?: null);
            Auditoria::anota('comision.asignar',
                'venta ' . $id . ' · ' . $r['colaborador']
                . ($r['varios'] ? ' · ' . $r['producto'] : ''),
                null, $_POST['pct'] . '% sobre ' . $r['base']);
            $this->volver($id, $r['colaborador'] . ': '
                . \LibertyFin\Dominio\Dinero::pesos($r['asignada'])
                . ' sobre una base de ' . \LibertyFin\Dominio\Dinero::pesos($r['base'])
                . ($r['varios'] ? ' (' . $r['producto'] . ')' : '') . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] asignarComision: ' . $e->getMessage());
            $this->volver($id, 'No se pudo asignar la comisión.', 'error');
        }
    }

    /** Quita una comisión. */
    public function quitarComision($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede quitar comisiones.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $c = (new AsignarComision($db))->quitar((int)($_POST['comision'] ?? 0));
            Auditoria::anota('comision.quitar',
                'venta ' . $id . ' · comisión ' . (int)($_POST['comision'] ?? 0),
                'activa', 'cancelada');
            $this->volver($id, 'Comisión de ' . $c['colaborador_nombre'] . ' retirada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] quitarComision: ' . $e->getMessage());
            $this->volver($id, 'No se pudo quitar la comisión.', 'error');
        }
    }

    /** El ticket, para imprimir. Sin barra lateral ni menús. */
    public function ticket($id) { $this->imprimir($id, false); }

    /**
     * El ticket de la FACTURA: el mismo papel de 80 mm, pero con los
     * datos del CFDI.
     *
     * Un cliente que pide factura casi siempre quiere además algo que
     * llevarse en el momento, y el PDF del PAC no sale de una impresora
     * térmica: es una hoja carta. Esto es el comprobante de siempre con
     * el folio fiscal, el RFC del receptor, el uso del CFDI y el
     * desglose de impuestos — lo que de verdad se le entrega en el
     * mostrador mientras el XML le llega por correo.
     *
     * NO SUSTITUYE AL CFDI y lo dice en el pie: el comprobante fiscal
     * es el XML timbrado. Un papel que se parece a una factura sin
     * serlo es justo lo que después nadie puede deducir.
     */
    public function ticketFactura($id) { $this->imprimir($id, true); }

    /** El ticket, en sus dos formas. Sin layout: es papel, no pantalla. */
    private function imprimir($id, $fiscal)
    {
        $db    = Conexion::de($_SESSION['empresa_db']);
        $repo  = new VentaRepo($db);
        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        // Pedir el ticket fiscal de algo sin timbrar no es un error del
        // sistema: es que todavía no se factura. Se dice y se ofrece el
        // ticket normal, en vez de imprimir una factura en blanco.
        if ($fiscal && empty($venta['factura_uuid'])) {
            Plantilla::pagina('errores/403', [
                'titulo'    => 'Esa venta todavía no se factura',
                'icono'     => 'alerta',
                'subtitulo' => 'Tímbrala en Facturación y vuelve. Mientras tanto puedes imprimir el '
                             . 'ticket normal desde la venta.',
                'permiso'   => '',
            ]);
            return;
        }

        $empresa = null;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $empresa = (new \LibertyFin\Datos\EmpresaRepo($principal))->uno($_SESSION['empresa_id'] ?? 0);
        } catch (\Throwable $e) { /* el ticket se imprime igual sin el membrete */ }

        // La regla de IVA de la empresa, de respaldo para los renglones
        // cuyo producto no dice lo suyo.
        $reglaEmpresa = \LibertyFin\Dominio\Iva::desdeVenta($venta);

        Plantilla::parcial('ventas/ticket', [
            'venta'   => $venta,
            'lineas'  => $repo->lineas($id),
            'promos'  => (new \LibertyFin\Servicio\Promociones($db))->deVenta($id),
            'empresa' => $empresa,
            'fiscal'  => (bool)$fiscal,
            'regla'   => $reglaEmpresa,
        ]);
    }
}