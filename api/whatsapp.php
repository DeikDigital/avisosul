<?php
/* Aviso Sul — inscripcion a alertas por WhatsApp (Premium).
   POST {acao:'inscrever', tel, lat, lon, cidade, lang}  -> queda pendiente con un codigo; se activa cuando la persona
        manda "ATIVAR <codigo>" a nuestro numero (eso prueba que el numero es suyo y da el permiso de WhatsApp).
   POST {acao:'cancelar', tel, codigo}                   -> se borra la inscripcion.
   GET  ?tel=&codigo=                                    -> estado de la inscripcion.
   Los numeros se guardan fuera de public_html y solo se usan para estos avisos. */
require __DIR__ . '/_comun.php';

function config_wa() {
    $f = dir_privado() . '/whatsapp.json';
    $c = file_exists($f) ? json_decode(file_get_contents($f), true) : null;
    return is_array($c) ? $c : [];
}
function tel_ok($t, $pais = '') {
    $t = ltrim(preg_replace('/\D+/', '', (string)$t), '0');
    if ($pais === 'ar' || (strpos($t, '54') === 0 && strlen($t) >= 12)) {   // Argentina: formato de WhatsApp 54 9 + área + número
        if (strpos($t, '54') === 0) $t = substr($t, 2);
        $t = ltrim($t, '0'); if (strpos($t, '9') === 0 && strlen($t) === 11) $t = substr($t, 1);
        if (preg_match('/^(\d{2,4})15(\d{6,8})$/', $t, $m) && strlen($m[1] . $m[2]) === 10) $t = $m[1] . $m[2];   // quita el 15 del celular
        return strlen($t) === 10 ? '549' . $t : null;
    }
    if (strlen($t) === 10 || strlen($t) === 11) $t = '55' . $t;          // numero brasileno sin el 55
    return (strlen($t) >= 12 && strlen($t) <= 15) ? $t : null;
}
/* Solo Premium: cada mensaje de WhatsApp tiene costo. La cuenta tiene que estar verificada y con Premium vigente. */
function email_premium($tok) {
    $h = hash('sha256', (string)$tok); $em = null;
    $f = dir_privado() . '/cuentas.json'; $d = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    foreach ($d as $k => $c) if (isset($c['ses'][$h])) { $em = $k; break; }
    if (!$em) return null;
    $p = json_decode(@file_get_contents(dir_privado() . '/premium.json') ?: '{}', true) ?: [];
    return (int)($p[$em]['hasta'] ?? 0) > time() ? $em : null;
}
$cfg = config_wa();
$numero = $cfg['numero_publico'] ?? null;                               // numero de Aviso Sul, solo digitos

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tel = tel_ok($_GET['tel'] ?? '', $_GET['pais'] ?? ''); $cod = (string)($_GET['codigo'] ?? '');
    if (!$tel) salir(['ok' => false, 'error' => 'tel']);
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $cod) {
        $x = $d[$tel] ?? null;
        return [$d, ($x && hash_equals($x['codigo'], $cod)) ? ['ok' => true, 'estado' => $x['estado']] : ['ok' => true, 'estado' => 'nenhum']];
    });
    salir($r);
}

$e = entrada(); $acao = $e['acao'] ?? '';
$tel = tel_ok($e['tel'] ?? '', $e['pais'] ?? '');
if (!$tel) salir(['ok' => false, 'error' => 'tel'], 400);

