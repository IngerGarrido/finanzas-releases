-- Fecha límite (vencimiento) para los gastos pendientes / deudas
ALTER TABLE gastos_pendientes
  ADD COLUMN IF NOT EXISTS fecha_limite DATE DEFAULT NULL
  AFTER monto_total;
