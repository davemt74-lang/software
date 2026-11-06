<?php
declare(strict_types=1);

// The common Initiative scope service is isolated here; source ownership,
// recording rules, passages, saved analysis, compute policy and policy proofs
// run against their actual production implementations and a real SQLite DB.
require_once dirname(__DIR__).'/includes/agent-initiative-transcripts-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-runtime-routing-v420.php';

set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
    if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});

class InitiativeTranscriptPDO extends PDO
{
    public bool $readsOnly=false;
    public string $failRead='';
    public int $raceOnSegmentRead=0;
    public int $segmentReads=0;
    public array $sql=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->sql[]=$query;
        if($this->readsOnly&&!preg_match('/\A\s*(SELECT|PRAGMA)\b/i',$query))throw new LogicException('A read adapter attempted a database write.');
        if($this->failRead!==''&&str_contains($query,$this->failRead)){
            $error=new PDOException('A fixture policy query failed.');$error->errorInfo=['42000',1142,'Fixture access denied'];throw $error;
        }
        if($this->raceOnSegmentRead>0&&str_contains($query,'FROM artist_transcript_segments_v172')&&++$this->segmentReads===2){
            // Simulate a distinct source writer between two production reads;
            // the adapter itself still cannot issue any mutation statement.
            $id=$this->raceOnSegmentRead;$this->raceOnSegmentRead=0;
            $writer=parent::prepare('UPDATE artist_transcript_segments_v172 SET transcript_text=? WHERE session_id=?');
            $writer->execute(['Concurrent source replacement.',$id]);
        }
        return parent::prepare($query,$options);
    }
    public function exec(string $statement):int|false
    {
        $this->sql[]=$statement;
        if($this->readsOnly)throw new LogicException('A read adapter attempted DDL or a direct database write.');
        if(str_starts_with(trim($statement),'CREATE TABLE IF NOT EXISTS agent_compute_preferences')){
            // The unchanged legacy foreground reader uses MySQL installer DDL.
            // SQLite executes its existing-table equivalent for that one test.
            return parent::exec('CREATE TABLE IF NOT EXISTS agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT)');
        }
        return parent::exec($statement);
    }
}

$pdo=new InitiativeTranscriptPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return $q->fetchColumn()!==false;}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function setting(string $key,mixed $default=''):mixed{return $default;}
function url(string $path):string{return $path;}
function has_permission(string $key,array $user):bool{return empty($GLOBALS['transcriptDenied'][$key]);}
function current_user():?array{return array_key_exists('transcriptActor',$GLOBALS)?$GLOBALS['transcriptActor']:['id'=>1,'is_active'=>1];}
function vp3_participant_scope_v1():?array{return !empty($GLOBALS['transcriptParticipant'])?['participant_id'=>99]:null;}
function agent_brain_schema_ready():bool{return false;}
function ai_generate_chat_response(...$arguments):array{++$GLOBALS['transcriptInference'];throw new LogicException('Reading must not invoke a model.');}
function homeserver_scope_v026_fetch(int $owner,bool $force=false,bool $readonly=false,?PDO $connection=null):array
{
    ++$GLOBALS['transcriptPolicyReads'];
    if(db()->inTransaction())throw new LogicException('A transaction attempted a paired policy request.');
    if(!$force)throw new LogicException('The processing guard must refresh current policy.');
    if(!empty($GLOBALS['transcriptPolicyUnavailable']))throw new RuntimeException('Fixture paired service interrupted.');
    if(isset($GLOBALS['transcriptScopeState']))return $GLOBALS['transcriptScopeState'];
    return ['version'=>'v0.26','supported'=>true,'available'=>true,'reason'=>'scope_ready',
        'scope'=>homeserver_scope_v026_normalize(['cloud_allowed'=>empty($GLOBALS['transcriptCloudBlocked'])]),
        'last_known_cloud_blocked'=>false];
}
foreach(['homeserver_scope_v026_string_list','homeserver_scope_v026_normalize','homeserver_scope_v026_blocks_cloud'] as $policyFunction){
    if(!function_exists($policyFunction))eval(transcript_capability_production_function(dirname(__DIR__).'/includes/homeserver-scope-v026.php',$policyFunction));
}

// Root integration tests exercise this service itself. This fixture tests that
// the domain cannot bypass its contract and that source authorization is real.
function vp3_initiative_capability_scope_v1(PDO $pdo,array $scope,array $binding):array
{
    if(!empty($GLOBALS['transcriptParticipant'])||$binding!==$GLOBALS['transcriptBinding']
        ||($scope['agent_id']??null)!==17||($scope['conversation_id']??null)!==170
        ||($scope['user']['id']??null)!==1)throw new RuntimeException('Scope changed.',403);
    if(!empty($GLOBALS['transcriptPaused'])&&($scope['verification_only']??false)!==true)throw new RuntimeException('Initiative paused.',403);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([1]);$user=$q->fetch();
    if(!$user||empty($user['is_active'])||!has_permission('account.access',$user)||!has_permission('chat.access',$user))throw new RuntimeException('Owner access changed.',403);
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,17);
    if(!vp3_agent_chat_conversation_v380($pdo,170,1,$agent))throw new RuntimeException('Chat changed.',403);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    foreach(['kind','agent_id','owner_user_id','viewer_user_id'] as $key){
        if(($scope['principal'][$key]??null)!==($principal[$key]??null))throw new RuntimeException('Principal changed.',403);
    }
    return ['user'=>$user,'principal'=>$principal,'agent_id'=>17,'conversation_id'=>170]+(!empty($scope['verification_only'])?['verification_only'=>true]:[]);
}

