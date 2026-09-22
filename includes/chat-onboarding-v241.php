<?php
declare(strict_types=1);

require_once __DIR__.'/studio-participants.php';
require_once __DIR__.'/studio-voice-profile.php';
require_once __DIR__.'/onboarding-intelligence.php';

const STONEFELLOW_CHAT_ONBOARDING_V241='chat-onboarding-intelligence-20260906';

function chat_onboarding_v241_username(PDO $pdo,array $user,array $profile): string
{
    $existing=profile_username_normalize((string)($profile['username']??''));if($existing!=='')return $existing;
    $base=profile_username_normalize((string)($user['display_name']??'member'));
    if(mb_strlen($base)<3||!profile_username_valid($base))$base='member-'.(int)($user['id']??0);
    $candidate=$base;$suffix=1;
    while(true){$stmt=$pdo->prepare('SELECT 1 FROM user_profiles WHERE username=? AND user_id<>? LIMIT 1');$stmt->execute([$candidate,(int)($user['id']??0)]);if(!$stmt->fetchColumn())return $candidate;$suffix++;$candidate=mb_strimwidth($base,0,52,'').'-'.$suffix;}
}

function chat_onboarding_v241_entitled(array $user,string $capability,bool $legacyAllowed): bool
{
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return $legacyAllowed;
    $sub=subscription_current($user);if(!$sub)return false;
    if(subscription_has_entitlement($user,'legacy.permissions'))return $legacyAllowed;
    return subscription_has_entitlement($user,$capability);
}

function chat_onboarding_v241_permission_state(array $user): array
{
    return [
        'agent_brain'=>chat_onboarding_v241_entitled($user,'agent_brain.access',personal_capability_has_v242('agent_brain.access',$user)),
        'personal_knowledge'=>chat_onboarding_v241_entitled($user,'knowledge.access',personal_capability_has_v242('personal_knowledge.access',$user)),
        'personal_knowledge_manage'=>chat_onboarding_v241_entitled($user,'knowledge.access',personal_capability_has_v242('personal_knowledge.manage',$user)),
        'profile_agent'=>chat_onboarding_v241_entitled($user,'profile_agent.access',personal_capability_has_v242('profile_agent.access',$user)),
        'profile_chat'=>chat_onboarding_v241_entitled($user,'profile_agent.access',personal_capability_has_v242('profile_chat.access',$user)),
        'voice_profile'=>chat_onboarding_v241_entitled($user,'voice.access',personal_capability_has_v242('voice_profile.access',$user)),
        'voice_clone'=>chat_onboarding_v241_entitled($user,'voice_clone.access',personal_capability_has_v242('voice_profile.access',$user)),
        'transcriptions'=>chat_onboarding_v241_entitled($user,'transcription.access',has_permission('artist_listening.access',$user)),
        'stem_editor'=>chat_onboarding_v241_entitled($user,'stem_editor.access',true),
        'video_editor'=>chat_onboarding_v241_entitled($user,'video_editor.access',true),
        'main_ai'=>chat_onboarding_v241_entitled($user,'main_ai.access',has_permission('chat.access',$user)),
    ];
}

function chat_onboarding_v241_voice_state(PDO $pdo,array $user): array
{
    $permissions=chat_onboarding_v241_permission_state($user);$permitted=!empty($permissions['voice_profile']);
    $out=['permitted'=>$permitted,'available'=>false,'clone_created'=>false,'clone_verified'=>false,'sample_count'=>0,'url'=>url('/voice-profile.php')];if(!$permitted)return $out;
    try{if(!studio_participants_schema_ready()||!studio_voice_profile_schema_ready())return $out;$state=studio_voice_profile_state($pdo,$user);$voice=is_array($state['voice']??null)?$state['voice']:[];$samples=is_array($state['samples']??null)?$state['samples']:[];$out['available']=true;$out['clone_created']=trim((string)($voice['clone_provider_voice_id']??''))!=='';$out['clone_verified']=!empty($voice['clone_verified']);$out['sample_count']=count($samples);}catch(Throwable $e){}
    return $out;
}

