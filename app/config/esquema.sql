-- ========================================================================
-- LIBERTYFIN · ESQUEMA DE UNA EMPRESA NUEVA
--
-- Extraído de getDatabaseScript() en registroEmpresa.php, donde vivía
-- como una cadena de PHP de mil y pico líneas.
--
-- Sacarlo a un archivo permite tres cosas que antes no se podían:
--   · leerlo sin abrir el PHP entero
--   · versionarlo y ver qué cambió entre releases
--   · cargarlo desde el instalador sin duplicar el texto
--
-- Las columnas que agregó el sistema nuevo NO están aquí: las pone
-- Servicio\Migraciones al terminar, para que haya UN solo lugar
-- donde se declara cada cambio de esquema.
-- ========================================================================

CREATE TABLE IF NOT EXISTS `sucursales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `responsable` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `es_matriz` tinyint(1) DEFAULT '0',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sucursales_nombre` (`nombre`)
);

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `rol` enum('admin','cajero','inventario') COLLATE utf8_unicode_ci DEFAULT 'cajero',
  `sucursal_id` int(11) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_usuarios_sucursal` (`sucursal_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ;

CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ;

CREATE TABLE IF NOT EXISTS `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `tipo` enum('normal','frecuente','corporativo') COLLATE utf8_unicode_ci DEFAULT 'normal',
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ;

CREATE TABLE IF NOT EXISTS `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `contacto` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
) ;

