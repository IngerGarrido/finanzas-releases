<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/tarjeta';

// ── GET ───────────────────────────────────────────
if ($method === 'GET') {
    // Lista de tarjetas con sus fijos, cuotas y totales
    $stmt = $db->prepare("SELECT * FROM tarjetas WHERE usuario_id = ? AND activa = 1 ORDER BY nombre");
    $stmt->execute([$uid]);
    $tarjetas = $stmt->fetchAll();

    if (empty($tarjetas)) { ok(['tarjetas' => []]); }

    // Batch queries — no N+1
    $ids  = array_column($tarjetas, 'id');
    $ph   = implode(',', array_fill(0, count($ids), '?'));

    $fijosStmt = $db->prepare(
        "SELECT * FROM tarjeta_gastos_fijos WHERE tarjeta_id IN ($ph) AND activo = 1 ORDER BY tarjeta_id, nombre"
    );
    $fijosStmt->execute($ids);
    $fijosMap = [];
    foreach ($fijosStmt->fetchAll() as $f) { $fijosMap[$f['tarjeta_id']][] = $f; }

    $cuotasStmt = $db->prepare("
        SELECT *,
            LEAST(
                GREATEST(TIMESTAMPDIFF(MONTH, fecha_primer_pago, CURDATE()) + 1, 1),
                n_total_cuotas
            ) as cuota_calculada,
            n_total_cuotas - LEAST(
                GREATEST(TIMESTAMPDIFF(MONTH, fecha_primer_pago, CURDATE()) + 1, 1),
                n_total_cuotas
            ) + 1 as cuotas_faltantes,
            monto_cuota * (
                n_total_cuotas - LEAST(
                    GREATEST(TIMESTAMPDIFF(MONTH, fecha_primer_pago, CURDATE()) + 1, 1),
                    n_total_cuotas
                ) + 1
            ) as total_faltante,
            DATE_ADD(fecha_primer_pago, INTERVAL (n_total_cuotas - 1) MONTH) as fecha_termino
        FROM tarjeta_cuotas
        WHERE tarjeta_id IN ($ph)
          AND TIMESTAMPDIFF(MONTH, fecha_primer_pago, CURDATE()) < n_total_cuotas
        ORDER BY tarjeta_id, fecha_compra DESC
    ");
    $cuotasStmt->execute($ids);
    $cuotasMap = [];
    foreach ($cuotasStmt->fetchAll() as $q) { $cuotasMap[$q['tarjeta_id']][] = $q; }

    foreach ($tarjetas as &$t) {
        $tid = (int)$t['id'];
        $t['fijos']  = $fijosMap[$tid]  ?? [];
        $t['cuotas'] = $cuotasMap[$tid] ?? [];
        $t['total_fijos']  = array_reduce($t['fijos'],  fn($c, $f) => $c + (float)$f['monto'], 0);
        $t['total_cuotas'] = array_reduce($t['cuotas'], fn($c, $q) => $c + (float)$q['monto_cuota'], 0);
        $t['total']        = $t['total_fijos'] + $t['total_cuotas'];
    }
    unset($t);

    ok(['tarjetas' => $tarjetas]);
}

// ── POST ──────────────────────────────────────────
if ($method === 'POST') {
    $b    = body();
    $tipo = $b['tipo'] ?? 'tarjeta';

    if ($tipo === 'tarjeta') {
        if (!trim($b['nombre'] ?? '')) err('Nombre requerido.');
        $db->prepare("
            INSERT INTO tarjetas (usuario_id, nombre, banco, ultimos_4, fecha_emision, fecha_facturacion, dia_pago, limite_credito)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $uid, trim($b['nombre']), trim($b['banco'] ?? ''),
            trim($b['ultimos_4'] ?? ''),
            $b['fecha_emision'] ?: null, $b['fecha_facturacion'] ?: null,
            $b['dia_pago'] ?: null, $b['limite_credito'] ?: null,
        ]);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }

    $tarjetaId = (int)($b['tarjeta_id'] ?? 0);
    // Verificar pertenencia
    $chk = $db->prepare("SELECT id FROM tarjetas WHERE id = ? AND usuario_id = ?");
    $chk->execute([$tarjetaId, $uid]);
    if (!$chk->fetch()) err('Tarjeta no encontrada.', 404);

    if ($tipo === 'fijo') {
        if (!trim($b['nombre'] ?? '') || !($b['monto'] ?? 0)) err('Nombre y monto requeridos.');
        $db->prepare("INSERT INTO tarjeta_gastos_fijos (tarjeta_id, nombre, monto, dia_cobro) VALUES (?,?,?,?)")
           ->execute([$tarjetaId, trim($b['nombre']), (float)$b['monto'], $b['dia_cobro'] ?: null]);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }

    if ($tipo === 'cuota') {
        $req = ['descripcion','fecha_compra','monto_cuota','n_total_cuotas','fecha_primer_pago'];
        foreach ($req as $k) if (empty($b[$k])) err("Campo $k requerido.");
        $db->prepare("
            INSERT INTO tarjeta_cuotas (tarjeta_id, descripcion, fecha_compra, monto_total, monto_cuota, n_total_cuotas, cuota_actual, fecha_primer_pago)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $tarjetaId, trim($b['descripcion']), $b['fecha_compra'],
            (float)($b['monto_total'] ?? ($b['monto_cuota'] * $b['n_total_cuotas'])),
            (float)$b['monto_cuota'], (int)$b['n_total_cuotas'],
            (int)($b['cuota_actual'] ?? 1), $b['fecha_primer_pago'],
        ]);
        ok(['id' => (int)$db->lastInsertId()], 201);
    }

    err('Tipo inválido.');
}

