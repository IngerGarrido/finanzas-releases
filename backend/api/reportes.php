<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();
$uid  = (int)$user['id'];
$db   = getDB();

$meses = max(1, min(24, (int)($_GET['meses'] ?? 6)));

// Inicio del rango: primer día del mes (meses-1) atrás
$start = new DateTime('first day of this month');
$start->modify('-' . ($meses - 1) . ' months');

// No mostrar meses anteriores al primer registro del usuario
// (antes no usaba el sistema → no tiene sentido mostrar meses vacíos al inicio).
$primero = $db->prepare("
    SELECT LEAST(
        COALESCE((SELECT MIN(fecha) FROM transacciones WHERE usuario_id=? AND eliminada_at IS NULL), '9999-12-31'),
        COALESCE((SELECT MIN(STR_TO_DATE(CONCAT(anio,'-',LPAD(mes,2,'0'),'-01'),'%Y-%m-%d')) FROM ingresos WHERE usuario_id=?), '9999-12-31')
    )
");
$primero->execute([$uid, $uid]);
$minFecha = $primero->fetchColumn();
if ($minFecha && $minFecha < '9999-12-31') {
    $minStart = new DateTime($minFecha);
    $minStart->modify('first day of this month');
    if ($minStart > $start) $start = $minStart;
}

$now       = new DateTime('first day of this month');
$startStr  = $start->format('Y-m-d');
$startAnio = (int)$start->format('Y');
$startMes  = (int)$start->format('n');

// Lista de meses desde el inicio efectivo hasta el mes actual
$mesesList = [];
$cur = clone $start;
while ($cur <= $now) {
    $mesesList[$cur->format('Y-n')] = [
        'anio' => (int)$cur->format('Y'),
        'mes'  => (int)$cur->format('n'),
        'ingresos' => 0.0,
        'gastos'   => 0.0,
    ];
    $cur->modify('+1 month');
}

// Gastos por mes
$gq = $db->prepare("
    SELECT YEAR(fecha) y, MONTH(fecha) m, COALESCE(SUM(monto),0) total
    FROM transacciones
    WHERE usuario_id = ? AND tipo = 'gasto' AND eliminada_at IS NULL AND fecha >= ?
    GROUP BY y, m
");
$gq->execute([$uid, $startStr]);
foreach ($gq->fetchAll() as $r) {
    $k = $r['y'] . '-' . $r['m'];
    if (isset($mesesList[$k])) $mesesList[$k]['gastos'] = (float)$r['total'];
}

// Ingresos por mes
$iq = $db->prepare("
    SELECT anio, mes, COALESCE(SUM(actual),0) total
    FROM ingresos
    WHERE usuario_id = ? AND (anio > ? OR (anio = ? AND mes >= ?))
    GROUP BY anio, mes
");
$iq->execute([$uid, $startAnio, $startAnio, $startMes]);
foreach ($iq->fetchAll() as $r) {
    $k = $r['anio'] . '-' . $r['mes'];
    if (isset($mesesList[$k])) $mesesList[$k]['ingresos'] = (float)$r['total'];
}
$porMes = array_values($mesesList);

// Gasto por categoría en todo el rango
$cq = $db->prepare("
    SELECT c.nombre, c.color, c.tipo, COALESCE(SUM(t.monto),0) total
    FROM transacciones t
    JOIN categorias c ON t.categoria_id = c.id
    WHERE t.usuario_id = ? AND t.tipo = 'gasto' AND t.eliminada_at IS NULL AND t.fecha >= ?
    GROUP BY c.id, c.nombre, c.color, c.tipo
    ORDER BY total DESC
");
$cq->execute([$uid, $startStr]);
$porCategoria = $cq->fetchAll();

// Top 10 gastos individuales del rango
$tq = $db->prepare("
    SELECT t.fecha, t.monto, t.descripcion, c.nombre categoria
    FROM transacciones t
    LEFT JOIN categorias c ON t.categoria_id = c.id
    WHERE t.usuario_id = ? AND t.tipo = 'gasto' AND t.eliminada_at IS NULL AND t.fecha >= ?
    ORDER BY t.monto DESC, t.fecha DESC
    LIMIT 10
");
$tq->execute([$uid, $startStr]);
$topGastos = $tq->fetchAll();

// Totales y promedios (sobre los meses realmente mostrados)
$totalGastos   = array_sum(array_column($porMes, 'gastos'));
$totalIngresos = array_sum(array_column($porMes, 'ingresos'));
$mesesReales   = max(1, count($porMes));

ok([
    'meses'         => $mesesReales,
    'por_mes'       => $porMes,
    'por_categoria' => $porCategoria,
    'top_gastos'    => $topGastos,
    'total_gastos'   => $totalGastos,
    'total_ingresos' => $totalIngresos,
    'promedio_gasto' => $totalGastos / $mesesReales,
]);
