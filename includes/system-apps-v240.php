<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v230.php';

const VP3_SYSTEM_APPS_V240='user-app-private-distribution-v240-20260930';
const VP3_USER_APP_SHARE_TTL_DAYS_V240=14;

function vp3_user_app_share_schema_ready_v240(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('vp3_user_app_private_shares_v240');
}

function vp3_user_app_share_ensure_schema_v240(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_user_app_private_shares_v240 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(32) NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      recipient_user_id INT UNSIGNED NOT NULL,
      app_key VARCHAR(80) NOT NULL,
      app_name VARCHAR(160) NOT NULL,
      app_version VARCHAR(80) NOT NULL DEFAULT '',
      package_sha256 CHAR(64) NOT NULL,
      publisher_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
      permission_json LONGTEXT NOT NULL,
      data_schema_version VARCHAR(80) NOT NULL DEFAULT '1',
      grant_code_hash CHAR(64) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'pending',
      expires_at DATETIME NOT NULL,
      redeemed_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_user_app_share_public_v240 (public_id),
      INDEX idx_vp3_user_app_share_sender_v240 (sender_user_id,status,created_at),
      INDEX idx_vp3_user_app_share_recipient_v240 (recipient_user_id,status,created_at),
      INDEX idx_vp3_user_app_share_hash_v240 (package_sha256),
      CONSTRAINT fk_vp3_user_app_share_sender_v240 FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_user_app_share_recipient_v240 FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_user_app_share_descriptor_v240(int $userId,string $appKey,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.user.distribution.describe',['app_key'=>trim($appKey)],$remote);
    $descriptor=is_array($payload['descriptor']??null)?$payload['descriptor']:[];
    $hash=strtolower(trim((string)($descriptor['package_sha256']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('HomeServer returned invalid app distribution provenance.');
    if(!empty($payload['package_content_exposed'])||!empty($payload['app_data_exposed'])||!empty($payload['secrets_exposed'])){
        throw new RuntimeException('HomeServer distribution projection exposed private package content.');
    }
    return $descriptor;
}

function vp3_user_app_share_recipient_v240(PDO $pdo,string $email): array
{
    $email=mb_strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter the recipient VP3 account email.');
    $stmt=$pdo->prepare("SELECT id,email,display_name FROM users WHERE LOWER(email)=? AND is_active=1 LIMIT 1");
    $stmt->execute([$email]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('That recipient does not have an active VP3 account.');
    return $row;
}

function vp3_user_app_share_public_v240(array $row,bool $includeRecipient=true): array
{
    $permissions=json_decode((string)($row['permission_json']??'[]'),true);
    if(!is_array($permissions))$permissions=[];
    return [
      'public_id'=>(string)$row['public_id'],
      'sender_user_id'=>(int)$row['sender_user_id'],
      'recipient_user_id'=>(int)$row['recipient_user_id'],
      'recipient_email'=>$includeRecipient?(string)($row['recipient_email']??''):null,
      'recipient_name'=>$includeRecipient?(string)($row['recipient_name']??''):null,
      'sender_name'=>(string)($row['sender_name']??''),
      'app_key'=>(string)$row['app_key'],
      'app_name'=>(string)$row['app_name'],
      'app_version'=>(string)$row['app_version'],
      'package_sha256'=>(string)$row['package_sha256'],
      'publisher_fingerprint'=>(string)$row['publisher_fingerprint'],
      'permissions'=>array_values(array_map('strval',$permissions)),
      'data_schema_version'=>(string)$row['data_schema_version'],
      'status'=>(string)$row['status'],
      'expires_at'=>(string)$row['expires_at'],
      'redeemed_at'=>$row['redeemed_at']!==null?(string)$row['redeemed_at']:null,
      'revoked_at'=>$row['revoked_at']!==null?(string)$row['revoked_at']:null,
      'created_at'=>(string)$row['created_at'],
    ];
}

function vp3_user_app_share_create_v240(
    int $senderUserId,string $appKey,string $recipientEmail,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo||$senderUserId<1)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_user_app_share_schema_ready_v240($pdo))vp3_user_app_share_ensure_schema_v240($pdo);
    $recipient=vp3_user_app_share_recipient_v240($pdo,$recipientEmail);
    if((int)$recipient['id']===$senderUserId)throw new RuntimeException('Choose another VP3 account for a private share.');
    $descriptor=vp3_user_app_share_descriptor_v240($senderUserId,$appKey,$remote);
    $code=bin2hex(random_bytes(24));
    $publicId=bin2hex(random_bytes(16));
    $permissions=is_array($descriptor['permissions']??null)?array_values(array_map('strval',$descriptor['permissions'])):[];
    $stmt=$pdo->prepare("INSERT INTO vp3_user_app_private_shares_v240
      (public_id,sender_user_id,recipient_user_id,app_key,app_name,app_version,package_sha256,publisher_fingerprint,permission_json,data_schema_version,grant_code_hash,status,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',DATE_ADD(NOW(),INTERVAL ".VP3_USER_APP_SHARE_TTL_DAYS_V240." DAY))");
    $stmt->execute([
      $publicId,$senderUserId,(int)$recipient['id'],
      mb_substr((string)$descriptor['app_key'],0,80),mb_substr((string)$descriptor['name'],0,160),
      mb_substr((string)($descriptor['version']??''),0,80),(string)$descriptor['package_sha256'],
      mb_substr((string)($descriptor['publisher_fingerprint']??''),0,64),
      json_encode($permissions,JSON_UNESCAPED_SLASHES),
      mb_substr((string)($descriptor['data_schema_version']??'1'),0,80),
      hash('sha256',$code),
    ]);
    return [
      'contract'=>'vp3.user-app-private-share.v1',
      'public_id'=>$publicId,
      'grant_code'=>$code,
      'install_url'=>url('/private-app-share.php?id='.$publicId).'#code='.$code,
      'recipient'=>['id'=>(int)$recipient['id'],'email'=>(string)$recipient['email'],'display_name'=>(string)$recipient['display_name']],
      'descriptor'=>$descriptor,
      'expires_in_days'=>VP3_USER_APP_SHARE_TTL_DAYS_V240,
      'package_transport'=>'sender_provides_exported_vp3app_bundle',
    ];
}

function vp3_user_app_share_rows_v240(int $userId,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$userId<1)return ['sent'=>[],'received'=>[]];
    if(!vp3_user_app_share_schema_ready_v240($pdo))vp3_user_app_share_ensure_schema_v240($pdo);
    $sql="SELECT s.*,sender.display_name sender_name,recipient.display_name recipient_name,recipient.email recipient_email
      FROM vp3_user_app_private_shares_v240 s
      INNER JOIN users sender ON sender.id=s.sender_user_id
      INNER JOIN users recipient ON recipient.id=s.recipient_user_id
      WHERE %s=? ORDER BY s.created_at DESC,s.id DESC LIMIT 100";
    $stmt=$pdo->prepare(sprintf($sql,'s.sender_user_id'));$stmt->execute([$userId]);$sent=$stmt->fetchAll()?:[];
    $stmt=$pdo->prepare(sprintf($sql,'s.recipient_user_id'));$stmt->execute([$userId]);$received=$stmt->fetchAll()?:[];
    return [
      'contract'=>'vp3.user-app-private-share-list.v1',
      'sent'=>array_map('vp3_user_app_share_public_v240',$sent),
      'received'=>array_map(static fn($row)=>vp3_user_app_share_public_v240($row,false),$received),
    ];
}

function vp3_user_app_share_lookup_v240(int $recipientUserId,string $publicId,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo||$recipientUserId<1)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_user_app_share_schema_ready_v240($pdo))vp3_user_app_share_ensure_schema_v240($pdo);
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId))throw new RuntimeException('Private app share is invalid.');
    $stmt=$pdo->prepare("SELECT s.*,sender.display_name sender_name,recipient.display_name recipient_name,recipient.email recipient_email
      FROM vp3_user_app_private_shares_v240 s
      INNER JOIN users sender ON sender.id=s.sender_user_id
      INNER JOIN users recipient ON recipient.id=s.recipient_user_id
      WHERE s.public_id=? AND s.recipient_user_id=? LIMIT 1");
    $stmt->execute([$publicId,$recipientUserId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Private app share was not found for this account.');
    return vp3_user_app_share_public_v240($row,false);
}

function vp3_user_app_share_redeem_v240(
    int $recipientUserId,string $publicId,string $grantCode,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo||$recipientUserId<1)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_user_app_share_schema_ready_v240($pdo))vp3_user_app_share_ensure_schema_v240($pdo);
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||!preg_match('/^[a-f0-9]{48}$/',$grantCode)){
        throw new RuntimeException('Private app install grant is invalid.');
    }
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM vp3_user_app_private_shares_v240
          WHERE public_id=? AND recipient_user_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$publicId,$recipientUserId]);$row=$stmt->fetch();
        if(!$row)throw new RuntimeException('Private app share was not found for this account.');
        if((string)$row['status']==='revoked'||$row['revoked_at']!==null)throw new RuntimeException('This private app share was revoked.');
        if(strtotime((string)$row['expires_at'])<=time())throw new RuntimeException('This private app share expired.');
        if((string)$row['status']!=='pending')throw new RuntimeException('This private app install grant was already used.');
        if(!hash_equals((string)$row['grant_code_hash'],hash('sha256',$grantCode)))throw new RuntimeException('Private app install grant is invalid.');
        $pdo->prepare("UPDATE vp3_user_app_private_shares_v240
          SET status='accepted',redeemed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        $pdo->commit();
        return [
          'contract'=>'vp3.user-app-private-share-redemption.v1',
          'public_id'=>$publicId,
          'app_key'=>(string)$row['app_key'],
          'app_name'=>(string)$row['app_name'],
          'app_version'=>(string)$row['app_version'],
          'expected_package_sha256'=>(string)$row['package_sha256'],
          'publisher_fingerprint'=>(string)$row['publisher_fingerprint'],
          'install_authorized'=>true,
          'package_transport'=>'upload_sender_exported_vp3app_bundle_to_homeserver',
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_user_app_share_revoke_v240(int $senderUserId,string $publicId,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$senderUserId<1)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_user_app_share_schema_ready_v240($pdo))vp3_user_app_share_ensure_schema_v240($pdo);
    $stmt=$pdo->prepare("UPDATE vp3_user_app_private_shares_v240
      SET status='revoked',revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW()
      WHERE public_id=? AND sender_user_id=? AND status<>'revoked'");
    $stmt->execute([$publicId,$senderUserId]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Private app share was not found or is already revoked.');
    return ['contract'=>'vp3.user-app-private-share.v1','public_id'=>$publicId,'revoked'=>true];
}

function vp3_system_apps_capability_v240(): array
{
    return array_replace(vp3_system_apps_capability_v230(),[
      'user_app_private_distribution_contract'=>'vp3.user-app-private-share.v1',
      'private_share_account_binding'=>true,
      'private_share_package_hash_binding'=>true,
      'private_share_one_time_grant'=>true,
      'private_share_expiry_days'=>VP3_USER_APP_SHARE_TTL_DAYS_V240,
      'private_share_revocation'=>true,
      'cloud_package_repository'=>false,
      'cloud_source_repository'=>false,
      'package_transport'=>'sender_exported_bundle',
      'ownership_transfer'=>false,
      'marketplace'=>false,
    ]);
}
