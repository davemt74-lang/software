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
    $package=chat_onboarding_v241_package_state($user);$intelligence=onboarding_intelligence_state($pdo,$user);
    return ['build'=>STONEFELLOW_CHAT_ONBOARDING_V241,'user'=>['id'=>(int)$user['id'],'display_name'=>(string)($user['display_name']??'')],'system_agent_name'=>system_agent_name(),'agent'=>$defaultAgent,'profile'=>$profile,'profile_url'=>(string)($profileState['profile_url']??''),'suggested_username'=>chat_onboarding_v241_username($pdo,$user,$profile),'public_agent_status'=>$publicAgent,'chat'=>$chat,'voice'=>$voice,'permissions'=>$permissions,'package'=>$package,'intelligence'=>$intelligence,'setup'=>$requiredSetup,'capabilities'=>$capabilities,'missing'=>$missingRequired,'unavailable'=>$unavailable,'locked'=>$locked,'completion_percent'=>$completion,'required_setup_complete'=>!$missingRequired,'onboarding_dismissed'=>$onboardingComplete];
}

function chat_onboarding_v241_empty_tool_result(): array{return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];}

function chat_onboarding_v241_tool(string $query,array $user): array
{
    $empty=chat_onboarding_v241_empty_tool_result();$q=mb_strtolower(trim($query));if($q==='')return $empty;
    $intent=(bool)preg_match('/\b(onboarding|setup|set up|package|plan|subscription|trial|tokens?|quota|usage|upgrade|recommend|best plan|stem editor|video editor|profile agent|voice clone|team seats?|what.*missing|finish.*setup)\b/u',$q);if(!$intent)return $empty;
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
    if(str_contains($q,'profile agent')){$item=$cap['profile_agent']??[];$result['answer']=empty($item['permitted'])?'Profile Agent is not included in your current package. This does not reduce your onboarding completion.':(!empty($item['available'])?'Your Profile Agent is enabled and live.':'Profile Agent is included but still needs setup or activation.');if(!empty($item['permitted']))$result['actions'][]=['type'=>'open_url','label'=>'Open Profile Agent','url'=>(string)$item['setup_url']];else$result['actions'][]=['type'=>'open_url','label'=>'View Packages','url'=>url('/subscription.php')];return $result;}
    if(str_contains($q,'voice clone')){$item=$cap['voice_clone']??[];$result['answer']=empty($item['permitted'])?'Voice Clone is not included in your current package.':(!empty($item['available'])?'Your voice clone is ready.':'Voice Clone is included but has not been created yet.');$result['actions'][]=['type'=>'open_url','label'=>empty($item['permitted'])?'View Packages':'Open Voice Profile','url'=>empty($item['permitted'])?url('/subscription.php'):(string)$item['setup_url']];return $result;}

    $missing=is_array($state['missing']??null)?$state['missing']:[];$tasks=[];if(in_array('agent_named',$missing,true))$tasks[]='name your agent';if(in_array('profile_username',$missing,true))$tasks[]='choose your profile address';foreach($state['unavailable']??[] as $key){if(in_array($key,['profile_view','profile_agent','voice_profile'],true))$tasks[]='finish '.strtolower((string)($cap[$key]['label']??$key));}
    $completion=(int)($state['completion_percent']??0);$result['answer']='Your '.((string)($pkg['package_name']??'account')).' onboarding is '.$completion.'% complete.';
    if(($intel['current_step']??'')!=='complete')$result['answer'].=' Your saved onboarding step is '.str_replace('_',' ',(string)$intel['current_step']).'.';
    if($tasks)$result['answer'].=' Next: '.implode('; ',array_values(array_unique($tasks))).'.';else$result['answer'].=' All required setup included in your package is complete.';
    if(!empty($state['locked']))$result['answer'].=' Features outside your package are upgrade options and do not count against completion.';
    $result['actions'][]=['type'=>'open_url','label'=>$tasks?'Continue Setup':'View Plan & AI Usage','url'=>$tasks?url('/chat.php'):url('/subscription.php')];return $result;
}