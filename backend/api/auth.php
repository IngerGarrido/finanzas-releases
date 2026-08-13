<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/auth';

if ($method === 'POST' && str_ends_with($uri, '/login')) {
    $b = body();
    $email = trim($b['email'] ?? '');
    $pass  = trim($b['password'] ?? '');
    if (!$email || !$pass) err('Email y contraseña requeridos.');

    // Rate limiting: máx. 5 intentos fallidos por IP+email en 15 minutos
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $rlKey = 'login_fail_' . $ip . '_' . strtolower($email);
    $fails = (int)(cacheGet($rlKey) ?? 0);
    if ($fails >= 5) err('Demasiados intentos fallidos. Espera unos minutos e intenta de nuevo.', 429);

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, nombre, email, password_hash FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($pass, $user['password_hash'])) {
        cacheSet($rlKey, $fails + 1, 900);   // recordar el fallo 15 min
        err('Credenciales incorrectas.', 401);
    }
    // Login correcto → limpiar contador
    cacheSet($rlKey, 0, 1);

    // Limpiar sesiones expiradas de este usuario
    $db->prepare("DELETE FROM sesiones WHERE usuario_id = ? AND expira_en < NOW()")->execute([$user['id']]);

    // El token plano se entrega al cliente; en la BD solo guardamos su hash.
    // Así, si la BD se filtra, los tokens almacenados no son utilizables.
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare("INSERT INTO sesiones (usuario_id, token, expira_en) VALUES (?,?,?)")
       ->execute([$user['id'], $tokenHash, $expires]);

    ok(['token' => $token, 'nombre' => $user['nombre'], 'email' => $user['email']]);
}

if ($method === 'POST' && str_ends_with($uri, '/logout')) {
    $user = requireAuth();
    $db   = getDB();
    $db->prepare("DELETE FROM sesiones WHERE token = ?")->execute([hash('sha256', getToken())]);
    ok();
}

// ── Solicitar recuperación de contraseña ──────────
if ($method === 'POST' && str_ends_with($uri, '/forgot')) {
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $db    = getDB();

    // Rate limit: máx. 3 solicitudes por IP en 15 minutos (evita spam de correos)
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $rlKey = 'forgot_' . $ip;
    $tries = (int)(cacheGet($rlKey) ?? 0);
    if ($tries >= 3) err('Demasiadas solicitudes. Espera unos minutos.', 429);
    cacheSet($rlKey, $tries + 1, 900);

    if ($email) {
        $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u) {
            // Token de un solo uso (se guarda hasheado), válido 1 hora
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            $db->prepare("DELETE FROM password_resets WHERE usuario_id = ?")->execute([$u['id']]);
            $db->prepare("INSERT INTO password_resets (usuario_id, token, expira_en) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
               ->execute([$u['id'], $hash]);

            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $link  = "$proto://$host/?reset=" . $token;
            $hostBare = preg_replace('/:\d+$/', '', $host);

            $subject = '=?UTF-8?B?' . base64_encode('Recupera tu contraseña — Finanzas') . '?=';
            $cuerpo  = "Hola {$u['nombre']},\n\n"
                     . "Recibimos una solicitud para cambiar tu contraseña.\n"
                     . "Entra a este enlace (válido por 1 hora):\n\n$link\n\n"
                     . "Si no fuiste tú, ignora este correo: tu contraseña no cambiará.\n";
            $headers = "From: Finanzas <no-reply@$hostBare>\r\n"
                     . "Content-Type: text/plain; charset=utf-8\r\n";
            @mail($email, $subject, $cuerpo, $headers);
        }
    }
    // Respuesta neutra: no revelar si el correo existe
    ok(['message' => 'Si el correo está registrado, te enviamos las instrucciones.']);
}

// ── Confirmar nueva contraseña con el token ───────
if ($method === 'POST' && str_ends_with($uri, '/reset')) {
    $b     = body();
    $token = trim($b['token'] ?? '');
    $pass  = (string)($b['password'] ?? '');
    if (strlen($pass) < 6) err('La contraseña debe tener al menos 6 caracteres.');
    if (!$token) err('Enlace inválido.', 400);

    $db   = getDB();
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT usuario_id FROM password_resets WHERE token = ? AND expira_en > NOW() LIMIT 1");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) err('El enlace es inválido o expiró. Solicita uno nuevo.', 400);

    $uid = (int)$row['usuario_id'];
    $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($pass, PASSWORD_BCRYPT), $uid]);
    $db->prepare("DELETE FROM password_resets WHERE usuario_id = ?")->execute([$uid]);
    // Cerrar todas las sesiones por seguridad
    $db->prepare("DELETE FROM sesiones WHERE usuario_id = ?")->execute([$uid]);
    ok(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
}

err('Acción no reconocida.', 404);
