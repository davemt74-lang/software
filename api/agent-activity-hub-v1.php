<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to read Agent activity.', 401);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Activity storage is unavailable.', 503);
    $binding = (string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? '');
    $marketingRead=($_GET['view']??'')==='marketing';$sessionId=session_id();$owner=(int)$user['id'];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $state = vp3_agent_activity_hub_request_v1($pdo, $user, (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        $_GET['agent_id'] ?? '0', $_GET['conversation_id'] ?? '0', $binding, $_GET['view'] ?? '',
        $_GET['collection'] ?? '', $_GET['cursor'] ?? '', $_GET['list_id'] ?? '');
    if($marketingRead){
        if($sessionId==='')throw new RuntimeException('Your Agent session changed.',403);
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $_SESSION=[];session_id($sessionId);
        $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
        if(!$opened||!hash_equals($sessionId,session_id())){$_SESSION=[];reset_current_user_cache();throw new RuntimeException('Your Agent session changed.',403);}
        reset_current_user_cache();$fresh=current_user();
        if(!$fresh||(int)$fresh['id']!==$owner||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($fresh),$binding))
            throw new RuntimeException('Your Agent session changed.',403);
        $scope=vp3_agent_activity_scope_v1($pdo,$fresh,$state['agent_id'],$state['conversation_id']);
        if($state['conversation_id']>0){
            require_once dirname(__DIR__).'/includes/profile-analytics-authority-v1.php';
            $periods=(bool)array_filter($state['product_marketing']['items']??[],static fn(array $item):bool=>($item['kind']??null)==='followthrough');
            $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
            try{vp3_profile_analytics_authority_current_v1($pdo,$scope,$periods);if($own)$pdo->commit();}
            catch(Throwable $error){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
        }
    }
    echo json_encode(['ok'=>true, 'state'=>$state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = in_array($error->getCode(), [401,403,405,409,422,503], true) ? (int)$error->getCode() : 503;
    if ($status === 405) header('Allow: GET');
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>match ($status) {
        401,403=>'Your Agent session or access changed.',
        405=>'Use GET to read Agent activity.',
        422=>'Choose a valid activity view, Agent, and conversation.',
        default=>'Agent activity could not be refreshed.',
    }], JSON_UNESCAPED_SLASHES);
}
