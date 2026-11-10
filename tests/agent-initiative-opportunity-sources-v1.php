<?php
declare(strict_types=1);

/** Actual source readers, canonical Agent/Canvas authorities and Player history. */
class OpportunityReadPDO extends PDO
{
    public bool $readOnly=false;
    public int $writeAttempts=0;
    public int $sensitiveReads=0;
    private function inspect(string $sql): void
    {
        if(!$this->readOnly)return;
        if(!preg_match('/\A\s*(?:SELECT|PRAGMA)\b/i',$sql)){$this->writeAttempts++;throw new RuntimeException('Opportunity GET attempted a write.');}
        if(preg_match('/\b(?:content_text|state_content|app_data)\b/i',$sql)){$this->sensitiveReads++;throw new RuntimeException('Opportunity metadata selected private Canvas content.');}
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->inspect($query);return parent::prepare($query,$options);}
    public function exec(string $statement): int|false {$this->inspect($statement);return parent::exec($statement);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {$this->inspect($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
}
function db(): PDO{return $GLOBALS['opportunity_pdo'];}
function url(string $path=''): string{return '/vp3'.$path;}
function setting(string $key,mixed $default=null): mixed{return $default;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$GLOBALS['opportunity_denied']??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function personal_knowledge_available(?array $user=null): bool{return $user!==null&&personal_capability_has_v242('personal_knowledge.access',$user)&&table_exists('knowledge_items');}
function table_exists(string $table): bool
{
    try{db()->query('SELECT 1 FROM '.$table.' WHERE 1=0');return true;}catch(PDOException){return false;}
}
function column_exists(string $table,string $column): bool
{
    try{db()->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');return true;}catch(PDOException){return false;}
}
// Only the account media permission gateway is a fixture. History aggregation,
// owner scoping, exact selected lookup and the real media adapter remain shipped code.
function get_track_by_id(int $id): ?array
{
    $q=db()->prepare('SELECT id,owner_user_id,title,album,visibility,is_published FROM tracks WHERE id=? AND is_published=1');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function can_manage_track_production(array $track,?array $user): bool{return $user!==null&&(int)$track['owner_user_id']===(int)$user['id'];}
function can_view_track(array $track,?array $user=null): bool{return ($track['visibility']??'')==='public'||can_manage_track_production($track,$user);}

require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-initiative-opportunity-sources-v1.php';

$dsn=getenv('VP3_TEST_MYSQL_DSN')?:'sqlite::memory:';$mysql=str_starts_with($dsn,'mysql:');
$pdo=$GLOBALS['opportunity_pdo']=new OpportunityReadPDO($dsn,$mysql?getenv('VP3_TEST_MYSQL_USER'):null,$mysql?getenv('VP3_TEST_MYSQL_PASS'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($mysql){
    $database='opportunity_sources_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.$database);
    register_shutdown_function(static function()use($pdo,$database):void{$pdo->readOnly=false;$pdo->exec('DROP DATABASE '.$database);});
}
$key=$mysql?'INT UNSIGNED PRIMARY KEY':'INTEGER PRIMARY KEY';$big=$mysql?'BIGINT UNSIGNED PRIMARY KEY':'INTEGER PRIMARY KEY';
$pdo->exec("CREATE TABLE users(id {$key},is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(7,1,1,'Longfellow'),(8,1,1,'Second Agent'),(9,2,1,'Foreign Agent');
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title TEXT,updated_at TEXT);
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT);
    CREATE TABLE artist_transcript_folders_v177(id {$big},created_by_user_id INTEGER);
    CREATE TABLE knowledge_items(id {$key},folder_id INTEGER,created_by_user_id INTEGER,knowledge_scope TEXT,visibility TEXT,is_published INTEGER,content_text TEXT);
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE tracks(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,album TEXT,visibility TEXT,is_published INTEGER);
    CREATE TABLE track_play_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,track_id INTEGER,started_at TEXT,last_position_seconds REAL,duration_seconds REAL,listened_seconds REAL,qualified_play INTEGER,completed INTEGER)");
vp3_coding_canvas_schema_v1($pdo);
$chat=$pdo->prepare('INSERT INTO chat_conversations VALUES(?,?,?,?,?)');
$message=$pdo->prepare('INSERT INTO chat_messages VALUES(?,?,?,?,?,?,?)');
foreach([[101,1,7],[102,1,7],[103,1,7],[104,1,7],[105,1,7],[106,1,7],[107,1,7],[201,1,null],[202,1,8],[203,2,9]] as [$cid,$owner,$agent]){
    $chat->execute([$cid,$owner,$agent,'Project '.$cid,'2026-11-10 01:00:00']);
    $context=$cid===106?' {"shared":true}':($cid===107?'invalid-json':'{}');
    $message->execute([$cid*10,$cid,$owner,'user','OWNER_CONTENT_'.$cid,$context,'2026-11-10 01:00:00']);
    $message->execute([$cid*10+1,$cid,null,'assistant','AGENT_SELF_REPORT_'.$cid,$cid===105?'{"participant_tasks_v1":{"participant_id":99}}':'{}','2026-11-10 01:00:01']);
}
for($i=1;$i<=15;$i++)$message->execute([3000+$i,104,1,'user',str_repeat('Long owner statement '.$i.' ',40),'{}','2026-11-10 02:00:00']);
$message->execute([3100,104,2,'user','FOREIGN_USER_IN_OWNER_CHAT','{}','2026-11-10 02:01:00']);
$message->execute([3101,104,1,'user','EXPLICIT_PARTICIPANT_CONTEXT','{"agent_context":{"voice_speaker":{"participant_id":99}}}','2026-11-10 02:01:01']);
$message->execute([3102,104,1,'user','SHARED_CONTEXT_CONTENT','{"shared_context":{"id":5}}','2026-11-10 02:01:02']);
$message->execute([3103,104,1,'user','DO_NOT_ARCHIVE_PRIVATE_QUERY','{"skip_brain_archive":true}','2026-11-10 02:01:03']);
$uuid=static fn(int $id):string=>sprintf('123e4567-e89b-42d3-a456-%012d',$id);
$canvas=$pdo->prepare('INSERT INTO vp3_coding_canvases_v1(id,owner_user_id,agent_id,conversation_id,folder_id,title,state_item_id,state_version,active_revision,draft_revision,updated_at) VALUES(?,?,?,?,?,?,?,1,?,?,?)');
$folder=$pdo->prepare('INSERT INTO artist_transcript_folders_v177 VALUES(?,?)');
$item=$pdo->prepare("INSERT INTO knowledge_items VALUES(?,?,?,'personal','private',0,?)");
$version=$pdo->prepare('INSERT INTO vp3_coding_canvas_versions_v1(canvas_id,revision,code_item_id,artifact_hash) VALUES(?,1,?,?)');
for($id=1;$id<=8;$id++){
    $owner=$id===8?2:1;$agent=$id===7?8:($id===8?9:7);$cid=$id===7?202:($id===8?203:101);
    $folder->execute([$id,$id===6?2:$owner]);$item->execute([$id*2-1,$id,$owner,'SECRET_APP_DATA_'.$id]);
    $item->execute([$id*2,$id,$id===5?2:$owner,'SECRET_CODE_'.$id]);
    $canvas->execute([$uuid($id),$owner,$agent,$cid,$id,'Canvas '.$id,$id*2-1,$id===1?1:null,$id===1?null:1,'2026-11-10 0'.$id.':00:00']);
    $version->execute([$uuid($id),$id*2,str_repeat((string)$id,64)]);
}
$track=$pdo->prepare("INSERT INTO tracks VALUES(?,?,?,'Album','public',1)");
$play=$pdo->prepare('INSERT INTO track_play_sessions VALUES(?,?,?,?,?,?,?,?,?)');
for($id=1;$id<=210;$id++){
    $track->execute([$id,1,'Track '.$id]);$play->execute([$id,1,$id,sprintf('2026-11-%02d %02d:%02d:00',1+intdiv($id,1440),intdiv($id%1440,60),$id%60),10,180,30,1,0]);
}
$play->execute([300,1,1,'2026-11-01 00:01:30',23,180,50,1,0]);
$play->execute([301,2,1,'2026-11-10 12:00:00',170,180,9000,1,1]);
$track->execute([999,2,'FOREIGN_UNPLAYED_TRACK']);$play->execute([302,2,999,'2026-11-10 13:00:00',10,180,40,1,0]);
$pdo->exec("UPDATE tracks SET owner_user_id=2,visibility='private' WHERE id=210");

$user=['id'=>1,'is_active'=>1];$now=new DateTimeImmutable('2026-11-10T12:00:00Z');
$principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
$checks=0;
function opportunity_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function opportunity_read(?array $refs=null,?array $p=null,?array $u=null,?DateTimeImmutable $at=null): array
{
    global $pdo,$user,$principal,$now;$coverage=[];$pdo->readOnly=true;
    try{$rows=vp3_initiative_opportunity_sources_v1($pdo,$u??$user,$p??$principal,$at??$now,$refs,$coverage);}
    finally{$pdo->readOnly=false;}
    return [$rows,$coverage];
}
function opportunity_reject(callable $work): void
{
    try{$work();}catch(Throwable){opportunity_check(true,'Rejected');return;}throw new RuntimeException('Expected opportunity scope/reference rejection.');
}
[$rows,$coverage]=opportunity_read();
opportunity_check($coverage===['chat_history'=>'ready','canvas_work'=>'ready','song_history'=>'ready'],'Canonical sources were not all ready: '.json_encode($coverage));
foreach(['chat_history','canvas_work','song_history'] as $kind)opportunity_check(count(array_filter($rows,static fn(array $row):bool=>$row['kind']===$kind))===3,'Discovery did not bound '.$kind.' to three.');
$encoded=json_encode($rows,JSON_THROW_ON_ERROR);
foreach(['SECRET_CODE','SECRET_APP_DATA','OWNER_CONTENT_105','OWNER_CONTENT_106','OWNER_CONTENT_107','OWNER_CONTENT_201','OWNER_CONTENT_202','OWNER_CONTENT_203','AGENT_SELF_REPORT','FOREIGN_USER_IN_OWNER_CHAT','EXPLICIT_PARTICIPANT_CONTEXT','SHARED_CONTEXT_CONTENT','DO_NOT_ARCHIVE_PRIVATE_QUERY','FOREIGN_UNPLAYED_TRACK','Track 210'] as $secret)opportunity_check(!str_contains($encoded,$secret),'Disclosed '.$secret);
$snippets=explode("\n",$rows['chat-history:104']['detail']);array_shift($snippets);
opportunity_check(count($snippets)===3&&max(array_map('mb_strlen',$snippets))<=500,'Chat owner snippets exceeded the 3 x 500 bound.');
foreach(['chat-history:101','canvas-work:'.$uuid(1),'song-history:1'] as $ref)opportunity_check(!isset($rows[$ref]),'Older fixture unexpectedly entered discovery: '.$ref);

$older=['chat-history:101','canvas-work:'.$uuid(1),'song-history:1'];
[$selected,$selectedCoverage]=opportunity_read($older);
opportunity_check(count($selected)===3&&$selectedCoverage===$coverage,'Exact older references did not rehydrate outside all discovery windows.');
[$same]=opportunity_read(array_keys($rows));
foreach($rows as $ref=>$row)opportunity_check(($same[$ref]['revision']??null)===$row['revision'],'Discovery/selected revisions disagree for '.$ref);
opportunity_check(str_contains($selected['song-history:1']['detail'],'2 sessions, 80 recorded listening seconds')&&str_contains($selected['song-history:1']['detail'],'0:23'),'Selected old track lost owner-scoped canonical aggregates or resume position.');
[$later]=opportunity_read($older,null,null,$now->modify('+2 days'));
opportunity_check(array_column($later,'revision','ref')===array_column($selected,'revision','ref'),'Polling time invented a source revision.');
$message->execute([5000,101,null,'assistant','New self-reported preparation','{"source":"agent_initiative"}','2026-11-10 12:00:00']);
$pdo->exec("UPDATE chat_conversations SET updated_at='2026-12-01' WHERE id=101");
[$afterReport]=opportunity_read($older);
opportunity_check($afterReport['chat-history:101']['revision']===$selected['chat-history:101']['revision'],'Assistant self-report or conversation activity revived unchanged owner history.');
$pdo->exec("UPDATE chat_messages SET message='Owner changed the goal' WHERE id=1010");
[$changed]=opportunity_read($older);
opportunity_check($changed['chat-history:101']['revision']!==$selected['chat-history:101']['revision'],'Owner history edit did not revise its source.');
$pdo->exec("UPDATE knowledge_items SET content_text='REPLACED_SECRET_CODE' WHERE id=2");
[$metadataOnly]=opportunity_read($older);
opportunity_check($metadataOnly['canvas-work:'.$uuid(1)]['revision']===$selected['canvas-work:'.$uuid(1)]['revision'],'Metadata evidence read or depended on Canvas code/data content.');
$pdo->exec("UPDATE vp3_coding_canvases_v1 SET state_version=2 WHERE id='".$uuid(1)."'");
[$changed]=opportunity_read($older);
opportunity_check($changed['canvas-work:'.$uuid(1)]['revision']!==$selected['canvas-work:'.$uuid(1)]['revision'],'Canonical Canvas state revision did not update the source.');
$pdo->exec('UPDATE track_play_sessions SET listened_seconds=55 WHERE id=300');
[$changed]=opportunity_read($older);
opportunity_check($changed['song-history:1']['revision']!==$selected['song-history:1']['revision'],'Canonical listening change did not update the source.');

foreach([['chat_conversations','user_agent_id',8,'id=101','chat-history:101'],['knowledge_items','visibility',"'public'",'id=2','canvas-work:'.$uuid(1)],
    ['artist_transcript_folders_v177','created_by_user_id',2,'id=1','canvas-work:'.$uuid(1)],['knowledge_items','is_published',1,'id=1','canvas-work:'.$uuid(1)],
    ['tracks','owner_user_id',2,'id=1','song-history:1']] as [$table,$column,$value,$where,$ref]){
    $old=$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE '.$where)->fetchColumn();
    $pdo->exec('UPDATE '.$table.' SET '.$column.'='.$value.' WHERE '.$where);
    if($table==='tracks')$pdo->exec("UPDATE tracks SET visibility='private' WHERE id=1");
    [$revoked]=opportunity_read([$ref]);opportunity_check(!isset($revoked[$ref]),'Changed ownership/privacy retained '.$ref);
    $pdo->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE '.$where)->execute([$old]);
    if($table==='tracks')$pdo->exec("UPDATE tracks SET visibility='public' WHERE id=1");
}
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','2','deny')");
[$denied]=opportunity_read($older);opportunity_check(!isset($denied['canvas-work:'.$uuid(1)])&&isset($denied['chat-history:101'],$denied['song-history:1']),'Named Agent document deny leaked Canvas or destroyed unrelated evidence.');
$pdo->exec("DELETE FROM user_agent_data_rules;INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
[$denied,$deniedCoverage]=opportunity_read($older);opportunity_check(!isset($denied['song-history:1'])&&$deniedCoverage['song_history']==='forbidden'&&isset($denied['chat-history:101']),'Playback policy deny was ignored.');
$pdo->exec('DELETE FROM user_agent_data_rules');
$GLOBALS['opportunity_denied']=['personal_knowledge.access'];
[$denied,$deniedCoverage]=opportunity_read($older);opportunity_check(!isset($denied['canvas-work:'.$uuid(1)])&&$deniedCoverage['canvas_work']==='forbidden'&&isset($denied['chat-history:101'],$denied['song-history:1']),'Knowledge permission revocation leaked Canvas or suppressed independent sources.');
$GLOBALS['opportunity_denied']=[];
$otherAgent=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,8));
[$other]=opportunity_read($older,$otherAgent);opportunity_check(array_keys($other)===['song-history:1']&&$other['song-history:1']['revision']===$changed['song-history:1']['revision'],'Agent scope crossed private Chat/Canvas boundaries or split owner-wide Player history.');
$foreignUser=['id'=>2,'is_active'=>1];$foreignPrincipal=vp3_agent_chat_principal_v380($foreignUser,vp3_agent_chat_resolve_agent_v380($pdo,$foreignUser,9));
[$foreign]=opportunity_read($older,$foreignPrincipal,$foreignUser);
opportunity_check(array_keys($foreign)===['song-history:1']&&str_contains($foreign['song-history:1']['detail'],'1 sessions, 9000 recorded listening seconds'),'A valid foreign owner received private Chat/Canvas context or another owner’s listening aggregates.');
$system=vp3_agent_chat_principal_v380($user,null);[$systemRows]=opportunity_read(null,$system);
opportunity_check(isset($systemRows['chat-history:201'])&&!isset($systemRows['chat-history:101']),'System inherited a named Agent conversation.');
foreach([['kind'=>'profile_agent','agent_id'=>7,'owner_user_id'=>1],['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>2],['kind'=>'system','agent_id'=>0,'owner_user_id'=>2],
    ['kind'=>'system','agent_id'=>7],['kind'=>'user_agent','agent_id'=>'7','owner_user_id'=>1],array_replace($principal,['viewer_user_id'=>2])] as $bad)opportunity_reject(static fn()=>opportunity_read($older,$bad));
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1];opportunity_reject(static fn()=>opportunity_read($older));unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');opportunity_reject(static fn()=>opportunity_read($older));$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');opportunity_reject(static fn()=>opportunity_read($older));$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
foreach(['account.access','chat.access'] as $permission){$GLOBALS['opportunity_denied']=[$permission];opportunity_reject(static fn()=>opportunity_read($older));}$GLOBALS['opportunity_denied']=[];
foreach([['chat-history:0'],['song-history:1 OR 1=1'],['canvas-work:../private'],array_map(static fn(int $id):string=>'chat-history:'.$id,range(1,33))] as $bad)opportunity_reject(static fn()=>opportunity_read($bad));
foreach([['DELETE FROM chat_conversations WHERE id=101','chat-history:101'],['DELETE FROM knowledge_items WHERE id=2','canvas-work:'.$uuid(1)],['DELETE FROM track_play_sessions WHERE user_id=1 AND track_id=1','song-history:1']] as [$sql,$ref]){
    $pdo->exec($sql);[$deleted]=opportunity_read([$ref]);opportunity_check(!isset($deleted[$ref]),'Deleted source rehydrated '.$ref);
}
$pdo->exec('DROP TABLE track_play_sessions');[$partial,$partialCoverage]=opportunity_read();
opportunity_check($partialCoverage['song_history']==='unavailable'&&isset($partial['chat-history:104']),'Missing optional Player storage destroyed unrelated evidence.');
opportunity_check($pdo->writeAttempts===0&&$pdo->sensitiveReads===0,'A source GET wrote data or read Canvas code/data.');
echo 'INITIATIVE_OPPORTUNITY_SOURCES=PASS '.$checks.' '.($mysql?'mysql':'sqlite')." checks\n";
