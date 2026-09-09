<?php
declare(strict_types=1);

const VP3_HOMESERVER_POLICY_V035 = 'homeserver-action-policy-v035-20260909';

function homeserver_policy_v035_modes(): array
{
    return ['read_only','safe_automatic','approval_required','sensitive_high_impact'];
}

function homeserver_policy_v035_empty(string $reason='unavailable'): array
{
    return [
        'version'=>'v0.35',
        'build'=>VP3_HOMESERVER_POLICY_V035,
        'available'=>false,
        'reason'=>mb_strimwidth(trim($reason),0,80,''),
        'owner_managed'=>true,
        'app'=>'vp3',
        'counts'=>[
            'total'=>0,
            'read_only'=>0,
            'safe_automatic'=>0,
            'approval_required'=>0,
            'sensitive_high_impact'=>0,
            'inherited'=>0,
        ],
        'tools'=>[],
    ];
}

function homeserver_policy_v035_tool(mixed $raw): ?array
{
    if(!is_array($raw))return null;
    $key=mb_strimwidth(trim((string)($raw['key']??'')),0,80,'');
    if($key==='')return null;
    $policy=is_array($raw['execution_policy']??null)?$raw['execution_policy']:[];
    $mode=trim((string)($policy['policy_mode']??''));
    if(!in_array($mode,homeserver_policy_v035_modes(),true))$mode='';
    return [
        'key'=>$key,
        'name'=>mb_strimwidth(trim((string)($raw['name']??$key)),0,160,''),
        'tool_mode'=>in_array((string)($raw['mode']??''),['read','write'],true)?(string)$raw['mode']:'',
        'enabled'=>!empty($raw['enabled']),
        'available'=>!empty($raw['available']),
        'policy_mode'=>$mode,
        'inherited'=>!empty($policy['inherited']),
        'allowed_modes'=>array_values(array_filter(
            array_unique(array_map(static fn($value): string=>trim((string)$value),is_array($policy['allowed_modes']??null)?$policy['allowed_modes']:[])),
            static fn(string $value): bool=>in_array($value,homeserver_policy_v035_modes(),true)
        )),
        'updated_at'=>mb_strimwidth(trim((string)($policy['updated_at']??'')),0,64,''),
    ];
}

function homeserver_policy_v035_normalize(array $raw): array
{
    $result=homeserver_policy_v035_empty('ready');
    $result['available']=true;
    $result['reason']='ready';
    $result['app']=mb_strimwidth(trim((string)($raw['app']??'vp3')),0,80,'');
    $items=is_array($raw['items']??null)?$raw['items']:[];
    foreach($items as $item){
        $tool=homeserver_policy_v035_tool($item);
        if(!$tool)continue;
        $result['tools'][]=$tool;
        $result['counts']['total']++;
        if($tool['policy_mode']!==''&&array_key_exists($tool['policy_mode'],$result['counts']))$result['counts'][$tool['policy_mode']]++;
        if($tool['inherited'])$result['counts']['inherited']++;
        if(count($result['tools'])>=100)break;
    }
    usort($result['tools'],static fn(array $a,array $b): int=>strcasecmp((string)$a['name'],(string)$b['name']));
    return $result;
}

function homeserver_policy_v035_snapshot(int $userId,bool $forceRefresh=false): array
{
    if($userId<1||!function_exists('homeserver_vp3_status')||!function_exists('homeserver_agent_v018_credentials')||!function_exists('homeserver_vp3_remote_operation')){
        return homeserver_policy_v035_empty('unavailable');
    }
    try{
        $status=homeserver_vp3_status($userId,$forceRefresh);
    }catch(Throwable $e){
        return homeserver_policy_v035_empty('status_unavailable');
    }
    if(empty($status['paired']))return homeserver_policy_v035_empty('unpaired');
    if(empty($status['connected']))return homeserver_policy_v035_empty('offline');
    $features=is_array($status['capabilities']??null)?$status['capabilities']:[];
    if(!in_array('action.policy.v1',$features,true))return homeserver_policy_v035_empty('unsupported');
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials)return homeserver_policy_v035_empty('credentials_unavailable');
    try{
        $raw=homeserver_vp3_remote_operation((string)$credentials['relay'],'tools.list',[],(string)$credentials['home']);
        if(!is_array($raw)||!is_array($raw['items']??null))return homeserver_policy_v035_empty('invalid_response');
        return homeserver_policy_v035_normalize($raw);
    }catch(Throwable $e){
        return homeserver_policy_v035_empty('remote_unavailable');
    }
}
