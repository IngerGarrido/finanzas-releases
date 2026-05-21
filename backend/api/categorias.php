<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/categorias';

// ── GET ───────────────────────────────────────────
if ($method === 'GET') {
    // Categorías en una sola query (sin N+1)
    $stmt = $db->prepare("
        SELECT c.id, c.nombre, c.tipo, c.icono, c.color, c.activa, c.orden,
               s.id as sub_id, s.nombre as sub_nombre, s.activa as sub_activa
        FROM categorias c
        LEFT JOIN subcategorias s ON s.categoria_id = c.id
        WHERE c.usuario_id = ?
        ORDER BY c.orden, c.id, s.nombre
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    // Agrupar subcategorías por categoría en PHP
    $cats = []; $idx = [];
    foreach ($rows as $r) {
        $cid = $r['id'];
        if (!isset($idx[$cid])) {
            $idx[$cid] = count($cats);
            $cats[] = [
                'id' => $cid, 'nombre' => $r['nombre'], 'tipo' => $r['tipo'],
                'icono' => $r['icono'], 'color' => $r['color'],
                'activa' => $r['activa'], 'orden' => $r['orden'],
                'subcategorias' => [],
            ];
        }
        if ($r['sub_id'] !== null) {
            $cats[$idx[$cid]]['subcategorias'][] = [
                'id' => $r['sub_id'], 'nombre' => $r['sub_nombre'], 'activa' => $r['sub_activa'],
            ];
        }
    }

    ok(array_values($cats));
}

// ── POST ──────────────────────────────────────────
if ($method === 'POST') {
    $b    = body();
    $tipo = $b['tipo_registro'] ?? 'categoria'; // 'categoria' o 'subcategoria'

    if ($tipo === 'subcategoria') {
        $catId = (int)($b['categoria_id'] ?? 0);
        if (!$catId || !trim($b['nombre'] ?? '')) err('Datos inválidos.');
        // Verificar que la categoría pertenece al usuario
        $chk = $db->prepare("SELECT id FROM categorias WHERE id = ? AND usuario_id = ?");
        $chk->execute([$catId, $uid]);
        if (!$chk->fetch()) err('Categoría no encontrada.', 404);

        $db->prepare("INSERT INTO subcategorias (categoria_id, nombre) VALUES (?,?)")
           ->execute([$catId, trim($b['nombre'])]);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }

    // Nueva categoría
    if (!trim($b['nombre'] ?? '') || !in_array($b['tipo'] ?? '', ['necesidad','discrecional','ahorro']))
        err('Nombre y tipo requeridos.');

    $stmtOrd = $db->prepare("SELECT COALESCE(MAX(orden),0) FROM categorias WHERE usuario_id = ?");
    $stmtOrd->execute([$uid]);
    $maxOrden = (int)$stmtOrd->fetchColumn();
    $db->prepare("INSERT INTO categorias (usuario_id, nombre, tipo, icono, color, orden) VALUES (?,?,?,?,?,?)")
       ->execute([$uid, trim($b['nombre']), $b['tipo'], $b['icono'] ?? '📦', $b['color'] ?? '#6B7280', $maxOrden + 1]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

// ── PUT ───────────────────────────────────────────
if ($method === 'PUT') {
    $b    = body();
    $tipo = $b['tipo_registro'] ?? 'categoria';

    if ($tipo === 'subcategoria') {
        $id = (int)($b['id'] ?? 0);
        $db->prepare("UPDATE subcategorias s
                      JOIN categorias c ON s.categoria_id = c.id
                      SET s.nombre = ?, s.activa = ?
                      WHERE s.id = ? AND c.usuario_id = ?")
           ->execute([trim($b['nombre']), (int)($b['activa'] ?? 1), $id, $uid]);
        ok();
    }

    $id = (int)($b['id'] ?? 0);
    $db->prepare("UPDATE categorias SET nombre=?, tipo=?, icono=?, color=?, activa=?, orden=?
                  WHERE id=? AND usuario_id=?")
       ->execute([
           trim($b['nombre']), $b['tipo'], $b['icono'] ?? '📦',
           $b['color'] ?? '#6B7280', (int)($b['activa'] ?? 1),
           (int)($b['orden'] ?? 0), $id, $uid,
       ]);
    ok();
}

// ── DELETE ────────────────────────────────────────
if ($method === 'DELETE') {
    $id   = (int)($_GET['id'] ?? 0);
    $tipo = $_GET['tipo_registro'] ?? 'categoria';

    if ($tipo === 'subcategoria') {
        $db->prepare("DELETE s FROM subcategorias s
                      JOIN categorias c ON s.categoria_id = c.id
                      WHERE s.id = ? AND c.usuario_id = ?")
           ->execute([$id, $uid]);
        ok();
    }

    $db->prepare("DELETE FROM categorias WHERE id = ? AND usuario_id = ?")->execute([$id, $uid]);
    ok();
}

err('Método no permitido.', 405);
