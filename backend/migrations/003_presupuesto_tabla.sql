-- Tabla de presupuesto por categoría (puede faltar en instalaciones anteriores)
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
