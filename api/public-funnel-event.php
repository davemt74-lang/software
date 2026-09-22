<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/vp3-funnel.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
    exit;
}

$cta = vp3_funnel_token($_POST['cta'] ?? null, 80);
$target = vp3_funnel_token($_POST['target'] ?? null, 80);
if ($cta === null || $target === null) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid event.']);
    exit;
}

vp3_funnel_event('cta_click', ['cta'=>$cta,'target'=>$target]);
echo json_encode(['ok'=>true]);
