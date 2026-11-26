<?php
declare(strict_types=1);
require_once __DIR__.'/phone-workspace-v1.php';
require_once __DIR__.'/section12-data.php';

/** Deliver saved end-of-call outcomes from cron even if the owner's browser is closed. */
function vp3_operator_outcome_chat_tick_v1(PDO $pdo,int $now,int $limit=40):array
{
    $counts=['delivered'=>0,'deferred'=>0,'errors'=>0];
    $query=$pdo->prepare("SELECT owner_user_id,event_id,start_epoch FROM phone_operator_occurrences_v1
        WHERE state='ended' AND conversation_id>0 AND chat_notice_after BETWEEN 0 AND ?
        ORDER BY chat_notice_after,start_epoch,owner_user_id,event_id LIMIT ".max(1,min(100,$limit)));
    $query->execute([$now]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $item){
        $owner=(int)$item['owner_user_id'];
        $key=[$owner,(int)$item['event_id'],(int)$item['start_epoch']];
        $where="owner_user_id=? AND event_id=? AND start_epoch=? AND state='ended' AND chat_notice_after BETWEEN 0 AND ?";
        try{
            $result=section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$key,$where,$now){
                $q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE '.$where.vp3_phone_workspace_lock_v1($pdo));
                $q->execute([...$key,$now]);
                $row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row)return null;
                $scope=vp3_phone_workspace_scope_v1($pdo,['id'=>$owner],['conversation_id'=>$row['conversation_id'],'agent_id'=>$row['chat_agent_id']]);
                $labels=['answer_consented'=>'The recipient agreed to a recorded and transcribed conversation.',
                    'conversation_completed'=>'The recipient ended the conversation by voice.',
                    'conversation_silent'=>'The call ended while the agent was waiting for a spoken response.',
                    'conversation_turn_limit'=>'The conversation reached its eight-turn limit.',
                    'conversation_model_failed'=>'The agent could not generate a reply and ended the call.',
                    'conversation_ended'=>'The call ended during the conversation.',
                    'answer_declined'=>'The recipient declined the conversation.','answer_no_input'=>'The recipient gave no keypad response.',
                    'answer_permission_changed'=>'Permission or availability changed before the conversation could start.',
                    'carrier_busy'=>'The recipient’s line was busy.','carrier_failed'=>'The carrier could not connect the call.',
                    'carrier_no_answer'=>'The call went unanswered.','carrier_canceled'=>'The call was canceled.',
                    'ended_before_consent'=>'The call ended before the recipient confirmed.'];
                $label=$labels[$row['reason']]??'The scheduled call ended.';
                $contact=$pdo->prepare('SELECT name FROM crm_contacts WHERE owner_user_id=? AND id=?');
                $contact->execute([$owner,$row['contact_id']]);
                $name=trim(preg_replace("/[^\p{L}\p{M}\p{N} .’'-]+/u",' ',(string)$contact->fetchColumn())??'');
                $title=$name===''?'Scheduled phone call':'Scheduled phone call with '.mb_substr($name,0,120);
                $text=$title.' · '.gmdate('Y-m-d H:i \U\T\C',$key[2])."\n".$label;
                $receipt='scheduled:'.$key[1].':'.$key[2];
                vp3_phone_workspace_delivery_v1($pdo,['owner_user_id'=>$owner,'conversation_id'=>$scope['conversation_id'],
                    'chat_agent_id'=>$scope['agent_id'],'public_id'=>$receipt],'scheduled_outcome',$receipt,$text,['status'=>$row['reason']]);
                $pdo->prepare('UPDATE phone_operator_occurrences_v1 SET chat_notice_after=-1 WHERE '.$where)->execute([...$key,$now]);
                return 'delivered';
            });
            if($result!==null)$counts[$result]++;
        }catch(Throwable $error){
            // A missing destination never redirects delivery. Let other calls progress while this receipt waits.
            $counts[$error instanceof VP3PhoneWorkspaceException&&$error->httpStatus===403?'deferred':'errors']++;
            $pdo->prepare('UPDATE phone_operator_occurrences_v1 SET chat_notice_after=? WHERE '.$where)->execute([$now+300,...$key,$now]);
        }
    }
    return $counts;
}
