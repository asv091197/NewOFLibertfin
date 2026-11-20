-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 35
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
--  ANTES corre actualizar-v34.sql si la base no estaba en la 34.
--
--  SE PUEDE CORRER DOS VECES sin romper nada. NO BORRA NADA.
--
--  Al terminar, sal del sistema y vuelve a entrar.
-- ════════════════════════════════════════════════════════════════════


-- ── v35 · Movimientos que se leen solos ──────────────────────────────
-- Lo que había en el almacén antes, el total de la sucursal después y el
-- documento (traspaso, captura, conteo) al que pertenece cada renglón.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lf_inventario_movimientos' AND COLUMN_NAME = 'anterior');
SET @s := IF(@c = 0, 'ALTER TABLE lf_inventario_movimientos ADD COLUMN anterior DECIMAL(12,3) NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lf_inventario_movimientos' AND COLUMN_NAME = 'existencia_sucursal');
SET @s := IF(@c = 0, 'ALTER TABLE lf_inventario_movimientos ADD COLUMN existencia_sucursal DECIMAL(12,3) NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lf_inventario_movimientos' AND COLUMN_NAME = 'lote');
SET @s := IF(@c = 0, 'ALTER TABLE lf_inventario_movimientos ADD COLUMN lote VARCHAR(40) NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lf_inventario_movimientos' AND INDEX_NAME = 'ix_im_lote');
SET @s := IF(@c = 0, 'CREATE INDEX ix_im_lote ON lf_inventario_movimientos (lote)', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;


-- ── v35 · Productos: agotado a mano y borrado ────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'agotado');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN agotado TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'eliminado_en');
SET @s := IF(@c = 0, 'ALTER TABLE productos ADD COLUMN eliminado_en DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;


-- ── v35 · Etiquetas y atributos del producto (texto y color) ─────────
CREATE TABLE IF NOT EXISTS lf_producto_atributos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    nombre      VARCHAR(60)  NOT NULL,
    valor       VARCHAR(120) NULL DEFAULT NULL,
    color       VARCHAR(7)   NULL DEFAULT NULL,
    orden       SMALLINT NOT NULL DEFAULT 0,
    KEY ix_patr_prod (producto_id),
    KEY ix_patr_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 35 ─────────────────────
INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '35', NOW())
ON DUPLICATE KEY UPDATE valor = '35', actualizado = NOW();

SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';
