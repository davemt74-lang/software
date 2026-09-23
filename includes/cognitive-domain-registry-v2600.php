<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v26.00 — Cognitive Domain Registry foundation.
 *
 * This is a validation/orchestration layer over canonical authorities.
 * v5.00 owns runtime module registration, v23.70 owns the domain inventory,
 * v19.20 owns event ingress, v24.10 owns attention policy, and v25.90 owns
 * bounded current-state materialization plus user-facing presentation.
 *
 * No tables, queues, workers, approvals, business records or event ledgers are
 * created here.
 */
const VP3_COGNITIVE_DOMAIN_REGISTRY_V2600='vp3-cognitive-domain-registry-v2600-20260923';
const VP3_COGNITIVE_DOMAIN_CONTRACT_V2600='vp3-cognitive-domain-registry-v1';
const VP3_COGNITIVE_DOMAIN_MAX_REFS_V2600=16;
const VP3_COGNITIVE_DOMAIN_MAX_PAYLOAD_BYTES_V2600=16384;

function vp3_cognitive_domain_id_v2600(mixed $value,int $limit=80): string
{
    $value=strtolower(trim((string)$value));
    $value=preg_replace('/[^a-z0-9._:-]+/','_',$value)??'';
    return mb_strimwidth(trim($value,'_'),0,max(1,$limit),'');
}

function vp3_cognitive_domain_event_classes_v2600(): array
{
    return ['informational','actionable','approval_required','failure_recovery','completion','outcome'];
}

function vp3_cognitive_domain_key_v2600(string $manifestKey): string
{
    return match($manifestKey){
        'browser'=>'browser_operations',
        'knowledge_research'=>'research_knowledge',
        'workflows_tools_approvals'=>'workflow_tools_approvals',
        'homeserver'=>'homeserver_operations',
        default=>$manifestKey,
    };
}

function vp3_cognitive_domain_current_state_group_v2600(string $domain): string
{
    return match($domain){
        'agent_chat'=>'session',
        default=>$domain,
    };
}

function vp3_cognitive_domain_source_aliases_v2600(string $domain,string $manifestKey=''): array
{
    $aliases=[$domain];
    if($manifestKey!==''&&$manifestKey!==$domain)$aliases[]=$manifestKey;
    $extra=match($domain){
        'browser_operations'=>['browser','browser_companion'],
        'research_knowledge'=>['knowledge_research','research'],
        'workflow_tools_approvals'=>['workflows_tools_approvals','workflow','tools'],
        'homeserver_operations'=>['homeserver'],
        'crm_relationships'=>['crm'],
        'booking_appointments'=>['booking','appointments'],
        'messaging_team'=>['messaging','team'],
        default=>[],
    };
    return array_values(array_unique(array_filter(array_map(
        static fn($v)=>vp3_cognitive_domain_id_v2600($v,80),
        array_merge($aliases,$extra)
    ))));
}

function vp3_cognitive_campaigns_rewards_contract_v2600(): array
{
    return [
        'id'=>'campaigns_rewards',
        'label'=>'Campaigns & Rewards',
        'phase'=>'v26.00-reference-contract',
        'implementation_status'=>'contract_ready',
        'plugin_key'=>'campaigns_rewards',
        'plugin_catalog_registered'=>false,
        'authority'=>[],
        'planned_authority'=>[
            'merchant_accounts','merchant_account_members','merchant_locations',
            'campaigns','campaign_rewards','campaign_reward_claims','campaign_customers',
            'campaign_activity','campaign_reporting',
        ],
        'objects'=>[
            'merchant_account','merchant_location','campaign','reward','reward_claim','campaign_customer',
        ],
        'related_objects'=>['contact','team_member','profile'],
        'events'=>[
            'merchant.created','merchant.updated','merchant.location_created','merchant.location_updated',
            'campaign.created','campaign.updated','campaign.launched','campaign.paused','campaign.ended',
            'campaign.landing_viewed','campaign.customer_engaged','campaign.conversion',
            'reward.created','reward.updated','reward.issued','reward.claimed','reward.refunded','reward.expired',
            'claim.created','claim.validated','claim.failed','claim.completed',
        ],
        'event_classes'=>[
            'merchant.created'=>'informational',
            'merchant.updated'=>'informational',
            'merchant.location_created'=>'informational',
            'merchant.location_updated'=>'informational',
            'campaign.created'=>'informational',
            'campaign.updated'=>'informational',
            'campaign.launched'=>'completion',
            'campaign.paused'=>'actionable',
            'campaign.ended'=>'completion',
            'campaign.landing_viewed'=>'informational',
            'campaign.customer_engaged'=>'actionable',
            'campaign.conversion'=>'outcome',
            'reward.created'=>'informational',
            'reward.updated'=>'informational',
            'reward.issued'=>'actionable',
            'reward.claimed'=>'outcome',
            'reward.refunded'=>'outcome',
            'reward.expired'=>'completion',
            'claim.created'=>'actionable',
            'claim.validated'=>'completion',
            'claim.failed'=>'failure_recovery',
            'claim.completed'=>'outcome',
        ],
        'source_aliases'=>['campaigns_rewards','campaigns','rewards'],
        'current_state_domain'=>'campaigns_rewards',
        'presentation_firewall_required'=>true,
        'event_ingress'=>'agent_event_inbox_v1920',
        'attention_policy'=>'cognitive_attention_v2410',
        'current_state'=>'cognitive_current_state_v2590',
        'presentation'=>'cognitive_presentation_firewall_v2590',
        'notes'=>[
            'Business tables are intentionally not created by v26.00.',
            'The Campaigns & Rewards plugin will implement these authorities against this contract.',
            'CRM and Team remain independent authorities and are referenced rather than duplicated.',
        ],
    ];
}

