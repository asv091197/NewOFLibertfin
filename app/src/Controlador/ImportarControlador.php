<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\ImportarVentas;
use LibertyFin\Servicio\LectorHoja;
use LibertyFin\Vista\Plantilla;

/**
 * Traer a LibertyFin la hoja de ventas que se llevaba en Excel.
 *
 * SE MIRA ANTES DE APLICAR, SIEMPRE.
 *
 * Subir el archivo solo enseña qué va a pasar: cuántas ventas, cuánto
 * dinero, qué clientes nuevos y qué renglones traen problema. Nada se
 * escribe hasta que alguien lo confirma.
 *
 * No es prudencia de más. Son setenta y nueve ventas con su dinero y
 * sus comisiones: si entran mal, deshacerlo es un día de borrar a mano,
 * y para entonces alguien ya cobró contra esos números.
 *
 * EL PLAN VIAJA EN LA SESIÓN, NO EN EL FORMULARIO
 *
 * Mandar el plan completo en campos ocultos dejaría que cualquiera
 * cambiara los importes entre la revisión y el guardado —revisas una
 * venta de $500 y se guarda una de $50,000—. El archivo se queda en el
 * servidor y se vuelve a leer al confirmar.
 */
final class ImportarControlador
{
    const CARPETA = 'importaciones';
    /** Lo que una hoja de ventas pesa de sobra. */
    const MAX_BYTES = 6291456;        // 6 MB

    public function index()
    {
        $this->pantalla(null, null);
    }

    /**
     * Lee el archivo subido y enseña el plan. NO ESCRIBE NADA.
     */
    public function analizar()
    {
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.');

        $f = $_FILES['hoja'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->volver($this->motivoSubida($f['error'] ?? UPLOAD_ERR_NO_FILE));
        }
        if ((int)$f['size'] > self::MAX_BYTES) {
            $this->volver('El archivo pesa más de ' . round(self::MAX_BYTES / 1048576) . ' MB.');
        }
        if (!is_uploaded_file($f['tmp_name'])) $this->volver('No se recibió el archivo.');

        $guardado = $this->guardarTemporal($f);
        if (!$guardado) $this->volver('No se pudo guardar el archivo para revisarlo.');

        list($plan, $error) = $this->planDe($guardado['ruta'], $guardado['nombre']);
        if ($error) { $this->borrar($guardado['ruta']); $this->volver($error); }

        // Lo que se guarda es DÓNDE está el archivo, no el plan. El plan
        // se vuelve a calcular al confirmar, contra la base de ese
        // momento: entre una pantalla y otra alguien pudo capturar a
        // mano una de esas ventas.
        $_SESSION['lf_import'] = [
            'ruta' => $guardado['ruta'], 'nombre' => $guardado['nombre'],
            'cuando' => time(), 'ajustes' => [],
        ];
        $this->pantalla($plan, null);
    }

    /**
     * Guarda las correcciones hechas en la pantalla y vuelve a revisar.
     *
     * No se escribe nada todavía: lo corregido se mete en el análisis y
     * se valida igual que lo del archivo. Si el ajuste deja una venta
     * imposible —un anticipo mayor que el total— sale marcada, como
     * saldría si viniera así del Excel.
     */
    public function ajustar()
    {
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.');

        $g = $_SESSION['lf_import'] ?? null;
        if (!$g || !is_readable($g['ruta'])) {
            $this->volver('Se perdió el archivo que estabas revisando. Vuelve a subirlo.');
        }
        $crudo = (isset($_POST['aj']) && is_array($_POST['aj'])) ? $_POST['aj'] : [];
        $_SESSION['lf_import']['ajustes'] =
            ImportarVentas::ajustar((array)($g['ajustes'] ?? []), $crudo);
        // El reloj se reinicia: acaban de mirarlo.
        $_SESSION['lf_import']['cuando'] = time();

        list($plan, $error) = $this->planDe($g['ruta'], $g['nombre']);
        if ($error) $this->volver($error);
        $this->pantalla($plan, null);
    }

    /**
     * Aplica lo que se revisó.
     */
    public function aplicar()
    {
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.');

        $g = $_SESSION['lf_import'] ?? null;
        if (!$g || !is_readable($g['ruta'])) {
            $this->volver('Se perdió el archivo que estabas revisando. Vuelve a subirlo.');
        }
        // Media hora. Un plan de hace horas se revisó contra una base
        // que ya cambió, y lo que se vio no es lo que se guardaría.
        if (time() - (int)$g['cuando'] > 1800) {
            $this->borrar($g['ruta']); unset($_SESSION['lf_import']);
            $this->volver('Pasó mucho rato desde que lo revisaste. Súbelo otra vez.');
        }

        list($plan, $error) = $this->planDe($g['ruta'], $g['nombre']);
        if ($error) $this->volver($error);
        // El archivo de ejemplo trae datos inventados: se revisa, no se guarda.
        if (!empty($plan['ejemplo'])) {
            $this->borrar($g['ruta']); unset($_SESSION['lf_import']);
            $this->volver('Ese es el archivo de ejemplo: sirve para ver cómo se revisa, pero no se guarda. '
                        . 'Llena tu propia hoja con el mismo formato y súbela.');
        }

        $db  = Conexion::de($_SESSION['empresa_db']);
        $res = (new ImportarVentas($db))->aplicar($plan, [
            'usuario_id'  => $_SESSION['usuario_id'] ?? null,
            'sucursal_id' => $_SESSION['sucursal_id'] ?? 0,
        ]);

        // El archivo ya no hace falta y tiene nombres de clientes dentro.
        $this->borrar($g['ruta']);
        unset($_SESSION['lf_import']);

        $this->pantalla(null, $res);
    }

