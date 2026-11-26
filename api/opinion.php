<?php
/* Opiniones de los testers: POST JSON con las respuestas del formulario de /opinion/.
   Se guardan en opiniones.json (fuera de public_html) y llega un mail a contato@avisosul.com. Admin: GET ?token= (sesión de admin). */
require __DIR__ . '/_comun.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $h = hash('sha256', (string)($_GET['token'] ?? ''));
    $em = con_archivo('cuentas.json', function ($d) use ($h) { foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$d, $em]; return [$d, null]; });
    if ($em !== 'deikdigital@gmail.com') salir(['ok' => false], 403);
    salir(['ok' => true, 'opiniones' => con_archivo('opiniones.json', fn($d) => [$d, $d])]);
}
$e = entrada(); $vis = visitante();
$campos = ['nombre' => 80, 'instalo' => 20, 'claridad' => 2, 'notif' => 300, 'fallo' => 1500, 'sirvio' => 800, 'pagaria' => 800, 'recomienda' => 400, 'dispositivo' => 120];
$r = ['t' => time(), 'v' => $vis];
foreach ($campos as $k => $max) $r[$k] = trim(mb_substr(strip_tags((string)($e[$k] ?? '')), 0, $max));
if (implode('', array_slice($r, 2)) === '') salir(['ok' => false, 'error' => 'vacio'], 400);
$ok = con_archivo('opiniones.json', function ($d) use ($r, $vis) {
    $n = 0; foreach ($d as $x) if (($x['v'] ?? '') === $vis && $x['t'] > time() - 3600) $n++;
    if ($n >= 5) return [$d, false];
    $d[] = $r; return [$d, true];
});
if (!$ok) salir(['ok' => false, 'error' => 'limite'], 429);
$txt = ''; foreach ($campos as $k => $_) $txt .= ucfirst($k) . ': ' . $r[$k] . "\n";
@enviar_mail('contato@avisosul.com', 'Opinión de tester: ' . ($r['nombre'] ?: 'sin nombre'), $txt);
salir(['ok' => true]);
