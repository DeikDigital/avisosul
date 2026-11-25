<?php
/* Aviso Sul — cuenta con email (código de 6 dígitos) o con Google, para guardar los ajustes.
   GET  ?cfg=1                         -> {"google": client_id o null}
   GET  ?token=...                     -> {"ok":true,"email":..,"ajustes":{..}}
   POST {"acao":"codigo","email"}      -> manda un código al email (vale 15 min)
   POST {"acao":"verificar","email","codigo"} -> {"ok":true,"token":..}
   POST {"acao":"email","email"}       -> entra sin código: sesión "no verificada" (guarda sus propios ajustes, sin Premium ni pagos)
   POST {"acao":"google","credential"} -> {"ok":true,"token":..}   (credencial de Google Identity Services)
   POST {"acao":"guardar","token","ajustes"} -> guarda los ajustes (máx. 20 KB)
   POST {"acao":"salir","token"}       -> cierra la sesión
   Datos fuera de public_html. El Client ID de Google va en datos_privados/google.json {"client_id":"..."}. */
require __DIR__ . '/_comun.php';
require __DIR__ . '/_precios.php';
function cfg_google() { $f = dir_privado() . '/google.json'; $c = file_exists($f) ? json_decode(file_get_contents($f), true) : null; return $c['client_id'] ?? GOOGLE_CLIENT_ID; }
/* Client ID público del proyecto "Aviso Sul" en Google Cloud (se puede reemplazar desde /admin/). */
const GOOGLE_CLIENT_ID = '483433790554-bqvpjs90f02q8tnakbg6uj77hf443m1r.apps.googleusercontent.com';
function premium_de($em) {
    $f = dir_privado() . '/premium.json'; if (!file_exists($f)) return null;
    $p = json_decode(file_get_contents($f), true); $x = $p[$em] ?? null; if (!$x) return null;
    return ['hasta' => (int)($x['hasta'] ?? 0), 'plan' => $x['plan'] ?? 'premium', 'estado' => $x['estado'] ?? '', 'pendiente' => $x['pendiente'] ?? null];
}
function checkout_br() { $f = dir_privado() . '/hotmart.json'; $c = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; $o = []; foreach (($c['checkout'] ?? []) as $k => $u) if (preg_match('#^https://(pay|go)\.hotmart\.com/#', (string)$u)) $o[$k] = $u; return $o ?: null; }
function hm_links($c) { if (!checkout_br()) return null; $p = precios_para($c); $o = []; foreach (PLANES_P as $k) if (!empty($p['br'][$k]['link'])) $o[$k] = $p['br'][$k]['link']; return $o ?: null; }
function mp_ok() { $f = dir_privado() . '/mercadopago.json'; $c = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; return !empty($c['access_token']); }
function email_ok($e) { $e = strtolower(trim((string)$e)); return filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 120 ? $e : null; }
function nueva_sesion($email) {
    $tok = bin2hex(random_bytes(24));
    con_archivo('cuentas.json', function ($d) use ($email, $tok) {
        if (!isset($d[$email])) $d[$email] = ['creado' => time(), 'ajustes' => (object)[], 'ses' => []];
        $d[$email]['ses'][hash('sha256', $tok)] = time();
        $d[$email]['ses'] = array_slice($d[$email]['ses'], -10, null, true);
        $d[$email]['visto'] = time();
        return [$d, true];
    });
    return $tok;
}
function cuenta_de($tok) {
    $h = hash('sha256', (string)$tok);
    return con_archivo('cuentas.json', function ($d) use ($h) {
        foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$d, [$em, $c]];
        return [$d, null];
    });
}
/* Sesiones sin verificar: archivo aparte, nunca ven ni pisan los datos de una cuenta verificada con el mismo email. */
function nv_de($tok) {
    $h = hash('sha256', (string)$tok);
    return con_archivo('cuentas_nv.json', function ($d) use ($h) { $x = $d[$h] ?? null; if ($x) { $d[$h]['visto'] = time(); } return [$d, $x]; });
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['cfg'])) { $gf = dir_privado() . '/google.json'; $gc = file_exists($gf) ? json_decode(file_get_contents($gf), true) : []; salir(['ok' => true, 'google' => cfg_google(), 'maps' => $gc['maps_key'] ?? null, 'hotmart' => hm_links($_GET['c'] ?? ''), 'mp' => mp_ok()]); }
    $c = cuenta_de($_GET['token'] ?? '');
    if (!$c) { $n = nv_de($_GET['token'] ?? ''); if ($n) salir(['ok' => true, 'email' => $n['email'], 'ajustes' => $n['ajustes'] ?? (object)[], 'premium' => null, 'nv' => true]); }
    if (!$c) salir(['ok' => false, 'error' => 'sesion'], 401);
    salir(['ok' => true, 'email' => $c[0], 'ajustes' => $c[1]['ajustes'] ?? (object)[], 'premium' => premium_de($c[0])]);
}
$e = entrada(); $acao = $e['acao'] ?? '';

