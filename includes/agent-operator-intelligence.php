<?php
declare(strict_types=1);

function vp3_agent_operator_reputation(float $trust,float $value,float $cost,int $risk,int $referrals,int $conversions): int
{
    $outcome=min(20,($conversions*6)+min(8,$referrals));
    return max(0,min(100,(int)round(35+($trust*.30)+($value*.20)+$outcome-($risk*.42)-($cost*.08))));
}

function vp3_agent_operator_reputation_label(int $score,int $risk): string
{
    if($risk>=70)return 'high risk';
    if($score>=75)return 'strong';
    if($score>=60)return 'positive';
    if($score>=40)return 'neutral';
    return 'caution';
}

function vp3_agent_operator_summaries(PDO $pdo,array $user,int $limit=50): array
{
    $owner=(int)($user['id']??0);if($owner<1||!vp3_radar_schema_ready($pdo))return [];$limit=max(1,min(100,$limit));
    if(function_exists('vp3_agent_relationship_refresh_owner')){
        try{vp3_agent_relationship_refresh_owner($pdo,$user,250,false);}catch(Throwable $e){error_log('Agent operator relationship refresh failed: '.$e->getMessage());}
    }
    $stmt=$pdo->prepare("SELECT operator_name,COUNT(*) agent_count,
        COALESCE(SUM(session_count),0) session_count,COALESCE(SUM(request_count),0) request_count,COALESCE(SUM(page_view_count),0) page_view_count,
        COALESCE(SUM(referral_count),0) referral_count,COALESCE(SUM(conversion_count),0) conversion_count,
        COALESCE(AVG(trust_score),0) avg_trust,COALESCE(AVG(value_score),0) avg_value,COALESCE(AVG(cost_score),0) avg_cost,COALESCE(AVG(engagement_score),0) avg_engagement,
        COALESCE(MAX(risk_score),0) max_risk,MAX(last_seen_at) last_seen_at
      FROM vp3_agent_contacts WHERE owner_user_id=? AND operator_name<>''
      GROUP BY operator_name ORDER BY conversion_count DESC,referral_count DESC,avg_value DESC,last_seen_at DESC LIMIT {$limit}");
    $stmt->execute([$owner]);$rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $reputation=vp3_agent_operator_reputation((float)$row['avg_trust'],(float)$row['avg_value'],(float)$row['avg_cost'],(int)$row['max_risk'],(int)$row['referral_count'],(int)$row['conversion_count']);
        $row['private_reputation_score']=$reputation;$row['private_reputation_label']=vp3_agent_operator_reputation_label($reputation,(int)$row['max_risk']);
    }unset($row);
    return $rows;
}

function vp3_agent_operator_find(array $summaries,string $subject): ?array
{
    $needle=mb_strtolower(trim($subject));if($needle==='')return null;
    $best=null;$score=0;
    foreach($summaries as $row){
        $name=mb_strtolower(trim((string)$row['operator_name']));$current=0;
        if($name===$needle)$current=100;
        elseif(str_contains($name,$needle)||str_contains($needle,$name))$current=70;
        if($current>$score){$score=$current;$best=$row;}
    }
    return $score>=70?$best:null;
}

function vp3_agent_operator_contacts(PDO $pdo,int $ownerUserId,string $operator): array
{
    if($ownerUserId<1||trim($operator)==='')return [];
    $stmt=$pdo->prepare("SELECT c.*,r.slug AS registry_slug,r.purpose AS registry_purpose FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.owner_user_id=? AND LOWER(c.operator_name)=LOWER(?) ORDER BY c.last_seen_at DESC,c.id DESC LIMIT 100");
    $stmt->execute([$ownerUserId,$operator]);return $stmt->fetchAll()?:[];
}

function vp3_agent_operator_policy(PDO $pdo,int $ownerUserId,string $operator): ?array
{
    foreach(vp3_radar_gateway_scoped_rules($pdo,$ownerUserId) as $rule){
        if((string)($rule['scope_type']??'')==='operator'&&strcasecmp(trim((string)($rule['scope_value']??'')),trim($operator))===0)return $rule;
    }
    return null;
}

