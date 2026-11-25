<?php
/* Aviso Sul / Alerta Sur — configuración de cobros (solo administradores).
   Entra solo una sesión de cuenta (api/cuenta.php) cuyo email esté en ADMINS.
   POST {"acao":"estado","token"}                       -> qué está configurado (sin mostrar secretos) y últimos avisos de pago
   POST {"acao":"hotmart","token","hottok","checkout":{mensal,temporada,anual},"extra":{codigo:plan}}  -> guarda hotmart.json
   POST {"acao":"mp","token","access_token","webhook_secret"}                                          -> verifica y guarda mercadopago.json
   Un campo secreto vacío conserva el valor guardado. */
require __DIR__ . '/_comun.php';
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
salir(['ok' => false, 'error' => 'acao'], 400);