$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(17,1,1,'Owner Agent'),(18,1,1,'Other Agent'),(19,2,1,'Foreign Agent');
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(170,1,17),(180,1,18),(190,2,19);
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
      owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,
      title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT);
    CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,
      segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,
      confidence REAL,created_at TEXT,updated_at TEXT);
    CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,
      source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,
      model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));
    CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,
      page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT);");
$now=new DateTimeImmutable('2026-11-06 01:00:00',new DateTimeZone('UTC'));
$insert=$pdo->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(?,?,?,?,?,?,?,?)');
foreach([
    [101,1,170,'New recording','draft'],[102,1,170,'Edited recording','draft'],
    [103,1,170,'Analysis without actions','draft'],[104,1,170,'Reviewed actions','active'],
    [105,1,null,'Unlinked recording','draft'],[106,2,190,'Foreign private recording','draft'],
    [107,1,180,'Another Agent recording','draft'],[108,1,170,'Discarded recording','discarded'],
    [109,1,170,'Older selected recording','draft'],[110,1,170,'Empty recording','draft'],
] as $row){
    $stamp=$row[0]===109?'2020-01-01 00:00:00':'2026-11-06 00:30:00';
    $insert->execute([...$row,$stamp,$stamp,'{}']);
}
$segments=$pdo->prepare('INSERT INTO artist_transcript_segments_v172 VALUES(?,?,?,0,?,?,?,1000,2000,.9,?,?)');
foreach([101,102,103,104,105,106,107,108,109] as $id){
    $segments->execute([$id,$id,'segment-'.$id,'transcript','Owner','Saved transcript body '.$id.'. Follow up on the quoted proposal.','2026-11-06','2026-11-06']);
}
function transcript_capability_analysis(int $id,array $modules,?string $sourceHash=null):void
{
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments(db(),$id));$hash=$sourceHash??$map['source_hash'];
    foreach($modules as &$module){$module=['source_hash'=>$hash,'generated_at'=>gmdate('c'),'result'=>$module];}unset($module);
    $q=db()->prepare("INSERT OR REPLACE INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");
    $q->execute([$id,$hash,$map['total_words'],$map['page_count'],0,json_encode(['modules'=>$modules],JSON_THROW_ON_ERROR),'{}',gmdate('c')]);
}
transcript_capability_analysis(102,['basic'=>['summary'=>'DO NOT REUSE STALE PRIVATE ANALYSIS']],str_repeat('a',64));
transcript_capability_analysis(103,['basic'=>['summary'=>'A saved interpretation without action items.']]);
transcript_capability_analysis(104,['basic'=>['summary'=>'A private interpreted summary.'],
    'actions'=>['items'=>[['item_id'=>'a1','action'=>'Clarify the proposal before agreeing.','review_state'=>'accepted'],
        ['item_id'=>'a2','action'=>'An unreviewed suggestion.','review_state'=>'unreviewed']]]]);
$GLOBALS['transcriptBinding']=['owner_user_id'=>1,'agent_id'=>17,'conversation_id'=>170,'revision'=>str_repeat('a',32)];
$binding=$GLOBALS['transcriptBinding'];
$user=['id'=>1,'is_active'=>1];$principal=vp3_agent_chat_principal_v380($user,user_agent_get_v236($pdo,1,17));
$scope=['user'=>$user,'principal'=>$principal,'agent_id'=>17,'conversation_id'=>170];
$GLOBALS['transcriptInference']=0;$GLOBALS['transcriptPolicyReads']=0;$checks=0;
$pdo->readsOnly=true;

