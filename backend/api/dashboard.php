<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();
$uid  = $user['id'];
$db   = getDB();

$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = (int)($_GET['mes']  ?? date('n'));

// ── Ingresos del mes (tabla ingresos) ─────────────
$stmtIng = $db->prepare("
    SELECT COALESCE(SUM(actual),0) as real_total,
           COALESCE(SUM(planificado),0) as plan_total
    FROM ingresos
    WHERE usuario_id = ? AND anio = ? AND mes = ?
");
$stmtIng->execute([$uid, $anio, $mes]);
$ingRow      = $stmtIng->fetch();
$ingReal     = (float)$ingRow['real_total'];
$ingPlan     = (float)$ingRow['plan_total'];

// Rangos de fecha del mes (evitar YEAR/MONTH para usar índices)
$fechaDesde = sprintf('%04d-%02d-01', $anio, $mes);
$fechaHasta = date('Y-m-d', mktime(0, 0, 0, $mes + 1, 1, $anio));

// ── Gastos del mes (transacciones) ────────────────
$stmtGasto = $db->prepare("
    SELECT COALESCE(SUM(monto),0) as total
    FROM transacciones
    WHERE usuario_id = ? AND tipo = 'gasto' AND fecha >= ? AND fecha < ?
");
$stmtGasto->execute([$uid, $fechaDesde, $fechaHasta]);
$gastos = (float)$stmtGasto->fetchColumn();

// ── Gastos por categoría ──────────────────────────
$stmt = $db->prepare("
    SELECT c.nombre, c.tipo, c.color, SUM(t.monto) as total
    FROM transacciones t
    JOIN categorias c ON t.categoria_id = c.id
    WHERE t.usuario_id = ? AND t.tipo = 'gasto'
      AND t.fecha >= ? AND t.fecha < ?
    GROUP BY c.id, c.nombre, c.tipo, c.color
    ORDER BY total DESC
");
$stmt->execute([$uid, $fechaDesde, $fechaHasta]);
$por_categoria = $stmt->fetchAll();

// ── Resumen anual ─────────────────────────────────
$stmtA = $db->prepare("
    SELECT MONTH(fecha) as mes, COALESCE(SUM(monto),0) as total
    FROM transacciones
    WHERE usuario_id = ? AND tipo = 'gasto' AND fecha >= ? AND fecha < ?
    GROUP BY MONTH(fecha)
");
$stmtA->execute([$uid, "$anio-01-01", ($anio + 1) . "-01-01"]);
$gastos_mes = [];
foreach ($stmtA->fetchAll() as $r) $gastos_mes[(int)$r['mes']] = (float)$r['total'];

$stmtB = $db->prepare("
    SELECT mes, COALESCE(SUM(actual),0) as total
    FROM ingresos WHERE usuario_id = ? AND anio = ?
    GROUP BY mes
");
$stmtB->execute([$uid, $anio]);
$ingresos_mes = [];
foreach ($stmtB->fetchAll() as $r) $ingresos_mes[(int)$r['mes']] = (float)$r['total'];

$anual = [];
for ($m = 1; $m <= 12; $m++) {
    $anual[] = [
        'mes'      => $m,
        'ingresos' => $ingresos_mes[$m] ?? 0,
        'gastos'   => $gastos_mes[$m] ?? 0,
    ];
}

// ── Meta 50/30/20 ─────────────────────────────────
$stmt = $db->prepare("SELECT * FROM presupuesto_metas WHERE usuario_id = ? AND anio = ? AND mes = ? LIMIT 1");
$stmt->execute([$uid, $anio, $mes]);
$meta = $stmt->fetch() ?: ['necesidades_pct' => 50, 'discrecionales_pct' => 30, 'ahorro_pct' => 20];

// ── Últimas 5 transacciones ───────────────────────
$stmt = $db->prepare("
    SELECT t.id, t.fecha, t.monto, t.tipo, t.descripcion,
           c.nombre as categoria, c.color as categoria_color,
           s.nombre as subcategoria
    FROM transacciones t
    LEFT JOIN categorias c ON t.categoria_id = c.id
    LEFT JOIN subcategorias s ON t.subcategoria_id = s.id
    WHERE t.usuario_id = ?
    ORDER BY t.fecha DESC, t.id DESC
    LIMIT 5
");
$stmt->execute([$uid]);
$recientes = $stmt->fetchAll();

// ── Total tarjeta mes (join a través de tarjetas) ─
$stmtF = $db->prepare("
    SELECT COALESCE(SUM(f.monto),0)
    FROM tarjeta_gastos_fijos f
    JOIN tarjetas t ON f.tarjeta_id = t.id
    WHERE t.usuario_id = ? AND f.activo = 1
");
$stmtF->execute([$uid]);
$fijos = (float)$stmtF->fetchColumn();

$stmtC = $db->prepare("
    SELECT COALESCE(SUM(c.monto_cuota),0)
    FROM tarjeta_cuotas c
    JOIN tarjetas t ON c.tarjeta_id = t.id
    WHERE t.usuario_id = ? AND c.cuota_actual <= c.n_total_cuotas
");
$stmtC->execute([$uid]);
$cuotas = (float)$stmtC->fetchColumn();

// ── Ahorro: total ahorrado vs metas ───────────────
$stmtAh = $db->prepare("SELECT COALESCE(SUM(monto_actual),0) as total, COALESCE(SUM(monto_meta),0) as meta FROM metas_ahorro WHERE usuario_id = ? AND activa = 1");
$stmtAh->execute([$uid]);
$ahorro = $stmtAh->fetch();

// ── Gastos pendientes (deudas) ────────────────────
$stmtPend = $db->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(p.monto_total - COALESCE(pp.pagado,0)), 0) as total
    FROM gastos_pendientes p
    LEFT JOIN (
        SELECT pendiente_id, SUM(monto) as pagado
        FROM gastos_pendientes_pagos GROUP BY pendiente_id
    ) pp ON pp.pendiente_id = p.id
    WHERE p.usuario_id = ?
      AND (p.monto_total - COALESCE(pp.pagado,0)) > 0
");
$stmtPend->execute([$uid]);
$pendientes_res = $stmtPend->fetch();

// ── Tarjetas: ciclo de facturación ────────────────
$stmtTC = $db->prepare("
    SELECT nombre, fecha_facturacion, dia_pago
    FROM tarjetas WHERE usuario_id = ? AND activa = 1
      AND (fecha_facturacion IS NOT NULL OR dia_pago IS NOT NULL)
    ORDER BY nombre
");
$stmtTC->execute([$uid]);
$tarjetas_ciclo = $stmtTC->fetchAll();

// ── Recurrentes: total mensual comprometido ───────
$stmtRec = $db->prepare("
    SELECT COALESCE(SUM(monto),0) as total, COUNT(*) as count
    FROM gastos_recurrentes WHERE usuario_id = ? AND activo = 1
");
$stmtRec->execute([$uid]);
$recurrentes_res = $stmtRec->fetch();

// ── Mes anterior (comparativa) ────────────────────
$mesPrev  = $mes  - 1;
$anioPrev = $anio;
if ($mesPrev < 1) { $mesPrev = 12; $anioPrev--; }

$prevDesde = sprintf('%04d-%02d-01', $anioPrev, $mesPrev);
$prevHasta = date('Y-m-d', mktime(0, 0, 0, $mesPrev + 1, 1, $anioPrev));
$stmtPrevG = $db->prepare("
    SELECT COALESCE(SUM(monto),0) FROM transacciones
    WHERE usuario_id=? AND tipo='gasto' AND fecha >= ? AND fecha < ?
");
$stmtPrevG->execute([$uid, $prevDesde, $prevHasta]);
$gastos_prev = (float)$stmtPrevG->fetchColumn();

$stmtPrevI = $db->prepare("
    SELECT COALESCE(SUM(actual),0) FROM ingresos
    WHERE usuario_id=? AND anio=? AND mes=?
");
$stmtPrevI->execute([$uid, $anioPrev, $mesPrev]);
$ingresos_prev = (float)$stmtPrevI->fetchColumn();

// ── Balance disponible real ───────────────────────
// Ingresos del mes − gastos registrados − compromisos tarjeta (ya fijos, no en transacciones)
$balance_real = $ingReal - $gastos - ($fijos + $cuotas);

ok([
    'mes'          => $mes,
    'anio'         => $anio,
    'ingresos'     => $ingReal,
    'ingresos_plan'=> $ingPlan,
    'gastos'       => $gastos,
    'saldo'        => $ingReal - $gastos,
    'por_categoria'=> array_values($por_categoria),
    'anual'        => $anual,
    'meta'         => $meta,
    'recientes'    => $recientes,
    'tarjeta'        => ['fijos' => $fijos, 'cuotas' => $cuotas, 'total' => $fijos + $cuotas],
    'ahorro'         => ['total' => (float)$ahorro['total'], 'meta' => (float)$ahorro['meta']],
    'pendientes'     => ['count' => (int)$pendientes_res['count'], 'total' => (float)$pendientes_res['total']],
    'tarjetas_ciclo' => array_values($tarjetas_ciclo),
    'recurrentes'    => ['total' => (float)$recurrentes_res['total'], 'count' => (int)$recurrentes_res['count']],
    'balance_real'   => $balance_real,
    'prev_mes'       => ['gastos' => $gastos_prev, 'ingresos' => $ingresos_prev, 'mes' => $mesPrev, 'anio' => $anioPrev],
]);
