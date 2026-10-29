<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-voice-preferences.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function agent_voice_settings_json(array $data, int $status=200): never {
    http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
}
$user=current_user(); $pdo=db();
if (!$user || !has_permission('chat.access',$user) || !chat_settings_agent_voice_allowed_v237($user)) agent_voice_settings_json(['ok'=>false,'error'=>'Voice settings are unavailable for this account.'],403);
if (!$pdo) agent_voice_settings_json(['ok'=>false,'error'=>'Database unavailable.'],503);
$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    if ($method==='GET') agent_voice_settings_json(['ok'=>true,'voices'=>agent_voice_default_catalog(),'selected'=>agent_voice_preferences_get($pdo,(int)$user['id'])]);
    if ($method!=='POST') agent_voice_settings_json(['ok'=>false,'error'=>'GET or POST required.'],405);
    $input=json_decode((string)file_get_contents('php://input'),true); $input=is_array($input)?$input:$_POST;
    if (!hash_equals(csrf_token(),(string)($input['csrf_token']??''))) agent_voice_settings_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
    if (($input['action']??'')!=='save_system_voice') agent_voice_settings_json(['ok'=>false,'error'=>'Unknown voice settings action.'],422);
    $id=trim((string)($input['voice_id']??''));
    $saved=agent_voice_preferences_save($pdo,(int)$user['id'],$id,$id===''?[]:agent_voice_default_catalog());
    unset($_SESSION['stonefellow_voice_v234_provider']);
    agent_voice_settings_json(['ok'=>true,'selected'=>$saved]);
} catch (Throwable $e) {
    agent_voice_settings_json(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Voice settings could not be saved.'],422);
}
