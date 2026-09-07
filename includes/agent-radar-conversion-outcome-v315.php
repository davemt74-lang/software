<?php
declare(strict_types=1);

/**
 * Agent Radar conversion outcome closure v315.
 *
 * Bridges first-party attributed human conversions back into the existing
 * Agent Brain outcome learner. The bridge is deliberately conservative:
 * - conversion identity comes from vp3_agent_referral_events;
 * - opportunity identity comes from the owner notification -> Radar event;
 * - both sides must resolve to the same agent_contact_id;
 * - only the newest pre-conversion opportunity is eligible for attribution;
 * - the exact notification-hash recommendation must have been surfaced;
 * - an already-finalized exposure cycle is never counted again.
 *
 * No fuzzy names, inferred intent, browser identifiers, new schema, or second
 * learner are introduced here.
 */
const VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315='agent-radar-conversion-outcome-v315-20260907';
const VP3_AGENT_RADAR_CONVERSION_OUTCOME_LOOKBACK_DAYS_V315=60;
const VP3_AGENT_RADAR_CONVERSION_SCAN_DAYS_V315=35;
const VP3_AGENT_RADAR_CONVERSION_SCAN_SECONDS_V315=60;

function agent_radar_outcome_v315_notification_hash(int $notificationId): string
{
    return $notificationId>0?sha1('notification|'.$notificationId):'';
}

function agent_radar_outcome_v315_time(string $value): string
{
    $ts=strtotime(trim($value));
    return $ts===false?'':date('Y-m-d H:i:s',$ts);
}

function agent_radar_outcome_v315_event_name(string $eventType): string
{
    $eventType=trim($eventType);
    return str_starts_with($eventType,'conversion:')?substr($eventType,11):'';
}

function agent_radar_outcome_v315_cycle_state(int $userId,string $hash,string $conversionAt): array
{
    $result=['eligible'=>false,'reason'=>'no-exposure','exposure_at'=>'','final_outcome'=>'','final_at'=>''];
    $conversionAt=agent_radar_outcome_v315_time($conversionAt);
    if($userId<1||!preg_match('/^[a-f0-9]{40}$/',$hash)||$conversionAt===''||!table_exists('agent_proactive_events'))return $result;
    $pdo=db();
    if(!$pdo)return $result;

    try{
        $stmt=$pdo->prepare(
            "SELECT event_type,source_kind,context_json,created_at
             FROM agent_proactive_events
             WHERE user_id=? AND suggestion_hash=? AND source_kind='radar_agent_opportunity'
               AND event_type IN ('shown','acted','dismissed')
               AND created_at<=?
               AND created_at>=DATE_SUB(?,INTERVAL ".VP3_AGENT_RADAR_CONVERSION_OUTCOME_LOOKBACK_DAYS_V315." DAY)
             ORDER BY id DESC LIMIT 80"
        );
        $stmt->execute([$userId,$hash,$conversionAt,$conversionAt]);
        $rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){
        return ['eligible'=>false,'reason'=>'ledger-unavailable','exposure_at'=>'','final_outcome'=>'','final_at'=>''];
    }

    $exposureAt='';
    foreach($rows as $row){
        $eventType=(string)($row['event_type']??'');
        $context=function_exists('agent_action_v313_feedback_context')
            ? agent_action_v313_feedback_context((string)($row['context_json']??''))
            : (json_decode((string)($row['context_json']??''),true)?:[]);
        $outcome=function_exists('agent_action_v313_normalize_outcome')
            ? agent_action_v313_normalize_outcome((string)($context['outcome']??''),$eventType)
            : ($eventType==='dismissed'?'ignored':($eventType==='acted'?'acted':''));

        if(in_array($outcome,['successful','resolved','unsuccessful','ignored'],true)){
            if($exposureAt!==''){
                return ['eligible'=>true,'reason'=>'new-exposure-after-final','exposure_at'=>$exposureAt,'final_outcome'=>$outcome,'final_at'=>(string)($row['created_at']??'')];
            }
            return ['eligible'=>false,'reason'=>'already-final','exposure_at'=>'','final_outcome'=>$outcome,'final_at'=>(string)($row['created_at']??'')];
        }

        if($eventType==='shown'||($eventType==='acted'&&$outcome==='acted')){
            if($exposureAt==='')$exposureAt=(string)($row['created_at']??'');
        }
    }

    if($exposureAt!=='')return ['eligible'=>true,'reason'=>'open-exposure','exposure_at'=>$exposureAt,'final_outcome'=>'','final_at'=>''];
    return $result;
}

