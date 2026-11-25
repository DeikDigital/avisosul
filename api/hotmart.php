<?php
/* Aviso Sul — webhook de Hotmart (versión 2.0) para activar y quitar Premium en Brasil.
   Hotmart avisa cada cambio de una compra o suscripción. Este archivo:
   - verifica el Hottok (token de verificación de la cuenta de Hotmart) que llega en el encabezado X-HOTMART-HOTTOK;
   - identifica el plan por el código de oferta (o el id de producto) según datos_privados/hotmart.json;
   - guarda en datos_privados/premium.json, por email del comprador, hasta cuándo tiene Premium.
   La app consulta ese estado con la cuenta (api/cuenta.php) usando el mismo email.

   datos_privados/hotmart.json (lo completa Deik, nunca va al repositorio):
   {
     "hottok": "el Hottok de Ferramentas > Webhook > Autenticação",
     "checkout": { "mensal": "https://pay.hotmart.com/XXXX?off=aaaa", "temporada": "https://pay.hotmart.com/YYYY", "anual": "https://pay.hotmart.com/XXXX?off=bbbb" },
     "planes":   { "aaaa": {"plan":"mensal","dias":31}, "bbbb": {"plan":"anual","dias":366}, "YYYY": {"plan":"temporada","hasta":"03-31"} }
   }
   En "planes" la clave puede ser el código de oferta (off=) o el código/id del producto. */
require __DIR__ . '/_comun.php';

function cfg_hotmart() { $f = dir_privado() . '/hotmart.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function busca($a, $ruta) { foreach (explode('.', $ruta) as $k) { if (!is_array($a) || !array_key_exists($k, $a)) return null; $a = $a[$k]; } return $a; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false], 405);
$cfg = cfg_hotmart();
$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
if (!is_array($j)) { parse_str($raw, $j); }   // por si llega como formulario (versión 1)
$tok = $_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? ($j['hottok'] ?? '');
if (empty($cfg['hottok']) || !hash_equals((string)$cfg['hottok'], (string)$tok)) salir(['ok' => false, 'error' => 'hottok'], 401);

$ev = strtoupper((string)($j['event'] ?? ''));
$d = $j['data'] ?? $j;
$email = strtolower(trim((string)(busca($d, 'buyer.email') ?? busca($d, 'subscriber.email') ?? busca($d, 'subscription.subscriber.email') ?? ($j['email'] ?? ''))));
$oferta = (string)(busca($d, 'purchase.offer.code') ?? ($j['off'] ?? ''));
$prod = (string)(busca($d, 'product.id') ?? ($j['prod'] ?? ''));
$prodUcode = (string)(busca($d, 'product.ucode') ?? '');
$tx = (string)(busca($d, 'purchase.transaction') ?? ($j['transaction'] ?? ''));
$sub = (string)(busca($d, 'subscription.subscriber.code') ?? busca($d, 'subscriber.code') ?? ($j['subscriber_code'] ?? ''));
$plano = (string)(busca($d, 'subscription.plan.name') ?? '');

// registro de eventos (los últimos 300), para revisar la integración
con_archivo('hotmart_eventos.json', function ($l) use ($ev, $email, $oferta, $prod, $tx, $sub, $j) {
    $l[] = ['t' => time(), 'ev' => $ev, 'email' => $email, 'off' => $oferta, 'prod' => $prod, 'tx' => $tx, 'sub' => $sub, 'raw' => $j];
    return [array_slice($l, -300), true];
});
if (!$email) salir(['ok' => true, 'nota' => 'sin email']);

$pl = $cfg['planes'][$oferta] ?? $cfg['planes'][$prod] ?? $cfg['planes'][$prodUcode] ?? null;
$ACTIVA = ['PURCHASE_APPROVED', 'PURCHASE_COMPLETE'];
$QUITA = ['PURCHASE_REFUNDED', 'PURCHASE_CHARGEBACK', 'PURCHASE_PROTEST', 'PURCHASE_CANCELED', 'PURCHASE_EXPIRED'];

$res = con_archivo('premium.json', function ($p) use ($ev, $email, $pl, $tx, $sub, $oferta, $plano, $ACTIVA, $QUITA) {
    $x = $p[$email] ?? ['hasta' => 0, 'tx' => []];
    $ahora = time();
    if (in_array($ev, $ACTIVA, true)) {
        if (!$pl) { $x['pendiente'] = 'oferta sin configurar: ' . $oferta; $p[$email] = $x; return [$p, 'sin plan']; }
        if ($tx && in_array($tx, $x['tx'] ?? [], true)) return [$p, 'ya contado'];   // APPROVED y COMPLETE de la misma compra cuentan una vez
        if (!empty($pl['hasta'])) {   // pase hasta una fecha fija (ej.: 31/03)
            [$m, $dd] = array_map('intval', explode('-', $pl['hasta']));
            $y = (int)date('Y'); $lim = mktime(23, 59, 59, $m, $dd, $y); if ($lim < $ahora) $lim = mktime(23, 59, 59, $m, $dd, $y + 1);
            $x['hasta'] = max($x['hasta'] ?? 0, $lim);
        } else {
            $x['hasta'] = max($x['hasta'] ?? 0, $ahora) + ((int)($pl['dias'] ?? 31) + 2) * 86400;   // 2 días de margen para la renovación
        }
        $x['plan'] = $pl['plan'] ?? ($plano ?: 'premium'); $x['estado'] = 'activa';
        if ($tx) $x['tx'] = array_slice(array_merge($x['tx'] ?? [], [$tx]), -40);
        if ($sub) $x['sub'] = $sub;
        unset($x['pendiente']);
    } elseif (in_array($ev, $QUITA, true)) {
        if (!$tx || in_array($tx, $x['tx'] ?? [], true) || $ev !== 'PURCHASE_CANCELED') { $x['hasta'] = min($x['hasta'] ?? 0, $ahora); $x['estado'] = strtolower(str_replace('PURCHASE_', '', $ev)); }
    } elseif ($ev === 'SUBSCRIPTION_CANCELLATION') {
        $x['estado'] = 'cancelada';   // sigue con Premium hasta el final del período pagado
    } elseif ($ev === 'PURCHASE_DELAYED') {
        $x['estado'] = 'atrasada';
    } elseif ($ev === 'SWITCH_PLAN' && $pl) {
        $x['plan'] = $pl['plan'] ?? $x['plan'] ?? 'premium';
    }
    $x['act'] = $ahora;
    $p[$email] = $x;
    return [$p, $x['estado'] ?? 'ok'];
});
salir(['ok' => true, 'estado' => $res]);
