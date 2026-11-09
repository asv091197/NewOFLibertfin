<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\CompararExcel;
use LibertyFin\Servicio\LectorHoja;
use LibertyFin\Vista\Plantilla;

/**
 * Auditoría · Contra un Excel.
 *
 * Se sube la hoja UNA vez y se queda guardada un rato: los filtros (qué
 * revisar, periodo, área, tolerancia) vuelven a comparar sin pedir el
 * archivo otra vez. Auditar es ir y venir entre filtros; subir el archivo
 * en cada vuelta lo haría insoportable.
 *
 * No escribe nada en la base. Importar está en otra pantalla, con otro
 * permiso, a propósito: aquí se revisa, allá se mete.
 */
final class CompararExcelControlador
{
    const MAX_BYTES = 6291456;          // 6 MB, como Importar
    const CARPETA   = 'auditoria';
    const DURA      = 28800;            // 8 horas: una tarde de revisión

    public function index()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $arch = $this->archivo();
        $resultado = null; $error = '';
        $filtros = [
            'revisar' => array_values(array_filter((array)($_GET['revisar'] ?? []), 'is_string')),
            'desde'   => Peticion::fecha('desde', ''),
            'hasta'   => Peticion::fecha('hasta', ''),
            'area'    => Peticion::texto('area'),
            'tol'     => in_array(Peticion::texto('tol'), ['0.05', '1', '10'], true) ? Peticion::texto('tol') : '0.05',
            'hojas'   => array_values(array_filter((array)($_GET['hojas'] ?? []), 'is_string')),
        ];

        if ($arch) {
            $lector = new LectorHoja();
            $hojas  = $lector->leer($arch['ruta'], $arch['nombre']);
            if ($hojas === null) {
                $error = $lector->error() ?: 'No se pudo leer el archivo.';
            } else {
                try {
                    $resultado = (new CompararExcel($db))->comparar($hojas, $filtros);
                    $resultado['todasHojas'] = array_keys($hojas);
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] comparar excel: ' . $e->getMessage());
                    $error = 'No se pudo comparar. Quedó anotado el error.';
                }
            }
        }

        if ($resultado && Peticion::texto('formato') === 'csv') $this->csv($resultado['hallazgos']);

        Plantilla::pagina('auditoria/excel', [
            'titulo'    => 'Auditoría',
            'icono'     => 'alerta',
            'subtitulo' => 'Contra un Excel · lo capturado contra la hoja que se lleva aparte',
            'archivo'   => $arch,
            'resultado' => $resultado,
            'error'     => $error,
            'filtros'   => $filtros,
            'puedeXlsx' => LectorHoja::puedeXlsx(),
            'maxMb'     => round(self::MAX_BYTES / 1048576),
            'aviso'     => $this->tomarAviso(),
        ]);
    }

    public function subir()
    {
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario. Intenta de nuevo.');
        $f = $_FILES['hoja'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->volver($this->motivoSubida((int)($f['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if ((int)$f['size'] > self::MAX_BYTES) {
            $this->volver('El archivo pesa más de ' . round(self::MAX_BYTES / 1048576) . ' MB.');
        }
        $g = $this->guardarTemporal($f);
        if (!$g) $this->volver('No se pudo guardar el archivo en el servidor. Avisa a soporte.');

        // El anterior ya no sirve: uno a la vez.
        $previo = $_SESSION['lf_comparar']['ruta'] ?? null;
        if ($previo && $previo !== $g['ruta'] && is_file($previo)) @unlink($previo);
        $_SESSION['lf_comparar'] = $g + ['cuando' => time()];

        // Lo que se marcó al subir se vuelve el filtro de la primera vuelta.
        $q = array_filter([
            'revisar' => array_values(array_filter((array)($_POST['revisar'] ?? []), 'is_string')),
            'tol'     => (string)($_POST['tol'] ?? ''),
        ]);
        header('Location: /auditoria/excel' . ($q ? '?' . http_build_query($q) : ''));
        exit;
    }

    public function quitar()
    {
        $r = $_SESSION['lf_comparar']['ruta'] ?? null;
        if ($r && is_file($r)) @unlink($r);
        unset($_SESSION['lf_comparar']);
        header('Location: /auditoria/excel');
        exit;
    }

    /** El archivo que se subió, si sigue ahí y no ha caducado. */
    private function archivo()
    {
        $a = $_SESSION['lf_comparar'] ?? null;
        if (!$a || empty($a['ruta']) || !is_file($a['ruta'])) { unset($_SESSION['lf_comparar']); return null; }
        if (time() - (int)($a['cuando'] ?? 0) > self::DURA) {
            @unlink($a['ruta']);
            unset($_SESSION['lf_comparar']);
            return null;
        }
        return $a;
    }

    private function csv(array $filas)
    {
        $nombre = 'auditoria-excel-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");   // para que Excel respete los acentos
        fputcsv($f, ['Gravedad', 'Qué', 'Folio', 'Fecha', 'Cliente', 'Área', 'Excel', 'Sistema', 'Nota', 'Hoja', 'Fila']);
        foreach ($filas as $h) {
            $cifra = function ($v) use ($h) {
                if ($v === null) return '';
                return $h['unidad'] === 'pesos' && is_numeric($v) ? number_format((float)$v, 2, '.', '') : self::sinFormula($v);
            };
            fputcsv($f, [
                CompararExcel::SEVERIDADES[$h['severidad']], $h['titulo'], self::sinFormula($h['folio']),
                $h['fecha'] ? substr((string)$h['fecha'], 0, 10) : '',
                self::sinFormula($h['cliente']), self::sinFormula($h['area']),
                $cifra($h['excel']), $cifra($h['sistema']),
                self::sinFormula($h['nota']), self::sinFormula($h['hoja']), $h['fila'] ?: '',
            ]);
        }
        fclose($f);
        exit;
    }

    /** Que una celda que empieza con = + - @ no se lea como fórmula en Excel. */
    private static function sinFormula($v)
    {
        $v = (string)$v;
        return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    }

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
        foreach ((array)@glob($dir . '/*') as $v) {
            if (is_file($v) && basename($v) !== '.htaccess' && time() - (int)@filemtime($v) > self::DURA) @unlink($v);
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt'], true)) $ext = 'bin';
        $ruta = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $ruta)) return null;
        @chmod($ruta, 0640);
        return ['ruta' => $ruta, 'nombre' => (string)$f['name']];
    }

    private function motivoSubida($cod)
    {
        switch ($cod) {
            case UPLOAD_ERR_NO_FILE:   return 'No elegiste ningún archivo.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'El archivo pesa más de lo que admite el servidor.';
            case UPLOAD_ERR_PARTIAL:   return 'La subida se cortó a medias. Inténtalo otra vez.';
            default:                   return 'No se pudo subir el archivo.';
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => 'error'];
        header('Location: /auditoria/excel');
        exit;
    }

    private function tomarAviso()
    {
        $a = $_SESSION['lf_aviso'] ?? null;
        unset($_SESSION['lf_aviso']);
        return $a;
    }
}
