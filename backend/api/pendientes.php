<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->prepare("
        SELECT p.*,
               COALESCE(SUM(pp.monto),0) as pagado,
               p.monto_total - COALESCE(SUM(pp.monto),0) as pendiente
        FROM gastos_pendientes p
        LEFT JOIN gastos_pendientes_pagos pp ON pp.pendiente_id = p.id
        WHERE p.usuario_id = ?
        GROUP BY p.id ORDER BY p.persona, p.created_at
    ");
    $stmt->execute([$uid]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $p = $db->prepare("SELECT * FROM gastos_pendientes_pagos WHERE pendiente_id = ? ORDER BY fecha");
        $p->execute([$item['id']]);
        $item['pagos'] = $p->fetchAll();
    }

    ok($items);
}

if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? '';

    // Editar pago individual (PUT bloqueado en algunos hostings → usamos POST)
    if ($action === 'edit_pago') {
        $id = (int)($b['id'] ?? 0);
        if (!$id) err('ID requerido.');
        $db->prepare("UPDATE gastos_pendientes_pagos pp
                      JOIN gastos_pendientes p ON pp.pendiente_id = p.id
                      SET pp.fecha = ?, pp.monto = ?
                      WHERE pp.id = ? AND p.usuario_id = ?")
           ->execute([$b['fecha'], (float)$b['monto'], $id, $uid]);
        ok();
    }

    // Agregar pago a pendiente existente
    if (isset($b['pendiente_id'])) {
        $db->prepare("INSERT INTO gastos_pendientes_pagos (pendiente_id, fecha, monto) VALUES (?,?,?)")
           ->execute([(int)$b['pendiente_id'], $b['fecha'], (float)$b['monto']]);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }

    // Nuevo pendiente
    if (!trim($b['persona'] ?? '') || !($b['monto_total'] ?? 0)) err('Datos inválidos.');
    $db->prepare("INSERT INTO gastos_pendientes (usuario_id, persona, descripcion, monto_total) VALUES (?,?,?,?)")
       ->execute([$uid, trim($b['persona']), trim($b['descripcion'] ?? ''), (float)$b['monto_total']]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $b    = body();
    $id   = (int)($b['id'] ?? 0);
    $tipo = $b['tipo'] ?? 'pendiente';
    if (!$id) err('ID requerido.');

    if ($tipo === 'pago') {
        // Editar un pago individual (verificar ownership vía JOIN)
        $db->prepare("UPDATE gastos_pendientes_pagos pp
                      JOIN gastos_pendientes p ON pp.pendiente_id = p.id
                      SET pp.fecha = ?, pp.monto = ?
                      WHERE pp.id = ? AND p.usuario_id = ?")
           ->execute([$b['fecha'], (float)$b['monto'], $id, $uid]);
    } else {
        // Editar el pendiente principal
        $db->prepare("UPDATE gastos_pendientes SET persona=?, descripcion=?, monto_total=?
                      WHERE id=? AND usuario_id=?")
           ->execute([trim($b['persona']), trim($b['descripcion'] ?? ''), (float)$b['monto_total'], $id, $uid]);
    }
    ok();
}

if ($method === 'DELETE') {
    $id   = (int)($_GET['id'] ?? 0);
    $tipo = $_GET['tipo'] ?? 'pendiente';

    if ($tipo === 'pago') {
        $db->prepare("DELETE pp FROM gastos_pendientes_pagos pp
                      JOIN gastos_pendientes p ON pp.pendiente_id = p.id
                      WHERE pp.id = ? AND p.usuario_id = ?")
           ->execute([$id, $uid]);
    } else {
        $db->prepare("DELETE FROM gastos_pendientes WHERE id = ? AND usuario_id = ?")
           ->execute([$id, $uid]);
    }
    ok();
}

err('Método no permitido.', 405);
