<?php
/* Alerta de vecinos: reportes de granizo, alagamento, vendaval, rayos u otro fenómeno, con texto y foto o video opcionales.
   GET  ?lat=&lon=&r=30|100|300  -> reportes de las últimas 72 h dentro del radio
   POST multipart (acao=novo, tipo, tam, texto, lat, lon, media=archivo opcional)   o JSON {"acao":"novo",...}
   POST JSON {"acao":"confirmar","id":"..."}
   Se guarda la hora exacta y la ubicación con 3 decimales (~100 m). No se guarda quién reportó (solo un hash diario para frenar repeticiones).
   Las fotos y videos quedan fuera de public_html y se borran junto con el reporte. */
require __DIR__ . '/_comun.php';
const HORAS = 72;
const TIPOS = ['granizo', 'alagamento', 'vendaval', 'raios', 'outro'];
const TAMS = ['ervilha', 'gude', 'noz', 'ovo'];
const MEDIA_MAX = 25 * 1024 * 1024;
const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

function km($a1, $o1, $a2, $o2) {
    $r = 6371; $d1 = deg2rad($a2 - $a1); $d2 = deg2rad($o2 - $o1);
    $x = sin($d1 / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($d2 / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($x)));
}
function dir_media() { $d = dir_privado() . '/media'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function limpiar($d) {
    $lim = time() - HORAS * 3600; $quedan = [];
    foreach ($d['rep'] ?? [] as $x) {
        if ($x['t'] >= $lim) { $quedan[] = $x; continue; }
        if (!empty($x['m'])) @unlink(dir_media() . '/' . $x['m']);
    }
    $d['rep'] = $quedan;
    $d['ult'] = array_filter($d['ult'] ?? [], fn($t) => $t >= time() - 3600);
    return $d;
}
function coord($v, $min, $max, $dec = 3) { return is_numeric($v) && $v >= $min && $v <= $max ? round((float)$v, $dec) : null; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lat = coord($_GET['lat'] ?? null, -60, 15); $lon = coord($_GET['lon'] ?? null, -90, -30);
    $r = in_array((int)($_GET['r'] ?? 10), [10, 30, 100, 300]) ? (int)$_GET['r'] : 10;
    if ($lat === null || $lon === null) salir(['ok' => false, 'error' => 'coordenadas'], 400);
    $yo = visitante();
    $res = con_archivo('vecinos.json', function ($d) use ($lat, $lon, $r, $yo) {
        $d = limpiar($d); $out = [];
        foreach ($d['rep'] as $x) {
            $k = km($lat, $lon, $x['lat'], $x['lon']);
            if ($k > $r) continue;
            $out[] = ['id' => $x['id'], 'tipo' => $x['tipo'], 'tam' => $x['tam'] ?? null, 'texto' => $x['tx'] ?? '', 'km' => round($k, 1),
                      'min' => (int)floor((time() - $x['t']) / 60), 'ts' => $x['t'], 'conf' => count($x['c'] ?? []),
                      'media' => empty($x['m']) ? null : (preg_match('/\.(mp4|webm|mov)$/', $x['m']) ? 'video' : 'foto'),
                      'meu' => $x['v'] === $yo || in_array($yo, $x['c'] ?? []), 'lat' => $x['lat'], 'lon' => $x['lon']];
        }
        // coincidencia: reportes del mismo tipo a 10 km o menos y con 5 minutos o menos de diferencia (contando el propio)
        foreach ($out as &$o) {
            $n = 0;
            foreach ($d['rep'] as $y) {
                if ($y['tipo'] !== $o['tipo'] || abs($y['t'] - $o['ts']) > 300) continue;
                if (km($o['lat'], $o['lon'], $y['lat'], $y['lon']) <= 10) $n++;
            }
            $o['coinc'] = $n;
        }
        unset($o);
        usort($out, fn($a, $b) => $a['min'] <=> $b['min']);
        return [$d, ['ok' => true, 'reportes' => array_slice($out, 0, 60), 'total' => count($d['rep'])]];
    });
    salir($res);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = !empty($_POST) ? $_POST : entrada(); $yo = visitante();
    if (($in['acao'] ?? '') === 'novo') {
        $tipo = in_array($in['tipo'] ?? '', TIPOS) ? $in['tipo'] : null;
        $tam = $tipo === 'granizo' && in_array($in['tam'] ?? '', TAMS) ? $in['tam'] : null;
        $lat = coord($in['lat'] ?? null, -60, 15); $lon = coord($in['lon'] ?? null, -90, -30);
        $tx = trim(mb_substr(strip_tags((string)($in['texto'] ?? '')), 0, 280));
        if (!$tipo || $lat === null || $lon === null || ($tipo === 'granizo' && !$tam) || ($tipo === 'outro' && $tx === '')) salir(['ok' => false, 'error' => 'datos'], 400);
        /* foto o video opcional: se valida el tipo real del archivo, no el que dice el navegador */
        $media = null; $tmp = null;
        if (!empty($_FILES['media']) && $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['media'];
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MEDIA_MAX) salir(['ok' => false, 'error' => 'archivo'], 400);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!isset(MIMES[$mime])) salir(['ok' => false, 'error' => 'formato'], 400);
            $tmp = $f['tmp_name']; $media = MIMES[$mime];
        }
        $res = con_archivo('vecinos.json', function ($d) use ($tipo, $tam, $lat, $lon, $tx, $yo, $media, $tmp) {
            $d = limpiar($d);
            if (isset($d['ult'][$yo]) && $d['ult'][$yo] > time() - 600) return [$d, ['ok' => false, 'error' => 'espera']];
            $id = bin2hex(random_bytes(6)); $m = null;
            if ($media && move_uploaded_file($tmp, dir_media() . "/$id.$media")) $m = "$id.$media";
            $d['ult'][$yo] = time();
            $d['rep'][] = ['id' => $id, 'tipo' => $tipo, 'tam' => $tam, 'tx' => $tx, 'm' => $m, 'lat' => $lat, 'lon' => $lon, 't' => time() - 60 * max(0, min(180, (int)($_POST['hace'] ?? 0))), 'v' => $yo, 'c' => []];
            if (count($d['rep']) > 3000) $d['rep'] = array_slice($d['rep'], -3000);
            return [$d, ['ok' => true, 'id' => $id]];
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
