-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost
-- Tiempo de generación: 12-11-2025 a las 00:00:00
-- Versión del servidor: 10.4.28-MariaDB
-- Versión de PHP: 8.2.4

DROP DATABASE IF EXISTS `coeval_db`;
CREATE DATABASE `coeval_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `coeval_db`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
@OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
@OLD_COLLATION_CONNECTION=@@OLD_COLLATION_CONNECTION;
/*!40101 SET NAMES utf8mb4 */;

-- ========================================
-- TABLA: usuarios
-- ========================================
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `email` varchar(191) NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `es_docente` tinyint(1) DEFAULT 0,
  `password` varchar(255) DEFAULT NULL,
  `id_curso` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `id_equipo` (`id_equipo`),
  KEY `idx_id_curso` (`id_curso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: cursos
-- ========================================
CREATE TABLE `cursos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_curso` varchar(255) NOT NULL,
  `semestre` varchar(10) NOT NULL COMMENT 'Ej: 2025-1',
  `anio` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_semestre_anio` (`nombre_curso`,`semestre`,`anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: docente_curso
-- ========================================
CREATE TABLE `docente_curso` (
  `id_docente` int(11) NOT NULL,
  `id_curso` int(11) NOT NULL,
  `ponderacion` decimal(3,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id_docente`,`id_curso`),
  KEY `id_curso` (`id_curso`),
  CONSTRAINT `docente_curso_ibfk_1` FOREIGN KEY (`id_docente`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docente_curso_ibfk_2` FOREIGN KEY (`id_curso`) REFERENCES `cursos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: docente_curso_log (AUDITORÍA)
-- ========================================
CREATE TABLE `docente_curso_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_docente` INT(11) NOT NULL,
  `id_curso` INT(11) NOT NULL,
  `ponderacion_anterior` DECIMAL(3,2) DEFAULT NULL,
  `ponderacion_nueva` DECIMAL(3,2) NOT NULL,
  `id_usuario_accion` INT(11) NOT NULL,
  `fecha_cambio` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_docente_curso` (`id_docente`,`id_curso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: criterios
-- ========================================
CREATE TABLE `criterios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` text NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `id_curso` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_curso` (`id_curso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: equipos
-- ========================================
CREATE TABLE `equipos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_equipo` varchar(100) NOT NULL,
  `estado_presentacion` varchar(20) NOT NULL DEFAULT 'pendiente',
  `id_curso` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_curso_equipo` (`id_curso`,`nombre_equipo`),
  KEY `id_curso` (`id_curso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: evaluaciones_maestro
-- ========================================
CREATE TABLE `evaluaciones_maestro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_evaluador` int(11) NOT NULL,
  `id_equipo_evaluado` int(11) NOT NULL,
  `puntaje_total` int(11) NOT NULL,
  `fecha_evaluacion` timestamp NULL DEFAULT current_timestamp(),
  `id_curso` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_evaluador_equipo_curso` (`id_evaluador`,`id_equipo_evaluado`,`id_curso`),
  KEY `id_equipo_evaluado` (`id_equipo_evaluado`),
  KEY `id_curso` (`id_curso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: evaluaciones_detalle
-- ========================================
CREATE TABLE `evaluaciones_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_evaluacion` int(11) NOT NULL,
  `id_criterio` int(11) NOT NULL,
  `puntaje` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_evaluacion` (`id_evaluacion`),
  KEY `id_criterio` (`id_criterio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABLA: logs
-- ========================================
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `accion` varchar(50) NOT NULL,
  `detalle` text DEFAULT NULL,
  `fecha` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`id_usuario`) 
      REFERENCES `usuarios` (`id`) 
      ON DELETE CASCADE 
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- DATOS DE PRUEBA
-- ========================================

-- Insertar usuario docente
INSERT INTO `usuarios` (`nombre`, `email`, `es_docente`, `password`, `id_equipo`, `id_curso`) 
VALUES ('Docente Prueba', 'docente@uct.cl', 1, '$2y$10$5RFvZl7w.zP5YL7KDH.cTu0Jq6kU4kDH8Qj4qK3L9Q2M6N7O8P9Q', NULL, NULL);

-- Insertar cursos de prueba
INSERT INTO `cursos` (`nombre_curso`, `semestre`, `anio`) 
VALUES 
('Programación I', '2025-1', 2025),
('Algoritmos', '2025-1', 2025),
('Base de Datos', '2025-2', 2025);

-- Asociar docente a cursos
INSERT INTO `docente_curso` (`id_docente`, `id_curso`, `ponderacion`) 
VALUES 
(1, 1, 1.00),
(1, 2, 1.00),
(1, 3, 1.00);

-- Insertar equipos de prueba
INSERT INTO `equipos` (`nombre_equipo`, `estado_presentacion`, `id_curso`) 
VALUES 
('Equipo A', 'pendiente', 1),
('Equipo B', 'pendiente', 1),
('Equipo C', 'pendiente', 1);

-- Insertar criterios de prueba
INSERT INTO `criterios` (`descripcion`, `orden`, `activo`, `id_curso`) 
VALUES 
('Presentación', 1, 1, 1),
('Contenido Técnico', 2, 1, 1),
('Organización', 3, 1, 1),
('Calidad del Código', 4, 1, 1),
('Respuesta a Preguntas', 5, 1, 1);

-- Insertar estudiantes
INSERT INTO `usuarios` (`nombre`, `email`, `es_docente`, `password`, `id_equipo`, `id_curso`) 
VALUES 
('Estudiante Prueba 1', 'estudiante@alu.uct.cl', 0, NULL, 1, 1),
('Estudiante Prueba 2', 'estudiante2@alu.uct.cl', 0, NULL, 1, 1),
('Estudiante Prueba 3', 'estudiante3@alu.uct.cl', 0, NULL, 2, 1),
('Estudiante Prueba 4', 'estudiante4@alu.uct.cl', 0, NULL, 2, 1),
('Estudiante Prueba 5', 'estudiante5@alu.uct.cl', 0, NULL, 3, 1);

-- Insertar evaluaciones maestro
INSERT INTO `evaluaciones_maestro` (`id_evaluador`, `id_equipo_evaluado`, `puntaje_total`, `id_curso`) 
VALUES 
(2, 2, 85, 1),
(3, 3, 90, 1),
(4, 1, 78, 1);

-- Insertar detalles
INSERT INTO `evaluaciones_detalle` (`id_evaluacion`, `id_criterio`, `puntaje`) 
VALUES 
(1, 1, 17),(1, 2, 18),(1, 3, 16),(1, 4, 17),(1, 5, 17),
(2, 1, 18),(2, 2, 19),(2, 3, 18),(2, 4, 18),(2, 5, 17),
(3, 1, 15),(3, 2, 16),(3, 3, 16),(3, 4, 15),(3, 5, 16);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET */;
@OLD_CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
@OLD_CHARACTER_SET_CONNECTION=@OLD_COLLATION_CONNECTION */;
