<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * El expediente de un cliente: lo que hay que saber de él y no cabe en
 * su nombre y teléfono.
 *
 *   ficha     un texto que siempre está a la vista: alergias, tipo de
 *             piel, régimen fiscal, contraseñas que NO se guardan aquí…
 *   notas     lo que pasó, con fecha y quién: «se le aplicó tinte 6.1»,
 *             «trajo constancia de situación fiscal».
 *   archivos  fotos y PDF (identificación, antes y después, contratos).
 *
 * LOS ARCHIVOS SON PRIVADOS
 *
 * Se guardan FUERA de public/ (almacen/expedientes/<empresa>/), con un
 * nombre al azar, y solo se entregan a través del controlador, a quien
 * tiene permiso y es de esa empresa. Un enlace copiado no abre nada.
 */
final class Expediente
{
    const TIPOS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    const MAX_BYTES = 8388608;   // 8 MB

    private $db;
    private $carpeta;

    /** @param string $empresa la base de la empresa: su carpeta */
    public function __construct(PDO $db, $empresa = '')
    {
        $this->db = $db;
        $empresa = preg_replace('/[^a-z0-9_-]/i', '', (string)($empresa ?: ($_SESSION['empresa_db'] ?? 'x')));
        $this->carpeta = dirname(__DIR__, 2) . '/almacen/expedientes/' . ($empresa ?: 'x');
    }