function transcript_capability_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo "PASS $label\n";
}
function transcript_capability_reject(callable $read,int $code,string $label):void
{
    try{$read();}catch(RuntimeException|InvalidArgumentException $error){
        transcript_capability_check($error->getCode()===$code,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function transcript_capability_mutate(callable $change):void
{
    db()->readsOnly=false;try{$change(db());}finally{db()->readsOnly=true;}
}
function transcript_capability_evidence(array $refs):array
{
    $coverage=[];return vp3_initiative_transcript_capabilities_evidence_v1(db(),$GLOBALS['scope'],$GLOBALS['binding'],$GLOBALS['now'],$refs,$coverage);
}
function transcript_capability_job(array $refs):array{return ['tool'=>'transcripts.read','primary'=>$refs[0],'refs'=>$refs,'focus'=>'Follow up on the proposal','report'=>true];}
function transcript_capability_read(array $refs,?array $evidence=null,?array $scope=null):array
{
    return vp3_initiative_transcript_capabilities_read_v1(db(),$scope??$GLOBALS['scope'],$GLOBALS['binding'],
        'transcripts.read',transcript_capability_job($refs),$evidence??transcript_capability_evidence($refs));
}

$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$schema=(int)$pdo->query('PRAGMA schema_version')->fetchColumn();
$evidence=transcript_capability_evidence(['transcript:101','transcript:102','transcript:103','transcript:104','transcript:110']);
transcript_capability_check(count($evidence)===5&&$evidence['transcript:101']['analysis_state']==='missing','recordings with missing analysis are admitted');
transcript_capability_check($evidence['transcript:102']['analysis_state']==='stale','recordings with stale analysis are admitted');
transcript_capability_check($evidence['transcript:103']['analysis_state']==='fresh'&&!$evidence['transcript:103']['reviewed_actions'],'current analysis without accepted actions remains useful context');
transcript_capability_check($evidence['transcript:104']['reviewed_actions']===['Clarify the proposal before agreeing.'],'accepted action references use the canonical reviewed-analysis reader');
$read=transcript_capability_read(array_keys($evidence),$evidence);
transcript_capability_check(count($read['records'])===10&&str_contains($read['records'][0]['text'],'Saved transcript body 101')
    &&str_contains($read['records'][0]['text'],'segment #101'),'the deterministic read supplies actual source text with stable segment/page/time locators');
transcript_capability_check($read['records'][1]['data']['analysis_state']==='missing'&&$read['records'][1]['text']===''
    &&$read['records'][3]['data']['analysis_state']==='stale'&&$read['records'][3]['text']===''
    &&!str_contains(json_encode($read),'DO NOT REUSE'),'missing and stale analysis are explicit states and stale contents are never exposed');
transcript_capability_check(str_contains($read['records'][5]['text'],'saved interpretation')
    &&$read['records'][5]['data']['interpretation_is_not_verified_fact']&&$read['records'][5]['data']['new_analysis_performed']===false,
    'fresh saved findings retain their interpretation status and do not claim new analysis');
transcript_capability_check($read['records'][8]['text']===''&&$read['records'][8]['data']['coverage']['total_characters']===0,'empty saved transcripts have honest empty read coverage');
transcript_capability_check($before===(int)$pdo->query('SELECT total_changes()')->fetchColumn()&&$schema===(int)$pdo->query('PRAGMA schema_version')->fetchColumn()
    &&$GLOBALS['transcriptInference']===0,'admission and reads perform zero inference, DDL or database writes');
transcript_capability_check($evidence===transcript_capability_evidence(array_keys($evidence)),'unchanged canonical data produces byte-stable evidence and source revisions');
$coverage=[];$recent=vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,null,$coverage);
transcript_capability_check(count($recent)===4&&$coverage['transcripts']==='limited'
    &&array_intersect(array_keys($recent),['transcript:106','transcript:107','transcript:108','transcript:109'])===[],
    'recent discovery is bounded and excludes foreign owner, other Agent, discarded and old recordings');
transcript_capability_check(isset(transcript_capability_evidence(['transcript:109'])['transcript:109']),'explicit current-source verification can read an older authorized source without a time-varying expiry hash');
foreach([106,107,108,999] as $id){
    $coverage=[];$denied=vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:'.$id],$coverage);
    transcript_capability_check($denied===[],'source selection does not grant foreign, other-Agent or deleted access '.$id);
}
$only=transcript_capability_read(['transcript:103'],$evidence);
transcript_capability_check(array_column($only['sources'],'source')===['transcript:103']&&count($only['records'])===2,'the read retrieves only the exact selected source rather than the wider discovered evidence');
foreach([
    ['agent_id'=>18],['conversation_id'=>180],['user'=>['id'=>2,'is_active'=>1]],
    ['principal'=>array_replace($principal,['viewer_user_id'=>2])],['principal'=>array_replace($principal,['kind'=>'system','agent_id'=>0])],
] as $change){
    transcript_capability_reject(fn()=>transcript_capability_read(['transcript:101'],$evidence,array_replace($scope,$change)),403,'fresh canonical scope rejects replacement '.array_key_first($change));
}
$GLOBALS['transcriptParticipant']=true;
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:101'],$evidence),403,'participant scope cannot read owner background transcripts');
unset($GLOBALS['transcriptParticipant']);
$GLOBALS['transcriptDenied']['artist_listening.access']=true;
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:101'],$evidence),403,'fresh recording access permission is required');
unset($GLOBALS['transcriptDenied']['artist_listening.access']);
transcript_capability_mutate(fn($p)=>$p->exec("INSERT INTO user_agent_data_rules VALUES(17,'recording','101','deny')"));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:101'],$evidence),404,'revoked named-Agent recording permission prevents stale source reads');
transcript_capability_mutate(fn($p)=>$p->exec('DELETE FROM user_agent_data_rules'));
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='An edited source, after admission.' WHERE session_id=101"));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:101'],$evidence),409,'a transcript edit invalidates its admitted source proof');
$current=transcript_capability_evidence(['transcript:103']);
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE artist_transcript_master_analysis_v237 SET analysis_json=REPLACE(analysis_json,'saved interpretation','changed interpretation') WHERE session_id=103"));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:103'],$current),409,'saved analysis edits invalidate the source revision even without timestamp changes');
$current=transcript_capability_evidence(['transcript:105']);
transcript_capability_mutate(fn($p)=>$p->exec('DELETE FROM artist_transcript_sessions_v172 WHERE id=105'));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:105'],$current),404,'source deletion cannot return cached content');
$coverage=[];
$deleted=vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:105'],$coverage);
transcript_capability_check($deleted===[]&&($coverage['transcripts']??null)==='ready',
    'an exact selected deleted source is known absent, so canonical verification can withhold stale work rather than retry unavailable forever');
$pdo->raceOnSegmentRead=103;$pdo->segmentReads=0;$coverage=[];
$changed=vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:103'],$coverage);
transcript_capability_check($pdo->raceOnSegmentRead===0&&$changed===[]&&($coverage['transcripts']??null)==='ready',
    'an actual source edit between canonical reads is a known changed selected source rather than uncertain discovery coverage');
transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_read_v1($pdo,$scope,$binding,'transcripts.analyze',transcript_capability_job(['transcript:104']),$evidence),0,'the read adapter cannot select an analysis or write executor');
transcript_capability_reject(fn()=>transcript_capability_evidence(['transcript:0104']),0,'noncanonical source IDs are rejected');
transcript_capability_reject(fn()=>transcript_capability_evidence(['transcript:104','transcript:104']),0,'duplicate source IDs are rejected');

// Worst-case escaped Unicode output remains within the shared receipt budget.
transcript_capability_mutate(static function($p)use($insert,$segments):void{
    for($id=201;$id<=205;++$id){
        $insert->execute([$id,1,170,str_repeat('🌎',170),'draft','2026-11-06 00:30:00','2026-11-06 00:30:00','{}']);
        $segments->execute([$id,$id,'unicode-'.$id,'transcript',str_repeat('🌎',60),str_repeat('🌎',10000),'2026-11-06','2026-11-06']);
        transcript_capability_analysis($id,['basic'=>['summary'=>str_repeat('🌎',5000)]]);
    }
});
$unicode=transcript_capability_read(['transcript:201','transcript:202','transcript:203','transcript:204','transcript:205']);
transcript_capability_check(strlen(json_encode($unicode,JSON_THROW_ON_ERROR))<65536&&$unicode['coverage']['state']==='limited'
    &&!$unicode['records'][0]['data']['coverage']['complete']&&!$unicode['records'][1]['data']['complete'],
    'five long Unicode sources remain within the escaped-JSON budget and disclose truncated transcript and analysis coverage');
transcript_capability_check($unicode===transcript_capability_read(['transcript:201','transcript:202','transcript:203','transcript:204','transcript:205']),
    'bounded passage and analysis selection remains deterministic');

// The readonly compute path proves missing tables without provisioning them.
$schema=(int)$pdo->query('PRAGMA schema_version')->fetchColumn();
$preference=vp3_agent_runtime_preference_readonly_v420($pdo,1,17);
transcript_capability_check($preference['effective_preference']==='auto'&&$schema===(int)$pdo->query('PRAGMA schema_version')->fetchColumn()
    &&!table_exists('agent_compute_preferences'),'known absent compute tables use canonical legacy defaults without schema creation');
transcript_capability_mutate(static function($p):void{
    $p->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
        CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
        INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud');
        INSERT INTO agent_compute_overrides VALUES(1,17,'homeserver_only');
        CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,public_id TEXT,
          room_name TEXT,agent_mode TEXT,title TEXT);
        CREATE TABLE video_meeting_participants(id INTEGER,invite_token TEXT);
        CREATE TABLE video_meeting_transcript_segments(id INTEGER);
        CREATE TABLE video_meeting_artifacts(id INTEGER);
        INSERT INTO video_meetings VALUES(500,1,17,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','room-fixture','assist','Owner meeting');
        CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,
          relay_token_enc TEXT,homeserver_token_enc TEXT,device_id TEXT,last_seen_at TEXT,last_checked_at TEXT,updated_at TEXT,last_error TEXT);
        INSERT INTO homeserver_connections(user_id,status,capabilities_json,relay_token_enc,homeserver_token_enc,device_id)
          VALUES(1,'connected','{\"features\":[\"app.scopes.v1\"]}','fixture-relay','fixture-home','fixture-device');
        CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,status TEXT,device_id TEXT,
          session_token_hash TEXT,last_seen_at TEXT,updated_at TEXT);
        INSERT INTO homeserver_https_sessions VALUES(1,'active','fixture-device','fixture-session-hash',NULL,NULL);");
});
transcript_capability_check(vp3_agent_runtime_preference_readonly_v420($pdo,1,17)['effective_preference']==='homeserver_only','readonly compute policy preserves exact named-Agent override precedence');
$pdo->failRead='FROM agent_compute_preferences';
transcript_capability_reject(fn()=>vp3_agent_runtime_preference_readonly_v420($pdo,1,17),503,'unknown compute read errors remain unavailable rather than missing preferences or revoked permission');
$pdo->failRead='';
transcript_capability_reject(fn()=>vp3_agent_runtime_preference_readonly_v420($pdo,0,17),403,'invalid compute-policy actors remain an explicit authorization failure');
transcript_capability_mutate(fn($p)=>$p->exec("DELETE FROM agent_compute_overrides;UPDATE agent_compute_preferences SET preference='auto';UPDATE artist_transcript_sessions_v172 SET metadata_json='{\"capture_mode\":\"vp3_video_meeting\",\"video_meeting_id\":500}' WHERE id=104"));
$meetingSession=vp3_transcript_session_v1($pdo,$user,$principal,104);
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$schema=(int)$pdo->query('PRAGMA schema_version')->fetchColumn();
$pdo->beginTransaction();
try{
    transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a meeting transaction without a fresh same-request processing proof fails closed as unavailable');
    transcript_capability_check($pdo->inTransaction(),'a failed source-policy check preserves the caller transaction');
}finally{$pdo->rollBack();}
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
$requests=$GLOBALS['transcriptPolicyReads'];
$pdo->beginTransaction();
try{
    vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
    transcript_capability_check($pdo->inTransaction()&&$GLOBALS['transcriptPolicyReads']===$requests,'a fresh automatic-meeting proof revalidates inside the transaction without DDL, policy traffic or implicit commit');
}finally{$pdo->rollBack();}
transcript_capability_check($before===(int)$pdo->query('SELECT total_changes()')->fetchColumn()&&$schema===(int)$pdo->query('PRAGMA schema_version')->fetchColumn(),'fresh readonly meeting policy checks perform zero database and schema writes');
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET status='revoked' WHERE user_id=1"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,
    'local pairing revocation after preflight cannot reuse a cached processing proof');}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET status='connected' WHERE user_id=1"));
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET homeserver_token_enc='a changed fixture token' WHERE user_id=1"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,
    'an encrypted credential change between checks invalidates the private cache key without decryption or traffic inside the transaction');}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET homeserver_token_enc='fixture-home' WHERE user_id=1"));
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET capabilities_json='{\"features\":[\"app.scopes.v1\"],\"permissions\":[\"tools.list\"],\"app_scope\":{\"cloud_allowed\":true,\"reported_at\":\"before\"}}' WHERE user_id=1"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,
    'stored capability permission changes require fresh outside-transaction processing policy');}finally{$pdo->rollBack();}
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,
    'a local HTTPS session revocation invalidates the earlier privacy proof');}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_https_sessions SET status='active' WHERE user_id=1"));
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);$requests=$GLOBALS['transcriptPolicyReads'];
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE homeserver_connections SET last_seen_at='later',last_checked_at='later',updated_at='later',last_error='display only',
    capabilities_json='{\"features\":[\"app.scopes.v1\"],\"permissions\":[\"tools.list\"],\"app_scope\":{\"cloud_allowed\":true,\"reported_at\":\"later\"},\"reported_at\":\"later\"}' WHERE user_id=1;
    UPDATE homeserver_https_sessions SET last_seen_at='later',updated_at='later' WHERE user_id=1;"));
