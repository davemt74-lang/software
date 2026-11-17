<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-rules-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to manage Top of Mind rules.', 401);
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method,['GET','POST'],true)) {
        header('Allow: GET, POST');
        throw new RuntimeException('Use GET or POST.', 405);
    }
    $binding = (string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? '');
    if ($binding === '' || !hash_equals(vp3_catch_up_page_binding_v1($user),$binding)) {
        throw new RuntimeException('Your Agent session changed. Refresh and try again.', 403);
    }
    $input = $_GET;
    $revision = null;
    $patch = null;
    if ($method === 'POST') {
        $raw = (string)file_get_contents('php://input',false,null,0,8193);
        if (strlen($raw) > 8192) throw new RuntimeException('The rule update is too large.', 413);
        $input = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Choose a Top of Mind rule.');
        if (!is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(),$input['csrf_token'])) {
            throw new RuntimeException('Your Agent session changed. Refresh and try again.', 419);
        }
        $revision = $input['revision'] ?? null;
        $patch = $input['rules'] ?? null;
        if (array_diff(array_keys($input),['agent_id','conversation_id','csrf_token','revision','rules'])
            || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D',$revision)
            || !is_array($patch) || $patch === []) {
            throw new InvalidArgumentException('Choose a valid Top of Mind rule and revision.');
        }
    }
    $rawAgent = $input['agent_id'] ?? null;
    $rawChat = $input['conversation_id'] ?? null;
    foreach ([$rawAgent,$rawChat] as $id) {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D',(string)$id)
            || (int)$id > 9007199254740991) throw new InvalidArgumentException('Choose an exact Agent and saved Chat.');
    }
    if ((int)$rawChat < 1) throw new InvalidArgumentException('Choose a saved Chat.');
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Top of Mind rules are unavailable.', 503);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $state = vp3_coordination_rule_settings_v1($pdo,$user,(int)$rawAgent,(int)$rawChat,$revision,$patch);
    echo json_encode(['ok'=>true,'state'=>$state],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException=>422,
        in_array($error->getCode(),[401,403,405,409,413,419,422,503],true)=>(int)$error->getCode(),
        default=>503,
    };
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>match ($status) {
        401=>'Sign in to manage Top of Mind rules.',
        403=>'Your Agent session or Chat access changed.',
        405=>'Use GET or POST to manage Top of Mind rules.',
        409=>'Top of Mind rules changed. Refresh and try again.',
        413=>'The rule update is too large.',
        419=>'Your Agent session changed. Refresh and try again.',
        422=>'Choose a valid Top of Mind rule and saved Chat.',
        default=>'Top of Mind rules are unavailable.',
    }],JSON_UNESCAPED_SLASHES);
}
