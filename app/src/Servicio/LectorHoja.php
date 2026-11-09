<?php
namespace LibertyFin\Servicio;

/**
 * Lee una hoja de cálculo sin instalar nada.
 *
 * POR QUÉ A MANO Y NO CON UNA LIBRERÍA
 *
 * Lo normal sería PhpSpreadsheet. Pero este proyecto no usa Composer y
 * vive en hosting compartido: meter un `vendor/` de veinte megas para
 * leer dos columnas de números es pagar muy caro por muy poco, y a la
 * larga es una dependencia más que actualizar.
 *
 * Un .xlsx es un ZIP con XML dentro. PHP trae ZipArchive y SimpleXML de
 * fábrica. Lo que hace falta de verdad son tres archivos:
 *
 *   xl/workbook.xml              los nombres de las hojas
 *   xl/sharedStrings.xml         el texto, que Excel guarda aparte
 *   xl/worksheets/sheetN.xml     las celdas
 *
 * ESTO NO ES UN LECTOR DE EXCEL COMPLETO, y no pretende serlo. No
 * entiende fórmulas (lee el último valor calculado, que es lo que se
 * quiere), ni formatos, ni gráficas, ni hojas protegidas. Para importar
 * una tabla de ventas eso sobra.
 *
 * Acepta CSV también, que es lo que va a usar quien tenga problemas:
 * «guárdalo como CSV» resuelve un archivo raro más rápido que cualquier
 * cosa que yo escriba aquí.
 */
final class LectorHoja
{
    /** Tope de celdas. Un archivo de un millón de filas tumba el PHP. */
    const MAX_FILAS = 5000;

    private $error = '';

    public function error() { return $this->error; }

    /**
     * ¿Se puede leer xlsx en este servidor?
     *
     * ZipArchive viene de fábrica, pero algunos hostings lo apagan. Más
     * vale decirlo antes de que alguien suba un archivo y reciba un
     * error que no explica nada.
     */
    public static function puedeXlsx()
    {
        return class_exists('ZipArchive') && function_exists('simplexml_load_string');
    }

