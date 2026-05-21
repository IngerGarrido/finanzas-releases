-- Migración inicial: esquema completo de finanzas personales
-- Idempotente: usa IF NOT EXISTS
-- NOTA: refleja el schema real de la aplicación

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sesiones (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token      VARCHAR(255) NOT NULL UNIQUE,
  expira_en  DATETIME NOT NULL,
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre     VARCHAR(100) NOT NULL,
  tipo       ENUM('necesidad','discrecional','ahorro') NOT NULL,
  icono      VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  color      VARCHAR(20) DEFAULT '#6B7280',
  activa     TINYINT(1) DEFAULT 1,
  orden      INT DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subcategorias (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  nombre       VARCHAR(100) NOT NULL,
  activa       TINYINT(1) DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transacciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT NOT NULL,
  fecha           DATE NOT NULL,
  monto           DECIMAL(12,2) NOT NULL,
  tipo            ENUM('ingreso','gasto') NOT NULL,
  categoria_id    INT DEFAULT NULL,
  subcategoria_id INT DEFAULT NULL,
  descripcion     VARCHAR(255) DEFAULT NULL,
  notas           TEXT DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)      ON DELETE CASCADE,
  FOREIGN KEY (categoria_id)    REFERENCES categorias(id)    ON DELETE SET NULL,
  FOREIGN KEY (subcategoria_id) REFERENCES subcategorias(id) ON DELETE SET NULL,
  INDEX idx_usuario_fecha (usuario_id, fecha),
  INDEX idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuesto (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT NOT NULL,
  categoria_id INT NOT NULL,
  anio         INT NOT NULL,
  mes          TINYINT NOT NULL,
  meta         DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_presupuesto (usuario_id, categoria_id, anio, mes),
  FOREIGN KEY (usuario_id)   REFERENCES usuarios(id)   ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarjetas (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id          INT NOT NULL,
  nombre              VARCHAR(100) NOT NULL,
  banco               VARCHAR(100) DEFAULT NULL,
  ultimos_4           CHAR(4) DEFAULT NULL,
  fecha_facturacion   TINYINT DEFAULT NULL,
  dia_pago            TINYINT DEFAULT NULL,
  limite_credito      DECIMAL(12,2) DEFAULT NULL,
  activa              TINYINT(1) DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarjeta_gastos_fijos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  usuario_id INT DEFAULT NULL,
  nombre     VARCHAR(150) NOT NULL,
  monto      DECIMAL(12,2) NOT NULL,
  categoria  VARCHAR(100) DEFAULT NULL,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarjeta_cuotas (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id       INT NOT NULL,
  usuario_id       INT DEFAULT NULL,
  descripcion      VARCHAR(150) NOT NULL,
  fecha_compra     DATE NOT NULL,
  monto_cuota      DECIMAL(12,2) NOT NULL,
  monto_total      DECIMAL(12,2) DEFAULT NULL,
  n_total_cuotas   INT NOT NULL,
  cuota_actual     INT NOT NULL DEFAULT 1,
  fecha_primer_pago DATE NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gastos_pendientes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT NOT NULL,
  persona     VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  monto_total DECIMAL(12,2) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gastos_pendientes_pagos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  pendiente_id INT NOT NULL,
  fecha        DATE NOT NULL,
  monto        DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (pendiente_id) REFERENCES gastos_pendientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuentes_ingreso (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre     VARCHAR(100) NOT NULL,
  tipo       ENUM('fijo','variable') NOT NULL DEFAULT 'fijo',
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingresos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT NOT NULL,
  fuente_id   INT DEFAULT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  planificado DECIMAL(12,2) NOT NULL DEFAULT 0,
  actual      DECIMAL(12,2) NOT NULL DEFAULT 0,
  anio        INT NOT NULL,
  mes         TINYINT NOT NULL,
  fecha_recibo DATE DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (fuente_id)  REFERENCES fuentes_ingreso(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metas_ahorro (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT NOT NULL,
  nombre       VARCHAR(100) NOT NULL,
  descripcion  VARCHAR(255) DEFAULT NULL,
  monto_meta   DECIMAL(12,2) NOT NULL,
  monto_actual DECIMAL(12,2) DEFAULT 0,
  fecha_meta   DATE DEFAULT NULL,
  icono        VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '🎯',
  activa       TINYINT(1) DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metas_ahorro_aportes (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  meta_id  INT NOT NULL,
  fecha    DATE NOT NULL,
  monto    DECIMAL(12,2) NOT NULL,
  nota     VARCHAR(200) DEFAULT NULL,
  FOREIGN KEY (meta_id) REFERENCES metas_ahorro(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migraciones (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  archivo      VARCHAR(255) NOT NULL UNIQUE,
  ejecutada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  duracion_ms  INT DEFAULT NULL,
  checksum     CHAR(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
