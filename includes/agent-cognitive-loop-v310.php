<?php
declare(strict_types=1);

/**
 * VP3 Agent Brain cognitive loop v310.
 *
 * One bounded server-owned loop turns the existing product signals into the
 * Agent Brain's current prioritized state. Chat and voice are presentation
 * surfaces; they consume this Brain state rather than owning separate logic.
 *
 * OBSERVE -> CORRELATE -> REMEMBER -> PRIORITIZE -> PLAN -> SURFACE -> LEARN
 */
const VP3_AGENT_COGNITIVE_LOOP_V310 = 'agent-brain-cognitive-loop-v310-20260907';
const VP3_AGENT_COGNITIVE_LOOP_INTERVAL_SECONDS_V310 = 300;
const VP3_AGENT_COGNITIVE_LOOP_STATE_MAX_AGE_SECONDS_V310 = 1800;
const VP3_AGENT_COGNITIVE_LOOP_PRIORITY_LIMIT_V310 = 6;
const VP3_AGENT_COGNITIVE_LOOP_SURFACE_THRESHOLD_V310 = 0.72;

function agent_cognitive_loop_v310_user(int $userId): ?array
{
    $pdo=db();if(!$pdo||$userId<1||!table_exists('users'))return null;
    try{$stmt=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();return is_array($row)?$row:null;}catch(Throwable $e){return null;}
}

function agent_cognitive_loop_v310_memory(array $user): ?array
{
    return function_exists('agent_brain_v122_memory')?agent_brain_v122_memory($user,'cognitive_state','main-loop'):null;
}

function agent_cognitive_loop_v310_state(array $user): array
{
    $row=agent_cognitive_loop_v310_memory($user);$meta=is_array($row['metadata']??null)?$row['metadata']:[];
    if(!$meta)return ['active'=>false,'build'=>VP3_AGENT_COGNITIVE_LOOP_V310,'priorities'=>[],'last_run_at'=>'','signature'=>''];
    $meta['active']=true;$meta['build']=(string)($meta['build']??VP3_AGENT_COGNITIVE_LOOP_V310);
    $meta['priorities']=array_values(array_filter((array)($meta['priorities']??[]),'is_array'));
    return $meta;
}

function agent_cognitive_loop_v310_state_fresh(array $state,int $maxAge=VP3_AGENT_COGNITIVE_LOOP_STATE_MAX_AGE_SECONDS_V310): bool
{
    $last=strtotime((string)($state['last_run_at']??''));
    return $last!==false&&$last>0&&time()-$last<=max(60,$maxAge);
}

function agent_cognitive_loop_v310_since(array $prior): string
{
    $last=strtotime((string)($prior['last_run_at']??''));
    if($last===false||$last<1)return date('Y-m-d H:i:s',time()-86400);
    return date('Y-m-d H:i:s',max(time()-7*86400,$last-300));
}

function agent_cognitive_loop_v310_notification_priority(string $type): int
{
    $type=strtolower(trim($type));
    if(str_contains($type,'security_action'))return 200;
    if(str_contains($type,'access_request')||str_contains($type,'approval'))return 190;
    if($type==='radar_agent_conversion')return 186;
    if($type==='analytics_traffic_spike')return 180;
    if($type==='radar_agent_opportunity')return 176;
    if($type==='profile_conversation_started')return 172;
    if($type==='radar_agent_watchlist')return 158;
    if(str_starts_with($type,'profile_'))return 132;
    return 150;
}

