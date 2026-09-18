<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v5.00 — Phase 11B.1 core.
 *
 * This layer owns deterministic contracts around cognitive modules, object
 * references, context providers, observations, presentation decisions and
 * structured Display Cards. It deliberately does not call an LLM and does not
 * execute model-proposed actions.
 */
const VP3_COGNITIVE_RUNTIME_V500='vp3-cognitive-runtime-v500-20260918';
const VP3_COGNITIVE_CONTRACT_V500='cognitive-runtime-v1';
const VP3_COGNITIVE_CONTEXT_MAX_BYTES_V500=32768;
const VP3_COGNITIVE_CONTEXT_MAX_DEPTH_V500=7;
const VP3_COGNITIVE_CARD_MAX_ACTIONS_V500=8;
const VP3_COGNITIVE_RECENT_LIMIT_V500=50;

function vp3_cognitive_categories_v500(): array
{
    return ['fact_summary','opportunity','risk','commitment','unanswered_question','pattern','anomaly','forecast','recommendation','decision_support','action_plan','memory_candidate'];
}

function vp3_cognitive_surfaces_v500(): array
{
    return ['none','memory','brief','away_digest','notification','voice_announce','ask_user','chat_response'];
}

function vp3_cognitive_truth_types_v500(): array
{
    return ['database_fact','user_confirmed','external_verified','source_document','model_inference','hypothesis','forecast','recommendation'];
}

function vp3_cognitive_scopes_v500(): array
{
    return ['personal','team','public','profile_agent','homeserver','workspace'];
}

function vp3_cognitive_card_modes_v500(): array
{
    return ['compact','standard','expanded'];
}

function vp3_cognitive_uuid_v500(): string
{
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&0x0f)|0x40);
    $b[8]=chr((ord($b[8])&0x3f)|0x80);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function vp3_cognitive_id_v500(mixed $value,int $max=120): string
{
    $v=strtolower(trim((string)$value));
    return preg_match('/^[a-z0-9][a-z0-9._:-]{0,'.max(0,$max-1).'}$/',$v)?$v:'';
}

function vp3_cognitive_text_v500(mixed $value,int $max=1000): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,max(1,$max),'…');
}

function vp3_cognitive_score_v500(mixed $value): float
{
    if(!is_numeric($value))return 0.0;
    return round(max(0.0,min(1.0,(float)$value)),5);
}

function vp3_cognitive_agent_namespace_v500(PDO $pdo,array $user,int $agentId=0): string
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    if($agentId<1)return 'system';
    if(!function_exists('user_agent_get_v236'))throw new RuntimeException('Agent identity is unavailable.');
    $agent=user_agent_get_v236($pdo,$uid,$agentId);
    if(!$agent)throw new RuntimeException('The requested Agent does not belong to this VP3 account.');
    return 'agent:'.$agentId;
}

function vp3_cognitive_agent_id_v500(string $namespace): int
{
    if($namespace==='system')return 0;
    return preg_match('/^agent:(\d+)$/',$namespace,$m)?max(0,(int)$m[1]):0;
}

function vp3_cognitive_validate_namespace_v500(PDO $pdo,array $user,string $namespace): string
{
    $namespace=trim($namespace);
    if($namespace==='system'||$namespace==='')return 'system';
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    if($agentId<1)throw new InvalidArgumentException('Invalid Agent namespace.');
    return vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
}

function &vp3_cognitive_registry_storage_v500(): array
{
    if(!isset($GLOBALS['vp3_cognitive_registry_v500'])||!is_array($GLOBALS['vp3_cognitive_registry_v500'])){
        $GLOBALS['vp3_cognitive_registry_v500']=[
            'modules'=>[],
            'objects'=>[],
            'events'=>[],
            'cards'=>[],
            'tools'=>[],
        ];
    }
    return $GLOBALS['vp3_cognitive_registry_v500'];
}

function vp3_cognitive_register_module_v500(array $definition): void
{
    $id=vp3_cognitive_id_v500($definition['module']??'',80);
    $version=vp3_cognitive_text_v500($definition['version']??'',40);
    if($id===''||$version==='')throw new InvalidArgumentException('Cognitive module identity is incomplete.');

    $objects=array_values(array_unique(array_filter(array_map(static fn($v)=>vp3_cognitive_id_v500($v,80),(array)($definition['objects']??[])))));
    $events=array_values(array_unique(array_filter(array_map(static fn($v)=>vp3_cognitive_id_v500($v,120),(array)($definition['events']??[])))));
    $cards=is_array($definition['cards']??null)?$definition['cards']:[];
    $tools=is_array($definition['tools']??null)?$definition['tools']:[];
    $permission=$definition['permission_resolver']??null;
    $context=$definition['context_provider']??null;
    $relationships=$definition['relationship_provider']??null;

    if(!$objects)throw new InvalidArgumentException('Cognitive modules must register at least one object type.');
    if(!is_callable($permission))throw new InvalidArgumentException('Cognitive modules require a callable permission resolver.');
    if($context!==null&&!is_callable($context))throw new InvalidArgumentException('Cognitive context provider must be callable.');
    if($relationships!==null&&!is_callable($relationships))throw new InvalidArgumentException('Cognitive relationship provider must be callable.');

    $normalizedCards=[];
    foreach($cards as $cardType=>$renderer){
        if(is_int($cardType)&&is_array($renderer)){
            $cardType=(string)($renderer['type']??'');
            $renderer=$renderer['renderer']??null;
        }
        $cardType=vp3_cognitive_id_v500($cardType,80);
        if($cardType===''||!is_callable($renderer))throw new InvalidArgumentException('Cognitive card registration is invalid.');
        $normalizedCards[$cardType]=$renderer;
    }

    $normalizedTools=[];
    foreach($tools as $toolId=>$meta){
        if(is_int($toolId)&&is_string($meta)){$toolId=$meta;$meta=[];}
        $toolId=vp3_cognitive_id_v500($toolId,120);
        if($toolId==='')throw new InvalidArgumentException('Cognitive tool id is invalid.');
        $meta=is_array($meta)?$meta:[];
        $normalizedTools[$toolId]=[
            'id'=>$toolId,
            'label'=>vp3_cognitive_text_v500($meta['label']??$toolId,120),
            'kind'=>in_array((string)($meta['kind']??'read'),['read','prepare','write','external'],true)?(string)$meta['kind']:'read',
            'risk'=>in_array((string)($meta['risk']??'low'),['low','medium','high'],true)?(string)$meta['risk']:'low',
            'requires_approval'=>!empty($meta['requires_approval']),
        ];
    }

    $registry=&vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules'][$id]))throw new RuntimeException('Cognitive module already registered: '.$id);
    foreach($objects as $type)if(isset($registry['objects'][$type]))throw new RuntimeException('Cognitive object type already registered: '.$type);
    foreach($events as $type)if(isset($registry['events'][$type]))throw new RuntimeException('Cognitive event type already registered: '.$type);
    foreach($normalizedCards as $type=>$renderer)if(isset($registry['cards'][$type]))throw new RuntimeException('Cognitive card type already registered: '.$type);
    foreach($normalizedTools as $toolId=>$meta)if(isset($registry['tools'][$toolId]))throw new RuntimeException('Cognitive tool already registered: '.$toolId);

    $module=[
        'module'=>$id,
        'version'=>$version,
        'objects'=>$objects,
        'events'=>$events,
        'permission_resolver'=>$permission,
        'context_provider'=>$context,
        'relationship_provider'=>$relationships,
        'cards'=>$normalizedCards,
        'tools'=>$normalizedTools,
        'freshness_policy'=>is_array($definition['freshness_policy']??null)?$definition['freshness_policy']:[],
        'sensitivity_policy'=>is_array($definition['sensitivity_policy']??null)?$definition['sensitivity_policy']:[],
        'surfaces'=>array_values(array_intersect(vp3_cognitive_surfaces_v500(),(array)($definition['surfaces']??vp3_cognitive_surfaces_v500()))),
        'voice_safe'=>!empty($definition['voice_safe']),
    ];
    $registry['modules'][$id]=$module;
    foreach($objects as $type)$registry['objects'][$type]=$id;
    foreach($events as $type)$registry['events'][$type]=$id;
    foreach($normalizedCards as $type=>$renderer)$registry['cards'][$type]=['module'=>$id,'renderer'=>$renderer];
    foreach($normalizedTools as $toolId=>$meta)$registry['tools'][$toolId]=$meta+['module'=>$id];
}

