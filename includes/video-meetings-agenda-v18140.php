<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.14 — Meeting Agenda & Action Orchestration.
 *
 * Builds an organizer-owned durable agenda on top of Phase 18.13 preparation.
 * Suggested material remains read-only until explicitly added. Approval state
 * never executes Tasks, CRM, Calendar, email, notifications, Agent Brain writes,
 * HomeServer operations, or tools; it only marks an item for later Agent review.
 */
const VP3_VIDEO_MEETINGS_AGENDA_V18140='video-meetings-agenda-v18140-20260916';
const VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140=80;
const VP3_VIDEO_MEETINGS_AGENDA_SUGGESTION_LIMIT_V18140=12;

require_once __DIR__.'/video-meetings-automation-v18130.php';

function video_meeting_agenda_schema_ready_v18140(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!table_exists('video_meeting_agenda_items'))return false;
    foreach(['item_type','source_kind','source_hash','source_index_hash','status','priority','action_kind','approval_state','sort_order','fingerprint'] as $column){
        if(!column_exists('video_meeting_agenda_items',$column))return false;
    }
    return true;
}

function video_meeting_agenda_ensure_schema_v18140(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_intelligence_schema_ready_v1820($pdo))video_meeting_intelligence_ensure_schema_v1820($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_agenda_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      item_text VARCHAR(1500) NOT NULL,
      item_type VARCHAR(32) NOT NULL DEFAULT 'discussion',
      source_kind VARCHAR(40) NOT NULL DEFAULT 'manual',
      source_meeting_id BIGINT UNSIGNED NULL,
      source_hash CHAR(64) NOT NULL DEFAULT '',
      source_index_hash CHAR(64) NOT NULL DEFAULT '',
      source_review_path VARCHAR(500) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'open',
      priority VARCHAR(16) NOT NULL DEFAULT 'normal',
      action_kind VARCHAR(24) NOT NULL DEFAULT '',
      approval_state VARCHAR(32) NOT NULL DEFAULT 'none',
      sort_order INT NOT NULL DEFAULT 0,
      fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_video_meeting_agenda_fingerprint (meeting_id,fingerprint),
      INDEX idx_video_meeting_agenda_items (meeting_id,status,sort_order,id),
      INDEX idx_video_meeting_agenda_owner (owner_user_id,meeting_id),
      CONSTRAINT fk_video_meeting_agenda_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_agenda_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_agenda_text_v18140(mixed $value,int $limit=1500): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
    return mb_strimwidth($text,0,$limit,'…');
}

function video_meeting_agenda_owner_guard_v18140(array $meeting,int $ownerUserId): void
{
    if($ownerUserId<1||(int)($meeting['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Only the organizer can manage this meeting agenda.');
    if(strtolower((string)($meeting['status']??''))==='cancelled')throw new RuntimeException('Cancelled meetings do not have an active agenda.');
}

function video_meeting_agenda_normalize_type_v18140(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['discussion','question','decision','follow_up'],true)?$value:'discussion';
}

function video_meeting_agenda_normalize_status_v18140(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['open','discussed','decision','follow_up','skipped'],true)?$value:'open';
}

function video_meeting_agenda_normalize_priority_v18140(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['low','normal','high'],true)?$value:'normal';
}

function video_meeting_agenda_normalize_action_v18140(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['task','calendar','crm','email'],true)?$value:'';
}

function video_meeting_agenda_items_v18140(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_agenda_schema_ready_v18140($pdo))return [];
    $stmt=$pdo->prepare('SELECT id,item_text,item_type,source_kind,source_meeting_id,source_hash,source_index_hash,source_review_path,status,priority,action_kind,approval_state,sort_order,created_at,updated_at FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? ORDER BY sort_order,id LIMIT '.VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140);
    $stmt->execute([(int)$meeting['id'],$ownerUserId]);
    return $stmt->fetchAll()?:[];
}

function video_meeting_agenda_next_sort_v18140(PDO $pdo,int $meetingId): int
{
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM video_meeting_agenda_items WHERE meeting_id=?');
    $stmt->execute([$meetingId]);
    return max(10,(int)$stmt->fetchColumn());
}

