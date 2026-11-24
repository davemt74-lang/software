<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/agent-operator-owner-phone-v1.php';
$dsn=getenv('VP3_OWNER_PHONE_TEST_DSN')?:'sqlite::memory:';
if(!in_array($dsn,['sqlite::memory:','mysql:host=127.0.0.1;port=3306;dbname=operator_owner_ci'],true))throw new RuntimeException('Dedicated test database only.');
$pdo=new PDO($dsn,getenv('VP3_OWNER_PHONE_TEST_USER')?:null,getenv('VP3_OWNER_PHONE_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if(!$mysql)$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id '.($mysql?'INT UNSIGNED':'INTEGER').' PRIMARY KEY,is_active INTEGER NOT NULL,password_hash VARCHAR(255) NOT NULL)'.($mysql?' ENGINE=InnoDB':''));
$pdo->exec("INSERT INTO users VALUES(1,1,'hash-one'),(2,1,'hash-two')");
$GLOBALS['owner_allowed']=true;$GLOBALS['participant']=null;
function has_permission(string $p,array $user):bool{return $p==='account.access'&&$GLOBALS['owner_allowed'];}
function vp3_participant_scope_v1():mixed{return $GLOBALS['participant'];}
function session8owner(int $id,string $hash):void{$_SESSION=['user_id'=>$id,'auth_password_fingerprint'=>hash('sha256',$id.':'.$hash)];}
$n=0;
function ok8owner(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);echo 'ok '.(++$n).' - '.$label."\n";}
function no8owner(callable $f,string $label):void{try{$f();}catch(DomainException){ok8owner(true,$label);return;}throw new RuntimeException('Unexpected authorization '.$label);}
$one=['id'=>1];$two=['id'=>2];$now=1800000000;
session8owner(1,'hash-one');
ok8owner(!VP3OperatorOwnerPhoneV1::ready($pdo),'missing schema does not auto-install');
no8owner(fn()=>VP3OperatorOwnerPhoneV1::read($pdo,$one),'missing migration fails closed');
VP3OperatorOwnerPhoneV1::install($pdo);VP3OperatorOwnerPhoneV1::install($pdo);
$initial=VP3OperatorOwnerPhoneV1::read($pdo,$one);
ok8owner($initial===VP3OperatorOwnerPhoneV1::defaults(),'all preferences off by default');
ok8owner((int)$pdo->query('SELECT COUNT(*) FROM phone_operator_owner_preferences_v1')->fetchColumn()===0,'read never creates a row');
$save=fn(array $data,?int $t=null)=>VP3OperatorOwnerPhoneV1::saveNumber($pdo,$one,$data,$t??$now);
$prefs=['expected_revision'=>'0','updates_requested'=>'1','hello_requested'=>'1','update_scope'=>'important','timezone'=>'America/Phoenix',
    'window_start'=>'09:00','window_end'=>'17:00','max_calls_per_day'=>'1','min_gap_minutes'=>'240','max_duration_seconds'=>'180'];
no8owner(fn()=>VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,$prefs,$now),'no call preference without a phone');
foreach(['6025550100','+0 123456789','+1abc5550100','+1+6025550100','+1 602 555 0100 ext 2',[], '+1234567890123456'] as $bad)
    no8owner(fn()=>$save(['phone_e164'=>$bad,'expected_revision'=>'0']),'invalid or ambiguous number refused');
$first=$save(['phone_e164'=>'+1 (602) 555-0100','expected_revision'=>'0','owner_user_id'=>2]);
ok8owner($first['phone_e164']==='+16025550100'&&$first['revision']===1&&$first['phone_revision']===1&&!$first['verified']&&!$first['execution_enabled'],'private canonical number saved unverified');
ok8owner($save(['phone_e164'=>'+16025550100','expected_revision'=>'1'])===$first,'same number is idempotent');
no8owner(fn()=>$save(['phone_e164'=>'+16025550102','expected_revision'=>'0']),'stale number save refused');
no8owner(fn()=>VP3OperatorOwnerPhoneV1::read($pdo,$two),'cannot read another owner');
$prefs['expected_revision']='1';
foreach([['timezone'=>'Bad/Zone'],['window_end'=>'08:00'],['window_start'=>'25:00'],['max_calls_per_day'=>'4'],
         ['min_gap_minutes'=>'1'],['max_duration_seconds'=>'301'],['max_duration_seconds'=>'61'],['updates_requested'=>'true'],['update_scope'=>'anything']] as $bad)
    no8owner(fn()=>VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,array_replace($prefs,$bad),$now+1),'invalid preference refused');
$r=VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,$prefs,$now+1);
ok8owner($r['updates_requested']&&$r['hello_requested']&&$r['consent_epoch']===$now+1&&$r['revision']===2&&!$r['verified']&&!$r['execution_enabled'],'both requested modes saved but not enabled');
ok8owner(VP3OperatorOwnerPhoneV1::read($pdo,$one)===$r,'preferences round trip');
no8owner(fn()=>VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,$prefs,$now+2),'stale preference save refused');
session8owner(2,'hash-two');
ok8owner(VP3OperatorOwnerPhoneV1::read($pdo,$two)['phone_e164']==='','other owner isolated');
no8owner(fn()=>$save(['phone_e164'=>'+16025550103','expected_revision'=>'2']),'cross-account write refused');
session8owner(1,'hash-one');$GLOBALS['participant']=['id'=>3];
no8owner(fn()=>VP3OperatorOwnerPhoneV1::read($pdo,$one),'participant cannot view private number');
$GLOBALS['participant']=null;$GLOBALS['owner_allowed']=false;
no8owner(fn()=>$save(['phone_e164'=>'+16025550103','expected_revision'=>'2']),'revoked account permission refuses write');
$GLOBALS['owner_allowed']=true;$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
no8owner(fn()=>VP3OperatorOwnerPhoneV1::read($pdo,$one),'inactive user refused');
$pdo->exec("UPDATE users SET is_active=1,password_hash='hash-new' WHERE id=1");
no8owner(fn()=>VP3OperatorOwnerPhoneV1::read($pdo,$one),'stale session refused after password reset');
session8owner(1,'hash-new');
$r2=$save(['phone_e164'=>'+16025550103','expected_revision'=>'2','verified'=>'1','execution_enabled'=>'1'],$now+3);
ok8owner($r2['revision']===3&&$r2['phone_revision']===2&&!$r2['updates_requested']&&!$r2['hello_requested']&&$r2['consent_epoch']===0&&!$r2['verified']&&!$r2['execution_enabled'],'new number clears both requests and ignores forged verification');
$prefs['expected_revision']='3';unset($prefs['updates_requested']);
$r3=VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,$prefs,$now+4);
ok8owner(!$r3['updates_requested']&&$r3['hello_requested'],'independent social call preference');
$prefs['expected_revision']='4';unset($prefs['hello_requested']);
$r4=VP3OperatorOwnerPhoneV1::savePreferences($pdo,$one,$prefs,$now+5);
ok8owner(!$r4['updates_requested']&&!$r4['hello_requested']&&$r4['consent_epoch']===0,'both call preferences can be turned off');
$removed=$save(['phone_e164'=>'','expected_revision'=>'5'],$now+6);
ok8owner($removed['phone_e164']===''&&$removed['phone_revision']===3&&!$removed['hello_requested'],'number removal clears calling requests');
$pdo->exec('DELETE FROM users WHERE id=1');
ok8owner((int)$pdo->query('SELECT COUNT(*) FROM phone_operator_owner_preferences_v1')->fetchColumn()===0,'account deletion removes personal number');
echo "PASS: $n owner phone preference checks\n";
