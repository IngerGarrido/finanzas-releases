<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/ahorro';

// ── /ahorro/aportes ───────────────────────────────
if (strpos($uri, '/ahorro/aportes') === 0) {
    if ($method === 'POST') {
        $b      = body();
        $metaId = (int)($b['meta_id'] ?? 0);
        // Verificar que la meta pertenece al usuario
        $ok = $db->prepare("SELECT id FROM metas_ahorro WHERE id = ? AND usuario_id = ?");
        $ok->execute([$metaId, $uid]);
        if (!$ok->fetch()) err('Meta no encontrada.', 404);

        $db->prepare("INSERT INTO metas_ahorro_aportes (meta_id, fecha, monto, nota) VALUES (?,?,?,?)")
           ->execute([$metaId, $b['fecha'] ?? date('Y-m-d'), (float)$b['monto'], trim($b['nota'] ?? '')]);

        // monto_actual se recalcula dinámicamente en GET — no necesita update manual

        ok(['id' => (int)$db->lastInsertId()], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        // Obtener el monto antes de borrar
        $row = $db->prepare("SELECT a.monto, a.meta_id FROM metas_ahorro_aportes a
                              JOIN metas_ahorro m ON a.meta_id = m.id
                              WHERE a.id = ? AND m.usuario_id = ?");
        $row->execute([$id, $uid]);
        $aporte = $row->fetch();
        if (!$aporte) err('Aporte no encontrado.', 404);

        $db->prepare("DELETE FROM metas_ahorro_aportes WHERE id = ?")->execute([$id]);
        // monto_actual se recalcula dinámicamente en GET — no necesita update manual
        ok();
    }
    err('Método no permitido.', 405);
}

// ── /ahorro ───────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->prepare("SELECT * FROM metas_ahorro WHERE usuario_id = ? ORDER BY activa DESC, created_at");
    $stmt->execute([$uid]);
    $metas = $stmt->fetchAll();

    if ($metas) {
        // Traer todos los aportes en una sola query (sin N+1)
        $ids      = array_column($metas, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $apStmt   = $db->prepare(
            "SELECT * FROM metas_ahorro_aportes WHERE meta_id IN ($placeholders) ORDER BY fecha DESC"
        );
        $apStmt->execute($ids);
        $allAportes = $apStmt->fetchAll();

        // Agrupar por meta_id en PHP
        $aportesMap = [];
        foreach ($allAportes as $a) {
            $aportesMap[$a['meta_id']][] = $a;
        }

        foreach ($metas as &$m) {
            $apsMeta = $aportesMap[$m['id']] ?? [];
            // Recalcular monto_actual dinámicamente: inicial + suma de aportes (evita desincronía)
            $sumaAportes       = (float)array_sum(array_column($apsMeta, 'monto'));
            $m['monto_actual'] = (float)($m['monto_inicial'] ?? 0) + $sumaAportes;
            $m['aportes']      = array_slice($apsMeta, 0, 10);
            $m['pct']          = $m['monto_meta'] > 0
                ? round(($m['monto_actual'] / $m['monto_meta']) * 100, 1)
                : 0;
        }
    }

    ok($metas);
}

if ($method === 'POST') {
    $b = body();
    if (!trim($b['nombre'] ?? '') || !($b['monto_meta'] ?? 0)) err('Nombre y monto requeridos.');
    $inicial = (float)($b['monto_inicial'] ?? $b['monto_actual'] ?? 0);
    $db->prepare("
        INSERT INTO metas_ahorro (usuario_id, nombre, descripcion, monto_meta, monto_inicial, monto_actual, fecha_meta, icono)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $uid,
        trim($b['nombre']),
        trim($b['descripcion'] ?? ''),
        (float)$b['monto_meta'],
        $inicial,
        $inicial,   // monto_actual = monto_inicial al crear (los aportes se suman dinámicamente)
        $b['fecha_meta'] ?: null,
        $b['icono'] ?? '🎯',
    ]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    $db->prepare("
        UPDATE metas_ahorro
        SET nombre=?, descripcion=?, monto_meta=?, fecha_meta=?, icono=?, activa=?
        WHERE id=? AND usuario_id=?
    ")->execute([
        trim($b['nombre']),
        trim($b['descripcion'] ?? ''),
        (float)$b['monto_meta'],
        $b['fecha_meta'] ?: null,
        $b['icono'] ?? '🎯',
        (int)($b['activa'] ?? 1),
        $id, $uid,
    ]);
    ok();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM metas_ahorro WHERE id = ? AND usuario_id = ?")->execute([$id, $uid]);
    ok();
}

err('Método no permitido.', 405);
