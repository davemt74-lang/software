<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/profile-calls-pa3c.php';

/** Private Chat authority is independent from the public Profile responder. */
function vp3_profile_workspace_call_scope_v1(PDO $pdo,array $scope): array
{
    $user=$scope['user']??null;$agent=$scope['agent_id']??null;$chat=$scope['conversation_id']??null;
    if(!is_array($user)||!is_int($agent)||$agent<0||!is_int($chat)||$chat<1)
        throw new RuntimeException('Private Profile workspace access is required.',403);
    $fresh=vp3_agent_activity_scope_v1($pdo,$user,$agent,$chat);
    $fresh['user']=vp3_profile_handoff_owner_pa3($pdo,$fresh['user'],$chat,$agent);
    return $fresh;
}

function vp3_profile_workspace_call_row_v1(PDO $pdo,array $scope,int $id): array
{
    if($id<1)throw new InvalidArgumentException('Select an exact Profile call.');
    if(!vp3_profile_call_schema_ready_pa3c($pdo))throw new RuntimeException('Profile calls are unavailable.',503);
    $row=vp3_profile_call_row_pa3c($pdo,$id);
    if(!$row||(int)$row['owner_user_id']!==(int)$scope['user']['id'])throw new RuntimeException('Profile call not found.',404);
    vp3_profile_call_integrity_pa3c($row);
    if(!in_array($row['state'],['offered','preparing','ready','active','ending','ended','declined','cancelled','expired','failed'],true)
        ||!in_array($row['mode'],['audio','video'],true)||!in_array($row['offered_by'],['owner','visitor'],true)||(int)$row['revision']<1)
        throw new RuntimeException('This Profile call changed.',409);
    if(in_array($row['state'],['ended','declined','cancelled','expired','failed'],true)
        &&((int)($row['ended_at_ms']??0)<1||(int)$row['ended_at_ms']<(int)$row['created_at_ms']))
        throw new RuntimeException('This Profile call outcome could not be verified.',409);
    vp3_profile_call_context_pa3c($pdo,(int)$scope['user']['id'],(int)$row['conversation_id'],false,$row);
    return $row;
}

