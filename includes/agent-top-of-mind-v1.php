<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-top-of-mind-specialists-v1.php';

/** A small owner/Agent work read for the global orb. Never opens the feed,
 * schedules work, claims a job or invokes a model. */
function vp3_top_of_mind_work_v1(PDO $pdo,array $user,int $agentId,int $conversationId=0,bool $exactChat=false,
    bool $includeEngagements=false,bool $includeTranscriptReports=false): array
{
    if($agentId<0||$conversationId<0||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Agent work is unavailable for this context.',403);
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$conversationId,$exactChat,
        $includeEngagements,$includeTranscriptReports):array{
        try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){throw new RuntimeException('Agent access changed.',403,$error);}
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live)){
            throw new RuntimeException('Agent access changed.',403);
        }
        $policy=vp3_initiative_policy_v1($live,$agentId);
        $linked=is_int($policy['conversation_id']??null)?max(0,$policy['conversation_id']):0;
        if(!$exactChat&&$conversationId>0&&$conversationId!==$linked)throw new RuntimeException('Agent work context changed.',403);
        $cid=$exactChat?$conversationId:$linked;
        if($cid>0&&!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent)){
            throw new RuntimeException('Conversation access changed.',403);
        }
        $health=$cid>0?vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)
            :['state'=>'idle','enabled'=>false,'active_jobs'=>[]];
        $jobs=[];$now=time()*1000;
        foreach(($health['enabled']??false)===true?array_slice($health['active_jobs']??[],0,25):[] as $job){
            if(!is_array($job)||!is_string($job['id']??null)||!preg_match('/\Aworkflow:[1-9][0-9]{0,19}\z/D',$job['id'])
                ||!is_int($job['expiresAt']??null)||$job['expiresAt']<=$now)continue;
            $active=['id'=>$job['id'],'expiresAt'=>$job['expiresAt']];
            if(in_array($job['kind']??null,['assessment','preparation'],true))$active['kind']=$job['kind'];
            if(isset($active['kind'])&&is_string($job['step']??null))$active['step']=vp3_top_of_mind_text_v1($job['step'],240);
            $jobs[]=$active;
        }
        $gmail=vp3_top_of_mind_gmail_v1($pdo,$live,$agentId,new DateTimeImmutable('@'.intdiv($now,1000)),false);
        $gmailScanExpiry=($gmail['state']??null)==='working'?(strtotime($gmail['lease_until']??'')?:0):0;
        $gmailAnalysisExpiry=($gmail['analysis_work']['state']??null)==='working'
            ?(strtotime($gmail['analysis_work']['lease_until']??'')?:0):0;
        $gmailExpiresAt=max($gmailScanExpiry,$gmailAnalysisExpiry)*1000;
        $out=['contract'=>'vp3.agent.work.v1','owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
            'conversation_id'=>$cid,'observed_at'=>gmdate(DATE_ATOM),
            'health'=>['state'=>vp3_top_of_mind_text_v1($health['state']??'unavailable',40),
                'enabled'=>($health['enabled']??false)===true,'active_jobs'=>$jobs]];
        if($gmailExpiresAt>$now)$out['gmail_job']=['id'=>'gmail:inbox-updates','expiresAt'=>$gmailExpiresAt];
        $out['specialists']=vp3_top_of_mind_specialists_v1($pdo,$live,$principal,$agentId,$cid,$policy,$health,$gmail,intdiv($now,1000),
            includeEngagements:$includeEngagements,includeTranscriptReports:$includeTranscriptReports,privateChat:$exactChat);
        $out['coordination']=vp3_top_of_mind_coordination_v1($cid>0?$health:['state'=>'no_chat'],$out['specialists'],intdiv($now,1000));
        return $out;
    });
}

/** Page older unanswered questions only after the same live owner/Agent/Chat checks as the full feed. */
function vp3_top_of_mind_engagements_v1(PDO $pdo,array $user,int $agentId,int $cid,string $specialist,int $before=0): array
{
    if($agentId<0||$cid<1||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Open engagements are unavailable for this Chat.',403);
    }
    if(!in_array($specialist,VP3_TOP_OF_MIND_ENGAGEMENT_SPECIALISTS_V1,true)||$before<0){
        throw new RuntimeException('Choose a valid specialist and engagement page.',422);
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$specialist,$before):array{
        try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){throw new RuntimeException('Agent access changed.',403,$error);}
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live)
            ||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent)){
            throw new RuntimeException('Conversation access changed.',403);
        }
        $page=vp3_top_of_mind_open_engagements_v1($pdo,$live,$principal,$agentId,$cid,$specialist,$before)[$specialist];
        return ['contract'=>'vp3.agent.engagements.v1','owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
            'conversation_id'=>$cid,'specialist'=>$specialist]+$page;
    });
}

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

