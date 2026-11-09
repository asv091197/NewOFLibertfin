<?php
namespace LibertyFin\Servicio;

/**
 * Genera archivos .xlsx con formato.
 *
 * POR QUÉ ESTÁ ESCRITO AQUÍ
 *
 * Un .xlsx es un ZIP con unos XML dentro. PhpSpreadsheet lo hace mejor,
 * pero pesa 40 MB, necesita Composer y se come la memoria de un hosting
 * compartido con un reporte mediano.
 *
 * Esto escribe lo que de verdad hace falta: encabezados con color,
 * números como números —no como texto—, moneda con su formato, anchos
 * de columna, fila congelada y totales. Que es exactamente la diferencia
 * entre un CSV que nadie puede leer y una hoja que se entiende.
 */
final class Libro
{
    private $hojas = [];

    /** Formatos disponibles para una columna. */
    const TEXTO   = 't';
    const NUMERO  = 'n';
    const MONEDA  = '$';
    const PORCENT = '%';
    const FECHA   = 'f';

    /**
     * Agrega una hoja.
     *
     * @param string $nombre
     * @param array  $columnas  [['rotulo', tipo, ancho], …]
     * @param array  $filas     cada fila como arreglo de valores
     * @param array  $extras    'titulo', 'subtitulo', 'totales' (fila final)
     */
    public function hoja($nombre, array $columnas, array $filas, array $extras = [])
    {
        $this->hojas[] = ['nombre' => self::limpiarNombre($nombre), 'columnas' => $columnas,
                          'filas' => $filas, 'extras' => $extras];
        return $this;
    }

    public function guardar($ruta)
    {
        if (!$this->hojas) throw new \RuntimeException('El libro no tiene hojas');

        $partes = [
            '[Content_Types].xml'      => $this->tipos(),
            '_rels/.rels'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml'          => $this->libro(),
            'xl/_rels/workbook.xml.rels' => $this->relaciones(),
            'xl/styles.xml'            => $this->estilos(),
        ];
        foreach ($this->hojas as $i => $h) {
            $partes['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->hojaXml($h);
        }

        file_put_contents($ruta, self::zip($partes));
        return $ruta;
    }

    /**
     * Arma el ZIP a mano.
     *
     * POR QUÉ NO USA ZipArchive
     *
     * Esa clase viene de una extensión que no está en todos los PHP, y
     * cuando falta el error es "Class ZipArchive not found": no dice qué
     * hacer y aparece justo cuando alguien quería su reporte.
     *
     * Un .xlsx no necesita compresión, así que basta con guardar las
     * partes tal cual, cada una con su encabezado y su suma CRC32. Son
     * cuarenta líneas y el reporte funciona en cualquier servidor.
     *
     * Si la extensión está, se usa igual: comprime y el archivo pesa
     * menos. Pero ya no hace falta.
     */
    private static function zip(array $partes)
    {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'lf');
            $z = new \ZipArchive();
            if ($z->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                foreach ($partes as $n => $c) $z->addFromString($n, $c);
                $z->close();
                $b = file_get_contents($tmp);
                @unlink($tmp);
                return $b;
            }
            @unlink($tmp);
        }

        $local = ''; $central = ''; $desfase = 0; $n = 0;
        foreach ($partes as $nombre => $contenido) {
            $crc  = crc32($contenido);
            $tam  = strlen($contenido);
            $nom  = $nombre;

            // Encabezado local: firma, versión, banderas, método 0 (sin
            // comprimir), hora, crc, tamaños, longitudes de nombre y extra
            $cab = "\x50\x4b\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0)
                 . pack('V', $crc) . pack('V', $tam) . pack('V', $tam)
                 . pack('v', strlen($nom)) . pack('v', 0);
            $local .= $cab . $nom . $contenido;

            $central .= "\x50\x4b\x01\x02" . pack('v', 20) . pack('v', 20)
                 . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                 . pack('V', $crc) . pack('V', $tam) . pack('V', $tam)
                 . pack('v', strlen($nom)) . pack('v', 0) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0) . pack('V', 0)
                 . pack('V', $desfase) . $nom;

