<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/auth';

if ($method === 'POST' && str_ends_with($uri, '/login')) {
    $b = body();
    $email = trim($b['email'] ?? '');
    $pass  = trim($b['password'] ?? '');
    if (!$email || !$pass) err('Email y contraseña requeridos.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, nombre, email, password_hash FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($pass, $user['password_hash'])) err('Credenciales incorrectas.', 401);

    // Limpiar sesiones expiradas de este usuario
    $db->prepare("DELETE FROM sesiones WHERE usuario_id = ? AND expira_en < NOW()")->execute([$user['id']]);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare("INSERT INTO sesiones (usuario_id, token, expira_en) VALUES (?,?,?)")
       ->execute([$user['id'], $token, $expires]);

    ok(['token' => $token, 'nombre' => $user['nombre'], 'email' => $user['email']]);
}

if ($method === 'POST' && str_ends_with($uri, '/logout')) {
    $user = requireAuth();
    $db   = getDB();
    $db->prepare("DELETE FROM sesiones WHERE token = ?")->execute([getToken()]);
    ok();
}

err('Acción no reconocida.', 404);