function vp3_top_of_mind_media_href_v1(int $agentId,int $conversationId,int $messageId): string
{
    return url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$conversationId.'&message_id='.$messageId);
}

function vp3_top_of_mind_gmail_v1(PDO $pdo,array $user,int $agentId,DateTimeImmutable $now,bool $includeResults=true): ?array
{
    try{
        if(!table_exists('agent_connection_gmail_inbox_v1'))return null;
        require_once __DIR__.'/agent-connection-gmail-inbox-status-v1.php';
        $monitor=agent_connection_gmail_inbox_background_status_v1($pdo,$user,$agentId,$now,$includeResults);
        if($monitor===null||!$includeResults)return $monitor;
        $monitor['settings_url']=url('/connections.php?app=gmail#gmail-inbox-review');
        if($monitor['latest']!==null){
            $latest=$monitor['latest'];
            $monitor['latest']['chat_url']=vp3_top_of_mind_media_href_v1($agentId,$latest['conversation_id'],$latest['message_id']);
        }
        if(isset($monitor['matters']['items'])){
            foreach($monitor['matters']['items'] as &$matter){
                $reply=$matter['reply_result']??null;
                if($reply===null)continue;
                if($reply['agent_id']!==$agentId||$reply['conversation_id']<1||$reply['message_id']<1){
                    unset($matter['reply_result']);
                    continue;
                }
                $matter['reply_result']['chat_url']=vp3_top_of_mind_media_href_v1($agentId,$reply['conversation_id'],$reply['message_id']);
            }
            unset($matter);
        }
        return $monitor;
    }catch(PDOException $error){
        return null;
    }
}

