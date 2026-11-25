<?php
/* Alerta Sur (Argentina) — cobro de Premium con Mercado Pago (Checkout Pro, pago único por período).
   POST {"acao":"checkout","token":<sesion de la cuenta>,"plan":"mensal|temporada|anual"}
        -> crea el cobro y devuelve {"ok":true,"url":<checkout de Mercado Pago>}
        Usa la API de Orders (POST /v1/orders). Si falla, cae a la API de Preferences (se va a discontinuar).
   POST/GET ?hook=1  -> aviso de Mercado Pago (webhook). Acepta los eventos "order" (Orders) y "payment" (Pagos legacy).
        Se consulta el cobro a la API de Mercado Pago (fuente de verdad) y, si está acreditado,
        se activa Premium en datos_privados/premium.json para el email de la cuenta.

   datos_privados/mercadopago.json (lo completa Deik desde /admin/, nunca va al repositorio):
   { "access_token": "APP_USR-...", "webhook_secret": "opcional: clave secreta de Webhooks",
     "precios": { "mensal": 4990, "temporada": 14990, "anual": 29990 }, "api": "orders|preferences (opcional)" }
   Planes: mensal = 31 días, anual = 366 días, temporada = hasta el 31/03. Reembolso o contracargo quitan Premium.
   Un mismo cobro se cuenta una sola vez aunque lleguen avisos de "order" y de "payment": la marca es la referencia externa. */
require __DIR__ . '/_comun.php';

function cfg_mp() { $f = dir_privado() . '/mercadopago.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function mp_api($metodo, $ruta, $cfg, $cuerpo = null) {
    $ch = curl_init(($cfg['api_base'] ?? 'https://api.mercadopago.com') . $ruta);   // api_base solo para pruebas locales
    $h = ['Authorization: Bearer ' . $cfg['access_token'], 'Content-Type: application/json'];
    if ($cuerpo !== null) $h[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($r ?: '', true)];
}
function cuenta_email($tok) {
    $h = hash('sha256', (string)$tok);
    return con_archivo('cuentas.json', function ($d) use ($h) { foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$d, $em]; return [$d, null]; });
}
/* Referencia externa corta (Orders admite hasta 64 caracteres): "as<16 hex>", con el email y el plan guardados aparte.
   Las referencias viejas "email|plan|nonce" se siguen entendiendo. */
function ref_nueva($email, $plan) {
    $ref = 'as' . bin2hex(random_bytes(8));
    con_archivo('mp_refs.json', function ($m) use ($ref, $email, $plan) {
        $m[$ref] = ['e' => $email, 'p' => $plan, 't' => time()];
        if (count($m) > 5000) $m = array_slice($m, -4000, null, true);
        return [$m, true];
    });
    return $ref;
}
function ref_leer($ref) {
    $ref = (string)$ref;
    if (strpos($ref, '|') !== false) { $x = explode('|', $ref); return [strtolower($x[0] ?? ''), $x[1] ?? '']; }
    return con_archivo('mp_refs.json', function ($m) use ($ref) { $x = $m[$ref] ?? null; return [$m, $x ? [$x['e'], $x['p']] : ['', '']]; });
}
$PLANES = ['mensal' => ['dias' => 31, 'nombre' => 'Mensual'], 'anual' => ['dias' => 366, 'nombre' => 'Anual'], 'temporada' => ['hasta' => '03-31', 'nombre' => 'Pase de temporada']];
$cfg = cfg_mp();

