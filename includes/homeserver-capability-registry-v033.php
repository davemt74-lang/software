<?php
declare(strict_types=1);

/**
 * VP3 v0.33 — authenticated, sanitized HomeServer capability inventory.
 *
 * HomeServer remains the source of truth. This layer never stores or returns
 * relay/bearer credentials and degrades to an explicit unavailable registry.
 */
function homeserver_capability_v033_empty(string $reason='unavailable'): array
{
    return [
        'version'=>'v0.33',
        'available'=>false,
        'reason'=>mb_strimwidth($reason,0,80,''),
        'registry_version'=>'',
        'service'=>'HomeServer',
        'installed_version'=>'',
        'app'=>['key'=>'vp3','permissions'=>[],'scope'=>[]],
        'brain'=>['available'=>false,'primary'=>null],
        'compute'=>['available'=>false,'preferred_provider'=>'','selected_provider'=>'','model'=>'','compute_source'=>'','cloud_fallback_required'=>false,'providers'=>[],'installed_local_models'=>[]],
        'memory'=>['available'=>false,'readable'=>false,'writable'=>false,'visible_items'=>0,'restricted'=>false],
        'knowledge'=>['available'=>false,'searchable'=>false,'writable'=>false,'visible_items'=>0,'visible_kinds'=>[],'restricted'=>false],
        'files'=>['available'=>false,'source_count'=>0,'enabled_sources'=>0,'tracked_files'=>0,'indexed_files'=>0,'supported_extensions'=>[]],
        'contacts'=>['available'=>false,'readable'=>false,'visible_contacts'=>0],
        'tools'=>[], 'skills'=>[], 'plugins'=>[], 'services'=>[], 'operations'=>[],
        'counts'=>['tools'=>0,'available_tools'=>0,'skills'=>0,'available_skills'=>0,'plugins'=>0,'local_models'=>0],
    ];
}

function homeserver_capability_v033_strings(mixed $value,int $maxItems=100,int $maxChars=160): array
{
    if(!is_array($value))return [];
    $out=[];
    foreach($value as $item){
        if(!is_scalar($item))continue;
        $item=mb_strimwidth(trim((string)$item),0,$maxChars,'');
        if($item===''||in_array($item,$out,true))continue;
        $out[]=$item;
        if(count($out)>=$maxItems)break;
    }
    return $out;
}

function homeserver_capability_v033_named_items(mixed $value,array $allowedKeys,int $maxItems=100): array
{
    if(!is_array($value))return [];
    $out=[];
    foreach($value as $item){
        if(!is_array($item))continue;
        $safe=[];
        foreach($allowedKeys as $key=>$kind){
            if(!array_key_exists($key,$item))continue;
            $raw=$item[$key];
            if($kind==='bool')$safe[$key]=!empty($raw);
            elseif($kind==='int')$safe[$key]=max(0,(int)$raw);
            elseif($kind==='strings')$safe[$key]=homeserver_capability_v033_strings($raw,50,120);
            else $safe[$key]=mb_strimwidth(trim((string)$raw),0,(int)$kind,'');
        }
        if($safe!==[])$out[]=$safe;
        if(count($out)>=$maxItems)break;
    }
    return $out;
}

