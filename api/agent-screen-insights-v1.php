<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-screen-handoffs-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$status=200;
try{
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to use sharing follow-ups.',403);$method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))throw new RuntimeException('GET or POST required.',405);screen_sessions_permission_v1($user,$method==='POST');$pdo=db();if(!$pdo)throw new RuntimeException('Sharing storage unavailable.',503);
    if($method==='POST'){$raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new RuntimeException('Follow-up request exceeds its limit.',413);$input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Use a follow-up request object.');if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh before updating follow-ups.',419);}else $input=$_GET;
    if((string)($input['owner_key']??'')!==(string)$user['id'])throw new RuntimeException('Sharing account changed. Refresh.',409);$aid=screen_insights_agent_v1($pdo,$user,(int)($input['agent_id']??0));screen_sessions_schema_v1($pdo);screen_insights_schema_v1($pdo);$action=$input['action']??'feed';
    if($method==='GET'&&$action==='feed'){$q=$pdo->prepare('SELECT * FROM agent_screen_handoffs_v1 WHERE owner_user_id=? AND agent_id=? ORDER BY updated_at DESC LIMIT 20');$q->execute([(int)$user['id'],$aid]);$links=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){try{$links[]=screen_handoff_view_v1($pdo,$user,$r);}catch(Throwable $ignored){}}$result=['cards'=>screen_insights_feed_v1($pdo,$user,$aid),'handoffs'=>$links];}
    elseif($method==='POST'&&$action==='reset_suggestions'){$pdo->prepare('DELETE FROM agent_screen_insight_state_v1 WHERE owner_user_id=? AND agent_id=?')->execute([(int)$user['id'],$aid]);$result=['reset'=>true];}
    elseif($method==='POST'&&$action==='decide')$result=screen_insights_decide_v1($pdo,$user,$aid,$input);
    elseif($method==='POST'&&$action==='review_capture')$result=screen_insights_review_capture_v1($pdo,$user,$input);
    elseif($method==='POST'&&$action==='wrapup')$result=screen_insights_wrapup_v1($pdo,$user,$aid,$input);
    elseif($method==='POST'&&$action==='handoff'){$result=screen_handoff_prepare_v1($pdo,$user,$aid,$input);}
    elseif($method==='POST'&&$action==='sync_results')$result=screen_handoff_sync_v1($pdo,$user,$aid,screen_session_id_v1($input['handoff_id']??null));
    else throw new InvalidArgumentException('Unknown sharing follow-up action.');
    $result=['ok'=>true,'owner_key'=>(string)$user['id'],'agent_id'=>$aid,...$result];
}catch(Throwable $e){$code=(int)$e->getCode();$status=in_array($code,[403,404,405,409,413,419,429,503],true)?$code:422;$result=['ok'=>false,'error'=>$e instanceof PDOException?'Follow-up storage is unavailable.':($e instanceof JsonException?'Invalid follow-up request.':$e->getMessage())];}
http_response_code($status);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
