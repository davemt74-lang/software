<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function vp3_analytics_json(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo=db();$user=current_user();
if(!$pdo||!$user||!has_permission('account.access',$user))vp3_analytics_json(false,['error'=>'Sign in to view VP3 Analytics.'],401);
if(!personal_capability_has_v242('profile_agent.access',$user))vp3_analytics_json(false,['error'=>'VP3 Analytics is unavailable for this account.'],403);
if(!vp3_radar_schema_ready($pdo))vp3_analytics_json(false,['error'=>'VP3 Analytics is not ready. Run /upgrade.php.'],503);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')vp3_analytics_json(false,['error'=>'Method not allowed.'],405);
$propertyId=max(0,(int)($_GET['property_id']??0));
$days=max(1,min(90,(int)($_GET['days']??30)));
try{vp3_analytics_json(true,['analytics'=>vp3_analytics_dashboard_state_v2($pdo,$user,$propertyId,$days)]);}catch(Throwable $e){error_log('VP3 Analytics dashboard failed: '.$e->getMessage());vp3_analytics_json(false,['error'=>'VP3 Analytics could not be loaded.'],500);}