function vp3_agent_operator_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    foreach(['agent operators','operator summary','operator summaries','agent organizations','ai organizations','ai companies','which operators','operator activity','operator reputation','private reputation','reputation of','what has'] as $needle)if(str_contains($q,$needle))return true;
    if(preg_match('/\b(?:what has|show|review)\s+.+?\s+(?:been doing|agents?|activity|reputation)\b/i',$q))return true;
    return false;
}

function vp3_agent_operator_chat_subject(string $query): string
{
    $patterns=[
        '/\bwhat\s+has\s+(.+?)\s+been\s+doing[?.!]*$/i',
        '/\bshow\s+(.+?)\s+agents?[?.!]*$/i',
        '/\b(?:show|review)\s+(.+?)\s+(?:operator\s+)?activity[?.!]*$/i',
        '/\b(?:operator\s+reputation|reputation)\s+(?:for|of)\s+(.+?)[?.!]*$/i',
    ];
    foreach($patterns as $pattern)if(preg_match($pattern,trim($query),$m))return trim((string)$m[1]);
    return '';
}

function vp3_agent_operator_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_agent_operator_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;$uid=(int)$user['id'];
    $summaries=vp3_agent_operator_summaries($pdo,$user,50);
    if(!$summaries)return ['handled'=>true,'answer'=>'Agent Radar has not identified any operator organizations yet.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-operators','title'=>'Agent Operator Intelligence']]];
    $subject=vp3_agent_operator_chat_subject($query);
    if($subject===''){
        $lines=['Agent operator relationships:'];
        foreach(array_slice($summaries,0,20) as $row)$lines[]='• '.(string)$row['operator_name'].' — '.(int)$row['agent_count'].' agents · '.(int)$row['session_count'].' sessions · '.(int)$row['referral_count'].' referrals · '.(int)$row['conversion_count'].' conversions · max risk '.(int)$row['max_risk'].' · private reputation '.(int)$row['private_reputation_score'].'/100 ('.(string)$row['private_reputation_label'].').';
        $lines[]='Reputation is private to your VP3 account and is derived only from your own observed relationships; it is not a global blacklist or shared reputation score.';
        return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-operators','title'=>'Agent Operator Intelligence']]];
    }
    $operator=vp3_agent_operator_find($summaries,$subject);
    if(!$operator)return ['handled'=>true,'answer'=>'I could not match that operator to your Agent Radar contacts. Ask for “agent operators” to see the operators currently known to your account.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-operators','title'=>'Agent Operator Intelligence']]];
    $name=(string)$operator['operator_name'];$contacts=vp3_agent_operator_contacts($pdo,$uid,$name);$policy=vp3_agent_operator_policy($pdo,$uid,$name);
    $lines=[
        $name.' — operator relationship summary',
        '• '.(int)$operator['agent_count'].' Agent CRM contacts · '.(int)$operator['session_count'].' sessions · '.(int)$operator['page_view_count'].' views · '.(int)$operator['request_count'].' requests.',
        '• '.(int)$operator['referral_count'].' explicitly attributed human referrals · '.(int)$operator['conversion_count'].' conversions.',
        '• Average trust '.(int)round((float)$operator['avg_trust']).'/100 · value '.(int)round((float)$operator['avg_value']).'/100 · cost '.(int)round((float)$operator['avg_cost']).'/100 · max risk '.(int)$operator['max_risk'].'/100.',
        '• Owner-private reputation '.(int)$operator['private_reputation_score'].'/100 · '.(string)$operator['private_reputation_label'].'.',
    ];
    if($policy)$lines[]='• Operator-wide Gateway rule: '.(string)$policy['action'].'. Contact-specific overrides still take precedence.';
    $lines[]='Agents:';
    foreach(array_slice($contacts,0,20) as $contact)$lines[]='  • '.(string)$contact['display_name'].' — '.str_replace('_',' ',(string)$contact['visitor_class']).' · trust '.(int)$contact['trust_score'].' · risk '.(int)$contact['risk_score'].' · value '.(int)$contact['value_score'].' · '.(int)$contact['conversion_count'].' conversions.';
    $lines[]='This reputation is private to your account and is not shared across VP3 customers.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_operator.summary',$query,'success',['operator'=>$name,'agents'=>count($contacts)],$conversationId);
    return ['handled'=>true,'answer'=>implode("\n",$lines),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-operator:'.mb_strtolower($name),'title'=>$name.' Agent Operator']]];
}
