<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v110.php';
require_once __DIR__.'/cloud-hosting-v100.php';
require_once __DIR__.'/cloud-hosting-v120.php';

const VP3_SYSTEM_APPS_V120='system-app-hosting-binding-v120-20260930';

function vp3_system_apps_schema_ready_v120(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo&&vp3_system_apps_schema_ready_v110($pdo)&&table_exists('vp3_system_app_hosting_bindings');
}

function vp3_system_apps_ensure_schema_v120(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
    vp3_cloud_hosting_ensure_schema_v100($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_hosting_bindings (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      app_id INT UNSIGNED NOT NULL,
      site_id BIGINT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      bound_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      unbound_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_system_app_host_app (user_id,app_id),
      UNIQUE KEY uq_vp3_system_app_host_site (site_id),
      INDEX idx_vp3_system_app_host_user (user_id,status),
      CONSTRAINT fk_vp3_system_app_host_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_system_app_host_app FOREIGN KEY (app_id) REFERENCES vp3_system_app_catalog(id) ON DELETE RESTRICT,
      CONSTRAINT fk_vp3_system_app_host_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_system_apps_hosting_binding_for_site_v120(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$siteId<1||!table_exists('vp3_system_app_hosting_bindings'))return null;
    $stmt=$pdo->prepare("SELECT b.*,c.app_key,c.homeserver_catalog_key,c.name app_name
      FROM vp3_system_app_hosting_bindings b
      INNER JOIN vp3_system_app_catalog c ON c.id=b.app_id
      WHERE b.site_id=? AND b.status='active' LIMIT 1");
    $stmt->execute([$siteId]);$row=$stmt->fetch();
    return $row?:null;
}

function vp3_system_apps_hosting_projection_v120(int $userId,int $appId,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)return [];
    $stmt=$pdo->prepare("SELECT b.*,s.site_key,s.display_name,s.requested_hostname,s.canonical_hostname,s.desired_state,s.observed_state,s.desired_revision
      FROM vp3_system_app_hosting_bindings b
      INNER JOIN cloud_hosting_sites s ON s.id=b.site_id
      WHERE b.user_id=? AND b.app_id=? AND b.status='active' LIMIT 1");
    $stmt->execute([$userId,$appId]);$row=$stmt->fetch();
    if(!$row)return ['bound'=>false,'site_id'=>null,'site_key'=>null,'display_name'=>null,'hostname'=>null,'desired_state'=>null,'observed_state'=>null];
    $hostname=trim((string)($row['canonical_hostname']??''))?:trim((string)($row['requested_hostname']??''));
    return [
      'bound'=>true,
      'site_id'=>(int)$row['site_id'],
      'site_key'=>(string)$row['site_key'],
      'display_name'=>(string)$row['display_name'],
      'hostname'=>$hostname?:null,
      'desired_state'=>(string)$row['desired_state'],
      'observed_state'=>(string)$row['observed_state'],
      'bound_at'=>(string)$row['bound_at'],
      'public_url'=>$hostname!==''?'https://'.$hostname:null,
    ];
}

function vp3_system_apps_hosting_sites_v120(int $userId,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$userId<1)return [];
    $sites=vp3_cloud_hosting_sites_v100($userId,$pdo);
    $stmt=$pdo->prepare("SELECT site_id,app_id FROM vp3_system_app_hosting_bindings WHERE user_id=? AND status='active'");
    $stmt->execute([$userId]);$used=[];
    foreach($stmt->fetchAll()?:[] as $row)$used[(int)$row['site_id']]=(int)$row['app_id'];
    $out=[];
    foreach($sites as $site){
        $id=(int)$site['id'];
        $out[]=[
          'id'=>$id,'site_key'=>(string)$site['site_key'],'display_name'=>(string)$site['display_name'],
          'hostname'=>trim((string)($site['canonical_hostname']??''))?:trim((string)($site['requested_hostname']??''))?:null,
          'desired_state'=>(string)$site['desired_state'],'observed_state'=>(string)$site['observed_state'],
          'bound_app_id'=>$used[$id]??null,
        ];
    }
    return $out;
}

function vp3_system_apps_hosting_bind_v120(int $userId,string $appKey,int $siteId,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $install=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    if(empty($install['installed']))throw new RuntimeException('Install this VP3 system app on HomeServer before assigning hosting.');
    $site=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if(!$site)throw new RuntimeException('Hosted site was not found for this account.');

    $pdo->beginTransaction();
    try{
        $other=$pdo->prepare("SELECT app_id FROM vp3_system_app_hosting_bindings WHERE site_id=? AND status='active' AND app_id<>? LIMIT 1");
        $other->execute([$siteId,(int)$app['id']]);
        if((int)$other->fetchColumn()>0)throw new RuntimeException('This hosted site is already assigned to another app.');
        $stmt=$pdo->prepare("INSERT INTO vp3_system_app_hosting_bindings(user_id,app_id,site_id,status,bound_at,unbound_at,metadata_json)
          VALUES (?,?,?,'active',NOW(),NULL,?)
          ON DUPLICATE KEY UPDATE site_id=VALUES(site_id),status='active',bound_at=NOW(),unbound_at=NULL,metadata_json=VALUES(metadata_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,(int)$app['id'],$siteId,json_encode(['contract'=>'vp3.system-app-hosting.v1'],JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1,last_error_code='',last_error_message='' WHERE id=? AND user_id=?")
            ->execute([$siteId,$userId]);
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.hosting.bound',['site_id'=>$siteId,'site_key'=>$site['site_key']],$pdo);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$site;
    $sync=vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
    return ['hosting'=>vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo),'reconcile'=>$sync];
}

function vp3_system_apps_hosting_unbind_v120(int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $stmt=$pdo->prepare("SELECT * FROM vp3_system_app_hosting_bindings WHERE user_id=? AND app_id=? AND status='active' LIMIT 1");
    $stmt->execute([$userId,(int)$app['id']]);$binding=$stmt->fetch();
    if(!$binding)return ['hosting'=>vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo),'reconcile'=>null];
    $siteId=(int)$binding['site_id'];
    $site=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);

    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE vp3_system_app_hosting_bindings SET status='inactive',unbound_at=NOW(),updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$binding['id']]);
        if($site){
            $pdo->prepare("UPDATE cloud_hosting_sites SET desired_state='configured',desired_revision=desired_revision+1,last_error_code='',last_error_message='' WHERE id=? AND user_id=?")
                ->execute([$siteId,$userId]);
        }
        vp3_system_apps_event_v110($userId,(int)$app['id'],'app.hosting.unbound',['site_id'=>$siteId],$pdo);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    $sync=null;
    if($site){
        $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$site;
        $sync=vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
    }
    return ['hosting'=>vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo),'reconcile'=>$sync];
}

function vp3_system_apps_catalog_v120(?array $user=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
    $catalog=vp3_system_apps_catalog_v110($user,$pdo);
    $userId=(int)($user['id']??0);$hosted=0;
    foreach($catalog['apps'] as &$app){
        $app['hosting']=vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo);
        if(!empty($app['hosting']['bound']))$hosted++;
    }unset($app);
    $catalog['hosting_contract']='vp3.system-app-hosting.v1';
    $catalog['counts']['hosted']=$hosted;
    $catalog['hosting_sites']=vp3_system_apps_hosting_sites_v120($userId,$pdo);
    return $catalog;
}

function vp3_system_apps_capability_v120(): array
{
    return array_replace(vp3_system_apps_capability_v110(),[
      'hosting_contract'=>'vp3.system-app-hosting.v1',
      'hosting_binding'=>true,
      'existing_hosting_sites_only'=>true,
      'hosting_owns_dns_tls'=>true,
      'one_site_per_app'=>true,
      'one_app_per_site'=>true,
      'open_marketplace'=>false,
    ]);
}
