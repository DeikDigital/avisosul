<?php
/* Aviso Sul / Alerta Sur — configuración de cobros (solo administradores).
   Entra solo una sesión de cuenta (api/cuenta.php) cuyo email esté en ADMINS.
   POST {"acao":"estado","token"}                       -> qué está configurado (sin mostrar secretos) y últimos avisos de pago
   POST {"acao":"hotmart","token","hottok","checkout":{mensal,temporada,anual},"extra":{codigo:plan}}  -> guarda hotmart.json
   POST {"acao":"precios","token","precios":{…}}  -> guarda precios.json (máximos, piso, campañas, relámpago, ofertas de Hotmart)
   POST {"acao":"google","token","client_id"}  -> guarda el Client ID de Google (ingreso con Google en la app)
   POST {"acao":"mp","token","access_token","webhook_secret"}                                          -> verifica y guarda mercadopago.json
   Un campo secreto vacío conserva el valor guardado. */
require __DIR__ . '/_comun.php';
require __DIR__ . '/_precios.php';
const ADMINS = ['deikdigital@gmail.com'];

function sesion_admin($tok) {
    $h = hash('sha256', (string)$tok);
    $em = con_archivo('cuentas.json', function ($d) use ($h) { foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$d, $em]; return [$d, null]; });
    return $em && in_array($em, ADMINS, true) ? $em : null;
}
function leer($n) { $f = dir_privado() . '/' . $n; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function escribir($n, $d) { $f = dir_privado() . '/' . $n; file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); @chmod($f, 0600); }
function cola($s) { $s = (string)$s; return $s === '' ? null : '…' . substr($s, -4); }
function oferta($url) { $q = []; parse_str((string)parse_url($url, PHP_URL_QUERY), $q); return preg_replace('/[^A-Za-z0-9]/', '', (string)($q['off'] ?? '')); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false], 405);
$e = entrada();
$admin = sesion_admin($e['token'] ?? '');
if (!$admin) salir(['ok' => false, 'error' => 'no_admin'], 403);
$acao = $e['acao'] ?? '';

if ($acao === 'estado') {
    $hm = leer('hotmart.json'); $mp = leer('mercadopago.json');
    $ev = array_slice(leer('hotmart_eventos.json'), -8); $evm = array_slice(leer('mp_eventos.json'), -8);
    $sin = [];   // ofertas que llegaron en avisos y no tienen plan asignado
    foreach (leer('premium.json') as $em => $x) if (!empty($x['pendiente'])) $sin[] = substr($x['pendiente'], strrpos($x['pendiente'], ':') + 1);
    salir(['ok' => true, 'admin' => $admin,
        'hotmart' => ['hottok' => cola($hm['hottok'] ?? ''), 'checkout' => $hm['checkout'] ?? (object)[], 'planes' => $hm['planes'] ?? (object)[]],
        'mp' => ['access_token' => cola($mp['access_token'] ?? ''), 'webhook_secret' => cola($mp['webhook_secret'] ?? ''), 'cuenta' => $mp['cuenta'] ?? null],
        'google' => (leer('google.json')['client_id'] ?? GOOGLE_CLIENT_ID),
        'precios' => precios_cfg(), 'precios_hoy' => precios_para(''),
        'smtp' => !empty(leer('smtp.json')['pass']),
        'whatsapp' => (function () { $w = leer('whatsapp.json'); if (empty($w['verify_token'])) { $w['verify_token'] = bin2hex(random_bytes(12)); escribir('whatsapp.json', $w); }
            return ['activo' => !empty($w['activo']), 'phone_number_id' => $w['phone_number_id'] ?? '', 'numero_publico' => $w['numero_publico'] ?? '', 'plantilla' => $w['plantilla'] ?? 'aviso_clima',
                    'token' => cola($w['token'] ?? ''), 'app_secret' => cola($w['app_secret'] ?? ''), 'verify_token' => $w['verify_token']]; })(),
        'pixel' => (leer('meta.json')['pixel'] ?? ''), 'embudo' => array_slice(leer('embudo.json'), -14, null, true),
        'ofertas_sin_plan' => array_values(array_unique(array_map('trim', $sin))),
        'eventos_hotmart' => array_map(fn($x) => ['t' => $x['t'], 'ev' => $x['ev'], 'off' => $x['off'], 'prod' => $x['prod'], 'email' => $x['email'] ? substr($x['email'], 0, 3) . '…' : ''], $ev),
        'eventos_mp' => array_map(fn($x) => ['t' => $x['t'], 'estado' => $x['estado'], 'plan' => $x['plan'], 'monto' => $x['monto']], $evm)]);
}

