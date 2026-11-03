<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/system-apps-v300.php';
require_once dirname(__DIR__).'/includes/vrss-v2b.php';
require __DIR__.'/fixtures/vrss-agent-v1.php';

class VrssReadPDO extends PDO
{
    public bool $readGuard=false,$ddlGuard=false;
    public int $writeAttempts=0,$ddlAttempts=0;
    private function guard(string $sql): void
    {
        if($this->ddlGuard&&preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$sql)){$this->ddlAttempts++;throw new RuntimeException('Unexpected schema mutation.');}
        if($this->readGuard&&!preg_match('/^\s*(SELECT|PRAGMA)\b/i',$sql)){$this->writeAttempts++;throw new RuntimeException('Read attempted a write.');}
    }
    public function exec(string $sql): int|false{$this->guard($sql);return parent::exec($sql);}
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $this->guard($sql);
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql=str_replace(' FOR UPDATE','',$sql);
            $sql=preg_replace('/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL (\d+) MINUTE\)/i',"datetime('now','+$1 minutes')",$sql);
        }
        return parent::prepare($sql,$options);
    }
    public function query(string $sql,?int $mode=null,mixed ...$args): PDOStatement|false{$this->guard($sql);return $mode===null?parent::query($sql):parent::query($sql,$mode,...$args);}
}
$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_SECTION7_MYSQL_DSN');$dsn='sqlite::memory:';$dbUser=null;$dbPassword=null;
if($mysqlDsn){
    $dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPassword=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture';
    $admin=new PDO($mysqlDsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$database='vp3_vrss_read_test_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysqlDsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysqlDsn):rtrim($mysqlDsn,';').';dbname='.$database;
    register_shutdown_function(static function()use($admin,$database): void {$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
}
$pdo=new VrssReadPDO($dsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
else{$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));}
$checks=0;$vrss_calls=[];$vrss_mode='success';$permissions=['account.access'=>true,'chat.access'=>true];
function db(): PDO{return $GLOBALS['pdo'];}
function has_permission($key,$user): bool{return $GLOBALS['permissions'][$key]??false;}
function personal_capability_has_v242(...$args): bool{return false;}
function table_exists($table): bool{$sql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT name FROM sqlite_master WHERE type='table' AND name=?":"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";$q=db()->prepare($sql);$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists($table,$column): bool
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
    $q=db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
function url($path): string{return $path;}
function agent_tool_log(...$args): void{}
function homeserver_vp3_remote_operation_for_user(...$args): array{return vrss_agent_remote_v1(...$args);}
function check($value,string $label): void{if(!$value)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function vrss_writable_v1(callable $work): mixed{$pdo=db();$guard=$pdo->readGuard;$pdo->readGuard=false;try{return $work();}finally{$pdo->readGuard=$guard;}}
function vrss_plan_v1(string $view,array $extra=[]): array
{
    $plan=['operation'=>'vrss_read','ids'=>['feeds.vrss.read'],'view'=>$view];
    if(in_array($view,['likes','reposts','comments','thread'],true))$plan['url']=vrss_fixture_post_v1();
    if(in_array($view,['comments','thread'],true))$plan['cursor']='0';
    return array_replace($plan,$extra);
}
function readVrss(string $view,array $extra=[],?array $principal=null,array $context=['conversation_id'=>1]): array
{
    return vp3_vrss_read_v1(db(),$GLOBALS['user'],$principal??$GLOBALS['principal'],vrss_plan_v1($view,$extra),$context,'vrss_agent_remote_v1');
}
function safeVrss(array $r): bool
{
    $json=json_encode($r,JSON_UNESCAPED_SLASHES);
    return !str_contains($json,'SECRET')&&!str_contains($json,'/private/path')&&!isset($r['vrss'])&&empty($r['tool_receipt']['changes_applied']);
}
function resetVrss(): void
{
    $GLOBALS['vrss_mode']='success';$GLOBALS['permissions']=['account.access'=>true,'chat.access'=>true];unset($GLOBALS['vrss_during_remote'],$GLOBALS['vrss_result_transform'],$GLOBALS['vp3_capability_turn_v1']);
    vrss_writable_v1(function(): void {db()->exec('UPDATE users SET is_active=1 WHERE id=1');db()->exec('UPDATE user_agents SET owner_user_id=1,is_active=1 WHERE id=7');db()->exec('UPDATE chat_conversations SET user_id=1,user_agent_id=7 WHERE id=1');db()->exec("UPDATE vp3_system_app_ownership SET status='active' WHERE user_id=1 AND app_id=2");});
}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');$pdo->exec('INSERT INTO user_agents VALUES(7,1,1),(8,1,1),(9,2,1),(10,1,0)');
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');$pdo->exec('INSERT INTO chat_conversations VALUES(1,1,7),(2,2,9)');
vrss_agent_fixture_v1($pdo);$pdo->readGuard=true;$pdo->ddlGuard=true;
$user=['id'=>1,'is_active'=>1];$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
$views=['subscriptions'=>'vrss.status','posts'=>'vrss.feed','connections'=>'vrss.social.status','likes'=>'vrss.social.reactions','reposts'=>'vrss.social.reposts','comments'=>'vrss.social.comments','conversations'=>'vrss.social.conversations','thread'=>'vrss.social.thread'];
$markers=['subscriptions'=>'Fixture Kitchen Journal','posts'=>'Fixture Desert News','connections'=>'Fixture Publisher','likes'=>'2 likes','reposts'=>'2 reposts','comments'=>'Fixture public comment','conversations'=>vrss_fixture_post_v1(),'thread'=>'Fixture conversation reply'];

foreach($views as $view=>$action){
    resetVrss();$before=count($vrss_calls);$r=readVrss($view);$call=end($vrss_calls);
    check(($r['tool_receipt']['status']??'')==='read_complete'&&safeVrss($r)&&str_contains($r['answer'],$markers[$view]),$view.' reads verified bounded evidence with a shared receipt and no raw payload');
    check(count($vrss_calls)===$before+1&&$call[0]===1&&$call[1]==='apps.control.invoke'&&$call[2]['app_key']==='vp3.feed'&&$call[2]['action']===$action&&$call[2]['confirmed']===false,$view.' invokes exactly its canonical read for the current owner');
    foreach(['offline','denied','wrong_app','wrong_action','missing_result','bad_result','missing_projection','bad_row'] as $mode){
        $vrss_mode=$mode;$r=readVrss($view);
        check(($r['tool_receipt']['status']??'')===($mode==='denied'?'blocked':'failed')&&safeVrss($r)&&!str_contains($r['answer'],'Fixture'),$view.' distinguishes '.$mode.' from verified records or a successful empty read');
    }
}
foreach(['subscriptions','posts','comments','conversations','thread'] as $view){$vrss_mode='empty';$r=readVrss($view);check($r['tool_receipt']['status']==='no_matches'&&$r['tool_receipt']['count']===0,$view.' accepts a real empty list without inventing records');}
foreach(['connections','likes','reposts'] as $view){
    $vrss_mode='empty';$r=readVrss($view);check($r['tool_receipt']['status']==='read_complete'&&safeVrss($r),$view.' verified zero counts remain distinct from unavailable evidence');
    $vrss_mode='negative_count';$r=readVrss($view);check($r['tool_receipt']['status']==='failed',$view.' rejects negative reported counts');
    $vrss_mode='missing_count';$r=readVrss($view);check($r['tool_receipt']['status']==='failed',$view.' missing counts cannot silently default to zero');
}
foreach(['likes','reposts'] as $view){$vrss_mode='unavailable_count';$r=readVrss($view);check($r['tool_receipt']['status']==='read_complete'&&preg_match('/unavailable|not available|not reported/i',$r['answer'])&&!preg_match('/\b0 (?:likes|reposts)\b/i',$r['answer']),$view.' explicit unavailable counts remain unavailable');}
$vrss_mode='missing_pending';$r=readVrss('connections');check($r['tool_receipt']['status']==='read_complete'&&preg_match('/not reported|unavailable/i',$r['answer'])&&!str_contains($r['answer'],'1 connection updates'),'delivery list size cannot become the pending delivery total');
foreach(['likes','reposts','comments','thread'] as $view){$vrss_mode='identity';$r=readVrss($view);check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Fixture'),$view.' rejects returned evidence bound to a different post');}
foreach(['likes'=>'reaction','reposts'=>'repost','comments'=>'thread','thread'=>'thread'] as $view=>$field){resetVrss();$GLOBALS['vrss_result_transform']=function($result)use($field){$result[$field]['object']='https://foreign.example/vrss/v1/posts/99999999-9999-4999-8999-999999999999';return$result;};$r=readVrss($view);check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Fixture'),$view.' rejects nested evidence for another exact post even without a root identity');}
foreach(['likes'=>'reaction','reposts'=>'repost','comments'=>'thread','thread'=>'thread'] as $view=>$field){resetVrss();$GLOBALS['vrss_result_transform']=function($result)use($field){unset($result[$field]['object']);return$result;};$r=readVrss($view);check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Fixture'),$view.' requires the source response to identify its exact post');}
resetVrss();$canonicalAlias=str_replace('social.example','SOCIAL.EXAMPLE:443',vrss_fixture_post_v1());$r=readVrss('likes',['url'=>$canonicalAlias]);$call=end($vrss_calls);check($r['tool_receipt']['status']==='read_complete'&&$call[2]['arguments']['url']===vrss_fixture_post_v1(),'equivalent HTTPS host case and default port normalize to one exact post identity before dispatch');
resetVrss();
foreach(['comments','thread'] as $view){
    $vrss_mode='pagination';$r=readVrss($view,['cursor'=>'12']);$call=end($vrss_calls);check($r['tool_receipt']['status']==='read_complete'&&$call[2]['arguments']['cursor']==='12'&&str_contains($r['answer'],'17'),$view.' preserves exact requested cursor and a verified next-page cursor');
    $r=readVrss($view,['cursor'=>'17']);check($r['tool_receipt']['status']==='failed',$view.' rejects a cursor that repeats the requested page');
    $vrss_mode='bad_cursor';$r=readVrss($view);check($r['tool_receipt']['status']==='failed'&&safeVrss($r),$view.' cannot render or follow an invalid returned cursor');
    $vrss_mode='public_private_state';$r=readVrss($view);check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'Fixture'),$view.' public result cannot disclose private comment states');
    resetVrss();$GLOBALS['vrss_result_transform']=function($result){$result['thread']['owner']=false;$result['thread']['items'][0]['state']='retraction_pending';return$result;};$r=readVrss($view);check($r['tool_receipt']['status']==='read_complete'&&preg_match('/retraction pending/i',$r['answer'])&&str_contains($r['answer'],'Fixture'),$view.' preserves source-defined public retraction pending without claiming remote removal');resetVrss();
    $GLOBALS['vrss_result_transform']=function($result){unset($result['thread']['owner']);return$result;};$r=readVrss($view);check($r['tool_receipt']['status']==='failed',$view.' requires the source to declare its public or owner scope');resetVrss();
    $GLOBALS['vrss_result_transform']=function($result){unset($result['thread']['items'][0]['id']);return$result;};$r=readVrss($view);check($r['tool_receipt']['status']==='failed',$view.' requires each returned comment to have an exact canonical identity');resetVrss();
}
$vrss_mode='conversation_duplicate';$r=readVrss('conversations');check($r['tool_receipt']['status']==='failed','duplicate conversation targets cannot become ambiguous control state');
foreach(['connections','conversations'] as $view){
    resetVrss();$GLOBALS['vrss_result_transform']=function($result,$action){if($action==='vrss.social.status')$result['social']['relations'][0]['blocked']=false;else{$result['conversations']['items'][0]['enabled']=true;$result['conversations']['items'][0]['muted']=false;}return$result;};
    $r=readVrss($view);check($r['tool_receipt']['status']==='read_complete',$view.' accepts canonical boolean projections as well as SQLite integer flags');
    $GLOBALS['vrss_result_transform']=function($result,$action){if($action==='vrss.social.status')$result['social']['relations'][0]['blocked']='0';else $result['conversations']['items'][0]['enabled']='1';return$result;};
    $r=readVrss($view);check($r['tool_receipt']['status']==='failed',$view.' rejects numeric strings instead of coercing record flags');
}
resetVrss();$GLOBALS['vrss_result_transform']=function($result){$result['feed']['items'][0]['title']="[click](https://evil.example/)\n<script>untrusted</script>";return$result;};$r=readVrss('posts');
check($r['tool_receipt']['status']==='read_complete'&&preg_match('/untrusted|evidence/i',$r['answer'])&&!str_contains($r['answer'],'[click](https://evil.example/)')&&!str_contains($r['answer'],'<script>'),'post labels remain untrusted plain text rather than executable markup');
resetVrss();$GLOBALS['vrss_result_transform']=function($result){$result['thread']['items'][0]['body']=str_repeat('x',2001);return$result;};$r=readVrss('comments');check($r['tool_receipt']['status']==='failed','oversized comment bodies fail before reaching Chat');
resetVrss();$GLOBALS['vrss_result_transform']=function($result){$result['thread']['items'][0]['body']=str_repeat('é',1200);return$result;};$r=readVrss('comments');check($r['tool_receipt']['status']==='failed','the source comment limit applies to UTF-8 bytes as well as character count');
resetVrss();$GLOBALS['vrss_result_transform']=function($result){$result['conversations']['items']=array_fill(0,51,$result['conversations']['items'][0]);return$result;};$r=readVrss('conversations');check($r['tool_receipt']['status']==='failed','unbounded conversation payloads cannot enter Chat');
foreach([['contract'=>null],['ok'=>false]] as $metadata){resetVrss();$GLOBALS['vrss_result_transform']=fn($result)=>array_replace($result,$metadata);$r=readVrss('posts');check($r['tool_receipt']['status']==='failed'&&safeVrss($r),'supplied invalid inner acknowledgement cannot be treated as an absent optional field '.json_encode($metadata));}
foreach(['ok','contract','viewer_id'] as $field){resetVrss();$GLOBALS['vrss_result_transform']=function($result)use($field){unset($result[$field]);return$result;};$r=readVrss('posts');check($r['tool_receipt']['status']==='failed','a non-status read requires the deployed inner acknowledgement field '.$field);}
resetVrss();

foreach(['account.access','chat.access'] as $permission){$permissions[$permission]=false;$before=count($vrss_calls);$r=readVrss('posts');check($r['tool_receipt']['status']==='blocked'&&count($vrss_calls)===$before,$permission.' denial prevents a remote read');$permissions[$permission]=true;}
foreach(['foreign owner'=>['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>7],'foreign Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>9],'inactive Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>10],'different conversation Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],'Profile visitor'=>['kind'=>'profile_agent','owner_user_id'=>1]] as $label=>$who){$before=count($vrss_calls);$r=readVrss('posts',[],$who);check($r['tool_receipt']['status']==='blocked'&&count($vrss_calls)===$before,$label.' cannot read private owner feeds');}
foreach([2,999] as $cid){$before=count($vrss_calls);$r=readVrss('posts',[],null,['conversation_id'=>$cid]);check($r['tool_receipt']['status']==='blocked'&&count($vrss_calls)===$before,'foreign or missing conversation '.$cid.' prevents network access');}
foreach(['inactive account'=>'UPDATE users SET is_active=0 WHERE id=1','inactive Agent'=>'UPDATE user_agents SET is_active=0 WHERE id=7','Agent ownership change'=>'UPDATE user_agents SET owner_user_id=2 WHERE id=7','conversation owner change'=>'UPDATE chat_conversations SET user_id=2 WHERE id=1','conversation Agent change'=>'UPDATE chat_conversations SET user_agent_id=8 WHERE id=1'] as $label=>$sql){
    $GLOBALS['vrss_during_remote']=fn()=>vrss_writable_v1(fn()=>$pdo->exec($sql));$r=readVrss('posts');check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Fixture')&&empty($r['sources']),'late '.$label.' discards remote evidence');resetVrss();
}
foreach(['account.access','chat.access'] as $permission){$GLOBALS['vrss_during_remote']=function()use($permission): void {$GLOBALS['permissions'][$permission]=false;};$r=readVrss('posts');check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Fixture'),'late '.$permission.' revocation discards remote evidence');resetVrss();}
vrss_writable_v1(fn()=>$pdo->exec("UPDATE vp3_system_app_ownership SET status='revoked' WHERE user_id=1 AND app_id=2"));$r=readVrss('posts');check($r['tool_receipt']['status']==='read_complete','read-only HomeServer feed access does not acquire a new Cloud ownership prerequisite');resetVrss();

$before=count($vrss_calls);$r=vp3_capability_chat_v1($pdo,$user,$principal,'Can you read my VRSS feed?',['conversation_id'=>1],'off',[],fn()=>['ok'=>true,'answer'=>'{"operation":"inspect","ids":["feeds.vrss.read"]}']);check(count($vrss_calls)===$before&&!str_contains($r['answer'],'Fixture'),'capability inspection never fetches feed records');
$snapshot=vp3_capability_snapshot_v1($pdo,$user,$principal,['conversation_id'=>1]);
foreach([
    ['view'=>'delete'],['view'=>null],['view'=>'comments'],['view'=>'comments','url'=>vrss_fixture_post_v1(),'cursor'=>null],['view'=>'subscriptions','url'=>vrss_fixture_post_v1()],['view'=>'likes','url'=>vrss_fixture_post_v1(),'cursor'=>'1'],
    ['view'=>'thread','url'=>vrss_fixture_post_v1(),'cursor'=>'00'],['view'=>'thread','url'=>vrss_fixture_post_v1(),'cursor'=>3],['view'=>'thread','url'=>vrss_fixture_post_v1(),'cursor'=>'12345678901234567'],
    ['view'=>'likes','url'=>'http://social.example/vrss/v1/posts/11111111-1111-4111-8111-111111111111'],['view'=>'likes','url'=>vrss_fixture_post_v1().'?token=secret'],
    ['view'=>'posts','user_id'=>2],['view'=>'posts','action'=>'vrss.follow'],['view'=>'posts','confirmed'=>true],['view'=>'posts','query'=>'anything'],['view'=>'posts','ids'=>['feeds.vrss.read','apps.user.read']],
] as $extra){$bad=false;try{vp3_capability_plan_v1(json_encode(array_replace(['operation'=>'vrss_read','ids'=>['feeds.vrss.read']],$extra)),$snapshot);}catch(InvalidArgumentException $e){$bad=true;}check($bad,'strict read plan rejects unsupported selector or action '.json_encode($extra));}
foreach(['Can you read my VRSS posts?','Do you have permission to read VRSS comments?','Does your VRSS tool support following feeds?','Does VRSS support following feeds?','Does VRSS support unfollow https://writer.example/vrss/v1/actor?','I want a tutorial about my VRSS followers','What is VRSS?','What are VRSS feeds?','How do I follow a VRSS conversation?','Build a VRSS feed dashboard','If I follow a VRSS conversation, what happens?','Explain how VRSS subscriptions work','Refresh my feed if I ask later','like VRSS','follow VRSS conversation','approve VRSS comment'] as $query){$before=count($vrss_calls);$count=vrssActionCount();$r=vp3_vrss_agent_query_v2b($query,$user,1,'vrss_agent_remote_v1',$pdo);check(count($vrss_calls)===$before&&vrssActionCount()===$count&&!isset($r['system_app_plan']),'legacy ability, explanation, conditional or incomplete prompt performs no read or mutation preparation: '.$query);}
foreach(['show VRSS subscriptions'=>'subscriptions','show new VRSS posts'=>'posts','show VRSS connections'=>'connections','show VRSS likes '.vrss_fixture_post_v1()=>'likes','show VRSS reposts '.vrss_fixture_post_v1()=>'reposts','show VRSS comments '.vrss_fixture_post_v1()=>'comments','show VRSS conversations'=>'conversations','show VRSS conversation '.vrss_fixture_post_v1()=>'thread'] as $query=>$view){$r=vp3_vrss_agent_query_v2b($query,$user,1,'vrss_agent_remote_v1',$pdo);check(($r['tool_receipt']['status']??'')==='read_complete'&&safeVrss($r)&&str_contains($r['answer'],$markers[$view]),'legacy '.$view.' uses the shared verified read and receipt');}

function prepareVrss(string $query): array{return vrss_writable_v1(fn()=>vp3_vrss_agent_query_v2b($query,$GLOBALS['user'],1,'vrss_agent_remote_v1',db()));}
function vrssActionCount(): int{return(int)db()->query('SELECT COUNT(*) FROM vp3_system_app_agent_actions')->fetchColumn();}
$post=vrss_fixture_post_v1();$comment=vrss_fixture_comment_v1();
foreach(['show VRSS last refresh','what is my VRSS last refresh status','show VRSS subscription health'] as $query){resetVrss();$before=count($vrss_calls);$count=vrssActionCount();$r=vp3_vrss_agent_query_v2b($query,$user,1,'vrss_agent_remote_v1',$pdo);check(($r['tool_receipt']['status']??'')==='read_complete'&&count($vrss_calls)===$before+1&&end($vrss_calls)[2]['action']==='vrss.status'&&vrssActionCount()===$count&&!isset($r['system_app_plan']),'refresh health question reads evidence without preparing another refresh: '.$query);}
resetVrss();$before=count($vrss_calls);$count=vrssActionCount();$r=prepareVrss('refresh my feed');check(($r['system_app_plan']['preview']['operation']??'')==='vrss.refresh_all'&&($r['system_app_plan']['requires_confirmation']??false)&&vrssActionCount()===$count+1&&count($vrss_calls)===$before,'an explicit feed refresh still requires the canonical durable confirmation');
resetVrss();$before=count($vrss_calls);$count=vrssActionCount();$body='Explain how this works if I follow the tutorial later.';$r=prepareVrss('comment VRSS '.$post.': '.$body);$plan=$r['system_app_plan']??[];
check(($plan['preview']['operation']??'')==='vrss.social.comment'&&($plan['preview']['comment']??'')===$body&&($plan['requires_confirmation']??false)&&vrssActionCount()===$count+1&&count($vrss_calls)===$before,'an explicit comment keeps tutorial and hypothetical words in its body while requiring confirmation');
resetVrss();$r=prepareVrss('follow VRSS conversation '.str_replace('social.example','SOCIAL.EXAMPLE:443',$post));$plan=$r['system_app_plan']??[];check(($plan['requires_confirmation']??false)&&($plan['preview']['revision']??null)===7,'a canonical URL spelling variant selects the existing conversation revision rather than inventing a new watch');
foreach(['follow VRSS conversation '.$post=>'vrss.social.conversations','approve VRSS comment '.$comment.' on '.$post=>'vrss.social.comment_review'] as $query=>$action){
    resetVrss();$before=count($vrss_calls);$count=vrssActionCount();$r=prepareVrss($query);$plan=$r['system_app_plan']??[];
    check(($plan['requires_confirmation']??false)===true&&vrssActionCount()===$count+1&&count($vrss_calls)===$before+1&&end($vrss_calls)[2]['action']===$action&&end($vrss_calls)[2]['confirmed']===false,'mutation preflight '.$action.' reads exact current evidence and saves only the canonical confirmation');
    if($action==='vrss.social.comment_review')check(($plan['preview']['comment']??'')==='Exact comment body for review'&&($plan['preview']['revision']??null)===3,'moderation preview binds the exact comment body and revision');
    foreach(['offline','missing_projection','bad_row','revoke','swap'] as $mode){
        resetVrss();$vrss_mode=$mode;$count=vrssActionCount();$r=prepareVrss($query);
        check(!isset($r['system_app_plan'])&&vrssActionCount()===$count&&!str_contains(json_encode($r),'SECRET'),'failed or revoked '.$action.' preflight '.$mode.' cannot create an approval or expose raw errors');
    }
    resetVrss();$GLOBALS['vrss_during_remote']=fn()=>vrss_writable_v1(fn()=>$pdo->exec("UPDATE vp3_system_app_ownership SET status='revoked' WHERE user_id=1 AND app_id=2"));$count=vrssActionCount();$r=prepareVrss($query);check(!isset($r['system_app_plan'])&&vrssActionCount()===$count,'late Feed ownership revocation during '.$action.' cannot save an approval');
}
foreach(['comment_identity','identity','review_denied'] as $mode){resetVrss();$vrss_mode=$mode;$count=vrssActionCount();$r=prepareVrss('approve VRSS comment '.$comment.' on '.$post);check(!isset($r['system_app_plan'])&&vrssActionCount()===$count,'comment moderation rejects '.$mode.' rather than approving a different or unauthorized target');}
foreach(['conversation_duplicate','conversation_partial'] as $mode){resetVrss();$vrss_mode=$mode;$count=vrssActionCount();$r=prepareVrss('follow VRSS conversation '.$post);check(!isset($r['system_app_plan'])&&vrssActionCount()===$count,'conversation preflight rejects '.$mode.' instead of inventing a default state');}
resetVrss();vrss_writable_v1(fn()=>$pdo->exec("UPDATE vp3_system_app_ownership SET status='revoked' WHERE user_id=1 AND app_id=2"));$count=vrssActionCount();$r=prepareVrss('follow VRSS conversation '.$post);check(!isset($r['system_app_plan'])&&vrssActionCount()===$count,'revoked Cloud Feed ownership cannot prepare a consequential VRSS action');resetVrss();
$before=count($vrss_calls);$r=prepareVrss('Remove follower VRSS https://writer.example/vrss/v1/actor');$plan=$r['system_app_plan']??[];
check(($plan['preview']['operation']??'')==='vrss.social.remove'&&($plan['requires_confirmation']??false)===true&&count($vrss_calls)===$before,'capitalized Remove follower preserves the canonical remove action and explicit confirmation');
$q=$pdo->prepare('SELECT payload_json FROM vp3_system_app_agent_actions WHERE public_id=?');$q->execute([$plan['action_id']??'']);$payload=json_decode((string)$q->fetchColumn(),true);
check(($payload['action']??'')==='vrss.social.remove'&&($payload['arguments']['actor']??'')==='https://writer.example/vrss/v1/actor','the actual durable approval preserves exact normalized action and actor identity');
check($pdo->writeAttempts===0&&$pdo->ddlAttempts===0,'pure reads and mutation preflights never initialize schemas or perform implicit record writes');
echo 'VRSS_AGENT_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; real authority and approval storage, fixture network only)' . "\n";
