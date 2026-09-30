<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v160.php';

const VP3_SYSTEM_APPS_V170='system-app-health-diagnostics-v170-20260930';

function vp3_system_apps_health_snapshot_v170(array $user,?PDO $pdo=null): array
{
    $pdo??=db();
    $snapshot=vp3_system_apps_agent_snapshot_v150($user,$pdo);
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
    $health=vp3_system_apps_health_snapshot_v170($user,$pdo);
    $lines=[];
    foreach($health['apps'] as $app){
        if((string)$app['health_state']==='healthy'&&!$app['update_available'])continue;
        $line=(string)$app['name'].' · '.(string)$app['health_state'];
        if($app['health_issues'])$line.=' · '.implode('; ',$app['health_issues']);
        $lines[]=$line;
    }
    if(!$lines)$lines[]='No current System App health issues are visible in the canonical Cloud/HomeServer projection.';
    return [[
      'source'=>'system-apps:health',
      'title'=>'VP3 System Apps health and diagnostics context',
      'text'=>implode("\n",$lines),
      'data'=>$health,
    ]];
}

function vp3_system_apps_health_query_v170(string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:app|apps|application|vp3 notes|vp3 inventory|vp3 checklists|notes|inventory|checklists)\b/i',$query))return $empty;
    if(!preg_match('/\b(?:health|healthy|diagnos|why|problem|issue|error|not working|broken|offline)\b/i',$query))return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    $app=function_exists('vp3_system_apps_agent_find_app_v160')?vp3_system_apps_agent_find_app_v160($query,$user,$pdo):null;
    if(!$app){
        $health=vp3_system_apps_health_snapshot_v170($user,$pdo);
        $lines=[];foreach($health['apps'] as $row){
            if(in_array((string)$row['health_state'],['healthy','ready'],true))continue;
            $lines[]='• '.(string)$row['name'].' — '.ucfirst((string)$row['health_state']).($row['health_issues']?' · '.implode('; ',$row['health_issues']):'');
        }
        return [
          'handled'=>true,
          'answer'=>$lines?"System App health needs attention in these areas:\n".implode("\n",$lines):'I do not see a current System App health problem.',
          'stem_media'=>[],'media'=>[],'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
          'sources'=>[['source'=>'system-apps:health','title'=>'VP3 System Apps health']],
          'system_app_health'=>$health,
        ];
    }

    $health=vp3_system_apps_health_snapshot_v170($user,$pdo);$row=null;
    foreach($health['apps'] as $candidate)if((string)$candidate['app_key']===(string)$app['app_key']){$row=$candidate;break;}
    if(!$row)return $empty;
    $issues=(array)$row['health_issues'];$hostingDiagnostics=null;
    if(!empty($row['hosted'])&&(int)($row['hosting_site_id']??0)>0&&!empty($health['connection']['connected'])
        &&function_exists('vp3_cloud_hosting_diagnostics_v230_summary')){
        $site=vp3_cloud_hosting_site_v100((int)$row['hosting_site_id'],(int)$user['id'],$pdo);
        if($site){
            try{
                $hostingDiagnostics=vp3_cloud_hosting_diagnostics_v230_summary($site,60,12,$remote,$pdo);
                foreach((array)($hostingDiagnostics['issues']??[]) as $issue)$issues[]=(string)$issue;
            }catch(Throwable $e){
                $issues[]='Live Hosting diagnostics are unavailable: '.mb_substr($e->getMessage(),0,220);
            }
        }
    }
    $issues=array_values(array_unique(array_filter(array_map('trim',$issues))));
    $answer=(string)$row['name'].' is '.str_replace('_',' ',(string)$row['health_state']).'.';
    if($issues)$answer.="\n".implode("\n",array_map(static fn($issue)=>'• '.$issue,$issues));
    else $answer.=' I do not see a current runtime, update, Hosting route, TLS, or reconciliation fault.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'system_apps.diagnose',$query,'success',[
      'app_key'=>$row['app_key'],'health_state'=>$row['health_state'],'issue_count'=>count($issues),'hosting_diagnostics'=>$hostingDiagnostics!==null,
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[
        ['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')],
        !empty($row['hosted'])?['type'=>'open_url','label'=>'Manage Hosting','url'=>url('/hosting.php')]:[],
      ],
      'sources'=>[['source'=>'system-apps:health','title'=>'VP3 System Apps health'],
        ['source'=>'cloud-hosting:diagnostics','title'=>'Cloud Hosting diagnostics']],
      'system_app_health'=>['app'=>$row,'hosting_diagnostics'=>$hostingDiagnostics],
    ];
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