$pdo->beginTransaction();
try{
    vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
    transcript_capability_check($requests===$GLOBALS['transcriptPolicyReads']&&$pdo->inTransaction(),
        'heartbeat, advertisement-time and display-only connection updates preserve the same current authorization proof');
}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE video_meetings SET title='Changed meeting' WHERE id=500"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a current meeting-row change invalidates its earlier processing proof until fresh preflight');}finally{$pdo->rollBack();}
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1"));
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a changed stored compute preference invalidates the transaction proof until fresh preflight');}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1"));
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
$GLOBALS['transcriptCloudBlocked']=true;
transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),403,'the canonical live meeting privacy restriction blocks Cloud context');
$GLOBALS['transcriptCloudBlocked']=false;
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a failed fresh policy check clears every old proof for the same source');}finally{$pdo->rollBack();}
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
$GLOBALS['transcriptPolicyUnavailable']=true;
transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a paired policy interruption is temporary unavailability rather than permission revocation');
$coverage=[];
transcript_capability_reject(function()use($pdo,$scope,$binding,$now,&$coverage):void{
    vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:104'],$coverage);
},503,'selected source verification preserves temporary policy failure as unavailable');
transcript_capability_check(($coverage['transcripts']??null)==='unavailable','a failed selected processing read records unavailable coverage');
$GLOBALS['transcriptPolicyUnavailable']=false;
$pdo->beginTransaction();
try{transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'network recovery alone cannot revive a processing proof cleared by the failed refresh');}finally{$pdo->rollBack();}
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
$requests=$GLOBALS['transcriptPolicyReads'];
$pdo->beginTransaction();
try{
    vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession);
    transcript_capability_check($GLOBALS['transcriptPolicyReads']===$requests&&$pdo->inTransaction(),'a fresh retry after policy recovery restores a verified proof without transactional network or DDL');
}finally{$pdo->rollBack();}
usleep(15_050_000);
$pdo->beginTransaction();
try{
    transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$meetingSession),503,'a real elapsed fifteen-second processing proof expires and cannot authorize a later transaction');
    $coverage=[];
    transcript_capability_reject(function()use($pdo,$scope,$binding,$now,&$coverage):void{
        vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:104'],$coverage);
    },503,'an expired transaction proof preserves unavailable classification during exact selected admission');
    transcript_capability_check(($coverage['transcripts']??null)==='unavailable','expired processing proof coverage cannot masquerade as a known stale or revoked source');
}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1"));
$explicit=video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$meetingSession,true);
transcript_capability_check($explicit['cloud_ai_allowed']===true,'readonly canonical meeting policy honors explicit Cloud compute');
$pdo->failRead='FROM agent_compute_preferences';
$policy=video_meeting_transcription_ai_policy_v1801($pdo,$user,$meetingSession,true,true);
transcript_capability_check($policy['cloud_ai_allowed']===false&&$policy['reason']==='compute_policy_unavailable','canonical readonly policy fails closed when current compute preferences cannot be read');
$pdo->failRead='';
transcript_capability_check((new ReflectionFunction('video_meeting_homeserver_policy_v1801'))->getParameters()[3]->getDefaultValue()===false
    &&(new ReflectionFunction('video_meeting_transcription_ai_policy_v1801'))->getParameters()[4]->getDefaultValue()===false
    &&(new ReflectionFunction('video_meeting_transcription_assert_cloud_ai_v1801'))->getParameters()[3]->getDefaultValue()===false,
    'existing meeting policy signatures retain their default execution behavior');

