<?php
/* Sirve la foto o el video de un reporte de vecinos mientras el reporte siga vigente (72 h). */
require __DIR__ . '/_comun.php';
$id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
$f = null;
foreach (glob(dir_privado() . "/media/$id.*") ?: [] as $p) { $f = $p; break; }
if (!$id || !$f || filemtime($f) < time() - 72 * 3600) { http_response_code(404); header('Content-Type: text/plain'); echo 'no'; exit; }
$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
$ext = pathinfo($f, PATHINFO_EXTENSION);
header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . filesize($f));
header('Content-Disposition: inline');
readfile($f);
