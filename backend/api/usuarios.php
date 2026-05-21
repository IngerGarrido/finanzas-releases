<?php
require_once __DIR__ . '/../config.php';
cors();
$me     = requireAuth();
$uid    = (int)$me['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: listar usuarios (solo admin) ─────────────────────────
if ($method === 'GET') {
    requireAdmin();
    $stmt = $db->prepare("
        SELECT id, nombre, email,
               COALESCE(is_admin,0) AS is_admin,
               activo,
               DATE_FORMAT(created_at,'%d/%m/%Y') AS creado
        FROM usuarios
        ORDER BY id
    ");
    $stmt->execute();
    ok($stmt->fetchAll());
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'crear';

    // ── Cambiar propia contraseña (sin requireAdmin) ──────────
    if ($action === 'cambiar_pass') {
        $old = $b['password_actual'] ?? '';
        $new = $b['password_nueva']  ?? '';
        if (strlen($new) < 6) err('La contraseña debe tener al menos 6 caracteres.');

        $row = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $row->execute([$uid]);
        $row = $row->fetch();
        if (!$row || !password_verify($old, $row['password_hash'])) err('Contraseña actual incorrecta.');

        $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
        ok(['message' => 'Contraseña actualizada']);
    }

    // ── Crear usuario (admin only) ────────────────────────────
    requireAdmin();
    $nombre = trim($b['nombre'] ?? '');
    $email  = strtolower(trim($b['email'] ?? ''));
    $pass   = $b['password'] ?? '';
    if (!$nombre || !$email)   err('Nombre y email son requeridos.');
    if (strlen($pass) < 6)     err('La contraseña debe tener al menos 6 caracteres.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Email inválido.');

    $dup = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $dup->execute([$email]);
    if ($dup->fetch()) err('Ya existe un usuario con ese correo.');

    $db->prepare("
        INSERT INTO usuarios (nombre, email, password_hash, is_admin, activo)
        VALUES (?,?,?,?,1)
    ")->execute([$nombre, $email, password_hash($pass, PASSWORD_BCRYPT), (int)($b['is_admin'] ?? 0)]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) err('ID requerido.');

    // Resetear contraseña → admin puede hacerlo a cualquiera
    if (isset($b['password_nueva'])) {
        if ($id !== $uid) requireAdmin();
        if (strlen($b['password_nueva']) < 6) err('Mínimo 6 caracteres.');
        $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
           ->execute([password_hash($b['password_nueva'], PASSWORD_BCRYPT), $id]);
        ok();
    }

    // Editar datos
    $nombre = trim($b['nombre'] ?? '');
    $email  = strtolower(trim($b['email'] ?? ''));
    if (!$nombre || !$email) err('Nombre y email son requeridos.');

    if ($id !== $uid) {
        // Admin edita otro usuario
        requireAdmin();
        $db->prepare("UPDATE usuarios SET nombre=?, email=?, is_admin=?, activo=? WHERE id=?")
           ->execute([$nombre, $email, (int)($b['is_admin'] ?? 0), (int)($b['activo'] ?? 1), $id]);
    } else {
        // Usuario edita su propio perfil
        $db->prepare("UPDATE usuarios SET nombre=?, email=? WHERE id=?")
           ->execute([$nombre, $email, $id]);
    }
    ok();
}

// ── DELETE: desactivar usuario (admin only) ───────────────────
if ($method === 'DELETE') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if ($id === $uid) err('No puedes desactivar tu propia cuenta.');
    $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?")->execute([$id]);
    ok();
}

err('Método no permitido.', 405);