function chat_onboarding_v241_capabilities(array $profile,array $publicAgent,array $chat,array $voice,bool $onboardingComplete,array $permissions=[]): array
{
    $username=trim((string)($profile['username']??''));$profileConfigured=$username!=='';$profilePublic=!empty($profile['is_public']);$profileAgentSelected=(int)($publicAgent['agent_id']??0)>0;$profileAgentEnabled=!empty($publicAgent['enabled']);$profileAgentLive=array_key_exists('live',$publicAgent)?!empty($publicAgent['live']):($profileConfigured&&$profilePublic&&$profileAgentSelected&&$profileAgentEnabled);
    $presenceOnline=(string)($chat['presence_mode']??'online')==='online';$socialChat=!empty($chat['social_chat_enabled']);$sound=!empty($chat['sound_enabled']);$cloneCreated=!empty($voice['clone_created']);
    $profileAllowed=!empty($permissions['profile_agent']);$voiceAllowed=!empty($permissions['voice_profile']);$cloneAllowed=!empty($permissions['voice_clone']);
    return [
        'profile_view'=>['label'=>'Public profile','permitted'=>$profileAllowed,'configured'=>$profileConfigured,'enabled'=>$profileAllowed&&$profilePublic,'available'=>$profileAllowed&&$profileConfigured&&$profilePublic,'setup_url'=>url('/profile-agent.php?tab=profile')],
        'profile_agent'=>['label'=>'Profile Agent','permitted'=>$profileAllowed,'configured'=>$profileAllowed&&($onboardingComplete||$profileAgentSelected),'enabled'=>$profileAllowed&&$profileAgentEnabled,'available'=>$profileAllowed&&$profileAgentLive,'setup_url'=>url('/profile-agent.php')],
        'online_presence'=>['label'=>'Online presence','permitted'=>true,'configured'=>$onboardingComplete,'enabled'=>$presenceOnline,'available'=>$presenceOnline,'setup_url'=>url('/chat.php')],
        'social_chat'=>['label'=>'User-to-user chat','permitted'=>true,'configured'=>$onboardingComplete,'enabled'=>$socialChat,'available'=>$presenceOnline&&$socialChat,'setup_url'=>url('/chat.php')],
        'incoming_sound'=>['label'=>'Incoming chat sound','permitted'=>true,'configured'=>$onboardingComplete,'enabled'=>$sound,'available'=>$sound,'setup_url'=>url('/chat.php')],
        'voice_profile'=>['label'=>'Voice Profile','permitted'=>$voiceAllowed,'configured'=>$voiceAllowed&&!empty($voice['available']),'enabled'=>$voiceAllowed,'available'=>$voiceAllowed&&!empty($voice['available']),'setup_url'=>(string)($voice['url']??url('/voice-profile.php'))],
        'voice_clone'=>['label'=>'Voice clone','permitted'=>$cloneAllowed,'configured'=>$cloneAllowed&&$cloneCreated,'enabled'=>$cloneAllowed&&$cloneCreated,'available'=>$cloneAllowed&&$cloneCreated,'verified'=>$cloneAllowed&&!empty($voice['clone_verified']),'setup_url'=>(string)($voice['url']??url('/voice-profile.php'))],
        'transcriptions'=>['label'=>'Transcriptions','permitted'=>!empty($permissions['transcriptions']),'configured'=>true,'enabled'=>!empty($permissions['transcriptions']),'available'=>!empty($permissions['transcriptions']),'setup_url'=>url('/artist-listening.php')],
        'stem_editor'=>['label'=>'Stem Editor','permitted'=>!empty($permissions['stem_editor']),'configured'=>true,'enabled'=>!empty($permissions['stem_editor']),'available'=>!empty($permissions['stem_editor']),'setup_url'=>url('/admin/stems.php')],
        'video_editor'=>['label'=>'Video Editor','permitted'=>!empty($permissions['video_editor']),'configured'=>true,'enabled'=>!empty($permissions['video_editor']),'available'=>!empty($permissions['video_editor']),'setup_url'=>url('/subscription.php')],
    ];
}

