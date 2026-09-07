<?php
declare(strict_types=1);

const VP3_AGENT_RELATIONSHIP_WINDOW_DAYS = 30;
const VP3_AGENT_OPPORTUNITY_THRESHOLD = 80;
const VP3_AGENT_OPPORTUNITY_COOLDOWN_DAYS = 7;

function vp3_agent_relationship_clamp(int|float $value): int
{
    return max(0,min(100,(int)round($value)));
}

function vp3_agent_relationship_path_signal(string $path): array
{
    $path=mb_strtolower(trim($path));
    if($path==='')return ['intent'=>'','weight'=>0,'commercial'=>false,'structured'=>false];
    if(preg_match('#(?:/pricing|/plans|/subscribe|/signup|/contact|/book|/demo|/buy|/checkout|/purchase|/order|/cart)#i',$path))return ['intent'=>'commercial_interest','weight'=>18,'commercial'=>true,'structured'=>false];
    if(preg_match('#(?:/products?|/services?|/merch|/events?|/shows?|/music|/portfolio|/offers?)#i',$path))return ['intent'=>'content_discovery','weight'=>10,'commercial'=>false,'structured'=>false];
    if(str_contains($path,'/.well-known/vp3-agent')||str_contains($path,'/api/agent-manifest.php')||str_contains($path,'/api/agent-content.php'))return ['intent'=>'structured_research','weight'=>12,'commercial'=>false,'structured'=>true];
    if(preg_match('#(?:/admin|/private|/config|/\.env)#i',$path))return ['intent'=>'restricted_probe','weight'=>0,'commercial'=>false,'structured'=>false];
    return ['intent'=>'general_research','weight'=>4,'commercial'=>false,'structured'=>false];
}

function vp3_agent_relationship_recommendation(array $contact,array $metrics): string
{
    $risk=(int)($contact['risk_score']??0);$opportunity=(int)($metrics['opportunity_score']??0);$intent=(string)($metrics['intent']??'');
    if($risk>=70)return 'Review this contact before granting broader access. Keep private capabilities blocked and consider a stricter Gateway rule.';
    if($opportunity>=80&&$intent==='commercial_interest')return 'High-value commercial interest detected. Keep structured offers, pricing and contact actions current and consider a trusted Agent Messaging relationship.';
    if($opportunity>=80&&$intent==='structured_research')return 'High-value structured research detected. Keep the Agent Manifest and public structured content current and review whether trusted messaging would help.';
    if((int)($metrics['message_inbound']??0)>0)return 'This agent has entered an approved conversation. Review the CRM timeline, token cost and relationship value before expanding permissions.';
    if((string)($contact['visitor_class']??'')==='ai_search')return 'Maintain accurate structured public content and monitor whether this agent begins generating repeat discovery or human referrals.';
    if((string)($contact['visitor_class']??'')==='ai_crawler')return (int)($metrics['cost_score']??0)>=60?'Crawler cost is elevated. Consider limiting request volume unless it is producing measurable value.':'Routine crawler activity. Continue monitoring unless request cost rises or value becomes measurable.';
    return 'Continue monitoring this agent relationship. Promote it only when repeat engagement, useful intent or measurable outcomes justify broader access.';
}

