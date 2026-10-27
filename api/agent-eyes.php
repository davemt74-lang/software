<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-eyes.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function vp3_eyes_json(int $status,array $body): never {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_eyes_json(403,['ok'=>false,'error'=>'Agent access required.']);
$pdo=db();
if(!$pdo||!agent_event_schema_ready_v1920($pdo)||!studio_participants_schema_ready())vp3_eyes_json(503,['ok'=>false,'error'=>'Run the existing database upgrade to enable Agent Eyes history.']);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $s=$pdo->prepare("SELECT * FROM agent_event_inbox WHERE owner_user_id=? AND source='agent_eyes' ORDER BY id DESC LIMIT 40");$s->execute([(int)$user['id']]);
        vp3_eyes_json(200,['ok'=>true,'profiles'=>studio_participants_list($pdo,$user),'events'=>array_map('agent_event_public_v1920',$s->fetchAll()?:[])]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')vp3_eyes_json(405,['ok'=>false,'error'=>'POST required.']);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if(!is_string($raw)||strlen($raw)>8192)vp3_eyes_json(413,['ok'=>false,'error'=>'Event is too large.']);
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_eyes_json(422,['ok'=>false,'error'=>'Invalid event.']);
    $csrf=(string)($input['csrf_token']??'');unset($input['csrf_token']);
    if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_eyes_json(419,['ok'=>false,'error'=>'Refresh your session.']);
    $s=$pdo->prepare("SELECT COUNT(*) FROM agent_event_inbox WHERE owner_user_id=? AND source='agent_eyes' AND received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)");$s->execute([(int)$user['id']]);
    if((int)$s->fetchColumn()>=120)vp3_eyes_json(429,['ok'=>false,'error'=>'Agent Eyes history is busy. Retry shortly.']);
    vp3_eyes_json(200,['ok'=>true]+vp3_eyes_record($pdo,$user,$input));
}catch(Throwable $e){
    vp3_eyes_json($e instanceof PDOException?503:($e instanceof RuntimeException?422:500),['ok'=>false,'error'=>$e instanceof RuntimeException && !$e instanceof PDOException?$e->getMessage():'Agent Eyes history unavailable.']);
}
