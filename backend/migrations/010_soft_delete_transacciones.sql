-- Papelera: borrado suave de transacciones.
-- eliminada_at = NULL → activa; con fecha → en la papelera.
ALTER TABLE transacciones
  ADD COLUMN IF NOT EXISTS eliminada_at DATETIME DEFAULT NULL
  AFTER updated_at;

-- Índice para que filtrar "no eliminadas" sea barato
ALTER TABLE transacciones
  ADD INDEX IF NOT EXISTS idx_eliminada (usuario_id, eliminada_at);
