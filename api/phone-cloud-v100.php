<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';

function vp3_phone_member_json_v100(bool $ok,array $body=[],int $status=200): never
{
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok]+$body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

$user=current_user();$pdo=db();
if (!$user || !has_permission('account.access',$user) || !has_permission('chat.access',$user))
    vp3_phone_member_json_v100(false,['error'=>'Phone settings are unavailable for this account.'],403);
if (!$pdo || !vp3_phone_schema_ready_v100($pdo))
    vp3_phone_member_json_v100(false,['error'=>'Phone settings require the Cloud database upgrade.'],503);
$userId=(int)$user['id'];

if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    vp3_phone_member_json_v100(true,['state'=>vp3_phone_member_state_v100($pdo,$userId),
        'provider_connected'=>(bool)vp3_phone_twilio_state_v100()['configured']]);
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST')
    vp3_phone_member_json_v100(false,['error'=>'GET or POST required.'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input) || !hash_equals(csrf_token(),(string)($input['csrf_token']??'')))
    vp3_phone_member_json_v100(false,['error'=>'Session expired. Refresh and try again.'],419);

try {
    $action=(string)($input['action']??'');
    if ($action==='search') {
        $state=vp3_phone_member_state_v100($pdo,$userId);
        if (empty($state['can_register'])) throw new RuntimeException('Your subscription has no available phone number allowance.');
        // Carrier searches are read-only, but still rate-limit access to the
        // platform's central Twilio account.
        $now=time();$attempts=array_values(array_filter((array)($_SESSION['phone_cloud_search_v100']??[]),
          static fn($timestamp)=>is_numeric($timestamp)&&(int)$timestamp>$now-60));
        if (count($attempts)>=6) vp3_phone_member_json_v100(false,['error'=>'Please wait before searching again.'],429);
        $attempts[]=$now;$_SESSION['phone_cloud_search_v100']=$attempts;
        vp3_phone_member_json_v100(true,['numbers'=>vp3_phone_twilio_search_v100((string)($input['area_code']??''))]);
    }
    if ($action==='request_number') {
        $state=vp3_phone_member_state_v100($pdo,$userId);
        if (empty($state['can_register'])) throw new RuntimeException('Your subscription has no available phone number allowance.');
        vp3_phone_request_number_v100($pdo,$userId,(int)($input['agent_id']??0),(string)($input['number']??''));
        vp3_phone_member_json_v100(true,['state'=>vp3_phone_member_state_v100($pdo,$userId)]);
    }
    vp3_phone_member_json_v100(false,['error'=>'Unknown phone action.'],422);
} catch (InvalidArgumentException|RuntimeException $error) {
    vp3_phone_member_json_v100(false,['error'=>$error->getMessage()],422);
} catch (Throwable) {
    vp3_phone_member_json_v100(false,['error'=>'Phone request unavailable. Try again later.'],503);
}