if ($acao === 'codigo') {
    $em = email_ok($e['email'] ?? ''); if (!$em) salir(['ok' => false, 'error' => 'email'], 400);
    $vis = visitante(); $cod = (string)random_int(100000, 999999);
    $r = con_archivo('cuentas_codigos.json', function ($d) use ($em, $cod, $vis) {
        $d = array_filter($d, fn($x) => $x['t'] > time() - 3600);
        $n = 0; foreach ($d as $x) if (($x['v'] ?? '') === $vis) $n++;
        if ($n >= 5) return [$d, false];
        $d[$em] = ['h' => hash('sha256', $em . '|' . $cod), 't' => time(), 'v' => $vis, 'i' => 0];
        return [$d, true];
    });
    if (!$r) salir(['ok' => false, 'error' => 'limite'], 429);
    $asunto = 'Seu código do Aviso Sul / Tu código de Aviso Sul: ' . $cod;
    $txt = "Seu código para entrar no Aviso Sul é: $cod\nTu código para entrar en Aviso Sul es: $cod\n\nVale por 15 minutos. Se não foi você, ignore este e-mail.\nSi no fuiste vos, ignorá este mail.\n\nhttps://avisosul.com";
    $ok = @mail($em, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $txt, "From: Aviso Sul <contato@avisosul.com>\r\nContent-Type: text/plain; charset=UTF-8");
    salir(['ok' => (bool)$ok, 'error' => $ok ? null : 'envio']);
}
if ($acao === 'email') {
    $em = email_ok($e['email'] ?? ''); if (!$em) salir(['ok' => false, 'error' => 'email'], 400);
    $vis = visitante(); $tok = bin2hex(random_bytes(24));
    $ok = con_archivo('cuentas_nv.json', function ($d) use ($em, $tok, $vis) {
        $n = 0; foreach ($d as $x) if (($x['v'] ?? '') === $vis && $x['t'] > time() - 3600) $n++;
        if ($n >= 20) return [$d, false];
        $d[hash('sha256', $tok)] = ['email' => $em, 't' => time(), 'v' => $vis, 'ajustes' => (object)[]];
        return [$d, true];
    });
    if (!$ok) salir(['ok' => false, 'error' => 'limite'], 429);
    salir(['ok' => true, 'token' => $tok, 'email' => $em, 'nv' => true]);
}
if ($acao === 'verificar') {
    $em = email_ok($e['email'] ?? ''); $cod = preg_replace('/\D/', '', (string)($e['codigo'] ?? ''));
    if (!$em || strlen($cod) !== 6) salir(['ok' => false, 'error' => 'datos'], 400);
    $ok = con_archivo('cuentas_codigos.json', function ($d) use ($em, $cod) {
        $x = $d[$em] ?? null;
        if (!$x || $x['t'] < time() - 900 || $x['i'] >= 5) return [$d, false];
        if (!hash_equals($x['h'], hash('sha256', $em . '|' . $cod))) { $d[$em]['i']++; return [$d, false]; }
        unset($d[$em]); return [$d, true];
    });
    if (!$ok) salir(['ok' => false, 'error' => 'codigo']);
    salir(['ok' => true, 'token' => nueva_sesion($em), 'email' => $em, 'premium' => premium_de($em)]);
}
if ($acao === 'google') {
    $cid = cfg_google(); if (!$cid) salir(['ok' => false, 'error' => 'sin_google'], 400);
    $cred = (string)($e['credential'] ?? '');
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($cred));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $j = json_decode(curl_exec($ch) ?: '', true); curl_close($ch);
    if (!$j || ($j['aud'] ?? '') !== $cid || ($j['email_verified'] ?? '') !== 'true' || !in_array($j['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'])) salir(['ok' => false, 'error' => 'google'], 401);
    $em = email_ok($j['email'] ?? ''); if (!$em) salir(['ok' => false, 'error' => 'google'], 401);
    salir(['ok' => true, 'token' => nueva_sesion($em), 'email' => $em, 'premium' => premium_de($em)]);
}
if ($acao === 'guardar') {
    $aj = $e['ajustes'] ?? null; if (!is_array($aj) || strlen(json_encode($aj)) > 20000) salir(['ok' => false, 'error' => 'ajustes'], 400);
    $h = hash('sha256', (string)($e['token'] ?? ''));
    $r = con_archivo('cuentas.json', function ($d) use ($h, $aj) {
        foreach ($d as $em => $c) if (isset($c['ses'][$h])) { $d[$em]['ajustes'] = $aj; $d[$em]['visto'] = time(); return [$d, true]; }
        return [$d, false];
    });
    if (!$r) $r = con_archivo('cuentas_nv.json', function ($d) use ($h, $aj) { if (!isset($d[$h])) return [$d, false]; $d[$h]['ajustes'] = $aj; $d[$h]['visto'] = time(); return [$d, true]; });
    salir(['ok' => $r], $r ? 200 : 401);
}
if ($acao === 'salir') {
    $h = hash('sha256', (string)($e['token'] ?? ''));
    con_archivo('cuentas.json', function ($d) use ($h) { foreach ($d as $em => $c) unset($d[$em]['ses'][$h]); return [$d, true]; });
    con_archivo('cuentas_nv.json', function ($d) use ($h) { unset($d[$h]); return [$d, true]; });
    salir(['ok' => true]);
}
salir(['ok' => false, 'error' => 'acao'], 400);
