<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Los envíos y las direcciones de salida.
 *
 * Aquí solo se consulta y se guarda. Quién habla con Envia.com y qué se
 * le manda es cosa de Servicio\Envios; quién tiene permiso, del
 * controlador.
 */
final class EnvioRepo extends Repo
{
    /** Los estados por los que pasa un envío, y cómo se llaman en pantalla. */
    const ESTADOS = [
        'borrador'  => 'Sin guía',
        'generado'  => 'Guía generada',
        'en_camino' => 'En camino',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelada',
    ];

    /** Los que ya no se pueden cancelar ni volver a rastrear. */
    const CERRADOS = ['entregado', 'cancelado'];

    // ─────────────────────────────────────────────────────────
    //  DE DÓNDE SALE
    // ─────────────────────────────────────────────────────────

    /**
     * El domicilio de salida de una sucursal.
     *
     * Devuelve el renglón aunque esté vacío —se crea en la migración—
     * para que la pantalla de captura tenga qué pintar. Si ni eso hay,
     * devuelve los campos en blanco en vez de null: una pantalla que
     * tiene que preguntar `?? ''` en cada campo acaba enseñando avisos
     * de PHP el día que falte el renglón.
     */
    public function origen($sucursalId)
    {
        $r = $this->uno("SELECT * FROM lf_envio_origenes WHERE sucursal_id = ?", [(int)$sucursalId]);
        if ($r) return $r;
        return ['sucursal_id' => (int)$sucursalId, 'nombre' => '', 'empresa' => '',
                'telefono' => '', 'email' => '', 'calle' => '', 'numero' => '',
                'colonia' => '', 'ciudad' => '', 'estado' => '', 'cp' => '',
                'pais' => 'MX', 'referencia' => '', 'actualizado' => null];
    }

