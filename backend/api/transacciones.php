<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Reemplaza las etiquetas de una transacción (crea las que no existan)
function syncEtiquetas(PDO $db, int $uid, int $txId, $nombres): void {
    if (!is_array($nombres)) return;
    $db->prepare("DELETE FROM transaccion_etiquetas WHERE transaccion_id = ?")->execute([$txId]);
    $foc  = $db->prepare("INSERT INTO etiquetas (usuario_id, nombre) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $link = $db->prepare("INSERT IGNORE INTO transaccion_etiquetas (transaccion_id, etiqueta_id) VALUES (?, ?)");
    $vistos = [];
    foreach ($nombres as $nombre) {
        $nombre = trim(mb_substr((string)$nombre, 0, 50));
        $key = mb_strtolower($nombre);
        if ($nombre === '' || isset($vistos[$key])) continue;
        $vistos[$key] = true;
        $foc->execute([$uid, $nombre]);
        $link->execute([$txId, (int)$db->lastInsertId()]);
    }
}

if ($method === 'GET') {
    // ── Sugerir categoría según descripciones pasadas ──
    if (isset($_GET['sugerir'])) {
        $desc = trim($_GET['sugerir']);
        if ($desc === '') ok(null);
        $st = $db->prepare("SELECT categoria_id, subcategoria_id FROM transacciones
                            WHERE usuario_id=? AND tipo='gasto' AND eliminada_at IS NULL AND categoria_id IS NOT NULL AND descripcion = ?
                            ORDER BY id DESC LIMIT 1");
        $st->execute([$uid, $desc]);
        $row = $st->fetch();
        if (!$row) {
            $st = $db->prepare("SELECT categoria_id, subcategoria_id FROM transacciones
                                WHERE usuario_id=? AND tipo='gasto' AND eliminada_at IS NULL AND categoria_id IS NOT NULL AND descripcion LIKE ?
                                ORDER BY id DESC LIMIT 1");
            $st->execute([$uid, '%' . $desc . '%']);
            $row = $st->fetch();
        }
        ok($row ?: null);
    }

    // ── Lista de etiquetas del usuario (para el filtro) ──
    if (isset($_GET['etiquetas'])) {
        $st = $db->prepare("SELECT id, nombre, color FROM etiquetas WHERE usuario_id = ? ORDER BY nombre");
        $st->execute([$uid]);
        ok($st->fetchAll());
    }

    // ── Papelera: transacciones eliminadas ──
    if (($_GET['papelera'] ?? '') === '1') {
        $st = $db->prepare("SELECT t.id, t.fecha, t.monto, t.tipo, t.descripcion,
                                   t.categoria_id, c.nombre as categoria, t.eliminada_at
                            FROM transacciones t
                            LEFT JOIN categorias c ON t.categoria_id = c.id
                            WHERE t.usuario_id = ? AND t.eliminada_at IS NOT NULL
                            ORDER BY t.eliminada_at DESC LIMIT 100");
        $st->execute([$uid]);
        ok(['items' => $st->fetchAll()]);
    }

    $mes      = isset($_GET['mes'])  ? (int)$_GET['mes']  : null;
    $anio     = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
    $tipo     = $_GET['tipo']      ?? null;
    $cat_id   = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : null;
    $busqueda = trim($_GET['busqueda'] ?? '');
    $exportar = ($_GET['export'] ?? '') === 'csv';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per      = $exportar ? 9999 : 50;

    $where  = ["t.usuario_id = ?", "t.eliminada_at IS NULL"];
    $params = [$uid];

    // Usar rangos de fecha para aprovechar índices (YEAR/MONTH impiden uso de índice)
    if ($mes) {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', mktime(0, 0, 0, $mes + 1, 1, $anio));
        $where[]  = "t.fecha >= ?"; $params[] = $desde;
        $where[]  = "t.fecha < ?";  $params[] = $hasta;
    } else {
        $where[]  = "t.fecha >= ?"; $params[] = "$anio-01-01";
        $where[]  = "t.fecha < ?";  $params[] = ($anio + 1) . "-01-01";
    }
    if ($tipo)   { $where[] = "t.tipo = ?";          $params[] = $tipo; }
    if ($cat_id) { $where[] = "t.categoria_id = ?";  $params[] = $cat_id; }
    if (!empty($_GET['etiqueta_id'])) {
        $where[]  = "EXISTS (SELECT 1 FROM transaccion_etiquetas te WHERE te.transaccion_id = t.id AND te.etiqueta_id = ?)";
        $params[] = (int)$_GET['etiqueta_id'];
    }
    if ($busqueda) {
        // Si el término es un número, busca también por monto exacto
        if (is_numeric($busqueda)) {
            $where[] = "(t.descripcion LIKE ? OR t.monto = ?)";
            $params[] = "%$busqueda%"; $params[] = (float)$busqueda;
        } else {
            $where[] = "t.descripcion LIKE ?"; $params[] = "%$busqueda%";
        }
    }

    $whereStr = implode(' AND ', $where);

    // Totales del período filtrado
    $totStmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) as sum_ingresos,
            COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) as sum_gastos
        FROM transacciones t WHERE $whereStr
    ");
    $totStmt->execute($params);
    $totales = $totStmt->fetch();

    $sql = "SELECT t.id, t.fecha, t.monto, t.tipo, t.descripcion, t.notas,
                   t.categoria_id, t.subcategoria_id,
                   c.nombre as categoria, s.nombre as subcategoria
            FROM transacciones t
            LEFT JOIN categorias c ON t.categoria_id = c.id
            LEFT JOIN subcategorias s ON t.subcategoria_id = s.id
            WHERE $whereStr
            ORDER BY t.fecha DESC, t.id DESC
            LIMIT $per OFFSET " . (($page - 1) * $per);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Exportar CSV
    if ($exportar) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transacciones.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
        fputcsv($out, ['Fecha','Tipo','Descripción','Categoría','Subcategoría','Monto'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['fecha'], $r['tipo'], $r['descripcion'] ?? '',
                $r['categoria'] ?? '', $r['subcategoria'] ?? '',
                number_format((float)$r['monto'], 0, ',', '.')
            ], ';');
        }
        fclose($out);
        exit;
    }

    // Etiquetas de las filas de esta página (sin N+1)
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $tg  = $db->prepare("SELECT te.transaccion_id, e.nombre
                             FROM transaccion_etiquetas te JOIN etiquetas e ON te.etiqueta_id = e.id
                             WHERE te.transaccion_id IN ($ph) ORDER BY e.nombre");
        $tg->execute($ids);
        $tagMap = [];
        foreach ($tg->fetchAll() as $r) { $tagMap[$r['transaccion_id']][] = $r['nombre']; }
        foreach ($rows as &$row) { $row['etiquetas'] = $tagMap[$row['id']] ?? []; }
        unset($row);
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM transacciones t WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    ok([
        'items'        => $rows,
        'total'        => $total,
        'page'         => $page,
        'pages'        => ceil($total / $per),
        'sum_ingresos' => (float)$totales['sum_ingresos'],
        'sum_gastos'   => (float)$totales['sum_gastos'],
    ]);
}

if ($method === 'POST') {
    $b = body();

    // ── Restaurar desde la papelera ──
    if (($b['action'] ?? '') === 'restaurar') {
        $id = (int)($b['id'] ?? 0);
        $st = $db->prepare("UPDATE transacciones SET eliminada_at = NULL WHERE id = ? AND usuario_id = ?");
        $st->execute([$id, $uid]);
        if (!$st->rowCount()) err('No encontrado.', 404);
        ok();
    }

    $fecha       = $b['fecha'] ?? '';
    $monto       = (float)($b['monto'] ?? 0);
    $tipo        = $b['tipo'] ?? '';
    $cat_id      = $b['categoria_id'] ? (int)$b['categoria_id'] : null;
    $subcat_id   = $b['subcategoria_id'] ? (int)$b['subcategoria_id'] : null;
    $descripcion = trim($b['descripcion'] ?? '');
    $notas       = trim($b['notas'] ?? '');

    if (!$fecha || $monto <= 0 || !in_array($tipo, ['ingreso','gasto'])) err('Datos inválidos.');

    $db->prepare("INSERT INTO transacciones (usuario_id, fecha, monto, tipo, categoria_id, subcategoria_id, descripcion, notas)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$uid, $fecha, $monto, $tipo, $cat_id, $subcat_id, $descripcion ?: null, $notas ?: null]);
    $newId = (int)$db->lastInsertId();
    syncEtiquetas($db, (int)$uid, $newId, $b['etiquetas'] ?? []);

    ok(['id' => $newId], 201);
}

if ($method === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) err('ID requerido.');

    $stmt = $db->prepare("SELECT id FROM transacciones WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $uid]);
    if (!$stmt->fetch()) err('No encontrado.', 404);

    $db->prepare("UPDATE transacciones SET fecha=?, monto=?, tipo=?, categoria_id=?, subcategoria_id=?, descripcion=?, notas=?, updated_at=NOW()
                  WHERE id = ? AND usuario_id = ?")
       ->execute([
           $b['fecha'], (float)$b['monto'], $b['tipo'],
           $b['categoria_id'] ? (int)$b['categoria_id'] : null,
           $b['subcategoria_id'] ? (int)$b['subcategoria_id'] : null,
           trim($b['descripcion'] ?? '') ?: null,
           trim($b['notas'] ?? '') ?: null,
           $id, $uid
       ]);

    if (array_key_exists('etiquetas', $b)) syncEtiquetas($db, (int)$uid, $id, $b['etiquetas']);

    ok();
}

if ($method === 'DELETE') {
    // Vaciar papelera (borrado definitivo de todas las eliminadas)
    if (($_GET['vaciar'] ?? '') === '1') {
        $db->prepare("DELETE FROM transacciones WHERE usuario_id = ? AND eliminada_at IS NOT NULL")->execute([$uid]);
        ok();
    }

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('ID requerido.');

    if (($_GET['purgar'] ?? '') === '1') {
        // Borrado definitivo de una transacción ya en la papelera
        $stmt = $db->prepare("DELETE FROM transacciones WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $uid]);
        if (!$stmt->rowCount()) err('No encontrado.', 404);
        ok();
    }

    // Borrado suave: va a la papelera
    $stmt = $db->prepare("UPDATE transacciones SET eliminada_at = NOW() WHERE id = ? AND usuario_id = ? AND eliminada_at IS NULL");
    $stmt->execute([$id, $uid]);
    if (!$stmt->rowCount()) err('No encontrado.', 404);
    ok();
}

err('Método no permitido.', 405);
