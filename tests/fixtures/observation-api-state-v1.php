<?php
declare(strict_types=1);
// Local integration fixture only. Production adapters never reach this setup/reset route.
require __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json');
$domains=[
    'analytics'=>['vp3_radar_properties','vp3_radar_sessions','vp3_radar_events','profile_visit_sessions','profile_events'],
    'radar'=>['vp3_radar_properties','vp3_radar_sessions','vp3_radar_events','vp3_agent_registry','vp3_agent_contacts','vp3_agent_policies','vp3_agent_access_requests'],
    'tracky'=>['user_plugin_installations','homeserver_reconciliation_state_v246','tracky_cloud_sites','tracky_cloud_world_state','tracky_cloud_context','tracky_cloud_events','tracky_cloud_scene_share_order','tracky_cloud_visual_status_order'],
];
$domain=$_GET['domain']??'';if(!isset($domains[$domain])){http_response_code(400);exit('{}');}
$pdo=db();$tables=$domains[$domain];
if(($_GET['action']??'')==='reset'){
    if($domain==='radar')$pdo->exec('DROP TRIGGER IF EXISTS radar_fixture_outcome_v1');
    foreach($tables as $table)if(table_exists($table))$pdo->exec('DELETE FROM '.$table);
}
$state=[];$counts=[];
foreach($tables as $table){$state[$table]=table_exists($table)?$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC):null;$counts[$table]=$state[$table]===null?null:count($state[$table]);}
$q=$pdo->prepare('SELECT type,name,sql FROM sqlite_master WHERE tbl_name IN ('.implode(',',array_fill(0,count($tables),'?')).') ORDER BY name');$q->execute($tables);$state['schema']=$q->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['fingerprint'=>hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)),'counts'=>$counts],JSON_THROW_ON_ERROR);
