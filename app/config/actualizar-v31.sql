-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 31
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
--  ANTES corre actualizar-v30.sql si la base no estaba en la 30.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v31 · Tienda Nube ────────────────────────────────────────────────
-- La tienda de la empresa. Un solo renglón (id = 1). La llave va CIFRADA
-- con la llave de config/integraciones.php y no caduca.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada variante de cada producto de la tienda, y con qué producto se cuenta.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada orden que ya movió existencias: lo que impide descontar dos veces
-- cuando el aviso de Tienda Nube llega repetido.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 31 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '31', NOW())
ON DUPLICATE KEY UPDATE valor = '31', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
