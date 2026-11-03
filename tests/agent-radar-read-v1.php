<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-radar-read-v1.php';
require_once dirname(__DIR__).'/includes/agent-radar-chat.php';
require_once dirname(__DIR__).'/includes/agent-crm.php';
require_once __DIR__.'/fixtures/radar-agent-v1.php';

class RadarReadPDO extends PDO
{
    public bool $readGuard=false,$ddlGuard=false;
    public int $writeAttempts=0,$ddlAttempts=0,$sqlCount=0,$transactions=0;
    public mixed $beforeSql=null,$afterCommit=null;
    private function guard(string $sql): void
    {
        $this->sqlCount++;
        if($this->ddlGuard&&preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$sql)){$this->ddlAttempts++;throw new RuntimeException('Unexpected Radar schema mutation.');}
        if($this->readGuard&&!preg_match('/^\s*(SELECT|PRAGMA)\b/i',$sql)){$this->writeAttempts++;throw new RuntimeException('Radar read attempted a write.');}
        if(is_callable($this->beforeSql))($this->beforeSql)($sql);
    }
    public function exec(string $sql): int|false{$this->guard($sql);return parent::exec($sql);}
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $this->guard($sql);
        // The canonical MySQL writer compares its numeric COALESCE to an execute-bound value.
        // SQLite has no expression affinity; preserve MySQL's numeric comparison in this fixture.
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace('COALESCE(property_id,0)=?','COALESCE(property_id,0)=CAST(? AS INTEGER)',$sql);
        return parent::prepare($sql,$options);
    }
    public function query(string $sql,?int $mode=null,mixed ...$args): PDOStatement|false{$this->guard($sql);return $mode===null?parent::query($sql):parent::query($sql,$mode,...$args);}
    public function beginTransaction(): bool{$this->transactions++;return parent::beginTransaction();}
    public function commit(): bool{$ok=parent::commit();if(is_callable($this->afterCommit)){$hook=$this->afterCommit;$this->afterCommit=null;$hook();}return $ok;}
}