function agent_cognitive_loop_v310_notification_signals(PDO $pdo,array $user,string $since): array
{
    $uid=(int)($user['id']??0);if($uid<1||!table_exists('notifications'))return [];
    try{
        $stmt=$pdo->prepare('SELECT * FROM notifications WHERE user_id=? AND created_at>? ORDER BY id DESC LIMIT 100');$stmt->execute([$uid,$since]);$rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}
    $always=['analytics_traffic_spike','radar_agent_opportunity','radar_agent_conversion','radar_agent_watchlist','radar_agent_access_request','radar_security_action','radar_external_security_action'];
    $signals=[];
    foreach($rows as $row){
        if(function_exists('notification_is_agent_brain_activity')&&notification_is_agent_brain_activity($row))continue;
        $type=(string)($row['type']??'');$attention=function_exists('notification_requires_attention')&&notification_requires_attention($row);
        if(!$attention&&!in_array($type,$always,true))continue;
        $title=trim((string)($row['title']??''))?:'Account update';$body=trim((string)($row['body']??''));
        $priority=agent_cognitive_loop_v310_notification_priority($type);
        $signals[]=[
            'hash'=>sha1('notification|'.(int)$row['id']),'key'=>'notification:'.(int)$row['id'],'title'=>$title,
            'prompt'=>'Review this VP3 system signal and help me take the best next action: '.($body!==''?$body:$title),
            'reason'=>$body!==''?$body:'VP3 system event requiring review','priority'=>$priority,'score'=>round(min(1,$priority/200),4),
            'source'=>$type!==''?$type:'notification','url'=>(string)($row['target_url']??''),'notification_id'=>(int)$row['id'],
            'created_at'=>(string)($row['created_at']??date('Y-m-d H:i:s')),
        ];
    }
    return $signals;
}

function agent_cognitive_loop_v310_analytics_candidates(array $signals): array
{
    $out=[];
    foreach($signals as $signal){if(!is_array($signal))continue;
        $title=trim((string)($signal['title']??''));$reason=trim((string)($signal['reason']??$signal['body']??''));if($title==='')continue;
        $key=(string)($signal['key']??sha1($title.'|'.$reason));$prompt=trim((string)($signal['prompt']??''));
        $out[]=$signal+[
            'hash'=>sha1('analytics|'.$key.'|'.$title),'key'=>$key,'title'=>$title,
            'prompt'=>$prompt!==''?$prompt:'Review this Analytics signal, correlate it with the rest of VP3, and recommend the next useful action.',
            'reason'=>$reason,'priority'=>(int)($signal['priority']??160),'score'=>(float)($signal['score']??0.8),'source'=>'analytics','url'=>(string)($signal['target_url']??$signal['url']??''),
        ];
    }
    return $out;
}

function agent_cognitive_loop_v310_base_candidates(array $user,array $context=[]): array
{
    if(!function_exists('agent_proactive_v123_suggestions'))return [];
    try{
        $result=agent_proactive_v123_suggestions($user,'brain',$context);
        return array_values(array_filter((array)($result['suggestions']??[]),'is_array'));
    }catch(Throwable $e){return [];}
}

