<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v240.php';

const VP3_SYSTEM_APPS_V250='trusted-share-update-lifecycle-v250-20260930';

function vp3_user_app_share_lifecycle_ensure_schema_v250(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_user_app_share_installs_v250 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      share_public_id CHAR(32) NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      recipient_user_id INT UNSIGNED NOT NULL,
      app_key VARCHAR(80) NOT NULL,
      app_version VARCHAR(80) NOT NULL DEFAULT '',
      package_sha256 CHAR(64) NOT NULL,
      publisher_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
      installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_user_app_install_share_v250 (share_public_id,recipient_user_id),
      INDEX idx_vp3_user_app_install_recipient_v250 (recipient_user_id,app_key,last_seen_at),
      INDEX idx_vp3_user_app_install_sender_v250 (sender_user_id,app_key,last_seen_at),
      CONSTRAINT fk_vp3_user_app_install_sender_v250 FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_user_app_install_recipient_v250 FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_user_app_share_updates_v250 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      prior_share_public_id CHAR(32) NOT NULL,
      update_share_public_id CHAR(32) NOT NULL,
      sender_user_id INT UNSIGNED NOT NULL,
      recipient_user_id INT UNSIGNED NOT NULL,
      app_key VARCHAR(80) NOT NULL,
      from_package_sha256 CHAR(64) NOT NULL,
      to_package_sha256 CHAR(64) NOT NULL,
      permission_delta_json LONGTEXT NOT NULL,
      schema_from VARCHAR(80) NOT NULL DEFAULT '1',
      schema_to VARCHAR(80) NOT NULL DEFAULT '1',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_user_app_update_share_v250 (update_share_public_id),
      INDEX idx_vp3_user_app_update_recipient_v250 (recipient_user_id,app_key,created_at),
      CONSTRAINT fk_vp3_user_app_update_sender_v250 FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_user_app_update_recipient_v250 FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_user_app_share_sync_installs_v250(int $recipientUserId,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$recipientUserId<1)throw new RuntimeException('Database connection is unavailable.');
    vp3_user_app_share_ensure_schema_v240($pdo);vp3_user_app_share_lifecycle_ensure_schema_v250($pdo);
    $snapshot=vp3_user_apps_snapshot_v220($recipientUserId,$remote);
    $stmt=$pdo->prepare("SELECT * FROM vp3_user_app_private_shares_v240
      WHERE recipient_user_id=? AND status='accepted' AND revoked_at IS NULL ORDER BY created_at DESC,id DESC");
    $stmt->execute([$recipientUserId]);$shares=$stmt->fetchAll()?:[];
    $installed=[];$byKey=[];
    foreach((array)$snapshot['items'] as $app){
        if(!is_array($app))continue;
        $dist=is_array($app['distribution']??null)?$app['distribution']:[];
        if(empty($dist['installed_from_private_distribution']))continue;
        $key=(string)($app['app_key']??'');if($key==='')continue;
        $byKey[$key]=['app'=>$app,'distribution'=>$dist];
    }
    foreach($shares as $share){
        $key=(string)$share['app_key'];$current=$byKey[$key]??null;
        if(!$current)continue;
        $dist=$current['distribution'];
        if(!hash_equals((string)$share['package_sha256'],(string)($dist['package_sha256']??'')))continue;
        if((string)$share['publisher_fingerprint']!==''&&!hash_equals((string)$share['publisher_fingerprint'],(string)($dist['publisher_fingerprint']??'')))continue;
        $pdo->prepare("INSERT INTO vp3_user_app_share_installs_v250
          (share_public_id,sender_user_id,recipient_user_id,app_key,app_version,package_sha256,publisher_fingerprint,installed_at,last_seen_at)
          VALUES (?,?,?,?,?,?,?,NOW(),NOW())
          ON DUPLICATE KEY UPDATE app_version=VALUES(app_version),package_sha256=VALUES(package_sha256),
            publisher_fingerprint=VALUES(publisher_fingerprint),last_seen_at=NOW()")
          ->execute([
            (string)$share['public_id'],(int)$share['sender_user_id'],$recipientUserId,$key,
            (string)($current['app']['installed_version']??$share['app_version']),
            (string)$share['package_sha256'],(string)$share['publisher_fingerprint']
          ]);
        $installed[]=(string)$share['public_id'];
    }
    return ['contract'=>'vp3.user-app-share-install-sync.v1','installed_share_ids'=>$installed,'count'=>count($installed)];
}

function vp3_user_app_share_reissue_update_v250(
    int $senderUserId,string $priorSharePublicId,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo||$senderUserId<1)throw new RuntimeException('Database connection is unavailable.');
    vp3_user_app_share_ensure_schema_v240($pdo);vp3_user_app_share_lifecycle_ensure_schema_v250($pdo);
    $stmt=$pdo->prepare("SELECT s.*,recipient.email recipient_email
      FROM vp3_user_app_private_shares_v240 s
      INNER JOIN users recipient ON recipient.id=s.recipient_user_id
      WHERE s.public_id=? AND s.sender_user_id=? LIMIT 1");
    $stmt->execute([$priorSharePublicId,$senderUserId]);$prior=$stmt->fetch();
    if(!$prior)throw new RuntimeException('Prior private app share was not found.');
    $descriptor=vp3_user_app_share_descriptor_v240($senderUserId,(string)$prior['app_key'],$remote);
    if(hash_equals((string)$prior['package_sha256'],(string)$descriptor['package_sha256'])){
        throw new RuntimeException('This app has no newer package to share.');
    }
    if((string)$prior['publisher_fingerprint']!==''&&!hash_equals((string)$prior['publisher_fingerprint'],(string)$descriptor['publisher_fingerprint'])){
        throw new RuntimeException('Publisher fingerprint changed; update reissue is blocked.');
    }
    $created=vp3_user_app_share_create_v240(
      $senderUserId,(string)$prior['app_key'],(string)$prior['recipient_email'],$remote,$pdo
    );
    $oldPermissions=json_decode((string)$prior['permission_json'],true);if(!is_array($oldPermissions))$oldPermissions=[];
    $newPermissions=is_array($descriptor['permissions']??null)?$descriptor['permissions']:[];
    $added=array_values(array_diff($newPermissions,$oldPermissions));
    $removed=array_values(array_diff($oldPermissions,$newPermissions));
    $retained=array_values(array_intersect($oldPermissions,$newPermissions));
    $delta=['added'=>$added,'removed'=>$removed,'retained'=>$retained,'requires_review'=>!empty($added)];
    $pdo->prepare("INSERT INTO vp3_user_app_share_updates_v250
      (prior_share_public_id,update_share_public_id,sender_user_id,recipient_user_id,app_key,from_package_sha256,to_package_sha256,permission_delta_json,schema_from,schema_to)
      VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
        (string)$prior['public_id'],(string)$created['public_id'],$senderUserId,(int)$prior['recipient_user_id'],
        (string)$prior['app_key'],(string)$prior['package_sha256'],(string)$descriptor['package_sha256'],
        json_encode($delta,JSON_UNESCAPED_SLASHES),(string)$prior['data_schema_version'],
        (string)($descriptor['data_schema_version']??'1')
      ]);
    return [
      'contract'=>'vp3.user-app-private-update.v1',
      'prior_share_public_id'=>(string)$prior['public_id'],
      'update_share'=>$created,
      'permission_delta'=>$delta,
      'schema_change'=>[
        'from'=>(string)$prior['data_schema_version'],
        'to'=>(string)($descriptor['data_schema_version']??'1'),
        'changed'=>(string)$prior['data_schema_version']!==(string)($descriptor['data_schema_version']??'1'),
      ],
      'automatic_update'=>false,
    ];
}

function vp3_user_app_share_lifecycle_v250(int $userId,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$userId<1)throw new RuntimeException('Database connection is unavailable.');
    vp3_user_app_share_ensure_schema_v240($pdo);vp3_user_app_share_lifecycle_ensure_schema_v250($pdo);
    try{vp3_user_app_share_sync_installs_v250($userId,$remote,$pdo);}catch(Throwable $ignored){}
    $shares=vp3_user_app_share_rows_v240($userId,$pdo);
    $stmt=$pdo->prepare("SELECT * FROM vp3_user_app_share_installs_v250 WHERE recipient_user_id=? ORDER BY last_seen_at DESC");
    $stmt->execute([$userId]);$installs=$stmt->fetchAll()?:[];
    $installedByApp=[];
    foreach($installs as $row)$installedByApp[(string)$row['app_key']]=$row;
    $stmt=$pdo->prepare("SELECT * FROM vp3_user_app_share_updates_v250 WHERE recipient_user_id=? ORDER BY created_at DESC");
    $stmt->execute([$userId]);$updates=$stmt->fetchAll()?:[];
    $updatesByShare=[];
    foreach($updates as $row)$updatesByShare[(string)$row['update_share_public_id']]=$row;
    $received=[];
    foreach($shares['received'] as $share){
        $key=(string)$share['app_key'];$installed=$installedByApp[$key]??null;$update=$updatesByShare[(string)$share['public_id']]??null;
        $received[]=[
          ...$share,
          'installed'=>$installed!==null,
          'installed_package_sha256'=>$installed?(string)$installed['package_sha256']:'',
          'installed_version'=>$installed?(string)$installed['app_version']:'',
          'update_available'=>boolval($update&&$installed&&(string)$update['to_package_sha256']!==(string)$installed['package_sha256']),
          'update'=> $update ? [
            'from_package_sha256'=>(string)$update['from_package_sha256'],
            'to_package_sha256'=>(string)$update['to_package_sha256'],
            'permission_delta'=>json_decode((string)$update['permission_delta_json'],true)?:[],
            'schema_change'=>['from'=>(string)$update['schema_from'],'to'=>(string)$update['schema_to'],'changed'=>(string)$update['schema_from']!==(string)$update['schema_to']],
          ] : null,
          'revocation_effect'=>'blocks_future_grants_only',
        ];
    }
    return [
      'contract'=>'vp3.user-app-trusted-share-lifecycle.v1',
      'sent'=>$shares['sent'],
      'received'=>$received,
      'automatic_updates'=>false,
      'revocation_uninstalls_app'=>false,
      'ownership_transfer'=>false,
    ];
}

