<?php
declare(strict_types=1);
// Canonical service SQL on SQLite with syntax adaptation; not InnoDB certification.
final class ParticipantSQLite extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        $query=str_replace([' FOR UPDATE','NOW()','<=>','DATE_SUB(NOW(), INTERVAL 300 SECOND)'],['','CURRENT_TIMESTAMP',' IS ',"datetime('now','-300 seconds')"],$query);
        $query=str_replace('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 300 SECOND)',"datetime('now','-300 seconds')",$query);
        $query=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(participant_id,provider) DO UPDATE SET',$query);
        $query=preg_replace('/VALUES\((\w+)\)/','excluded.$1',$query);
        return parent::prepare($query,$options);
    }
}
$connection=new ParticipantSQLite('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $connection;return $connection;}
function table_exists(string $table): bool {return true;}
function column_exists(string $table,string $column): bool {return true;}
require __DIR__.'/../includes/studio-participants.php';
require __DIR__.'/../includes/agent-surface-context-v131.php';
$connection->exec("CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER);INSERT INTO chat_conversations VALUES(1,1),(2,2),(3,1);
CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,status TEXT);
INSERT INTO artist_transcript_sessions_v172 VALUES(1,1,1,'active'),(2,2,2,'active'),(3,1,3,'draft');
CREATE TABLE studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,linked_user_id INTEGER,display_name TEXT,relationship_scope TEXT,recognition_consent INTEGER,cloning_consent INTEGER,recognition_scope TEXT,is_active INTEGER,consent_updated_at TEXT);
INSERT INTO studio_participants VALUES(1,1,1,'Owner','self',1,1,'private',1,NULL),(2,1,NULL,'Guest','guest',1,0,'private',1,NULL),(3,2,2,'Foreign','self',1,0,'private',1,NULL);
CREATE TABLE studio_participant_voices(id INTEGER PRIMARY KEY,owner_user_id INTEGER,participant_id INTEGER,provider TEXT,recognition_provider_speaker_id TEXT,clone_provider_voice_id TEXT,source_session_id INTEGER,source_recording_key TEXT,recognition_enabled INTEGER,clone_enabled INTEGER,recognition_verified INTEGER,clone_verified INTEGER,consent_snapshot_at TEXT,revoked_at TEXT,UNIQUE(participant_id,provider));
INSERT INTO studio_participant_voices(owner_user_id,participant_id,provider,recognition_provider_speaker_id,clone_provider_voice_id,recognition_enabled,recognition_verified,clone_enabled,clone_verified) VALUES(1,1,'elevenlabs','voice-a','clone-a',1,1,1,1);
CREATE TABLE studio_session_participants(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,conversation_id INTEGER,transcript_session_id INTEGER,participant_id INTEGER,speaker_label TEXT,recognition_method TEXT,recognition_confidence REAL,provider TEXT,provider_speaker_id TEXT,presence_state TEXT DEFAULT 'present',last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP);");
function check(bool $ok,string $message): void {if(!$ok)throw new LogicException($message);}
function rejects(callable $fn): void {try{$fn();}catch(RuntimeException $e){return;}throw new LogicException('Expected rejection');}
function pass(string $name): void {echo 'PASS '.$name."\n";}
$user=['id'=>1];
$voice=['conversation_id'=>1,'speaker_label'=>'S1','recognition_method'=>'voice','provider_speaker_id'=>'voice-a','confidence'=>.95];
$receipt=studio_participants_record_presence($connection,$user,$voice);check($receipt['recognized'],'voice binding');
check(studio_participants_context($connection,$user,1)['count']===1,'canonical context');pass('current owner binding resolves session-scoped conversational identity');
rejects(fn()=>studio_participants_record_presence($connection,$user,['conversation_id'=>2,'speaker_label'=>'S']));
rejects(fn()=>studio_participants_record_presence($connection,$user,['transcript_session_id'=>2,'speaker_label'=>'S']));
check(!$connection->inTransaction(),'rollback releases transaction');pass('foreign conversation and transcript writes rejected');
rejects(fn()=>studio_participants_record_presence($connection,$user,['conversation_id'=>1,'transcript_session_id'=>3,'speaker_label'=>'S']));
rejects(fn()=>studio_participants_record_presence($connection,$user,['transcript_session_id'=>3,'speaker_label'=>'S']));pass('mismatched and completed transcript presence rejected');
$r=studio_participants_record_presence($connection,$user,array_replace($voice,['speaker_label'=>'Unknown','recognition_method'=>'unknown','participant_id'=>2]));check(!$r['recognized'],'unknown explicit id');
rejects(fn()=>studio_participants_record_presence($connection,$user,['conversation_id'=>1,'participant_id'=>2,'recognition_method'=>'account']));pass('unknown and account claims cannot borrow contact identity');
$connection->exec("INSERT INTO studio_participant_voices(owner_user_id,participant_id,provider,recognition_provider_speaker_id,recognition_enabled,recognition_verified) VALUES(1,2,'elevenlabs','voice-a',1,1)");
check(studio_participants_recognition_match($connection,$user,'voice-a')===null,'ambiguous');
foreach(studio_participants_context($connection,$user,1)['participants'] as $row)check(!$row['recognized'],'stale ambiguous context');
$connection->exec('DELETE FROM studio_participant_voices WHERE participant_id=2');pass('duplicate bindings reject recognition and downgrade existing context');
$connection->exec("UPDATE studio_participant_voices SET recognition_provider_speaker_id='voice-b' WHERE participant_id=1");
foreach(studio_participants_context($connection,$user,1)['participants'] as $row)check(!$row['recognized'],'stale binding');
$connection->exec("UPDATE studio_participant_voices SET recognition_provider_speaker_id='voice-a' WHERE participant_id=1");pass('changed provider binding invalidates old presence');
$r=studio_participants_record_presence($connection,$user,array_replace($voice,['speaker_label'=>'Low','confidence'=>.4]));check(!$r['recognized'],'low confidence');pass('low confidence retains unidentified speaker');
studio_participants_record_presence($connection,$user,array_replace($voice,['presence_state'=>'left']));
check(!array_filter(studio_participants_context($connection,$user,1)['participants'],fn($r)=>$r['speaker_label']==='S1'),'departed');
$r=studio_participants_record_presence($connection,$user,array_replace($voice,['presence_state'=>'left','speaker_label'=>'Never seen']));check($r['id']===0,'no phantom departure');pass('departure is idempotent and never creates phantom presence');
studio_participants_record_presence($connection,$user,$voice);
$connection->exec("UPDATE studio_session_participants SET last_seen_at=datetime('now','-301 seconds')");
check(studio_participants_context($connection,$user,1)['count']===0,'expires');pass('stale presence expires');
studio_participants_record_presence($connection,$user,$voice);
studio_participants_set_consent($connection,$user,['participant_id'=>1,'recognition_consent'=>false,'cloning_consent'=>true]);
foreach(studio_participants_context($connection,$user,1)['participants'] as $row)check(!$row['recognized'],'revoked');pass('consent revocation invalidates cached voice presence');
$connection->exec("UPDATE studio_participants SET recognition_consent=1 WHERE id=1;UPDATE studio_participant_voices SET recognition_provider_speaker_id='voice-a',recognition_enabled=1,recognition_verified=1 WHERE participant_id=1;");
studio_participants_bind_voice($connection,$user,1,['recognition_provider_speaker_id'=>'replacement']);
check(!(bool)$connection->query('SELECT recognition_verified FROM studio_participant_voices WHERE participant_id=1')->fetchColumn(),'verification inheritance');
check($connection->query('SELECT clone_provider_voice_id FROM studio_participant_voices WHERE participant_id=1')->fetchColumn()==='clone-a','independent clone');pass('binding changes reset verification and preserve independent clone');
$enriched=agent_surface_v131_enrich($user,'chat',['conversation_id'=>1,'participants'=>['participants'=>[['participant_id'=>3,'name'=>'Forged','recognized'=>true]]]]);
check(!str_contains(json_encode($enriched['participants']),'Forged'),'forged browser context');check($enriched['participants']['authentication_authority']===false,'non-auth');
$connection->exec('UPDATE studio_participants SET is_active=0 WHERE id=1');
foreach(studio_participants_context($connection,$user,1)['participants'] as $row)check(!$row['recognized'],'inactive profile');pass('Agent context is server-resolved and inactive profiles fail closed');
echo "PARTICIPANTS_INTEGRITY_SECTION5=PASS (12 canonical SQLite PHP cases)\n";
