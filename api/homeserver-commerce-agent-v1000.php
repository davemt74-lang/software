<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-commerce-agent-v1000.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
function hs_commerce_agent_fail(int $status,string $message): never{http_response_code($status);echo json_encode(['ok'=>false,'error'=>mb_substr($message,0,400)],JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')hs_commerce_agent_fail(405,'Method not allowed.');
$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))hs_commerce_agent_fail(401,'Agent Commerce authorization is required.');$userId=homeserver_commerce_agent_v1000_authenticate(trim($m[1]));if(!$userId)hs_commerce_agent_fail(401,'Agent Commerce authorization is invalid or revoked.');
$raw=file_get_contents('php://input');if(!is_string($raw)||strlen($raw)>65536)hs_commerce_agent_fail(413,'Agent Commerce request is too large.');$body=json_decode($raw,true);if(!is_array($body))hs_commerce_agent_fail(400,'Agent Commerce request must be JSON.');$unknown=array_diff(array_keys($body),['operation','arguments']);if($unknown)hs_commerce_agent_fail(422,'Unsupported request field: '.array_values($unknown)[0]);$operation=trim((string)($body['operation']??''));$arguments=$body['arguments']??[];if(!is_array($arguments))hs_commerce_agent_fail(422,'arguments must be an object.');
$lockPdo=null;$lockName='';$failureStatus=0;$failureMessage='';$result=null;
try{
    if($operation==='vp3.commerce.fulfillment.update'){
        $idempotencyKey=trim((string)($arguments['idempotency_key']??''));if($idempotencyKey===''||strlen($idempotencyKey)>160)throw new InvalidArgumentException('A stable idempotency key is required.');
        $lockPdo=db();if(!$lockPdo)throw new RuntimeException('Agent Commerce is unavailable.');$lockName='vp3-commerce-agent:'.substr(hash('sha256',$userId.'|'.$operation.'|'.$idempotencyKey),0,48);$stmt=$lockPdo->prepare('SELECT GET_LOCK(?,10)');$stmt->execute([$lockName]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Another matching fulfillment request is still being processed.');
    }
    $result=homeserver_commerce_agent_v1000_execute($userId,$operation,$arguments);
}catch(InvalidArgumentException $e){$failureStatus=422;$failureMessage=$e->getMessage();}catch(Throwable $e){$failureStatus=409;$failureMessage=trim($e->getMessage());if($failureMessage===''||preg_match('/(?:sql|database|encrypt|decrypt|credential|stack|trace)/i',$failureMessage))$failureMessage='Agent Commerce request could not be completed.';}finally{
    if($lockPdo&&$lockName!==''){try{$stmt=$lockPdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lockName]);}catch(Throwable $ignored){}}
}
if($failureStatus>0)hs_commerce_agent_fail($failureStatus,$failureMessage);echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
