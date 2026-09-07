<?php
declare(strict_types=1);

function vp3_agent_relationship_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach([
        'agent opportunities','agent opportunity','most valuable agent','most valuable agents','highest value agent','agent value',
        'most expensive agent','most expensive agents','highest cost agent','agent cost','costliest agent','costliest agents',
        'which agents should i allow','which agent should i allow','which agents are worth','relationship score','relationship scores',
        'what is chatgpt-user interested in','what is claude-user interested in','what is perplexity-user interested in','inferred intent',
        'agent relationship','agent relationships','why is this agent valuable','why is this agent an opportunity',
        'agent referrals','ai referrals','agent conversions','ai conversions','generated any customers','generated customers','generated any leads','generated leads','ai-attributed','attributed conversion','attributed conversions'
    ] as $needle)if(str_contains($q,$needle))return true;
    return false;
}

function vp3_agent_relationship_chat_rows(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1)return [];
    if(function_exists('vp3_agent_relationship_refresh_owner'))vp3_agent_relationship_refresh_owner($pdo,$user,120,false);
    $stmt=$pdo->prepare("SELECT c.*,r.slug AS registry_slug,r.purpose AS registry_purpose FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.owner_user_id=? ORDER BY c.last_seen_at DESC,c.id DESC LIMIT 120");
    $stmt->execute([$uid]);$rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $intel=is_array($meta['relationship_intelligence']??null)?$meta['relationship_intelligence']:[];
        $row['opportunity_score']=(int)($intel['opportunity_score']??0);
        $row['recommendation']=(string)($intel['recommendation']??'');
        $row['relationship_intelligence']=$intel;
    }unset($row);
    return $rows;
}

function vp3_agent_relationship_chat_name(array $row): string
{
    return (trim((string)($row['operator_name']??''))!==''?trim((string)$row['operator_name']).' · ':'').(trim((string)($row['display_name']??''))?:'Automated agent');
}

function vp3_agent_relationship_chat_detail(array $row): string
{
    $intel=is_array($row['relationship_intelligence']??null)?$row['relationship_intelligence']:[];
    $name=vp3_agent_relationship_chat_name($row);$intent=trim((string)($row['inferred_intent']??''));
    $lines=[
        $name.' — Agent Relationship intelligence',
        '• Relationship: '.str_replace('_',' ',(string)($row['relationship_status']??'new')).'.',
        '• Value '.(int)($row['value_score']??0).'/100 · Cost '.(int)($row['cost_score']??0).'/100 · Engagement '.(int)($row['engagement_score']??0).'/100 · Opportunity '.(int)($row['opportunity_score']??0).'/100.',
        '• Trust '.(int)($row['trust_score']??0).'/100 · Risk '.(int)($row['risk_score']??0).'/100 · verification '.str_replace('_',' ',(string)($row['verification_status']??'unknown')).'.',
    ];
    if($intent!=='')$lines[]='• Inferred intent: '.str_replace('_',' ',$intent).' at '.(int)($row['intent_confidence']??0).'% confidence.';
    $lines[]='• Last 30 days: '.(int)($intel['sessions_30d']??0).' sessions, '.(int)($intel['views_30d']??0).' views, '.(int)($intel['requests_30d']??0).' requests across '.(int)($intel['properties_30d']??0).' properties.';
    if(!empty($intel['attribution_ready']))$lines[]='• First-party attribution: '.(int)($intel['referrals_total']??$row['referral_count']??0).' human referrals, '.(int)($intel['conversions_total']??$row['conversion_count']??0).' conversions; last 30 days '.(int)($intel['referrals_30d']??0).' referrals and '.(int)($intel['conversions_30d']??0).' conversions.';
    if((int)($intel['message_inbound']??0)>0||(int)($intel['message_outbound']??0)>0)$lines[]='• Agent Messaging: '.(int)($intel['message_inbound']??0).' inbound, '.(int)($intel['message_outbound']??0).' outbound, '.(int)($intel['messaging_tokens']??0).' AI tokens used.';
    $recommendation=trim((string)($row['recommendation']??''));if($recommendation!=='')$lines[]='• Recommended next step: '.$recommendation;
    return implode("\n",$lines);
}

function vp3_agent_relationship_chat_subject(string $query): string
{
    $q=trim($query);
    $patterns=[
        '/\b(?:what is|what\'s)\s+(.+?)\s+(?:interested in|looking for)[?.!]*$/i',
        '/\b(?:show|explain|review)\s+(?:the\s+)?(?:relationship|relationship score|opportunity|value|cost|referrals?|conversions?)\s+(?:for|of)\s+(.+?)[?.!]*$/i',
        '/\bwhy\s+is\s+(.+?)\s+(?:valuable|an opportunity|expensive|high cost)[?.!]*$/i',
    ];
    foreach($patterns as $pattern)if(preg_match($pattern,$q,$m))return trim((string)$m[1]);
    foreach(['ChatGPT-User','Claude-User','Perplexity-User','GPTBot','ClaudeBot','PerplexityBot','OAI-SearchBot','Claude-SearchBot'] as $known)if(stripos($q,$known)!==false)return $known;
    return '';
}

