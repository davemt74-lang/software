<?php
declare(strict_types=1);

function vp3_agent_crm_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['watched agents','watchlist','watch agent','watch this agent','stop watching','unwatch agent','unwatch this agent'] as $needle)if(str_contains($q,$needle))return true;
    if(preg_match('/^(?:please\s+)?(?:watch|unwatch)\s+[a-z0-9]/i',$q))return true;
    return false;
}

function vp3_agent_crm_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_agent_crm_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $q=trim($query);$lower=mb_strtolower($q);
    if(str_contains($lower,'watched agents')||str_contains($lower,'watchlist')){
        $rows=array_values(array_filter(vp3_agent_crm_contacts($pdo,$user,250),static fn(array $row):bool=>!empty($row['watch_enabled'])));
        if(!$rows)return ['handled'=>true,'answer'=>'Your Agent CRM watchlist is empty. You can say “watch ChatGPT-User” or use Manage contact in My Contacts.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-crm-watchlist','title'=>'Agent CRM Watchlist']]];
        $lines=['Watched Agent CRM contacts:'];
        foreach(array_slice($rows,0,30) as $row)$lines[]='• '.((string)$row['operator_name']!==''?(string)$row['operator_name'].' · ':'').(string)$row['display_name'].' — risk '.(int)$row['risk_score'].'/100, opportunity '.(int)$row['opportunity_score'].'/100, last seen '.(string)$row['last_seen_at'].'.';
        return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-crm-watchlist','title'=>'Agent CRM Watchlist']]];
    }

    $enabled=true;$subject='';
    if(preg_match('/\b(?:stop\s+watching|unwatch)(?:\s+agent)?\s+(.+?)[.!?]*$/i',$q,$m)){$enabled=false;$subject=trim((string)$m[1]);}
    elseif(preg_match('/\bwatch(?:\s+agent)?\s+(.+?)[.!?]*$/i',$q,$m)){$enabled=true;$subject=trim((string)$m[1]);}
    else return $empty;
    if(in_array(mb_strtolower($subject),['this agent','this contact'],true))return ['handled'=>true,'answer'=>'Use the agent’s exact name, such as ChatGPT-User, so I change the correct CRM contact.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-crm-watchlist','title'=>'Agent CRM Watchlist']]];
    $contact=function_exists('vp3_radar_chat_find_contact')?vp3_radar_chat_find_contact($pdo,(int)$user['id'],$subject):null;
    if(!$contact)return ['handled'=>true,'answer'=>'I could not match that name to one Agent CRM contact. Use the exact agent name shown in My Contacts or Agent Radar.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-crm-watchlist','title'=>'Agent CRM Watchlist']]];
    $result=vp3_agent_crm_set_watch($pdo,$user,(int)$contact['id'],$enabled);
    $name=((string)$contact['operator_name']!==''?(string)$contact['operator_name'].' · ':'').(string)$contact['display_name'];
    $answer=$enabled?$name.' is now on your Agent CRM watchlist. I’ll surface its next new session in your notification/Main Feed flow unless a higher-priority security alert applies.':$name.' has been removed from your Agent CRM watchlist.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_crm.watch',$query,'success',['contact_id'=>(int)$contact['id'],'watch_enabled'=>$enabled],$conversationId);
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-crm:contact:'.(int)$contact['id'],'title'=>$name]]];
}
