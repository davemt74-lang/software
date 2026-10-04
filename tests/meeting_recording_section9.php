<?php
declare(strict_types=1);
// Execute production SQL; adapt dialect only, never attribution/correction logic.
final class Section9PDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace([' FOR UPDATE','INSERT IGNORE','NOW()','GREATEST('],['','INSERT OR IGNORE','CURRENT_TIMESTAMP','MAX('],$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE correction_json=VALUES(correction_json),revision=VALUES(revision)','ON CONFLICT(segment_id) DO UPDATE SET correction_json=excluded.correction_json,revision=excluded.revision',$sql);
        return parent::prepare($sql,$options);
    }
}
$pdo=new Section9PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
function table_exists(string $table): bool {return true;}
function column_exists(string $table,string $column): bool {return true;}
function db(): PDO {global $pdo;return $pdo;}
require __DIR__.'/../includes/video-meetings-v1800.php';
require __DIR__.'/../includes/video-meetings-transcription-v1800.php';
video_meeting_transcription_load_stack_v1800();
function check(bool $condition,string $name): void {if(!$condition)throw new LogicException($name);echo 'PASS '.$name."\n";}
function rejects(callable $call): void {try{$call();}catch(Throwable $error){return;}throw new LogicException('expected rejection');}
$pdo->exec("CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,public_id TEXT,room_name TEXT,status TEXT,ended_at TEXT,transcription_enabled INTEGER);
 INSERT INTO video_meetings VALUES(1,7,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','fixture','live',NULL,1);
 CREATE TABLE video_meeting_participants(id INTEGER PRIMARY KEY,meeting_id INTEGER,role TEXT,display_name TEXT,user_id INTEGER,invite_token TEXT,invitation_status TEXT);
 INSERT INTO video_meeting_participants VALUES(1,1,'organizer','Owner',7,'a','accepted'),(2,1,'attendee','Guest',8,'b','accepted');
 CREATE TABLE video_meeting_transcript_segments(id INTEGER PRIMARY KEY AUTOINCREMENT,meeting_id INTEGER,participant_id INTEGER,speaker_key TEXT,speaker_name TEXT,start_ms INTEGER,end_ms INTEGER,transcript_text TEXT,confidence REAL,source TEXT,source_key TEXT,is_final INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(meeting_id,source_key));
 CREATE TABLE video_meeting_speaker_evidence(segment_id INTEGER PRIMARY KEY REFERENCES video_meeting_transcript_segments(id) ON DELETE CASCADE,attribution_json TEXT NOT NULL,correction_json TEXT,revision INTEGER DEFAULT 0);
 CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,duration_ms INTEGER DEFAULT 0,last_activity_at TEXT);
 INSERT INTO artist_transcript_sessions_v172(id) VALUES(10);
 CREATE TABLE video_meeting_transcription_links(meeting_id INTEGER PRIMARY KEY,transcript_session_id INTEGER);
 INSERT INTO video_meeting_transcription_links VALUES(1,10);
 CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(session_id,client_segment_key));
 CREATE TABLE video_meeting_artifacts(id INTEGER);");
