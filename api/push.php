<?php
/* Aviso Sul / Alerta Sur — notificaciones push (llegan con la app o la web cerradas).
   GET  ?clave=1                                   -> {"key": clave pública VAPID}
   POST {"acao":"suscribir","sub":{endpoint,keys},"slugs":[…],"lang","pais","token"?}
   POST {"acao":"baja","endpoint"}
   CLI  php api/push.php enviar   (lo corre agentes.sh cada 10 min): manda cada aviso oficial nuevo de las ciudades
        que sigue cada suscripción, una sola vez por aviso. A quien no es Premium, como mucho una vez por día, el
        aviso suma una invitación suave a Premium ("te llega también por WhatsApp").
   Las suscripciones se guardan en datos_privados/push.json. */
require __DIR__ . '/_comun.php';
require __DIR__ . '/_push.php';

function raiz_datos() { $r = dirname(__DIR__); return is_dir("$r/vivo/datos") ? "$r/vivo/datos" : "$r/datos"; }
function email_de_token($tok) {
    if (!$tok) return [null, false];
    $h = hash('sha256', (string)$tok);
    foreach (['cuentas.json' => false, 'cuentas_nv.json' => true] as $f => $nv) {
        $p = dir_privado() . "/$f"; if (!file_exists($p)) continue;
        $d = json_decode(file_get_contents($p), true) ?: [];
        if ($nv) { if (isset($d[$h])) return [$d[$h]['email'], true]; }
        else foreach ($d as $em => $c) if (isset($c['ses'][$h])) return [$em, false];
    }
    return [null, false];
}

/* ---------- envío (cron) ---------- */
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'enviar') { echo "uso: php push.php enviar [--prueba]\n"; exit(1); }
    $prueba = in_array('--prueba', $argv, true);
    $ciud = []; foreach ((json_decode(@file_get_contents(dirname(__DIR__) . '/ciudades.json'), true) ?: []) as $c) $ciud[$c['slug']] = $c;
    $prem = json_decode(@file_get_contents(dir_privado() . '/premium.json') ?: '{}', true) ?: [];
    $hoy = (new DateTime('now', new DateTimeZone('America/Argentina/Cordoba')))->format('Y-m-d');
    $cache = []; $env = 0; $mal = 0; $borr = 0; $ahora = time();
    $avisos = function ($slug) use (&$cache, $ahora) {
        if (!isset($cache[$slug])) {
            $d = json_decode(@file_get_contents(raiz_datos() . "/$slug.json") ?: '{}', true) ?: [];
            $cache[$slug] = array_values(array_filter($d['avisos'] ?? [], fn($a) => strtotime($a['hasta'] ?? '') > $ahora && (strtotime($a['desde'] ?? '') ?: 0) <= $ahora + 3 * 3600));
        }
        return $cache[$slug];
    };
    con_archivo('push.json', function ($d) use ($avisos, $ciud, $prem, $hoy, $prueba, $ahora, &$env, &$mal, &$borr) {
        foreach ($d as $id => &$s) {
            $es = ($s['lang'] ?? 'pt') === 'es'; $n = 0;
            $esPrem = !empty($s['email']) && empty($s['nv']) && (int)($prem[$s['email']]['hasta'] ?? 0) > $ahora;
            foreach (array_slice($s['slugs'] ?? [], 0, 11) as $slug) foreach ($avisos($slug) as $a) {
                $k = substr(sha1($slug . '|' . ($a['url'] ?? '') . '|' . ($a['desde'] ?? '')), 0, 16);
                if (isset($s['env'][$k]) || $n >= 3) continue;
                $nom = $ciud[$slug]['nombre'] ?? $slug; $t = $es ? ($a['titulo_es'] ?? '') : ($a['titulo_pt'] ?? '');
                $hs = fn($x) => ($x = strtotime($x ?? '')) ? gmdate('d/m H:i', $x - 3 * 3600) : '';
                $cuerpo = ($es ? 'Hasta ' : 'Até ') . $hs($a['hasta'] ?? null) . '. ' . ($es ? 'Tocá para ver el mapa y qué hacer.' : 'Toque para ver o mapa e o que fazer.');
                $msg = ['title' => '⚠️ ' . $t . ' · ' . $nom, 'body' => $cuerpo, 'url' => '/' . $slug . '/', 'tag' => 'aviso-' . $k];
                if (!$esPrem && ($s['upsell'] ?? '') !== $hoy) {   // invitación a Premium: como mucho una vez por día
                    $msg['body'] .= $es ? ' Con Premium te llega también por WhatsApp.' : ' Com o Premium chega também pelo WhatsApp.';
                    $msg['premium'] = '/app/#premium'; $msg['accion'] = $es ? 'Ver Premium' : 'Ver Premium';
                    $s['upsell'] = $hoy;
                }
                $code = $prueba ? 201 : push_enviar($s['sub'], $msg);
                if ($prueba) echo "prueba → $id: {$msg['title']} | {$msg['body']}\n";
                if ($code === 404 || $code === 410) { $s['_borrar'] = 1; $borr++; break 2; }
                if ($code >= 200 && $code < 300) { $s['env'][$k] = $ahora; $env++; $n++; } else $mal++;
            }
            foreach (($s['env'] ?? []) as $k => $t) if ($t < $ahora - 5 * 86400) unset($s['env'][$k]);
        }
        unset($s);
        foreach ($d as $id => $s) if (!empty($s['_borrar']) || ($s['t'] ?? 0) < $ahora - 120 * 86400) unset($d[$id]);
        return [$d, true];
    });
    echo "push: $env enviados, $mal con error, $borr suscripciones vencidas\n";
    exit(0);
}

