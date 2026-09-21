<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-transaction-control-v2280.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');
function vp3_extension_control_json_v2280(int $status,array $payload=[]): never {
  http_response_code($status);if($status!==204)echo json_encode(['build'=>VP3_BROWSER_CONTROL_V2280]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
function vp3_extension_control_input_v2280(): array {
  $raw=(string)file_get_contents('php://input');if(strlen($raw)>32768)vp3_extension_control_json_v2280(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Transaction control request is too large.']]);
  if(trim($raw)==='')return [];$in=json_decode($raw,true);if(!is_array($in))vp3_extension_control_json_v2280(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);return $in;
}
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));if($method==='OPTIONS')vp3_extension_control_json_v2280(204);if($method!=='POST')vp3_extension_control_json_v2280(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1')vp3_extension_control_json_v2280(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
try{
  $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
  if(!vp3_browser_control_schema_ready_v2280($pdo))vp3_extension_control_json_v2280(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Transaction Trust & Control.']]);
  $session=vp3_extension_session_authenticate_v2001($pdo);if(!$session)vp3_extension_control_json_v2280(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
  if(!vp3_extension_session_has_capability_v2001($session,'agent.message'))vp3_extension_control_json_v2280(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Transaction controls require Browser Agent access.']]);
  $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
  if(!$user||!has_permission('chat.access',$user))vp3_extension_control_json_v2280(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Transaction controls are unavailable for this VP3 account.']]);
  $input=vp3_extension_control_input_v2280();$action=trim((string)($input['action']??'status'));$device=(string)($session['device_id']??'');
  $base=['ok'=>true,'server_authoritative'=>true,'automatic_external_writes'=>false,'external_write_requires_fresh_v2240'=>true,'transaction_family_frozen_at'=>'v22.80'];
  if($action==='status')vp3_extension_control_json_v2280(200,$base+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='settings_update')vp3_extension_control_json_v2280(200,$base+['settings'=>vp3_browser_control_update_settings_v2280($pdo,$user,$input,$device),'control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='stop_all')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_stop_all_v2280($pdo,$user,$device)+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='resume_all')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_resume_all_v2280($pdo,$user,$device)+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='tracker_action')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_tracker_action_v2280($pdo,$user,trim((string)($input['continuity_id']??'')),trim((string)($input['tracker_action']??'')),$device)+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='forget')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_forget_v2280($pdo,$user,trim((string)($input['continuity_id']??'')),$device)+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='prune')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_prune_v2280($pdo,$user,$device)+['control'=>vp3_browser_control_status_v2280($pdo,$user)]);
  if($action==='scan_permit')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_scan_permit_v2280($pdo,$user,$device,trim((string)($input['domain']??''))));
  if($action==='scan_result')vp3_extension_control_json_v2280(200,$base+vp3_browser_control_scan_result_v2280($pdo,$user,$device,trim((string)($input['scope_key']??'')),trim((string)($input['result_code']??''))));
  if($action==='audit')vp3_extension_control_json_v2280(200,$base+['audit'=>vp3_browser_control_audit_v2280($pdo,$user,(int)($input['limit']??80))]);
  vp3_extension_control_json_v2280(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Transaction Control action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){vp3_extension_control_json_v2280($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){vp3_extension_control_json_v2280(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){vp3_extension_control_json_v2280(422,['ok'=>false,'error'=>['code'=>'transaction_control_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){error_log('VP3 Transaction Control v22.80 failed: '.$e->getMessage());vp3_extension_control_json_v2280(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Transaction Control is temporarily unavailable.']]);}
