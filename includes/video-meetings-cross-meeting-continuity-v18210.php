<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.21 — Cross-Meeting Commitment Intelligence & Continuity.
 *
 * Creates organizer-owned lineage between prior agenda commitments/decisions and
 * the current meeting. Continuity is context and agenda orchestration only: it
 * never creates or executes Task, Calendar, CRM, Email, notification, Agent
 * Brain, HomeServer, or tool actions.
 */
const VP3_VIDEO_MEETINGS_CONTINUITY_V18210='video-meetings-cross-meeting-continuity-v18210-20260917';
const VP3_VIDEO_MEETINGS_CONTINUITY_LIMIT_V18210=16;

require_once __DIR__.'/video-meetings-followthrough-verification-v18200.php';

function video_meeting_continuity_schema_ready_v18210(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!table_exists('video_meeting_continuity_links')||!table_exists('video_meeting_continuity_events'))return false;
    foreach(['source_meeting_id','source_agenda_item_id','target_meeting_id','target_agenda_item_id','status','lineage_hash'] as $column){
        if(!column_exists('video_meeting_continuity_links',$column))return false;
    }
    return true;
}

function video_meeting_continuity_ensure_schema_v18210(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_followthrough_verification_schema_ready_v18200($pdo))video_meeting_followthrough_verification_ensure_schema_v18200($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_continuity_links (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      source_meeting_id BIGINT UNSIGNED NOT NULL,
      source_agenda_item_id BIGINT UNSIGNED NOT NULL,
      source_execution_id BIGINT UNSIGNED NULL,
      source_closure_id BIGINT UNSIGNED NULL,
      target_meeting_id BIGINT UNSIGNED NOT NULL,
      target_agenda_item_id BIGINT UNSIGNED NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'carried_forward',
      lineage_hash CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_continuity_source_target (owner_user_id,source_agenda_item_id,target_meeting_id),
      UNIQUE KEY uq_video_meeting_continuity_lineage (owner_user_id,lineage_hash),
      INDEX idx_video_meeting_continuity_target (owner_user_id,target_meeting_id,status,id),
      INDEX idx_video_meeting_continuity_source (owner_user_id,source_meeting_id,source_agenda_item_id),
      CONSTRAINT fk_video_meeting_continuity_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_continuity_source_meeting FOREIGN KEY (source_meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_continuity_target_meeting FOREIGN KEY (target_meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_continuity_source_item FOREIGN KEY (source_agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE RESTRICT,
      CONSTRAINT fk_video_meeting_continuity_target_item FOREIGN KEY (target_agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_continuity_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      continuity_link_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      target_meeting_id BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(48) NOT NULL,
      from_status VARCHAR(24) NOT NULL DEFAULT '',
      to_status VARCHAR(24) NOT NULL DEFAULT '',
      summary VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_continuity_events_link (continuity_link_id,id),
      INDEX idx_video_meeting_continuity_events_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_video_meeting_continuity_event_link FOREIGN KEY (continuity_link_id) REFERENCES video_meeting_continuity_links(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_continuity_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_continuity_event_target FOREIGN KEY (target_meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_continuity_text_v18210(mixed $value,int $limit=1500): string
{
    return video_meeting_action_text_v18150($value,$limit);
}

function video_meeting_continuity_tokens_v18210(string $text): array
{
    $stop=array_fill_keys(['the','and','for','with','from','that','this','into','about','have','has','had','will','would','should','could','meeting','meetings','follow','followup','action','actions','item','items','review','update','updates','discuss','discussion','project','team','our','your','their','they','them','then','than','what','when','where','which','who','why','how','are','was','were','been','being','not','but','can','all','any','new','old'],true);
    $parts=preg_split('/[^\pL\pN]+/u',mb_strtolower($text),-1,PREG_SPLIT_NO_EMPTY)?:[];$out=[];
    foreach($parts as $part){if(mb_strlen($part)<3||isset($stop[$part]))continue;$out[$part]=true;if(count($out)>=80)break;}
    return array_keys($out);
}

function video_meeting_continuity_context_v18210(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    $text=video_meeting_continuity_text_v18210($meeting['title']??'',500);
    if(video_meeting_agenda_schema_ready_v18140($pdo)){
        $stmt=$pdo->prepare("SELECT item_text,source_meeting_id,source_hash FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND status<>'skipped' ORDER BY sort_order,id LIMIT 80");
        $stmt->execute([(int)$meeting['id'],$ownerUserId]);$items=$stmt->fetchAll()?:[];
        foreach($items as $row)$text.=' '.video_meeting_continuity_text_v18210($row['item_text']??'',700);
    }else $items=[];
    return ['tokens'=>video_meeting_continuity_tokens_v18210($text),'items'=>$items];
}

function video_meeting_continuity_lineage_hash_v18210(int $ownerUserId,int $sourceAgendaItemId,int $targetMeetingId): string
{
    return hash('sha256','meeting-continuity-v18210|'.$ownerUserId.'|'.$sourceAgendaItemId.'|'.$targetMeetingId);
}

function video_meeting_continuity_event_v18210(PDO $pdo,array $link,string $eventType,string $from,string $to,string $summary): void
{
    $stmt=$pdo->prepare('INSERT INTO video_meeting_continuity_events (continuity_link_id,owner_user_id,target_meeting_id,event_type,from_status,to_status,summary) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([(int)$link['id'],(int)$link['owner_user_id'],(int)$link['target_meeting_id'],video_meeting_continuity_text_v18210($eventType,48),video_meeting_continuity_text_v18210($from,24),video_meeting_continuity_text_v18210($to,24),video_meeting_continuity_text_v18210($summary,500)]);
}

function video_meeting_continuity_link_v18210(PDO $pdo,int $ownerUserId,int $linkId,bool $forUpdate=false): ?array
{
    if($linkId<1||$ownerUserId<1||!video_meeting_continuity_schema_ready_v18210($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_continuity_links WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$stmt->execute([$linkId,$ownerUserId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_continuity_candidates_v18210(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_continuity_schema_ready_v18210($pdo))return [];
    $context=video_meeting_continuity_context_v18210($pdo,$meeting,$ownerUserId);$contextTokens=array_fill_keys($context['tokens'],true);$currentItems=(array)$context['items'];
    $sql="SELECT a.id AS source_agenda_item_id,a.meeting_id AS source_meeting_id,a.item_text,a.item_type,a.status AS agenda_status,a.priority,a.source_hash,
                 m.public_id AS meeting_public_id,m.title AS meeting_title,m.start_at_utc AS meeting_when_utc,
                 e.id AS execution_id,e.status AS execution_status,
                 c.id AS closure_id,c.status AS closure_status,
                 l.id AS continuity_link_id,l.target_agenda_item_id,l.status AS continuity_status
          FROM video_meeting_agenda_items a
          JOIN video_meetings m ON m.id=a.meeting_id AND m.owner_user_id=a.owner_user_id
          LEFT JOIN video_meeting_action_executions e ON e.agenda_item_id=a.id AND e.owner_user_id=a.owner_user_id
          LEFT JOIN video_meeting_followthrough_monitors fm ON fm.execution_id=e.id AND fm.owner_user_id=a.owner_user_id
          LEFT JOIN video_meeting_followthrough_closures c ON c.monitor_id=fm.id AND c.owner_user_id=a.owner_user_id
          LEFT JOIN video_meeting_continuity_links l ON l.source_agenda_item_id=a.id AND l.target_meeting_id=? AND l.owner_user_id=a.owner_user_id
          WHERE a.owner_user_id=? AND a.meeting_id<>? AND m.status IN ('ended','processed') AND a.status<>'skipped'
            AND (a.item_type IN ('follow_up','decision') OR a.status IN ('follow_up','decision'))
          ORDER BY m.start_at_utc DESC,a.id DESC LIMIT 80";
    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$meeting['id'],$ownerUserId,(int)$meeting['id']]);$rows=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $candidateText=video_meeting_continuity_text_v18210(($row['item_text']??'').' '.($row['meeting_title']??''),1800);$candidateTokens=video_meeting_continuity_tokens_v18210($candidateText);$score=0;
        foreach($candidateTokens as $token)if(isset($contextTokens[$token]))$score++;
        $direct=false;
        foreach($currentItems as $current){if((int)($current['source_meeting_id']??0)===(int)$row['source_meeting_id']){$direct=true;$score+=3;if((string)($current['source_hash']??'')!==''&&(string)$current['source_hash']===(string)($row['source_hash']??''))$score+=3;}}
        $exact=false;foreach($currentItems as $current){if(mb_strtolower(video_meeting_continuity_text_v18210($current['item_text']??'',1500))===mb_strtolower(video_meeting_continuity_text_v18210($row['item_text']??'',1500))){$exact=true;$score+=8;break;}}
        $linked=(int)($row['continuity_link_id']??0)>0;if($score<1&&!$linked&&!$direct&&!$exact)continue;
        $closureVerified=(string)($row['closure_status']??'')==='verified';$hasExecution=(int)($row['execution_id']??0)>0;
        $status=$linked?(string)$row['continuity_status']:($closureVerified?'resolved_elsewhere':'open');
        $canCarry=!$linked&&!$closureVerified&&!$hasExecution;
        $guard='';if($hasExecution&&!$closureVerified)$guard='This source commitment already has Meeting Action history. Review that action instead of creating a duplicate.';elseif($closureVerified)$guard='This source commitment already has a verified intended outcome.';elseif($linked)$guard='This thread is already linked to the current meeting.';
        $kind=((string)$row['item_type']==='decision'||(string)$row['agenda_status']==='decision')?'decision':'commitment';
        $rows[]=[
            'source_agenda_item_id'=>(int)$row['source_agenda_item_id'],'source_meeting_id'=>(int)$row['source_meeting_id'],'thread_kind'=>$kind,
            'text'=>video_meeting_continuity_text_v18210($row['item_text']??'',1500),'meeting_title'=>video_meeting_continuity_text_v18210($row['meeting_title']??'Meeting',190),
            'meeting_when_utc'=>(string)($row['meeting_when_utc']??''),'review_path'=>'/meeting.php?meeting='.rawurlencode((string)($row['meeting_public_id']??'')),
            'priority'=>(string)($row['priority']??'normal'),'relevance_score'=>$score,'direct_lineage'=>$direct||$exact,
            'status'=>$status,'can_carry'=>$canCarry,'action_guard'=>$guard,
            'continuity_link_id'=>(int)($row['continuity_link_id']??0),'target_agenda_item_id'=>(int)($row['target_agenda_item_id']??0),
            'source_execution_id'=>(int)($row['execution_id']??0),'source_execution_status'=>(string)($row['execution_status']??''),'source_closure_id'=>(int)($row['closure_id']??0),'source_closure_status'=>(string)($row['closure_status']??''),
        ];
    }
    usort($rows,static fn(array $a,array $b)=>($b['relevance_score']<=>$a['relevance_score'])?:strcmp((string)$b['meeting_when_utc'],(string)$a['meeting_when_utc']));
    return array_slice($rows,0,VP3_VIDEO_MEETINGS_CONTINUITY_LIMIT_V18210);
}

function video_meeting_continuity_state_v18210(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $candidates=video_meeting_continuity_candidates_v18210($pdo,$meeting,$ownerUserId);$open=0;$carried=0;$guarded=0;
    foreach($candidates as $row){if((string)$row['status']==='open')$open++;if((string)$row['status']==='carried_forward')$carried++;if(!$row['can_carry']&&(string)$row['status']==='open')$guarded++;}
    $agentContext=[];foreach(array_slice($candidates,0,8) as $row)$agentContext[]=['kind'=>$row['thread_kind'],'status'=>$row['status'],'text'=>$row['text'],'source_meeting'=>$row['meeting_title'],'action_guarded'=>!$row['can_carry']&&$row['source_execution_id']>0];
    return [
        'version'=>'v18.21','schema'=>'vp3.meeting.continuity','candidates'=>$candidates,
        'summary'=>['open'=>$open,'carried_forward'=>$carried,'action_guarded'=>$guarded],
        'agent_context'=>$agentContext,
        'policy'=>['organizer_owned'=>true,'explicit_carry_forward'=>true,'duplicate_action_prevention'=>true,'automatic_execution'=>false,'automatic_task_creation'=>false,'automatic_calendar_creation'=>false,'automatic_crm_write'=>false,'automatic_email_send'=>false],
        'privacy'=>['participant_scoring'=>false,'participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false],
    ];
}

function video_meeting_continuity_carry_forward_v18210(PDO $pdo,array $meeting,int $ownerUserId,int $sourceAgendaItemId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);if($sourceAgendaItemId<1)throw new RuntimeException('Choose a prior commitment or decision to carry forward.');
    if(!video_meeting_continuity_schema_ready_v18210($pdo))throw new RuntimeException('Cross-Meeting Continuity is not ready. Run the current database upgrade first.');
    $pdo->beginTransaction();
    try{
        $sourceStmt=$pdo->prepare("SELECT a.*,m.public_id AS meeting_public_id,m.title AS meeting_title FROM video_meeting_agenda_items a JOIN video_meetings m ON m.id=a.meeting_id AND m.owner_user_id=a.owner_user_id WHERE a.id=? AND a.owner_user_id=? AND a.meeting_id<>? AND m.status IN ('ended','processed') LIMIT 1 FOR UPDATE");
        $sourceStmt->execute([$sourceAgendaItemId,$ownerUserId,(int)$meeting['id']]);$source=$sourceStmt->fetch();if(!is_array($source))throw new RuntimeException('That prior meeting item is unavailable.');
        $isFollow=((string)$source['item_type']==='follow_up'||(string)$source['status']==='follow_up');$isDecision=((string)$source['item_type']==='decision'||(string)$source['status']==='decision');if(!$isFollow&&!$isDecision)throw new RuntimeException('Only prior commitments or decisions can be carried forward.');
        if((string)$source['status']==='skipped')throw new RuntimeException('Skipped source items cannot be carried forward.');
        $existing=$pdo->prepare('SELECT * FROM video_meeting_continuity_links WHERE owner_user_id=? AND source_agenda_item_id=? AND target_meeting_id=? LIMIT 1 FOR UPDATE');$existing->execute([$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']]);$existingLink=$existing->fetch();if(is_array($existingLink)){$pdo->commit();return video_meeting_continuity_state_v18210($pdo,$meeting,$ownerUserId);}
        $execution=$pdo->prepare("SELECT e.id,e.status,c.id AS closure_id,c.status AS closure_status FROM video_meeting_action_executions e LEFT JOIN video_meeting_followthrough_monitors fm ON fm.execution_id=e.id AND fm.owner_user_id=e.owner_user_id LEFT JOIN video_meeting_followthrough_closures c ON c.monitor_id=fm.id AND c.owner_user_id=e.owner_user_id WHERE e.agenda_item_id=? AND e.owner_user_id=? LIMIT 1 FOR UPDATE");$execution->execute([$sourceAgendaItemId,$ownerUserId]);$exec=$execution->fetch();
        if(is_array($exec)){
            if((string)($exec['closure_status']??'')==='verified')throw new RuntimeException('That commitment already has a verified intended outcome and does not need to be carried forward.');
            throw new RuntimeException('That commitment already has Meeting Action history. Review the existing action instead of creating a duplicate Task, Calendar, CRM, or Email action.');
        }
        $sourceHash=hash('sha256','continuity-source|'.$ownerUserId.'|'.$sourceAgendaItemId);$type=$isDecision?'decision':'follow_up';$priority=$isFollow?'high':video_meeting_agenda_normalize_priority_v18140((string)($source['priority']??'normal'));
        video_meeting_agenda_insert_v18140($pdo,$meeting,$ownerUserId,(string)$source['item_text'],$type,$priority,'continuity',(int)$source['meeting_id'],$sourceHash,'','/meeting.php?meeting='.rawurlencode((string)$source['meeting_public_id']));
        $target=$pdo->prepare("SELECT id FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND source_kind='continuity' AND source_meeting_id=? AND source_hash=? LIMIT 1");$target->execute([(int)$meeting['id'],$ownerUserId,(int)$source['meeting_id'],$sourceHash]);$targetAgendaItemId=(int)$target->fetchColumn();if($targetAgendaItemId<1)throw new RuntimeException('The continuity agenda item could not be created.');
        $lineage=video_meeting_continuity_lineage_hash_v18210($ownerUserId,$sourceAgendaItemId,(int)$meeting['id']);$insert=$pdo->prepare("INSERT INTO video_meeting_continuity_links (owner_user_id,source_meeting_id,source_agenda_item_id,target_meeting_id,target_agenda_item_id,status,lineage_hash) VALUES (?,?,?,?,?,'carried_forward',?)");$insert->execute([$ownerUserId,(int)$source['meeting_id'],$sourceAgendaItemId,(int)$meeting['id'],$targetAgendaItemId,$lineage]);$linkId=(int)$pdo->lastInsertId();$link=video_meeting_continuity_link_v18210($pdo,$ownerUserId,$linkId,true);if(!$link)throw new RuntimeException('Continuity lineage could not be recorded.');
        video_meeting_continuity_event_v18210($pdo,$link,'carried_forward','open','carried_forward','Organizer carried the prior meeting thread into the current agenda. No external action was created or executed.');
        $pdo->commit();return video_meeting_continuity_state_v18210($pdo,$meeting,$ownerUserId);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function video_meeting_continuity_set_status_v18210(PDO $pdo,array $meeting,int $ownerUserId,int $linkId,string $status): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$status=strtolower(trim($status));if(!in_array($status,['carried_forward','resolved_elsewhere','superseded'],true))throw new RuntimeException('Unsupported continuity status.');
    $pdo->beginTransaction();
    try{$link=video_meeting_continuity_link_v18210($pdo,$ownerUserId,$linkId,true);if(!$link||(int)$link['target_meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Continuity thread not found.');$from=(string)$link['status'];if($from!==$status){$stmt=$pdo->prepare('UPDATE video_meeting_continuity_links SET status=?,updated_at=NOW() WHERE id=? AND owner_user_id=?');$stmt->execute([$status,$linkId,$ownerUserId]);$link['status']=$status;video_meeting_continuity_event_v18210($pdo,$link,'status_changed',$from,$status,'Organizer updated cross-meeting continuity status. No external action was executed.');}$pdo->commit();return video_meeting_continuity_state_v18210($pdo,$meeting,$ownerUserId);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
