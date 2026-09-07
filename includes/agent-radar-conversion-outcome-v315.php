<?php
declare(strict_types=1);

/**
 * Agent Radar conversion outcome closure v315.
 *
 * A first-party attributed human conversion may close an Agent Brain Radar
 * opportunity as successful, but only when the exact opportunity notification
 * for the same agent_contact_id was genuinely surfaced and remains unclosed.
 * This bridges existing Radar/referral evidence into the canonical v313 learner;
 * it does not create another identity, analytics, CRM, or outcome store.
 */
const VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315='agent-radar-conversion-outcome-v315-20260907';
const VP3_AGENT_RADAR_CONVERSION_OUTCOME_LOOKBACK_DAYS_V315=60;

function agent_radar_outcome_v315_notification_hash(int $notificationId): string
{
    return $notificationId>0?sha1('notification|'.$notificationId):'';
}

function agent_radar_outcome_v315_time(string $value): string
{
    $ts=strtotime(trim($value));
    return $ts===false?'':date('Y-m-d H:i:s',$ts);
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

function agent_radar_outcome_v315_close_conversion(PDO $pdo,array $user,array $conversion): array
{
    $userId=(int)($user['id']??0);
    $agentContactId=max(0,(int)($conversion['agent_contact_id']??0));
    $conversionAt=agent_radar_outcome_v315_time((string)($conversion['occurred_at']??date('Y-m-d H:i:s')));
    $eventName=trim((string)($conversion['event_name']??''));
    $referralId=max(0,(int)($conversion['referral_id']??0));
    $conversionRadarEventId=max(0,(int)($conversion['radar_event_id']??0));
    if($userId<1||$agentContactId<1||$conversionAt===''||$referralId<1||$conversionRadarEventId<1||$eventName===''){
        return ['recorded'=>false,'reason'=>'conversion-identity-incomplete'];
    }
    if(!function_exists('agent_action_v124_record_outcome'))return ['recorded'=>false,'reason'=>'outcome-writer-unavailable'];

    $candidates=agent_radar_outcome_v315_opportunity_candidates($pdo,$userId,$agentContactId,$conversionAt);
    if(!$candidates)return ['recorded'=>false,'reason'=>'no-agent-opportunity'];

    foreach($candidates as $candidate){
        $notificationId=max(0,(int)($candidate['notification_id']??0));
        $hash=agent_radar_outcome_v315_notification_hash($notificationId);
        if($hash==='')continue;
        $cycle=agent_radar_outcome_v315_cycle_state($userId,$hash,$conversionAt);
        if(empty($cycle['eligible']))continue;

        $opportunityDetails=json_decode((string)($candidate['opportunity_details_json']??''),true);
        if(!is_array($opportunityDetails))$opportunityDetails=[];
        $context=[
            'automatic'=>true,
            'trigger'=>'first_party_agent_conversion',
            'attribution'=>'first_party_token',
            'agent_contact_id'=>$agentContactId,
            'referral_id'=>$referralId,
            'conversion_radar_event_id'=>$conversionRadarEventId,
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
        $result['conversion_radar_event_id']=$conversionRadarEventId;
        $result['opportunity_notification_id']=$notificationId;
        $result['automatic']=true;
        $result['closure_build']=VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315;

        if(!empty($result['recorded'])&&function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('brain.radar_conversion_outcome_auto_closed',[
                'user_id'=>$userId,
                'agent_contact_id'=>$agentContactId,
                'referral_id'=>$referralId,
                'conversion_radar_event_id'=>$conversionRadarEventId,
                'opportunity_notification_id'=>$notificationId,
                'event_name'=>$eventName,
                'outcome'=>'successful',
            ]);
        }
        return $result;
    }

    return ['recorded'=>false,'reason'=>'no-open-surfaced-opportunity'];
}