function vp3_cognitive_registry_public_v500(): array
{
    $registry=vp3_cognitive_registry_storage_v500();
    $modules=[];
    foreach($registry['modules'] as $id=>$module){
        $modules[]=[
            'module'=>$id,
            'version'=>(string)$module['version'],
            'objects'=>array_values($module['objects']),
            'events'=>array_values($module['events']),
            'cards'=>array_keys($module['cards']),
            'tools'=>array_values($module['tools']),
            'surfaces'=>array_values($module['surfaces']),
            'voice_safe'=>!empty($module['voice_safe']),
        ];
    }
    usort($modules,static fn($a,$b)=>strcmp((string)$a['module'],(string)$b['module']));
    return [
        'contract'=>VP3_COGNITIVE_CONTRACT_V500,
        'build'=>VP3_COGNITIVE_RUNTIME_V500,
        'modules'=>$modules,
        'object_types'=>array_values(array_keys($registry['objects'])),
        'event_types'=>array_values(array_keys($registry['events'])),
        'card_types'=>array_values(array_keys($registry['cards'])),
        'tools'=>array_values($registry['tools']),
    ];
}

function vp3_cognitive_object_ref_v500(string $type,mixed $id,string $scope='personal',array $extra=[]): array
{
    $type=vp3_cognitive_id_v500($type,80);
    $id=vp3_cognitive_text_v500($id,190);
    $scope=vp3_cognitive_id_v500($scope,40);
    if($type===''||$id===''||!in_array($scope,vp3_cognitive_scopes_v500(),true))throw new InvalidArgumentException('Invalid cognitive object reference.');
    $ref=['type'=>$type,'id'=>$id,'scope'=>$scope];
    foreach(['workspace_id','version','fresh_at','sensitivity','provenance'] as $key){
        if(!array_key_exists($key,$extra))continue;
        if($key==='workspace_id')$ref[$key]=max(0,(int)$extra[$key]);
        else $ref[$key]=vp3_cognitive_text_v500($extra[$key],$key==='provenance'?120:80);
    }
    return $ref;
}

function vp3_cognitive_validate_object_ref_v500(array $ref,bool $registered=true): array
{
    $normalized=vp3_cognitive_object_ref_v500((string)($ref['type']??''),$ref['id']??'',(string)($ref['scope']??'personal'),$ref);
    if($registered){
        $registry=vp3_cognitive_registry_storage_v500();
        if(!isset($registry['objects'][$normalized['type']]))throw new InvalidArgumentException('Unregistered cognitive object type: '.$normalized['type']);
    }
    return $normalized;
}

function vp3_cognitive_module_for_ref_v500(array $ref): array
{
    $ref=vp3_cognitive_validate_object_ref_v500($ref,true);
    $registry=vp3_cognitive_registry_storage_v500();
    $moduleId=(string)$registry['objects'][$ref['type']];
    $module=$registry['modules'][$moduleId]??null;
    if(!is_array($module))throw new RuntimeException('Cognitive module registry is inconsistent.');
    return [$module,$ref];
}

function vp3_cognitive_authorize_ref_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    [$module,$ref]=vp3_cognitive_module_for_ref_v500($ref);
    $resolver=$module['permission_resolver'];
    try{return (bool)$resolver($pdo,$user,$agentNamespace,$ref,$operation);}catch(Throwable $e){return false;}
}

function vp3_cognitive_forbidden_key_v500(string $key): bool
{
    return (bool)preg_match('/(?:authorization|cookie|password|secret|token|credential|private[_-]?key|api[_-]?key|access[_-]?key|refresh[_-]?key|native[_-]?path|filesystem[_-]?path|absolute[_-]?path)/i',$key);
}

