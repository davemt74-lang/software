<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.50 — Proactive Follow-Through & Cross-Surface Handoff.
 *
 * v24.50 is a policy/projection layer over v24.40 continuity, v24.10 attention,
 * v24.20 working context, v24.30 turn orchestration, Cognitive Presentation
 * v5.10, and Browser Companion notification delivery v21.40.
 *
 * It creates no second notification queue, task queue, delivery ledger, or
 * execution/approval authority.
 */
const VP3_COGNITIVE_FOLLOWTHROUGH_V2450='vp3-cognitive-followthrough-v2450-20260922';
const VP3_COGNITIVE_FOLLOWTHROUGH_CONTRACT_V2450='cognitive-followthrough-v1';
const VP3_COGNITIVE_FOLLOWTHROUGH_MAX_CANDIDATES_V2450=12;
const VP3_COGNITIVE_FOLLOWTHROUGH_MAX_PRESENTATION_V2450=6;

function vp3_cognitive_followthrough_priority_v2450(string $state): int
{
    return match($state){
        'needs_approval'=>98,
        'needs_user'=>96,
        'repair_needed'=>94,
        'blocked'=>92,
        'working'=>84,
        'ready'=>82,
        'needs_planning'=>80,
        'needs_review'=>78,
        'verifying'=>76,
        'scheduled'=>70,
        'tracking'=>66,
        'paused'=>60,
        default=>68,
    };
}

function vp3_cognitive_followthrough_source_surface_v2450(array $item): string
{
    $surface=vp3_cognitive_id_v500($item['surface']??'',40);
    if($surface!=='')return $surface;
    return match((string)($item['kind']??'')){
        'browser_continuity'=>'browser',
        'meeting_commitment'=>'meetings',
        'live_session'=>'chat',
        'workflow','goal','cognitive_plan'=>'chat',
        default=>'chat',
    };
}

function vp3_cognitive_followthrough_target_surface_v2450(array $item): string
{
    $source=vp3_cognitive_followthrough_source_surface_v2450($item);
    $state=(string)($item['state']??'planned');
    if(in_array($state,['needs_approval','needs_user','repair_needed','blocked','needs_review'],true))return 'chat';
    if($source==='browser')return 'browser';
    if($source==='meetings')return 'meetings';
    return 'chat';
}