/* ---------- webhook ---------- */
if (isset($_GET['hook'])) {
    if (empty($cfg['access_token'])) salir(['ok' => false], 503);
    $raw = file_get_contents('php://input'); $j = json_decode($raw ?: '', true) ?: [];
    $tipo = $j['type'] ?? ($_GET['type'] ?? ($_GET['topic'] ?? ''));
    $id = (string)($j['data']['id'] ?? ($_GET['data_id'] ?? ($_GET['data.id'] ?? ($_GET['id'] ?? ''))));
    // firma (x-signature: ts=...,v1=...) si Deik cargó la clave secreta de Webhooks
    if (!empty($cfg['webhook_secret']) && !empty($_SERVER['HTTP_X_SIGNATURE'])) {
        $p = []; foreach (explode(',', $_SERVER['HTTP_X_SIGNATURE']) as $kv) { [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, ''); $p[$k] = $v; }
        $man = 'id:' . strtolower($id) . ';request-id:' . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . ';ts:' . ($p['ts'] ?? '') . ';';
        if (!hash_equals(hash_hmac('sha256', $man, $cfg['webhook_secret']), $p['v1'] ?? '')) salir(['ok' => false, 'error' => 'firma'], 401);
    }
    if ($tipo === 'order' && preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id)) {
        [$code, $o] = mp_api('GET', '/v1/orders/' . rawurlencode($id), $cfg);
        if ($code === 404) salir(['ok' => true, 'nota' => 'no existe']);   // avisos de prueba o de otra cuenta: no reintentar
        if ($code !== 200 || !$o) salir(['ok' => false, 'error' => 'consulta'], 502);   // Mercado Pago reintenta
        $s = strtolower((string)($o['status'] ?? '')); $sd = strtolower((string)($o['status_detail'] ?? ''));
        if (in_array($s, ['refunded', 'charged_back'], true) || in_array($sd, ['refunded', 'charged_back'], true)) $st = in_array('charged_back', [$s, $sd], true) ? 'charged_back' : 'refunded';
        elseif ($s === 'processed' && in_array($sd, ['accredited', 'processed', ''], true)) $st = 'approved';
        else $st = $s . ($sd ? '/' . $sd : '');
        $refExt = (string)($o['external_reference'] ?? ''); $monto = $o['total_paid_amount'] ?? ($o['total_amount'] ?? null); $cobro = 'ord:' . $id;
    } elseif ($tipo === 'payment' && preg_match('/^\d+$/', $id)) {
        [$code, $pago] = mp_api('GET', '/v1/payments/' . $id, $cfg);
        if ($code === 404) salir(['ok' => true, 'nota' => 'no existe']);
        if ($code !== 200 || !$pago) salir(['ok' => false, 'error' => 'consulta'], 502);
        $st = (string)($pago['status'] ?? ''); $refExt = (string)($pago['external_reference'] ?? ''); $monto = $pago['transaction_amount'] ?? null; $cobro = 'pago:' . $id;
    } else {
        salir(['ok' => true, 'nota' => 'ignorado']);
    }
    [$email, $plan] = ref_leer($refExt); $email = strtolower((string)$email);
    con_archivo('mp_eventos.json', function ($l) use ($cobro, $email, $plan, $st, $monto) {
        $l[] = ['t' => time(), 'pago' => $cobro, 'email' => $email, 'plan' => $plan, 'estado' => $st, 'monto' => $monto];
        return [array_slice($l, -300), true];
    });
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($PLANES[$plan])) salir(['ok' => true, 'nota' => 'sin referencia']);
    $pl = $PLANES[$plan]; $tx = 'mr' . substr(hash('sha256', $refExt), 0, 20);   // una marca por cobro, venga por order o por payment
    $res = con_archivo('premium.json', function ($p) use ($email, $plan, $pl, $st, $tx) {
        $x = $p[$email] ?? ['hasta' => 0, 'tx' => []]; $ahora = time();
        if ($st === 'approved') {
            if (in_array($tx, $x['tx'] ?? [], true)) return [$p, 'ya contado'];
            if (!empty($pl['hasta'])) { [$m, $d] = array_map('intval', explode('-', $pl['hasta'])); $y = (int)date('Y'); $lim = mktime(23, 59, 59, $m, $d, $y); if ($lim < $ahora) $lim = mktime(23, 59, 59, $m, $d, $y + 1); $x['hasta'] = max($x['hasta'] ?? 0, $lim); }
            else $x['hasta'] = max($x['hasta'] ?? 0, $ahora) + ($pl['dias'] + 1) * 86400;
            $x['plan'] = $plan; $x['estado'] = 'activa'; $x['fuente'] = 'mercadopago';
            $x['tx'] = array_slice(array_merge($x['tx'] ?? [], [$tx]), -40);
        } elseif (in_array($st, ['refunded', 'charged_back'], true) && in_array($tx, $x['tx'] ?? [], true)) {
            if (($x['estado'] ?? '') === $st) return [$p, $st];
            $x['hasta'] = min($x['hasta'] ?? 0, $ahora); $x['estado'] = $st;
        } else {
            return [$p, $st];
        }
        $x['act'] = $ahora; $p[$email] = $x; return [$p, $x['estado']];
    });
    salir(['ok' => true, 'estado' => $res]);
}

