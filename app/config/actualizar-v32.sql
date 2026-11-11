-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 32
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
--  ANTES corre actualizar-v31.sql si la base no estaba en la 31.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v32 · Shopify ────────────────────────────────────────────────────
-- La tienda de la empresa. Un solo renglón (id = 1). La llave va CIFRADA
-- con la llave de config/integraciones.php. `refresh_token` y `expira_en`
-- solo se usan si Shopify entrega una llave que caduca.
CREATE TABLE IF NOT EXISTS lf_shop_cuenta (
    id                 TINYINT NOT NULL PRIMARY KEY,
    tienda             VARCHAR(120) NULL DEFAULT NULL,
    nombre             VARCHAR(120) NULL DEFAULT NULL,
    url                VARCHAR(255) NULL DEFAULT NULL,
    moneda             VARCHAR(3)   NULL DEFAULT NULL,
    access_token       TEXT NULL,
    refresh_token      TEXT NULL,
    expira_en          DATETIME NULL DEFAULT NULL,
    estado             VARCHAR(20) NOT NULL DEFAULT 'desconectada',
    location_id        VARCHAR(24)  NULL DEFAULT NULL,
    location_nombre    VARCHAR(120) NULL DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada variante de cada producto de la tienda, su «inventory item» (donde
-- vive la existencia en Shopify) y con qué producto se cuenta.
CREATE TABLE IF NOT EXISTS lf_shop_productos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    producto_shop   VARCHAR(24) NOT NULL,
    variante_shop   VARCHAR(24) NOT NULL,
    inventario_shop VARCHAR(24) NULL DEFAULT NULL,
    titulo          VARCHAR(255) NULL DEFAULT NULL,
    variante        VARCHAR(160) NULL DEFAULT NULL,
    sku             VARCHAR(100) NULL DEFAULT NULL,
    estado_shop     VARCHAR(20)  NULL DEFAULT NULL,
    permalink       VARCHAR(255) NULL DEFAULT NULL,
    imagen          VARCHAR(255) NULL DEFAULT NULL,
    precio          DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_shop      INT NULL DEFAULT NULL,
    producto_id     INT NULL DEFAULT NULL,
    vinculo         VARCHAR(10) NULL DEFAULT NULL,
    pendiente       TINYINT(1) NOT NULL DEFAULT 0,
    enviado_stock   INT NULL DEFAULT NULL,
    enviado_en      DATETIME NULL DEFAULT NULL,
    error           VARCHAR(255) NULL DEFAULT NULL,
    actualizado_en  DATETIME NULL DEFAULT NULL,
    UNIQUE KEY ux_sp_variante (variante_shop),
    KEY ix_sp_producto_shop (producto_shop),
    KEY ix_sp_producto (producto_id),
    KEY ix_sp_pendiente (pendiente),
    KEY ix_sp_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada orden que ya movió existencias: lo que impide descontar dos veces
-- cuando el aviso de Shopify llega repetido.
CREATE TABLE IF NOT EXISTS lf_shop_ordenes (
    orden_id       VARCHAR(24) NOT NULL PRIMARY KEY,
    numero         VARCHAR(20) NULL DEFAULT NULL,
    estado         VARCHAR(20) NOT NULL DEFAULT 'nueva',
    estado_shop    VARCHAR(40) NULL DEFAULT NULL,
    comprador      VARCHAR(120) NULL DEFAULT NULL,
    total          DECIMAL(12,2) NOT NULL DEFAULT 0,
    fecha_shop     DATETIME NULL DEFAULT NULL,
    aplicado       TEXT NULL,
    nota           VARCHAR(255) NULL DEFAULT NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NULL DEFAULT NULL,
    KEY ix_so_fecha (fecha_shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 32 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '32', NOW())
ON DUPLICATE KEY UPDATE valor = '32', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
