<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
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
    $siteState=vp3_radar_server_enrich_site_state($pdo,$user,vp3_radar_external_site_state($pdo,$user));
    vp3_radar_policy_json(true,[
        'policies'=>vp3_radar_gateway_contact_policy_map($pdo,$uid,$ids),
        'access_profile'=>vp3_radar_access_profile_current($pdo,$uid),
        'access_profiles'=>vp3_radar_access_profile_public_catalog(),
        'access_requests'=>vp3_agent_access_owner_list($pdo,$uid,50),
        'agent_messaging_enabled'=>vp3_agent_messaging_allowed($user),
        'scoped_rules'=>vp3_radar_gateway_scoped_rules($pdo,$uid),
        'sites'=>$siteState['sites']??[],
        'agent_classes'=>VP3_RADAR_GATEWAY_CLASSES,
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
    if($action==='set_contact_watch'){
        vp3_radar_policy_json(true,vp3_agent_crm_set_watch(
            $pdo,$user,max(0,(int)($input['contact_id']??0)),!empty($input['watch_enabled'])
        ));
    }
    if($action==='apply_access_profile'){
        vp3_radar_policy_json(true,vp3_radar_access_profile_apply($pdo,$user,(string)($input['profile_slug']??'')));
    }
    if($action==='access_request_decision'){
        vp3_radar_policy_json(true,vp3_agent_access_owner_decide(
            $pdo,$user,max(0,(int)($input['request_id']??0)),(string)($input['decision']??'deny')
        ));
    }
    if($action==='set_scoped_rule'){
        vp3_radar_policy_json(true,vp3_radar_gateway_set_scoped_rule(
            $pdo,$user,
            (string)($input['scope_type']??''),(string)($input['scope_value']??''),
            (string)($input['policy_action']??'monitor'),max(0,(int)($input['property_id']??0)),
            max(1,(int)($input['limit_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M))
        ));
    }
    if($action==='delete_scoped_rule'){
        vp3_radar_policy_json(true,vp3_radar_gateway_delete_scoped_rule($pdo,$user,max(0,(int)($input['policy_id']??0))));
    }
    vp3_radar_policy_json(false,['error'=>'Unknown Agent Gateway action.'],404);
}catch(Throwable $e){vp3_radar_policy_json(false,['error'=>$e->getMessage()],400);}
