<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-coordination-profile-v1.php';
require_once __DIR__.'/profile-owner-reply-v1.php';

const VP3_PROFILE_WORKSPACE_V1='vp3.profile-workspace.v1';

function vp3_profile_workspace_id_v1(mixed $value,bool $zero=false): int
{
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D',(string)$value)
        ||(int)$value>9007199254740991||(int)$value<($zero?0:1))throw new RuntimeException('Choose an exact Profile workspace record.',422);
    return (int)$value;
}

function vp3_profile_workspace_text_v1(mixed $value,int $limit,bool $empty=false): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$limit
        ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)||(!$empty&&trim($value)===''))
        throw new RuntimeException('Enter valid Profile text within the allowed length.',422);
    return trim($value);
}

/** Private scope remains valid when the public Profile is disabled or private. */
function vp3_profile_workspace_scope_v1(PDO $pdo,array $user,int $agent,int $chat): array
{
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agent,$chat);
    vp3_profile_attention_dependencies_pa4b();
    $scope['user']=vp3_profile_handoff_owner_pa3($pdo,$scope['user'],$chat,$agent);
    return $scope;
}

function vp3_profile_workspace_fresh_v1(PDO $pdo,array $scope,?callable $sessionGuard=null): array
{
    $user=$sessionGuard!==null?$sessionGuard():$scope['user'];
    if(!is_array($user)||(int)($user['id']??0)!==(int)$scope['user']['id'])throw new RuntimeException('Your Profile session changed.',403);
    return vp3_profile_workspace_scope_v1($pdo,$user,$scope['agent_id'],$scope['conversation_id']);
}

function vp3_profile_workspace_query_v1(PDO $pdo,string $sql,array $params=[]): array
{
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return $rows;
}

function vp3_profile_workspace_turn_v1(PDO $pdo,int $owner,int $conversation,bool $lock=false): ?array
{
    if(!vp3_profile_handoff_schema_ready_pa3($pdo))throw new RuntimeException('Profile controls need a database upgrade.',503);
    return vp3_profile_workspace_query_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY visitor_message_id DESC,id DESC LIMIT 1'
        .($lock?vp3_profile_handoff_lock_pa3($pdo):''),[$owner,$conversation])[0]??null;
}

function vp3_profile_workspace_identity_v1(PDO $pdo,array $conversation): array
{
    $rows=vp3_profile_workspace_query_v1($pdo,'SELECT s.visitor_user_id,s.identity_disclosed,u.display_name FROM profile_visit_sessions s LEFT JOIN users u ON u.id=? WHERE s.id=? AND s.owner_user_id=?',
        [(int)($conversation['visitor_user_id']??0),(int)$conversation['profile_session_id'],(int)$conversation['owner_user_id']]);
    $row=$rows[0]??[];$visitor=(int)($conversation['visitor_user_id']??0);
    $disclosed=$visitor>0&&(int)($row['visitor_user_id']??0)===$visitor&&!empty($row['identity_disclosed']);
    $label=$disclosed?mb_substr(trim((string)($row['display_name']??'')),0,100):'';
    return ['visitor_label'=>$label!==''?$label:($visitor>0?'Signed-in visitor':'Guest visitor'),'identity_disclosed'=>$disclosed];
}

