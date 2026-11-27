<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-v1.php';

vp3_phone_http_headers_v1();
try {
    vp3_phone_http_transport_v1();
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST');throw new RuntimeException('Use POST.',405); }
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=(vp3_phone_http_secure_v1()?'https://':'http://').($_SERVER['HTTP_HOST']??'');
    vp3_phone_agent_workspace_need_v1(($_SERVER['HTTP_X_VP3_PHONE']??'')==='1'
        &&($origin===''||strcasecmp($origin,$expected)===0)
        &&in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin',['same-origin','none'],true),403);
    $input=vp3_phone_http_json_v1();$pdo=db();
    if (!$pdo) throw new RuntimeException('Workspace unavailable.',503);
    $result=vp3_phone_agent_workspace_request_v1($pdo,current_user(),(string)($_COOKIE['vp3_phone_device_v1']??''),$input);
    vp3_phone_http_send_v1($result);
} catch(Throwable $error) {
    $status=(int)$error->getCode();
    if (!($error instanceof RuntimeException)||$error instanceof PDOException||!in_array($status,[401,403,404,405,409,413,415,419,422,429,503],true)) $status=503;
    http_response_code($status);
    $codes=[401=>'phone_approval_required',403=>'workspace_access_changed',404=>'item_unavailable',409=>'workspace_changed',419=>'session_changed',503=>'workspace_unavailable'];
    $messages=[401=>'Approve this phone’s AGENT access from the main app.',403=>'Workspace access changed. Check this phone’s AGENT approval in the main app.',
        404=>'This item is no longer available.',409=>'This workspace or item changed. Refresh before continuing.',419=>'Your session changed. Refresh before continuing.',
        503=>'The Agent workspace is temporarily unavailable.'];
    echo json_encode(['ok'=>false,'error'=>$codes[$status]??'invalid_workspace_request','message'=>$messages[$status]??'Check the workspace request and try again.']);
}
