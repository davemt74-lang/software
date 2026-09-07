<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-radar-access-profiles.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function vp3_radar_policy_json(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo=db();$user=current_user();
if(!$pdo||!$user||!has_permission('account.access',$user))vp3_radar_policy_json(false,['error'=>'Sign in to manage Agent Gateway.'],401);
if(!personal_capability_has_v242('profile_agent.access',$user))vp3_radar_policy_json(false,['error'=>'Agent Gateway is unavailable for this account.'],403);
if(!vp3_radar_schema_ready($pdo))vp3_radar_policy_json(false,['error'=>'Agent Radar is not ready. Run /upgrade.php.'],503);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$uid=(int)$user['id'];
if($method==='GET'){
    $stmt=$pdo->prepare('SELECT id FROM vp3_agent_contacts WHERE owner_user_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 250');
    $stmt->execute([$uid]);
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    vp3_radar_policy_json(true,[
        'policies'=>vp3_radar_gateway_contact_policy_map($pdo,$uid,$ids),
        'access_profile'=>vp3_radar_access_profile_current($pdo,$uid),
        'access_profiles'=>vp3_radar_access_profile_public_catalog(),
    ]);
}
if($method!=='POST')vp3_radar_policy_json(false,['error'=>'Method not allowed.'],405);

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>8192)vp3_radar_policy_json(false,['error'=>'Payload too large.'],413);
$input=json_decode($raw,true);if(!is_array($input))$input=[];
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_radar_policy_json(false,['error'=>'Session expired. Refresh and try again.'],419);
$action=trim((string)($input['action']??''));
try{
    if($action==='set_contact_policy'){
        $result=vp3_radar_gateway_set_contact_policy(
            $pdo,$user,max(0,(int)($input['contact_id']??0)),
            (string)($input['policy_action']??'monitor'),
            max(1,(int)($input['limit_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M))
        );
        vp3_radar_policy_json(true,$result);
    }
    if($action==='apply_access_profile'){
        vp3_radar_policy_json(true,vp3_radar_access_profile_apply($pdo,$user,(string)($input['profile_slug']??'')));
    }
    vp3_radar_policy_json(false,['error'=>'Unknown Agent Gateway action.'],404);
}catch(Throwable $e){vp3_radar_policy_json(false,['error'=>$e->getMessage()],400);}