// Execute the production metadata writer without entering its authenticated
// HTTP controller/bootstrap. No copy or replacement of its SQL/body is used.
function transcript_capability_production_function(string $path,string $name):string
{
    $tokens=token_get_all((string)file_get_contents($path));$code='';$collect=false;$opened=false;$depth=0;
    foreach($tokens as $index=>$token){
        if(!$collect&&is_array($token)&&$token[0]===T_FUNCTION){
            for($next=$index+1;isset($tokens[$next]);++$next){
                if(is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE)continue;
                $collect=is_array($tokens[$next])&&$tokens[$next][0]===T_STRING&&$tokens[$next][1]===$name;break;
            }
        }
        if(!$collect)continue;
        $code.=is_array($token)?$token[1]:$token;
        if($token==='{'){$opened=true;++$depth;}
        elseif($token==='}'&&$opened&&--$depth===0)return $code;
    }
    throw new RuntimeException('Production fixture function was not found.');
}
eval(transcript_capability_production_function(dirname(__DIR__).'/api/artist-recordings-v198.php','artist_recordings_v198_save_rows'));
transcript_capability_mutate(static function($p)use($user):void{
    $metadata=json_encode(['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>500,
        'video_meeting_public_id'=>str_repeat('a',32)],JSON_THROW_ON_ERROR);
    $p->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=104')->execute([$metadata]);
    $p->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1;
        INSERT INTO video_meetings VALUES(501,1,17,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','other-room','assist','Other owned meeting');
        INSERT INTO video_meetings VALUES(502,2,19,'cccccccccccccccccccccccccccccccc','foreign-room','assist','Foreign meeting');");
    $session=vp3_transcript_session_v1($p,$user,$GLOBALS['principal'],104);
    artist_recordings_v198_save_rows($p,$user,$session,[]);
});
$rewritten=vp3_transcript_session_v1($pdo,$user,$principal,104);$metadata=json_decode($rewritten['metadata_json'],true);
transcript_capability_check($metadata['capture_mode']==='transcription_with_optional_audio'
    &&$metadata['video_meeting_id']===500&&$metadata['video_meeting_public_id']===str_repeat('a',32),
    'the actual recordings metadata writer changes capture mode while retaining both original meeting bindings');
transcript_capability_check(video_meeting_transcription_readonly_binding_v1801($pdo,$user,$rewritten)['id']===500,
    'readonly policy follows the retained meeting binding after the production recording edit');
$requests=$GLOBALS['transcriptPolicyReads'];$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true),403,
    'a recording-library metadata edit cannot bypass explicit HomeServer-only processing');
