<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.90 — Unified Current State.
 *
 * This layer materializes a bounded, ephemeral view over existing authorities.
 * It does not create another event ledger, live-session store, Brain, memory
 * store, scheduler, queue, worker, approval system, or execution authority.
 */
const VP3_COGNITIVE_CURRENT_STATE_V2590='vp3-cognitive-current-state-v2590-20260923';
const VP3_COGNITIVE_CURRENT_STATE_MAX_EVENTS_V2590=60;
const VP3_COGNITIVE_CURRENT_STATE_MAX_ITEMS_V2590=12;
const VP3_COGNITIVE_CURRENT_STATE_FRESH_SECONDS_V2590=21600;

function vp3_cognitive_current_state_text_v2590(mixed $value,int $limit=240): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    if(function_exists('vp3_cognitive_text_v500'))return vp3_cognitive_text_v500($text,$limit);
    return mb_strimwidth($text,0,max(1,$limit),'…');
}

function vp3_cognitive_current_state_domain_v2590(string $source,string $eventType): string
{
    $source=strtolower(trim($source));
    $eventType=strtolower(trim($eventType));
    $prefix=str_contains($eventType,'.')?strstr($eventType,'.',true):$eventType;
    return match($prefix){
        'session','chat'=>'session',
        'profile'=>'profile_agent',
        'schedule'=>'scheduling',
        'booking','appointment'=>'booking_appointments',
        'calendar'=>'calendar',
        'order','refund','product','commerce'=>'commerce',
        'lead','crm','relationship','contact'=>'crm_relationships',
        'meeting'=>'meetings',
        'browser'=>'browser_operations',
        'research','knowledge'=>'research_knowledge',
        'workflow','approval','tool','goal','commitment'=>'workflow_tools_approvals',
        'team','message','messaging'=>'messaging_team',
        'homeserver','device','room'=>'homeserver_operations',
        'media','recording','transcription','studio'=>'media_studio',
        'analytics','conversion','referral','attribution'=>'analytics_attribution',
        'notification'=>'notifications',
        'billing','subscription','payment'=>'subscription_billing',
        'release','client_release'=>'client_release',
        default=>str_contains($source,'browser')?'browser_operations':(
            str_contains($source,'meeting')?'meetings':(
            str_contains($source,'calendar')?'calendar':(
            str_contains($source,'commerce')?'commerce':(
            str_contains($source,'research')?'research_knowledge':'external'))))
    };
}

function vp3_cognitive_current_state_label_v2590(string $eventType): string
{
    $eventType=trim($eventType);
    if($eventType==='')return 'Recent activity';
    $parts=explode('.',$eventType,2);
    $tail=$parts[1]??$parts[0];
    $tail=str_replace(['_','-'],' ',$tail);
    return ucfirst(vp3_cognitive_current_state_text_v2590($tail,120));
}

function vp3_cognitive_current_state_refs_v2590(array $payload): array
{
    $allowed=[
        'goal_id','task_id','project_id','conversation_id','meeting_id','booking_id',
        'appointment_id','calendar_event_id','order_id','product_id','contact_id',
        'relationship_id','workflow_id','run_id','commitment_id','notification_id',
        'research_project_id','source_id','annotation_id','recording_id',
        'transcription_id','device_id','room_id','release_id','object_type','object_id',
        'surface','state','status'
    ];
    $refs=[];
    foreach($allowed as $key){
        if(!array_key_exists($key,$payload))continue;
        $value=$payload[$key];
        if(is_int($value)||is_float($value)||is_bool($value)){
            $refs[$key]=$value;
            continue;
        }
        if(is_string($value)){
            $text=vp3_cognitive_current_state_text_v2590($value,120);
            if($text!=='')$refs[$key]=$text;
        }
    }
    return $refs;
}

function vp3_cognitive_current_state_event_attention_v2590(string $eventType,string $processingStatus): bool
{
    $eventType=strtolower($eventType);
    $processingStatus=strtolower($processingStatus);
    if($processingStatus==='failed')return true;
    if((bool)preg_match('/(?:^|\.)(?:failed|blocked|payment_failed|approval_requested|requested)$/',$eventType))return true;
    return str_contains($eventType,'approval.requested')||str_contains($eventType,'billing.payment_failed');
}