/** Keep Calendar scoped to Chat and show verified YouTube activity for the owner. */
function vp3_top_of_mind_chat_context_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $now,?array $principal=null): array
{
    require_once __DIR__.'/agent-calendar-prep-v1.php';
    require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
    $context=['calendar'=>[],'music'=>null,'youtube_search'=>null,'artist_research'=>null,'topic_research'=>null];
    $gmail=vp3_top_of_mind_gmail_v1($pdo,$user,$agentId,new DateTimeImmutable('@'.$now));
    if($gmail!==null)$context['gmail_inbox']=$gmail;
    if($principal!==null){
        require_once __DIR__.'/agent-gmail-workspace-context-v1.php';
        try{$context['gmail_workspace']=agent_gmail_workspace_context_v1($pdo,$user,$principal,$conversationId,$now);}
        catch(PDOException $error){}
    }
    if($conversationId>0&&$principal!==null){
        require_once __DIR__.'/agent-transcript-focus-v1.php';
        try{
            $recording=vp3_transcript_focus_view_v1($pdo,$user,$principal,$conversationId,new DateTimeImmutable('@'.$now));
            if($recording!==null)$context['recording']=$recording;
        }catch(PDOException $error){}catch(RuntimeException $error){if($error->getCode()!==503)throw $error;}
    }
    try{
        if($conversationId>0)$context['calendar']=array_slice(vp3_calendar_prep_today_v1($pdo,$user,$agentId,$conversationId,new DateTimeImmutable('@'.$now)),0,1);
    }catch(PDOException $error){}catch(RuntimeException $error){
        if($error->getCode()!==503)throw $error;
    }
    try{
        $records=agent_connection_youtube_activity_records_v1($pdo,(int)$user['id'],$agentId,$now);
        foreach($records['searches'] as $record){
            $results=$record['results'];
            $query=vp3_top_of_mind_text_v1($results['query']??'',180);
            if($query==='')continue;
            $context['youtube_search']=[
                'message_id'=>$record['message_id'],'conversation_id'=>$record['conversation_id'],'query'=>$query,
                'kind'=>($results['suggestion_kind']??null)==='playlist'?'playlist':'search',
                'result_count'=>count(array_slice($results['videos'],0,6)),
                'searched_at'=>gmdate(DATE_ATOM,$record['created_at_epoch']),
                'chat_url'=>vp3_top_of_mind_media_href_v1($agentId,$record['conversation_id'],$record['message_id']),
            ];
            break;
        }
        foreach($records['plays'] as $video){
            $title=vp3_top_of_mind_text_v1($video['title']??'',180);
            $cid=(int)$video['conversation_id'];$mid=(int)$video['message_id'];
            if($title===''||$cid<1||$mid<1)continue;
            $context['music']=['title'=>$title,'channel_title'=>vp3_top_of_mind_text_v1($video['channel_title']??'',140),
                'music_suggestion'=>$video['music_suggestion'],'message_id'=>$mid,'conversation_id'=>$cid,
                'played_at'=>gmdate(DATE_ATOM,$video['played_at']),'chat_url'=>vp3_top_of_mind_media_href_v1($agentId,$cid,$mid)];
            break;
        }
    }catch(PDOException $error){}
    require_once __DIR__.'/agent-activity-media-v1.php';
    try{
        $context['youtube_activity']=vp3_agent_activity_youtube_playlist_v1($pdo,$user,$agentId,$conversationId,$now);
    }catch(RuntimeException $error){
        $context['youtube_activity']=['status'=>'unavailable','searches'=>[],'played'=>[]];
    }
    if($principal!==null){
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        $instant=new DateTimeImmutable('@'.$now);
        $mediaScope=['user'=>$user,'principal'=>$principal,'agent_id'=>$agentId,'conversation_id'=>$conversationId];
        $nativeListening=[];
        try{$nativeListening=vp3_agent_activity_native_listening_v1($user);}
        catch(PDOException){}
        $context['recent_listening']=vp3_media_recent_listening_v1($pdo,$mediaScope,$instant,$nativeListening);
        $context['media_findings']=[];
        try{
            $media=vp3_media_play_findings_sources_v1($pdo,$mediaScope,null,$instant);
            $fields=array_fill_keys(['kind','title','summary','next_step','question','actions'],true);
            $lead=[];$kinds=[];
            foreach($media as $ref=>$item){
                if(isset($kinds[$item['kind']]))continue;
                $lead[$ref]=$item;
                $kinds[$item['kind']]=true;
            }
            $context['media_findings']=array_values(array_map(static fn(array $item):array=>
                ['id'=>$item['ref']]+array_intersect_key($item,$fields),$lead+$media));
        }
        catch(RuntimeException){}
        $listeningFinding=vp3_media_recent_play_finding_v1(array_values(array_filter($context['recent_listening'],
            static fn(array $play):bool=>($play['actions'][0]['kind']??null)==='stoneward_history')));
        if($listeningFinding!==null)$context['media_findings'][]=$listeningFinding;
    }
    foreach(['artist','topic'] as $kind){
        $key=$kind.'_research';
        try{
            $context[$key]=vp3_top_of_mind_research_result_v1($pdo,(int)$user['id'],$agentId,$now,$kind);
        }catch(PDOException $error){}
    }
    return $context;
}