$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_SECTION7_MYSQL_DSN');$dsn='sqlite::memory:';$dbUser=null;$dbPassword=null;
if($mysqlDsn){
    $dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPassword=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture';
    $admin=new PDO($mysqlDsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$database='vp3_radar_read_test_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysqlDsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysqlDsn):rtrim($mysqlDsn,';').';dbname='.$database;
    register_shutdown_function(static function()use($admin,$database): void {$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
}
$pdo=new RadarReadPDO($dsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
else $pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
$checks=0;$permissions=['chat.access'=>true,'account.access'=>true,'profile_agent.access'=>true];$seeded=false;$delegateCalls=0;
function db(): PDO{return $GLOBALS['pdo'];}
function has_permission($key,$user): bool{return $GLOBALS['permissions'][$key]??false;}
function personal_capability_has_v242($key,$user): bool{return $GLOBALS['permissions'][$key]??false;}
function permissions_schema_ready(): bool{return $GLOBALS['seeded'];}
function personal_capability_seeded_v242(): bool{return $GLOBALS['seeded'];}
function user_roles_for_user($user): array{return ['member'];}
function user_has_role($role,$user): bool{return ($user['role']??'')===$role;}
function url($path): string{return $path;}
function agent_tool_log(...$args): void{}
function vp3_agent_access_chat_intent(string $query): bool{return str_contains($query,'agent messaging request');}
function vp3_agent_access_chat_tool(string $query,array $user,int $cid): array{$GLOBALS['delegateCalls']++;return vp3_agent_access_chat_intent($query)?['handled'=>true,'answer'=>'Existing incoming messaging delegate']:['handled'=>false];}
function check($condition,string $label): void{if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function writableRadar(callable $work,bool $ddl=false): mixed
{
    $pdo=db();$guard=$pdo->readGuard;$schema=$pdo->ddlGuard;$pdo->readGuard=false;if($ddl)$pdo->ddlGuard=false;
    try{return $work();}finally{$pdo->readGuard=$guard;$pdo->ddlGuard=$schema;}
}
function readRadar(string $view='recent',array $extra=[],?array $who=null,array $context=['conversation_id'=>1]): array
{
    return vp3_radar_read_v1(db(),$GLOBALS['user'],$who??$GLOBALS['principal'],array_replace(['operation'=>'radar_read','ids'=>['radar.read'],'view'=>$view],$extra),$context);
}
function commandRadar(string $query,?array $who=null,array $context=['conversation_id'=>1]): array
{
    return writableRadar(fn()=>vp3_radar_apply_command_v1(db(),$GLOBALS['user'],$who??$GLOBALS['principal'],$context,$query));
}
function safeRadar(array $result): bool{return !preg_match('/SECRET|FOREIGN|192\.0\.2\.90/',json_encode($result))&&!isset($result['rows']);}
function policyCount(): int{return(int)db()->query('SELECT COUNT(*) FROM vp3_agent_policies')->fetchColumn();}
function resetRadar(): void
{
    $GLOBALS['permissions']=['chat.access'=>true,'account.access'=>true,'profile_agent.access'=>true];$GLOBALS['seeded']=false;db()->beforeSql=null;db()->afterCommit=null;unset($GLOBALS['vp3_capability_turn_v1']);
    writableRadar(function(): void{
        db()->exec('UPDATE users SET is_active=1 WHERE id=1');db()->exec('UPDATE user_agents SET owner_user_id=1,is_active=1 WHERE id=7');db()->exec('UPDATE chat_conversations SET user_id=1,user_agent_id=7 WHERE id=1');
        db()->exec('UPDATE vp3_agent_contacts SET owner_user_id=1 WHERE id IN (101,102)');db()->exec('UPDATE vp3_radar_properties SET owner_user_id=1 WHERE id=31');
        db()->exec('DELETE FROM vp3_agent_policies WHERE owner_user_id=1 OR id IN (101,102,103,104,110)');
        radar_agent_fixture_v1(db());
    },true);
}
function beforeRadar(string $needle,callable $work): void
{
    db()->beforeSql=function(string $sql)use($needle,$work): void{if(str_contains($sql,$needle)){db()->beforeSql=null;writableRadar($work,true);}};
}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,role VARCHAR(20))');$pdo->exec("INSERT INTO users VALUES(1,1,'member'),(2,1,'member')");
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');$pdo->exec('INSERT INTO user_agents VALUES(7,1,1),(8,1,1),(9,2,1),(10,1,0)');
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');$pdo->exec('INSERT INTO chat_conversations VALUES(1,1,7),(2,2,9)');
$pdo->exec('CREATE TABLE role_permissions(permission_key VARCHAR(100),role VARCHAR(40))');$pdo->exec("INSERT INTO role_permissions VALUES('profile_agent.access','member')");
radar_agent_fixture_v1($pdo);$pdo->readGuard=true;$pdo->ddlGuard=true;
$user=['id'=>1,'is_active'=>1,'role'=>'member'];$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];

check(vp3_radar_read_ready_v1($pdo),'readiness probes existing columns without schema initialization');
foreach(['recent'=>'Fixture GPTBot','high_risk'=>'Fixture GPTBot','contact_policies'=>'30 requests per 30 minutes','scoped_rules'=>'Fixture Radar Site','access_profile'=>'Monitor All'] as $view=>$marker){
    $r=readRadar($view);check($r['tool_receipt']['status']==='read_complete'&&safeRadar($r)&&str_contains($r['answer'],$marker),$view.' reports current owned source evidence and a shared receipt');
    check(str_contains($r['answer'],'enforcement depends')&&$r['actions'][0]['auto']===false,$view.' provides a manual review link and makes no live enforcement claim');
}
$r=readRadar('high_risk');check(!str_contains($r['answer'],'ChatGPT')&&$r['tool_receipt']['count']===1,'high-risk view uses the actual stored score threshold 70');
$r=readRadar('contact_policies',['contact_id'=>102]);check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'ChatGPT')&&!str_contains($r['answer'],'GPTBot'),'an exact owned contact selector limits the actual SQL scope');
$r=readRadar('contact_policies',['contact_id'=>201]);check($r['tool_receipt']['status']==='no_matches'&&safeRadar($r),'foreign contact selector cannot reveal a contact or its policy');
$r=readRadar('recent',['query'=>'%']);check($r['tool_receipt']['status']==='no_matches','literal percent search is not an SQL wildcard');
$r=readRadar('recent',['query'=>'Fixture OpenAI']);check($r['tool_receipt']['count']===2,'literal search supports the recorded operator label without using it as a mutation target');

foreach([
    ['operation'=>'radar_write'],['operation'=>null],['ids'=>['radar.gateway.manage']],['view'=>null],['view'=>'events'],['view'=>'access_profile','query'=>'x'],['view'=>'scoped_rules','contact_id'=>101],['contact_id'=>'101'],['contact_id'=>0],['contact_id'=>true],['query'=>null],['query'=>str_repeat('x',161)],['query'=>'','contact_id'=>101],['owner_user_id'=>2],['action'=>'block'],['limit'=>999],['property_id'=>31],['ids'=>['radar.read','radar.gateway.manage']],
] as $extra){$before=$pdo->sqlCount;$bad=false;try{vp3_radar_plan_v1(array_replace(['operation'=>'radar_read','ids'=>['radar.read'],'view'=>'contact_policies'],$extra));}catch(InvalidArgumentException $e){$bad=true;}check($bad&&$before===$pdo->sqlCount,'strict plan rejects unsupported selector without I/O '.json_encode($extra));}
foreach([0,'0',17,'17',PHP_INT_MAX,(string)PHP_INT_MAX] as $number)check(vp3_radar_number_v1($number)===(int)$number,'accepts real PDO integer evidence '.json_encode($number));
foreach([null,false,true,1.0,'01','1e2','-1','999999999999999999999999'] as $number){$bad=false;try{vp3_radar_number_v1($number);}catch(Throwable $e){$bad=true;}check($bad,'rejects malformed numeric evidence '.json_encode($number));}

foreach(['chat.access','profile_agent.access'] as $permission){$permissions[$permission]=false;$r=readRadar();check($r['tool_receipt']['status']==='blocked'&&safeRadar($r)&&!str_contains($r['answer'],'Fixture'),$permission.' denial prevents private record output');resetRadar();}
$permissions['account.access']=false;check(readRadar()['tool_receipt']['status']==='read_complete','Radar does not invent an extra account.access prerequisite');resetRadar();
foreach(['foreign owner'=>['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>7],'foreign Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>9],'inactive Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>10],'wrong conversation Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],'profile visitor'=>['kind'=>'profile_agent','owner_user_id'=>1]] as $label=>$who){$r=readRadar('recent',[],$who);check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Fixture'),$label.' cannot read Radar records');}
foreach([2,999] as $cid)check(readRadar('recent',[],null,['conversation_id'=>$cid])['tool_receipt']['status']==='blocked','foreign or missing conversation '.$cid.' cannot read Radar records');
foreach(['account'=>'UPDATE users SET is_active=0 WHERE id=1','Agent'=>'UPDATE user_agents SET is_active=0 WHERE id=7','conversation'=>'UPDATE chat_conversations SET user_id=2 WHERE id=1','contact'=>'UPDATE vp3_agent_contacts SET owner_user_id=2 WHERE id=101','policy'=>'UPDATE vp3_agent_policies SET owner_user_id=2 WHERE id=101'] as $label=>$sql){
    beforeRadar($label==='policy'?'SELECT COUNT(*) FROM vp3_agent_policies':'SELECT p.id,p.action,p.updated_at',fn()=>db()->exec($sql));$r=readRadar();check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Fixture')&&empty($r['sources']),'late '.$label.' authority/ownership change discards fetched evidence');resetRadar();
}
beforeRadar('SELECT COUNT(*) FROM vp3_radar_properties',fn()=>db()->exec('UPDATE vp3_radar_properties SET owner_user_id=2 WHERE id=31'));$r=readRadar('scoped_rules');check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Fixture'),'late scoped-property reassignment discards the fetched property label');resetRadar();
beforeRadar('SELECT p.id,p.action,p.updated_at',function(): void{$GLOBALS['permissions']['profile_agent.access']=false;});check(readRadar()['tool_receipt']['status']==='blocked','late Profile Agent permission revocation discards fetched records');resetRadar();
$seeded=true;check(readRadar()['tool_receipt']['status']==='read_complete','seeded permission is read from the real role_permissions table');
writableRadar(fn()=>db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'"));check(readRadar()['tool_receipt']['status']==='blocked','missing seeded grant cannot fall back to role defaults');
writableRadar(fn()=>db()->exec('DROP TABLE role_permissions'),true);$r=readRadar();check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Fixture'),'failed seeded permission SQL never falls back to permissive defaults');
writableRadar(function(): void{db()->exec('CREATE TABLE role_permissions(permission_key VARCHAR(100),role VARCHAR(40))');db()->exec("INSERT INTO role_permissions VALUES('profile_agent.access','member')");},true);resetRadar();

foreach(['risk_score'=>null,'session_count'=>-1,'last_seen_at'=>null] as $column=>$value){$before=db()->query('SELECT '.$column.' FROM vp3_agent_contacts WHERE id=101')->fetchColumn();writableRadar(function()use($column,$value): void{$q=db()->prepare('UPDATE vp3_agent_contacts SET '.$column.'=? WHERE id=101');$q->execute([$value]);});$r=readRadar();check($r['tool_receipt']['status']==='failed'&&safeRadar($r)&&!str_contains($r['answer'],'Fixture'),'malformed '.$column.' is unavailable rather than silently zero or fresh');writableRadar(function()use($column,$before): void{$q=db()->prepare('UPDATE vp3_agent_contacts SET '.$column.'=? WHERE id=101');$q->execute([$before]);});}
foreach(['{"requests_per_30m":0}','{"requests_per_30m":"30oops"}','[30]','{"requests_per_30m":30,"extra":"'.str_repeat('x',65537).'"}'] as $json){writableRadar(function()use($json): void{$q=db()->prepare('UPDATE vp3_agent_policies SET metadata_json=? WHERE id=102');$q->execute([$json]);});$r=readRadar('contact_policies');check($r['tool_receipt']['status']==='failed'&&safeRadar($r),'invalid or oversized saved policy metadata cannot become a default rate limit');resetRadar();}
writableRadar(fn()=>db()->exec("UPDATE vp3_agent_policies SET metadata_json='{}' WHERE id=101"));check(readRadar()['tool_receipt']['status']==='read_complete','an empty canonical policy metadata object is allowed for non-limit policies');resetRadar();
writableRadar(fn()=>db()->exec('UPDATE vp3_agent_policies SET property_id=41 WHERE id=104'));$r=readRadar('scoped_rules');check($r['tool_receipt']['status']==='blocked'&&safeRadar($r),'a cross-owner property link cannot leak a foreign site label');resetRadar();
writableRadar(fn()=>db()->exec("UPDATE vp3_agent_policies SET action='allow' WHERE id=110"));check(readRadar('access_profile')['tool_receipt']['status']==='failed','Access Profile metadata cannot contradict its actual saved rules');resetRadar();
foreach([[110,'access_profile','set access profile to Private','set access profile to Monitor All'],[103,'scoped_rules','block operator Fixture OpenAI','monitor operator Fixture OpenAI']] as [$id,$view,$change,$same]){
    writableRadar(fn()=>db()->exec("UPDATE vp3_agent_policies SET metadata_json='SECRET invalid JSON' WHERE id=".$id));$r=readRadar($view);
    check($r['tool_receipt']['status']==='failed'&&safeRadar($r)&&!str_contains($r['answer'],'No explicit Access Profile'),$view.' cannot hide malformed canonical-priority metadata as absent settings');resetRadar();
    $json=(string)db()->query('SELECT metadata_json FROM vp3_agent_policies WHERE id='.$id)->fetchColumn();$pretty=json_encode(json_decode($json,true),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    writableRadar(function()use($pretty,$id): void{$q=db()->prepare('UPDATE vp3_agent_policies SET metadata_json=? WHERE id=?');$q->execute([$pretty,$id]);});
    check(readRadar($view)['tool_receipt']['status']==='read_complete',$view.' accepts valid whitespace-formatted source metadata');
    $count=policyCount();$r=commandRadar($change);check($r['tool_receipt']['status']==='failed'&&policyCount()===$count,$view.' change stops before a canonical writer that cannot deactivate the existing source marker');
    $r=commandRadar($same);check($r['tool_receipt']['status']==='read_complete'&&policyCount()===$count,$view.' unchanged whitespace-formatted policy remains a verified no-op');resetRadar();
}
writableRadar(fn()=>db()->exec("UPDATE vp3_agent_policies SET metadata_json='{\"source\":\"scoped_rule\",\"scope_type\":\"operator\",\"scope_value\":\"Different operator\"}' WHERE id=103"));check(readRadar('scoped_rules')['tool_receipt']['status']==='failed','scoped metadata is bound to its actual operator/class/path columns');resetRadar();
writableRadar(function(): void{$q=db()->prepare('UPDATE vp3_agent_contacts SET display_name=? WHERE id=101');$q->execute(['[click](https://evil.example/) <script>instructions</script>']);});$r=readRadar();check($r['tool_receipt']['status']==='read_complete'&&!str_contains($r['answer'],'[click](https://evil.example/)')&&!str_contains($r['answer'],'<script>'),'stored source labels are escaped as untrusted text');writableRadar(fn()=>db()->exec("UPDATE vp3_agent_contacts SET display_name='Fixture GPTBot' WHERE id=101"));
writableRadar(function(): void{$q=db()->prepare("INSERT INTO vp3_agent_contacts(id,owner_user_id,identity_key,display_name,operator_name,visitor_class,verification_status,risk_score,last_seen_at) VALUES(?,1,?,'Newer unrelated bot','Other','ai_crawler','unknown',0,'2099-01-01 00:00:00')");for($i=300;$i<330;$i++)$q->execute([$i,'extra'.$i]);});
$r=readRadar();check($r['tool_receipt']['count']===12&&str_contains($r['answer'],'More records'),'record display is bounded to twelve with explicit truncation');
$r=readRadar('recent',['query'=>'Fixture GPTBot']);check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'GPTBot'),'literal filtering happens before the result limit');writableRadar(fn()=>db()->exec('DELETE FROM vp3_agent_contacts WHERE id>=300'));
writableRadar(fn()=>db()->exec("UPDATE vp3_agent_policies SET expires_at='2000-01-01 00:00:00' WHERE id=101"));$r=readRadar('contact_policies',['contact_id'=>101]);check($r['tool_receipt']['status']==='no_matches','expired policy overrides do not appear as current saved policy');resetRadar();

foreach(['Can you block agent #101?','Do you have permission to read Agent Radar?','Does Agent Radar support blocking contacts?','Does your Radar tool allow block agent #101?','How do I block GPTBot?','Explain how Radar policies work','I want a tutorial about Radar contacts','If I block GPTBot, what happens?','Block agent #101 if I ask later','Tomorrow block GPTBot','What is Agent Radar?','What would blocking a bot do?'] as $query){$before=$pdo->sqlCount;$delegates=$delegateCalls;$r=vp3_radar_chat_tool($query,$user,1);check($pdo->sqlCount===$before&&$delegateCalls===$delegates&&!str_contains($r['answer']??'','Fixture'),'capability/tutorial/conditional prompt performs zero private I/O or delegation: '.$query);}
foreach(['show Agent Radar contacts'=>'recent','show high-risk agents'=>'high_risk','show Agent Gateway contact policies'=>'contact_policies','show Agent Gateway advanced rules'=>'scoped_rules','show Agent Gateway access profile'=>'access_profile'] as $query=>$view){$delegates=$delegateCalls;$r=vp3_radar_chat_tool($query,$user,1);check($r['tool_receipt']['capability']==='radar.read'&&$r['tool_receipt']['status']==='read_complete'&&$delegates===$delegateCalls,'explicit legacy '.$view.' uses the bounded adapter instead of broad delegated summaries');}
$delegates=$delegateCalls;$r=vp3_radar_chat_tool('show agent messaging requests',$user,1);check($r['answer']==='Existing incoming messaging delegate'&&$delegateCalls===$delegates+1,'incoming messaging requests retain their existing separate delegate');
foreach(['block agent #201','block Fixture','block Fixture OpenAI','block all Fixture OpenAI agents','block agent #101 and #102','use unknown Radar access profile','limit agent #101 to 0','limit agent #101 to 10001'] as $query){$before=policyCount();$r=commandRadar($query);check(!in_array($r['tool_receipt']['status'],['completed','read_complete'],true)&&policyCount()===$before,'ambiguous, foreign or unsupported command writes nothing: '.$query);}
writableRadar(fn()=>db()->exec("INSERT INTO vp3_agent_contacts(id,owner_user_id,display_name,operator_name) VALUES(103,1,'Fixture GPTBot','Duplicate')"));$before=policyCount();$r=commandRadar('block Fixture GPTBot');check($r['tool_receipt']['status']==='clarification_required'&&policyCount()===$before,'duplicate exact contact names require a contact ID rather than score-based tie breaking');writableRadar(fn()=>db()->exec('DELETE FROM vp3_agent_contacts WHERE id=103'));
check(vp3_radar_resolve_contact_v1($pdo,1,'Fixture GPTBót')===null,'SQL collation equivalence cannot replace an exact normalized contact identity');
$unicodeOperator=str_repeat('É',80);check(vp3_radar_command_v1('block operator '.$unicodeOperator)['scope_value']===$unicodeOperator,'explicit operator scope preserves the canonical UTF-8 character/width contract');
$unicodePath='/'.str_repeat('日',240);check(vp3_radar_command_v1('block bots on '.$unicodePath)['scope_value']===$unicodePath,'explicit path scope preserves the canonical UTF-8 character/width contract');
foreach(['block agent #101','allow Fixture GPTBot','limit agent #101 to 42 requests per 30 minutes','monitor fixture-gptbot','block operator Fixture OpenAI','block all search agents','block bots on /admin/*','set access profile to Search Friendly'] as $query){
    $before=$pdo->transactions;$r=commandRadar($query);check($r['tool_receipt']['status']==='completed'&&safeRadar($r)&&str_contains($r['answer'],'policy saved')&&str_contains($r['answer'],'Enforcement on connected sites depends'),$query.' verifies the canonical saved state without claiming live enforcement');
    check($pdo->transactions===$before+1,'canonical writer owns exactly one transaction for '.$query);
    $count=policyCount();$before=$pdo->transactions;$r=commandRadar($query);check($r['tool_receipt']['status']==='read_complete'&&policyCount()===$count&&$pdo->transactions===$before&&str_contains($r['answer'],'already saved'),'repeating unchanged '.$query.' verifies a no-op without another policy row');
}
check(vp3_radar_scoped_exact_v1($pdo,1,['scope_type'=>'class','scope_value'=>'ai_search'])['action']==='block','all search agents selects the explicit class, never an operator name');
$policy=vp3_radar_contact_policy_v1($pdo,1,101);check($policy['action']==='monitor','subsequent profile/class rules leave contact-specific saved override intact');resetRadar();
$permissions['profile_agent.access']=false;$count=policyCount();$r=commandRadar('block agent #101');check($r['tool_receipt']['status']==='blocked'&&policyCount()===$count,'current denied personal permission prevents a canonical write');resetRadar();
$count=policyCount();$pdo->afterCommit=fn()=>db()->exec('UPDATE users SET is_active=0 WHERE id=1');$r=commandRadar('block agent #101');check($r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='outcome_unverified'&&policyCount()===$count+1&&str_contains($r['answer'],'Effects may already exist'),'late account revocation after a commit reports uncertain outcome without retry');resetRadar();
$count=policyCount();$pdo->afterCommit=function(): void{throw new RuntimeException('SECRET lost commit acknowledgement');};$r=commandRadar('block agent #101');check($r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='outcome_unverified'&&policyCount()===$count+1&&safeRadar($r),'lost canonical completion response cannot become success or replay a paid/consequential write');resetRadar();
writableRadar(fn()=>radar_agent_fixture_v1(db(),'unverified_write'),true);$count=policyCount();$r=commandRadar('block agent #101');check($r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='outcome_unverified'&&policyCount()===$count+1,'a changed persisted policy state cannot produce a completed receipt or automatic retry');writableRadar(fn()=>db()->exec('DROP TRIGGER radar_fixture_outcome_v1'),true);resetRadar();
foreach(['recent','high_risk','contact_policies','scoped_rules','access_profile'] as $view){writableRadar(fn()=>radar_agent_fixture_v1(db(),'empty'),true);$r=readRadar($view);check($r['tool_receipt']['status']===($view==='access_profile'?'read_complete':'no_matches')&&$r['tool_receipt']['count']===0,'verified empty '.$view.' remains distinct from unavailable storage');resetRadar();}
writableRadar(fn()=>db()->exec('DROP TABLE vp3_agent_policies'),true);$r=readRadar();check(!vp3_radar_read_ready_v1($pdo)&&$r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='upgrade_required','missing schema reports unavailable instead of silently creating tables or an empty list');
check($pdo->writeAttempts===0&&$pdo->ddlAttempts===0,'all pure read paths and readiness probes performed zero record or schema writes');
echo 'AGENT_RADAR_READ_V1='.json_encode(['passed'=>$checks,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)],JSON_UNESCAPED_SLASHES)."\n";