    /** Todas, con el nombre de su sucursal, para la pantalla de ajustes. */
    public function origenes()
    {
        return $this->todos("
            SELECT s.id AS sucursal_id, s.nombre AS sucursal, s.es_matriz,
                   o.nombre, o.empresa, o.telefono, o.email, o.calle, o.numero,
                   o.colonia, o.ciudad, o.estado, o.cp, o.pais, o.referencia, o.actualizado,
                   (o.calle IS NOT NULL AND o.calle <> '' AND o.cp IS NOT NULL AND o.cp <> ''
                    AND o.estado IS NOT NULL AND o.estado <> '') AS completo
              FROM sucursales s
              LEFT JOIN lf_envio_origenes o ON o.sucursal_id = s.id
             WHERE COALESCE(s.activo,1) = 1
             ORDER BY s.es_matriz DESC, s.nombre");
    }

    public function guardarOrigen($sucursalId, array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_envio_origenes
                (sucursal_id, nombre, empresa, telefono, email, calle, numero,
                 colonia, ciudad, estado, cp, pais, referencia, actualizado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre), empresa = VALUES(empresa),
                telefono = VALUES(telefono), email = VALUES(email),
                calle = VALUES(calle), numero = VALUES(numero),
                colonia = VALUES(colonia), ciudad = VALUES(ciudad),
                estado = VALUES(estado), cp = VALUES(cp), pais = VALUES(pais),
                referencia = VALUES(referencia), actualizado = NOW()
        ")->execute([
            (int)$sucursalId,
            self::corta($d['nombre'] ?? '', 100),   self::corta($d['empresa'] ?? '', 100),
            self::corta($d['telefono'] ?? '', 30),  self::corta($d['email'] ?? '', 120),
            self::corta($d['calle'] ?? '', 160),    self::corta($d['numero'] ?? '', 30),
            self::corta($d['colonia'] ?? '', 120),  self::corta($d['ciudad'] ?? '', 120),
            mb_strtoupper(self::corta($d['estado'] ?? '', 5)),
            self::corta($d['cp'] ?? '', 10),
            mb_strtoupper(self::corta($d['pais'] ?? 'MX', 2)) ?: 'MX',
            self::corta($d['referencia'] ?? '', 160),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    //  LOS ENVÍOS
    // ─────────────────────────────────────────────────────────

    public function uno_($id)
    {
        $e = $this->uno("
            SELECT e.*, v.codigo_venta, v.cliente_id
              FROM lf_envios e
              LEFT JOIN ventas v ON v.id = e.venta_id
             WHERE e.id = ?", [(int)$id]);
        return $e ? self::abrir($e) : null;
    }

    /** Los de una venta, el más nuevo primero. */
    public function deVenta($ventaId)
    {
        $r = $this->todos("SELECT * FROM lf_envios WHERE venta_id = ? ORDER BY id DESC",
                          [(int)$ventaId]);
        return array_map([self::class, 'abrir'], $r);
    }

    /**
     * El envío de un número de guía.
     *
     * Busca TAMBIÉN entre las guías secundarias. Un envío de tres
     * bultos tiene tres números de rastreo, y la paquetería avisa por
     * cualquiera de ellos: buscando solo en `guia` se perdían los
     * avisos de los bultos 2 y 3, que es justo cuando más importa
     * saber dónde va el paquete.
     *
     * El LIKE sobre el JSON es tosco, pero la lista ya viene acotada a
     * una sola empresa y los números de guía son largos y únicos.
     */
    public function porGuia($guia)
    {
        $guia = trim((string)$guia);
        if ($guia === '') return null;

        $e = $this->uno("SELECT * FROM lf_envios WHERE guia = ? ORDER BY id DESC LIMIT 1", [$guia]);
        if ($e) return self::abrir($e);

        $e = $this->uno("SELECT * FROM lf_envios
                          WHERE guias LIKE ? ORDER BY id DESC LIMIT 1",
                        ['%"' . $guia . '"%']);
        return $e ? self::abrir($e) : null;
    }

    /**
     * El listado, con filtros.
     *
     * `q` busca por guía, por folio de venta y por nombre de quien
     * recibe: son las tres formas en que alguien llega preguntando por
     * un paquete.
     */
    public function listar(array $f = [], $limite = 100, $desfase = 0)
    {
        list($donde, $p) = $this->donde($f);
        $r = $this->todos("
            SELECT e.*, v.codigo_venta
              FROM lf_envios e
              LEFT JOIN ventas v ON v.id = e.venta_id
             $donde
             ORDER BY e.id DESC
             LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
        return array_map([self::class, 'abrir'], $r);
    }

    public function cuantos(array $f = [])
    {
        list($donde, $p) = $this->donde($f);
        return (int)$this->valor("SELECT COUNT(*) FROM lf_envios e
                                  LEFT JOIN ventas v ON v.id = e.venta_id $donde", $p);
    }

    /** Cuántos hay en cada estado, para las pastillas de arriba. */
    public function resumen()
    {
        $r = $this->todos("SELECT estado, COUNT(*) n, COALESCE(SUM(costo),0) costo
                             FROM lf_envios GROUP BY estado");
        $out = [];
        foreach (self::ESTADOS as $k => $_) $out[$k] = ['n' => 0, 'costo' => 0.0];
        foreach ($r as $f) {
            $out[$f['estado']] = ['n' => (int)$f['n'], 'costo' => (float)$f['costo']];
        }
        return $out;
    }

    /**
     * Los que vale la pena volver a rastrear.
     *
     * Los entregados y los cancelados ya no cambian, así que se quedan
     * fuera: rastrearlos otra vez es gastar llamadas a la API para que
     * digan lo mismo.
     */
    public function porRastrear($limite = 25)
    {
        $r = $this->todos("
            SELECT * FROM lf_envios
             WHERE estado NOT IN ('entregado','cancelado','borrador')
               AND guia IS NOT NULL AND guia <> ''
             ORDER BY COALESCE(rastreado_en, '1970-01-01') ASC
             LIMIT " . (int)$limite);
        return array_map([self::class, 'abrir'], $r);
    }

    public function crear(array $d)
    {
        $this->db->prepare("
            INSERT INTO lf_envios
                (venta_id, sucursal_id, estado, carrier, servicio, servicio_desc,
                 guia, guias, envia_envio_id, etiqueta_url, rastreo_url, costo, moneda,
                 dest_nombre, dest_telefono, dest_email, dest_calle, dest_numero,
                 dest_colonia, dest_ciudad, dest_estado, dest_cp, dest_pais, dest_referencia,
                 paquetes, nota, usuario_id, usuario_nombre, creado_en, generado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)
        ")->execute([
            ($d['venta_id'] ?? null) ? (int)$d['venta_id'] : null,
            ($d['sucursal_id'] ?? null) ? (int)$d['sucursal_id'] : null,
            (string)($d['estado'] ?? 'generado'),
            self::corta($d['carrier'] ?? '', 40),
            self::corta($d['servicio'] ?? '', 60),
            self::corta($d['servicio_desc'] ?? '', 120),
            self::corta($d['guia'] ?? '', 80),
            json_encode(array_values((array)($d['guias'] ?? [])), JSON_UNESCAPED_UNICODE),
            self::corta($d['envia_envio_id'] ?? '', 60),
            self::corta($d['etiqueta_url'] ?? '', 500),
            self::corta($d['rastreo_url'] ?? '', 500),
            round((float)($d['costo'] ?? 0), 2),
            self::corta($d['moneda'] ?? 'MXN', 5) ?: 'MXN',
            self::corta($d['dest']['nombre'] ?? '', 120),
            self::corta($d['dest']['telefono'] ?? '', 30),
            self::corta($d['dest']['email'] ?? '', 120),
            self::corta($d['dest']['calle'] ?? '', 160),
            self::corta($d['dest']['numero'] ?? '', 30),
            self::corta($d['dest']['colonia'] ?? '', 120),
            self::corta($d['dest']['ciudad'] ?? '', 120),
            mb_strtoupper(self::corta($d['dest']['estado'] ?? '', 5)),
            self::corta($d['dest']['cp'] ?? '', 10),
            mb_strtoupper(self::corta($d['dest']['pais'] ?? 'MX', 2)) ?: 'MX',
            self::corta($d['dest']['referencia'] ?? '', 160),
            json_encode(array_values((array)($d['paquetes'] ?? [])), JSON_UNESCAPED_UNICODE),
            self::corta($d['nota'] ?? '', 255),
            ($d['usuario_id'] ?? null) ? (int)$d['usuario_id'] : null,
            self::corta($d['usuario_nombre'] ?? '', 100),
            ($d['estado'] ?? 'generado') === 'borrador' ? null : date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Lo que devolvió el rastreo. */
    public function anotarRastreo($id, $estado, $texto)
    {
        $this->db->prepare("
            UPDATE lf_envios SET estado = ?, ultimo_estado = ?, rastreado_en = NOW()
             WHERE id = ?
        ")->execute([(string)$estado, self::corta($texto, 160), (int)$id]);
    }

    public function anotarCancelado($id)
    {
        $this->db->prepare("
            UPDATE lf_envios SET estado = 'cancelado', cancelado_en = NOW() WHERE id = ?
        ")->execute([(int)$id]);
    }

    // ─────────────────────────────────────────────────────────

    private function donde(array $f)
    {
        $w = []; $p = [];
        if (!empty($f['estado']) && isset(self::ESTADOS[$f['estado']])) {
            $w[] = 'e.estado = ?'; $p[] = $f['estado'];
        }
        if (!empty($f['venta_id'])) { $w[] = 'e.venta_id = ?'; $p[] = (int)$f['venta_id']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(e.guia LIKE ? OR e.dest_nombre LIKE ? OR v.codigo_venta LIKE ?)';
            $como = '%' . $q . '%';
            $p[] = $como; $p[] = $como; $p[] = $como;
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Deshace el JSON para que la vista no tenga que acordarse. */
    private static function abrir(array $e)
    {
        $e['guias']    = json_decode((string)($e['guias'] ?? '[]'), true) ?: [];
        $e['paquetes'] = json_decode((string)($e['paquetes'] ?? '[]'), true) ?: [];
        $e['estado_rotulo'] = self::ESTADOS[$e['estado']] ?? $e['estado'];
        $e['cerrado'] = in_array($e['estado'], self::CERRADOS, true);
        $e['destino'] = [
            'nombre' => $e['dest_nombre'], 'telefono' => $e['dest_telefono'],
            'email'  => $e['dest_email'],  'calle'    => $e['dest_calle'],
            'numero' => $e['dest_numero'], 'colonia'  => $e['dest_colonia'],
            'ciudad' => $e['dest_ciudad'], 'estado'   => $e['dest_estado'],
            'cp'     => $e['dest_cp'],     'pais'     => $e['dest_pais'],
            'referencia' => $e['dest_referencia'],
        ];
        return $e;
    }

    private static function corta($v, $n)
    {
        return mb_substr(trim((string)$v), 0, $n);
    }
}