function agent_cognitive_loop_v310_rank(array $user,array $candidates,array $activity): array
{
    $uid=(int)($user['id']??0);$dedup=[];
    foreach($candidates as $candidate){
        if(!is_array($candidate))continue;$source=(string)($candidate['source']??'agent_brain');
        if(in_array($source,['starter','fallback','activity_working'],true))continue;
        $title=trim((string)($candidate['title']??''));if($title==='')continue;
        $key=(string)($candidate['key']??$candidate['hash']??sha1($source.'|'.$title));
        $candidate['key']=$key;$candidate['hash']=(string)($candidate['hash']??sha1($source.'|'.$key.'|'.$title));
        $score=(float)($candidate['score']??0);
        if($score<=0)$score=max(0,min(1,(int)($candidate['priority']??100)/200));
        if(function_exists('agent_action_v124_source_feedback')&&function_exists('agent_action_v124_outcome_factor')){
            $feedback=agent_action_v124_source_feedback($uid,$source);$factor=agent_action_v124_outcome_factor($feedback);$score=max(0,min(1,$score*$factor));$candidate['outcome_factor']=round($factor,4);
        }
        $candidate['score']=round($score,6);$candidate['priority']=(int)round($score*200);
        if(function_exists('agent_action_v124_suppression')){
            $suppression=agent_action_v124_suppression($candidate,$uid);$candidate['suppression']=$suppression;if(!empty($suppression['suppressed']))continue;
        }
        $event=[
            'id'=>'cognitive-event-'.sha1($source.'|'.$key),'type'=>'event','event_kind'=>'cognitive_signal','source'=>$source,
            'title'=>(string)($candidate['reason']??$title),'summary'=>(string)($candidate['reason']??''),'occurred_at'=>(string)($candidate['created_at']??date('Y-m-d H:i:s')),'evidence'=>['candidate_key'=>$key],
        ];
        $candidate['event_id']=$event['id'];$candidate['action_id']='cognitive-action-'.sha1($event['id'].'|'.$title);
        if(function_exists('agent_action_v124_risk'))$candidate['risk']=agent_action_v124_risk($candidate);
        if(function_exists('agent_action_v124_plan'))$candidate['plan']=agent_action_v124_plan($candidate,$event,[]);
        $existing=$dedup[$key]??null;if(!$existing||(float)$candidate['score']>(float)$existing['score'])$dedup[$key]=$candidate;
    }
    $ranked=array_values($dedup);
    usort($ranked,static function(array $a,array $b):int{
        $score=(float)($b['score']??0)<=>(float)($a['score']??0);if($score!==0)return $score;
        $priority=(int)($b['priority']??0)<=>(int)($a['priority']??0);if($priority!==0)return $priority;
        return strcmp((string)($a['title']??''),(string)($b['title']??''));
    });
    return array_slice($ranked,0,VP3_AGENT_COGNITIVE_LOOP_PRIORITY_LIMIT_V310);
}

function agent_cognitive_loop_v310_public_priority(array $item): array
{
    $risk=is_array($item['risk']??null)?$item['risk']:[];
    return [
        'key'=>(string)($item['key']??''),'title'=>mb_strimwidth((string)($item['title']??''),0,140,'…'),
        'reason'=>mb_strimwidth((string)($item['reason']??''),0,420,'…'),'prompt'=>mb_strimwidth((string)($item['prompt']??''),0,900,'…'),
        'source'=>(string)($item['source']??'agent_brain'),'url'=>(string)($item['url']??$item['target_url']??''),
        'score'=>round((float)($item['score']??0),4),'priority'=>(int)($item['priority']??0),
        'risk_level'=>(string)($risk['level']??'low'),'requires_approval'=>!empty($risk['requires_approval']),
        'event_id'=>(string)($item['event_id']??''),'action_id'=>(string)($item['action_id']??''),
    ];
}

function agent_cognitive_loop_v310_signature(array $priorities): string
{
    $parts=[];foreach(array_slice($priorities,0,3) as $item)$parts[]=(string)($item['key']??'').'|'.(string)($item['risk_level']??'');
    return sha1(implode("\n",$parts));
}

function agent_cognitive_loop_v310_summary(array $priorities): string
{
    if(!$priorities)return 'VP3 Agent Brain cognitive loop is active. No high-value priority currently exceeds the noise floor.';
    $lines=['VP3 Agent Brain cognitive loop is active. Current priorities:'];$index=1;
    foreach(array_slice($priorities,0,5) as $item){$lines[]=$index.'. '.(string)$item['title'].' — '.(string)$item['reason'];$index++;}
    return mb_strimwidth(implode("\n",$lines),0,5800,'…');
}

