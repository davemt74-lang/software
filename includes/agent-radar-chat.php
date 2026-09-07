<?php
declare(strict_types=1);
require_once __DIR__.'/vp3-analytics-chat.php';

function vp3_radar_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['agent radar','agent gateway','ai agent','ai agents','bot','bots','crawler','crawlers','automated visitor','automated visitors','high risk agent','high-risk agent','who visited','visited my profile','website traffic','agent traffic','block this agent','block agent','allow agent','limit agent','access profile','search friendly','agent friendly','open web','locked down','lock down agent','private profile','monitor all','block operator','allow operator','block all crawlers','allow all crawlers','unknown automation','advanced rules','gateway rules','/admin/'] as $needle){
        if(str_contains($q,$needle))return true;
    }
    return false;
}

function vp3_radar_chat_clean_subject(string $value): string
{
    $value=trim($value," \t\n\r\0\x0B\"'?.!");
    $value=preg_replace('/\s+(?:everywhere|across all sites|on all sites|on my sites|from my sites)$/i','',$value)??$value;
    $value=preg_replace('/^(?:the\s+)?(?:agent\s+)?/i','',$value)??$value;
    return mb_strimwidth(trim($value),0,190,'');
}

function vp3_radar_chat_find_contact(PDO $pdo,int $ownerUserId,string $subject): ?array
{
    if($ownerUserId<1)return null;
    $subject=vp3_radar_chat_clean_subject($subject);
    if($subject==='')return null;
    if(preg_match('/^#?(\d+)$/',$subject,$m)){
        $stmt=$pdo->prepare('SELECT c.*,r.slug AS registry_slug FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.id=? AND c.owner_user_id=? LIMIT 1');
        $stmt->execute([(int)$m[1],$ownerUserId]);
        return $stmt->fetch()?:null;
    }
    $stmt=$pdo->prepare('SELECT c.*,r.slug AS registry_slug FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.owner_user_id=? ORDER BY c.last_seen_at DESC,c.id DESC LIMIT 250');
    $stmt->execute([$ownerUserId]);
    $needle=mb_strtolower($subject);$best=null;$bestScore=0;
    foreach($stmt->fetchAll()?:[] as $row){
        $name=mb_strtolower(trim((string)$row['display_name']));
        $operator=mb_strtolower(trim((string)$row['operator_name']));
        $slug=mb_strtolower(trim((string)($row['registry_slug']??'')));
        $score=0;
        if($name===$needle)$score=100;
        elseif($slug!==''&&$slug===$needle)$score=95;
        elseif($operator!==''&&$operator===$needle)$score=80;
        elseif($name!==''&&(str_contains($name,$needle)||str_contains($needle,$name)))$score=70;
        elseif($slug!==''&&(str_contains($slug,$needle)||str_contains($needle,$slug)))$score=65;
        elseif($operator!==''&&(str_contains($operator,$needle)||str_contains($needle,$operator)))$score=55;
        if($score>$bestScore){$best=$row;$bestScore=$score;}
    }
    return $bestScore>=55?$best:null;
}

function vp3_radar_chat_policy_label(?array $policy): string
{
    if(!$policy)return 'profile default';
    $action=(string)($policy['action']??'monitor');
    if($action==='limit')return 'limit '.(int)($policy['metadata']['requests_per_30m']??30).'/30m';
    return $action;
}

function vp3_radar_chat_access_profile_slug(string $query): string
{
    $q=mb_strtolower($query);
    $map=[
        'search friendly'=>'search_friendly',
        'agent friendly'=>'agent_friendly',
        'open web'=>'open_web',
        'locked down'=>'locked_down',
        'lock down'=>'locked_down',
        'private profile'=>'private',
        'private access'=>'private',
        'monitor all'=>'monitor_all',
    ];
    foreach($map as $needle=>$slug)if(str_contains($q,$needle))return $slug;
    return '';
}

