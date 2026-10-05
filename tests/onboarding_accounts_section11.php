<?php
declare(strict_types=1);
// Run production services over SQLite, adapting only schema/time/locking dialect.
final class Section11PDO extends PDO {
    public function exec(string $sql): int|false {
        if(str_starts_with(ltrim($sql),'CREATE TABLE IF NOT EXISTS'))return 0;
        return parent::exec($sql);
    }
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace([' FOR UPDATE','UTC_TIMESTAMP()'],['','CURRENT_TIMESTAMP'],$sql);
        return parent::prepare($sql,$options);
    }
}
$pdo=new Section11PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
function table_exists(string $name): bool {return true;}
function column_exists(string $table,string $name): bool {return true;}
function check(bool $ok,string $name): void {if(!$ok)throw new LogicException($name);echo "PASS $name\n";}
function rejects(callable $call): void {try{$call();}catch(Throwable $e){return;}throw new LogicException('Expected rejection');}
$private=sys_get_temp_dir().'/vp3-section11-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$private);
require __DIR__.'/../includes/homeserver-account-pairing-v1210.php';
require_once __DIR__.'/../includes/homeserver-https-relay-v1300.php';
require_once __DIR__.'/../includes/chat-onboarding-v241.php';
require_once __DIR__.'/../includes/agent-onboarding-skill-v100.php';
function vp3_cognitive_homeserver_event_v2390(PDO $pdo,int $uid,string $event,string $state): void {throw new RuntimeException('projection failure fixture');}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(7),(8);
 CREATE TABLE homeserver_pairing_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,token_hash TEXT UNIQUE,status TEXT,device_id TEXT DEFAULT '',expires_at TEXT,redeemed_at TEXT);
 CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id TEXT UNIQUE,session_token_hash TEXT UNIQUE,status TEXT,installed_version TEXT,capabilities_json TEXT,last_seen_at TEXT);
 CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,device_id TEXT,relay_token_enc TEXT,homeserver_token_enc TEXT,pending_request_id TEXT,pending_claim_token_enc TEXT,pending_code TEXT,status TEXT,installed_version TEXT,last_seen_at TEXT,last_checked_at TEXT,last_error TEXT,capabilities_json TEXT);");