transcript_capability_reject(fn()=>vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$rewritten),403,
    'the Initiative proof path uses the same retained-binding processing restriction');
$coverage=[];$blocked=vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$binding,$now,['transcript:104'],$coverage);
transcript_capability_check($blocked===[]&&($coverage['transcripts']??null)==='forbidden'
    &&$GLOBALS['transcriptPolicyReads']===$requests&&$before===(int)$pdo->query('SELECT total_changes()')->fetchColumn(),
    'the rewritten restricted recording is excluded without policy traffic, provider calls or database writes');
transcript_capability_mutate(static function($p)use($user,$rewritten):void{
    transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($p,$user,$rewritten),403,
        'the foreground default processing guard also enforces retained meeting privacy after the actual recording edit');
    $meeting=video_meeting_row_v1800($p,500);
    $policy=video_meeting_homeserver_policy_v1801($p,$meeting,true);
    transcript_capability_check($policy['requested_compute']==='homeserver_only'&&$policy['cloud_processing_allowed']===false
        &&$policy['cloud_block_reason']==='homeserver_only'&&$policy['scope_checked']===true,
        'Cloud-allowed paired scope cannot override explicit HomeServer-only preference while the owner still receives local capability discovery');
});
foreach([null,'','   ','{}','[]'] as $empty){
    $ordinary=array_replace($rewritten,['metadata_json'=>$empty]);
    transcript_capability_check(video_meeting_transcription_readonly_binding_v1801($pdo,$user,$ordinary)===null
        &&video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$ordinary,true)===null,
        'ordinary legacy empty metadata remains a non-meeting source '.json_encode($empty));
}
foreach(['{','null','"opaque"','["opaque"]'] as $invalid){
    $unknown=array_replace($rewritten,['metadata_json'=>$invalid]);
    transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$unknown,true),503,
        'unreadable nonempty source metadata cannot authorize Cloud processing '.json_encode($invalid));
}
foreach([
    ['video_meeting_id'=>0],['video_meeting_id'=>false],['video_meeting_id'=>[]],['video_meeting_id'=>'0500'],
    ['video_meeting_id'=>'500x'],['video_meeting_id'=>500.5],['video_meeting_id'=>999],
    ['video_meeting_public_id'=>'bad'],['video_meeting_public_id'=>500],
    ['video_meeting_public_id'=>str_repeat('d',32)],['capture_mode'=>'vp3_video_meeting'],
    ['video_meeting_id'=>500,'video_meeting_public_id'=>str_repeat('b',32)],
    ['video_meeting_id'=>500,'video_meeting_public_id'=>str_repeat('c',32)],
    ['video_meeting_id'=>999,'video_meeting_public_id'=>str_repeat('a',32)],
    ['video_meeting_id'=>500,'video_meeting_public_id'=>str_repeat('d',32)],
] as $bindingMetadata){
    $bad=array_replace($rewritten,['metadata_json'=>json_encode($bindingMetadata,JSON_THROW_ON_ERROR)]);
    transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$bad,true),403,
        'malformed, unknown, foreign or conflicting retained bindings fail closed '.json_encode($bindingMetadata));
}
foreach([['video_meeting_id'=>500],['video_meeting_public_id'=>str_repeat('a',32)]] as $single){
    $bound=array_replace($rewritten,['metadata_json'=>json_encode($single,JSON_THROW_ON_ERROR)]);
    transcript_capability_check(video_meeting_transcription_readonly_binding_v1801($pdo,$user,$bound)['id']===500,
        'one exact retained identifier resolves its currently owned meeting '.array_key_first($single));
}
$pdo->failRead='FROM video_meetings';
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true),503,
    'an unreadable current meeting binding is unavailable rather than ordinary non-meeting context');
