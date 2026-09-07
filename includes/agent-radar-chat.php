<?php
declare(strict_types=1);

function vp3_radar_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['agent radar','agent gateway','ai agent','ai agents','bot','bots','crawler','crawlers','automated visitor','automated visitors','high risk agent','high-risk agent','who visited','visited my profile','website traffic','agent traffic','block this agent','block agent','allow agent','limit agent'] as $needle){
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
    if(!$policy)return 'monitor';
    $action=(string)($policy['action']??'monitor');
    if($action==='limit')return 'limit '.(int)($policy['metadata']['requests_per_30m']??30).'/30m';
    return $action;
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
        if(!$rows)return 'Agent Gateway has no explicit contact policies yet. New agent contacts default to Monitor.';
        $lines=['Current Agent Gateway contact policies:'];
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

    $contact=vp3_radar_chat_find_contact($pdo,(int)$user['id'],$subject);
    if(!$contact){
        return ['handled'=>true,'answer'=>'I could not match that name to one Agent Radar contact. Open Agent Radar or use the exact agent name, such as GPTBot or ChatGPT-User.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar','title'=>'Agent Radar']]];
    }
    $result=vp3_radar_gateway_set_contact_policy($pdo,$user,(int)$contact['id'],$action,$limit);
    $name=((string)$contact['operator_name']!==''?(string)$contact['operator_name'].' · ':'').(string)$contact['display_name'];
    $answer=match($action){
        'block'=>$name.' is now blocked across connected sites that have the server-side Agent Gateway installed.',
        'allow'=>$name.' is now allowed across connected sites. Radar will continue recording its activity.',
        'monitor'=>$name.' is now set to Monitor. Its activity is recorded without an access restriction.',
        'limit'=>$name.' is now limited to '.$limit.' requests per 30 minutes on each connected site with the server-side Gateway installed.',
        default=>$name.' policy updated.',
    };
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.gateway',$query,'success',['contact_id'=>(int)$contact['id'],'action'=>$action,'limit_30m'=>$action==='limit'?$limit:null],null);
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar:contact:'.(int)$contact['id'],'title'=>$name]]];
}

function vp3_radar_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_radar_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $action=vp3_radar_chat_action($pdo,$user,$query);
    if($action){
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.gateway',$query,'success',['handled'=>'write'],$conversationId);
        return $action;
    }
    $answer=vp3_radar_chat_recent_summary($pdo,$user,$query);
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_radar.query',$query,'success',['handled'=>'read'],$conversationId);
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-radar','title'=>'Agent Radar & Gateway']]];
}
