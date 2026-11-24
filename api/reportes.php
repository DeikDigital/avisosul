<?php
/* Alerta de vecinos: reportes de granizo, alagamento, vendaval y rayos.
   GET  ?lat=&lon=&r=30|100|300  -> reportes de las ultimas 72 h dentro del radio
   POST {"acao":"novo","tipo":"granizo","tam":"noz","lat":..,"lon":..}
   POST {"acao":"confirmar","id":"..."}
   La ubicacion se redondea a ~1 km y no se guarda quien reporto (solo un hash diario para frenar repeticiones). */
require __DIR__ . '/_comun.php';
const HORAS = 72;
const TIPOS = ['granizo', 'alagamento', 'vendaval', 'raios'];
const TAMS = ['ervilha', 'gude', 'noz', 'ovo'];

function km($a1, $o1, $a2, $o2) {
    $r = 6371; $d1 = deg2rad($a2 - $a1); $d2 = deg2rad($o2 - $o1);
    $x = sin($d1 / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($d2 / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($x)));
}
function limpiar($d) {
    $lim = time() - HORAS * 3600;
    $d['rep'] = array_values(array_filter($d['rep'] ?? [], fn($x) => $x['t'] >= $lim));
    $d['ult'] = array_filter($d['ult'] ?? [], fn($t) => $t >= time() - 3600);
    return $d;
}
function coord($v, $min, $max) { return is_numeric($v) && $v >= $min && $v <= $max ? round((float)$v, 2) : null; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lat = coord($_GET['lat'] ?? null, -60, 15); $lon = coord($_GET['lon'] ?? null, -90, -30);
    $r = in_array((int)($_GET['r'] ?? 30), [30, 100, 300]) ? (int)$_GET['r'] : 30;
    if ($lat === null || $lon === null) salir(['ok' => false, 'error' => 'coordenadas'], 400);
    $yo = visitante();
    $res = con_archivo('vecinos.json', function ($d) use ($lat, $lon, $r, $yo) {
        $d = limpiar($d); $out = [];
        foreach ($d['rep'] as $x) {
            $k = km($lat, $lon, $x['lat'], $x['lon']);
            if ($k > $r) continue;
            $out[] = ['id' => $x['id'], 'tipo' => $x['tipo'], 'tam' => $x['tam'] ?? null, 'km' => round($k),
                      'min' => (int)floor((time() - $x['t']) / 60), 'conf' => count($x['c'] ?? []),
                      'meu' => $x['v'] === $yo || in_array($yo, $x['c'] ?? []), 'lat' => $x['lat'], 'lon' => $x['lon']];
        }
        usort($out, fn($a, $b) => $a['min'] <=> $b['min']);
        return [$d, ['ok' => true, 'reportes' => array_slice($out, 0, 60), 'total' => count($d['rep'])]];
    });
    salir($res);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = entrada(); $yo = visitante();
    if (($in['acao'] ?? '') === 'novo') {
        $tipo = in_array($in['tipo'] ?? '', TIPOS) ? $in['tipo'] : null;
        $tam = $tipo === 'granizo' && in_array($in['tam'] ?? '', TAMS) ? $in['tam'] : null;
        $lat = coord($in['lat'] ?? null, -60, 15); $lon = coord($in['lon'] ?? null, -90, -30);
        if (!$tipo || $lat === null || $lon === null || ($tipo === 'granizo' && !$tam)) salir(['ok' => false, 'error' => 'datos'], 400);
        $res = con_archivo('vecinos.json', function ($d) use ($tipo, $tam, $lat, $lon, $yo) {
            $d = limpiar($d);
            if (isset($d['ult'][$yo]) && $d['ult'][$yo] > time() - 600) return [$d, ['ok' => false, 'error' => 'espera']];
            $d['ult'][$yo] = time();
            $d['rep'][] = ['id' => bin2hex(random_bytes(6)), 'tipo' => $tipo, 'tam' => $tam, 'lat' => $lat, 'lon' => $lon, 't' => time(), 'v' => $yo, 'c' => []];
            if (count($d['rep']) > 3000) $d['rep'] = array_slice($d['rep'], -3000);
            return [$d, ['ok' => true]];
        });
        salir($res, $res['ok'] ? 200 : 429);
    }
    if (($in['acao'] ?? '') === 'confirmar') {
        $id = preg_replace('/[^a-f0-9]/', '', (string)($in['id'] ?? ''));
        $res = con_archivo('vecinos.json', function ($d) use ($id, $yo) {
            $d = limpiar($d);
            foreach ($d['rep'] as &$x) {
                if ($x['id'] !== $id) continue;
                if ($x['v'] === $yo || in_array($yo, $x['c'])) return [$d, ['ok' => false, 'error' => 'ya']];
                $x['c'][] = $yo; return [$d, ['ok' => true, 'conf' => count($x['c'])]];
            }
            return [$d, ['ok' => false, 'error' => 'no']];
        });
        salir($res);
    }
}
salir(['ok' => false, 'error' => 'metodo'], 405);
