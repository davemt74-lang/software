<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v100.php';

const VP3_SYSTEM_APPS_V110='system-app-homeserver-install-v110-20260930';

function vp3_system_apps_schema_ready_v110(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo&&vp3_system_apps_schema_ready_v100($pdo)&&table_exists('vp3_system_app_installations');
}

function vp3_system_apps_ensure_schema_v110(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v100($pdo))vp3_system_apps_ensure_schema_v100($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_installations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      app_id INT UNSIGNED NOT NULL,
      observed_state VARCHAR(40) NOT NULL DEFAULT 'unknown',
      installed_version VARCHAR(64) NULL,
      homeserver_catalog_version VARCHAR(80) NULL,
      package_sha256 CHAR(64) NULL,
      is_installed TINYINT(1) NOT NULL DEFAULT 0,
      is_current TINYINT(1) NOT NULL DEFAULT 0,
      update_available TINYINT(1) NOT NULL DEFAULT 0,
      last_remote_sync_at DATETIME NULL,
      last_install_at DATETIME NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      remote_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_system_app_install_user_app (user_id,app_id),
      INDEX idx_vp3_system_app_install_user (user_id,is_installed,observed_state),
      CONSTRAINT fk_vp3_system_app_install_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_system_app_install_app FOREIGN KEY (app_id) REFERENCES vp3_system_app_catalog(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_system_apps_remote_v110(int $userId,string $operation,array $payload=[],?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    if(!in_array($operation,['apps.system.catalog','apps.system.status','apps.system.install','apps.system.deactivate','apps.system.reconcile','apps.system.release.status','apps.system.rollback','apps.system.permissions.status','apps.system.permissions.set','apps.user.list','apps.user.status','apps.user.workspace.status','apps.user.distribution.describe','apps.manager.status','apps.media.status','apps.media.search'],true)){
        throw new RuntimeException('Unsupported HomeServer System Apps operation.');
    }
    $result=$remote!==null?$remote($userId,$operation,$payload):homeserver_vp3_remote_operation_for_user($userId,$operation,$payload);
    if(!is_array($result))throw new RuntimeException('HomeServer returned an invalid System Apps response.');
    return $result;
}

function vp3_system_apps_owned_row_v110(int $userId,string $appKey,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo||$userId<1)throw new RuntimeException('Database connection is unavailable.');
    $stmt=$pdo->prepare("SELECT c.*,o.id ownership_id,o.status ownership_status
      FROM vp3_system_app_catalog c
      INNER JOIN vp3_system_app_ownership o ON o.app_id=c.id
      WHERE o.user_id=? AND o.status='active' AND c.app_key=? AND c.is_active=1 LIMIT 1");
    $stmt->execute([$userId,strtolower(trim($appKey))]);
    $row=$stmt->fetch();
    if(!$row)throw new RuntimeException('You must own this VP3 system app before installing it.');
    return $row;
}

function vp3_system_apps_install_projection_v110(int $userId,int $appId,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)return [];
    $stmt=$pdo->prepare('SELECT * FROM vp3_system_app_installations WHERE user_id=? AND app_id=? LIMIT 1');
    $stmt->execute([$userId,$appId]);$row=$stmt->fetch();
    if(!$row)return [
      'state'=>'not_synced','installed'=>false,'current'=>false,'update_available'=>false,
      'installed_version'=>null,'catalog_version'=>null,'last_synced_at'=>null,'last_install_at'=>null,'error'=>'',
    ];
    return [
      'state'=>(string)$row['observed_state'],
      'installed'=>!empty($row['is_installed']),
      'current'=>!empty($row['is_current']),
      'update_available'=>!empty($row['update_available']),
      'installed_version'=>$row['installed_version']!==null?(string)$row['installed_version']:null,
      'catalog_version'=>$row['homeserver_catalog_version']!==null?(string)$row['homeserver_catalog_version']:null,
      'package_sha256'=>$row['package_sha256']!==null?(string)$row['package_sha256']:null,
      'last_synced_at'=>$row['last_remote_sync_at']!==null?(string)$row['last_remote_sync_at']:null,
      'last_install_at'=>$row['last_install_at']!==null?(string)$row['last_install_at']:null,
      'error'=>(string)$row['last_error_message'],
    ];
}

function vp3_system_apps_remote_item_v110(array $remote): array
{
    $package=is_array($remote['package']??null)?$remote['package']:[];
    $key=strtolower(trim((string)($package['key']??$remote['app_key']??'')));
    $sha=strtolower(trim((string)($package['installed_package_sha256']??$package['package_sha256']??'')));
    if($sha!==''&&!preg_match('/^[a-f0-9]{64}$/',$sha))$sha='';
    return [
      'app_key'=>$key,
      'state'=>mb_substr((string)($remote['state']??$package['state']??'unknown'),0,40),
      'installed'=>!empty($remote['installed'])||!empty($package['installed']),
      'current'=>!empty($remote['current'])||!empty($package['current']),
      'update_available'=>!empty($remote['update_available'])||!empty($package['update_available']),
      'installed_version'=>trim((string)($package['installed_version']??($remote['installed_version']??'')))?:(
          !empty($remote['installed'])?trim((string)($package['version']??'')):null
      ),
      'catalog_version'=>trim((string)($remote['catalog_version']??''))?:null,
      'package_sha256'=>$sha?:null,
    ];
}

function vp3_system_apps_store_remote_v110(int $userId,array $app,array $remote,bool $installAction=false,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $before=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    $projection=vp3_system_apps_remote_item_v110($remote);
    if($projection['app_key']!==''&&$projection['app_key']!==(string)$app['homeserver_catalog_key']){
        throw new RuntimeException('HomeServer returned a mismatched System App identity.');
    }
    $stmt=$pdo->prepare("INSERT INTO vp3_system_app_installations
      (user_id,app_id,observed_state,installed_version,homeserver_catalog_version,package_sha256,is_installed,is_current,update_available,last_remote_sync_at,last_install_at,last_error_code,last_error_message,remote_json)
      VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,'','',?)
      ON DUPLICATE KEY UPDATE observed_state=VALUES(observed_state),installed_version=VALUES(installed_version),
        homeserver_catalog_version=VALUES(homeserver_catalog_version),package_sha256=VALUES(package_sha256),
        is_installed=VALUES(is_installed),is_current=VALUES(is_current),update_available=VALUES(update_available),
        last_remote_sync_at=NOW(),last_install_at=IF(VALUES(last_install_at) IS NULL,last_install_at,VALUES(last_install_at)),
        last_error_code='',last_error_message='',remote_json=VALUES(remote_json),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([
      $userId,(int)$app['id'],$projection['state'],$projection['installed_version'],$projection['catalog_version'],$projection['package_sha256'],
      $projection['installed']?1:0,$projection['current']?1:0,$projection['update_available']?1:0,
      $installAction?gmdate('Y-m-d H:i:s'):null,
      json_encode($remote,JSON_UNESCAPED_SLASHES),
    ]);
    $after=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    if(!empty($before['last_synced_at'])){
        if(empty($before['update_available'])&&!empty($after['update_available'])){
            vp3_system_apps_event_v110($userId,(int)$app['id'],'app.update.available',[
              'from_version'=>$before['installed_version']??null,'installed_version'=>$after['installed_version']??null,
              'catalog_version'=>$after['catalog_version']??null,
            ],$pdo);
        }elseif(!empty($before['update_available'])&&empty($after['update_available'])){
            vp3_system_apps_event_v110($userId,(int)$app['id'],'app.update.cleared',[
              'installed_version'=>$after['installed_version']??null,'catalog_version'=>$after['catalog_version']??null,
            ],$pdo);
        }
        $beforeState=(string)($before['state']??'');$afterState=(string)($after['state']??'');
        if($beforeState!==''&&$afterState!==''&&$beforeState!==$afterState){
            vp3_system_apps_event_v110($userId,(int)$app['id'],'app.runtime.state_changed',[
              'from'=>$beforeState,'to'=>$afterState,'installed_version'=>$after['installed_version']??null,
            ],$pdo);
        }
    }
    if((string)($before['error']??'')!==''&&(string)($after['error']??'')===''){
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.health.recovered',[
          'state'=>$after['state']??'unknown','installed_version'=>$after['installed_version']??null,
        ],$pdo);
    }
    return $after;
}

