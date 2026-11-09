-- ════════════════════════════════════════════════════════════════════
--  LibertyFin · poner la base de UNA empresa en la versión 29
-- ════════════════════════════════════════════════════════════════════
--
--  CÓMO SE USA
--
--  cPanel → phpMyAdmin → elige la base de la empresa (p. ej.
--  juanc141_grupo_ideas_mx) → pestaña SQL → pega TODO esto → Continuar.
--
--  Hay que correrlo UNA VEZ POR EMPRESA.
--
--  ESTE ARCHIVO TRAE TODO desde la v26: almacenes, impuestos por
--  producto, perecederos, conteos, subcategorías, envío de cotizaciones
--  Y los envíos nuevos. Si ya corriste actualizar-v27.sql, no pasa nada:
--  vuelve a correr este completo. Todo lleva IF NOT EXISTS y lo que ya
--  esté se salta solo.
--
--  SE PUEDE CORRER DOS VECES sin romper nada.
--  Si alguna línea avisa «Duplicate column», esa parte ya estaba.
--
--  ESTO NO BORRA NADA. Solo agrega tablas y columnas.
--
--  Al terminar, sal del sistema y vuelve a entrar: tu sesión trae
--  guardada la versión vieja del esquema.
--
--  Si tu servidor es MySQL y no MariaDB, `ADD COLUMN IF NOT EXISTS` no
--  existe: quita los `IF NOT EXISTS` de los ALTER y corre de nuevo;
--  las que ya estén dirán «Duplicate column» y se ignoran.
-- ════════════════════════════════════════════════════════════════════