function vp3_cognitive_sanitize_value_v500(mixed $value,int $depth=0): mixed
{
    if($depth>=VP3_COGNITIVE_CONTEXT_MAX_DEPTH_V500)return '[depth-limited]';
    if($value===null||is_bool($value)||is_int($value)||is_float($value))return $value;
    if(is_string($value))return vp3_cognitive_text_v500($value,4000);
    if(!is_array($value))return vp3_cognitive_text_v500((string)$value,500);
    $out=[];$count=0;
    foreach($value as $key=>$item){
        if(++$count>120)break;
        if(is_string($key)&&vp3_cognitive_forbidden_key_v500($key))continue;
        $cleanKey=is_int($key)?$key:vp3_cognitive_text_v500($key,80);
        $out[$cleanKey]=vp3_cognitive_sanitize_value_v500($item,$depth+1);
    }
    return $out;
}

function vp3_cognitive_context_for_ref_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    [$module,$ref]=vp3_cognitive_module_for_ref_v500($ref);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$ref,'read'))throw new RuntimeException('Cognitive object access denied.');
    $provider=$module['context_provider']??null;
    if(!is_callable($provider))return ['object_ref'=>$ref,'module'=>$module['module'],'context'=>[],'fresh_at'=>gmdate('c')];
    $raw=$provider($pdo,$user,$agentNamespace,$ref,$options);
    if(!is_array($raw))throw new RuntimeException('Cognitive context provider returned an invalid payload.');
    $safe=vp3_cognitive_sanitize_value_v500($raw);
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json)||strlen($json)>VP3_COGNITIVE_CONTEXT_MAX_BYTES_V500)throw new RuntimeException('Cognitive context exceeded the bounded context limit.');
    return ['object_ref'=>$ref,'module'=>$module['module'],'context'=>$safe,'fresh_at'=>gmdate('c')];
}

function vp3_cognitive_relationships_for_ref_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    [$module,$ref]=vp3_cognitive_module_for_ref_v500($ref);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$ref,'read'))throw new RuntimeException('Cognitive object access denied.');
    $provider=$module['relationship_provider']??null;
    if(!is_callable($provider))return [];
    $raw=$provider($pdo,$user,$agentNamespace,$ref,$options);
    if(!is_array($raw))return [];
    $out=[];
    foreach(array_slice($raw,0,100) as $edge){
        if(!is_array($edge))continue;
        try{
            $target=vp3_cognitive_validate_object_ref_v500((array)($edge['object_ref']??[]),true);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$target,'read'))continue;
            $relation=vp3_cognitive_id_v500($edge['relation']??'relates_to',60);
            if($relation==='')continue;
            $out[]=[
                'relation'=>$relation,
                'object_ref'=>$target,
                'provenance'=>vp3_cognitive_text_v500($edge['provenance']??$module['module'],120),
                'confidence'=>vp3_cognitive_score_v500($edge['confidence']??1),
                'confirmation_state'=>in_array((string)($edge['confirmation_state']??'deterministic'),['deterministic','user_confirmed','model_inferred'],true)?(string)$edge['confirmation_state']:'deterministic',
            ];
        }catch(Throwable $e){}
    }
    return $out;
}

function vp3_cognitive_validate_card_request_v500(array $card): array
{
    $allowed=['card_type','object_ref','display_mode'];
    foreach(array_keys($card) as $key)if(!in_array((string)$key,$allowed,true))throw new InvalidArgumentException('Unknown cognitive card field: '.$key);
    $type=vp3_cognitive_id_v500($card['card_type']??'',80);
    $mode=(string)($card['display_mode']??'standard');
    if($type===''||!in_array($mode,vp3_cognitive_card_modes_v500(),true))throw new InvalidArgumentException('Invalid cognitive card request.');
    $registry=vp3_cognitive_registry_storage_v500();
    if(!isset($registry['cards'][$type]))throw new InvalidArgumentException('Unregistered cognitive card type: '.$type);
    $ref=vp3_cognitive_validate_object_ref_v500((array)($card['object_ref']??[]),true);
    return ['card_type'=>$type,'object_ref'=>$ref,'display_mode'=>$mode];
}

function vp3_cognitive_card_action_v500(array $action): ?array
{
    $type=(string)($action['type']??'');
    $label=vp3_cognitive_text_v500($action['label']??'',80);
    if($label==='')return null;
    if($type==='open_url'){
        $target=trim((string)($action['url']??''));
        if($target===''||!str_starts_with($target,'/')||str_starts_with($target,'//'))return null;
        return ['type'=>'open_url','label'=>$label,'url'=>mb_strimwidth($target,0,500,'')];
    }
    if($type==='prompt'){
        $prompt=vp3_cognitive_text_v500($action['prompt']??'',800);
        return $prompt!==''?['type'=>'prompt','label'=>$label,'prompt'=>$prompt]:null;
    }
    if($type==='tool'){
        $tool=vp3_cognitive_id_v500($action['tool_id']??'',120);
        $registry=vp3_cognitive_registry_storage_v500();
        if($tool===''||!isset($registry['tools'][$tool]))return null;
        return ['type'=>'tool','label'=>$label,'tool_id'=>$tool,'requires_approval'=>!empty($registry['tools'][$tool]['requires_approval'])];
    }
    return null;
}

function vp3_cognitive_card_normalize_v500(array $card,array $request): array
{
    $out=[
        'contract'=>VP3_COGNITIVE_CONTRACT_V500,
        'card_type'=>$request['card_type'],
        'display_mode'=>$request['display_mode'],
        'object_ref'=>$request['object_ref'],
        'title'=>vp3_cognitive_text_v500($card['title']??'VP3 item',190),
        'subtitle'=>vp3_cognitive_text_v500($card['subtitle']??'',300),
        'status'=>vp3_cognitive_text_v500($card['status']??'',60),
        'summary'=>vp3_cognitive_text_v500($card['summary']??'',1200),
        'timestamp'=>vp3_cognitive_text_v500($card['timestamp']??'',64),
        'metadata'=>is_array($card['metadata']??null)?vp3_cognitive_sanitize_value_v500($card['metadata']):[],
        'actions'=>[],
    ];
    foreach(array_slice((array)($card['actions']??[]),0,VP3_COGNITIVE_CARD_MAX_ACTIONS_V500) as $action){
        if(!is_array($action))continue;
        $normalized=vp3_cognitive_card_action_v500($action);
        if($normalized)$out['actions'][]=$normalized;
    }
    return $out;
}