function vp3_user_app_share_agent_query_v250(
    string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:shared app|private app|app update|update available|who shared|publisher fingerprint|shared with me)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{$state=vp3_user_app_share_lifecycle_v250($uid,$remote,$pdo);}
    catch(Throwable $e){return $empty;}
    $rows=[];
    foreach($state['received'] as $share){
        $parts=[(string)$share['app_name'],'from '.(string)$share['sender_name']];
        if(!empty($share['installed']))$parts[]='installed'.(!empty($share['installed_version'])?' v'.$share['installed_version']:'');
        if(!empty($share['update_available']))$parts[]='update available';
        if((string)$share['status']==='revoked')$parts[]='share revoked';
        $rows[]='• '.implode(' · ',$parts);
    }
    $answer=$rows?"Private app status:\n".implode("\n",$rows):'You do not currently have any private app shares.';
    $answer.=" Updates are never installed automatically; new permissions and schema changes are reviewed before activation.";
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.user_app_shares.read',$query,'success',['count'=>count($rows)],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:user-app-shares','title'=>'Trusted private app lifecycle']],
      'private_app_lifecycle'=>$state,
    ];
}

function vp3_system_apps_capability_v250(): array
{
    return array_replace(vp3_system_apps_capability_v240(),[
      'trusted_share_lifecycle_contract'=>'vp3.user-app-trusted-share-lifecycle.v1',
      'installed_share_provenance'=>true,
      'recipient_install_sync'=>true,
      'private_update_reissue'=>true,
      'private_update_permission_delta'=>true,
      'private_update_schema_delta'=>true,
      'automatic_private_updates'=>false,
      'revocation_uninstalls_app'=>false,
      'agent_private_share_status'=>true,
    ]);
}
