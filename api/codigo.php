<?php
/* Códigos de descuento o de prueba de Premium.
   POST {"codigo":"GRATIS24"} -> {"ok":true,"horas":24} o {"ok":false}
   Los códigos se pueden cambiar en datos_privados/codigos.json ({"CODIGO":{"horas":24}}); los de prueba y VIP se guardan solo como hash. */
require __DIR__ . '/_comun.php';
$e = entrada(); $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($e['codigo'] ?? '')));
$f = dir_privado() . '/codigos.json';
$cod = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
/* códigos privados: solo se guarda el hash, así el código no queda escrito en el repositorio */
$PRIV = ['c96ba6b46d7e46fac27716794b9b875326009cbfee6fdf7e6afaaf0a78046c8d' => ['horas' => 24], '696439e894d1fc5776441b67a6b72cc9f0d53ed08ef78ca9167caa499140768a' => ['horas' => 87600]];
$hc = hash('sha256', $c);
foreach ($PRIV as $hh => $v) if (hash_equals($hh, $hc)) { $cod[$c] = $v; break; }
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
