<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-call-continuity-v1.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'");
header('Referrer-Policy: no-referrer');
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')vp3_phone_workspace_error_v1('method_not_allowed',405,'Open Phone from VP3.');
    $pdo=db();if(!$pdo instanceof PDO)vp3_phone_workspace_error_v1('phone_unavailable',503,'Phone is temporarily unavailable.');
    $scope=vp3_phone_call_window_scope_v1($pdo,current_user(),$_GET);
}catch(VP3PhoneWorkspaceException $error){http_response_code($error->httpStatus);header('Content-Type: text/plain; charset=UTF-8');exit($error->getMessage());}
catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');exit('Phone could not verify this account. Reopen it from VP3.');}
$GLOBALS['VP3_PHONE_CONTINUITY_OWNER_V1']=true;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Phone · VP3</title><link rel="stylesheet" href="<?= e(url('/phone-call-window-v1.css?v='.substr(hash_file('sha256',__DIR__.'/phone-call-window-v1.css'),0,12))) ?>">
</head><body class="vp3-phone-call-window">
<header class="vp3-call-window-heading"><strong>VP3 · Phone</strong><p id="phoneCallWindowStatus" role="status">Checking your calling window…</p></header>
<?= vp3_phone_workspace_runtime_v1($scope['user'],$scope['agent_id'],$scope['conversation_id']) ?>
</body></html>
