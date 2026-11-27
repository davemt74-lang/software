<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/phone-agent-workspace-native-v1.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
$nonce=bin2hex(random_bytes(24));
// Only this trusted host can read native data. Generated Canvas code runs in a
// separate opaque-origin sandbox with the existing Canvas runtime's own CSP.
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce' blob:; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: blob: https: http:; media-src 'self' blob:; frame-src 'self'; worker-src blob:; base-uri 'none'; frame-ancestors 'self'; form-action 'none'");
$payload=null;$message='This private item is unavailable. Return to Agent and reopen it.';
try {
    vp3_phone_agent_native_origin_v1();
    vp3_phone_agent_workspace_need_v1(($_SERVER['REQUEST_METHOD']??'')==='GET',405);
    $channel=vp3_phone_agent_workspace_id_v1($_GET['channel']??null);
    $pdo=db();vp3_phone_agent_workspace_need_v1($pdo instanceof PDO,503);
    $context=vp3_phone_agent_native_context_v1($pdo,current_user(),$_GET);
    $item=$context['resolved']['item'];$target=$context['resolved']['source']['target'];
    vp3_phone_agent_workspace_need_v1(in_array($item['source_kind'],['gmail_draft','gmail_attention','image','canvas'],true),404);
    vp3_phone_agent_workspace_need_v1(is_string($_GET['source_revision']??null)
        &&hash_equals($item['source_revision'],$_GET['source_revision']),409);
    $params=['device_id'=>$context['scope']['device']['id'],'item_ref'=>$item['item_ref'],
        'scope_revision'=>$context['scope']['scope_revision']];
    if (in_array($item['source_kind'],['image','canvas','gmail_attention'],true)) $params['source_revision']=$item['source_revision'];
    $endpoint=static fn(string $kind):string=>url('/api/phone-location-v1.php').'?'.http_build_query(['workspace_'.$kind=>'1']+$params);
    $payload=['channel'=>$channel,'nonce'=>$nonce,'kind'=>$item['source_kind'],'title'=>$item['title'],
        'ownerKey'=>(string)$context['scope']['user']['id'],'agentId'=>$target['agent_id'],
        'conversationId'=>$target['conversation_id'],'binding'=>$context['scope']['scope_revision'],'target'=>$target,
        'csrf'=>csrf_token(),'emailEndpoint'=>$endpoint('email'),'canvasEndpoint'=>$endpoint('canvas'),
        'imageEndpoint'=>$endpoint('image'),'mediaEndpoint'=>$endpoint('media'),
        'callSettingsEndpoint'=>url('/api/phone-location-v1.php').'?workspace_calls=1',
        'deviceId'=>$context['scope']['device']['id'],'enrollmentRevision'=>(int)$context['scope']['enrollment']['revision'],
        'itemRef'=>$item['item_ref'],
        'connectionsUrl'=>url('/connections.php'),'basePath'=>rtrim(url('/'),'/')];
} catch(Throwable $error) {
    $code=(int)$error->getCode();http_response_code(in_array($code,[401,403,404,405,409,422,503],true)?$code:503);
    if($code===401)$message='Agent access changed. Approve this phone again from the main app.';
}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$clientPayload=$payload??(isset($channel)?['channel'=>$channel,'error'=>$message]:null);
$asset=static fn(string $file):string=>url('/'.$file.'?v='.substr(hash_file('sha256',dirname(__DIR__).'/'.$file),0,12));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= e($payload['title']??'Private Agent item') ?></title>
  <link rel="stylesheet" href="<?= e($asset('chat-email-composer-v1.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('chat-email-followthrough-v1.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('email-writing-preferences-v1.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('gmail-documents-v1.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('gmail-email-display-v1.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('phone-agent-item-v1.css')) ?>">
  <?php if($clientPayload!==null): ?>
  <script id="phoneAgentItemData" type="application/json" nonce="<?= e($nonce) ?>"><?= json_encode($clientPayload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
  <script src="<?= e($asset('phone-agent-item-v1.js')) ?>" defer></script>
  <?php endif; ?>
  <?php if($payload!==null): ?>
  <script src="<?= e($asset('gmail-documents-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('gmail-email-display-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('chat-email-followthrough-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('email-writing-preferences-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('chat-email-composer-v1.js')) ?>" defer></script>
  <script src="<?= e($asset('coding-canvas-runtime-v1.js')) ?>" defer></script>
  <?php endif; ?>
</head>
<body>
  <main id="phoneAgentItem">
    <p id="phoneAgentItemStatus" role="status"><?= e($payload===null?$message:'Opening your private item…') ?></p>
    <div id="chatThread" data-view="email"></div>
    <section id="phoneAgentItemContent" hidden></section>
  </main>
</body>
</html>
