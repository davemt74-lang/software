<?php
declare(strict_types=1);
// Isolated native API infrastructure. Production context/authority/receipt code is copied unchanged.
session_save_path(dirname(__DIR__));session_id('webmcp-native-fixture');session_start();
require_once __DIR__.'/agent-profile-webmcp-outcomes-v1.php';
require_once __DIR__.'/profile-webmcp-actions-v150.php';
require_once __DIR__.'/profile-webmcp-outcomes-fixture-v1.php';
$GLOBALS['native_pdo']=new PDO('sqlite:'.dirname(__DIR__).'/native.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
db()->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
function db(): PDO{return $GLOBALS['native_pdo'];}
function url(string $path): string{return $path;}
function current_user(): ?array{$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);if($id<1)return null;$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function has_permission($key,$user): bool{return $key==='chat.access'&&!empty($user['chat_allowed']);}
function personal_capability_has_v242($key,$user): bool{return $key==='profile_agent.access'&&!empty($user['profile_allowed']);}
function user_has_role(...$args): bool{return false;}
function profile_agent_schema_ready($pdo): bool{return true;}
function profile_username_normalize(string $name): string{return preg_match('/^[a-z0-9_-]{1,64}$/D',$name)?$name:'';}
function profile_by_username(PDO $pdo,string $name): ?array{$q=$pdo->prepare('SELECT * FROM user_profiles WHERE username=?');$q->execute([$name]);return $q->fetch()?:null;}
function profile_for_user(PDO $pdo,int $uid,bool $create=false): ?array{$q=$pdo->prepare('SELECT * FROM user_profiles WHERE user_id=?');$q->execute([$uid]);return $q->fetch()?:null;}
function table_exists(string $name): bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}

function native_fixture_reset_v1(): void
{
    $p=db();foreach(['profile_webmcp_actions','agent_scheduling_bookings','agent_commerce_orders_v800','merchant_accounts','campaigns','campaign_enrollments','crm_contacts','reward_issuances','reward_transfers'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    $cases=profile_webmcp_outcome_fixture_v1($p);file_put_contents(dirname(__DIR__).'/cases.json',json_encode($cases));
    $p->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER,chat_allowed INTEGER,profile_allowed INTEGER)');$p->exec('DELETE FROM users');$p->exec('INSERT INTO users VALUES(1,1,1,1),(2,1,1,1)');
    $p->exec('CREATE TABLE IF NOT EXISTS user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');$p->exec('DELETE FROM user_agents');$p->exec('INSERT INTO user_agents VALUES(7,1,1),(8,1,1)');
    $p->exec('CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');$p->exec('DELETE FROM chat_conversations');$p->exec('INSERT INTO chat_conversations VALUES(1,1,7)');
    $p->exec('CREATE TABLE IF NOT EXISTS user_profiles(user_id INTEGER PRIMARY KEY,username VARCHAR(64),is_public INTEGER,is_active INTEGER)');$p->exec('DELETE FROM user_profiles');$p->exec("INSERT INTO user_profiles VALUES(1,'fixture',1,1)");
    $p->exec('CREATE TABLE IF NOT EXISTS fixture_calls(tool VARCHAR(120))');$p->exec('DELETE FROM fixture_calls');
    $_SESSION=[];$context=$cases['booking.create']['context'];$context['allowed_tools'][]='vp3.profile.get';$context['return_token_hash']=hash('sha256',str_repeat('b',32));$context['goal']='Book through my public Profile';
    $_SESSION['vp3_profile_webmcp_action_context_v195']=[$context['context_id']=>$context];
}
if(!table_exists('users'))native_fixture_reset_v1();
$GLOBALS['native_cases']=json_decode(file_get_contents(dirname(__DIR__).'/cases.json'),true);
