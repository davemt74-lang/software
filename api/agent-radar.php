<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_radar_portal_json_v1(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
        header('Allow: GET');vp3_radar_portal_json_v1(false,['error'=>'Use GET to read Agent Radar.'],405);
    }
    $pdo=db();$user=current_user();
    if(!$pdo||!$user||!has_permission('account.access',$user))vp3_radar_portal_json_v1(false,['error'=>'Sign in to view Agent Radar.'],401);
    if(!personal_capability_has_v242('profile_agent.access',$user))vp3_radar_portal_json_v1(false,['error'=>'Agent Radar is unavailable for this account.'],403);

    // Relationship refresh can scan substantial activity. Releasing the
    // browser lock lets Profile Inbox and chat requests proceed independently.
    $sessionId=session_id();$owner=(int)$user['id'];
    if($sessionId==='')throw new RuntimeException('Profile session unavailable.',403);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $relationshipMetrics=[];
    try{$relationshipMetrics=vp3_agent_relationship_refresh_owner($pdo,$user,120,true);}
    catch(Throwable $e){error_log('Agent Relationship intelligence refresh failed: '.$e->getMessage());}
    $radar=vp3_radar_portal_state($pdo,$owner);
    $radar=vp3_agent_relationship_enrich_portal($radar,$relationshipMetrics);

    // Never return an old owner's private Radar after logout, account switch,
    // session deletion or access revocation during the unlocked read.
    $_SESSION=[];session_id($sessionId);
    $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
    if(!$opened||!hash_equals($sessionId,session_id())){$_SESSION=[];reset_current_user_cache();throw new RuntimeException('Profile session changed.',403);}
    reset_current_user_cache();$fresh=current_user();
    if(!$fresh||(int)$fresh['id']!==$owner||!has_permission('account.access',$fresh)||!personal_capability_has_v242('profile_agent.access',$fresh))
        throw new RuntimeException('Profile access changed.',403);
    vp3_radar_portal_json_v1(true,['radar'=>$radar]);
}catch(Throwable $e){
    $status=$e instanceof RuntimeException&&$e->getCode()===403?403:503;
    if($status===503)error_log('Agent Radar portal failed: '.$e->getMessage());
    vp3_radar_portal_json_v1(false,['error'=>$status===403?'Your Profile session or access changed. Refresh before continuing.':'Agent Radar could not be loaded. Try again.'],$status);
}