function vp3_agent_relationship_calculate(array $contact,array $sessionMetrics,array $eventRows,bool $pendingMessaging=false): array
{
    $class=(string)($contact['visitor_class']??'unknown');
    $baseValue=match($class){'ai_user_agent'=>55,'ai_search'=>42,'ai_crawler'=>20,'automated_unknown'=>10,default=>15};
    $sessions30=(int)($sessionMetrics['sessions_30d']??0);$views30=(int)($sessionMetrics['views_30d']??0);$requests30=(int)($sessionMetrics['requests_30d']??0);$properties30=(int)($sessionMetrics['properties_30d']??0);
    $messageInbound=0;$messageOutbound=0;$accessRequests=0;$commercialHits=0;$structuredHits=0;$tokenUsage=0;$bestIntent='';$bestIntentWeight=0;$lastPropertyId=0;
    foreach($eventRows as $row){
        $type=(string)($row['event_type']??'');$lastPropertyId=$lastPropertyId?:max(0,(int)($row['property_id']??0));
        if($type==='agent_message_inbound')$messageInbound++;
        elseif($type==='agent_message_outbound'){
            $messageOutbound++;
            $details=json_decode((string)($row['details_json']??''),true);if(is_array($details))$tokenUsage+=max(0,(int)($details['usage']['total_tokens']??0));
        }elseif($type==='agent_access_requested')$accessRequests++;
        $signal=vp3_agent_relationship_path_signal((string)($row['path']??''));
        if(!empty($signal['commercial']))$commercialHits++;
        if(!empty($signal['structured']))$structuredHits++;
        if((int)$signal['weight']>$bestIntentWeight){$bestIntent=(string)$signal['intent'];$bestIntentWeight=(int)$signal['weight'];}
    }
    if((int)($contact['risk_score']??0)>=70){$bestIntent='restricted_probe';$bestIntentWeight=100;}
    elseif($commercialHits>0){$bestIntent='commercial_interest';$bestIntentWeight=max($bestIntentWeight,70+min(25,$commercialHits*5));}
    elseif($messageInbound>0){$bestIntent='conversation';$bestIntentWeight=max($bestIntentWeight,70+min(20,$messageInbound*5));}
    elseif($structuredHits>0){$bestIntent='structured_research';$bestIntentWeight=max($bestIntentWeight,65+min(25,$structuredHits*5));}
    elseif($bestIntent===''){$bestIntent='general_research';$bestIntentWeight=$sessions30>1?60:45;}

    $engagement=vp3_agent_relationship_clamp(
        min(32,$sessions30*8)+min(24,$views30*2)+min(24,$messageInbound*10)+min(12,$commercialHits*4)+min(8,max(0,$properties30-1)*4)
    );
    $value=vp3_agent_relationship_clamp(
        $baseValue+min(18,$sessions30*4)+min(12,$views30)+min(20,$messageInbound*5)+min(18,$commercialHits*4)+min(8,$structuredHits*2)+min(30,(int)($contact['conversion_count']??0)*15)+($pendingMessaging?5:0)
    );
    $cost=vp3_agent_relationship_clamp(min(40,$requests30/3)+min(45,$tokenUsage/400)+min(15,$messageOutbound*3));
    $risk=(int)($contact['risk_score']??0);$trust=(int)($contact['trust_score']??0);
    $opportunity=vp3_agent_relationship_clamp(($value*.55)+($engagement*.35)+($trust*.20)-($risk*.60)-($cost*.15));
    $status='new';
    if($risk>=70)$status='restricted';
    elseif((int)($contact['conversion_count']??0)>0)$status='converted';
    elseif($messageInbound>=2||$commercialHits>=2||$sessions30>=3)$status='engaged';
    elseif((int)($contact['session_count']??0)>=2)$status='returning';
    elseif((string)($contact['verification_status']??'')==='known')$status='observed';
    $metrics=[
        'window_days'=>VP3_AGENT_RELATIONSHIP_WINDOW_DAYS,'sessions_30d'=>$sessions30,'views_30d'=>$views30,'requests_30d'=>$requests30,'properties_30d'=>$properties30,
        'message_inbound'=>$messageInbound,'message_outbound'=>$messageOutbound,'messaging_tokens'=>$tokenUsage,'access_requests'=>$accessRequests,
        'commercial_hits'=>$commercialHits,'structured_hits'=>$structuredHits,'intent'=>$bestIntent,'intent_confidence'=>vp3_agent_relationship_clamp($bestIntentWeight),
        'engagement_score'=>$engagement,'value_score'=>$value,'cost_score'=>$cost,'opportunity_score'=>$opportunity,'relationship_status'=>$status,'pending_messaging'=>$pendingMessaging,
        'last_property_id'=>$lastPropertyId,
    ];
    $metrics['recommendation']=vp3_agent_relationship_recommendation($contact,$metrics);
    return $metrics;
}

