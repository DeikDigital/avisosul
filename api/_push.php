<?php
/* Aviso Sul — Web Push sin librerías externas (RFC 8291 aes128gcm + VAPID RFC 8292), con OpenSSL de PHP.
   Las claves VAPID se generan en el propio servidor la primera vez y quedan en datos_privados/vapid.json. */

function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { $s = strtr((string)$s, '-_', '+/'); return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/* Clave pública P-256 cruda (65 bytes, 0x04|x|y) -> PEM */
function p256_pem($raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function p256_nueva() {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    openssl_pkey_export($k, $pem);
    return [$k, $pub, $pem];
}
function vapid() {
    $f = dir_privado() . '/vapid.json';
    if (file_exists($f)) { $v = json_decode(file_get_contents($f), true); if (!empty($v['priv']) && !empty($v['pub'])) return $v; }
    [, $pub, $pem] = p256_nueva();
    $v = ['pub' => b64u($pub), 'priv' => $pem, 'creada' => time()];
    file_put_contents($f, json_encode($v)); @chmod($f, 0600);
    return $v;
}
/* Firma DER ECDSA -> r|s de 64 bytes (formato JWS ES256) */
function der_a_jose($der) {
    $p = 2; if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
    $lr = ord($der[$p + 1]); $r = substr($der, $p + 2, $lr); $p += 2 + $lr;
    $ls = ord($der[$p + 1]); $s = substr($der, $p + 2, $ls);
    $f = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
    return $f($r) . $f($s);
}
function vapid_cabecera($endpoint) {
    $v = vapid(); $u = parse_url($endpoint);
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:contato@avisosul.com']));
    openssl_sign("$h.$c", $sig, openssl_pkey_get_private($v['priv']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$c." . b64u(der_a_jose($sig)) . ', k=' . $v['pub'];
}
/* Cifra el contenido para una suscripción (aes128gcm, un solo registro) */
function push_cifrar($texto, $p256dh, $auth) {
    $ua = b64u_dec($p256dh); $sec = b64u_dec($auth);
    if (strlen($ua) !== 65 || strlen($sec) < 16) return null;
    [$eph, $as] = p256_nueva();
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_pem($ua)), $eph, 32);
    if ($ecdh === false) return null;
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua . $as, $sec);
    $sal = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $non = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $ct = openssl_encrypt($texto . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $non, $tag);
    return $sal . pack('N', 4096) . chr(65) . $as . $ct . $tag;
}
/* Envía; devuelve el código HTTP (201 ok; 404/410 = suscripción muerta) */
function push_enviar($sub, array $datos, $ttl = 21600) {
    $cuerpo = push_cifrar(json_encode($datos, JSON_UNESCAPED_UNICODE), $sub['keys']['p256dh'] ?? '', $sub['keys']['auth'] ?? '');
    if ($cuerpo === null) return 0;
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: ' . vapid_cabecera($sub['endpoint']), 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: ' . $ttl, 'Urgency: high']]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}
