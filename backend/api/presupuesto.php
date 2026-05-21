<?php
require_once __DIR__ . '/../config.php';
$user   = requireAuth();
$uid    = $user['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────
if ($method === 'GET') {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    $mes  = (int)($_GET['mes']  ?? date('n'));
    $tipo = $_GET['tipo'] ?? 'metas';

    if ($tipo === 'categorias') {
        // Presupuesto por categoría con gasto real del período
        $stmt = $db->prepare("
            SELECT c.id, c.nombre, c.tipo, c.color,
                   COALESCE(p.meta, 0) as meta,
                   COALESCE(SUM(t.monto), 0) as gastado
            FROM categorias c
            LEFT JOIN presupuesto p
                ON p.categoria_id = c.id AND p.usuario_id = ? AND p.anio = ? AND p.mes = ?
            LEFT JOIN transacciones t
                ON t.categoria_id = c.id AND t.usuario_id = ?
                AND YEAR(t.fecha) = ? AND MONTH(t.fecha) = ? AND t.tipo = 'gasto'
            WHERE c.usuario_id = ? AND c.activa = 1
            GROUP BY c.id, c.nombre, c.tipo, c.color, p.meta
            ORDER BY gastado DESC, c.nombre
        ");
        $stmt->execute([$uid, $anio, $mes, $uid, $anio, $mes, $uid]);
        ok($stmt->fetchAll());
    }

    // 50/30/20 metas (comportamiento original)
    $stmt = $db->prepare("SELECT * FROM presupuesto_metas WHERE usuario_id = ? AND anio = ? AND mes = ? LIMIT 1");
    $stmt->execute([$uid, $anio, $mes]);
    $meta = $stmt->fetch() ?: [
        'necesidades_pct'     => 50,
        'discrecionales_pct'  => 30,
        'ahorro_pct'          => 20,
        'ingreso_planificado' => 0,
    ];
    ok($meta);
}

// ── POST: upsert presupuesto por categoría ────────
if ($method === 'POST') {
    $b      = body();
    $cat_id = (int)($b['categoria_id'] ?? 0);
    $anio   = (int)($b['anio']         ?? date('Y'));
    $mes    = (int)($b['mes']          ?? date('n'));
    $meta   = (float)($b['meta']       ?? 0);
    if (!$cat_id) err('Categoría requerida.');

    $db->prepare("INSERT INTO presupuesto (usuario_id, categoria_id, anio, mes, meta)
                  VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE meta = VALUES(meta)")
       ->execute([$uid, $cat_id, $anio, $mes, $meta]);
    ok();
}

// ── PUT: actualizar metas 50/30/20 ───────────────
if ($method === 'PUT') {
    $b    = body();
    $anio = (int)($b['anio'] ?? date('Y'));
    $mes  = (int)($b['mes']  ?? date('n'));

    $db->prepare("INSERT INTO presupuesto_metas (usuario_id, anio, mes, ingreso_planificado, necesidades_pct, discrecionales_pct, ahorro_pct)
                  VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE ingreso_planificado=?, necesidades_pct=?, discrecionales_pct=?, ahorro_pct=?")
       ->execute([
           $uid, $anio, $mes,
           (float)($b['ingreso_planificado'] ?? 0),
           (float)($b['necesidades_pct']     ?? 50),
           (float)($b['discrecionales_pct']  ?? 30),
           (float)($b['ahorro_pct']          ?? 20),
           (float)($b['ingreso_planificado'] ?? 0),
           (float)($b['necesidades_pct']     ?? 50),
           (float)($b['discrecionales_pct']  ?? 30),
           (float)($b['ahorro_pct']          ?? 20),
       ]);
    ok();
}

// ── DELETE: quitar presupuesto de una categoría ──
if ($method === 'DELETE') {
    $cat_id = (int)($_GET['categoria_id'] ?? 0);
    $anio   = (int)($_GET['anio']         ?? date('Y'));
    $mes    = (int)($_GET['mes']          ?? date('n'));
    if (!$cat_id) err('Categoría requerida.');
    $db->prepare("DELETE FROM presupuesto WHERE usuario_id=? AND categoria_id=? AND anio=? AND mes=?")
       ->execute([$uid, $cat_id, $anio, $mes]);
    ok();
}

err('Método no permitido.', 405);
