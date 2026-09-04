<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function profile_agent_json(bool $ok,array $payload=[],int $status=200): never{http_response_code($status);echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function profile_agent_require_csrf(array $input): void{$token=(string)($input['csrf_token']??'');if($token===''||!hash_equals(csrf_token(),$token))profile_agent_json(false,['error'=>'Session expired. Refresh and try again.'],419);}

$pdo=db();if(!$pdo||!profile_agent_schema_ready($pdo))profile_agent_json(false,['error'=>'Profile Agent is not ready. Run /upgrade.php.'],503);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?json_decode((string)file_get_contents('php://input'),true):$_GET;if(!is_array($input))$input=$_POST;
$action=trim((string)($input['action']??'state'));$user=current_user();
$ownerActions=['owner_state','save_profile','save_profile_agent','save_profile_access','attention_action','conversation_messages','owner_reply'];

try{
if(in_array($action,$ownerActions,true)){
    if(!$user||!has_permission('account.access',$user))profile_agent_json(false,['error'=>'Sign in to manage your Profile Agent.'],401);
    $uid=(int)$user['id'];if($method==='POST')profile_agent_require_csrf($input);
    if($action==='owner_state')profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);
    if($action==='save_profile'){profile_save($pdo,$user,$input);profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);}
    if($action==='save_profile_agent'){profile_configure_agent($pdo,$user,$input);profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);}
    if($action==='save_profile_access'){
        $type=(string)($input['resource_type']??'');if(!isset(user_agent_resource_catalog_v236()[$type]))throw new RuntimeException('Unknown profile data type.');
        $current=user_data_policy_get_v236($pdo,$uid,$type);
        user_data_policy_save_v236($pdo,$user,['resource_type'=>$type,'resource_id'=>'*','audience_scope'=>(string)($input['audience_scope']??$current['audience_scope']),'owner_agents_allowed'=>!empty($current['owner_agents_allowed']),'profile_agent_allowed'=>!empty($input['profile_agent_allowed']),'stonefellow_shared'=>!empty($current['stonefellow_shared'])]);
        profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);
    }
    if($action==='attention_action'){profile_attention_update($pdo,$uid,(int)($input['attention_id']??0),(string)($input['attention_action']??''));profile_agent_json(true,['attention'=>profile_runtime_attention_list($pdo,$uid,20)]);}
    if($action==='conversation_messages'){
        $cid=max(0,(int)($input['conversation_id']??0));$conversation=profile_runtime_conversation_owner($pdo,$cid,$uid);if(!$conversation)profile_agent_json(false,['error'=>'Conversation not found.'],404);
        profile_agent_json(true,['conversation'=>$conversation,'messages'=>profile_agent_messages($pdo,$cid)]);
    }
    if($action==='owner_reply'){
        $cid=max(0,(int)($input['conversation_id']??0));$reply=trim((string)($input['message']??''));if($reply===''||mb_strlen($reply)>4000)throw new RuntimeException('Enter a reply up to 4,000 characters.');
        $conversation=profile_runtime_conversation_owner($pdo,$cid,$uid);if(!$conversation)profile_agent_json(false,['error'=>'Conversation not found.'],404);
        $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES (?,'owner',?,?)")->execute([$cid,$uid,$reply]);
        $pdo->prepare("UPDATE profile_agent_conversations SET status='owner_joined',last_summary=?,last_message_at=NOW() WHERE id=? AND owner_user_id=?")->execute([mb_strimwidth($reply,0,900,'…'),$cid,$uid]);
        $pdo->prepare("UPDATE agent_attention_items SET status='handled',snoozed_until=NULL WHERE owner_user_id=? AND source_conversation_id=? AND status IN ('pending','seen','snoozed')")->execute([$uid,$cid]);
        profile_agent_json(true,['messages'=>profile_agent_messages($pdo,$cid),'attention'=>profile_runtime_attention_list($pdo,$uid,20)]);
    }
}

$username=profile_username_normalize((string)($input['username']??''));$profile=profile_by_username($pdo,$username);
if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))profile_agent_json(false,['error'=>'That profile is not available.'],404);
$owner=(int)$profile['user_id'];$visitor=$user;if((int)($visitor['id']??0)===$owner)profile_agent_json(false,['error'=>'Use visitor preview from your Profile Agent dashboard.'],403);
$agent=profile_active_agent($pdo,$profile);if(!$agent)profile_agent_json(false,['error'=>'This Profile Agent is not available.'],404);
if($method==='POST'&&!profile_chat_token_valid($owner,(string)($input['profile_token']??'')))profile_agent_json(false,['error'=>'Profile session expired. Refresh and try again.'],419);
$session=profile_runtime_session($pdo,$owner,$visitor,false);