function video_meeting_agenda_fingerprint_v18140(int $meetingId,string $text,string $sourceKind,int $sourceMeetingId,string $sourceHash): string
{
    $sourceIdentity=$sourceMeetingId>0?($sourceMeetingId.'|'.$sourceHash):'manual';
    return hash('sha256',$meetingId.'|'.mb_strtolower($text).'|'.$sourceIdentity);
}

function video_meeting_agenda_insert_v18140(PDO $pdo,array $meeting,int $ownerUserId,string $text,string $type='discussion',string $priority='normal',string $sourceKind='manual',int $sourceMeetingId=0,string $sourceHash='',string $sourceIndexHash='',string $sourceReviewPath=''): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_agenda_schema_ready_v18140($pdo))throw new RuntimeException('Meeting Agenda storage is not ready. Run the current database upgrade first.');
    $text=video_meeting_agenda_text_v18140($text);if($text==='')throw new RuntimeException('Enter an agenda item.');
    $type=video_meeting_agenda_normalize_type_v18140($type);$priority=video_meeting_agenda_normalize_priority_v18140($priority);
    $sourceKind=video_meeting_agenda_text_v18140($sourceKind,40)?:'manual';$sourceHash=preg_match('/^[a-f0-9]{64}$/i',$sourceHash)?strtolower($sourceHash):'';$sourceIndexHash=preg_match('/^[a-f0-9]{64}$/i',$sourceIndexHash)?strtolower($sourceIndexHash):'';
    $sourceReviewPath=video_meeting_agenda_text_v18140($sourceReviewPath,500);if($sourceReviewPath!==''&&!str_starts_with($sourceReviewPath,'/meeting.php?'))$sourceReviewPath='';
    $meetingId=(int)$meeting['id'];$fingerprint=video_meeting_agenda_fingerprint_v18140($meetingId,$text,$sourceKind,$sourceMeetingId,$sourceHash);
    $existing=$pdo->prepare('SELECT id FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND fingerprint=? LIMIT 1');$existing->execute([$meetingId,$ownerUserId,$fingerprint]);
    if(!(int)$existing->fetchColumn()){$count=$pdo->prepare('SELECT COUNT(*) FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=?');$count->execute([$meetingId,$ownerUserId]);if((int)$count->fetchColumn()>=VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140)throw new RuntimeException('This meeting already has the maximum number of agenda items.');}
    $sort=video_meeting_agenda_next_sort_v18140($pdo,$meetingId);
    $stmt=$pdo->prepare("INSERT INTO video_meeting_agenda_items (meeting_id,owner_user_id,item_text,item_type,source_kind,source_meeting_id,source_hash,source_index_hash,source_review_path,status,priority,sort_order,fingerprint) VALUES (?,?,?,?,?,?,?,?,?,'open',?,?,?) ON DUPLICATE KEY UPDATE item_text=VALUES(item_text),item_type=VALUES(item_type),priority=VALUES(priority),source_kind=VALUES(source_kind),source_index_hash=VALUES(source_index_hash),source_review_path=VALUES(source_review_path),updated_at=NOW()");
    $stmt->execute([$meetingId,$ownerUserId,$text,$type,$sourceKind,$sourceMeetingId>0?$sourceMeetingId:null,$sourceHash,$sourceIndexHash,$sourceReviewPath,$priority,$sort,$fingerprint]);
    return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_source_fields_v18140(array $row): array
{
    return [
        'source_meeting_id'=>(int)($row['meeting_id']??0),
        'source_hash'=>(string)($row['provenance']['source_hash']??''),
        'source_index_hash'=>(string)($row['provenance']['index_hash']??''),
        'source_review_path'=>(string)($row['review_path']??''),
    ];
}

