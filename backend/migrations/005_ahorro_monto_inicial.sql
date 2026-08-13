-- Agrega monto_inicial para rastrear el monto inicial de una meta de ahorro
-- independiente de los aportes posteriores.
-- monto_actual se calculará dinámicamente como monto_inicial + SUM(aportes)

ALTER TABLE metas_ahorro
  ADD COLUMN IF NOT EXISTS monto_inicial DECIMAL(14,2) NOT NULL DEFAULT 0
  AFTER monto_meta;

-- Poblar monto_inicial con el valor que queda una vez restados todos los aportes.
-- Si la diferencia es negativa (por inconsistencias previas), se deja en 0.
UPDATE metas_ahorro m
SET m.monto_inicial = GREATEST(0,
    m.monto_actual - COALESCE(
        (SELECT SUM(a.monto) FROM metas_ahorro_aportes a WHERE a.meta_id = m.id),
        0
    )
);