function vp3_system_apps_store_error_v110(int $userId,array $app,string $message,string $code='remote_error',?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)return;
    $before=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    $stmt=$pdo->prepare("INSERT INTO vp3_system_app_installations
      (user_id,app_id,observed_state,last_error_code,last_error_message)
      VALUES (?,?,'unknown',?,?)
      ON DUPLICATE KEY UPDATE last_error_code=VALUES(last_error_code),last_error_message=VALUES(last_error_message),updated_at=CURRENT_TIMESTAMP");
    $boundedCode=mb_substr($code,0,80);$boundedMessage=mb_substr($message,0,500);
    $stmt->execute([$userId,(int)$app['id'],$boundedCode,$boundedMessage]);
    $beforeError=(string)($before['error']??'');
    if($boundedMessage!==''&&$beforeError!==$boundedMessage){
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.health.problem',[
          'error_code'=>$boundedCode,'error'=>$boundedMessage,'previous_error'=>$beforeError,
        ],$pdo);
    }
}

function vp3_system_apps_event_v110(int $userId,int $appId,string $eventType,array $metadata=[],?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)return;
    $stmt=$pdo->prepare("INSERT INTO vp3_system_app_events(user_id,app_id,event_type,actor_type,actor_key,metadata_json)
      VALUES (?,?,?,'user',?,?)");
    $stmt->execute([$userId,$appId,mb_substr($eventType,0,80),(string)$userId,json_encode($metadata,JSON_UNESCAPED_SLASHES)]);
}

