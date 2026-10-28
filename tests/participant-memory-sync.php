<?php
declare(strict_types=1);
$file=getenv('VP3_PARTICIPANT_TEST_DB')?:':memory:';
$pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{$q=db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
function column_exists(string $table,string $column):bool{return true;}
function has_permission(string $permission,?array $user=null):bool{return empty($GLOBALS['deny']);}
class HomeServerHttpsSessionError extends RuntimeException{}
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
function agent_brain_v122_memory(array $user,string $type,string $subject):?array{
    $q=db()->prepare('SELECT metadata FROM notes WHERE owner=? AND agent=? AND subject=?');$q->execute([$user['id'],vp3_agent_memory_scope_current_v410($user),$subject]);$row=$q->fetchColumn();return $row?['metadata'=>json_decode($row,true)]:null;
}
function agent_brain_v122_upsert_system_memory(array $user,string $type,string $subject,string $text,array $metadata=[],float $confidence=.95):int{
    if(!empty($GLOBALS['failMemory']))return 0;
    db()->prepare('INSERT INTO notes(owner,agent,subject,metadata) VALUES(?,?,?,?) ON CONFLICT(owner,agent,subject) DO UPDATE SET metadata=excluded.metadata')->execute([$user['id'],vp3_agent_memory_scope_current_v410($user),$subject,json_encode($metadata)]);return 1;
}
require dirname(__DIR__).'/includes/homeserver-workspace-sync-v1.php';
$session=['user_id'=>1,'device_id'=>'hs-test','session_token_hash'=>hash('sha256',str_repeat('a',64)),'status'=>'active'];
if(!table_exists('users')){
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id TEXT,session_token_hash TEXT,status TEXT);
    CREATE TABLE studio_participants(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,profile_key TEXT,display_name TEXT,relationship_scope TEXT DEFAULT 'guest',is_active INTEGER DEFAULT 1,recognition_consent INTEGER DEFAULT 0,cloning_consent INTEGER DEFAULT 0,linked_user_id INTEGER,UNIQUE(owner_user_id,profile_key));
    CREATE TABLE studio_participant_voices(id INTEGER PRIMARY KEY);CREATE TABLE studio_session_participants(id INTEGER PRIMARY KEY);
    CREATE TABLE notes(id INTEGER PRIMARY KEY,owner INTEGER,agent INTEGER,subject TEXT,metadata TEXT,UNIQUE(owner,agent,subject));
    INSERT INTO studio_participants(owner_user_id,profile_key,display_name,relationship_scope) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Owner','self'),(2,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Other account','guest');");
    $pdo->prepare('INSERT INTO homeserver_https_sessions VALUES(?,?,?,?)')->execute(array_values($session));
}
if(PHP_SAPI==='cli-server'){
    header('Content-Type: application/json');
    try{
        if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer '.str_repeat('a',64)||($_SERVER['HTTP_X_HOMESERVER_DEVICE']??'')!=='hs-test')throw new RuntimeException('Unauthorized',401);
        $body=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        echo json_encode(workspace_sync_exchange_v1($session,$body),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){http_response_code(in_array($e->getCode(),[401,403,409,410],true)?$e->getCode():422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    return;
}
$checks=0;function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
function rejects(callable $fn,string $name):void{try{$fn();}catch(Throwable $e){check(true,$name);return;}throw new LogicException($name);}
function exchange(array $body=[]):array{return workspace_sync_exchange_v1($GLOBALS['session'],['action'=>'participants']+$body);}
function edit(string $key,string $rev,string $name,string $note='',bool $deleted=false):array{return ['key'=>$key,'expected_revision'=>$rev,'name'=>$name,'note'=>$note,'deleted'=>$deleted,'change_id'=>bin2hex(random_bytes(16))];}
$first=exchange();check(count($first['participants'])===1&&$first['participants'][0]['name']==='Owner','only paired owner participant names export');
$key='cccccccccccccccccccccccccccccccc';$change=edit($key,'','Alex','Prefers a short follow-up');
$made=exchange(['changes'=>[$change]]);$r=$made['results'][0]['participant'];
check($made['results'][0]['status']==='applied'&&$r['note']==='Prefers a short follow-up','new stable-key participant and explicit note persist together');
check(count(exchange(['changes'=>[$change]])['participants'])===2,'lost creation receipt retries without duplicate profiles');
check(!array_intersect(array_keys($r),['embeddings','voiceEmbeddings','recognition_consent','linked_user_id']),'export contains text and stable references only');
vp3_participant_save_details($pdo,['id'=>1],['participant_id'=>$r['id'],'display_name'=>'Alex updated in Cloud','conversation_note'=>'Cloud note']);
$conflict=exchange(['changes'=>[edit($key,$r['revision'],'Offline Alex','Local note')]])['results'][0];
check($conflict['status']==='conflict'&&$conflict['participant']['note']==='Cloud note','stale offline change preserves current Cloud details');
$new=exchange(['changes'=>[edit($key,$conflict['participant']['revision'],'Offline Alex','Local note')]])['results'][0]['participant'];
check($new['name']==='Offline Alex'&&$new['note']==='Local note','explicit resolution uses the reviewed current revision');
check(exchange(['changes'=>[edit($key,$r['revision'],'Alex','Old replay')]])['results'][0]['status']==='conflict','older replay cannot overwrite a subsequent edit');
$clear=exchange(['changes'=>[edit($key,$new['revision'],$new['name'],'')]])['results'][0]['participant'];check($clear['note']==='','note clearing synchronizes as an empty value');
$delete=exchange(['changes'=>[edit($key,$clear['revision'],'','',true)]])['results'][0]['participant'];check($delete['deleted']&&$delete['note']===''&&$delete['name']==='','deletion is retained as a text-free tombstone');
check(exchange(['changes'=>[edit($key,$clear['revision'],'Bring it back')]])['results'][0]['status']==='conflict','stale edits cannot resurrect a removed participant');
$early='ffffffffffffffffffffffffffffffff';exchange(['changes'=>[edit($early,'','','',true)]]);
check(exchange(['changes'=>[edit($early,'','Late creation')]])['results'][0]['status']==='conflict','deletion arriving before creation still prevents resurrection');
$self=$first['participants'][0];check(exchange(['changes'=>[edit($self['key'],$self['revision'],'','',true)]])['results'][0]['status']==='protected','Cloud account self profile cannot be deleted through sync');
$foreign=exchange(['keys'=>['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']]);check($foreign['participants'][count($foreign['participants'])-1]['deleted'],'foreign owner profile key reveals no name or note');
$invalid=edit('dddddddddddddddddddddddddddddddd','','No upload');$invalid['embeddings']=[[1,2,3]];rejects(fn()=>exchange(['changes'=>[$invalid]]),'recognition sample fields rejected');
$GLOBALS['failMemory']=true;rejects(fn()=>exchange(['changes'=>[edit('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','','Rollback')]]),'memory failure rejects the complete transaction');$GLOBALS['failMemory']=false;
check(!(bool)$pdo->query("SELECT COUNT(*) FROM studio_participants WHERE display_name='Rollback'")->fetchColumn(),'failed memory write leaves no new participant');
for($i=0;$i<105;$i++)$pdo->prepare('INSERT INTO studio_participants(owner_user_id,profile_key,display_name) VALUES(1,?,?)')->execute([str_pad((string)$i,32,'0',STR_PAD_LEFT),'Repeated name']);
$page=exchange();$next=exchange(['after'=>$page['next_after']]);
check(count($page['participants'])===100&&$page['next_after']>0&&count($next['participants'])<100&&$next['next_after']===0,'bounded cursor pages retain separate duplicate-name records');
$GLOBALS['deny']=true;rejects(fn()=>exchange(),'current Chat access is required');$GLOBALS['deny']=false;
$pdo->exec("UPDATE homeserver_https_sessions SET status='revoked'");rejects(fn()=>exchange(),'revoked pairing cannot access participant text');
echo "PARTICIPANT_MEMORY_SYNC=PASS ($checks cases)\n";