function chat_onboarding_v241_workspace_state(PDO $pdo,array $user,array $permissions=[]): array
{
    $uid=(int)($user['id']??0);
    $count=static function(string $sql,array $args=[]) use($pdo): int {
        try{$stmt=$pdo->prepare($sql);$stmt->execute($args);return (int)$stmt->fetchColumn();}catch(Throwable $e){return 0;}
    };

    $browserReady=function_exists('vp3_extension_schema_ready_v2000')&&vp3_extension_schema_ready_v2000($pdo);
    $browserConnections=$browserReady?$count("SELECT COUNT(*) FROM extension_devices_v2000 WHERE user_id=? AND device_status='active'",[$uid]):0;

    if(function_exists('vp3_cognitive_meeting_load_v500')){
        try{vp3_cognitive_meeting_load_v500();}catch(Throwable $e){}
    }
    $meetingReady=function_exists('video_meeting_schema_ready_v1800')&&video_meeting_schema_ready_v1800($pdo)
        &&table_exists('video_meeting_transcription_links')
        &&column_exists('video_meeting_transcript_segments','source_key')
        &&function_exists('video_meeting_external_calendar_schema_ready_v1801')&&video_meeting_external_calendar_schema_ready_v1801($pdo)
        &&function_exists('video_meeting_intelligence_schema_ready_v1820')&&video_meeting_intelligence_schema_ready_v1820($pdo);
    $meetingCount=$meetingReady&&table_exists('video_meetings')?$count('SELECT COUNT(*) FROM video_meetings WHERE owner_user_id=?',[$uid]):0;

    $calendarReady=function_exists('user_calendar_schema_ready_v1300')&&user_calendar_schema_ready_v1300($pdo);
    $calendarCount=$calendarReady&&table_exists('user_calendar_events')?$count("SELECT COUNT(*) FROM user_calendar_events WHERE owner_user_id=? AND status<>'cancelled'",[$uid]):0;

    $bookingReady=function_exists('agent_scheduling_schema_ready_v430')&&agent_scheduling_schema_ready_v430($pdo)
        &&function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo);
    $bookingTypes=$bookingReady?$count("SELECT COUNT(*) FROM agent_scheduling_event_types et INNER JOIN agent_scheduling_schedules s ON s.id=et.schedule_id WHERE s.owner_user_id=? AND s.is_active=1 AND et.is_active=1",[$uid]):0;
    $bookingCount=$bookingReady&&table_exists('agent_scheduling_bookings')?$count("SELECT COUNT(*) FROM agent_scheduling_bookings WHERE owner_user_id=? AND status<>'cancelled'",[$uid]):0;

    $commerceReady=function_exists('agent_commerce_schema_ready_v800')&&agent_commerce_schema_ready_v800($pdo);
    $commerceConnections=$commerceReady?$count("SELECT COUNT(*) FROM agent_commerce_provider_connections_v800 WHERE owner_user_id=? AND status='connected'",[$uid]):0;
    $commerceProducts=$commerceReady?$count("SELECT COUNT(*) FROM agent_commerce_products_v800 WHERE owner_user_id=? AND is_active=1",[$uid]):0;

    $profileAllowed=!empty($permissions['profile_agent']);
    $analyticsReady=$profileAllowed&&function_exists('profile_agent_schema_ready')&&profile_agent_schema_ready($pdo);

    $teamReady=function_exists('workspace_team_v350_schema_ready')&&workspace_team_v350_schema_ready($pdo);
    $teamState=[];if($teamReady&&function_exists('team_subscription_state')){try{$teamState=team_subscription_state($user,$pdo);}catch(Throwable $e){$teamState=[];}}
    $teamPermitted=$teamReady&&(!empty($teamState['authorized'])||!empty($teamState['included']));
    $teamCount=$teamReady?$count("SELECT COUNT(*) FROM workspace_memberships_v350 WHERE (workspace_owner_user_id=? OR member_user_id=?) AND membership_status='active'",[$uid,$uid]):0;

    $homeReady=table_exists('homeserver_connections');
    $homeState='unpaired';
    if($homeReady&&$uid>0){
        try{$stmt=$pdo->prepare('SELECT status FROM homeserver_connections WHERE user_id=? LIMIT 1');$stmt->execute([$uid]);$homeState=(string)($stmt->fetchColumn()?:'unpaired');}catch(Throwable $e){}
    }
    $homeConnected=in_array($homeState,['connected','paired'],true);

    $transcriptionAllowed=!empty($permissions['transcriptions']);

    return [
        'browser'=>[
            'label'=>'Browser Companion + Annotations','description'=>'Bring page-aware Agent help, source-linked annotations and approved browser actions into Chrome.',
            'interest_key'=>'workflow.browser','permitted'=>!empty($permissions['main_ai']),'configured'=>$browserConnections>0,'available'=>$browserReady&&!empty($permissions['main_ai']),
            'status'=>$browserConnections>0?$browserConnections.' connected browser'.($browserConnections===1?'':'s'):($browserReady?'Not connected yet':'System upgrade required'),
            'setup_url'=>url('/connected-browsers.php'),'action_label'=>'Connected Browsers','usage_count'=>$browserConnections,'milestone_label'=>'First Browser Companion connected',
        ],
        'transcription'=>[
            'label'=>'Transcription + AI Summary','description'=>'Capture conversations and turn them into searchable transcripts, summaries and action items.',
            'interest_key'=>'workflow.transcription','permitted'=>$transcriptionAllowed,'configured'=>$transcriptionAllowed,'available'=>$transcriptionAllowed,
            'status'=>$transcriptionAllowed?'Ready to use':'Not included in current package','setup_url'=>url('/artist-listening.php'),'action_label'=>'Open Transcription','usage_count'=>0,'milestone_label'=>'',
        ],
        'meetings'=>[
            'label'=>'Meetings','description'=>'Schedule VP3 video meetings with transcription, Meeting Intelligence, commitments and follow-through.',
            'interest_key'=>'workflow.meetings','permitted'=>!empty($permissions['main_ai']),'configured'=>$meetingReady,'available'=>$meetingReady&&!empty($permissions['main_ai']),
            'status'=>$meetingReady?'Ready to use':'System upgrade required','setup_url'=>url('/meetings.php'),'action_label'=>'Open Meetings','usage_count'=>$meetingCount,'milestone_label'=>'First VP3 meeting created',
        ],
        'calendar'=>[
            'label'=>'Calendar','description'=>'Keep personal events, bookings, meetings and Agent-managed time in one calendar.',
            'interest_key'=>'workflow.calendar','permitted'=>!empty($permissions['main_ai']),'configured'=>$calendarReady,'available'=>$calendarReady&&!empty($permissions['main_ai']),
            'status'=>$calendarReady?'Ready to use':'System upgrade required','setup_url'=>url('/calendar.php'),'action_label'=>'Open Calendar','usage_count'=>$calendarCount,'milestone_label'=>'First calendar event added',
        ],
        'booking'=>[
            'label'=>'Booking','description'=>'Create public scheduling offers, availability, reminders, intake and appointment follow-through.',
            'interest_key'=>'workflow.booking','permitted'=>!empty($permissions['main_ai']),'configured'=>$bookingTypes>0,'available'=>$bookingReady&&!empty($permissions['main_ai']),
            'status'=>$bookingTypes>0?$bookingTypes.' active booking type'.($bookingTypes===1?'':'s'):($bookingReady?'No booking offer configured yet':'System upgrade required'),
            'setup_url'=>url('/scheduling.php'),'action_label'=>'Set Up Booking','usage_count'=>$bookingCount,'milestone_label'=>'First booking received',
        ],
        'commerce'=>[
            'label'=>'Ecommerce','description'=>'Connect a payment provider, publish products and keep orders, fulfillment and refunds in VP3.',
            'interest_key'=>'workflow.commerce','permitted'=>!empty($permissions['main_ai']),'configured'=>$commerceConnections>0||$commerceProducts>0,'available'=>$commerceReady&&!empty($permissions['main_ai']),
            'status'=>$commerceConnections>0?$commerceConnections.' payment connection'.($commerceConnections===1?'':'s'):($commerceProducts>0?$commerceProducts.' active product'.($commerceProducts===1?'':'s'):($commerceReady?'No provider or product configured yet':'System upgrade required')),
            'setup_url'=>url('/commerce.php'),'action_label'=>'Open Commerce','usage_count'=>$commerceProducts,'milestone_label'=>'First product published',
        ],
        'analytics'=>[
            'label'=>'Agent Analytics','description'=>'See Profile visits, intent, conversions, attributed revenue, sources and Agent opportunities.',
            'interest_key'=>'workflow.analytics','permitted'=>$profileAllowed,'configured'=>$analyticsReady,'available'=>$analyticsReady,
            'status'=>$analyticsReady?'Ready in Profile Agent':'Profile Agent access required','setup_url'=>url('/profile-agent.php#analytics'),'action_label'=>'Open Analytics','usage_count'=>0,'milestone_label'=>'',
        ],
        'teams'=>[
            'label'=>'Teams','description'=>'Invite collaborators into permission-aware shared workspaces and Agent workflows.',
            'interest_key'=>'workflow.teams','permitted'=>$teamPermitted,'configured'=>$teamCount>0,'available'=>$teamPermitted,
            'status'=>$teamCount>0?$teamCount.' active Team relationship'.($teamCount===1?'':'s'):($teamPermitted?'Ready to invite your Team':'Team access is not included'),
            'setup_url'=>url('/team.php'),'action_label'=>'Manage Team','usage_count'=>$teamCount,'milestone_label'=>'First Team relationship created',
        ],
        'homeserver'=>[
            'label'=>'HomeServer','description'=>'Pair private local knowledge, tools, models and compute with the same VP3 Agent.',
            'interest_key'=>'workflow.homeserver','permitted'=>!empty($permissions['personal_knowledge']),'configured'=>$homeConnected,'available'=>$homeReady&&!empty($permissions['personal_knowledge']),
            'status'=>$homeConnected?'Paired with HomeServer':($homeReady?'Not paired yet':'System upgrade required'),
            'setup_url'=>url('/settings-homeserver.php'),'action_label'=>'HomeServer Settings','usage_count'=>$homeConnected?1:0,'milestone_label'=>'HomeServer paired',
        ],
    ];
}