$pdo->failRead='';

// The real CLI worker has no browser user. Only a fresh, exact server-side
// owner/Agent/Chat/source context may resolve automatic HomeServer privacy.
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1"));
$GLOBALS['transcriptActor']=null;$requests=$GLOBALS['transcriptPolicyReads'];
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true),503,
    'anonymous callers without explicit processing context cannot probe the owner HomeServer');
transcript_capability_check($GLOBALS['transcriptPolicyReads']===$requests,'an absent owner processing context performs no policy request');
$cli=video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope);
transcript_capability_check($cli['cloud_ai_allowed']===true&&current_user()===null&&$GLOBALS['transcriptPolicyReads']===$requests+1,
    'an actual-sessionless worker resolves current privacy through its validated server context without browser impersonation');
foreach([
    ['principal'=>array_replace($principal,['viewer_user_id'=>2])],
    ['principal'=>array_replace($principal,['owner_user_id'=>2])],
    ['agent_id'=>18],['conversation_id'=>180],['user'=>['id'=>2,'is_active'=>1]],
] as $change){
    $requests=$GLOBALS['transcriptPolicyReads'];$forged=array_replace($scope,$change);
    transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$forged),403,
        'a forged anonymous processing context cannot become an owner probe '.array_key_first($change));
    transcript_capability_check($requests===$GLOBALS['transcriptPolicyReads'],'invalid processing context emits no private policy traffic');
}
$GLOBALS['transcriptParticipant']=true;
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope),403,
    'participant context cannot claim the server processing scope');
unset($GLOBALS['transcriptParticipant']);
$cached=video_meeting_transcription_ai_policy_v1801($pdo,$user,$rewritten,false,true,$scope);
transcript_capability_mutate(fn($p)=>$p->exec('UPDATE users SET is_active=0 WHERE id=1'));
transcript_capability_reject(fn()=>video_meeting_transcription_ai_policy_v1801($pdo,$user,$rewritten,false,true,$scope),403,
    'account revocation is checked before reusing a processing-policy cache');
transcript_capability_mutate(fn($p)=>$p->exec('UPDATE users SET is_active=1 WHERE id=1'));
$otherAgent=vp3_transcript_session_v1($pdo,$user, user_agent_principal_v236($user,user_agent_get_v236($pdo,1,18)),107);
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$otherAgent,true,$scope),404,
    'canonical processing context reloads source ownership and Agent recording scope');
transcript_capability_mutate(static function($p):void{
    $p->exec("INSERT INTO chat_conversations VALUES(173,1,NULL);");
    $metadata=json_encode(['video_meeting_id'=>500],JSON_THROW_ON_ERROR);
    $p->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(401,1,173,?,?,?, ?,?)')
        ->execute(['System recording','draft','2026-11-06 00:30:00','2026-11-06 00:30:00',$metadata]);
});
$systemSession=['id'=>401];$systemScope=['user'=>$user,'principal'=>user_agent_principal_v236($user,null),'agent_id'=>0,'conversation_id'=>173];
$system=video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$systemSession,true,$systemScope);
transcript_capability_check($system['cloud_ai_allowed']===true&&$systemScope['principal']['owner_user_id']===0,
    'canonical System owner-zero principal and owned Chat work without a browser session');