CREATE TABLE IF NOT EXISTS `sistema_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_empresa` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'Mi Empresa',
  `rfc` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8_unicode_ci,
  `logo` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `iva` decimal(5,2) DEFAULT '16.00',
  `moneda` varchar(10) COLLATE utf8_unicode_ci DEFAULT 'MXN',
  `notificaciones_stock` tinyint(1) DEFAULT '1',
  `stock_minimo_global` int(11) DEFAULT '5',
  `backup_automatico` tinyint(1) DEFAULT '0',
  `frecuencia_backup` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'diario',
  `ticket_empresa` tinyint(1) DEFAULT '1',
  `ticket_leyenda` text COLLATE utf8_unicode_ci,
  `color_primario` varchar(7) COLLATE utf8_unicode_ci DEFAULT '#27ae60',
  `color_secundario` varchar(7) COLLATE utf8_unicode_ci DEFAULT '#2ecc71',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `facturapi_test_api_key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_client_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_secret` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_mode` enum('sandbox','live') COLLATE utf8_unicode_ci DEFAULT 'sandbox',
  `paypal_webhook_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `tipo_persona` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'fisica o moral',
  `razon_social` varchar(200) COLLATE utf8_unicode_ci DEFAULT NULL,
  `regimen_fiscal` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL,
  `cp_fiscal` varchar(5) COLLATE utf8_unicode_ci DEFAULT NULL,
  `documentacion_estado` varchar(20) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'sin_enviar',
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `tipos_movimiento_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_apertura` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_cierre` timestamp NULL DEFAULT NULL,
  `monto_apertura` decimal(10,2) NOT NULL,
  `monto_cierre` decimal(10,2) DEFAULT '0.00',
  `monto_esperado` decimal(10,2) DEFAULT '0.00',
  `diferencia` decimal(10,2) DEFAULT '0.00',
  `estado` enum('abierta','cerrada') COLLATE utf8_unicode_ci DEFAULT 'abierta',
  `observaciones` text COLLATE utf8_unicode_ci,
  `ventas_efectivo` decimal(10,2) DEFAULT '0.00',
  `ventas_tarjeta` decimal(10,2) DEFAULT '0.00',
  `ventas_transferencia` decimal(10,2) DEFAULT '0.00',
  `total_ventas` decimal(10,2) DEFAULT '0.00',
  `otros_ingresos` decimal(10,2) DEFAULT '0.00',
  `otros_egresos` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `caja_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `caja_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `marca` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `subprecio` decimal(10,2) DEFAULT '0.00',
  `costo` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `stock` decimal(10,3) DEFAULT '0.000',
  `stock_minimo` decimal(10,3) DEFAULT '0.000',
  `categoria_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `imagen` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `unidad_medida` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'pieza',
  `tipo_producto` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'Estandar',
  `porcentaje_merma_danado` decimal(5,2) DEFAULT '0.00',
  `porcentaje_merma_deshidratacion` decimal(5,2) DEFAULT '0.00',
  `aplicar_merma_venta` tinyint(1) DEFAULT '0',
  `aplicar_merma_compra` tinyint(1) DEFAULT '0',
  `peso_kg` decimal(10,3) DEFAULT '1.000',
  `permite_fracciones` tinyint(1) DEFAULT '0',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fecha_caducidad` date DEFAULT NULL,
  `facturapi_producto_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `utilidad` decimal(5,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `categoria_id` (`categoria_id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `idx_productos_codigo` (`codigo`),
  KEY `idx_productos_nombre` (`nombre`),
  KEY `idx_productos_marca` (`marca`),
  KEY `idx_productos_activo` (`activo`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
);

CREATE TABLE IF NOT EXISTS `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_venta` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `iva` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') COLLATE utf8_unicode_ci DEFAULT 'efectivo',
  `estado` enum('pendiente','completada','cancelada') COLLATE utf8_unicode_ci DEFAULT 'completada',
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_original` datetime DEFAULT NULL,
  `fecha_modificada_por` int(11) DEFAULT NULL,
  `fecha_modificacion` datetime DEFAULT NULL,
  `motivo_fecha` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `cambio` decimal(10,2) DEFAULT '0.00',
  `efectivo_recibido` decimal(10,2) DEFAULT '0.00',
  `urlfacturacion` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `facturapi_receipt_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `factura_uuid`  VARCHAR(36)  NULL,
  `factura_folio` VARCHAR(50)  NULL,
  `descripcion` varchar(500) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_order_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_payer_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_status` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `empresa_id` INT NULL,
  `empresa_db` VARCHAR(100) NULL,
  `factura_token` VARCHAR(64) NULL,
  `factura_token_expira` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_venta` (`codigo_venta`),
  KEY `cliente_id` (`cliente_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `idx_ventas_codigo` (`codigo_venta`),
  KEY `caja_id` (`caja_id`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ventas_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `ventas_ibfk_4` FOREIGN KEY (`caja_id`) REFERENCES `caja` (`id`)
);

CREATE TABLE IF NOT EXISTS `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `numero_factura` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `iva` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `estado` enum('pendiente','recibida','cancelada') COLLATE utf8_unicode_ci DEFAULT 'pendiente',
  `fecha_compra` date DEFAULT NULL,
  `fecha_recibo` date DEFAULT NULL,
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_compras_fecha` (`fecha_compra`),
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compras_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `compras_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
);

CREATE TABLE IF NOT EXISTS `compra_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `costo_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `compra_id` (`compra_id`),
  KEY `producto_id` (`producto_id`),
  KEY `idx_detalles_compra` (`compra_id`),
  KEY `idx_detalles_producto` (`producto_id`),
  KEY `idx_detalles_compra_producto` (`compra_id`,`producto_id`),
  CONSTRAINT `compra_detalles_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compra_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
);

CREATE TABLE IF NOT EXISTS `producto_sucursal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `stock` decimal(10,3) DEFAULT '0.000',
  `stock_minimo` decimal(10,3) DEFAULT '0.000',
  `activo` tinyint(4) DEFAULT '1',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_producto_sucursal` (`producto_id`,`sucursal_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `idx_stock_sucursal` (`producto_id`,`sucursal_id`),
  CONSTRAINT `producto_sucursal_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `producto_sucursal_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `venta_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `cambio` decimal(10,2) DEFAULT '0.00',
  `unidad_medida` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'unidad',
  `efectivo_recibido` decimal(10,2) DEFAULT '0.00',
  -- NULL = la línea nació con la venta. Con fecha = se agregó después,
  -- cuando el cliente se acordó de otro servicio. Ver Servicio\AmpliarVenta.
  `agregado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `venta_detalles_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `venta_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ;

CREATE TABLE IF NOT EXISTS `movimientos_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8_unicode_ci NOT NULL,
  `concepto` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') COLLATE utf8_unicode_ci DEFAULT 'efectivo',
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_tipo` enum('venta','compra','gasto','otros') COLLATE utf8_unicode_ci DEFAULT 'otros',
  `observaciones` text COLLATE utf8_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `caja_id` (`caja_id`),
  KEY `sucursal_id` (`sucursal_id`),
  CONSTRAINT `movimientos_caja_ibfk_1` FOREIGN KEY (`caja_id`) REFERENCES `caja` (`id`),
  CONSTRAINT `movimientos_caja_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ;

CREATE TABLE IF NOT EXISTS `movimientos_inventario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `tipo` enum('entrada','salida','ajuste') COLLATE utf8_unicode_ci NOT NULL,
  `cantidad` int(11) NOT NULL,
  `cantidad_anterior` int(11) NOT NULL,
  `cantidad_nueva` int(11) NOT NULL,
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_tipo` enum('venta','compra','ajuste') COLLATE utf8_unicode_ci NOT NULL,
  `observaciones` text COLLATE utf8_unicode_ci,
  `usuario_id` int(11) NOT NULL,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_movimientos_fecha` (`fecha`),
  CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_2` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
);

CREATE TABLE IF NOT EXISTS `producto_imagenes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `ruta_imagen` varchar(500) NOT NULL,
  `orden` int(11) DEFAULT '0',
  `es_principal` tinyint(1) DEFAULT '0',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_producto` (`producto_id`),
  CONSTRAINT `producto_imagenes_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ;

CREATE TABLE IF NOT EXISTS `comision_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ;

CREATE TABLE IF NOT EXISTS `comision_colaboradores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `area_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_area` (`area_id`),
  KEY `idx_activo` (`activo`),
  CONSTRAINT `comision_colaboradores_ibfk_1` FOREIGN KEY (`area_id`) REFERENCES `comision_areas` (`id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `comision_porcentajes_reparto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `valor` decimal(5,2) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `valor` (`valor`)
);

CREATE TABLE IF NOT EXISTS `comision_reglas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `area_id` int(11) NOT NULL,
  `concepto` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `porcentaje` decimal(8,4) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `orden` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_area` (`area_id`),
  CONSTRAINT `comision_reglas_ibfk_1` FOREIGN KEY (`area_id`) REFERENCES `comision_areas` (`id`) ON DELETE CASCADE
) ;

CREATE TABLE IF NOT EXISTS `gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `concepto` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tipo` enum('automatico','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `origen` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `venta_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `metodo_pago` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proveedor` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_referencia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comprobante` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `fecha` datetime NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gastos_fecha` (`fecha`),
  KEY `idx_gastos_categoria` (`categoria`),
  KEY `idx_gastos_tipo` (`tipo`),
  KEY `idx_gastos_venta_id` (`venta_id`),
  KEY `idx_gastos_sucursal_id` (`sucursal_id`)
) ;

CREATE TABLE IF NOT EXISTS `venta_comisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `venta_detalle_id` int(11) NOT NULL,
  `area_id` int(11) DEFAULT NULL,
  `area_nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `regla_id` int(11) DEFAULT NULL,
  `concepto` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `colaborador_id` int(11) DEFAULT NULL,
  `colaborador_nombre` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `porcentaje_regla` decimal(8,4) NOT NULL,
  `porcentaje_reparto` decimal(8,4) NOT NULL DEFAULT '100.0000',
  `costo_unitario` decimal(10,2) NOT NULL,
  `gasto_operacion` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_unitario` decimal(10,2) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `monto_base` decimal(10,2) NOT NULL,
  `monto_comision` decimal(10,2) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `cancelada` tinyint(1) NOT NULL DEFAULT '0',
  `cancelada_por` int(11) DEFAULT NULL,
  `fecha_cancelacion` datetime DEFAULT NULL,
  `motivo_cancelacion` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_venta_detalle` (`venta_detalle_id`),
  KEY `idx_colaborador` (`colaborador_id`),
  KEY `idx_fecha` (`fecha_creacion`),
  KEY `venta_comisiones_ibfk_4` (`area_id`),
  KEY `venta_comisiones_ibfk_5` (`regla_id`),
  KEY `idx_vc_cancelada` (`cancelada`),
  CONSTRAINT `venta_comisiones_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `venta_comisiones_ibfk_2` FOREIGN KEY (`venta_detalle_id`) REFERENCES `venta_detalles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `venta_comisiones_ibfk_3` FOREIGN KEY (`colaborador_id`) REFERENCES `comision_colaboradores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venta_comisiones_ibfk_4` FOREIGN KEY (`area_id`) REFERENCES `comision_areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `venta_comisiones_ibfk_5` FOREIGN KEY (`regla_id`) REFERENCES `comision_reglas` (`id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `datos_pago_comercio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titular_nombre` varchar(200) DEFAULT NULL COMMENT 'Como aparece en el estado de cuenta',
  `nombre_comercio` varchar(200) DEFAULT NULL COMMENT 'Nombre comercial / de sucursal',
  `titular_correo` varchar(160) DEFAULT NULL,
  `giro` varchar(200) DEFAULT NULL,
  `calle_numero` varchar(200) DEFAULT NULL,
  `numero_interior` varchar(50) DEFAULT NULL,
  `colonia` varchar(150) DEFAULT NULL,
  `delegacion_municipio` varchar(150) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `estado_direccion` varchar(100) DEFAULT NULL,
  `pais` varchar(100) DEFAULT 'México',
  `telefono_oficina` varchar(20) DEFAULT NULL,
  `telefono_celular` varchar(20) DEFAULT NULL,
  `nombre_vendedor` varchar(150) DEFAULT NULL,
  `rep_legal_nombre` varchar(200) DEFAULT NULL,
  `rep_legal_escritura` varchar(200) DEFAULT NULL,
  `rep_legal_notaria_numero` varchar(50) DEFAULT NULL,
  `rep_legal_notario_nombre` varchar(200) DEFAULT NULL,
  `rep_legal_ciudad` varchar(100) DEFAULT NULL,
  `empresa_escritura` varchar(200) DEFAULT NULL COMMENT 'Solo persona moral',
  `empresa_folio_rpc` varchar(100) DEFAULT NULL,
  `empresa_ciudad` varchar(100) DEFAULT NULL,
  `empresa_notario_nombre` varchar(200) DEFAULT NULL,
  `empresa_notaria_numero` varchar(50) DEFAULT NULL,
  `id_tipo` varchar(50) DEFAULT NULL,
  `id_numero` varchar(100) DEFAULT NULL,
  `id_fecha_expedicion` date DEFAULT NULL,
  `id_vigencia` date DEFAULT NULL,
  `banco` varchar(100) DEFAULT NULL,
  `plaza` varchar(100) DEFAULT NULL,
  `sucursal_bancaria` varchar(100) DEFAULT NULL,
  `cuenta_cheques` varchar(30) DEFAULT NULL,
  `cuenta_clabe` varchar(18) DEFAULT NULL COMMENT 'Dato sensible',
  `clausulado_aceptado_en` datetime DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  `actualizado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `documentos_comercio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(40) NOT NULL,
  `ruta_archivo` varchar(500) NOT NULL,
  `nombre_original` varchar(255) DEFAULT NULL,
  `mime_real` varchar(100) DEFAULT NULL,
  `tamano_bytes` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | aprobado | rechazado',
  `motivo_rechazo` varchar(300) DEFAULT NULL,
  `subido_por` int(11) DEFAULT NULL,
  `subido_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revisado_por` int(11) DEFAULT NULL,
  `revisado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documentos_comercio_tipo` (`tipo`)
);

CREATE TABLE IF NOT EXISTS `emida_configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `config_value` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
);

CREATE TABLE IF NOT EXISTS `emida_transacciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `tipo_operacion` enum('recarga','pago_servicio','venta_directa') COLLATE utf8_unicode_ci NOT NULL,
  `product_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `product_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `account_id` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `invoice_no` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `version` varchar(10) COLLATE utf8_unicode_ci DEFAULT '01',
  `terminal_id` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `clerk_id` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `response_code` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL,
  `h2h_result_code` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL,
  `response_message` text COLLATE utf8_unicode_ci,
  `carrier_control_no` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `transaction_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `pin` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `control_no` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `customer_service_no` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `transaction_datetime` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_duplicate` tinyint(1) DEFAULT '0',
  `estado` enum('exitosa','fallida','duplicada') COLLATE utf8_unicode_ci DEFAULT NULL,
  `requires_lookup` tinyint(1) DEFAULT '0',
  `request_data` text COLLATE utf8_unicode_ci,
  `response_data` text COLLATE utf8_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sucursal_id` (`sucursal_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `caja_id` (`caja_id`),
  KEY `idx_emida_fecha` (`fecha`),
  KEY `idx_emida_account` (`account_id`),
  KEY `idx_emida_transaction` (`transaction_id`),
  KEY `idx_requires_lookup` (`requires_lookup`),
  KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `emida_transacciones_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`),
  CONSTRAINT `emida_transacciones_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `emida_transacciones_ibfk_3` FOREIGN KEY (`caja_id`) REFERENCES `caja` (`id`)
);

CREATE TABLE IF NOT EXISTS `venta_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  -- El subfolio del cobro: 20260102143022-02. Ver Servicio\Folio.
  `folio` varchar(24) DEFAULT NULL,
  `tipo` enum('anticipo','abono','liquidacion') NOT NULL DEFAULT 'abono',
  `monto` decimal(12,2) NOT NULL,
  `fecha_pago` date NOT NULL,
  `metodo_pago` varchar(30) DEFAULT NULL,
  `banco` varchar(100) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `cancelado` tinyint(1) NOT NULL DEFAULT '0',
  `cancelado_por` int(11) DEFAULT NULL,
  `fecha_cancelacion` datetime DEFAULT NULL,
  `motivo_cancelacion` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vp_venta` (`venta_id`),
  KEY `ix_vp_folio` (`folio`),
  KEY `idx_vp_fecha` (`fecha_pago`),
  KEY `idx_vp_cancelado` (`cancelado`),
  CONSTRAINT `venta_pagos_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pago_comisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pago_id` int(11) NOT NULL,
  `venta_comision_id` int(11) NOT NULL,
  `venta_id` int(11) NOT NULL,
  `colaborador_id` int(11) DEFAULT NULL,
  `colaborador_nombre` varchar(150) DEFAULT NULL,
  `area_nombre` varchar(150) DEFAULT NULL,
  `porcentaje` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `proporcion_cobrada` decimal(9,6) NOT NULL DEFAULT '0.000000',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fecha_pago` date NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pc_pago` (`pago_id`),
  KEY `idx_pc_venta` (`venta_id`),
  KEY `idx_pc_colab` (`colaborador_id`),
  KEY `idx_pc_fecha` (`fecha_pago`),
  KEY `pago_comisiones_ibfk_2` (`venta_comision_id`),
  CONSTRAINT `pago_comisiones_ibfk_1` FOREIGN KEY (`pago_id`) REFERENCES `venta_pagos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pago_comisiones_ibfk_2` FOREIGN KEY (`venta_comision_id`) REFERENCES `venta_comisiones` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `producto_precios_mayoreo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `cantidad_minima` decimal(10,2) NOT NULL,
  `precio_especial` decimal(10,2) NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `producto_precios_mayoreo_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
);

-- Las promociones del sistema anterior (promociones, promocion_productos,
-- promociones_aplicables, promociones_combo, promociones_sucursales) ya no
-- se crean aquí. La definición de `promociones` venía cortada a la mitad
-- desde la extracción de registroEmpresa.php, así que ninguna empresa nueva
-- llegó a tenerlas: la primera fallaba por sintaxis y las otras cuatro por su
-- llave foránea hacia ella. Nada del sistema actual las lee; las promociones
-- nuevas son lf_promociones y compañía (Servicio\Migraciones, v19). Las
-- empresas que vienen del sistema anterior conservan las suyas.