            $desfase += strlen($cab) + strlen($nom) + $tam;
            $n++;
        }

        $fin = "\x50\x4b\x05\x06" . pack('v', 0) . pack('v', 0)
             . pack('v', $n) . pack('v', $n)
             . pack('V', strlen($central)) . pack('V', $desfase) . pack('v', 0);

        return $local . $central . $fin;
    }

    // ══════════════════════════════════════════════════════════

    /**
     * El XML de una hoja.
     *
     * EL ORDEN DE LOS ELEMENTOS NO ES NEGOCIABLE
     *
     * El esquema de Excel exige: sheetViews, sheetFormatPr, cols,
     * sheetData, autoFilter. En ese orden exacto.
     *
     * Antes se escribia `cols` y despues se insertaba `sheetViews`
     * delante de `sheetData` con un reemplazo, lo que dejaba cols antes
     * de sheetViews. Un lector permisivo lo abre igual; Excel dice que
     * el archivo esta danado y no lo abre.
     *
     * Y `cols` vacio tampoco vale: si no hay columnas, se omite.
     */
    private function hojaXml(array $h)
    {
        $cols = $h['columnas'];
        $n = count($cols);

        $filas = '';
        $fila = 1;
        $titulo = $h['extras']['titulo'] ?? '';
        $sub    = $h['extras']['subtitulo'] ?? '';

        if ($titulo !== '') {
            $filas .= '<row r="' . $fila . '" ht="24" customHeight="1">'
                   . $this->celda('A' . $fila, $titulo, self::TEXTO, 5) . '</row>';
            $fila++;
        }
        if ($sub !== '') {
            $filas .= '<row r="' . $fila . '" ht="16" customHeight="1">'
                   . $this->celda('A' . $fila, $sub, self::TEXTO, 6) . '</row>';
            $fila++;
        }
        if ($titulo !== '' || $sub !== '') $fila++;   // un renglon en blanco

        $filaEnc = $fila;
        if ($n) {
            $filas .= '<row r="' . $fila . '" ht="22" customHeight="1">';
            foreach ($cols as $i => $c) {
                $filas .= $this->celda($this->col($i) . $fila, $c[0], self::TEXTO, 1);
            }
            $filas .= '</row>';
            $fila++;
        }

        foreach ($h['filas'] as $f) {
            $filas .= '<row r="' . $fila . '">';
            foreach ($cols as $i => $c) {
                $v = $f[$i] ?? null;
                if ($v === null || $v === '') continue;
                $filas .= $this->celda($this->col($i) . $fila, $v, $c[1] ?? self::TEXTO,
                                       $this->estiloDe($c[1] ?? self::TEXTO));
            }
            $filas .= '</row>';
            $fila++;
        }

        if (!empty($h['extras']['totales'])) {
            $hay = false;
            $r = '<row r="' . $fila . '" ht="20" customHeight="1">';
            foreach ($cols as $i => $c) {
                $v = $h['extras']['totales'][$i] ?? null;
                if ($v === null || $v === '') continue;
                $tipo = $c[1] ?? self::TEXTO;
                $r .= $this->celda($this->col($i) . $fila, $v, $tipo,
                                   $tipo === self::MONEDA ? 7 : ($tipo === self::NUMERO ? 8 : 4));
                $hay = true;
            }
            if ($hay) { $filas .= $r . '</row>'; $fila++; }
        }

        $ultima = max($fila - 1, 1);

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // 1 · sheetViews
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($n) {
            $x .= '<pane ySplit="' . $filaEnc . '" topLeftCell="A' . ($filaEnc + 1)
                . '" activePane="bottomLeft" state="frozen"/>';
        }
        $x .= '</sheetView></sheetViews>';

        // 2 · sheetFormatPr
        $x .= '<sheetFormatPr defaultRowHeight="15"/>';

        // 3 · cols, solo si hay
        if ($n) {
            $x .= '<cols>';
            foreach ($cols as $i => $c) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="'
                    . (float)($c[2] ?? 16) . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }

        // 4 · sheetData
        $x .= '<sheetData>' . $filas . '</sheetData>';

        // 5 · autoFilter, solo si hay encabezados y al menos un renglon
        if ($n && $ultima > $filaEnc) {
            $x .= '<autoFilter ref="A' . $filaEnc . ':' . $this->col($n - 1) . $ultima . '"/>';
        }

        return $x . '</worksheet>';
    }

    private function estiloDe($tipo)
    {
        switch ($tipo) {
            case self::MONEDA:  return 2;
            case self::PORCENT: return 3;
            case self::NUMERO:  return 9;
            case self::FECHA:   return 10;
            default:            return 0;
        }
    }

    private function celda($ref, $v, $tipo, $estilo)
    {
        $s = $estilo ? ' s="' . $estilo . '"' : '';

        if ($tipo === self::MONEDA || $tipo === self::NUMERO || $tipo === self::PORCENT) {
            // Como NÚMERO, no como texto. Un CSV manda "$1,234.00" y Excel
            // lo trata como letras: no suma, no ordena y no grafica.
            $num = is_numeric($v) ? (float)$v
                 : (float)preg_replace('/[^0-9.\-]/', '', (string)$v);
            if ($tipo === self::PORCENT && abs($num) > 1.5) $num = $num / 100;
            return '<c r="' . $ref . '"' . $s . '><v>' . rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.') . '</v></c>';
        }
        if ($tipo === self::FECHA && $v) {
            $t = is_numeric($v) ? (int)$v : strtotime((string)$v);
            if ($t) {
                // Serial de Excel: días desde 1900, con el error del 1900
                // bisiesto que Excel conserva a propósito.
                $serial = ($t / 86400) + 25569;
                return '<c r="' . $ref . '"' . $s . '><v>' . round($serial, 6) . '</v></c>';
            }
        }
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
             . self::esc((string)$v) . '</t></is></c>';
    }

    private function col($i)
    {
        $s = '';
        $i++;
        while ($i > 0) { $r = ($i - 1) % 26; $s = chr(65 + $r) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    }

    private function estilos()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        .   '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
        .   '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
        . '</numFmts>'
        . '<fonts count="5">'
        .   '<font><sz val="11"/><name val="Calibri"/><color rgb="FF1B2420"/></font>'
        .   '<font><sz val="11"/><b/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        .   '<font><sz val="16"/><b/><name val="Calibri"/><color rgb="FF1B2420"/></font>'
        .   '<font><sz val="10"/><name val="Calibri"/><color rgb="FF6D7A74"/></font>'
        .   '<font><sz val="11"/><b/><name val="Calibri"/><color rgb="FF1B2420"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF27AE60"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF5F1"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left/><right/><top style="thin"><color rgb="FF27AE60"/></top><bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="11">'
        .   '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'                              // 0 texto
        .   '<xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' // 1 encabezado
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'        // 2 moneda
        .   '<xf numFmtId="10"  fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'        // 3 porcentaje
        .   '<xf numFmtId="0"   fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 4 total texto
        .   '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 5 título
        .   '<xf numFmtId="0"   fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                // 6 subtítulo
        .   '<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 7 total moneda
        .   '<xf numFmtId="0"   fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 8 total número
        .   '<xf numFmtId="3"   fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'        // 9 número
        .   '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'        // 10 fecha
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
    }

    private function libro()
    {
        $h = '';
        foreach ($this->hojas as $i => $x) {
            $h .= '<sheet name="' . self::esc($x['nombre']) . '" sheetId="' . ($i+1)
                . '" r:id="rId' . ($i+1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $h . '</sheets></workbook>';
    }

    private function relaciones()
    {
        $r = '';
        foreach ($this->hojas as $i => $x) {
            $r .= '<Relationship Id="rId' . ($i+1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i+1) . '.xml"/>';
        }
        $r .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $r . '</Relationships>';
    }

    private function tipos()
    {
        $t = '';
        foreach ($this->hojas as $i => $x) {
            $t .= '<Override PartName="/xl/worksheets/sheet' . ($i+1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $t . '</Types>';
    }

    /** Excel no acepta : \ / ? * [ ] ni más de 31 caracteres. */
    private static function limpiarNombre($n)
    {
        return mb_substr(str_replace([':','\\','/','?','*','[',']'], '-', (string)$n), 0, 31);
    }

    private static function esc($s)
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
