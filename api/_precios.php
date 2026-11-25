<?php
/* Aviso Sul / Alerta Sur — motor de precios (un solo lugar para todos los precios).
   Reglas (definidas por Deik, nov 2026):
   - Los precios máximos son los de lista; todo precio menor se muestra como % de descuento entero.
   - Hay un descuento máximo ("piso"): el precio nunca baja de ahí.
   - Campañas de temporada o festividades valen para todos (lanzamiento, verano, El Niño…).
   - La oferta relámpago vale solo en la ciudad donde hay un temporal (aviso oficial vigente o reportes de vecinos).
   - Argentina cobra con Mercado Pago (el monto lo pone este servidor). Brasil cobra con Hotmart, donde cada precio es una
     oferta fija: para un descuento hace falta su enlace de oferta; si no está cargado, se usa la del precio mínimo.
   Configuración: datos_privados/precios.json (se edita desde /admin/). */

const PLANES_P = ['mensal', 'temporada', 'anual'];

function precios_base() {
    return [
        'maximos' => ['ar' => ['mensal' => 9980, 'temporada' => 29980, 'anual' => 59980],
                      'br' => ['mensal' => 49.90, 'temporada' => 149.90, 'anual' => 299.90]],
        'piso' => 70,
        'relampago' => ['activa' => true, 'descuento' => 70, 'radio_km' => 50, 'horas' => 6, 'min_vecinos' => 2],
        'campanas' => [
            ['id' => 'lanzamiento', 'es' => 'Oferta de lanzamiento', 'pt' => 'Oferta de lançamento', 'descuento' => 50, 'desde' => '2026-11-20', 'hasta' => '2026-12-25', 'activa' => true],
            ['id' => 'verano', 'es' => 'Oferta de verano', 'pt' => 'Oferta de verão', 'descuento' => 40, 'desde' => '2026-12-26', 'hasta' => '2027-02-28', 'activa' => true],
            ['id' => 'nino', 'es' => 'Cuidado con El Niño 2026/2027', 'pt' => 'Cuidado com o El Niño 2026/2027', 'descuento' => 30, 'desde' => '2027-03-01', 'hasta' => '2027-06-30', 'activa' => true],
        ],
        'br_ofertas' => (object)[],   // {"50": {"mensal": {"link": "https://pay.hotmart.com/…?off=…", "precio": 24.90}, …}}
    ];
}
function precios_cfg() {
    $f = dir_privado() . '/precios.json';
    $c = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    return array_replace(precios_base(), $c);
}
/* Redondeo comercial: Argentina termina en 990, Brasil en ,90. */
function redondear($pais, $x) {
    if ($pais === 'ar') return max(990, (int)(round(($x + 10) / 1000) * 1000 - 10));
    $n = round($x - 0.90); return round(max(0, $n) + 0.90, 2);
}
function fmt_precio($pais, $x) {
    return $pais === 'ar' ? '$ ' . number_format($x, 0, ',', '.') : 'R$ ' . number_format($x, 2, ',', '.');
}
function hoy_ar() { return (new DateTime('now', new DateTimeZone('America/Argentina/Cordoba')))->format('Y-m-d'); }