/** Reuse the latest verified public research response for the exact private Agent. */
function vp3_top_of_mind_research_result_v1(PDO $pdo,int $ownerId,int $agentId,int $now,string $kind): ?array
{
    $key=$kind.'_research_v1';
    $scope=$agentId>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $params=$agentId>0?[$ownerId,$agentId]:[$ownerId];
    $stmt=$pdo->prepare("SELECT m.id,m.conversation_id,m.context_json FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND {$scope} AND m.role='assistant' AND m.created_at BETWEEN ? AND ?
            AND m.context_json LIKE ? ORDER BY m.created_at DESC,m.id DESC LIMIT 12");
    $stmt->execute([...$params,gmdate('Y-m-d H:i:s',$now-86400),gmdate('Y-m-d H:i:s',$now),'%"'.$key.'"%']);
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
        if($status!==null){
            $cid=(int)$row['conversation_id'];$mid=(int)$row['id'];
            return [$kind=>$subject,'status'=>$status,'message_id'=>$mid,'conversation_id'=>$cid,
                'chat_url'=>vp3_top_of_mind_media_href_v1($agentId,$cid,$mid)];
        }
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
function vp3_top_of_mind_v1(PDO $pdo,array $user,int $agentId,int $conversationId=0,bool $exactChat=false): array
{
    if($agentId<0||$conversationId<0||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Top of Mind is unavailable for this context.',403);
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$conversationId,$exactChat):array{
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live))throw new RuntimeException('Agent access changed.',403);
        $policy=vp3_initiative_policy_v1($live,$agentId);
        $cid=$exactChat||$conversationId>0?$conversationId:(is_int($policy['conversation_id']??null)?$policy['conversation_id']:0);
        $now=time();
        $out=['contract'=>'vp3.top-of-mind.v1','owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
            'conversation_id'=>$cid,'observed_at'=>gmdate(DATE_ATOM,$now),'state'=>'no_chat','items'=>[],
            'coverage'=>['initiative'=>'ready','foreground'=>'client_runtime_only']];
        if($cid>0&&!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent))throw new RuntimeException('Conversation access changed.',403);
        $out['state']=match(true){
            $cid<1=>'no_chat',
            !agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)=>'unavailable',
            $policy===[]=>'starting',
            ($policy['conversation_id']??null)!==$cid=>'linked_elsewhere',
            ($policy['enabled']??null)===false=>'paused',
            default=>'ready',
        };
        $finish=static function(array $out,array $live)use($pdo,$principal,$agentId,$cid,$policy,$now,$exactChat):array{
            $out+=vp3_top_of_mind_chat_context_v1($pdo,$live,$agentId,$cid,$now,$principal);
            $health=$out['state']==='ready'?vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)
                :['state'=>$out['state'],'active_jobs'=>[]];
            $out['specialists']=vp3_top_of_mind_specialists_v1($pdo,$live,$principal,$agentId,$cid,$policy,$health,$out['gmail_inbox']??null,
                $now,$out['items'],includeEngagements:true,includeTranscriptReports:true,privateChat:$exactChat);
            $out['coordination']=vp3_top_of_mind_coordination_v1($health,$out['specialists'],$now,$out['items']);
            if(isset($out['coordination_finding'])){
                if(in_array($out['coordination']['phase'],['idle','checked'],true)&&$out['coordination_finding']['completed_at']>$now-120)
                    $out['coordination']=['phase'=>'presenting','detail'=>'The latest finding from specialist reports.'];
                $out['coordination']['finding']=$out['coordination_finding']['text'];
                unset($out['coordination_finding']);
            }
            return $out;
        };
        if($out['state']!=='ready')return $finish($out,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $byId=[];
        foreach(array_merge(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,false,true),
            vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,true),
            vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy)) as $run)$byId[(int)$run['id']]=$run;
        $byId=array_filter($byId,static function(array $run):bool{
            if(($run['capability_key']??null)!=='initiative.plan')return true;
            $goal=json_decode((string)($run['goal']??''),true);
            return ($goal['opportunity_lane']??null)!=='app_suggestions';
        });
        $runs=array_values($byId);
        $workRank=static fn(array $run):int=>($run['status']??'')==='executing'
            ?(vp3_top_of_mind_lease_time_v1($run['lease_expires_at']??'')>=$now?0:1)
            :(in_array($run['status']??'', ['approved','queued'],true)?2:3);
        usort($runs,static fn(array $a,array $b):int=>($workRank($a)<=>$workRank($b))?:((int)$b['id']<=>(int)$a['id']));
        $visible=[];
        foreach($runs as $run){
            if(str_starts_with((string)($run['source_key']??''),'coordination:'))continue;
            // Private aggregate analyses are projected by specialist status. They
            // cannot consume owner-agenda slots before native source verification.
            $goal=json_decode((string)($run['goal']??''),true);
            if(in_array($goal['tool']??null,['brainstorm.explore','curiosity.analyze'],true))continue;
            $visible[($run['capability_key']??'')==='initiative.plan'?'assessment':(int)$run['id']]??=$run;
        }
        $runs=array_slice($visible,0,6);
        require_once __DIR__.'/agent-initiative-task-reports-v1.php';
        $classified=vp3_initiative_task_report_classifications_v1($pdo,$live,$policy,
            array_filter($runs,static fn(array $run):bool=>$run['status']==='completed'),$now,
            ['user'=>$live,'principal'=>$principal,'agent'=>$agent,'agent_id'=>$agentId,'conversation_id'=>$cid]);
        $selected=[];$sessions=[];
        foreach($runs as $run){
            if(($run['capability_key']??'')==='initiative.plan')continue;
            $goal=json_decode((string)($run['goal']??''),true);
            if(($goal['office_entry']??null)===true||!is_array($goal['sources']??null)||!$goal['sources']||count($goal['sources'])>5)continue;
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
            if($run['status']==='completed'&&vp3_initiative_task_report_superseded_v1($run,$classified))continue;
            $goal=json_decode((string)($run['goal']??''),true);
            $assessment=($run['capability_key']??'')==='initiative.plan';
            if(!is_array($goal)||!is_array($goal['sources']??null)
                ||(!$goal['sources']&&(!$assessment||!is_string($goal['report_only']??null)))
                ||(!$assessment&&count($goal['sources'])>5))continue;
            try{
                $officeEntry=($goal['office_entry']??null)===true;
                $sources=[];
                if($officeEntry){
                    $sources=vp3_initiative_work_sources_v1($pdo,$live,$policy,$goal);
                }elseif(!$assessment){
                    foreach($goal['sources'] as $ref=>$revision){
                        if(!isset($selected[$ref])||($current[$ref]['revision']??null)!==$revision)throw new RuntimeException('Source changed.',409);
                    }
                    $sources=array_intersect_key($current,$goal['sources']);
                }
                $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id LIMIT 1');
                $q->execute([(int)$run['id'],(int)$live['id']]);$action=$q->fetch(PDO::FETCH_ASSOC);
                if(!$action)continue;
                $result=json_decode((string)($action['result_json']??''),true);$result=is_array($result)?$result:[];
                if(($result['initiative_tool_result']['output']['public_topic_unavailable']??false)===true)continue;
                // A delivered check-in legitimately precedes later owner turns.
                // Its source authority remains current; its old delivery-tail
                // guard is not a permanent read permission.
                if(!$assessment&&($goal['tool']??'')!=='chat.check_in'){
                    vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
                }
                $receipt=null;
                if(($run['status']??'')==='completed'){
                    $q=$pdo->prepare('SELECT status,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 1');
                    $q->execute([(int)$run['id'],(int)$action['id'],(int)$live['id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC)?:null;
                }
                $state=vp3_top_of_mind_state_v1($run,$action,$receipt,$result,$now);
                if(in_array($state,['ready_to_report','completed'],true)
                    &&in_array($result['initiative_tool_result']['output']['office_disposition']??null,['note','ignore'],true))continue;
                $primary=$sources[$goal['primary']??'']??[];
                $objective=($primary['kind']??null)==='transcript_research'?$primary['title']:($goal['focus']??$run['title']??'Private preparation');
                if($officeEntry&&isset($goal['office_agent_task'])){
                    $task=vp3_initiative_office_agent_task_parent_v1($pdo,$live,$goal);
                    $objective='Agent Task · '.$task['title'];
                }
                $item=['run_id'=>(int)$run['id'],'state'=>$state,'kind'=>$assessment?'assessment':'preparation',
                    'objective'=>$assessment?'Assess useful next steps':vp3_top_of_mind_text_v1($objective),
                    'step'=>$state==='working'?($assessment?'Assessing the current situation':vp3_top_of_mind_text_v1($run['progress_message']??$action['label']??'',240)):'',
                    'progress'=>$state==='working'&&(int)($run['progress_percent']??0)>0?min(99,(int)$run['progress_percent']):null,
                    'observed_at'=>gmdate(DATE_ATOM,$now),'updated_at'=>vp3_top_of_mind_text_v1($run['updated_at']??$run['created_at']??'',40),
                    'lease_expires_at'=>$state==='working'?gmdate(DATE_ATOM,vp3_top_of_mind_lease_time_v1($run['lease_expires_at'])):'',
                    'sources'=>[],'result_url'=>'','owner_state'=>''];
                $specialist=vp3_top_of_mind_tool_specialist_v1($goal);
                if($specialist!=='')$item['specialist_id']=$specialist;
                $canvasSuggestion=($goal['tool']??null)==='canvas.prepare'&&($goal['opportunity_lane']??null)==='app_suggestions';
                if(!$assessment&&is_string($goal['tool']??null)){
                    $handoff=vp3_initiative_handoff_view_v1($goal['tool'],$goal['sources'],$sources);
                    if($handoff!==null)$item['handoff']=$handoff;
                }
                foreach(array_slice($sources,0,3) as $source){
                    $item['sources'][]=['title'=>vp3_top_of_mind_text_v1($source['title']??'',140)];
                }
                $messageId=filter_var($result['initiative_message_id']??0,FILTER_VALIDATE_INT);
                $hasReview=false;
                if(!$assessment&&$messageId>0){
                    $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' LIMIT 1");
                    $q->execute([$messageId,$cid]);
                    $message=$q->fetch(PDO::FETCH_ASSOC);$context=json_decode((string)($message['context_json']??''),true);
                    $marker=$context['initiative_work']??[];
                    if(($context['source']??null)==='agent_initiative'&&($context['initiative_run_id']??null)===(int)$run['id']
                        &&($marker['owner_user_id']??null)===(int)$live['id']&&($marker['agent_id']??null)===$agentId
                        &&($marker['conversation_id']??null)===$cid&&($marker['run_id']??null)===(int)$run['id']){
                        $item['result_url']=$officeEntry||$canvasSuggestion?vp3_top_of_mind_media_href_v1($agentId,$cid,(int)$message['id'])
                            :url('/chat.php?agent='.$agentId.'&conversation_id='.$cid);
                        $item['message_id']=(int)$message['id'];
                        $ownerState=$context['initiative_followthrough_state']['state']??'';
                        if(($context['initiative_followthrough_state']['version']??null)===1
                            &&in_array($ownerState,['open','discussed','queued','deferred','declined','owner_resolved','completed','stale'],true)
                            &&($marker['followthrough']['state']??null)===$ownerState)$item['owner_state']=$ownerState;
                        $ownerFollowup=$context['initiative_followthrough_state']??[];
                        $hasReview=max((int)($ownerFollowup['action_run_id']??0),(int)($ownerFollowup['followup_run_id']??0))>0;
                    }
                }
                $out['items'][]=$item;
                $verified[]=['goal'=>$goal,'tool_result'=>$result['initiative_tool_result']??[],'has_review'=>$hasReview];
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
            try{
                if(($goal['office_entry']??null)===true)vp3_initiative_work_sources_v1($pdo,$fresh['user'],$policy,$goal);
                else foreach($goal['sources'] as $ref=>$revision)if(($final[$ref]['revision']??null)!==$revision)return false;
                if(($goal['tool']??'')!=='chat.check_in')vp3_initiative_result_current_v1($pdo,$fresh['user'],$policy,$goal,$verified[$index]['tool_result']);
            }catch(Throwable $error){return false;}
            return true;
        },ARRAY_FILTER_USE_BOTH);
        $out['items']=[];
        foreach($visibleItems as $index=>$item){
            $goal=$verified[$index]['goal'];
            $primary=$goal['primary']??'';
            $source=is_string($primary)?($final[$primary]??[]):[];
            $interest=vp3_top_of_mind_interest_v1($source);
            if($interest)$item['youtube_interest']=$interest;
            if(($goal['tool']??null)==='topic.research'&&($source['kind']??null)==='transcript_research'){
                require_once __DIR__.'/agent-meeting-research-v1.php';
                $meeting=vp3_meeting_research_projection_v1($pdo,(int)$fresh['user']['id'],$agentId,$cid,$item['run_id'],$goal);
                if($meeting!==null){
                    $item['meeting_followup']=$meeting;
                    if($item['state']==='completed'&&isset($item['message_id'])){
                        $item['result_url']=vp3_top_of_mind_media_href_v1($agentId,$cid,$item['message_id']);
                        if($verified[$index]['has_review']){
                            $review=vp3_top_of_mind_meeting_review_v1($pdo,$fresh['user'],$principal,$cid,$item['message_id']);
                            if($review!==null)$item['meeting_followup_action']=$review;
                        }
                    }
                }
            }
            $out['items'][]=$item;
        }
        if(count($out['items'])!==$before)$out['coverage']['initiative']='limited';
        $finding=vp3_top_of_mind_latest_finding_v1($pdo,$fresh['user'],$policy,$byId,$now);
        if($finding!==null)$out['coordination_finding']=$finding;
        return $finish($out,$fresh['user']);
    });
}

/** Reuse the Planner's exact review and canonical receipt on the existing Research item. */
function vp3_top_of_mind_meeting_review_v1(PDO $pdo,array $user,array $principal,int $conversationId,int $messageId): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    try{
        $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$conversationId,$messageId);
        if(!isset($loaded['meeting_research']))return null;
        $review=($loaded['state']['active_review_slot']??null)==='followup_run_id'?$loaded['followup_action']:$loaded['domain_action'];
        $status=$review['run']['status']??null;
        if(!in_array($status,['approval_pending','completed'],true))return null;
        $skill=match(strtok($review['goal']['plan']['adapter'],'.')){
            'task','task_list'=>'task','calendar'=>'calendar','reminder','proposal'=>'reminder',default=>null,
        };
        return $skill===null?null:['skill'=>$skill,'status'=>$status];
    }catch(RuntimeException $error){return null;}
}
