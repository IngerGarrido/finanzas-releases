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
        // Rango de fecha (evita YEAR/MONTH que anulan el índice idx_fecha)
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', mktime(0, 0, 0, $mes + 1, 1, $anio));

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
                AND t.fecha >= ? AND t.fecha < ? AND t.tipo = 'gasto' AND t.eliminada_at IS NULL
            WHERE c.usuario_id = ? AND c.activa = 1
            GROUP BY c.id, c.nombre, c.tipo, c.color, p.meta
            ORDER BY gastado DESC, c.nombre
        ");
        $stmt->execute([$uid, $anio, $mes, $uid, $desde, $hasta, $uid]);
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

    // ── Copiar los límites del mes anterior ──
    if (($b['action'] ?? '') === 'repetir') {
        $anio = (int)($b['anio'] ?? date('Y'));
        $mes  = (int)($b['mes']  ?? date('n'));
        $mesPrev = $mes - 1; $anioPrev = $anio;
        if ($mesPrev < 1) { $mesPrev = 12; $anioPrev--; }

        $prev = $db->prepare("SELECT categoria_id, meta FROM presupuesto WHERE usuario_id=? AND anio=? AND mes=? AND meta > 0");
        $prev->execute([$uid, $anioPrev, $mesPrev]);
        $rows = $prev->fetchAll();
        if (!$rows) err('No hay límites en el mes anterior para copiar.');

        $ins = $db->prepare("INSERT INTO presupuesto (usuario_id, categoria_id, anio, mes, meta)
                             VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE meta = VALUES(meta)");
        foreach ($rows as $r) {
            $ins->execute([$uid, (int)$r['categoria_id'], $anio, $mes, (float)$r['meta']]);
        }
        ok(['copiados' => count($rows)]);
    }

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
