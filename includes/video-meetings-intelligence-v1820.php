<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.2 — Meeting Intelligence.
 *
 * This layer does not create another transcription/AI stack. It projects the
 * canonical VP3 Transcription Intelligence modules into the meeting workspace,
 * adds private meeting notes/objectives, throttles rolling analysis, and hands
 * reviewed meeting outcomes back to the existing Agent Chat command center.
 */
const VP3_VIDEO_MEETINGS_INTELLIGENCE_V1820='video-meetings-intelligence-v1820-20260915';
const VP3_VIDEO_MEETINGS_INTELLIGENCE_LIVE_SECONDS_V1820=90;
const VP3_VIDEO_MEETINGS_INTELLIGENCE_MIN_WORDS_V1820=80;

function video_meeting_intelligence_schema_ready_v1820(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['video_meeting_intelligence_state','video_meeting_notes','video_meeting_objectives'] as $table){
        if(!table_exists($table))return false;
    }
    return column_exists('video_meeting_intelligence_state','last_live_source_hash')
        && column_exists('video_meeting_intelligence_state','final_source_hash')
        && column_exists('video_meeting_intelligence_state','handoff_source_hash');
}

function video_meeting_intelligence_ensure_schema_v1820(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_transcription_schema_ready_v1800($pdo))video_meeting_transcription_ensure_schema_v1800($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_intelligence_state (
      meeting_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      transcript_session_id BIGINT UNSIGNED NULL,
      last_live_source_hash CHAR(64) NOT NULL DEFAULT '',
      last_live_analysis_at DATETIME NULL,
      final_source_hash CHAR(64) NOT NULL DEFAULT '',
      final_analysis_at DATETIME NULL,
      handoff_source_hash CHAR(64) NOT NULL DEFAULT '',
      handoff_conversation_id BIGINT UNSIGNED NULL,
      handoff_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_intelligence_session (transcript_session_id),
      CONSTRAINT fk_video_meeting_intelligence_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_intelligence_session FOREIGN KEY (transcript_session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_notes (
      meeting_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      note_text MEDIUMTEXT NOT NULL,
      updated_by_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_video_meeting_note_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_note_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_note_editor FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_objectives (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      objective_text VARCHAR(1000) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'open',
      sort_order INT NOT NULL DEFAULT 0,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_objectives (meeting_id,status,sort_order,id),
      CONSTRAINT fk_video_meeting_objective_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_objective_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_intelligence_owner_v1820(PDO $pdo,array $meeting): ?array
{
    return video_meeting_user_v1800($pdo,(int)($meeting['owner_user_id']??0));
}

function video_meeting_intelligence_owner_allowed_v1820(?array $user,array $meeting): bool
{
    return (int)($user['id']??0)>0 && (int)($user['id']??0)===(int)($meeting['owner_user_id']??0);
}

function video_meeting_intelligence_state_row_v1820(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_intelligence_state WHERE meeting_id=? LIMIT 1');
    $stmt->execute([(int)$meeting['id']]);$row=$stmt->fetch();
    return is_array($row)?$row:[
        'meeting_id'=>(int)$meeting['id'],'transcript_session_id'=>null,
        'last_live_source_hash'=>'','last_live_analysis_at'=>null,
        'final_source_hash'=>'','final_analysis_at'=>null,
        'handoff_source_hash'=>'','handoff_conversation_id'=>null,'handoff_at'=>null,'last_error'=>'',
    ];
}

function video_meeting_intelligence_source_v1820(PDO $pdo,array $meeting): array
{
    video_meeting_transcription_load_stack_v1800();
    $session=video_meeting_transcription_ensure_session_v1800($pdo,$meeting);
    if(!$session)return ['session'=>null,'session_id'=>0,'source_hash'=>'','word_count'=>0,'segment_count'=>0,'duration_ms'=>0];
    $segments=artist_listening_v172_segments($pdo,(int)$session['id']);
    $map=artist_listening_transcript_page_map($segments);
    $words=0;$count=0;$duration=0;
    foreach($segments as $segment){
        if(!is_array($segment)||(string)($segment['segment_type']??'transcript')!=='transcript')continue;
        $text=trim((string)($segment['transcript_text']??''));if($text==='')continue;
        $count++;$duration=max($duration,(int)($segment['ended_ms']??0));
        $words+=count(preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[]);
    }
    return [
        'session'=>$session,'session_id'=>(int)$session['id'],'source_hash'=>(string)($map['source_hash']??''),
        'word_count'=>$words,'segment_count'=>$count,'duration_ms'=>$duration,
    ];
}

function video_meeting_intelligence_modules_v1820(PDO $pdo,array $source): array
{
    $sessionId=(int)($source['session_id']??0);$sourceHash=(string)($source['source_hash']??'');
    if($sessionId<1||$sourceHash==='')return ['master'=>null,'modules'=>[],'fresh'=>false];
    video_meeting_transcription_load_stack_v1800();
    $status=artist_listening_v237_analysis_status($pdo,$sessionId,['source_hash'=>$sourceHash]);
    $master=is_array($status['master']??null)?$status['master']:null;
    if(!$master)return ['master'=>null,'modules'=>[],'fresh'=>false];
    $modules=function_exists('transcription_app_modules_v306')
        ?transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master)
        :transcription_app_modules_v301(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $fresh=!empty($master['fresh'])&&hash_equals($sourceHash,(string)($master['source_hash']??''));
    return ['master'=>$master,'modules'=>$modules,'fresh'=>$fresh];
}

function video_meeting_intelligence_clean_v1820(mixed $value,int $depth=0): mixed
{
    if($depth>4)return null;
    if(is_string($value))return mb_strimwidth(trim($value),0,2200,'…');
    if(is_bool($value)||is_int($value)||is_float($value)||$value===null)return $value;
    if(!is_array($value))return mb_strimwidth(trim((string)$value),0,2200,'…');
    $out=[];$count=0;
    foreach($value as $key=>$item){
        if($count++>=40)break;
        $clean=video_meeting_intelligence_clean_v1820($item,$depth+1);
        if($clean===null&&$item!==null)continue;
        $out[$key]=$clean;
    }
    return $out;
}

function video_meeting_intelligence_rows_v1820(array $result,string $key,int $limit=20): array
{
    $rows=is_array($result[$key]??null)?$result[$key]:[];$out=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $out[]=video_meeting_intelligence_clean_v1820($row);
        if(count($out)>=$limit)break;
    }
    return $out;
}

function video_meeting_intelligence_snapshot_v1820(PDO $pdo,array $source): array
{
    $bundle=video_meeting_intelligence_modules_v1820($pdo,$source);$modules=$bundle['modules'];$master=$bundle['master'];
    $result=static function(string $id) use($modules): array {
        return is_array($modules[$id]['result']??null)?$modules[$id]['result']:[];
    };
    $basic=$result('basic');$summaryOutput=$result('summary_output');$actionPlan=$result('action_plan');
    $decisions=$result('decisions');$actions=$result('actions');$qa=$result('qa');$followup=$result('followup');$risks=$result('risks');$topics=$result('topics');$crm=$result('crm');

    $summary='';
    $overview=video_meeting_intelligence_rows_v1820($summaryOutput,'overview',1);
    if($overview)$summary=trim((string)($overview[0]['summary']??$overview[0]['text']??''));
    if($summary==='')$summary=trim((string)($basic['summary']??$basic['analysis']??''));

    $decisionRows=array_merge(
        video_meeting_intelligence_rows_v1820($summaryOutput,'decisions',12),
        video_meeting_intelligence_rows_v1820($decisions,'decisions',16),
        video_meeting_intelligence_rows_v1820($decisions,'commitments',16)
    );
    $actionRows=video_meeting_intelligence_rows_v1820($actionPlan,'actions',24);
    if(!$actionRows)$actionRows=array_merge(video_meeting_intelligence_rows_v1820($actions,'items',20),video_meeting_intelligence_rows_v1820($followup,'items',20));
    $questionRows=array_merge(video_meeting_intelligence_rows_v1820($qa,'unanswered',20),video_meeting_intelligence_rows_v1820($basic,'open_questions',20));
    $riskRows=video_meeting_intelligence_rows_v1820($actionPlan,'blockers',16);
    if(!$riskRows)$riskRows=video_meeting_intelligence_rows_v1820($risks,'items',16);

    $pluginState=[];
    foreach($modules as $id=>$module){
        if(!is_array($module)||!transcription_app_has_result_v300($module['result']??null))continue;
        $pluginState[]=[
            'id'=>(string)$id,'generated_at'=>(string)($module['generated_at']??''),
            'provider'=>(string)($module['provider']??''),'model'=>(string)($module['model']??''),
            'source_hash'=>(string)($module['source_hash']??''),
        ];
    }

    return [
        'fresh'=>!empty($bundle['fresh']),
        'summary'=>mb_strimwidth($summary,0,7000,'…'),
        'key_points'=>video_meeting_intelligence_rows_v1820($summaryOutput,'key_points',16)?:video_meeting_intelligence_rows_v1820($basic,'key_findings',16),
        'decisions'=>array_slice($decisionRows,0,28),
        'actions'=>array_slice($actionRows,0,28),
        'questions'=>array_slice($questionRows,0,24),
        'answered_questions'=>video_meeting_intelligence_rows_v1820($qa,'answered',12),
        'risks'=>array_slice($riskRows,0,20),
        'topics'=>video_meeting_intelligence_rows_v1820($topics,'items',16),
        'crm'=>[
            'matched_contacts'=>video_meeting_intelligence_rows_v1820($crm,'matched_contacts',12),
            'signals'=>video_meeting_intelligence_rows_v1820($crm,'signals',16),
            'next_best_actions'=>video_meeting_intelligence_rows_v1820($crm,'next_best_actions',16),
            'recommended_updates'=>video_meeting_intelligence_rows_v1820($crm,'recommended_updates',16),
        ],
        'plugins'=>$pluginState,
        'generated_at'=>(string)($master['generated_at']??''),
        'provider'=>(string)($master['provider']??''),'model'=>(string)($master['model']??''),
    ];
}

function video_meeting_intelligence_notes_v1820(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare('SELECT note_text,updated_at FROM video_meeting_notes WHERE meeting_id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([(int)$meeting['id'],(int)$meeting['owner_user_id']]);$row=$stmt->fetch();
    return is_array($row)?['text'=>(string)$row['note_text'],'updated_at'=>(string)$row['updated_at']]:['text'=>'','updated_at'=>''];
}

function video_meeting_intelligence_save_note_v1820(PDO $pdo,array $meeting,array $user,string $text): array
{
    if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))throw new RuntimeException('Only the organizer can edit private meeting notes.');
    $text=mb_strimwidth(trim($text),0,40000,'…');
    $pdo->prepare('INSERT INTO video_meeting_notes (meeting_id,owner_user_id,note_text,updated_by_user_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note_text=VALUES(note_text),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()')
        ->execute([(int)$meeting['id'],(int)$meeting['owner_user_id'],$text,(int)$user['id']]);
    return video_meeting_intelligence_notes_v1820($pdo,$meeting);
}

function video_meeting_intelligence_objectives_v1820(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare("SELECT id,objective_text,status,sort_order,completed_at,created_at,updated_at FROM video_meeting_objectives WHERE meeting_id=? AND owner_user_id=? ORDER BY status='open' DESC,sort_order,id");
    $stmt->execute([(int)$meeting['id'],(int)$meeting['owner_user_id']]);
    return $stmt->fetchAll()?:[];
}

function video_meeting_intelligence_add_objective_v1820(PDO $pdo,array $meeting,array $user,string $text): array
{
    if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))throw new RuntimeException('Only the organizer can manage meeting objectives.');
    $text=mb_strimwidth(trim(preg_replace('/\s+/u',' ',$text)??$text),0,1000,'');if($text==='')throw new RuntimeException('Enter a meeting objective.');
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM video_meeting_objectives WHERE meeting_id=?');$stmt->execute([(int)$meeting['id']]);$sort=max(10,(int)$stmt->fetchColumn());
    $pdo->prepare("INSERT INTO video_meeting_objectives (meeting_id,owner_user_id,objective_text,status,sort_order) VALUES (?,?,?,'open',?)")
        ->execute([(int)$meeting['id'],(int)$meeting['owner_user_id'],$text,$sort]);
    return video_meeting_intelligence_objectives_v1820($pdo,$meeting);
}

function video_meeting_intelligence_toggle_objective_v1820(PDO $pdo,array $meeting,array $user,int $objectiveId,string $status): array
{
    if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))throw new RuntimeException('Only the organizer can manage meeting objectives.');
    $status=strtolower(trim($status));if(!in_array($status,['open','completed','cancelled'],true))$status='open';
    $stmt=$pdo->prepare("UPDATE video_meeting_objectives SET status=?,completed_at=CASE WHEN ?='completed' THEN COALESCE(completed_at,NOW()) ELSE NULL END WHERE id=? AND meeting_id=? AND owner_user_id=?");
    $stmt->execute([$status,$status,$objectiveId,(int)$meeting['id'],(int)$meeting['owner_user_id']]);
    return video_meeting_intelligence_objectives_v1820($pdo,$meeting);
}