function chat_onboarding_v241_activation_state(array $workspace,array $intelligence,bool $requiredSetupComplete): array
{
    $interests=(array)($intelligence['feature_interests']??[]);
    $priority=['browser'=>95,'booking'=>92,'commerce'=>90,'teams'=>86,'homeserver'=>84,'transcription'=>76,'meetings'=>74,'calendar'=>72,'analytics'=>70];
    $items=[];$pending=[];$selectedCount=0;$configuredCount=0;$deferredCount=0;$blockedCount=0;
    foreach($workspace as $key=>$item){
        $interest=(string)($item['interest_key']??'');if($interest==='')continue;
        $selected=!empty($interests[$interest]);
        $dismissed=!empty($interests['activation.dismiss.'.$interest]);
        $deferRaw=$interests['activation.defer.'.$interest]??false;
        $deferUntil=is_string($deferRaw)?trim($deferRaw):'';
        $deferTs=$deferUntil!==''?strtotime($deferUntil):false;
        $configured=!empty($item['configured']);
        $permitted=!empty($item['permitted']);
        $available=!empty($item['available']);
        $deferred=$selected&&!$configured&&!$dismissed&&$deferTs!==false&&$deferTs>time();
        if($configured)$status='complete';
        elseif($dismissed||!$selected)$status='not_interested';
        elseif(!$permitted)$status='locked';
        elseif(!$available)$status='unavailable';
        elseif($deferred)$status='deferred';
        else$status='pending';
        if($selected){
            $selectedCount++;
            if($configured)$configuredCount++;
            elseif($status==='deferred')$deferredCount++;
            elseif(in_array($status,['locked','unavailable'],true))$blockedCount++;
        }
        $row=$item+[
            'key'=>(string)$key,'selected'=>$selected,'dismissed'=>$dismissed,'deferred'=>$deferred,
            'defer_until'=>$deferred?$deferUntil:'','activation_status'=>$status,'priority'=>(int)($priority[$key]??60),
            'current_status'=>(string)($item['status']??''),
        ];
        $items[(string)$key]=$row;
        if($status==='pending')$pending[]=$row;
    }
    usort($pending,static fn(array $a,array $b): int=>((int)$b['priority']<=>((int)$a['priority']))?:strcmp((string)$a['label'],(string)$b['label']));
    $milestones=[['key'=>'core','label'=>'Agent + profile ready','complete'=>$requiredSetupComplete,'kind'=>'setup']];
    $usageMilestones=[];
    foreach($items as $key=>$item)if(!empty($item['selected'])){
        $milestones[]=['key'=>$key,'label'=>(string)$item['label'],'complete'=>!empty($item['configured']),'kind'=>'setup'];
        $milestoneLabel=trim((string)($item['milestone_label']??''));
        if($milestoneLabel!=='')$usageMilestones[]=['key'=>$key,'label'=>$milestoneLabel,'achieved'=>(int)($item['usage_count']??0)>0,'count'=>(int)($item['usage_count']??0)];
    }
    $draft=(array)($intelligence['draft']??[]);
    $attribution=['origin'=>(string)($interests['activation.origin']??$draft['origin']??''),'source'=>(string)($interests['activation.source']??$draft['source']??''),'selected_workflows'=>array_values(array_map(static fn(array $row): string=>(string)$row['interest_key'],array_filter($items,static fn(array $row): bool=>!empty($row['selected'])))),'configured_workflows'=>array_values(array_map(static fn(array $row): string=>(string)$row['interest_key'],array_filter($items,static fn(array $row): bool=>!empty($row['selected'])&&!empty($row['configured']))))];
    $percent=$selectedCount>0?(int)round(($configuredCount/$selectedCount)*100):100;
    return [
        'build'=>'onboarding-activation-v243-20260921','items'=>$items,'next_action'=>$pending[0]??null,
        'pending_count'=>count($pending),'deferred_count'=>$deferredCount,'blocked_count'=>$blockedCount,
        'selected_count'=>$selectedCount,'configured_count'=>$configuredCount,'activation_percent'=>$percent,
        'milestones'=>$milestones,'usage_milestones'=>$usageMilestones,'attribution'=>$attribution,'complete'=>$requiredSetupComplete&&$selectedCount===$configuredCount,
    ];
}

