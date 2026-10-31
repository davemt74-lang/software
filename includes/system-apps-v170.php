<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v160.php';

const VP3_SYSTEM_APPS_V170='system-app-health-diagnostics-v170-20260930';

function vp3_system_apps_health_snapshot_v170(array $user,?PDO $pdo=null,bool $readOnly=false): array
{
    $pdo??=db();
    $snapshot=vp3_system_apps_agent_snapshot_v150($user,$pdo,$readOnly);
    $connection=(array)($snapshot['connection']??[]);
    $summary=['healthy'=>0,'attention'=>0,'issue'=>0,'offline'=>0,'inactive'=>0,'ready'=>0];
    $apps=[];
    foreach((array)($snapshot['apps']??[]) as $app){
        $issues=[];
        $state='ready';$severity='info';
        if(!empty($app['cleanup_pending'])){
            $state='issue';$severity='warning';$issues[]='Ownership cleanup is waiting for HomeServer reconciliation.';
        }elseif(trim((string)($app['error']??''))!==''){
            $state='issue';$severity='error';$issues[]=trim((string)$app['error']);
        }elseif(!empty($app['installed'])&&empty($connection['connected'])){
            $state='offline';$severity='warning';$issues[]='HomeServer is offline, so live runtime state cannot be verified.';
        }elseif(!empty($app['update_available'])){
            $state='attention';$severity='info';$issues[]='A System App update is available.';
        }elseif(!empty($app['installed'])&&!empty($app['running'])){
            $state='healthy';$severity='success';
        }elseif(!empty($app['installed'])){
            $state='inactive';$severity='info';$issues[]='The app is installed but is not currently running.';
        }

        $hosting=null;
        $siteId=(int)($app['hosting_site_id']??0);
        if($siteId>0&&$pdo&&function_exists('vp3_cloud_hosting_site_v100')){
            $site=vp3_cloud_hosting_site_v100($siteId,(int)($user['id']??0),$pdo);
            if($site){
                $hosting=[
                  'site_id'=>$siteId,
                  'display_name'=>(string)($site['display_name']??''),
                  'desired_state'=>(string)($site['desired_state']??''),
                  'observed_state'=>(string)($site['observed_state']??''),
                  'route_state'=>(string)($site['route_state']??''),
                  'tls_state'=>(string)($site['tls_state']??''),
                  'hostname'=>(string)($site['canonical_hostname']??$site['requested_hostname']??''),
                ];
                if($hosting['desired_state']==='active'&&$hosting['observed_state']!=='active')$issues[]='Hosting has not reached the desired active state.';
                if($hosting['route_state']!=='active'&&$hosting['route_state']!=='ready')$issues[]='The public Hosting route is not active.';
                if(!in_array($hosting['tls_state'],['active','renewing'],true))$issues[]='Cloud-edge TLS is not active.';
                if($issues&&$state==='healthy'){$state='attention';$severity='warning';}
            }
        }
        $summary[$state]=($summary[$state]??0)+1;
        $apps[]=$app+[
          'health_state'=>$state,'health_severity'=>$severity,'health_issues'=>array_values(array_unique($issues)),
          'hosting_health'=>$hosting,
        ];
    }
    return [
      'contract'=>'vp3.system-app-health.v1',
      'connection'=>$connection,
      'summary'=>$summary,
      'apps'=>$apps,
      'authorities'=>[
        'app_runtime'=>'homeserver','ownership'=>'vp3_cloud','public_hosting'=>'cloud_hosting',
      ],
    ];
}

function vp3_system_apps_health_context_items_v170(array $user,string $query='',?PDO $pdo=null): array
{
    if($query!==''&&!preg_match('/\b(?:app|apps|application|applications|vp3 notes|vp3 inventory|vp3 checklists)\b/i',$query))return [];
    if($query!==''&&!preg_match('/\b(?:health|healthy|problem|issue|error|offline|working|update|hosted|hosting|diagnos|why|status)\b/i',$query))return [];
    if(!has_permission('account.access',$user)||!has_permission('chat.access',$user))return [];
    try{$health=vp3_system_apps_health_snapshot_v170($user,$pdo,true);}catch(Throwable $e){return [['source'=>'runtime:system-app-health-failure','title'=>'Stored System App health unavailable','text'=>'Stored System App health could not be read. No live diagnostic was performed. Do not claim healthy, broken, or empty based on this failed read.']];}
    $lines=[];
    foreach($health['apps'] as $app){
        if((string)$app['health_state']==='healthy'&&!$app['update_available'])continue;
        $line=(string)$app['name'].' · '.(string)$app['health_state'];
        if($app['health_issues'])$line.=' · '.count($app['health_issues']).' stored issue flags; review Apps diagnostics';
        $lines[]=$line;
    }
    if(!$lines)$lines[]='No issues were found in the stored System App projection; live health was not checked.';
    array_unshift($lines,'Stored observations only; this is not live runtime, route or TLS verification.');
    return [[
      'source'=>'system-apps:health',
      'title'=>'VP3 System Apps health and diagnostics context',
      'text'=>implode("\n",$lines),
      'data'=>['stored_projection'=>true,'summary'=>$health['summary']],
    ]];
}

function vp3_system_apps_health_query_v170(string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:app|apps|application|vp3 notes|vp3 inventory|vp3 checklists|notes|inventory|checklists)\b/i',$query))return $empty;
    if(!preg_match('/\b(?:health|healthy|diagnos|why|problem|issue|error|not working|broken|offline)\b/i',$query))return $empty;
    $terms=preg_replace('/\b(?:show|list|inspect|check|diagnose|health|healthy|diagnostics|why|is|are|my|app|apps|application|the|for|of|problem|issue|error|not working|broken|offline)\b/i',' ',$query);
    return vp3_system_app_agent_legacy_v1($user,$conversationId,'system_apps.health.read',trim(preg_replace('/\s+/',' ',$terms)," \t\n\r\0\x0B?.!"),'all',$remote,$pdo);
}

function vp3_system_apps_capability_v170(): array
{
    return array_replace(vp3_system_apps_capability_v160(),[
      'health_contract'=>'vp3.system-app-health.v1',
      'transition_health_events'=>true,
      'proactive_update_events'=>true,
      'proactive_error_recovery_events'=>true,
      'agent_health_context'=>true,
      'agent_diagnostics'=>true,
      'hosting_diagnostics_integration'=>true,
      'diagnostics_read_only'=>true,
    ]);
}
