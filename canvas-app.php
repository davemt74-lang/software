<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/coding-canvas-publishing-v1.php';
require_once __DIR__.'/includes/coding-canvas-public-view-v1.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), midi=(), payment=()');

// An opaque srcdoc inherits this policy as well as its own tighter policy.
$nonce=bin2hex(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce' blob:; style-src 'self' 'nonce-$nonce';"
    ." frame-src 'self'; worker-src blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");

$error='';
$payload=null;
$title='Canvas app';
$slug=$_GET['app']??null;
try {
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Canvas storage unavailable.');
    $release=is_string($slug)?vp3_coding_canvas_public_release_v1($pdo,$slug):null;
    if($release===null){
        http_response_code(404);
        $error='This Canvas app isn’t available.';
    } else {
        $title=trim($release['title'])?:$title;
        $payload=json_encode(vp3_public_canvas_payload_v1($pdo,$release),
            JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
} catch(Throwable $errorInfo) {
    http_response_code(503);
    $error='This Canvas app is temporarily unavailable.';
    error_log('Public Canvas unavailable: '.get_class($errorInfo));
}

$asset=static fn(string $file):string=>url('/'.$file.'?v='.substr(hash_file('sha256',__DIR__.'/'.$file),0,12));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e($asset('canvas-app-v1.css')) ?>">
  <?php if($payload!==null): ?>
  <script src="<?= e($asset('coding-canvas-runtime-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('canvas-app-v1.js')) ?>" nonce="<?= e($nonce) ?>" defer></script>
  <?php endif; ?>
</head>
<body class="public-canvas">
  <header class="public-canvas-header"><h1><?= e($title) ?></h1></header>
  <main class="public-canvas-main" id="publicCanvas">
    <?php if($payload===null): ?>
    <p class="public-canvas-status" role="status"><?= e($error) ?></p>
    <?php else: ?>
    <p class="public-canvas-status" id="publicCanvasStatus" role="status" hidden></p>
    <noscript><p class="public-canvas-status">Enable JavaScript to open this app.</p></noscript>
    <?php endif; ?>
  </main>
  <?php if($payload!==null): ?>
  <script type="application/json" id="publicCanvasData"><?= $payload ?></script>
  <?php endif; ?>
</body>
</html>
