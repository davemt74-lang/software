<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Feed Composition v5.30 — Phase 11B.4.
 *
 * The feed is a bounded presentation projection. It does not create Chat turns,
 * grant authority, execute tools, or copy canonical objects into feed storage.
 */
const VP3_COGNITIVE_FEED_V530='vp3-cognitive-feed-v530-20260918';
const VP3_COGNITIVE_FEED_MAX_ITEMS_V530=12;
const VP3_COGNITIVE_FEED_LOOKAHEAD_DAYS_V530=7;

function vp3_cognitive_feed_schema_ready_v530(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('cognitive_feed_item_state_v530');
}

function vp3_cognitive_feed_ensure_schema_v530(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_feed_schema_ready_v530($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Feed.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_feed_item_state_v530 (
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      item_key VARCHAR(190) NOT NULL,
      hidden_fingerprint CHAR(64) NOT NULL DEFAULT '',
      hidden_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,agent_namespace,item_key),
      INDEX idx_cognitive_feed_hidden_v530 (owner_user_id,agent_namespace,hidden_at),
      CONSTRAINT fk_cognitive_feed_state_owner_v530 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_feed_text_v530(mixed $value,int $limit=500): string
{
    return vp3_cognitive_text_v500($value,$limit);
}

function vp3_cognitive_feed_fingerprint_v530(array $parts): string
{
    return hash('sha256',vp3_cognitive_json_v500(vp3_cognitive_sanitize_value_v500($parts)));
}

function vp3_cognitive_feed_card_group_v530(array $request,string $fallback=''): string
{
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    $type=vp3_cognitive_id_v500($ref['type']??'',80);
    $id=vp3_cognitive_text_v500($ref['id']??'',190);
    if($type!==''&&$id!==''){
        if(in_array($type,['meeting','meeting_brief','meeting_summary','meeting_followup'],true))$type='meeting';
        return $type.':'.$id;
    }
    return $fallback;
}

function vp3_cognitive_feed_request_v530(string $cardType,string|int $id,string $scope='personal',string $mode='standard',array $extra=[]): array
{
    return [
        'card_type'=>$cardType,
        'object_ref'=>vp3_cognitive_object_ref_v500($cardType,(string)$id,$scope,$extra),
        'display_mode'=>in_array($mode,['compact','standard','expanded'],true)?$mode:'standard',
    ];
}

function vp3_cognitive_feed_candidate_v530(
    string $key,string $section,float $score,string $reason,array $cardRequest,
    string $source,string $updatedAt,array $fingerprintParts=[],bool $attention=false
): array {
    $key=mb_strimwidth(trim($key),0,190,'');
    if($key==='')throw new InvalidArgumentException('Feed item key is required.');
    $sections=['attention','next_up','priorities','opportunities','recent'];
    if(!in_array($section,$sections,true))$section='recent';
    $score=max(0,min(100,$score));
    $fingerprint=vp3_cognitive_feed_fingerprint_v530([$key,$source,$updatedAt,$cardRequest,$fingerprintParts]);
    return [
        'key'=>$key,
        'group_key'=>vp3_cognitive_feed_card_group_v530($cardRequest,$key),
        'section'=>$section,
        'score'=>round($score,3),
        'reason'=>vp3_cognitive_feed_text_v530($reason,420),
        'card_request'=>$cardRequest,
        'source'=>vp3_cognitive_id_v500($source,80),
        'updated_at'=>vp3_cognitive_feed_text_v530($updatedAt,64),
        'fingerprint'=>$fingerprint,
        'attention'=>$attention,
        'signals'=>[$source],
    ];
}

function vp3_cognitive_feed_relative_time_v530(string $utc): string
{
    $ts=strtotime($utc);
    if($ts===false)return 'Upcoming';
    $delta=$ts-time();
    if($delta<=0&&$delta>=-7200)return 'Happening now';
    if($delta<0)return 'Recently started';
    if($delta<3600)return 'Starts in '.max(1,(int)ceil($delta/60)).' min';
    if($delta<86400)return 'Starts in '.max(1,(int)round($delta/3600)).' hr';
    return 'Starts in '.max(1,(int)round($delta/86400)).' day'.((int)round($delta/86400)===1?'':'s');
}

function vp3_cognitive_feed_state_map_v530(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_feed_schema_ready_v530($pdo))return [];
    $stmt=$pdo->prepare('SELECT item_key,hidden_fingerprint,hidden_at FROM cognitive_feed_item_state_v530 WHERE owner_user_id=? AND agent_namespace=?');
    $stmt->execute([(int)$user['id'],$namespace]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row)$out[(string)$row['item_key']]=$row;
    return $out;
}

function vp3_cognitive_feed_hide_v530(PDO $pdo,array $user,string $namespace,string $itemKey,string $fingerprint): void
{
    if(!vp3_cognitive_feed_schema_ready_v530($pdo))throw new RuntimeException('Cognitive Feed schema is not ready.');
    $itemKey=mb_strimwidth(trim($itemKey),0,190,'');$fingerprint=strtolower(trim($fingerprint));
    if($itemKey===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))throw new InvalidArgumentException('Feed item identity is invalid.');
    $pdo->prepare("INSERT INTO cognitive_feed_item_state_v530 (owner_user_id,agent_namespace,item_key,hidden_fingerprint,hidden_at)
      VALUES (?,?,?,?,UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE hidden_fingerprint=VALUES(hidden_fingerprint),hidden_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()")
      ->execute([(int)$user['id'],$namespace,$itemKey,$fingerprint]);
}

function vp3_cognitive_feed_restore_v530(PDO $pdo,array $user,string $namespace,string $itemKey=''): void
{
    if(!vp3_cognitive_feed_schema_ready_v530($pdo))return;
    if($itemKey===''){
        $pdo->prepare('DELETE FROM cognitive_feed_item_state_v530 WHERE owner_user_id=? AND agent_namespace=?')
            ->execute([(int)$user['id'],$namespace]);
        return;
    }
    $pdo->prepare('DELETE FROM cognitive_feed_item_state_v530 WHERE owner_user_id=? AND agent_namespace=? AND item_key=?')
        ->execute([(int)$user['id'],$namespace,mb_strimwidth(trim($itemKey),0,190,'')]);
}

function vp3_cognitive_feed_observation_candidates_v530(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_schema_ready_v500($pdo))return [];
    $out=[];$context=vp3_cognitive_presentation_context_v500($pdo,$user,[
        'idle_minutes'=>0,'voice_candidate_allowed'=>false,'interruptible'=>true,'attention_budget_remaining'=>true,
    ]);
    foreach(vp3_cognitive_recent_observations_v500($pdo,$user,$namespace,40) as $obs){
        if(!is_array($obs))continue;
        $decision=vp3_cognitive_presentation_decide_v500($obs,$context);
        $surface=(string)($decision['surface']??'none');
        if(in_array($surface,['none','memory'],true))continue;

        $score=min(100,
            ((float)($obs['urgency']??0)*30)+
            ((float)($obs['impact']??0)*25)+
            ((float)($obs['goal_relevance']??0)*20)+
            ((float)($obs['confidence']??0)*15)+
            ((float)($obs['novelty']??0)*10)
        );
        $category=(string)($obs['category']??'');
        $attention=in_array($surface,['notification','ask_user','voice_announce'],true)||((float)($obs['urgency']??0)>=0.85&&$score>=65);
        $section=$attention?'attention':(in_array($category,['opportunity','recommendation','action_plan','decision_support'],true)?'opportunities':'priorities');

        $requests=[];
        foreach((array)($obs['proposed_cards']??[]) as $request){
            if(is_array($request)&&isset($request['card_type'],$request['object_ref']))$requests[]=$request;
        }
        if(!$requests){
            $type=match($category){
                'risk'=>'risk','commitment'=>'commitment','decision_support'=>'decision',
                'opportunity','recommendation','action_plan'=>'opportunity',
                default=>'',
            };
            if($type!=='')$requests[]=vp3_cognitive_feed_request_v530($type,(string)$obs['id'],'personal','standard');
        }
        foreach(array_slice($requests,0,2) as $index=>$request){
            try{$request=vp3_cognitive_validate_card_request_v500($request);}catch(Throwable $e){continue;}
            $key='observation:'.(string)$obs['id'].':'.$index;
            $candidate=vp3_cognitive_feed_candidate_v530(
                $key,$section,$score,(string)($obs['reason']??''),$request,'cognitive_observation',
                (string)($obs['updated_at']??''),[
                    'category'=>$category,'surface'=>$surface,'confidence'=>$obs['confidence']??0,
                    'urgency'=>$obs['urgency']??0,'impact'=>$obs['impact']??0,'goal_relevance'=>$obs['goal_relevance']??0,
                ],$attention
            );
            $candidate['planning_action_ids']=array_values(array_filter(array_map(
                static fn($v)=>vp3_cognitive_id_v500($v,120),(array)($obs['proposed_action_ids']??[])
            )));
            $out[]=$candidate;
        }
    }
    return $out;
}

function vp3_cognitive_feed_meeting_candidates_v530(PDO $pdo,array $user): array
{
    if(!function_exists('video_meeting_upcoming_for_user_v1800'))return [];
    $out=[];$uid=(int)$user['id'];$now=time();
    foreach(array_slice(video_meeting_upcoming_for_user_v1800($pdo,$uid,8),0,6) as $meeting){
        $id=(int)($meeting['id']??0);if($id<1)continue;
        $start=strtotime((string)($meeting['start_at_utc']??''))?:$now;
        $minutes=(int)floor(($start-$now)/60);$status=(string)($meeting['status']??'scheduled');
        $attention=$status==='live'||($minutes<=15&&$minutes>=-120);
        $brief=$status!=='live'&&$minutes<=1440&&$minutes>=0;
        $cardType=$brief?'meeting_brief':'meeting';
        $timeBucket=$status==='live'?'live':($minutes<=15?'imminent':($minutes<=60?'hour':($minutes<=1440?'day':'future')));
        $score=$status==='live'?99:($minutes<=15?96:($minutes<=60?91:($minutes<=1440?82:68)));
        $out[]=vp3_cognitive_feed_candidate_v530(
            'meeting:'.$id,$attention?'attention':'next_up',$score,vp3_cognitive_feed_relative_time_v530((string)$meeting['start_at_utc']),
            vp3_cognitive_feed_request_v530($cardType,$id,'personal','standard'),
            'meeting',(string)($meeting['updated_at']??$meeting['start_at_utc']??''),[
                'status'=>$status,'start_at_utc'=>$meeting['start_at_utc']??'','time_bucket'=>$timeBucket,
                'calendar_event_id'=>(int)($meeting['calendar_event_id']??0),'booking_id'=>(int)($meeting['booking_id']??0),
            ],$attention
        );
    }
    return $out;
}

function vp3_cognitive_feed_calendar_candidates_v530(PDO $pdo,array $user): array
{
    if(!function_exists('user_calendar_events_v1300'))return [];
    $from=gmdate('Y-m-d H:i:s',time()-3600);
    $to=gmdate('Y-m-d H:i:s',time()+(VP3_COGNITIVE_FEED_LOOKAHEAD_DAYS_V530*86400));
    $out=[];$now=time();
    foreach(array_slice(user_calendar_events_v1300($pdo,$user,$from,$to),0,12) as $event){
        $kind=(string)($event['kind']??'event');$id=(int)($event['id']??0);if($id<1)continue;
        try{
            if($kind==='event'&&function_exists('video_meeting_for_calendar_event_v1800')&&video_meeting_for_calendar_event_v1800($pdo,$id))continue;
            if($kind==='booking'&&function_exists('video_meeting_for_booking_v1800')&&video_meeting_for_booking_v1800($pdo,$id))continue;
        }catch(Throwable $e){}
        $start=strtotime((string)($event['start_at_utc']??''))?:$now;$minutes=(int)floor(($start-$now)/60);
        $attention=$minutes<=15&&$minutes>=-60;
        $timeBucket=$minutes<=15?'imminent':($minutes<=60?'hour':($minutes<=1440?'day':'future'));
        $score=$minutes<=15?90:($minutes<=60?84:($minutes<=1440?74:58));
        $objectId=($kind==='booking'?'booking:':'event:').$id;
        $out[]=vp3_cognitive_feed_candidate_v530(
            'calendar:'.$objectId,$attention?'attention':'next_up',$score,vp3_cognitive_feed_relative_time_v530((string)$event['start_at_utc']),
            vp3_cognitive_feed_request_v530('calendar_booking',$objectId,'personal','standard'),
            'calendar',(string)($event['start_at_utc']??''),['status'=>$event['status']??'','kind'=>$kind,'time_bucket'=>$timeBucket],$attention
        );
    }
    return $out;
}

function vp3_cognitive_feed_workflow_candidates_v530(PDO $pdo,array $user): array
{
    if(function_exists('vp3_agent_work_queue_model_v172')){
        try{$queue=vp3_agent_work_queue_model_v172($pdo,$user,'UTC');}catch(Throwable $e){$queue=[];}
        if(!empty($queue['available'])&&is_array($queue['lanes']??null)){
            $out=[];
            $laneMeta=[
                'approval'=>['section'=>'attention','base'=>94,'attention'=>true],
                'failed_retry'=>['section'=>'attention','base'=>93,'attention'=>true],
                'blocked'=>['section'=>'attention','base'=>91,'attention'=>true],
                'scheduled'=>['section'=>'next_up','base'=>80,'attention'=>false],
                'active'=>['section'=>'priorities','base'=>78,'attention'=>false],
                'paused'=>['section'=>'priorities','base'=>61,'attention'=>false],
                'completed'=>['section'=>'recent','base'=>42,'attention'=>false],
            ];
            foreach($laneMeta as $lane=>$meta){
                $rows=is_array($queue['lanes'][$lane]??null)?$queue['lanes'][$lane]:[];
                if($lane==='completed')$rows=array_slice($rows,0,2);
                foreach($rows as $row){
                    if(!is_array($row))continue;
                    $id=(int)($row['id']??0);if($id<1)continue;
                    $priority=max(1,min(100,(int)($row['priority']??50)));
                    $score=min(99,(float)$meta['base']+($priority*.05));
                    $reason=vp3_cognitive_feed_text_v530($row['detail']??'Agent workflow.',420);
                    $candidate=vp3_cognitive_feed_candidate_v530(
                        'workflow:'.$id,(string)$meta['section'],$score,$reason,
                        vp3_cognitive_feed_request_v530('workflow',$id,'personal','standard'),
                        'workflow',(string)($row['updated_at']??''),[
                            'status'=>$row['status']??'',
                            'progress'=>$row['progress']??0,
                            'work_queue_lane'=>$lane,
                            'work_priority'=>$priority,
                            'target'=>$row['target']??'',
                            'next_attempt_at'=>$row['next_attempt_at']??'',
                            'blocked'=>!empty($row['blocked']),
                        ],!empty($meta['attention'])
                    );
                    $candidate['work_queue_lane']=$lane;
                    $candidate['work_priority']=$priority;
                    $out[]=$candidate;
                }
            }
            return $out;
        }
    }

    if(!function_exists('agent_workflow_active_summary_v1400'))return [];
    $out=[];
    foreach(agent_workflow_active_summary_v1400($pdo,$user,8) as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $status=strtolower((string)($row['status']??''));$attention=(bool)preg_match('/approval|blocked|failed|error/',$status);
        $section=$attention?'attention':'priorities';
        $score=$attention?92:(in_array($status,['running','executing','active'],true)?82:66);
        $reason=$attention?'Agent workflow needs attention.':'Active Agent workflow.';
        $out[]=vp3_cognitive_feed_candidate_v530(
            'workflow:'.$id,$section,$score,$reason,
            vp3_cognitive_feed_request_v530('workflow',$id,'personal','standard'),
            'workflow',(string)($row['updated_at']??''),['status'=>$status,'progress'=>$row['progress_percent']??null],$attention
        );
    }
    return $out;
}

function vp3_cognitive_feed_goal_candidates_v530(PDO $pdo,array $user): array
{
    if(!function_exists('agent_goal_list_v1710'))return [];
    $out=[];
    foreach(array_slice(agent_goal_list_v1710($pdo,$user,false),0,4) as $state){
        $goal=(array)($state['goal']??[]);$id=(int)($goal['id']??0);if($id<1)continue;
        $attention=max(0,min(100,(int)($goal['attention_score_percent']??0)));
        $priority=max(0,min(100,(int)($goal['priority']??0)));
        $score=min(88,48+($attention*.28)+($priority*.12));
        $out[]=vp3_cognitive_feed_candidate_v530(
            'goal:'.$id,'priorities',$score,'Active goal · '.((int)($goal['progress_percent']??0)).'% complete.',
            vp3_cognitive_feed_request_v530('goal',$id,'personal','standard'),
            'goal',(string)($goal['target_date']??''),[
                'status'=>$goal['status']??'','derived_status'=>$goal['derived_status']??'',
                'progress'=>$goal['progress_percent']??0,'attention'=>$attention,'priority'=>$priority,
            ],false
        );
    }
    return $out;
}

function vp3_cognitive_feed_brain_candidates_v530(array $user): array
{
    if(!function_exists('agent_cognitive_loop_v310_priority_items'))return [];
    $out=[];
    foreach(agent_cognitive_loop_v310_priority_items($user,5) as $row){
        $id=(string)($row['id']??'');if($id==='')continue;
        $risk=strtolower((string)($row['risk_level']??'low'));$attention=!empty($row['requires_approval'])||in_array($risk,['high','critical'],true);
        $score=max(50,min(94,50+((float)($row['score']??0)*40)+((int)($row['priority']??0)*.08)));
        $out[]=vp3_cognitive_feed_candidate_v530(
            'brain:'.$id,$attention?'attention':'priorities',$score,(string)($row['body']??''),
            vp3_cognitive_feed_request_v530('brain_priority',$id,'personal','standard'),
            'agent_brain',(string)($row['created_at']??''),[
                'score'=>$row['score']??0,'priority'=>$row['priority']??0,'movement'=>$row['movement']??'',
                'requires_approval'=>!empty($row['requires_approval']),
            ],$attention
        );
    }
    return $out;
}

function vp3_cognitive_feed_notification_candidates_v530(PDO $pdo,array $user): array
{
    if(!table_exists('notifications'))return [];
    $uid=(int)$user['id'];$predicate=function_exists('notification_system_sql_predicate')?notification_system_sql_predicate('n'):'1=1';
    try{
        $stmt=$pdo->prepare("SELECT n.* FROM notifications n WHERE n.user_id=? AND n.is_read=0 AND n.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND {$predicate} ORDER BY n.id DESC LIMIT 30");
        $stmt->execute([$uid]);$rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}
    $out=[];
    foreach($rows as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $attention=function_exists('notification_requires_attention')&&notification_requires_attention($row);
        $request=function_exists('vp3_cognitive_cards_notification_request_v520')
            ? vp3_cognitive_cards_notification_request_v520($row)
            : null;
        if(!is_array($request)){
            $request=vp3_cognitive_feed_request_v530('feed_activity',$id,'personal','compact');
        }else{
            $request['display_mode']='compact';
        }
        $score=$attention?86:46;
        $out[]=vp3_cognitive_feed_candidate_v530(
            'activity:'.$id,$attention?'attention':'recent',$score,
            $attention?'Unread update needs attention.':'Recent meaningful update.',
            $request,'notification',(string)($row['created_at']??''),[
                'type'=>$row['type']??'','source_type'=>$row['source_type']??'','source_id'=>$row['source_id']??0,
            ],$attention
        );
    }
    return $out;
}

function vp3_cognitive_feed_candidates_v530(PDO $pdo,array $user,string $namespace): array
{
    $base=array_merge(
        vp3_cognitive_feed_meeting_candidates_v530($pdo,$user),
        vp3_cognitive_feed_calendar_candidates_v530($pdo,$user),
        vp3_cognitive_feed_workflow_candidates_v530($pdo,$user),
        vp3_cognitive_feed_goal_candidates_v530($pdo,$user),
        vp3_cognitive_feed_brain_candidates_v530($user),
        vp3_cognitive_feed_notification_candidates_v530($pdo,$user)
    );

    if(function_exists('vp3_cognitive_opportunity_sync_v2320')){
        try{vp3_cognitive_opportunity_sync_v2320($pdo,$user,$namespace,$base);}catch(Throwable $e){}
    }

    $all=array_merge(
        vp3_cognitive_feed_observation_candidates_v530($pdo,$user,$namespace),
        $base
    );

    if(function_exists('vp3_cognitive_planning_schema_ready_v550')&&vp3_cognitive_planning_schema_ready_v550($pdo)){
        vp3_cognitive_planning_sync_v550($pdo,$user,$namespace,$all);
    }
    if(function_exists('vp3_cognitive_orchestration_schema_ready_v560')&&vp3_cognitive_orchestration_schema_ready_v560($pdo)){
        if(function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo)){
            vp3_cognitive_learning_reconcile_v540($pdo,$user,$namespace);
        }
        vp3_cognitive_orchestration_sync_v560($pdo,$user,$namespace);
    }
    if(function_exists('vp3_cognitive_planning_schema_ready_v550')&&vp3_cognitive_planning_schema_ready_v550($pdo)){
        $all=array_merge($all,vp3_cognitive_planning_feed_candidates_v550($pdo,$user,$namespace));
    }
    if(function_exists('vp3_cognitive_orchestration_schema_ready_v560')&&vp3_cognitive_orchestration_schema_ready_v560($pdo)){
        $all=array_merge($all,vp3_cognitive_orchestration_feed_candidates_v560($pdo,$user,$namespace));
    }

    $authorized=[];
    foreach($all as $candidate){
        if(!is_array($candidate)||!is_array($candidate['card_request']??null))continue;
        try{
            $request=vp3_cognitive_validate_card_request_v500($candidate['card_request']);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$request['object_ref'],'read'))continue;
            $candidate['card_request']=$request;$candidate['group_key']=vp3_cognitive_feed_card_group_v530($request,(string)$candidate['key']);
            $authorized[]=$candidate;
        }catch(Throwable $e){}
    }

    if(function_exists('vp3_cognitive_memory_schema_ready_v570')&&vp3_cognitive_memory_schema_ready_v570($pdo)){
        vp3_cognitive_memory_sync_v570($pdo,$user,$namespace,$authorized);
        foreach(vp3_cognitive_memory_feed_candidates_v570($pdo,$user,$namespace) as $candidate){
            try{
                $request=vp3_cognitive_validate_card_request_v500($candidate['card_request']);
                if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$request['object_ref'],'read'))continue;
                $candidate['card_request']=$request;
                $candidate['group_key']=vp3_cognitive_feed_card_group_v530($request,(string)$candidate['key']);
                $authorized[]=$candidate;
            }catch(Throwable $e){}
        }
    }
    return $authorized;
}

