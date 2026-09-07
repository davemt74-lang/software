<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function vp3_radar_sites_json(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo=db();$user=current_user();
if(!$pdo||!$user||!has_permission('account.access',$user))vp3_radar_sites_json(false,['error'=>'Sign in to manage connected sites.'],401);
if(!personal_capability_has_v242('profile_agent.access',$user))vp3_radar_sites_json(false,['error'=>'Agent Radar is unavailable for this account.'],403);
if(!vp3_radar_schema_ready($pdo))vp3_radar_sites_json(false,['error'=>'Agent Radar is not ready. Run /upgrade.php.'],503);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $state=vp3_radar_server_enrich_site_state($pdo,$user,vp3_radar_external_site_state($pdo,$user));
    vp3_radar_sites_json(true,['state'=>$state]);
}
if($method!=='POST')vp3_radar_sites_json(false,['error'=>'Method not allowed.'],405);

$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=[];
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_radar_sites_json(false,['error'=>'Session expired. Refresh and try again.'],419);
$action=trim((string)($input['action']??''));
try{
    if($action==='create'){
        $state=vp3_radar_external_site_create($pdo,$user,(string)($input['domain']??''),(string)($input['label']??''));
        vp3_radar_sites_json(true,['state'=>vp3_radar_server_enrich_site_state($pdo,$user,$state)]);
    }
    if($action==='set_active'){
        $state=vp3_radar_external_site_set_active($pdo,$user,max(0,(int)($input['property_id']??0)),!empty($input['is_active']));
        vp3_radar_sites_json(true,['state'=>vp3_radar_server_enrich_site_state($pdo,$user,$state)]);
    }
    if($action==='rotate_server_token'){
        $result=vp3_radar_server_token_rotate($pdo,$user,max(0,(int)($input['property_id']??0)));
        vp3_radar_sites_json(true,$result);
    }
    if($action==='revoke_server_token'){
        $state=vp3_radar_server_token_revoke($pdo,$user,max(0,(int)($input['property_id']??0)));
        vp3_radar_sites_json(true,['state'=>$state]);
    }
    vp3_radar_sites_json(false,['error'=>'Unknown connected-site action.'],404);
}catch(Throwable $e){vp3_radar_sites_json(false,['error'=>$e->getMessage()],400);}
