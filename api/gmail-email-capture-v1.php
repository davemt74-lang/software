<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/gmail-email-capture-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-preferences-http-v1.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new DomainException('Use POST for email selections.',405);
    $raw=file_get_contents('php://input',false,null,0,460001);
    if (!is_string($raw)||strlen($raw)>460000) throw new DomainException('The email selection is too large.',413);
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_is_list($input)) throw new DomainException('Choose a valid email selection action.',422);
    $base=['action','owner_key','agent_id','conversation_id','csrf_token','source_ref'];
    $fields=match($input['action']??null){'analyze'=>['request_id','mode','text','image','timezone'],'get'=>['capture_id','request_id'],'history'=>['cursor'],'calendar','reminder','task','chat'=>['text','fields','capture_id'],default=>throw new DomainException('Choose an email selection action.',422)};
    if (!is_array($input)||array_is_list($input)||array_diff(array_keys($input),[...$base,...$fields])||!is_string($input['source_ref']??null)) throw new DomainException('Choose a valid email selection action.',422);
    if (defined('VP3_PHONE_AGENT_NATIVE_V1')||vp3_participant_scope_v1()!==null) throw new DomainException('Open your private owner Chat to use email selections.',403);
    $user=current_user();
    if (!$user||!has_permission('chat.access',$user)) throw new DomainException('Sign in to use email selections.',401);
    if (($input['owner_key']??null)!==(string)$user['id']) throw new DomainException('The Chat account changed.',409);
    if (!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token'])) throw new DomainException('Refresh Chat before using this email.',419);
    if (!is_int($input['agent_id']??null)||$input['agent_id']<0||!is_int($input['conversation_id']??null)||$input['conversation_id']<1) throw new DomainException('Open a saved private Chat.',422);
    $pdo=db();if (!$pdo instanceof PDO) throw new DomainException('Email selection storage is unavailable.',503);
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$input['agent_id']);$principal=vp3_agent_chat_principal_v380($user,$agent);
    $_SESSION['agent_connection_email_review_v1']??=bin2hex(random_bytes(32));
    $current=agent_email_preferences_http_current_v1($pdo,$user,$principal,$input['conversation_id']);
    $source=gmail_email_capture_source_open_v1($user,$input['source_ref']);session_write_close();
    if ($input['action']==='get'&&isset($input['request_id'])) {
        if (isset($input['capture_id'])||!is_string($input['request_id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$input['request_id'])) throw new DomainException('Choose one saved analysis request.',422);
        $input['capture_id']=substr(gmail_email_capture_hash_v1([(int)$user['id'],$input['agent_id'],$input['conversation_id'],$input['request_id']]),0,32);
    }
    if ($input['action']==='get'&&(!is_string($input['capture_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$input['capture_id']))) throw new DomainException('Choose a saved email analysis.',422);
    if ($input['action']==='history'&&isset($input['cursor'])&&!is_string($input['cursor'])) throw new DomainException('Refresh the saved analysis list.',422);
    if ($input['action']==='analyze') ignore_user_abort(true);
    $result=match($input['action']) {
        'analyze'=>['capture'=>gmail_email_capture_analyze_v1($pdo,$user,$principal,$input['conversation_id'],$source,$input,$current)],
        'get'=>['capture'=>gmail_email_capture_get_v1($pdo,$user,$principal,$input['conversation_id'],$source,(string)($input['capture_id']??''),$current)],
        'history'=>['history'=>gmail_email_capture_history_v1($pdo,$user,$principal,$input['conversation_id'],$source,$input['cursor']??null,$current)],
        'task','chat'=>['saved'=>gmail_email_capture_finding_v1($pdo,$user,$principal,$input['conversation_id'],$source,$input['action'],$input,$current)],
        default=>['saved'=>gmail_email_capture_save_v1($pdo,$user,$principal,$input['conversation_id'],$source,$input['action'],$input,$current)],
    };
    $current();echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    $code=(int)$e->getCode();if ($e instanceof JsonException||$e instanceof InvalidArgumentException) $code=422;
    if ($e instanceof PDOException||!in_array($code,[401,403,404,405,409,413,419,422,429,502,503],true)) $code=503;
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$e instanceof DomainException||$e instanceof AgentConnectionExceptionV1||$e instanceof AgentConnectionGmailExceptionV1?$e->getMessage():'The email selection could not be verified. Reopen the email and check the saved analysis.'],JSON_INVALID_UTF8_SUBSTITUTE);
}