function vp3_cognitive_domain_declarations_v2600(): array
{
    $manifest=function_exists('vp3_cognitive_domain_manifest_v2370')
        ?vp3_cognitive_domain_manifest_v2370()
        :['domains'=>[]];
    $domains=[];
    foreach((array)($manifest['domains']??[]) as $manifestKey=>$definition){
        if(!is_array($definition))continue;
        $domain=vp3_cognitive_domain_key_v2600((string)$manifestKey);
        $domains[$domain]=[
            'id'=>$domain,
            'label'=>ucwords(str_replace('_',' ',$domain)),
            'phase'=>(string)($definition['phase']??'integrated'),
            'implementation_status'=>'integrated',
            'plugin_key'=>'',
            'plugin_catalog_registered'=>false,
            'authority'=>array_values((array)($definition['authority']??[])),
            'planned_authority'=>[],
            'objects'=>array_values((array)($definition['objects']??[])),
            'related_objects'=>[],
            'events'=>array_values((array)($definition['events']??[])),
            'event_classes'=>[],
            'source_aliases'=>vp3_cognitive_domain_source_aliases_v2600($domain,(string)$manifestKey),
            'current_state_domain'=>vp3_cognitive_domain_current_state_group_v2600($domain),
            'presentation_firewall_required'=>true,
            'event_ingress'=>'agent_event_inbox_v1920',
            'attention_policy'=>'cognitive_attention_v2410',
            'current_state'=>'cognitive_current_state_v2590',
            'presentation'=>'cognitive_presentation_firewall_v2590',
            'notes'=>[],
        ];
    }
    $domains['campaigns_rewards']=vp3_cognitive_campaigns_rewards_contract_v2600();
    ksort($domains);
    return $domains;
}

function vp3_cognitive_domain_registry_v2600(): array
{
    $domains=vp3_cognitive_domain_declarations_v2600();
    $sources=[];$events=[];
    foreach($domains as $domain=>$definition){
        foreach((array)$definition['source_aliases'] as $source)$sources[$source]=$domain;
        foreach((array)$definition['events'] as $event){
            $events[$event]??=[];
            if(!in_array($domain,$events[$event],true))$events[$event][]=$domain;
        }
    }
    ksort($sources);ksort($events);
    return [
        'contract'=>VP3_COGNITIVE_DOMAIN_CONTRACT_V2600,
        'build'=>VP3_COGNITIVE_DOMAIN_REGISTRY_V2600,
        'authority'=>'validation_projection_only',
        'source_manifest'=>'cognitive-domain-manifest-v2370',
        'runtime_module_registry'=>'cognitive-runtime-v500',
        'event_ingress'=>'agent-event-infrastructure-v1920',
        'domains'=>$domains,
        'source_index'=>$sources,
        'event_index'=>$events,
    ];
}