function vp3_cognitive_render_card_v500(PDO $pdo,array $user,string $agentNamespace,array $request): array
{
    $request=vp3_cognitive_validate_card_request_v500($request);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$request['object_ref'],'read'))throw new RuntimeException('Cognitive card access denied.');
    $registry=vp3_cognitive_registry_storage_v500();
    $renderer=$registry['cards'][$request['card_type']]['renderer']??null;
    if(!is_callable($renderer))throw new RuntimeException('Cognitive card renderer is unavailable.');
    $raw=$renderer($pdo,$user,$agentNamespace,$request['object_ref'],$request['display_mode']);
    if(!is_array($raw))throw new RuntimeException('Cognitive card renderer returned an invalid payload.');
    return vp3_cognitive_card_normalize_v500($raw,$request);
}

function vp3_cognitive_validate_event_envelope_v500(array $event): array
{
    $allowed=['event_id','owner_user_id','agent_namespace','source','event_type','occurred_at','received_at','verification','object_refs','correlation_id','causation_id','schema_version'];
    foreach(array_keys($event) as $key)if(!in_array((string)$key,$allowed,true))throw new InvalidArgumentException('Unknown cognitive event field: '.$key);
    foreach($allowed as $key)if(!array_key_exists($key,$event))throw new InvalidArgumentException('Missing cognitive event field: '.$key);

    $eventId=vp3_cognitive_text_v500($event['event_id']??'',80);
    $source=vp3_cognitive_id_v500($event['source']??'',80);
    $eventType=vp3_cognitive_id_v500($event['event_type']??'',120);
    $verification=(string)($event['verification']??'');
    $namespace=(string)($event['agent_namespace']??'system');
    if($eventId===''||$source===''||$eventType===''||!in_array($verification,['trusted','verified'],true))throw new InvalidArgumentException('Invalid cognitive event envelope.');
    if($namespace!=='system'&&vp3_cognitive_agent_id_v500($namespace)<1)throw new InvalidArgumentException('Invalid cognitive event Agent namespace.');
    $registry=vp3_cognitive_registry_storage_v500();
    if(!isset($registry['events'][$eventType]))throw new InvalidArgumentException('Unregistered cognitive event type: '.$eventType);

    $refs=[];
    foreach(array_slice((array)$event['object_refs'],0,40) as $ref){
        if(!is_array($ref))throw new InvalidArgumentException('Cognitive event object references must be structured.');
        $refs[]=vp3_cognitive_validate_object_ref_v500($ref,true);
    }
    if(!$refs)throw new InvalidArgumentException('Cognitive events require at least one registered object reference.');

    $occurred=vp3_cognitive_text_v500($event['occurred_at']??'',64);
    $received=vp3_cognitive_text_v500($event['received_at']??'',64);
    if($occurred!==''&&strtotime($occurred)===false)throw new InvalidArgumentException('Invalid cognitive event occurred time.');
    if($received===''||strtotime($received)===false)throw new InvalidArgumentException('Invalid cognitive event received time.');

    return [
        'event_id'=>$eventId,
        'owner_user_id'=>max(0,(int)$event['owner_user_id']),
        'agent_namespace'=>$namespace,
        'source'=>$source,
        'event_type'=>$eventType,
        'occurred_at'=>$occurred,
        'received_at'=>$received,
        'verification'=>$verification,
        'object_refs'=>$refs,
        'correlation_id'=>vp3_cognitive_text_v500($event['correlation_id']??'',120),
        'causation_id'=>vp3_cognitive_text_v500($event['causation_id']??'',120),
        'schema_version'=>max(1,(int)$event['schema_version']),
    ];
}

function vp3_cognitive_event_from_agent_event_v500(array $user,string $agentNamespace,array $event,array $objectRefs): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    return vp3_cognitive_validate_event_envelope_v500([
        'event_id'=>(string)($event['event_uuid']??$event['id']??''),
        'owner_user_id'=>$uid,
        'agent_namespace'=>$agentNamespace,
        'source'=>(string)($event['source']??''),
        'event_type'=>(string)($event['event_type']??''),
        'occurred_at'=>(string)($event['occurred_at']??''),
        'received_at'=>(string)($event['received_at']??gmdate('c')),
        'verification'=>(string)($event['verification_status']??'trusted'),
        'object_refs'=>$objectRefs,
        'correlation_id'=>(string)($event['correlation_id']??''),
        'causation_id'=>(string)($event['causation_id']??''),
        'schema_version'=>max(1,(int)($event['schema_version']??1)),
    ]);
}

function vp3_cognitive_context_packet_v500(PDO $pdo,array $user,string $agentNamespace,array $event,array $options=[]): array
{
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    $event=vp3_cognitive_validate_event_envelope_v500($event);
    if((int)$event['owner_user_id']!==(int)($user['id']??0))throw new RuntimeException('Cognitive event principal mismatch.');
    if(!hash_equals((string)$event['agent_namespace'],$agentNamespace))throw new RuntimeException('Cognitive event Agent namespace mismatch.');

    $objects=[];$relationships=[];
    foreach($event['object_refs'] as $ref){
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$ref,'read'))continue;
        $objects[]=vp3_cognitive_context_for_ref_v500($pdo,$user,$agentNamespace,$ref,$options);
        foreach(vp3_cognitive_relationships_for_ref_v500($pdo,$user,$agentNamespace,$ref,$options) as $edge)$relationships[]=$edge;
    }
    if(!$objects)throw new RuntimeException('No authorized cognitive objects remain for this event.');

    $registry=vp3_cognitive_registry_public_v500();
    $packet=[
        'contract'=>VP3_COGNITIVE_CONTRACT_V500,
        'build'=>VP3_COGNITIVE_RUNTIME_V500,
        'principal'=>['user_id'=>(int)$user['id'],'agent_namespace'=>$agentNamespace],
        'trigger'=>$event,
        'objects'=>$objects,
        'relationships'=>array_slice($relationships,0,120),
        'available_tools'=>$registry['tools'],
        'available_card_types'=>$registry['card_types'],
        'presentation_context'=>vp3_cognitive_presentation_context_v500($pdo,$user,(array)($options['presentation_context']??[])),
        'generated_at'=>gmdate('c'),
    ];
    $safe=vp3_cognitive_sanitize_value_v500($packet);
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json)||strlen($json)>VP3_COGNITIVE_CONTEXT_MAX_BYTES_V500*4)throw new RuntimeException('Cognitive context packet exceeded the bounded packet limit.');
    return $safe;
}

