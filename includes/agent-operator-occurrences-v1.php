<?php
declare(strict_types=1);

require_once __DIR__.'/phone-contact-schedules-v1.php';
require_once __DIR__.'/agent-operator-admission-v1.php';

/** Return the current or following occurrence in the series' own time zone. */
function vp3_operator_next_occurrence_v1(array $event,int $now): int
{
    $rows=user_calendar_occurrences_v1340($event,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+369*86400));
    return $rows?user_calendar_validate_utc_v1300($rows[0]['start_at_utc'])->getTimestamp():0;
}

/** Recheck the assigned private Chat and the remaining call window before preparing the receipt. */
function vp3_operator_occurrence_calendar_reason_v1(PDO $pdo,array $assignment,int $due,int $end,int $now): string
{
    try{
        $scope=vp3_phone_workspace_scope_v1($pdo,['id'=>(int)$assignment['owner_user_id']],[
            'conversation_id'=>(int)$assignment['conversation_id'],'agent_id'=>(int)$assignment['chat_agent_id']
        ]);
    }catch(VP3PhoneWorkspaceException){return 'chat_unavailable';}
    $assigned=['id'=>(int)$assignment['event_id'],'start'=>$due,'end'=>$end];
    $ranges=vp3_operator_calendar_conflicts_v1($pdo,$scope,$now,$end,$assigned);
    return vp3_operator_calendar_slot_free_v1($now,$end-$now,$ranges)?'awaiting_dispatch_checks':'calendar_conflict';
}

/** The worker records a due occurrence once. A receipt is never permission to dial. */
function vp3_operator_occurrences_tick_v1(PDO $pdo,int $now,int $limit=40): array
{
    $limit=max(1,min(100,$limit));$counts=['prepared'=>0,'blocked'=>0,'missed'=>0,'review'=>0,'errors'=>0];
    $q=$pdo->prepare("SELECT owner_user_id,event_id FROM phone_operator_scheduled_assignments_v1
        WHERE enabled=1 AND next_due_epoch>0 AND next_due_epoch<=? ORDER BY next_due_epoch,owner_user_id,event_id LIMIT ".$limit);
    $q->execute([$now]);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    foreach($candidates as $candidate){
        $owner=(int)$candidate['owner_user_id'];$id=(int)$candidate['event_id'];
        try{
            $state=section12_owner_transaction($pdo,$owner,function()use($pdo,$owner,$id,$now){
                $q=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?');
                $q->execute([$owner,$id]);$assignment=$q->fetch(PDO::FETCH_ASSOC);
                if(!$assignment||!$assignment['enabled'])return null;
                $due=(int)$assignment['next_due_epoch'];
                if($due===0||$due>$now)return null;
                $event=user_calendar_event_v1300($pdo,$owner,$id);
                $advance=$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET next_due_epoch=? WHERE owner_user_id=? AND event_id=?');
                if(!$event||$event['status']!=='active'||!hash_equals($assignment['event_revision'],section12_revision($event))){
                    $advance->execute([0,$owner,$id]);return 'review';
                }
                $interval=user_calendar_occurrences_v1340($event,gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$due+1));
                if(!$interval||user_calendar_validate_utc_v1300($interval[0]['start_at_utc'])->getTimestamp()!==$due){
                    $advance->execute([0,$owner,$id]);return 'review';
                }
                $end=user_calendar_validate_utc_v1300($interval[0]['end_at_utc'])->getTimestamp();
                $state='blocked';$reason='contact_or_line_changed';
                $q=$pdo->prepare("SELECT c.phone,n.phone_e164,n.provider_sid FROM crm_contacts c JOIN phone_cloud_numbers_v100 n ON n.owner_user_id=c.owner_user_id
                    WHERE c.id=? AND c.owner_user_id=? AND c.status<>'archived' AND n.id=? AND n.agent_id=? AND n.voice_enabled=1");
                $q->execute([$assignment['contact_id'],$owner,$assignment['number_id'],$assignment['agent_id']]);$route=$q->fetch(PDO::FETCH_ASSOC);
                if($end<=$now){$state='missed';$reason='window_ended';}
                elseif($route){
                    $destination=vp3_phone_caller_contacts_e164_v1((string)$route['phone']);
                    if(hash_equals($assignment['line_binding'],hash('sha256',$route['provider_sid'].'|'.$route['phone_e164']))&&hash_equals($assignment['destination_hash'],hash('sha256',$destination))){
                        $modes=vp3_operator_holder_modes_v1($pdo,$owner,(int)$assignment['contact_id'],$destination,$now);
                        $allowed=$modes['scheduled']&&!vp3_operator_recipient_opted_out_v1($pdo,$owner,$assignment['destination_hash']);
                        $reason=$allowed?vp3_operator_occurrence_calendar_reason_v1($pdo,$assignment,$due,$end,$now):'recipient_permission';
                        $state=$reason==='awaiting_dispatch_checks'?'prepared':'blocked';
                    }
                }
                $q=$pdo->prepare('SELECT 1 FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?');
                $q->execute([$owner,$id,$due]);$exists=(bool)$q->fetchColumn();$q->closeCursor();
                if(!$exists)$pdo->prepare('INSERT INTO phone_operator_occurrences_v1(owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$owner,$id,$due,$end,$assignment['revision'],$state,$reason,$now,$assignment['contact_id'],$assignment['conversation_id'],$assignment['chat_agent_id']]);
                $advance->execute([vp3_operator_next_occurrence_v1($event,max($end,$now)),$owner,$id]);
                return $exists?null:$state;
            });
            if($state!==null)$counts[$state]++;
        }catch(Throwable){$counts['errors']++;}
    }
    return $counts;
}
