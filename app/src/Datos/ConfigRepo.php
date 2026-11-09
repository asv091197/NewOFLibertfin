<?php
namespace LibertyFin\Datos;

/**
 * Configuración de la empresa.
 *
 * VIVE EN DOS TABLAS, Y NO ES CAPRICHO:
 *
 *  · `sistema_config` ya existe en el esquema del sistema anterior. Es de
 *    UN SOLO RENGLÓN con columnas fijas, y trae justo lo que hace falta:
 *    logo, color_primario, rfc, tipo_persona, razon_social,
 *    regimen_fiscal, cp_fiscal, documentacion_estado. Se usa tal cual.
 *
 *  · `lf_ajustes` es clave-valor y la crea este sistema. Guarda lo que no
 *    tiene columna propia: la versión del esquema y qué secciones están
 *    apagadas.
 *
 * Intenté meter todo en una tabla clave-valor llamada `sistema_config` y
 * chocó de frente con la que ya existía: el CREATE IF NOT EXISTS no hizo
 * nada y cada escritura fallaba con "Unknown column 'clave'". Pelearse
 * con el esquema que ya está siempre sale más caro que adaptarse a él.
 */
final class ConfigRepo extends Repo
{
    /** Lo que sí tiene columna en `sistema_config`. */
    const COLUMNAS = [
        'marca.color'         => 'color_primario',
        'marca.color2'        => 'color_secundario',
        'marca.logo'          => 'logo',
        'fiscal.tipo_persona' => 'tipo_persona',
        'fiscal.rfc_fiscal'   => 'rfc',
        'fiscal.cp_fiscal'    => 'cp_fiscal',
        'fiscal.razon_social' => 'razon_social',
        'fiscal.regimen_sat'  => 'regimen_fiscal',
        'documentacion'       => 'documentacion_estado',
    ];

    private static $fila = null;
    private static $kv   = null;

    // ── sistema_config · un renglón, columnas fijas ─────────────

    private function fila()
    {
        if (self::$fila !== null) return self::$fila;
        try {
            $f = $this->uno("SELECT * FROM sistema_config ORDER BY id LIMIT 1");
            if (!$f) {
                // Sin renglón no hay dónde escribir: se crea el primero.
                $this->db->exec("INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')");
                $f = $this->uno("SELECT * FROM sistema_config ORDER BY id LIMIT 1");
            }
            self::$fila = $f ?: [];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sistema_config: ' . $e->getMessage());
            self::$fila = [];
        }
        return self::$fila;
    }

    // ── lf_ajustes · clave-valor, de este sistema ───────────────