function chat_onboarding_v241_package_state(array $user): array
{
    if(!function_exists('subscription_current'))return ['available'=>false];
    $sub=subscription_current($user);$balance=subscription_ai_balance($user);$teamLimit=artist_workspace_v104_team_limit($user);
    $used=(int)($balance['used']??0);$allowance=(int)($balance['package_allowance']??0);$percent=$allowance>0?min(100,(int)round(($used/$allowance)*100)):0;
    return ['available'=>(bool)$sub,'subscription'=>$sub,'package_name'=>(string)($sub['package_name']??''),'status'=>(string)($sub['status']??''),'is_trial'=>!empty($sub['is_trial']),'period_end'=>(string)($sub['current_period_end']??$sub['ends_at']??''),'ai'=>$balance,'usage_percent'=>$percent,'team_seats'=>$teamLimit,'manage_url'=>url('/subscription.php')];
}

function chat_onboarding_v241_state(PDO $pdo,array $user): array
{
    $profileState=profile_runtime_owner_state($pdo,$user);$profile=is_array($profileState['profile']??null)?$profileState['profile']:[];$agents=user_agents_list_v236($pdo,(int)$user['id'],true);$defaultAgent=null;foreach($agents as $agent){if(!empty($agent['is_default'])){$defaultAgent=$agent;break;}}$defaultAgent??=$agents[0]??null;
    $permissions=chat_onboarding_v241_permission_state($user);$chat=chat_settings_get_v237($pdo,(int)$user['id']);$publicAgent=is_array($profileState['public_agent_status']??null)?$profileState['public_agent_status']:[];$voice=chat_onboarding_v241_voice_state($pdo,$user);$onboardingComplete=user_agent_onboarding_dismissed_v236($pdo,(int)$user['id']);
    $requiredSetup=['agent_named'=>(bool)$defaultAgent,'profile_username'=>trim((string)($profile['username']??''))!==''];$missingRequired=[];foreach($requiredSetup as $key=>$ready)if(!$ready)$missingRequired[]=$key;
    $capabilities=chat_onboarding_v241_capabilities($profile,$publicAgent,$chat,$voice,$onboardingComplete,$permissions);$unavailable=[];$locked=[];$setupCandidates=[];$setupReady=0;
    foreach($capabilities as $key=>$capability){if(empty($capability['permitted'])){$locked[]=$key;continue;}if(empty($capability['available']))$unavailable[]=$key;if(in_array($key,['profile_view','profile_agent','voice_profile'],true)){$setupCandidates[]=$key;if(!empty($capability['configured']))$setupReady++;}}
    $requiredCount=count($requiredSetup);$requiredReady=$requiredCount-count($missingRequired);$denominator=max(1,$requiredCount+count($setupCandidates));$completion=(int)round((($requiredReady+$setupReady)/$denominator)*100);
    $package=chat_onboarding_v241_package_state($user);$intelligence=onboarding_intelligence_state($pdo,$user);$workspace=chat_onboarding_v241_workspace_state($pdo,$user,$permissions);
    $interests=(array)($intelligence['feature_interests']??[]);foreach($workspace as $key=>&$item){$interest=(string)($item['interest_key']??'');$item['selected']=$interest!==''&&!empty($interests[$interest]);}unset($item);
    $activation=chat_onboarding_v241_activation_state($workspace,$intelligence,!$missingRequired);
    return ['build'=>STONEFELLOW_CHAT_ONBOARDING_V241,'user'=>['id'=>(int)$user['id'],'display_name'=>(string)($user['display_name']??'')],'system_agent_name'=>system_agent_name(),'agent'=>$defaultAgent,'profile'=>$profile,'profile_url'=>(string)($profileState['profile_url']??''),'suggested_username'=>chat_onboarding_v241_username($pdo,$user,$profile),'public_agent_status'=>$publicAgent,'chat'=>$chat,'voice'=>$voice,'permissions'=>$permissions,'package'=>$package,'intelligence'=>$intelligence,'workspace'=>$workspace,'activation'=>$activation,'setup'=>$requiredSetup,'capabilities'=>$capabilities,'missing'=>$missingRequired,'unavailable'=>$unavailable,'locked'=>$locked,'completion_percent'=>$completion,'required_setup_complete'=>!$missingRequired,'onboarding_dismissed'=>$onboardingComplete];
}

