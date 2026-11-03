<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-profile-webmcp-outcomes-v1.php';
require dirname(__DIR__).'/includes/profile-webmcp-actions-v150.php';
require __DIR__.'/fixtures/profile-webmcp-outcomes-v1.php';

class WebmcpOutcomePDO extends PDO
{
    public bool $readOnly=false;public int $writeAttempts=0;public $onRead=null;
    private function guard(string $sql): void{if($this->readOnly&&!preg_match('/^\s*SELECT\b/i',$sql)){$this->writeAttempts++;throw new RuntimeException('Unexpected evidence write.');}if($this->onRead&&preg_match('/^\s*SELECT\b/i',$sql))($this->onRead)($sql);}
    public function exec(string $sql): int|false{$this->guard($sql);return parent::exec($sql);}
    public function prepare(string $sql,array $options=[]): PDOStatement|false{$this->guard($sql);if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace('DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)',"datetime(UTC_TIMESTAMP(),'+' || ? || ' seconds')",$sql);return parent::prepare($sql,$options);}
    public function query(string $sql,?int $mode=null,mixed ...$args): PDOStatement|false{$this->guard($sql);return $mode===null?parent::query($sql):parent::query($sql,$mode,...$args);}
}
function url(string $path): string{return 'https://fixture.invalid'.$path;}
function table_exists(string $table): bool{$p=$GLOBALS['pdo'];$q=$p->prepare($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{$p=$GLOBALS['pdo'];if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=$p->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}return in_array($column,array_column($p->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
$dsn=getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_SECTION7_MYSQL_DSN');$dbUser=null;$dbPass=null;
if($dsn){$dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPass=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture';$admin=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='vp3_webmcp_outcome_'.bin2hex(random_bytes(5));$admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$name,$dsn):rtrim($dsn,';').';dbname='.$name;register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$name.'`'));}else $dsn='sqlite::memory:';
$pdo=new WebmcpOutcomePDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));else $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$cases=profile_webmcp_outcome_fixture_v1($pdo);$checks=0;$contexts=[];
function woTest(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
function woReject(callable $run): bool{try{$run();return false;}catch(Throwable){return true;}}
function woSafe(array $out): bool{$json=json_encode($out);return !str_contains($json,'SECRET')&&!str_contains($json,'https:')&&!str_contains($json,'token')&&!str_contains($json,'_id');}
function woWritable(callable $run): mixed{$old=$GLOBALS['pdo']->readOnly;$GLOBALS['pdo']->readOnly=false;try{return $run();}finally{$GLOBALS['pdo']->readOnly=$old;}}
function woCommit(array $case): void
{
    $p=$GLOBALS['pdo'];$a=$case['action'];$idem=hash('sha256',$a['owner_user_id'].'|'.$a['operation'].'|'.$case['args']['idempotency_key']);
    if($case['spec'][2]==='order'){$q=$p->prepare("UPDATE profile_webmcp_actions SET state='committed',result_type='order',result_id=?,result_json=?,idempotency_hash=?,committed_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared'");$q->execute([$case['resultId'],json_encode($case['result']),$idem,$a['id']]);}
    else vp3_profile_webmcp_action_commit_v150($p,$a['id'],$idem,$case['spec'][2],$case['resultId'],$case['result']);
}

foreach($cases as $op=>$case){
    $pdo->readOnly=true;$patch=vp3_profile_webmcp_server_evidence_v1($pdo,$case['profile'],$case['context'],$case['transport'],$case['spec'][0],[],$case['prepare']);$context=array_replace($case['context'],$patch);$contexts[$op]=$context;
    woTest($patch['server_outcome']['verification']==='verified'&&$patch['server_outcome']['state']==='prepared'&&woSafe($patch['server_outcome']),$op.' binds the actual prepared intent without exposing private fields');
    vp3_profile_webmcp_request_binding_v1($pdo,$case['profile'],$context,$case['transport'],$case['spec'][1],$case['args']);
    woWritable(fn()=>woCommit($case));
    $recovered=vp3_profile_webmcp_outcome_read_v1($pdo,$context);woTest($recovered['state']==='recorded'&&$recovered['result']===$case['spec'][3],$op.' recovers a committed exact intent even if the browser lost its response');
    $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$case['profile'],$context,$case['transport'],$case['spec'][1],$case['args'],$case['result']);$context=array_replace($context,$patch);$contexts[$op]=$context;
    woTest($patch['server_outcome']['state']==='recorded'&&$patch['server_outcome']['result']===$case['spec'][3]&&woSafe($patch['server_outcome']),$op.' projects the operation-specific canonical receipt');
    $replay=vp3_profile_webmcp_server_evidence_v1($pdo,$case['profile'],$context,$case['transport'],$case['spec'][1],$case['args'],$case['result']+['idempotent_replay'=>true]);woTest($replay['server_outcome']['replayed']===true,$op.' records a verified same-intent canonical replay');
}
woTest(str_contains(vp3_profile_webmcp_outcome_text_v1($contexts['commerce.checkout']['server_outcome']),'does not establish that payment completed'),'checkout receipt cannot claim paid');
woTest(str_contains(vp3_profile_webmcp_outcome_text_v1($contexts['commerce.refund_request']['server_outcome']),'does not establish that money was refunded'),'refund request receipt cannot claim refunded');
woTest(str_contains(vp3_profile_webmcp_outcome_text_v1($contexts['reward.claim_handoff']['server_outcome']),'not redeemed'),'Reward handoff cannot claim redemption');
$rows=$pdo->query('SELECT * FROM profile_webmcp_actions')->fetchAll();
function woReset(): void{woWritable(function():void{$p=$GLOBALS['pdo'];$p->onRead=null;$p->exec('DELETE FROM profile_webmcp_actions');foreach($GLOBALS['rows'] as $row)profile_webmcp_outcome_fixture_put_v1($p,'profile_webmcp_actions',$row);});}
$base=$cases['booking.create'];$ctx=$contexts['booking.create'];
foreach(['owner_user_id'=>2,'profile_username'=>'FIXTURE','surface'=>'external_site','property_id'=>3,'session_hash'=>str_repeat('b',64),'operation'=>'booking.cancel','payload_hash'=>str_repeat('b',64),'intent_id'=>strtoupper($base['action']['intent_id']),'idempotency_hash'=>str_repeat('b',64),'state'=>'failed','result_type'=>'order','result_id'=>999,'result_json'=>'{"ok":true}','committed_at'=>'2099-01-01 00:00:00'] as $field=>$value){
    woReset();woWritable(function()use($pdo,$field,$value,$base):void{$pdo->prepare('UPDATE profile_webmcp_actions SET '.$field.'=? WHERE id=?')->execute([$value,$base['action']['id']]);});$out=vp3_profile_webmcp_outcome_read_v1($pdo,$ctx);woTest($out['verification']==='unverified'&&woSafe($out),'changed '.$field.' cannot verify a result');
}
woReset();$bad=$ctx;$bad['server_outcome']=['contract'=>'vp3.webmcp.outcome.v1','verification'=>'verified','state'=>'recorded'];unset($bad['server_action']);woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$bad)['verification']==='unverified','cached or legacy completed context without a binding stays unverified');
foreach(['profile_user_id'=>2,'profile_username'=>'FIXTURE','created_at'=>time()+5] as $key=>$value){$bad=$ctx;$bad[$key]=$value;woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$bad)['verification']==='unverified','wrong source context '.$key.' is rejected');}
// Keep the canonical LONGTEXT storage capacity; the reader, not MySQL TEXT,
// must enforce the 64KiB limit. JSON whitespace preserves an otherwise valid receipt.
$validReceipt=vp3_profile_webmcp_replay_projection_v1('booking',$base['resultId'],$base['result']);
$validJson=json_encode($validReceipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$atLimit=str_pad($validJson,65536,' ');$oversized=str_pad($validJson,65537,' ');
woReset();woWritable(fn()=>$pdo->prepare('UPDATE profile_webmcp_actions SET result_json=? WHERE id=?')->execute([$atLimit,$base['action']['id']]));
$q=$pdo->prepare('SELECT result_json FROM profile_webmcp_actions WHERE id=?');$q->execute([$base['action']['id']]);
woTest($q->fetchColumn()===$atLimit&&vp3_profile_webmcp_outcome_read_v1($pdo,$ctx)['verification']==='verified','canonical receipt at exactly 64KiB round-trips unchanged and remains verified');
foreach(['null'=>null,'array'=>'["not-an-object"]','oversized'=>$oversized,'numeric'=>'0','malformed'=>'{'] as $label=>$value){
    woReset();woWritable(fn()=>$pdo->prepare('UPDATE profile_webmcp_actions SET result_json=? WHERE id=?')->execute([$value,$base['action']['id']]));
    if($label==='oversized'){
        $q=$pdo->prepare('SELECT result_json FROM profile_webmcp_actions WHERE id=?');$q->execute([$base['action']['id']]);$stored=$q->fetchColumn();
        woTest($stored===$oversized&&strlen($stored)===65537&&json_decode($stored,true,512,JSON_THROW_ON_ERROR)===$validReceipt,'oversized valid receipt reaches the reader with exact LONGTEXT byte fidelity');
    }
    woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$ctx)['verification']==='unverified',$label.' receipt body stays unverified');
}
woReset();woWritable(fn()=>$pdo->exec('ALTER TABLE profile_webmcp_actions RENAME TO fixture_missing_actions'));woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$ctx)['verification']==='unverified','missing ledger schema is unverified without initialization');woWritable(fn()=>$pdo->exec('ALTER TABLE fixture_missing_actions RENAME TO profile_webmcp_actions'));
woReset();woWritable(fn()=>$pdo->prepare('DELETE FROM profile_webmcp_actions WHERE id=?')->execute([$base['action']['id']]));woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$ctx)['verification']==='unverified','missing exact receipt stays unverified');
woReset();$pdo->onRead=function($sql)use($pdo,$base):void{if(str_starts_with($sql,'SELECT id FROM agent_scheduling_bookings')){$pdo->onRead=null;woWritable(fn()=>$pdo->prepare('UPDATE profile_webmcp_actions SET result_id=999 WHERE id=?')->execute([$base['action']['id']]));}};woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$ctx)['verification']==='unverified','receipt identity changed during canonical result verification is discarded');
woReset();$bad=$ctx;$bad['allowed_tools']=[];woTest(woReject(fn()=>vp3_profile_webmcp_request_binding_v1($pdo,$base['profile'],$bad,$base['transport'],$base['spec'][1],$base['args'])),'handoff tool allowlist is checked before execution');
foreach(['owner_user_id'=>2,'profile_username'=>'other','surface'=>'external_site','property_id'=>9,'session_hash'=>str_repeat('c',64)] as $key=>$value){$transport=$base['transport'];$transport[$key]=$value;woTest(woReject(fn()=>vp3_profile_webmcp_request_binding_v1($pdo,$base['profile'],$ctx,$transport,$base['spec'][1],$base['args'])),'wrong native transport '.$key.' is denied before execution');}
$args=$base['args'];$args['intent']['target']=999;woTest(woReject(fn()=>vp3_profile_webmcp_request_binding_v1($pdo,$base['profile'],$ctx,$base['transport'],$base['spec'][1],$args)),'changed canonical payload is denied before execution');
$args=$base['args'];$parts=explode('.',$args['confirmation_token']);$claims=json_decode(base64_decode(strtr($parts[0],'-_','+/')),true);$claims['intent_id']=str_repeat('f',32);$args['confirmation_token']=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=').'.'.$parts[1];woTest(woReject(fn()=>vp3_profile_webmcp_request_binding_v1($pdo,$base['profile'],$ctx,$base['transport'],$base['spec'][1],$args)),'even same-payload confirmation must name the exact bound prepared intent');
woTest(vp3_profile_webmcp_server_evidence_v1($pdo,$base['profile'],$ctx,$base['transport'],$base['spec'][0],[],['provider_selection_required'=>true,'confirmation_required'=>false])===[],'successful no-intent preparation never creates or replaces committed evidence');
woTest(vp3_profile_webmcp_server_evidence_v1($pdo,$base['profile'],$ctx,$base['transport'],'vp3.profile.get',[],['profile'=>[]])===[],'ordinary public reads create no action evidence');

// A second prepared intent may resolve to an earlier identical committed action only through the exact canonical idempotency identity.
woReset();$other=$base;$other['action']['id']=99;$other['action']['intent_id']=str_repeat('d',32);$other['action']['state']='prepared';$other['prepare']['intent_id']=$other['action']['intent_id'];
woWritable(fn()=>profile_webmcp_outcome_fixture_put_v1($pdo,'profile_webmcp_actions',$other['action']));$newPatch=vp3_profile_webmcp_server_evidence_v1($pdo,$other['profile'],$other['context'],$other['transport'],$other['spec'][0],[],$other['prepare']);$newCtx=array_replace($other['context'],$newPatch);
$parts=explode('.',$other['args']['confirmation_token']);$claims=json_decode(base64_decode(strtr($parts[0],'-_','+/')),true);$claims['intent_id']=$other['action']['intent_id'];$encoded=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');$other['args']['confirmation_token']=$encoded.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256',$encoded,'fixture-native-secret',true)),'+/','-_'),'=');
$replay=vp3_profile_webmcp_server_evidence_v1($pdo,$other['profile'],$newCtx,$other['transport'],$other['spec'][1],$other['args'],$other['result']+['idempotent_replay'=>true]);woTest($replay['server_action']['committed_intent_id']===$base['action']['intent_id']&&$replay['server_outcome']['replayed'],'exact earlier canonical action may satisfy idempotent replay');
woWritable(fn()=>$pdo->prepare('UPDATE profile_webmcp_actions SET session_hash=? WHERE id=?')->execute([str_repeat('e',64),$base['action']['id']]));woTest(woReject(fn()=>vp3_profile_webmcp_server_evidence_v1($pdo,$other['profile'],$newCtx,$other['transport'],$other['spec'][1],$other['args'],$other['result'])),'earlier action from a different session cannot satisfy replay');

woReset();foreach($contexts as $op=>$context){$out=$context['server_outcome'];foreach(['extra'=>'SECRET','replayed'=>1,'result'=>'paid','committed_at'=>'2099-01-01T00:00:00Z'] as $key=>$value){$bad=$out;$bad[$key]=$value;woTest(woReject(fn()=>vp3_profile_webmcp_outcome_projection_v1($bad)),$op.' rejects public outcome '.$key.' contamination');}}
foreach(['reward.claim_handoff','reward.transfer'] as $op){$q=$pdo->prepare('SELECT result_json FROM profile_webmcp_actions WHERE id=?');$q->execute([$cases[$op]['action']['id']]);$stored=json_decode($q->fetchColumn(),true);woTest(isset($stored['reward_public_id'])&&!str_contains(json_encode($stored),'SECRET'),$op.' replay keeps operation facts and excludes private credentials/contact fields');}
woTest(!str_contains(json_encode($pdo->query("SELECT result_json FROM profile_webmcp_actions WHERE result_type IN ('booking','campaign_enrollment')")->fetchAll(PDO::FETCH_COLUMN)),'SECRET'),'canonical booking and Campaign replay snapshots still exclude guest credentials and contact data');
// The production prepare uses UTC independently of the connection timezone.
// SQLite translates DATE_ADD only; MySQL executes the unchanged statement in +05:30.
woReset();$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$priorZone=$mysql?$pdo->query('SELECT @@session.time_zone')->fetchColumn():null;
if($mysql)woWritable(fn()=>$pdo->exec("SET time_zone='+05:30'"));
try{
    $fresh=woWritable(fn()=>vp3_profile_webmcp_action_prepare_v150($pdo,$base['transport'],'booking.create',$base['prepare']['intent']));
    $preparedResult=array_replace($base['prepare'],['intent_id'=>$fresh['intent_id'],'expires_at_unix'=>$fresh['expires_at_unix']]);
    $freshContext=$base['context'];$freshContext['created_at']=time()-1;
    $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$base['profile'],$freshContext,$base['transport'],$base['spec'][0],[],$preparedResult);$freshContext=array_replace($freshContext,$patch);
    $freshRow=vp3_profile_webmcp_outcome_row_v1($pdo,$patch['server_action'],$fresh['intent_id']);
    woTest($patch['server_outcome']['state']==='prepared'&&abs(time()-(int)vp3_profile_webmcp_outcome_time_v1($freshRow['created_at']))<=2,'real canonical preparation binds in UTC even with a non-UTC MySQL session');
    woWritable(fn()=>vp3_profile_webmcp_action_commit_v150($pdo,$fresh['id'],hash('sha256','new-real-prepare'),'booking',$base['resultId'],$base['result']));
    woTest(vp3_profile_webmcp_outcome_read_v1($pdo,$freshContext)['state']==='recorded','real prepared action commits with an ordered UTC receipt and remains independently recoverable');
}finally{if($mysql)woWritable(fn()=>$pdo->exec('SET time_zone='.$pdo->quote($priorZone)));}
woTest($pdo->writeAttempts===0,'evidence reads and server binding never perform DDL or database writes');
echo 'PROFILE_WEBMCP_OUTCOMES_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; real action ledger and canonical commit serializer)' . "\n";
