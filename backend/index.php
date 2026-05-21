<?php
require_once __DIR__ . '/config.php';
cors();

$uri    = strtok($_SERVER['REQUEST_URI'], '?');
$method = $_SERVER['REQUEST_METHOD'];

// Normalizar: quitar prefijos según entorno
// dev MAMP:    /pagos/backend/dashboard  → /dashboard
// producción:  /api/dashboard            → /dashboard
$uri = preg_replace('#^(/pagos/backend|/api)#', '', $uri);
$uri = rtrim($uri, '/') ?: '/';

$routes = [
    'POST /auth'           => 'auth.php',
    'GET /dashboard'       => 'dashboard.php',
    'GET /transacciones'   => 'transacciones.php',
    'POST /transacciones'  => 'transacciones.php',
    'PUT /transacciones'   => 'transacciones.php',
    'DELETE /transacciones'=> 'transacciones.php',
    'GET /categorias'      => 'categorias.php',
    'POST /categorias'     => 'categorias.php',
    'PUT /categorias'      => 'categorias.php',
    'DELETE /categorias'   => 'categorias.php',
    'GET /ingresos'        => 'ingresos.php',
    'POST /ingresos'       => 'ingresos.php',
    'PUT /ingresos'        => 'ingresos.php',
    'DELETE /ingresos'     => 'ingresos.php',
    'GET /tarjeta'         => 'tarjeta.php',
    'POST /tarjeta'        => 'tarjeta.php',
    'PUT /tarjeta'         => 'tarjeta.php',
    'DELETE /tarjeta'      => 'tarjeta.php',
    'GET /ahorro'          => 'ahorro.php',
    'POST /ahorro'         => 'ahorro.php',
    'PUT /ahorro'          => 'ahorro.php',
    'DELETE /ahorro'       => 'ahorro.php',
    'GET /pendientes'      => 'pendientes.php',
    'POST /pendientes'     => 'pendientes.php',
    'PUT /pendientes'      => 'pendientes.php',
    'DELETE /pendientes'   => 'pendientes.php',
    'GET /presupuesto'     => 'presupuesto.php',
    'POST /presupuesto'    => 'presupuesto.php',
    'PUT /presupuesto'     => 'presupuesto.php',
    'DELETE /presupuesto'  => 'presupuesto.php',
    'GET /recurrentes'     => 'recurrentes.php',
    'POST /recurrentes'    => 'recurrentes.php',
    'PUT /recurrentes'     => 'recurrentes.php',
    'DELETE /recurrentes'  => 'recurrentes.php',
];

// Rutas admin con sub-path (ej: /admin/actualizar, /admin/migraciones)
$adminRoutes = [
    '/admin/actualizar'  => 'admin/actualizar.php',
    '/admin/migraciones' => 'admin/migraciones.php',
];
if (isset($adminRoutes[$uri])) {
    $_SERVER['PATH_INFO'] = $uri;
    require_once __DIR__ . '/api/' . $adminRoutes[$uri];
    exit;
}

// Clave: METHOD + primer segmento del path
$base = '/' . explode('/', ltrim($uri, '/'))[0];
$key  = "$method $base";

if (!isset($routes[$key])) {
    err('Ruta no encontrada: ' . $key, 404);
}

$_SERVER['PATH_INFO'] = $uri;
require_once __DIR__ . '/api/' . $routes[$key];