function video_meeting_intelligence_prep_v1820(PDO $pdo,array $meeting): ?array
{
    $bookingId=(int)($meeting['booking_id']??0);if($bookingId<1||!function_exists('agent_appointment_lifecycle_brief_v700'))return null;
    try{
        $brief=agent_appointment_lifecycle_brief_v700($pdo,$bookingId);if(!is_array($brief))return null;
        return ['brief_text'=>mb_strimwidth((string)($brief['brief_text']??''),0,10000,'…'),'prepared_at'=>(string)($brief['prepared_at']??$brief['updated_at']??'')];
    }catch(Throwable $ignored){return null;}
}

function video_meeting_intelligence_policy_v1820(PDO $pdo,array $meeting,array $source): array
{
    $owner=video_meeting_intelligence_owner_v1820($pdo,$meeting);$session=$source['session']??null;
    if(!$owner||!is_array($session))return ['is_meeting'=>true,'cloud_ai_allowed'=>false,'policy_resolved'=>false,'requested_compute'=>'unknown','reason'=>'transcript_not_ready'];
    if(function_exists('video_meeting_transcription_ai_policy_v1801')){
        $policy=video_meeting_transcription_ai_policy_v1801($pdo,$owner,$session,false);
        if(is_array($policy))return $policy;
    }
    return ['is_meeting'=>true,'cloud_ai_allowed'=>true,'policy_resolved'=>true,'requested_compute'=>'auto','reason'=>'meeting_default'];
}