// ── PUT ───────────────────────────────────────────
if ($method === 'PUT') {
    $b    = body();
    $id   = (int)($b['id'] ?? 0);
    $tipo = $b['tipo'] ?? 'tarjeta';

    if ($tipo === 'tarjeta') {
        $db->prepare("
            UPDATE tarjetas SET nombre=?, banco=?, ultimos_4=?, fecha_emision=?, fecha_facturacion=?, dia_pago=?, limite_credito=?, activa=?
            WHERE id=? AND usuario_id=?
        ")->execute([
            trim($b['nombre']), trim($b['banco'] ?? ''), trim($b['ultimos_4'] ?? ''),
            $b['fecha_emision'] ?: null, $b['fecha_facturacion'] ?: null,
            $b['dia_pago'] ?: null, $b['limite_credito'] ?: null,
            (int)($b['activa'] ?? 1), $id, $uid,
        ]);
        ok();
    }

    if ($tipo === 'fijo') {
        $db->prepare("UPDATE tarjeta_gastos_fijos f
                      JOIN tarjetas t ON f.tarjeta_id = t.id
                      SET f.nombre=?, f.monto=?, f.dia_cobro=?, f.activo=?
                      WHERE f.id=? AND t.usuario_id=?")
           ->execute([trim($b['nombre']), (float)$b['monto'], $b['dia_cobro'] ?: null, (int)($b['activo'] ?? 1), $id, $uid]);
        ok();
    }

    if ($tipo === 'cuota') {
        $db->prepare("UPDATE tarjeta_cuotas c
                      JOIN tarjetas t ON c.tarjeta_id = t.id
                      SET c.descripcion=?, c.monto_cuota=?, c.cuota_actual=?, c.n_total_cuotas=?
                      WHERE c.id=? AND t.usuario_id=?")
           ->execute([trim($b['descripcion']), (float)$b['monto_cuota'],
                      (int)$b['cuota_actual'], (int)$b['n_total_cuotas'], $id, $uid]);
        ok();
    }

    err('Tipo inválido.');
}

// ── DELETE ────────────────────────────────────────
if ($method === 'DELETE') {
    $id   = (int)($_GET['id'] ?? 0);
    $tipo = $_GET['tipo'] ?? 'tarjeta';

    if ($tipo === 'tarjeta') {
        $db->prepare("DELETE FROM tarjetas WHERE id = ? AND usuario_id = ?")->execute([$id, $uid]);
        ok();
    }
    if ($tipo === 'fijo') {
        $db->prepare("DELETE f FROM tarjeta_gastos_fijos f
                      JOIN tarjetas t ON f.tarjeta_id = t.id
                      WHERE f.id = ? AND t.usuario_id = ?")
           ->execute([$id, $uid]);
        ok();
    }
    if ($tipo === 'cuota') {
        $db->prepare("DELETE c FROM tarjeta_cuotas c
                      JOIN tarjetas t ON c.tarjeta_id = t.id
                      WHERE c.id = ? AND t.usuario_id = ?")
           ->execute([$id, $uid]);
        ok();
    }
    err('Tipo inválido.');
}

err('Método no permitido.', 405);
