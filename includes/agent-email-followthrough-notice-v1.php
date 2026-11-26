<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-followthrough-status-v1.php';

/** Only a saved, current canonical draft can upgrade an overdue milestone to ready. */
function agent_email_followthrough_notice_draft_v1(PDO $pdo,array $row): ?array
{
    return agent_email_followthrough_saved_draft_v1($pdo,$row);
}

/** Control changes cancel work, but do not turn the same deadline into a new milestone. */
function agent_email_followthrough_notice_saved_stage_v1(PDO $pdo,array $row): ?string
{
    if((int)($row['notice_message_id'] ?? 0)<1)return null;
    $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL");
    $q->execute([(int)$row['notice_message_id'],(int)$row['conversation_id']]);$context=json_decode((string)$q->fetchColumn(),true,16);
    $marker=$context['email_followthrough'] ?? null;
    if(($context['source'] ?? null)!=='agent_email_followthrough' || !is_array($marker)
        || ($marker['id'] ?? null)!==$row['id'] || ($marker['source_revision'] ?? null)!==(int)$row['source_revision']
        || ($marker['revision'] ?? null)!==(int)($row['notice_revision'] ?? 0)
        || ($marker['stage'] ?? null)!==($row['notice_stage'] ?? null)
        || !in_array($marker['stage'],['overdue','draft_ready'],true))return null;
    return $marker['stage'];
}