if ($acao === 'hotmart') {
    $hm = leer('hotmart.json');
    $hot = trim((string)($e['hottok'] ?? '')); if ($hot !== '') $hm['hottok'] = $hot;
    $DIAS = ['mensal' => ['plan' => 'mensal', 'dias' => 31], 'anual' => ['plan' => 'anual', 'dias' => 366], 'temporada' => ['plan' => 'temporada', 'hasta' => '03-31']];
    $planes = [];
    foreach (['mensal', 'temporada', 'anual'] as $p) {
        $u = trim((string)($e['checkout'][$p] ?? ''));
        if ($u === '') { unset($hm['checkout'][$p]); continue; }
        if (!preg_match('#^https://(pay|go)\.hotmart\.com/[A-Za-z0-9]+#', $u)) salir(['ok' => false, 'error' => 'url_' . $p], 400);
        $hm['checkout'][$p] = $u;
        $off = oferta($u); if ($off) $planes[$off] = $DIAS[$p];
        if (preg_match('#hotmart\.com/([A-Za-z0-9]+)#', $u, $m)) $planes[$m[1]] = $planes[$m[1]] ?? $DIAS[$p];   // código del producto
    }
    foreach ((array)($e['extra'] ?? []) as $cod => $p) { $cod = preg_replace('/[^A-Za-z0-9]/', '', (string)$cod); if ($cod && isset($DIAS[$p])) $planes[$cod] = $DIAS[$p]; }
    // conserva asignaciones manuales previas que no choquen
    foreach (($hm['planes'] ?? []) as $k => $v) if (!isset($planes[$k])) $planes[$k] = $v;
    $hm['planes'] = $planes;
    if (empty($hm['hottok'])) salir(['ok' => false, 'error' => 'falta_hottok'], 400);
    escribir('hotmart.json', $hm);
    salir(['ok' => true, 'planes' => $planes]);
}