/** Full owned inventory is independent of attention/report suppression. */
function vp3_profile_workspace_conversations_v1(PDO $pdo,array $scope,string $filter='active',string $cursor='',int $limit=20): array
{
    if(!in_array($filter,['active','resolved','all'],true))throw new RuntimeException('Choose a valid conversation filter.',422);
    $owner=(int)$scope['user']['id'];$where='owner_user_id=?';$params=[$owner];
    if($filter!=='all')$where.=$filter==='active'?" AND status IN ('open','owner_joined')":" AND status='resolved'";
    if($cursor!==''){
        if(strlen($cursor)>240||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$cursor))throw new RuntimeException('Refresh the conversation list.',422);
        $decoded=base64_decode(strtr($cursor,'-_','+/'),true);$page=is_string($decoded)?json_decode($decoded,true):null;
        if(!is_array($page)||array_keys($page)!==['owner','agent','chat','filter','at','id']
            ||$page['owner']!==$owner||$page['agent']!==$scope['agent_id']||$page['chat']!==$scope['conversation_id']||$page['filter']!==$filter
            ||!is_string($page['at'])||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$page['at']))throw new RuntimeException('Refresh the conversation list.',422);
        $before=vp3_profile_workspace_id_v1($page['id']);$where.=' AND (last_message_at<? OR (last_message_at=? AND id<?))';
        array_push($params,$page['at'],$page['at'],$before);
    }
    $limit=max(1,min(20,$limit));
    $rows=vp3_profile_workspace_query_v1($pdo,'SELECT * FROM profile_agent_conversations WHERE '.$where.' ORDER BY last_message_at DESC,id DESC LIMIT '.($limit+1),$params);
    $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
    foreach($rows as $row){
        $cid=(int)$row['id'];$turn=vp3_profile_workspace_turn_v1($pdo,$owner,$cid);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid);
        $agent=vp3_profile_workspace_query_v1($pdo,'SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=?',[(int)$row['profile_agent_id'],$owner]);
        $last=vp3_profile_workspace_query_v1($pdo,"SELECT id,message,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','owner','agent') ORDER BY id DESC LIMIT 1",[$cid]);
        require_once __DIR__.'/profile-chat-attachments-v1.php';$last=vp3_profile_chat_attachment_messages_v1($pdo,$last);
        $items[]=['public_conversation_id'=>$cid,'profile_agent_id'=>(int)$row['profile_agent_id'],
            'profile_agent_name'=>mb_substr(trim((string)($agent[0]['display_name']??'Profile Agent')),0,100),
            'status'=>(string)$row['status'],'mode'=>(string)($thread['mode']??($row['status']==='owner_joined'?'owner':'none')),
            'latest_summary'=>mb_substr((string)($last[0]['message']??$row['last_summary']),0,240),'last_message_at'=>(string)$row['last_message_at'],
            'latest_message_id'=>(int)($last[0]['id']??0),'needs_owner'=>$turn!==null&&in_array($turn['state'],['awaiting_delivery','awaiting_owner','owner_requested','owner_claimed'],true)]
            +vp3_profile_workspace_identity_v1($pdo,$row);
    }
    $next=null;
    if($more&&$rows){$last=end($rows);$next=rtrim(strtr(base64_encode(json_encode(['owner'=>$owner,'agent'=>$scope['agent_id'],'chat'=>$scope['conversation_id'],
        'filter'=>$filter,'at'=>(string)$last['last_message_at'],'id'=>(int)$last['id']],JSON_THROW_ON_ERROR)),'+/','-_'),'=');}
    return ['coverage'=>'ready','items'=>$items,'has_more'=>$more,'next_cursor'=>$next];
}

