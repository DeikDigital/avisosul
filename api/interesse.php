<?php
/* Validacion de precio: cuenta cuantas personas tocan "Quero assinar" en cada plan. No guarda datos personales. */
require __DIR__ . '/_comun.php';
$planes = ['mensal', 'anual', 'temporada'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = entrada(); $p = in_array($in['plano'] ?? '', $planes) ? $in['plano'] : null;
    if (!$p) salir(['ok' => false], 400);
    $yo = visitante();
    salir(con_archivo('interesse.json', function ($d) use ($p, $yo) {
        $dia = gmdate('Y-m-d'); $k = $dia . '|' . $yo . '|' . $p;
        $d['vistos'] = array_filter($d['vistos'] ?? [], fn($x) => str_starts_with($x, $dia), ARRAY_FILTER_USE_KEY);
        if (empty($d['vistos'][$k])) { $d['vistos'][$k] = 1; $d['total'][$p] = ($d['total'][$p] ?? 0) + 1; $d['dias'][$dia][$p] = ($d['dias'][$dia][$p] ?? 0) + 1; }
        return [$d, ['ok' => true]];
    }));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') salir(con_archivo('interesse.json', fn($d) => [$d, ['ok' => true, 'total' => $d['total'] ?? new stdClass, 'dias' => $d['dias'] ?? new stdClass]]));
salir(['ok' => false], 405);
