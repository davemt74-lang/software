<?php
declare(strict_types=1);
require_once __DIR__.'/vp3-analytics-chat.php';
require_once __DIR__.'/agent-radar-read-v1.php';

function vp3_radar_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['agent radar','agent gateway','ai agent','bot','crawler','automated visitor','high risk agent','high-risk agent','who visited','visited my profile','agent traffic','block agent','allow agent','limit agent','access profile','gateway profile','search friendly','agent friendly','open web','locked down','monitor all','block operator','allow operator','block all crawlers','unknown automation','advanced rules','gateway rules','/admin/'] as $needle)if(str_contains($q,$needle))return true;
    return(bool)preg_match('/\b(?:radar|gateway)\b|^(?:please\s+)?(?:block|allow|monitor|limit)\s+(?:agent\s+)?#?[1-9][0-9]*(?:\s|[.!?]|$)/i',$q);
}

function vp3_radar_chat_clean_subject(string $value): string
{
    $value=trim($value," \t\n\r\0\x0B\"'?.!");
    $value=preg_replace('/\s+(?:everywhere|across all sites|on all sites|on my sites|from my sites)$/i','',$value)??$value;
    return trim(preg_replace('/^(?:the\s+)?(?:agent\s+)?/i','',$value)??$value);
}

/** Preserve the full owned-contact contract for incoming access/CRM callers, with exact targeting. */
function vp3_radar_chat_find_contact(PDO $pdo,int $ownerUserId,string $subject): ?array
{
    $match=vp3_radar_resolve_contact_v1($pdo,$ownerUserId,vp3_radar_chat_clean_subject($subject));
    if(!$match)return null;
    $q=$pdo->prepare('SELECT c.*,r.slug AS registry_slug FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.id=? AND c.owner_user_id=? LIMIT 1');
    $q->execute([(int)$match['id'],$ownerUserId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_radar_chat_principal_v1(PDO $pdo,array $user,int $conversationId): array
{
    if(!function_exists('vp3_capability_control_principal_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';
    return vp3_capability_control_principal_v1($pdo,$user,$conversationId);
}

/** Pure legacy selectors feed the same bounded adapter used by planned typed/voice reads. */
function vp3_radar_chat_read_plan_v1(string $query): array
{
    $q=mb_strtolower($query);$view='recent';
    if(preg_match('/\b(?:access|gateway) profile\b/',$q))$view='access_profile';
    elseif(preg_match('/\b(?:advanced|gateway|scoped) rules\b/',$q))$view='scoped_rules';
    elseif(preg_match('/\b(?:high[- ]risk|suspicious)\b/',$q))$view='high_risk';
    elseif(preg_match('/\b(?:blocked|polic(?:y|ies)|overrides|gateway)\b/',$q))$view='contact_policies';
    $plan=['operation'=>'radar_read','ids'=>['radar.read'],'view'=>$view];
    if($view==='contact_policies'&&preg_match_all('/\bcontact\s*#?([1-9][0-9]*)\b/i',$query,$m)){
        if(count($m[1])!==1||preg_match('/\b(?:and|or)\s+#?[1-9][0-9]*\b/i',$query)||preg_match('/\b(?:matching|named)\b/i',$query))throw new InvalidArgumentException('Choose one exact Radar contact.');
        $plan['contact_id']=vp3_radar_number_v1($m[1][0],1);
    }
    elseif(in_array($view,['recent','high_risk','contact_policies'],true)&&preg_match('/\b(?:for|matching|named)\s+["\x27]([^"\x27]+)["\x27]\s*[?.!]*$/u',$query,$m))$plan['query']=$m[1];
    elseif(preg_match('/\b(?:for|matching|named)\s+|\bcontact\s*(?:#|[0-9])/i',$query))throw new InvalidArgumentException('Choose one supported Radar selector.');
    return vp3_radar_plan_v1($plan);
}

function vp3_radar_chat_read_v1(PDO $pdo,array $user,string $query,int $conversationId=0): array
{
    if(vp3_radar_nonexecuting_v1($query)!=='')return ['handled'=>false,'answer'=>'','actions'=>[],'sources'=>[]];
    try{return vp3_radar_read_v1($pdo,$user,vp3_radar_chat_principal_v1($pdo,$user,$conversationId),vp3_radar_chat_read_plan_v1($query),['conversation_id'=>$conversationId]);}
    catch(Throwable $e){return vp3_radar_failure_v1($e);}
}

function vp3_radar_chat_access_profile_action(PDO $pdo,array $user,string $query,int $conversationId=0): ?array
{
    $command=vp3_radar_command_v1($query);if(($command['type']??'')!=='profile')return null;
    return vp3_radar_apply_command_v1($pdo,$user,vp3_radar_chat_principal_v1($pdo,$user,$conversationId),['conversation_id'=>$conversationId],$query);
}

function vp3_radar_chat_scoped_action(PDO $pdo,array $user,string $query,int $conversationId=0): ?array
{
    $command=vp3_radar_command_v1($query);if(($command['type']??'')!=='scoped')return null;
    $result=vp3_radar_apply_command_v1($pdo,$user,vp3_radar_chat_principal_v1($pdo,$user,$conversationId),['conversation_id'=>$conversationId],$query);
    if(in_array($result['tool_receipt']['status']??'', ['completed','read_complete'],true))$result['answer'].=' Contact-specific overrides still take precedence.';
    return $result;
}

function vp3_radar_chat_action(PDO $pdo,array $user,string $query,int $conversationId=0): ?array
{
    $command=vp3_radar_command_v1($query);if(($command['type']??'')!=='contact')return null;
    return vp3_radar_apply_command_v1($pdo,$user,vp3_radar_chat_principal_v1($pdo,$user,$conversationId),['conversation_id'=>$conversationId],$query);
}

function vp3_radar_chat_scoped_summary(PDO $pdo,array $user,string $query,int $conversationId=0): ?array
{
    if(!preg_match('/\b(?:advanced|gateway|scoped) rules\b/i',$query))return null;
    return vp3_radar_chat_read_v1($pdo,$user,$query,$conversationId);
}

function vp3_radar_chat_recent_summary(PDO $pdo,array $user,string $query,int $conversationId=0): string
{
    return vp3_radar_chat_read_v1($pdo,$user,$query,$conversationId)['answer'];
}

function vp3_radar_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $nonexecuting=vp3_radar_nonexecuting_v1($query);
    if($nonexecuting!==''){
        if($nonexecuting!=='inspect'||!vp3_radar_chat_intent($query))return $empty;
        return array_replace($empty,['handled'=>true,'answer'=>'Radar tools can read your saved contact observations, high-risk contacts, contact policies, scoped rules and Access Profile. Clear commands can save a policy for one exact contact, operator, class or path through the existing Gateway controls. Review them in Agent Radar. A saved policy does not verify enforcement on connected sites.',
            'tool_receipt'=>vp3_tool_receipt_v1('capabilities.inspect','inspect','read_complete')]);
    }
    // Incoming-agent message approvals keep their existing separate authority and workflow.
    // Explicit Radar reads skip the older operator-summary fallback inside that delegate.
    $directRadar=vp3_radar_chat_intent($query)&&(bool)preg_match('/^\s*(?:please\s+)?(?:show|list|read|review|check|what|which|who|how many|tell me|block|allow|monitor|limit|use|apply|set|switch|change|enable|disable|delete|remove)\b/i',$query);
    $incoming=function_exists('vp3_agent_access_chat_intent')&&vp3_agent_access_chat_intent($query);
    if($incoming&&function_exists('vp3_agent_access_chat_tool')){$messaging=vp3_agent_access_chat_tool($query,$user,$conversationId);if(!empty($messaging['handled']))return $messaging;}
    if(!$incoming&&function_exists('vp3_analytics_chat_intent')&&vp3_analytics_chat_intent($query)&&function_exists('vp3_analytics_chat_tool'))return vp3_analytics_chat_tool($query,$user,$conversationId);
    if(!$incoming&&!$directRadar&&function_exists('vp3_agent_access_chat_tool')){$messaging=vp3_agent_access_chat_tool($query,$user,$conversationId);if(!empty($messaging['handled']))return $messaging;}
    if(!$directRadar&&function_exists('vp3_analytics_chat_tool')){$analytics=vp3_analytics_chat_tool($query,$user,$conversationId);if(!empty($analytics['handled']))return $analytics;}
    if(!vp3_radar_chat_intent($query))return $empty;
    try{
        $command=vp3_radar_command_v1($query);
        if(!$command&&preg_match('/\b(?:block|allow|monitor|limit|apply|set|switch|change|enable|disable|delete|remove)\b/i',$query))return vp3_radar_result_v1('Use one clear Gateway command with an exact contact ID or name, explicit operator, class, path, or Access Profile. Review Agent Radar to choose the target. No policy was changed.','clarification_required',0,'radar.gateway.manage','set_policy');
        if(!$command&&!preg_match('/^\s*(?:please\s+)?(?:show|list|read|review|check|what|which|who|how many|tell me)\b/i',$query))return $empty;
        $pdo=db();if(!$pdo)throw new RuntimeException('Radar storage unavailable.');
        if($command){
            $result=match($command['type']){
                'profile'=>vp3_radar_chat_access_profile_action($pdo,$user,$query,$conversationId),
                'scoped'=>vp3_radar_chat_scoped_action($pdo,$user,$query,$conversationId),
                default=>vp3_radar_chat_action($pdo,$user,$query,$conversationId),
            };
        }else $result=vp3_radar_chat_read_v1($pdo,$user,$query,$conversationId);
        if(function_exists('agent_tool_log'))try{agent_tool_log($user,$command?'agent_radar.gateway':'agent_radar.query',$query,($result['tool_receipt']['status']??'')==='failed'?'failed':'success',['receipt_status'=>$result['tool_receipt']['status']??'failed'],$conversationId);}catch(Throwable $ignored){}
        return array_replace($empty,$result);
    }catch(Throwable $e){return vp3_radar_failure_v1($e,(bool)preg_match('/^\s*(?:please\s+)?(?:block|allow|monitor|limit|apply|set|switch|change|enable)\b/i',$query));}
}
