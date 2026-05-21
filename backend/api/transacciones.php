<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $mes      = isset($_GET['mes'])  ? (int)$_GET['mes']  : null;
    $anio     = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
    $tipo     = $_GET['tipo']      ?? null;
    $cat_id   = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : null;
    $busqueda = trim($_GET['busqueda'] ?? '');
    $exportar = ($_GET['export'] ?? '') === 'csv';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per      = $exportar ? 9999 : 50;

    $where  = ["t.usuario_id = ?"];
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
    if ($busqueda) { $where[] = "t.descripcion LIKE ?"; $params[] = "%$busqueda%"; }

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

    cacheClear();
    ok(['id' => (int)$db->lastInsertId()], 201);
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

    cacheClear();
    ok();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('ID requerido.');
    $stmt = $db->prepare("DELETE FROM transacciones WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $uid]);
    if (!$stmt->rowCount()) err('No encontrado.', 404);
    cacheClear();
    ok();
}

err('Método no permitido.', 405);