if ($acao === 'inscrever') {
    $email = email_premium($e['token'] ?? ''); if (!$email) salir(['ok' => false, 'error' => 'premium'], 403);
    $lat = round((float)($e['lat'] ?? 0), 2); $lon = round((float)($e['lon'] ?? 0), 2);
    if ($lat < -56 || $lat > 6 || $lon < -75 || $lon > -30) salir(['ok' => false, 'error' => 'local'], 400);
    $cidade = preg_replace('/[^a-z]/', '', (string)($e['cidade'] ?? ''));
    $lang = ($e['lang'] ?? 'pt') === 'es' ? 'es' : 'pt';
    $vis = visitante();
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $lat, $lon, $cidade, $lang, $vis, $email) {
        $n = 0; foreach ($d as $x) if (($x['vis'] ?? '') === $vis && time() - ($x['criado'] ?? 0) < 86400) $n++;
        if ($n >= 3 && !isset($d[$tel])) return [$d, ['ok' => false, 'error' => 'limite']];
        $cod = isset($d[$tel]) ? $d[$tel]['codigo'] : (string)random_int(1000, 9999);
        $d[$tel] = ['codigo' => $cod, 'estado' => ($d[$tel]['estado'] ?? 'pendente'), 'lat' => $lat, 'lon' => $lon,
                    'cidade' => $cidade, 'lang' => $lang, 'criado' => $d[$tel]['criado'] ?? time(), 'vis' => $vis, 'email' => $email,
                    'enviados' => $d[$tel]['enviados'] ?? []];
        return [$d, ['ok' => true, 'codigo' => $cod, 'estado' => $d[$tel]['estado']]];
    });
    if ($r['ok']) $r['numero'] = $numero;   // null mientras el servicio no este encendido
    salir($r, $r['ok'] ? 200 : 429);
}
if ($acao === 'prefs') {   /* nivel minimo (1-3), fenomenos y silencio de noche, elegidos en Ajustes */
    $cod = (string)($e['codigo'] ?? '');
    $fen = array_values(array_intersect((array)($e['fen'] ?? []), ['granizo', 'chuva', 'vento', 'raios']));
    $km = (int)($e['km'] ?? 5); if (!in_array($km, [5, 10, 20, 50], true)) $km = 5;
    $pr = ['nivel' => max(1, min(3, (int)($e['nivel'] ?? 2))), 'km' => $km, 'fen' => $fen ?: ['granizo', 'chuva', 'vento', 'raios'],
           'noche' => !empty($e['noche']), 'desde' => max(0, min(23, (int)($e['desde'] ?? 23))), 'hasta' => max(0, min(23, (int)($e['hasta'] ?? 7)))];
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $cod, $pr) {
        if (!isset($d[$tel]) || !hash_equals($d[$tel]['codigo'], $cod)) return [$d, ['ok' => false, 'error' => 'tel']];
        $d[$tel]['prefs'] = $pr; return [$d, ['ok' => true]];
    });
    salir($r, $r['ok'] ? 200 : 403);
}
if ($acao === 'lugar') {   /* avisar también por otro lugar consultado (Premium): on=true lo suma, on=false lo saca */
    $cod = (string)($e['codigo'] ?? ''); $lat = round((float)($e['lat'] ?? 0), 3); $lon = round((float)($e['lon'] ?? 0), 3);
    $nome = mb_substr(strip_tags((string)($e['nome'] ?? '')), 0, 60); $on = !empty($e['on']);
    if ($lat < -60 || $lat > 15 || $lon < -90 || $lon > -30) salir(['ok' => false, 'error' => 'local'], 400);
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $cod, $lat, $lon, $nome, $on) {
        if (!isset($d[$tel]) || !hash_equals($d[$tel]['codigo'], $cod)) return [$d, ['ok' => false, 'error' => 'tel']];
        $k = $lat . ',' . $lon; $ex = $d[$tel]['extras'] ?? [];
        if ($on) { if (count($ex) >= 10 && !isset($ex[$k])) return [$d, ['ok' => false, 'error' => 'limite']]; $ex[$k] = ['lat' => $lat, 'lon' => $lon, 'nome' => $nome]; }
        else unset($ex[$k]);
        $d[$tel]['extras'] = $ex; return [$d, ['ok' => true, 'on' => $on, 'estado' => $d[$tel]['estado']]];
    });
    salir($r);
}
if ($acao === 'ubicar') {   /* la app manda la ubicacion actual al abrirse y cada hora: el punto principal del aviso sigue a la persona */
    $cod = (string)($e['codigo'] ?? ''); $lat = round((float)($e['lat'] ?? 0), 3); $lon = round((float)($e['lon'] ?? 0), 3);
    if ($lat < -60 || $lat > 15 || $lon < -90 || $lon > -30) salir(['ok' => false, 'error' => 'local'], 400);
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $cod, $lat, $lon) {
        if (!isset($d[$tel]) || !hash_equals($d[$tel]['codigo'], $cod)) return [$d, ['ok' => false, 'error' => 'tel']];
        $d[$tel]['lat'] = $lat; $d[$tel]['lon'] = $lon; $d[$tel]['ubic_ts'] = time(); return [$d, ['ok' => true]];
    });
    salir($r, $r['ok'] ? 200 : 403);
}
if ($acao === 'cancelar') {
    $cod = (string)($e['codigo'] ?? '');
    $r = con_archivo('whatsapp_inscritos.json', function ($d) use ($tel, $cod) {
        if (isset($d[$tel]) && hash_equals($d[$tel]['codigo'], $cod)) { unset($d[$tel]); return [$d, ['ok' => true]]; }
        return [$d, ['ok' => false, 'error' => 'codigo']];
    });
    salir($r);
}
salir(['ok' => false, 'error' => 'acao'], 400);
