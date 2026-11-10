<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';

/** Canonical job leases are stored as UTC SQL timestamps, independent of the
 * PHP host timezone or the owner's display timezone. */
function vp3_top_of_mind_lease_time_v1(mixed $value): int
{
    if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value))return 0;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    $errors=DateTimeImmutable::getLastErrors();
    return $time&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))?$time->getTimestamp():0;
}

/** A read model of canonical work, never a planner, worker or source of authority. */
function vp3_top_of_mind_text_v1(mixed $value,int $limit=300): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]|\s+/u',' ',strip_tags(is_scalar($value)?(string)$value:''))??''),0,$limit);
}

function vp3_top_of_mind_state_v1(array $run,array $action,?array $receipt,array $result,int $now): string
{
    if(($run['status']??'')==='executing'){
        return vp3_top_of_mind_lease_time_v1($run['lease_expires_at']??null)>=$now
            &&($action['status']??'')==='executing'&&(int)($run['current_action_id']??0)===(int)($action['id']??0)?'working':'interrupted';
    }
    if(($run['status']??'')==='failed'){
        return ($run['capability_key']??'')==='initiative.plan'&&($run['last_error_class']??'')==='assessment_sources_changed'
            ?'superseded':'failed';
    }
    if(($run['status']??'')==='cancelled')return 'cancelled';
    if(($run['status']??'')==='paused')return 'paused';
    if(in_array($run['status']??'', ['queued','approved'],true))return 'queued';
    if(($run['status']??'')!=='completed')return 'unknown';
    if(($action['status']??'')!=='completed'||($receipt['status']??'')!=='completed'
        ||($receipt['result_json']??null)!==($action['result_json']??null))return 'unverified';
    if(($run['capability_key']??'')==='initiative.plan'){
        return in_array($result['effect_state']??null,['assignments_queued','existing_assignments','idle'],true)?'assessed':'unverified';
    }
    if(($result['effect_state']??null)!=='private_draft_ready'||!is_string($result['initiative_draft']??null)
        ||trim($result['initiative_draft'])===''||mb_strlen($result['initiative_draft'])>8000)return 'unverified';
    if(($result['initiative_delivery']??'')==='withheld')return 'withheld';
    $output=$result['initiative_tool_result']['output']??[];
    if(in_array($output['status']??'', ['approval_pending','review_required'],true))return 'waiting_owner';
    if(($result['initiative_delivery']??'')==='pending')return 'ready_to_report';
    return 'completed';
}

/** A current owner-only playback observation for this Chat. */
function vp3_top_of_mind_music_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $now): ?array
{
    require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
    $owner=(int)$user['id'];
    if(!vp3_youtube_playback_ready_v1($pdo)||!agent_connection_youtube_enabled_v1()
        ||!agent_connection_youtube_consent_v1($pdo,$owner))return null;
    $captureKey=vp3_youtube_capture_key_v1($pdo,$owner);
    if($captureKey==='')return null;
    foreach(vp3_youtube_history_recent_v1($pdo,$owner,$agentId,$conversationId) as $video){
        $playedAt=$video['played_at'];
        if($playedAt<$now-86400||$playedAt>$now)continue;
        $title=vp3_top_of_mind_text_v1($video['title']??'',180);
        if($title==='')continue;
        if(!hash_equals($captureKey,vp3_youtube_capture_key_v1($pdo,$owner))
            ||!agent_connection_youtube_consent_v1($pdo,$owner)||!agent_connection_youtube_enabled_v1())return null;
        return ['title'=>$title,'channel_title'=>vp3_top_of_mind_text_v1($video['channel_title']??'',140),
            'music_suggestion'=>$video['music_suggestion'],
            'played_at'=>gmdate(DATE_ATOM,$playedAt),'chat_url'=>url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$conversationId)];
    }
    return null;
}

