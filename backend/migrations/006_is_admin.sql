-- Agrega columna is_admin a usuarios para control de acceso al panel de administración

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0
  AFTER activo;

-- El primer usuario registrado es el administrador del sistema
UPDATE usuarios SET is_admin = 1 ORDER BY id ASC LIMIT 1;