function agent_radar_outcome_v315_opportunity_candidates(PDO $pdo,int $userId,int $agentContactId,string $conversionAt): array
{
    $conversionAt=agent_radar_outcome_v315_time($conversionAt);
    if($userId<1||$agentContactId<1||$conversionAt===''||!table_exists('notifications')||!table_exists('vp3_radar_events'))return [];
    try{
        $stmt=$pdo->prepare(
            "SELECT n.id notification_id,n.source_id opportunity_radar_event_id,n.created_at notification_created_at,
                    e.occurred_at opportunity_at,e.details_json opportunity_details_json
             FROM notifications n
             INNER JOIN vp3_radar_events e
               ON n.source_type='radar_event' AND e.id=n.source_id
             WHERE n.user_id=?
               AND n.type='radar_agent_opportunity'
               AND e.owner_user_id=?
               AND e.agent_contact_id=?
               AND e.event_type='agent_opportunity_detected'
               AND e.occurred_at<=?
               AND e.occurred_at>=DATE_SUB(?,INTERVAL ".VP3_AGENT_RADAR_CONVERSION_OUTCOME_LOOKBACK_DAYS_V315." DAY)
             ORDER BY e.occurred_at DESC,e.id DESC
             LIMIT 20"
        );
        $stmt->execute([$userId,$userId,$agentContactId,$conversionAt,$conversionAt]);
        return $stmt->fetchAll()?:[];
    }catch(Throwable $e){
        return [];
    }
}

/**
 * Mirror a recorded Brain learning result into the canonical Radar activity
 * ledger so the existing CRM Activity tab can show it. The path is deliberately
 * empty and the method is SYSTEM: this audit receipt must never influence path
 * intent scoring or top-path Analytics.
 */
