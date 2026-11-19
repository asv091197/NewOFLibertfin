-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 34
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
--  ANTES corre actualizar-v33.sql si la base no estaba en la 33.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v34 · Preventa ───────────────────────────────────────────────────
-- Las columnas del producto. MySQL no tiene «ADD COLUMN IF NOT EXISTS»,
-- así que cada una se agrega solo si falta.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'preventa');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN preventa TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'preventa_llegada');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN preventa_llegada DATE NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'preventa_limite');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN preventa_limite DECIMAL(12,3) NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'preventa_anticipo');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN preventa_anticipo DECIMAL(5,2) NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- Cada pieza prevendida y si ya se entregó.
CREATE TABLE IF NOT EXISTS lf_preventas (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    venta_id         INT NOT NULL,
    producto_id      INT NOT NULL,
    sucursal_id      INT NULL DEFAULT NULL,
    cliente_id       INT NULL DEFAULT NULL,
    cantidad         DECIMAL(12,3) NOT NULL,
    estado           VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    llegada_estimada DATE NULL DEFAULT NULL,
    creado_en        DATETIME NOT NULL,
    entregado_en     DATETIME NULL DEFAULT NULL,
    entregado_por    INT NULL DEFAULT NULL,
    KEY ix_pv_producto (producto_id, estado),
    KEY ix_pv_venta (venta_id),
    KEY ix_pv_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 34 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '34', NOW())
ON DUPLICATE KEY UPDATE valor = '34', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
