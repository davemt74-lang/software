<?php
declare(strict_types=1);

/** Data-only seed. Caller owns reset, session, service seams and the existing PDO. */
function pco_schema_v1(PDO $pdo): void
{
    $auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172(id $auto,session_id BIGINT,client_segment_key CHAR(64),segment_index INTEGER DEFAULT 0,segment_type VARCHAR(20),speaker_label VARCHAR(100),transcript_text TEXT,started_ms BIGINT,ended_ms BIGINT DEFAULT 0,confidence REAL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    foreach(['segment_index'=>'INTEGER DEFAULT 0','ended_ms'=>'BIGINT DEFAULT 0','confidence'=>'REAL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'] as $column=>$definition)
        profile_specialist_fixture_column_v1($pdo,'artist_transcript_segments_v172',$column,$definition);
    foreach(['title'=>"VARCHAR(190) DEFAULT 'Saved Profile call'",'client_session_key'=>'CHAR(64)','knowledge_id'=>'INTEGER','agent_memory_id'=>'BIGINT','project_note_id'=>'BIGINT'] as $column=>$definition)
        profile_specialist_fixture_column_v1($pdo,'artist_transcript_sessions_v172',$column,$definition.' NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237(session_id BIGINT PRIMARY KEY,source_hash CHAR(64),source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json LONGTEXT,research_json LONGTEXT,provider VARCHAR(32),model VARCHAR(160),generated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237(id '.$auto.',session_id BIGINT,page_number INTEGER,source_hash CHAR(64),source_word_count INTEGER,analysis_json LONGTEXT,provider VARCHAR(32),model VARCHAR(160),generated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
}

function pco_analysis_v1(PDO $pdo,int $session,array $analysis=[]): void
{
    require_once dirname(__DIR__,2).'/includes/transcript-agent-v1.php';
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$session));
    $analysis=$analysis?:['summary'=>'The saved call discussed public opening hours and an owner-reviewed follow-up.',
        'key_points'=>['The visitor asked about opening hours.'],'open_questions'=>['Which reviewed task should be advanced next?']];
    $pdo->prepare('DELETE FROM artist_transcript_master_analysis_v237 WHERE session_id=?')->execute([$session]);
    $pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237(session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model) VALUES(?,?,?,?,?,?,?,'fixture','saved-analysis')")
        ->execute([$session,$map['source_hash'],$map['total_words'],$map['page_count'],$map['page_count'],json_encode($analysis,JSON_THROW_ON_ERROR),'{}']);
}

function pco_seed_v1(PDO $pdo,array $options=[]): array
{
    pco_schema_v1($pdo);$base=(int)($options['base']??86000);$agent=(int)($options['agent_id']??7);
    $record=pfs_followup($base,$agent);$id=$record['session'];$q=$pdo->prepare('INSERT INTO artist_transcript_segments_v172(id,session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms) VALUES(?,?,?,?,?,?,?,?,?)');
    for($i=0;$i<(int)($options['segments']??55);$i++)$q->execute([$base+100+$i,$id,hash('sha256','outcome-'.$base.'-'.$i),$i+1,'transcript',$i%2?'Profile owner':'Profile visitor',
        'Saved call segment '.($i+1).': '.($i===0?'Could you confirm the public opening hours?':'A verified saved transcript statement.'),$i*1000,$i*1000+900]);
    $q=$pdo->prepare('INSERT INTO profile_call_research_pa3c(call_id,request_id,capture_revision,query,status,started_at_ms,result_json) VALUES(?,?,1,?,?,?,?)');
    for($i=0;$i<(int)($options['research']??7);$i++)$q->execute([$record['public_id'],'saved-research-'.str_pad((string)$i,16,'0',STR_PAD_LEFT),
        'Public opening hours question '.($i+1),'complete',$GLOBALS['pa3Now']-10000+$i,
        json_encode(['summary'=>'Saved public research result '.($i+1).'.','sources'=>[['title'=>'Public source','url'=>'https://example.com/hours']]],JSON_THROW_ON_ERROR)]);
    $q=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,is_active,memory_type,subject,memory_text,metadata_json) VALUES(?,1,?,1,'task',?,'Reviewed follow-up evidence',?)");
    for($i=1;$i<(int)($options['tasks']??23);$i++){
        $meta=['source_kind'=>'transcription_intelligence','source_session_id'=>$id,'created_from_reviewed_item'=>true,'item_id'=>'reviewed-outcome-'.$base.'-'.$i,
            'task_status'=>['open','in_progress','waiting','completed','cancelled'][$i%5]];
        $q->execute([$base+1000+$i,$agent?:null,'Reviewed saved call follow-up '.($i+1),json_encode($meta,JSON_THROW_ON_ERROR)]);
    }
    pco_analysis_v1($pdo,$id);
    $offer=pa3c_offer('owner',pa3c_context(2,'e'));
    $ordinary=vp3_profile_call_visitor_action_pa3c($pdo,$offer['context'],pa3c_visitor_input($offer,'decline'));
    $ending=null;
    if(($options['include_ending']??true)!==false){
        $ending=pa3c_offer('owner',pa3c_context(2,'f'));
        // Rendering seed only; the lifecycle suite separately uses real cleanup work.
        $pdo->prepare("UPDATE profile_calls_pa3c SET state='ending',accepted_at_ms=?,revision=revision+1 WHERE id=?")->execute([$GLOBALS['pa3Now'],(int)$ending['call_id']]);
    }
    $call=vp3_profile_call_row_pa3c($pdo,$record['call']);
    return ['assisted'=>['call_id'=>$record['call'],'public_conversation_id'=>(int)$call['conversation_id'],'session_id'=>$id,'task_id'=>$record['task'],'public_id'=>$record['public_id']],
        'ordinary'=>['call_id'=>(int)$ordinary['call_id'],'public_conversation_id'=>(int)$ordinary['conversation_id']],
        'ending'=>$ending?['call_id'=>(int)$ending['call_id'],'public_conversation_id'=>(int)$ending['conversation_id']]:null];
}