function agent_cognitive_loop_v310_surface(array $user,array $prior,array &$state): bool
{
    $priorities=(array)($state['priorities']??[]);$top=$priorities[0]??null;if(!is_array($top))return false;
    $priorSignature=(string)($prior['signature']??'');$signature=(string)($state['signature']??'');
    if($priorSignature===''||$signature===''||hash_equals($priorSignature,$signature))return false;
    if((float)($top['score']??0)<VP3_AGENT_COGNITIVE_LOOP_SURFACE_THRESHOLD_V310)return false;
    $lastSurface=strtotime((string)($prior['last_surface_at']??''))?:0;
    $lastSurfaceSignature=(string)($prior['last_surface_signature']??'');
    if($lastSurfaceSignature===$signature&&$lastSurface>0&&time()-$lastSurface<1800)return false;
    $activity=is_array($state['activity']??null)?$state['activity']:[];$interruptible=!array_key_exists('interruptible',$activity)||!empty($activity['interruptible']);
    if(!$interruptible&&(float)($top['score']??0)<0.90)return false;
    if(!function_exists('agent_chat_v101_append_ecosystem_message'))return false;

    $lines=['Agent Brain priority update'];$actions=[];$voice=[];$i=1;
    foreach(array_slice($priorities,0,3) as $item){
        $lines[]=$i.'. '.(string)$item['title'].' — '.(string)$item['reason'];
        if($i===1)$voice[]='Your top priority changed to '.(string)$item['title'].'. '.(string)$item['reason'];
        $url=trim((string)($item['url']??''));if($url!=='')$actions[]=['label'=>'Open '.mb_strimwidth((string)$item['title'],0,72,'…'),'url'=>$url];$i++;
    }
    $lines[]='I’ll keep these prioritized until the underlying evidence changes.';
    $conversationId=agent_chat_v101_append_ecosystem_message($user,implode("\n",$lines),[
        'source'=>'agent_cognitive_loop','source_label'=>'Agent Brain','cognitive_priority'=>true,'cognitive_signature'=>$signature,
        'voice_summary'=>mb_strimwidth(implode(' ',$voice),0,360,'…'),'actions'=>$actions,'sources'=>[['source'=>'agent-brain:cognitive-loop','title'=>'Agent Brain priority state']],
        'skip_brain_archive'=>true,'generated_at'=>gmdate('c'),
    ]);
    if($conversationId<1)return false;
    $state['last_surface_at']=gmdate('c');$state['last_surface_signature']=$signature;$state['last_surface_conversation_id']=$conversationId;
    return true;
}

function agent_cognitive_loop_v310_run(array $user): array
{
    $pdo=db();$uid=(int)($user['id']??0);$started=microtime(true);$prior=agent_cognitive_loop_v310_state($user);
    if(!$pdo||$uid<1||!function_exists('agent_brain_v122_upsert_system_memory'))return ['ok'=>false,'reason'=>'brain-unavailable'];
    $since=agent_cognitive_loop_v310_since($prior);$refresh=['relationships'=>0,'watchlist_notifications'=>0];

    // OBSERVE + CORRELATE: refresh canonical relationship state first so CRM,
    // Radar and messaging evidence is current before ranking anything.
    if(function_exists('agent_memory_v123_reconcile_user')){try{agent_memory_v123_reconcile_user($user);}catch(Throwable $e){}}
    if(function_exists('vp3_agent_relationship_refresh_owner')){try{$rows=vp3_agent_relationship_refresh_owner($pdo,$user,120,true);$refresh['relationships']=count((array)$rows);}catch(Throwable $e){}}
    if(function_exists('vp3_agent_crm_watchlist_refresh')){try{$refresh['watchlist_notifications']=vp3_agent_crm_watchlist_refresh($pdo,$user,7);}catch(Throwable $e){}}

    $analytics=function_exists('vp3_analytics_intelligence_signals_v310')?vp3_analytics_intelligence_signals_v310($pdo,$user,true):[];
    $context=function_exists('agent_brain_v122_activity_context')?agent_brain_v122_activity_context($user):[];
    $activity=function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,'brain',$context):['state'=>'idle','interruptible'=>true,'task_title'=>''];
    $candidates=array_merge(
        agent_cognitive_loop_v310_base_candidates($user,$context),
        agent_cognitive_loop_v310_notification_signals($pdo,$user,$since),
        agent_cognitive_loop_v310_analytics_candidates($analytics)
    );

    // PRIORITIZE + PLAN: reuse existing scoring feedback, suppression and risk
    // semantics, then store only a small current working set in Agent Brain.
    $ranked=agent_cognitive_loop_v310_rank($user,$candidates,$activity);
    $priorities=array_map('agent_cognitive_loop_v310_public_priority',$ranked);$signature=agent_cognitive_loop_v310_signature($priorities);
    $state=[
        'active'=>true,'build'=>VP3_AGENT_COGNITIVE_LOOP_V310,'loop'=>'observe-correlate-remember-prioritize-plan-surface-learn',
        'last_run_at'=>gmdate('c'),'since'=>$since,'signature'=>$signature,'priorities'=>$priorities,'candidate_count'=>count($candidates),
        'analytics_signal_count'=>count($analytics),'refresh'=>$refresh,'activity'=>$activity,
        'last_surface_at'=>(string)($prior['last_surface_at']??''),'last_surface_signature'=>(string)($prior['last_surface_signature']??''),
        'last_surface_conversation_id'=>(int)($prior['last_surface_conversation_id']??0),
        'duration_ms'=>0,
    ];
    $state['duration_ms']=(int)round((microtime(true)-$started)*1000);
    $surfaced=agent_cognitive_loop_v310_surface($user,$prior,$state);$state['surfaced']=$surfaced;
    $text=agent_cognitive_loop_v310_summary($priorities);
    $memoryId=agent_brain_v122_upsert_system_memory($user,'cognitive_state','main-loop',$text,$state,0.99);
    if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('brain.cognitive_loop',['user_id'=>$uid,'priorities'=>count($priorities),'candidates'=>count($candidates),'analytics'=>count($analytics),'surfaced'=>$surfaced,'duration_ms'=>$state['duration_ms']]);
    return ['ok'=>$memoryId>0,'memory_id'=>$memoryId,'state'=>$state];
}

