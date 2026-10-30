<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-sms-outbound-v120.php';

function vp3_phone_outbound_json_v120(bool $ok,array $data=[],int $status=200): never
{
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
$user=current_user();$pdo=db();
if (!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user))
    vp3_phone_outbound_json_v120(false,['error'=>'Not authorized.'],403);
if (!$pdo||!vp3_phone_sms_outbound_ready_v120())
    vp3_phone_outbound_json_v120(false,['error'=>'SMS sending requires the Cloud upgrade.'],503);
$owner=(int)$user['id'];
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    $draftId=(int)($_GET['draft_id']??0);
    $draft=$draftId>0?vp3_phone_sms_draft_owned_v120($pdo,$owner,$draftId):null;
    if ($draftId>0&&!$draft) vp3_phone_outbound_json_v120(false,['error'=>'Draft not found.'],404);
    vp3_phone_outbound_json_v120(true,['draft'=>$draft,'monthly'=>vp3_phone_sms_monthly_quota_v120($pdo,$owner),
        'live'=>vp3_phone_sms_live_enabled_v120()]);
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') vp3_phone_outbound_json_v120(false,['error'=>'Method not allowed.'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input)||!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))
    vp3_phone_outbound_json_v120(false,['error'=>'Session expired.'],419);
try {
    if (($input['action']??'')==='draft') {
        $id=vp3_phone_sms_draft_v120($pdo,$owner,(int)($input['phone_number_id']??0),
            (string)($input['recipient']??''),(string)($input['body']??''),(string)($input['consent_source']??''));
        vp3_phone_outbound_json_v120(true,['draft_id'=>$id]);
    }
    if (($input['action']??'')==='send') {
        // Deliberate one-shot explicit authorization, never inferred from AI or
        // voice-recognized text without a separate verified user action.
        $result=vp3_phone_sms_authorize_send_v120($pdo,$owner,(int)($input['draft_id']??0),
            (string)($input['confirmation']??''));
        vp3_phone_outbound_json_v120(true,$result);
    }
    vp3_phone_outbound_json_v120(false,['error'=>'Unknown action.'],422);
} catch(InvalidArgumentException|RuntimeException $error) {
    vp3_phone_outbound_json_v120(false,['error'=>$error->getMessage()],422);
} catch(Throwable) {vp3_phone_outbound_json_v120(false,['error'=>'SMS service unavailable.'],503);}
