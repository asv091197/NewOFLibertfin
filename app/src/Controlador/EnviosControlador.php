<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\EnvioRepo;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Envios;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Envíos con Envia.com.
 *
 * Hablar con la paquetería es cosa de Servicio\Envia; de dónde sale y a
 * dónde va, de Servicio\Envios. Aquí se arma la pantalla y se decide a
 * dónde regresa el usuario.
 *
 * COTIZAR Y GENERAR SON DOS PASOS, A PROPÓSITO
 *
 * Generar una guía cuesta dinero y no se deshace solo. Entre cotizar y
 * generar hay un clic de por medio y el precio a la vista, para que
 * nadie compre una guía de $380 creyendo que eran $140.
 */
final class EnviosControlador
{
    public function index()
    {
        if (!Modulos::exigir('envios')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Envios($db);

        $estado = Peticion::opcion('estado', array_keys(EnvioRepo::ESTADOS), '');
        $q      = Peticion::texto('q');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 50;

        $datos = ['lista' => [], 'resumen' => [], 'total' => 0, 'faltaMigrar' => false];
        try {
            $repo = $srv->repo();
            $f = ['estado' => $estado, 'q' => $q];
            $datos['total']   = $repo->cuantos($f);
            $datos['lista']   = $repo->listar($f, $porPag, ($pagina - 1) * $porPag);
            $datos['resumen'] = $repo->resumen();
            $datos['origenes']= $repo->origenes();
        } catch (\Throwable $e) {
            $datos['faltaMigrar'] = $this->avisarEsquema('envios', $e);
            $datos['origenes'] = [];
        }

        Plantilla::pagina('envios/index', array_merge($datos, [
            'titulo'    => 'Envíos',
            'icono'     => 'camion',
            'subtitulo' => $datos['total'] . ' ' . ($datos['total'] === 1 ? 'guía' : 'guías'),
            'estado'    => $estado,
            'buscar'    => $q,
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil($datos['total'] / $porPag)),
            'estados'   => EnvioRepo::ESTADOS,
            'listo'     => $srv->listo(),
            'sandbox'   => $srv->sandbox(),
            'puedeEditar' => Permisos::puede('editar.envios'),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]));
        unset($_SESSION['lf_aviso']);
    }