function chat_onboarding_v241_empty_tool_result(): array{return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];}

function chat_onboarding_v241_tool(string $query,array $user): array
{
    $empty=chat_onboarding_v241_empty_tool_result();$q=mb_strtolower(trim($query));if($q==='')return $empty;
    $intent=(bool)preg_match('/\b(onboarding|setup|set up|package|plan|subscription|trial|tokens?|quota|usage|upgrade|recommend|best plan|stem editor|video editor|profile agent|agent voice|voice clone|browser companion|annotations?|meetings?|calendar|booking|ecommerce|commerce|agent analytics|analytics|homeserver|teams?|team seats?|what.*missing|finish.*setup|set up next|setup next|getting started|activation|what.*left.*setup)\b/u',$q);if(!$intent)return $empty;
    $pdo=db();if(!$pdo)return $empty;
    try{if(!user_agent_system_schema_ready_v236($pdo)||!profile_agent_schema_ready($pdo)||!chat_settings_schema_ready_v237($pdo))return $empty;$state=chat_onboarding_v241_state($pdo,$user);}catch(Throwable $e){return $empty;}
    $result=$empty;$result['handled']=true;$result['sources'][]=['source'=>'account:onboarding-state','title'=>'Package and account setup state'];$pkg=$state['package']??[];$balance=$pkg['ai']??[];$cap=$state['capabilities']??[];$intel=$state['intelligence']??[];$recommendation=$intel['package_recommendation']??null;

    if(preg_match('/\b(tokens?|quota|usage|allowance|balance)\b/u',$q)){
        $remaining=!empty($balance['unlimited'])?'unlimited':number_format((int)($balance['remaining']??0));$allowance=!empty($balance['unlimited'])?'unlimited':number_format((int)($balance['package_allowance']??0));$topups=number_format((int)($balance['credits_remaining']??0));
        $result['answer']='Your '.((string)($pkg['package_name']??'current')).' package has '.$allowance.' package AI tokens, '.$topups.' top-up tokens remaining, and '.$remaining.' tokens available now.';
        if(!empty($pkg['period_end']))$result['answer'].=' Current period/trial end: '.date('M j, Y',strtotime((string)$pkg['period_end'])).'.';
        $result['actions'][]=['type'=>'open_url','label'=>'View Plan & AI Usage','url'=>url('/subscription.php')];return $result;
    }
    if(str_contains($q,'stem editor')||str_contains($q,'video editor')){
        $key=str_contains($q,'video editor')?'video_editor':'stem_editor';$item=$cap[$key]??[];
        if(empty($item['permitted']))$result['answer']=(string)($item['label']??'That editor').' is not included in your current package. It is an upgrade option, not an incomplete onboarding task.';
        else$result['answer']=(string)($item['label']??'That editor').' is included in your current package.';
        $result['actions'][]=['type'=>'open_url','label'=>empty($item['permitted'])?'View Packages':'Open '.(string)$item['label'],'url'=>empty($item['permitted'])?url('/subscription.php'):(string)($item['setup_url']??url('/subscription.php'))];return $result;
    }
    if(preg_match('/\b(package|plan|subscription|trial|upgrade|recommend|best plan)\b/u',$q)){
        $result['answer']='You are on '.((string)($pkg['package_name']??'an unassigned package')).' ('.((string)($pkg['status']??'unknown')).').';
        if(!empty($pkg['is_trial'])&&!empty($pkg['period_end'])){$end=strtotime((string)$pkg['period_end']);$days=$end?max(0,(int)ceil(($end-time())/86400)):null;$result['answer'].=' Your trial ends '.date('M j, Y',$end).($days!==null?' — '.$days.' day'.($days===1?'':'s').' remaining.':'.');}
        if(is_array($recommendation)&&!empty($recommendation['package_name'])){
            if(!empty($recommendation['is_current']))$result['answer'].=' Based on your recent feature usage, your current package is already the lowest matching package.';
            else{$reasons=array_slice((array)($recommendation['reasons']??[]),0,3);$result['answer'].=' Based on your recent feature usage, '.$recommendation['package_name'].' is the lowest available package that matches what you are using'.($reasons?' ('.implode('; ',$reasons).')':'').'.';}
        }else$result['answer'].=' I do not have enough actual feature-usage signals yet to recommend a different package.';
        $result['actions'][]=['type'=>'open_url','label'=>'View Plans','url'=>url('/subscription.php')];return $result;
    }
    if(str_contains($q,'agent voice')){
        $allowed=function_exists('chat_settings_agent_voice_allowed_v237')?chat_settings_agent_voice_allowed_v237($user):!empty($state['permissions']['voice_profile']);
        $enabled=$allowed&&!empty($state['chat']['agent_voice_enabled']);
        $result['answer']=$allowed?('Agent Voice is '.($enabled?'on. Spoken Agent responses and eligible notification announcements are enabled.':'off. Agent Chat stays text-first and notifications will not be spoken.')):'Agent Voice is not included for this account.';
        $result['actions'][]=['type'=>'open_url','label'=>$allowed?'Open Chat Settings':'View Plans','url'=>$allowed?url('/chat.php'):url('/subscription.php')];
        return $result;
    }
    if(str_contains($q,'profile agent')){$item=$cap['profile_agent']??[];$result['answer']=empty($item['permitted'])?'Profile Agent is not included in your current package. This does not reduce your onboarding completion.':(!empty($item['available'])?'Your Profile Agent is enabled and live.':'Profile Agent is included but still needs setup or activation.');if(!empty($item['permitted']))$result['actions'][]=['type'=>'open_url','label'=>'Open Profile Agent','url'=>(string)$item['setup_url']];else$result['actions'][]=['type'=>'open_url','label'=>'View Packages','url'=>url('/subscription.php')];return $result;}
    $activation=(array)($state['activation']??[]);
    if(preg_match('/\b(set up next|setup next|getting started|activation|what.*left.*setup|what.*set up next)\b/u',$q)){
        $next=is_array($activation['next_action']??null)?$activation['next_action']:null;
        if($next){
            $result['answer']='Your next selected VP3 setup step is '.(string)$next['label'].'. '.(string)($next['current_status']??'');
            $result['actions'][]=['type'=>'open_url','label'=>(string)($next['action_label']??('Open '.(string)$next['label'])),'url'=>(string)($next['setup_url']??url('/chat.php?setup=1'))];
        }elseif((int)($activation['deferred_count']??0)>0)$result['answer']='Your selected setup items are either complete or deferred for later. I will surface deferred items again when their reminder date arrives.';
        elseif((int)($activation['blocked_count']??0)>0)$result['answer']='Your selected setup has no immediate action, but one or more items are currently unavailable or outside this package.';
        else$result['answer']='Your selected VP3 systems are activated. You can reopen VP3 Setup any time to add another system.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open VP3 Setup','url'=>url('/chat.php?setup=1')];
        return $result;
    }
    if(str_contains($q,'voice clone')){$item=$cap['voice_clone']??[];$result['answer']=empty($item['permitted'])?'Voice Clone is not included in your current package.':(!empty($item['available'])?'Your voice clone is ready.':'Voice Clone is included but has not been created yet.');$result['actions'][]=['type'=>'open_url','label'=>empty($item['permitted'])?'View Packages':'Open Voice Profile','url'=>empty($item['permitted'])?url('/subscription.php'):(string)$item['setup_url']];return $result;}

    $workspace=(array)($state['workspace']??[]);
    $workspaceAliases=[
        'browser'=>['browser companion','annotation','annotations'],
        'meetings'=>['meeting','meetings'],
        'calendar'=>['calendar'],
        'booking'=>['booking','schedule','scheduling'],
        'commerce'=>['ecommerce','commerce','store','payment provider'],
        'analytics'=>['agent analytics','analytics'],
        'teams'=>['team','teams'],
        'homeserver'=>['homeserver','home server'],
        'transcription'=>['transcription','transcriptions','ai summary'],
    ];
    foreach($workspaceAliases as $key=>$aliases){
        $matched=false;foreach($aliases as $alias)if(str_contains($q,$alias)){$matched=true;break;}if(!$matched)continue;
        $item=$workspace[$key]??[];if(!$item)break;
        $label=(string)($item['label']??$key);$status=(string)($item['status']??'');
        if(empty($item['permitted']))$result['answer']=$label.' is not available with your current account/package configuration.';
        elseif(empty($item['available']))$result['answer']=$label.' is not ready on this VP3 installation yet.';
        elseif(!empty($item['configured']))$result['answer']=$label.' is ready. '.($status!==''?$status.'.':'');
        else$result['answer']=$label.' is available but still has setup remaining. '.($status!==''?$status.'.':'');
        $result['actions'][]=['type'=>'open_url','label'=>empty($item['permitted'])?'View Plans':(string)($item['action_label']??('Open '.$label)),'url'=>empty($item['permitted'])?url('/subscription.php'):(string)($item['setup_url']??url('/chat.php?setup=1'))];
        return $result;
    }

    $missing=is_array($state['missing']??null)?$state['missing']:[];$tasks=[];if(in_array('agent_named',$missing,true))$tasks[]='name your agent';if(in_array('profile_username',$missing,true))$tasks[]='choose your profile address';foreach($state['unavailable']??[] as $key){if(in_array($key,['profile_view','profile_agent','voice_profile'],true))$tasks[]='finish '.strtolower((string)($cap[$key]['label']??$key));}
    $completion=(int)($state['completion_percent']??0);$result['answer']='Your '.((string)($pkg['package_name']??'account')).' onboarding is '.$completion.'% complete.';
    if(($intel['current_step']??'')!=='complete')$result['answer'].=' Your saved onboarding step is '.str_replace('_',' ',(string)$intel['current_step']).'.';
    if($tasks)$result['answer'].=' Next: '.implode('; ',array_values(array_unique($tasks))).'.';else$result['answer'].=' All required setup included in your package is complete.';
    if(!empty($state['locked']))$result['answer'].=' Features outside your package are upgrade options and do not count against completion.';
    $result['actions'][]=['type'=>'open_url','label'=>$tasks?'Continue Setup':'View Plan & AI Usage','url'=>$tasks?url('/chat.php'):url('/subscription.php')];return $result;
}