function vp3_cognitive_feed_merge_candidates_v530(array $candidates): array
{
    usort($candidates,static function(array $a,array $b): int {
        $score=((float)($b['score']??0))<=>((float)($a['score']??0));
        if($score!==0)return $score;
        return strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??''));
    });
    $groups=[];
    foreach($candidates as $candidate){
        $group=(string)($candidate['group_key']??$candidate['key']??'');
        if($group==='')continue;
        if(!isset($groups[$group])){$groups[$group]=$candidate;continue;}
        $current=&$groups[$group];
        $current['attention']=!empty($current['attention'])||!empty($candidate['attention']);
        $current['signals']=array_values(array_unique(array_merge((array)($current['signals']??[]),(array)($candidate['signals']??[]))));
        if(!empty($current['attention'])&&$current['section']!=='attention')$current['section']='attention';
        if((float)$candidate['score']>(float)$current['score']){
            $signals=$current['signals'];$attention=$current['attention'];$current=$candidate;
            $current['signals']=$signals;$current['attention']=$attention;
            if($attention)$current['section']='attention';
        }
        unset($current);
    }
    return array_values($groups);
}

function vp3_cognitive_feed_compose_v530(PDO $pdo,array $user,string $namespace,bool $includeHidden=false): array
{
    if(!vp3_cognitive_feed_schema_ready_v530($pdo))throw new RuntimeException('Cognitive Feed schema is not ready.');
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $state=vp3_cognitive_feed_state_map_v530($pdo,$user,$namespace);
    $candidates=vp3_cognitive_feed_merge_candidates_v530(vp3_cognitive_feed_candidates_v530($pdo,$user,$namespace));
    if(function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo)){
        // Reconciliation already runs once before orchestration sync in
        // vp3_cognitive_feed_candidates_v530(). Observing current candidates
        // here must not trigger a second full lifecycle reconciliation in the
        // same read/refresh cycle.
        vp3_cognitive_learning_observe_candidates_v540($pdo,$user,$namespace,$candidates);
        foreach($candidates as &$candidate){
            $candidate=vp3_cognitive_learning_adjust_candidate_v540($pdo,$user,$namespace,$candidate);
        }
        unset($candidate);
    }

    $visible=[];$hidden=0;
    foreach($candidates as $candidate){
        $saved=$state[(string)$candidate['key']]??null;
        $isHidden=is_array($saved)&&hash_equals((string)($saved['hidden_fingerprint']??''),(string)$candidate['fingerprint']);
        if($isHidden){$hidden++;if(!$includeHidden)continue;$candidate['hidden']=true;}
        else $candidate['hidden']=false;
        $visible[]=$candidate;
    }

    $order=['attention','next_up','priorities','opportunities','recent'];
    $caps=['attention'=>4,'next_up'=>2,'priorities'=>3,'opportunities'=>2,'recent'=>1];
    $labels=[
        'attention'=>['label'=>'Needs attention','description'=>'Current items that may need a decision, approval, or timely response.'],
        'next_up'=>['label'=>'Next up','description'=>'Upcoming meetings, bookings, and scheduled work.'],
        'priorities'=>['label'=>'Priorities','description'=>'Current goals and active Agent work with the strongest relevance.'],
        'opportunities'=>['label'=>'Opportunities','description'=>'Evidence-backed suggestions worth considering.'],
        'recent'=>['label'=>'Recent changes','description'=>'Meaningful unread activity not already represented above.'],
    ];

    $sections=[];$selectedForQueue=[];$used=0;
    foreach($order as $section){
        $items=array_values(array_filter($visible,static fn(array $c): bool=>(string)($c['section']??'')===$section&&!$c['hidden']));
        usort($items,static fn(array $a,array $b): int=>((float)$b['score']<=>((float)$a['score']))?:strcmp((string)$b['updated_at'],(string)$a['updated_at']));
        $room=max(0,VP3_COGNITIVE_FEED_MAX_ITEMS_V530-$used);
        if($room<1)break;
        $items=array_slice($items,0,min($caps[$section],$room));
        if(!$items)continue;
        foreach($items as $selected)$selectedForQueue[]=$selected;
        foreach($items as &$item){
            unset($item['score']);
            unset($item['learning_adjustment']);
            unset($item['planning_action_ids']);
            $item['signals']=array_values(array_filter(array_map(static fn($v)=>vp3_cognitive_id_v500($v,80),(array)$item['signals'])));
        }unset($item);
        $sections[]=['id'=>$section]+$labels[$section]+['items'=>$items];
        $used+=count($items);
    }

    $priorityQueue=null;
    if(function_exists('vp3_cognitive_priority_queue_compose_v2310')){
        $priorityQueue=vp3_cognitive_priority_queue_compose_v2310($pdo,$user,$namespace,$selectedForQueue);
    }

    $operations=null;
    if(function_exists('vp3_cognitive_operations_compose_v2300')){
        $operations=vp3_cognitive_operations_compose_v2300($visible);
        if(function_exists('vp3_cognitive_operations_public_v2300')){
            $operations=vp3_cognitive_operations_public_v2300($operations);
        }
    }

    return [
        'build'=>VP3_COGNITIVE_FEED_V530,
        'agent_namespace'=>$namespace,
        'operations'=>$operations,
        'priority_queue'=>$priorityQueue,
        'generated_at'=>gmdate(DATE_ATOM),
        'sections'=>$sections,
        'item_count'=>$used,
        'hidden_count'=>$hidden,
        'has_attention'=>(bool)array_filter($sections,static fn($s)=>(string)$s['id']==='attention'),
        'refresh_seconds'=>60,
    ];
}

function vp3_cognitive_feed_find_candidate_v530(PDO $pdo,array $user,string $namespace,string $itemKey): ?array
{
    foreach(vp3_cognitive_feed_candidates_v530($pdo,$user,$namespace) as $candidate){
        if(hash_equals((string)$candidate['key'],$itemKey))return $candidate;
    }
    return null;
}
