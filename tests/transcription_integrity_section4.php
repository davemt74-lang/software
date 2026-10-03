<?php
declare(strict_types=1);
// Execute the canonical service against real SQLite. Only SQL dialect syntax is
// adapted; this checks transactions/replay/ownership, not InnoDB lock behavior.
final class TranscriptSQLite extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace([' FOR UPDATE','INSERT IGNORE','NOW()','GREATEST('],['','INSERT OR IGNORE','CURRENT_TIMESTAMP','MAX('],$query);
        return parent::prepare($query,$options);
    }
}
$connection = new TranscriptSQLite('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO { global $connection;return $connection; }
function table_exists(string $table): bool { return in_array($table,['artist_transcript_sessions_v172','artist_transcript_segments_v172'],true); }
function column_exists(string $table,string $column): bool { return true; }
function user_has_role(string $role,array $user): bool { return $role==='artist'; }
require __DIR__.'/../includes/artist-listening.php';
$connection->exec('CREATE TABLE users(id INTEGER PRIMARY KEY);INSERT INTO users VALUES(1),(2);');
$connection->exec("CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,conversation_id INTEGER,client_session_key TEXT,title TEXT,status TEXT,language TEXT,duration_ms INTEGER DEFAULT 0,metadata_json TEXT,stopped_at TEXT,discarded_at TEXT,last_activity_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,client_session_key));");
$connection->exec("CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(session_id,client_segment_key),UNIQUE(session_id,segment_index));");
$connection->exec("INSERT INTO artist_transcript_sessions_v172(owner_user_id,created_by_user_id,client_session_key,title,status) VALUES(1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','original','active'),(2,2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','foreign','active');");
function check(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function rejects(callable $operation): void {try{$operation();}catch(RuntimeException $error){return;}throw new LogicException('Expected rejection');}
$user=['id'=>1];$first=['key'=>str_repeat('c',32),'type'=>'transcript','text'=>'你好 first','started_ms'=>100,'ended_ms'=>200];$second=['key'=>str_repeat('d',32),'type'=>'transcript','text'=>'second','started_ms'=>10,'ended_ms'=>20];
$initial=artist_listening_v172_append($user,1,[$first,$second]);
check($initial['accepted']===2,'initial append');
check(array_column($initial['session']['segments'],'transcript_text')===['你好 first','second'],'canonical append order');
$replay=artist_listening_v172_append($user,1,[$first,$second]);check($replay['accepted']===0,'active replay must not duplicate');
$third=['key'=>str_repeat('e',32),'text'=>'third'];
$next=artist_listening_v172_append($user,1,[$first,$third]);check(array_column($next['session']['segments'],'segment_index')===[0,1,2],'replayed segment must not consume an index');
echo "PASS canonical order and active lost-ack replay\n";
rejects(fn()=>artist_listening_v172_append($user,1,[array_replace($first,['text'=>'different'])]));
check(count(artist_listening_v172_payload($connection,$user,1)['segments'])===3,'conflicting key modified document');
echo "PASS conflicting segment key is rejected\n";
artist_listening_v172_stop($user,1,500);
check(artist_listening_v172_append($user,1,[$first,$second])['accepted']===0,'completed lost-ack replay');
rejects(fn()=>artist_listening_v172_append($user,1,[['key'=>str_repeat('f',32),'text'=>'new text']]));
rejects(fn()=>artist_listening_v172_append($user,1,[$first,['key'=>str_repeat('f',32),'text'=>'new text']]));
check(count(artist_listening_v172_payload($connection,$user,1)['segments'])===3,'completed transaction changed text');
echo "PASS completed replay cannot reopen or append new text\n";
rejects(fn()=>artist_listening_v172_append($user,2,[$first]));
check(count(artist_listening_v172_payload($connection,['id'=>2],2)['segments'])===0,'foreign document changed');
echo "PASS owner isolation remains enforced\n";
$connection->exec("INSERT INTO artist_transcript_sessions_v172(owner_user_id,created_by_user_id,client_session_key,title,status) VALUES(1,1,'gggggggggggggggggggggggggggggggg','other active','active');");
$start=artist_listening_v172_start($user,str_repeat('a',32),0,'en-US','auto');check((int)$start['id']===1,'start replay retargeted to another active doc');check($start['status']==='draft','start replay reopened completed doc');
echo "PASS original start key resolves its completed document\n";
echo "TRANSCRIPTION_INTEGRITY_SECTION4=PASS (5 SQLite PHP service cases)\n";
