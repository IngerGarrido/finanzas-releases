<?php
/**
 * Categorías de ejemplo para usuarios nuevos.
 * Inserta solo las que no existan ya (por nombre) para ese usuario.
 */
function categoriasDefault(): array {
    return [
        // [nombre, tipo, icono, color]
        ['Arriendo / Dividendo', 'necesidad',    '🏠', '#2563EB'],
        ['Supermercado',         'necesidad',    '🛒', '#059669'],
        ['Servicios básicos',    'necesidad',    '💡', '#D97706'],
        ['Internet / Teléfono',  'necesidad',    '📱', '#0EA5E9'],
        ['Transporte',           'necesidad',    '🚗', '#6366F1'],
        ['Salud',                'necesidad',    '🏥', '#DC2626'],
        ['Restaurantes',         'discrecional', '🍽️', '#C0400D'],
        ['Entretenimiento',      'discrecional', '🎬', '#7C3AED'],
        ['Ropa',                 'discrecional', '👗', '#DB2777'],
        ['Suscripciones',        'discrecional', '🎵', '#0891B2'],
        ['Ahorro',               'ahorro',       '💰', '#059669'],
        ['Inversión',            'ahorro',       '📈', '#15803D'],
    ];
}

function seedCategorias(PDO $db, int $uid): int {
    // Nombres ya existentes (para no duplicar)
    $ex = $db->prepare("SELECT LOWER(nombre) FROM categorias WHERE usuario_id = ?");
    $ex->execute([$uid]);
    $existentes = array_map('strval', $ex->fetchAll(PDO::FETCH_COLUMN));

    $maxStmt = $db->prepare("SELECT COALESCE(MAX(orden),0) FROM categorias WHERE usuario_id = ?");
    $maxStmt->execute([$uid]);
    $orden = (int)$maxStmt->fetchColumn();

    $ins = $db->prepare("INSERT INTO categorias (usuario_id, nombre, tipo, icono, color, orden) VALUES (?,?,?,?,?,?)");
    $n = 0;
    foreach (categoriasDefault() as [$nombre, $tipo, $icono, $color]) {
        if (in_array(mb_strtolower($nombre), $existentes, true)) continue;
        $ins->execute([$uid, $nombre, $tipo, $icono, $color, ++$orden]);
        $n++;
    }
    return $n;
}
