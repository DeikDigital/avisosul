<?php
/* Aviso Sul — webhook de la API de WhatsApp (Meta).
   GET: verificacion de Meta (hub.challenge). POST: mensajes entrantes.
   "ATIVAR 1234" activa la inscripcion; "PARAR", "SAIR" o "STOP" la cancela. Responde con un mensaje de servicio (gratis). */
require __DIR__ . '/_comun.php';
$f = dir_privado() . '/whatsapp.json';
$cfg = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && !empty($cfg['verify_token']) && hash_equals($cfg['verify_token'], (string)($_GET['hub_verify_token'] ?? ''))) {
        echo $_GET['hub_challenge'] ?? ''; exit;
    }
    http_response_code(403); echo 'no'; exit;
}
$raw = file_get_contents('php://input') ?: '';
if (!empty($cfg['app_secret'])) {   // firma de Meta: descarta pedidos que no vienen de WhatsApp
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $cfg['app_secret']), $sig)) salir(['ok' => false], 403);
}
$j = json_decode($raw, true) ?: [];

function responder($cfg, $para, $texto) {
    if (empty($cfg['token']) || empty($cfg['phone_number_id'])) return;
    $ch = curl_init('https://graph.facebook.com/v21.0/' . $cfg['phone_number_id'] . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $para, 'type' => 'text', 'text' => ['body' => $texto]])]);
    curl_exec($ch); curl_close($ch);
}
foreach (($j['entry'] ?? []) as $en) foreach (($en['changes'] ?? []) as $ch) foreach (($ch['value']['messages'] ?? []) as $m) {
    $de = preg_replace('/\D+/', '', (string)($m['from'] ?? ''));
    $txt = strtoupper(trim((string)($m['text']['body'] ?? '')));
    if (!$de || $txt === '') continue;
    $res = con_archivo('whatsapp_inscritos.json', function ($d) use ($de, $txt) {
        // WhatsApp a veces informa los celulares de Brasil sin el 9 inicial: se prueba con y sin el 9
        $claves = [$de];
        if (strlen($de) === 12 && substr($de, 0, 2) === '55') $claves[] = substr($de, 0, 4) . '9' . substr($de, 4);
        if (strlen($de) === 13 && substr($de, 0, 2) === '55') $claves[] = substr($de, 0, 4) . substr($de, 5);
        $k = null; foreach ($claves as $c) if (isset($d[$c])) { $k = $c; break; }
        if (preg_match('/\b(PARAR|SAIR|STOP|CANCELAR)\b/', $txt)) { if ($k) unset($d[$k]); return [$d, $k ? 'parou' : 'nada']; }
        if (preg_match('/ATIVAR\s*(\d{4})/', $txt, $mm)) {
            if ($k && hash_equals($d[$k]['codigo'], $mm[1])) { $d[$k]['estado'] = 'ativo'; $d[$k]['ativado'] = time(); return [$d, 'ativo:' . $d[$k]['lang']]; }
            return [$d, 'codigo'];
        }
        return [$d, 'nada'];
    });
    $es = substr($res, -2) === 'es';
    if (strpos($res, 'ativo') === 0) responder($cfg, $de, $es ? "✅ Listo. Vas a recibir aquí los avisos oficiales para tu zona. Para dejar de recibirlos, escribí PARAR." : "✅ Pronto! Você vai receber aqui os avisos oficiais da sua região. Para parar, escreva PARAR.");
    elseif ($res === 'parou') responder($cfg, $de, "Feito: você não vai mais receber avisos do Aviso Sul. / Listo: no vas a recibir más avisos.");
    elseif ($res === 'codigo') responder($cfg, $de, "Código não encontrado. Abra o Aviso Sul, toque em Ajustes > WhatsApp e tente de novo.");
}
salir(['ok' => true]);