/** This source belongs to the private owner; it never acquires the public responder lease. */
function vp3_profile_workspace_source_v1(PDO $pdo,array $scope,int $cid,bool $lock=false): array
{
    $owner=(int)$scope['user']['id'];$conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$owner,$lock);
    if(!$conversation)throw new RuntimeException('Profile conversation not found.',404);
    $turn=vp3_profile_workspace_turn_v1($pdo,$owner,$cid,$lock);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,$lock);
    $rows=vp3_profile_workspace_query_v1($pdo,"SELECT id,sender_type,sender_user_id,message,context_json,created_at FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','owner','agent') ORDER BY id DESC LIMIT 41",[$cid]);
    $more=count($rows)>40;$rows=array_reverse(array_slice($rows,0,40));
    $card=$turn?vp3_profile_handoff_owner_card_pa3($pdo,$turn,$scope['conversation_id'],$scope['agent_id']):null;
    $bindings=vp3_profile_workspace_query_v1($pdo,"SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id LIMIT 2",[$cid]);
    $publicBinding=count($bindings)===1?json_decode((string)$bindings[0]['context_json'],true):null;
    $accepted=$turn?json_decode((string)$turn['accepted_json'],true):null;
    $visitorMessage=null;
    if($turn){$visitorMessage=vp3_profile_workspace_query_v1($pdo,"SELECT id,message,sender_user_id,context_json FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'",[(int)$turn['visitor_message_id'],$cid])[0]??null;}
    $bound=$turn!==null&&is_array($publicBinding)&&vp3_profile_agent_conversation_authority_valid_v1($publicBinding,$owner)
        &&$publicBinding['transport']==='native'&&(int)($publicBinding['profile_agent_id']??0)===(int)$conversation['profile_agent_id']
        &&(int)($publicBinding['profile_session_id']??0)===(int)$conversation['profile_session_id']
        &&(int)$publicBinding['visitor_user_id']===(int)($conversation['visitor_user_id']??0)
        &&hash_equals((string)$turn['credential_hash'],$publicBinding['credential_hash'])
        &&is_array($accepted)&&($accepted['contract']??null)==='vp3.profile-accepted-turn.pa3'
        &&is_string($accepted['message_sha256']??null)&&$visitorMessage!==null
        &&hash_equals($accepted['message_sha256'],hash('sha256',(string)$visitorMessage['message']))
        &&(int)($visitorMessage['sender_user_id']??0)===(int)$turn['visitor_user_id'];
    $canPrepare=$bound&&($thread['mode']??'')==='owner'&&($card['can_reply']??false)===true
        &&in_array($turn['state'],['owner_claimed','replied'],true);
    $reason=$canPrepare?'':($turn===null?'no_accepted_turn':(!$bound?'source_unavailable':(($thread['mode']??'')!=='owner'?'owner_claim_required':'reply_unavailable')));
    if($canPrepare){
        require_once __DIR__.'/profile-agent-accepted-turn-pa3.php';
        try{vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock,true);}
        catch(VP3ProfileAgentPublicException|VP3ProfileCallException|VP3ProfileHandoffException){$canPrepare=false;$reason='source_unavailable';}
    }
    if($canPrepare){require_once __DIR__.'/profile-owner-drafts-v1.php';if(!vp3_profile_owner_drafts_ready_v1($pdo)){$canPrepare=false;$reason='draft_storage_unavailable';}}
    $agent=$scope['agent']??[];$agentProof=[];
    foreach(['id','display_name','instructions','agent_role','engine_key','is_active'] as $key)$agentProof[$key]=(string)($agent[$key]??'');
    require_once __DIR__.'/profile-chat-attachments-v1.php';
    $projected=vp3_profile_chat_attachment_messages_v1($pdo,$rows);
    require_once __DIR__.'/profile-image-edit-v1.php';
    $projected=vp3_profile_image_edit_messages_v1($pdo,$owner,$cid,$projected,true);
    $sourceHash=hash('sha256',json_encode([$owner,$scope['agent_id'],$scope['conversation_id'],$cid,
        array_intersect_key($conversation,array_flip(['profile_agent_id','profile_session_id','visitor_user_id','status'])),
        $thread?array_intersect_key($thread,array_flip(['mode','epoch','requested_mode','live_status'])):null,
        $turn?array_intersect_key($turn,array_flip(['id','visitor_message_id','version','conversation_epoch','state','accepted_json','credential_hash'])):null,
        $bindings,$rows,$projected,$agentProof],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    $messages=[];
    foreach($projected as $row){$item=['message_id'=>(int)$row['id'],'sender_type'=>(string)$row['sender_type'],
        'text'=>mb_substr((string)$row['message'],0,8000),'created_at'=>(string)$row['created_at']];
        if(isset($row['attachment']))$item['attachment']=$row['attachment']+['url'=>url('/api/profile-agent.php?action=owner_attachment&conversation_id='.$cid.'&message_id='.(int)$row['id'])];
        if(isset($row['image_edit']))$item['image_edit']=$row['image_edit'];
        $messages[]=$item;}
    return ['conversation'=>$conversation,'turn'=>$turn,'thread'=>$thread,'current_turn'=>$card,'messages'=>$messages,
        'history_complete'=>!$more,'source_revision'=>$sourceHash,'latest_message_id'=>$rows?(int)end($rows)['id']:0,
        'can_prepare'=>$canPrepare,'assistance_reason'=>$reason,'agent_proof'=>$agentProof];
}

function vp3_profile_workspace_conversation_v1(PDO $pdo,array $scope,int $cid,int $before=0): array
{
    $source=vp3_profile_workspace_source_v1($pdo,$scope,$cid);$row=$source['conversation'];$messages=$source['messages'];$more=!$source['history_complete'];
    if($before>0){
        $rows=vp3_profile_workspace_query_v1($pdo,"SELECT id,sender_type,message,context_json,created_at FROM profile_agent_messages WHERE conversation_id=? AND id<? AND sender_type IN ('visitor','owner','agent') ORDER BY id DESC LIMIT 41",[$cid,$before]);
        $more=count($rows)>40;$rows=vp3_profile_chat_attachment_messages_v1($pdo,array_reverse(array_slice($rows,0,40)));$messages=[];
        $rows=vp3_profile_image_edit_messages_v1($pdo,(int)$scope['user']['id'],$cid,$rows,true);
        foreach($rows as $message){$item=['message_id'=>(int)$message['id'],'sender_type'=>$message['sender_type'],'text'=>mb_substr($message['message'],0,8000),'created_at'=>$message['created_at']];
            if(isset($message['attachment']))$item['attachment']=$message['attachment']+['url'=>url('/api/profile-agent.php?action=owner_attachment&conversation_id='.$cid.'&message_id='.(int)$message['id'])];
            if(isset($message['image_edit']))$item['image_edit']=$message['image_edit'];$messages[]=$item;}
    }
    $agent=vp3_profile_workspace_query_v1($pdo,'SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=?',[(int)$row['profile_agent_id'],(int)$scope['user']['id']]);
    $turn=$source['turn'];
    return ['public_conversation_id'=>$cid,'conversation'=>[
        'public_conversation_id'=>$cid,'profile_agent_id'=>(int)$row['profile_agent_id'],'profile_agent_name'=>mb_substr((string)($agent[0]['display_name']??'Profile Agent'),0,100),
        'status'=>$row['status'],'mode'=>(string)($source['thread']['mode']??($row['status']==='owner_joined'?'owner':'none')),
        'started_at'=>$row['started_at'],'last_message_at'=>$row['last_message_at'],'latest_message_id'=>$source['latest_message_id'],
        'source_revision'=>$source['source_revision'],'can_manual_reply'=>$turn===null||($source['current_turn']['can_reply']??false)===true]+vp3_profile_workspace_identity_v1($pdo,$row),
        'messages'=>['items'=>$messages,'has_more'=>$more,'next_before_message_id'=>$more&&$messages?$messages[0]['message_id']:null],
        'current_turn'=>$source['current_turn'],'source'=>['source_revision'=>$source['source_revision'],
            'conversation_epoch'=>(int)($source['thread']['epoch']??0),'latest_message_id'=>$source['latest_message_id'],
            'visitor_message_id'=>(int)($turn['visitor_message_id']??0),'history_complete'=>$source['history_complete']],
        'assistance'=>['can_prepare'=>$source['can_prepare'],'reason'=>$source['assistance_reason']]];
}

function vp3_profile_workspace_overview_v1(PDO $pdo,array $scope): array
{
    $empty=vp3_profile_specialist_report_v1(['items'=>[],'coverage'=>['profile'=>'unconfigured']]);
    if($scope['conversation_id']===0)return ['status'=>'save_chat_required','profile'=>null,'coverage'=>'unconfigured','report'=>$empty,
        'conversations'=>['coverage'=>'unconfigured','items'=>[],'has_more'=>false,'next_cursor'=>null]];
    $profile=vp3_profile_workspace_query_v1($pdo,'SELECT p.is_public,p.profile_agent_enabled,p.profile_agent_id,a.display_name FROM user_profiles p LEFT JOIN user_agents a ON a.id=p.profile_agent_id AND a.owner_user_id=p.user_id WHERE p.user_id=?',[(int)$scope['user']['id']]);
    $row=$profile[0]??null;$binding=['owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],'enabled'=>false];
    try{$snapshot=vp3_coordination_profile_sources_v1($pdo,$scope,$binding,null,new DateTimeImmutable('now',new DateTimeZone('UTC')));}
    catch(Throwable $error){if($error->getCode()===403)throw $error;$snapshot=['items'=>[],'coverage'=>'unavailable'];}
    $report=vp3_profile_specialist_report_v1(['items'=>$snapshot['items'],'coverage'=>['profile'=>$snapshot['coverage']]]);
    return ['status'=>'ready','profile'=>$row?['is_public'=>(bool)$row['is_public'],'enabled'=>(bool)$row['profile_agent_enabled'],
        'profile_agent_id'=>(int)$row['profile_agent_id'],'profile_agent_name'=>mb_substr((string)($row['display_name']??'Profile Agent'),0,100)]:null,
        'coverage'=>$snapshot['coverage'],'report'=>$report,'conversations'=>vp3_profile_workspace_conversations_v1($pdo,$scope,'active','',10)];
}

/** Exact canonical receipt replay is checked before any current-source rejection. */
function vp3_profile_workspace_action_v1(PDO $pdo,array $scope,array $input,?callable $sessionGuard=null): array
{
    $cid=vp3_profile_workspace_id_v1($input['public_conversation_id']??null);$turnId=vp3_profile_workspace_id_v1($input['turn_id']??null,true);
    $revision=vp3_profile_workspace_id_v1($input['revision']??null,true);$request=vp3_profile_owner_reply_request_v1($input['request_id']??null);
    $operation=$input['operation']??null;if(!in_array($operation,['claim','reply','delegate','resolve'],true))throw new RuntimeException('Choose a valid visitor operation.',422);
    $message=$operation==='reply'?vp3_profile_workspace_text_v1($input['message']??null,4000):'';
    if(($turnId===0)!==($revision===0)||($turnId===0&&$operation!=='reply'))throw new RuntimeException('These older conversations support manual replies.',422);
    if(isset($input['prepare_request_id'])&&($turnId===0||$operation!=='reply'))throw new RuntimeException('A reviewed draft requires its exact accepted visitor turn.',422);
    $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$sessionGuard);$owner=(int)$scope['user']['id'];
    if(!$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $result=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$scope,$cid,$turnId,$revision,$request,$operation,$message,$input,$owner,$sessionGuard){
        if($turnId===0){
            $live=vp3_profile_owner_reply_lock_v1($pdo,$scope['user']);$conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$owner,true);
            if(!$conversation)throw new RuntimeException('Profile conversation not found.',404);
            $receipt=vp3_profile_owner_reply_recover_v1($pdo,$live,$cid,$request,$message);
            if($receipt===null){
                if(vp3_profile_workspace_turn_v1($pdo,$owner,$cid,true)!==null)throw new RuntimeException('This thread now has a current visitor turn. Refresh before sending.',409);
                $receipt=vp3_profile_owner_reply_legacy_save_v1($pdo,$live,$conversation,$request,$message);
            }
            vp3_profile_workspace_fresh_v1($pdo,$scope,$sessionGuard);return $receipt;
        }
        $prior=vp3_profile_workspace_query_v1($pdo,'SELECT request_hash FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND request_id=?',[$owner,$request]);
        if(!$prior&&isset($input['prepare_request_id'])){
            if($operation!=='reply')throw new RuntimeException('A reviewed draft can only accompany a reply.',422);
            require_once __DIR__.'/profile-owner-drafts-v1.php';
            vp3_profile_owner_draft_send_guard_v1($pdo,$scope,$input,$cid,$turnId,$revision);
        }
        $receipt=vp3_profile_handoff_owner_action_pa3($pdo,$scope['user'],['operation'=>$operation,'request_id'=>$request,
            'turn_id'=>$turnId,'revision'=>$revision,'conversation_id'=>$cid,'message'=>$message,
            'chat_conversation_id'=>$scope['conversation_id'],'chat_agent_id'=>$scope['agent_id']]);
        if(isset($input['prepare_request_id'])){
            require_once __DIR__.'/profile-owner-drafts-v1.php';
            vp3_profile_owner_draft_sent_v1($pdo,$scope,$input['prepare_request_id'],$cid,$request,$message,(int)$receipt['message_id']);
        }
        vp3_profile_workspace_fresh_v1($pdo,$scope,$sessionGuard);
        return $receipt;
    });
    $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$sessionGuard);
    return vp3_profile_workspace_conversation_v1($pdo,$scope,$cid)+['request_id'=>$request,'operation'=>$operation,
        'duplicate'=>$result['duplicate']===true,'message_id'=>(int)($result['message_id']??0)];
}

