-- Registro de pagos de tarjeta convertidos en transacción, por mes.
-- Permite el botón "Registrar pago del mes" sin duplicar y sin doble conteo
-- en el balance disponible del Dashboard.

CREATE TABLE IF NOT EXISTS tarjeta_pagos_aplicados (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id     INT NOT NULL,
  anio           SMALLINT NOT NULL,
  mes            TINYINT NOT NULL,
  transaccion_id INT DEFAULT NULL,
  monto          DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tarjeta_mes (tarjeta_id, anio, mes),
  FOREIGN KEY (tarjeta_id)     REFERENCES tarjetas(id)     ON DELETE CASCADE,
  FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