function vp3_cognitive_current_state_event_v2590(array $row): array
{
    $payload=json_decode((string)($row['payload_json']??''),true);
    if(!is_array($payload))$payload=[];
    $eventType=vp3_cognitive_current_state_text_v2590($row['event_type']??'',120);
    $source=vp3_cognitive_current_state_text_v2590($row['source']??'',80);
    $occurred=(string)($row['occurred_at']??'');
    if($occurred==='')$occurred=(string)($row['received_at']??'');
    $ts=strtotime($occurred)?:0;
    $age=$ts>0?max(0,time()-$ts):null;
    $processing=vp3_cognitive_current_state_text_v2590($row['processing_status']??'',24);
    return [
        'event_id'=>max(0,(int)($row['id']??0)),
        'domain'=>vp3_cognitive_current_state_domain_v2590($source,$eventType),
        'source'=>$source,
        'event_type'=>$eventType,
        'label'=>vp3_cognitive_current_state_label_v2590($eventType),
        'processing_status'=>$processing,
        'verification_status'=>vp3_cognitive_current_state_text_v2590($row['verification_status']??'',24),
        'occurred_at'=>$occurred,
        'age_seconds'=>$age,
        'fresh'=>$age===null||$age<=VP3_COGNITIVE_CURRENT_STATE_FRESH_SECONDS_V2590,
        'attention'=>vp3_cognitive_current_state_event_attention_v2590($eventType,$processing),
        'refs'=>vp3_cognitive_current_state_refs_v2590($payload),
    ];
}

function vp3_cognitive_current_state_recent_events_v2590(PDO $pdo,array $user,int $limit=VP3_COGNITIVE_CURRENT_STATE_MAX_EVENTS_V2590): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo))return [];
    $limit=max(1,min(VP3_COGNITIVE_CURRENT_STATE_MAX_EVENTS_V2590,$limit));
    $sql="SELECT id,source,event_type,verification_status,processing_status,occurred_at,received_at,payload_json
          FROM agent_event_inbox
          WHERE owner_user_id=? AND verification_status IN ('trusted','verified')
          ORDER BY COALESCE(occurred_at,received_at) DESC,id DESC
          LIMIT {$limit}";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$uid]);
    $items=[];
    foreach($stmt->fetchAll()?:[] as $row){
        if(is_array($row))$items[]=vp3_cognitive_current_state_event_v2590($row);
    }
    return $items;
}

function vp3_cognitive_current_state_activity_v2590(array $user): array
{
    if(!function_exists('agent_activity_v94_snapshot'))return [];
    try{$row=agent_activity_v94_snapshot($user);}catch(Throwable $e){return [];}
    return [
        'state'=>vp3_cognitive_current_state_text_v2590($row['state']??'idle',24),
        'label'=>vp3_cognitive_current_state_text_v2590($row['label']??'Idle',40),
        'surface'=>vp3_cognitive_current_state_text_v2590($row['surface']??'',40),
        'task_title'=>vp3_cognitive_current_state_text_v2590($row['task_title']??'',160),
        'seconds_since_activity'=>max(0,(int)($row['seconds_since_activity']??0)),
        'idle_for_seconds'=>max(0,(int)($row['idle_for_seconds']??0)),
        'interruptible'=>!empty($row['interruptible']),
        'tracking_ready'=>!empty($row['tracking_ready']),
    ];
}

function vp3_cognitive_current_state_session_v2590(PDO $pdo,array $user): array
{
    if(!function_exists('vp3_live_session_snapshot_v2370'))return [];
    try{$snapshot=vp3_live_session_snapshot_v2370($pdo,$user,false);}catch(Throwable $e){return [];}
    $row=is_array($snapshot['session']??null)?$snapshot['session']:[];
    if(!$row)return [];
    return [
        'session_id'=>vp3_cognitive_current_state_text_v2590($row['session_id']??'',80),
        'status'=>vp3_cognitive_current_state_text_v2590($row['status']??'',24),
        'current_surface'=>vp3_cognitive_current_state_text_v2590($row['current_surface']??'',40),
        'current_context_key'=>vp3_cognitive_current_state_text_v2590($row['current_context_key']??'',120),
        'current_conversation_id'=>max(0,(int)($row['current_conversation_id']??0)),
        'current_project_ref'=>vp3_cognitive_current_state_text_v2590($row['current_project_ref']??'',120),
        'current_task_ref'=>vp3_cognitive_current_state_text_v2590($row['current_task_ref']??'',120),
        'current_goal_ref'=>vp3_cognitive_current_state_text_v2590($row['current_goal_ref']??'',120),
        'last_user_action'=>vp3_cognitive_current_state_text_v2590($row['last_user_action']??'',160),
        'last_agent_action'=>vp3_cognitive_current_state_text_v2590($row['last_agent_action']??'',160),
        'last_tool_action'=>vp3_cognitive_current_state_text_v2590($row['last_tool_action']??'',160),
        'last_external_event'=>vp3_cognitive_current_state_text_v2590($row['last_external_event']??'',160),
        'last_action_at'=>(string)($row['last_action_at']??''),
    ];
}