    private function asegurarKv()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ajustes (
                    clave VARCHAR(80) NOT NULL PRIMARY KEY,
                    valor TEXT NULL,
                    actualizado DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ajustes: ' . $e->getMessage());
        }
    }

    private function kv()
    {
        if (self::$kv !== null) return self::$kv;
        try {
            $f = $this->todos("SELECT clave, valor FROM lf_ajustes");
            self::$kv = array_column($f, 'valor', 'clave');
        } catch (\Throwable $e) { self::$kv = []; }
        return self::$kv;
    }

    // ── La interfaz: una sola, sin importar dónde viva el dato ──

    public function valorDe($clave, $porDefecto = null)
    {
        if (isset(self::COLUMNAS[$clave])) {
            $f = $this->fila();
            $col = self::COLUMNAS[$clave];
            $v = $f[$col] ?? null;
            return ($v === null || $v === '') ? $porDefecto : $v;
        }
        $kv = $this->kv();
        return array_key_exists($clave, $kv) && $kv[$clave] !== null ? $kv[$clave] : $porDefecto;
    }

    public function guardar($clave, $valor)
    {
        if (isset(self::COLUMNAS[$clave])) {
            $col = self::COLUMNAS[$clave];
            $f = $this->fila();
            if (!$f) throw new \RuntimeException('No se pudo leer sistema_config');
            // El nombre de columna sale de una lista fija, nunca del
            // llamador: por eso se puede interpolar sin riesgo.
            $this->db->prepare("UPDATE sistema_config SET `$col` = ? WHERE id = ?")
                     ->execute([$valor === '' ? null : $valor, $f['id']]);
            self::$fila = null;
            return true;
        }
        $this->asegurarKv();
        $this->db->prepare("
            INSERT INTO lf_ajustes (clave, valor, actualizado) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado = NOW()
        ")->execute([$clave, (string)$valor]);
        self::$kv = null;
        return true;
    }

    /** Todo junto, para quien lo quiera de un jalón. */
    public function todo()
    {
        $r = $this->kv();
        foreach (self::COLUMNAS as $k => $col) {
            $f = $this->fila();
            if (isset($f[$col])) $r[$k] = $f[$col];
        }
        return $r;
    }

    // ── Secciones encendidas o apagadas ─────────────────────────

    const APAGABLES = [
        'cobranza'    => 'Cobranza',
        'corte'       => 'Corte de caja',
        'comisiones'  => 'Comisiones',
        'gastos'      => 'Gastos',
        'reportes'    => 'Reportes',
        'recargas'    => 'Recargas',
        'facturacion' => 'Facturación',
        'ligas'       => 'Ligas de pago',
    ];

    /**
     * ¿Está disponible esta sección para esta empresa?
     *
     * Tienen que decir que sí los DOS interruptores: el de LibertyFin y
     * el de la empresa. Si LibertyFin apagó Facturación porque está
     * rota, que una empresa la tenga encendida no la arregla.
     */
    public function seccionActiva($clave)
    {
        if (!isset(self::APAGABLES[$clave])) return true;
        if (!self::globalActivo('seccion.' . $clave)) return false;
        return $this->valorDe('seccion.' . $clave, '1') !== '0';
    }

    /** ¿Está disponible este método de pago? Misma regla. */
    public function metodoActivo($clave)
    {
        // El efectivo no se puede apagar: sin él no hay forma de cobrar
        // en el mostrador.
        if ($clave === 'efectivo') return true;
        if (!self::globalActivo('metodo.' . $clave)) return false;
        return $this->valorDe('metodo.' . $clave, '1') !== '0';
    }

    public function metodos()
    {
        $r = ['efectivo' => ['rotulo' => 'Efectivo', 'activa' => true, 'fijo' => true]];
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::METODOS as $k => $rotulo) {
            $r[$k] = ['rotulo' => $rotulo,
                      'activa' => $this->metodoActivo($k),
                      'global' => self::globalActivo('metodo.' . $k)];
        }
        return $r;
    }

    /** Los métodos que de verdad se pueden usar al cobrar. */
    public function metodosDisponibles()
    {
        $r = ['efectivo'];
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::METODOS as $k => $_) {
            if ($this->metodoActivo($k)) $r[] = $k;
        }
        return $r;
    }

    public function alternarMetodo($clave)
    {
        if (!isset(\LibertyFin\Datos\AjustesPlataformaRepo::METODOS[$clave])) {
            throw new \InvalidArgumentException('Ese método no se puede apagar');
        }
        if (!self::globalActivo('metodo.' . $clave)) {
            throw new \InvalidArgumentException(
                'LibertyFin tiene ese método apagado para todos. No se puede encender aquí.');
        }
        return $this->guardar('metodo.' . $clave,
            $this->valorDe('metodo.' . $clave, '1') !== '0' ? '0' : '1');
    }

    /**
     * Lo global vive en la sesión. Se lee una vez al entrar.
     * Si no está —sesión vieja— se asume encendido: preferible a apagar
     * media aplicación por no haber leído un dato.
     */
    private static function globalActivo($clave)
    {
        $g = $_SESSION['lf_global'] ?? null;
        if (!is_array($g) || !array_key_exists($clave, $g)) return true;
        return (bool)$g[$clave];
    }

    public function secciones()
    {
        $r = [];
        foreach (self::APAGABLES as $k => $rotulo) {
            $r[$k] = ['rotulo' => $rotulo, 'activa' => $this->seccionActiva($k)];
        }
        return $r;
    }

    public function alternarSeccion($clave)
    {
        if (!isset(self::APAGABLES[$clave])) {
            throw new \InvalidArgumentException('Esa sección no se puede apagar');
        }
        if (!self::globalActivo('seccion.' . $clave)) {
            throw new \InvalidArgumentException(
                'LibertyFin tiene esa sección apagada para todos. No se puede encender aquí.');
        }
        // Se lee el valor PROPIO, no el efectivo: si se leyera el
        // efectivo, apagar algo ya apagado por lo global lo encendería.
        $propio = $this->valorDe('seccion.' . $clave, '1') !== '0';
        return $this->guardar('seccion.' . $clave, $propio ? '0' : '1');
    }

    // ── Diagnóstico ─────────────────────────────────────────────

    public function diagnostico()
    {
        $r = [];
        $r['php']      = PHP_VERSION;
        $r['mysql']    = (string)$this->valor("SELECT VERSION()");
        $r['zona_php'] = date_default_timezone_get() . ' (UTC' . date('P') . ')';
        $r['zona_sql'] = (string)$this->valor("SELECT @@session.time_zone");
        $r['hora_php'] = date('Y-m-d H:i:s');
        $r['hora_sql'] = (string)$this->valor("SELECT NOW()");

        $r['ventas_sin_area'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas
            WHERE estado <> 'cancelada' AND (area_nombre IS NULL OR area_nombre = '')");
        $r['ventas_desfasadas'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas
            WHERE codigo_venta REGEXP '^[0-9]{14}$'
              AND ABS(TIMESTAMPDIFF(MINUTE, STR_TO_DATE(codigo_venta,'%Y%m%d%H%i%s'), fecha)) > 5");
        $r['cobrado_mayor_total'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) c FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) p ON p.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND COALESCE(p.c,0) - v.total > 0.01");
        $r['comisiones_sin_dueno'] = (int)$this->valor("
            SELECT COUNT(*) FROM venta_comisiones
            WHERE cancelada = 0 AND colaborador_nombre = 'POR ASIGNAR'");
        $r['ventas_sin_cliente'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada' AND cliente_id IS NULL");
        $r['cajas_abiertas'] = (int)$this->valor("
            SELECT COUNT(*) FROM caja WHERE estado = 'abierta'");

        $r['tablas'] = $this->todos("
            SELECT TABLE_NAME AS tabla, TABLE_ROWS AS filas,
                   ROUND((DATA_LENGTH + INDEX_LENGTH)/1024) AS kb,
                   ROUND(INDEX_LENGTH/1024) AS ikb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 12");
        return $r;
    }

    /**
     * Como se aplican los pagos en linea.
     *
     * DOS FORMAS DE TRABAJAR, Y LAS DOS SON LEGITIMAS
     *
     *   'auto'    el abono entra solo cuando el proveedor confirma.
     *             Para quien usa el sistema como bitacora: el dinero ya
     *             llego, anotarlo a mano es trabajo doble.
     *
     *   'revisar' el pago confirmado queda PENDIENTE DE APROBAR y
     *             alguien lo libera. Para quien necesita que una
     *             persona vea cada entrada antes de darla por buena:
     *             negocios con varias sucursales, o donde quien cobra
     *             no es quien responde por la caja.
     *
     * Por omision va en 'auto'. Pedir una aprobacion que nadie va a dar
     * deja las ventas colgadas y a la gente convencida de que el
     * sistema no sirve.
     */
    const APROBACION = [
        'auto'    => ['Se aplican solos',
                      'En cuanto el proveedor confirma el pago, el abono entra'],
        'revisar' => ['Requieren aprobación',
                      'El pago confirmado espera a que alguien lo apruebe'],
    ];

    public function modoAprobacion()
    {
        $v = $this->valorDe('pagos.aprobacion', 'auto');
        return isset(self::APROBACION[$v]) ? $v : 'auto';
    }

    /**
     * Cómo arranca cada venta en Caja (Ajustes → Caja). La primera opción
     * de cada lista es la que vale si nadie la ha cambiado.
     *
     *   anticipo    total = la venta se cobra completa salvo que el cajero
     *               escriba otra cantidad; vacio = arranca en $0 y el cajero
     *               escribe cuánto deja (para quien casi siempre cobra un
     *               anticipo).
     *   iva_inicio  sin = cada venta empieza sin IVA (lo normal cuando el IVA
     *               solo va si el cliente pide factura); con = empieza con IVA.
     *   iva_tasa    16 o 8 (zona fronteriza). Los precios ya lo traen: el IVA
     *               no cambia lo que paga el cliente, dice qué parte es IVA.
     */
    const CAJA = [
        'anticipo'   => ['total' => ['El total de la venta', 'Se cobra completa. Si deja solo una parte, el cajero escribe cuánto.'],
                         'vacio' => ['Vacío', 'El cajero escribe cuánto deja. Para quien casi siempre cobra un anticipo.']],
        'iva_inicio' => ['sin'   => ['Sin IVA', 'El cajero lo enciende cuando la venta lleva IVA (por ejemplo, si piden factura).'],
                         'con'   => ['Con IVA', 'Toda venta lleva IVA; el cajero lo apaga cuando no.']],
        'iva_tasa'   => ['16'    => ['16%', 'La tasa general.'],
                         '8'     => ['8%', 'Región fronteriza.']],
    ];

    public function caja()
    {
        $r = [];
        foreach (self::CAJA as $k => $ops) {
            $v = (string)$this->valorDe('caja.' . $k, '');
            $r[$k] = isset($ops[$v]) ? $v : (string)key($ops);
        }
        return $r;
    }

    /** Guarda solo las opciones que vengan, y solo si son válidas. */
    public function guardarCaja(array $d)
    {
        $hecho = 0;
        foreach (self::CAJA as $k => $ops) {
            if (!isset($d[$k])) continue;
            $v = (string)$d[$k];
            if (!isset($ops[$v])) throw new \InvalidArgumentException('Esa opción no existe.');
            $this->guardar('caja.' . $k, $v);
            $hecho++;
        }
        return $hecho;
    }

    /**
     * Inventario (Ajustes → Inventario). La primera opción de cada una es
     * la que vale si nunca se tocó: APAGADO. Las empresas de servicios no
     * deben ver existencias, y no se enteran de que existe.
     */
    const INVENTARIO = [
        'activo'         => ['no'       => ['Apagado', 'Para empresas de servicios: nada de existencias en Caja ni en Productos.'],
                             'si'       => ['Encendido', 'Los productos que lo lleven cuentan existencias; Caja las descuenta al cobrar.']],
        'sin_existencia' => ['avisar'   => ['Avisar y dejar vender', 'Caja dice que no alcanza, pero cobra. La existencia queda en negativo hasta que registres la entrada.'],
                             'bloquear' => ['No dejar vender', 'Caja no cobra más de lo que hay. Útil si las existencias siempre están al día.']],

        // ── Avisos ──
        // Tres niveles a propósito. «Avisar» se puede cerrar y no vuelve
        // en toda la sesión: sirve cuando faltan cosas a diario y el
        // aviso se vuelve paisaje. «Obligatoria» no se cierra, y es para
        // lo que de verdad no puede pasarse por alto. Y «no» existe
        // porque una empresa que compra bajo pedido no tiene nada que
        // avisar, y un aviso que no aplica enseña a ignorar los que sí.
        'alerta_stock' => [
            'avisar'     => ['Avisar, se puede cerrar', 'Sale un aviso arriba cuando algo se agota o llega al mínimo. Se cierra y no vuelve hasta el siguiente ingreso.'],
            'obligatoria'=> ['Aviso fijo', 'El aviso se queda hasta que el problema se resuelva. Para cuando quedarse sin producto no es opción.'],
            'no'         => ['Sin aviso', 'Las existencias se siguen llevando; simplemente no avisa.'],
        ],
        'alerta_caducidad' => [
            'avisar'     => ['Avisar, se puede cerrar', 'Sale un aviso cuando algo perecedero está por caducar o ya caducó.'],
            'obligatoria'=> ['Aviso fijo', 'No se cierra hasta que se dé de baja o se venda lo que caduca.'],
            'no'         => ['Sin aviso', 'No avisa de caducidades.'],
        ],
    ];

    /** Con cuántos días de anticipación avisar que algo caduca. */
    const DIAS_CADUCIDAD = 15;

    public function inventario()
    {
        $r = [];
        foreach (self::INVENTARIO as $k => $ops) {
            $v = (string)$this->valorDe('inventario.' . $k, '');
            $r[$k] = isset($ops[$v]) ? $v : (string)key($ops);
        }
        // Este no es de opciones: es un número de días. Vive aparte
        // porque el formulario de arriba solo sabe de listas cerradas.
        $d = (string)$this->valorDe('inventario.dias_caducidad', '');
        $r['dias_caducidad'] = ctype_digit($d) && (int)$d > 0 && (int)$d <= 365
                             ? (int)$d : self::DIAS_CADUCIDAD;
        return $r;
    }

    public function inventarioActivo() { return $this->inventario()['activo'] === 'si'; }

    public function guardarInventario(array $d)
    {
        $hecho = 0;
        foreach (self::INVENTARIO as $k => $ops) {
            if (!isset($d[$k])) continue;
            $v = (string)$d[$k];
            if (!isset($ops[$v])) throw new \InvalidArgumentException('Esa opción no existe.');
            $this->guardar('inventario.' . $k, $v);
            $hecho++;
        }
        if (isset($d['dias_caducidad'])) {
            $n = trim((string)$d['dias_caducidad']);
            if ($n !== '') {
                if (!ctype_digit($n) || (int)$n < 1 || (int)$n > 365) {
                    throw new \InvalidArgumentException('Los días de aviso van de 1 a 365.');
                }
                $this->guardar('inventario.dias_caducidad', $n);
                $hecho++;
            }
        }
        return $hecho;
    }

    public function exigeAprobacion()
    {
        return $this->modoAprobacion() === 'revisar';
    }

    public function fijarAprobacion($modo)
    {
        if (!isset(self::APROBACION[$modo])) {
            throw new \InvalidArgumentException('Ese modo no existe');
        }
        return $this->guardar('pagos.aprobacion', $modo);
    }
}