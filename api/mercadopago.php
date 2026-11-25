<?php
/* Alerta Sur (Argentina) — cobro de Premium con Mercado Pago (Checkout Pro, pago único por período).
   POST {"acao":"checkout","token":<sesion de la cuenta>,"plan":"mensal|temporada|anual"}
        -> crea la preferencia de pago y devuelve {"ok":true,"url":init_point}
   POST/GET ?hook=1  -> aviso de Mercado Pago (webhook). Se consulta el pago a la API de Mercado Pago (fuente de verdad)
        y, si está aprobado, se activa Premium en datos_privados/premium.json para el email de la cuenta.

   datos_privados/mercadopago.json (lo completa Deik, nunca va al repositorio):
   { "access_token": "APP_USR-...", "webhook_secret": "opcional: clave secreta de Webhooks",
     "precios": { "mensal": 4990, "temporada": 14990, "anual": 29990 } }
   Planes: mensal = 31 días, anual = 366 días, temporada = hasta el 31/03. Reembolso o contracargo quitan Premium. */
require __DIR__ . '/_comun.php';

function cfg_mp() { $f = dir_privado() . '/mercadopago.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function mp_api($metodo, $ruta, $cfg, $cuerpo = null) {
    $ch = curl_init(($cfg['api_base'] ?? 'https://api.mercadopago.com') . $ruta);   // api_base solo para pruebas locales
    $h = ['Authorization: Bearer ' . $cfg['access_token'], 'Content-Type: application/json'];
    if ($cuerpo !== null) $h[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(12));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($r ?: '', true)];
}
function cuenta_email($tok) {
    $h = hash('sha256', (string)$tok);
    return con_archivo('cuentas.json', function ($d) use ($h) { foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$d, $em]; return [$d, null]; });
}
$PLANES = ['mensal' => ['dias' => 31, 'nombre' => 'Mensual'], 'anual' => ['dias' => 366, 'nombre' => 'Anual'], 'temporada' => ['hasta' => '03-31', 'nombre' => 'Pase de temporada']];
$cfg = cfg_mp();