function vp3_cognitive_followthrough_target_url_v2450(array $item): string
{
    $ref=(string)($item['ref']??'');
    $kind=(string)($item['kind']??'');
    $metadata=is_array($item['metadata']??null)?$item['metadata']:[];
    if($kind==='workflow'&&preg_match('/^workflow:(\d+)$/',$ref,$m))return '/agent-workflows.php?run='.(int)$m[1];
    if($kind==='meeting_commitment'){
        $url=trim((string)($metadata['review_path']??''));
        if($url!==''&&str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;
    }
    if($kind==='browser_continuity')return '/chat.php';
    if($kind==='goal')return '/chat.php';
    if($kind==='cognitive_plan')return '/chat.php';
    return '/chat.php';
}

function vp3_cognitive_followthrough_reason_v2450(array $item): string
{
    $state=(string)($item['state']??'planned');
    return match($state){
        'needs_approval'=>'Existing work is waiting for explicit approval.',
        'needs_user'=>'Existing work is waiting for a user decision.',
        'repair_needed'=>'Existing work needs repair or replanning before it can continue.',
        'blocked'=>'Existing work is blocked by an unresolved dependency.',
        'working'=>'Existing work is still active.',
        'ready'=>'Existing work is ready for its next authorized step.',
        'needs_planning'=>'Existing work needs planning before execution.',
        'needs_review'=>'Existing work needs review before it advances.',
        'verifying'=>'Existing work is waiting for verification or outcome evidence.',
        'scheduled'=>'Existing work is scheduled for a later attempt.',
        'tracking'=>'Existing follow-through is still being monitored.',
        'paused'=>'Existing work is paused and should not resume without intent.',
        default=>'Existing work remains open.',
    };
}

function vp3_cognitive_followthrough_event_key_v2450(string $namespace,array $item): string
{
    // Stable for a continuity item while it remains in the same semantic state.
    // This gives v24.10 receipts and Browser v21.40 delivery a shared dedupe key.
    $basis=implode('|',[
        $namespace,
        (string)($item['ref']??''),
        (string)($item['state']??''),
        !empty($item['requires_user'])?'user':'',
        !empty($item['requires_approval'])?'approval':'',
    ]);
    return 'followthrough:'.hash('sha256',$basis);
}

function vp3_cognitive_followthrough_candidate_v2450(string $namespace,array $item): array
{
    $state=(string)($item['state']??'planned');
    $sourceSurface=vp3_cognitive_followthrough_source_surface_v2450($item);
    $targetSurface=vp3_cognitive_followthrough_target_surface_v2450($item);
    $requiresApproval=!empty($item['requires_approval']);
    $requiresUser=!empty($item['requires_user'])||$requiresApproval;
    $title=vp3_cognitive_text_v500($item['title']??'Open VP3 work',190);
    $summary=vp3_cognitive_text_v500($item['summary']??vp3_cognitive_followthrough_reason_v2450($item),500);
    $eventKey=vp3_cognitive_followthrough_event_key_v2450($namespace,$item);
    $handoffReason=vp3_cognitive_followthrough_reason_v2450($item);
    $type=$requiresApproval?'followthrough_approval_required'
        :($requiresUser?'followthrough_response_required'
        :(in_array($state,['repair_needed','blocked'],true)?'followthrough_failure':'followthrough_update'));

    return [
        'event_key'=>$eventKey,
        'source_kind'=>'cognitive_followthrough',
        'source_ref'=>(string)($item['ref']??''),
        'type'=>$type,
        'source_type'=>(string)($item['source']??'cognitive_continuity_v2440'),
        'continuity_ref'=>(string)($item['ref']??''),
        'continuity_kind'=>(string)($item['kind']??''),
        'continuity_state'=>$state,
        'title'=>$title,
        'body'=>$summary!==''?$summary:$handoffReason,
        'priority'=>vp3_cognitive_followthrough_priority_v2450($state),
        'requires_user_response'=>$requiresUser,
        'requires_approval'=>$requiresApproval,
        'source_surface'=>$sourceSurface,
        'target_surface'=>$targetSurface,
        'handoff_reason'=>$handoffReason,
        'handoff_status'=>'candidate',
        'target_url'=>vp3_cognitive_followthrough_target_url_v2450($item),
        'action_label'=>$requiresApproval?'Review approval':($requiresUser?'Review':'Open'),
        'voice_allowed'=>in_array($state,['needs_approval','repair_needed','blocked'],true),
        'voice_text'=>in_array($state,['needs_approval','repair_needed','blocked'],true)
            ?vp3_cognitive_text_v500($title.'. '.$handoffReason,340)
            :'',
        'sensitive'=>false,
        'created_at'=>(string)($item['updated_at']??gmdate('Y-m-d H:i:s')),
        'goal_ref'=>(string)($item['goal_ref']??''),
        'task_ref'=>(string)($item['task_ref']??''),
        'project_ref'=>(string)($item['project_ref']??''),
        'resume_action'=>(string)($item['resume_action']??''),
        'metadata'=>vp3_cognitive_sanitize_value_v500(is_array($item['metadata']??null)?$item['metadata']:[]),
    ];
}

function vp3_cognitive_followthrough_candidates_v2450(
    PDO $pdo,array $user,string $namespace='system'
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    if(!function_exists('vp3_cognitive_continuity_snapshot_v2440'))return [];
    try{$snapshot=vp3_cognitive_continuity_snapshot_v2440($pdo,$user,$namespace);}catch(Throwable $e){return [];}
    $out=[];$seen=[];
    foreach((array)($snapshot['items']??[]) as $item){
        if(!is_array($item))continue;
        $ref=(string)($item['ref']??'');if($ref===''||isset($seen[$ref]))continue;
        $candidate=vp3_cognitive_followthrough_candidate_v2450($namespace,$item);
        $seen[$ref]=true;$out[]=$candidate;
        if(count($out)>=VP3_COGNITIVE_FOLLOWTHROUGH_MAX_CANDIDATES_V2450)break;
    }
    usort($out,static function(array $a,array $b): int {
        $x=((int)$b['priority'])<=>((int)$a['priority']);if($x!==0)return $x;
        return strcmp((string)$b['created_at'],(string)$a['created_at']);
    });
    return $out;
}

function vp3_cognitive_followthrough_attention_signal_v2450(array $candidate): array
{
    $state=(string)($candidate['continuity_state']??'');
    $requires=!empty($candidate['requires_user_response']);
    $category=in_array($state,['repair_needed','blocked'],true)?'risk'
        :($requires?'commitment':'recommendation');
    $requested=$requires?'ask_user'
        :(in_array($state,['repair_needed','blocked'],true)?'notification':'brief');
    return [
        'key'=>(string)$candidate['event_key'],
        'fingerprint'=>hash('sha256',(string)$candidate['event_key']),
        'source'=>'cognitive_followthrough',
        'category'=>$category,
        'title'=>(string)$candidate['title'],
        'reason'=>(string)$candidate['body'],
        'priority'=>(int)$candidate['priority'],
        'confidence'=>.95,
        'novelty'=>.70,
        'urgency'=>(int)$candidate['priority']/100,
        'impact'=>in_array($state,['needs_approval','needs_user','repair_needed','blocked'],true)?.92:.70,
        'goal_relevance'=>.95,
        'presentation_recommendation'=>$requested,
        'voice_safe_summary'=>(string)$candidate['voice_text'],
        'requires_user_response'=>$requires,
        'sensitive'=>!empty($candidate['sensitive']),
        'updated_at'=>(string)$candidate['created_at'],
    ];
}

function vp3_cognitive_followthrough_turn_type_v2450(array $candidate,array $attention): string
{
    $surface=(string)($attention['surface']??'none');
    $allowed=!in_array($surface,['none','memory'],true);
    $continuity=[
        'active'=>false,
        'resumable'=>true,
        'requires_approval'=>!empty($candidate['requires_approval']),
        'requires_user'=>!empty($candidate['requires_user_response']),
    ];
    if(function_exists('vp3_cognitive_turn_preferred_type_v2430')){
        return vp3_cognitive_turn_preferred_type_v2430(
            ['question'=>false,'continuation'=>true,'action_request'=>false,'approval_language'=>false],
            $continuity,
            ['proactive'=>true,'attention_allowed'=>$allowed]
        );
    }
    if(!$allowed)return 'remain_silent';
    if(!empty($continuity['requires_approval']))return 'request_approval';
    if(!empty($continuity['requires_user']))return 'ask_user';
    return 'present_update';
}

function vp3_cognitive_followthrough_status_v2450(array $attention): string
{
    $status=(string)($attention['status']??'');
    if($status==='delivered')return 'delivered';
    if($status==='dismissed')return 'dismissed';
    if($status==='released')return 'released';
    $surface=(string)($attention['surface']??'none');
    if(in_array($surface,['notification','voice_announce','ask_user'],true))return 'ready';
    if(in_array($surface,['brief','away_digest'],true))return 'deferred';
    return 'quiet';
}

function vp3_cognitive_followthrough_preview_v2450(
    PDO $pdo,array $user,string $namespace,array $candidate,array $context=[]
): array {
    $signal=vp3_cognitive_followthrough_attention_signal_v2450($candidate);
    $baseContext=function_exists('vp3_cognitive_presentation_context_v500')
        ?vp3_cognitive_presentation_context_v500($pdo,$user)
        :['interruptible'=>true,'idle_minutes'=>0];
    $ctx=array_replace($baseContext,[
        'requires_user_response'=>!empty($candidate['requires_user_response']),
        'agent_voice_enabled'=>!empty($baseContext['agent_voice_enabled']),
        'voice_candidate_allowed'=>!empty($candidate['voice_allowed']),
        'sensitive_for_voice'=>!empty($candidate['sensitive']),
    ],$context);
    $attention=function_exists('vp3_cognitive_attention_preview_v2410')
        ?vp3_cognitive_attention_preview_v2410($pdo,$user,$namespace,$signal,$ctx)
        :vp3_cognitive_attention_decide_v2410($signal,$ctx);
    $candidate['attention']=$attention;
    $candidate['handoff_status']=vp3_cognitive_followthrough_status_v2450($attention);
    $candidate['delivery_surface']=(string)($attention['surface']??'none');
    $candidate['turn_type']=vp3_cognitive_followthrough_turn_type_v2450($candidate,$attention);
    return $candidate;
}

function vp3_cognitive_followthrough_prepare_turn_v2450(
    PDO $pdo,array $user,string $namespace,array $candidate
): array {
    if(!function_exists('vp3_cognitive_turn_prepare_v2430'))return [];
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    $attention=is_array($candidate['attention']??null)?$candidate['attention']:[];
    $surface=(string)($attention['surface']??'none');
    $attentionAllowed=!in_array($surface,['none','memory'],true);
    try{
        $prepared=vp3_cognitive_turn_prepare_v2430(
            $pdo,$user,
            ['kind'=>$agentId>0?'user_agent':'system','agent_id'=>$agentId],
            'Continue authorized follow-through for '.(string)($candidate['continuity_ref']??'open work').'.',
            [],
            [
                'direct_user_turn'=>false,
                'proactive'=>true,
                'attention_allowed'=>$attentionAllowed,
                'surface'=>(string)($candidate['target_surface']??'chat'),
            ]
        );
    }catch(Throwable $e){return [];}
    $control=is_array($prepared['control']??null)?$prepared['control']:[];
    $context=is_array($control['context']??null)?$control['context']:[];
    return [
        'turn_type'=>(string)($control['preferred_turn_type']??'remain_silent'),
        'context_build'=>(string)($context['build']??VP3_COGNITIVE_CONTEXT_V2420),
        'context_item_count'=>max(0,(int)($context['item_count']??0)),
        'context_section_counts'=>is_array($context['section_counts']??null)?$context['section_counts']:[],
        'continuity_ref'=>(string)($candidate['continuity_ref']??''),
        'target_surface'=>(string)($candidate['target_surface']??'chat'),
        'authority'=>'response_shape_only',
    ];
}

function vp3_cognitive_followthrough_state_v2450(
    PDO $pdo,array $user,string $namespace='system'
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $items=[];
    foreach(vp3_cognitive_followthrough_candidates_v2450($pdo,$user,$namespace) as $candidate){
        try{$items[]=vp3_cognitive_followthrough_preview_v2450($pdo,$user,$namespace,$candidate);}
        catch(Throwable $e){}
        if(count($items)>=VP3_COGNITIVE_FOLLOWTHROUGH_MAX_PRESENTATION_V2450)break;
    }
    if(isset($items[0])){
        $preparedTurn=vp3_cognitive_followthrough_prepare_turn_v2450($pdo,$user,$namespace,$items[0]);
        if($preparedTurn)$items[0]['prepared_turn']=$preparedTurn;
    }
    $ready=0;$waiting=0;$cross=0;
    foreach($items as $item){
        if((string)($item['handoff_status']??'')==='ready')$ready++;
        if(!empty($item['requires_user_response']))$waiting++;
        if((string)($item['source_surface']??'')!==(string)($item['target_surface']??''))$cross++;
    }
    return [
        'contract'=>VP3_COGNITIVE_FOLLOWTHROUGH_CONTRACT_V2450,
        'build'=>VP3_COGNITIVE_FOLLOWTHROUGH_V2450,
        'agent_namespace'=>$namespace,
        'focus'=>$items[0]??null,
        'items'=>$items,
        'counts'=>[
            'open'=>count($items),
            'ready_to_surface'=>$ready,
            'waiting_for_user'=>$waiting,
            'cross_surface'=>$cross,
        ],
        'authority'=>[
            'continuity'=>'cognitive_continuity_v2440',
            'attention'=>'cognitive_attention_v2410',
            'working_context'=>'cognitive_context_v2420',
            'turn_orchestration'=>'cognitive_turn_v2430',
            'web_delivery'=>'cognitive_presentation_v510',
            'browser_delivery'=>'extension_notifications_v2140',
            'execution_authority'=>false,
            'approval_authority'=>false,
            'notification_queue'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_followthrough_extension_candidates_v2450(
    PDO $pdo,array $user,string $namespace
): array {
    $out=[];
    foreach(vp3_cognitive_followthrough_candidates_v2450($pdo,$user,$namespace) as $candidate){
        $out[]=[
            'event_key'=>(string)$candidate['event_key'],
            'source_kind'=>'cognitive_followthrough',
            'source_ref'=>(string)$candidate['continuity_ref'],
            'notification_id'=>null,
            'card_type'=>(string)$candidate['type'],
            'type'=>(string)$candidate['type'],
            'source_type'=>'cognitive_followthrough',
            'title'=>(string)$candidate['title'],
            'body'=>(string)$candidate['body'],
            'target_url'=>(string)$candidate['target_url'],
            'action_label'=>(string)$candidate['action_label'],
            'created_at'=>(string)$candidate['created_at'],
            'priority'=>(int)$candidate['priority'],
            'voice_allowed'=>!empty($candidate['voice_allowed']),
            'voice_text'=>(string)$candidate['voice_text'],
            'sensitive'=>!empty($candidate['sensitive']),
            'followthrough'=>[
                'continuity_ref'=>(string)$candidate['continuity_ref'],
                'source_surface'=>(string)$candidate['source_surface'],
                'target_surface'=>(string)$candidate['target_surface'],
                'handoff_reason'=>(string)$candidate['handoff_reason'],
            ],
        ];
    }
    return $out;
}

function vp3_cognitive_followthrough_away_v2450(
    PDO $pdo,array $user,string $namespace,string $lastMeaningfulAt,?array $state=null
): array {
    $cutoff=strtotime($lastMeaningfulAt.' UTC')?:0;
    if($cutoff<1)return ['count'=>0,'summary'=>'','items'=>[]];
    $state=$state?:vp3_cognitive_followthrough_state_v2450($pdo,$user,$namespace);
    $items=[];
    foreach((array)($state['items']??[]) as $item){
        $updated=strtotime((string)($item['created_at']??''))?:0;
        if($updated<=$cutoff)continue;
        if((string)($item['handoff_status']??'')==='quiet'&&empty($item['requires_user_response']))continue;
        $items[]=[
            'continuity_ref'=>(string)$item['continuity_ref'],
            'title'=>(string)$item['title'],
            'state'=>(string)$item['continuity_state'],
            'turn_type'=>(string)$item['turn_type'],
            'source_surface'=>(string)$item['source_surface'],
            'target_surface'=>(string)$item['target_surface'],
            'handoff_status'=>(string)$item['handoff_status'],
            'target_url'=>(string)$item['target_url'],
        ];
        if(count($items)>=4)break;
    }
    $count=count($items);
    $summary=$count===0?'':($count===1
        ?'1 open work item changed while you were away.'
        :$count.' open work items changed while you were away.');
    return ['count'=>$count,'summary'=>$summary,'items'=>$items];
}

function vp3_cognitive_followthrough_brief_v2450(
    PDO $pdo,array $user,string $namespace,array $presentationState=[]
): array {
    $state=vp3_cognitive_followthrough_state_v2450($pdo,$user,$namespace);
    $last=(string)($presentationState['last_meaningful_at']??'');
    $away=$last!==''?vp3_cognitive_followthrough_away_v2450($pdo,$user,$namespace,$last,$state):['count'=>0,'summary'=>'','items'=>[]];
    return [
        'build'=>VP3_COGNITIVE_FOLLOWTHROUGH_V2450,
        'focus'=>$state['focus'],
        'items'=>$state['items'],
        'counts'=>$state['counts'],
        'away'=>$away,
    ];
}

function vp3_cognitive_followthrough_activity_projection_v2450(
    PDO $pdo,array $user,string $namespace
): array {
    $state=vp3_cognitive_followthrough_state_v2450($pdo,$user,$namespace);
    return [
        'build'=>VP3_COGNITIVE_FOLLOWTHROUGH_V2450,
        'focus'=>$state['focus'],
        'items'=>$state['items'],
        'counts'=>$state['counts'],
        'projection_only'=>true,
    ];
}
