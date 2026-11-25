<?php
/* Aviso Sul — precios vigentes para mostrar en la web y la app.  GET ?c=<ciudad>  (la ciudad decide la oferta relámpago) */
require __DIR__ . '/_comun.php';
require __DIR__ . '/_precios.php';
header('Cache-Control: public, max-age=120');
$p = precios_para($_GET['c'] ?? '');
foreach (PLANES_P as $k) unset($p['br'][$k]['link']);   // los enlaces de pago se piden aparte, con cuenta
salir(['ok' => true] + $p);