    /** El formulario. Con `?venta=` se llena con los datos del cliente. */
    public function nuevo()
    {
        if (!Modulos::exigir('envios')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Envios($db);
        $ventaId = Peticion::entero('venta');

        $venta = null; $destino = null; $paquetes = null;
        if ($ventaId) {
            $venta = $this->venta($db, $ventaId);
            if (!$venta) { $this->noExiste(); return; }
            $destino  = $srv->destinoDeCliente($venta['cliente_id'] ?? 0);
            $paquetes = [$srv->paqueteDeVenta($ventaId)];
        }

        $this->pintarForm($srv, [
            'venta'    => $venta,
            'destino'  => $destino,
            'paquetes' => $paquetes,
            'opciones' => null,
        ]);
    }

    /**
     * Pide precios. Vuelve al mismo formulario con las opciones debajo.
     *
     * Si no sale ninguna, el mensaje dice la causa real —que casi
     * siempre es el código postal o el estado— y no «no hay envíos
     * disponibles», que manda a la gente a llamar a la paquetería.
     */
    public function cotizar()
    {
        if (!Modulos::exigir('envios')) return;
        if (!$this->tokenValido()) $this->volver('/envios', 'No se pudo verificar el formulario.', 'error');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Envios($db);

        $destino  = $this->destinoDeForm($_POST);
        $paquetes = Envios::paquetesDeForm($_POST);
        $ventaId  = (int)($_POST['venta_id'] ?? 0);

        // El CP manda: se usa para fijar el estado antes de preguntar.
        list($destino, $queja, $avisoCp) = $srv->completarConCp($destino);

        $opciones = null; $error = $queja;
        if ($error === '') {
            if (!$srv->listo()) {
                $error = 'Falta el token de Envia. Pídelo en el panel de Envia.com '
                       . '(Developers) y ponlo en config/integraciones.php. '
                       . 'Para revisarlo: php app/bin/revisar-envios.php';
            } else {
                // Cinturón: pase lo que pase, el usuario ve una frase y no
                // una traza de SQL. En este servidor display_errors está
                // encendido, así que una excepción suelta se imprime entera
                // —rutas del servidor incluidas— en plena pantalla.
                try {
                    $opciones = $srv->cotizar($this->sucursal(), $destino, $paquetes);
                } catch (\Throwable $ex) {
                    $this->avisarEsquema('envios', $ex);
                    $opciones = null;
                    $error = 'No se pudo cotizar: ' . $ex->getMessage();
                }
                if ($error !== '')           { /* ya hay motivo */ }
                elseif ($opciones === null)  $error = $srv->error();
                elseif (count($opciones) === 0) {
                    $error = 'Ninguna paquetería cotizó esta ruta. Suele ser el código postal '
                           . 'de destino o el de salida: revisa que los dos existan y que el '
                           . 'domicilio de salida esté completo en Envíos → De dónde sale.';
                }
            }
        }

        // Las que no se pueden convertir en guía desde aquí se apartan
        // en vez de mezclarse con las buenas. Ofrecer un precio que al
        // apretar devuelve un error es peor que no ofrecerlo: hace
        // perder un minuto y deja dudando del resto de la lista.
        $apartadas = [];
        if (is_array($opciones) && !Peticion::entero('todas')) {
            list($opciones, $apartadas) = Envios::separarUsables($opciones);
        }

        $this->pintarForm($srv, [
            'venta'     => $ventaId ? $this->venta($db, $ventaId) : null,
            'destino'   => $destino,
            'paquetes'  => $paquetes,
            'opciones'  => $opciones,
            'apartadas' => $apartadas,
            'error'     => $error,
            'avisoCp'   => $avisoCp,
            'nota'      => (string)($_POST['nota'] ?? ''),
        ]);
    }

    /** Compra la guía. Es el paso que cuesta dinero. */
    public function generar()
    {
        if (!Modulos::exigir('envios')) return;
        if (!$this->tokenValido()) $this->volver('/envios', 'No se pudo verificar el formulario.', 'error');
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Envios($db);

        $destino  = $this->destinoDeForm($_POST);
        $paquetes = Envios::paquetesDeForm($_POST);
        $ventaId  = (int)($_POST['venta_id'] ?? 0);
        $carrier  = (string)($_POST['carrier'] ?? '');
        $servicio = (string)($_POST['servicio'] ?? '');
        $volverA  = '/envios/nuevo' . ($ventaId ? '?venta=' . $ventaId : '');

        list($destino, $queja, ) = $srv->completarConCp($destino);
        if ($queja !== '') $this->volver($volverA, $queja, 'error');

        try {
            $id = $srv->generar($this->sucursal(), $destino, $paquetes, $carrier, $servicio, [
                'venta_id'      => $ventaId ?: null,
                'servicio_desc' => (string)($_POST['servicio_desc'] ?? ''),
                'nota'          => (string)($_POST['nota'] ?? ''),
                // Solo viene con los servicios que salen de un mostrador
                // de la paquetería.
                'sucursal_envia'=> (string)($_POST['sucursal_envia'] ?? ''),
            ]);
        } catch (\Throwable $ex) {
            $this->avisarEsquema('envios', $ex);
            $this->volver($volverA, 'No se pudo generar la guía: ' . $ex->getMessage(), 'error');
            return;
        }

        if (!$id) $this->volver($volverA, 'No se pudo generar la guía. ' . $srv->error(), 'error');
        $this->volver('/envios/' . $id, 'Guía generada. Imprime la etiqueta y pégala al paquete.', 'ok');
    }

    public function ver($id)
    {
        if (!Modulos::exigir('envios')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Envios($db);
        try {
            $e = $srv->repo()->uno_($id);
        } catch (\Throwable $ex) {
            $this->avisarEsquema('envios', $ex);
            $this->volver('/envios', 'La base no está al día para Envíos.', 'error');
            return;
        }
        if (!$e) { $this->noExiste(); return; }

        Plantilla::pagina('envios/ver', [
            'titulo'    => 'Guía ' . ($e['guia'] ?: $e['id']),
            'icono'     => 'camion',
            'subtitulo' => Envios::carrier($e['carrier']) . ' · ' . $e['estado_rotulo'],
            'envio'     => $e,
            'eventos'   => $_SESSION['lf_envio_eventos'] ?? null,
            'listo'     => $srv->listo(),
            'sandbox'   => $srv->sandbox(),
            'puedeEditar' => Permisos::puede('editar.envios'),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_envio_eventos']);
    }

    public function rastrear($id)
    {
        if (!Modulos::exigir('envios')) return;
        if (!$this->tokenValido()) $this->volver('/envios/' . (int)$id, 'No se pudo verificar el formulario.', 'error');
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        $r = $srv->rastrear($id);
        if ($r === null) $this->volver('/envios/' . (int)$id, 'No se pudo rastrear. ' . $srv->error(), 'error');

        // Los movimientos no se guardan: se enseñan y se olvidan. La
        // paquetería es la dueña de esa lista y reescribe su historia
        // más seguido de lo que parece.
        $_SESSION['lf_envio_eventos'] = $r[0]['eventos'] ?? [];
        $this->volver('/envios/' . (int)$id, 'Rastreo actualizado.', 'ok');
    }

    public function cancelar($id)
    {
        if (!Modulos::exigir('envios')) return;
        if (!$this->tokenValido()) $this->volver('/envios/' . (int)$id, 'No se pudo verificar el formulario.', 'error');
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        if ($srv->cancelar($id)) {
            $this->volver('/envios/' . (int)$id, 'Guía cancelada.', 'ok');
        }
        $this->volver('/envios/' . (int)$id,
            'No se pudo cancelar. ' . ($srv->error() ?: 'La paquetería ya recogió el paquete, '
            . 'o la guía ya estaba cancelada.'), 'error');
    }

    /**
     * Qué ciudad y qué estado son un código postal.
     *
     * Lo llama el formulario al salir del campo del CP. Es lo que evita
     * que alguien escriba «Jalisco» donde va «JA» y se quede sin
     * cotización y sin explicación.
     */
    /**
     * Las sucursales de una paquetería, para los servicios «ocurre».
     *
     * Lo pide la pantalla cuando eliges una opción que no recoge en tu
     * domicilio: ahí hay que decir a qué mostrador vas a llevar el
     * paquete.
     */
    public function sucursalesEnvia()
    {
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        $lista = $srv->sucursalesDe(Peticion::texto('carrier'), Peticion::texto('cp'));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($lista ? ['ok' => true, 'sucursales' => $lista]
                                : ['ok' => false, 'error' => $srv->error()],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function cp()
    {
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        $r = $srv->cp(Peticion::texto('cp'), Peticion::texto('pais') ?: 'MX');
        header('Content-Type: application/json; charset=utf-8');
        // Las colonias van incluidas: el catálogo las trae y así el
        // formulario puede ofrecerlas en vez de dejar que alguien
        // escriba el municipio donde va la colonia.
        echo json_encode($r ? ['ok' => true] + $r : ['ok' => false, 'error' => $srv->error()],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    //  DE DÓNDE SALE
    // ─────────────────────────────────────────────────────────

    public function origenes()
    {
        if (!Modulos::exigir('envios')) return;
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        $lista = []; $falta = false;
        try { $lista = $srv->repo()->origenes(); }
        catch (\Throwable $e) { $falta = $this->avisarEsquema('envios', $e); }

        Plantilla::pagina('envios/origenes', [
            'titulo'    => 'De dónde salen los envíos',
            'icono'     => 'camion',
            'subtitulo' => 'El domicilio que va como remitente en cada guía',
            'lista'     => $lista,
            'faltaMigrar' => $falta,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardarOrigen()
    {
        if (!Modulos::exigir('envios')) return;
        if (!$this->tokenValido()) $this->volver('/envios/origenes', 'No se pudo verificar el formulario.', 'error');
        $srv = new Envios(Conexion::de($_SESSION['empresa_db']));
        $suc = (int)($_POST['sucursal_id'] ?? 0);
        if (!$suc) $this->volver('/envios/origenes', 'Falta la sucursal.', 'error');

        $d = [
            'nombre'   => $_POST['nombre']   ?? '', 'empresa' => $_POST['empresa'] ?? '',
            'telefono' => $_POST['telefono'] ?? '', 'email'   => $_POST['email'] ?? '',
            'calle'    => $_POST['calle']    ?? '', 'numero'  => $_POST['numero'] ?? '',
            'colonia'  => $_POST['colonia']  ?? '', 'ciudad'  => $_POST['ciudad'] ?? '',
            'estado'   => $_POST['estado']   ?? '', 'cp'      => $_POST['cp'] ?? '',
            'pais'     => $_POST['pais'] ?: 'MX',
            'referencia' => $_POST['referencia'] ?? '',
        ];
        // El CP rellena el estado cuando puede; si no puede, se respeta
        // lo que haya escrito el usuario y solo se le avisa.
        list($d, $queja, $avisoCp) = $srv->completarConCp($d);
        if ($queja !== '') $this->volver('/envios/origenes', $queja, 'error');

        try {
            $srv->repo()->guardarOrigen($suc, $d);
            \LibertyFin\Servicio\Auditoria::anota('envio.origen', 'sucursal ' . $suc, null,
                trim($d['calle'] . ' ' . $d['numero'] . ', ' . $d['ciudad'] . ' ' . $d['cp']),
                Conexion::de($_SESSION['empresa_db']));
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar origen: ' . $e->getMessage());
            $this->volver('/envios/origenes', 'No se pudo guardar.', 'error');
        }
        $this->volver('/envios/origenes',
            'Domicilio de salida guardado.' . ($avisoCp !== '' ? ' ' . $avisoCp : ''),
            $avisoCp !== '' ? 'aviso' : 'ok');
    }

    // ─────────────────────────────────────────────────────────

    private function pintarForm(Envios $srv, array $datos)
    {
        $origen = []; $faltaOrigen = '';
        try {
            $origen = $srv->origen($this->sucursal());
            $faltaOrigen = \LibertyFin\Servicio\Envia::revisar($origen, 'de donde sale');
        } catch (\Throwable $e) {
            $this->avisarEsquema('envios', $e);
            $faltaOrigen = 'La base todavía no tiene las tablas de Envíos. '
                         . 'Aplica config/actualizar-v28.sql en phpMyAdmin, o entra a '
                         . 'Mantenimiento y aprieta «Poner todas al día».';
        }

        // Nada de array_merge aquí: `$datos` trae claves en null a
        // propósito —un formulario en blanco no tiene venta ni destino—
        // y array_merge dejaba que ese null pisara al valor por omisión.
        // La vista recibía null donde esperaba un arreglo y se llenaba
        // de avisos. Cada campo se resuelve solo, a la vista.
        $venta    = $datos['venta']    ?? null;
        $destino  = $datos['destino']  ?? null;
        $paquetes = $datos['paquetes'] ?? null;

        if (!is_array($destino) || !$destino) $destino = $srv->destinoDeCliente(0);
        // Si se quedó sin bultos —todos sin peso— se repone uno: con la
        // tabla vacía no hay fila que copiar y el botón «Otro bulto» no
        // tendría de dónde partir.
        if (!is_array($paquetes) || !$paquetes) {
            $paquetes = [['tipo' => 'box', 'contenido' => 'Mercancía', 'valor' => 0,
                          'peso' => 1, 'largo' => 30, 'ancho' => 20, 'alto' => 15]];
        }

        Plantilla::pagina('envios/nuevo', [
            'titulo'    => 'Nuevo envío',
            'icono'     => 'camion',
            'subtitulo' => $venta ? 'Venta ' . $venta['codigo_venta'] : 'Sin venta ligada',
            'venta'     => $venta,
            'destino'   => $destino,
            'paquetes'  => $paquetes,
            'opciones'  => $datos['opciones'] ?? null,
            'apartadas' => $datos['apartadas'] ?? [],
            'origen'      => $origen,
            'faltaOrigen' => $faltaOrigen,
            'listo'     => $srv->listo(),
            'sandbox'   => $srv->sandbox(),
            'error'     => (string)($datos['error'] ?? ''),
            'avisoCp'   => (string)($datos['avisoCp'] ?? ''),
            'nota'      => (string)($datos['nota'] ?? ''),
            'tipos'     => \LibertyFin\Servicio\Envia::TIPOS,
        ]);
    }

    private function destinoDeForm(array $p)
    {
        return [
            'nombre'   => trim((string)($p['d_nombre'] ?? '')),
            'telefono' => trim((string)($p['d_telefono'] ?? '')),
            'email'    => trim((string)($p['d_email'] ?? '')),
            'calle'    => trim((string)($p['d_calle'] ?? '')),
            'numero'   => trim((string)($p['d_numero'] ?? '')),
            'colonia'  => trim((string)($p['d_colonia'] ?? '')),
            'ciudad'   => trim((string)($p['d_ciudad'] ?? '')),
            'estado'   => trim((string)($p['d_estado'] ?? '')),
            'cp'       => trim((string)($p['d_cp'] ?? '')),
            'pais'     => trim((string)($p['d_pais'] ?? 'MX')) ?: 'MX',
            'referencia' => trim((string)($p['d_referencia'] ?? '')),
        ];
    }

    private function venta($db, $id)
    {
        try {
            $st = $db->prepare("SELECT id, codigo_venta, cliente_id, total FROM ventas WHERE id = ?");
            $st->execute([(int)$id]);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private function sucursal()
    {
        return (int)($_SESSION['sucursal_id'] ?? 1) ?: 1;
    }

    /** Igual que en Inventario: distingue «falta migrar» de «se rompió». */
    private function avisarEsquema($donde, \Throwable $e)
    {
        $m = $e->getMessage();
        $falta = strpos($m, "doesn't exist") !== false
              || strpos($m, 'Unknown column') !== false
              || strpos($m, '42S02') !== false || strpos($m, '42S22') !== false;
        $llave = 'lf_esq_' . $donde;
        if (empty($_SESSION[$llave])) {
            $_SESSION[$llave] = 1;
            error_log('[LibertyFin] ' . $donde . ': ' . $m
                . ($falta ? ' · la base no está al día; corre php app/bin/migrar.php --aplicar '
                          . 'o aplica config/actualizar-v28.sql en phpMyAdmin' : ''));
        }
        return $falta;
    }

    private function noExiste()
    {
        http_response_code(404);
        Plantilla::pagina('errores/404', ['titulo' => 'No encontrado', 'icono' => 'alerta', 'subtitulo' => '']);
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function volver($a, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $a); exit;
    }
}