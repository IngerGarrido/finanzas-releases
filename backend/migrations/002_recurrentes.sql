-- Gastos recurrentes (arriendo, servicios, suscripciones, etc.)
CREATE TABLE IF NOT EXISTS gastos_recurrentes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT NOT NULL,
  descripcion     VARCHAR(200) NOT NULL,
  monto           DECIMAL(12,2) NOT NULL DEFAULT 0,
  categoria_id    INT DEFAULT NULL,
  subcategoria_id INT DEFAULT NULL,
  dia_mes         TINYINT DEFAULT NULL COMMENT 'Día del mes en que cae el gasto (1-31)',
  activo          TINYINT(1) DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)     ON DELETE CASCADE,
  FOREIGN KEY (categoria_id)    REFERENCES categorias(id)   ON DELETE SET NULL,
  FOREIGN KEY (subcategoria_id) REFERENCES subcategorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de cuáles recurrentes ya se aplicaron en cada mes
CREATE TABLE IF NOT EXISTS recurrentes_aplicados (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  recurrente_id  INT NOT NULL,
  anio           SMALLINT NOT NULL,
  mes            TINYINT NOT NULL,
  transaccion_id INT DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_aplicado (recurrente_id, anio, mes),
  FOREIGN KEY (recurrente_id)  REFERENCES gastos_recurrentes(id) ON DELETE CASCADE,
  FOREIGN KEY (transaccion_id) REFERENCES transacciones(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
