-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 30
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
--  ANTES corre actualizar-v29.sql si la base no estaba en la 29.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v30 · Mercado Libre ──────────────────────────────────────────────
-- La cuenta de vendedor de la empresa. Un solo renglón (id = 1). Las
-- llaves van CIFRADAS con la llave de config/integraciones.php: un
-- respaldo de la base no basta para usar la cuenta.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada publicación —o cada variación— y con qué producto se cuenta.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada orden que ya movió existencias: lo que impide descontar dos veces
-- cuando el aviso de Mercado Libre llega repetido.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El tipo de movimiento «mercadolibre» mide 12 letras, justo el tope
-- anterior. Se agranda para el que siga.
ALTER TABLE lf_inventario_movimientos MODIFY tipo VARCHAR(20) NOT NULL;


-- ── Y se deja marcado que la base ya va en la 30 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '30', NOW())
ON DUPLICATE KEY UPDATE valor = '30', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