function vp3_agent_relationship_store(PDO $pdo,array $contact,array $metrics): array
{
    $metadata=json_decode((string)($contact['metadata_json']??''),true);if(!is_array($metadata))$metadata=[];
    $metadata['relationship_intelligence']=[
        'window_days'=>(int)$metrics['window_days'],'opportunity_score'=>(int)$metrics['opportunity_score'],'recommendation'=>(string)$metrics['recommendation'],
        'sessions_30d'=>(int)$metrics['sessions_30d'],'views_30d'=>(int)$metrics['views_30d'],'requests_30d'=>(int)$metrics['requests_30d'],'properties_30d'=>(int)$metrics['properties_30d'],
        'message_inbound'=>(int)$metrics['message_inbound'],'message_outbound'=>(int)$metrics['message_outbound'],'messaging_tokens'=>(int)$metrics['messaging_tokens'],
        'commercial_hits'=>(int)$metrics['commercial_hits'],'structured_hits'=>(int)$metrics['structured_hits'],'updated_at'=>gmdate('c'),
    ];
    $encoded=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE vp3_agent_contacts SET engagement_score=?,value_score=?,cost_score=?,relationship_status=?,inferred_intent=?,intent_confidence=?,metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([
        (int)$metrics['engagement_score'],(int)$metrics['value_score'],(int)$metrics['cost_score'],mb_strimwidth((string)$metrics['relationship_status'],0,30,''),
        mb_strimwidth((string)$metrics['intent'],0,120,''),(int)$metrics['intent_confidence'],$encoded,(int)$contact['id'],(int)$contact['owner_user_id'],
    ]);
    return $metadata;
}