    /**
     * Lee el archivo y devuelve las hojas.
     *
     * @return array|null  ['NOMBRE' => [[celda, celda, ...], ...]]
     */
    public function leer($ruta, $nombreOriginal = '')
    {
        $this->error = '';
        if (!is_readable($ruta)) { $this->error = 'No se pudo leer el archivo.'; return null; }

        $ext = strtolower(pathinfo($nombreOriginal ?: $ruta, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return ['Hoja 1' => $this->csv($ruta)];

        // El tipo se deduce del CONTENIDO, no de la extensión: el nombre
        // lo escribe quien sube el archivo.
        $firma = (string)file_get_contents($ruta, false, null, 0, 4);
        if (strncmp($firma, "PK\x03\x04", 4) !== 0) {
            // No es un ZIP. Si parece texto, se intenta como CSV antes
            // de rendirse: mucha gente renombra el archivo y ya.
            $hojas = $this->csv($ruta);
            if ($hojas && count($hojas) > 1) return ['Hoja 1' => $hojas];
            $this->error = 'El archivo no es un .xlsx ni un .csv que se entienda.';
            return null;
        }
        if (!self::puedeXlsx()) {
            $this->error = 'Este servidor no trae ZipArchive, así que no puede abrir .xlsx. '
                         . 'Guarda la hoja como CSV y súbela así.';
            return null;
        }
        return $this->xlsx($ruta);
    }

    // ── CSV ────────────────────────────────────────────────

    private function csv($ruta)
    {
        $filas = [];
        $f = fopen($ruta, 'r');
        if (!$f) return $filas;

        // El BOM que mete Excel al guardar como CSV se cuela en la
        // primera celda y rompe la comparación del encabezado.
        $primero = fgets($f);
        rewind($f);
        if (strncmp((string)$primero, "\xEF\xBB\xBF", 3) === 0) fseek($f, 3);

        // Excel en español separa con punto y coma. Se mira la primera
        // línea para decidir en vez de obligar a un formato.
        $sep = (substr_count((string)$primero, ';') > substr_count((string)$primero, ',')) ? ';' : ',';

        while (($f2 = fgetcsv($f, 0, $sep)) !== false) {
            if (count($filas) >= self::MAX_FILAS) break;
            $filas[] = array_map(function ($c) {
                $c = trim((string)$c);
                return $c === '' ? null : $c;
            }, $f2);
        }
        fclose($f);
        return $filas;
    }

    // ── XLSX ───────────────────────────────────────────────

    private function xlsx($ruta)
    {
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            $this->error = 'El archivo está dañado o no se pudo abrir.';
            return null;
        }
        try {
            $textos = $this->textosCompartidos($zip);
            $fechas = $this->estilosDeFecha($zip);
            $hojas  = [];

            foreach ($this->hojasDelLibro($zip) as $nombre => $destino) {
                $xml = $zip->getFromName($destino);
                if ($xml === false) continue;
                $hojas[$nombre] = $this->celdas($xml, $textos, $fechas);
            }
            if (!$hojas) $this->error = 'El archivo no tiene hojas que se puedan leer.';
            return $hojas ?: null;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] leer xlsx: ' . $e->getMessage());
            $this->error = 'No se pudo leer el archivo: ' . $e->getMessage();
            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Qué hojas hay y dónde está cada una.
     *
     * El nombre de la hoja vive en workbook.xml y el archivo que la
     * contiene, en las relaciones. NO se puede asumir que la hoja 1 sea
     * sheet1.xml: al borrar y reordenar hojas en Excel, los números
     * dejan de corresponder y se acaba leyendo la hoja equivocada.
     */
    private function hojasDelLibro(\ZipArchive $zip)
    {
        $libro = $zip->getFromName('xl/workbook.xml');
        if ($libro === false) return [];

        $rels = [];
        $xmlRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($xmlRels !== false) {
            $r = @simplexml_load_string($xmlRels);
            if ($r) foreach ($r->Relationship as $x) {
                $destino = (string)$x['Target'];
                if (strncmp($destino, '/xl/', 4) === 0)      $destino = ltrim($destino, '/');
                elseif (strncmp($destino, 'xl/', 3) !== 0)   $destino = 'xl/' . ltrim($destino, '/');
                $rels[(string)$x['Id']] = $destino;
            }
        }

        $x = @simplexml_load_string($libro);
        if (!$x) return [];
        $ns = $x->getNamespaces(true);
        $out = []; $n = 0;
        foreach ($x->sheets->sheet as $h) {
            $n++;
            $nombre = trim((string)$h['name']) ?: ('Hoja ' . $n);
            $id = '';
            foreach ($h->attributes($ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $k => $v) {
                if ($k === 'id') $id = (string)$v;
            }
            $out[$nombre] = $rels[$id] ?? ('xl/worksheets/sheet' . $n . '.xml');
        }
        return $out;
    }

    /** El texto de las celdas, que Excel guarda en una tabla aparte. */
    private function textosCompartidos(\ZipArchive $zip)
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $x = @simplexml_load_string($xml);
        if (!$x) return [];
        $t = [];
        foreach ($x->si as $s) {
            // Una celda con texto en varios formatos viene partida en
            // varios <r>; hay que pegarlos o se pierde media palabra.
            $t[] = isset($s->t) ? (string)$s->t
                 : implode('', array_map(function ($r) { return (string)$r->t; }, iterator_to_array($s->r)));
        }
        return $t;
    }

    /**
     * Qué estilos son de fecha.
     *
     * Excel guarda las fechas como un NÚMERO —días desde 1900— y lo que
     * dice que es una fecha y no un importe es el formato. Sin esto, un
     * 2026-08-03 llega como 46237 y nadie entiende de dónde salió.
     */
    private function estilosDeFecha(\ZipArchive $zip)
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) return [];
        $x = @simplexml_load_string($xml);
        if (!$x) return [];

        // Los formatos de fecha que Excel trae de fábrica.
        $deFabrica = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];
        $propios = [];
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->numFmt as $f) {
                $cod = (string)$f['formatCode'];
                // Si tiene y/m/d fuera de comillas, es fecha.
                if (preg_match('/(^|[^"\\\\])[ymdhs]/i', preg_replace('/"[^"]*"/', '', $cod))) {
                    $propios[] = (int)$f['numFmtId'];
                }
            }
        }
        $fecha = [];
        if (isset($x->cellXfs)) {
            $i = 0;
            foreach ($x->cellXfs->xf as $xf) {
                $id = (int)$xf['numFmtId'];
                if (in_array($id, $deFabrica, true) || in_array($id, $propios, true)) $fecha[$i] = true;
                $i++;
            }
        }
        return $fecha;
    }

    /** Las celdas de una hoja, como matriz de filas. */
    private function celdas($xml, array $textos, array $fechas)
    {
        $x = @simplexml_load_string($xml);
        if (!$x || !isset($x->sheetData)) return [];

        $filas = [];
        foreach ($x->sheetData->row as $r) {
            if (count($filas) >= self::MAX_FILAS) break;
            $fila = [];
            foreach ($r->c as $c) {
                // La columna sale de la referencia (B7 -> 1). Excel OMITE
                // las celdas vacías, así que contar celdas desalinea todo
                // en cuanto una fila tiene un hueco.
                $col = self::columna((string)$c['r']);
                $fila[$col] = $this->valor($c, $textos, $fechas);
            }
            if (!$fila) { $filas[] = []; continue; }
            // Se rellenan los huecos para que la fila sea una lista.
            $max = max(array_keys($fila));
            $plana = [];
            for ($i = 0; $i <= $max; $i++) $plana[] = $fila[$i] ?? null;
            $filas[] = $plana;
        }
        return $filas;
    }

    private function valor($c, array $textos, array $fechas)
    {
        $tipo = (string)$c['t'];

        if ($tipo === 's') {                       // texto compartido
            $i = (int)$c->v;
            return isset($textos[$i]) ? self::limpiar($textos[$i]) : null;
        }
        if ($tipo === 'inlineStr') {
            return isset($c->is->t) ? self::limpiar((string)$c->is->t) : null;
        }
        if ($tipo === 'b') return ((string)$c->v) === '1';
        if ($tipo === 'e') return null;            // #¡VALOR!, #N/A...

        if (!isset($c->v) || (string)$c->v === '') return null;
        $v = (string)$c->v;

        // ¿Número con formato de fecha?
        $s = isset($c['s']) ? (int)$c['s'] : -1;
        if (isset($fechas[$s]) && is_numeric($v)) {
            $f = self::fechaDeSerie((float)$v);
            if ($f) return $f;
        }
        return is_numeric($v) ? (0 + $v) : self::limpiar($v);
    }

    /**
     * El número de Excel a fecha.
     *
     * El día 0 es el 1899-12-30 y no el 1900-01-01: Excel cree que 1900
     * fue bisiesto —no lo fue— para seguir siendo compatible con Lotus
     * 1-2-3 de 1983. Usar la fecha "correcta" desplaza todo un día.
     */
    private static function fechaDeSerie($n)
    {
        if ($n < 1 || $n > 2958465) return null;        // fuera de 1900-9999
        $base = new \DateTimeImmutable('1899-12-30 00:00:00');
        $dias = (int)floor($n);
        $seg  = (int)round(($n - $dias) * 86400);
        return $base->modify('+' . $dias . ' days +' . $seg . ' seconds')->format('Y-m-d H:i:s');
    }

    /** "BC7" -> 54. Base 26 con letras. */
    private static function columna($ref)
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = strtoupper($ref[$i]);
            if ($ch < 'A' || $ch > 'Z') break;
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    /**
     * Espacios de más, incluido el duro.
     *
     * El espacio duro (U+00A0) se cuela al pegar desde una página web y
     * es invisible: "LEGAL " y "LEGAL" se ven iguales y crean dos áreas
     * distintas en el catálogo.
     */
    public static function limpiar($s)
    {
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', (string)$s);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        return $s === '' ? null : $s;
    }
}