function video_meeting_intelligence_public_state_v1820(PDO $pdo,array $meeting): array
{
    $source=video_meeting_intelligence_source_v1820($pdo,$meeting);$state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    $sessionId=(int)$source['session_id'];
    if($sessionId>0&&(int)($state['transcript_session_id']??0)!==$sessionId){
        $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id) VALUES (?,?) ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),updated_at=NOW()')
            ->execute([(int)$meeting['id'],$sessionId]);
        $state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    }
    $sourceHash=(string)$source['source_hash'];$lastLive=(string)($state['last_live_source_hash']??'');$finalHash=(string)($state['final_source_hash']??'');
    $lastLiveAt=strtotime((string)($state['last_live_analysis_at']??''))?:0;
    $liveDue=$sourceHash!==''&&(int)$source['word_count']>=VP3_VIDEO_MEETINGS_INTELLIGENCE_MIN_WORDS_V1820
        &&($lastLive===''||!hash_equals($sourceHash,$lastLive))
        &&($lastLiveAt===0||$lastLiveAt<=time()-VP3_VIDEO_MEETINGS_INTELLIGENCE_LIVE_SECONDS_V1820);
    $closed=in_array((string)$meeting['status'],['ended','processed'],true);
    $finalDue=$closed&&$sourceHash!==''&&($finalHash===''||!hash_equals($sourceHash,$finalHash));
    $policy=video_meeting_intelligence_policy_v1820($pdo,$meeting,$source);
    return [
        'meeting_status'=>(string)$meeting['status'],'session_id'=>$sessionId,'source_hash'=>$sourceHash,
        'word_count'=>(int)$source['word_count'],'segment_count'=>(int)$source['segment_count'],'duration_ms'=>(int)$source['duration_ms'],
        'live_analysis_due'=>$liveDue,'final_analysis_due'=>$finalDue,
        'last_live_analysis_at'=>(string)($state['last_live_analysis_at']??''),'final_analysis_at'=>(string)($state['final_analysis_at']??''),
        'handoff_at'=>(string)($state['handoff_at']??''),'handoff_conversation_id'=>(int)($state['handoff_conversation_id']??0),
        'last_error'=>(string)($state['last_error']??''),'processing_policy'=>video_meeting_intelligence_clean_v1820($policy),
        'snapshot'=>video_meeting_intelligence_snapshot_v1820($pdo,$source),
        'notes'=>video_meeting_intelligence_notes_v1820($pdo,$meeting),'objectives'=>video_meeting_intelligence_objectives_v1820($pdo,$meeting),
        'prep'=>video_meeting_intelligence_prep_v1820($pdo,$meeting),
        'full_transcription_url'=>$sessionId>0?url('/artist-listening.php?session='.$sessionId):url('/artist-listening.php'),
        'active_agent_available'=>false,
    ];
}