/** Safe view data only. Native Chat remains the sole owner of call controls. */
function vp3_profile_workspace_call_card_v1(PDO $pdo,array $scope,array $row): array
{
    $input=['chat_conversation_id'=>$scope['conversation_id'],'chat_agent_id'=>$scope['agent_id']];
    $card=vp3_profile_call_owner_card_pa3c($pdo,$row,$input);
    $message=0;
    if($card['delivery_id']!==''){
        $delivery=vp3_profile_call_delivery_check_pa3c($pdo,$row,$input+['delivery_id'=>$card['delivery_id']]);
        $q=$pdo->prepare('SELECT role,user_id FROM chat_messages WHERE id=? AND conversation_id=?');
        $q->execute([(int)$delivery['chat_message_id'],$scope['conversation_id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
        if(!$saved||$saved['role']!=='assistant'||$saved['user_id']!==null)throw new RuntimeException('The original call card changed.',409);
        $message=(int)$delivery['chat_message_id'];
    }
    $label=match($row['state']){
        'offered'=>$card['state']==='expired'?'Offer expired':'Call requested',
        'preparing'=>'Preparing connection','ready'=>'Connection prepared',
        'active'=>$card['connection_verified']?'Call active':'Connection awaiting verification',
        'ending'=>'Call cleanup pending','ended'=>'Call ended','declined'=>'Call declined',
        'cancelled'=>'Call cancelled','expired'=>'Offer expired',default=>'Call could not connect',
    };
    return ['call_id'=>(int)$row['id'],'public_conversation_id'=>(int)$row['conversation_id'],
        'revision'=>(int)$row['revision'],'mode'=>(string)$row['mode'],'state'=>(string)$row['state'],'state_label'=>$label,
        'detail_kind'=>in_array($row['state'],['ended','declined','cancelled','expired','failed'],true)?'outcome':'current',
        'visitor_label'=>$card['visitor_label'],'agent_name'=>$card['agent_name'],
        'assisted'=>!empty($row['assisted']),'activity_available'=>!empty($row['assisted'])&&$row['mode']==='audio'
            &&has_permission('artist_listening.access',$scope['user'])&&vp3_profile_call_activity_schema_ready_pa3c($pdo),
        'created_at_ms'=>(int)$row['created_at_ms'],'accepted_at_ms'=>$row['accepted_at_ms']===null?null:(int)$row['accepted_at_ms'],
        'ended_at_ms'=>$row['ended_at_ms']===null?null:(int)$row['ended_at_ms'],'chat_message_id'=>$message];
}

function vp3_profile_workspace_call_read_v1(PDO $pdo,array $scope,int $id): array
{
    $row=vp3_profile_workspace_call_row_v1($pdo,$scope,$id);$card=vp3_profile_workspace_call_card_v1($pdo,$scope,$row);
    $again=vp3_profile_workspace_call_row_v1($pdo,$scope,$id);
    if($card!==vp3_profile_workspace_call_card_v1($pdo,$scope,$again))throw new RuntimeException('This Profile call changed. Refresh its current view.',409);
    return $card;
}

/** Current calls and terminal history are independent of native Chat delivery. */
function vp3_profile_workspace_calls_v1(PDO $pdo,array $scope,array $selection=[],string $filter='current',string $cursor=''): array
{
    foreach($selection as $key=>$value)if(!in_array($key,['conversation_id','call_id'],true)||!is_int($value)||$value<0)
        throw new InvalidArgumentException('Invalid Profile call selection.');
    $scope=vp3_profile_workspace_call_scope_v1($pdo,$scope);
    if(!in_array($filter,['current','history'],true))throw new RuntimeException('Choose a valid call filter.',422);
    if(!vp3_profile_call_schema_ready_pa3c($pdo))throw new RuntimeException('Profile calls are unavailable.',503);
    $owner=(int)$scope['user']['id'];$conversation=$selection['conversation_id']??0;$selected=$selection['call_id']??0;
    if($conversation>0)vp3_profile_call_context_pa3c($pdo,$owner,$conversation);
    $binding=['owner'=>$owner,'agent'=>$scope['agent_id'],'chat'=>$scope['conversation_id'],'conversation'=>$conversation,'filter'=>$filter];
    $sql="SELECT c.id FROM profile_calls_pa3c c WHERE c.owner_user_id=? AND c.state IN (".
        ($filter==='current'?"'offered','preparing','ready','active','ending'":"'ended','declined','cancelled','expired','failed'").')';
    $args=[$owner];
    if($conversation>0){$sql.=' AND c.conversation_id=?';$args[]=$conversation;}
    if($cursor!==''){
        $page=vp3_profile_call_outcome_decode_v1($cursor);
        if(array_keys($page)!==['binding','before']||$page['binding']!==$binding||!is_int($page['before'])||$page['before']<1)
            throw new RuntimeException('Refresh the call list.',422);
        $sql.=' AND c.id<?';$args[]=$page['before'];
    }
    $q=$pdo->prepare($sql.' ORDER BY c.id DESC LIMIT 21');$q->execute($args);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    $more=count($ids)>20;$pageIds=array_slice($ids,0,20);
    $out=['calls'=>[],'coverage'=>$more?'limited':'ready','has_more'=>$more,'selected'=>null,'filter'=>$filter,
        'next_cursor'=>$more?vp3_profile_call_outcome_encode_v1(['binding'=>$binding,'before'=>(int)end($pageIds)]):null];
    foreach(array_slice($ids,0,20) as $id){
        try{$out['calls'][]=vp3_profile_workspace_call_read_v1($pdo,$scope,(int)$id);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){if(!in_array($error->getCode(),[403,404,409,422],true))throw $error;$out['coverage']='limited';}
    }
    if($selected>0){
        $out['selected']=vp3_profile_workspace_call_read_v1($pdo,$scope,$selected);
        if($conversation>0&&$out['selected']['public_conversation_id']!==$conversation)throw new RuntimeException('The selected call belongs to a different visitor conversation.',404);
    }
    vp3_profile_workspace_call_scope_v1($pdo,$scope);
    return $out;
}

require_once __DIR__.'/profile-call-outcomes-v1.php';

/** Authenticate the stored recording itself, not just the assistance pointer. */
function vp3_profile_workspace_call_transcript_v1(PDO $pdo,array $scope,array $call): int
{
    if(!has_permission('artist_listening.access',$scope['user']))throw new RuntimeException('Call activity is unavailable for this Agent.',403);
    if(!vp3_profile_call_activity_schema_ready_pa3c($pdo))throw new RuntimeException('Profile call activity is unavailable.',503);
    $link=vp3_profile_call_activity_row_pa3c($pdo,(string)$call['public_id']);$id=(int)($link['transcript_session_id']??0);
    if($id<1)return 0;
    require_once __DIR__.'/transcript-agent-v1.php';
    $session=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],$id);
    $raw=$session['metadata_json']??null;
    $meta=is_string($raw)&&strlen($raw)<=32768?json_decode($raw,true):null;
    if(($session['discarded_at']??null)!==null||!is_array($meta)||array_is_list($meta)
        ||($meta['source']??null)!=='profile_call_pa3c'||($meta['profile_call_id']??null)!==$call['public_id'])
        throw new RuntimeException('This call recording is unavailable.',404);
    return $id;
}

function vp3_profile_workspace_call_activity_v1(PDO $pdo,array $scope,int $callId,int $after=0,int $publicConversationId=0): array
{
    if($after<0)throw new InvalidArgumentException('Invalid call activity cursor.');
    $scope=vp3_profile_workspace_call_scope_v1($pdo,$scope);$row=vp3_profile_workspace_call_row_v1($pdo,$scope,$callId);
    if($publicConversationId<0||($publicConversationId>0&&(int)$row['conversation_id']!==$publicConversationId))
        throw new RuntimeException('The selected call belongs to a different visitor conversation.',404);
    if(empty($row['assisted'])||$row['mode']!=='audio')throw new RuntimeException('Agent activity is not available for this call.',404);
    $session=vp3_profile_workspace_call_transcript_v1($pdo,$scope,$row);
    $activity=vp3_profile_call_activity_view_pa3c($pdo,$row,$after);
    $again=vp3_profile_workspace_call_row_v1($pdo,$scope,$callId);
    if((int)$again['revision']!==(int)$row['revision']||vp3_profile_workspace_call_transcript_v1($pdo,$scope,$again)!==$session)
        throw new RuntimeException('This call activity changed. Refresh its current view.',409);
    vp3_profile_workspace_call_scope_v1($pdo,$scope);
    return ['call_id'=>$callId,'public_conversation_id'=>(int)$row['conversation_id'],'activity'=>$activity];
}

/** Only explicit workspace actions reach these existing canonical mutations. */
function vp3_profile_workspace_call_action_v1(PDO $pdo,array $scope,int $callId,string $operation,array $input,int $publicConversationId=0): array
{
    $keys=array_keys($input);sort($keys);
    if($operation==='set_assistance'){
        if($keys!==['assistance_revision','enabled']||!is_bool($input['enabled'])||!is_int($input['assistance_revision'])||$input['assistance_revision']<1)
            throw new InvalidArgumentException('Invalid assistance change.');
    }elseif($operation==='research'){
        if($keys!==['query','request_id']||!is_string($input['query'])||trim($input['query'])===''||mb_strlen(trim($input['query']))>500
            ||!is_string($input['request_id'])||!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D',$input['request_id']))
            throw new InvalidArgumentException('Invalid call research request.');
    }else throw new InvalidArgumentException('Unknown call activity action.');
    $scope=vp3_profile_workspace_call_scope_v1($pdo,$scope);$row=vp3_profile_workspace_call_row_v1($pdo,$scope,$callId);
    if($publicConversationId<0||($publicConversationId>0&&(int)$row['conversation_id']!==$publicConversationId))
        throw new RuntimeException('The selected call belongs to a different visitor conversation.',404);
    if(empty($row['assisted'])||$row['mode']!=='audio')throw new RuntimeException('Agent activity is not available for this call.',404);
    $session=vp3_profile_workspace_call_transcript_v1($pdo,$scope,$row);$public=(string)$row['public_id'];
    $result=$operation==='set_assistance'
        ?vp3_profile_call_assistance_toggle_pa3c($pdo,(int)$scope['user']['id'],$public,$input['enabled'],$input['assistance_revision'])
        :vp3_profile_call_research_pa3c($pdo,$scope['user'],$public,$input['request_id'],$input['query']);
    $scope=vp3_profile_workspace_call_scope_v1($pdo,$scope);$again=vp3_profile_workspace_call_row_v1($pdo,$scope,$callId);
    if(vp3_profile_workspace_call_transcript_v1($pdo,$scope,$again)!==$session)
        throw new RuntimeException('This call recording changed. Refresh its current view.',409);
    return ['call_id'=>$callId,'public_conversation_id'=>(int)$row['conversation_id'],'operation'=>$operation,'result'=>$result];
}