function vp3_cognitive_validate_evidence_v500(array $item): array
{
    $allowed=['truth_type','object_ref','statement','occurred_at'];
    foreach(array_keys($item) as $key)if(!in_array((string)$key,$allowed,true))throw new InvalidArgumentException('Unknown cognitive evidence field: '.$key);
    $truth=(string)($item['truth_type']??'');
    if(!in_array($truth,vp3_cognitive_truth_types_v500(),true))throw new InvalidArgumentException('Invalid cognitive evidence truth type.');
    return [
        'truth_type'=>$truth,
        'object_ref'=>vp3_cognitive_validate_object_ref_v500((array)($item['object_ref']??[]),true),
        'statement'=>vp3_cognitive_text_v500($item['statement']??'',500),
        'occurred_at'=>vp3_cognitive_text_v500($item['occurred_at']??'',64),
    ];
}

function vp3_cognitive_validate_observation_v500(array $input): array
{
    $allowed=['observation_id','category','title','reason','evidence_refs','confidence','novelty','urgency','impact','goal_relevance','valid_until','proposed_action_ids','proposed_cards','presentation_recommendation','voice_safe_summary','source','source_event_uuid'];
    foreach(array_keys($input) as $key)if(!in_array((string)$key,$allowed,true))throw new InvalidArgumentException('Unknown cognitive observation field: '.$key);
    foreach(['observation_id','category','title','reason','evidence_refs','confidence','novelty','urgency','impact','goal_relevance'] as $key){
        if(!array_key_exists($key,$input))throw new InvalidArgumentException('Missing cognitive observation field: '.$key);
    }
    $observationId=vp3_cognitive_id_v500($input['observation_id']??'',120);
    $category=(string)($input['category']??'');
    $title=vp3_cognitive_text_v500($input['title']??'',190);
    $reason=vp3_cognitive_text_v500($input['reason']??'',1200);
    if($observationId===''||!in_array($category,vp3_cognitive_categories_v500(),true)||$title===''||$reason==='')throw new InvalidArgumentException('Cognitive observation identity is invalid.');

    $evidence=[];
    foreach(array_slice((array)$input['evidence_refs'],0,30) as $item){
        if(!is_array($item))throw new InvalidArgumentException('Cognitive evidence must be structured.');
        $evidence[]=vp3_cognitive_validate_evidence_v500($item);
    }
    if(!$evidence)throw new InvalidArgumentException('Cognitive observations require evidence.');

    $registry=vp3_cognitive_registry_storage_v500();
    $actions=[];
    foreach(array_slice((array)($input['proposed_action_ids']??[]),0,20) as $tool){
        $tool=vp3_cognitive_id_v500($tool,120);
        if($tool===''||!isset($registry['tools'][$tool]))throw new InvalidArgumentException('Unregistered cognitive action: '.$tool);
        if(!in_array($tool,$actions,true))$actions[]=$tool;
    }
    $cards=[];
    foreach(array_slice((array)($input['proposed_cards']??[]),0,20) as $card){
        if(!is_array($card))throw new InvalidArgumentException('Cognitive card proposal must be structured.');
        $cards[]=vp3_cognitive_validate_card_request_v500($card);
    }
    $recommendation=(string)($input['presentation_recommendation']??'memory');
    if(!in_array($recommendation,vp3_cognitive_surfaces_v500(),true))throw new InvalidArgumentException('Invalid cognitive presentation recommendation.');

    $validUntil=trim((string)($input['valid_until']??''));
    if($validUntil!==''&&strtotime($validUntil)===false)throw new InvalidArgumentException('Invalid cognitive observation expiration.');

    return [
        'observation_id'=>$observationId,
        'category'=>$category,
        'title'=>$title,
        'reason'=>$reason,
        'evidence_refs'=>$evidence,
        'confidence'=>vp3_cognitive_score_v500($input['confidence']),
        'novelty'=>vp3_cognitive_score_v500($input['novelty']),
        'urgency'=>vp3_cognitive_score_v500($input['urgency']),
        'impact'=>vp3_cognitive_score_v500($input['impact']),
        'goal_relevance'=>vp3_cognitive_score_v500($input['goal_relevance']),
        'valid_until'=>$validUntil,
        'proposed_action_ids'=>$actions,
        'proposed_cards'=>$cards,
        'presentation_recommendation'=>$recommendation,
        'voice_safe_summary'=>vp3_cognitive_text_v500($input['voice_safe_summary']??'',500),
        'source'=>vp3_cognitive_id_v500($input['source']??'model',80)?:'model',
        'source_event_uuid'=>vp3_cognitive_text_v500($input['source_event_uuid']??'',64),
    ];
}

function vp3_cognitive_schema_ready_v500(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_observations_v500')
        && table_exists('cognitive_presentations_v500')
        && table_exists('cognitive_relationships_v500');
}

