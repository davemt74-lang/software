<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-icon-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        header('Allow: POST');
        throw new DomainException('Use POST.',405);
    }
    $user = current_user();
    if (!$user) throw new DomainException('Sign in to generate a private Canvas icon.',401);
    $raw = file_get_contents('php://input',false,null,0,8193);
    if ($raw===false || strlen($raw)>8192) throw new DomainException('The Canvas icon request is too large.',413);
    $input = json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input),
        ['action','csrf_token','owner_id','agent_id','conversation_id','canvas_id','revision','replace_hash'])) {
        throw new InvalidArgumentException('Choose a Canvas icon operation.');
    }
    if (!is_string($input['csrf_token']??null) || !hash_equals(csrf_token(),$input['csrf_token'])) {
        throw new DomainException('Refresh Chat to restore this session.',419);
    }
    if (($input['owner_id']??null)!==(int)$user['id']) throw new DomainException('Reopen Chat in the signed-in account.',403);
    $action = $input['action']??null;
    $agent = $input['agent_id']??null;
    $chat = $input['conversation_id']??null;
    $canvas = $input['canvas_id']??null;
    $revision = $input['revision']??null;
    $replace = $input['replace_hash']??null;
    if (!in_array($action,['run','status'],true) || !is_int($agent) || $agent<0 || !is_int($chat) || $chat<1
        || !is_string($canvas) || !is_int($revision) || $revision<1
        || $replace!==null && (!is_string($replace) || !preg_match('/\A[a-f0-9]{64}\z/D',$replace))) {
        throw new InvalidArgumentException('Choose the current Canvas preview.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Canvas storage is unavailable.',503);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if ($action==='run') {
        ignore_user_abort(true);
        @set_time_limit(150);
    }
    $canvas = vp3_coding_canvas_id_v1($canvas);
    $state = vp3_coding_canvas_icon_step_v1($pdo,$user,$agent,$chat,$canvas,$revision,$replace,$action==='run');
    echo json_encode(['ok'=>true,'owner_id'=>(int)$user['id'],'agent_id'=>$agent,'conversation_id'=>$chat,
        'canvas_id'=>$canvas,'revision'=>$revision]+$state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $code = $error->getCode();
    $status = match(true) {
        $error instanceof InvalidArgumentException,$error instanceof JsonException=>422,
        $error instanceof PDOException=>503,
        in_array($code,[401,402,403,404,405,409,413,419,503],true)=>$code,
        default=>503,
    };
    http_response_code($status);
    $message = $status===503 ? 'The Image Generator is unavailable for this Canvas. The current icon is kept.'
        : ($error instanceof JsonException?'Enter a valid Canvas icon request.':$error->getMessage());
    echo json_encode(['ok'=>false,'error'=>$message],JSON_INVALID_UTF8_SUBSTITUTE);
}
