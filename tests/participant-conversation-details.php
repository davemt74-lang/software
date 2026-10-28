<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER,recognition_consent INTEGER,cloning_consent INTEGER,linked_user_id INTEGER,relationship_scope TEXT);
INSERT INTO studio_participants VALUES(7,1,'David',1,1,1,101,'contact'),(8,2,'Other owner',1,1,0,102,'contact'),(9,1,'Inactive',0,1,0,NULL,'guest');
CREATE TABLE notes(id INTEGER PRIMARY KEY AUTOINCREMENT,owner INTEGER,agent INTEGER,subject TEXT,text TEXT,metadata TEXT,UNIQUE(owner,agent,subject))");
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
function agent_brain_v122_memory(array $user,string $type,string $subject):?array{
    $q=$GLOBALS['pdo']->prepare('SELECT * FROM notes WHERE owner=? AND agent=? AND subject=?');$q->execute([$user['id'],vp3_agent_memory_scope_current_v410($user),$subject]);$p=$q->fetch();return $p?['metadata'=>json_decode($p['metadata'],true)]:null;
}
function agent_brain_v122_upsert_system_memory(array $user,string $type,string $subject,string $text,array $metadata=[],float $confidence=.95):int{
    if(!empty($GLOBALS['failMemory']))return 0;
    $q=$GLOBALS['pdo']->prepare('INSERT INTO notes(owner,agent,subject,text,metadata) VALUES(?,?,?,?,?) ON CONFLICT(owner,agent,subject) DO UPDATE SET text=excluded.text,metadata=excluded.metadata');$q->execute([$user['id'],vp3_agent_memory_scope_current_v410($user),$subject,$text,json_encode($metadata)]);return 1;
}
require dirname(__DIR__).'/includes/participant-conversation-details.php';
require dirname(__DIR__).'/includes/voice-speaker-context.php';
$checks=0;function check(bool $ok,string $name):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $fn,string $name):void{try{$fn();}catch(RuntimeException $e){check(true,$name);return;}throw new LogicException($name);}
$user=['id'=>1];vp3_agent_memory_scope_set_current_v410(1,42);
$before=$pdo->query('SELECT * FROM studio_participants WHERE id=7')->fetch();
$saved=vp3_participant_save_details($pdo,$user,['participant_id'=>7,'display_name'=>'David Evans','conversation_note'=>'Prefers brief answers']);
check($saved['name']==='David Evans'&&$saved['note']==='Prefers brief answers','explicit name and note persist on one participant');
$after=$pdo->query('SELECT * FROM studio_participants WHERE id=7')->fetch();unset($before['display_name'],$after['display_name']);check($before===$after,'linked account relationship and both consents stay unchanged');
check(vp3_agent_memory_scope_current_v410($user)===42,'participant memory lookup restores caller agent scope');
$details=vp3_participant_conversation_details($pdo,$user,7);check($details['note']==='Prefers brief answers','reloaded details return saved canonical note');
$voice=vp3_voice_speaker_context($pdo,$user,['participant_id'=>7,'source'=>'local_voice_similarity','confidence'=>.95,'display_name'=>'Forged','conversation_note'=>'Forged','authentication_authority'=>false]);check($voice['display_name']==='David Evans'&&$voice['conversation_note']==='Prefers brief answers','Chat resolves name and note from owned server records');
rejects(fn()=>vp3_participant_save_details($pdo,$user,['participant_id'=>8,'display_name'=>'Wrong','conversation_note'=>'wrong']),'foreign participant cannot be renamed or given a note');
rejects(fn()=>vp3_participant_conversation_details($pdo,$user,8),'foreign participant details cannot be read');
rejects(fn()=>vp3_participant_save_details($pdo,$user,['participant_id'=>9,'display_name'=>'Inactive']),'inactive participant cannot be changed');
rejects(fn()=>vp3_participant_save_details($pdo,$user,['participant_id'=>7,'display_name'=>'David','conversation_note'=>str_repeat('x',1001)]),'oversized note rejected before writes');
$GLOBALS['failMemory']=true;rejects(fn()=>vp3_participant_save_details($pdo,$user,['participant_id'=>7,'display_name'=>'Bad rename','conversation_note'=>'lost']),'memory failure rolls back the name update');$GLOBALS['failMemory']=false;
check($pdo->query('SELECT display_name FROM studio_participants WHERE id=7')->fetchColumn()==='David Evans','failed memory update does not partially rename');
check(vp3_agent_memory_scope_current_v410($user)===42,'failed memory update restores caller scope');
vp3_participant_save_details($pdo,$user,['participant_id'=>7,'display_name'=>'David Evans','conversation_note'=>'']);check(vp3_participant_conversation_details($pdo,$user,7)['note']==='','forget note removes the note from subsequent Chat context');
$p=vp3_voice_speaker_context($pdo,$user,['participant_id'=>7,'source'=>'local_voice_similarity','confidence'=>.95]);check($p['conversation_note']==='','old participant note cannot return after clearing');
$pdo->exec('UPDATE studio_participants SET recognition_consent=0 WHERE id=7');
$manual=vp3_voice_speaker_context($pdo,$user,['participant_id'=>7,'source'=>'manual_selection','display_name'=>'Forged','conversation_note'=>'Forged']);
check($manual['display_name']==='David Evans'&&$manual['confidence']===null&&$manual['source']==='owner_selected_participant','manual typed selection uses canonical records without pretending to be biometric evidence');
check(vp3_voice_speaker_context($pdo,$user,['participant_id'=>8,'source'=>'manual_selection'])===null,'manual selection cannot reach another owner');
check(vp3_voice_speaker_context($pdo,$user,['participant_id'=>7,'source'=>'manual_selection','authentication_authority'=>true])===null,'manual selection grants no account authority');
echo "PARTICIPANT_CONVERSATION_DETAILS=PASS ($checks cases)\n";