/* ---------- API ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['clave'])) salir(['ok' => true, 'key' => vapid()['pub']]);
    salir(['ok' => false], 400);
}
$e = entrada(); $acao = $e['acao'] ?? '';
if ($acao === 'suscribir') {
    $sub = $e['sub'] ?? null; $ep = (string)($sub['endpoint'] ?? '');
    if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $ep) || strlen($ep) > 1000 || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) salir(['ok' => false, 'error' => 'sub'], 400);
    $slugs = array_values(array_unique(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$x)), (array)($e['slugs'] ?? [])))));
    $slugs = array_slice($slugs, 0, 11); if (!$slugs) salir(['ok' => false, 'error' => 'slugs'], 400);
    [$em, $nv] = email_de_token($e['token'] ?? null);
    $id = sha1($ep);
    $r = con_archivo('push.json', function ($d) use ($id, $sub, $ep, $slugs, $e, $em, $nv) {
        if (!isset($d[$id]) && count($d) > 200000) return [$d, false];
        $x = $d[$id] ?? ['env' => (object)[]];
        $x['sub'] = ['endpoint' => $ep, 'keys' => ['p256dh' => (string)$sub['keys']['p256dh'], 'auth' => (string)$sub['keys']['auth']]];
        $x['slugs'] = $slugs; $x['lang'] = ($e['lang'] ?? '') === 'es' ? 'es' : 'pt'; $x['pais'] = ($e['pais'] ?? '') === 'ar' ? 'ar' : 'br';
        $x['email'] = $em; $x['nv'] = $nv; $x['t'] = time();
        $d[$id] = $x; return [$d, true];
    });
    salir(['ok' => (bool)$r]);
}
if ($acao === 'prueba') {   // solo el administrador: manda una notificación de prueba a sus propios dispositivos
    [$em, $nv] = email_de_token($e['token'] ?? null);
    if (!$em || $nv || $em !== 'deikdigital@gmail.com') salir(['ok' => false, 'error' => 'sesion'], 401);
    $f = dir_privado() . '/push.json'; $d = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $n = 0; $r = []; $tipo = [];
    foreach ($d as $s) if (($s['email'] ?? '') === $em) { $c = push_enviar($s['sub'], ['title' => '⚠️ Prueba de aviso · ' . ($s['slugs'][0] ?? ''), 'body' => 'Si ves esto con la app cerrada, las notificaciones funcionan. Con Premium te llega también por WhatsApp.', 'url' => '/app/', 'tag' => 'prueba', 'premium' => '/app/#premium', 'accion' => 'Ver Premium'], 600); $r[] = $c; $h = parse_url($s['sub']['endpoint'], PHP_URL_HOST); $tipo[] = strpos($h, 'apple') !== false ? 'iPhone/Safari' : (strpos($h, 'mozilla') !== false ? 'Firefox' : (strpos($h, 'windows') !== false || strpos($h, 'notify') !== false ? 'Edge' : 'Chrome (Android o PC)')); if ($c >= 200 && $c < 300) $n++; }
    salir(['ok' => true, 'dispositivos' => count($r), 'enviadas' => $n, 'codigos' => $r, 'tipos' => $tipo]);
}
if ($acao === 'baja') {
    $id = sha1((string)($e['endpoint'] ?? ''));
    con_archivo('push.json', function ($d) use ($id) { unset($d[$id]); return [$d, true]; });
    salir(['ok' => true]);
}
salir(['ok' => false, 'error' => 'acao'], 400);
