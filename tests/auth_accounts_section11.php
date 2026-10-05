<?php
declare(strict_types=1);
ob_start();session_start();
final class Auth11PDO extends PDO {
    public bool $resetBeforeChange=false;
    public bool $disableBeforeReset=false;
    public function beginTransaction(): bool {
        if($this->disableBeforeReset){
            $this->disableBeforeReset=false;parent::exec('UPDATE users SET is_active=0 WHERE id=1');
        }
        return parent::beginTransaction();
    }
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        if($this->resetBeforeChange&&str_starts_with($sql,'UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=? AND password_hash=?')){
            $this->resetBeforeChange=false;
            parent::prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([password_hash('Intervening-password',PASSWORD_DEFAULT)]);
        }
        $sql=str_replace([' FOR UPDATE','NOW()','DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 5 MINUTE)','DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 60 MINUTE)'],['','CURRENT_TIMESTAMP',"datetime('now','-5 minutes')","datetime('now','+60 minutes')"],$sql);
        return parent::prepare($sql,$options);
    }
}
$pdo=new Auth11PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
function table_exists(string $name): bool {return true;}
function column_exists(string $table,string $name): bool {return false;}
function user_account_types_for_user_id(int $uid,string $role): array {return [$role];}
function setting(string $key,mixed $default=null): mixed {return $default;}
function site_config(string $key,mixed $default=null): mixed {return $default;}
function check(bool $ok,string $name): void {if(!$ok)throw new LogicException($name);echo "PASS $name\n";}
require __DIR__.'/../includes/auth.php';
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,display_name TEXT,role TEXT,is_active INTEGER,updated_at TEXT);
 CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,request_ip TEXT);");
$old=password_hash('Original-password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users VALUES(1,?,?,?,\'fan\',1,CURRENT_TIMESTAMP)')->execute(['one@example.test',$old,'One']);
$_SESSION=['user_id'=>1];reset_current_user_cache();
check(current_user()===null&&!isset($_SESSION['user_id']),'unbound pre-upgrade session requires fresh sign-in');
check(login_attempt('one@example.test','Original-password'),'normal login creates bound session');
$session=$_SESSION;check(current_user()['id']===1,'current credentials authorize account');
check(!str_contains(json_encode($_SESSION),$old),'session holds a fingerprint rather than raw password hash');
check(auth_change_password($pdo,1,'Original-password','Changed-password'),'owner password change checks original credential');
check(current_user()['id']===1,'legitimate password change rotates and retains initiating session');
$newSession=$_SESSION;
$_SESSION=$session;reset_current_user_cache();check(current_user()===null,'older browser session is revoked by self-service password change');
$_SESSION=$newSession;reset_current_user_cache();
$token=str_repeat('a',64);$second=str_repeat('b',64);
$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(1,?,?)')->execute([hash('sha256',$token),gmdate('Y-m-d H:i:s',time()+3600)]);
$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(1,?,?)')->execute([hash('sha256',$second),gmdate('Y-m-d H:i:s',time()+3600)]);
check(password_reset_complete($token,'Reset-new-password'),'single-use reset updates canonical credential');
reset_current_user_cache();check(current_user()===null,'password reset revokes every existing bound session');
check(!password_reset_complete($token,'Replay-password')&&!password_reset_complete($second,'Other-password'),'used and sibling reset links cannot replay');
check(login_attempt('one@example.test','Reset-new-password'),'new password can establish fresh authority');
$pdo->resetBeforeChange=true;
check(!auth_change_password($pdo,1,'Reset-new-password','Stale-new-password'),'concurrent reset cannot be overwritten by stale password change');
$stored=$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
check(password_verify('Intervening-password',$stored),'newer password survives rejected stale change');
reset_current_user_cache();check(current_user()===null,'admin/direct password replacement also invalidates session');
password_reset_request('one@example.test');
$count=(int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
password_reset_request('one@example.test');
check((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()===$count&&!$pdo->inTransaction(),'reset cooldown returns with account transaction closed');
$third=str_repeat('c',64);
$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(1,?,?)')->execute([hash('sha256',$third),gmdate('Y-m-d H:i:s',time()+3600)]);
$pdo->disableBeforeReset=true;
check(!password_reset_complete($third,'Disabled-new-password')&&!$pdo->inTransaction(),'account disabled after token lookup cannot reset credentials');
check(password_verify('Intervening-password',$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()),'disabled reset cannot change password');
$_SESSION=[];check(!login_attempt('one@example.test','Intervening-password'),'disabled account cannot sign in');
check(!auth_change_password($pdo,1,'Intervening-password','Too-short'),'invalid password cannot mutate account');
echo "AUTH_ACCOUNTS_SECTION11=PASS\n";
ob_end_flush();
