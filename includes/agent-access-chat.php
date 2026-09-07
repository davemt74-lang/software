<?php
declare(strict_types=1);

function vp3_agent_access_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['agent messaging request','agent messaging requests','messaging access request','allow once','always allow','deny agent messaging','revoke agent messaging','allow agent messaging'] as $needle){
        if(str_contains($q,$needle))return true;
    }
    return false;
}

function vp3_agent_access_chat_request_for_contact(PDO $pdo,int $ownerUserId,int $contactId): ?array
{
    if($ownerUserId<1||$contactId<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM vp3_agent_access_requests WHERE owner_user_id=? AND agent_contact_id=? AND capability='agent.message' AND status IN ('pending','approved_once','approved') ORDER BY (status='pending') DESC,updated_at DESC,id DESC LIMIT 1");
    $stmt->execute([$ownerUserId,$contactId]);
    return $stmt->fetch()?:null;
}

function vp3_agent_access_chat_list(PDO $pdo,array $user): array
{
    $rows=vp3_agent_access_owner_list($pdo,(int)$user['id'],20);
    if(!$rows)return ['handled'=>true,'answer'=>'There are no pending or active Agent Messaging access requests.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-messaging','title'=>'Agent Messaging Access']]];
    $lines=['Agent Messaging access:'];
    foreach($rows as $row){
        $name=((string)$row['operator_name']!==''?(string)$row['operator_name'].' · ':'').(string)$row['display_name'];
        $line='• '.$name.' — '.str_replace('_',' ',(string)$row['status']).', risk '.(int)$row['risk_score'].'/100, trust '.(int)$row['trust_score'].'/100';
        if(trim((string)$row['purpose'])!=='')$line.='. Purpose: '.mb_strimwidth(trim((string)$row['purpose']),0,180,'…');
        $lines[]=$line.'.';
    }
    return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-messaging','title'=>'Agent Messaging Access']]];
}

function vp3_agent_access_chat_decision(PDO $pdo,array $user,string $query): ?array
{
    $q=trim($query);$decision='';$subject='';
    if(preg_match('/\b(?:allow|approve)\s+(.+?)\s+(?:to\s+message\s+)?once[.!?]*$/i',$q,$m)){$decision='allow_once';$subject=(string)$m[1];}
    elseif(preg_match('/\b(?:always\s+allow|approve)\s+(.+?)(?:\s+to\s+message(?:\s+me|\s+my\s+agent)?)?[.!?]*$/i',$q,$m)){$decision='allow';$subject=(string)$m[1];}
    elseif(preg_match('/\b(?:deny|revoke)\s+(.+?)(?:\s+agent\s+messaging|\s+messaging\s+access|\s+from\s+messaging)?[.!?]*$/i',$q,$m)){$decision='deny';$subject=(string)$m[1];}
    else return null;

    $contact=function_exists('vp3_radar_chat_find_contact')?vp3_radar_chat_find_contact($pdo,(int)$user['id'],$subject):null;
    if(!$contact)return ['handled'=>true,'answer'=>'I could not match that name to one Agent Radar contact. Use the exact agent name shown in Agent Radar.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-messaging','title'=>'Agent Messaging Access']]];
    $request=vp3_agent_access_chat_request_for_contact($pdo,(int)$user['id'],(int)$contact['id']);
    if(!$request)return ['handled'=>true,'answer'=>'That Agent Radar contact has no pending or active Agent Messaging request to change.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-messaging','title'=>'Agent Messaging Access']]];
    vp3_agent_access_owner_decide($pdo,$user,(int)$request['id'],$decision);
    $name=((string)$contact['operator_name']!==''?(string)$contact['operator_name'].' · ':'').(string)$contact['display_name'];
    $answer=match($decision){
        'allow_once'=>$name.' can message your Profile Agent once during the next 30 minutes. The approved AI reply will use your existing VP3 AI token balance.',
        'allow'=>$name.' is now approved for Agent Messaging until you revoke access. AI replies use your existing VP3 AI token balance and remain subject to rate limits and Agent Gateway policy.',
        default=>$name.' no longer has Agent Messaging access.',
    };
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-messaging:contact:'.(int)$contact['id'],'title'=>$name]]];
}

function vp3_agent_access_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    if(!vp3_agent_access_chat_intent($query)){
        return function_exists('vp3_agent_relationship_chat_tool')
            ? vp3_agent_relationship_chat_tool($query,$user,$conversationId)
            : $empty;
    }
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $action=vp3_agent_access_chat_decision($pdo,$user,$query);
    $result=$action?:vp3_agent_access_chat_list($pdo,$user);
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent.messaging.access',$query,'success',['handled'=>$action?'decision':'list'],$conversationId);
    return $result;
}
