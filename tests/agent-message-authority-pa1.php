<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/personal-capabilities-v242.php';
require dirname(__DIR__).'/includes/profile-agent.php';
require dirname(__DIR__).'/includes/shared-knowledge-index-v236.php';
require dirname(__DIR__).'/includes/agent-radar-foundation.php';
require dirname(__DIR__).'/includes/agent-radar-native-profile.php';
require dirname(__DIR__).'/includes/agent-radar-gateway.php';
require dirname(__DIR__).'/includes/agent-access.php';
require dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
require __DIR__.'/fixtures/agent-message-authority-pa1.php';

/** SQLite only translates locking/date syntax; the same queries run natively on MySQL. */
class AgentMessagePDO extends PDO
{
    public bool $observe=false;public int $ddl=0;public $onStatement=null;
    private function sql(string $sql): string
    {
        if($this->observe&&preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$sql)){$this->ddl++;throw new RuntimeException('Unexpected schema mutation.');}
        if($this->onStatement)($this->onStatement)($sql);
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace([' FOR UPDATE','DATE_SUB(NOW(),INTERVAL 1 MINUTE)'],['',"datetime('now','-1 minute')"],$sql);
        return $sql;
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false{return parent::prepare($this->sql($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false{$query=$this->sql($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
    public function exec(string $statement): int|false{return parent::exec($this->sql($statement));}
}

function db(): PDO{if(!empty($GLOBALS['setup_failure']))throw new PDOException('SECRET fixture setup SQL failure');return $GLOBALS['pdo'];}
function table_exists(string $name): bool{$p=db();$q=$p->prepare($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{$p=db();if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=$p->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}return in_array($column,array_column($p->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function setting(string $key,mixed $default=null): mixed{return $key==='personal_capabilities_seed_v242'?'1':$default;}
function permissions_schema_ready(): bool{return table_exists('role_permissions');}
function user_has_role(string $role,array $user): bool{return ($user['role']??'')===$role;}
function user_roles_for_user(array $user): array{return [(string)$user['role']];}
function user_roles(): array{return ['member'=>[]];}
// Subscription service fixture, using current stored entitlement on every call.
function subscription_has_entitlement(?array $user,string $key): bool{$q=db()->prepare('SELECT is_enabled FROM fixture_entitlements WHERE user_id=? AND capability=?');$q->execute([(int)($user['id']??0),$key]);return(bool)$q->fetchColumn();}
function chat_policy_terms_v236(string $query): array{return array_values(array_filter(preg_split('/\s+/',mb_strtolower($query))?:[],fn($x)=>mb_strlen($x)>2));}
function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $usageScope=null,?array $turnControl=null): array
{
    $GLOBALS['provider_calls'][]=compact('query','history','context','user','usageScope','turnControl');
    if($GLOBALS['during_provider'])($GLOBALS['during_provider'])(db());
    return $GLOBALS['provider_result']??['ok'=>true,'answer'=>'Approved public reply.','provider'=>'fixture','model'=>'fixture-model','usage'=>['input_tokens'=>10,'output_tokens'=>5]];
}

$dsn=defined('VP3_AGENT_MESSAGE_API_FIXTURE')?'':(getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_SECTION7_MYSQL_DSN'));$dbUser=null;$dbPass=null;
if($dsn){$dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPass=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture';$admin=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='vp3_agent_message_'.bin2hex(random_bytes(5));$admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$name,$dsn):rtrim($dsn,';').';dbname='.$name;register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$name.'`'));}else $dsn='sqlite::memory:';
$pdo=new AgentMessagePDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));else{$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
$tokens=agent_message_fixture_pa1($pdo);$snapshots=[];
foreach(['users','user_profiles','user_agents','role_permissions','fixture_entitlements','vp3_radar_properties','vp3_agent_registry','vp3_agent_contacts','vp3_agent_access_requests','vp3_agent_policies','knowledge_items','user_data_policies','user_agent_data_rules'] as $table)$snapshots[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
$pdo->observe=true;$checks=0;
function amTest(bool $ok,string $message): void{if(!$ok)throw new RuntimeException('FAIL '.$message);$GLOBALS['checks']++;echo 'PASS '.$message."\n";}
function amReset(): void
{
    $p=db();$p->onStatement=null;$GLOBALS['provider_calls']=[];$GLOBALS['during_provider']=null;unset($GLOBALS['provider_result']);
    foreach($GLOBALS['snapshots'] as $table=>$rows){$p->exec('DELETE FROM '.$table);foreach($rows as $row)agent_message_fixture_put_pa1($p,$table,$row);}
    $p->exec('DELETE FROM vp3_radar_events');
}
function amContext(): array{return vp3_agent_access_owner_context(db(),'fixture',false)??throw new RuntimeException('Missing fixture context.');}
function amClaim(int $id=51): array{return vp3_agent_access_grant_claim(db(),1,$GLOBALS['tokens'][$id],'ChatGPT-User fixture')??throw new RuntimeException('Claim failed.');}
function amValue(string $sql): mixed{return db()->query($sql)->fetchColumn();}
function amDenied(callable $call,string $expected='VP3_AGENT_MESSAGE_AUTHORITY'): bool{try{$call();return false;}catch(Throwable $e){return $e->getMessage()===$expected;}}
function amEvent(array $context,array $grant,string $message,string $type='agent_message_inbound',array $patch=[]): int
{
    $details=array_replace(vp3_agent_message_binding_v1($context,$grant),['capability'=>'agent.message','message'=>$message],$type==='agent_message_outbound'?['sources'=>[['source'=>'knowledge:101','title'=>'Public brochure']],'source_dependencies'=>[['source'=>'knowledge:101']]]:[],$patch);
    $id=vp3_agent_access_event(db(),$context['property'],['id'=>(int)$grant['agent_contact_id']],$type,'Fixture event.',$details);
    db()->prepare('UPDATE vp3_radar_events SET occurred_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-600),$id]);return $id;
}

if(defined('VP3_AGENT_MESSAGE_API_FIXTURE')){
    amReset();$mode=(string)($_SERVER['HTTP_X_FIXTURE_MODE']??'');
    if($mode==='provider_error')$provider_result=['ok'=>false];
    if($mode==='provider_exception')$during_provider=static fn()=>throw new RuntimeException('SECRET provider exception /private/path');
    if($mode==='quota')$provider_result=['ok'=>false,'quota_exhausted'=>true];
    if($mode==='grant_denied')$during_provider=static fn(PDO $p)=>$p->exec("UPDATE vp3_agent_access_requests SET status='denied' WHERE id=51");
    if($mode==='grant_expired')$during_provider=static fn(PDO $p)=>$p->exec("UPDATE vp3_agent_access_requests SET approved_until='2000-01-01 00:00:00' WHERE id=51");
    if($mode==='history'){
        $ctx=amContext();$a=amClaim();$b=amClaim(52);amEvent($ctx,$a,'Own history only.');for($i=0;$i<20;$i++)amEvent($ctx,$b,'SECRET another caller '.$i);
    }
    if($mode==='rate'){
        $ctx=amContext();$a=amClaim();for($i=0;$i<6;$i++)amEvent($ctx,$a,'Recent rate event.');$pdo->exec("UPDATE vp3_radar_events SET occurred_at=NOW() WHERE event_type='agent_message_inbound'");
    }
    function vp3_agent_referral_create_for_message(PDO $pdo,array $context,array $grant): ?array
    {
        $mode=(string)($_SERVER['HTTP_X_FIXTURE_MODE']??'');
        if($mode==='referral_denied')$pdo->exec("UPDATE vp3_agent_access_requests SET status='denied' WHERE id=51");
        if($mode==='referral_source_revoked')$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");
        return str_starts_with($mode,'referral_')?['url'=>'https://fixture.invalid/SECRET_REFERRAL','expires_at'=>gmdate('c',time()+60)]:null;
    }
    register_shutdown_function(static function():void{
        $p=$GLOBALS['pdo'];$file=(string)getenv('VP3_MESSAGE_HTTP_AUDIT');if($file==='')return;
        $q=$p->query('SELECT id,status,use_count FROM vp3_agent_access_requests ORDER BY id');
        file_put_contents($file,json_encode(['grants'=>$q->fetchAll(),'provider_calls'=>count($GLOBALS['provider_calls']),'history'=>$GLOBALS['provider_calls'][0]['history']??[],'ddl'=>$p->ddl],JSON_THROW_ON_ERROR));
    });
    if($mode==='setup_error')$GLOBALS['setup_failure']=true;
    return;
}

amReset();$ctx=amContext();$a=amClaim();$b=amClaim(52);
amEvent($ctx,$a,'Caller A private query.');amEvent($ctx,$a,'Caller A private answer.','agent_message_outbound');
for($i=0;$i<24;$i++)amEvent($ctx,$b,'SECRET caller B query '.$i);
$history=vp3_agent_message_history($pdo,$ctx,$a,12);
amTest(array_column($history,'message')===['Caller A private query.','Caller A private answer.'],'exact grant filter precedes LIMIT even when another caller shares the Radar contact');
amTest(array_column($history,'role')===['user','assistant'],'history preserves canonical event roles and chronology');
amTest(count(vp3_agent_message_history($pdo,$ctx,$b,99))===20,'history is bounded to twenty matching events');
amTest(vp3_agent_access_grant_claim($pdo,1,$tokens[54],'ChatGPT-User')===null,'another owner token cannot claim this Profile');
amTest(vp3_agent_access_grant_claim($pdo,1,$tokens[51],'Claude-User')===null,'a different registry caller cannot claim the grant');
amTest(vp3_agent_access_grant_claim($pdo,1,str_repeat('e',64),'ChatGPT-User')===null,'a known brand without the exact secret has no grant');

foreach(['legacy'=>['contract'=>null],'wrong_request'=>['request_id'=>52],'numeric_string'=>['request_id'=>'51'],'fractional_id'=>['request_id'=>51.1],'wrong_agent'=>['profile_agent_id'=>8],'wrong_profile'=>['profile_username'=>'other'],'case_changed_profile'=>['profile_username'=>'FIXTURE'],'wrong_binding'=>['grant_binding'=>str_repeat('f',64)],'array_message'=>['message'=>['SECRET']],'object_message'=>['message'=>['text'=>'SECRET']],'boolean_message'=>['message'=>true],'oversized_message'=>['message'=>str_repeat('x',2001)]] as $label=>$patch){
    amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Safe existing query.');amEvent($ctx,$a,'SECRET malformed row.','agent_message_inbound',$patch);
    amTest(array_column(vp3_agent_message_history($pdo,$ctx,$a),'message')===['Safe existing query.'],$label.' history cannot become model context');
}
foreach(['{','null','[]','true','123',str_repeat(' ',131073).'{}'] as $bad){
    amReset();$ctx=amContext();$a=amClaim();$id=amEvent($ctx,$a,'SECRET invalid JSON.');$pdo->prepare('UPDATE vp3_radar_events SET details_json=? WHERE id=?')->execute([$bad,$id]);
    amTest(vp3_agent_message_history($pdo,$ctx,$a)===[],'malformed or unbounded JSON history is excluded safely');
}
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'SECRET former Agent context.');$pdo->exec('UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1');$new=amContext();
amTest(vp3_agent_message_history($pdo,$new,$a)===[],'changing the current Profile Agent does not inherit the prior Agent history');
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'SECRET old token context.');$pdo->prepare('UPDATE vp3_agent_access_requests SET request_token_hash=? WHERE id=51')->execute([hash('sha256',str_repeat('e',64))]);$rotated=vp3_agent_access_grant_claim($pdo,1,str_repeat('e',64),'ChatGPT-User');
amTest(vp3_agent_message_history($pdo,$ctx,$rotated)===[],'a replaced grant secret cannot inherit old caller history');

amReset();$ctx=amContext();$a=amClaim();$b=amClaim(52);amEvent($ctx,$a,'Own caller history.');amEvent($ctx,$b,'SECRET other caller history.');
$result=vp3_agent_message_generate($pdo,$ctx,$a,'Public brochure');$sent=$provider_calls[0];
amTest($result['answer']==='Approved public reply.'&&count($provider_calls)===1,'valid approved grant returns a generated public reply');
amTest(array_column($sent['history'],'message')===['Own caller history.'],'actual provider receives only the exact grant history');
amTest(array_column($sent['context'],'source')===['profile:identity','profile:rules','knowledge:101']&&!str_contains(json_encode($sent),'SECRET'),'actual canonical context excludes private and foreign knowledge');
amTest($sent['usageScope']==='agent.messaging'&&$sent['user']['id']==1&&$sent['turnControl']===['surface'=>'profile_agent','profile_owner_user_id'=>1,'profile_agent_id'=>7,'profile_agent_name'=>'Profile Agent 7','profile_owner_name'=>'Owner 1','profile_instructions'=>'Use approved public facts.'],'external inference uses the exact public owner/Agent prompt and existing messaging quota');
amTest((int)amValue("SELECT COUNT(*) FROM vp3_radar_events WHERE event_type='agent_message_outbound'")===1,'successful reply is stored as a bound outbound event beside historical activity');

foreach(['missing'=>null,'string'=>'knowledge:101','unknown'=>[['source'=>'private:anything']],'foreign'=>[['source'=>'knowledge:103']],'malformed'=>[['source'=>['knowledge:101']]],'unbounded'=>array_fill(0,21,['source'=>'knowledge:101'])] as $label=>$sources){
    amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Safe visitor question.');amEvent($ctx,$a,'SECRET unprovable answer.','agent_message_outbound',['sources'=>$sources]);
    amTest(array_column(vp3_agent_message_history($pdo,$ctx,$a),'message')===['Safe visitor question.'],$label.' historical assistant source proof is omitted');
}
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Historical public answer.','agent_message_outbound');$proofs=[];
amTest(count(vp3_agent_message_history($pdo,$ctx,$a,12,$proofs))===1&&count($proofs)===1,'currently allowed historical answer retains server-side source proof');
$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");
amTest(vp3_agent_message_history($pdo,$ctx,$a)===[],'revoked public source removes old assistant facts from the next history read');

function amCurrentGuide(): void{agent_message_fixture_put_pa1(db(),'knowledge_items',['id'=>104,'title'=>'Current guide','description'=>'Current public material','content_text'=>'Current guide is available.','visibility'=>'public','is_published'=>1,'created_by_user_id'=>1,'updated_at'=>gmdate('Y-m-d H:i:s'),'knowledge_scope'=>'personal']);}
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'SECRET former brochure answer.','agent_message_outbound');amCurrentGuide();$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");
vp3_agent_message_generate($pdo,$ctx,$a,'Current guide');
amTest($provider_calls[0]['history']===[]&&array_column($provider_calls[0]['context'],'source')===['profile:identity','profile:rules','knowledge:104'],'the real provider never receives revoked historical facts even when current inference is otherwise allowed');
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Previously public answer.','agent_message_outbound');amCurrentGuide();$during_provider=static fn(PDO $p)=>$p->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");
amTest(amDenied(function()use($pdo,$ctx,&$a){vp3_agent_message_generate($pdo,$ctx,$a,'Current guide');})&&count($provider_calls[0]['history'])===1&&(int)amValue("SELECT COUNT(*) FROM vp3_radar_events WHERE event_type='agent_message_outbound'")===1,'history-only source revocation during inference blocks output even when current-query sources stay public');
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Previously public answer.','agent_message_outbound');amCurrentGuide();$finished=vp3_agent_message_generate($pdo,$ctx,$a,'Current guide');$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");
amTest(amDenied(fn()=>vp3_agent_message_context_authorized_v1($pdo,$ctx,$finished['_approved_context'],$finished['_history_contexts'])),'the API final source check also detects history-only revocation after optional referral work');
amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'Original brochure fact.','agent_message_outbound');amCurrentGuide();$provider_result=['ok'=>true,'answer'=>'Derived brochure fact from prior history.'];
vp3_agent_message_generate($pdo,$ctx,$a,'Current guide');$saved=json_decode((string)amValue("SELECT details_json FROM vp3_radar_events WHERE event_type='agent_message_outbound' ORDER BY id DESC LIMIT 1"),true);
amTest(array_column($saved['sources'],'source')===['knowledge:104']&&array_column($saved['source_dependencies'],'source')===['knowledge:101','knowledge:104'],'derived answer stores transitive source dependencies separately from its current public citations');
$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101");$third=vp3_agent_message_history($pdo,$ctx,$a);
amTest(!in_array('Original brochure fact.',array_column($third,'message'),true)&&!in_array('Derived brochure fact from prior history.',array_column($third,'message'),true),'third-turn history excludes derived answers when an original historical source is revoked');
foreach([null,'knowledge:101',array_fill(0,129,['source'=>'knowledge:101'])] as $dependencies){amReset();$ctx=amContext();$a=amClaim();amEvent($ctx,$a,'SECRET unprovable dependency.','agent_message_outbound',['source_dependencies'=>$dependencies]);amTest(vp3_agent_message_history($pdo,$ctx,$a)===[],'missing malformed or unbounded transitive source proof stays out of history');}

$mutations=[
 'grant denied'=>"UPDATE vp3_agent_access_requests SET status='denied' WHERE id=51",
 'grant expired'=>"UPDATE vp3_agent_access_requests SET approved_until='2000-01-01 00:00:00' WHERE id=51",
 'grant secret changed'=>"UPDATE vp3_agent_access_requests SET request_token_hash='".str_repeat('f',64)."' WHERE id=51",
 'grant contact changed'=>'UPDATE vp3_agent_access_requests SET agent_contact_id=43 WHERE id=51',
 'grant property changed'=>'UPDATE vp3_agent_access_requests SET property_id=12 WHERE id=51',
 'grant capability changed'=>"UPDATE vp3_agent_access_requests SET capability='other' WHERE id=51",
 'grant removed'=>'DELETE FROM vp3_agent_access_requests WHERE id=51',
 'contact owner changed'=>'UPDATE vp3_agent_contacts SET owner_user_id=2 WHERE id=41',
 'caller registry disabled'=>'UPDATE vp3_agent_registry SET is_active=0 WHERE id=31',
 'owner disabled'=>'UPDATE users SET is_active=0 WHERE id=1',
 'owner permission revoked'=>"DELETE FROM role_permissions WHERE permission_key='profile_chat.access'",
 'owner entitlement revoked'=>"UPDATE fixture_entitlements SET is_enabled=0 WHERE user_id=1",
 'Profile made private'=>'UPDATE user_profiles SET is_public=0 WHERE user_id=1',
 'Profile messaging disabled'=>'UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1',
 'Profile renamed'=>"UPDATE user_profiles SET username='renamed' WHERE user_id=1",
 'Profile Agent replaced'=>'UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1',
 'Agent disabled'=>'UPDATE user_agents SET is_active=0 WHERE id=7',
 'Agent owner changed'=>'UPDATE user_agents SET owner_user_id=2 WHERE id=7',
 'Agent instructions changed'=>"UPDATE user_agents SET instructions='Different instructions.' WHERE id=7",
 'native property disabled'=>'UPDATE vp3_radar_properties SET is_active=0 WHERE id=11',
 'native property owner changed'=>'UPDATE vp3_radar_properties SET owner_user_id=2 WHERE id=11',
 'public knowledge unpublished'=>'UPDATE knowledge_items SET is_published=0 WHERE id=101',
 'public knowledge made private'=>"UPDATE knowledge_items SET visibility='private' WHERE id=101",
 'public knowledge reassigned'=>'UPDATE knowledge_items SET created_by_user_id=2 WHERE id=101',
 'source sharing revoked'=>'UPDATE user_data_policies SET profile_agent_allowed=0 WHERE owner_user_id=1',
 'Agent source rule denied'=>"INSERT INTO user_agent_data_rules (agent_id,resource_type,resource_id,access_mode) VALUES (7,'knowledge','*','deny')",
];
foreach($mutations as $label=>$sql){
    amReset();$ctx=amContext();$a=amClaim();$during_provider=static fn(PDO $p)=>$p->exec($sql);
    amTest(amDenied(function()use($pdo,$ctx,&$a){vp3_agent_message_generate($pdo,$ctx,$a,'Public brochure');}),$label.' during inference suppresses the generated reply');
    amTest((int)amValue("SELECT COUNT(*) FROM vp3_radar_events WHERE event_type='agent_message_outbound'")===0,$label.' produces no successful outbound event');
}
amReset();$ctx=amContext();$a=amClaim();$during_provider=static function(PDO $p):void{agent_message_fixture_put_pa1($p,'vp3_agent_policies',['id'=>1,'owner_user_id'=>1,'property_id'=>11,'agent_contact_id'=>41,'operator_name'=>'','visitor_class'=>'','path_pattern'=>'*','action'=>'block','priority'=>1,'is_active'=>1,'expires_at'=>null,'metadata_json'=>null]);};
amTest(amDenied(function()use($pdo,$ctx,&$a){vp3_agent_message_generate($pdo,$ctx,$a,'Public brochure');},'Agent Gateway denies messaging for this contact.'),'a Gateway block during inference suppresses the generated reply');

amReset();$ctx=amContext();$once=amClaim(53);
amTest(amValue('SELECT status FROM vp3_agent_access_requests WHERE id=53')==='consumed'&&vp3_agent_access_grant_claim($pdo,1,$tokens[53],'ChatGPT-User')===null,'one-use grant is atomically consumed before work and cannot be claimed twice');
amTest(amDenied(function()use($pdo,$ctx,&$once){vp3_agent_message_generate($pdo,$ctx,$once,'');},'Enter an agent message up to 2,000 characters.')&&vp3_agent_access_restore_once($pdo,1,53,$once,$ctx),'unchanged pre-provider validation failure restores exactly that one-use grant');
$second=amClaim(53);
amTest(!vp3_agent_access_restore_once($pdo,1,53,$once,$ctx)&&amValue('SELECT status FROM vp3_agent_access_requests WHERE id=53')==='consumed','an old failure cannot restore a newer claim even within the same second');
amTest(vp3_agent_access_restore_once($pdo,1,53,$second,$ctx),'the actual current unused claim remains safely restorable');
amReset();$ctx=amContext();$once=amClaim(53);$provider_result=['ok'=>false,'quota_exhausted'=>true];
amTest(amDenied(function()use($pdo,$ctx,&$once){vp3_agent_message_generate($pdo,$ctx,$once,'Public brochure');},'VP3_AGENT_MESSAGE_QUOTA')&&vp3_agent_access_restore_once($pdo,1,53,$once,$ctx),'canonical pre-provider quota refusal preserves one-use retry behavior');
amReset();$ctx=amContext();$once=amClaim(53);$provider_result=['ok'=>false];
amTest(amDenied(function()use($pdo,$ctx,&$once){vp3_agent_message_generate($pdo,$ctx,$once,'Public brochure');},'VP3_AGENT_MESSAGE_PROVIDER')&&!vp3_agent_access_restore_once($pdo,1,53,$once,$ctx)&&amValue('SELECT status FROM vp3_agent_access_requests WHERE id=53')==='consumed','uncertain provider failure stays consumed instead of replaying an approved-once operation');
amReset();$ctx=amContext();$once=amClaim(53);$during_provider=static fn(PDO $p)=>$p->exec("UPDATE vp3_agent_access_requests SET status='denied' WHERE id=53");
amTest(amDenied(function()use($pdo,$ctx,&$once){vp3_agent_message_generate($pdo,$ctx,$once,'Public brochure');})&&!vp3_agent_access_restore_once($pdo,1,53,$once,$ctx)&&amValue('SELECT status FROM vp3_agent_access_requests WHERE id=53')==='denied','late revoked one-use grant is never resurrected');
amReset();$ctx=amContext();$once=amClaim(53);$out=vp3_agent_message_generate($pdo,$ctx,$once,'hello');
amTest(str_contains($out['answer'],'Hello')&&count($provider_calls)===0&&!vp3_agent_access_restore_once($pdo,1,53,$once,$ctx),'successful local greeting consumes one-use authority without a provider call');
amReset();$ctx=amContext();$a=amClaim();$out=vp3_agent_message_generate($pdo,$ctx,$a,'Unrelated question');
amTest(count($provider_calls)===0&&str_contains($out['answer'],'approved public information'),'missing public evidence retains truthful local fallback');
amReset();$ctx=amContext();$a=amClaim();$b=amClaim(52);for($i=0;$i<6;$i++){amEvent($ctx,$a,'Recent aggregate message.');}$pdo->exec("UPDATE vp3_radar_events SET occurred_at=NOW() WHERE event_type='agent_message_inbound'");
amTest(amDenied(function()use($pdo,$ctx,&$b){vp3_agent_message_generate($pdo,$ctx,$b,'Public brochure');},'Agent messaging rate limit reached.')&&count($provider_calls)===0,'rate limits remain owner/contact aggregate across grants');
amReset();$ctx=amContext();$a=amClaim();$pdo->exec('UPDATE vp3_radar_properties SET is_active=0 WHERE id=11');
amTest(vp3_agent_access_owner_context($pdo,'fixture',false)===null&&(int)amValue('SELECT is_active FROM vp3_radar_properties WHERE id=11')===0,'messaging authority does not reactivate a disabled native property');
amTest($pdo->ddl===0,'message execution and authorization perform no schema writes');
echo 'AGENT_MESSAGE_AUTHORITY_PA1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; real grant/history/context/Gateway services)' . "\n";
