<?php
/**
 * Entry point de Finanzas Personales.
 *
 * - Si no existe backend/.env → redirige al instalador.
 * - Si existe → sirve el SPA compilado (dist/index.html).
 *
 * Las rutas de assets se reescriben automáticamente para funcionar
 * tanto en la raíz del dominio como en un subdirectorio.
 */

$envPath   = __DIR__ . '/backend/.env';
$lockPath  = __DIR__ . '/backend/install.lock';
$indexPath = __DIR__ . '/dist/index.html';

// ── Si no está instalado → instalar ──────────────────────────
if (!is_file($envPath) || !is_file($lockPath)) {
    // Detectar si estamos en un subdirectorio (ej: /pagos/) para construir
    // la URL del instalador correctamente.
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $base   = rtrim($script, '/');
    header("Location: {$base}/backend/install/");
    exit;
}

// ── Servir el SPA ─────────────────────────────────────────────
if (!is_file($indexPath)) {
    http_response_code(503);
    echo '<p style="font-family:sans-serif;padding:40px">Frontend no compilado. '
       . 'Ejecutá <code>./release.sh</code> o <code>cd frontend && npm run build</code>.</p>';
    exit;
}

$html = file_get_contents($indexPath);

// Los assets compilados están en /dist/assets/ pero el HTML los referencia
// como /assets/ (ruta absoluta de Vite). Reescribimos siempre para apuntar
// a la ruta real, sea dominio raíz o subdirectorio.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$html = preg_replace('#(src|href)="/assets/#', '$1="' . $base . '/dist/assets/', $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