function vp3_cognitive_domain_for_event_v2600(string $source,string $eventType): string
{
    $source=vp3_cognitive_domain_id_v2600($source,80);
    $eventType=vp3_cognitive_domain_id_v2600($eventType,120);
    if($source===''||$eventType==='')return '';
    $registry=vp3_cognitive_domain_registry_v2600();
    $candidate=(string)($registry['source_index'][$source]??'');
    if($candidate!==''&&in_array($eventType,(array)($registry['domains'][$candidate]['events']??[]),true))return $candidate;
    $matches=(array)($registry['event_index'][$eventType]??[]);
    return count($matches)===1?(string)$matches[0]:'';
}

function vp3_cognitive_current_state_domain_v2600(string $source,string $eventType): string
{
    $domain=vp3_cognitive_domain_for_event_v2600($source,$eventType);
    if($domain==='')return '';
    $registry=vp3_cognitive_domain_registry_v2600();
    return (string)($registry['domains'][$domain]['current_state_domain']??$domain);
}

function vp3_cognitive_domain_event_class_v2600(string $source,string $eventType): string
{
    $domain=vp3_cognitive_domain_for_event_v2600($source,$eventType);
    if($domain!==''){
        $registry=vp3_cognitive_domain_registry_v2600();
        $explicit=(string)($registry['domains'][$domain]['event_classes'][$eventType]??'');
        if(in_array($explicit,vp3_cognitive_domain_event_classes_v2600(),true))return $explicit;
    }
    $eventType=strtolower(trim($eventType));
    if(str_contains($eventType,'approval.requested')||str_contains($eventType,'action_request.created'))return 'approval_required';
    if((bool)preg_match('/(?:^|\.)(?:failed|blocked|payment_failed|recovery_required|risk_changed)$/',$eventType))return 'failure_recovery';
    if((bool)preg_match('/(?:conversion|converted|attributed|claimed|refunded)$/',$eventType))return 'outcome';
    if((bool)preg_match('/(?:^|\.)(?:completed|ended|fulfilled|published|validated|expired|ready)$/',$eventType))return 'completion';
    if((bool)preg_match('/(?:due|intent|opportunity_changed|assignment_created|issued|paused|requested)$/',$eventType))return 'actionable';
    return 'informational';
}

function vp3_cognitive_domain_event_policy_v2600(string $source,string $eventType): array
{
    $domain=vp3_cognitive_domain_for_event_v2600($source,$eventType);
    $class=vp3_cognitive_domain_event_class_v2600($source,$eventType);
    return [
        'domain'=>$domain,
        'event_type'=>vp3_cognitive_domain_id_v2600($eventType,120),
        'class'=>$class,
        'known'=>$domain!=='',
        'current_state_eligible'=>$domain!=='',
        'attention_candidate'=>in_array($class,['actionable','approval_required','failure_recovery'],true),
        'approval_required'=>$class==='approval_required',
        'outcome_candidate'=>$class==='outcome',
        'presentation_firewall_required'=>true,
    ];
}

function vp3_cognitive_domain_entity_ref_v2600(
    string $domain,string $type,mixed $id,string $scope='personal',array $extra=[]
): array {
    $registry=vp3_cognitive_domain_registry_v2600();
    if(!isset($registry['domains'][$domain]))throw new InvalidArgumentException('Unknown cognitive domain.');
    $definition=$registry['domains'][$domain];
    $type=vp3_cognitive_domain_id_v2600($type,80);
    $allowed=array_merge((array)$definition['objects'],(array)$definition['related_objects']);
    if($type===''||!in_array($type,$allowed,true))throw new InvalidArgumentException('Object type is not declared for this cognitive domain.');
    if(function_exists('vp3_cognitive_object_ref_v500')){
        $ref=vp3_cognitive_object_ref_v500($type,$id,$scope,$extra);
    }else{
        $idText=trim((string)$id);
        if($idText==='')throw new InvalidArgumentException('Cognitive object id is required.');
        $ref=['type'=>$type,'id'=>mb_strimwidth($idText,0,190,''),'scope'=>$scope];
    }
    $ref['domain']=$domain;
    return $ref;
}

function vp3_cognitive_domain_validate_refs_v2600(string $domain,array $refs): array
{
    $out=[];
    foreach(array_slice($refs,0,VP3_COGNITIVE_DOMAIN_MAX_REFS_V2600) as $ref){
        if(!is_array($ref))throw new InvalidArgumentException('Cognitive domain references must be objects.');
        $refDomain=(string)($ref['domain']??$domain);
        if($refDomain!==$domain)throw new InvalidArgumentException('Cross-domain references must use the related-object declaration.');
        $out[]=vp3_cognitive_domain_entity_ref_v2600(
            $domain,
            (string)($ref['type']??''),
            $ref['id']??'',
            (string)($ref['scope']??'personal'),
            $ref
        );
    }
    return $out;
}