function vp3_radar_chat_access_profile_action(PDO $pdo,array $user,string $query): ?array
{
    $q=mb_strtolower(trim($query));
    $slug=vp3_radar_chat_access_profile_slug($query);
    $mentionsProfile=str_contains($q,'access profile')||str_contains($q,'gateway profile')||$slug!=='';
    if(!$mentionsProfile)return null;
    $isWrite=(bool)preg_match('/\b(?:use|set|apply|switch|change|make|enable|lock down)\b/i',$query);
    if($slug!==''&&$isWrite){
        $result=vp3_radar_access_profile_apply($pdo,$user,$slug);$profile=$result['profile']??null;
        return ['handled'=>true,'answer'=>'Agent Gateway default access profile is now '.(string)($profile['label']??$slug).'. '.(string)($profile['description']??'').' Existing per-contact overrides remain in place.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:access-profile','title'=>'Agent Gateway Access Profile']]];
    }
    $current=vp3_radar_access_profile_current($pdo,(int)$user['id']);
    if(!$current)return ['handled'=>true,'answer'=>'Agent Gateway is currently using its implicit Monitor default. You can choose Open Web, Search Friendly, Agent Friendly, Private, Locked Down, or Monitor All.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:access-profile','title'=>'Agent Gateway Access Profile']]];
    return ['handled'=>true,'answer'=>'Your current Agent Gateway access profile is '.(string)$current['label'].'. '.(string)$current['description'].' Per-contact policies override the profile when you set one.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:access-profile','title'=>'Agent Gateway Access Profile']]];
}

function vp3_radar_chat_class_slug(string $value): string
{
    $q=mb_strtolower(trim($value));
    if(str_contains($q,'unknown'))return 'automated_unknown';
    if(str_contains($q,'search'))return 'ai_search';
    if(str_contains($q,'crawler')||str_contains($q,'training'))return 'ai_crawler';
    if(str_contains($q,'user agent')||str_contains($q,'user-directed')||str_contains($q,'assistant agent'))return 'ai_user_agent';
    return '';
}

function vp3_radar_chat_scoped_action(PDO $pdo,array $user,string $query): ?array
{
    $q=trim($query);$action='';$scopeType='';$scopeValue='';
    if(preg_match('/\b(block|allow|monitor)\s+operator\s+(.+?)[.!?]*$/i',$q,$m)){
        $action=strtolower((string)$m[1]);$scopeType='operator';$scopeValue=vp3_radar_chat_clean_subject((string)$m[2]);
    }elseif(preg_match('/\b(block|allow|monitor)\s+all\s+(.+?)\s+agents?[.!?]*$/i',$q,$m)){
        $action=strtolower((string)$m[1]);$scopeType='operator';$scopeValue=vp3_radar_chat_clean_subject((string)$m[2]);
    }elseif(preg_match('/\b(block|allow|monitor)\s+(?:all\s+)?(crawlers?|training crawlers?|ai search|search agents?|unknown automation|user-directed agents?)[.!?]*$/i',$q,$m)){
        $action=strtolower((string)$m[1]);$scopeType='class';$scopeValue=vp3_radar_chat_class_slug((string)$m[2]);
    }elseif(preg_match('/\b(block|allow|monitor)\s+(?:agents?|bots?|automation)\s+(?:on|from)\s+(\/[^\s?#]+\*?)[.!?]*$/i',$q,$m)){
        $action=strtolower((string)$m[1]);$scopeType='path';$scopeValue=(string)$m[2];
    }else return null;
    if($scopeValue==='')return null;
    vp3_radar_gateway_set_scoped_rule($pdo,$user,$scopeType,$scopeValue,$action,0,VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M);
    $label=$scopeType==='class'?str_replace('_',' ',$scopeValue):$scopeValue;
    return ['handled'=>true,'answer'=>'Agent Gateway advanced rule saved: '.$action.' '.$scopeType.' '.$label.' across connected sites. Contact-specific overrides still take precedence.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:scoped-rule','title'=>'Agent Gateway Advanced Rule']]];
}

