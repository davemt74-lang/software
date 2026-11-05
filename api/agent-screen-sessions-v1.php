<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-screen-sessions-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$claim='';$uid=0;$status=200;$response=[];
try{
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to use sharing history.',403);
    $method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))throw new RuntimeException('GET or POST required.',405);
    $pdo=db();if(!$pdo)throw new RuntimeException('My Knowledge storage is unavailable.',503);$uid=(int)$user['id'];
    if($method==='POST'){
        $raw=file_get_contents('php://input',false,null,0,450001);if(strlen($raw)>450000)throw new RuntimeException('Capture exceeds its upload budget.',413);
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Use a sharing request object.');
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh before updating sharing history.',419);
        if((string)($input['owner_key']??'')!==(string)$uid)throw new RuntimeException('Screen account changed. Refresh this page.',409);
    }else{$input=$_GET;if(isset($input['owner_key'])&&(string)$input['owner_key']!==(string)$uid)throw new RuntimeException('Screen account changed. Refresh this page.',409);}
    $action=$input['action']??($method==='GET'?'list':'');
    if(in_array($action,['initiative_status','initiative_set'],true)){
        $write=$action==='initiative_set';
        if($method!==($write?'POST':'GET'))throw new RuntimeException('Invalid method for this screen setting.',405);
        if((string)($input['owner_key']??'')!==(string)$uid)throw new RuntimeException('Screen account changed. Refresh this page.',409);
        $cid=filter_var($input['conversation_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($cid===false)throw new InvalidArgumentException('Choose an existing System Agent Chat.');
        if($write&&(!is_bool($input['enabled']??null)||!is_string($input['expected_revision']??null)
            ||!preg_match('/\A[a-f0-9]{32}\z/',$input['expected_revision']))){
            throw new InvalidArgumentException('Reload the screen follow-up setting before saving.');
        }
        require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
        if(!function_exists('vp3_participant_scope_v1'))require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
        require_once dirname(__DIR__).'/includes/agent-initiative-v1.php';
        $initiative=vp3_initiative_screen_setting_v1($pdo,$user,$cid,$write?$input['enabled']:null,$write?$input['expected_revision']:null);
        echo json_encode(['ok'=>true,'owner_key'=>(string)$uid,'initiative'=>$initiative],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    screen_sessions_permission_v1($user,$method==='POST');
    if($method!=='GET'||$action!=='timeline')screen_sessions_schema_v1($pdo);
    if($method==='GET'){
        if($action==='list'){$q=$pdo->prepare("SELECT * FROM agent_screen_sessions_v1 WHERE owner_user_id=? AND status<>'deleted' ORDER BY updated_at DESC,session_id DESC LIMIT 200");$q->execute([$uid]);$response=['sessions'=>array_map(fn($s)=>screen_session_public_v1($pdo,$user,$s),$q->fetchAll(PDO::FETCH_ASSOC))];}
        elseif($action==='history'){$s=screen_session_v1($pdo,$user,screen_session_id_v1($input['session_id']??null));$q=$pdo->prepare('SELECT owner_user_id,session_id,capture_id,kind,captured_at,knowledge_id,memory_id,revision,body_json FROM agent_screen_captures_v1 WHERE owner_user_id=? AND session_id=? ORDER BY captured_at DESC,capture_id DESC LIMIT 100');$q->execute([$uid,$s['session_id']]);$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['body']=json_decode($r['body_json'],true,32,JSON_THROW_ON_ERROR);unset($r['body_json']);$rows[]=screen_capture_public_v1($r);}$response=['session'=>screen_session_public_v1($pdo,$user,$s),'captures'=>$rows];}
        elseif($action==='timeline')$response=screen_session_timeline_v1($pdo,$user,screen_session_id_v1($input['session_id']??null),$input['before']??null);
        elseif($action==='capture'){$r=screen_capture_v1($pdo,$user,screen_session_id_v1($input['capture_id']??null),true);screen_session_v1($pdo,$user,$r['session_id']);$response=['capture'=>screen_capture_public_v1($r,true)];}
        else throw new InvalidArgumentException('Unknown sharing history action.');
    }elseif($action==='start')$response=['session'=>screen_session_start_v1($pdo,$user,$input)];
    elseif($action==='end'){$s=screen_session_v1($pdo,$user,screen_session_id_v1($input['session_id']??null));$pdo->prepare("UPDATE agent_screen_sessions_v1 SET status='ended',updated_at=? WHERE owner_user_id=? AND session_id=? AND status='active'")->execute([gmdate('Y-m-d\TH:i:s\Z'),$uid,$s['session_id']]);$response=['ended'=>true];}
    elseif($action==='capture'){
        $sid=screen_session_id_v1($input['session_id']??null);$frame=screen_frame_v1(array_intersect_key($input,array_flip(['image','captured_at','surface'])));
        if(array_key_exists('annotation_logging',$input)&&!is_bool($input['annotation_logging']))throw new InvalidArgumentException('Choose whether to log screen annotations.');
        if(($input['annotation_logging']??false)===true)$frame['annotation_logging']=true;
        if(array_key_exists('watch_region',$input))$frame['watch_region']=screen_watch_region_v1($input['watch_region']);
        $frame['note']=screen_text_v1($input['note']??'',1000);
        $r=screen_capture_store_v1($pdo,$user,$sid,screen_session_id_v1($input['capture_id']??null),$frame);$response=['capture'=>screen_capture_public_v1($r)];
    }elseif($action==='analyze'){
        $id=screen_session_id_v1($input['capture_id']??null);$r=screen_capture_v1($pdo,$user,$id);screen_session_v1($pdo,$user,$r['session_id']);$claim=screen_budget_v1($uid);session_write_close();
        $r=screen_capture_analyze_v1($pdo,$user,$id);$response=['capture'=>screen_capture_public_v1($r)];
    }elseif($action==='brain')$response=['capture'=>screen_capture_public_v1(screen_capture_brain_v1($pdo,$user,screen_session_id_v1($input['capture_id']??null)))];
    elseif($action==='delete_session'){
        if(!is_bool($input['remove_brain']??null))throw new InvalidArgumentException('Choose whether to remove this session’s Brain memories.');
        $response=screen_session_delete_v1($pdo,$user,screen_session_id_v1($input['session_id']??null),$input['remove_brain']);
    }else throw new InvalidArgumentException('Unknown sharing session action.');
    $response=['ok'=>true,'owner_key'=>(string)$uid,...$response];
}catch(Throwable $e){$code=(int)$e->getCode();$status=in_array($code,[403,404,405,409,413,419,429,502,503],true)?$code:422;$response=['ok'=>false,'error'=>$e instanceof JsonException?'Invalid sharing request.':($e instanceof PDOException?'Sharing storage is unavailable. Please retry.':$e->getMessage())];}
finally{if($claim!=='')try{screen_budget_v1($uid,$claim);}catch(Throwable $ignored){}}
http_response_code($status);echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