function video_meeting_intelligence_record_analysis_v1820(PDO $pdo,array $meeting,string $mode,string $sourceHash,string $error=''): array
{
    $source=video_meeting_intelligence_source_v1820($pdo,$meeting);$current=(string)$source['source_hash'];
    if($current===''||$sourceHash===''||!hash_equals($current,$sourceHash))throw new RuntimeException('The meeting transcript changed while intelligence was running. Refresh and try again.');
    $mode=$mode==='final'?'final':'live';$error=mb_strimwidth(trim($error),0,1000,'…');
    if($mode==='final'){
        $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id,final_source_hash,final_analysis_at,last_error) VALUES (?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),final_source_hash=VALUES(final_source_hash),final_analysis_at=NOW(),last_error=VALUES(last_error),updated_at=NOW()')
            ->execute([(int)$meeting['id'],(int)$source['session_id'],$current,$error]);
    }else{
        $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id,last_live_source_hash,last_live_analysis_at,last_error) VALUES (?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),last_live_source_hash=VALUES(last_live_source_hash),last_live_analysis_at=NOW(),last_error=VALUES(last_error),updated_at=NOW()')
            ->execute([(int)$meeting['id'],(int)$source['session_id'],$current,$error]);
    }
    return video_meeting_intelligence_public_state_v1820($pdo,$meeting);
}