/** Read-only context for the exact Chat, independent of whether Initiative can run. */
function vp3_top_of_mind_chat_context_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $now): array
{
    require_once __DIR__.'/agent-calendar-prep-v1.php';
    require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
    $context=['calendar'=>[],'youtube_search'=>null,'artist_research'=>null,'topic_research'=>null];
    $chatUrl=url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$conversationId);
    try{
        $context['calendar']=array_slice(vp3_calendar_prep_today_v1($pdo,$user,$agentId,$conversationId,new DateTimeImmutable('@'.$now)),0,1);
    }catch(PDOException $error){}catch(RuntimeException $error){
        if($error->getCode()!==503)throw $error;
    }
    try{
        $records=agent_connection_youtube_result_records_v1($pdo,(int)$user['id'],$agentId,$conversationId);
        foreach($records as $record){
            $searchedAt=$record['created_at_epoch'];
            $results=$record['results'];
            $query=vp3_top_of_mind_text_v1($results['query']??'',180);
            if(!$searchedAt||$searchedAt<$now-86400||$searchedAt>$now||$query==='')continue;
            $context['youtube_search']=[
                'message_id'=>$record['message_id'],'query'=>$query,
                'kind'=>($results['suggestion_kind']??null)==='playlist'?'playlist':'search',
                'result_count'=>count(array_slice($results['videos'],0,6)),
                'searched_at'=>gmdate(DATE_ATOM,$searchedAt),'chat_url'=>$chatUrl,
            ];
            break;
        }
    }catch(PDOException $error){}
    foreach(['artist','topic'] as $kind){
        $key=$kind.'_research';
        try{
            $context[$key]=vp3_top_of_mind_research_result_v1($pdo,(int)$user['id'],$conversationId,$now,$chatUrl,$kind);
        }catch(PDOException $error){}
    }
    return $context;
}

/** Reuse the latest verified public research response in this owner Chat. */
function vp3_top_of_mind_research_result_v1(PDO $pdo,int $ownerId,int $conversationId,int $now,string $chatUrl,string $kind): ?array
{
    $key=$kind.'_research_v1';
    $stmt=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.id=? AND c.user_id=? AND m.role='assistant' AND m.created_at BETWEEN ? AND ?
            AND m.context_json LIKE ? ORDER BY m.id DESC LIMIT 12");
    $stmt->execute([$conversationId,$ownerId,gmdate('Y-m-d H:i:s',$now-86400),gmdate('Y-m-d H:i:s',$now),'%"'.$key.'"%']);
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        $context=json_decode((string)$row['context_json'],true);
        $research=$context[$key]??null;
        $receipt=vp3_tool_receipt_normalize_v1($context['tool_receipt']??null);
        if(!is_array($research)||($receipt['capability']??null)!==$kind.'.research'||($receipt['status']??null)!==($research['status']??null))continue;
        $subject=vp3_top_of_mind_text_v1($research[$kind]??'',120);
        if($subject==='')continue;
        $status=match($research['status']){
            'read_complete'=>($research['mode']??null)==='delegate'?'mission_ready':'ready',
            'clarification_required'=>($research['mode']??null)==='delegate'&&!empty($research['mission_id'])?'waiting_owner':null,
            'no_matches'=>'no_matches',
            'blocked','failed'=>'unavailable',
            default=>null,
        };
        if($status!==null)return [$kind=>$subject,'status'=>$status,'message_id'=>(int)$row['id'],'chat_url'=>$chatUrl];
    }
    return null;
}

/** Explain the selected thought using current source facts only. */
function vp3_top_of_mind_interest_v1(array $source): array
{
    $interest=$source['interest']??null;
    if(!is_array($interest)||!in_array($source['kind']??null,['artist_interest','topic_interest','youtube_interest_checkin'],true))return [];
    $facts=[];
    foreach(['search_count','related_search_count','watch_sessions','watch_seconds'] as $key){
        $value=$interest[$key]??0;
        if(is_int($value)&&$value>0)$facts[$key]=$value;
    }
    if(($interest['basis']??null)==='clarified')$facts['owner_reply']=true;
    return $facts;
}