$token=homeserver_account_v1210_generate_token(7)['token'];
$device='hs-'.str_repeat('a',24);$local=str_repeat('L',64);$session=str_repeat('S',64);
try{
 $result=homeserver_https_v1300_pair($token,$device,$local,'2.4',['agent.chat'],$session);
 check($result['session_token']===$session&&!$pdo->inTransaction(),'pairing commits despite projection failure');
 check($pdo->query('SELECT status FROM homeserver_pairing_tokens')->fetchColumn()==='paired','account token and connection committed together');
 check($pdo->query('SELECT session_token_hash FROM homeserver_https_sessions')->fetchColumn()===hash('sha256',$session),'Cloud persists only the session hash');
 $enc=$pdo->query('SELECT homeserver_token_enc FROM homeserver_connections')->fetchColumn();
 check($enc!==$local&&homeserver_vp3_decrypt($enc)===$local,'local authorization encrypted at rest');
 $before=$pdo->query('SELECT * FROM homeserver_https_sessions')->fetch();
 $retry=homeserver_https_v1300_pair($token,$device,$local,'changed',[], $session);
 check($retry['session_token']===$session&&$pdo->query('SELECT * FROM homeserver_https_sessions')->fetch()===$before,'lost response replay returns original session without modifying authority');
 rejects(fn()=>homeserver_https_v1300_pair($token,$device,$local));
 rejects(fn()=>homeserver_https_v1300_pair($token,'hs-'.str_repeat('b',24),$local,'',[],$session));
 rejects(fn()=>homeserver_https_v1300_pair($token,$device,str_repeat('X',64),'',[],$session));
 rejects(fn()=>homeserver_https_v1300_pair($token,$device,$local,'',[],str_repeat('X',64)));
 check(!$pdo->inTransaction(),'legacy replay and changed device/local/session bindings rejected');
 rejects(fn()=>homeserver_account_v1210_generate_token(7));
 $other=homeserver_account_v1210_generate_token(8)['token'];
 rejects(fn()=>homeserver_https_v1300_pair($other,$device,str_repeat('B',64),'',[],str_repeat('C',64)));
 check($pdo->query("SELECT status FROM homeserver_pairing_tokens WHERE user_id=8")->fetchColumn()==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM homeserver_connections')->fetchColumn()===1,'device collision cannot overwrite another account and rolls back token');
 $pdo->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=7");
 rejects(fn()=>homeserver_https_v1300_pair($token,$device,$local,'',[],$session));
 $pdo->exec("UPDATE homeserver_https_sessions SET status='active'; UPDATE homeserver_pairing_tokens SET expires_at='2000-01-01 00:00:00' WHERE user_id=7");
 rejects(fn()=>homeserver_https_v1300_pair($token,$device,$local,'',[],$session));
 check(!$pdo->inTransaction(),'revocation and expiration prevent replay');
 $pdo->exec("CREATE TRIGGER fail_connection BEFORE INSERT ON homeserver_connections BEGIN SELECT RAISE(ABORT,'storage fixture'); END;");
 rejects(fn()=>homeserver_https_v1300_pair($other,'hs-'.str_repeat('b',24),str_repeat('B',64),'',[],str_repeat('C',64)));
 check((int)$pdo->query('SELECT COUNT(*) FROM homeserver_https_sessions')->fetchColumn()===1&&$pdo->query('SELECT status FROM homeserver_pairing_tokens WHERE user_id=8')->fetchColumn()==='pending','connection write failure rolls back session and leaves original token retryable');
 $pdo->exec('DROP TRIGGER fail_connection');
 homeserver_https_v1300_pair($other,'hs-'.str_repeat('b',24),str_repeat('B',64),'',[],str_repeat('C',64));
 check((int)$pdo->query('SELECT COUNT(*) FROM homeserver_connections')->fetchColumn()===2,'same token succeeds after storage recovery');
 $item=['label'=>'HomeServer','interest_key'=>'workflow.homeserver','configured'=>true,'permitted'=>false,'available'=>false];
 $state=chat_onboarding_v241_activation_state(['homeserver'=>$item],['feature_interests'=>['workflow.homeserver'=>true]],true);
 check($state['items']['homeserver']['activation_status']==='locked'&&$state['blocked_count']===1&&$state['configured_count']===0&&!$state['complete'],'configured but revoked entitlement remains blocked');
 check(!$state['milestones'][1]['complete']&&$state['attribution']['configured_workflows']===[],'milestones and attribution do not claim revoked service completion');
 $skill=vp3_agent_onboarding_skill_state_v100(['setup'=>['agent_named'=>true,'profile_username'=>true],'activation'=>$state]);
 check($skill['task']['phase']==='waiting'&&!$skill['selected_optional'][0]['verified'],'Chat/Brain onboarding skill sees permission changes');
 $state=chat_onboarding_v241_activation_state(['homeserver'=>$item+[]],['feature_interests'=>[]],true);
 check($state['selected_count']===0&&$state['complete'],'unselected optional service never blocks essential setup');
 $state=chat_onboarding_v241_activation_state(['homeserver'=>array_replace($item,['permitted'=>true,'available'=>true])],['feature_interests'=>['workflow.homeserver'=>true]],true);
 check($state['complete']&&$state['configured_count']===1,'restored canonical entitlement completes existing setup');
}finally{
 @unlink($private.'/private/homeserver-vp3.key');@rmdir($private.'/private');@rmdir($private);
}
echo "ONBOARDING_ACCOUNTS_SECTION11=PASS\n";