function vp3_radar_chat_scoped_summary(PDO $pdo,array $user,string $query): ?array
{
    $q=mb_strtolower($query);if(!str_contains($q,'advanced rules')&&!str_contains($q,'gateway rules'))return null;
    $rules=vp3_radar_gateway_scoped_rules($pdo,(int)$user['id']);
    if(!$rules)return ['handled'=>true,'answer'=>'Agent Gateway has no advanced operator, class, or path rules. Your Access Profile and contact overrides remain active.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:scoped-rule','title'=>'Agent Gateway Advanced Rules']]];
    $lines=['Agent Gateway advanced rules:'];
    foreach(array_slice($rules,0,20) as $rule){$site=(int)($rule['property_id']??0)>0?' on '.((string)($rule['property_label']??$rule['property_domain']??'connected site')):' across all connected sites';$lines[]='• '.(string)$rule['action'].' '.(string)$rule['scope_type'].' '.(string)$rule['scope_value'].$site.'.';}
    return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-gateway:scoped-rule','title'=>'Agent Gateway Advanced Rules']]];
}

function vp3_radar_chat_recent_summary(PDO $pdo,array $user,string $query): string
{
    $uid=(int)$user['id'];$q=mb_strtolower($query);
    if(str_contains($q,'high risk')||str_contains($q,'high-risk')||str_contains($q,'suspicious')){
        $stmt=$pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE owner_user_id=? AND risk_score>=70 ORDER BY risk_score DESC,last_seen_at DESC LIMIT 10');
        $stmt->execute([$uid]);$rows=$stmt->fetchAll()?:[];
        if(!$rows)return 'Agent Radar has no contacts currently scored high risk (70 or above).';
        $lines=['High-risk Agent Radar contacts:'];
        foreach($rows as $row){$policy=vp3_radar_gateway_contact_policy($pdo,$uid,(int)$row['id']);$lines[]='• '.((string)$row['operator_name']!==''?(string)$row['operator_name'].' · ':'').(string)$row['display_name'].' — risk '.(int)$row['risk_score'].'/100, '.(int)$row['session_count'].' sessions, policy '.vp3_radar_chat_policy_label($policy).'.';}
        return implode("\n",$lines);
    }
    if(str_contains($q,'blocked')||str_contains($q,'policies')||str_contains($q,'gateway')){
        $stmt=$pdo->prepare("SELECT c.id,c.display_name,c.operator_name,p.action,p.metadata_json,p.updated_at FROM vp3_agent_policies p INNER JOIN vp3_agent_contacts c ON c.id=p.agent_contact_id WHERE p.owner_user_id=? AND p.is_active=1 AND p.agent_contact_id IS NOT NULL AND p.property_id IS NULL AND p.path_pattern='*' ORDER BY p.updated_at DESC,p.id DESC LIMIT 20");
        $stmt->execute([$uid]);$rows=$stmt->fetchAll()?:[];
        if(!$rows)return 'Agent Gateway has no explicit contact overrides yet. New agent contacts use the current default access profile.';
        $lines=['Current Agent Gateway contact overrides:'];
        foreach($rows as $row){$meta=vp3_radar_gateway_policy_metadata((string)$row['metadata_json']);$label=(string)$row['action'];if($label==='limit')$label.=' '.(int)($meta['requests_per_30m']??30).'/30m';$lines[]='• '.((string)$row['operator_name']!==''?(string)$row['operator_name'].' · ':'').(string)$row['display_name'].' — '.$label.'.';}
        return implode("\n",$lines);
    }
    $contacts=vp3_radar_agent_contacts($pdo,$uid,10);
    if(!$contacts)return 'Agent Radar has not recorded any automated agent contacts yet.';
    $lines=['Recent Agent Radar contacts:'];
    foreach(array_slice($contacts,0,10) as $row){$policy=vp3_radar_gateway_contact_policy($pdo,$uid,(int)$row['id']);$lines[]='• '.((string)$row['operator_name']!==''?(string)$row['operator_name'].' · ':'').(string)$row['display_name'].' — '.str_replace('_',' ',(string)$row['visitor_class']).', risk '.(int)$row['risk_score'].'/100, '.(int)$row['session_count'].' sessions, '.(int)$row['page_view_count'].' views, policy '.vp3_radar_chat_policy_label($policy).'.';}
    return implode("\n",$lines);
}