function vp3_agent_relationship_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_agent_relationship_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $rows=vp3_agent_relationship_chat_rows($pdo,$user);
    if(!$rows)return ['handled'=>true,'answer'=>'Agent Radar has not recorded enough automated relationship activity to score yet.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-relationship','title'=>'Agent Relationship Intelligence']]];

    $subject=vp3_agent_relationship_chat_subject($query);
    if($subject!==''){
        $contact=function_exists('vp3_radar_chat_find_contact')?vp3_radar_chat_find_contact($pdo,(int)$user['id'],$subject):null;
        if(!$contact)return ['handled'=>true,'answer'=>'I could not match that name to one Agent Radar contact. Use the exact agent name shown in Radar.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-relationship','title'=>'Agent Relationship Intelligence']]];
        $match=null;foreach($rows as $row)if((int)$row['id']===(int)$contact['id']){$match=$row;break;}
        if(!$match)$match=$contact;
        $answer=vp3_agent_relationship_chat_detail($match);
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_relationship.detail',$query,'success',['contact_id'=>(int)$match['id']],$conversationId);
        return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-relationship:contact:'.(int)$match['id'],'title'=>vp3_agent_relationship_chat_name($match)]]];
    }

    $q=mb_strtolower($query);$mode='opportunity';
    if(str_contains($q,'referral')||str_contains($q,'conversion')||str_contains($q,'generated any customer')||str_contains($q,'generated customer')||str_contains($q,'generated any lead')||str_contains($q,'generated lead')||str_contains($q,'ai-attributed'))$mode='outcomes';
    elseif(str_contains($q,'expensive')||str_contains($q,'costliest')||str_contains($q,'agent cost'))$mode='cost';
    elseif(str_contains($q,'valuable')||str_contains($q,'highest value')||str_contains($q,'agent value'))$mode='value';
    elseif(str_contains($q,'should i allow')||str_contains($q,'worth allowing'))$mode='allow';
    $filtered=$rows;
    if($mode==='allow')$filtered=array_values(array_filter($rows,static fn(array $r):bool=>(string)($r['visitor_class']??'')==='ai_user_agent'&&(int)($r['risk_score']??0)<40&&(int)($r['trust_score']??0)>=40&&(int)($r['opportunity_score']??0)>=60));
    if($mode==='outcomes')$filtered=array_values(array_filter($rows,static fn(array $r):bool=>(int)($r['referral_count']??0)>0||(int)($r['conversion_count']??0)>0));
    usort($filtered,match($mode){
        'cost'=>static fn(array $a,array $b):int=>(int)$b['cost_score']<=>(int)$a['cost_score'],
        'value'=>static fn(array $a,array $b):int=>(int)$b['value_score']<=>(int)$a['value_score'],
        'outcomes'=>static fn(array $a,array $b):int=>((int)$b['conversion_count']<=> (int)$a['conversion_count'])?:((int)$b['referral_count']<=> (int)$a['referral_count']),
        default=>static fn(array $a,array $b):int=>(int)$b['opportunity_score']<=>(int)$a['opportunity_score'],
    });
    $top=array_slice($filtered,0,8);
    if(!$top){
        $answer=match($mode){
            'allow'=>'I do not have a user-directed Agent Radar contact that currently clears the advisory allow threshold. Keep existing Gateway defaults in place until the relationship has more evidence.',
            'outcomes'=>(function_exists('vp3_agent_referral_schema_ready')&&vp3_agent_referral_schema_ready($pdo))?'No AI-agent contact has an explicitly attributed human referral or conversion yet. Referral attribution is active, so future first-party referral links and tracked conversion events will appear here.':'AI referral attribution is not installed yet. Run the VP3 database upgrade before referral/conversion outcomes can be measured.',
            default=>'No scored Agent Radar contacts match that relationship view yet.',
        };
        return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-relationship','title'=>'Agent Relationship Intelligence']]];
    }
    $heading=match($mode){'cost'=>'Highest-cost Agent Radar relationships:','value'=>'Highest-value Agent Radar relationships:','allow'=>'Best current candidates for review before allowing Agent Messaging:','outcomes'=>'AI-attributed human outcomes: ',default=>'Top Agent Radar opportunities:'};
    $lines=[$heading];
    foreach($top as $row){
        $line='• '.vp3_agent_relationship_chat_name($row).' — value '.(int)$row['value_score'].', cost '.(int)$row['cost_score'].', opportunity '.(int)$row['opportunity_score'].', risk '.(int)$row['risk_score'];
        if($mode==='outcomes')$line.=' · '.(int)$row['referral_count'].' referrals · '.(int)$row['conversion_count'].' conversions';
        elseif(trim((string)$row['inferred_intent'])!=='')$line.=' · '.str_replace('_',' ',(string)$row['inferred_intent']);
        $lines[]=$line.'.';
    }
    if($mode==='allow')$lines[]='This is advisory only. I will not change Agent Gateway or messaging permission unless you explicitly tell me which agent to allow.';
    if($mode==='outcomes')$lines[]='These counts come only from VP3 first-party referral tokens and deduped conversion events; ordinary HTTP referrers are not counted as AI-generated customers.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_relationship.rank',$query,'success',['mode'=>$mode,'count'=>count($top)],$conversationId);
    return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-relationship','title'=>'Agent Relationship Intelligence']]];
}
