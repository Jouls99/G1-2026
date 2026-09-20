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

CREATE TABLE IF NOT EXISTS `producto` (
  `ID_stock` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `codigo` varchar(100) DEFAULT NULL,
  `cantTotal` int(11) DEFAULT 0,
  `cantVendida` int(11) DEFAULT 0,
  `ID_categoria` int(11) DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT 0.00,
  `fase` varchar(50) NOT NULL DEFAULT 'habilitado',
  PRIMARY KEY (`ID_stock`),
  KEY `fk_producto_categoria` (`ID_categoria`),
  CONSTRAINT `fk_producto_categoria`
    FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS `facturacion` (
  `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cantidadVendida` int(11) NOT NULL,
  `precioFinal` decimal(10,2) NOT NULL,
  `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
  `ID_stock` int(11) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT 'gomez11',
  PRIMARY KEY (`ID_factura`),
  KEY `fk_facturacion_producto` (`ID_stock`),
  CONSTRAINT `fk_facturacion_producto`
    FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
    ON DELETE SET NULL ON UPDATE CASCADE
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

INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`)
VALUES
  ('gomez11', '$2y$10$YRaYHgD0mlNkI0PXn.cKUeBflEdGDLx97LVrGtc2sufPLOeHa/x22', 'superadmin', NOW()),
  ('GOMEZ ADMIN', '$2y$10$JyH3Uir2a6822VOa.gOdx.swjfuYetdL1.EB7wr5DiXBOgtQwKJZK', 'administrador', NOW()),
  ('vendedor_demo', '$2y$10$i7vxlO0ZNVpDht7ClhhupOB548wSgAa2FduQMNMx7s4qmtpFt4LXe', 'vendedor', NOW())
ON DUPLICATE KEY UPDATE
  `rol` = VALUES(`rol`);

