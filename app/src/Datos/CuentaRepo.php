<?php
namespace LibertyFin\Datos;

/**
 * Los datos de la cuenta: fiscales, de comercio y documentos.
 *
 * Todo esto existe para una sola cosa: poder cobrar de verdad y facturar.
 * Hasta que el proveedor de pagos y el SAT tengan estos datos, el negocio
 * puede registrar ventas pero no puede procesar una tarjeta ni timbrar.
 *
 * Las dos tablas ya existían en el esquema (`datos_pago_comercio` y
 * `documentos_comercio`): se usan tal cual en vez de inventar unas nuevas.
 */
final class CuentaRepo extends Repo
{
    /** Los documentos que pide el proveedor, en el orden en que se piden. */
    const DOCUMENTOS = [
        'id_frente'   => ['Identificación del dueño · frente',  true],
        'id_reverso'  => ['Identificación del dueño · reverso', true],
        'estado_cta'  => ['Portada del estado de cuenta',        true],
        'domicilio'   => ['Comprobante de domicilio',            true],
        'csf'         => ['Constancia de situación fiscal',      true],
        'acta'        => ['Acta constitutiva',                   false],
        'poder'       => ['Poder del representante legal',       false],
    ];

    const REGIMENES = [
        '601' => '601 · General de Ley Personas Morales',
        '603' => '603 · Personas Morales con Fines no Lucrativos',
        '605' => '605 · Sueldos y Salarios',
        '606' => '606 · Arrendamiento',
        '607' => '607 · Enajenación o Adquisición de Bienes',
        '608' => '608 · Demás ingresos',
        '612' => '612 · Actividades Empresariales y Profesionales',
        '614' => '614 · Ingresos por intereses',
        '616' => '616 · Sin obligaciones fiscales',
        '621' => '621 · Incorporación Fiscal',
        '622' => '622 · Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '626' => '626 · RESICO',
    ];

    // ── DATOS DE COMERCIO ───────────────────────────────────────