/** Pending high-priority items receive the next slot before lower-priority mail. */
function agent_email_followthrough_notice_priority_v1(PDO $pdo,array $row): bool
{
    $rank=['high'=>0,'normal'=>1,'low'=>2];$priority=$rank[$row['priority']] ?? 1;
    if($priority===0)return true;
    $q=$pdo->prepare("SELECT * FROM agent_email_followthrough_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND connection_id=? AND authorization_key=? AND id<>? AND outcome='overdue' AND paused=0 AND awaiting_new_deadline=0
        AND check_state='verified' AND check_result='no_reply' AND checked_at>=deadline_at
        AND CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END<?
        ORDER BY CASE priority WHEN 'high' THEN 0 ELSE 1 END,deadline_at,id LIMIT 50");
    $q->execute([(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],(int)$row['connection_id'],
        $row['authorization_key'],$row['id'],$priority]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
        try{
            $scope=agent_email_followthrough_binding_current_v1($pdo,(int)$candidate['owner_user_id'],(int)$candidate['agent_id'],
                (int)$candidate['conversation_id'],['followthrough_id'=>$candidate['id'],'revision'=>(int)$candidate['source_revision']]);
            $candidate=$scope['row'];$stage=agent_email_followthrough_notice_draft_v1($pdo,$candidate)!==null?'draft_ready':'overdue';
            $saved=agent_email_followthrough_notice_saved_stage_v1($pdo,$candidate);
            if($saved!=='draft_ready' && $saved!==$stage)return false;
        }catch(DomainException|AgentConnectionExceptionV1){/* Invalid or revoked sources cannot starve other mail. */}
    }
    return true;
}

/** Use real saved turns for traffic and spacing; polling never changes the cadence. */
function agent_email_followthrough_notice_spacing_v1(PDO $pdo,array $row,string $stage,int $now): bool
{
    require_once __DIR__.'/agent-coordination-reminders-v1.php';
    $q=$pdo->prepare("SELECT id,role,user_id,context_json,created_at FROM chat_messages
        WHERE conversation_id=? AND role IN ('user','assistant') ORDER BY id DESC LIMIT 80");
    $q->execute([(int)$row['conversation_id']]);$traffic=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $message){
        $at=strtotime((string)$message['created_at'].' UTC') ?: 0;
        if($at<1 || $at>$now || $at<$now-86400)continue;
        $context=json_decode((string)($message['context_json'] ?? ''),true,16);
        $source=$context['source'] ?? '';
        if(($message['role']==='user' && (int)$message['user_id']===(int)$row['owner_user_id'])
            || ($message['role']==='assistant' && $message['user_id']===null
                && in_array($source,['agent_initiative','agent_initiative_morning_brief','agent_initiative_reminder','agent_email_followthrough'],true)))$traffic++;
    }
    // A busy Chat may have more than 80 recent turns. The durable marker still reserves its spacing slot.
    $q=$pdo->prepare("SELECT f.id,f.notice_revision,f.notice_stage,m.context_json,m.created_at FROM agent_email_followthrough_v1 f
        JOIN chat_messages m ON m.id=f.notice_message_id AND m.conversation_id=f.conversation_id AND m.role='assistant' AND m.user_id IS NULL
        WHERE f.owner_user_id=? AND f.agent_id=? AND f.conversation_id=? AND m.created_at>=? AND m.created_at<=?
        ORDER BY m.created_at DESC,m.id DESC LIMIT 50");
    $q->execute([(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],gmdate('Y-m-d H:i:s',$now-86400),gmdate('Y-m-d H:i:s',$now)]);
    $last=0;$same=false;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $message){
        $context=json_decode((string)$message['context_json'],true,16);$marker=$context['email_followthrough'] ?? null;
        if(($context['source'] ?? null)!=='agent_email_followthrough' || !is_array($marker) || ($marker['id'] ?? null)!==$message['id']
            || ($marker['revision'] ?? null)!==(int)$message['notice_revision'] || ($marker['stage'] ?? null)!==$message['notice_stage'])continue;
        $last=strtotime($message['created_at'].' UTC') ?: 0;
        $same=$marker['id']===$row['id'] && ($marker['source_revision'] ?? null)===(int)$row['source_revision'];break;
    }
    if($last===0)return true;
    // A ready draft is a new result. Routine reminders for different mail keep their established longer spacing.
    $delay=$row['priority']==='high' || ($stage==='draft_ready' && $same) ? 60
        : intdiv(vp3_coordination_gmail_reminder_delay_v1($row['priority'],0,min(6,$traffic)),2);
    return $now-$last >= $delay;
}

/** One canonical Chat milestone, notification and marker commit together; no model or Gmail I/O. */
function agent_email_followthrough_notice_v1(PDO $pdo,array $row,array $watch,string $watchClaim): void
{
    if($pdo->inTransaction())throw new LogicException('Prepare follow-up notices outside a transaction.');
    require_once __DIR__.'/agent-initiative-v1.php';
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$row,$watchClaim):void{
        $scope=agent_email_followthrough_current_v1($pdo,$row,$watchClaim,true);$row=$scope['row'];
        if($row['paused'] || !empty($row['awaiting_new_deadline']) || $row['outcome']!=='overdue'
            || $row['check_state']!=='verified' || $row['check_result']!=='no_reply')return;
        if(vp3_initiative_report_gate_v1($pdo,$scope['user'],(int)$row['conversation_id'],(int)$row['agent_id'])!=='ready')return;
        $draft=agent_email_followthrough_notice_draft_v1($pdo,$row);$stage=$draft===null?'overdue':'draft_ready';
        $saved=agent_email_followthrough_notice_saved_stage_v1($pdo,$row);
        if($saved==='draft_ready' || $saved===$stage)return;
        if(!agent_email_followthrough_notice_priority_v1($pdo,$row)
            || !agent_email_followthrough_notice_spacing_v1($pdo,$row,$stage,time()))return;
        $checked=agent_email_followthrough_iso_v1($row['checked_at']);
        $message=$draft!==null?'I saved a follow-up draft for your review. ':'Your response deadline passed. ';
        $message.='The Gmail check at '.$checked.' found no matching reply in the original thread.';
        $actions=$draft!==null ? [[
            'type'=>'email_review','id'=>$draft['id'],'conversation_id'=>(int)$row['conversation_id'],'label'=>'Review follow-up',
            'auto'=>false,'policy'=>['version'=>'v4.00','domain'=>'connections_email','risk'=>'high','requires_approval'=>true,'authorized'=>true],
        ]] : [[
            'type'=>'open_url','label'=>'View follow-up',
            'url'=>url('/chat.php?agent='.((int)$row['agent_id'] ?: 'system').'&conversation_id='.(int)$row['conversation_id'].'&gmail_followthrough='.$row['id']),
        ]];
        $context=['source'=>'agent_email_followthrough','skip_brain_archive'=>true,
            'email_followthrough'=>['id'=>$row['id'],'revision'=>(int)$row['revision'],'source_revision'=>(int)$row['source_revision'],'stage'=>$stage,
                'checked_at'=>$checked,'priority'=>$row['priority']],
            'actions'=>$actions];
        $now=agent_commerce_time_pa2();
        $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
            VALUES (?,NULL,'assistant',?,?,?)")
            ->execute([(int)$row['conversation_id'],$message,json_encode($context,JSON_THROW_ON_ERROR),$now]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')
            ->execute([$now,(int)$row['conversation_id'],(int)$row['owner_user_id']]);
        notification_create_locked((int)$row['owner_user_id'],'agent_email_followthrough',
            $stage==='draft_ready'?'Gmail follow-up draft ready':'Gmail response deadline passed',
            'Open your private Agent Chat to review this Gmail follow-up.',
            url('/chat.php?agent='.((int)$row['agent_id'] ?: 'system').'&conversation_id='.(int)$row['conversation_id'].'&message_id='.$id),
            'agent_email_followthrough',$id);
        $pdo->prepare('UPDATE agent_email_followthrough_v1 SET notice_revision=?,notice_stage=?,notice_message_id=?,state_version=state_version+1
            WHERE id=? AND revision=?')->execute([(int)$row['revision'],$stage,$id,$row['id'],(int)$row['revision']]);
    });
}
