<?php
declare(strict_types=1);

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$dbUser=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$dbUser,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {$s=db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function url(string $path=''): string {return $path;}
function setting(string $key,string $default=''): string {return $default;}
function subscription_current_for_user_id(int $userId): ?array {return ['package_slug'=>'basic-user'];}
function subscription_has_entitlement(?array $user,string $key): bool {return true;}
function subscription_effective_entitlement_v340(?array $user,string $key): array {return ['key'=>$key,'enabled'=>true,'limit'=>10,'unlimited'=>false];}
function homeserver_vp3_connection(int $userId): ?array {return ['user_id'=>$userId,'status'=>'connected','last_seen_at'=>date('Y-m-d H:i:s')];}
function homeserver_vp3_remote_operation_for_user(int $userId,string $operation,array $payload=[]): array {throw new RuntimeException('Unexpected real remote operation');}
function agent_tool_log(array $user,string $tool,string $request,string $status,array $result=[],int $conversationId=0): void {}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['vp3_user_app_share_updates_v250','vp3_user_app_share_installs_v250','vp3_user_app_private_shares_v240','users'] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 display_name VARCHAR(120) NOT NULL,
 role VARCHAR(30) NOT NULL DEFAULT 'user',
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role,is_active) VALUES
 (1,'sender@example.com','Sender','user',1),
 (2,'recipient@example.com','Recipient','user',1)");

require dirname(__DIR__).'/includes/system-apps-v250.php';

$descriptor=[
 'contract'=>'vp3.app.distribution.v1','distribution_id'=>'appdist_v1','app_key'=>'shared.notes',
 'name'=>'Shared Notes','version'=>'1.0.0','runtime'=>'static','sdk_version'=>'1.1','release_channel'=>'stable',
 'package_sha256'=>str_repeat('a',64),'compressed_bytes'=>1234,'expanded_bytes'=>4321,'file_count'=>8,
 'permissions'=>['notifications.write'],'data_schema_version'=>'1','source_kind'=>'project',
 'publisher_fingerprint'=>'publisherfingerprint1234567890ab','includes_app_data'=>false,'includes_secrets'=>false,
 'ownership_transfer'=>false,'publisher_seal'=>str_repeat('b',64),
];
$installedHash=str_repeat('a',64);
$remote=static function(int $userId,string $operation,array $payload) use (&$descriptor,&$installedHash): array {
    if($operation==='apps.user.distribution.describe'&&$userId===1){
        return ['contract'=>'vp3.user-app-distribution-projection.v1','descriptor'=>$descriptor,'package_content_exposed'=>false,'app_data_exposed'=>false,'secrets_exposed'=>false];
    }
    if($operation==='apps.user.list'&&$userId===2){
        return ['contract'=>'vp3.user-app-list.v1','count'=>1,'items'=>[[
          'app_key'=>'shared.notes','name'=>'Shared Notes','source_type'=>'zip','lifecycle_state'=>'running',
          'installed_version'=>$installedHash===str_repeat('a',64)?'1.0.0':'2.0.0','runtime'=>'static','sdk_version'=>'1.1',
          'permissions'=>null,'release'=>null,'data'=>null,
          'distribution'=>[
            'contract'=>'vp3.app.distribution-provenance.v1','installed_from_private_distribution'=>true,
            'publisher_fingerprint'=>'publisherfingerprint1234567890ab',
            'package_sha256'=>$installedHash,'installed_version'=>$installedHash===str_repeat('a',64)?'1.0.0':'2.0.0',
            'last_share_public_id'=>'','ownership_transferred'=>false,
          ]
        ]]];
    }
    throw new RuntimeException('Unexpected remote operation '.$operation.' for '.$userId);
};

vp3_user_app_share_ensure_schema_v240($testPdo);
vp3_user_app_share_lifecycle_ensure_schema_v250($testPdo);
$share=vp3_user_app_share_create_v240(1,'shared.notes','recipient@example.com',$remote,$testPdo);
vp3_user_app_share_redeem_v240(2,$share['public_id'],$share['grant_code'],$testPdo);

$sync=vp3_user_app_share_sync_installs_v250(2,$remote,$testPdo);
if(($sync['count']??0)!==1)throw new RuntimeException('Accepted share did not synchronize installed provenance.');
$state=vp3_user_app_share_lifecycle_v250(2,$remote,$testPdo);
if(empty($state['received'][0]['installed']))throw new RuntimeException('Recipient installed state missing.');
if(!empty($state['received'][0]['update_available']))throw new RuntimeException('Initial share incorrectly reports update.');

$descriptor['distribution_id']='appdist_v2';
$descriptor['version']='2.0.0';
$descriptor['package_sha256']=str_repeat('c',64);
$descriptor['permissions']=['notifications.write','network.external'];
$descriptor['data_schema_version']='2';

$update=vp3_user_app_share_reissue_update_v250(1,$share['public_id'],$remote,$testPdo);
if(($update['update_share']['descriptor']['package_sha256']??'')!==str_repeat('c',64))throw new RuntimeException('Update share did not bind new hash.');
if(empty($update['permission_delta']['requires_review']))throw new RuntimeException('New permission did not require review.');
if(!in_array('network.external',$update['permission_delta']['added'],true))throw new RuntimeException('Permission delta missed network.external.');
if(empty($update['schema_change']['changed']))throw new RuntimeException('Schema delta was not surfaced.');
if(!empty($update['automatic_update']))throw new RuntimeException('Private update became automatic.');

$recipient=vp3_user_app_share_lifecycle_v250(2,$remote,$testPdo);
$updates=array_values(array_filter($recipient['received'],static fn($row)=>!empty($row['update_available'])));
if(count($updates)!==1)throw new RuntimeException('Recipient update availability was not surfaced.');
if(($updates[0]['update']['schema_change']['to']??'')!=='2')throw new RuntimeException('Recipient schema review missing.');

vp3_user_app_share_revoke_v240(1,$update['update_share']['public_id'],$testPdo);
$afterRevoke=vp3_user_app_share_lifecycle_v250(2,$remote,$testPdo);
$revoked=array_values(array_filter($afterRevoke['received'],static fn($row)=>(string)$row['status']==='revoked'));
if(count($revoked)!==1)throw new RuntimeException('Revoked update share not visible.');
$installedRows=$testPdo->query("SELECT COUNT(*) FROM vp3_user_app_share_installs_v250")->fetchColumn();
if((int)$installedRows<1)throw new RuntimeException('Revocation removed installed provenance.');

$cap=vp3_system_apps_capability_v250();
if(!empty($cap['automatic_private_updates'])||!empty($cap['revocation_uninstalls_app'])||empty($cap['private_update_permission_delta'])||empty($cap['private_update_schema_delta'])){
    throw new RuntimeException('Trusted share lifecycle capability flags invalid.');
}

echo "System Apps Section 12 trusted share lifecycle MySQL: PASS\n";
