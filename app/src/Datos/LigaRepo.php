<?php
namespace LibertyFin\Datos;

/**
 * Las ligas de pago generadas.
 *
 * UNA LIGA NO ES UN PAGO
 *
 * Se guardan aparte de `venta_pagos` a propósito. Un renglón ahí
 * significa "entró dinero" y lo suman el corte, los reportes y las
 * comisiones. Una liga significa "le pedimos al cliente que pague", que
 * es otra cosa y a veces no termina en nada.
 *
 * Cuando el proveedor confirma, se crea el abono de verdad y la liga
 * queda marcada como cobrada. Hasta entonces, la venta tiene saldo.
 */
final class LigaRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ligas_pago (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    referencia VARCHAR(20) NOT NULL,
                    venta_id INT NULL,
                    cliente_nombre VARCHAR(200) NULL,
                    monto DECIMAL(12,2) NOT NULL,
                    metodo VARCHAR(16) NOT NULL DEFAULT 'todos',
                    descripcion VARCHAR(80) NULL,
                    liga VARCHAR(500) NULL,
                    clabe VARCHAR(30) NULL,
                    barras VARCHAR(80) NULL,
                    remota VARCHAR(40) NULL,
                    imagen VARCHAR(500) NULL,
                    formato VARCHAR(500) NULL,
                    estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
                    vence DATE NULL,
                    pagado_en DATETIME NULL,
                    pagado_monto DECIMAL(12,2) NULL,
                    transaccion VARCHAR(32) NULL,
                    autorizacion VARCHAR(32) NULL,
                    pago_id INT NULL,
                    pruebas TINYINT(1) NOT NULL DEFAULT 0,
                    usuario_id INT NULL,
                    usuario_nombre VARCHAR(160) NULL,
                    usuario_correo VARCHAR(160) NULL,
                    revisado_en DATETIME NULL,
                    creado_en DATETIME NOT NULL,
                    UNIQUE KEY ix_lp_ref (referencia),
                    KEY ix_lp_estado (estado, vence),
                    KEY ix_lp_venta (venta_id),
                    KEY ix_lp_trans (transaccion),
                    KEY ix_lp_remota (remota)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Migración: agrega usuario_correo si falta (instalaciones viejas)
            $col = $this->db->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'lf_ligas_pago'
                  AND COLUMN_NAME = 'usuario_correo'
            ")->fetchColumn();

            if ((int)$col === 0) {
                $this->db->exec("
                    ALTER TABLE lf_ligas_pago
                    ADD COLUMN usuario_correo VARCHAR(160) DEFAULT NULL AFTER usuario_nombre");
            }

            // Migración: la referencia que genera EL PROVEEDOR (22 dígitos
            // en la liga de tarjeta, ~30 en tienda). El aviso de tarjeta
            // (pago-liga) llega con esa, no con la nuestra: sin guardarla
            // no casaba con ningún cobro y el pago nunca se aplicaba.
            $col = $this->db->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'lf_ligas_pago'
                  AND COLUMN_NAME = 'remota'
            ")->fetchColumn();

            if ((int)$col === 0) {
                $this->db->exec("
                    ALTER TABLE lf_ligas_pago
                    ADD COLUMN remota VARCHAR(40) DEFAULT NULL AFTER barras,
                    ADD INDEX ix_lp_remota (remota)");
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ligas_pago: ' . $e->getMessage());
        }
    }

    public function crear(array $d)
    {
        $this->asegurar();
        $this->db->prepare("
            INSERT INTO lf_ligas_pago
                (referencia, venta_id, cliente_nombre, monto, metodo, descripcion,
                 liga, clabe, barras, remota, imagen, formato, estado, vence, pruebas,
                 usuario_id, usuario_nombre, usuario_correo, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pendiente', ?,?,?,?,?, NOW())
        ")->execute([
            $d['referencia'], $d['venta_id'] ?? null, $d['cliente'] ?? null,
            $d['monto'], $d['metodo'], mb_substr((string)($d['descripcion'] ?? ''), 0, 80),
            $d['liga'] ?? null, $d['clabe'] ?? null, $d['barras'] ?? null,
            (preg_replace('/\D/', '', (string)($d['remota'] ?? '')) ?: null),
            $d['imagen'] ?? null, $d['formato'] ?? null,
            $d['vence'] ?? null, !empty($d['pruebas']) ? 1 : 0,
            $d['usuario_id'] ?? null, $d['usuario_nombre'] ?? null,
            $d['usuario_correo'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * El cobro al que apunta una CLABE.
     *
     * El aviso de SPEI llega con la CLABE, no con la referencia: es el
     * único dato que el banco conoce.
     */
    public function porClabe($clabe)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_ligas_pago WHERE clabe = ?", [(string)$clabe]);
    }

    /**
     * El cobro que corresponde a lo que mandó el proveedor.
     *
     * Busca por referencia y, si no, por CLABE, código de barras o la
     * referencia remota: el mismo número viaja en campos distintos según
     * el servicio, y exigir que coincida el nombre del campo dejaba pagos
     * sin casar.
     *
     * Último recurso: la referencia del proveedor LLEVA DENTRO la nuestra
     * (p.ej. 000005·00·<nuestra>·1). Sirve para ligas generadas antes de
     * que se guardara `remota`. Solo con valores más largos que la
     * nuestra, para no casar un número corto con cualquier cosa.
     */
    public function porCualquiera($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') return null;
        $this->asegurar();
        $l = $this->uno("
            SELECT * FROM lf_ligas_pago
            WHERE referencia = ? OR clabe = ? OR barras = ? OR remota = ?
            ORDER BY id DESC LIMIT 1", [$valor, $valor, $valor, $valor]);
        if ($l || strlen($valor) <= 15) return $l;
        return $this->uno("
            SELECT * FROM lf_ligas_pago
            WHERE CHAR_LENGTH(referencia) >= 13 AND LOCATE(referencia, ?) > 0
            ORDER BY id DESC LIMIT 1", [$valor]);
    }

    /**
     * El aviso de pago del proveedor, con su número de transacción.
     *
     * Se guarda ANTES de abonar. Si el proveedor reintenta —y lo hace
     * cuando no le contestamos a tiempo— aquí se ve que esa transacción
     * ya entró y no se abona dos veces.
     */
    public function yaEntro($referencia, $transaccion)
    {
        if (trim((string)$transaccion) === '') return null;
        $this->asegurar();
        return $this->uno("
            SELECT * FROM lf_ligas_pago
            WHERE referencia = ? AND transaccion = ? AND estado = 'pagada'",
            [(string)$referencia, (string)$transaccion]);
    }

    /**
     * El abono vigente que ya dejó esa transacción del proveedor en la
     * venta. Funciona con pagos parciales, donde la liga sigue abierta y
     * `yaEntro` no lo ve.
     */
    public function abonoDeTransaccion($ventaId, $transaccion)
    {
        $t = preg_replace('/[^0-9A-Za-z-]/', '', (string)$transaccion);
        if ($t === '' || (int)$ventaId <= 0) return null;
        return $this->uno("
            SELECT * FROM venta_pagos
            WHERE venta_id = ? AND cancelado = 0 AND referencia LIKE ?
            ORDER BY id DESC LIMIT 1", [(int)$ventaId, '% · trx ' . $t]);
    }

    /** Deja constancia de la transacción y la autorización del aviso. */
    public function anotarAviso($id, $transaccion, $autorizacion, $monto = null)
    {
        $this->db->prepare("
            UPDATE lf_ligas_pago
            SET transaccion = ?, autorizacion = ?,
                pagado_monto = COALESCE(?, pagado_monto), revisado_en = NOW()
            WHERE id = ?
        ")->execute([
            $transaccion !== '' ? mb_substr((string)$transaccion, 0, 32) : null,
            $autorizacion !== '' ? mb_substr((string)$autorizacion, 0, 32) : null,
            $monto !== null ? \LibertyFin\Dominio\Dinero::centavos($monto) : null,
            (int)$id,
        ]);
        return true;
    }

    /**
     * Deshace un cobro que el proveedor canceló.
     *
     * El cliente puede volver a pagar la misma referencia, así que vuelve
     * a 'pendiente' y NO a un estado terminal. Lo que se borra es la
     * marca de pagado; el abono lo cancela quien llame a esto.
     */
    public function marcarCancelada($id)
    {
        $this->db->prepare("
            UPDATE lf_ligas_pago
            SET estado = 'pendiente', pagado_en = NULL, pago_id = NULL,
                pagado_monto = NULL, revisado_en = NOW()
            WHERE id = ?")->execute([(int)$id]);
        return true;
    }

    public function porId($id)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_ligas_pago WHERE id = ?", [(int)$id]);
    }

    public function porReferencia($ref)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_ligas_pago WHERE referencia = ?", [(string)$ref]);
    }

    /**
     * Marca una liga como cobrada y deja el abono hecho.
     *
     * Las dos cosas van en una transacción: si el abono se crea y la
     * liga no se marca, la siguiente consulta volvería a abonar y la
     * venta quedaría cobrada dos veces.
     */
    public function marcarPagada($id, $pagoId)
    {
        $this->db->prepare("
            UPDATE lf_ligas_pago
            SET estado = 'pagada', pagado_en = NOW(), pago_id = ?, revisado_en = NOW()
            WHERE id = ? AND estado <> 'pagada'")->execute([$pagoId ?: null, (int)$id]);
        return true;
    }

    public function marcarRevisada($id, $estado = null)
    {
        $sets = 'revisado_en = NOW()';
        $p = [];
        if ($estado && $estado !== 'pagada') { $sets .= ', estado = ?'; $p[] = $estado; }
        $p[] = (int)$id;
        $this->db->prepare("UPDATE lf_ligas_pago SET {$sets} WHERE id = ?")->execute($p);
        return true;
    }

    public function listado($estado = '', $buscar = '', $limite = 30, $desfase = 0)
    {
        $this->asegurar();
        $w = []; $p = [];
        if ($estado === 'pendientes') {
            $w[] = "estado = 'pendiente'";
        } elseif ($estado === 'vencidas') {
            $w[] = "estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()";
        } elseif ($estado !== '') {
            $w[] = 'estado = ?'; $p[] = $estado;
        }
        if ($buscar !== '') {
            $w[] = '(referencia LIKE ? OR cliente_nombre LIKE ? OR descripcion LIKE ?)';
            $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return $this->todos("SELECT * FROM lf_ligas_pago {$where}
            ORDER BY creado_en DESC LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantas($estado = '', $buscar = '')
    {
        $this->asegurar();
        $w = []; $p = [];
        if ($estado === 'pendientes')   $w[] = "estado = 'pendiente'";
        elseif ($estado === 'vencidas') $w[] = "estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()";
        elseif ($estado !== '')       { $w[] = 'estado = ?'; $p[] = $estado; }
        if ($buscar !== '') {
            $w[] = '(referencia LIKE ? OR cliente_nombre LIKE ? OR descripcion LIKE ?)';
            $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return (int)$this->valor("SELECT COUNT(*) FROM lf_ligas_pago {$where}", $p);
    }

    public function cifras()
    {
        $this->asegurar();
        return $this->uno("
            SELECT COUNT(*) AS total,
                   SUM(estado = 'pendiente') AS pendientes,
                   SUM(estado = 'pagada') AS pagadas,
                   SUM(estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()) AS vencidas,
                   COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN monto END),0) AS esperando,
                   COALESCE(SUM(CASE WHEN estado = 'pagada'
                        AND DATE(pagado_en) = CURDATE() THEN monto END),0) AS cobrado_hoy
            FROM lf_ligas_pago") ?: [];
    }

    /** Las que llevan rato sin revisar, para consultarlas en tanda. */
    public function porRevisar($tope = 25)
    {
        $this->asegurar();
        return $this->todos("
            SELECT * FROM lf_ligas_pago
            WHERE estado = 'pendiente'
              AND (vence IS NULL OR vence >= CURDATE())
              AND (revisado_en IS NULL OR revisado_en < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            ORDER BY revisado_en IS NOT NULL, creado_en
            LIMIT " . (int)$tope);
    }
}