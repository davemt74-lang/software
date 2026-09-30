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
foreach(['vp3_user_app_private_shares_v240','users'] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
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
 (2,'recipient@example.com','Recipient','user',1),
 (3,'other@example.com','Other','user',1)");

require dirname(__DIR__).'/includes/system-apps-v240.php';

$descriptor=[
 'contract'=>'vp3.app.distribution.v1','distribution_id'=>'appdist_test','app_key'=>'shared.notes',
 'name'=>'Shared Notes','version'=>'1.4.0','runtime'=>'static','sdk_version'=>'1.1','release_channel'=>'stable',
 'package_sha256'=>str_repeat('a',64),'compressed_bytes'=>1234,'expanded_bytes'=>4321,'file_count'=>8,
 'permissions'=>['notifications.write'],'data_schema_version'=>'2','source_kind'=>'project',
 'publisher_fingerprint'=>'publisherfingerprint1234567890ab','includes_app_data'=>false,'includes_secrets'=>false,
 'ownership_transfer'=>false,'publisher_seal'=>str_repeat('b',64),
];
$remote=static function(int $userId,string $operation,array $payload) use ($descriptor): array {
    if($userId!==1||$operation!=='apps.user.distribution.describe'||($payload['app_key']??'')!=='shared.notes'){
        throw new RuntimeException('Unexpected distribution remote call');
    }
    return [
      'contract'=>'vp3.user-app-distribution-projection.v1',
      'descriptor'=>$descriptor,
      'package_content_exposed'=>false,'app_data_exposed'=>false,'secrets_exposed'=>false,
    ];
};

vp3_user_app_share_ensure_schema_v240($testPdo);
if(!vp3_user_app_share_schema_ready_v240($testPdo))throw new RuntimeException('Private share schema was not created.');

$created=vp3_user_app_share_create_v240(1,'shared.notes','recipient@example.com',$remote,$testPdo);
if(($created['descriptor']['package_sha256']??'')!==str_repeat('a',64))throw new RuntimeException('Share did not bind package hash.');
if(($created['recipient']['id']??0)!==2)throw new RuntimeException('Share did not bind recipient account.');
if(!preg_match('/^[a-f0-9]{48}$/',(string)$created['grant_code']))throw new RuntimeException('Grant code shape invalid.');
if(strpos((string)$created['install_url'],(string)$created['public_id'])===false)throw new RuntimeException('Install link missing public ID.');

$rows=vp3_user_app_share_rows_v240(1,$testPdo);
if(count($rows['sent'])!==1)throw new RuntimeException('Sender share ledger missing entry.');
$received=vp3_user_app_share_rows_v240(2,$testPdo);
if(count($received['received'])!==1)throw new RuntimeException('Recipient share ledger missing entry.');

try{
    vp3_user_app_share_redeem_v240(3,$created['public_id'],$created['grant_code'],$testPdo);
    throw new RuntimeException('Wrong recipient redeemed private share.');
}catch(RuntimeException $e){
    if(str_contains($e->getMessage(),'Wrong recipient'))throw $e;
}

try{
    vp3_user_app_share_redeem_v240(2,$created['public_id'],str_repeat('0',48),$testPdo);
    throw new RuntimeException('Wrong grant code redeemed private share.');
}catch(RuntimeException $e){
    if(str_contains($e->getMessage(),'Wrong grant code'))throw $e;
}

$redeemed=vp3_user_app_share_redeem_v240(2,$created['public_id'],$created['grant_code'],$testPdo);
if(empty($redeemed['install_authorized']))throw new RuntimeException('Recipient redemption was not authorized.');
if(($redeemed['expected_package_sha256']??'')!==str_repeat('a',64))throw new RuntimeException('Redemption lost exact package hash.');

$second=vp3_user_app_share_create_v240(1,'shared.notes','other@example.com',$remote,$testPdo);
$revoked=vp3_user_app_share_revoke_v240(1,$second['public_id'],$testPdo);
if(empty($revoked['revoked']))throw new RuntimeException('Share revoke failed.');
try{
    vp3_user_app_share_redeem_v240(3,$second['public_id'],$second['grant_code'],$testPdo);
    throw new RuntimeException('Revoked share redeemed.');
}catch(RuntimeException $e){
    if(str_contains($e->getMessage(),'Revoked share redeemed'))throw $e;
}

$cap=vp3_system_apps_capability_v240();
foreach(['private_share_account_binding','private_share_package_hash_binding','private_share_one_time_grant','private_share_revocation'] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Missing private distribution capability '.$key);
}
if(!empty($cap['cloud_package_repository'])||!empty($cap['cloud_source_repository'])||!empty($cap['ownership_transfer'])||!empty($cap['marketplace'])){
    throw new RuntimeException('Section 11 expanded into forbidden repository/ownership/marketplace scope.');
}

echo "System Apps Section 11 private distribution MySQL: PASS\n";
