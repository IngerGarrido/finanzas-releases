-- Tarjeta como cuenta con saldo: deuda arrastrada manual + pagos flexibles
-- (parciales y múltiples por mes).

-- 1) Campo de deuda de meses anteriores (lo ajusta el usuario)
ALTER TABLE tarjetas
  ADD COLUMN IF NOT EXISTS saldo_arrastrado DECIMAL(12,2) NOT NULL DEFAULT 0
  AFTER limite_credito;

-- 2) Registro flexible de pagos (varios por mes, cualquier monto)
CREATE TABLE IF NOT EXISTS tarjeta_pagos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id     INT NOT NULL,
  anio           SMALLINT NOT NULL,
  mes            TINYINT NOT NULL,
  fecha          DATE NOT NULL,
  monto          DECIMAL(12,2) NOT NULL,
  nota           VARCHAR(200) DEFAULT NULL,
  transaccion_id INT DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tarjeta_mes (tarjeta_id, anio, mes),
  FOREIGN KEY (tarjeta_id)     REFERENCES tarjetas(id)     ON DELETE CASCADE,
  FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Migrar pagos previos (modelo antiguo de 1 pago total por mes) al nuevo log
INSERT INTO tarjeta_pagos (tarjeta_id, anio, mes, fecha, monto, transaccion_id)
SELECT tp.tarjeta_id, tp.anio, tp.mes, DATE(tp.created_at), tp.monto, tp.transaccion_id
FROM tarjeta_pagos_aplicados tp
WHERE NOT EXISTS (
  SELECT 1 FROM tarjeta_pagos p
  WHERE p.tarjeta_id = tp.tarjeta_id AND p.anio = tp.anio AND p.mes = tp.mes
);