function video_meeting_agenda_suggestions_v18140(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    $prep=video_meeting_automation_prep_v18130($pdo,$meeting,$ownerUserId);
    $suggestions=[];$questions=[];$seen=[];
    $push=static function(array &$target,array &$seen,string $text,string $type,string $priority,string $bucket,int $index,array $row): void {
        $text=video_meeting_agenda_text_v18140($text);if($text==='')return;$key=mb_strtolower($text);if(isset($seen[$key]))return;$seen[$key]=true;
        $source=video_meeting_agenda_source_fields_v18140($row);
        $target[]=['text'=>$text,'type'=>$type,'priority'=>$priority,'source_bucket'=>$bucket,'source_index'=>$index,'source_meeting_id'=>$source['source_meeting_id'],'source_hash'=>$source['source_hash'],'source_index_hash'=>$source['source_index_hash'],'source_review_path'=>$source['source_review_path']];
    };
    foreach(array_slice((array)($prep['unresolved_commitments']??[]),0,4) as $i=>$row){if(!is_array($row))continue;$text=(string)($row['text']??'');$push($suggestions,$seen,$text,'follow_up','high','unresolved_commitments',$i,$row);$questions[]='What is the current status of: '.video_meeting_agenda_text_v18140($text,260).'?';}
    foreach(array_slice((array)($prep['historical_decisions']??[]),0,3) as $i=>$row){if(!is_array($row))continue;$text=(string)($row['text']??'');$push($suggestions,$seen,'Confirm whether this still holds: '.$text,'decision','normal','historical_decisions',$i,$row);$questions[]='Does this prior decision still hold: '.video_meeting_agenda_text_v18140($text,260).'?';}
    foreach(array_slice((array)($prep['relevant_context']??[]),0,3) as $i=>$row){if(!is_array($row))continue;$text=(string)($row['text']??'');$type=((string)($row['category']??''))==='question'?'question':'discussion';$push($suggestions,$seen,$text,$type,'normal','relevant_context',$i,$row);}
    if(video_meeting_intelligence_schema_ready_v1820($pdo)){
        foreach(array_slice(video_meeting_intelligence_objectives_v1820($pdo,$meeting),0,4) as $objective){$text=video_meeting_agenda_text_v18140($objective['objective_text']??'');if($text==='')continue;$key=mb_strtolower($text);if(isset($seen[$key]))continue;$seen[$key]=true;$suggestions[]=['text'=>$text,'type'=>'discussion','priority'=>'high','source_bucket'=>'objective','source_index'=>(int)($objective['id']??0),'source_meeting_id'=>(int)$meeting['id'],'source_hash'=>'','source_index_hash'=>'','source_review_path'=>''];}
    }
    $suggestions=array_slice($suggestions,0,VP3_VIDEO_MEETINGS_AGENDA_SUGGESTION_LIMIT_V18140);
    $questions=array_values(array_unique(array_filter(array_map(static fn($q)=>video_meeting_agenda_text_v18140($q,420),$questions))));$questions=array_slice($questions,0,6);
    $participants=(array)($prep['meeting']['participants']??[]);$title=video_meeting_agenda_text_v18140($meeting['title']??'Meeting',190);
    $brief=$title!==''?'Prepare for '.$title.'.':'Prepare for this meeting.';
    if($participants)$brief.=' Participants: '.implode(', ',array_slice($participants,0,6)).'.';
    $brief.=' '.count((array)($prep['unresolved_commitments']??[])).' unresolved commitment(s), '.count((array)($prep['historical_decisions']??[])).' prior decision(s), and '.count((array)($prep['relevant_context']??[])).' relevant context item(s) were found in finalized Meeting Memory.';
    return ['prep'=>$prep,'brief_text'=>$brief,'suggestions'=>$suggestions,'suggested_questions'=>$questions];
}