function video_meeting_intelligence_handoff_text_v1820(array $meeting,array $public): string
{
    $snapshot=is_array($public['snapshot']??null)?$public['snapshot']:[];
    $line=static function(array $row,array $keys): string {
        foreach($keys as $key){$value=trim((string)($row[$key]??''));if($value!=='')return $value;}
        return '';
    };
    $parts=['Meeting intelligence: '.(string)$meeting['title']];
    $summary=trim((string)($snapshot['summary']??''));if($summary!=='')$parts[]="Summary\n".$summary;
    $decisions=[];foreach(array_slice((array)($snapshot['decisions']??[]),0,8) as $row){if(is_array($row)&&($text=$line($row,['decision','commitment','text']))!=='')$decisions[]='• '.$text;}
    if($decisions)$parts[]="Decisions & commitments\n".implode("\n",$decisions);
    $actions=[];foreach(array_slice((array)($snapshot['actions']??[]),0,10) as $row){if(is_array($row)&&($text=$line($row,['action','follow_up','next_step','text']))!=='')$actions[]='• '.$text;}
    if($actions)$parts[]="Actions / follow-up\n".implode("\n",$actions);
    $questions=[];foreach(array_slice((array)($snapshot['questions']??[]),0,8) as $row){if(is_array($row)&&($text=$line($row,['question','text']))!=='')$questions[]='• '.$text;}
    if($questions)$parts[]="Open questions\n".implode("\n",$questions);
    $parts[]='Review the linked meeting intelligence before promoting items into CRM, Tasks, Knowledge or external follow-up.';
    return mb_strimwidth(implode("\n\n",$parts),0,16000,'…');
}

