-- Asegurar tabla subcategorias (puede faltar en instalaciones anteriores)
CREATE TABLE IF NOT EXISTS subcategorias (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  activa      TINYINT(1) DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corregir charset de la columna icono para soportar emoji (utf8mb4)
-- Si ya es utf8mb4 el MODIFY es inocuo; si era utf8/latin1 lo convierte.
ALTER TABLE categorias
  MODIFY COLUMN icono VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