/** Endpoint's only dispatcher. Optional readers never grant wider authority. */
function vp3_profile_workspace_request_v1(PDO $pdo,array $user,string $method,array $input,string $binding,
    ?callable $sessionGuard=null,?callable $model=null): array
{
    $action=$input['action']??'overview';
    $reads=['overview','conversations','conversation','current_turn','draft_status','analytics','analytics_period','revenue','calls','call_activity','call_outcome'];
    $writes=['conversation_action','draft_prepare','call_set_assistance','call_research'];
    if(!is_string($action)||!in_array($action,array_merge($reads,$writes),true))throw new RuntimeException('Choose a valid Profile workspace action.',422);
    if($method!==(in_array($action,$reads,true)?'GET':'POST'))throw new RuntimeException('Use the correct method for this Profile action.',405);
    if($binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding))throw new RuntimeException('Your Profile session changed.',403);
    $allowed=match($action){
        'conversations'=>['filter','cursor'],'conversation'=>['public_conversation_id','before_message_id'],
        'current_turn'=>['public_conversation_id'],'draft_status'=>['public_conversation_id','prepare_request_id','latest'],
        'conversation_action'=>['public_conversation_id','turn_id','revision','operation','request_id','message','prepare_request_id'],
        'draft_prepare'=>['public_conversation_id','turn_id','revision','source_revision','prepare_request_id','instruction','supersedes_request_id'],
        'analytics'=>['property_id','days'],'analytics_period'=>['ref','period'],'calls'=>['public_conversation_id','call_id','filter','cursor'],'call_activity'=>['public_conversation_id','call_id','after'],
        'call_outcome'=>['public_conversation_id','call_id','section','cursor'],
        'call_set_assistance'=>['public_conversation_id','call_id','enabled','assistance_revision'],
        'call_research'=>['public_conversation_id','call_id','request_id','query'],default=>[],
    };
    if(array_diff(array_keys($input),array_merge(['action','chat_agent_id','chat_conversation_id','csrf_token'],$allowed)))throw new RuntimeException('Unexpected Profile request fields.',422);
    $agent=vp3_profile_workspace_id_v1($input['chat_agent_id']??null,true);$chat=vp3_profile_workspace_id_v1($input['chat_conversation_id']??null,true);
    $scope=vp3_profile_workspace_scope_v1($pdo,$user,$agent,$chat);
    if($chat===0&&$action!=='overview')throw new RuntimeException('Save this Chat before opening the Profile workspace.',422);
    $cid=isset($input['public_conversation_id'])?vp3_profile_workspace_id_v1($input['public_conversation_id']):0;
    if(in_array($action,['conversation','current_turn','draft_status','conversation_action','draft_prepare'],true)&&$cid===0)throw new RuntimeException('Choose an exact visitor conversation.',422);
    if(in_array($action,['draft_prepare','draft_status'],true))require_once __DIR__.'/profile-owner-drafts-v1.php';
    if(in_array($action,['analytics','revenue'],true))require_once __DIR__.'/profile-specialist-analytics-v1.php';
    if($action==='analytics_period')require_once __DIR__.'/profile-analytics-period-v1.php';
    if(in_array($action,['calls','call_activity','call_outcome','call_set_assistance','call_research'],true))require_once __DIR__.'/profile-workspace-calls-v1.php';
    $data=match($action){
        'overview'=>vp3_profile_workspace_overview_v1($pdo,$scope),
        'conversations'=>vp3_profile_workspace_conversations_v1($pdo,$scope,vp3_profile_workspace_text_v1($input['filter']??'active',8),vp3_profile_workspace_text_v1($input['cursor']??'',240,true)),
        'conversation','current_turn'=>vp3_profile_workspace_conversation_v1($pdo,$scope,$cid,vp3_profile_workspace_id_v1($input['before_message_id']??0,true)),
        'conversation_action'=>vp3_profile_workspace_action_v1($pdo,$scope,$input,$sessionGuard),
        'draft_prepare'=>['public_conversation_id'=>$cid,'draft'=>vp3_profile_owner_draft_prepare_v1($pdo,$scope,$input,$sessionGuard,$model)],
        'draft_status'=>['public_conversation_id'=>$cid,'draft'=>vp3_profile_owner_draft_status_v1($pdo,$scope,$cid,$input)],
        'analytics'=>['analytics'=>vp3_profile_specialist_analytics_v1($pdo,$scope,vp3_profile_workspace_id_v1($input['property_id']??0,true),vp3_profile_workspace_id_v1($input['days']??30))],
        'revenue'=>['revenue'=>vp3_profile_specialist_revenue_v1($pdo,$scope)],
        'analytics_period'=>['analytics_period'=>vp3_profile_analytics_period_v1($pdo,$scope,array_intersect_key($input,array_flip(['ref','period'])))],
        'calls'=>['calls'=>vp3_profile_workspace_calls_v1($pdo,$scope,array_filter(['conversation_id'=>$cid,'call_id'=>isset($input['call_id'])?vp3_profile_workspace_id_v1($input['call_id']):0]),
            vp3_profile_workspace_text_v1($input['filter']??'current',7),vp3_profile_workspace_text_v1($input['cursor']??'',2048,true))],
        'call_activity'=>['call_activity'=>vp3_profile_workspace_call_activity_v1($pdo,$scope,vp3_profile_workspace_id_v1($input['call_id']??null),vp3_profile_workspace_id_v1($input['after']??0,true),$cid)],
        'call_outcome'=>['call_outcome'=>vp3_profile_workspace_call_outcome_v1($pdo,$scope,vp3_profile_workspace_id_v1($input['call_id']??null),$cid,
            vp3_profile_workspace_text_v1($input['section']??'all',10),vp3_profile_workspace_text_v1($input['cursor']??'',2048,true))],
        'call_set_assistance','call_research'=>['call_action'=>vp3_profile_workspace_call_action_v1($pdo,$scope,vp3_profile_workspace_id_v1($input['call_id']??null),$action==='call_set_assistance'?'set_assistance':'research',
            array_intersect_key($input,array_flip($action==='call_set_assistance'?['enabled','assistance_revision']:['query','request_id'])),$cid)],
    };
    if($cid>0&&in_array($action,['call_activity','call_outcome','call_set_assistance','call_research'],true)){
        $value=$data[in_array($action,['call_activity','call_outcome'],true)?$action:'call_action'];
        if(($value['public_conversation_id']??null)!==$cid)throw new RuntimeException('The selected call belongs to another visitor conversation.',409);
    }
    // Do not move this check into a source reader's snapshot transaction.
    $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$sessionGuard);
    return ['contract'=>VP3_PROFILE_WORKSPACE_V1,'action'=>$action,'scope'=>['owner_user_id'=>(int)$scope['user']['id'],
        'chat_agent_id'=>$agent,'chat_conversation_id'=>$chat],'working_attention_binding'=>$binding,'observed_at'=>gmdate('c'),'data'=>$data];
}