    /**
     * Baja una hoja de ejemplo con datos inventados (ver
     * ImportarVentas::ejemplo()). Se arma al momento con los mismos
     * encabezados que reconoce el importador.
     */
    public function ejemplo()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lf') . '.xlsx';
        try {
            ImportarVentas::ejemplo()->guardar($tmp);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ejemplo de importar: ' . $e->getMessage());
            $this->volver('No se pudo generar el ejemplo.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="ejemplo-importar-ventas.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** Tira el archivo sin aplicar nada. */
    public function cancelar()
    {
        $g = $_SESSION['lf_import'] ?? null;
        if ($g) { $this->borrar($g['ruta']); unset($_SESSION['lf_import']); }
        header('Location: /importar'); exit;
    }

    // ── Lo de dentro ───────────────────────────────────────

    private function planDe($ruta, $nombre)
    {
        $lector = new LectorHoja();
        $hojas  = $lector->leer($ruta, $nombre);
        if (!$hojas) return [null, $lector->error() ?: 'No se pudo leer el archivo.'];

        $db   = Conexion::de($_SESSION['empresa_db']);
        $solo = isset($_POST['hojas']) && is_array($_POST['hojas'])
              ? array_values(array_filter(array_map('strval', $_POST['hojas']))) : null;

        $plan = (new ImportarVentas($db))->analizar($hojas, [
            'hojas'   => $solo,
            // Las correcciones se aplican SIEMPRE al releer: el archivo
            // manda y el ajuste va encima, nunca al revés.
            'ajustes' => (array)($_SESSION['lf_import']['ajustes'] ?? []),
        ]);
        $plan['archivo']   = $nombre;
        $plan['disponibles'] = array_keys($hojas);
        return [$plan, null];
    }

    private function pantalla($plan, $res)
    {
        Plantilla::pagina('importar/index', [
            'titulo'    => 'Importar ventas',
            'icono'     => 'venta',
            'subtitulo' => $plan ? 'Revisa antes de guardar'
                         : ($res ? 'Listo' : 'Desde una hoja de cálculo'),
            'plan'      => $plan,
            'resultado' => $res,
            'puedeXlsx' => LectorHoja::puedeXlsx(),
            'maxMb'     => round(self::MAX_BYTES / 1048576),
            'token'     => $this->token(),
            'aviso'     => $this->tomarAviso(),
        ]);
    }

    /**
     * Guarda el archivo fuera de public/, con un nombre nuevo.
     *
     * El nombre que manda el navegador lo escribe quien sube el archivo,
     * así que se descarta entero: con `../../` dentro acabaría escrito
     * donde no debe. Y la carpeta va fuera de lo que sirve el servidor
     * web, porque dentro lleva los nombres y los importes de clientes
     * reales.
     */
    private function guardarTemporal(array $f)
    {
        $dir = dirname(__DIR__, 2) . '/almacen/' . self::CARPETA;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('[LibertyFin] no se pudo crear ' . $dir);
            return null;
        }
        // Red de seguridad por si la carpeta acaba siendo accesible.
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");

        $this->limpiarViejos($dir);

        $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt'], true)) $ext = 'bin';
        $ruta = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;

        if (!@move_uploaded_file($f['tmp_name'], $ruta)) return null;
        @chmod($ruta, 0640);
        return ['ruta' => $ruta, 'nombre' => (string)$f['name']];
    }

    /** Lo que nadie confirmó ni canceló no se queda ahí para siempre. */
    private function limpiarViejos($dir)
    {
        foreach ((array)@glob($dir . '/*') as $v) {
            if (!is_file($v) || basename($v) === '.htaccess') continue;
            if (time() - (int)@filemtime($v) > 7200) @unlink($v);
        }
    }

    private function borrar($ruta) { if ($ruta && is_file($ruta)) @unlink($ruta); }

    private function motivoSubida($cod)
    {
        switch ($cod) {
            case UPLOAD_ERR_NO_FILE:   return 'No elegiste ningún archivo.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'El archivo pesa más de lo que admite el servidor.';
            case UPLOAD_ERR_PARTIAL:   return 'La subida se cortó a medias. Inténtalo otra vez.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:return 'El servidor no pudo guardar el archivo. Avisa a soporte.';
            default:                   return 'No se pudo subir el archivo.';
        }
    }

    private function token()
    {
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['lf_token'];
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    private function volver($texto)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => 'error'];
        header('Location: /importar'); exit;
    }

    private function tomarAviso()
    {
        $a = $_SESSION['lf_aviso'] ?? null;
        unset($_SESSION['lf_aviso']);
        return $a;
    }
}
