<?php
declare(strict_types=1);

/** Execute the historical install table and its real Chat preference migration. */
final class PSInstallPDO extends PDO
{
    public function exec(string $sql): int|false
    {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql=preg_replace('/\s+UNSIGNED\b|\s+ON UPDATE CURRENT_TIMESTAMP|\s+AFTER [a-z_]+/i','',$sql);
            $sql=preg_replace('/,\s*INDEX [a-z0-9_]+\s*\([^)]*\)/i','',$sql);
            $sql=preg_replace('/\s+ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i','',$sql);
        }
        return parent::exec($sql);
    }
}
$dsn=getenv('VP3_TEST_MYSQL_DSN');
if($dsn){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';$admin=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$database='vp3_profile_speech_install_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $dsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database;
    $pdo=new PSInstallPDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}else $pdo=new PSInstallPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $name): bool
{$p=db();$q=$p->prepare($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?":'SELECT 1 FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$name]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool
{
    $p=db();if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){foreach($p->query('PRAGMA table_info('.$table.')')->fetchAll() as $row)if($row['name']===$column)return true;return false;}
    $q=$p->prepare('SELECT 1 FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
require dirname(__DIR__).'/includes/chat-settings-v237.php';
require dirname(__DIR__).'/includes/profile-visitor-speech-pa4a.php';
$checks=0;
function psi_check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo 'PASS '.$message."\n";}
$schema=(string)file_get_contents(dirname(__DIR__).'/schema.sql');
if(!preg_match('/CREATE TABLE IF NOT EXISTS team_user_presence \([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/',$schema,$match))throw new RuntimeException('Canonical historical presence DDL is missing.');
$pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY)');$pdo->exec('INSERT INTO users VALUES(1)');$pdo->exec($match[0]);
$pdo->exec("INSERT INTO team_user_presence(user_id,page_key,context_label,last_seen_at) VALUES(1,'chat','Saved existing context','2026-01-02 03:04:05')");
psi_check(!chat_settings_schema_ready_v237($pdo)&&!chat_settings_agent_voice_schema_ready_v237($pdo),'historical fresh-install table requires canonical Chat preference normalization');
$before=$pdo->query('SELECT user_id,page_key,context_label,last_seen_at FROM team_user_presence')->fetch();
chat_settings_ensure_schema_v237($pdo);
psi_check(chat_settings_schema_ready_v237($pdo)&&chat_settings_agent_voice_schema_ready_v237($pdo),'setup normalization creates all presence, sound and Agent Voice fields');
psi_check($pdo->query('SELECT user_id,page_key,context_label,last_seen_at FROM team_user_presence')->fetch()===$before,'normalization preserves existing presence identity and content');
$settings=chat_settings_get_v237($pdo,1);
psi_check($settings['presence_mode']==='online'&&$settings['social_chat_enabled']&&$settings['sound_enabled']&&$settings['agent_voice_enabled'],'fresh settings use the existing canonical defaults');
vp3_profile_speech_ensure_schema_pa4a($pdo);
psi_check(vp3_profile_speech_schema_ready_pa4a($pdo),'speech schema is ready alongside normalized canonical Chat settings');
$pdo->exec("UPDATE team_user_presence SET presence_mode='offline',social_chat_enabled=0,sound_enabled=0,agent_voice_enabled=0 WHERE user_id=1");
$saved=$pdo->query('SELECT * FROM team_user_presence WHERE user_id=1')->fetch();
chat_settings_ensure_schema_v237($pdo);vp3_profile_speech_ensure_schema_pa4a($pdo);
psi_check($pdo->query('SELECT * FROM team_user_presence WHERE user_id=1')->fetch()===$saved,'repeated installation preserves explicit offline, muted and disabled-voice choices');
$settings=chat_settings_get_v237($pdo,1);
psi_check($settings['presence_mode']==='offline'&&!$settings['social_chat_enabled']&&!$settings['sound_enabled']&&!$settings['agent_voice_enabled'],'canonical settings read retains saved consent after idempotent normalization');
psi_check(vp3_profile_speech_schema_ready_pa4a($pdo)&&(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'idempotent setup leaves speech ready and existing account intact');
echo 'PROFILE_SPEECH_INSTALL_PA4A=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
