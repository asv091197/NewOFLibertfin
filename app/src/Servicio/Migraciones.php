<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Migraciones por empresa.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * LibertyFin crea una base de datos por empresa, y el esquema vive como
 * 36 CREATE TABLE escritos dentro de registroEmpresa.php. Cada columna
 * que agrega una versión nueva del sistema hay que agregarla a mano en
 * TODAS las bases existentes, y además acordarse de meterla en ese
 * archivo para las que nazcan después.
 *
 * Nadie se acuerda. Y el síntoma es el peor posible: una empresa nueva
 * estrena el sistema y le falta una columna, con un error que el resto
 * de los clientes no tiene.
 *
 * CÓMO FUNCIONA
 *
 * Cada migración tiene un número y es IDEMPOTENTE: correrla dos veces
 * no hace nada la segunda. La versión aplicada se guarda en
 * sistema_config de cada empresa, así que cada base sabe dónde va.
 *
 * Se ejecutan solas al entrar, si la base está atrasada. En una base al
 * día el costo es una consulta.
 */
final class Migraciones
{
    /** Súbelo al agregar una migración nueva. */
    const VERSION = 31;

    /**
     * La versión vive en `lf_ajustes`, no en `sistema_config`.
     *
     * `sistema_config` ya existía en el esquema del sistema anterior con
     * columnas fijas —logo, color, datos fiscales— y no tiene forma de
     * clave-valor. Meter ahí la versión obligaba a agregarle una columna
     * más a una tabla que no es nuestra.
     */
    public static function versionDe(PDO $db)
    {
        try {
            $st = $db->query("SELECT valor FROM lf_ajustes WHERE clave = 'esquema.version'");
            return (int)($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;   // sin tabla, la base está en cero
        }
    }

    public static function alDia(PDO $db) { return self::versionDe($db) >= self::VERSION; }

    /**
     * Aplica lo que falte. Devuelve qué se corrió.
     * Nunca lanza: una migración que falla no debe dejar a nadie fuera
     * del sistema. Se anota y se sigue.
     */
    public static function aplicar(PDO $db)
    {
        $desde = self::versionDe($db);
        $hecho = [];
        self::$ultimoFallo = null;
        for ($v = $desde + 1; $v <= self::VERSION; $v++) {
            $metodo = 'v' . $v;
            if (!method_exists(__CLASS__, $metodo)) continue;
            try {
                self::$metodo($db);
                self::marcar($db, $v);
                $hecho[] = $v;
            } catch (\Throwable $e) {
                error_log('[LibertyFin] migración ' . $v . ': ' . $e->getMessage());
                // Además del log, se GUARDA. El log vive en el servidor y
                // quien atiende soporte no siempre puede abrirlo; sin esto,
                // una base atorada solo dice «con problema» y no hay forma
                // de saber cuál de las migraciones se cayó ni por qué.
                self::$ultimoFallo = ['version' => $v, 'mensaje' => $e->getMessage()];
                break;   // no seguir: la siguiente puede depender de esta
            }
        }
        return $hecho;
    }

    /**
     * Qué migración se cayó en el último aplicar(), y con qué error.
     *
     * null si todo salió bien. Se limpia en cada aplicar(), así que hay
     * que leerlo justo después.
     *
     * @return array|null ['version' => int, 'mensaje' => string]
     */
    public static function fallo() { return self::$ultimoFallo; }

    private static $ultimoFallo = null;

    private static function marcar(PDO $db, $v)
    {
        $db->prepare("
            INSERT INTO lf_ajustes (clave, valor, actualizado) VALUES ('esquema.version',?,NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado = NOW()
        ")->execute([(string)$v]);
    }

    /** ¿Existe la columna? Evita el error 1060 al correr dos veces. */
    private static function hayColumna(PDO $db, $tabla, $columna)
    {
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$tabla, $columna]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function hayTabla(PDO $db, $tabla)
    {
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$tabla]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function hayIndice(PDO $db, $tabla, $indice)
    {
        if (!self::hayTabla($db, $tabla)) return true;   // nada que hacer
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$tabla, $indice]);
        return (int)$st->fetchColumn() > 0;
    }

    // ══════════════════════════════════════════════════════════════
    // LAS MIGRACIONES
    // ══════════════════════════════════════════════════════════════

    /**
     * 1 · La tabla clave-valor de este sistema.
     *
     * Se llama `lf_ajustes` y no `sistema_config` a propósito: esa ya
     * existe con otra forma. Un nombre propio evita que dos esquemas
     * peleen por la misma tabla.
     */
    private static function v1(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_ajustes (
                clave VARCHAR(80) NOT NULL PRIMARY KEY,
                valor TEXT NULL,
                actualizado DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Y el renglón único de sistema_config, si la empresa no lo trae.
        try {
            $n = (int)$db->query("SELECT COUNT(*) FROM sistema_config")->fetchColumn();
            if ($n === 0) $db->exec("INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')");
        } catch (\Throwable $e) { /* la empresa no tiene esa tabla: nada que hacer */ }
    }

    /** 2 · Área del cliente. Antes salía de su última venta y no se editaba. */
    private static function v2(PDO $db)
    {
        if (!self::hayColumna($db, 'clientes', 'area')) {
            $db->exec("ALTER TABLE clientes ADD COLUMN area VARCHAR(60) NULL DEFAULT NULL AFTER rfc");
        }
    }

    /** 3 · Foto de perfil. */
    private static function v3(PDO $db)
    {
        if (!self::hayColumna($db, 'usuarios', 'foto')) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN foto VARCHAR(200) NULL DEFAULT NULL AFTER email");
        }
        if (!self::hayColumna($db, 'productos', 'imagen')) {
            $db->exec("ALTER TABLE productos ADD COLUMN imagen VARCHAR(200) NULL DEFAULT NULL");
        }
    }

    /**
     * 4 · Índices.
     *
     * En una base recién creada no se notan. En una con años de ventas
     * son la diferencia entre un listado instantáneo y uno que recorre
     * la tabla entera. Ponerlos desde el día uno evita descubrirlo tarde.
     */
    private static function v4(PDO $db)
    {
        $indices = [
            ['ventas','ix_v_fecha','`fecha`'],
            ['ventas','ix_v_fecha_estado','`fecha`,`estado`'],
            ['ventas','ix_v_cliente','`cliente_id`'],
            ['ventas','ix_v_codigo','`codigo_venta`'],
            ['venta_pagos','ix_vp_venta_cancel','`venta_id`,`cancelado`'],
            ['venta_pagos','ix_vp_fecha_cancel','`fecha_pago`,`cancelado`'],
            ['venta_detalles','ix_vd_venta','`venta_id`'],
            ['venta_detalles','ix_vd_producto','`producto_id`'],
            ['venta_comisiones','ix_vc_venta_cancel','`venta_id`,`cancelada`'],
            ['pago_comisiones','ix_pc_venta','`venta_id`'],
            ['pago_comisiones','ix_pc_comision','`venta_comision_id`'],
            ['gastos','ix_g_venta_tipo','`venta_id`,`tipo`'],
            ['productos','ix_p_activo','`activo`'],
            ['clientes','ix_c_nombre','`nombre`(40)'],
        ];
        foreach ($indices as $i) {
            list($tabla, $nombre, $cols) = $i;
            if (self::hayIndice($db, $tabla, $nombre)) continue;
            try {
                $db->exec("ALTER TABLE `$tabla` ADD INDEX `$nombre` ($cols)");
            } catch (\Throwable $e) {
                // Una tabla que no existe en esta empresa no es un error.
                error_log('[LibertyFin] índice ' . $tabla . '.' . $nombre . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * 5 · Marca de guía vista.
     *
     * No va en localStorage a propósito: eso es por NAVEGADOR, no por
     * usuario. Dos personas de la misma empresa en la misma computadora
     * compartirían el estado y la segunda nunca vería la guía; y la misma
     * persona desde su celular la volvería a ver.
     *
     * Es DATETIME y no un booleano: saber CUÁNDO la vio permite responder
     * "¿esta empresa se atoró el primer día?" sin otra columna después.
     */
    private static function v5(PDO $db)
    {
        if (!self::hayColumna($db, 'usuarios', 'guia_vista_en')) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN guia_vista_en DATETIME NULL DEFAULT NULL");
            // Las cuentas que YA existen no deberían recibir una guía de
            // bienvenida: llevan tiempo usando el sistema y sería ruido.
            $db->exec("UPDATE usuarios SET guia_vista_en = NOW() WHERE guia_vista_en IS NULL");
        }
    }

    /**
     * 6 · Los roles nuevos en el ENUM de `usuarios.rol`.
     *
     * La columna era ENUM('admin','cajero','inventario'). MySQL NO falla
     * al escribir un valor fuera de la lista: guarda cadena vacía y sigue.
     * Por eso asignar "soporte" parecía funcionar y el usuario quedaba sin
     * rol y sin permisos, sin un solo error en ningún lado.
     *
     * Es el peor tipo de fallo: silencioso y con apariencia de éxito.
     *
     * Se agregan los valores nuevos conservando los tres que ya existían,
     * para no invalidar a nadie.
     */
    private static function v6(PDO $db)
    {
        try {
            $st = $db->query("
                SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                  AND COLUMN_NAME = 'rol'");
            $tipo = (string)$st->fetchColumn();
            // Si ya no es ENUM (alguien lo pasó a VARCHAR), no hay nada que hacer.
            if (stripos($tipo, 'enum') !== 0) return;
            if (stripos($tipo, "'soporte'") !== false) return;

            $db->exec("
                ALTER TABLE usuarios MODIFY COLUMN rol
                ENUM('admin','cajero','inventario','soporte','superadmin','validador')
                NOT NULL DEFAULT 'cajero'");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migración 6: ' . $e->getMessage());
        }
    }

    /**
     * 7 · La bitácora.
     *
     * Vive en la base de CADA empresa, no en la principal: registra
     * cambios sobre datos de esa empresa, y juntarlos todos obligaría a
     * cargar el id de empresa en cada renglón y a filtrar siempre por él.
     * Además, si una empresa se va, su bitácora se va con ella.
     *
     * El índice por fecha es lo que hace usable la pantalla: sin él,
     * ver "los últimos 50 movimientos" recorre la tabla entera, y esta
     * tabla solo crece.
     */
    private static function v7(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_auditoria (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                accion VARCHAR(40) NOT NULL,
                sobre VARCHAR(200) NULL,
                antes TEXT NULL,
                despues TEXT NULL,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                usuario_rol VARCHAR(40) NULL,
                ip VARCHAR(45) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_au_fecha (creado_en),
                KEY ix_au_accion (accion, creado_en),
                KEY ix_au_usuario (usuario_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 8 · El catálogo de Emida y sus transacciones.
     *
     * Se crean aquí y no al vuelo porque son parte del esquema: una
     * empresa que vende recargas las necesita desde el primer día, y
     * crearlas en el primer uso significa que el primer uso es el más
     * lento y el más propenso a fallar.
     */
    private static function v8(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_emida_productos (
                producto_id VARCHAR(30) NOT NULL PRIMARY KEY,
                nombre VARCHAR(220) NOT NULL,
                categoria VARCHAR(80) NULL,
                carrier VARCHAR(80) NULL,
                comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                monto DECIMAL(12,2) NOT NULL DEFAULT 0,
                monto_min DECIMAL(12,2) NOT NULL DEFAULT 0,
                monto_max DECIMAL(12,2) NOT NULL DEFAULT 0,
                tipo VARCHAR(12) NOT NULL DEFAULT 'directa',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                actualizado DATETIME NULL,
                KEY ix_ep_cat (categoria),
                KEY ix_ep_carrier (carrier),
                KEY ix_ep_activo (activo, categoria)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_emida_transacciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sales_id VARCHAR(30) NOT NULL,
                producto_id VARCHAR(30) NOT NULL,
                producto_nombre VARCHAR(220) NULL,
                cuenta VARCHAR(40) NOT NULL,
                monto DECIMAL(12,2) NOT NULL,
                comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                folio_proveedor VARCHAR(80) NULL,
                codigo VARCHAR(10) NULL,
                h2h VARCHAR(10) NULL,
                mensaje VARCHAR(300) NULL,
                venta_id INT NULL,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                UNIQUE KEY ix_et_sales (sales_id),
                KEY ix_et_fecha (creado_en),
                KEY ix_et_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 9 · Ligas de pago.
     *
     * Aparte de `venta_pagos` a propósito: un renglón ahí significa
     * "entró dinero" y lo suman el corte, los reportes y las comisiones.
     * Una liga significa "le pedimos al cliente que pague", que a veces
     * no termina en nada.
     */
    private static function v9(PDO $db)
    {
        $db->exec("
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
                estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
                vence DATE NULL,
                pagado_en DATETIME NULL,
                pago_id INT NULL,
                pruebas TINYINT(1) NOT NULL DEFAULT 0,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                revisado_en DATETIME NULL,
                creado_en DATETIME NOT NULL,
                UNIQUE KEY ix_lp_ref (referencia),
                KEY ix_lp_estado (estado, vence),
                KEY ix_lp_venta (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 10 · El especialista asignado a la venta.
     *
     * NO es quien la cobró. `usuario_id` ya guarda al cajero que tecleó;
     * esto es quién va a HACER el trabajo. Son distintos casi siempre:
     * recepción cobra, el contador trabaja.
     *
     * Se guarda el id y también el nombre. El id para poder filtrar y
     * enlazar; el nombre porque si esa persona se va de la empresa y su
     * usuario se desactiva, el reporte de hace seis meses tiene que
     * seguir diciendo quién atendió.
     */
    private static function v10(PDO $db)
    {
        foreach ([
            "ALTER TABLE ventas ADD COLUMN especialista_id INT NULL AFTER usuario_id",
            "ALTER TABLE ventas ADD COLUMN especialista_nombre VARCHAR(160) NULL AFTER especialista_id",
            "ALTER TABLE ventas ADD INDEX ix_v_especialista (especialista_id)",
        ] as $sql) {
            try { $db->exec($sql); } catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 11 · Lo que traen los avisos de Paga de Todo.
     *
     * Hasta ahora un cobro solo guardaba qué se le pidió al proveedor.
     * Cuando el proveedor contesta —y sobre todo cuando avisa que ya
     * pagaron— trae datos que había que poder guardar:
     *
     *   transaccion   su folio de la operación. Es lo que hace que un
     *                 reintento no abone dos veces: si ya entró con ese
     *                 número, se contesta que sí y no se toca nada.
     *   autorizacion  el número que la tienda imprime en el ticket. Es
     *                 por donde pregunta el cliente cuando reclama.
     *   pagado_monto  cuánto pagó DE VERDAD. En SPEI el cliente deposita
     *                 lo que quiere, y sin esto no había forma de ver
     *                 que pagó de menos.
     *   imagen        el PNG del código de barras.
     *   formato       el PDF que el proveedor arma con las instrucciones.
     */
    private static function v11(PDO $db)
    {
        if (!self::hayTabla($db, 'lf_ligas_pago')) return;   // nace con todo
        foreach ([
            'imagen'       => "ALTER TABLE lf_ligas_pago ADD COLUMN imagen VARCHAR(500) NULL AFTER barras",
            'formato'      => "ALTER TABLE lf_ligas_pago ADD COLUMN formato VARCHAR(500) NULL AFTER imagen",
            'pagado_monto' => "ALTER TABLE lf_ligas_pago ADD COLUMN pagado_monto DECIMAL(12,2) NULL AFTER pagado_en",
            'transaccion'  => "ALTER TABLE lf_ligas_pago ADD COLUMN transaccion VARCHAR(32) NULL AFTER pagado_monto",
            'autorizacion' => "ALTER TABLE lf_ligas_pago ADD COLUMN autorizacion VARCHAR(32) NULL AFTER transaccion",
        ] as $columna => $sql) {
            if (self::hayColumna($db, 'lf_ligas_pago', $columna)) continue;
            try { $db->exec($sql); } catch (\Throwable $e) { /* ya existe */ }
        }
        if (!self::hayIndice($db, 'lf_ligas_pago', 'ix_lp_trans')) {
            try { $db->exec("ALTER TABLE lf_ligas_pago ADD INDEX ix_lp_trans (transaccion)"); }
            catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 12 · Subfolios de cobro y ampliación de ventas.
     *
     *   venta_pagos.folio       el subfolio: 20260102143022-02
     *   venta_detalles.agregado_en  cuándo se agregó esta línea
     *
     * POR QUÉ EL SUBFOLIO
     *
     * Los cobros no tenían folio propio. Una clienta que deja $1,500 al
     * mes generaba doce renglones identificados todos con el folio de la
     * venta, indistinguibles entre sí y confundibles con una venta de
     * caja. Nadie podía referirse a uno por teléfono.
     *
     * LOS QUE YA ESTABAN NO SE RELLENAN
     *
     * Se podría recorrer la tabla y numerarlos por fecha. No se hace: el
     * orden de dos cobros del mismo día no siempre es el de su id, y un
     * número inventado hoy contradiría un ticket impreso hace meses.
     * Los viejos se muestran con un número calculado al vuelo, marcado
     * como tal. Ver Servicio\Folio::deRespaldo().
     */
    private static function v12(PDO $db)
    {
        if (self::hayTabla($db, 'venta_pagos')
            && !self::hayColumna($db, 'venta_pagos', 'folio')) {
            try {
                $db->exec("ALTER TABLE venta_pagos
                           ADD COLUMN folio VARCHAR(24) NULL AFTER venta_id");
            } catch (\Throwable $e) { /* ya existe */ }
            // No es UNIQUE: los cobros viejos van en NULL y MySQL permite
            // varios NULL en un índice único, pero un índice normal
            // basta para lo único que se hace, que es buscar por folio.
            if (!self::hayIndice($db, 'venta_pagos', 'ix_vp_folio')) {
                try { $db->exec("ALTER TABLE venta_pagos ADD INDEX ix_vp_folio (folio)"); }
                catch (\Throwable $e) { /* ya existe */ }
            }
        }
        if (self::hayTabla($db, 'venta_detalles')
            && !self::hayColumna($db, 'venta_detalles', 'agregado_en')) {
            try {
                $db->exec("ALTER TABLE venta_detalles
                           ADD COLUMN agregado_en DATETIME NULL");
            } catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 13 · El detalle de una operación en lote, en la bitácora.
     *
     * `antes` y `despues` se recortan a mil caracteres: sirven para "el
     * precio pasó de $800 a $999", no para la lista de 300 ventas que
     * tocó una asignación de comisiones en lote. Esa lista va aparte, y
     * es lo que la bitácora despliega para contestar "¿cuáles fueron?".
     */
    private static function v13(PDO $db)
    {
        if (self::hayTabla($db, 'lf_auditoria')
            && !self::hayColumna($db, 'lf_auditoria', 'detalle')) {
            try {
                $db->exec("ALTER TABLE lf_auditoria ADD COLUMN detalle MEDIUMTEXT NULL AFTER despues");
            } catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 14 · El banco de un pago con tarjeta o SPEI.
     *
     * El esquema actual ya trae `venta_pagos.banco`, pero bases más
     * viejas no. Sin la columna el cobro fallaría al guardarlo.
     */
    private static function v14(PDO $db)
    {
        if (self::hayTabla($db, 'venta_pagos')
            && !self::hayColumna($db, 'venta_pagos', 'banco')) {
            try {
                $db->exec("ALTER TABLE venta_pagos ADD COLUMN banco VARCHAR(100) NULL AFTER metodo_pago");
            } catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 15 · Datos fiscales del comprobante y domicilio del cliente.
     *
     * ── Ventas · el CFDI emitido ─────────────────────────────────────
     *
     * `factura_uuid` y `factura_folio` ya existían: guardaban "esto se
     * timbró, y con qué folio". Pero un CFDI no es solo el UUID. Si esa
     * venta hay que reimprimirla, cancelarla o mandarla por correo, el
     * sistema necesita poder reconstruir el comprobante sin volver a
     * preguntarle al PAC: para quién fue, con qué uso de CFDI, con qué
     * método y forma de pago, y por cuánto.
     *
     * Se guardan los importes (subtotal, IVA, total) y NO se recalculan
     * al vuelo a propósito: un CFDI emitido en 2024 con IVA del 16% debe
     * seguir diciendo eso aunque hoy la venta se vea distinto. Lo
     * timbrado es un hecho, no una derivación.
     *
     * `facturada` es un booleano con DEFAULT 0 para que las ventas
     * históricas queden marcadas como no facturadas sin ambigüedad, y
     * para poder listar "las que faltan por facturar" con un índice
     * barato. Los demás campos van NULL: un NULL en uso_cfdi significa
     * "se timbró pero no sabemos con qué", que es distinto de cadena
     * vacía y no hay que confundir.
     *
     * ── Clientes · el domicilio y los datos fiscales del receptor ────
     *
     * Al principio `clientes` solo guardaba un `direccion` de texto
     * libre: servía para imprimir en el ticket, nada más. Cuando llegó
     * el timbrado, el CFDI exige la dirección DESGLOSADA (calle, colonia,
     * municipio, estado, CP, país) y el régimen fiscal del receptor. Los
     * primeros esquemas no los tienen y sus ventas no se pueden facturar.
     *
     * `razon_social` va aparte de `nombre` a propósito. `nombre` es como
     * le decimos al cliente en el mostrador ("Juan", "Doña Mary"); la
     * razón social es como aparece en el SAT ("JUAN PÉREZ HERNÁNDEZ" o
     * "COMERCIALIZADORA DEL VALLE SA DE CV"). Casi siempre son distintos,
     * y confundirlos produce CFDI rechazados.
     *
     * `pais` lleva DEFAULT 'México' porque en la práctica todas las
     * empresas del sistema son mexicanas y un NULL obligaría a teclearlo
     * cada vez. Si algún día hay un cliente extranjero, se cambia.
     *
     * Todo va NULL salvo el país: un cliente capturado antes de esta
     * migración no tiene domicilio fiscal capturado, y eso se distingue
     * de "tiene dirección vacía" — que sería un dato falso. Al facturar
     * el sistema debe pedir los datos, no inventarlos.
     *
     * Los `AFTER` de ventas van encadenados sobre `factura_folio`, que es
     * la columna que ya existía en el esquema. Si esa no estuviera, el
     * ALTER falla y se anota, pero no detiene el resto: por eso cada
     * columna se envuelve por separado.
     */
    private static function v15(PDO $db)
    {
        // ─────────────────────────────────────────────────────────
        // Ventas · datos fiscales del comprobante
        // ─────────────────────────────────────────────────────────
        if (self::hayTabla($db, 'ventas')) {

            $columnas = [
                'facturada'                => "TINYINT(1) NOT NULL DEFAULT 0 AFTER factura_folio",
                'factura_fecha_timbrado'   => "DATETIME NULL AFTER facturada",
                'factura_rfc_receptor'     => "VARCHAR(20) NULL",
                'factura_razon_social'     => "VARCHAR(255) NULL",
                'factura_uso_cfdi'         => "VARCHAR(5) NULL",
                'factura_metodo_pago'      => "VARCHAR(3) NULL",   // PUE / PPD
                'factura_forma_pago'       => "VARCHAR(2) NULL",   // 01/02/03/04/28/99
                'factura_cp_receptor'      => "VARCHAR(10) NULL",
                'factura_email_receptor'   => "VARCHAR(100) NULL",
                'factura_subtotal'         => "DECIMAL(10,2) NULL",
                'factura_iva'              => "DECIMAL(10,2) NULL",
                'factura_total'            => "DECIMAL(10,2) NULL",
            ];

            foreach ($columnas as $columna => $definicion) {
                if (self::hayColumna($db, 'ventas', $columna)) continue;
                try {
                    $db->exec("ALTER TABLE ventas ADD COLUMN `$columna` $definicion");
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] migración 15 · ventas.' . $columna . ': ' . $e->getMessage());
                }
            }

            // El índice sobre factura_uuid acelera la búsqueda por UUID al
            // reimprimir o cancelar. No es UNIQUE: las ventas sin facturar
            // lo dejan en NULL y varias NULL conviven sin problema.
            if (!self::hayIndice($db, 'ventas', 'idx_ventas_factura_uuid')) {
                try {
                    $db->exec("ALTER TABLE ventas ADD INDEX idx_ventas_factura_uuid (factura_uuid)");
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] migración 15 · índice factura_uuid: ' . $e->getMessage());
                }
            }
        }

        // ─────────────────────────────────────────────────────────
        // Clientes · domicilio y datos fiscales del receptor
        // ─────────────────────────────────────────────────────────
        if (self::hayTabla($db, 'clientes')) {

            $columnas = [
                // Nombre fiscal, va pegado a `nombre` porque son el mismo
                // dato visto desde dos lados: mostrador vs. SAT.
                'razon_social'         => "VARCHAR(255) NULL AFTER nombre",
                // Domicilio desglosado, como lo pide el CFDI 4.0.
                'calle_numero'         => "VARCHAR(200) NULL",
                'numero_interior'      => "VARCHAR(200) NULL",
                'colonia'              => "VARCHAR(200) NULL",
                'delegacion_municipio' => "VARCHAR(200) NULL",
                'ciudad'               => "VARCHAR(100) NULL",
                'estado_direccion'     => "VARCHAR(100) NULL",
                'pais'                 => "VARCHAR(100) NULL DEFAULT 'México'",
                'codigo_postal'        => "VARCHAR(10) NULL",
                // Datos fiscales del receptor.
                'regimen_fiscal'       => "VARCHAR(10) NULL",   // catálogo SAT c_RegimenFiscal
                'uso_cfdi'             => "VARCHAR(5) NULL",    // catálogo SAT c_UsoCFDI
            ];

            foreach ($columnas as $columna => $definicion) {
                if (self::hayColumna($db, 'clientes', $columna)) continue;
                try {
                    $db->exec("ALTER TABLE clientes ADD COLUMN `$columna` $definicion");
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] migración 15 · clientes.' . $columna . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Porcentajes de comisión con decimales (33.333).
     *
     * Las columnas eran DECIMAL(5,2) —solo dos decimales—, así que 33.333
     * se guardaba como 33.33 y tres partes iguales nunca sumaban 100.
     * Se amplían a DECIMAL(8,4). Solo se toca la columna que todavía tenga
     * menos de 4 decimales, de modo que correrla otra vez no hace nada, y
     * cada tabla va por su cuenta: si una falla se anota y se sigue.
     */
    private static function v17(PDO $db)
    {
        $columnas = [
            ['venta_comisiones', 'porcentaje_regla',   "DECIMAL(8,4) NOT NULL"],
            ['venta_comisiones', 'porcentaje_reparto', "DECIMAL(8,4) NOT NULL DEFAULT '100.0000'"],
            ['comision_reglas',  'porcentaje',         "DECIMAL(8,4) NOT NULL"],
            ['pago_comisiones',  'porcentaje',         "DECIMAL(8,4) NOT NULL DEFAULT '0.0000'"],
        ];
        foreach ($columnas as list($tabla, $columna, $definicion)) {
            if (!self::hayTabla($db, $tabla) || !self::hayColumna($db, $tabla, $columna)) continue;
            $st = $db->prepare("
                SELECT NUMERIC_SCALE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$tabla, $columna]);
            if ((int)$st->fetchColumn() >= 4) continue;
            try {
                $db->exec("ALTER TABLE `$tabla` MODIFY COLUMN `$columna` $definicion");
            } catch (\Throwable $e) {
                error_log('[LibertyFin] migración 17 · ' . $tabla . '.' . $columna . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * 18 · El historial de «Recalcular comisiones», para poder deshacerlo.
     *
     *   comision_recalculos        cada vez que se presionó el botón: quién,
     *                              cuándo, cuántas ventas revisó y cambió, y
     *                              si se deshizo.
     *   comision_recalculo_ventas  cada venta que cambió: cómo estaban sus
     *                              renglones de pago_comisiones antes
     *                              (filas_antes) y la huella de cómo quedó,
     *                              para no deshacer encima de algo nuevo.
     *
     * Fue la versión 16, pero ese número lo tomó después la de los
     * decimales (hoy la 17) y esta se perdió. No se puede volver a usar el
     * 16: una base que ya está en 16 o más se lo saltaría. En las empresas
     * que ya tienen las tablas, IF NOT EXISTS no las toca.
     */
    private static function v18(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS comision_recalculos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                creado_en DATETIME NOT NULL,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                ventas_revisadas INT NOT NULL DEFAULT 0,
                ventas_cambiadas INT NOT NULL DEFAULT 0,
                deshecho_en DATETIME NULL,
                deshecho_por VARCHAR(160) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("
            CREATE TABLE IF NOT EXISTS comision_recalculo_ventas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recalculo_id INT NOT NULL,
                venta_id INT NOT NULL,
                antes DECIMAL(12,2) NOT NULL DEFAULT 0,
                despues DECIMAL(12,2) NOT NULL DEFAULT 0,
                meses TEXT NULL,
                huella CHAR(32) NOT NULL,
                filas_antes MEDIUMTEXT NOT NULL,
                deshecha TINYINT(1) NOT NULL DEFAULT 0,
                KEY ix_crv_recalculo (recalculo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 19 · Promociones.
     *
     *   lf_promociones           la promoción: tipo, valor, vigencia, días,
     *                            código (cupón), compra mínima y límite de usos.
     *   lf_promocion_objetivos   a qué aplica: productos o áreas (categorías).
     *                            En un paquete, los productos que lo forman y
     *                            cuántos de cada uno.
     *   lf_promocion_usos        cada vez que una venta la usó, por producto y
     *                            con cuánto se descontó. De aquí salen el límite
     *                            de usos y lo que costó cada promoción.
     *
     * Nombres propios a propósito: el esquema heredado trae `promociones` y
     * sus tablas hermanas, pensadas para una tienda (marcas, mayoreo), con la
     * definición cortada en config/esquema.sql. Nadie las usa, y en las bases
     * viejas pueden existir con otra forma: compartir el nombre las pisaría.
     */
    private static function v19(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_promociones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                tipo VARCHAR(12) NOT NULL,
                valor DECIMAL(12,4) NOT NULL DEFAULT 0,
                lleva INT NULL,
                paga INT NULL,
                alcance VARCHAR(10) NOT NULL DEFAULT 'productos',
                desde DATE NULL,
                hasta DATE NULL,
                dias VARCHAR(20) NULL,
                codigo VARCHAR(30) NULL,
                minimo DECIMAL(12,2) NOT NULL DEFAULT 0,
                limite INT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                nota VARCHAR(255) NULL,
                creado_en DATETIME NOT NULL,
                creado_por VARCHAR(160) NULL,
                actualizado_en DATETIME NULL,
                KEY ix_pr_activo (activo, desde, hasta),
                KEY ix_pr_codigo (codigo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_promocion_objetivos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                promocion_id INT NOT NULL,
                tipo VARCHAR(10) NOT NULL,
                ref_id INT NOT NULL,
                cantidad INT NOT NULL DEFAULT 1,
                KEY ix_po_promo (promocion_id),
                KEY ix_po_ref (tipo, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_promocion_usos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                promocion_id INT NOT NULL,
                venta_id INT NOT NULL,
                producto_id INT NULL,
                cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
                descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
                codigo VARCHAR(30) NULL,
                usuario_id INT NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_pu_promo (promocion_id),
                KEY ix_pu_venta (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 20 · Lo que una empresa NUEVA no tenía y el código ya usaba.
     *
     * Las empresas que vienen del sistema anterior traen estas columnas en
     * su base; config/esquema.sql no, y ninguna versión las agregaba. Una
     * empresa creada desde LibertyFin no podía ni abrir Ventas
     * ("Unknown column 'v.area_nombre'") ni cobrar en Caja (iva_modo y el
     * total de la línea). Se encontraron cruzando cada consulta del código
     * contra lo que crea una empresa nueva.
     *
     *   ventas.area_nombre                el área de la venta (la de más peso)
     *   ventas.iva_modo                   si el IVA va incluido o se suma
     *   venta_comisiones.descuento_linea  el descuento de la línea, sin IVA
     *   venta_pagos.cfdi_complemento_*    el complemento de pago timbrado
     *   venta_detalles.total              tenía NOT NULL sin valor por
     *                                     omisión y nadie lo llena: toda
     *                                     línea nueva tronaba
     *
     * Solo se agrega lo que falte: en una base que ya lo tiene no toca nada.
     */
    private static function v20(PDO $db)
    {
        $columnas = [
            ['ventas',           'area_nombre',           "VARCHAR(150) NULL DEFAULT NULL"],
            ['ventas',           'iva_modo',              "VARCHAR(10) NOT NULL DEFAULT 'incluido'"],
            ['venta_comisiones', 'descuento_linea',       "DECIMAL(12,2) NOT NULL DEFAULT 0"],
            ['venta_pagos',      'cfdi_complemento_id',   "VARCHAR(64) NULL DEFAULT NULL"],
            ['venta_pagos',      'cfdi_complemento_uuid', "VARCHAR(64) NULL DEFAULT NULL"],
            ['venta_pagos',      'cfdi_complemento_en',   "DATETIME NULL DEFAULT NULL"],
        ];
        foreach ($columnas as list($tabla, $columna, $definicion)) {
            if (!self::hayTabla($db, $tabla) || self::hayColumna($db, $tabla, $columna)) continue;
            try {
                $db->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicion");
            } catch (\Throwable $e) {
                error_log('[LibertyFin] migración 20 · ' . $tabla . '.' . $columna . ': ' . $e->getMessage());
            }
        }

        // venta_detalles.total: valor por omisión, conservando su tipo. Solo
        // si es obligatorio y no tiene uno (en las bases viejas ya lo tiene).
        try {
            $st = $db->query("
                SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles' AND COLUMN_NAME = 'total'");
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c && $c['IS_NULLABLE'] === 'NO' && $c['COLUMN_DEFAULT'] === null
                && preg_match('/^(decimal|int|float|double)/i', $c['COLUMN_TYPE'])) {
                $db->exec("ALTER TABLE venta_detalles MODIFY COLUMN `total` {$c['COLUMN_TYPE']} NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migración 20 · venta_detalles.total: ' . $e->getMessage());
        }
    }

    /**
     * Inventario: qué productos llevan existencias y cada movimiento.
     *
     * Las existencias por sucursal ya tenían tabla (`producto_sucursal`,
     * del sistema anterior); lo que faltaba era saber cuáles productos
     * las llevan —un servicio no— y el rastro de cada cambio: sin él, una
     * existencia que no cuadra no se puede explicar.
     *
     * Los productos que ya traían existencias del sistema anterior quedan
     * marcados: así, al encender Inventario, no empiezan de cero.
     */
    private static function v21(PDO $db)
    {
        if (!self::hayColumna($db, 'productos', 'lleva_inventario')) {
            $db->exec("ALTER TABLE productos ADD COLUMN lleva_inventario TINYINT(1) NOT NULL DEFAULT 0");
            try {
                $db->exec("
                    UPDATE productos SET lleva_inventario = 1
                    WHERE COALESCE(stock,0) <> 0
                       OR id IN (SELECT producto_id FROM producto_sucursal WHERE COALESCE(stock,0) <> 0)");
            } catch (\Throwable $e) {
                error_log('[LibertyFin] migración 21 · marcar productos: ' . $e->getMessage());
            }
        }

        if (!self::hayTabla($db, 'producto_sucursal')) {
            $db->exec("
                CREATE TABLE producto_sucursal (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    producto_id INT NOT NULL,
                    sucursal_id INT NOT NULL,
                    stock DECIMAL(10,3) DEFAULT 0,
                    stock_minimo DECIMAL(10,3) DEFAULT 0,
                    activo TINYINT DEFAULT 1,
                    fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_producto_sucursal (producto_id, sucursal_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // El stock que el sistema anterior guardaba solo en `productos`
        // (sin fila por sucursal) pasa a la matriz: Inventario lee por
        // sucursal y, sin esto, esas piezas no aparecerían.
        try {
            $db->exec("
                INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo, activo)
                SELECT p.id,
                       (SELECT s.id FROM sucursales s ORDER BY COALESCE(s.es_matriz,0) DESC, s.id LIMIT 1),
                       p.stock, COALESCE(p.stock_minimo,0), 1
                FROM productos p
                WHERE p.lleva_inventario = 1 AND COALESCE(p.stock,0) <> 0
                  AND NOT EXISTS (SELECT 1 FROM producto_sucursal ps WHERE ps.producto_id = p.id)
                  AND EXISTS (SELECT 1 FROM sucursales)");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migración 21 · stock a la matriz: ' . $e->getMessage());
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_inventario_movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                producto_id INT NOT NULL,
                sucursal_id INT NOT NULL,
                tipo VARCHAR(12) NOT NULL,
                cantidad DECIMAL(12,3) NOT NULL,
                existencia DECIMAL(12,3) NOT NULL,
                costo DECIMAL(12,2) NULL DEFAULT NULL,
                proveedor_id INT NULL DEFAULT NULL,
                proveedor VARCHAR(150) NULL DEFAULT NULL,
                nota VARCHAR(255) NULL DEFAULT NULL,
                venta_id INT NULL DEFAULT NULL,
                usuario_id INT NULL DEFAULT NULL,
                usuario_nombre VARCHAR(100) NULL DEFAULT NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_im_prod (producto_id, sucursal_id, creado_en),
                KEY ix_im_venta (venta_id),
                KEY ix_im_fecha (creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Los módulos por giro (ver Servicio\Modulos) y cancelar ventas.
     *
     * Todas las tablas nacen vacías y nada las usa mientras el módulo esté
     * apagado: una empresa de servicios no paga por la agenda de un spa.
     * Una sola versión para todo porque llegaron juntos; si una tabla
     * falla, las demás se crean igual (cada una en su try).
     */
    private static function v22(PDO $db)
    {
        $columnas = [
            // Cancelar una venta: quién, cuándo y por qué.
            ['ventas',    'cancelada_en',       "DATETIME NULL DEFAULT NULL"],
            ['ventas',    'cancelada_por',      "VARCHAR(100) NULL DEFAULT NULL"],
            ['ventas',    'motivo_cancelacion', "VARCHAR(255) NULL DEFAULT NULL"],
            // Agenda: cuánto dura el servicio. Paquetes: N sesiones de otro servicio.
            ['productos', 'duracion_min',       "INT NULL DEFAULT NULL"],
            ['productos', 'sesiones',           "INT NULL DEFAULT NULL"],
            ['productos', 'sesion_de',          "INT NULL DEFAULT NULL"],
        ];
        foreach ($columnas as list($tabla, $columna, $def)) {
            if (!self::hayTabla($db, $tabla) || self::hayColumna($db, $tabla, $columna)) continue;
            try { $db->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $def"); }
            catch (\Throwable $e) { error_log('[LibertyFin] migración 22 · ' . $tabla . '.' . $columna . ': ' . $e->getMessage()); }
        }

        $tablas = [
            // ── Expediente del cliente ──
            "CREATE TABLE IF NOT EXISTS lf_cliente_ficha (
                cliente_id INT NOT NULL PRIMARY KEY,
                texto TEXT NULL,
                actualizado_en DATETIME NULL,
                actualizado_por VARCHAR(100) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS lf_cliente_notas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                texto TEXT NOT NULL,
                usuario_nombre VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_cn_cliente (cliente_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS lf_cliente_archivos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                nombre VARCHAR(200) NOT NULL,
                archivo VARCHAR(120) NOT NULL,
                mime VARCHAR(60) NOT NULL,
                tamano INT NOT NULL DEFAULT 0,
                usuario_nombre VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_ca_cliente (cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Cotizaciones ──
            "CREATE TABLE IF NOT EXISTS lf_cotizaciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folio VARCHAR(20) NOT NULL,
                cliente_id INT NULL,
                cliente_nombre VARCHAR(200) NULL,
                estado VARCHAR(12) NOT NULL DEFAULT 'borrador',
                vigencia DATE NULL,
                notas TEXT NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                venta_id INT NULL,
                creado_por VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                actualizado_en DATETIME NULL,
                KEY ix_cot_estado (estado), KEY ix_cot_cliente (cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS lf_cotizacion_lineas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cotizacion_id INT NOT NULL,
                producto_id INT NULL,
                nombre VARCHAR(255) NOT NULL,
                cantidad DECIMAL(12,3) NOT NULL DEFAULT 1,
                precio DECIMAL(12,2) NOT NULL DEFAULT 0,
                KEY ix_cl_cot (cotizacion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Igualas: un cobro que se repite cada mes ──
            "CREATE TABLE IF NOT EXISTS lf_igualas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                producto_id INT NOT NULL,
                concepto VARCHAR(200) NULL,
                monto DECIMAL(12,2) NOT NULL,
                dia TINYINT NOT NULL DEFAULT 1,
                especialista_id INT NULL,
                desde CHAR(7) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_por VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_ig_cliente (cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // La llave única es la que impide generar dos veces el mismo mes.
            "CREATE TABLE IF NOT EXISTS lf_iguala_ventas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                iguala_id INT NOT NULL,
                periodo CHAR(7) NOT NULL,
                venta_id INT NULL,
                creado_en DATETIME NOT NULL,
                UNIQUE KEY ux_iv (iguala_id, periodo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Seguimiento del trabajo de cada venta ──
            "CREATE TABLE IF NOT EXISTS lf_trabajos (
                venta_id INT NOT NULL PRIMARY KEY,
                estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
                responsable_id INT NULL,
                entrega DATE NULL,
                nota VARCHAR(255) NULL,
                actualizado_en DATETIME NULL,
                actualizado_por VARCHAR(100) NULL,
                KEY ix_tr_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Compras a proveedor ──
            "CREATE TABLE IF NOT EXISTS lf_compras (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folio VARCHAR(20) NOT NULL,
                proveedor_id INT NULL,
                proveedor_nombre VARCHAR(200) NULL,
                sucursal_id INT NOT NULL,
                estado VARCHAR(12) NOT NULL DEFAULT 'pedida',
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                notas VARCHAR(255) NULL,
                gasto_id INT NULL,
                creado_por VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                recibida_en DATETIME NULL,
                recibida_por VARCHAR(100) NULL,
                KEY ix_co_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS lf_compra_lineas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                compra_id INT NOT NULL,
                producto_id INT NOT NULL,
                cantidad DECIMAL(12,3) NOT NULL,
                costo DECIMAL(12,2) NOT NULL DEFAULT 0,
                KEY ix_col_compra (compra_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Agenda de citas ──
            "CREATE TABLE IF NOT EXISTS lf_citas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NULL,
                cliente_nombre VARCHAR(200) NOT NULL,
                telefono VARCHAR(30) NULL,
                colaborador_id INT NULL,
                producto_id INT NULL,
                servicio VARCHAR(255) NULL,
                inicio DATETIME NOT NULL,
                fin DATETIME NOT NULL,
                estado VARCHAR(12) NOT NULL DEFAULT 'agendada',
                notas VARCHAR(255) NULL,
                venta_id INT NULL,
                creado_por VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_ci_inicio (inicio), KEY ix_ci_colab (colaborador_id, inicio), KEY ix_ci_cliente (cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // ── Paquetes de sesiones ──
            "CREATE TABLE IF NOT EXISTS lf_paquetes_cliente (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                producto_id INT NOT NULL,
                paquete_id INT NOT NULL,
                total INT NOT NULL,
                usadas INT NOT NULL DEFAULT 0,
                venta_id INT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL,
                KEY ix_pc_cliente (cliente_id, producto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS lf_paquete_usos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                paquete_cliente_id INT NOT NULL,
                venta_id INT NULL,
                cantidad INT NOT NULL DEFAULT 1,
                usuario_nombre VARCHAR(100) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_pu_paquete (paquete_cliente_id), KEY ix_pu_venta (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tablas as $sql) {
            try { $db->exec($sql); }
            catch (\Throwable $e) { error_log('[LibertyFin] migración 22 · tabla: ' . $e->getMessage()); }
        }
    }

    /**
     * Recupera el catálogo de Emida que apagó la descarga automática.
     *
     * Guardar el catálogo lo REEMPLAZABA: apagaba todo y encendía solo lo
     * que llegaba. Con el catálogo pegado desde el portal (completo) eso
     * estaba bien; cuando la descarga se hizo automática, la conexión trajo
     * menos productos y apagó todos los demás. No se borró nada: siguen en
     * la tabla con activo = 0. (Desde ahora la descarga solo agrega.)
     *
     * Solo se toca si se ve exactamente eso: hay productos apagados, y
     * DESPUÉS de que se apagaron hubo una descarga del proveedor (la
     * bitácora dice «catálogo de Emida»; lo pegado dice «… (pegado)»).
     * Entonces se vuelve a encender el lote apagado más reciente: el
     * catálogo completo que había antes de la descarga. Los lotes viejos,
     * que alguien quitó a propósito al pegar uno nuevo, se quedan apagados.
     */
    private static function v23(PDO $db)
    {
        if (!self::hayTabla($db, 'lf_emida_productos') || !self::hayTabla($db, 'lf_auditoria')) return;
        $apagado = $db->query("SELECT MAX(actualizado) FROM lf_emida_productos WHERE activo = 0")->fetchColumn();
        if (!$apagado) return;
        $encendido = $db->query("SELECT MAX(actualizado) FROM lf_emida_productos WHERE activo = 1")->fetchColumn();
        if (!$encendido || $encendido <= $apagado) return;
        $st = $db->prepare("SELECT COUNT(*) FROM lf_auditoria WHERE sobre = 'catálogo de Emida' AND creado_en >= ?");
        $st->execute([$apagado]);
        if ((int)$st->fetchColumn() === 0) return;
        // El lote: lo que se guardó junto (un pegado tarda segundos).
        $st = $db->prepare("UPDATE lf_emida_productos SET activo = 1 WHERE activo = 0 AND actualizado >= ?");
        $st->execute([date('Y-m-d H:i:s', strtotime($apagado) - 1800)]);
        error_log('[LibertyFin] migración 23: ' . $st->rowCount() . ' productos de Emida vueltos a encender');
    }

    /**
     * La API: cobros que no se aplican dos veces.
     *
     * Un sistema que manda un abono por la API y no recibe respuesta (se
     * cortó la red, se venció el tiempo) lo vuelve a mandar. Sin esto, el
     * mismo abono entraría dos veces. Con la cabecera Idempotency-Key, el
     * segundo envío recibe la respuesta del primero. Ver src/Api.
     */
    private static function v24(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_api_idempotencia (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                llave_id    INT NOT NULL,
                clave       VARCHAR(100) NOT NULL,
                huella      CHAR(64) NOT NULL,
                estado      VARCHAR(12) NOT NULL DEFAULT 'procesando',
                http_estado SMALLINT NULL,
                respuesta   MEDIUMTEXT NULL,
                creado_en   DATETIME NOT NULL,
                UNIQUE KEY ux_api_idem (llave_id, clave),
                KEY ix_api_idem_creado (creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * La clave SAT del producto (catálogo c_ClaveProdServ, 8 dígitos).
     * Opcional: sin ella el producto se vende igual.
     */
    private static function v25(PDO $db)
    {
        if (self::hayTabla($db, 'productos') && !self::hayColumna($db, 'productos', 'clave_sat')) {
            $db->exec("ALTER TABLE productos ADD COLUMN clave_sat VARCHAR(8) NULL DEFAULT NULL");
        }
    }

    /**
     * Almacenes, impuestos por producto, perecederos y conteos.
     *
     * ALMACENES, Y POR QUÉ NO BASTABA LA SUCURSAL
     *
     * Hasta aquí la existencia vivía por SUCURSAL: una tienda, un número.
     * Pero en el mostrador y en la bodega de esa misma tienda no hay el
     * mismo producto, y la pregunta diaria —"¿hay que bajar más de la
     * bodega?"— no se podía contestar. Así que la existencia pasa a
     * llevarse por ALMACÉN, y cada sucursal nace con dos: Piso y Bodega.
     *
     * `producto_sucursal` NO se retira: queda como la SUMA de los
     * almacenes de esa sucursal, porque medio sistema la lee y cambiarlo
     * todo de golpe es cómo se rompe una venta un viernes. Servicio\
     * Inventario la mantiene al día en cada movimiento.
     *
     * IMPUESTOS POR PRODUCTO
     *
     * El IVA era UNA regla de la empresa entera. Para servicios está
     * bien; para productos no: en la misma tienda conviven el refresco
     * con IVA e IEPS, la papelería con IVA, y la tortilla con tasa cero.
     * Ahora cada producto dice si lleva y a qué tasa; el que no lo diga
     * sigue usando la de la empresa, que es exactamente como se comporta
     * hoy. Por eso `iva_aplica` nace en 1: nada cambia hasta que alguien
     * lo cambie a propósito.
     */
    private static function v26(PDO $db)
    {
        // ── Almacenes ──
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_almacenes (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                sucursal_id  INT NOT NULL,
                nombre       VARCHAR(100) NOT NULL,
                tipo         VARCHAR(20) NOT NULL DEFAULT 'piso',
                es_principal TINYINT(1) NOT NULL DEFAULT 0,
                activo       TINYINT(1) NOT NULL DEFAULT 1,
                creado_en    DATETIME NOT NULL,
                UNIQUE KEY ux_alm_nombre (sucursal_id, nombre),
                KEY ix_alm_suc (sucursal_id, activo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_producto_almacen (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                producto_id  INT NOT NULL,
                almacen_id   INT NOT NULL,
                stock        DECIMAL(12,3) NOT NULL DEFAULT 0,
                stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
                UNIQUE KEY ux_pa (producto_id, almacen_id),
                KEY ix_pa_alm (almacen_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Cada sucursal estrena Piso y Bodega. Piso es el principal: ahí
        // entra lo que no diga a qué almacén va, que es como se comportaba
        // todo hasta ahora.
        if (self::hayTabla($db, 'sucursales')) {
            $sucs = $db->query("SELECT id FROM sucursales")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($sucs as $s) {
                foreach ([['Piso', 'piso', 1], ['Bodega', 'bodega', 0]] as $a) {
                    $st = $db->prepare("
                        INSERT IGNORE INTO lf_almacenes
                            (sucursal_id, nombre, tipo, es_principal, activo, creado_en)
                        VALUES (?,?,?,?,1,NOW())");
                    $st->execute([(int)$s, $a[0], $a[1], $a[2]]);
                }
            }
        }

        // La existencia que ya había se va TODA al piso. Suponer que una
        // parte estaba en bodega sería inventar un dato que nadie capturó;
        // que el encargado la reparta con un traspaso es un minuto y es
        // cierto.
        if (self::hayTabla($db, 'producto_sucursal')) {
            $db->exec("
                INSERT IGNORE INTO lf_producto_almacen (producto_id, almacen_id, stock, stock_minimo)
                SELECT ps.producto_id, a.id, ps.stock, ps.stock_minimo
                  FROM producto_sucursal ps
                  JOIN lf_almacenes a
                    ON a.sucursal_id = ps.sucursal_id AND a.es_principal = 1");
        }

        // ── De qué almacén salió y a cuál entró ──
        foreach (['almacen_id', 'almacen_destino_id'] as $c) {
            if (self::hayTabla($db, 'lf_inventario_movimientos')
                && !self::hayColumna($db, 'lf_inventario_movimientos', $c)) {
                $db->exec("ALTER TABLE lf_inventario_movimientos
                           ADD COLUMN $c INT NULL DEFAULT NULL");
            }
        }

        // ── Impuestos, perecederos y código de barras ──
        if (self::hayTabla($db, 'productos')) {
            $cols = [
                // 1 por omisión: se comporta igual que hoy, con la regla
                // de la empresa. La tasa en NULL quiere decir "la de la
                // empresa"; con número, manda la del producto.
                'iva_aplica'            => "TINYINT(1) NOT NULL DEFAULT 1",
                'iva_tasa'              => "DECIMAL(5,2) NULL DEFAULT NULL",
                'ieps_aplica'           => "TINYINT(1) NOT NULL DEFAULT 0",
                'ieps_tasa'             => "DECIMAL(5,2) NULL DEFAULT NULL",
                'es_perecedero'         => "TINYINT(1) NOT NULL DEFAULT 0",
                'dias_alerta_caducidad' => "SMALLINT NULL DEFAULT NULL",
                // El SKU (`codigo`) lo pone el negocio; el código de
                // barras viene impreso del fabricante. Compartían campo, y
                // por eso al escanear un producto nuevo se chocaba con el
                // SKU de otro.
                'codigo_barras'         => "VARCHAR(64) NULL DEFAULT NULL",
            ];
            foreach ($cols as $c => $tipo) {
                if (!self::hayColumna($db, 'productos', $c)) {
                    $db->exec("ALTER TABLE productos ADD COLUMN `$c` $tipo");
                }
            }
            if (!self::hayIndice($db, 'productos', 'ix_prod_barras')) {
                $db->exec("CREATE INDEX ix_prod_barras ON productos (codigo_barras)");
            }
            // El que ya tiene fecha de caducidad capturada ES perecedero:
            // alguien se tomó la molestia de escribirla.
            $db->exec("UPDATE productos SET es_perecedero = 1
                        WHERE fecha_caducidad IS NOT NULL AND es_perecedero = 0");
        }

        // ── Conteos que se pueden pausar ──
        //
        // `conteoGeneral()` ya existía, pero era de un solo golpe: se
        // mandaba todo contado o no se mandaba nada. Un inventario de
        // tienda se hace en varias sentadas y entre clientes, así que
        // ahora es una sesión con renglones que se van guardando.
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_inventario_conteos (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                sucursal_id  INT NOT NULL,
                almacen_id   INT NULL DEFAULT NULL,
                nombre       VARCHAR(120) NULL DEFAULT NULL,
                alcance      VARCHAR(20) NOT NULL DEFAULT 'general',
                estado       VARCHAR(20) NOT NULL DEFAULT 'abierto',
                usuario_id   INT NULL DEFAULT NULL,
                usuario_nombre VARCHAR(100) NULL DEFAULT NULL,
                nota         VARCHAR(255) NULL DEFAULT NULL,
                creado_en    DATETIME NOT NULL,
                pausado_en   DATETIME NULL DEFAULT NULL,
                cerrado_en   DATETIME NULL DEFAULT NULL,
                KEY ix_cont_suc (sucursal_id, estado),
                KEY ix_cont_alm (almacen_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_inventario_conteo_lineas (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                conteo_id   INT NOT NULL,
                producto_id INT NOT NULL,
                esperado    DECIMAL(12,3) NOT NULL DEFAULT 0,
                contado     DECIMAL(12,3) NULL DEFAULT NULL,
                contado_en  DATETIME NULL DEFAULT NULL,
                usuario_id  INT NULL DEFAULT NULL,
                UNIQUE KEY ux_cl (conteo_id, producto_id),
                KEY ix_cl_conteo (conteo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Subcategorías, y el envío de la cotización por correo.
     *
     * SUBCATEGORÍAS SIN UNA TABLA MÁS
     *
     * `categorias` gana un `padre_id`: una categoría cuyo padre es NULL
     * es de primer nivel, y una con padre es subcategoría de aquella.
     * `productos.categoria_id` sigue apuntando a una sola categoría, que
     * ahora puede ser cualquiera de las dos.
     *
     * La alternativa era una columna `subcategoria_id` en productos. Se
     * descartó porque obliga a que todo el sistema pregunte por las dos
     * —los informes, Caja, las comisiones por área— y basta con que uno
     * se olvide para que un producto desaparezca de un total. Con
     * `padre_id`, lo que ya existe sigue funcionando sin cambiar una
     * línea: las categorías de hoy quedan con padre NULL, que es
     * exactamente lo que eran.
     *
     * EL ENVÍO DE LA COTIZACIÓN
     *
     * Se anota a dónde y cuándo se mandó. Dentro de un mes, «yo sí se la
     * mandé» se contesta con un renglón en vez de con la memoria.
     */
    private static function v27(PDO $db)
    {
        if (self::hayTabla($db, 'categorias') && !self::hayColumna($db, 'categorias', 'padre_id')) {
            $db->exec("ALTER TABLE categorias ADD COLUMN padre_id INT NULL DEFAULT NULL");
            if (!self::hayIndice($db, 'categorias', 'ix_cat_padre')) {
                $db->exec("CREATE INDEX ix_cat_padre ON categorias (padre_id)");
            }
        }

        if (self::hayTabla($db, 'lf_cotizaciones')) {
            foreach (['enviada_a'  => "VARCHAR(160) NULL DEFAULT NULL",
                      'enviada_en' => "DATETIME NULL DEFAULT NULL"] as $c => $tipo) {
                if (!self::hayColumna($db, 'lf_cotizaciones', $c)) {
                    $db->exec("ALTER TABLE lf_cotizaciones ADD COLUMN `$c` $tipo");
                }
            }
        }
    }

    /**
     * 28 · Envíos con Envia.com.
     *
     * DOS TABLAS, Y POR QUÉ
     *
     * `lf_envio_origenes` guarda el «de dónde sale», una por sucursal.
     * Podría haber ido como columnas en `sucursales`, pero el domicilio
     * de la tienda y el domicilio desde el que se despacha no siempre
     * son el mismo —hay quien vende en plaza y manda desde bodega—, y
     * la paquetería necesita el segundo. Además `sucursales` es del
     * esquema viejo y se toca lo menos posible.
     *
     * `lf_envios` guarda cada guía. Lo que llama la atención es que el
     * DESTINO se guarda COPIADO, columna por columna, en vez de apuntar
     * al cliente. Es a propósito y es la decisión importante de esta
     * migración: la guía ya se imprimió y el paquete ya salió hacia una
     * dirección concreta. Si mañana el cliente se muda y corrige su
     * ficha, una pantalla que leyera la dirección actual diría que el
     * paquete fue a la casa nueva, que es mentira. Un envío es un hecho
     * que ocurrió; lo que valía ese día se congela.
     *
     * `venta_id` admite NULL: se puede mandar algo que no salió de una
     * venta —una garantía, una reposición— y eso también es un envío.
     *
     * `paquetes` y `guias` van en JSON de texto. Un envío puede llevar
     * tres bultos y tres números de rastreo, y normalizar eso en dos
     * tablas más no compra nada: nadie va a consultar «todos los bultos
     * de 30 cm», solo se leen junto con su envío.
     */
    private static function v28(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_envio_origenes (
                sucursal_id INT NOT NULL PRIMARY KEY,
                nombre      VARCHAR(100) NULL DEFAULT NULL,
                empresa     VARCHAR(100) NULL DEFAULT NULL,
                telefono    VARCHAR(30)  NULL DEFAULT NULL,
                email       VARCHAR(120) NULL DEFAULT NULL,
                calle       VARCHAR(160) NULL DEFAULT NULL,
                numero      VARCHAR(30)  NULL DEFAULT NULL,
                colonia     VARCHAR(120) NULL DEFAULT NULL,
                ciudad      VARCHAR(120) NULL DEFAULT NULL,
                estado      VARCHAR(5)   NULL DEFAULT NULL,
                cp          VARCHAR(10)  NULL DEFAULT NULL,
                pais        VARCHAR(2)   NOT NULL DEFAULT 'MX',
                referencia  VARCHAR(160) NULL DEFAULT NULL,
                actualizado DATETIME NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_envios (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                venta_id        INT NULL DEFAULT NULL,
                sucursal_id     INT NULL DEFAULT NULL,
                estado          VARCHAR(20) NOT NULL DEFAULT 'generado',
                carrier         VARCHAR(40)  NULL DEFAULT NULL,
                servicio        VARCHAR(60)  NULL DEFAULT NULL,
                servicio_desc   VARCHAR(120) NULL DEFAULT NULL,
                guia            VARCHAR(80)  NULL DEFAULT NULL,
                guias           TEXT NULL,
                envia_envio_id  VARCHAR(60)  NULL DEFAULT NULL,
                etiqueta_url    VARCHAR(500) NULL DEFAULT NULL,
                rastreo_url     VARCHAR(500) NULL DEFAULT NULL,
                costo           DECIMAL(10,2) NOT NULL DEFAULT 0,
                moneda          VARCHAR(5) NOT NULL DEFAULT 'MXN',
                dest_nombre     VARCHAR(120) NULL DEFAULT NULL,
                dest_telefono   VARCHAR(30)  NULL DEFAULT NULL,
                dest_email      VARCHAR(120) NULL DEFAULT NULL,
                dest_calle      VARCHAR(160) NULL DEFAULT NULL,
                dest_numero     VARCHAR(30)  NULL DEFAULT NULL,
                dest_colonia    VARCHAR(120) NULL DEFAULT NULL,
                dest_ciudad     VARCHAR(120) NULL DEFAULT NULL,
                dest_estado     VARCHAR(5)   NULL DEFAULT NULL,
                dest_cp         VARCHAR(10)  NULL DEFAULT NULL,
                dest_pais       VARCHAR(2)   NOT NULL DEFAULT 'MX',
                dest_referencia VARCHAR(160) NULL DEFAULT NULL,
                paquetes        TEXT NULL,
                ultimo_estado   VARCHAR(160) NULL DEFAULT NULL,
                rastreado_en    DATETIME NULL DEFAULT NULL,
                nota            VARCHAR(255) NULL DEFAULT NULL,
                usuario_id      INT NULL DEFAULT NULL,
                usuario_nombre  VARCHAR(100) NULL DEFAULT NULL,
                creado_en       DATETIME NOT NULL,
                generado_en     DATETIME NULL DEFAULT NULL,
                cancelado_en    DATETIME NULL DEFAULT NULL,
                KEY ix_env_venta (venta_id),
                KEY ix_env_estado (estado, creado_en),
                KEY ix_env_guia (guia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // El origen de cada sucursal se deja creado con lo que ya se
        // sabe —nombre y teléfono—. El domicilio lo captura el
        // encargado: `sucursales.direccion` es un texto libre y partirlo
        // en calle, colonia y CP a la adivina produciría guías mal
        // hechas, que es peor que una pantalla pidiendo los datos.
        if (self::hayTabla($db, 'sucursales')) {
            $db->exec("
                INSERT IGNORE INTO lf_envio_origenes (sucursal_id, nombre, telefono, email, pais)
                SELECT s.id, LEFT(s.nombre, 100), LEFT(COALESCE(s.telefono,''), 30),
                       LEFT(COALESCE(s.email,''), 120), 'MX'
                  FROM sucursales s");
        }
    }

    /**
     * 29 · Capturas de entrada y salida que se pueden pausar.
     *
     * POR QUÉ NO SE APLICA AL ESCANEAR
     *
     * Quien recibe mercancía pasa veinte cajas con la pistola, y a la
     * mitad tiene que ir a cobrarle a alguien. Si cada escaneo moviera
     * la existencia al instante, una captura abandonada a la mitad
     * dejaría el inventario con diez cajas que nadie terminó de
     * verificar, y nadie sabría que quedó a medias.
     *
     * Así que lo escaneado se guarda aquí, aparte, sin tocar nada. La
     * existencia se mueve de una sola vez al finalizar, y una captura
     * que se abandona no deja rastro en el inventario.
     */
    private static function v29(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_inventario_capturas (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                sucursal_id    INT NOT NULL,
                almacen_id     INT NULL DEFAULT NULL,
                tipo           VARCHAR(20) NOT NULL DEFAULT 'entrada',
                estado         VARCHAR(20) NOT NULL DEFAULT 'abierta',
                motivo         VARCHAR(20)  NULL DEFAULT NULL,
                nota           VARCHAR(255) NULL DEFAULT NULL,
                usuario_id     INT NULL DEFAULT NULL,
                usuario_nombre VARCHAR(100) NULL DEFAULT NULL,
                creado_en      DATETIME NOT NULL,
                pausado_en     DATETIME NULL DEFAULT NULL,
                cerrado_en     DATETIME NULL DEFAULT NULL,
                KEY ix_cap_suc (sucursal_id, estado),
                KEY ix_cap_user (usuario_id, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_inventario_captura_lineas (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                captura_id  INT NOT NULL,
                producto_id INT NOT NULL,
                cantidad    DECIMAL(12,3) NOT NULL DEFAULT 0,
                costo       DECIMAL(12,2) NULL DEFAULT NULL,
                agregado_en DATETIME NOT NULL,
                UNIQUE KEY ux_capl (captura_id, producto_id),
                KEY ix_capl (captura_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 30 · Mercado Libre: la cuenta de vendedor de la empresa, sus
     * publicaciones vinculadas a productos y las órdenes que ya movieron
     * existencias.
     *
     * LAS LLAVES VAN AQUÍ, Y CIFRADAS
     *
     * La regla de la casa es que las credenciales no viven en la base. Las
     * de la APLICACIÓN de Mercado Libre se respetan: van en
     * config/integraciones.php. Pero las de la CUENTA son de cada empresa
     * y las emite Mercado Libre al conectar; no hay archivo por empresa
     * donde ponerlas. Así que van aquí, cifradas con una llave que sí vive
     * en el archivo (ver MercadoLibre::cifrar).
     *
     * `meli_user_id` es VARCHAR y no BIGINT: es un id ajeno, y uno ajeno se
     * guarda como texto para no depender de que siempre quepa en un número.
     *
     * `lf_meli_ordenes.aplicado` guarda LO QUE SE DESCONTÓ, no las líneas
     * de la orden. Si se cancela, regresa justo eso, aunque mientras tanto
     * alguien haya cambiado el vínculo de la publicación.
     */
    private static function v30(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_meli_cuenta (
                id                 TINYINT NOT NULL PRIMARY KEY,
                meli_user_id       VARCHAR(20)  NULL DEFAULT NULL,
                nickname           VARCHAR(100) NULL DEFAULT NULL,
                site_id            VARCHAR(5)   NULL DEFAULT NULL,
                access_token       TEXT NULL,
                refresh_token      TEXT NULL,
                expira_en          DATETIME NULL DEFAULT NULL,
                estado             VARCHAR(20) NOT NULL DEFAULT 'desconectada',
                sucursal_id        INT NULL DEFAULT NULL,
                reserva            DECIMAL(12,3) NOT NULL DEFAULT 0,
                enviar_existencias TINYINT(1) NOT NULL DEFAULT 0,
                descontar_ventas   TINYINT(1) NOT NULL DEFAULT 1,
                conectado_por      VARCHAR(100) NULL DEFAULT NULL,
                conectado_en       DATETIME NULL DEFAULT NULL,
                actualizado_en     DATETIME NULL DEFAULT NULL,
                sincronizado_en    DATETIME NULL DEFAULT NULL,
                ordenes_desde      DATETIME NULL DEFAULT NULL,
                ultimo_error       VARCHAR(255) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_meli_publicaciones (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                item_id        VARCHAR(20) NOT NULL,
                variacion_id   BIGINT NOT NULL DEFAULT 0,
                titulo         VARCHAR(255) NULL DEFAULT NULL,
                variacion      VARCHAR(160) NULL DEFAULT NULL,
                sku            VARCHAR(100) NULL DEFAULT NULL,
                estado_ml      VARCHAR(20)  NULL DEFAULT NULL,
                logistica      VARCHAR(30)  NULL DEFAULT NULL,
                permalink      VARCHAR(255) NULL DEFAULT NULL,
                imagen         VARCHAR(255) NULL DEFAULT NULL,
                precio         DECIMAL(12,2) NOT NULL DEFAULT 0,
                stock_ml       INT NOT NULL DEFAULT 0,
                producto_id    INT NULL DEFAULT NULL,
                vinculo        VARCHAR(10) NULL DEFAULT NULL,
                pendiente      TINYINT(1) NOT NULL DEFAULT 0,
                enviado_stock  INT NULL DEFAULT NULL,
                enviado_en     DATETIME NULL DEFAULT NULL,
                error          VARCHAR(255) NULL DEFAULT NULL,
                actualizado_en DATETIME NULL DEFAULT NULL,
                UNIQUE KEY ux_mp_item (item_id, variacion_id),
                KEY ix_mp_producto (producto_id),
                KEY ix_mp_pendiente (pendiente, item_id),
                KEY ix_mp_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_meli_ordenes (
                orden_id       VARCHAR(24) NOT NULL PRIMARY KEY,
                estado         VARCHAR(20) NOT NULL DEFAULT 'nueva',
                estado_ml      VARCHAR(30) NULL DEFAULT NULL,
                comprador      VARCHAR(120) NULL DEFAULT NULL,
                total          DECIMAL(12,2) NOT NULL DEFAULT 0,
                fecha_ml       DATETIME NULL DEFAULT NULL,
                aplicado       TEXT NULL,
                nota           VARCHAR(255) NULL DEFAULT NULL,
                creado_en      DATETIME NOT NULL,
                actualizado_en DATETIME NULL DEFAULT NULL,
                KEY ix_mo_fecha (fecha_ml)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // `tipo` medía 12 y «mercadolibre» mide justo 12. Se agranda para
        // que el siguiente tipo que llegue no se corte en silencio.
        if (self::hayTabla($db, 'lf_inventario_movimientos')) {
            $db->exec("ALTER TABLE lf_inventario_movimientos MODIFY tipo VARCHAR(20) NOT NULL");
        }
    }

    /**
     * 31 · Tienda Nube: la tienda en línea de la empresa, cada variante de
     * sus productos vinculada a un producto, y las órdenes que ya movieron
     * existencias. Mismo diseño que la 30 (Mercado Libre).
     *
     * La llave de la tienda va cifrada (TiendaNube::cifrar) y no caduca, por
     * eso no hay refresh_token ni expira_en. `stock_tn` NULL = la variante
     * no lleva control de existencias en Tienda Nube («sin límite»).
     */
    private static function v31(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_tn_cuenta (
                id                 TINYINT NOT NULL PRIMARY KEY,
                store_id           VARCHAR(20)  NULL DEFAULT NULL,
                nombre             VARCHAR(120) NULL DEFAULT NULL,
                url                VARCHAR(255) NULL DEFAULT NULL,
                idioma             VARCHAR(5)   NOT NULL DEFAULT 'es',
                moneda             VARCHAR(3)   NULL DEFAULT NULL,
                access_token       TEXT NULL,
                estado             VARCHAR(20) NOT NULL DEFAULT 'desconectada',
                sucursal_id        INT NULL DEFAULT NULL,
                reserva            DECIMAL(12,3) NOT NULL DEFAULT 0,
                enviar_existencias TINYINT(1) NOT NULL DEFAULT 0,
                descontar_ventas   TINYINT(1) NOT NULL DEFAULT 1,
                avisos_ok          TINYINT(1) NOT NULL DEFAULT 0,
                avisos_nota        VARCHAR(255) NULL DEFAULT NULL,
                conectado_por      VARCHAR(100) NULL DEFAULT NULL,
                conectado_en       DATETIME NULL DEFAULT NULL,
                actualizado_en     DATETIME NULL DEFAULT NULL,
                sincronizado_en    DATETIME NULL DEFAULT NULL,
                ordenes_desde      DATETIME NULL DEFAULT NULL,
                ultimo_error       VARCHAR(255) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_tn_productos (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                producto_tn    VARCHAR(20) NOT NULL,
                variante_tn    VARCHAR(20) NOT NULL,
                titulo         VARCHAR(255) NULL DEFAULT NULL,
                variante       VARCHAR(160) NULL DEFAULT NULL,
                sku            VARCHAR(100) NULL DEFAULT NULL,
                estado_tn      VARCHAR(20)  NULL DEFAULT NULL,
                permalink      VARCHAR(255) NULL DEFAULT NULL,
                imagen         VARCHAR(255) NULL DEFAULT NULL,
                precio         DECIMAL(12,2) NOT NULL DEFAULT 0,
                stock_tn       INT NULL DEFAULT NULL,
                producto_id    INT NULL DEFAULT NULL,
                vinculo        VARCHAR(10) NULL DEFAULT NULL,
                pendiente      TINYINT(1) NOT NULL DEFAULT 0,
                enviado_stock  INT NULL DEFAULT NULL,
                enviado_en     DATETIME NULL DEFAULT NULL,
                error          VARCHAR(255) NULL DEFAULT NULL,
                actualizado_en DATETIME NULL DEFAULT NULL,
                UNIQUE KEY ux_tp_variante (variante_tn),
                KEY ix_tp_producto_tn (producto_tn),
                KEY ix_tp_producto (producto_id),
                KEY ix_tp_pendiente (pendiente),
                KEY ix_tp_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_tn_ordenes (
                orden_id       VARCHAR(24) NOT NULL PRIMARY KEY,
                numero         VARCHAR(20) NULL DEFAULT NULL,
                estado         VARCHAR(20) NOT NULL DEFAULT 'nueva',
                estado_tn      VARCHAR(40) NULL DEFAULT NULL,
                comprador      VARCHAR(120) NULL DEFAULT NULL,
                total          DECIMAL(12,2) NOT NULL DEFAULT 0,
                fecha_tn       DATETIME NULL DEFAULT NULL,
                aplicado       TEXT NULL,
                nota           VARCHAR(255) NULL DEFAULT NULL,
                creado_en      DATETIME NOT NULL,
                actualizado_en DATETIME NULL DEFAULT NULL,
                KEY ix_to_fecha (fecha_tn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Lo que hace cada versión, para mostrarlo en Mantenimiento. */
    const DESCRIPCIONES = [
        1 => 'Tabla de ajustes propia (lf_ajustes)',
        2 => 'Área del cliente',
        3 => 'Foto de perfil e imagen del servicio',
        4 => 'Índices de rendimiento',
        5 => 'Marca de guía de primer uso',
        6 => 'Roles nuevos en la columna usuarios.rol',
        7 => 'Bitácora de cambios (lf_auditoria)',
        8 => 'Catálogo y transacciones de Emida',
        9 => 'Ligas de pago (tarjeta, SPEI, tiendas)',
        10 => 'Especialista asignado a la venta',
        11 => 'Avisos de pago de Paga de Todo (transacción, autorización, comprobante)',
        12 => 'Subfolios de cobro y servicios agregados a una venta existente',
        13 => 'Detalle de las operaciones en lote en la bitácora',
        14 => 'Banco del pago con tarjeta o SPEI',
        15 => 'Datos fiscales del comprobante y domicilio/datos fiscales del cliente',
        17 => 'Porcentajes de comisión con hasta 4 decimales (33.333)',
        18 => 'Historial de «Recalcular comisiones», para poder deshacerlo',
        19 => 'Promociones: descuentos, precios especiales, paquetes y códigos',
        20 => 'Columnas que les faltaban a las empresas nuevas (área e IVA de la venta, complemento de pago)',
        21 => 'Inventario: productos con existencias y bitácora de movimientos',
        22 => 'Módulos por giro: cancelar ventas, expediente, cotizaciones, igualas, seguimiento, compras, agenda y paquetes',
        23 => 'Recupera los productos de Emida que apagó la descarga automática del catálogo',
        24 => 'API: cobros por la API que no se aplican dos veces (Idempotency-Key)',
        25 => 'Clave SAT del producto',
        26 => 'Almacenes (piso y bodega) con existencia propia, IVA e IEPS por producto, perecederos, código de barras aparte del SKU y conteos que se pueden pausar',
        27 => 'Subcategorías de producto (categorías con padre) y registro del envío de cotizaciones por correo',
        28 => 'Envíos con Envia.com: dirección de salida por sucursal y guías con rastreo ligadas a la venta',
        29 => 'Capturas de entrada y salida con pistola de código de barras, que se pueden pausar y retomar',
        30 => 'Mercado Libre: cuenta de vendedor por empresa, publicaciones vinculadas a productos y órdenes que descuentan existencias',
        31 => 'Tienda Nube: tienda en línea por empresa, variantes vinculadas a productos y órdenes que descuentan existencias',
    ];
}