function homeserver_capability_v033_normalize(array $raw): array
{
    $base=homeserver_capability_v033_empty('none');
    $app=is_array($raw['app']??null)?$raw['app']:[];
    $brain=is_array($raw['brain']??null)?$raw['brain']:[];
    $primary=is_array($brain['primary']??null)?$brain['primary']:null;
    $compute=is_array($raw['compute']??null)?$raw['compute']:[];
    $memory=is_array($raw['memory']??null)?$raw['memory']:[];
    $knowledge=is_array($raw['knowledge']??null)?$raw['knowledge']:[];
    $files=is_array($raw['files']??null)?$raw['files']:[];
    $contacts=is_array($raw['contacts']??null)?$raw['contacts']:[];
    $counts=is_array($raw['counts']??null)?$raw['counts']:[];

    $base['available']=true;
    $base['reason']='ready';
    $base['registry_version']=mb_strimwidth(trim((string)($raw['registry_version']??'')),0,40,'');
    $base['service']=mb_strimwidth(trim((string)($raw['service']??'HomeServer')),0,120,'');
    $base['installed_version']=mb_strimwidth(trim((string)($raw['version']??'')),0,64,'');
    $base['app']=[
        'key'=>mb_strimwidth(trim((string)($app['key']??'vp3')),0,80,''),
        'permissions'=>homeserver_capability_v033_strings($app['permissions']??[],100,120),
        'scope'=>is_array($app['scope']??null)?[
            'cloud_allowed'=>!empty($app['scope']['cloud_allowed']),
            'memory_key_prefixes'=>homeserver_capability_v033_strings($app['scope']['memory_key_prefixes']??[],100,160),
            'knowledge_kinds'=>homeserver_capability_v033_strings($app['scope']['knowledge_kinds']??[],100,120),
            'tool_names'=>homeserver_capability_v033_strings($app['scope']['tool_names']??[],100,120),
            'plugin_keys'=>homeserver_capability_v033_strings($app['scope']['plugin_keys']??[],100,120),
        ]:[],
    ];
    $base['brain']=[
        'available'=>!empty($brain['available']),
        'primary'=>$primary?[
            'id'=>max(0,(int)($primary['id']??0)),
            'name'=>mb_strimwidth(trim((string)($primary['name']??'')),0,120,''),
            'model'=>mb_strimwidth(trim((string)($primary['model']??'')),0,160,''),
            'updated_at'=>mb_strimwidth(trim((string)($primary['updated_at']??'')),0,64,''),
        ]:null,
    ];
    $base['compute']=[
        'available'=>!empty($compute['available']),
        'preferred_provider'=>mb_strimwidth(trim((string)($compute['preferred_provider']??'')),0,80,''),
        'selected_provider'=>mb_strimwidth(trim((string)($compute['selected_provider']??'')),0,80,''),
        'model'=>mb_strimwidth(trim((string)($compute['model']??'')),0,160,''),
        'compute_source'=>mb_strimwidth(trim((string)($compute['compute_source']??'')),0,80,''),
        'cloud_fallback_required'=>!empty($compute['cloud_fallback_required']),
        'providers'=>homeserver_capability_v033_named_items($compute['providers']??[],[
            'key'=>80,'name'=>120,'kind'=>80,'model'=>160,'enabled'=>'bool','ready'=>'bool','compute_source'=>80,
        ]),
        'installed_local_models'=>homeserver_capability_v033_strings($compute['installed_local_models']??[],100,160),
    ];
    $base['memory']=[
        'available'=>!empty($memory['available']),'readable'=>!empty($memory['readable']),'writable'=>!empty($memory['writable']),
        'visible_items'=>max(0,(int)($memory['visible_items']??0)),'restricted'=>!empty($memory['restricted']),
    ];
    $base['knowledge']=[
        'available'=>!empty($knowledge['available']),'searchable'=>!empty($knowledge['searchable']),'writable'=>!empty($knowledge['writable']),
        'visible_items'=>max(0,(int)($knowledge['visible_items']??0)),
        'visible_kinds'=>homeserver_capability_v033_strings($knowledge['visible_kinds']??[],100,120),'restricted'=>!empty($knowledge['restricted']),
    ];
    $base['files']=[
        'available'=>!empty($files['available']),'source_count'=>max(0,(int)($files['source_count']??0)),
        'enabled_sources'=>max(0,(int)($files['enabled_sources']??0)),'tracked_files'=>max(0,(int)($files['tracked_files']??0)),
        'indexed_files'=>max(0,(int)($files['indexed_files']??0)),
        'supported_extensions'=>homeserver_capability_v033_strings($files['supported_extensions']??[],100,16),
    ];
    $base['contacts']=[
        'available'=>!empty($contacts['available']),'readable'=>!empty($contacts['readable']),
        'visible_contacts'=>max(0,(int)($contacts['visible_contacts']??0)),
    ];
    $base['tools']=homeserver_capability_v033_named_items($raw['tools']??[],[
        'key'=>80,'name'=>160,'mode'=>20,'enabled'=>'bool','available'=>'bool','required_permissions'=>'strings',
    ]);
    $base['skills']=homeserver_capability_v033_named_items($raw['skills']??[],[
        'key'=>80,'name'=>160,'available'=>'bool','tools'=>'strings',
    ]);
    $base['plugins']=homeserver_capability_v033_named_items($raw['plugins']??[],[
        'key'=>80,'name'=>160,'version'=>80,'trusted'=>'bool','tool_keys'=>'strings',
    ]);
    $base['services']=homeserver_capability_v033_named_items($raw['services']??[],[
        'key'=>80,'available'=>'bool','status'=>40,'version'=>64,
    ]);
    $base['operations']=homeserver_capability_v033_strings($raw['operations']??[],200,80);
    foreach(array_keys($base['counts']) as $key)$base['counts'][$key]=max(0,(int)($counts[$key]??0));
    return $base;
}

function homeserver_capability_v033_registry(int $userId,bool $forceRefresh=false): array
{
    if($userId<1||!function_exists('homeserver_agent_v018_credentials')||!function_exists('homeserver_vp3_remote_operation')){
        return homeserver_capability_v033_empty('unpaired');
    }
    $legacy=function_exists('homeserver_capability_v024_registry')?homeserver_capability_v024_registry($userId,$forceRefresh):[];
    if(empty($legacy['paired']))return homeserver_capability_v033_empty('unpaired');
    if(empty($legacy['connected']))return homeserver_capability_v033_empty('offline');
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials)return homeserver_capability_v033_empty('credentials_unavailable');
    try{
        $raw=homeserver_vp3_remote_operation((string)$credentials['relay'],'capability.registry',[],(string)$credentials['home']);
        if(!is_array($raw)||trim((string)($raw['registry_version']??''))==='')return homeserver_capability_v033_empty('invalid_response');
        return homeserver_capability_v033_normalize($raw);
    }catch(Throwable $e){
        return homeserver_capability_v033_empty('remote_unavailable');
    }
}

/** Facts consumed by the existing canonical v0.31 AI Gateway planner. */
function homeserver_capability_v033_gateway_facts(array $registry): array
{
    $compute=is_array($registry['compute']??null)?$registry['compute']:[];
    $scope=is_array($registry['app']['scope']??null)?$registry['app']['scope']:[];
    return [
        'home_supported'=>!empty($registry['available'])&&in_array('agent.chat',$registry['operations']??[],true),
        'home_ready'=>!empty($registry['available'])&&!empty($compute['available']),
        'homeserver_cloud_allowed'=>array_key_exists('cloud_allowed',$scope)?!empty($scope['cloud_allowed']):true,
        'home_provider'=>(string)($compute['selected_provider']??''),
        'home_model'=>(string)($compute['model']??''),
        'home_allowed_models'=>is_array($compute['installed_local_models']??null)?$compute['installed_local_models']:[],
    ];
}
