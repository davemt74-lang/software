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
        'phase'=>'campaigns-rewards-v1.26',
        'implementation_status'=>'integrated-v1.26',
        'plugin_key'=>'campaigns_rewards',
        'plugin_catalog_registered'=>true,
        'authority'=>[
            'merchant_accounts','merchant_members','merchant_locations','crm_merchant_relationships',
            'campaign_types','campaigns','campaign_versions','campaign_enrollments','campaign_cases',
            'campaign_landing_pages','campaign_profile_publications','reward_types','reward_products',
            'campaign_reward_sets','reward_issuances','reward_transfers','merchant_claim_codes','reward_claims',
            'loyalty_accounts','loyalty_ledger','reward_inventory_ledger','reward_liability_ledger',
            'campaign_activity_events','campaign_messages','campaign_deliveries','campaign_journeys','campaign_journey_versions','campaign_journey_publications','campaign_journey_instances','campaign_decisions','campaign_decision_outcomes','campaign_optimization_snapshots','campaign_automation_rules','campaign_rule_executions','campaign_agent_recommendations',
            'campaign_idempotency_keys','campaign_reconciliation_runs',
        ],
        'planned_authority'=>[],
        'objects'=>[
            'merchant','merchant_location','merchant_team_member','campaign','campaign_enrollment','campaign_case',
            'reward_product','reward_issuance','reward_claim','claim_code','loyalty_account','campaign_message','campaign_delivery','campaign_journey','campaign_journey_version','campaign_journey_publication','campaign_journey_instance','campaign_decision','campaign_decision_outcome','campaign_optimization_snapshot',
        ],
        'related_objects'=>['contact','team_member','profile'],
        'events'=>[
            'merchant.created','merchant.updated','merchant.member_granted','merchant.member_revoked',
            'merchant.location_created','merchant.location_updated','merchant.owner_added','merchant.owner_removed',
            'merchant.ownership_transferred','merchant.suspended','merchant.restored','merchant.archived','merchant.closed',
            'claim_code.created','claim_code.assigned','claim_code.rotated','claim_code.suspended',
            'campaign.created','campaign.updated','campaign.published','campaign.activated','campaign.paused',
            'campaign.resumed','campaign.completed','campaign.archived','campaign.audience_changed',
            'campaign.budget_threshold','campaign.inventory_risk','campaign.page_published','campaign.landing_viewed',
            'campaign.signup_started','campaign.signup_completed','campaign.contact_acquired',
            'campaign.newsletter_signup','campaign.contest_entry','campaign.qr_claim','campaign.referral_signup','campaign.birthday_signup',
            'campaign.proof_submit','campaign.instant_claim','campaign.interest_signup','campaign.event_rsvp','campaign.partner_signup','campaign.community_signup',
            'campaign.rewards_updated','campaign.automation_saved','campaign.automation_executed','campaign.recommendation_proposed',
            'campaign.message_saved','campaign.journey_queued','campaign.message_sent','campaign.message_delivered','campaign.message_viewed','campaign.message_failed','campaign.message_suppressed','campaign.message_converted',
            'campaign.journey_node_saved','campaign.journey_node_queued','campaign.journey_started','campaign.journey_branch_selected','campaign.journey_node_completed','campaign.journey_exited','campaign.message_retry_scheduled','campaign.message_dead_lettered','campaign.provider_event_received',
            'campaign.journey_optimization_updated','campaign.journey_template_applied','campaign.journey_simulated','campaign.journey_frequency_deferred','campaign.journey_send_time_optimized','campaign.journey_recommendation_proposed','campaign.journey_recommendation_reviewed',
            'campaign.journey_draft_changed','campaign.journey_release_validated','campaign.journey_published','campaign.journey_publish_scheduled','campaign.journey_publish_cancelled','campaign.journey_version_started','campaign.journey_release_simulated','campaign.journey_enrollment_changed','campaign.journey_archived','campaign.journey_cloned',
            'campaign.journey_instance_pause','campaign.journey_instance_resume','campaign.journey_instance_cancel','campaign.journey_instance_node_skipped','campaign.journey_instance_step_moved','campaign.journey_emergency_stopped','campaign.journey_incident_proposed',
            'campaign.decision_recorded','campaign.decision_recommendation_proposed','campaign.decision_outcome_recorded','campaign.optimization_snapshot_recorded','campaign.optimization_recommendation_proposed',
            'campaign.enrollment_qualified','campaign.enrollment_created','campaign.enrollment_completed',
            'campaign.enrollment_disqualified','campaign.case_opened','campaign.case_resolved','campaign.case_reopened',
            'reward.product_created','reward.product_updated','reward.issued','reward.sent','reward.viewed',
            'reward.expiring','reward.expired','reward.voided','reward.reissued',
            'claim.attempted','claim.accepted','claim.rejected','claim.overridden','claim.reversed',
            'loyalty.joined','loyalty.earned','loyalty.spent','loyalty.tier_changed','loyalty.reward_unlocked',
            'campaign.conversion_attributed','campaign.goal_progress_changed',
            'reconciliation.started','reconciliation.finding_created','reconciliation.repair_applied',
            'reconciliation.completed','cognitive_bridge.replayed',
        ],
        'event_classes'=>[
            'merchant.created'=>'informational','merchant.updated'=>'informational','merchant.member_granted'=>'actionable','merchant.member_revoked'=>'actionable',
            'merchant.location_created'=>'informational','merchant.location_updated'=>'informational','merchant.owner_added'=>'approval_required','merchant.owner_removed'=>'approval_required',
            'merchant.ownership_transferred'=>'approval_required','merchant.suspended'=>'approval_required','merchant.restored'=>'completion','merchant.archived'=>'completion','merchant.closed'=>'completion',
            'claim_code.created'=>'actionable','claim_code.assigned'=>'actionable','claim_code.rotated'=>'approval_required','claim_code.suspended'=>'actionable',
            'campaign.created'=>'informational','campaign.updated'=>'informational','campaign.published'=>'completion','campaign.activated'=>'completion',
            'campaign.paused'=>'actionable','campaign.resumed'=>'completion','campaign.completed'=>'completion','campaign.archived'=>'completion',
            'campaign.audience_changed'=>'informational','campaign.budget_threshold'=>'actionable','campaign.inventory_risk'=>'failure_recovery',
            'campaign.page_published'=>'completion','campaign.landing_viewed'=>'informational','campaign.signup_started'=>'informational','campaign.signup_completed'=>'completion','campaign.contact_acquired'=>'outcome',
            'campaign.newsletter_signup'=>'outcome','campaign.contest_entry'=>'informational','campaign.qr_claim'=>'outcome','campaign.referral_signup'=>'outcome','campaign.birthday_signup'=>'informational',
            'campaign.proof_submit'=>'actionable','campaign.instant_claim'=>'outcome','campaign.interest_signup'=>'informational','campaign.event_rsvp'=>'informational','campaign.partner_signup'=>'outcome','campaign.community_signup'=>'informational',
            'campaign.rewards_updated'=>'informational','campaign.automation_saved'=>'informational','campaign.automation_executed'=>'outcome','campaign.recommendation_proposed'=>'actionable',
            'campaign.message_saved'=>'informational','campaign.journey_queued'=>'informational','campaign.message_sent'=>'informational','campaign.message_delivered'=>'completion','campaign.message_viewed'=>'informational','campaign.message_failed'=>'failure_recovery','campaign.message_suppressed'=>'informational','campaign.message_converted'=>'outcome',
            'campaign.journey_node_saved'=>'informational','campaign.journey_node_queued'=>'informational','campaign.journey_started'=>'informational','campaign.journey_branch_selected'=>'informational','campaign.journey_node_completed'=>'completion','campaign.journey_exited'=>'completion','campaign.message_retry_scheduled'=>'actionable','campaign.message_dead_lettered'=>'failure_recovery','campaign.provider_event_received'=>'informational',
            'campaign.journey_optimization_updated'=>'informational','campaign.journey_template_applied'=>'informational','campaign.journey_simulated'=>'informational','campaign.journey_frequency_deferred'=>'actionable','campaign.journey_send_time_optimized'=>'informational','campaign.journey_recommendation_proposed'=>'actionable','campaign.journey_recommendation_reviewed'=>'completion',
            'campaign.journey_draft_changed'=>'informational','campaign.journey_release_validated'=>'informational','campaign.journey_published'=>'completion','campaign.journey_publish_scheduled'=>'approval_required','campaign.journey_publish_cancelled'=>'informational','campaign.journey_version_started'=>'informational','campaign.journey_release_simulated'=>'informational','campaign.journey_enrollment_changed'=>'actionable','campaign.journey_archived'=>'completion','campaign.journey_cloned'=>'informational',
            'campaign.journey_instance_pause'=>'actionable','campaign.journey_instance_resume'=>'completion','campaign.journey_instance_cancel'=>'approval_required','campaign.journey_instance_node_skipped'=>'approval_required','campaign.journey_instance_step_moved'=>'approval_required','campaign.journey_emergency_stopped'=>'approval_required','campaign.journey_incident_proposed'=>'failure_recovery',
            'campaign.decision_recorded'=>'informational','campaign.decision_recommendation_proposed'=>'actionable','campaign.decision_outcome_recorded'=>'outcome','campaign.optimization_snapshot_recorded'=>'informational','campaign.optimization_recommendation_proposed'=>'actionable',
            'campaign.enrollment_qualified'=>'informational','campaign.enrollment_created'=>'actionable','campaign.enrollment_completed'=>'completion','campaign.enrollment_disqualified'=>'completion',
            'campaign.case_opened'=>'actionable','campaign.case_resolved'=>'outcome','campaign.case_reopened'=>'actionable',
            'reward.product_created'=>'informational','reward.product_updated'=>'informational','reward.issued'=>'actionable','reward.sent'=>'informational','reward.viewed'=>'informational',
            'reward.expiring'=>'actionable','reward.expired'=>'completion','reward.voided'=>'completion','reward.reissued'=>'actionable',
            'claim.attempted'=>'informational','claim.accepted'=>'outcome','claim.rejected'=>'failure_recovery','claim.overridden'=>'approval_required','claim.reversed'=>'approval_required',
            'loyalty.joined'=>'informational','loyalty.earned'=>'outcome','loyalty.spent'=>'outcome','loyalty.tier_changed'=>'completion','loyalty.reward_unlocked'=>'actionable',
            'campaign.conversion_attributed'=>'outcome','campaign.goal_progress_changed'=>'informational',
            'reconciliation.started'=>'informational','reconciliation.finding_created'=>'failure_recovery','reconciliation.repair_applied'=>'completion',
            'reconciliation.completed'=>'completion','cognitive_bridge.replayed'=>'informational',
        ],
        'source_aliases'=>['campaigns_rewards','campaigns','rewards'],
        'current_state_domain'=>'campaigns_rewards',
        'presentation_firewall_required'=>true,
        'event_ingress'=>'agent_event_inbox_v1920',
        'attention_policy'=>'cognitive_attention_v2410',
        'current_state'=>'cognitive_current_state_v2590',
        'presentation'=>'cognitive_presentation_firewall_v2590',
        'notes'=>[
            'Campaigns & Rewards V1.26 adds append-only verified Decision outcomes, immutable optimization snapshots, lifecycle/fatigue intelligence and human-review optimization recommendations without autonomous Campaign mutation.',
            'Campaigns & Rewards V1.25 freezes personalization, holdout, conflict and dynamic offer rules into Journey releases and records deterministic entry, branch and offer evidence in the append-only Campaign Decision ledger.',
            'Campaigns & Rewards V1.24 promotes pinned Journey Instances into canonical operational records for live monitoring, human recovery controls, controlled new-entry rollouts, incident surfacing and descriptive release operations comparison.',
            'Campaigns & Rewards V1.23 makes the whole Journey graph a governed release artifact with draft/live separation, atomic publishing, immutable version history, in-flight release pinning, rollback and scheduled publication.',
            'Campaigns & Rewards V1.22 derives journey intelligence from canonical delivery outcomes, adds opt-in optimization/frequency controls, dry-run simulation and human-reviewed Agent recommendations without an autonomous learner.',
            'Campaigns & Rewards V1.21 adds graph orchestration, deterministic A/B variants, timezone delivery, bounded retry/dead-letter handling and signed provider delivery events over the existing message/delivery authorities.',
            'Campaigns & Rewards V1.20 adds versioned Campaign messaging journeys over the existing message, delivery and idempotency authorities without a second scheduler.',
            'Campaigns & Rewards V1.19 activates the existing Campaign Automation Rule and Rule Execution authorities for governed lifecycle triggers.',
            'Event automations are constrained to the triggering CRM contact; scheduled birthday and win-back rules may evaluate configured CRM audiences.',
            'Lifecycle intelligence creates human-review recommendations only and never auto-activates Campaigns or automation rules.',
            'Signup Reward remains a newsletter/email-list acquisition flow that writes Core CRM marketing consent and issues the attached welcome Reward.',
            'CRM contact identity and Team human lifecycle remain VP3 Core authorities and are referenced rather than duplicated.',
            'INBOX, SENT and CLAIMED are projections over Reward Issuance, Reward Transfer and Claim state; no second mutable Wallet ledger exists.',
            'Plugin disable pauses surfaces without deleting Merchant, CRM relationship, Campaign, Reward, Claim, Loyalty or audit history.',
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
    if(!str_starts_with((string)($campaign['implementation_status']??''),'integrated'))$errors[]='campaigns_rewards:not_integrated';
    if(empty($campaign['plugin_catalog_registered']))$errors[]='campaigns_rewards:plugin_catalog_missing';
    if(empty($campaign['authority']))$errors[]='campaigns_rewards:missing_business_authority';
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