function agent_radar_outcome_v315_crm_audit(PDO $pdo,array $user,array $context): int
{
    $userId=(int)($user['id']??0);
    $contactId=max(0,(int)($context['agent_contact_id']??0));
    if($userId<1||$contactId<1||!table_exists('vp3_radar_events')||!table_exists('vp3_agent_contacts'))return 0;

    try{
        $property=function_exists('vp3_agent_crm_primary_property')?vp3_agent_crm_primary_property($pdo,$userId):null;
        if(!$property){
            $stmt=$pdo->prepare("SELECT id FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY (property_type='native') DESC,is_active DESC,id LIMIT 1");
            $stmt->execute([$userId]);
            $propertyId=(int)($stmt->fetchColumn()?:0);
        }else{
            $propertyId=(int)($property['id']??0);
        }
        if($propertyId<1)return 0;

        $contact=$pdo->prepare('SELECT risk_score FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');
        $contact->execute([$contactId,$userId]);
        $risk=max(0,min(100,(int)($contact->fetchColumn()?:0)));
        $eventName=trim((string)($context['conversion_event_name']??''));
        $summary='Agent Brain learned a successful Agent Radar opportunity outcome from a first-party attributed'.($eventName!==''?' '.$eventName:'').' conversion.';
        $details=[
            'outcome'=>'successful',
            'automatic'=>true,
            'source'=>'agent_radar_opportunity',
            'source_label'=>'Agent Radar',
            'agent_contact_id'=>$contactId,
            'referral_id'=>max(0,(int)($context['referral_id']??0)),
            'conversion_referral_event_id'=>max(0,(int)($context['conversion_referral_event_id']??0)),
            'opportunity_notification_id'=>max(0,(int)($context['opportunity_notification_id']??0)),
            'opportunity_radar_event_id'=>max(0,(int)($context['opportunity_radar_event_id']??0)),
            'conversion_event_name'=>$eventName,
            'conversion_value'=>$context['conversion_value']??null,
            'conversion_at'=>(string)($context['conversion_at']??''),
            'exposure_at'=>(string)($context['exposure_at']??''),
            'closure_build'=>VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315,
        ];
        $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
          (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
          VALUES (?,?,NULL,?,'agent_brain_outcome_learned','low','','SYSTEM',NULL,92,?,?,?,NOW())");
        $stmt->execute([
            $userId,$propertyId,$contactId,$risk,mb_strimwidth($summary,0,500,'…'),
            json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$pdo->lastInsertId();
    }catch(Throwable $e){
        return 0;
    }
}

function agent_radar_outcome_v315_close_conversion(PDO $pdo,array $user,array $conversion): array
{
    $userId=(int)($user['id']??0);
    $agentContactId=max(0,(int)($conversion['agent_contact_id']??0));
    $conversionAt=agent_radar_outcome_v315_time((string)($conversion['occurred_at']??''));
    $eventName=trim((string)($conversion['event_name']??''));
    $referralId=max(0,(int)($conversion['referral_id']??0));
    $referralEventId=max(0,(int)($conversion['referral_event_id']??0));
    if($userId<1||$agentContactId<1||$conversionAt===''||$referralId<1||$referralEventId<1||$eventName===''){
        return ['recorded'=>false,'reason'=>'conversion-identity-incomplete'];
    }
    if(!function_exists('agent_action_v124_record_outcome'))return ['recorded'=>false,'reason'=>'outcome-writer-unavailable'];

    $candidates=agent_radar_outcome_v315_opportunity_candidates($pdo,$userId,$agentContactId,$conversionAt);
    if(!$candidates)return ['recorded'=>false,'reason'=>'no-agent-opportunity'];

    // The newest opportunity before this conversion owns attribution. Do not
    // skip an unsurfaced/already-final latest opportunity and backfill success
    // onto an older recommendation merely because it happens to be eligible.
    $candidate=$candidates[0];
    $notificationId=max(0,(int)($candidate['notification_id']??0));
    $hash=agent_radar_outcome_v315_notification_hash($notificationId);
    if($hash==='')return ['recorded'=>false,'reason'=>'latest-opportunity-identity-invalid'];
    $cycle=agent_radar_outcome_v315_cycle_state($userId,$hash,$conversionAt);
    if(empty($cycle['eligible'])){
        return [
            'recorded'=>false,
            'reason'=>'latest-opportunity-'.(string)($cycle['reason']??'not-eligible'),
            'cycle'=>$cycle,
            'agent_contact_id'=>$agentContactId,
            'opportunity_notification_id'=>$notificationId,
        ];
    }

    $opportunityDetails=json_decode((string)($candidate['opportunity_details_json']??''),true);
    if(!is_array($opportunityDetails))$opportunityDetails=[];
    $context=[
        'automatic'=>true,
        'trigger'=>'first_party_agent_conversion',
        'attribution'=>'first_party_token',
        'agent_contact_id'=>$agentContactId,
        'referral_id'=>$referralId,
        'conversion_referral_event_id'=>$referralEventId,
        'conversion_event_name'=>$eventName,
        'conversion_value'=>is_numeric($conversion['value']??null)?(float)$conversion['value']:null,
        'conversion_at'=>$conversionAt,
        'opportunity_notification_id'=>$notificationId,
        'opportunity_radar_event_id'=>max(0,(int)($candidate['opportunity_radar_event_id']??0)),
        'opportunity_at'=>(string)($candidate['opportunity_at']??''),
        'opportunity_score'=>max(0,(int)($opportunityDetails['opportunity_score']??0)),
        'exposure_at'=>(string)($cycle['exposure_at']??''),
        'source_label'=>'Agent Radar',
        'source_url'=>url('/contacts.php#agent-contact-'.$agentContactId),
        'closure_build'=>VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315,
    ];
    $result=agent_action_v124_record_outcome($user,$hash,'successful','agent_radar_conversion_auto',[
        'source'=>'radar_agent_opportunity',
        'outcome'=>'successful',
        'context'=>$context,
    ]);
    $result['cycle']=$cycle;
    $result['agent_contact_id']=$agentContactId;
    $result['referral_id']=$referralId;
    $result['conversion_referral_event_id']=$referralEventId;
    $result['opportunity_notification_id']=$notificationId;
    $result['automatic']=true;
    $result['closure_build']=VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315;

    if(!empty($result['recorded'])){
        $result['crm_activity_event_id']=agent_radar_outcome_v315_crm_audit($pdo,$user,$context);
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('brain.radar_conversion_outcome_auto_closed',[
                'user_id'=>$userId,
                'agent_contact_id'=>$agentContactId,
                'referral_id'=>$referralId,
                'conversion_referral_event_id'=>$referralEventId,
                'opportunity_notification_id'=>$notificationId,
                'crm_activity_event_id'=>(int)($result['crm_activity_event_id']??0),
                'event_name'=>$eventName,
                'outcome'=>'successful',
            ]);
        }
    }
    return $result;
}

function agent_radar_outcome_v315_recent_conversions(PDO $pdo,int $userId,int $limit=100): array
{
    if($userId<1||!table_exists('vp3_agent_referral_events'))return [];
    $limit=max(1,min(250,$limit));
    try{
        $stmt=$pdo->prepare(
            "SELECT id referral_event_id,referral_id,agent_contact_id,event_type,value_amount,occurred_at
             FROM vp3_agent_referral_events
             WHERE owner_user_id=?
               AND event_type LIKE 'conversion:%'
               AND occurred_at>=DATE_SUB(NOW(),INTERVAL ".VP3_AGENT_RADAR_CONVERSION_SCAN_DAYS_V315." DAY)
             ORDER BY occurred_at DESC,id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll()?:[];
    }catch(Throwable $e){
        return [];
    }
}

function agent_radar_outcome_v315_reconcile(array $user): array
{
    $summary=['ready'=>false,'examined'=>0,'eligible'=>0,'recorded'=>0,'duplicates'=>0,'skipped'=>0];
    $userId=(int)($user['id']??0);
    $pdo=db();
    if(!$pdo||$userId<1||!function_exists('agent_action_v124_record_outcome'))return $summary;
    if(!table_exists('vp3_agent_referral_events')||!table_exists('agent_proactive_events'))return $summary;
    $summary['ready']=true;

    $rows=agent_radar_outcome_v315_recent_conversions($pdo,$userId,100);
    $summary['examined']=count($rows);
    foreach($rows as $row){
        if(!is_array($row))continue;
        $eventName=agent_radar_outcome_v315_event_name((string)($row['event_type']??''));
        if($eventName===''){
            $summary['skipped']++;
            continue;
        }
        $result=agent_radar_outcome_v315_close_conversion($pdo,$user,[
            'referral_event_id'=>(int)($row['referral_event_id']??0),
            'referral_id'=>(int)($row['referral_id']??0),
            'agent_contact_id'=>(int)($row['agent_contact_id']??0),
            'event_name'=>$eventName,
            'value'=>$row['value_amount']??null,
            'occurred_at'=>(string)($row['occurred_at']??''),
        ]);
        if(!empty($result['cycle']['eligible']))$summary['eligible']++;
        if(!empty($result['recorded']))$summary['recorded']++;
        elseif(!empty($result['duplicate']))$summary['duplicates']++;
        else $summary['skipped']++;
    }
    return $summary;
}

function agent_radar_outcome_v315_boot(): void
{
    if(PHP_SAPI==='cli'||!function_exists('current_user'))return;
    try{
        $user=current_user();
        $userId=(int)($user['id']??0);
        if(!$user||$userId<1)return;
        if(function_exists('has_permission')&&!has_permission('chat.access',$user))return;
        if(!table_exists('vp3_agent_referral_events')||!table_exists('agent_proactive_events'))return;

        $sessionKey='agent_radar_outcome_v315_last_'.$userId;
        $last=max(0,(int)($_SESSION[$sessionKey]??0));
        if($last>0&&time()-$last<VP3_AGENT_RADAR_CONVERSION_SCAN_SECONDS_V315)return;
        $_SESSION[$sessionKey]=time();
        agent_radar_outcome_v315_reconcile($user);
    }catch(Throwable $e){
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('brain.radar_conversion_outcome_auto_close_failed',['error_class'=>get_class($e)]);
        }
    }
}
