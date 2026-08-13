-- Migración: actualizar schema al esquema completo
-- Ejecutar desde: mysql -u root -proot finanzas_personales < migrate.sql

-- ── categorias: agregar columnas faltantes ────────
ALTER TABLE categorias
  ADD COLUMN usuario_id INT NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN color VARCHAR(20) DEFAULT '#6B7280' AFTER icono,
  ADD COLUMN activa TINYINT(1) DEFAULT 1 AFTER color,
  ADD COLUMN orden INT DEFAULT 0 AFTER activa;

UPDATE categorias SET usuario_id = 1, orden = id;

-- ── subcategorias: agregar activa ─────────────────
ALTER TABLE subcategorias
  ADD COLUMN activa TINYINT(1) DEFAULT 1 AFTER nombre;

-- ── Crear tarjetas ────────────────────────────────
CREATE TABLE IF NOT EXISTS tarjetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  banco VARCHAR(100) DEFAULT NULL,
  ultimos_4 CHAR(4) DEFAULT NULL,
  fecha_emision TINYINT DEFAULT NULL,
  fecha_facturacion TINYINT DEFAULT NULL,
  dia_pago TINYINT DEFAULT NULL,
  limite_credito DECIMAL(12,2) DEFAULT NULL,
  activa TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Crear tarjeta placeholder si hay cargos/cuotas previas
INSERT INTO tarjetas (usuario_id, nombre)
  SELECT DISTINCT usuario_id, 'Tarjeta principal'
  FROM tarjeta_gastos_fijos
  LIMIT 1
  ON DUPLICATE KEY UPDATE nombre = nombre;

-- ── tarjeta_gastos_fijos: migrar a tarjeta_id ─────
ALTER TABLE tarjeta_gastos_fijos
  ADD COLUMN tarjeta_id INT DEFAULT NULL AFTER id,
  ADD COLUMN dia_cobro TINYINT DEFAULT NULL;

-- Asignar tarjeta_id (usa la primera tarjeta creada)
UPDATE tarjeta_gastos_fijos f
  JOIN tarjetas t ON t.usuario_id = f.usuario_id
  SET f.tarjeta_id = t.id
  WHERE f.tarjeta_id IS NULL;

-- ── tarjeta_cuotas: migrar a tarjeta_id ──────────
ALTER TABLE tarjeta_cuotas
  ADD COLUMN tarjeta_id INT DEFAULT NULL AFTER id,
  ADD COLUMN monto_total DECIMAL(12,2) DEFAULT NULL;

UPDATE tarjeta_cuotas c
  JOIN tarjetas t ON t.usuario_id = c.usuario_id
  SET c.tarjeta_id = t.id,
      c.monto_total = c.monto_cuota * c.n_total_cuotas
  WHERE c.tarjeta_id IS NULL;

-- ── Nuevas tablas ─────────────────────────────────
CREATE TABLE IF NOT EXISTS fuentes_ingreso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  tipo ENUM('fijo','variable') DEFAULT 'fijo',
  activa TINYINT(1) DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

INSERT IGNORE INTO fuentes_ingreso (id, usuario_id, nombre, tipo) VALUES
(1, 1, 'Sueldo', 'fijo'),
(2, 1, 'Freelance', 'variable'),
(3, 1, 'Otros', 'variable');

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

CREATE TABLE IF NOT EXISTS metas_ahorro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  monto_meta DECIMAL(12,2) NOT NULL,
  monto_actual DECIMAL(12,2) DEFAULT 0,
  fecha_meta DATE DEFAULT NULL,
  icono VARCHAR(10) DEFAULT '🎯',
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

-- ── Colores de categorias ─────────────────────────
UPDATE categorias SET color = '#1D6348' WHERE nombre = 'Vivienda';
UPDATE categorias SET color = '#2D6A4F' WHERE nombre = 'Servicios';
UPDATE categorias SET color = '#3D7A5F' WHERE nombre = 'Supermercado';
UPDATE categorias SET color = '#4D8A6F' WHERE nombre = 'Salud';
UPDATE categorias SET color = '#1A5240' WHERE nombre = 'Transporte';
UPDATE categorias SET color = '#0F3D30' WHERE nombre = 'Deudas';
UPDATE categorias SET color = '#C0400D' WHERE nombre = 'Comida fuera';
UPDATE categorias SET color = '#D0500D' WHERE nombre = 'Entretención';
UPDATE categorias SET color = '#A03000' WHERE nombre = 'Ropa';
UPDATE categorias SET color = '#B04000' WHERE nombre = 'Suscripciones';
UPDATE categorias SET color = '#904000' WHERE nombre = 'Varios';
UPDATE categorias SET color = '#1C4F82' WHERE nombre = 'Ahorro mensual';
UPDATE categorias SET color = '#2C5F92' WHERE nombre = 'Inversión';
UPDATE categorias SET color = '#0C3F72' WHERE nombre = 'Fondo emergencia';

SELECT 'Migración completada' as status;
