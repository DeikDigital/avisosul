<?php
/* Aviso Sul — embudo propio (sin cookies ni datos personales): cuenta por día y país cuántos dispositivos llegan a cada paso.
   POST {"e": visita|registro|premium_ver|pago_inicio|compra|push_ok, "pais": ar|br}   (la app manda cada paso una vez por día por dispositivo)
   GET  ?conf=1  -> {"pixel": ID del Píxel de Meta o null}   (público) */
require __DIR__ . '/_comun.php';
const PASOS = ['visita', 'registro', 'premium_ver', 'pago_inicio', 'compra', 'push_ok'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $f = dir_privado() . '/meta.json'; $m = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    header('Cache-Control: public, max-age=300');
    salir(['ok' => true, 'pixel' => $m['pixel'] ?? null]);
}
$e = entrada(); $ev = (string)($e['e'] ?? ''); $pa = ($e['pais'] ?? '') === 'ar' ? 'ar' : 'br';
if (!in_array($ev, PASOS, true)) salir(['ok' => false], 400);
$dia = (new DateTime('now', new DateTimeZone('America/Argentina/Cordoba')))->format('Y-m-d');
con_archivo('embudo.json', function ($d) use ($dia, $ev, $pa) {
    $d[$dia][$ev][$pa] = ($d[$dia][$ev][$pa] ?? 0) + 1;
    if (count($d) > 120) { ksort($d); $d = array_slice($d, -120, null, true); }
    return [$d, true];
});
salir(['ok' => true]);