function video_meeting_agenda_promote_prep_v18140(PDO $pdo,array $meeting,int $ownerUserId,string $bucket,int $index): array
{
    $allowed=['unresolved_commitments','historical_decisions','relevant_context'];if(!in_array($bucket,$allowed,true))throw new RuntimeException('That preparation source cannot be promoted.');
    $prep=video_meeting_automation_prep_v18130($pdo,$meeting,$ownerUserId);$rows=(array)($prep[$bucket]??[]);$row=$rows[$index]??null;if(!is_array($row))throw new RuntimeException('That source-backed preparation item is no longer available.');
    $type=$bucket==='unresolved_commitments'?'follow_up':($bucket==='historical_decisions'?'decision':(((string)($row['category']??''))==='question'?'question':'discussion'));
    $priority=$bucket==='unresolved_commitments'?'high':'normal';$source=video_meeting_agenda_source_fields_v18140($row);
    return video_meeting_agenda_insert_v18140($pdo,$meeting,$ownerUserId,(string)($row['text']??''),$type,$priority,'prep_'.$bucket,$source['source_meeting_id'],$source['source_hash'],$source['source_index_hash'],$source['source_review_path']);
}

function video_meeting_agenda_promote_suggestion_v18140(PDO $pdo,array $meeting,int $ownerUserId,int $suggestionIndex): array
{
    $state=video_meeting_agenda_suggestions_v18140($pdo,$meeting,$ownerUserId);$suggestion=$state['suggestions'][$suggestionIndex]??null;if(!is_array($suggestion))throw new RuntimeException('That agenda suggestion is no longer available.');
    return video_meeting_agenda_insert_v18140($pdo,$meeting,$ownerUserId,(string)$suggestion['text'],(string)$suggestion['type'],(string)$suggestion['priority'],'suggested',(int)$suggestion['source_meeting_id'],(string)$suggestion['source_hash'],(string)$suggestion['source_index_hash'],(string)$suggestion['source_review_path']);
}

function video_meeting_agenda_accept_suggestions_v18140(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $state=video_meeting_agenda_suggestions_v18140($pdo,$meeting,$ownerUserId);
    foreach((array)$state['suggestions'] as $suggestion){if(!is_array($suggestion))continue;video_meeting_agenda_insert_v18140($pdo,$meeting,$ownerUserId,(string)$suggestion['text'],(string)$suggestion['type'],(string)$suggestion['priority'],'suggested',(int)$suggestion['source_meeting_id'],(string)$suggestion['source_hash'],(string)$suggestion['source_index_hash'],(string)$suggestion['source_review_path']);}
    return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_update_v18140(PDO $pdo,array $meeting,int $ownerUserId,int $itemId,array $changes): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);if($itemId<1)throw new RuntimeException('Agenda item not found.');
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$itemId,(int)$meeting['id'],$ownerUserId]);$row=$stmt->fetch();if(!is_array($row))throw new RuntimeException('Agenda item not found.');
    $text=array_key_exists('item_text',$changes)?video_meeting_agenda_text_v18140($changes['item_text']):((string)$row['item_text']);if($text==='')throw new RuntimeException('Agenda item text cannot be empty.');
    $type=array_key_exists('item_type',$changes)?video_meeting_agenda_normalize_type_v18140((string)$changes['item_type']):(string)$row['item_type'];
    $status=array_key_exists('status',$changes)?video_meeting_agenda_normalize_status_v18140((string)$changes['status']):(string)$row['status'];
    $priority=array_key_exists('priority',$changes)?video_meeting_agenda_normalize_priority_v18140((string)$changes['priority']):(string)$row['priority'];
    if((string)($row['approval_state']??'none')!=='none'){$type='follow_up';$status='follow_up';}
    $fingerprint=video_meeting_agenda_fingerprint_v18140((int)$meeting['id'],$text,(string)$row['source_kind'],(int)($row['source_meeting_id']??0),(string)$row['source_hash']);
    $duplicate=$pdo->prepare('SELECT id FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND fingerprint=? AND id<>? LIMIT 1');$duplicate->execute([(int)$meeting['id'],$ownerUserId,$fingerprint,$itemId]);if((int)$duplicate->fetchColumn())throw new RuntimeException('An equivalent agenda item already exists.');
    $pdo->prepare('UPDATE video_meeting_agenda_items SET item_text=?,item_type=?,status=?,priority=?,fingerprint=?,updated_at=NOW() WHERE id=? AND meeting_id=? AND owner_user_id=?')->execute([$text,$type,$status,$priority,$fingerprint,$itemId,(int)$meeting['id'],$ownerUserId]);
    return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_reorder_v18140(PDO $pdo,array $meeting,int $ownerUserId,array $ids): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($v)=>$v>0)));if(count($ids)>VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140)throw new RuntimeException('Too many agenda items.');
    $currentIds=array_map(static fn(array $row): int=>(int)$row['id'],video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId));$checkIds=$ids;sort($currentIds);sort($checkIds);if($currentIds!==$checkIds)throw new RuntimeException('Agenda order is stale. Reload the agenda and try again.');
    $pdo->beginTransaction();try{$sort=10;$stmt=$pdo->prepare('UPDATE video_meeting_agenda_items SET sort_order=? WHERE id=? AND meeting_id=? AND owner_user_id=?');foreach($ids as $id){$stmt->execute([$sort,$id,(int)$meeting['id'],$ownerUserId]);$sort+=10;}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_delete_v18140(PDO $pdo,array $meeting,int $ownerUserId,int $itemId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$pdo->prepare('DELETE FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=?')->execute([$itemId,(int)$meeting['id'],$ownerUserId]);return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_action_state_v18140(PDO $pdo,array $meeting,int $ownerUserId,int $itemId,string $actionKind,string $approvalState): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$actionKind=video_meeting_agenda_normalize_action_v18140($actionKind);if($actionKind==='')throw new RuntimeException('Choose Task, Calendar, CRM, or Email.');
    $approvalState=strtolower(trim($approvalState));if(!in_array($approvalState,['proposed','approved_for_agent_review','none'],true))throw new RuntimeException('Unsupported action approval state.');
    if($approvalState==='none'){$stmt=$pdo->prepare("UPDATE video_meeting_agenda_items SET action_kind='',approval_state='none',updated_at=NOW() WHERE id=? AND meeting_id=? AND owner_user_id=?");$stmt->execute([$itemId,(int)$meeting['id'],$ownerUserId]);}
    else{$stmt=$pdo->prepare("UPDATE video_meeting_agenda_items SET item_type='follow_up',status='follow_up',action_kind=?,approval_state=?,updated_at=NOW() WHERE id=? AND meeting_id=? AND owner_user_id=?");$stmt->execute([$actionKind,$approvalState,$itemId,(int)$meeting['id'],$ownerUserId]);}
    if($stmt->rowCount()<1){$check=$pdo->prepare('SELECT id FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=?');$check->execute([$itemId,(int)$meeting['id'],$ownerUserId]);if(!$check->fetchColumn())throw new RuntimeException('Agenda item not found.');}
    return video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);
}

