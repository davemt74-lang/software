<?php
declare(strict_types=1);

$connection=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $connection;return $connection;}
function table_exists(string $table): bool {return in_array($table,['artist_transcript_sessions_v172','artist_transcript_segments_v172'],true);}
function current_user(): array {return ['id'=>1];}

require __DIR__.'/../includes/agent-surface-context-v131.php';

$connection->exec("CREATE TABLE artist_transcript_sessions_v172(
 id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,title TEXT,status TEXT,
 duration_ms INTEGER,knowledge_id INTEGER,metadata_json TEXT,last_activity_at TEXT
);
CREATE TABLE artist_transcript_segments_v172(
 id INTEGER PRIMARY KEY,session_id INTEGER,segment_type TEXT,transcript_text TEXT
);");
$insert=$connection->prepare("INSERT INTO artist_transcript_sessions_v172 VALUES(?,?,?,?,?,?,?,?,?)");
$insert->execute([1,1,11,'Current room notes','active',3200,null,'{}','2026-10-04 10:00:00']);
$insert->execute([2,1,12,'Imported planning','draft',4800,91,json_encode(['homeserver_import_v1'=>['source_hash'=>str_repeat('a',64)]]),'2026-10-04 09:00:00']);
$insert->execute([3,2,11,'Foreign transcript','active',1000,null,'{}','2026-10-04 11:00:00']);
$insert->execute([4,1,13,'Discarded transcript','discarded',1000,null,'{}','2026-10-04 11:00:00']);
$segment=$connection->prepare("INSERT INTO artist_transcript_segments_v172(session_id,segment_type,transcript_text) VALUES(?,?,?)");
$segment->execute([1,'transcript','private words must not enter surface state']);
$segment->execute([1,'transcript','second segment']);
$segment->execute([2,'transcript','imported private words']);

function check(bool $ok,string $message): void {if(!$ok)throw new LogicException($message);}
function pass(string $name): void {echo "PASS {$name}\n";}

$forged=agent_surface_v131_sanitize([
    'surface'=>'chat','conversation_id'=>11,
    'transcription'=>['session_id'=>999,'title'=>'Forged','status'=>'active','duration_ms'=>999999,'source'=>'cloud_transcription'],
]);
check($forged['transcription']===null,'browser transcription survived sanitizer');
pass('browser cannot author transcription state');

$current=agent_surface_v131_transcription($connection,['id'=>1],11);
check(is_array($current)&&$current['session_id']===1,'active transcript missing');
check($current['segment_count']===2&&$current['source']==='cloud_transcription','active transcript metadata wrong');
check($current['speaker_identity_authority']===false,'transcript became identity authority');
pass('server resolves bounded active transcript state');

$imported=agent_surface_v131_transcription($connection,['id'=>1],12);
check(is_array($imported)&&$imported['source']==='homeserver_import','HomeServer source not identified');
check($imported['cloud_copy_is_independent']===true&&$imported['knowledge_promoted']===true,'import boundary missing');
pass('independent HomeServer import state and Knowledge promotion are explicit');

check(agent_surface_v131_transcription($connection,['id'=>1],13)===null,'discarded transcript exposed');
check(agent_surface_v131_transcription($connection,['id'=>1],99)===null,'unrelated transcript exposed');
check(agent_surface_v131_transcription($connection,['id'=>2],11)['session_id']===3,'owner boundary setup failed');
pass('discarded and unrelated transcript state fail closed');

$item=agent_surface_v131_context_item([
    'surface'=>'chat','conversation_id'=>11,
    'transcription'=>['session_id'=>999,'title'=>'Forged','status'=>'active'],
]);
$text=(string)$item['text'];
check(str_contains($text,'"session_id":1'),'context item did not re-resolve canonical transcript');
check(!str_contains($text,'Forged'),'forged transcript survived context item');
check(!str_contains($text,'private words must not enter surface state'),'raw transcript text leaked into surface context');
check(str_contains($text,'speaker_identity_authority":false'),'identity authority boundary missing');
pass('Agent context carries state provenance without raw transcript text');

$connection->exec("UPDATE artist_transcript_sessions_v172 SET status='discarded' WHERE id=1");
$item=agent_surface_v131_context_item(['surface'=>'chat','conversation_id'=>11]);
check(!str_contains((string)$item['text'],'Current room notes'),'discarded state remained in Agent context');
pass('discard immediately removes transcript state from Agent context');

echo "AGENT_CONTEXT_SECTION8=PASS (5 canonical Cloud cases)\n";