function vp3_cognitive_domain_payload_v2600(array $payload): array
{
    foreach(['object_refs','domain_source','domain_contract','event_class','record_only','brain_promotion_deferred'] as $reserved)unset($payload[$reserved]);
    $safe=function_exists('vp3_cognitive_sanitize_value_v500')?vp3_cognitive_sanitize_value_v500($payload):$payload;
    if(!is_array($safe))$safe=[];
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json)||strlen($json)>VP3_COGNITIVE_DOMAIN_MAX_PAYLOAD_BYTES_V2600)throw new InvalidArgumentException('Cognitive domain payload exceeds the bounded contract.');
    return $safe;
}

function vp3_cognitive_domain_prepare_event_v2600(
    string $source,string $eventType,array $refs=[],array $payload=[]
): array {
    $domain=vp3_cognitive_domain_for_event_v2600($source,$eventType);
    if($domain===''){
        return [
            'accepted'=>false,
            'disposition'=>'quarantined_before_ingress',
            'reason'=>'unknown_domain_or_event',
            'source'=>vp3_cognitive_domain_id_v2600($source,80),
            'event_type'=>vp3_cognitive_domain_id_v2600($eventType,120),
        ];
    }
    try{
        $normalizedRefs=vp3_cognitive_domain_validate_refs_v2600($domain,$refs);
        $safePayload=vp3_cognitive_domain_payload_v2600($payload);
    }catch(Throwable $e){
        return [
            'accepted'=>false,
            'disposition'=>'quarantined_before_ingress',
            'reason'=>'malformed_domain_event',
            'source'=>vp3_cognitive_domain_id_v2600($source,80),
            'event_type'=>vp3_cognitive_domain_id_v2600($eventType,120),
        ];
    }
    $policy=vp3_cognitive_domain_event_policy_v2600($source,$eventType);
    $eventRefs=array_map(static function(array $ref): array{
        return ['type'=>$ref['type'],'id'=>$ref['id'],'scope'=>$ref['scope']];
    },$normalizedRefs);
    return [
        'accepted'=>true,
        'disposition'=>'canonical_ingress',
        'domain'=>$domain,
        'source'=>vp3_cognitive_domain_id_v2600($source,80),
        'event_type'=>vp3_cognitive_domain_id_v2600($eventType,120),
        'policy'=>$policy,
        'payload'=>[
            'object_refs'=>$eventRefs,
            'domain_source'=>$domain,
            'domain_contract'=>VP3_COGNITIVE_DOMAIN_CONTRACT_V2600,
            'event_class'=>$policy['class'],
            'record_only'=>true,
            'brain_promotion_deferred'=>true,
        ]+$safePayload,
    ];
}

function vp3_cognitive_domain_ingest_v2600(
    PDO $pdo,int $ownerUserId,string $source,string $eventType,array $refs=[],
    array $payload=[],array $options=[]
): array {
    if($ownerUserId<1)return ['accepted'=>false,'disposition'=>'quarantined_before_ingress','reason'=>'invalid_owner'];
    $prepared=vp3_cognitive_domain_prepare_event_v2600($source,$eventType,$refs,$payload);
    if(empty($prepared['accepted']))return $prepared;
    if(!function_exists('agent_event_ingest_v1920')||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo)){
        return $prepared+['accepted'=>false,'disposition'=>'ingress_unavailable','reason'=>'canonical_event_ingress_unavailable'];
    }
    $external=trim((string)($options['external_event_id']??''));
    if($external===''){
        $seed=$ownerUserId.'|'.$prepared['domain'].'|'.$prepared['event_type'].'|'.json_encode($prepared['payload']).'|'.sprintf('%.6F',microtime(true));
        $external='vp3-v2600-'.substr(hash('sha256',$seed),0,48);
    }
    try{
        $ingest=agent_event_ingest_v1920(
            $pdo,$ownerUserId,(string)$prepared['source'],(string)$prepared['event_type'],(array)$prepared['payload'],
            [
                'verification_status'=>'trusted',
                'external_event_id'=>mb_strimwidth($external,0,190,''),
                'occurred_at'=>$options['occurred_at']??gmdate('c'),
                'correlation_id'=>mb_strimwidth(trim((string)($options['correlation_id']??'')),0,120,''),
                'causation_id'=>mb_strimwidth(trim((string)($options['causation_id']??'')),0,120,''),
            ]
        );
        if(empty($ingest['duplicate'])&&is_array($ingest['event']??null)){
            $eventId=(int)($ingest['event']['id']??0);
            if($eventId>0)$pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=?")
                ->execute([$eventId,$ownerUserId]);
        }
        if(function_exists('vp3_cognitive_domain_note_live_session_v2380')){
            vp3_cognitive_domain_note_live_session_v2380($pdo,$ownerUserId,(string)$prepared['event_type'],(array)$prepared['payload']['object_refs']);
        }
        return $prepared+['ingest'=>$ingest];
    }catch(Throwable $e){
        error_log('VP3 v26.00 domain ingress failed ['.$prepared['domain'].' '.$prepared['event_type'].']: '.$e->getMessage());
        return $prepared+['accepted'=>false,'disposition'=>'ingress_failed','reason'=>'canonical_ingress_failed'];
    }
}

