<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.90 — Domain Integration II.
 *
 * Extends the canonical v23.70 event/live-session foundation and v23.80
 * operational adapters to cross-surface/browser/research/team/tool/HomeServer/
 * media/attribution domains. Existing domain tables remain authoritative.
 */
const VP3_COGNITIVE_DOMAIN_INTEGRATION_V2390='vp3-cognitive-domain-integration-v2390-20260922';

function vp3_cognitive_domain_event_types_v2390(): array
{
    return [
        'browser_operations'=>[
            'browser.transaction_started','browser.transaction_changed','browser.transaction_completed',
            'browser.transaction_followup_due','browser.recovery_required','browser.annotation_created',
        ],
        'research_knowledge'=>[
            'research.created','research.updated','research.source_added','research.finding_created',
            'research.finding_updated','research.finding_status_changed','research.report_published',
            'knowledge.created','knowledge.updated',
        ],
        'messaging_team'=>[
            'team.message_received','team.message_sent','team.member_joined','team.member_left',
            'team.member_role_changed','team.member_status_changed',
        ],
        'workflow_tools_approvals'=>[
            'tool.completed','tool.failed','tool.cancelled','approval.requested','approval.granted','approval.denied',
        ],
        'homeserver_operations'=>[
            'homeserver.pairing_started','homeserver.connected','homeserver.disconnected','homeserver.status_changed',
            'action_request.created','action_request.approved','action_request.completed',
        ],
        'media_studio'=>[
            'media.asset_created','recording.completed','transcription.completed','project.updated','export.completed',
        ],
        'analytics_attribution'=>[
            'analytics.signal_detected','referral.attributed','conversion.attributed',
        ],
    ];
}

function vp3_cognitive_domain_record_v2390(
    PDO $pdo,int $ownerUserId,string $source,string $eventType,array $objectRefs=[],
    array $payload=[],array $options=[]
): ?array {
    $catalog=vp3_cognitive_domain_event_types_v2390();
    if($ownerUserId<1||!isset($catalog[$source])||!in_array($eventType,$catalog[$source],true))return null;
    if(!function_exists('agent_event_ingest_v1920')||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo))return null;
    $refs=[];
    foreach(array_slice($objectRefs,0,16) as $ref){
        if(!is_array($ref)||empty($ref['type'])||empty($ref['id']))continue;
        $refs[]=[
            'type'=>mb_strimwidth((string)$ref['type'],0,80,''),
            'id'=>mb_strimwidth((string)$ref['id'],0,190,''),
            'scope'=>mb_strimwidth((string)($ref['scope']??'personal'),0,40,''),
        ];
    }
    $external=mb_strimwidth(trim((string)($options['external_event_id']??'')),0,190,'');
    if($external===''){
        $external='vp3-v2390-'.substr(hash('sha256',$source.'|'.$eventType.'|'.json_encode($refs).'|'.sprintf('%.6F',microtime(true))),0,48);
    }
    try{
        $ingest=agent_event_ingest_v1920($pdo,$ownerUserId,$source,$eventType,[
            'object_refs'=>$refs,
            'domain_source'=>$source,
            'record_only'=>true,
            'brain_promotion_deferred'=>true,
        ]+$payload,[
            'verification_status'=>'trusted',
            'external_event_id'=>$external,
            'occurred_at'=>$options['occurred_at']??gmdate('c'),
            'correlation_id'=>mb_strimwidth(trim((string)($options['correlation_id']??'')),0,120,''),
            'causation_id'=>mb_strimwidth(trim((string)($options['causation_id']??'')),0,120,''),
        ]);
        if(empty($ingest['duplicate'])&&is_array($ingest['event']??null)){
            $eventId=(int)($ingest['event']['id']??0);
            if($eventId>0)$pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=?")->execute([$eventId,$ownerUserId]);
        }
        if(function_exists('vp3_cognitive_domain_note_live_session_v2380'))vp3_cognitive_domain_note_live_session_v2380($pdo,$ownerUserId,$eventType,$refs);
        return $ingest;
    }catch(Throwable $e){
        error_log('VP3 v23.90 domain event record failed ['.$source.' '.$eventType.']: '.$e->getMessage());
        return null;
    }
}

function vp3_cognitive_ref_v2390(string $type,mixed $id,string $scope='personal'): array
{
    if(function_exists('vp3_cognitive_domain_ref_v2380'))return vp3_cognitive_domain_ref_v2380($type,$id,$scope);
    return ['type'=>$type,'id'=>(string)$id,'scope'=>$scope];
}