/** Exact requested Agent, current saved policy and authorized sources only. */
function vp3_top_of_mind_v1(PDO $pdo,array $user,int $agentId,int $conversationId=0): array
{
    if($agentId<0||$conversationId<0||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Top of Mind is unavailable for this context.',403);
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$conversationId):array{
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live))throw new RuntimeException('Agent access changed.',403);
        $policy=vp3_initiative_policy_v1($live,$agentId);
        $cid=$conversationId>0?$conversationId:(is_int($policy['conversation_id']??null)?$policy['conversation_id']:0);
        $now=time();
        $out=['contract'=>'vp3.top-of-mind.v1','owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
            'conversation_id'=>$cid,'observed_at'=>gmdate(DATE_ATOM,$now),'state'=>'no_chat','items'=>[],
            'coverage'=>['initiative'=>'ready','foreground'=>'client_runtime_only']];
        if($cid<1)return $out;
        if(!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent))throw new RuntimeException('Conversation access changed.',403);
        $out['state']=match(true){
            !agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)=>'unavailable',
            $policy===[]=>'starting',
            ($policy['conversation_id']??null)!==$cid=>'linked_elsewhere',
            ($policy['enabled']??null)===false=>'paused',
            default=>'ready',
        };
        if($out['state']!=='ready')return $out+vp3_top_of_mind_chat_context_v1($pdo,$live,$agentId,$cid,$now);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $byId=[];
        foreach(array_merge(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,false,true),
            vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,true),
            vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy)) as $run)$byId[(int)$run['id']]=$run;
        $runs=array_values($byId);
        $workRank=static fn(array $run):int=>($run['status']??'')==='executing'
            ?(vp3_top_of_mind_lease_time_v1($run['lease_expires_at']??'')>=$now?0:1)
            :(in_array($run['status']??'', ['approved','queued'],true)?2:3);
        usort($runs,static fn(array $a,array $b):int=>($workRank($a)<=>$workRank($b))?:((int)$b['id']<=>(int)$a['id']));
        $visible=[];
        foreach($runs as $run)$visible[($run['capability_key']??'')==='initiative.plan'?'assessment':(int)$run['id']]??=$run;
        $runs=array_slice($visible,0,6);
        $selected=[];$sessions=[];
        foreach($runs as $run){
            if(($run['capability_key']??'')==='initiative.plan')continue;
            $goal=json_decode((string)($run['goal']??''),true);
            if(!is_array($goal['sources']??null)||!$goal['sources']||count($goal['sources'])>5)continue;
            foreach($goal['sources'] as $ref=>$revision){
                if(!is_string($ref)||strlen($ref)>190||!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision))continue 2;
            }
            foreach($goal['sources'] as $ref=>$revision){
                $selected[$ref]=true;
                if(str_starts_with($ref,'screen-followup:'))$sessions[]=substr($ref,16);
            }
        }
        // One bounded source projection for the request, not one full account
        // projection per historical run. Never cache authority between polls.
        $coverage=[];
        $current=$selected?vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),
            !empty($policy['screen_enabled'])&&$sessions!==[],array_values(array_unique($sessions)),$coverage,array_keys($selected),$agentId):[];
        $verified=[];
        foreach($runs as $run){
            $goal=json_decode((string)($run['goal']??''),true);
            $assessment=($run['capability_key']??'')==='initiative.plan';
            if(!is_array($goal)||!is_array($goal['sources']??null)||!$goal['sources']||!$assessment&&count($goal['sources'])>5)continue;
            try{
                foreach($assessment?[]:$goal['sources'] as $ref=>$revision){
                    if(!isset($selected[$ref])||($current[$ref]['revision']??null)!==$revision)throw new RuntimeException('Source changed.',409);
                }
                $sources=$assessment?[]:array_intersect_key($current,$goal['sources']);
                $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id LIMIT 1');
                $q->execute([(int)$run['id'],(int)$live['id']]);$action=$q->fetch(PDO::FETCH_ASSOC);
                if(!$action)continue;
                $result=json_decode((string)($action['result_json']??''),true);$result=is_array($result)?$result:[];
                // A delivered check-in legitimately precedes later owner turns.
                // Its source authority remains current; its old delivery-tail
                // guard is not a permanent read permission.
                if(($goal['tool']??'')!=='chat.check_in'){
                    vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
                }
                $receipt=null;
                if(($run['status']??'')==='completed'){
                    $q=$pdo->prepare('SELECT status,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 1');
                    $q->execute([(int)$run['id'],(int)$action['id'],(int)$live['id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC)?:null;
                }
                $state=vp3_top_of_mind_state_v1($run,$action,$receipt,$result,$now);
                $item=['run_id'=>(int)$run['id'],'state'=>$state,'kind'=>$assessment?'assessment':'preparation',
                    'objective'=>$assessment?'Assess useful next steps':vp3_top_of_mind_text_v1($goal['focus']??$run['title']??'Private preparation'),
                    'step'=>$state==='working'?($assessment?'Assessing the current situation':vp3_top_of_mind_text_v1($run['progress_message']??$action['label']??'',240)):'',
                    'progress'=>$state==='working'?max(0,min(99,(int)($run['progress_percent']??0))):null,
                    'observed_at'=>gmdate(DATE_ATOM,$now),'updated_at'=>vp3_top_of_mind_text_v1($run['updated_at']??$run['created_at']??'',40),
                    'lease_expires_at'=>$state==='working'?gmdate(DATE_ATOM,vp3_top_of_mind_lease_time_v1($run['lease_expires_at'])):'',
                    'sources'=>[],'result_url'=>'','owner_state'=>''];
                foreach(array_slice($sources,0,3) as $source){
                    $item['sources'][]=['title'=>vp3_top_of_mind_text_v1($source['title']??'',140)];
                }
                $messageId=filter_var($result['initiative_message_id']??0,FILTER_VALIDATE_INT);
                if(!$assessment&&$messageId>0){
                    $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' LIMIT 1");
                    $q->execute([$messageId,$cid]);
                    $message=$q->fetch(PDO::FETCH_ASSOC);$context=json_decode((string)($message['context_json']??''),true);
                    $marker=$context['initiative_work']??[];
                    if(($context['source']??null)==='agent_initiative'&&($context['initiative_run_id']??null)===(int)$run['id']
                        &&($marker['owner_user_id']??null)===(int)$live['id']&&($marker['agent_id']??null)===$agentId
                        &&($marker['conversation_id']??null)===$cid&&($marker['run_id']??null)===(int)$run['id']){
                        $item['result_url']=url('/chat.php?agent='.$agentId.'&conversation_id='.$cid);
                        $item['message_id']=(int)$message['id'];
                        $ownerState=$context['initiative_followthrough_state']['state']??'';
                        if(($context['initiative_followthrough_state']['version']??null)===1
                            &&in_array($ownerState,['open','discussed','queued','deferred','declined','owner_resolved','completed','stale'],true)
                            &&($marker['followthrough']['state']??null)===$ownerState)$item['owner_state']=$ownerState;
                    }
                }
                $out['items'][]=$item;
                $verified[]=['goal'=>$goal,'tool_result'=>$result['initiative_tool_result']??[]];
            }catch(Throwable $error){
                // An inaccessible or changed source must not leave its old
                // objective, source title or result in this live projection.
                $out['coverage']['initiative']='limited';
            }
        }
        $fresh=vp3_initiative_runtime_context_v1($pdo,$live,$agentId,$cid);
        if($fresh['policy']!==$policy)throw new RuntimeException('Agent work scope changed.',409);
        // Recheck the bounded union using the latest account/Agent principal
        // before releasing any source-derived text. A race drops stale work.
        $finalCoverage=[];
        $final=$selected?vp3_initiative_evidence_v1($pdo,$fresh['user'],new DateTimeImmutable('now',new DateTimeZone('UTC')),
            !empty($policy['screen_enabled'])&&$sessions!==[],array_values(array_unique($sessions)),$finalCoverage,array_keys($selected),$agentId):[];
        $before=count($out['items']);
        $visibleItems=array_filter($out['items'],static function(array $item,int $index)use($verified,$final,$pdo,$fresh,$policy):bool{
            $goal=$verified[$index]['goal'];
            if($item['kind']==='assessment')return true;
            foreach($goal['sources'] as $ref=>$revision)if(($final[$ref]['revision']??null)!==$revision)return false;
            try{if(($goal['tool']??'')!=='chat.check_in')vp3_initiative_result_current_v1($pdo,$fresh['user'],$policy,$goal,$verified[$index]['tool_result']);}
            catch(Throwable $error){return false;}
            return true;
        },ARRAY_FILTER_USE_BOTH);
        $out['items']=[];
        foreach($visibleItems as $index=>$item){
            $goal=$verified[$index]['goal'];
            $primary=$goal['primary']??'';
            $interest=is_string($primary)?vp3_top_of_mind_interest_v1($final[$primary]??[]):[];
            if($interest)$item['youtube_interest']=$interest;
            $out['items'][]=$item;
        }
        if(count($out['items'])!==$before)$out['coverage']['initiative']='limited';
        try{$out['music']=vp3_top_of_mind_music_v1($pdo,$fresh['user'],$agentId,$cid,$now);}
        catch(PDOException $error){$out['music']=null;}
        return $out+vp3_top_of_mind_chat_context_v1($pdo,$fresh['user'],$agentId,$cid,$now);
    });
}