function vp3_system_apps_install_v110(int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    vp3_system_apps_event_v110($userId,(int)$app['id'],'app.install.requested',['app_key'=>$app['app_key']],$pdo);
    try{
        $response=vp3_system_apps_remote_v110($userId,'apps.system.install',['app_key'=>(string)$app['homeserver_catalog_key']],$remote);
        $projection=vp3_system_apps_store_remote_v110($userId,$app,$response,true,$pdo);
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.install.completed',[
          'app_key'=>$app['app_key'],'state'=>$projection['state'],'installed_version'=>$projection['installed_version'],
        ],$pdo);
        return ['app_key'=>(string)$app['app_key'],'changed'=>!empty($response['changed']),'reason'=>(string)($response['reason']??''),'homeserver'=>$projection];
    }catch(Throwable $e){
        vp3_system_apps_store_error_v110($userId,$app,$e->getMessage(),'install_failed',$pdo);
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.install.failed',['app_key'=>$app['app_key'],'error'=>mb_substr($e->getMessage(),0,300)],$pdo);
        throw $e;
    }
}

function vp3_system_apps_deactivate_v110(int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    try{
        $response=vp3_system_apps_remote_v110($userId,'apps.system.deactivate',['app_key'=>(string)$app['homeserver_catalog_key']],$remote);
        $projection=vp3_system_apps_store_remote_v110($userId,$app,$response,false,$pdo);
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.runtime.deactivated',[
          'app_key'=>$app['app_key'],'state'=>$projection['state'],'reason'=>(string)($response['reason']??''),
        ],$pdo);
        return ['app_key'=>(string)$app['app_key'],'changed'=>!empty($response['changed']),'reason'=>(string)($response['reason']??''),'homeserver'=>$projection];
    }catch(Throwable $e){
        vp3_system_apps_store_error_v110($userId,$app,$e->getMessage(),'deactivation_failed',$pdo);
        throw $e;
    }
}

function vp3_system_apps_reconcile_v110(int $userId,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
    $stmt=$pdo->prepare("SELECT c.* FROM vp3_system_app_catalog c INNER JOIN vp3_system_app_ownership o ON o.app_id=c.id
      WHERE o.user_id=? AND o.status='active' AND c.is_active=1 ORDER BY c.id");
    $stmt->execute([$userId]);$owned=$stmt->fetchAll()?:[];
    if(!$owned)return ['contract'=>'vp3.system-app-reconciliation.v1','count'=>0,'items'=>[]];
    $keys=array_values(array_map(static fn(array $r):string=>(string)$r['homeserver_catalog_key'],$owned));
    try{
        $response=vp3_system_apps_remote_v110($userId,'apps.system.reconcile',['app_keys'=>$keys],$remote);
    }catch(Throwable $e){
        foreach($owned as $app)vp3_system_apps_store_error_v110($userId,$app,$e->getMessage(),'reconcile_failed',$pdo);
        throw $e;
    }
    $remoteItems=is_array($response['items']??null)?$response['items']:[];
    $byKey=[];foreach($remoteItems as $item)if(is_array($item)){
        $normalized=vp3_system_apps_remote_item_v110($item);
        $key=$normalized['app_key']!==''?$normalized['app_key']:strtolower(trim((string)($item['app_key']??'')));
        if($key!=='')$byKey[$key]=$item;
    }
    $items=[];
    foreach($owned as $app){
        $key=(string)$app['homeserver_catalog_key'];
        if(isset($byKey[$key])&&!isset($byKey[$key]['error'])){
            $projection=vp3_system_apps_store_remote_v110($userId,$app,$byKey[$key],false,$pdo);
        }else{
            $message=(string)($byKey[$key]['error']??'HomeServer did not return this owned app.');
            vp3_system_apps_store_error_v110($userId,$app,$message,'reconcile_item_error',$pdo);
            $projection=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
        }
        $items[]=['app_key'=>(string)$app['app_key'],'homeserver'=>$projection];
    }
    return ['contract'=>'vp3.system-app-reconciliation.v1','count'=>count($items),'items'=>$items];
}

function vp3_system_apps_catalog_v110(?array $user=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
    $catalog=vp3_system_apps_catalog_v100($user,$pdo);
    $userId=(int)($user['id']??0);
    $installed=0;$updates=0;
    foreach($catalog['apps'] as &$app){
        $app['homeserver']=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
        if(!empty($app['homeserver']['installed']))$installed++;
        if(!empty($app['homeserver']['update_available']))$updates++;
    }unset($app);
    $catalog['installation_contract']='vp3.system-app-installation.v1';
    $catalog['counts']['installed']=$installed;
    $catalog['counts']['updates']=$updates;
    return $catalog;
}

function vp3_system_apps_capability_v110(): array
{
    return array_replace(vp3_system_apps_capability_v100(),[
      'installation_contract'=>'vp3.system-app-installation.v1',
      'homeserver_installation'=>true,
      'explicit_user_install'=>true,
      'durable_install_projection'=>true,
      'bounded_reconciliation'=>true,
      'cloud_package_execution'=>false,
      'cloud_filesystem_access'=>false,
    ]);
}