-- ── v26 · Almacenes ──────────────────────────────────────────────────
-- Hasta aquí la existencia era un número por tienda. Ahora se lleva por
-- ALMACÉN, y cada sucursal nace con dos: Piso y Bodega.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lf_producto_almacen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    producto_id  INT NOT NULL,
    almacen_id   INT NOT NULL,
    stock        DECIMAL(12,3) NOT NULL DEFAULT 0,
    stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
    UNIQUE KEY ux_pa (producto_id, almacen_id),
    KEY ix_pa_alm (almacen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada sucursal estrena Piso (el principal) y Bodega.
INSERT IGNORE INTO lf_almacenes (sucursal_id, nombre, tipo, es_principal, activo, creado_en)
SELECT s.id, 'Piso', 'piso', 1, 1, NOW() FROM sucursales s;

INSERT IGNORE INTO lf_almacenes (sucursal_id, nombre, tipo, es_principal, activo, creado_en)
SELECT s.id, 'Bodega', 'bodega', 0, 1, NOW() FROM sucursales s;

-- La existencia que ya había se va TODA al piso. Suponer que una parte
-- estaba en bodega sería inventar un dato que nadie capturó; que el
-- encargado la reparta con un traspaso es un minuto y es cierto.
INSERT IGNORE INTO lf_producto_almacen (producto_id, almacen_id, stock, stock_minimo)
SELECT ps.producto_id, a.id, ps.stock, ps.stock_minimo
  FROM producto_sucursal ps
  JOIN lf_almacenes a ON a.sucursal_id = ps.sucursal_id AND a.es_principal = 1;


-- ── v26 · De qué almacén salió y a cuál entró ────────────────────────
ALTER TABLE lf_inventario_movimientos
    ADD COLUMN IF NOT EXISTS almacen_id         INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS almacen_destino_id INT NULL DEFAULT NULL;


-- ── v26 · Impuestos, perecederos y código de barras ──────────────────
-- `iva_aplica` nace en 1: se comporta igual que hoy, con la regla de la
-- empresa. La tasa en NULL quiere decir «la de la empresa»; con número,
-- manda la del producto. El código de barras va APARTE del SKU: el SKU
-- lo pone el negocio, el de barras viene del fabricante.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS iva_aplica            TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS iva_tasa              DECIMAL(5,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ieps_aplica           TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ieps_tasa             DECIMAL(5,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS es_perecedero         TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS dias_alerta_caducidad SMALLINT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS codigo_barras         VARCHAR(64) NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS ix_prod_barras ON productos (codigo_barras);

-- El que ya tiene fecha de caducidad capturada ES perecedero: alguien se
-- tomó la molestia de escribirla.
UPDATE productos SET es_perecedero = 1
 WHERE fecha_caducidad IS NOT NULL AND es_perecedero = 0;


-- ── v26 · Conteos que se pueden pausar ───────────────────────────────
CREATE TABLE IF NOT EXISTS lf_inventario_conteos (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id    INT NOT NULL,
    almacen_id     INT NULL DEFAULT NULL,
    nombre         VARCHAR(120) NULL DEFAULT NULL,
    alcance        VARCHAR(20) NOT NULL DEFAULT 'general',
    estado         VARCHAR(20) NOT NULL DEFAULT 'abierto',
    usuario_id     INT NULL DEFAULT NULL,
    usuario_nombre VARCHAR(100) NULL DEFAULT NULL,
    nota           VARCHAR(255) NULL DEFAULT NULL,
    creado_en      DATETIME NOT NULL,
    pausado_en     DATETIME NULL DEFAULT NULL,
    cerrado_en     DATETIME NULL DEFAULT NULL,
    KEY ix_cont_suc (sucursal_id, estado),
    KEY ix_cont_alm (almacen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── v27 · Subcategorías y envío de cotizaciones ──────────────────────
-- Una categoría sin padre es de primer nivel; con padre, es subcategoría
-- de aquella. Las que ya existen quedan con padre NULL, que es
-- exactamente lo que eran.
ALTER TABLE categorias
    ADD COLUMN IF NOT EXISTS padre_id INT NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS ix_cat_padre ON categorias (padre_id);

ALTER TABLE lf_cotizaciones
    ADD COLUMN IF NOT EXISTS enviada_a  VARCHAR(160) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS enviada_en DATETIME NULL DEFAULT NULL;


-- ── v28 · Envíos con Envia.com ───────────────────────────────────────
-- De dónde sale cada envío. Una por sucursal. No se saca de
-- `sucursales.direccion` porque ése es un texto libre y la paquetería
-- necesita saber cuál pedazo es la colonia y cuál el CP.
--
-- `estado` lleva la clave de DOS LETRAS de Envia, que es suya y no
-- coincide con la del SAT ni con la ISO: la Ciudad de México es CX. El
-- sistema la llena sola a partir del código postal.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada guía. El DESTINO se guarda copiado, columna por columna, en vez
-- de apuntar al cliente: la guía ya se imprimió y el paquete ya salió
-- hacia una dirección concreta. Si mañana el cliente se muda y corrige
-- su ficha, el envío tiene que seguir diciendo a dónde fue de verdad.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Se deja el renglón de cada sucursal con lo que ya se sabe. El
-- domicilio lo captura el encargado en Envíos → De dónde sale: partir
-- `sucursales.direccion` a la adivina produciría guías mal hechas.
INSERT IGNORE INTO lf_envio_origenes (sucursal_id, nombre, telefono, email, pais)
SELECT s.id, LEFT(s.nombre, 100), LEFT(COALESCE(s.telefono,''), 30),
       LEFT(COALESCE(s.email,''), 120), 'MX'
  FROM sucursales s;


-- ── v29 · Capturas con pistola, que se pueden pausar ─────────────────
-- Lo escaneado se guarda AQUÍ, sin tocar existencias. El inventario se
-- mueve de una sola vez al finalizar: así, una captura que alguien dejó
-- a medias y abandonó no deja el inventario diciendo que hay mercancía
-- que nadie terminó de verificar.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lf_inventario_captura_lineas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    captura_id  INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad    DECIMAL(12,3) NOT NULL DEFAULT 0,
    costo       DECIMAL(12,2) NULL DEFAULT NULL,
    agregado_en DATETIME NOT NULL,
    UNIQUE KEY ux_capl (captura_id, producto_id),
    KEY ix_capl (captura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Y se deja marcado que la base ya va en la 29 ─────────────────────
-- Sin esto, el sistema cree que sigue atrasada y lo vuelve a intentar.
CREATE TABLE IF NOT EXISTS lf_ajustes (
    clave       VARCHAR(100) NOT NULL PRIMARY KEY,
    valor       TEXT NULL,
    actualizado DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO lf_ajustes (clave, valor, actualizado)
VALUES ('esquema.version', '29', NOW())
ON DUPLICATE KEY UPDATE valor = '29', actualizado = NOW();


-- ── Para comprobar que quedó ─────────────────────────────────────────
-- Debe decir 29, y las seis tablas deben existir.
SELECT valor AS version_de_la_base FROM lf_ajustes WHERE clave = 'esquema.version';

SELECT TABLE_NAME AS tablas_nuevas
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('lf_almacenes','lf_producto_almacen',
                      'lf_inventario_conteos','lf_inventario_conteo_lineas',
                      'lf_envio_origenes','lf_envios',
                      'lf_inventario_capturas','lf_inventario_captura_lineas');

SELECT COLUMN_NAME AS columnas_nuevas
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
   AND COLUMN_NAME IN ('es_perecedero','codigo_barras','iva_aplica','ieps_tasa');