    public function comercio()
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM datos_pago_comercio ORDER BY id LIMIT 1") ?: [];
    }

    private function asegurar()
    {
        // Si la tabla no existe en esta empresa, se crea. El esquema viene
        // del sistema anterior y no todas las bases lo traen.
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS datos_pago_comercio (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    titular_nombre VARCHAR(200) NULL, nombre_comercio VARCHAR(200) NULL,
                    titular_correo VARCHAR(160) NULL, giro VARCHAR(200) NULL,
                    calle_numero VARCHAR(200) NULL, numero_interior VARCHAR(50) NULL,
                    colonia VARCHAR(150) NULL, delegacion_municipio VARCHAR(150) NULL,
                    ciudad VARCHAR(100) NULL, estado_direccion VARCHAR(100) NULL,
                    pais VARCHAR(100) NULL, telefono_oficina VARCHAR(20) NULL,
                    telefono_celular VARCHAR(20) NULL, nombre_vendedor VARCHAR(150) NULL,
                    rep_legal_nombre VARCHAR(200) NULL, rep_legal_escritura VARCHAR(200) NULL,
                    rep_legal_notaria_numero VARCHAR(50) NULL,
                    rep_legal_notario_nombre VARCHAR(200) NULL, rep_legal_ciudad VARCHAR(100) NULL,
                    id_tipo VARCHAR(50) NULL, id_numero VARCHAR(100) NULL,
                    id_fecha_expedicion DATE NULL, id_vigencia DATE NULL,
                    banco VARCHAR(100) NULL, plaza VARCHAR(100) NULL,
                    sucursal_bancaria VARCHAR(100) NULL, cuenta_cheques VARCHAR(30) NULL,
                    cuenta_clabe VARCHAR(18) NULL, clausulado_aceptado_en DATETIME NULL,
                    actualizado_en DATETIME NULL, actualizado_por INT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS documentos_comercio (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tipo VARCHAR(40) NOT NULL, ruta_archivo VARCHAR(500) NULL,
                    nombre_original VARCHAR(255) NULL, mime_real VARCHAR(100) NULL,
                    tamano_bytes INT NULL, estado VARCHAR(20) DEFAULT 'pendiente',
                    motivo_rechazo VARCHAR(300) NULL, subido_por INT NULL,
                    subido_en DATETIME NULL, revisado_por INT NULL, revisado_en DATETIME NULL,
                    KEY ix_dc_tipo (tipo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tablas de comercio: ' . $e->getMessage());
        }
    }

    /** Campos que el formulario puede escribir. Lista blanca. */
    const CAMPOS = [
        'titular_nombre','nombre_comercio','titular_correo','giro',
        'calle_numero','numero_interior','colonia','delegacion_municipio',
        'ciudad','estado_direccion','pais','telefono_oficina','telefono_celular',
        'nombre_vendedor','rep_legal_nombre','rep_legal_escritura',
        'rep_legal_notaria_numero','rep_legal_notario_nombre','rep_legal_ciudad',
        'id_tipo','id_numero','id_fecha_expedicion','id_vigencia',
        'banco','plaza','sucursal_bancaria','cuenta_cheques','cuenta_clabe',
    ];

    public function guardarComercio(array $d, $usuarioId)
    {
        $this->asegurar();

        // La CLABE son 18 dígitos exactos. Una mal escrita manda el dinero
        // a otra cuenta o rebota el alta semanas después, y para entonces
        // nadie recuerda qué se capturó.
        $clabe = preg_replace('/\D/', '', (string)($d['cuenta_clabe'] ?? ''));
        if ($clabe !== '' && strlen($clabe) !== 18) {
            throw new \InvalidArgumentException('La CLABE debe tener exactamente 18 dígitos');
        }
        $correo = trim($d['titular_correo'] ?? '');
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo del titular no es válido');
        }
        foreach (['id_fecha_expedicion','id_vigencia'] as $f) {
            $v = trim($d[$f] ?? '');
            if ($v === '') continue;
            $x = \DateTime::createFromFormat('Y-m-d', $v);
            if (!$x || $x->format('Y-m-d') !== $v) {
                throw new \InvalidArgumentException('Revisa las fechas de la identificación');
            }
        }

        $vals = [];
        foreach (self::CAMPOS as $c) {
            $v = trim((string)($d[$c] ?? ''));
            if ($c === 'cuenta_clabe') $v = $clabe;
            if ($c === 'cuenta_cheques') $v = preg_replace('/\D/', '', $v);
            if (in_array($c, ['id_fecha_expedicion','id_vigencia'], true) && $v === '') $v = null;
            $vals[$c] = $v === '' ? null : $v;
        }

        $actual = $this->uno("SELECT id, clausulado_aceptado_en FROM datos_pago_comercio ORDER BY id LIMIT 1");
        $acepta = !empty($d['clausulado']);

        if ($actual) {
            $sets = []; $p = [];
            foreach ($vals as $c => $v) { $sets[] = "`$c` = ?"; $p[] = $v; }
            $sets[] = 'actualizado_en = NOW()';
            $sets[] = 'actualizado_por = ?'; $p[] = $usuarioId ?: null;
            if ($acepta && empty($actual['clausulado_aceptado_en'])) {
                $sets[] = 'clausulado_aceptado_en = NOW()';
            }
            $p[] = $actual['id'];
            $this->db->prepare("UPDATE datos_pago_comercio SET " . implode(', ', $sets) . " WHERE id = ?")
                     ->execute($p);
            return (int)$actual['id'];
        }

        $cols = array_keys($vals);
        $sql = "INSERT INTO datos_pago_comercio (`" . implode('`,`', $cols)
             . "`, actualizado_en, actualizado_por"
             . ($acepta ? ", clausulado_aceptado_en" : "")
             . ") VALUES (" . implode(',', array_fill(0, count($cols), '?'))
             . ", NOW(), ?" . ($acepta ? ", NOW()" : "") . ")";
        $p = array_values($vals); $p[] = $usuarioId ?: null;
        $this->db->prepare($sql)->execute($p);
        return (int)$this->db->lastInsertId();
    }

    // ── DOCUMENTOS ──────────────────────────────────────────────

    public function documentos()
    {
        $this->asegurar();
        $filas = $this->todos("
            SELECT d.*, u.nombre AS quien
            FROM documentos_comercio d
            LEFT JOIN usuarios u ON u.id = d.subido_por
            ORDER BY d.subido_en DESC");
        // Solo la versión más reciente de cada tipo.
        $r = [];
        foreach ($filas as $f) if (!isset($r[$f['tipo']])) $r[$f['tipo']] = $f;
        return $r;
    }

    public function guardarDocumento($tipo, $ruta, array $meta, $usuarioId)
    {
        if (!isset(self::DOCUMENTOS[$tipo])) {
            throw new \InvalidArgumentException('Ese documento no está en la lista');
        }
        $this->asegurar();
        // Subir de nuevo reemplaza al anterior y vuelve a 'pendiente':
        // el documento cambió, así que la revisión previa ya no vale.
        $this->db->prepare("DELETE FROM documentos_comercio WHERE tipo = ?")->execute([$tipo]);
        $this->db->prepare("
            INSERT INTO documentos_comercio
                (tipo, ruta_archivo, nombre_original, mime_real, tamano_bytes,
                 estado, subido_por, subido_en)
            VALUES (?,?,?,?,?, 'pendiente', ?, NOW())
        ")->execute([$tipo, $ruta, $meta['nombre'] ?? null, $meta['mime'] ?? null,
                     $meta['bytes'] ?? null, $usuarioId ?: null]);
        return true;
    }

    /**
     * En qué punto va la documentación.
     * 'aprobada' solo si TODOS los obligatorios están aprobados.
     */
    public function estadoDocumentacion()
    {
        $docs = $this->documentos();
        $faltan = []; $rechazados = []; $pendientes = 0; $aprobados = 0;
        foreach (self::DOCUMENTOS as $k => $d) {
            if (!$d[1]) continue;                 // opcional
            if (!isset($docs[$k])) { $faltan[] = $d[0]; continue; }
            $e = $docs[$k]['estado'];
            if ($e === 'rechazado') $rechazados[] = $d[0];
            elseif ($e === 'aprobado') $aprobados++;
            else $pendientes++;
        }
        $obligatorios = count(array_filter(self::DOCUMENTOS, function ($d) { return $d[1]; }));
        if ($faltan)          $estado = 'sin_enviar';
        elseif ($rechazados)  $estado = 'rechazada';
        elseif ($pendientes)  $estado = 'en_revision';
        else                  $estado = 'aprobada';

        // `sistema_config.documentacion_estado` ya existía en el esquema y
        // el sistema anterior la lee. Se mantiene al día para que las dos
        // versiones cuenten lo mismo mientras convivan.
        try {
            $this->db->prepare("UPDATE sistema_config SET documentacion_estado = ?")
                     ->execute([$estado]);
        } catch (\Throwable $e) { /* si no existe la columna, da igual */ }

        return ['estado' => $estado,
                'faltan' => $faltan ?: $rechazados,
                'aprobados' => $aprobados, 'total' => $obligatorios];
    }

    // ── REVISIÓN · lo hace soporte ───────────────────────────────

    /**
     * Revisa un documento.
     *
     * Rechazar SIN motivo está prohibido a propósito: lo que se escriba
     * aquí es lo único que el negocio tiene para saber qué corregir. Un
     * "rechazado" a secas garantiza que vuelvan a subir lo mismo.
     */
    public function revisar($id, $decision, $motivo, $revisorId)
    {
        $this->asegurar();
        if (!in_array($decision, ['aprobado','rechazado'], true)) {
            throw new \InvalidArgumentException('Decisión no válida');
        }
        $motivo = trim((string)$motivo);
        if ($decision === 'rechazado' && mb_strlen($motivo) < 10) {
            throw new \InvalidArgumentException(
                'Escribe por qué se rechaza, al menos una frase: es lo único que '
                . 'el negocio tiene para saber qué corregir');
        }
        $doc = $this->uno("SELECT id, tipo FROM documentos_comercio WHERE id = ?", [(int)$id]);
        if (!$doc) throw new \InvalidArgumentException('Ese documento no existe');

        $this->db->prepare("
            UPDATE documentos_comercio
            SET estado = ?, motivo_rechazo = ?, revisado_por = ?, revisado_en = NOW()
            WHERE id = ?
        ")->execute([$decision, $decision === 'rechazado' ? $motivo : null,
                     $revisorId ?: null, (int)$id]);
        return $doc['tipo'];
    }

    /** Todo lo que está esperando revisión, con su antigüedad. */
    public function porRevisar()
    {
        $this->asegurar();
        return $this->todos("
            SELECT d.*, u.nombre AS quien,
                   TIMESTAMPDIFF(HOUR, d.subido_en, NOW()) AS horas
            FROM documentos_comercio d
            LEFT JOIN usuarios u ON u.id = d.subido_por
            WHERE d.estado = 'pendiente'
            ORDER BY d.subido_en");
    }
}
