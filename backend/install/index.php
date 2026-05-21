<?php
/**
 * Instalador web de Finanzas Personales.
 *
 * Pasos:
 *   1. Bienvenida + verificación de requisitos
 *   2. Conexión a base de datos
 *   3. Crear tablas (schema.sql)
 *   4. Crear usuario
 *   5. Listo (escribe .env + lock + redirige)
 *
 * Seguridad:
 *   - Si existe backend/install.lock, bloquea el instalador.
 *   - Sesión PHP para acarrear datos entre pasos.
 */

session_start();

$ROOT   = realpath(__DIR__ . '/..');   // backend/
$LOCK   = $ROOT . '/install.lock';
$ENV    = $ROOT . '/.env';
$SCHEMA = __DIR__ . '/schema.sql';

// ─── Bloqueo si ya está instalado ──────────────────────────────
if (file_exists($LOCK) && ($_GET['action'] ?? '') !== 'force') {
    render_installed($LOCK);
    exit;
}

// ─── Estado entre pasos ────────────────────────────────────────
if (!isset($_SESSION['install'])) $_SESSION['install'] = [];
$S = &$_SESSION['install'];

$step   = max(1, min(5, (int)($_GET['step'] ?? 1)));
$errors = [];

// ─── Manejo de POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_step = (int)($_POST['step'] ?? 0);

    // Paso 2: validar BD
    if ($post_step === 2) {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $port = trim($_POST['db_port'] ?? '3306');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            $errors[] = 'Host, nombre de base de datos y usuario son obligatorios.';
        }

        if (empty($errors)) {
            try {
                // Conectar sin DB para poder crearla si no existe
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                // Reconectar con la BD
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $S['db'] = compact('host', 'port', 'name', 'user', 'pass');
                header('Location: index.php?step=3'); exit;
            } catch (Throwable $e) {
                $errors[] = 'No se pudo conectar a MySQL: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }
        $step = 2;
    }

    // Paso 3: ejecutar schema
    if ($post_step === 3) {
        if (empty($S['db'])) { $errors[] = 'Falta configurar la base de datos.'; $step = 2; }
        else {
            try {
                $pdo = pdo_from_session($S['db']);
                $sql = file_get_contents($SCHEMA);
                if ($sql === false) throw new RuntimeException('No se encontró schema.sql');
                // Eliminar CREATE DATABASE y USE (el instalador ya conectó a la BD correcta)
                $sql = preg_replace('/^\s*CREATE\s+DATABASE\b[^;]*;/im', '', $sql);
                $sql = preg_replace('/^\s*USE\s+\S+\s*;/im', '', $sql);
                // Ejecutar statement por statement para mejor manejo de errores
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '') $pdo->exec($stmt);
                }
                // Marcar todas las migraciones como ya aplicadas (schema ya las incluye)
                $pdo->exec("CREATE TABLE IF NOT EXISTS migraciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    archivo VARCHAR(255) NOT NULL UNIQUE,
                    ejecutada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    duracion_ms INT DEFAULT NULL,
                    checksum CHAR(64) DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $migDir = realpath(__DIR__ . '/../migrations');
                if ($migDir && is_dir($migDir)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO migraciones (archivo, duracion_ms, checksum) VALUES (?, 0, ?)");
                    foreach (glob($migDir . '/*.sql') as $f) {
                        $ins->execute([basename($f), hash_file('sha256', $f)]);
                    }
                }
                $S['schema_ok'] = true;
                header('Location: index.php?step=4'); exit;
            } catch (Throwable $e) {
                $errors[] = 'Error creando tablas: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
                $step = 3;
            }
        }
    }

    // Paso 4: crear usuario
    if ($post_step === 4) {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = strtolower(trim($_POST['email'] ?? ''));
        $pass   = (string)($_POST['pass'] ?? '');
        $pass2  = (string)($_POST['pass2'] ?? '');
        $url    = rtrim(trim($_POST['app_url'] ?? ''), '/');

        if (mb_strlen($nombre) < 2)                     $errors[] = 'El nombre es muy corto.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Email inválido.';
        if (mb_strlen($pass) < 8)                        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($pass !== $pass2)                            $errors[] = 'Las contraseñas no coinciden.';
        if ($url === '')                                  $errors[] = 'La URL de la app es obligatoria.';

        if (empty($errors)) {
            try {
                $pdo  = pdo_from_session($S['db']);
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $upd = $pdo->prepare("UPDATE usuarios SET nombre=?, password_hash=?, activo=1 WHERE id=?");
                    $upd->execute([$nombre, $hash, $existing['id']]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO usuarios (nombre, email, password_hash, activo) VALUES (?, ?, ?, 1)");
                    $ins->execute([$nombre, $email, $hash]);
                }
                // Escribir .env y lock
                write_env($ENV, $S['db'], $url);
                @file_put_contents($LOCK, date('c') . " | instalado por " . $email . "\n");
                $S['done']    = true;
                $S['app_url'] = $url;
                header('Location: index.php?step=5'); exit;
            } catch (Throwable $e) {
                $errors[] = 'Error creando usuario: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }
        $step = 4;
    }
}

render_page($step, $errors, $S);

// ═════════════════════════════════════════════════════════════════
// FUNCIONES
// ═════════════════════════════════════════════════════════════════

function pdo_from_session(array $db): PDO {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    return new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function write_env(string $envPath, array $db, string $appUrl): void {
    $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
    $env  = "# Generado por el instalador el " . date('c') . "\n";
    $env .= "DB_HOST={$db['host']}\n";
    $env .= "DB_PORT={$db['port']}\n";
    $env .= "DB_NAME={$db['name']}\n";
    $env .= "DB_USER={$db['user']}\n";
    $env .= "DB_PASS={$db['pass']}\n\n";
    $env .= "APP_ENV=production\n";
    $env .= "APP_DEBUG=false\n\n";
    $env .= "CORS_ORIGIN={$appUrl}\n\n";
    $env .= "LOG_LEVEL=info\n";
    $env .= "LOG_FILE=logs/app.log\n\n";
    $env .= "CACHE_DRIVER=file\n";
    $env .= "CACHE_TTL=300\n\n";
    $env .= "# Actualización automática desde GitHub\n";
    $env .= "GITHUB_REPO=IngerGarrido/finanzas-releases\n";
    $env .= "GITHUB_BRANCH=main\n";
    $env .= "# GITHUB_TOKEN=ghp_xxxx\n";

    if (file_put_contents($envPath, $env) === false) {
        throw new RuntimeException("No se pudo escribir {$envPath}. Verifica permisos del servidor.");
    }
    @chmod($envPath, 0640);
}

function check_requirements(): array {
    $checks = [];
    $checks[] = ['label' => 'PHP 8.0 o superior', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'hint' => 'Versión actual: ' . PHP_VERSION];
    foreach (['pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = ['label' => "Extensión {$ext}", 'ok' => $ok, 'hint' => $ok ? 'OK' : 'Falta. Habilitá en el panel de PHP de tu hosting.'];
    }
    $backend = realpath(__DIR__ . '/..');
    foreach (['', '/logs', '/cache'] as $d) {
        $p = $backend . $d;
        if ($d !== '' && !is_dir($p)) @mkdir($p, 0755, true);
        $checks[] = ['label' => 'Permiso escritura en backend' . ($d ?: '/'), 'ok' => is_writable($p), 'hint' => is_writable($p) ? 'Escribible ✓' : 'Sin permiso. chmod 755 en cPanel.'];
    }
    return $checks;
}

function render_installed(string $lock): void {
    $when = trim((string)@file_get_contents($lock));
    layout_start('Ya instalado');
    echo '<div class="card">';
    echo '<h2>✓ La aplicación ya está instalada</h2>';
    echo '<p>Por seguridad, no se puede volver a ejecutar el instalador.</p>';
    if ($when) echo '<p style="color:#888;font-size:13px">Instalado el: <code>' . htmlspecialchars($when) . '</code></p>';
    echo '<p style="margin-top:18px"><strong>¿Necesitás reinstalar?</strong></p>';
    echo '<ol style="font-size:14px;line-height:1.8">';
    echo '<li>Eliminá el archivo <code>backend/install.lock</code></li>';
    echo '<li>Eliminá <code>backend/.env</code> si querés reconfigurar la BD</li>';
    echo '<li><strong>Eliminá la carpeta <code>backend/install/</code> cuando termines.</strong></li>';
    echo '</ol>';
    echo '<p style="margin-top:22px"><a class="btn" href="../../">Ir a la app →</a></p>';
    echo '</div>';
    layout_end();
}

function render_page(int $step, array $errors, array $S): void {
    layout_start("Instalación · Paso {$step} de 5");
    $titulos = ['Inicio', 'Base de datos', 'Crear tablas', 'Tu usuario', '¡Listo!'];
    ?>
    <div class="wizard">
      <ol class="steps">
        <?php foreach ($titulos as $i => $t):
            $n = $i + 1;
            $cls = $n < $step ? 'done' : ($n === $step ? 'cur' : '');
        ?>
          <li class="<?= $cls ?>"><span><?= $n ?></span><?= $t ?></li>
        <?php endforeach; ?>
      </ol>

      <?php if ($errors): ?>
        <div class="alert err">
          <strong>Corregí lo siguiente:</strong>
          <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <div class="card">
      <?php
        switch ($step) {
            case 1: step1(); break;
            case 2: step2($S); break;
            case 3: step3($S); break;
            case 4: step4(); break;
            case 5: step5($S); break;
        }
      ?>
      </div>
    </div>
    <?php
    layout_end();
}

function step1(): void {
    $checks = check_requirements();
    $all_ok = array_reduce($checks, fn($a, $c) => $a && $c['ok'], true);
    ?>
    <h2>Bienvenido al instalador</h2>
    <p>Este asistente configura la app de finanzas en pocos pasos. Tenés que tener a mano:</p>
    <ul>
      <li>Los datos de tu base de datos MySQL (host, usuario, contraseña, nombre).</li>
      <li>El email y contraseña con los que querés acceder.</li>
      <li>La URL donde va a correr la app (ej: <code>https://tudominio.com</code>).</li>
    </ul>
    <h3 style="margin-top:22px">Requisitos del servidor</h3>
    <table class="req">
      <?php foreach ($checks as $c): ?>
        <tr>
          <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></td>
          <td><?= htmlspecialchars($c['label']) ?></td>
          <td><?= htmlspecialchars($c['hint']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div class="actions">
      <?php if ($all_ok): ?>
        <span></span><a class="btn" href="?step=2">Continuar →</a>
      <?php else: ?>
        <div class="alert warn">Corregí los problemas de arriba antes de continuar.</div>
        <a class="btn ghost" href="?step=1">Volver a verificar</a>
      <?php endif; ?>
    </div>
    <?php
}

function step2(array $S): void {
    $db = $S['db'] ?? ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => ''];
    ?>
    <h2>Base de datos</h2>
    <p>Ingresá los datos de tu base de datos MySQL. Si no existe, la crearemos automáticamente.</p>
    <form method="post">
      <input type="hidden" name="step" value="2">
      <div class="row2">
        <label>Host <input type="text" name="db_host" value="<?= h($db['host']) ?>" placeholder="localhost" required></label>
        <label>Puerto <input type="text" name="db_port" value="<?= h($db['port']) ?>" placeholder="3306"></label>
      </div>
      <label>Nombre de la base de datos <input type="text" name="db_name" value="<?= h($db['name']) ?>" placeholder="finanzas_db" required></label>
      <label>Usuario <input type="text" name="db_user" value="<?= h($db['user']) ?>" required></label>
      <label>Contraseña <input type="password" name="db_pass" placeholder="••••••••"></label>
      <div class="actions">
        <a class="btn ghost" href="?step=1">← Atrás</a>
        <button class="btn" type="submit">Probar conexión →</button>
      </div>
    </form>
    <?php
}

function step3(array $S): void {
    if (empty($S['db'])) { echo '<p>Falta configurar la BD. <a href="?step=2">Volvé al paso 2</a>.</p>'; return; }
    ?>
    <h2>Crear tablas</h2>
    <p>Vamos a crear todas las tablas en la base de datos <strong><?= h($S['db']['name']) ?></strong>.</p>
    <p style="color:#888;font-size:13px">Operación segura: usa <code>CREATE TABLE IF NOT EXISTS</code>. Si la BD ya tenía tablas, no las toca.</p>
    <form method="post">
      <input type="hidden" name="step" value="3">
      <div class="actions">
        <a class="btn ghost" href="?step=2">← Atrás</a>
        <button class="btn" type="submit">Crear tablas →</button>
      </div>
    </form>
    <?php
}

function step4(): void {
    $guess = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    ?>
    <h2>Tu usuario</h2>
    <p>Creá la cuenta con la que vas a acceder a la app.</p>
    <form method="post">
      <input type="hidden" name="step" value="4">
      <label>Nombre <input type="text" name="nombre" required minlength="2" placeholder="Tu nombre" autofocus></label>
      <label>Email <input type="email" name="email" required placeholder="tu@email.com"></label>
      <div class="row2">
        <label>Contraseña (mín. 8 caracteres) <input type="password" name="pass" required minlength="8"></label>
        <label>Repetir contraseña <input type="password" name="pass2" required minlength="8"></label>
      </div>
      <label>URL de la app
        <input type="url" name="app_url" required value="<?= h($guess) ?>" placeholder="https://tudominio.com">
        <small>Se usa para CORS y la configuración del sistema.</small>
      </label>
      <div class="actions">
        <a class="btn ghost" href="?step=3">← Atrás</a>
        <button class="btn" type="submit">Finalizar instalación →</button>
      </div>
    </form>
    <?php
}

function step5(array $S): void {
    $url = $S['app_url'] ?? '../../';
    ?>
    <div style="text-align:center; padding: 10px 0 6px">
      <div style="font-size:48px; margin-bottom:12px">🎉</div>
      <h2 style="color:#16a34a; margin-bottom:10px">¡Instalación completada!</h2>
      <p>La app está lista para usarse.</p>
    </div>
    <div class="alert warn" style="margin:18px 0">
      <strong>Importante por seguridad:</strong> eliminá la carpeta <code>backend/install/</code> del servidor apenas verifiques que la app funciona.
    </div>
    <p style="font-size:14px; color:#555">Lo que se configuró:</p>
    <ul style="font-size:14px; line-height:1.9; color:#444">
      <li>Base de datos creada y tablas listas</li>
      <li>Archivo <code>backend/.env</code> generado con tus credenciales</li>
      <li>Usuario creado y listo para entrar</li>
      <li>Migraciones registradas como aplicadas</li>
    </ul>
    <div class="actions" style="justify-content:center; margin-top:24px">
      <a class="btn" href="<?= h($url) ?>">Ir a la app →</a>
    </div>
    <?php
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function layout_start(string $title): void { ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
*{box-sizing:border-box}
body{font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;background:#F0EEE9;color:#1A1917;margin:0;padding:20px 16px 60px}
.wrap{max-width:660px;margin:0 auto}
h1{font-size:22px;font-weight:800;margin:0 0 24px;color:#17181A;display:flex;align-items:center;gap:10px}
h1 span{font-size:26px}
h2{font-size:19px;font-weight:700;margin:0 0 12px}
h3{font-size:15px;font-weight:700;margin:18px 0 8px}
p{margin:0 0 12px;color:#444}
ul{margin:0 0 12px;padding-left:20px;color:#444}
li{margin-bottom:4px}
code{background:#E5E3DC;padding:2px 6px;border-radius:4px;font-size:12px}
small{display:block;margin-top:4px;font-size:12px;color:#888}
.card{background:#fff;border:1px solid #E5E3DC;border-radius:12px;padding:26px 28px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.wizard{margin-top:0}
.steps{display:flex;list-style:none;padding:0;margin:0 0 20px;gap:5px;font-size:12px}
.steps li{flex:1;padding:9px 4px 8px;text-align:center;background:#fff;border:1px solid #E5E3DC;border-radius:8px;color:#AEABA2;line-height:1.3}
.steps li.cur{background:#17181A;color:#fff;border-color:#17181A;font-weight:700}
.steps li.done{background:#e6f4ec;color:#2A6B50;border-color:#a5d6be}
.steps li span{display:block;font-weight:800;font-size:15px;margin-bottom:2px}
label{display:block;margin:14px 0 0;font-weight:600;font-size:13px;color:#1A1917}
label input{display:block;width:100%;margin-top:5px;padding:9px 11px;border:1px solid #D5D3CC;border-radius:7px;font-size:14px;background:#FAFAF8;color:#1A1917;transition:border-color .12s}
label input:focus{outline:none;border-color:#2A6B50;background:#fff}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;background:#17181A;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;border:none;cursor:pointer;font-size:14px;font-weight:700;transition:opacity .12s}
.btn:hover{opacity:.85}
.btn.ghost{background:#E5E3DC;color:#1A1917}
.btn.green{background:#2A6B50}
.actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:22px;flex-wrap:wrap}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:13px}
.alert.err{background:#FAEDE8;color:#C0400D;border:1px solid #f1b8a0}
.alert.warn{background:#FEF3C7;color:#92600A;border:1px solid #fcd34d}
.alert ul{margin:6px 0 0;padding-left:18px}
table.req{width:100%;border-collapse:collapse;margin-top:8px}
table.req td{padding:8px;border-bottom:1px solid #F0EEE9;font-size:13px;color:#444}
table.req td.ok{color:#2A6B50;font-weight:800;width:28px}
table.req td.fail{color:#C0400D;font-weight:800;width:28px}
@media(max-width:500px){.row2{grid-template-columns:1fr}.steps li{font-size:10px}}
</style>
</head>
<body>
<div class="wrap">
  <h1><span>💰</span> Finanzas Personales — Instalador</h1>
<?php }

function layout_end(): void { echo '</div></body></html>'; }
