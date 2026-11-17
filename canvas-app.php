<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/coding-canvas-publishing-v1.php';

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

/** Only the documented public rows reach the guest page and its link broker. */
function vp3_public_canvas_source_v1(string $command,array $source,string $basePath): array
{
    $fields=match($command){
        'public-products'=>['price_label','price_cents','currency'],
        'public-events'=>['starts_at','location','status'],
        'public-booking'=>['duration_minutes','timezone'],
    };
    if(($source['status']??null)!=='ready'||!is_array($source['rows']??null))return ['status'=>'unavailable','rows'=>[]];
    $keys=array_fill_keys(['id','title','description','url',...$fields],true);
    $suffix=match($command){
        'public-products'=>'/product/[a-z0-9-]{1,80}',
        'public-events'=>'',
        'public-booking'=>'/book/[a-z0-9-]{1,80}',
    };
    $route='~\A/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]'.$suffix.'\z~D';
    $rows=[];
    foreach(array_slice($source['rows'],0,24) as $record){
        if(!is_array($record)||!is_string($record['id']??null)||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$record['id']))continue;
        $row=array_intersect_key($record,$keys);
        if(!is_string($row['url']??null)||!str_starts_with($row['url'],$basePath.'/')
            ||!preg_match($route,substr($row['url'],strlen($basePath))))unset($row['url']);
        $rows[]=$row;
    }
    return ['status'=>'ready','rows'=>$rows];
}

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
        $basePath=rtrim(url('/'),'/');
        $sources=[];
        $sourceCommands=['public-products','public-events','public-booking'];
        if(array_intersect($sourceCommands,$release['commands'])){
            require_once __DIR__.'/includes/coding-canvas-public-sources-v1.php';
            foreach(array_intersect($sourceCommands,$release['commands']) as $command){
                $sources[$command]=vp3_public_canvas_source_v1($command,
                    vp3_coding_canvas_public_command_data_v1($pdo,$release['owner_user_id'],$command),$basePath);
            }
        }
        $payload=json_encode(['artifact'=>$release['artifact'],'sources'=>$sources,'basePath'=>$basePath],
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