if($action==='state'){
    $cid=max(0,(int)($input['conversation_id']??0));$messages=[];if($cid>0){$c=profile_agent_conversation_get($pdo,$cid,$owner,(int)$session['id']);if($c)$messages=profile_agent_messages($pdo,$cid);}
    profile_agent_json(true,['agent'=>['id'=>(int)$agent['id'],'name'=>(string)$agent['display_name'],'system_name'=>system_agent_name(),'greeting'=>trim((string)($profile['profile_agent_greeting']??''))],'messages'=>$messages]);
}
if($method!=='POST')profile_agent_json(false,['error'=>'POST is required.'],405);
if($action==='message'){
    $query=trim((string)($input['message']??''));if($query===''||mb_strlen($query)>2000)throw new RuntimeException('Enter a message up to 2,000 characters.');
    $cid=max(0,(int)($input['conversation_id']??0));$conversation=$cid?profile_agent_conversation_get($pdo,$cid,$owner,(int)$session['id']):null;if(!$conversation)$conversation=profile_agent_conversation_create($pdo,$profile,$agent,$session);$cid=(int)$conversation['id'];profile_agent_rate_check($pdo,$cid);
    $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES (?,'visitor',?,?)")->execute([$cid,(int)($visitor['id']??0)?:null,$query]);$pdo->prepare('UPDATE profile_visit_sessions SET last_message_at=NOW() WHERE id=?')->execute([(int)$session['id']]);
    $history=[];foreach(profile_agent_messages($pdo,$cid,14) as $m){if($m['sender_type']==='visitor')$history[]=['role'=>'user','message'=>(string)$m['message']];elseif(in_array($m['sender_type'],['agent','owner'],true))$history[]=['role'=>'assistant','message'=>(string)$m['message']];}
    $context=profile_agent_context($pdo,$profile,$agent,$visitor,$query);$substantive=array_values(array_filter($context,static fn(array $c):bool=>!in_array((string)$c['source'],['profile:identity','profile:rules'],true)));$greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    if(!$substantive&&!$greeting){profile_agent_needs_owner($pdo,$profile,$agent,$session,$conversation,$query);$answer='I don’t have approved information to answer that accurately yet. I’ve asked '.(string)$profile['display_name'].' for input rather than guessing.';}
    elseif($greeting){$answer=trim((string)($profile['profile_agent_greeting']??''))?:'Hi — I’m '.(string)$agent['display_name'].', '.(string)$profile['display_name'].'’s AI representative. What would you like to know?';}
    else{$answer=chat_remote_answer($query,$history,$context,profile_user_row($pdo,$owner)?:$profile);if($answer===null)$answer=chat_local_answer($query,$context);if(trim((string)$answer)===''){profile_agent_needs_owner($pdo,$profile,$agent,$session,$conversation,$query);$answer='I don’t have enough approved information to answer that accurately.';}}
    $sources=[];foreach($context as $c){if(!in_array((string)$c['source'],['profile:identity','profile:rules'],true))$sources[]=['source'=>(string)$c['source'],'title'=>(string)$c['title']];}
    $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message,context_json) VALUES (?,'agent',NULL,?,?)")->execute([$cid,$answer,json_encode(['sources'=>$sources],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$pdo->prepare('UPDATE profile_agent_conversations SET last_summary=?,last_message_at=NOW() WHERE id=?')->execute([mb_strimwidth($query,0,900,'…'),$cid]);
    profile_agent_json(true,['conversation_id'=>$cid,'answer'=>$answer,'sources'=>$sources,'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()]]);
}
if($action==='poll'){$cid=max(0,(int)($input['conversation_id']??0));$after=max(0,(int)($input['after_id']??0));if(!profile_agent_conversation_get($pdo,$cid,$owner,(int)$session['id']))profile_agent_json(false,['error'=>'Conversation not found.'],404);$s=$pdo->prepare('SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 50');$s->execute([$cid,$after]);profile_agent_json(true,['messages'=>$s->fetchAll()?:[]]);}
profile_agent_json(false,['error'=>'Unknown Profile Agent action.'],404);
}catch(Throwable $e){profile_agent_json(false,['error'=>$e->getMessage()],400);}