$GLOBALS['transcriptActor']=['id'=>2,'is_active'=>1];$requests=$GLOBALS['transcriptPolicyReads'];
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope),403,
    'a foreign signed-in browser cannot borrow a server owner context');
transcript_capability_check($requests===$GLOBALS['transcriptPolicyReads'],'foreign browser identity is rejected before any policy probe');
$GLOBALS['transcriptActor']=null;
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1"));
$spoof=array_replace($rewritten,['metadata_json'=>'{}']);
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$spoof,true,$scope),403,
    'supplied metadata cannot hide the private meeting origin reloaded from the owned source');
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1"));

// Use the actual blocks_cloud implementation with the normal returned outage
// shape: its false result is not affirmative Cloud authorization.
$GLOBALS['transcriptScopeState']=['version'=>'v0.26','supported'=>true,'available'=>false,'reason'=>'homeserver_unavailable',
    'scope'=>null,'last_known_cloud_blocked'=>false];
transcript_capability_check(homeserver_scope_v026_blocks_cloud($GLOBALS['transcriptScopeState'])===false,
    'the production scope predicate alone does not establish permission during a returned outage');
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope),503,
    'a normal returned unavailable supported scope cannot authorize automatic Cloud processing');
$GLOBALS['transcriptActor']=['id'=>1,'is_active'=>1];
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten),503,
    'the foreground processing gate also uses strict fresh privacy during returned outages');
transcript_capability_mutate(static function($p)use($user):void{
    $global=video_meeting_homeserver_policy_v1801($p,video_meeting_row_v1800($p,500),true);
    transcript_capability_check($global['cloud_processing_allowed']===false&&$global['cloud_block_reason']==='homeserver_scope_unavailable',
        'a returned paired outage also fails closed in the global meeting route policy');
});
$GLOBALS['transcriptActor']=null;$GLOBALS['transcriptScopeState']['last_known_cloud_blocked']=true;
transcript_capability_reject(fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope),503,
    'an unavailable scope with an older retained block remains unavailable until a fresh current policy is read');
unset($GLOBALS['transcriptScopeState']);
vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$rewritten);$requests=$GLOBALS['transcriptPolicyReads'];
$pdo->beginTransaction();
try{
    vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$rewritten);
    transcript_capability_check($pdo->inTransaction()&&$requests===$GLOBALS['transcriptPolicyReads']&&current_user()===null,
        'recovered sessionless work commits under its real source proof without transactional probes or impersonation');
}finally{$pdo->rollBack();}
transcript_capability_mutate(fn($p)=>$p->exec('DELETE FROM homeserver_connections WHERE user_id=1'));
$requests=$GLOBALS['transcriptPolicyReads'];
$unpaired=video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$rewritten,true,$scope);
transcript_capability_check($unpaired['cloud_ai_allowed']===true&&$GLOBALS['transcriptPolicyReads']===$requests,
    'a successful strict read proving no connection preserves ordinary unpaired Auto behavior without a policy request');

$semanticBefore=transcript_capability_evidence(['transcript:103']);
$semanticRead=transcript_capability_read(['transcript:103'],$semanticBefore);
transcript_capability_mutate(static function($p):void{
    $p->exec('ALTER TABLE artist_transcript_sessions_v172 ADD COLUMN updated_at TEXT;
        ALTER TABLE artist_transcript_sessions_v172 ADD COLUMN folder_id INTEGER;');
    $cosmetic=['capture_mode'=>'transcription_with_optional_audio','audio_retained'=>true,'audio_updated_at'=>gmdate('c'),
        'recordings_v197'=>[['key'=>'fixture-recording','favorite'=>true,'name'=>'An audio library label']]];
    $p->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=?,updated_at=?,folder_id=9 WHERE id=103')
        ->execute([json_encode($cosmetic,JSON_THROW_ON_ERROR),'2026-11-06 00:31:00',gmdate('c')]);
});
$semanticAfter=transcript_capability_evidence(['transcript:103']);
transcript_capability_check($semanticBefore===$semanticAfter,
    'favorite, organization, audio-label and timestamp-only changes do not manufacture a new transcript situation');
transcript_capability_check($semanticRead===transcript_capability_read(['transcript:103'],$semanticBefore),
    'cosmetic recording touches preserve the exact bounded read fingerprint and verified source proof');
transcript_capability_mutate(fn($p)=>$p->exec("UPDATE artist_transcript_sessions_v172 SET title='A changed source title' WHERE id=103"));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:103'],$semanticBefore),409,
    'an actual transcript title change remains semantic and invalidates the admitted source');
$beforeOrigin=transcript_capability_evidence(['transcript:103']);
transcript_capability_mutate(fn($p)=>$p->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=103')
    ->execute([json_encode(['video_meeting_public_id'=>str_repeat('a',32)],JSON_THROW_ON_ERROR)]));
transcript_capability_reject(fn()=>transcript_capability_read(['transcript:103'],$beforeOrigin),409,
    'a changed retained meeting origin invalidates content binding while current privacy is still freshly checked');
transcript_capability_check($GLOBALS['transcriptInference']===0,'all transcript and policy checks used no analysis generation or model call');
echo 'AGENT_INITIATIVE_TRANSCRIPTS_V1=PASS ('.$checks." cases; actual SQLite readers, analysis state, authorization, bounded passages and processing proofs)\n";
