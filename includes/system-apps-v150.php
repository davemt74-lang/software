<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v140.php';
require_once __DIR__.'/agent-system-app-read-v1.php';

const VP3_SYSTEM_APPS_V150='system-app-agent-integration-v150-20260930';

function vp3_system_apps_agent_snapshot_v150(array $user,?PDO $pdo=null,bool $readOnly=false): array
{
    $pdo??=db();
    if(!$pdo||(int)($user['id']??0)<1)return [
      'contract'=>'vp3.system-app-agent-context.v1','connection'=>['state'=>'unavailable','connected'=>false],
      'counts'=>['available'=>0,'owned'=>0,'installed'=>0,'running'=>0,'hosted'=>0,'updates'=>0,'cleanup_pending'=>0],
      'apps'=>[],
    ];
    $catalog=function_exists('vp3_system_apps_catalog_v200')?vp3_system_apps_catalog_v200($user,$pdo,$readOnly):vp3_system_apps_catalog_v140($user,$pdo,$readOnly);
    $apps=[];
    foreach($catalog['apps'] as $app){
        $ui=(array)($app['ui']??[]);
        $hs=(array)($app['homeserver']??[]);
        $hosting=(array)($app['hosting']??[]);
        $apps[]=[
          'app_key'=>(string)$app['app_key'],
          'name'=>(string)$app['name'],
          'category'=>(string)$app['category'],
          'catalog_version'=>(string)$app['current_version'],
          'release'=>is_array($app['release']??null)?$app['release']:[],
          'availability'=>(string)$app['availability'],
          'owned'=>!empty($app['owned']),
          'state'=>(string)($ui['primary_state']??'available'),
          'installed'=>!empty($ui['installed']),
          'running'=>!empty($ui['running']),
          'installed_version'=>$hs['installed_version']??null,
          'update_available'=>!empty($ui['update_available']),
          'cleanup_pending'=>!empty($ui['cleanup_pending']),
          'hosted'=>!empty($ui['hosted']),
          'hostname'=>$hosting['hostname']??null,
          'public_url'=>$hosting['public_url']??null,
          'hosting_site_id'=>$hosting['site_id']??null,
          'last_synced_at'=>$hs['last_synced_at']??null,
          'error'=>(string)($hs['error']??''),
        ];
    }
    return [
      'contract'=>'vp3.system-app-agent-context.v1',
      'authority'=>[
        'catalog_ownership'=>'vp3_cloud',
        'install_runtime'=>'homeserver',
        'public_route'=>'cloud_hosting',
      ],
      'connection'=>$catalog['connection']??['state'=>'unknown','connected'=>false],
      'counts'=>[
        'available'=>(int)($catalog['counts']['available']??0),
        'owned'=>(int)($catalog['counts']['owned']??0),
        'installed'=>(int)($catalog['counts']['installed']??0),
        'running'=>(int)($catalog['counts']['running']??0),
        'hosted'=>(int)($catalog['counts']['hosted']??0),
        'updates'=>(int)($catalog['counts']['updates']??0),
        'cleanup_pending'=>(int)($catalog['counts']['cleanup_pending']??0),
      ],
      'apps'=>$apps,
    ];
}

function vp3_system_apps_agent_context_items_v150(array $user,string $query='',?PDO $pdo=null): array
{
    if(!has_permission('account.access',$user)||!has_permission('chat.access',$user))return [];
    try{$snapshot=vp3_system_apps_agent_snapshot_v150($user,$pdo,true);}catch(Throwable $e){return [['source'=>'runtime:system-app-read-failure','title'=>'Stored System App projection unavailable','text'=>'The stored System App read failed. Do not treat this as an empty catalog, missing permission, or verified HomeServer health.']];}
    $counts=(array)$snapshot['counts'];
    $connection=(array)$snapshot['connection'];
    $lines=[
      'Stored System App observations; live HomeServer status was not checked.',
      'Recorded HomeServer connection: '.(string)($connection['label']??ucfirst((string)($connection['state']??'unknown'))),
      'Owned: '.(int)$counts['owned'].' · installed: '.(int)$counts['installed'].' · running: '.(int)$counts['running'].' · hosted: '.(int)$counts['hosted'],
      'Updates available: '.(int)$counts['updates'].' · cleanup pending: '.(int)$counts['cleanup_pending'],
    ];
    foreach((array)$snapshot['apps'] as $app){
        $detail=[
          (string)$app['name'],
          'recorded state '.(string)$app['state'],
          !empty($app['last_synced_at'])?'observed '.(string)$app['last_synced_at'].' UTC':'runtime unobserved',
          !empty($app['installed_version'])?'version '.(string)$app['installed_version']:'',
          !empty($app['release']['release_channel'])?'channel '.(string)$app['release']['release_channel']:'',
          !empty($app['update_available'])?'update available':'',
          !empty($app['hostname'])?'hosted at '.(string)$app['hostname']:'',
          !empty($app['error'])?'stored runtime error; review Apps diagnostics':'',
        ];
        $lines[]=implode(' · ',array_values(array_filter($detail,static fn($x)=>trim((string)$x)!=='')));
    }
    return [[
      'source'=>'system-apps:canonical',
      'title'=>'VP3 System Apps · canonical ownership, runtime and hosting state',
      'text'=>implode("\n",$lines),
      'data'=>['stored_projection'=>true,'counts'=>$snapshot['counts']],
    ]];
}

