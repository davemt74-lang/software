<?php
declare(strict_types=1);
/**
 * Cloud-owned Agent onboarding skill. Read-only adapter over existing persisted
 * onboarding preferences and canonical account/device verification.
 * No separate database, scheduler, permission authority or tool execution.
 */
const VP3_AGENT_ONBOARDING_SKILL_V100='vp3-agent-onboarding-skill-v100';

function vp3_agent_onboarding_skill_manifest_v100(): array {
    return [
        'id'=>'vp3.account.onboarding','version'=>VP3_AGENT_ONBOARDING_SKILL_V100,
        'owner'=>'vp3-cloud','surface'=>'agent-chat-canvas',
        'loop'=>['observe','check_dependencies','propose','obtain_approval','act_via_existing_service','verify','remember','replan'],
        'required'=>['agent','profile'],
        'optional'=>['voice','voice_profile','profile_agent','chat','voice_clone','browser','homeserver','hosting','visual_profile'],
        'uses_canonical_authorities'=>true,
    ];
}
function vp3_agent_onboarding_skill_state_v100(array $snapshot): array {
    $setup=(array)($snapshot['setup']??[]);
    $intel=(array)($snapshot['intelligence']??[]);
    $draft=(array)($intel['draft']??[]);
    $activation=(array)($snapshot['activation']??[]);
    $workspace=(array)($snapshot['workspace']??[]);
    $voice=(array)($snapshot['voice']??[]);
    $required=[
        ['key'=>'agent','label'=>'Choose your Agent name','verified'=>!empty($setup['agent_named']),'draft_saved'=>trim((string)($draft['agent_name']??''))!=='','requires_user_input'=>true,'url'=>'/chat.php?setup=1'],
        ['key'=>'profile','label'=>'Choose your profile address','verified'=>!empty($setup['profile_username']),'draft_saved'=>trim((string)($draft['username']??''))!=='','requires_user_input'=>true,'url'=>'/chat.php?setup=1'],
    ];
    $coreReady=$required[0]['verified']&&$required[1]['verified'];
    $next=null;$selected=[];$pending=0;$waiting=0;$priority=-1;
    foreach((array)($activation['items']??[]) as $key=>$item){
        if(!is_array($item)||empty($item['selected']))continue;
        $status=(string)($item['activation_status']??'unavailable');
        if(!in_array($status,['complete','pending','deferred','locked','unavailable'],true))$status='unavailable';
        $row=[
            'key'=>(string)$key,'label'=>(string)($item['label']??$key),
            'status'=>$status,'verified'=>$status==='complete'&&!empty($item['configured']),
            'requires_owner_approval'=>true,'url'=>(string)($item['setup_url']??'/chat.php?setup=1'),
            'current_status'=>(string)($item['current_status']??'')
        ];
        $selected[]=$row;
        if($status==='pending'){
            ++$pending;
            if((int)($item['priority']??0)>$priority){$next=$row;$priority=(int)($item['priority']??0);}
        }elseif($status!=='complete')++$waiting;
    }
    $phase='complete';
    if(!$coreReady){$phase='essential';$next=!$required[0]['verified']?$required[0]:$required[1];}
    elseif($next!==null)$phase='optional';
    elseif($waiting)$phase='waiting';
    $readiness=[];
    foreach(['voice_profile','browser','hosting','homeserver'] as $key){
        $item=(array)($workspace[$key]??[]);
        $readiness[$key]=[
            'source'=>'canonical-service','selected'=>!empty($activation['items'][$key]['selected']),
            'permitted'=>!empty($item['permitted']),'available'=>!empty($item['available']),
            'configured'=>!empty($item['configured']),
            'live'=>!empty($item['live']),
            'status'=>(string)($item['status']??'Status unavailable'),
            'observed_state'=>(string)($item['observed_state']??'')
        ];
    }
    $readiness['voice_clone']=[
        'source'=>'canonical-voice-profile','configured'=>!empty($voice['clone_created']),
        'verified'=>!empty($voice['clone_verified']),
        'status'=>!empty($voice['clone_verified'])?'verified':(!empty($voice['clone_created'])?'created_not_verified':'not_enrolled')
    ];
    $visual=(array)($snapshot['visual_profile']??[]);
    $readiness['visual_profile']=[
        'source'=>'canonical-cloud-consent-and-tracky-site-inventory',
        'selected'=>!empty($visual['selected']),'consented'=>!empty($visual['consented']),
        'available'=>!empty($visual['site_ready']),
        'configured'=>false,'verified'=>false,
        'status'=>(string)($visual['stage']??'not_integrated'),
        'reason'=>'Cloud cannot certify local biometric enrollment from a photo, device heartbeat, or unsigned client report.'
    ];
    $reason=match($phase){
        'essential'=>$next['label'].' is required to complete Cloud setup.',
        'optional'=>'Your Cloud identity is ready. '.$next['label'].' is selected but not verified. '.(string)($next['current_status']??''),
        'waiting'=>'Cloud identity is ready; selected optional services need attention or are deferred.',
        default=>'Your Cloud identity is ready. You can add optional services any time.'
    };
    return [
        'skill'=>vp3_agent_onboarding_skill_manifest_v100(),
        'task'=>[
            'key'=>'vp3.onboarding.'.max(0,(int)($snapshot['user']['id']??0)),
            'status'=>$phase==='complete'?'completed':($phase==='waiting'?'waiting':'in_progress'),
            'phase'=>$phase,'required_complete'=>$coreReady,
            'optional_pending'=>$pending,'optional_waiting'=>$waiting,
            'next'=>$phase==='complete'||$phase==='waiting'?null:$next,
            'reason'=>$reason,'source'=>'canonical-account-and-device-state',
            'review_url'=>'/chat.php?setup=1'
        ],
        'required'=>$required,'selected_optional'=>$selected,'readiness'=>$readiness,
        'execution'=>['auto_provision'=>false,'requires_explicit_approval'=>true,'resume_existing_preferences'=>true]
    ];
}
function vp3_agent_onboarding_skill_candidate_v100(array $user): ?array {
    $pdo=function_exists('db')?db():null;
    if(!$pdo||empty($user['id'])||!function_exists('onboarding_intelligence_schema_ready')||
        !onboarding_intelligence_schema_ready($pdo)||!function_exists('chat_onboarding_v241_state'))return null;
    try{
        $prefs=onboarding_intelligence_preferences($pdo,(int)$user['id']);
        $selected=array_filter((array)($prefs['feature_interests']??[]),
            static fn($value,$key):bool=>str_starts_with((string)$key,'workflow.')&&!empty($value),ARRAY_FILTER_USE_BOTH);
        if(!empty($prefs['onboarding_dismissed'])&&!$selected)return null;
        $state=chat_onboarding_v241_state($pdo,$user);
        $task=vp3_agent_onboarding_skill_state_v100($state)['task'];
        if(!$task['next'])return null;
        $step=$task['next'];$essential=$task['phase']==='essential';
        return [
            'source'=>'onboarding_skill',
            'key'=>$task['key'].'.'.$step['key'],
            'title'=>$step['label'],'reason'=>$task['reason'],
            'prompt'=>'Continue VP3 onboarding: observe current state and request approval for any change.',
            'url'=>'/chat.php?setup=1','score'=>$essential?0.83:0.59,
            'priority'=>$essential?166:118,'object_type'=>'onboarding_task',
            'object_id'=>$task['key'],'requires_approval'=>true
        ];
    }catch(Throwable $e){
        if(function_exists('agent_runtime_v125_trace'))
            agent_runtime_v125_trace('onboarding.skill.observe.failed',['user_id'=>(int)$user['id'],'error_class'=>get_class($e)]);
        return null;
    }
}
