<?php
/* Aviso Sul / Alerta Sur — anuncios de Meta que se prenden solo donde hay temporal.
   Lo corre agentes.sh cada 10 minutos:  php ../api/anuncios_auto.php
   Lee los avisos oficiales que ya bajan los agentes (vivo/datos/<ciudad>.json) y, por país:
     - si alguna ciudad tiene un aviso de granizo, lluvia, viento o tormenta del nivel pedido (vigente o que empieza
       en las próximas horas), pone el conjunto de anuncios de ese país con esas ciudades (radio en km) y ACTIVO;
     - si no hay ninguno, o pasó la fecha de corte, lo PAUSA.
   Solo llama a Meta cuando cambia algo. En modo prueba no toca Meta: solo anota lo que haría.
   Configuración (datos_privados/meta_ads.json, se carga desde /admin/):
     token, api ("v23.0"), prueba (true), hasta ("2026-12-14"), radio_km (40), horas_antes (6),
     paises: {br: {adset, niveles:[naranja,rojo]}, ar: {adset, niveles:[amarillo,naranja,rojo]}}
   El token nunca se muestra ni se escribe en el registro. */
require_once __DIR__ . '/_comun.php';

const EVENTOS = '/(granizo|tempestade|tormenta|chuva|lluvia|precipita|vendaval|viento|vento|rajada|raio|rayo|el[eé]ctric|tornado)/iu';

function ma_cfg() {
    $f = dir_privado() . '/meta_ads.json';
    $c = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    return array_replace_recursive([
        'token' => '', 'api' => 'v23.0', 'prueba' => true, 'hasta' => '2026-12-14', 'radio_km' => 40, 'horas_antes' => 6,
        'paises' => ['br' => ['adset' => '', 'niveles' => ['naranja', 'rojo']],
                     'ar' => ['adset' => '', 'niveles' => ['amarillo', 'naranja', 'rojo']]],
    ], $c);
}
function ma_guardar($c) { $f = dir_privado() . '/meta_ads.json'; file_put_contents($f, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); @chmod($f, 0600); }
function ma_log($linea) {
    con_archivo('meta_ads_log.json', function ($d) use ($linea) {
        $d[] = ['t' => date('c'), 'm' => $linea];
        return [array_slice($d, -200), true];
    });
}

/* Ciudades de cada país con un aviso que corresponde, con el motivo. */
function ciudades_con_temporal($cfg) {
    $raiz = dirname(__DIR__);
    $dd = is_dir("$raiz/vivo/datos") ? "$raiz/vivo/datos" : "$raiz/datos";
    $ciudades = json_decode(@file_get_contents("$raiz/ciudades.json") ?: '[]', true) ?: [];
    $out = ['br' => [], 'ar' => []];
    $ahora = time(); $antes = $ahora + 3600 * (float)$cfg['horas_antes'];
    foreach ($ciudades as $c) {
        $p = $c['pais'] ?? ''; if (!isset($out[$p])) continue;
        $niv = $cfg['paises'][$p]['niveles'] ?? ['naranja', 'rojo'];
        $a = json_decode(@file_get_contents("$dd/{$c['slug']}.json") ?: '', true) ?: [];
        foreach (($a['avisos'] ?? []) as $x) {
            $h = strtotime($x['hasta'] ?? '') ?: 0; $d = strtotime($x['desde'] ?? '') ?: 0;
            if ($h <= $ahora || $d > $antes) continue;                          // vencido o todavía lejos
            if (!in_array($x['nivel'] ?? '', $niv, true)) continue;
            if (!preg_match(EVENTOS, ($x['titulo_pt'] ?? '') . ' ' . ($x['titulo_es'] ?? ''))) continue;   // ni calor, ni helada, ni humedad
            $out[$p][$c['slug']] = ['slug' => $c['slug'], 'nombre' => $c['nombre'], 'lat' => $c['lat'], 'lon' => $c['lon'],
                                    'aviso' => $x['titulo_es'] ?? $x['titulo_pt'] ?? '', 'hasta' => $x['hasta'] ?? ''];
            break;
        }
    }
    return $out;
}

function graph($cfg, $metodo, $ruta, $params = []) {
    $url = 'https://graph.facebook.com/' . $cfg['api'] . '/' . ltrim($ruta, '/');
    $params['access_token'] = $cfg['token'];
    $ch = curl_init();
    if ($metodo === 'GET') curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    else { curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true) ?: [];
    if ($code >= 400 || isset($j['error'])) throw new Exception(($j['error']['message'] ?? "HTTP $code") . (isset($j['error']['error_user_msg']) ? ' — ' . $j['error']['error_user_msg'] : ''));
    return $j;
}

/* Lo que debería estar haciendo cada conjunto de anuncios ahora. */
function plan($cfg) {
    $vence = strtotime($cfg['hasta'] . ' 00:00:00 America/Argentina/Cordoba');
    $temp = ciudades_con_temporal($cfg); $plan = [];
    foreach (['br', 'ar'] as $p) {
        $ciu = array_values($temp[$p]);
        $activo = $ciu && time() < $vence;
        $plan[$p] = ['adset' => $cfg['paises'][$p]['adset'] ?? '', 'activo' => $activo, 'ciudades' => $activo ? $ciu : [],
                     'motivo' => time() >= $vence ? 'pasó la fecha de corte' : ($ciu ? count($ciu) . ' ciudad(es) con aviso' : 'sin avisos')];
    }
    return $plan;
}

function aplicar($cfg) {
    $plan = plan($cfg);
    $estado_f = 'meta_ads_estado.json';
    $antes = con_archivo($estado_f, fn($d) => [$d, $d]);
    $nuevo = $antes; $hechos = [];
    foreach ($plan as $p => $x) {
        $firma = $x['activo'] ? 'on:' . implode(',', array_column($x['ciudades'], 'slug')) : 'off';
        if (($antes[$p] ?? '') === $firma) continue;                            // sin cambios: no se llama a Meta
        $txt = strtoupper($p) . ': ' . ($x['activo'] ? 'ACTIVAR en ' . implode(', ', array_column($x['ciudades'], 'nombre')) : 'PAUSAR') . ' (' . $x['motivo'] . ')';
        if ($cfg['prueba'] || !$cfg['token'] || !$x['adset']) {
            $hechos[] = '[prueba] ' . $txt; $nuevo[$p] = $firma; continue;
        }
        try {
            if ($x['activo']) {
                $t = graph($cfg, 'GET', $x['adset'], ['fields' => 'targeting'])['targeting'] ?? [];
                $t['geo_locations'] = ['custom_locations' => array_map(fn($c) => ['latitude' => $c['lat'], 'longitude' => $c['lon'],
                    'radius' => (int)$cfg['radio_km'], 'distance_unit' => 'kilometer'], $x['ciudades']), 'location_types' => ['home', 'recent']];
                graph($cfg, 'POST', $x['adset'], ['targeting' => json_encode($t), 'status' => 'ACTIVE']);
            } else {
                graph($cfg, 'POST', $x['adset'], ['status' => 'PAUSED']);
            }
            $hechos[] = $txt; $nuevo[$p] = $firma;
        } catch (Exception $e) {
            $hechos[] = 'ERROR ' . $txt . ': ' . $e->getMessage();               // se reintenta en la próxima pasada
        }
    }
    con_archivo($estado_f, fn($d) => [$nuevo, true]);
    foreach ($hechos as $h) ma_log($h);
    return ['plan' => $plan, 'hechos' => $hechos];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $r = aplicar(ma_cfg());
    echo $r['hechos'] ? implode("\n", $r['hechos']) . "\n" : "anuncios: sin cambios\n";
}
