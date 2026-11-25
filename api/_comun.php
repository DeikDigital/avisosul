<?php
/* Aviso Sul — utilidades comunes de la API.
   Los datos se guardan fuera de public_html (domains/avisosul.com/datos_privados), nunca en la carpeta publica. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* Client ID público del proyecto "Aviso Sul" en Google Cloud (se puede reemplazar desde /admin/). */
const GOOGLE_CLIENT_ID = '483433790554-bqvpjs90f02q8tnakbg6uj77hf443m1r.apps.googleusercontent.com';
function dir_privado() {
    $d = dirname(__DIR__, 2) . '/datos_privados';
    if (getenv('AVISOSUL_DATOS')) $d = getenv('AVISOSUL_DATOS');   // para pruebas locales
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
/* Lee y escribe un JSON con bloqueo, para que dos pedidos simultaneos no se pisen. */
function con_archivo($nombre, $fn) {
    $ruta = dir_privado() . '/' . $nombre;
    $h = fopen($ruta, 'c+');
    if (!$h) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'almacenamiento']); exit; }
    flock($h, LOCK_EX);
    $txt = stream_get_contents($h);
    $datos = $txt ? json_decode($txt, true) : null;
    if (!is_array($datos)) $datos = [];
    [$datos, $respuesta] = $fn($datos);
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($datos, JSON_UNESCAPED_UNICODE));
    fflush($h); flock($h, LOCK_UN); fclose($h);
    return $respuesta;
}
/* Identificador anonimo del visitante: hash de la IP con una sal propia que cambia cada dia. No se guarda la IP. */
function visitante() {
    $sal_f = dir_privado() . '/sal.txt';
    if (!file_exists($sal_f)) file_put_contents($sal_f, bin2hex(random_bytes(16)));
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return substr(hash('sha256', trim(file_get_contents($sal_f)) . '|' . $ip . '|' . gmdate('Y-m-d')), 0, 16);
}
function entrada() {
    $j = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($j) ? $j : [];
}
function salir($arr, $codigo = 200) { http_response_code($codigo); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