function video_meeting_intelligence_handoff_v1820(PDO $pdo,array $meeting,array $user): array
{
    if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))throw new RuntimeException('Only the organizer can publish meeting intelligence to Agent Chat.');
    if(!function_exists('agent_chat_v101_append_ecosystem_message'))throw new RuntimeException('Agent Chat is unavailable.');
    $public=video_meeting_intelligence_public_state_v1820($pdo,$meeting);$sourceHash=(string)($public['source_hash']??'');
    if($sourceHash==='')throw new RuntimeException('The meeting transcript does not contain enough information to publish yet.');
    $state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    if((string)($state['handoff_source_hash']??'')!==''&&hash_equals((string)$state['handoff_source_hash'],$sourceHash)){
        return ['published'=>false,'already_published'=>true,'conversation_id'=>(int)($state['handoff_conversation_id']??0)];
    }
    $sessionId=(int)($public['session_id']??0);$meetingId=(int)$meeting['id'];
    $context=[
        'source'=>'video_meeting_intelligence','source_label'=>'Meeting Intelligence','video_meeting_id'=>$meetingId,
        'video_meeting_public_id'=>(string)$meeting['public_id'],'transcript_session_id'=>$sessionId,
        'meeting_intelligence'=>true,'skip_brain_archive'=>true,'generated_at'=>gmdate('c'),
        'actions'=>[
            ['label'=>'Open meeting review','url'=>url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1')],
            ['label'=>'Open full transcription intelligence','url'=>url('/artist-listening.php?session='.$sessionId)],
        ],
        'sources'=>[
            ['source'=>'video_meeting:'.$meetingId,'title'=>(string)$meeting['title']],
            ['source'=>'transcript:'.$sessionId,'title'=>'Meeting transcript #'.$sessionId],
        ],
    ];
    $conversationId=agent_chat_v101_append_ecosystem_message($user,video_meeting_intelligence_handoff_text_v1820($meeting,$public),$context);
    if($conversationId<1)throw new RuntimeException('Agent Chat did not accept the meeting intelligence handoff.');
    $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id,handoff_source_hash,handoff_conversation_id,handoff_at,last_error) VALUES (?,?,?,?,NOW(),\'\') ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),handoff_source_hash=VALUES(handoff_source_hash),handoff_conversation_id=VALUES(handoff_conversation_id),handoff_at=NOW(),last_error=\'\',updated_at=NOW()')
        ->execute([$meetingId,$sessionId,$sourceHash,$conversationId]);
    if(function_exists('create_notification')){
        try{create_notification((int)$meeting['owner_user_id'],'video_meeting_intelligence_ready','Meeting intelligence ready',(string)$meeting['title'],url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'),'video_meeting',$meetingId);}catch(Throwable $ignored){}
    }
    return ['published'=>true,'conversation_id'=>$conversationId];
}

function video_meeting_intelligence_mark_ended_v1820(PDO $pdo,array $meeting): void
{
    if(!video_meeting_intelligence_schema_ready_v1820($pdo))return;
    try{
        $source=video_meeting_intelligence_source_v1820($pdo,$meeting);
        $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id,last_error) VALUES (?,?,\'\') ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),last_error=\'\',updated_at=NOW()')
            ->execute([(int)$meeting['id'],(int)($source['session_id']??0)?:null]);
    }catch(Throwable $e){
        error_log('VP3 meeting intelligence end marker: '.$e->getMessage());
    }
}
