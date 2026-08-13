-- Etiquetas (tags): una dimensión extra para cruzar gastos
-- (ej: "Vacaciones 2026") independiente de la categoría.

CREATE TABLE IF NOT EXISTS etiquetas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre     VARCHAR(50) NOT NULL,
  color      VARCHAR(7) DEFAULT '#64748B',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_nombre (usuario_id, nombre),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaccion_etiquetas (
  transaccion_id INT NOT NULL,
  etiqueta_id    INT NOT NULL,
  PRIMARY KEY (transaccion_id, etiqueta_id),
  FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE CASCADE,
  FOREIGN KEY (etiqueta_id)    REFERENCES etiquetas(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
