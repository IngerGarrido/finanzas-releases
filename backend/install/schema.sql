CREATE DATABASE IF NOT EXISTS finanzas_personales CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE finanzas_personales;

-- ─────────────────────────────────────────────
-- USUARIOS Y SESIONES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sesiones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  expira_en DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- CATEGORÍAS Y SUBCATEGORÍAS (editables)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  tipo ENUM('necesidad','discrecional','ahorro') NOT NULL,
  icono VARCHAR(20) DEFAULT NULL,
  color VARCHAR(20) DEFAULT '#6B7280',
  activa TINYINT(1) DEFAULT 1,
  orden INT DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subcategorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  activa TINYINT(1) DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- INGRESOS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fuentes_ingreso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  tipo ENUM('fijo','variable') DEFAULT 'fijo',
  activa TINYINT(1) DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ingresos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fuente_id INT DEFAULT NULL,
  anio SMALLINT NOT NULL,
  mes TINYINT NOT NULL,
  descripcion VARCHAR(150) DEFAULT NULL,
  planificado DECIMAL(12,2) DEFAULT 0,
  actual DECIMAL(12,2) DEFAULT 0,
  fecha_recibo DATE DEFAULT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (fuente_id) REFERENCES fuentes_ingreso(id) ON DELETE SET NULL
);

-- ─────────────────────────────────────────────
-- TRANSACCIONES (gastos)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS transacciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL DEFAULT 'gasto',
  categoria_id INT DEFAULT NULL,
  subcategoria_id INT DEFAULT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  notas TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
  FOREIGN KEY (subcategoria_id) REFERENCES subcategorias(id) ON DELETE SET NULL,
  INDEX idx_usuario_fecha (usuario_id, fecha)
);

-- ─────────────────────────────────────────────
-- PRESUPUESTO METAS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS presupuesto_metas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  anio SMALLINT NOT NULL,
  mes TINYINT NOT NULL,
  necesidades_pct DECIMAL(5,2) DEFAULT 50.00,
  discrecionales_pct DECIMAL(5,2) DEFAULT 30.00,
  ahorro_pct DECIMAL(5,2) DEFAULT 20.00,
  UNIQUE KEY uk_meta (usuario_id, anio, mes),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- TARJETA DE CRÉDITO
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tarjetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,          -- Ej: Falabella CMR
  banco VARCHAR(100) DEFAULT NULL,
  ultimos_4 CHAR(4) DEFAULT NULL,
  fecha_emision TINYINT DEFAULT NULL,    -- Día del mes (ej: 26)
  fecha_facturacion TINYINT DEFAULT NULL,-- Día del mes (ej: 10)
  dia_pago TINYINT DEFAULT NULL,         -- Día del mes límite de pago
  limite_credito DECIMAL(12,2) DEFAULT NULL,
  activa TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tarjeta_gastos_fijos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  dia_cobro TINYINT DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tarjeta_cuotas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  descripcion VARCHAR(150) NOT NULL,
  fecha_compra DATE NOT NULL,
  monto_total DECIMAL(12,2) NOT NULL,
  monto_cuota DECIMAL(12,2) NOT NULL,
  n_total_cuotas INT NOT NULL,
  cuota_actual INT NOT NULL DEFAULT 1,
  fecha_primer_pago DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- AHORRO (metas)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS metas_ahorro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  monto_meta DECIMAL(12,2) NOT NULL,
  monto_actual DECIMAL(12,2) DEFAULT 0,
  fecha_meta DATE DEFAULT NULL,
  icono VARCHAR(20) DEFAULT NULL,
  activa TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS metas_ahorro_aportes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  meta_id INT NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  nota VARCHAR(200) DEFAULT NULL,
  FOREIGN KEY (meta_id) REFERENCES metas_ahorro(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- GASTOS PENDIENTES (deudas por cobrar)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gastos_pendientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  persona VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  monto_total DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gastos_pendientes_pagos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pendiente_id INT NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (pendiente_id) REFERENCES gastos_pendientes(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- DATOS INICIALES
-- ─────────────────────────────────────────────

-- Usuario demo (pass: demo1234)
INSERT IGNORE INTO usuarios (id, nombre, email, password_hash) VALUES
(1, 'Inger', 'inger@pagos.local', '$2y$12$XfScaJ0a605Q7MmAI1V2puE.30aZy2utEsfD.KcaTGDjR9D0ewoLS');

-- Categorías por defecto
INSERT IGNORE INTO categorias (id, usuario_id, nombre, tipo, icono, color, orden) VALUES
(1, 1, 'Vivienda',       'necesidad',    '🏠', '#1D6348', 1),
(2, 1, 'Servicios',      'necesidad',    '💡', '#2D6A4F', 2),
(3, 1, 'Supermercado',   'necesidad',    '🛒', '#3D7A5F', 3),
(4, 1, 'Salud',          'necesidad',    '🏥', '#4D8A6F', 4),
(5, 1, 'Transporte',     'necesidad',    '🚗', '#1A5240', 5),
(6, 1, 'Deudas',         'necesidad',    '💳', '#0F3D30', 6),
(7, 1, 'Comida fuera',   'discrecional', '🍽️', '#C0400D', 7),
(8, 1, 'Entretención',   'discrecional', '🎬', '#D0500D', 8),
(9, 1, 'Ropa',           'discrecional', '👗', '#A03000', 9),
(10,1, 'Suscripciones',  'discrecional', '📱', '#B04000', 10),
(11,1, 'Varios',         'discrecional', '🛍️', '#904000', 11),
(12,1, 'Ahorro mensual', 'ahorro',       '💰', '#1C4F82', 12),
(13,1, 'Inversión',      'ahorro',       '📈', '#2C5F92', 13),
(14,1, 'Fondo emergencia','ahorro',      '🛡️', '#0C3F72', 14);

-- Subcategorías
INSERT IGNORE INTO subcategorias (categoria_id, nombre) VALUES
(1,'Arriendo'),(1,'Dividendo'),(1,'Gastos comunes'),
(2,'Agua'),(2,'Luz'),(2,'Gas'),(2,'Internet'),(2,'Teléfono celular'),
(3,'Supermercado Lider'),(3,'Supermercado Jumbo'),(3,'Feria/Verdulería'),
(4,'Médico'),(4,'Dentista'),(4,'Medicamentos'),(4,'Exámenes'),
(5,'Bencina'),(5,'TAG'),(5,'Estacionamiento'),(5,'Transporte público'),
(6,'Tarjeta Falabella'),(6,'Tarjeta Ripley'),(6,'Crédito banco'),(6,'Préstamo personal'),
(7,'Restaurante'),(7,'Delivery'),(7,'Café'),(7,'Bar'),
(8,'Cine'),(8,'Streaming'),(8,'Juegos'),(8,'Libros'),
(9,'Ropa adulto'),(9,'Zapatos'),(9,'Accesorios'),
(10,'Netflix'),(10,'Spotify'),(10,'Adobe'),(10,'Otros streaming'),
(11,'Mascotas'),(11,'Regalos'),(11,'Limpieza hogar'),(11,'Belleza/Cuidado personal'),
(12,'Ahorro mensual'),(13,'Inversión'),(14,'Fondo emergencia');

-- Fuentes de ingreso
INSERT IGNORE INTO fuentes_ingreso (id, usuario_id, nombre, tipo) VALUES
(1, 1, 'Sueldo', 'fijo'),
(2, 1, 'Freelance', 'variable'),
(3, 1, 'Otros', 'variable');