function vp3_system_apps_agent_intent_v150(string $query): bool
{
    $q=mb_strtolower(trim($query));
    if($q==='')return false;
    if(!preg_match('/\b(?:app|apps|application|applications|vp3 notes|vp3 inventory|vp3 checklists|vrss|feed)\b/i',$q))return false;
    return (bool)preg_match('/\b(?:what|which|show|list|status|running|installed|owned|available|hosted|hosting|subdomain|domain|update|updates|offline|problem|issue|health|where|open)\b/i',$q);
}

function vp3_system_apps_agent_query_v150(string $query,array $user,int $conversationId=0,?PDO $pdo=null): array
{
    if(!vp3_system_apps_agent_intent_v150($query))return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $view='all';foreach(['running','installed','hosted','updates','owned','available'] as $v)if(str_contains(mb_strtolower($query),$v)){$view=$v;break;}
    $terms=preg_replace('/\b(?:what|which|show|list|status|running|installed|owned|available|hosted|hosting|subdomain|domain|updates?|offline|problem|issue|health|where|open|my|apps?|applications?|do|i|have|are|the|for)\b/i',' ',$query);
    $terms=trim(preg_replace('/\s+/',' ',$terms)," \t\n\r\0\x0B?.!");
    if(preg_match('/\b(?:vrss|feed)\b/i',$terms))$terms='vp3.feed';
    return vp3_system_app_agent_legacy_v1($user,$conversationId,'system_apps.catalog.read',$terms,$view,null,$pdo);
}

function vp3_system_apps_agent_event_title_v150(array $row): array
{
    $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
    if(function_exists('vp3_system_apps_activity_presentation_v180')){
        $presentation=vp3_system_apps_activity_presentation_v180($row,$meta);
        return [(string)$presentation['title'],(string)$presentation['body']];
    }
    $type=(string)($row['event_type']??'app.updated');
    $name=(string)($row['app_name']??'System App');
    $map=[
      'app.ownership.acquired'=>['App added',$name.' was added to your VP3 Apps.'],
      'app.ownership.revoked'=>['App access removed',$name.' ownership was revoked.'],
      'app.install.requested'=>['App installation requested',$name.' installation was requested.'],
      'app.install.completed'=>['App installed',$name.' is installed on HomeServer.'],
      'app.install.failed'=>['App installation needs attention',$name.' could not be installed on HomeServer.'],
      'app.runtime.deactivated'=>['App deactivated',$name.' runtime was stopped on HomeServer.'],
      'app.hosting.bound'=>['App hosting assigned',$name.' was assigned to a Hosting site.'],
      'app.hosting.unbound'=>['App hosting removed',$name.' was removed from its Hosting site.'],
      'app.update.available'=>['App update available',$name.' has an update available.'],
      'app.update.cleared'=>['App update completed',$name.' is current again.'],
      'app.runtime.state_changed'=>['App runtime changed',$name.' changed runtime state.'],
      'app.health.problem'=>['App needs attention',$name.' reported a runtime or reconciliation problem.'],
      'app.health.recovered'=>['App recovered',$name.' recovered from its previous problem.'],
    ];
    return $map[$type]??['System App updated',$name.' changed state.'];
}

function vp3_system_apps_agent_reconcile_activity_v150(PDO $pdo,array $user): void
{
    if(!table_exists('vp3_system_app_events')||!table_exists('notifications'))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    try{
        $stmt=$pdo->prepare("SELECT e.id,e.event_type,e.metadata_json,e.created_at,c.app_key,c.name app_name
          FROM vp3_system_app_events e
          INNER JOIN vp3_system_app_catalog c ON c.id=e.app_id
          WHERE e.user_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
            AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.user_id=e.user_id AND n.source_type='system_app_event' AND n.source_id=e.id
            )
          ORDER BY e.id DESC LIMIT 24");
        $stmt->execute([$uid]);
        foreach(array_reverse($stmt->fetchAll()?:[]) as $row){
            [$title,$body]=vp3_system_apps_agent_event_title_v150($row);
            $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
            $target=url('/apps.php');
            if(function_exists('vp3_system_apps_activity_presentation_v180')){
                $presentation=vp3_system_apps_activity_presentation_v180($row,$meta);
                $target=(string)($presentation['target_url']??$target);
            }elseif((string)$row['event_type']==='app.install.failed'&&!empty($meta['error'])){
                $body.=' '.mb_substr((string)$meta['error'],0,220);
            }
            agent_chat_activity_notify(
              $user,
              str_replace('.','_',(string)$row['event_type']),
              $title,$body,$target,'system_app_event',(int)$row['id'],(string)$row['created_at']
            );
        }
    }catch(Throwable $e){
        error_log('System Apps activity reconcile failed: '.$e->getMessage());
    }
}

function vp3_system_apps_capability_v150(): array
{
    return array_replace(vp3_system_apps_capability_v140(),[
      'agent_context_contract'=>'vp3.system-app-agent-context.v1',
      'agent_brain_context'=>true,
      'agent_chat_read_queries'=>true,
      'activity_center_events'=>true,
      'notifications_activity_integration'=>true,
      'canonical_state_shared_across_surfaces'=>true,
      'consequential_agent_actions'=>false,
    ]);
}
