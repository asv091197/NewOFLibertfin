-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 33
-- ════════════════════════════════════════════════════════════════════
--
--  CÓMO SE USA
--
--  Normalmente NO hace falta: el sistema aplica la migración solo al
--  entrar. Esto es para cuando no se puede (o para poner al día una base
--  que nadie ha abierto).
--
--  cPanel → phpMyAdmin → elige la base de la empresa → pestaña SQL →
--  pega TODO esto → Continuar. Una vez por empresa.
--
--  ANTES corre actualizar-v32.sql si la base no estaba en la 32.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v33 · TikTok Shop ────────────────────────────────────────────────
-- La tienda de la empresa. Un solo renglón (id = 1). La llave, el
-- refresh_token y el app_secret propio van CIFRADOS con la llave de
-- config/integraciones.php. app_key / app_secret / service_id vacíos =
-- se usa la aplicación pública de LibertyFin; llenos = la aplicación
-- «Seller in-house» de esta empresa.
CREATE TABLE IF NOT EXISTS lf_tt_cuenta (
    id                 TINYINT NOT NULL PRIMARY KEY,
    shop_id            VARCHAR(32)  NULL DEFAULT NULL,
    shop_cipher        VARCHAR(128) NULL DEFAULT NULL,
    nombre             VARCHAR(120) NULL DEFAULT NULL,
    region             VARCHAR(8)   NULL DEFAULT NULL,
    vendedor           VARCHAR(120) NULL DEFAULT NULL,
    app_key            VARCHAR(64)  NULL DEFAULT NULL,
    app_secret         TEXT NULL,
    service_id         VARCHAR(64)  NULL DEFAULT NULL,
    access_token       TEXT NULL,
    refresh_token      TEXT NULL,
    expira_en          DATETIME NULL DEFAULT NULL,
    refresh_expira_en  DATETIME NULL DEFAULT NULL,
    estado             VARCHAR(20) NOT NULL DEFAULT 'desconectada',
    almacen_id         VARCHAR(32)  NULL DEFAULT NULL,
    almacen_nombre     VARCHAR(120) NULL DEFAULT NULL,
    sucursal_id        INT NULL DEFAULT NULL,
    reserva            DECIMAL(12,3) NOT NULL DEFAULT 0,
    enviar_existencias TINYINT(1) NOT NULL DEFAULT 0,
    descontar_ventas   TINYINT(1) NOT NULL DEFAULT 1,
    aviso_en           DATETIME NULL DEFAULT NULL,
    conectado_por      VARCHAR(100) NULL DEFAULT NULL,
    conectado_en       DATETIME NULL DEFAULT NULL,
    actualizado_en     DATETIME NULL DEFAULT NULL,
    sincronizado_en    DATETIME NULL DEFAULT NULL,
    ordenes_desde      DATETIME NULL DEFAULT NULL,
    ultimo_error       VARCHAR(255) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada SKU de cada producto de la tienda y con qué producto se cuenta.
CREATE TABLE IF NOT EXISTS lf_tt_productos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    producto_tt     VARCHAR(32) NOT NULL,
    sku_tt          VARCHAR(32) NOT NULL,
    titulo          VARCHAR(255) NULL DEFAULT NULL,
    variante        VARCHAR(160) NULL DEFAULT NULL,
    sku             VARCHAR(100) NULL DEFAULT NULL,
    estado_tt       VARCHAR(20)  NULL DEFAULT NULL,
    imagen          VARCHAR(255) NULL DEFAULT NULL,
    precio          DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_tt        INT NULL DEFAULT NULL,
    producto_id     INT NULL DEFAULT NULL,
    vinculo         VARCHAR(10) NULL DEFAULT NULL,
    pendiente       TINYINT(1) NOT NULL DEFAULT 0,
    enviado_stock   INT NULL DEFAULT NULL,
    enviado_en      DATETIME NULL DEFAULT NULL,
    error           VARCHAR(255) NULL DEFAULT NULL,
    actualizado_en  DATETIME NULL DEFAULT NULL,
    UNIQUE KEY ux_tp_sku_tt (sku_tt),
    KEY ix_tp_producto_tt (producto_tt),
    KEY ix_tp_producto (producto_id),
    KEY ix_tp_pendiente (pendiente),
    KEY ix_tp_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada orden que ya movió existencias: lo que impide descontar dos veces
-- cuando el aviso de TikTok llega repetido.
CREATE TABLE IF NOT EXISTS lf_tt_ordenes (
    orden_id       VARCHAR(32) NOT NULL PRIMARY KEY,
    estado         VARCHAR(20) NOT NULL DEFAULT 'nueva',
    estado_tt      VARCHAR(40) NULL DEFAULT NULL,
    total          DECIMAL(12,2) NOT NULL DEFAULT 0,
    fecha_tt       DATETIME NULL DEFAULT NULL,
    aplicado       TEXT NULL,
    nota           VARCHAR(255) NULL DEFAULT NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NULL DEFAULT NULL,
    KEY ix_to_fecha (fecha_tt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 33 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '33', NOW())
ON DUPLICATE KEY UPDATE valor = '33', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
