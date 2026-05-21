<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: lista + estado aplicado este mes ─────────
if ($method === 'GET') {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    $mes  = (int)($_GET['mes']  ?? date('n'));

    $stmt = $db->prepare("
        SELECT r.*,
               c.nombre as categoria,
               s.nombre as subcategoria,
               (ra.id IS NOT NULL) as aplicado
        FROM gastos_recurrentes r
        LEFT JOIN categorias c  ON c.id = r.categoria_id
        LEFT JOIN subcategorias s ON s.id = r.subcategoria_id
        LEFT JOIN recurrentes_aplicados ra
               ON ra.recurrente_id = r.id AND ra.anio = ? AND ra.mes = ?
        WHERE r.usuario_id = ? AND r.activo = 1
        ORDER BY r.dia_mes IS NULL, r.dia_mes, r.descripcion
    ");
    $stmt->execute([$anio, $mes, $uid]);
    $items = $stmt->fetchAll();

    // Forzar booleano real para evitar que "0" (string) sea truthy en JS
    foreach ($items as &$item) {
        $item['aplicado'] = (bool)(int)$item['aplicado'];
    }
    unset($item);

    $total = array_reduce($items, fn($s, $r) => $s + (float)$r['monto'], 0);
    ok(['items' => $items, 'total' => $total]);
}

// ── POST: crear recurrente O aplicar al mes ───────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? '';

    // ── Aplicar: crear transacción + marcar como aplicado
    if ($action === 'aplicar') {
        $rid  = (int)($b['id']   ?? 0);
        $anio = (int)($b['anio'] ?? date('Y'));
        $mes  = (int)($b['mes']  ?? date('n'));
        if (!$rid) err('ID requerido.');

        $r = $db->prepare("SELECT * FROM gastos_recurrentes WHERE id=? AND usuario_id=?");
        $r->execute([$rid, $uid]);
        $rec = $r->fetch();
        if (!$rec) err('No encontrado.', 404);

        // Calcular días del mes correctamente
        $diasMes = (int)date('t', mktime(0,0,0, $mes, 1, $anio));
        $dia     = $rec['dia_mes'] ? min((int)$rec['dia_mes'], $diasMes) : 1;
        $fecha   = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

        $db->beginTransaction();
        try {
            // Insertar transacción
            $db->prepare("INSERT INTO transacciones
                          (usuario_id, fecha, monto, tipo, categoria_id, subcategoria_id, descripcion)
                          VALUES (?,?,?,'gasto',?,?,?)")
               ->execute([$uid, $fecha, $rec['monto'],
                          $rec['categoria_id'], $rec['subcategoria_id'], $rec['descripcion']]);
            $txId = (int)$db->lastInsertId();

            // Marcar como aplicado (INSERT IGNORE para evitar duplicados)
            $db->prepare("INSERT IGNORE INTO recurrentes_aplicados
                          (recurrente_id, anio, mes, transaccion_id) VALUES (?,?,?,?)")
               ->execute([$rid, $anio, $mes, $txId]);

            $db->commit();
            ok(['id' => $txId]);
        } catch (\Throwable $e) {
            $db->rollBack();
            err('Error al aplicar: ' . $e->getMessage());
        }
    }

    // ── Crear nuevo recurrente
    $desc = trim($b['descripcion'] ?? '');
    if (!$desc) err('Descripción requerida.');

    $db->prepare("INSERT INTO gastos_recurrentes
                  (usuario_id, descripcion, monto, categoria_id, subcategoria_id, dia_mes)
                  VALUES (?,?,?,?,?,?)")
       ->execute([
           $uid,
           $desc,
           (float)($b['monto'] ?? 0),
           $b['categoria_id']    ? (int)$b['categoria_id']    : null,
           $b['subcategoria_id'] ? (int)$b['subcategoria_id'] : null,
           isset($b['dia_mes']) && $b['dia_mes'] !== '' ? (int)$b['dia_mes'] : null,
       ]);
    ok(['id' => (int)$db->lastInsertId()], 201);
}

// ── PUT: editar recurrente ────────────────────────
if ($method === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) err('ID requerido.');

    $db->prepare("UPDATE gastos_recurrentes
                  SET descripcion=?, monto=?, categoria_id=?, subcategoria_id=?, dia_mes=?
                  WHERE id=? AND usuario_id=?")
       ->execute([
           trim($b['descripcion']),
           (float)$b['monto'],
           $b['categoria_id']    ? (int)$b['categoria_id']    : null,
           $b['subcategoria_id'] ? (int)$b['subcategoria_id'] : null,
           isset($b['dia_mes']) && $b['dia_mes'] !== '' ? (int)$b['dia_mes'] : null,
           $id, $uid,
       ]);
    ok();
}

// ── DELETE ────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('ID requerido.');
    $db->prepare("DELETE FROM gastos_recurrentes WHERE id=? AND usuario_id=?")->execute([$id, $uid]);
    ok();
}

err('Método no permitido.', 405);
