<?php
declare(strict_types=1);
require_once __DIR__.'/agent-analytics-read-v1.php';

function vp3_analytics_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    // Explicit Agent/Radar/Gateway requests belong to the dedicated Radar tool.
    if(preg_match('/\b(?:agent radar|agent gateway|ai agent|bot|crawler|block agent|allow agent|limit agent)\b/',$q))return false;
    if(preg_match('/\b(?:who|how many(?:\s+(?:people|visitors?))?)\s+visited\s+(?:my\s+)?(?:website|web site|site|profile)\b/u',$q))return true;
    if(preg_match('/\b(?:website|web site|site|profile)\s+(?:visitors?|visits?)\b|\b(?:how many|number of|count|total)\b.*\b(?:visitors?|visits?)\b/u',$q))return true;
    foreach(['analytics','my traffic','website traffic','profile traffic','page views','pageviews','visitors this','sessions this','traffic this','traffic today','which website','which site','top page','top pages','referrers','referral traffic','conversions','conversion rate','device mix','browser mix'] as $needle){
        if(str_contains($q,$needle))return true;
    }
    return false;
}

function vp3_analytics_chat_days(string $query): int
{
    $q=mb_strtolower($query);
    if(str_contains($q,'today'))return 1;
    if(str_contains($q,'week')||str_contains($q,'7 day'))return 7;
    if(str_contains($q,'90 day')||str_contains($q,'quarter'))return 90;
    return 30;
}

function vp3_analytics_chat_property_name(array $row): string
{
    return (string)($row['property_type']??'')==='native'
        ? 'VP3 Profile'
        : (trim((string)($row['label']??'')) ?: (trim((string)($row['domain']??'')) ?: 'Connected site'));
}

/** The fallback grammar only chooses the same strict read plan used by typed and voice Chat. */
function vp3_analytics_chat_plan_v1(string $query): array
{
    $q=mb_strtolower($query);$view='summary';
    foreach(['top_pages'=>'/\btop pages?\b/','referrers'=>'/\b(?:referrers?|referral traffic)\b/','conversions'=>'/\bconversions?\b/','devices'=>'/\bdevices?\b/','browsers'=>'/\bbrowsers?\b/','properties'=>'/\b(?:properties|tracked sites|connected sites|which (?:web)?site)\b/'] as $candidate=>$pattern)if(preg_match($pattern,$q)){$view=$candidate;break;}
    $period=str_contains($q,'today')?'today':(preg_match('/\b(?:90\s*(?:days?|d)|quarter)\b/',$q)?'90d':(preg_match('/\b(?:7\s*(?:days?|d)|week)\b/',$q)?'7d':'30d'));
    if(preg_match('/\b(?:yesterday|tomorrow|last month|this month|last year|this year|between|timezone|time zone)\b|\b(?:19|20)\d{2}-\d{2}-\d{2}\b/',$q))throw new InvalidArgumentException('Choose a supported reporting period.');
    if(preg_match('/\b[0-9]+\s*(?:seconds?|minutes?|hours?|h|weeks?|months?|years?)\b/',$q))throw new InvalidArgumentException('Choose a supported reporting period.');
    if(preg_match_all('/\b([0-9]+)\s*days?\b/',$q,$durations))foreach($durations[1] as $duration)if(!in_array($duration,['7','30','90'],true))throw new InvalidArgumentException('Choose a supported reporting period.');
    $plan=['operation'=>'analytics_read','ids'=>['analytics.read'],'view'=>$view,'period'=>$period,'scope'=>'all_owned'];
    if(preg_match('/\b(?:property|site)\s*#\s*([1-9][0-9]*)\b/i',$query,$m))return vp3_analytics_plan_v1(array_replace($plan,['scope'=>'property','property_id'=>vp3_analytics_count_v1($m[1])]));
    if(preg_match_all('~https?://[^\s<>"\x27]+|\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\b~i',$query,$matches)&&$matches[0]){
        $targets=array_values(array_unique(array_map(static fn($target)=>rtrim($target,',;.!?'),$matches[0])));
        if(count($targets)!==1)throw new InvalidArgumentException('Choose one exact tracked property.');
        return vp3_analytics_plan_v1(array_replace($plan,['scope'=>'property','property'=>$targets[0]]));
    }
    if(preg_match('/\b(?:my|vp3) profile\b/i',$query))return array_replace($plan,['scope'=>'property','property'=>'VP3 Profile']);
    if(preg_match('/\b(?:for|on|named)\s+["\x27]([^"\x27]+)["\x27]/u',$query,$m))return vp3_analytics_plan_v1(array_replace($plan,['scope'=>'property','property'=>$m[1]]));
    if(preg_match('/\b(?:for|on)\s+(.+)$/iu',$query,$m)){
        $selector=trim(preg_replace('/\s+(?:today|(?:in|over|during|for)\s+)?(?:the\s+)?(?:last|past)?\s*(?:7|30|90)\s*days?\s*[?.!]*$|\s+today\s*[?.!]*$/iu','',$m[1])??'');$selector=rtrim($selector,'?.!');
        if(!preg_match('/^(?:all\s+)?(?:my\s+)?(?:owned\s+)?(?:tracked\s+)?(?:sites|properties|websites)$/iu',$selector))return vp3_analytics_plan_v1(array_replace($plan,['scope'=>'property','property'=>$selector]));
    }
    return $plan;
}

function vp3_analytics_chat_summary(PDO $pdo,array $user,string $query,int $conversationId=0): string
{
    try{if(!function_exists('vp3_capability_control_principal_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';$principal=vp3_capability_control_principal_v1($pdo,$user,$conversationId);return vp3_analytics_read_v1($pdo,$user,$principal,vp3_analytics_chat_plan_v1($query),['conversation_id'=>$conversationId])['answer'];}
    catch(Throwable $e){return vp3_analytics_failure_v1($e)['answer'];}
}

function vp3_analytics_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_analytics_chat_intent($query))return $empty;
    if(preg_match('/\b(?:tutorial|hypothetical|imagine|suppose|explain|how (?:to|does|do)|what (?:is|are) (?!my\b)|(?:if|when|unless) (?:i|we|you|the|my|our|a|an)|build|design|implement|create|delete|connect|disconnect|activate|deactivate|enable|disable)\b/i',$query))return $empty;
    if(preg_match('/\b(?:capabilit(?:y|ies)|what can you do|are you (?:able|allowed)|do you have (?:access|permission)|(?:can|could|would) you (?:access|read|inspect|manage)|(?:does|do)\b.*\b(?:support|allow|handle))\b/i',$query))return ['handled'=>true,'answer'=>'Analytics can read current-owner stored properties and bounded traffic aggregates. This description has not read traffic records or changed tracking.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('capabilities.inspect','inspect','read_complete')];
    try{
        $plan=vp3_analytics_chat_plan_v1($query);$pdo=db();if(!$pdo)throw new RuntimeException('Analytics storage unavailable.',409);
        if(!function_exists('vp3_capability_control_principal_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';
        $principal=vp3_capability_control_principal_v1($pdo,$user,$conversationId);
        return vp3_analytics_read_v1($pdo,$user,$principal,$plan,['conversation_id'=>$conversationId]);
    }catch(InvalidArgumentException $e){return ['handled'=>true,'answer'=>'Choose today or a rolling 7, 30 or 90 days in the stated database reporting timezone, and one exact tracked property or all owned properties. No traffic was read for the unsupported selection.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('analytics.read','list','clarification_required')];}
    catch(Throwable $e){return vp3_analytics_failure_v1($e);}
}