function vp3_cognitive_ensure_schema_v500(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_schema_ready_v500($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Runtime.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_observations_v500 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      observation_key VARCHAR(120) NOT NULL,
      category VARCHAR(40) NOT NULL,
      title VARCHAR(190) NOT NULL,
      reason VARCHAR(1200) NOT NULL,
      evidence_json MEDIUMTEXT NOT NULL,
      proposed_actions_json TEXT NOT NULL,
      proposed_cards_json MEDIUMTEXT NOT NULL,
      confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
      novelty DECIMAL(6,5) NOT NULL DEFAULT 0,
      urgency DECIMAL(6,5) NOT NULL DEFAULT 0,
      impact DECIMAL(6,5) NOT NULL DEFAULT 0,
      goal_relevance DECIMAL(6,5) NOT NULL DEFAULT 0,
      recommended_surface VARCHAR(32) NOT NULL DEFAULT 'memory',
      final_surface VARCHAR(32) NOT NULL DEFAULT 'none',
      voice_safe_summary VARCHAR(500) NOT NULL DEFAULT '',
      source VARCHAR(80) NOT NULL DEFAULT 'model',
      source_event_uuid VARCHAR(64) NOT NULL DEFAULT '',
      state VARCHAR(24) NOT NULL DEFAULT 'active',
      valid_until DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_observation_public_v500 (public_id),
      UNIQUE KEY uq_cognitive_observation_key_v500 (owner_user_id,agent_namespace,observation_key),
      INDEX idx_cognitive_observation_owner_v500 (owner_user_id,agent_namespace,state,updated_at,id),
      INDEX idx_cognitive_observation_surface_v500 (owner_user_id,final_surface,state,updated_at,id),
      CONSTRAINT fk_cognitive_observation_owner_v500 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_presentations_v500 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      observation_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      surface VARCHAR(32) NOT NULL,
      paired_surface VARCHAR(32) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'planned',
      reason_code VARCHAR(80) NOT NULL DEFAULT '',
      cards_json MEDIUMTEXT NOT NULL,
      voice_summary VARCHAR(500) NOT NULL DEFAULT '',
      presented_at DATETIME NULL,
      acknowledged_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_cognitive_presentation_owner_v500 (owner_user_id,agent_namespace,status,created_at,id),
      INDEX idx_cognitive_presentation_observation_v500 (observation_id,created_at,id),
      CONSTRAINT fk_cognitive_presentation_observation_v500 FOREIGN KEY (observation_id) REFERENCES cognitive_observations_v500(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_presentation_owner_v500 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_relationships_v500 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      left_type VARCHAR(80) NOT NULL,
      left_id VARCHAR(190) NOT NULL,
      left_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      left_workspace_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      relation_type VARCHAR(60) NOT NULL,
      right_type VARCHAR(80) NOT NULL,
      right_id VARCHAR(190) NOT NULL,
      right_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      right_workspace_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      provenance VARCHAR(120) NOT NULL,
      confidence DECIMAL(6,5) NOT NULL DEFAULT 1,
      confirmation_state VARCHAR(24) NOT NULL DEFAULT 'deterministic',
      valid_until DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_relationship_v500 (owner_user_id,left_type,left_id,left_scope,left_workspace_id,relation_type,right_type,right_id,right_scope,right_workspace_id),
      INDEX idx_cognitive_relationship_left_v500 (owner_user_id,left_type,left_id,left_scope,left_workspace_id,updated_at,id),
      INDEX idx_cognitive_relationship_right_v500 (owner_user_id,right_type,right_id,right_scope,right_workspace_id,updated_at,id),
      CONSTRAINT fk_cognitive_relationship_owner_v500 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_json_v500(mixed $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json)?$json:'[]';
}

function vp3_cognitive_observation_store_v500(PDO $pdo,array $user,string $agentNamespace,array $input): array
{
    if(!vp3_cognitive_schema_ready_v500($pdo))throw new RuntimeException('Cognitive Runtime schema is not ready.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    $obs=vp3_cognitive_validate_observation_v500($input);

    foreach($obs['evidence_refs'] as $evidence){
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$evidence['object_ref'],'read'))throw new RuntimeException('Observation evidence is not authorized.');
    }
    foreach($obs['proposed_cards'] as $card){
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$card['object_ref'],'read'))throw new RuntimeException('Observation card is not authorized.');
    }

    $publicId=vp3_cognitive_uuid_v500();
    $validUntil=$obs['valid_until']!==''?gmdate('Y-m-d H:i:s',strtotime($obs['valid_until'])):null;
    $stmt=$pdo->prepare("INSERT INTO cognitive_observations_v500
      (public_id,owner_user_id,agent_namespace,observation_key,category,title,reason,evidence_json,proposed_actions_json,proposed_cards_json,confidence,novelty,urgency,impact,goal_relevance,recommended_surface,voice_safe_summary,source,source_event_uuid,valid_until)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE category=VALUES(category),title=VALUES(title),reason=VALUES(reason),evidence_json=VALUES(evidence_json),proposed_actions_json=VALUES(proposed_actions_json),proposed_cards_json=VALUES(proposed_cards_json),confidence=VALUES(confidence),novelty=VALUES(novelty),urgency=VALUES(urgency),impact=VALUES(impact),goal_relevance=VALUES(goal_relevance),recommended_surface=VALUES(recommended_surface),voice_safe_summary=VALUES(voice_safe_summary),source=VALUES(source),source_event_uuid=VALUES(source_event_uuid),valid_until=VALUES(valid_until),state='active',updated_at=UTC_TIMESTAMP()");
    $stmt->execute([
        $publicId,$uid,$agentNamespace,$obs['observation_id'],$obs['category'],$obs['title'],$obs['reason'],
        vp3_cognitive_json_v500($obs['evidence_refs']),vp3_cognitive_json_v500($obs['proposed_action_ids']),vp3_cognitive_json_v500($obs['proposed_cards']),
        $obs['confidence'],$obs['novelty'],$obs['urgency'],$obs['impact'],$obs['goal_relevance'],$obs['presentation_recommendation'],
        $obs['voice_safe_summary'],$obs['source'],$obs['source_event_uuid'],$validUntil,
    ]);
    $select=$pdo->prepare('SELECT * FROM cognitive_observations_v500 WHERE owner_user_id=? AND agent_namespace=? AND observation_key=? LIMIT 1');
    $select->execute([$uid,$agentNamespace,$obs['observation_id']]);
    $row=$select->fetch();
    if(!is_array($row))throw new RuntimeException('Cognitive observation could not be reloaded.');
    return vp3_cognitive_observation_public_v500($row);
}

function vp3_cognitive_decode_array_v500(mixed $json): array
{
    $value=json_decode((string)$json,true);
    return is_array($value)?$value:[];
}

