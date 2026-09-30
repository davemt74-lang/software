<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v130.php';

const VP3_SYSTEM_APPS_V140='system-app-product-ux-v140-20260930';

function vp3_system_apps_connection_snapshot_v140(int $userId,?PDO $pdo=null): array
{
    $pdo??=db();
    $row=function_exists('homeserver_vp3_connection')?homeserver_vp3_connection($userId):null;
    if(!$row)return ['state'=>'unpaired','connected'=>false,'paired'=>false,'last_seen_at'=>null,'label'=>'Not paired'];
    $status=strtolower(trim((string)($row['status']??'')));
    $paired=!in_array($status,['unpaired','revoked','disconnected',''],true);
    $connected=in_array($status,['connected','paired'],true)&&!empty($row['last_seen_at']);
    $state=$connected?'connected':($paired?'offline':($status==='revoked'?'revoked':'unpaired'));
    return [
      'state'=>$state,'connected'=>$connected,'paired'=>$paired,
      'last_seen_at'=>$row['last_seen_at']??null,
      'label'=>$connected?'Connected':($paired?'Offline':($state==='revoked'?'Pairing revoked':'Not paired')),
    ];
}

function vp3_system_apps_view_item_v140(array $app,array $connection): array
{
    $hs=is_array($app['homeserver']??null)?$app['homeserver']:[];
    $hosting=is_array($app['hosting']??null)?$app['hosting']:[];
    $owned=!empty($app['owned']);$installed=!empty($hs['installed']);$hosted=!empty($hosting['bound']);
    $pending=(string)($hs['state']??'')==='revocation_pending'||str_contains((string)($hs['error']??''),'pending');
    $update=!empty($hs['update_available']);
    $running=$installed&&(string)($hs['state']??'')==='running';
    if($pending)$primary='cleanup_pending';
    elseif($hosted)$primary='hosted';
    elseif($running)$primary='running';
    elseif($installed)$primary='installed';
    elseif($owned)$primary='owned';
    elseif(($app['ownership_status']??null)==='revoked')$primary='revoked';
    elseif(!empty($app['eligible']))$primary='available';
    else $primary='unavailable';
    $remoteReady=!empty($connection['connected']);
    return [
      'primary_state'=>$primary,'owned'=>$owned,'installed'=>$installed,'running'=>$running,'hosted'=>$hosted,
      'update_available'=>$update,'cleanup_pending'=>$pending,'remote_ready'=>$remoteReady,
      'can_install'=>$owned&&$remoteReady&&!$pending,
      'can_host'=>$installed&&$remoteReady&&!$pending,
      'badge'=>$pending?'Cleanup pending':($update?'Update available':ucwords(str_replace('_',' ',$primary))),
      'tone'=>$pending?'warning':($hosted?'hosted':($running?'running':($installed?'installed':($owned?'owned':'neutral')))),
    ];
}

function vp3_system_apps_catalog_v140(?array $user=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $catalog=vp3_system_apps_catalog_v120($user,$pdo);
    $connection=vp3_system_apps_connection_snapshot_v140((int)($user['id']??0),$pdo);
    $counts=['running'=>0,'hosted'=>(int)($catalog['counts']['hosted']??0),'updates'=>0,'cleanup_pending'=>0];
    foreach($catalog['apps'] as &$app){
        $app['ui']=vp3_system_apps_view_item_v140($app,$connection);
        foreach(['running','update_available','cleanup_pending'] as $key)if(!empty($app['ui'][$key]))$counts[$key==='update_available'?'updates':$key]++;
    }unset($app);
    $catalog['connection']=$connection;
    $catalog['counts']=array_replace($catalog['counts'],$counts);
    $catalog['ux_contract']='vp3.system-app-product-ux.v1';
    return $catalog;
}

function vp3_system_apps_capability_v140(): array
{
    return array_replace(vp3_system_apps_capability_v130(),[
      'product_ux_contract'=>'vp3.system-app-product-ux.v1',
      'connection_aware_actions'=>true,
      'hosted_filter'=>true,
      'update_visibility'=>true,
      'cleanup_pending_visibility'=>true,
      'responsive_status_hierarchy'=>true,
    ]);
}