/* ¿Hay temporal ahora en esta ciudad? Aviso oficial vigente, o reportes recientes de varios vecinos cerca. */
function hay_temporal($slug, $cfg) {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$slug)); if (!$slug) return false;
    $raiz = dirname(__DIR__);
    $dd = is_dir("$raiz/vivo/datos") ? "$raiz/vivo/datos" : "$raiz/datos";   // los agentes escriben en vivo/
    $a = @json_decode(@file_get_contents("$dd/$slug.json") ?: '', true);
    foreach (($a['avisos'] ?? []) as $x) { $h = strtotime($x['hasta'] ?? ''); $d = strtotime($x['desde'] ?? '') ?: 0; if ($h && $h > time() && $d <= time()) return true; }
    $ciu = null; foreach ((@json_decode(@file_get_contents("$raiz/ciudades.json") ?: '[]', true) ?: []) as $c) if (($c['slug'] ?? '') === $slug) $ciu = $c;
    $vf = dir_privado() . '/vecinos.json';
    if (!$ciu || !file_exists($vf)) return false;
    $v = json_decode(file_get_contents($vf), true) ?: [];
    $r = $cfg['relampago']; $desde = time() - 3600 * (float)($r['horas'] ?? 6); $quien = [];
    foreach (($v['rep'] ?? []) as $x) {
        if (($x['t'] ?? 0) < $desde) continue;
        $km = 6371 * 2 * asin(sqrt(pow(sin(deg2rad($x['lat'] - $ciu['lat']) / 2), 2) + cos(deg2rad($ciu['lat'])) * cos(deg2rad($x['lat'])) * pow(sin(deg2rad($x['lon'] - $ciu['lon']) / 2), 2)));
        if ($km > (float)($r['radio_km'] ?? 50)) continue;
        $quien[$x['v'] ?? $x['id']] = 1; foreach (($x['c'] ?? []) as $k => $_) $quien[is_string($k) ? $k : (string)$_] = 1;
    }
    return count($quien) >= (int)($r['min_vecinos'] ?? 2);
}
/* La campaña de temporada vigente con mayor descuento (o null). */
function campana_vigente($cfg) {
    $hoy = hoy_ar(); $mej = null;
    foreach ($cfg['campanas'] as $c) {
        if (empty($c['activa']) || $hoy < ($c['desde'] ?? '0') || $hoy > ($c['hasta'] ?? '9')) continue;
        if (!$mej || (int)$c['descuento'] > (int)$mej['descuento']) $mej = $c;
    }
    return $mej;
}
/* Enlaces de Hotmart del precio mínimo: los que están cargados en Hotmart (hotmart.json). */
function br_ofertas($cfg) {
    $o = json_decode(json_encode($cfg['br_ofertas']), true) ?: [];
    $hf = dir_privado() . '/hotmart.json'; $hm = file_exists($hf) ? (json_decode(file_get_contents($hf), true) ?: []) : [];
    $piso = (string)(int)$cfg['piso'];
    foreach (PLANES_P as $p) if (!empty($hm['checkout'][$p]) && empty($o[$piso][$p]['link']))
        $o[$piso][$p] = ['link' => $hm['checkout'][$p], 'precio' => redondear('br', $cfg['maximos']['br'][$p] * (1 - $cfg['piso'] / 100))];
    return $o;
}

/* Precios para una ciudad (slug). Devuelve lo que se muestra y lo que se cobra, por país y plan. */
function precios_para($slug = '') {
    $cfg = precios_cfg(); $piso = max(0, min(95, (int)$cfg['piso']));
    $camp = campana_vigente($cfg); $rel = !empty($cfg['relampago']['activa']) && hay_temporal($slug, $cfg);
    $d = $camp ? (int)$camp['descuento'] : 0; $motivo = $camp;
    if ($rel && (int)$cfg['relampago']['descuento'] > $d) { $d = (int)$cfg['relampago']['descuento']; $motivo = ['id' => 'relampago', 'es' => 'Oferta relámpago por temporal en tu zona', 'pt' => 'Oferta relâmpago por temporal na sua região', 'hasta' => null]; }
    $d = min($d, $piso);
    $out = ['descuento' => $d, 'relampago' => $rel && $motivo && $motivo['id'] === 'relampago',
            'campana' => $motivo ? ['id' => $motivo['id'], 'es' => $motivo['es'], 'pt' => $motivo['pt'], 'hasta' => $motivo['hasta'] ?? null] : null];
    $ofs = br_ofertas($cfg);
    foreach (['ar', 'br'] as $pais) foreach (PLANES_P as $p) {
        $max = (float)$cfg['maximos'][$pais][$p]; $min = redondear($pais, $max * (1 - $piso / 100));
        $pr = $d ? max($min, redondear($pais, $max * (1 - $d / 100))) : $max; $link = null;
        if ($pais === 'br') {   // Hotmart: solo precios con oferta cargada; si falta, el mínimo
            $o = $ofs[(string)$d][$p] ?? null;
            if ($d && !empty($o['link'])) { $pr = (float)($o['precio'] ?? $pr); $link = $o['link']; }
            else { $o = $ofs[(string)$piso][$p] ?? null; $pr = (float)($o['precio'] ?? $min); $link = $o['link'] ?? null; }
        }
        $ds = $pr < $max ? (int)round((1 - $pr / $max) * 100) : 0;
        $out[$pais][$p] = ['max' => $max, 'precio' => $pr, 'desc' => $ds, 'fmax' => fmt_precio($pais, $max), 'fprecio' => fmt_precio($pais, $pr),
                           'fmes' => $p === 'anual' ? fmt_precio($pais, $pais === 'ar' ? floor($pr / 12) : floor($pr / 12 * 100) / 100) : null] + ($link ? ['link' => $link] : []);
    }
    return $out;
}
