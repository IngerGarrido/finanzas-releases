<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name); $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

loadEnv(__DIR__ . '/.env');

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'finanzas_personales');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: 'root');
define('APP_ENV',    getenv('APP_ENV')    ?: 'production');
define('APP_DEBUG',  getenv('APP_DEBUG')  === 'true');
define('CORS_ORIGIN',getenv('CORS_ORIGIN')?: 'http://localhost:5174');
define('LOG_LEVEL',  getenv('LOG_LEVEL')  ?: 'info');
define('LOG_FILE',   getenv('LOG_FILE')   ?: __DIR__ . '/logs/app.log');
define('CACHE_DIR',  __DIR__ . '/cache');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,   // IDs como enteros, no strings
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $pdo;
}

function cors(): void {
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', CORS_ORIGIN)));
    if (in_array('*', $allowed, true) && APP_DEBUG) {
        header("Access-Control-Allow-Origin: *");
    } elseif ($origin && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Vary: Origin");
    } elseif (!empty($allowed)) {
        header("Access-Control-Allow-Origin: " . $allowed[0]);
        header("Vary: Origin");
    }
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=utf-8");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function body(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }

function ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function errSafe(Throwable $e, int $code = 500): never {
    error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code($code);
    $payload = ['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Error interno del servidor'];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function getToken(): ?string {
    $candidates = [];
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach (['Authorization','authorization'] as $k) {
            if (!empty($h[$k])) $candidates[] = $h[$k];
        }
    }
    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) $candidates[] = $_SERVER[$k];
    }
    foreach ($candidates as $v) {
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($v), $m)) return $m[1];
    }
    return null;
}

function requireAuth(): array {
    $token = getToken();
    if (!$token) err('No autenticado.', 401);
    $db   = getDB();
    $stmt = $db->prepare("SELECT u.id, u.nombre, u.email, COALESCE(u.is_admin, 0) as is_admin
                          FROM usuarios u
                          JOIN sesiones s ON u.id = s.usuario_id
                          WHERE s.token = ? AND s.expira_en > NOW() AND u.activo = 1 LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) err('Sesión inválida o expirada.', 401);
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    try {
        // Si el usuario ya es admin, pasa directo
        if ((int)$user['is_admin']) return $user;
        // Si la columna no existe aún (migración pendiente) o no hay ningún admin
        // configurado → modo bootstrap: cualquier usuario autenticado puede acceder
        $db    = getDB();
        $count = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE is_admin = 1")->fetchColumn();
        if ($count === 0) return $user;   // todavía no hay admin → dejar pasar
        err('Acceso restringido — se requiere rol administrador.', 403);
    } catch (Throwable $e) {
        // La columna is_admin no existe aún (migración no aplicada) → bootstrap
        return $user;
    }
}

function cacheGet(string $key): mixed {
    $file = CACHE_DIR . '/' . md5($key) . '.cache';
    if (!file_exists($file)) return null;
    $data = unserialize(file_get_contents($file));
    if ($data['expires'] < time()) { unlink($file); return null; }
    return $data['value'];
}

function cacheSet(string $key, mixed $value, int $ttl = 300): void {
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
    file_put_contents(CACHE_DIR . '/' . md5($key) . '.cache',
        serialize(['expires' => time() + $ttl, 'value' => $value]), LOCK_EX);
}

function cacheClear(): void {
    foreach (glob(CACHE_DIR . '/*.cache') ?: [] as $f) unlink($f);
}
