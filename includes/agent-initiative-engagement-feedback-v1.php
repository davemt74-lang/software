<?php
declare(strict_types=1);

/** Read only canonical owner decision events for this exact Agent. */
function vp3_initiative_engagement_events_v1(PDO $pdo,int $owner,int $agentId,?int $cid,int $limit,?string $since=null,array $checkInPrimaries=[]): array
{
    if($owner<1||$agentId<0||($cid!==null&&$cid<1))return [];
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $jsonGoal="CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END";
    $jsonEvent="CASE WHEN JSON_VALID(e.metadata_json) THEN e.metadata_json ELSE '{}' END";
    $intent="JSON_EXTRACT($jsonEvent,'$.intent')";
    if(!$sqlite)$intent="JSON_UNQUOTE($intent)";
    $scope=$agentId===0?'(r.agent_id IS NULL OR r.agent_id=0)':'r.agent_id=?';
    $params=[$owner];
    if($agentId>0)$params[]=$agentId;
    if($cid!==null){
        $scope.=" AND CAST(JSON_EXTRACT($jsonGoal,'$.binding.conversation_id') AS ".($sqlite?'INTEGER':'UNSIGNED').')=?';
        $params[]=$cid;
    }
    if($since!==null){$scope.=' AND e.created_at>=?';$params[]=$since;}
    if($checkInPrimaries){
        $tool="JSON_EXTRACT($jsonGoal,'$.tool')";$primary="JSON_EXTRACT($jsonGoal,'$.primary')";
        if(!$sqlite){$tool="JSON_UNQUOTE($tool)";$primary="JSON_UNQUOTE($primary)";}
        $scope.=" AND $tool='chat.check_in' AND $primary IN (".implode(',',array_fill(0,count($checkInPrimaries),'?')).')';
        $params=array_merge($params,$checkInPrimaries);
    }
    try{
        $q=$pdo->prepare("SELECT e.id,e.run_id,e.metadata_json,r.goal FROM agent_workflow_events e
            JOIN agent_workflow_runs r ON r.id=e.run_id AND r.owner_user_id=e.owner_user_id
            WHERE e.owner_user_id=? AND $scope AND e.event_type='initiative_owner_decision' AND e.actor_kind='owner'
              AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare' AND r.status='completed'
              AND $intent IN ('discuss','advance','defer','decline','resolve')
            ORDER BY e.id DESC LIMIT ".max(1,min(32,$limit)));
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $error){return [];}
}

function vp3_initiative_youtube_interest_ref_v1(string $source): ?string
{
    if(!preg_match('/\A(?:youtube-interest-checkin:(artist|topic):|(artist|topic)-interest:)([a-f0-9]{64})\z/D',$source,$match))return null;
    return ($match[1]!==''?$match[1]:$match[2]).'-interest:'.$match[3];
}

/** This wake-up key contains no owner text and does not reopen a source. */
function vp3_initiative_engagement_revision_v1(PDO $pdo,array $user,array $binding): string
{
    $cid=(int)($binding['conversation_id']??0);
    $events=vp3_initiative_engagement_events_v1($pdo,(int)($user['id']??0),(int)($binding['agent_id']??0),$cid,1);
    return isset($events[0]['id'])?(string)$events[0]['id']:'';
}

/** Reuse the immutable origin receipt, not the current evidence lookup. The caller owns current source/consent checks. */
function vp3_initiative_youtube_interest_feedback_v1(PDO $pdo,array $user,int $agentId,array $interestRefs,?DateTimeImmutable $now=null,?array &$decisions=null): array
{
    if($decisions!==null)$decisions=[];
    $owner=(int)($user['id']??0);
    if(!$interestRefs||$owner<1||$agentId<0||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return [];
    $wanted=[];$primaries=[];
    foreach(array_slice($interestRefs,0,48) as $ref){
        if(!is_string($ref)||!preg_match('/\A(artist|topic)-interest:([a-f0-9]{64})\z/D',$ref,$match)||isset($wanted[$ref]))continue;
        $wanted[$ref]=true;
        $primaries[]=$ref;
        $primaries[]='youtube-interest-checkin:'.$match[1].':'.$match[2];
    }
    if(!$wanted)return [];
    $at=($now??new DateTimeImmutable())->getTimestamp();
    $since=gmdate('Y-m-d H:i:s',$at-604800);
    $events=vp3_initiative_engagement_events_v1($pdo,$owner,$agentId,null,32,$since,$primaries);
    if(!$events)return [];
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    try{$principal=vp3_initiative_principal_v1($pdo,$user,$agentId);}
    catch(Throwable $error){return [];}
    $seen=[];$feedback=[];
    foreach($events as $event){
        $goal=json_decode((string)$event['goal'],true);
        $source=$goal['primary']??null;
        if(($goal['tool']??null)!=='chat.check_in'||!is_string($source))continue;
        $interestRef=vp3_initiative_youtube_interest_ref_v1($source);
        if($interestRef===null)continue;
        if(!isset($wanted[$interestRef])||isset($seen[$interestRef]))continue;
        $cid=$goal['binding']['conversation_id']??null;
        $metadata=json_decode((string)$event['metadata_json'],true);
        $messageId=$metadata['message_id']??null;$replyId=$metadata['reply_message_id']??null;
        $intent=$metadata['intent']??null;
        if(!is_int($cid)||!is_int($messageId)||!is_int($replyId)||$replyId<=$messageId||!is_string($intent))continue;
        try{
            $post=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$messageId);
            $state=$post['state'];
            if((int)$post['run']['id']!==(int)$event['run_id']||($state['last_reply_message_id']??null)!==$replyId
                ||($state['last_intent']??null)!==$intent
                ||($post['context']['initiative_output']['type']??null)!=='initiative_chat_check_in'
                ||($state['interest_confirmed']??false)!==($metadata['interest_confirmed']??false))continue;
            $q=$pdo->prepare("SELECT message,created_at FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
            $q->execute([$replyId,$cid,$owner]);$reply=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_string($reply['message']??null)||!hash_equals($state['last_reply_hash'],hash('sha256',$reply['message'])))continue;
        }catch(Throwable $error){continue;}
        $seen[$interestRef]=true;
        if(in_array($intent,['discuss','advance'],true)&&($state['interest_confirmed']??false)===true)$feedback[$interestRef]='positive';
        elseif(in_array($intent,['defer','decline','resolve'],true))$feedback[$interestRef]='suppress';
        if($decisions!==null&&isset($feedback[$interestRef])){
            $savedAt=(string)($reply['created_at']??'');
            if(!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$savedAt))continue;
            $when=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$savedAt,new DateTimeZone('UTC'));
            if($when&&$when->format('Y-m-d H:i:s')===$savedAt&&$when->getTimestamp()<=$at){
                $decisions[$interestRef]=['intent'=>$intent,'at'=>$when->getTimestamp()];
            }
        }
    }
    return $feedback;
}
