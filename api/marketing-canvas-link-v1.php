<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-activity-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-marketing-canvas-link-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        header('Allow: POST');
        throw new RuntimeException('Use POST to link a private Canvas.',405);
    }
    $user=current_user();
    $binding=$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'';
    if (!$user || !is_string($binding) || $binding==='' || !hash_equals(vp3_catch_up_page_binding_v1($user),$binding)) {
        throw new RuntimeException('Your Agent session changed. Refresh this page.',403);
    }
    $raw=(string)file_get_contents('php://input',false,null,0,4097);
    if (strlen($raw)>4096) throw new InvalidArgumentException('Choose one exact campaign and Canvas version.');
    $body=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['csrf_token']??null) || !hash_equals(csrf_token(),$body['csrf_token'])) {
        throw new RuntimeException('Your Agent session changed. Refresh this page.',419);
    }
    foreach (['agent_id'=>0,'conversation_id'=>1,'campaign_id'=>1,'campaign_version'=>0,'canvas_version'=>1] as $field=>$min) {
        if (!is_int($body[$field]??null) || $body[$field]<$min || $body[$field]>9007199254740991) {
            throw new InvalidArgumentException('Choose one exact campaign and Canvas version.');
        }
    }
    if (!is_string($body['canvas_id']??null) || !is_string($body['canvas_proof']??null)
        || !preg_match('/\A[a-f0-9]{64}\z/D',$body['canvas_proof'])) throw new InvalidArgumentException('Choose a current Canvas version.');
    $pdo=db();
    if (!$pdo) throw new RuntimeException('Campaign review is unavailable.',503);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$body['agent_id'],$body['conversation_id']);
    $result=vp3_marketing_canvas_link_v1($pdo,$scope,$body['campaign_id'],$body['canvas_id'],$body['canvas_version'],
        $body['campaign_version'],$body['canvas_proof']);
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException=>422,
        ($error instanceof DomainException || $error instanceof RuntimeException) && !($error instanceof PDOException)
            && in_array($error->getCode(),[403,404,405,409,419,422],true)=>(int)$error->getCode(),
        default=>503,
    };
    http_response_code($status);
    $message=match($status){
        403,419=>'Your Agent session or campaign access changed. Refresh this page.',
        404=>'This campaign or Canvas could not be found.',
        405=>'Use POST to link a private Canvas.',
        409=>'The campaign or Canvas changed. Refresh and choose the current version.',
        422=>'Choose one exact campaign and Canvas version.',
        default=>'The private Canvas link could not be saved.',
    };
    echo json_encode(['ok'=>false,'error'=>$message],JSON_THROW_ON_ERROR);
}
