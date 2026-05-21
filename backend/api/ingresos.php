<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/ingresos';

// ── /ingresos/fuentes ─────────────────────────────
if (strpos($uri, '/ingresos/fuentes') === 0) {
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, nombre, tipo FROM fuentes_ingreso WHERE usuario_id = ? AND activa = 1 ORDER BY nombre");
        $stmt->execute([$uid]);
        ok($stmt->fetchAll());
    }
    if ($method === 'POST') {
        $b = body();
        if (!trim($b['nombre'] ?? '')) err('Nombre requerido.');
        $db->prepare("INSERT INTO fuentes_ingreso (usuario_id, nombre, tipo) VALUES (?,?,?)")
           ->execute([$uid, trim($b['nombre']), $b['tipo'] ?? 'fijo']);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("UPDATE fuentes_ingreso SET activa = 0 WHERE id = ? AND usuario_id = ?")
           ->execute([$id, $uid]);
        ok();
    }
    err('Método no permitido.', 405);
}

// ── /ingresos ─────────────────────────────────────
if ($method === 'GET') {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    $mes  = (int)($_GET['mes']  ?? date('n'));
    $stmt = $db->prepare("
        SELECT i.*, f.nombre as fuente
        FROM ingresos i
        LEFT JOIN fuentes_ingreso f ON f.id = i.fuente_id
        WHERE i.usuario_id = ? AND i.anio = ? AND i.mes = ?
        ORDER BY i.fecha_recibo, i.id
    ");
    $stmt->execute([$uid, $anio, $mes]);
    $items = $stmt->fetchAll();
    ok(['items' => $items]);
}

if ($method === 'POST') {
    $b = body();
    $db->prepare("
        INSERT INTO ingresos (usuario_id, fuente_id, anio, mes, descripcion, planificado, actual, fecha_recibo)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $uid,
        $b['fuente_id'] ?: null,
        (int)$b['anio'],
        (int)$b['mes'],
        trim($b['descripcion'] ?? ''),
        (float)($b['planificado'] ?? 0),
        (float)($b['actual'] ?? 0),
        $b['fecha_recibo'] ?: null,
    ]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    $db->prepare("
        UPDATE ingresos
        SET fuente_id=?, descripcion=?, planificado=?, actual=?, fecha_recibo=?
        WHERE id=? AND usuario_id=?
    ")->execute([
        $b['fuente_id'] ?: null,
        trim($b['descripcion'] ?? ''),
        (float)($b['planificado'] ?? 0),
        (float)($b['actual'] ?? 0),
        $b['fecha_recibo'] ?: null,
        $id, $uid,
    ]);
    ok();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM ingresos WHERE id = ? AND usuario_id = ?")->execute([$id, $uid]);
    ok();
}

err('Método no permitido.', 405);
