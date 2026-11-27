<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/agent-gmail-documents-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-preferences-http-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new DomainException('Use POST for attachment review.',405);
    $raw=file_get_contents('php://input',false,null,0,12001);
    if (!is_string($raw)||strlen($raw)>12000) throw new DomainException('Attachment request is too large.',413);
    $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_is_list($input)||array_diff(array_keys($input),['action','owner_key','agent_id','conversation_id','csrf_token','source_ref','part','request_id','instruction','review_id'])
        ||!in_array($input['action']??null,['list','open','get','analyze','reply'],true)||!is_string($input['source_ref']??null)) throw new DomainException('Choose an attachment action.',422);
    $allowed=['action','owner_key','agent_id','conversation_id','csrf_token','source_ref'];
    if ($input['action']!=='list') $allowed[]='part';
    if ($input['action']==='analyze') $allowed=[...$allowed,'request_id','instruction'];
    if ($input['action']==='reply') $allowed[]='review_id';
    if (array_diff(array_keys($input),$allowed)||($input['action']!=='list'&&!is_string($input['part']??null))) throw new DomainException('Choose a valid attachment action.',422);
    $user=current_user();
    if (!$user||!has_permission('chat.access',$user)) throw new DomainException('Sign in to review private attachments.',401);
    if (($input['owner_key']??null)!==(string)$user['id']) throw new DomainException('The Chat account changed.',409);
    if (!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token'])) throw new DomainException('Refresh Chat before reviewing attachments.',419);
    if (!is_int($input['agent_id']??null)||$input['agent_id']<0||!is_int($input['conversation_id']??null)||$input['conversation_id']<1) throw new DomainException('Open a saved private Agent Chat.',422);
    $pdo=db();if (!$pdo instanceof PDO) throw new DomainException('Attachment review storage is unavailable.',503);
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$input['agent_id']);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $_SESSION['agent_connection_email_review_v1']??=bin2hex(random_bytes(32));
    $input['_key']=$_SESSION['agent_connection_email_review_v1'];
    $current=agent_email_preferences_http_current_v1($pdo,$user,$principal,$input['conversation_id']);
    session_write_close();
    if ($input['action']==='analyze') ignore_user_abort(true);
    $result=agent_gmail_document_action_v1($pdo,$user,$principal,$input['conversation_id'],$input,$current);
    $source=agent_gmail_document_source_open_v1($user,$input['source_ref']);
    agent_gmail_document_current_v1($pdo,$user,$principal,$input['conversation_id'],$source,$current);
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $code=(int)$error->getCode();if ($error instanceof JsonException||$error instanceof InvalidArgumentException) $code=422;
    if ($error instanceof PDOException||!in_array($code,[400,401,403,404,405,409,413,419,422,429,502,503],true)) $code=503;
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$error instanceof DomainException||$error instanceof AgentConnectionExceptionV1||$error instanceof AgentConnectionGmailExceptionV1
        ?$error->getMessage():'The attachment review could not be verified. Reopen the email or check its saved review status.'],JSON_INVALID_UTF8_SUBSTITUTE);
}