function vp3_cognitive_current_state_materialize_events_v2590(array $events): array
{
    $latest=[];
    foreach($events as $event){
        if(!is_array($event))continue;
        $domain=(string)($event['domain']??'external');
        if($domain==='')$domain='external';
        if(!isset($latest[$domain]))$latest[$domain]=$event;
    }
    $latest=array_slice($latest,0,VP3_COGNITIVE_CURRENT_STATE_MAX_ITEMS_V2590,true);
    $attention=null;$attentionCount=0;
    foreach($latest as $event){
        $isAttention=!empty($event['attention'])&&!empty($event['fresh']);
        if(!$isAttention)continue;
        $attentionCount++;
        if($attention===null)$attention=$event;
    }
    return [
        'domains'=>$latest,
        'attention_candidate'=>$attention,
        'attention_count'=>$attentionCount,
    ];
}

function vp3_cognitive_current_state_projection_v2590(PDO $pdo,array $user,string $namespace=''): array
{
    $events=vp3_cognitive_current_state_recent_events_v2590($pdo,$user);
    $materialized=vp3_cognitive_current_state_materialize_events_v2590($events);
    $latest=is_array($materialized['domains']??null)?$materialized['domains']:[];
    $attention=is_array($materialized['attention_candidate']??null)?$materialized['attention_candidate']:null;
    $session=vp3_cognitive_current_state_session_v2590($pdo,$user);
    $activity=vp3_cognitive_current_state_activity_v2590($user);
    return [
        'build'=>VP3_COGNITIVE_CURRENT_STATE_V2590,
        'ready'=>true,
        'agent_namespace'=>vp3_cognitive_current_state_text_v2590($namespace,80),
        'session'=>$session,
        'activity'=>$activity,
        'domains'=>$latest,
        'recent'=>array_slice($events,0,VP3_COGNITIVE_CURRENT_STATE_MAX_ITEMS_V2590),
        'attention_candidate'=>$attention,
        'counts'=>[
            'recent_events'=>count($events),
            'domains'=>count($latest),
            'attention'=>max(0,(int)($materialized['attention_count']??0)),
        ],
        'authority'=>[
            'event_ingress'=>'agent_event_inbox_v1920',
            'live_session'=>'agent_live_sessions_v2370',
            'legacy_activity_compatibility'=>'agent_activity_v94_snapshot',
            'materialization'=>'ephemeral_read_only',
            'execution_authority'=>false,
            'memory_authority'=>false,
        ],
        'privacy'=>[
            'raw_event_payloads_exposed'=>false,
            'event_payloads_persisted_again'=>false,
            'model_reasoning_persisted'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_current_state_context_item_v2590(PDO $pdo,array $user,string $namespace=''): ?array
{
    $state=vp3_cognitive_current_state_projection_v2590($pdo,$user,$namespace);
    $session=is_array($state['session']??null)?$state['session']:[];
    $activity=is_array($state['activity']??null)?$state['activity']:[];
    $attention=is_array($state['attention_candidate']??null)?$state['attention_candidate']:null;
    $parts=[];
    if($session){
        $parts[]='Session '.((string)($session['status']??'active')).' on '.((string)($session['current_surface']??'VP3')).'.';
    }elseif($activity){
        $parts[]='Activity '.((string)($activity['state']??'idle')).' on '.((string)($activity['surface']??'VP3')).'.';
    }
    $parts[]=(int)($state['counts']['domains']??0).' domain state(s) materialized from canonical events.';
    if($attention){
        $parts[]='Attention candidate: '.(string)($attention['label']??'Recent item').' in '.str_replace('_',' ',(string)($attention['domain']??'VP3')).'.';
    }
    return [
        'source'=>'cognitive-current-state:v2590',
        'title'=>'Unified current state',
        'text'=>vp3_cognitive_current_state_text_v2590(implode(' ',$parts),700),
        'section'=>'current_state',
        'authority'=>'cognitive_current_state_v2590_ephemeral_projection',
        'trust'=>'data_only',
        'instruction_authority'=>false,
        'score'=>$attention?0.995:0.965,
        'meta'=>[
            'build'=>VP3_COGNITIVE_CURRENT_STATE_V2590,
            'domain_count'=>(int)($state['counts']['domains']??0),
            'attention_count'=>(int)($state['counts']['attention']??0),
            'raw_event_payloads_exposed'=>false,
        ],
    ];
}