function vp3_cognitive_observation_public_v500(array $row): array
{
    return [
        'id'=>(string)($row['public_id']??''),
        'observation_id'=>(string)($row['observation_key']??''),
        'category'=>(string)($row['category']??''),
        'title'=>(string)($row['title']??''),
        'reason'=>(string)($row['reason']??''),
        'evidence_refs'=>vp3_cognitive_decode_array_v500($row['evidence_json']??'[]'),
        'proposed_action_ids'=>vp3_cognitive_decode_array_v500($row['proposed_actions_json']??'[]'),
        'proposed_cards'=>vp3_cognitive_decode_array_v500($row['proposed_cards_json']??'[]'),
        'confidence'=>(float)($row['confidence']??0),
        'novelty'=>(float)($row['novelty']??0),
        'urgency'=>(float)($row['urgency']??0),
        'impact'=>(float)($row['impact']??0),
        'goal_relevance'=>(float)($row['goal_relevance']??0),
        'presentation_recommendation'=>(string)($row['recommended_surface']??'memory'),
        'final_surface'=>(string)($row['final_surface']??'none'),
        'voice_safe_summary'=>(string)($row['voice_safe_summary']??''),
        'source'=>(string)($row['source']??''),
        'source_event_uuid'=>(string)($row['source_event_uuid']??''),
        'state'=>(string)($row['state']??'active'),
        'valid_until'=>(string)($row['valid_until']??''),
        'created_at'=>(string)($row['created_at']??''),
        'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function vp3_cognitive_recent_observations_v500(PDO $pdo,array $user,string $agentNamespace,int $limit=VP3_COGNITIVE_RECENT_LIMIT_V500): array
{
    if(!vp3_cognitive_schema_ready_v500($pdo))return [];
    $uid=(int)($user['id']??0);
    if($uid<1)return [];
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT * FROM cognitive_observations_v500 WHERE owner_user_id=? AND agent_namespace=? AND state='active' AND (valid_until IS NULL OR valid_until>=UTC_TIMESTAMP()) ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$uid,$agentNamespace]);
    return array_map('vp3_cognitive_observation_public_v500',$stmt->fetchAll()?:[]);
}

function vp3_cognitive_presentation_context_v500(PDO $pdo,array $user,array $overrides=[]): array
{
    $uid=(int)($user['id']??0);
    $voice=true;
    if($uid>0&&function_exists('chat_settings_get_v237')){
        try{$settings=chat_settings_get_v237($pdo,$uid);$voice=$settings['agent_voice_enabled']??true;}catch(Throwable $e){}
    }
    $defaults=[
        'direct_user_request'=>false,
        'requires_user_response'=>false,
        'agent_voice_enabled'=>(bool)$voice,
        'interruptible'=>true,
        'quiet_hours'=>false,
        'focus_mode'=>false,
        'sensitive_for_voice'=>false,
        'idle_minutes'=>0,
        'already_presented'=>false,
        'attention_budget_remaining'=>true,
        'voice_candidate_allowed'=>false,
    ];
    return array_replace($defaults,$overrides);
}

