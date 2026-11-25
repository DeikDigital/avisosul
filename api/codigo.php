<?php
/* Códigos de descuento o de prueba de Premium.
   POST {"codigo":"GRATIS24"} -> {"ok":true,"horas":24} o {"ok":false}
   Los códigos se pueden cambiar en datos_privados/codigos.json ({"CODIGO":{"horas":24}}); si no existe, vale GRATIS24 = 24 h. */
require __DIR__ . '/_comun.php';
$e = entrada(); $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($e['codigo'] ?? '')));
$f = dir_privado() . '/codigos.json';
$cod = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : ['GRATIS24' => ['horas' => 24]];
if ($c === '' || !isset($cod[$c])) salir(['ok' => false, 'error' => 'codigo']);
$x = $cod[$c];
if (!empty($x['hasta']) && time() > strtotime($x['hasta'])) salir(['ok' => false, 'error' => 'vencido']);
$vis = visitante();
$r = con_archivo('codigos_usados.json', function ($d) use ($c, $vis) {
    $k = $c . '|' . $vis;
    if (isset($d[$k])) return [$d, false];
    $d[$k] = time(); return [$d, true];
});
salir(['ok' => true, 'horas' => (int)($x['horas'] ?? 24), 'descuento' => $x['descuento'] ?? null, 'repetido' => !$r]);