function vp3_radar_chat_action(PDO $pdo,array $user,string $query): ?array
{
    $q=trim($query);$action='';$subject='';$limit=VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M;
    if(preg_match('/\blimit\s+(.+?)\s+(?:to\s+)?(\d+)\s*(?:requests?)?(?:\s*(?:per|\/)\s*30\s*(?:minutes?|mins?|m))?(?:\s+everywhere|\s+across all sites|\s+on all sites)?[.!?]*$/i',$q,$m)){
        $action='limit';$subject=(string)$m[1];$limit=max(1,min(10000,(int)$m[2]));
    }elseif(preg_match('/\b(block|allow|monitor)\s+(.+?)(?:\s+everywhere|\s+across all sites|\s+on all sites|\s+on my sites|\s+from my sites)?[.!?]*$/i',$q,$m)){
        $action=mb_strtolower((string)$m[1]);$subject=(string)$m[2];
    }else return null;

    $owner=(int)$user['id'];$contact=vp3_radar_chat_find_contact($pdo,$owner,$subject);
    if(!$contact){
        return ['handled'=>true,'answer'=>'I could not match that name to one Agent Radar contact. Open Agent Radar or use the exact agent name, such as GPTBot or ChatGPT-User.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar','title'=>'Agent Radar']]];
    }
    $contactId=(int)$contact['id'];$before=vp3_radar_gateway_contact_policy($pdo,$owner,$contactId);
    $result=vp3_radar_gateway_set_contact_policy($pdo,$user,$contactId,$action,$limit);$after=is_array($result['policy']??null)?$result['policy']:null;
    $name=((string)$contact['operator_name']!==''?(string)$contact['operator_name'].' · ':'').(string)$contact['display_name'];
    $beforeAction=(string)($before['action']??'profile_default');$afterAction=(string)($after['action']??$action);
    $beforeLimit=$beforeAction==='limit'?(int)($before['metadata']['requests_per_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M):null;
    $afterLimit=$afterAction==='limit'?(int)($after['metadata']['requests_per_30m']??$limit):null;
    if(function_exists('vp3_agent_crm_audit_event')&&($beforeAction!==$afterAction||$beforeLimit!==$afterLimit)){
        vp3_agent_crm_audit_event($pdo,$owner,$contactId,'agent_policy_changed',$name.' Agent Gateway policy changed from '.$beforeAction.' to '.$afterAction.'.',[
            'previous_action'=>$beforeAction,'new_action'=>$afterAction,'previous_limit_30m'=>$beforeLimit,'new_limit_30m'=>$afterLimit,'surface'=>'main_feed',
        ]);
    }
    $answer=match($action){
        'block'=>$name.' is now blocked across connected sites that have the server-side Agent Gateway installed.',
        'allow'=>$name.' is now allowed across connected sites. Radar will continue recording its activity.',
        'monitor'=>$name.' is now set to Monitor. Its activity is recorded without an access restriction.',
        'limit'=>$name.' is now limited to '.$limit.' requests per 30 minutes on each connected site with the server-side Gateway installed.',
        default=>$name.' policy updated.',
    };
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar:contact:'.$contactId,'title'=>$name]]];
}

function vp3_radar_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(function_exists('vp3_agent_access_chat_tool')){
        $messaging=vp3_agent_access_chat_tool($query,$user,$conversationId);
        if(!empty($messaging['handled']))return $messaging;
    }
    if(function_exists('vp3_analytics_chat_tool')){
        $analytics=vp3_analytics_chat_tool($query,$user,$conversationId);
        if(!empty($analytics['handled']))return $analytics;
    }
    if(!vp3_radar_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $profile=vp3_radar_chat_access_profile_action($pdo,$user,$query);
    if($profile){if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.access_profile',$query,'success',['handled'=>'profile'],$conversationId);return $profile;}
    $scoped=vp3_radar_chat_scoped_action($pdo,$user,$query)??vp3_radar_chat_scoped_summary($pdo,$user,$query);
    if($scoped){if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.scoped_rule',$query,'success',['handled'=>'scoped'],$conversationId);return $scoped;}
    $action=vp3_radar_chat_action($pdo,$user,$query);
    if($action){
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.gateway',$query,'success',['handled'=>'write'],$conversationId);
        return $action;
    }
    $answer=vp3_radar_chat_recent_summary($pdo,$user,$query);
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.query',$query,'success',['handled'=>'read'],$conversationId);
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar','title'=>'Agent Radar & Gateway']]];
}