function agent_cognitive_loop_v310_priority_items(array $user,int $limit=6): array
{
    $state=agent_cognitive_loop_v310_state($user);if(!agent_cognitive_loop_v310_state_fresh($state))return [];$items=[];
    foreach(array_slice((array)$state['priorities'],0,max(1,min(10,$limit))) as $index=>$priority){if(!is_array($priority))continue;
        $items[]=[
            'id'=>'brain-priority-'.sha1((string)($priority['key']??$index)),'type'=>'opportunity','title'=>(string)($priority['title']??'Agent Brain priority'),
            'body'=>(string)($priority['reason']??''),'target_url'=>(string)($priority['url']??''),'created_at'=>(string)($state['last_run_at']??date('Y-m-d H:i:s')),
            'priority'=>(int)($priority['priority']??round((float)($priority['score']??0)*200)),'source'=>'agent_brain_cognitive','key'=>(string)($priority['key']??''),
        ];
    }
    return $items;
}

function agent_cognitive_loop_v310_boot(): void
{
    static $booted=false;if($booted)return;$booted=true;
    if(PHP_SAPI==='cli'||!function_exists('current_user')||!function_exists('agent_background_v125_enqueue'))return;
    try{
        $user=current_user();if(!$user||empty($user['id'])||(function_exists('has_permission')&&!has_permission('chat.access',$user)))return;
        $state=agent_cognitive_loop_v310_state($user);$last=strtotime((string)($state['last_run_at']??''))?:0;
        if($last>0&&time()-$last<VP3_AGENT_COGNITIVE_LOOP_INTERVAL_SECONDS_V310)return;
        $uid=(int)$user['id'];$bucket=(int)floor(time()/VP3_AGENT_COGNITIVE_LOOP_INTERVAL_SECONDS_V310);
        agent_background_v125_enqueue('cognitive-loop',['user_id'=>$uid],'cognitive-loop-'.$uid.'-'.$bucket,0);
    }catch(Throwable $e){if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('brain.cognitive_loop.schedule_failed',['error_class'=>get_class($e)]);}
}
