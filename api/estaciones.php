<?php
/* Modo Car: busca estaciones de servicio o cargadores en OpenStreetMap (Overpass) desde el servidor.
   Prueba varios servidores de Overpass y guarda la respuesta 6 horas, asi el celular no depende de uno solo. */
require __DIR__ . '/_comun.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false], 405);
$q = (string)($_POST['data'] ?? '');
if (strlen($q) < 20 || strlen($q) > 60000 || !preg_match('/^\[out:json\]/', $q) || !preg_match('/"amenity"="(fuel|charging_station)"/', $q)) salir(['ok' => false, 'error' => 'consulta'], 400);
$cdir = dir_privado() . '/cache_estaciones'; if (!is_dir($cdir)) @mkdir($cdir, 0750, true);
$cf = $cdir . '/' . md5($q) . '.json';
if (is_file($cf) && time() - filemtime($cf) < 6 * 3600) { echo file_get_contents($cf); exit; }
if (mt_rand(1, 50) === 1) foreach (glob($cdir . '/*.json') ?: [] as $f) if (time() - filemtime($f) > 86400) @unlink($f);
$servidores = ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter', 'https://overpass.private.coffee/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter'];
foreach ($servidores as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'data=' . rawurlencode($q), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'AvisoSul/1.0 (+https://avisosul.com)']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 200 && $r && ($j = json_decode($r, true)) && isset($j['elements'])) {
        $out = json_encode(['ok' => true, 'elements' => $j['elements']], JSON_UNESCAPED_UNICODE);
        @file_put_contents($cf, $out); echo $out; exit;
    }
}
salir(['ok' => false, 'error' => 'overpass'], 502);