function vp3_cognitive_presentation_decide_v500(array $observation,array $context=[]): array
{
    $obs=[
        'category'=>(string)($observation['category']??'fact_summary'),
        'confidence'=>vp3_cognitive_score_v500($observation['confidence']??0),
        'novelty'=>vp3_cognitive_score_v500($observation['novelty']??0),
        'urgency'=>vp3_cognitive_score_v500($observation['urgency']??0),
        'impact'=>vp3_cognitive_score_v500($observation['impact']??0),
        'goal_relevance'=>vp3_cognitive_score_v500($observation['goal_relevance']??0),
        'presentation_recommendation'=>(string)($observation['presentation_recommendation']??'memory'),
        'voice_safe_summary'=>vp3_cognitive_text_v500($observation['voice_safe_summary']??'',500),
        'valid_until'=>(string)($observation['valid_until']??''),
    ];
    $ctx=array_replace([
        'direct_user_request'=>false,'requires_user_response'=>false,'agent_voice_enabled'=>true,'interruptible'=>true,
        'quiet_hours'=>false,'focus_mode'=>false,'sensitive_for_voice'=>false,'idle_minutes'=>0,'already_presented'=>false,'attention_budget_remaining'=>true,'voice_candidate_allowed'=>false,
    ],$context);

    if($obs['valid_until']!==''&&strtotime($obs['valid_until'])!==false&&strtotime($obs['valid_until'])<time())return ['surface'=>'none','paired_surface'=>'','voice'=>false,'reason_code'=>'expired'];
    if(!empty($ctx['direct_user_request']))return ['surface'=>'chat_response','paired_surface'=>'','voice'=>false,'reason_code'=>'direct_user_request'];
    if(!empty($ctx['requires_user_response']))return ['surface'=>'ask_user','paired_surface'=>'notification','voice'=>false,'reason_code'=>'user_response_required'];
    if(!empty($ctx['already_presented'])&&$obs['urgency']<0.95)return ['surface'=>'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'already_presented'];

    $meaningful=max($obs['impact'],$obs['goal_relevance'],($obs['urgency']*0.9),($obs['novelty']*0.75));
    $voiceEligible=!empty($ctx['voice_candidate_allowed'])&&!empty($ctx['agent_voice_enabled'])&&!empty($ctx['interruptible'])&&empty($ctx['quiet_hours'])&&empty($ctx['focus_mode'])&&empty($ctx['sensitive_for_voice'])&&$obs['voice_safe_summary']!==''&&!empty($ctx['attention_budget_remaining']);

    if($obs['presentation_recommendation']==='voice_announce'&&$voiceEligible&&$obs['confidence']>=0.55){
        return ['surface'=>'voice_announce','paired_surface'=>'notification','voice'=>true,'reason_code'=>'module_voice_recommendation'];
    }
    if($obs['urgency']>=0.92&&$obs['impact']>=0.60){
        if($voiceEligible)return ['surface'=>'voice_announce','paired_surface'=>'notification','voice'=>true,'reason_code'=>'urgent_voice'];
        return ['surface'=>'notification','paired_surface'=>'','voice'=>false,'reason_code'=>'urgent_attention'];
    }

    $idle=max(0,(int)$ctx['idle_minutes']);
    if($idle>=60&&$meaningful>=0.55&&$obs['confidence']>=0.45)return ['surface'=>'away_digest','paired_surface'=>'','voice'=>false,'reason_code'=>'return_digest'];
    if($idle>=30&&$obs['urgency']>=0.75&&$meaningful>=0.65&&$obs['confidence']>=0.50)return ['surface'=>'away_digest','paired_surface'=>'','voice'=>false,'reason_code'=>'attention_return_digest'];

    if($obs['presentation_recommendation']==='notification'&&$meaningful>=0.60&&$obs['confidence']>=0.50&&!empty($ctx['attention_budget_remaining'])){
        return ['surface'=>'notification','paired_surface'=>'','voice'=>false,'reason_code'=>'module_notification_recommendation'];
    }
    if(in_array($obs['category'],['opportunity','recommendation','action_plan','risk','commitment','unanswered_question'],true)&&$meaningful>=0.50&&$obs['confidence']>=0.45){
        return ['surface'=>'brief','paired_surface'=>'','voice'=>false,'reason_code'=>'actionable_brief'];
    }
    if($meaningful>=0.72&&$obs['confidence']>=0.50)return ['surface'=>'brief','paired_surface'=>'','voice'=>false,'reason_code'=>'high_value_brief'];
    if($meaningful>=0.35||$obs['confidence']>=0.60)return ['surface'=>'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'retain_without_interrupt'];
    return ['surface'=>'none','paired_surface'=>'','voice'=>false,'reason_code'=>'below_noise_floor'];
}

function vp3_cognitive_presentation_record_v500(PDO $pdo,array $user,string $agentNamespace,array $observation,array $decision): array
{
    if(!vp3_cognitive_schema_ready_v500($pdo))throw new RuntimeException('Cognitive Runtime schema is not ready.');
    $uid=(int)($user['id']??0);
    $publicId=(string)($observation['id']??'');
    $stmt=$pdo->prepare('SELECT id,owner_user_id,agent_namespace FROM cognitive_observations_v500 WHERE public_id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$publicId,$uid]);$row=$stmt->fetch();
    if(!is_array($row))throw new RuntimeException('Cognitive observation was not found.');
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    if(!hash_equals((string)$row['agent_namespace'],$agentNamespace))throw new RuntimeException('Cognitive observation Agent namespace mismatch.');

    $surface=(string)($decision['surface']??'none');
    $paired=(string)($decision['paired_surface']??'');
    if(!in_array($surface,vp3_cognitive_surfaces_v500(),true))throw new InvalidArgumentException('Invalid cognitive presentation surface.');
    if($paired!==''&&!in_array($paired,vp3_cognitive_surfaces_v500(),true))throw new InvalidArgumentException('Invalid cognitive paired presentation surface.');

    $cards=[];
    foreach((array)($observation['proposed_cards']??[]) as $card){
        if(is_array($card))$cards[]=vp3_cognitive_validate_card_request_v500($card);
    }
    $voice=!empty($decision['voice'])?vp3_cognitive_text_v500($observation['voice_safe_summary']??'',500):'';
    $status=in_array($surface,['none','memory'],true)?'recorded':'planned';
    $pdo->prepare('INSERT INTO cognitive_presentations_v500 (observation_id,owner_user_id,agent_namespace,surface,paired_surface,status,reason_code,cards_json,voice_summary) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$row['id'],$uid,$agentNamespace,$surface,$paired,$status,vp3_cognitive_text_v500($decision['reason_code']??'',80),vp3_cognitive_json_v500($cards),$voice]);
    $pdo->prepare('UPDATE cognitive_observations_v500 SET final_surface=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?')
        ->execute([$surface,(int)$row['id'],$uid]);

    return [
        'presentation_id'=>(int)$pdo->lastInsertId(),
        'surface'=>$surface,
        'paired_surface'=>$paired,
        'voice'=>!empty($decision['voice']),
        'reason_code'=>(string)($decision['reason_code']??''),
        'cards'=>$cards,
        'voice_summary'=>$voice,
        'status'=>$status,
    ];
}

function vp3_cognitive_relationship_upsert_v500(PDO $pdo,array $user,string $agentNamespace,array $left,string $relation,array $right,array $meta=[]): void
{
    if(!vp3_cognitive_schema_ready_v500($pdo))throw new RuntimeException('Cognitive Runtime schema is not ready.');
    $uid=(int)($user['id']??0);
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    $left=vp3_cognitive_validate_object_ref_v500($left,true);
    $right=vp3_cognitive_validate_object_ref_v500($right,true);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$left,'read')||!vp3_cognitive_authorize_ref_v500($pdo,$user,$agentNamespace,$right,'read'))throw new RuntimeException('Cognitive relationship access denied.');
    $relation=vp3_cognitive_id_v500($relation,60);
    if($relation==='')throw new InvalidArgumentException('Invalid cognitive relationship type.');
    $provenance=vp3_cognitive_text_v500($meta['provenance']??'runtime',120);
    $confidence=vp3_cognitive_score_v500($meta['confidence']??1);
    $confirmation=in_array((string)($meta['confirmation_state']??'deterministic'),['deterministic','user_confirmed','model_inferred'],true)?(string)$meta['confirmation_state']:'deterministic';
    $validUntil=trim((string)($meta['valid_until']??''));$validUntil=$validUntil!==''&&strtotime($validUntil)!==false?gmdate('Y-m-d H:i:s',strtotime($validUntil)):null;
    $pdo->prepare("INSERT INTO cognitive_relationships_v500 (owner_user_id,left_type,left_id,left_scope,left_workspace_id,relation_type,right_type,right_id,right_scope,right_workspace_id,provenance,confidence,confirmation_state,valid_until)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE provenance=VALUES(provenance),confidence=VALUES(confidence),confirmation_state=VALUES(confirmation_state),valid_until=VALUES(valid_until),updated_at=UTC_TIMESTAMP()")
      ->execute([
          $uid,$left['type'],$left['id'],$left['scope'],(int)($left['workspace_id']??0),$relation,
          $right['type'],$right['id'],$right['scope'],(int)($right['workspace_id']??0),
          $provenance,$confidence,$confirmation,$validUntil
      ]);
}

function vp3_cognitive_state_v500(PDO $pdo,array $user,string $agentNamespace='system'): array
{
    $agentNamespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$agentNamespace);
    return [
        'build'=>VP3_COGNITIVE_RUNTIME_V500,
        'contract'=>VP3_COGNITIVE_CONTRACT_V500,
        'schema_ready'=>vp3_cognitive_schema_ready_v500($pdo),
        'agent_namespace'=>$agentNamespace,
        'registry'=>vp3_cognitive_registry_public_v500(),
        'presentation_context'=>vp3_cognitive_presentation_context_v500($pdo,$user),
        'recent_observations'=>vp3_cognitive_schema_ready_v500($pdo)?vp3_cognitive_recent_observations_v500($pdo,$user,$agentNamespace,20):[],
    ];
}
