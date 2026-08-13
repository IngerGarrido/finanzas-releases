<?php
require_once __DIR__ . '/../config.php';
cors();
$me     = requireAuth();
$uid    = (int)$me['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── Sesiones activas del propio usuario ───────────────────────
if (($_GET['recurso'] ?? '') === 'sesiones') {
    $currentHash = hash('sha256', getToken());
    if ($method === 'GET') {
        $st = $db->prepare("SELECT id, creado_en, expira_en, token FROM sesiones
                            WHERE usuario_id=? AND expira_en > NOW() ORDER BY creado_en DESC");
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll() as $s) {
            $out[] = [
                'id'        => (int)$s['id'],
                'creado_en' => $s['creado_en'],
                'expira_en' => $s['expira_en'],
                'actual'    => hash_equals($currentHash, (string)$s['token']),
            ];
        }
        ok($out);
    }
    if ($method === 'DELETE') {
        if (($_GET['otras'] ?? '') === '1') {
            $db->prepare("DELETE FROM sesiones WHERE usuario_id=? AND token<>?")->execute([$uid, $currentHash]);
            ok();
        }
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM sesiones WHERE id=? AND usuario_id=?")->execute([$id, $uid]);
        ok();
    }
    err('Método no permitido.', 405);
}

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

    // ── Editar mi propio perfil (nombre / email) ─────────────
    if ($action === 'perfil') {
        $nombre = trim($b['nombre'] ?? '');
        $email  = strtolower(trim($b['email'] ?? ''));
        if (!$nombre || !$email) err('Nombre y correo son requeridos.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Correo inválido.');

        // El correo no puede estar usado por otro usuario
        $dup = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ?");
        $dup->execute([$email, $uid]);
        if ($dup->fetch()) err('Ese correo ya está en uso.');

        $db->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?")
           ->execute([$nombre, $email, $uid]);
        ok(['nombre' => $nombre, 'email' => $email]);
    }

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
    $nuevoId = (int)$db->lastInsertId();
    // Sembrar categorías de ejemplo para el usuario nuevo
    require_once __DIR__ . '/../seed_categorias.php';
    seedCategorias($db, $nuevoId);
    ok(['id' => $nuevoId], 201);
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