/* ---------- crear el cobro ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false], 405);
$e = entrada();
if (($e['acao'] ?? '') !== 'checkout') salir(['ok' => false, 'error' => 'acao'], 400);
if (empty($cfg['access_token'])) salir(['ok' => false, 'error' => 'sin_config'], 503);
$plan = (string)($e['plan'] ?? ''); if (!isset($PLANES[$plan])) salir(['ok' => false, 'error' => 'plan'], 400);
$email = cuenta_email($e['token'] ?? ''); if (!$email) salir(['ok' => false, 'error' => 'sesion'], 401);
$precios = array_merge(['mensal' => 4990, 'temporada' => 14990, 'anual' => 29990], $cfg['precios'] ?? []);
$monto = (float)$precios[$plan];
$finPromo = strtotime(($cfg['promo_hasta'] ?? '2026-12-25') . ' 23:59:59 America/Argentina/Cordoba');
if (time() > $finPromo) $monto = (float)(($cfg['precios_regulares'] ?? [])[$plan] ?? $monto * 2);   // termina la promo del 50 %
$titulo = 'Alerta Sur Premium — ' . $PLANES[$plan]['nombre'];
$ref = ref_nueva($email, $plan);
$OK = 'https://avisosul.com/app/?pago=ok'; $MAL = 'https://avisosul.com/app/#premium';
$url = null; $det = null;

if (($cfg['api'] ?? 'orders') !== 'preferences') {
    $m = number_format($monto, 2, '.', '');
    $orden = [
        'type' => 'online', 'processing_mode' => 'manual', 'total_amount' => $m, 'external_reference' => $ref,
        'description' => $titulo, 'expiration_time' => 'P1D',
        'payer' => ['email' => $email],
        'items' => [['external_code' => 'premium-' . $plan, 'title' => $titulo, 'quantity' => 1, 'unit_price' => $m]],
        'config' => ['statement_descriptor' => 'ALERTA SUR',
                     'online' => ['success_url' => $OK, 'pending_url' => $OK, 'failure_url' => $MAL, 'auto_return' => 'approved']],
    ];
    [$code, $r] = mp_api('POST', '/v1/orders', $cfg, $orden);
    if ($code < 300 && !empty($r['checkout_url'])) $url = $r['checkout_url'];
    else $det = $r['message'] ?? ($r['errors'][0]['message'] ?? ('orders ' . $code));
}
if (!$url) {   // respaldo: API de Preferences
    $pref = [
        'items' => [['id' => 'premium-' . $plan, 'title' => $titulo, 'quantity' => 1, 'unit_price' => $monto, 'currency_id' => 'ARS']],
        'payer' => ['email' => $email], 'external_reference' => $ref,
        'back_urls' => ['success' => $OK, 'pending' => $OK, 'failure' => $MAL], 'auto_return' => 'approved',
        'notification_url' => 'https://avisosul.com/api/mercadopago.php?hook=1', 'statement_descriptor' => 'ALERTA SUR',
    ];
    [$code, $r] = mp_api('POST', '/checkout/preferences', $cfg, $pref);
    if ($code < 300 && !empty($r['init_point'])) $url = $r['init_point'];
    else $det = $r['message'] ?? $det;
}
if (!$url) salir(['ok' => false, 'error' => 'mp', 'detalle' => $det], 502);
salir(['ok' => true, 'url' => $url]);
