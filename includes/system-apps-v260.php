<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v250.php';

const VP3_SYSTEM_APPS_V260='unified-app-manager-v260-20260930';

function vp3_app_manager_status_v260(int $userId,?callable $remote=null,bool $strict=false): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.manager.status',[],$remote);
    if($strict){
        vp3_app_read_items_v1($payload['items']??null);
        if(!is_array($payload['counts']??null))throw new RuntimeException('App manager counts unavailable.');
        vp3_app_read_counts_v1($payload['counts'],['installed','available','updates','running','hosted']);
        foreach($payload['items'] as $row)foreach(['installed','available','update_available','hosted','shared'] as $key)if(!is_bool($row[$key]??null))throw new RuntimeException('App state could not be verified.');
    }
    $items=is_array($payload['items']??null)?array_values($payload['items']):[];
    return [
      'contract'=>'vp3.unified-app-manager.v1',
      'counts'=>is_array($payload['counts']??null)?$payload['counts']:[],
      'catalog_version'=>(string)($payload['catalog_version']??''),
      'items'=>array_map(static function($row){
          $row=is_array($row)?$row:[];
          return [
            'app_key'=>(string)($row['app_key']??''),
            'name'=>(string)($row['name']??''),
            'app_class'=>(string)($row['app_class']??''),
            'installed'=>!empty($row['installed']),
            'available'=>!empty($row['available']),
            'lifecycle_state'=>(string)($row['lifecycle_state']??''),
            'installed_version'=>$row['installed_version']??null,
            'available_version'=>$row['available_version']??null,
            'update_available'=>!empty($row['update_available']),
            'category'=>(string)($row['category']??''),
            'shared'=>!empty($row['shared']),
            'hosted'=>!empty($row['hosted']),
            'public'=>!empty($row['public']),
            'permission_counts'=>is_array($row['permission_counts']??null)?$row['permission_counts']:['declared'=>0,'allowed'=>0],
            'storage_used_bytes'=>(int)($row['storage_used_bytes']??0),
          ];
      },$items),
      'count'=>count($items),
      'authority'=>[
        'registry'=>'homeserver',
        'runtime'=>'homeserver',
        'permissions'=>'homeserver',
        'storage'=>'homeserver',
        'releases'=>'homeserver',
        'hosting'=>'homeserver',
        'cloud_registry'=>false,
      ],
    ];
}

function vp3_app_manager_query_v260(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:app manager|my apps|installed apps|available apps|vp3 apps|system apps|app updates|hosted apps|shared apps|what apps)\b/i',$query))return $empty;
    $view='all';foreach(['installed','running','available','hosted','shared','updates'] as $candidate)if(str_contains(mb_strtolower($query),$candidate)){$view=$candidate;break;}
    return vp3_app_agent_legacy_v1($user,$conversationId,'apps.manager.read','',$remote,$view);
}

function vp3_system_apps_capability_v260(): array
{
    return array_replace(vp3_system_apps_capability_v250(),[
      'unified_app_manager_contract'=>'vp3.unified-app-manager.v1',
      'app_manager_agent_reads'=>true,
      'app_manager_cloud_registry'=>false,
      'vp3_optional_app_library'=>true,
      'core_homeserver_features_are_apps'=>false,
      'app_store'=>false,
    ]);
}
