-- Base de datos: `sos_cosmeticos`
-- Schema actualizado para SOS Cosméticos

CREATE DATABASE IF NOT EXISTS `sos_cosmeticos`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_spanish2_ci;

USE `sos_cosmeticos`;

CREATE TABLE IF NOT EXISTS `categoria` (
  `ID_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  PRIMARY KEY (`ID_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `sub_categoria` (
  `ID_sub_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `ID_categoria` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  PRIMARY KEY (`ID_sub_categoria`),
  UNIQUE KEY `uq_sub_categoria_nombre` (`ID_categoria`, `nombre`),
  CONSTRAINT `fk_sub_categoria_categoria`
    FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `producto` (
  `ID_stock` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `sub_nombre` varchar(100) DEFAULT NULL,
  `codigo` varchar(100) DEFAULT NULL,
  `cantTotal` int(11) DEFAULT 0,
  `cantVendida` int(11) DEFAULT 0,
  `ID_categoria` int(11) DEFAULT NULL,
  `subcategoria` varchar(100) DEFAULT NULL,
  `ID_sub_categoria` int(11) DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT 0.00,
  `fase` varchar(50) NOT NULL DEFAULT 'habilitado',
  PRIMARY KEY (`ID_stock`),
  KEY `fk_producto_categoria` (`ID_categoria`),
  KEY `fk_producto_sub_categoria` (`ID_sub_categoria`),
  CONSTRAINT `fk_producto_categoria`
    FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_producto_sub_categoria`
    FOREIGN KEY (`ID_sub_categoria`) REFERENCES `sub_categoria` (`ID_sub_categoria`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

INSERT IGNORE INTO `sub_categoria` (`ID_categoria`, `nombre`)
SELECT DISTINCT `ID_categoria`, TRIM(`subcategoria`)
FROM `producto`
WHERE `ID_categoria` IS NOT NULL
  AND `subcategoria` IS NOT NULL
  AND TRIM(`subcategoria`) <> '';

UPDATE `producto` p
INNER JOIN `sub_categoria` sc
  ON sc.`ID_categoria` = p.`ID_categoria`
 AND LOWER(sc.`nombre`) = LOWER(TRIM(p.`subcategoria`))
SET p.`ID_sub_categoria` = sc.`ID_sub_categoria`
WHERE p.`ID_sub_categoria` IS NULL;

CREATE TABLE IF NOT EXISTS `facturacion` (
  `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cantidadVendida` int(11) NOT NULL,
  `precioFinal` decimal(10,2) NOT NULL,
  `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
  `ID_stock` int(11) DEFAULT NULL,
  `nombre_producto` varchar(100) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT 'gomez11',
  PRIMARY KEY (`ID_factura`),
  KEY `fk_facturacion_producto` (`ID_stock`),
  KEY `idx_facturacion_fecha_id` (`fecha`, `ID_factura`),
  CONSTRAINT `fk_facturacion_producto`
    FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `ventas` (
  `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cantidadVendida` int(11) NOT NULL,
  `precioFinal` decimal(10,2) NOT NULL,
  `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
  `ID_stock` int(11) DEFAULT NULL,
  `nombre_producto` varchar(100) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT 'gomez11',
  PRIMARY KEY (`ID_factura`),
  KEY `fk_ventas_producto` (`ID_stock`),
  KEY `idx_ventas_fecha_id` (`fecha`, `ID_factura`),
  CONSTRAINT `fk_ventas_producto`
    FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `ventas_historial` (
  `ID_factura` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `cantidadVendida` int(11) NOT NULL,
  `precioFinal` decimal(10,2) NOT NULL,
  `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
  `ID_stock` int(11) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT 'gomez11',
  `semana_inicio` date NOT NULL,
  PRIMARY KEY (`ID_factura`),
  KEY `idx_ventas_historial_semana_fecha` (`semana_inicio`, `fecha`),
  KEY `idx_ventas_historial_fecha_id` (`fecha`, `ID_factura`),
  CONSTRAINT `fk_ventas_historial_producto`
    FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `sesion_activa` (
  `token_sesion` char(64) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `usuario` varchar(100) NOT NULL,
  `iniciada_en` datetime NOT NULL,
  `ultima_actividad` datetime NOT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `cerrada_en` datetime DEFAULT NULL,
  PRIMARY KEY (`token_sesion`),
  KEY `idx_sesion_activa_ultima_actividad` (`activa`, `ultima_actividad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `cierre_jornada` (
  `id_cierre` bigint(20) NOT NULL AUTO_INCREMENT,
  `fecha_jornada` date NOT NULL,
  `fecha_cierre` datetime NOT NULL,
  `usuario` varchar(100) NOT NULL,
  `registros_eliminados` int(11) NOT NULL DEFAULT 0,
  `registros_archivados` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_cierre`),
  KEY `idx_cierre_jornada_fecha` (`fecha_jornada`, `fecha_cierre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `usuario` (
  `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` varchar(50) NOT NULL DEFAULT 'vendedor',
  `ultimo_acceso` datetime DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uq_usuario_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `actividad_usuario` (
  `id_actividad` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) DEFAULT NULL,
  `usuario` varchar(100) NOT NULL,
  `tipo_accion` varchar(50) NOT NULL,
  `descripcion` text NOT NULL,
  `detalles` text DEFAULT NULL,
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_actividad`),
  KEY `fk_actividad_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `amenaza` (
  `id_amenaza` char(64) NOT NULL,
  `regla` varchar(50) NOT NULL,
  `clave` varchar(255) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text NOT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `intentos` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `ventana_minutos` int(11) NOT NULL,
  PRIMARY KEY (`id_amenaza`),
  KEY `idx_amenaza_fecha` (`fecha`),
  KEY `idx_amenaza_regla_clave_fecha` (`regla`, `clave`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `historial_ajuste_precio` (
  `id_ajuste` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usuario` varchar(100) NOT NULL,
  `alcance` varchar(30) NOT NULL,
  `categoria` varchar(255) DEFAULT NULL,
  `tipo` varchar(255) DEFAULT NULL,
  `nombre_producto` varchar(100) DEFAULT NULL,
  `tipo_ajuste` varchar(20) NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `cantidad_productos` int(11) NOT NULL,
  PRIMARY KEY (`id_ajuste`),
  KEY `idx_historial_ajuste_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `Estado_fase` (
  `id_estado` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  PRIMARY KEY (`id_estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

INSERT INTO `Estado_fase` (`nombre`) VALUES
  ('habilitado'),
  ('deshabilitado');

INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`)
VALUES
  ('gomez11', '$2y$10$YRaYHgD0mlNkI0PXn.cKUeBflEdGDLx97LVrGtc2sufPLOeHa/x22', 'superadmin', NOW()),
  ('GOMEZ ADMIN', '$2y$10$JyH3Uir2a6822VOa.gOdx.swjfuYetdL1.EB7wr5DiXBOgtQwKJZK', 'administrador', NOW())
ON DUPLICATE KEY UPDATE
  `rol` = VALUES(`rol`);