/* ---------- webhook ---------- */
if (isset($_GET['hook'])) {
    if (empty($cfg['access_token'])) salir(['ok' => false], 503);
    $raw = file_get_contents('php://input'); $j = json_decode($raw ?: '', true) ?: [];
    $tipo = $j['type'] ?? ($_GET['type'] ?? ($_GET['topic'] ?? ''));
    $id = (string)($j['data']['id'] ?? ($_GET['data_id'] ?? ($_GET['data.id'] ?? ($_GET['id'] ?? ''))));
    // firma opcional (x-signature: ts=...,v1=...) si Deik cargó la clave secreta de Webhooks
    if (!empty($cfg['webhook_secret']) && !empty($_SERVER['HTTP_X_SIGNATURE'])) {
        $p = []; foreach (explode(',', $_SERVER['HTTP_X_SIGNATURE']) as $kv) { [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, ''); $p[$k] = $v; }
        $man = 'id:' . strtolower($id) . ';request-id:' . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . ';ts:' . ($p['ts'] ?? '') . ';';
        if (!hash_equals(hash_hmac('sha256', $man, $cfg['webhook_secret']), $p['v1'] ?? '')) salir(['ok' => false, 'error' => 'firma'], 401);
    }
    if ($tipo !== 'payment' || !preg_match('/^\d+$/', $id)) salir(['ok' => true, 'nota' => 'ignorado']);
    [$code, $pago] = mp_api('GET', '/v1/payments/' . $id, $cfg);
    if ($code !== 200 || !$pago) salir(['ok' => false, 'error' => 'consulta'], 502);   // Mercado Pago reintenta
    $ref = explode('|', (string)($pago['external_reference'] ?? ''));
    $email = strtolower($ref[0] ?? ''); $plan = $ref[1] ?? ''; $st = $pago['status'] ?? '';
    con_archivo('mp_eventos.json', function ($l) use ($id, $email, $plan, $st, $pago) {
        $l[] = ['t' => time(), 'pago' => $id, 'email' => $email, 'plan' => $plan, 'estado' => $st, 'monto' => $pago['transaction_amount'] ?? null];
        return [array_slice($l, -300), true];
    });
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($PLANES[$plan])) salir(['ok' => true, 'nota' => 'sin referencia']);
    $pl = $PLANES[$plan]; $tx = 'mp' . $id;
    $res = con_archivo('premium.json', function ($p) use ($email, $plan, $pl, $st, $tx) {
        $x = $p[$email] ?? ['hasta' => 0, 'tx' => []]; $ahora = time();
        if ($st === 'approved') {
            if (in_array($tx, $x['tx'] ?? [], true)) return [$p, 'ya contado'];
            if (!empty($pl['hasta'])) { [$m, $d] = array_map('intval', explode('-', $pl['hasta'])); $y = (int)date('Y'); $lim = mktime(23, 59, 59, $m, $d, $y); if ($lim < $ahora) $lim = mktime(23, 59, 59, $m, $d, $y + 1); $x['hasta'] = max($x['hasta'] ?? 0, $lim); }
            else $x['hasta'] = max($x['hasta'] ?? 0, $ahora) + ($pl['dias'] + 1) * 86400;
            $x['plan'] = $plan; $x['estado'] = 'activa'; $x['fuente'] = 'mercadopago';
            $x['tx'] = array_slice(array_merge($x['tx'] ?? [], [$tx]), -40);
        } elseif (in_array($st, ['refunded', 'charged_back'], true) && in_array($tx, $x['tx'] ?? [], true)) {
            $x['hasta'] = min($x['hasta'] ?? 0, $ahora); $x['estado'] = $st;
        } else {
            return [$p, $st];
        }
        $x['act'] = $ahora; $p[$email] = $x; return [$p, $x['estado']];
    });
    salir(['ok' => true, 'estado' => $res]);
}

/* ---------- crear el pago ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false], 405);
$e = entrada();
if (($e['acao'] ?? '') !== 'checkout') salir(['ok' => false, 'error' => 'acao'], 400);
if (empty($cfg['access_token'])) salir(['ok' => false, 'error' => 'sin_config'], 503);
$plan = (string)($e['plan'] ?? ''); if (!isset($PLANES[$plan])) salir(['ok' => false, 'error' => 'plan'], 400);
$email = cuenta_email($e['token'] ?? ''); if (!$email) salir(['ok' => false, 'error' => 'sesion'], 401);
$precios = array_merge(['mensal' => 4990, 'temporada' => 14990, 'anual' => 29990], $cfg['precios'] ?? []);
$monto = (float)$precios[$plan];
$pref = [
    'items' => [['id' => 'premium-' . $plan, 'title' => 'Alerta Sur Premium — ' . $PLANES[$plan]['nombre'], 'quantity' => 1, 'unit_price' => $monto, 'currency_id' => 'ARS']],
    'payer' => ['email' => $email],
    'external_reference' => $email . '|' . $plan . '|' . bin2hex(random_bytes(4)),
    'back_urls' => ['success' => 'https://avisosul.com/app/?pago=ok', 'pending' => 'https://avisosul.com/app/?pago=ok', 'failure' => 'https://avisosul.com/app/#premium'],
    'auto_return' => 'approved',
    'notification_url' => 'https://avisosul.com/api/mercadopago.php?hook=1',
    'statement_descriptor' => 'ALERTA SUR',
];
[$code, $r] = mp_api('POST', '/checkout/preferences', $cfg, $pref);
if ($code >= 300 || empty($r['init_point'])) salir(['ok' => false, 'error' => 'mp', 'detalle' => $r['message'] ?? null], 502);
salir(['ok' => true, 'url' => $r['init_point']]);