    public function cliente($id)
    {
        $st = $this->db->prepare("SELECT * FROM clientes WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Visitas, lo gastado, la última vez y lo que debe. */
    public function numeros($clienteId)
    {
        $st = $this->db->prepare("
            SELECT COUNT(*) AS visitas, COALESCE(SUM(v.total),0) AS gastado, MAX(v.fecha) AS ultima,
                   COALESCE(SUM(v.total - COALESCE(pg.cobrado,0)),0) AS saldo
            FROM ventas v
            LEFT JOIN (SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id) pg
                   ON pg.venta_id = v.id
            WHERE v.cliente_id = ? AND v.estado <> 'cancelada'");
        $st->execute([(int)$clienteId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['visitas' => (int)($r['visitas'] ?? 0), 'gastado' => (float)($r['gastado'] ?? 0),
                'ultima' => $r['ultima'] ?? null, 'saldo' => max(0, round((float)($r['saldo'] ?? 0), 2))];
    }

    public function ventas($clienteId, $tope = 12)
    {
        $st = $this->db->prepare("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado, v.especialista_nombre,
                   v.total - COALESCE((SELECT SUM(monto) FROM venta_pagos vp WHERE vp.venta_id = v.id AND vp.cancelado = 0),0) AS saldo
            FROM ventas v WHERE v.cliente_id = ?
            ORDER BY v.fecha DESC, v.id DESC LIMIT " . max(1, (int)$tope));
        $st->execute([(int)$clienteId]);
        $ventas = $st->fetchAll(PDO::FETCH_ASSOC);
        // Qué se llevó en cada una, en una sola consulta.
        $ids = array_map('intval', array_column($ventas, 'id'));
        $que = [];
        if ($ids) {
            $st = $this->db->prepare("SELECT d.venta_id, p.nombre FROM venta_detalles d LEFT JOIN productos p ON p.id = d.producto_id
                                      WHERE d.venta_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY d.id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $que[(int)$f['venta_id']][] = $f['nombre'] ?: '—';
        }
        foreach ($ventas as &$v) $v['conceptos'] = implode(', ', $que[(int)$v['id']] ?? []);
        return $ventas;
    }

    // ── La ficha ──

    public function ficha($clienteId)
    {
        $st = $this->db->prepare("SELECT texto, actualizado_en, actualizado_por FROM lf_cliente_ficha WHERE cliente_id = ?");
        $st->execute([(int)$clienteId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['texto' => '', 'actualizado_en' => null, 'actualizado_por' => null];
    }

    public function guardarFicha($clienteId, $texto, $usuario = '')
    {
        $texto = mb_substr(trim((string)$texto), 0, 8000);
        $existe = $this->db->prepare("SELECT 1 FROM lf_cliente_ficha WHERE cliente_id = ?");
        $existe->execute([(int)$clienteId]);
        $u = mb_substr((string)$usuario, 0, 100) ?: null;
        if ($existe->fetchColumn()) {
            $this->db->prepare("UPDATE lf_cliente_ficha SET texto = ?, actualizado_en = NOW(), actualizado_por = ? WHERE cliente_id = ?")
                     ->execute([$texto, $u, (int)$clienteId]);
        } else {
            $this->db->prepare("INSERT INTO lf_cliente_ficha (cliente_id, texto, actualizado_en, actualizado_por) VALUES (?,?,NOW(),?)")
                     ->execute([(int)$clienteId, $texto, $u]);
        }
    }

    // ── Las notas ──

    public function notas($clienteId, $tope = 100)
    {
        $st = $this->db->prepare("SELECT id, texto, usuario_nombre, creado_en FROM lf_cliente_notas
                                  WHERE cliente_id = ? ORDER BY creado_en DESC, id DESC LIMIT " . max(1, (int)$tope));
        $st->execute([(int)$clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function agregarNota($clienteId, $texto, $usuario = '')
    {
        $texto = mb_substr(trim((string)$texto), 0, 4000);
        if ($texto === '') throw new \InvalidArgumentException('Escribe la nota.');
        $this->db->prepare("INSERT INTO lf_cliente_notas (cliente_id, texto, usuario_nombre, creado_en) VALUES (?,?,?,NOW())")
                 ->execute([(int)$clienteId, $texto, mb_substr((string)$usuario, 0, 100) ?: null]);
        return (int)$this->db->lastInsertId();
    }

    public function borrarNota($clienteId, $notaId)
    {
        $st = $this->db->prepare("DELETE FROM lf_cliente_notas WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)$notaId, (int)$clienteId]);
        return $st->rowCount() > 0;
    }

    // ── Los archivos ──

    public function archivos($clienteId)
    {
        $st = $this->db->prepare("SELECT id, nombre, mime, tamano, usuario_nombre, creado_en FROM lf_cliente_archivos
                                  WHERE cliente_id = ? ORDER BY creado_en DESC, id DESC");
        $st->execute([(int)$clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Guarda lo subido en $_FILES[...]. Devuelve el id. */
    public function subir($clienteId, array $f, $usuario = '')
    {
        if (!isset($f['error']) || is_array($f['error'])) throw new \InvalidArgumentException('No se recibió el archivo.');
        if ($f['error'] === UPLOAD_ERR_NO_FILE) throw new \InvalidArgumentException('Elige un archivo.');
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$f['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('El archivo pesa más de ' . round(self::MAX_BYTES / 1048576) . ' MB.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('No se pudo subir el archivo.');
        if (!is_uploaded_file($f['tmp_name'])) throw new \InvalidArgumentException('Origen del archivo no válido.');
        return $this->guardarArchivo($clienteId, $f['tmp_name'], (string)$f['name'], $usuario, true);
    }

    /**
     * Lo de subir() sin lo que solo existe en una petición real. Separado
     * para poder probarlo.
     */
    public function guardarArchivo($clienteId, $origen, $nombre, $usuario = '', $subido = false)
    {
        // El tipo se lee del CONTENIDO, no del nombre ni de lo que diga el navegador.
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_file($fi, $origen);
            finfo_close($fi);
        } else {
            $cab = (string)@file_get_contents($origen, false, null, 0, 12);
            if (strncmp($cab, '%PDF', 4) === 0) $mime = 'application/pdf';
            elseif (strncmp($cab, "\xFF\xD8\xFF", 3) === 0) $mime = 'image/jpeg';
            elseif (strncmp($cab, "\x89PNG", 4) === 0) $mime = 'image/png';
            elseif (substr($cab, 0, 4) === 'RIFF' && substr($cab, 8, 4) === 'WEBP') $mime = 'image/webp';
        }
        if (!isset(self::TIPOS[$mime])) throw new \InvalidArgumentException('Solo se aceptan fotos (JPG, PNG, WebP) y PDF.');

        if (!is_dir($this->carpeta) && !@mkdir($this->carpeta, 0750, true) && !is_dir($this->carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta de expedientes.');
        }
        // Red de seguridad por si la carpeta llega a quedar a la vista del servidor web.
        $raiz = dirname($this->carpeta);
        if (!is_file($raiz . '/.htaccess')) @file_put_contents($raiz . '/.htaccess', "Require all denied\nDeny from all\n");

        $archivo = (int)$clienteId . '-' . bin2hex(random_bytes(10)) . '.' . self::TIPOS[$mime];
        $destino = $this->carpeta . '/' . $archivo;
        $ok = $subido ? @move_uploaded_file($origen, $destino) : @copy($origen, $destino);
        if (!$ok) throw new \RuntimeException('No se pudo guardar el archivo.');
        @chmod($destino, 0640);

        // El nombre que se muestra: el original, limpio.
        $limpio = trim(preg_replace('/[\x00-\x1F\/\\\\]+/u', ' ', $nombre));
        $limpio = mb_substr($limpio !== '' ? $limpio : 'archivo.' . self::TIPOS[$mime], 0, 200);
        $this->db->prepare("INSERT INTO lf_cliente_archivos (cliente_id, nombre, archivo, mime, tamano, usuario_nombre, creado_en)
                            VALUES (?,?,?,?,?,?,NOW())")
                 ->execute([(int)$clienteId, $limpio, $archivo, $mime, (int)filesize($destino), mb_substr((string)$usuario, 0, 100) ?: null]);
        return (int)$this->db->lastInsertId();
    }

    /** El archivo con su ruta en disco, o null. */
    public function archivo($clienteId, $archivoId)
    {
        $st = $this->db->prepare("SELECT * FROM lf_cliente_archivos WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)$archivoId, (int)$clienteId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !preg_match('/^[0-9]+-[0-9a-f]+\.(jpg|png|webp|pdf)$/', $a['archivo'])) return null;
        $a['ruta'] = $this->carpeta . '/' . $a['archivo'];
        return is_file($a['ruta']) ? $a : null;
    }

    public function borrarArchivo($clienteId, $archivoId)
    {
        $st = $this->db->prepare("SELECT * FROM lf_cliente_archivos WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)$archivoId, (int)$clienteId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        $this->db->prepare("DELETE FROM lf_cliente_archivos WHERE id = ?")->execute([(int)$archivoId]);
        if (preg_match('/^[0-9]+-[0-9a-f]+\.(jpg|png|webp|pdf)$/', $a['archivo'])) @unlink($this->carpeta . '/' . $a['archivo']);
        return $a;
    }
}