function vp3_agent_relationship_opportunity_notify(PDO $pdo,array $user,array $contact,array $metrics): void
{
    if((int)$metrics['opportunity_score']<VP3_AGENT_OPPORTUNITY_THRESHOLD||(int)$contact['risk_score']>=40)return;
    $recent=$pdo->prepare("SELECT id FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND event_type='agent_opportunity_detected' AND occurred_at>=DATE_SUB(NOW(),INTERVAL ".VP3_AGENT_OPPORTUNITY_COOLDOWN_DAYS." DAY) LIMIT 1");
    $recent->execute([(int)$user['id'],(int)$contact['id']]);if($recent->fetchColumn())return;
    $propertyId=max(0,(int)($metrics['last_property_id']??0));
    if($propertyId<1){$p=$pdo->prepare('SELECT id FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY property_type=\'native\' DESC,id LIMIT 1');$p->execute([(int)$user['id']]);$propertyId=(int)($p->fetchColumn()?:0);}
    if($propertyId<1)return;
    $name=(trim((string)$contact['operator_name'])!==''?trim((string)$contact['operator_name']).' · ':'').trim((string)$contact['display_name']);
    $summary=$name.' reached an Agent Relationship opportunity score of '.(int)$metrics['opportunity_score'].'/100. '.(string)$metrics['recommendation'];
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at) VALUES (?,?,NULL,?,'agent_opportunity_detected','low','/profile-agent.php','SYSTEM',NULL,?,?,?, ?,NOW())");
    $stmt->execute([(int)$user['id'],$propertyId,(int)$contact['id'],(int)$metrics['opportunity_score'],(int)$contact['risk_score'],mb_strimwidth($summary,0,500,'…'),json_encode(['opportunity_score'=>(int)$metrics['opportunity_score'],'intent'=>(string)$metrics['intent'],'recommendation'=>(string)$metrics['recommendation']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $eventId=(int)$pdo->lastInsertId();
    create_notification((int)$user['id'],'radar_agent_opportunity','Agent opportunity · '.trim((string)$contact['display_name']),$summary,url('/profile-agent.php?tab=radar'),'radar_event',$eventId);
    if(function_exists('agent_brain_v122_upsert_system_memory')){
        agent_brain_v122_upsert_system_memory($user,'agent_radar','agent-radar-opportunity:'.(int)$contact['id'],$summary,[
            'agent_contact_id'=>(int)$contact['id'],'opportunity_score'=>(int)$metrics['opportunity_score'],'intent'=>(string)$metrics['intent'],'recommendation'=>(string)$metrics['recommendation'],'source'=>'agent_radar_opportunity',
        ],0.86);
    }
}

function vp3_agent_relationship_refresh_owner(PDO $pdo,array $user,int $limit=120,bool $notify=true): array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_radar_schema_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $contactsStmt=$pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE owner_user_id=? ORDER BY last_seen_at DESC,id DESC LIMIT '.$limit);$contactsStmt->execute([$uid]);$contacts=$contactsStmt->fetchAll()?:[];
    if(!$contacts)return [];$ids=array_map(static fn(array $row):int=>(int)$row['id'],$contacts);$placeholders=implode(',',array_fill(0,count($ids),'?'));
    $sessionSql="SELECT agent_contact_id,COUNT(*) sessions_30d,COALESCE(SUM(page_view_count),0) views_30d,COALESCE(SUM(request_count),0) requests_30d,COUNT(DISTINCT property_id) properties_30d FROM vp3_radar_sessions WHERE owner_user_id=? AND agent_contact_id IN ({$placeholders}) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL ".VP3_AGENT_RELATIONSHIP_WINDOW_DAYS." DAY) GROUP BY agent_contact_id";
    $s=$pdo->prepare($sessionSql);$s->execute(array_merge([$uid],$ids));$sessionMap=[];foreach($s->fetchAll()?:[] as $row)$sessionMap[(int)$row['agent_contact_id']]=$row;
    $eventSql="SELECT id,agent_contact_id,property_id,event_type,path,details_json,occurred_at FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id IN ({$placeholders}) AND occurred_at>=DATE_SUB(NOW(),INTERVAL ".VP3_AGENT_RELATIONSHIP_WINDOW_DAYS." DAY) ORDER BY occurred_at DESC,id DESC LIMIT 5000";
    $e=$pdo->prepare($eventSql);$e->execute(array_merge([$uid],$ids));$eventMap=[];foreach($e->fetchAll()?:[] as $row)$eventMap[(int)$row['agent_contact_id']][]=$row;
    $pending=$pdo->prepare("SELECT DISTINCT agent_contact_id FROM vp3_agent_access_requests WHERE owner_user_id=? AND status='pending'");$pending->execute([$uid]);$pendingMap=array_fill_keys(array_map('intval',$pending->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
    $out=[];
    foreach($contacts as $contact){$id=(int)$contact['id'];$metrics=vp3_agent_relationship_calculate($contact,$sessionMap[$id]??[],$eventMap[$id]??[],isset($pendingMap[$id]));vp3_agent_relationship_store($pdo,$contact,$metrics);if($notify)vp3_agent_relationship_opportunity_notify($pdo,$user,$contact,$metrics);$out[$id]=$metrics;}
    return $out;
}

function vp3_agent_relationship_enrich_portal(array $portal,array $metrics): array
{
    if(empty($portal['contacts'])||!is_array($portal['contacts']))return $portal;
    foreach($portal['contacts'] as &$contact){$id=(int)($contact['id']??0);if(isset($metrics[$id])){$contact['opportunity_score']=(int)$metrics[$id]['opportunity_score'];$contact['recommendation']=(string)$metrics[$id]['recommendation'];$contact['relationship_metrics']=$metrics[$id];}}
    unset($contact);
    return $portal;
}