function vp3_cognitive_research_event_bridge_v2390(PDO $pdo,int $projectId,int $actorUserId,string $legacyType,string $objectType,string $objectPublicId,array $metadata,int $eventId=0): void
{
    $project=function_exists('vp3_research_project_row_by_id_v2060')?vp3_research_project_row_by_id_v2060($pdo,$projectId):null;
    $owner=(int)($project['owner_user_id']??0);if($owner<1)return;
    $map=[
        'project.created'=>'research.created','project.updated'=>'research.updated','item.added'=>'research.source_added',
        'finding.created'=>'research.finding_created','finding.updated'=>'research.finding_updated',
        'finding.status'=>'research.finding_status_changed','report.published'=>'research.report_published',
    ];
    $canonical=$map[$legacyType]??'';if($canonical==='')return;
    $refs=[vp3_cognitive_ref_v2390('research_project',(string)($project['public_id']??$projectId),!empty($project['team_owner_user_id'])?'team':'personal')];
    if($objectPublicId!==''){
        $refType=match($objectType){'annotation'=>'annotation','finding'=>'claim','report'=>'research',default=>''};
        if($refType!=='')$refs[]=vp3_cognitive_ref_v2390($refType,$objectPublicId,!empty($project['team_owner_user_id'])?'team':'personal');
    }
    vp3_cognitive_domain_record_v2390($pdo,$owner,'research_knowledge',$canonical,array_values(array_filter($refs)),[
        'actor_user_id'=>$actorUserId,
        'legacy_event_type'=>$legacyType,
        'object_type'=>$objectType,
        'state_from'=>mb_strimwidth((string)($metadata['from']??''),0,40,''),
        'state_to'=>mb_strimwidth((string)($metadata['to']??''),0,40,''),
        'visibility'=>mb_strimwidth((string)($metadata['visibility']??''),0,30,''),
    ],$eventId>0?['external_event_id'=>'research-event:'.$eventId]:[]);
}

function vp3_cognitive_knowledge_event_v2390(PDO $pdo,int $owner,int $knowledgeId,bool $created): void
{
    if($owner<1||$knowledgeId<1)return;
    vp3_cognitive_domain_record_v2390($pdo,$owner,'research_knowledge',$created?'knowledge.created':'knowledge.updated',[
        vp3_cognitive_ref_v2390('knowledge',$knowledgeId)
    ],[],['external_event_id'=>'knowledge:'.$knowledgeId.':'.($created?'created':'updated').':'.gmdate('YmdHis')]);
}

