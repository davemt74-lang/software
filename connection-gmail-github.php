<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-gmail-github-settings-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function agent_connection_gmail_github_response_v1(int $status, array $result): never
{
    http_response_code($status);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    agent_connection_gmail_github_response_v1(405,['ok'=>false,'result'=>'github_invalid','error'=>'POST is required.']);
}
$user = current_user();
if (!$user || !has_permission('account.access',$user)
    || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
    agent_connection_gmail_github_response_v1(403,['ok'=>false,'result'=>'github_permission','error'=>'Use your signed-in account to manage GitHub Workflow Review.']);
}
$pdo = db();
if (!$pdo) agent_connection_gmail_github_response_v1(503,['ok'=>false,'result'=>'github_unavailable','error'=>'GitHub Workflow Review is unavailable.']);

try {
    $type = strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '',2)[0]));
    $raw = $type === 'application/json' ? file_get_contents('php://input',false,null,0,32769) : false;
    $input = is_string($raw) && strlen($raw)<=32768 ? json_decode($raw,true,16) : null;
    if (!is_array($input)) throw new AgentConnectionExceptionV1('Invalid GitHub Workflow Review request.',400);
    $action = $input['action'] ?? null;
    if (!in_array($action,['state','enable','disable','restart'],true)) {
        throw new AgentConnectionExceptionV1('Invalid GitHub Workflow Review action.',400);
    }
    $fields = ['action','csrf_token','owner_key'];
    if ($action !== 'state') $fields = [...$fields,'connection_id','connection_revision','github_revision'];
    if ($action === 'enable') $fields = [...$fields,'folder_id','folder_name'];
    if (array_diff(array_keys($input),$fields)) throw new AgentConnectionExceptionV1('Invalid GitHub Workflow Review request.',400);
    $csrf = $input['csrf_token'] ?? null;
    if (!is_string($csrf) || !hash_equals(csrf_token(),$csrf)) {
        throw new AgentConnectionExceptionV1('Your session changed. Reload Chat and try again.',419);
    }
    $ownerKey = (string)$user['id'];
    if (($input['owner_key'] ?? null) !== $ownerKey) {
        throw new AgentConnectionExceptionV1('The Chat account changed. Reload Chat and try again.',409);
    }
    $result = ['ok'=>true,'owner_key'=>$ownerKey];
    if ($action !== 'state') $result['result'] = agent_connection_gmail_github_save_v1($pdo,$user,$input);
    $result['state'] = agent_connection_gmail_github_state_v1($pdo,$user);
    agent_connection_gmail_github_response_v1(200,$result);
} catch (AgentConnectionExceptionV1 $error) {
    [$status,$code] = match ($error->getCode()) {
        400,419,422 => [$error->getCode(),'github_invalid'],
        401 => [401,'github_reconnect'],
        403 => [403,'github_permission'],
        409 => [409,'github_conflict'],
        503 => [503,'github_unavailable'],
        default => [502,'github_failed'],
    };
    $message = $code === 'github_failed' ? 'Gmail could not verify the starting point. Try again.' : $error->getMessage();
    agent_connection_gmail_github_response_v1($status,['ok'=>false,'result'=>$code,'error'=>$message]);
} catch (AgentConnectionGmailExceptionV1 $error) {
    $reconnect = in_array($error->getCode(),[401,403],true);
    agent_connection_gmail_github_response_v1($reconnect ? 401 : 502,['ok'=>false,
        'result'=>$reconnect ? 'github_reconnect' : 'github_failed',
        'error'=>$reconnect ? 'Reconnect Gmail and try again.' : 'Gmail could not verify the starting point. Try again.']);
} catch (Throwable) {
    agent_connection_gmail_github_response_v1(500,['ok'=>false,'result'=>'github_failed','error'=>'GitHub Workflow Review could not complete the request.']);
}
