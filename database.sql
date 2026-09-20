-- Esquema de base de datos MySQL (Opcional para phpMyAdmin / MySQL en XAMPP)
CREATE DATABASE IF NOT EXISTS `cosmetica_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cosmetica_db`;

-- Tabla de Usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'vendedor',
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Categorías
CREATE TABLE IF NOT EXISTS `categorias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `nombre` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Productos / Inventario
CREATE TABLE IF NOT EXISTS `productos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `categoria` VARCHAR(100) NOT NULL,
  `subcategoria` VARCHAR(100) DEFAULT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `codigo` VARCHAR(50) NOT NULL UNIQUE,
  `precio` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cantidad` INT NOT NULL DEFAULT 0,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Ventas
CREATE TABLE IF NOT EXISTS `ventas` (
  `id` VARCHAR(50) PRIMARY KEY,
  `total` DECIMAL(10,2) NOT NULL,
  `dinero` DECIMAL(10,2) NOT NULL,
  `total_inventario` DECIMAL(12,2) DEFAULT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Detalle de Venta
CREATE TABLE IF NOT EXISTS `venta_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `venta_id` VARCHAR(50) NOT NULL,
  `producto_codigo` VARCHAR(50) NOT NULL,
  `producto_nombre` VARCHAR(150) NOT NULL,
  `categoria` VARCHAR(100) DEFAULT NULL,
  `cantidad` INT NOT NULL,
  `precio_unitario` DECIMAL(10,2) NOT NULL,
  CONSTRAINT `fk_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
