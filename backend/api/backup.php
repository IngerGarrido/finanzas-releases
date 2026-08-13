<?php
require_once __DIR__ . '/../config.php';
$user = requireAuth();
$uid  = (int)$user['id'];
$db   = getDB();

// Vuelca una consulta; si la tabla no existe aún (migración pendiente) → []
function dump(PDO $db, string $sql, array $params): array {
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

$data = [
    'app'          => 'finanzas',
    'version'      => 1,
    'exportado_en' => date('c'),
    'usuario'      => ['nombre' => $user['nombre'], 'email' => $user['email']],

    'categorias'    => dump($db, "SELECT * FROM categorias WHERE usuario_id=?", [$uid]),
    'subcategorias' => dump($db, "SELECT s.* FROM subcategorias s JOIN categorias c ON s.categoria_id=c.id WHERE c.usuario_id=?", [$uid]),
    'transacciones' => dump($db, "SELECT * FROM transacciones WHERE usuario_id=?", [$uid]),

    'fuentes_ingreso' => dump($db, "SELECT * FROM fuentes_ingreso WHERE usuario_id=?", [$uid]),
    'ingresos'        => dump($db, "SELECT * FROM ingresos WHERE usuario_id=?", [$uid]),

    'gastos_recurrentes'    => dump($db, "SELECT * FROM gastos_recurrentes WHERE usuario_id=?", [$uid]),
    'recurrentes_aplicados' => dump($db, "SELECT ra.* FROM recurrentes_aplicados ra JOIN gastos_recurrentes r ON ra.recurrente_id=r.id WHERE r.usuario_id=?", [$uid]),

    'tarjetas'             => dump($db, "SELECT * FROM tarjetas WHERE usuario_id=?", [$uid]),
    'tarjeta_gastos_fijos' => dump($db, "SELECT f.* FROM tarjeta_gastos_fijos f JOIN tarjetas t ON f.tarjeta_id=t.id WHERE t.usuario_id=?", [$uid]),
    'tarjeta_cuotas'       => dump($db, "SELECT c.* FROM tarjeta_cuotas c JOIN tarjetas t ON c.tarjeta_id=t.id WHERE t.usuario_id=?", [$uid]),
    'tarjeta_pagos'        => dump($db, "SELECT p.* FROM tarjeta_pagos p JOIN tarjetas t ON p.tarjeta_id=t.id WHERE t.usuario_id=?", [$uid]),

    'metas_ahorro'         => dump($db, "SELECT * FROM metas_ahorro WHERE usuario_id=?", [$uid]),
    'metas_ahorro_aportes' => dump($db, "SELECT a.* FROM metas_ahorro_aportes a JOIN metas_ahorro m ON a.meta_id=m.id WHERE m.usuario_id=?", [$uid]),

    'gastos_pendientes'       => dump($db, "SELECT * FROM gastos_pendientes WHERE usuario_id=?", [$uid]),
    'gastos_pendientes_pagos' => dump($db, "SELECT pp.* FROM gastos_pendientes_pagos pp JOIN gastos_pendientes p ON pp.pendiente_id=p.id WHERE p.usuario_id=?", [$uid]),

    'presupuesto'       => dump($db, "SELECT * FROM presupuesto WHERE usuario_id=?", [$uid]),
    'presupuesto_metas' => dump($db, "SELECT * FROM presupuesto_metas WHERE usuario_id=?", [$uid]),
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="finanzas-backup-' . date('Y-m-d') . '.json"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
