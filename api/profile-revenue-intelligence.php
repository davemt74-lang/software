<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-revenue-intelligence-v180.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function profile_revenue_intelligence_json_v180(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo=db();
$user=current_user();
if(!$pdo||!$user||!has_permission('account.access',$user)){
    profile_revenue_intelligence_json_v180(false,['error'=>'Sign in to view Profile conversion intelligence.'],401);
}
if(!personal_capability_has_v242('profile_agent.access',$user)){
    profile_revenue_intelligence_json_v180(false,['error'=>'Profile conversion intelligence is unavailable for this account.'],403);
}
if(!profile_agent_schema_ready($pdo)){
    profile_revenue_intelligence_json_v180(false,['error'=>'Profile conversion intelligence is not ready. Run /upgrade.php.'],503);
}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
    profile_revenue_intelligence_json_v180(false,['error'=>'Method not allowed.'],405);
}

try{
    profile_revenue_intelligence_json_v180(true,[
        'intelligence'=>profile_revenue_intelligence_v180($pdo,(int)$user['id']),
    ]);
}catch(Throwable $e){
    error_log('Profile revenue intelligence failed: '.$e->getMessage());
    profile_revenue_intelligence_json_v180(false,['error'=>'Profile conversion intelligence could not be loaded.'],500);
}