$meeting=$pdo->query('SELECT * FROM video_meetings')->fetch();
$participants=video_meeting_participants_v1800($pdo,1);
$identity=video_meeting_participant_identity_v1800($meeting,$participants[0]);
$input=['participant_identity'=>$identity,'speaker_name'=>'Spoofed display','track_id'=>'TR_secret','text'=>'Ship Friday','start_ms'=>0,'end_ms'=>2000,'source'=>'homeserver','source_key'=>str_repeat('1',64)];
$first=video_meeting_transcription_append_v1800($pdo,$meeting,$input);
check($first['accepted']===1&&!$pdo->inTransaction(),'capture and mirror commit atomically');
check($first['speaker_attribution']['speaker_identity_verified']&&$first['speaker_attribution']['speaker_label']==='Owner','server roster label wins and isolated track identity survives');
check(!str_contains((string)$pdo->query('SELECT attribution_json FROM video_meeting_speaker_evidence')->fetchColumn(),'TR_secret'),'raw track references are not persisted');
$retry=video_meeting_transcription_append_v1800($pdo,$meeting,array_merge($input,['participant_identity'=>'unknown','speaker_name'=>'Mutated','text'=>'Mutated']));
check($retry['duplicate']&&$retry['speaker_attribution']===$first['speaker_attribution'],'retry returns first committed evidence rather than caller mutation');
$other=array_merge($input,['participant_identity'=>video_meeting_participant_identity_v1800($meeting,$participants[1]),'source_key'=>str_repeat('2',64),'text'=>'Who owns it?','start_ms'=>1000,'end_ms'=>3000]);
video_meeting_transcription_append_v1800($pdo,$meeting,$other);
$segments=artist_listening_v172_segments($pdo,10);
check($segments[0]['overlap']&&$segments[1]['overlap']&&$segments[0]['speaker_attribution']['speaker_identity_verified'],'cross-track overlap is detected without erasing isolated identities');
$before=artist_listening_transcript_page_map($segments)['source_hash'];
rejects(fn()=>meeting_speaker_correct_section9($pdo,$meeting,['id'=>8],$first['segment_id'],'Wrong owner',0));
check(!$pdo->inTransaction(),'unauthorized correction rolls back and releases lock');
rejects(fn()=>meeting_speaker_correct_section9($pdo,$meeting,['id'=>7],999,'Wrong meeting',0));
$correction=meeting_speaker_correct_section9($pdo,$meeting,['id'=>7],$first['segment_id'],'Reviewed speaker',0);
check($correction['correction_revision']===1&&!$correction['speaker_attribution']['speaker_identity_verified'],'owner label remains an annotation rather than verified identity');
rejects(fn()=>meeting_speaker_correct_section9($pdo,$meeting,['id'=>7],$first['segment_id'],'Stale correction',0));
video_meeting_transcription_append_v1800($pdo,$meeting,$input);
$feed=video_meeting_transcription_segments_v1800($pdo,$meeting);
check($feed[0]['speaker_name']==='Reviewed speaker'&&$feed[0]['speaker_attribution']['source']==='manual_correction','feed reads reviewed label and duplicate capture cannot undo it');
check($pdo->query('SELECT speaker_name FROM video_meeting_transcript_segments WHERE id=1')->fetchColumn()==='Owner','original captured speaker stays immutable');
$after=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,10))['source_hash'];
check($before!==$after,'correction changes canonical intelligence hash even within one second');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET speaker_label='Editor annotation',transcript_text='Corrected text' WHERE segment_index=1");
$feed=video_meeting_transcription_segments_v1800($pdo,$meeting);
check($feed[0]['speaker_name']==='Editor annotation'&&$feed[0]['transcript_text']==='Corrected text'&&!$feed[0]['speaker_attribution']['speaker_identity_verified'],'canonical editor changes propagate into meeting playback without identity escalation');
$pdo->exec("CREATE TRIGGER fail_evidence BEFORE INSERT ON video_meeting_speaker_evidence BEGIN SELECT RAISE(ABORT,'fixture storage failure'); END;");
rejects(fn()=>video_meeting_transcription_append_v1800($pdo,$meeting,array_merge($input,['source_key'=>str_repeat('3',64)])));
check((int)$pdo->query('SELECT COUNT(*) FROM video_meeting_transcript_segments')->fetchColumn()===2&&!$pdo->inTransaction(),'evidence persistence failure rolls back capture');
$pdo->exec('DROP TRIGGER fail_evidence');
$pdo->exec("UPDATE video_meetings SET status='processed'");
rejects(fn()=>video_meeting_transcription_append_v1800($pdo,$meeting,$input));
check(!$pdo->inTransaction(),'current closed meeting state rejects stale worker snapshot');
$pdo->exec('DELETE FROM video_meeting_transcript_segments WHERE id=1');
check((int)$pdo->query('SELECT COUNT(*) FROM video_meeting_speaker_evidence')->fetchColumn()===1,'capture deletion cascades evidence and corrections');
echo "MEETING_RECORDING_SECTION9=PASS\n";