function vp3_cognitive_message_bridge_v2390(PDO $pdo,array $conversation,array $message): void
{
    $conversationId=(int)($conversation['id']??0);$messageId=(int)($message['id']??0);$sender=(int)($message['sender_user_id']??0);
    if($conversationId<1||$messageId<1||$sender<1)return;
    $stmt=$pdo->prepare('SELECT user_id FROM human_conversation_members WHERE conversation_id=? AND left_at IS NULL');
    $stmt->execute([$conversationId]);$members=array_values(array_unique(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
    if(!$members)$members=[$sender];
    foreach($members as $uid){
        if($uid<1)continue;
        $event=$uid===$sender?'team.message_sent':'team.message_received';
        vp3_cognitive_domain_record_v2390($pdo,$uid,'messaging_team',$event,[
            vp3_cognitive_ref_v2390('human_message',$messageId),
            vp3_cognitive_ref_v2390('team_conversation',$conversationId,str_starts_with((string)($conversation['conversation_type']??''),'team')?'team':'personal'),
        ],[
            'conversation_type'=>(string)($conversation['conversation_type']??'direct'),
            'sender_user_id'=>$sender,
            'body_stored'=>false,
        ],['external_event_id'=>'human-message:'.$messageId.':'.$uid]);
    }
}

function vp3_cognitive_team_member_event_v2390(PDO $pdo,int $owner,int $member,string $eventType,array $payload=[]): void
{
    if($owner<1||$member<1)return;
    $id=$owner.':'.$member;
    foreach(array_values(array_unique([$owner,$member])) as $uid){
        vp3_cognitive_domain_record_v2390($pdo,$uid,'messaging_team',$eventType,[
            vp3_cognitive_ref_v2390('team_member',$id,'team')
        ],['workspace_owner_user_id'=>$owner,'member_user_id'=>$member]+$payload,[
            'external_event_id'=>'team-member:'.$id.':'.$eventType.':'.substr(hash('sha256',json_encode($payload)),0,16)
        ]);
    }
}

function vp3_cognitive_tool_event_v2390(PDO $pdo,int $owner,int $historyId,string $status,string $toolKey,?int $conversationId=null): void
{
    if($owner<1||$historyId<1)return;
    $status=strtolower($status);
    $event=in_array($status,['success','completed','ok'],true)?'tool.completed':(in_array($status,['cancelled','canceled'],true)?'tool.cancelled':'tool.failed');
    vp3_cognitive_domain_record_v2390($pdo,$owner,'workflow_tools_approvals',$event,[
        vp3_cognitive_ref_v2390('tool_action',$historyId)
    ],[
        'tool_key'=>mb_strimwidth($toolKey,0,80,''),
        'status'=>mb_strimwidth($status,0,30,''),
        'conversation_id'=>max(0,(int)$conversationId),
    ],['external_event_id'=>'tool-history:'.$historyId]);
}

function vp3_cognitive_homeserver_event_v2390(PDO $pdo,int $owner,string $eventType,string $status='',array $payload=[]): void
{
    if($owner<1)return;
    vp3_cognitive_domain_record_v2390($pdo,$owner,'homeserver_operations',$eventType,[
        vp3_cognitive_ref_v2390('homeserver_device',$owner,'homeserver')
    ],['status'=>mb_strimwidth($status,0,40,'')]+$payload,[
        'external_event_id'=>'homeserver:'.$owner.':'.$eventType.':'.substr(hash('sha256',$status.'|'.json_encode($payload).'|'.gmdate('YmdHi')),0,16)
    ]);
}

function vp3_cognitive_media_event_v2390(PDO $pdo,int $owner,int $assetId,string $mediaType,string $source=''): void
{
    if($owner<1||$assetId<1)return;
    vp3_cognitive_domain_record_v2390($pdo,$owner,'media_studio','media.asset_created',[
        vp3_cognitive_ref_v2390('media_asset',$assetId)
    ],['media_type'=>mb_strimwidth($mediaType,0,20,''),'source'=>mb_strimwidth($source,0,60,'')],[
        'external_event_id'=>'media-asset:'.$assetId
    ]);
}

function vp3_cognitive_attribution_event_v2390(PDO $pdo,int $owner,int $referralId,int $contactId,bool $conversion,string $eventName,?float $value=null,int $attributionEventId=0): void
{
    if($owner<1||$referralId<1)return;
    $event=$conversion?'conversion.attributed':'referral.attributed';
    vp3_cognitive_domain_record_v2390($pdo,$owner,'analytics_attribution',$event,[
        vp3_cognitive_ref_v2390('attribution_event',$attributionEventId>0?$attributionEventId:$referralId),
        vp3_cognitive_ref_v2390('contact',$contactId),
    ],[
        'referral_id'=>$referralId,'contact_id'=>$contactId,'event_name'=>mb_strimwidth($eventName,0,80,''),
        'value'=>$value,
    ],['external_event_id'=>'attribution:'.($attributionEventId>0?$attributionEventId:$referralId.':'.$eventName)]);
}

function vp3_cognitive_browser_transaction_event_v2390(PDO $pdo,int $owner,array $continuity,string $eventType,array $payload=[]): void
{
    $public=(string)($continuity['public_id']??'');if($owner<1||$public==='')return;
    vp3_cognitive_domain_record_v2390($pdo,$owner,'browser_operations',$eventType,[
        vp3_cognitive_ref_v2390('browser_transaction',$public)
    ],[
        'lifecycle_family'=>(string)($continuity['lifecycle_family']??''),
        'lifecycle_state'=>(string)($continuity['lifecycle_state']??''),
        'tracking_status'=>(string)($continuity['tracking_status']??''),
        'workflow_run_id'=>max(0,(int)($continuity['workflow_run_id']??0)),
    ]+$payload);
}

function vp3_cognitive_annotation_event_v2390(PDO $pdo,int $owner,array $row): void
{
    $public=(string)($row['public_id']??'');if($owner<1||$public==='')return;
    vp3_cognitive_domain_record_v2390($pdo,$owner,'browser_operations','browser.annotation_created',[
        vp3_cognitive_ref_v2390('annotation',$public)
    ],[
        'source_public_id'=>(string)($row['source_public_id']??''),
        'visibility'=>(string)($row['visibility']??''),
    ],['external_event_id'=>'annotation-published:'.$public]);
}

function vp3_cognitive_domain_row_v2390(PDO $pdo,array $user,array $ref): ?array
{
    $uid=(int)($user['id']??0);$type=(string)($ref['type']??'');$id=(string)($ref['id']??'');if($uid<1||$id==='')return null;
    if($type==='browser_transaction'&&function_exists('vp3_browser_continuity_row_v2260'))return vp3_browser_continuity_row_v2260($pdo,$uid,$id);
    if($type==='research_project'&&function_exists('vp3_research_project_row_v2060')){
        $row=vp3_research_project_row_v2060($pdo,$id);if(!$row)return null;
        if((int)$row['owner_user_id']===$uid)return $row;
        if(function_exists('vp3_research_project_role_v2060')&&vp3_research_project_role_v2060($pdo,$row,$uid)!=='')return $row;
        return null;
    }
    if($type==='team_conversation'&&ctype_digit($id)&&function_exists('vp3_human_conversation_v370')){
        $row=vp3_human_conversation_v370($pdo,(int)$id);return $row&&vp3_human_can_access_v370($pdo,$row,$uid)?$row:null;
    }
    if($type==='team_member'&&preg_match('/^(\d+):(\d+)$/',$id,$m)){
        $owner=(int)$m[1];$member=(int)$m[2];if($uid!==$owner&&$uid!==$member)return null;
        return function_exists('workspace_team_v350_membership')?workspace_team_v350_membership($pdo,$owner,$member):null;
    }
    if($type==='tool_action'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,user_id,conversation_id,tool_key,status,created_at FROM agent_tool_history WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if($type==='homeserver_device'){
        if((string)$uid!==$id||!function_exists('homeserver_vp3_connection'))return null;
        $row=homeserver_vp3_connection($uid);if(!$row)return null;
        foreach(['relay_token_enc','homeserver_token_enc','pending_claim_token_enc','pending_code','pending_request_id'] as $key)unset($row[$key]);
        return $row;
    }
    if($type==='media_asset'&&ctype_digit($id)&&function_exists('media_studio_asset'))return media_studio_asset($user,(int)$id);
    if($type==='attribution_event'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,referral_id,owner_user_id,agent_contact_id,property_id,event_type,value_amount,occurred_at FROM vp3_agent_referral_events WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    return null;
}

function vp3_cognitive_domain_permission_v2390(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read')return false;
    try{return vp3_cognitive_domain_row_v2390($pdo,$user,$ref)!==null;}catch(Throwable $e){return false;}
}

function vp3_cognitive_domain_context_v2390(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $row=vp3_cognitive_domain_row_v2390($pdo,$user,$ref);if(!$row)throw new RuntimeException('Cognitive domain object is unavailable.');
    foreach(['body','request_text','result_json','metadata_json','reference_hash','last_page_fingerprint','last_content_hash','last_observation_fingerprint'] as $key)unset($row[$key]);
    return ['record'=>$row,'authority'=>'domain_record','build'=>VP3_COGNITIVE_DOMAIN_INTEGRATION_V2390];
}

function vp3_cognitive_register_domain_module_v2390(string $module,array $objects,array $events,array $freshness=[]): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();if(isset($registry['modules'][$module]))return;
    vp3_cognitive_register_module_v500([
        'module'=>$module,'version'=>'domain-integration-v2390','objects'=>$objects,'events'=>$events,
        'permission_resolver'=>'vp3_cognitive_domain_permission_v2390','context_provider'=>'vp3_cognitive_domain_context_v2390',
        'relationship_provider'=>null,'cards'=>[],'tools'=>[],
        'freshness_policy'=>$freshness?:['default_seconds'=>60],
        'sensitivity_policy'=>['owner_scoped'=>true,'minimal_context'=>true,'raw_event_payloads_exposed'=>false],
        'surfaces'=>['memory','brief','away_digest','notification','ask_user','chat_response'],'voice_safe'=>false,
    ]);
}

function vp3_cognitive_register_domains_v2390(): void
{
    $events=vp3_cognitive_domain_event_types_v2390();
    vp3_cognitive_register_domain_module_v2390('browser_operations',['browser_transaction'],$events['browser_operations'],['default_seconds'=>20]);
    vp3_cognitive_register_domain_module_v2390('research_knowledge',['research_project'],$events['research_knowledge'],['default_seconds'=>60]);
    vp3_cognitive_register_domain_module_v2390('messaging_team',['team_conversation','team_member'],$events['messaging_team'],['default_seconds'=>15]);
    vp3_cognitive_register_domain_module_v2390('workflow_tools_approvals',['tool_action'],$events['workflow_tools_approvals'],['default_seconds'=>20]);
    vp3_cognitive_register_domain_module_v2390('homeserver_operations',['homeserver_device'],$events['homeserver_operations'],['default_seconds'=>30]);
    vp3_cognitive_register_domain_module_v2390('media_studio',['media_asset'],$events['media_studio'],['default_seconds'=>60]);
    vp3_cognitive_register_domain_module_v2390('analytics_attribution',['attribution_event'],$events['analytics_attribution'],['default_seconds'=>60]);
}

vp3_cognitive_register_domains_v2390();
