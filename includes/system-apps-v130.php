<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v120.php';

const VP3_SYSTEM_APPS_V130='system-app-revocation-hardening-v130-20260930';

function vp3_system_apps_revoked_row_v130(int $userId,string $appKey,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$userId<1)return null;
    $stmt=$pdo->prepare("SELECT c.*,o.id ownership_id,o.status ownership_status
      FROM vp3_system_app_catalog c
      INNER JOIN vp3_system_app_ownership o ON o.app_id=c.id
      WHERE o.user_id=? AND o.status='revoked' AND c.app_key=? LIMIT 1");
    $stmt->execute([$userId,strtolower(trim($appKey))]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_system_apps_mark_revocation_pending_v130(int $userId,int $appId,string $message,?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)return;
    $stmt=$pdo->prepare("INSERT INTO vp3_system_app_installations
      (user_id,app_id,observed_state,last_error_code,last_error_message)
      VALUES (?,?,'revocation_pending','revocation_deactivate_pending',?)
      ON DUPLICATE KEY UPDATE observed_state='revocation_pending',
        last_error_code='revocation_deactivate_pending',
        last_error_message=VALUES(last_error_message),
        updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId,$appId,mb_substr($message,0,500)]);
}

function vp3_system_apps_deactivate_catalog_row_v130(
    int $userId,
    array $app,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    try{
        $response=vp3_system_apps_remote_v110(
            $userId,
            'apps.system.deactivate',
            ['app_key'=>(string)$app['homeserver_catalog_key']],
            $remote
        );
        $projection=vp3_system_apps_store_remote_v110($userId,$app,$response,false,$pdo);
        return [
          'ok'=>true,
          'changed'=>!empty($response['changed']),
          'reason'=>(string)($response['reason']??''),
          'homeserver'=>$projection,
        ];
    }catch(Throwable $e){
        vp3_system_apps_mark_revocation_pending_v130($userId,(int)$app['id'],$e->getMessage(),$pdo);
        return ['ok'=>false,'pending'=>true,'error'=>mb_substr($e->getMessage(),0,500)];
    }
}

function vp3_system_apps_before_ownership_revoke_v130(
    int $userId,
    string $appKey,
    ?PDO $pdo=null,
    ?callable $remote=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $result=[
      'contract'=>'vp3.system-app-revocation.v1',
      'hosting'=>['ok'=>true,'changed'=>false],
      'runtime'=>['ok'=>true,'changed'=>false],
    ];

    $hosting=vp3_system_apps_hosting_projection_v120($userId,(int)$app['id'],$pdo);
    if(!empty($hosting['bound'])){
        try{
            $unbind=vp3_system_apps_hosting_unbind_v120($userId,$appKey,$remote,$pdo);
            $result['hosting']=['ok'=>true,'changed'=>true,'result'=>$unbind['hosting']??[]];
        }catch(Throwable $e){
            // hosting_unbind_v120 commits the local desired-state/unbind before
            // attempting remote reconciliation. Keep revocation moving and
            // surface the remote cleanup as pending rather than restoring access.
            $result['hosting']=[
              'ok'=>false,'changed'=>true,'pending'=>true,
              'error'=>mb_substr($e->getMessage(),0,500),
            ];
        }
    }

    $install=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    if(!empty($install['installed'])){
        $result['runtime']=vp3_system_apps_deactivate_catalog_row_v130($userId,$app,$remote,$pdo);
    }
    return $result;
}

function vp3_system_apps_reconcile_revocations_v130(
    int $userId,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo||$userId<1)throw new RuntimeException('Database connection is unavailable.');
    $stmt=$pdo->prepare("SELECT c.*
      FROM vp3_system_app_catalog c
      INNER JOIN vp3_system_app_ownership o ON o.app_id=c.id AND o.user_id=?
      INNER JOIN vp3_system_app_installations i ON i.app_id=c.id AND i.user_id=?
      WHERE o.status='revoked'
        AND i.is_installed=1
        AND (i.observed_state='revocation_pending' OR i.last_error_code='revocation_deactivate_pending')
      ORDER BY c.id");
    $stmt->execute([$userId,$userId]);
    $items=[];
    foreach($stmt->fetchAll()?:[] as $app){
        $items[]=[
          'app_key'=>(string)$app['app_key'],
          'cleanup'=>vp3_system_apps_deactivate_catalog_row_v130($userId,$app,$remote,$pdo),
        ];
    }
    return [
      'contract'=>'vp3.system-app-revocation-reconciliation.v1',
      'count'=>count($items),
      'items'=>$items,
    ];
}

function vp3_system_apps_reconcile_all_v130(
    int $userId,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $owned=vp3_system_apps_reconcile_v110($userId,$remote,$pdo);
    $revoked=vp3_system_apps_reconcile_revocations_v130($userId,$remote,$pdo);
    return [
      'contract'=>'vp3.system-app-reconciliation.v2',
      'owned'=>$owned,
      'revoked_cleanup'=>$revoked,
      'count'=>(int)($owned['count']??0)+(int)$revoked['count'],
    ];
}

function vp3_system_apps_capability_v130(): array
{
    return array_replace(vp3_system_apps_capability_v120(),[
      'revocation_contract'=>'vp3.system-app-revocation.v1',
      'ownership_revocation_unbinds_hosting'=>true,
      'ownership_revocation_deactivates_runtime'=>true,
      'offline_revocation_cleanup_persists'=>true,
      'revocation_cleanup_retries_on_reconcile'=>true,
      'revocation_preserves_app_data'=>true,
    ]);
}
