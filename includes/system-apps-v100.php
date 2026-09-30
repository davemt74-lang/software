<?php
declare(strict_types=1);

const VP3_SYSTEM_APPS_V100='system-app-catalog-ownership-v100-20260930';

function vp3_system_apps_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && table_exists('vp3_system_app_catalog')
        && table_exists('vp3_system_app_ownership')
        && table_exists('vp3_system_app_events');
}

function vp3_system_apps_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_catalog (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      app_key VARCHAR(80) NOT NULL,
      name VARCHAR(160) NOT NULL,
      category VARCHAR(80) NOT NULL DEFAULT 'General',
      description TEXT NULL,
      current_version VARCHAR(64) NOT NULL,
      acquisition_mode VARCHAR(30) NOT NULL DEFAULT 'included',
      required_entitlement VARCHAR(120) NULL,
      homeserver_catalog_key VARCHAR(80) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 100,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_system_app_catalog_key (app_key),
      UNIQUE KEY uq_vp3_system_app_homeserver_key (homeserver_catalog_key),
      INDEX idx_vp3_system_app_catalog_active (is_active,sort_order,name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_ownership (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      app_id INT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      source_kind VARCHAR(40) NOT NULL DEFAULT 'self_service',
      source_ref VARCHAR(190) NULL,
      acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      revoked_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_system_app_owner (user_id,app_id),
      INDEX idx_vp3_system_app_ownership_user (user_id,status),
      CONSTRAINT fk_vp3_system_app_owner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_system_app_owner_app FOREIGN KEY (app_id) REFERENCES vp3_system_app_catalog(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      app_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      actor_type VARCHAR(40) NOT NULL DEFAULT 'user',
      actor_key VARCHAR(120) NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_vp3_system_app_events_user (user_id,id),
      INDEX idx_vp3_system_app_events_app (app_id,id),
      CONSTRAINT fk_vp3_system_app_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_system_app_event_app FOREIGN KEY (app_id) REFERENCES vp3_system_app_catalog(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    vp3_system_apps_seed_v100($pdo);
}

function vp3_system_apps_seed_v100(PDO $pdo): void
{
    $rows=[
      ['vp3.notes','VP3 Notes','Productivity','Private lightweight notes stored in isolated HomeServer app data.','1.1.0','included',null,'vp3.notes',10],
      ['vp3.inventory','VP3 Inventory','Operations','Track local inventory counts and supplies on your HomeServer.','1.1.0','included',null,'vp3.inventory',20],
      ['vp3.checklists','VP3 Checklists','Productivity','Create local operational and personal checklists.','1.1.0','included',null,'vp3.checklists',30],
    ];
    $sql="INSERT INTO vp3_system_app_catalog
      (app_key,name,category,description,current_version,acquisition_mode,required_entitlement,homeserver_catalog_key,is_active,sort_order,metadata_json)
      VALUES (?,?,?,?,?,?,?,?,1,?,?)
      ON DUPLICATE KEY UPDATE
        name=VALUES(name),category=VALUES(category),description=VALUES(description),
        current_version=VALUES(current_version),acquisition_mode=VALUES(acquisition_mode),
        required_entitlement=VALUES(required_entitlement),homeserver_catalog_key=VALUES(homeserver_catalog_key),
        is_active=1,sort_order=VALUES(sort_order),metadata_json=VALUES(metadata_json)";
    $stmt=$pdo->prepare($sql);
    foreach($rows as $row){
        $meta=json_encode([
          'publisher'=>'VP3',
          'distribution'=>'homeserver_prebuilt',
          'system_app'=>true,
          'protected_system_app'=>true,
          'release_channel'=>'stable',
          'release_notes'=>[
            'Adds Cloud ↔ HomeServer release lifecycle metadata.',
            'Adds verified protected updates and rollback support.',
            'Revalidates bound Hosting and subdomain routing after release changes.',
          ],
          'min_homeserver_version'=>'2.4',
          'max_homeserver_version'=>null,
        ],JSON_UNESCAPED_SLASHES);
        $stmt->execute([...$row,$meta]);
    }
}

function vp3_system_apps_json_v100(?string $raw): array
{
    if(!$raw)return [];
    $value=json_decode($raw,true);
    return is_array($value)?$value:[];
}

function vp3_system_apps_user_v100(int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT id,role,email,display_name FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_system_apps_eligible_v100(array $app,?array $user): bool
{
    if(!empty($app['owned']))return true;
    $mode=(string)($app['acquisition_mode']??'included');
    if($mode==='included')return true;
    if($mode==='entitlement'){
        $key=trim((string)($app['required_entitlement']??''));
        return $key!==''&&function_exists('subscription_has_entitlement')&&subscription_has_entitlement($user,$key);
    }
    return false;
}

function vp3_system_apps_catalog_v100(?array $user=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v100($pdo))vp3_system_apps_ensure_schema_v100($pdo);
    $userId=(int)($user['id']??0);
    $stmt=$pdo->prepare("SELECT c.*,o.id ownership_id,o.status ownership_status,o.source_kind ownership_source,
      o.acquired_at,o.revoked_at
      FROM vp3_system_app_catalog c
      LEFT JOIN vp3_system_app_ownership o ON o.app_id=c.id AND o.user_id=?
      WHERE c.is_active=1 ORDER BY c.sort_order,c.name,c.id");
    $stmt->execute([$userId]);
    $apps=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $owned=(string)($row['ownership_status']??'')==='active';
        $item=[
          'id'=>(int)$row['id'],
          'app_key'=>(string)$row['app_key'],
          'name'=>(string)$row['name'],
          'category'=>(string)$row['category'],
          'description'=>(string)($row['description']??''),
          'current_version'=>(string)$row['current_version'],
          'acquisition_mode'=>(string)$row['acquisition_mode'],
          'required_entitlement'=>$row['required_entitlement']!==null?(string)$row['required_entitlement']:null,
          'homeserver_catalog_key'=>(string)$row['homeserver_catalog_key'],
          'owned'=>$owned,
          'ownership_status'=>$owned?'active':((string)($row['ownership_status']??'')?:null),
          'ownership_source'=>$owned?(string)($row['ownership_source']??''):null,
          'acquired_at'=>$owned?(string)($row['acquired_at']??''):null,
          'metadata'=>vp3_system_apps_json_v100($row['metadata_json']??null),
        ];
        $item['eligible']=vp3_system_apps_eligible_v100($item,$user);
        $item['availability']=$owned?'owned':($item['eligible']?'available':'unavailable');
        $apps[]=$item;
    }
    return [
      'contract'=>'vp3.system-app-catalog.v1',
      'ownership_contract'=>'vp3.system-app-ownership.v1',
      'catalog_authority'=>'vp3_cloud',
      'runtime_authority'=>'homeserver',
      'apps'=>$apps,
      'counts'=>[
        'available'=>count(array_filter($apps,static fn(array $a):bool=>$a['eligible']&&!$a['owned'])),
        'owned'=>count(array_filter($apps,static fn(array $a):bool=>$a['owned'])),
        'total'=>count($apps),
      ],
    ];
}

function vp3_system_apps_acquire_v100(int $userId,string $appKey,string $sourceKind='self_service',?string $sourceRef=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v100($pdo))vp3_system_apps_ensure_schema_v100($pdo);
    $user=vp3_system_apps_user_v100($userId,$pdo);
    if(!$user)throw new RuntimeException('Account not found.');
    $key=strtolower(trim($appKey));
    $stmt=$pdo->prepare('SELECT * FROM vp3_system_app_catalog WHERE app_key=? AND is_active=1 LIMIT 1');
    $stmt->execute([$key]);$app=$stmt->fetch();
    if(!$app)throw new RuntimeException('System app not found.');

    $ownedStmt=$pdo->prepare('SELECT * FROM vp3_system_app_ownership WHERE user_id=? AND app_id=? LIMIT 1');
    $ownedStmt->execute([$userId,(int)$app['id']]);$existing=$ownedStmt->fetch();
    $probe=['owned'=>$existing&&(string)$existing['status']==='active','acquisition_mode'=>$app['acquisition_mode'],'required_entitlement'=>$app['required_entitlement']];
    if(!vp3_system_apps_eligible_v100($probe,$user))throw new RuntimeException('This app is not available for this account.');

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO vp3_system_app_ownership
          (user_id,app_id,status,source_kind,source_ref,acquired_at,revoked_at,metadata_json)
          VALUES (?,?,'active',?,?,CURRENT_TIMESTAMP,NULL,?)
          ON DUPLICATE KEY UPDATE acquired_at=IF(revoked_at IS NULL,acquired_at,CURRENT_TIMESTAMP),status='active',source_kind=VALUES(source_kind),source_ref=VALUES(source_ref),
            revoked_at=NULL,updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,(int)$app['id'],mb_substr($sourceKind,0,40),$sourceRef?mb_substr($sourceRef,0,190):null,json_encode(['catalog_version'=>(string)$app['current_version']],JSON_UNESCAPED_SLASHES)]);
        $event=$pdo->prepare("INSERT INTO vp3_system_app_events(user_id,app_id,event_type,actor_type,actor_key,metadata_json)
          VALUES (?,?,'app.ownership.acquired','user',?,?)");
        $event->execute([$userId,(int)$app['id'],(string)$userId,json_encode(['source_kind'=>$sourceKind,'source_ref'=>$sourceRef],JSON_UNESCAPED_SLASHES)]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    $catalog=vp3_system_apps_catalog_v100($user,$pdo);
    foreach($catalog['apps'] as $item)if($item['app_key']===$key)return $item;
    throw new RuntimeException('System app ownership could not be loaded.');
}

function vp3_system_apps_revoke_v100(int $userId,string $appKey,string $reason='admin',?PDO $pdo=null,?callable $remote=null): bool
{
    $pdo??=db();
    if(!$pdo||$userId<1)return false;
    $stmt=$pdo->prepare("SELECT c.id FROM vp3_system_app_catalog c WHERE c.app_key=? LIMIT 1");
    $stmt->execute([strtolower(trim($appKey))]);$appId=(int)$stmt->fetchColumn();
    if($appId<1)return false;
    $cleanup=[];
    if(function_exists('vp3_system_apps_before_ownership_revoke_v130')){
        try{
            $cleanup=vp3_system_apps_before_ownership_revoke_v130($userId,strtolower(trim($appKey)),$pdo,$remote);
        }catch(Throwable $ignored){
            $cleanup=['cleanup'=>'pending','error'=>'revocation_cleanup_failed'];
        }
    }
    $pdo->beginTransaction();
    try{
        $update=$pdo->prepare("UPDATE vp3_system_app_ownership SET status='revoked',revoked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND app_id=? AND status='active'");
        $update->execute([$userId,$appId]);
        if($update->rowCount()>0){
            $event=$pdo->prepare("INSERT INTO vp3_system_app_events(user_id,app_id,event_type,actor_type,actor_key,metadata_json) VALUES (?,?,'app.ownership.revoked','system','admin',?)");
            $event->execute([$userId,$appId,json_encode(['reason'=>mb_substr($reason,0,500),'cleanup'=>$cleanup],JSON_UNESCAPED_SLASHES)]);
        }
        $pdo->commit();
        return $update->rowCount()>0;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_system_apps_capability_v100(): array
{
    return [
      'contract'=>'vp3.system-app-catalog.v1',
      'cloud_catalog_authority'=>true,
      'durable_user_ownership'=>true,
      'package_eligibility_separate_from_ownership'=>true,
      'homeserver_runtime_authority'=>true,
      'homeserver_installation'=>false,
      'hosting_binding'=>false,
      'open_marketplace'=>false,
      'developer_sdk_expansion'=>false,
    ];
}