function video_meeting_agenda_state_v18140(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$suggested=video_meeting_agenda_suggestions_v18140($pdo,$meeting,$ownerUserId);$items=video_meeting_agenda_items_v18140($pdo,$meeting,$ownerUserId);$counts=['open'=>0,'discussed'=>0,'decision'=>0,'follow_up'=>0,'skipped'=>0,'approved_for_agent_review'=>0];foreach($items as $item){$status=(string)($item['status']??'open');if(isset($counts[$status]))$counts[$status]++;if((string)($item['approval_state']??'')==='approved_for_agent_review')$counts['approved_for_agent_review']++;}
    return ['version'=>'v18.14','schema'=>'vp3.meeting.intelligence.agenda','meeting'=>['id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>(string)($meeting['title']??''),'start_at_utc'=>(string)($meeting['start_at_utc']??''),'timezone'=>(string)($meeting['timezone']??'UTC'),'status'=>(string)($meeting['status']??'')],'brief_text'=>$suggested['brief_text'],'suggestions'=>$suggested['suggestions'],'suggested_questions'=>$suggested['suggested_questions'],'items'=>$items,'counts'=>$counts,'approval_policy'=>['explicit'=>true,'execution'=>'agent_review_only','side_effects_executed'=>false,'allowed_action_kinds'=>['task','calendar','crm','email']],'privacy'=>['source'=>'phase_18_13_finalized_meeting_prep','raw_transcript_read'=>false,'private_notes_read'=>false,'participant_email_read'=>false,'homeserver_historical_probe'=>false],'generated_at'=>gmdate('c')];
}