if ($acao === 'mp') {
    $mp = leer('mercadopago.json');
    $at = trim((string)($e['access_token'] ?? '')); $ws = trim((string)($e['webhook_secret'] ?? ''));
    if ($at !== '') {
        if (!preg_match('/^APP_USR-[A-Za-z0-9-]+$/', $at)) salir(['ok' => false, 'error' => 'formato_token'], 400);
        $ch = curl_init('https://api.mercadopago.com/users/me');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $at], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $r = json_decode(curl_exec($ch) ?: '', true); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200 || empty($r['id'])) salir(['ok' => false, 'error' => 'token_invalido'], 400);
        $mp['access_token'] = $at; $mp['cuenta'] = trim(($r['nickname'] ?? '') . ' · ' . ($r['site_id'] ?? ''));
    }
    if ($ws !== '') $mp['webhook_secret'] = $ws;
    if (empty($mp['access_token'])) salir(['ok' => false, 'error' => 'falta_token'], 400);
    $mp['precios'] = $mp['precios'] ?? ['mensal' => 4990, 'temporada' => 14990, 'anual' => 29990];
    $mp['promo_hasta'] = $mp['promo_hasta'] ?? '2026-12-25';
    escribir('mercadopago.json', $mp);
    salir(['ok' => true, 'cuenta' => $mp['cuenta'] ?? null]);
}
if ($acao === 'precios') {
    $in = $e['precios'] ?? null; if (!is_array($in)) salir(['ok' => false, 'error' => 'datos'], 400);
    $num = fn($x, $a, $b) => is_numeric($x) && $x >= $a && $x <= $b;
    $c = ['maximos' => [], 'piso' => (int)($in['piso'] ?? 0)];
    if (!$num($c['piso'], 0, 90)) salir(['ok' => false, 'error' => 'piso'], 400);
    foreach (['ar' => [500, 10000000], 'br' => [1, 100000]] as $pa => $lim) foreach (PLANES_P as $p) {
        $v = $in['maximos'][$pa][$p] ?? null; if (!$num($v, $lim[0], $lim[1])) salir(['ok' => false, 'error' => "maximo_{$pa}_{$p}"], 400);
        $c['maximos'][$pa][$p] = $pa === 'ar' ? (int)$v : round((float)$v, 2);
    }
    $r = $in['relampago'] ?? [];
    $c['relampago'] = ['activa' => !empty($r['activa']), 'descuento' => (int)($r['descuento'] ?? 0), 'radio_km' => (int)($r['radio_km'] ?? 50), 'horas' => (int)($r['horas'] ?? 6), 'min_vecinos' => (int)($r['min_vecinos'] ?? 2)];
    if (!$num($c['relampago']['descuento'], 0, $c['piso']) || !$num($c['relampago']['radio_km'], 5, 300) || !$num($c['relampago']['horas'], 1, 48) || !$num($c['relampago']['min_vecinos'], 1, 20)) salir(['ok' => false, 'error' => 'relampago'], 400);
    $c['campanas'] = [];
    foreach (array_slice((array)($in['campanas'] ?? []), 0, 12) as $i => $x) {
        $f = fn($d) => preg_match('/^\d{4}-\d\d-\d\d$/', (string)$d) ? $d : null;
        $k = ['id' => preg_replace('/[^a-z0-9]/', '', strtolower((string)($x['id'] ?? ''))) ?: 'c' . $i, 'es' => mb_substr(trim((string)($x['es'] ?? '')), 0, 60), 'pt' => mb_substr(trim((string)($x['pt'] ?? '')), 0, 60),
              'descuento' => (int)($x['descuento'] ?? 0), 'desde' => $f($x['desde'] ?? ''), 'hasta' => $f($x['hasta'] ?? ''), 'activa' => !empty($x['activa'])];
        if ($k['es'] === '' || $k['pt'] === '' || !$k['desde'] || !$k['hasta'] || $k['desde'] > $k['hasta'] || !$num($k['descuento'], 1, $c['piso'])) salir(['ok' => false, 'error' => 'campana_' . ($i + 1)], 400);
        $c['campanas'][] = $k;
    }
    $c['br_ofertas'] = []; $hm = leer('hotmart.json');
    $DIAS = ['mensal' => ['plan' => 'mensal', 'dias' => 31], 'anual' => ['plan' => 'anual', 'dias' => 366], 'temporada' => ['plan' => 'temporada', 'hasta' => '03-31']];
    foreach ((array)($in['br_ofertas'] ?? []) as $d => $pls) foreach (PLANES_P as $p) {
        $o = $pls[$p] ?? null; if (!$o || trim((string)($o['link'] ?? '')) === '') continue;
        $u = trim((string)$o['link']); $d = (int)$d;
        if (!$num($d, 1, $c['piso']) || !preg_match('#^https://(pay|go)\.hotmart\.com/[A-Za-z0-9]+\?(.*&)?off=([A-Za-z0-9]+)#', $u, $m) || !$num($o['precio'] ?? null, 1, 100000)) salir(['ok' => false, 'error' => "oferta_{$d}_{$p}"], 400);
        $c['br_ofertas'][(string)$d][$p] = ['link' => $u, 'precio' => round((float)$o['precio'], 2)];
        $hm['planes'][$m[3]] = $DIAS[$p];   // el aviso de Hotmart de esta oferta activa el plan correcto
    }
    if (!$c['br_ofertas']) $c['br_ofertas'] = (object)[];
    escribir('precios.json', $c); escribir('hotmart.json', $hm);
    salir(['ok' => true, 'precios_hoy' => precios_para('')]);
}
if ($acao === 'whatsapp') {   // datos de WhatsApp Business (Cloud API). Token y clave secreta: los pega Deik; vacío = conservar
    $w = leer('whatsapp.json');
    foreach (['phone_number_id' => '/^\d{6,25}$/', 'numero_publico' => '/^\d{10,15}$/', 'plantilla' => '/^[a-z0-9_]{1,60}$/'] as $k => $re) {
        $v = preg_replace('/\s+/', '', (string)($e[$k] ?? '')); if ($k === 'numero_publico') $v = preg_replace('/\D/', '', $v);
        if ($v === '') continue; if (!preg_match($re, $v)) salir(['ok' => false, 'error' => $k], 400); $w[$k] = $v;
    }
    foreach (['wa_token' => 'token', 'app_secret' => 'app_secret'] as $in => $k) { $v = trim((string)($e[$in] ?? '')); if ($v !== '') { if (strlen($v) > 600) salir(['ok' => false, 'error' => $k], 400); $w[$k] = $v; } }
    if (empty($w['phone_number_id']) && !empty($w['token'])) {   // busca solo el numero de la cuenta de WhatsApp de Aviso Sul
        $waba = $w['waba_id'] ?? '2181588913236388';
        $ch = curl_init('https://graph.facebook.com/v21.0/' . $waba . '/phone_numbers?fields=id,display_phone_number,verified_name');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $w['token']]]);
        $j = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
        if (!empty($j['data'][0]['id'])) { $w['phone_number_id'] = $j['data'][0]['id']; $w['numero_publico'] = $w['numero_publico'] ?? preg_replace('/\D/', '', $j['data'][0]['display_phone_number'] ?? ''); }
        else $w['error_api'] = $j['error']['message'] ?? 'sin_numero';
    }
    $w['activo'] = !empty($e['activo']) && !empty($w['token']) && !empty($w['phone_number_id']);
    escribir('whatsapp.json', $w);
    salir(['ok' => true, 'activo' => $w['activo'], 'phone_number_id' => $w['phone_number_id'] ?? '', 'numero_publico' => $w['numero_publico'] ?? '', 'error_api' => $w['error_api'] ?? null]);
}
if ($acao === 'wa_prueba') {   // manda UNA plantilla de prueba al numero que escribe Deik (cuesta como un aviso real)
    $w = leer('whatsapp.json');
    if (empty($w['token']) || empty($w['phone_number_id'])) salir(['ok' => false, 'error' => 'falta_config'], 400);
    $to = preg_replace('/\D/', '', (string)($e['tel'] ?? '')); if (strlen($to) < 12 || strlen($to) > 15) salir(['ok' => false, 'error' => 'tel'], 400);
    $es = strpos($to, '54') === 0;
    $cuerpo = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
        'template' => ['name' => $w['plantilla'] ?? 'aviso_clima', 'language' => ['code' => $es ? 'es' : 'pt_BR'],
            'components' => [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => $es ? 'PRUEBA: tormentas fuertes' : 'TESTE: tempestade forte'],
                ['type' => 'text', 'text' => (new DateTime('+3 hours', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('d/m H:i')],
                ['type' => 'text', 'text' => 'https://avisosul.com/' . ($es ? 'cordoba' : 'app') . '/']]]]]];
    $ch = curl_init('https://graph.facebook.com/v21.0/' . $w['phone_number_id'] . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $w['token'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($cuerpo)]);
    $res = curl_exec($ch); $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true) ?: [];
    salir(['ok' => $cod === 200, 'http' => $cod, 'id' => $j['messages'][0]['id'] ?? null, 'error' => $j['error']['message'] ?? null]);
}
if ($acao === 'wa_estado') {   // estados de entrega que mando Meta al webhook + salud del numero
    $w = leer('whatsapp.json'); $num = null;
    if (!empty($w['token']) && !empty($w['phone_number_id'])) {
        $ch = curl_init('https://graph.facebook.com/v21.0/' . $w['phone_number_id'] . '?fields=display_phone_number,verified_name,status,name_status,quality_rating,account_mode,messaging_limit_tier');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $w['token']]]);
        $num = json_decode((string)curl_exec($ch), true); curl_close($ch);
        if (isset($num['error'])) $num = ['error' => $num['error']['message'] ?? 'error'];
    }
    salir(['ok' => true, 'numero' => $num, 'estados' => array_slice(leer('whatsapp_estados.json') ?: [], 0, 10), 'hits' => array_slice(leer('whatsapp_hits.json') ?: [], 0, 5)]);
}
if ($acao === 'smtp') {   // contraseña del buzón contato@avisosul.com (la escribe Deik; nunca se devuelve)
    $pw = (string)($e['pass'] ?? '');
    if ($pw !== '') { if (strlen($pw) > 200) salir(['ok' => false], 400); escribir('smtp.json', ['pass' => $pw]); }
    $ok = enviar_mail($admin, 'Prueba de envío · Aviso Sul', "Si ves este mail en la bandeja de entrada (no en spam), el envío por contato@avisosul.com funciona.\n\nhttps://avisosul.com");
    salir(['ok' => $ok]);
}
if ($acao === 'pixel') {
    $px = preg_replace('/\D/', '', (string)($e['pixel'] ?? ''));
    if ($px !== '' && (strlen($px) < 10 || strlen($px) > 20)) salir(['ok' => false, 'error' => 'formato_pixel'], 400);
    $m = leer('meta.json'); $m['pixel'] = $px ?: null; escribir('meta.json', $m);
    salir(['ok' => true]);
}
if ($acao === 'metaads_probar') {   // comprueba el token contra Meta sin cambiar nada
    require_once __DIR__ . '/anuncios_auto.php'; $c = ma_cfg();
    if (!$c['token']) salir(['ok' => false, 'error' => 'sin_token']);
    try { $me = graph($c, 'GET', 'me', ['fields' => 'id,name']); $ok = ['ok' => true, 'quien' => $me['name'] ?? $me['id'] ?? '']; if ($c['paises']['br']['adset']) { $as = graph($c, 'GET', $c['paises']['br']['adset'], ['fields' => 'name,status,effective_status']); $ok['adset_br'] = ($as['name'] ?? '') . ' · ' . ($as['effective_status'] ?? $as['status'] ?? ''); } salir($ok); }
    catch (Exception $x) { salir(['ok' => false, 'error' => 'meta', 'detalle' => $x->getMessage()]); }
}
if ($acao === 'metaads' || $acao === 'metaads_ver') {   // anuncios que se prenden con temporal (api/anuncios_auto.php)
    require_once __DIR__ . '/anuncios_auto.php';
    $c = ma_cfg();
    if ($acao === 'metaads') {
        $tk = trim((string)($e['token_meta'] ?? '')); if ($tk !== '') { if (strlen($tk) > 600 || preg_match('/\s/', $tk)) salir(['ok' => false, 'error' => 'formato_token'], 400); $c['token'] = $tk; }
        foreach (['br', 'ar'] as $p) { $v = preg_replace('/\D/', '', (string)($e['adset_' . $p] ?? '')); if ($v !== '' || isset($e['adset_' . $p])) $c['paises'][$p]['adset'] = $v; }
        if (isset($e['hasta']) && preg_match('/^\d{4}-\d\d-\d\d$/', $e['hasta'])) $c['hasta'] = $e['hasta'];
        if ((int)($e['radio_km'] ?? 0) > 0) $c['radio_km'] = max(10, min(80, (int)$e['radio_km']));
        $c['prueba'] = !empty($e['prueba']);
        ma_guardar($c);
        if ($c['token'] && !$c['prueba']) { try { graph($c, 'GET', 'me', ['fields' => 'id,name']); } catch (Exception $x) { salir(['ok' => false, 'error' => 'meta', 'detalle' => $x->getMessage()]); } }
    }
    $log = leer('meta_ads_log.json');
    salir(['ok' => true, 'token' => cola($c['token']), 'adset_br' => $c['paises']['br']['adset'], 'adset_ar' => $c['paises']['ar']['adset'],
        'hasta' => $c['hasta'], 'radio_km' => $c['radio_km'], 'prueba' => $c['prueba'], 'plan' => plan($c), 'log' => array_slice($log, -15)]);
}
if ($acao === 'regalar') {   // Premium de regalo (testers, socios): email + días; 0 días = quitar
    $em = strtolower(trim((string)($e['email'] ?? ''))); $dias = (int)($e['dias'] ?? 365);
    if (!filter_var($em, FILTER_VALIDATE_EMAIL) || $dias < 0 || $dias > 3650) salir(['ok' => false, 'error' => 'datos'], 400);
    $p = leer('premium.json');
    if ($dias === 0) { if (($p[$em]['estado'] ?? '') === 'regalo') unset($p[$em]); }
    else { $base = max(time(), (int)($p[$em]['hasta'] ?? 0)); $p[$em] = ['hasta' => $base + $dias * 86400, 'plan' => $p[$em]['plan'] ?? 'anual', 'estado' => $p[$em]['estado'] ?? 'regalo', 'regalo' => trim(mb_substr((string)($e['nota'] ?? ''), 0, 80))]; }
    escribir('premium.json', $p);
    salir(['ok' => true, 'email' => $em, 'hasta' => $p[$em]['hasta'] ?? null]);
}
if ($acao === 'regalos') {
    $o = []; foreach (leer('premium.json') as $em => $x) if (($x['estado'] ?? '') === 'regalo' || ($x['estado'] ?? '') === 'revision') $o[] = ['email' => $em, 'hasta' => $x['hasta'] ?? 0, 'nota' => $x['regalo'] ?? ($x['estado'] ?? '')];
    salir(['ok' => true, 'lista' => $o]);
}
if ($acao === 'revisor') {   // cuenta para que Google revise la app: email fijo, código fijo de 6 dígitos y Premium por 1 año
    $r = ['email' => 'revisao@avisosul.com', 'codigo' => (string)random_int(100000, 999999), 'creado' => time()];
    escribir('revisor.json', $r);
    $p = leer('premium.json'); $p[$r['email']] = ['hasta' => time() + 365 * 86400, 'plan' => 'anual', 'estado' => 'revision'];
    escribir('premium.json', $p);
    salir(['ok' => true, 'email' => $r['email'], 'codigo' => $r['codigo']]);
}
if ($acao === 'google') {
    $cid = trim((string)($e['client_id'] ?? ''));
    if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $cid)) salir(['ok' => false, 'error' => 'formato_google'], 400);
    $g = leer('google.json'); $g['client_id'] = $cid; escribir('google.json', $g);
    salir(['ok' => true]);
}
salir(['ok' => false, 'error' => 'acao'], 400);
