<?php
/* Aviso Sul — API de conversiones de Meta (aviso desde el servidor).
   La web manda acá los mismos eventos que el Píxel (con el mismo event_id), así Meta los recibe aunque el navegador
   bloquee el Píxel, y no los cuenta doble. Solo se llama si la persona NO rechazó el aviso de cookies.
   POST {"ev": CompleteRegistration|InitiateCheckout|Purchase|ViewContent, "eid": "...", "url": "...", "fbp": "...", "fbc": "...",
         "em": "email (opcional, se manda con hash)", "pais": "ar|br", "value": 0, "currency": "ARS|BRL", "content_name": "..."}
   El token lo pega Deik en /admin/ (datos_privados/meta.json → capi_token). Sin token no hace nada. */
require_once __DIR__ . '/_comun.php';

const CAPI_EVENTOS = ['CompleteRegistration', 'InitiateCheckout', 'Purchase', 'ViewContent'];

function capi_cfg() { $f = dir_privado() . '/meta.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function capi_hash($v) { $v = strtolower(trim((string)$v)); return $v === '' ? null : hash('sha256', $v); }

/* Manda un evento a Meta. $test = código de prueba del Administrador de eventos (opcional). Devuelve [ok, respuesta]. */
function capi_enviar(array $ev, $test = null) {
    $m = capi_cfg(); $px = $m['pixel'] ?? ''; $tk = $m['capi_token'] ?? '';
    if (!$tk) {   // sin token propio: usa el de los anuncios automáticos (mismo portfolio, ya tiene acceso al Píxel)
        $f = dir_privado() . '/meta_ads.json'; $a = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        $tk = $a['token'] ?? '';
    }
    if (!$px || !$tk) return [false, 'sin_configurar'];
    $body = ['data' => json_encode([$ev], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'access_token' => $tk];
    if ($test ?: ($m['capi_test'] ?? '')) $body['test_event_code'] = $test ?: $m['capi_test'];
    $ch = curl_init('https://graph.facebook.com/v21.0/' . $px . '/events');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true) ?: [];
    $ok = $code === 200 && !empty($j['events_received']);
    $det = $ok ? ('recibido' . (!empty($body['test_event_code']) ? ' (prueba)' : '')) : ($j['error']['message'] ?? ('http ' . $code));
    con_archivo('capi_log.json', function ($d) use ($ev, $ok, $det) {
        $d[] = ['t' => gmdate('c'), 'ev' => $ev['event_name'], 'ok' => $ok, 'det' => mb_substr($det, 0, 160)];
        return [array_slice($d, -20), true];
    });
    return [$ok, $det];
}

function capi_armar($ev, $eid, $url, $extra = []) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $ud = array_filter(['client_ip_address' => $ip, 'client_user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400),
        'fbp' => $extra['fbp'] ?? null, 'fbc' => $extra['fbc'] ?? null,
        'em' => !empty($extra['em']) ? [capi_hash($extra['em'])] : null,
        'country' => !empty($extra['pais']) ? [capi_hash($extra['pais'])] : null]);
    $cd = array_filter(['value' => isset($extra['value']) && is_numeric($extra['value']) ? (float)$extra['value'] : null,
        'currency' => in_array($extra['currency'] ?? '', ['ARS', 'BRL', 'USD'], true) ? $extra['currency'] : null,
        'content_name' => isset($extra['content_name']) ? mb_substr((string)$extra['content_name'], 0, 60) : null], fn($x) => $x !== null);
    $e = ['event_name' => $ev, 'event_time' => time(), 'event_id' => $eid, 'action_source' => 'website',
        'event_source_url' => $url, 'user_data' => $ud];
    if ($cd) $e['custom_data'] = $cd;
    return $e;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'capi.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $e = entrada(); $ev = (string)($e['ev'] ?? '');
    if (!in_array($ev, CAPI_EVENTOS, true)) salir(['ok' => false], 400);
    $eid = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($e['eid'] ?? ''));
    if (strlen($eid) < 8 || strlen($eid) > 64) salir(['ok' => false], 400);
    $url = (string)($e['url'] ?? '');
    if (!preg_match('#^https://(www\.)?avisosul\.com(/|$)#', $url)) $url = 'https://avisosul.com/';
    $em = (string)($e['em'] ?? ''); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) $em = '';
    $fbp = preg_match('/^fb\.\d\.\d+\.\d+$/', (string)($e['fbp'] ?? '')) ? $e['fbp'] : null;
    $fbc = preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_-]+$/', (string)($e['fbc'] ?? '')) ? $e['fbc'] : null;
    [$ok] = capi_enviar(capi_armar($ev, $eid, $url, ['fbp' => $fbp, 'fbc' => $fbc, 'em' => $em, 'pais' => ($e['pais'] ?? '') === 'ar' ? 'ar' : 'br',
        'value' => $e['value'] ?? null, 'currency' => $e['currency'] ?? null, 'content_name' => $e['content_name'] ?? null]));
    salir(['ok' => $ok]);
}
