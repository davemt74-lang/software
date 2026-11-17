<?php
declare(strict_types=1);

function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $query=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function url(string $path): string{return $path;}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['participant']??null;}
function vp3_user_llm_v1_funding(PDO $pdo,array $user): string{return $GLOBALS['funding'][(int)$user['id']]??'system';}
function vp3_user_llm_v1_saved_key(PDO $pdo,int $owner,string $provider): string{return $GLOBALS['keys'][$owner]??'';}
function ai_provider_enabled(string $provider): bool{return $provider==='anthropic';}
function ai_provider_api_key(string $provider): string{return 'fixture-system-key';}
function subscription_is_internal_admin(array $user): bool{return ($user['role']??null)==='admin';}
function chat_settings_agent_voice_enabled_v237(PDO $pdo,array $user): bool{return $GLOBALS['voice']??true;}

require_once dirname(__DIR__).'/includes/notifications.php';
require_once dirname(__DIR__).'/includes/agent-anthropic-credit-notice-v1.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL);
    INSERT INTO users VALUES(1,1),(2,1),(3,1),(4,0);
    CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,
        body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        is_read INTEGER DEFAULT 0,read_at TEXT)");
$checks=0;
function credit_notice_check(bool $test,string $message): void
{
    if(!$test)throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
$owner=['id'=>1,'role'=>'customer'];
$other=['id'=>2,'role'=>'customer'];
$admin=['id'=>3,'role'=>'admin'];
$inactive=['id'=>4,'role'=>'customer'];
$GLOBALS['funding']=[1=>'own_key',2=>'own_key'];
$GLOBALS['keys']=[1=>'fixture-owner-key',2=>'fixture-other-key'];
$hash=hash('sha256','fixture-owner-key');
$system=hash('sha256','fixture-system-key');
$first=static fn():?array=>vp3_anthropic_credit_notice_current_v1($pdo,$owner);
$count=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();

vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,false,200);
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,true,100);
credit_notice_check($first()===null&&$count()===0,'a stale rejection cannot overwrite a newer successful request');
$GLOBALS['participant']=['actor_id'=>2];
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,true,250);
unset($GLOBALS['participant']);
vp3_anthropic_credit_observe_v1($pdo,$inactive,'system',$system,true,250);
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',hash('sha256','stale-key'),true,250);
credit_notice_check($count()===0,'participant, inactive account and stale key cannot create a credit notice');

vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,true,300);
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,true,400);
$notice=$first();
credit_notice_check($count()===1&&$notice['voice_pending']
    &&str_contains($notice['target_url'],'platform.claude.com/settings/billing')
    &&!str_contains(json_encode($notice,JSON_THROW_ON_ERROR),$hash)
    &&!notification_requires_attention($pdo->query('SELECT * FROM notifications')->fetch(PDO::FETCH_ASSOC)),
    'a personal-key rejection creates one owner billing notification with no private key material or duplicate Chat injection');
$id=$notice['notification_id'];
$GLOBALS['voice']=false;
credit_notice_check(vp3_anthropic_credit_notice_claim_v1($pdo,$owner,$id)===null&&$first()['voice_pending'],
    'muting Agent Voice leaves the notice available');
$GLOBALS['voice']=true;
credit_notice_check(vp3_anthropic_credit_notice_claim_v1($pdo,$other,$id)===null
    &&vp3_anthropic_credit_notice_claim_v1($pdo,$owner,$id)['notification_id']===$id
    &&vp3_anthropic_credit_notice_claim_v1($pdo,$owner,$id)===null&&!$first()['voice_pending'],
    'only the owner can claim the spoken notice and can do so once');
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,false,500);
credit_notice_check($first()===null&&(int)$pdo->query('SELECT is_read FROM notifications WHERE id='.$id)->fetchColumn()===1,
    'a successful request using the same credential clears the current alert and unread badge');
vp3_anthropic_credit_observe_v1($pdo,$owner,'own_key',$hash,true,600);
credit_notice_check($count()===2&&$first()['notification_id']!==$id&&$first()['voice_pending'],
    'a new credit incident can speak again after a successful request');
$GLOBALS['keys'][1]='fixture-replacement-key';
credit_notice_check($first()===null,'key rotation invalidates the active notice and voice offer');

$GLOBALS['funding'][2]='system';
vp3_anthropic_credit_observe_v1($pdo,$other,'system',$system,true,700);
vp3_anthropic_credit_observe_v1($pdo,$admin,'system',$system,true,700);
$service=vp3_anthropic_credit_notice_current_v1($pdo,$other);
$operations=vp3_anthropic_credit_notice_current_v1($pdo,$admin);
credit_notice_check($service['target_url']==='/settings-ai-providers.php'&&str_contains($service['message'],'VP3 manages')
    &&str_contains($operations['target_url'],'platform.claude.com/settings/billing'),
    'only a personal key owner or internal admin receives the provider billing link');
echo 'AGENT_ANTHROPIC_CREDIT_NOTICE_V1=PASS ('.$checks." checks)\n";