function vp3_cognitive_domain_registry_integrity_v2600(): array
{
    $registry=vp3_cognitive_domain_registry_v2600();
    $errors=[];$objectOwners=[];
    foreach($registry['domains'] as $domain=>$definition){
        if(empty($definition['authority'])&&$domain!=='campaigns_rewards')$errors[]=$domain.':missing_authority';
        if(empty($definition['objects']))$errors[]=$domain.':missing_objects';
        if(empty($definition['events']))$errors[]=$domain.':missing_events';
        if(empty($definition['presentation_firewall_required']))$errors[]=$domain.':presentation_firewall_not_required';
        foreach((array)$definition['objects'] as $type)$objectOwners[$type][]=$domain;
    }
    $campaign=(array)($registry['domains']['campaigns_rewards']??[]);
    if(($campaign['implementation_status']??'')!=='contract_ready')$errors[]='campaigns_rewards:not_contract_ready';
    if(!empty($campaign['plugin_catalog_registered']))$errors[]='campaigns_rewards:premature_plugin_catalog_exposure';
    return [
        'ok'=>$errors===[],
        'errors'=>$errors,
        'domain_count'=>count($registry['domains']),
        'event_count'=>count($registry['event_index']),
        'object_owners'=>$objectOwners,
    ];
}

function vp3_cognitive_domain_health_v2600(?PDO $pdo=null): array
{
    $registry=vp3_cognitive_domain_registry_v2600();
    $runtimeModules=[];
    if(function_exists('vp3_cognitive_registry_public_v500')){
        foreach((array)(vp3_cognitive_registry_public_v500()['modules']??[]) as $module){
            if(is_array($module)&&!empty($module['module']))$runtimeModules[(string)$module['module']]=true;
        }
    }
    $lastBySource=[];
    if($pdo&&function_exists('agent_event_schema_ready_v1920')&&agent_event_schema_ready_v1920($pdo)){
        try{
            $rows=$pdo->query("SELECT source,COUNT(*) AS event_count,MAX(received_at) AS last_event_at FROM agent_event_inbox GROUP BY source")->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($rows as $row)$lastBySource[(string)$row['source']]=['event_count'=>(int)$row['event_count'],'last_event_at'=>(string)$row['last_event_at']];
        }catch(Throwable $e){}
    }
    $domains=[];
    foreach($registry['domains'] as $domain=>$definition){
        $events=0;$last='';
        foreach((array)$definition['source_aliases'] as $source){
            $health=$lastBySource[$source]??null;
            if(!$health)continue;
            $events+=(int)$health['event_count'];
            if((string)$health['last_event_at']>$last)$last=(string)$health['last_event_at'];
        }
        $domains[$domain]=[
            'implementation_status'=>(string)$definition['implementation_status'],
            'declared'=>true,
            'runtime_module_registered'=>isset($runtimeModules[$domain]),
            'event_count'=>$events,
            'last_event_at'=>$last,
            'presentation_firewall_required'=>true,
        ];
    }
    return [
        'build'=>VP3_COGNITIVE_DOMAIN_REGISTRY_V2600,
        'contract'=>VP3_COGNITIVE_DOMAIN_CONTRACT_V2600,
        'integrity'=>vp3_cognitive_domain_registry_integrity_v2600(),
        'domains'=>$domains,
        'authority'=>'diagnostic_only',
    ];
}
