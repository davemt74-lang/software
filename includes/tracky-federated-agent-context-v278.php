<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 6 — Cloud federated Agent context mirror.
 *
 * The snapshot is derived by HomeServer from already-governed physical state.
 * Cloud validates and stores it for the shared Agent Brain, but cannot mutate,
 * author, or relay it back as a source of physical truth.
 */
const VP3_TRACKY_FEDERATED_AGENT_CONTEXT_V278='vp3-tracky-federated-agent-context-v278-20260927';
const VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278='physical_federated_agent_context.v1';

function tracky_v278_agent_context_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federated_agent_context'):false;
}

function tracky_v278_agent_context_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federated_agent_context (
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      local_site_uuid CHAR(36) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      source_fingerprint CHAR(64) NOT NULL,
      semantic_hash CHAR(64) NOT NULL,
      agent_state VARCHAR(40) NOT NULL,
      physical_state VARCHAR(40) NOT NULL,
      focus_identity_uuid CHAR(36) NULL,
      authority_site_uuid CHAR(36) NULL,
      authority_device_uuid CHAR(36) NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      current_site_uuid CHAR(36) NULL,
      context_json LONGTEXT NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,reporting_site_id),
      INDEX idx_tracky_agent_context_state (user_id,agent_state,physical_state,updated_at),
      INDEX idx_tracky_agent_context_authority (user_id,authority_site_uuid,authority_epoch,updated_at),
      CONSTRAINT fk_tracky_agent_context_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federated_agent_context_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      source_fingerprint CHAR(64) NOT NULL,
      semantic_hash CHAR(64) NOT NULL,
      agent_state VARCHAR(40) NOT NULL,
      physical_state VARCHAR(40) NOT NULL,
      context_json LONGTEXT NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_tracky_agent_context_history (user_id,reporting_site_id,revision),
      INDEX idx_tracky_agent_context_history_recent (user_id,reporting_site_id,revision),
      CONSTRAINT fk_tracky_agent_context_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_agent_context_uuid(mixed $value,string $label,bool $allowEmpty=false): string
{
    $text=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if($allowEmpty&&$text==='')return '';
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$text)){
        throw new RuntimeException('Tracky federated Agent context '.$label.' must be a UUID.');
    }
    return $text;
}

function tracky_v278_agent_context_text(mixed $value,int $max=240): string
{
    return mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
}

function tracky_v278_agent_context_confidence(mixed $value): float
{
    return is_numeric($value)?max(0.0,min(1.0,(float)$value)):0.0;
}

function tracky_v278_agent_context_site_summary(array $item): array
{
    tracky_cloud_v270_assert_governed_value($item,'federated_agent_context.site');
    $siteId=tracky_v278_agent_context_uuid($item['site_id']??'','site id');
    $device=tracky_v278_agent_context_uuid($item['authority_device_id']??'','authority device id',true);
    $fresh=strtolower(tracky_v278_agent_context_text($item['freshness']??'unknown',40));
    if(!in_array($fresh,['current','stale','unknown'],true))$fresh='unknown';
    return [
      'site_id'=>$siteId,
      'label'=>tracky_v278_agent_context_text($item['label']??'',120),
      'status'=>tracky_v278_agent_context_text($item['status']??'active',30),
      'authority_device_id'=>$device,
      'authority_epoch'=>max(0,(int)($item['authority_epoch']??0)),
      'world_revision'=>max(0,(int)($item['world_revision']??0)),
      'freshness'=>$fresh,
      'sync_status'=>tracky_v278_agent_context_text($item['sync_status']??'unknown',40),
      'recent_changes'=>array_values(array_filter(array_map(
        static fn($v)=>tracky_v278_agent_context_text($v,240),
        array_slice(is_array($item['recent_changes']??null)?$item['recent_changes']:[],0,5)
      ),static fn($v)=>$v!==''))
    ];
}

function tracky_v278_agent_context_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federated_agent_context');
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278){
        throw new RuntimeException('Tracky federated Agent context protocol is unsupported.');
    }
    $revision=max(0,(int)($input['revision']??0));
    if($revision<1)throw new RuntimeException('Tracky federated Agent context revision is required.');
    $fingerprint=strtolower(tracky_v278_agent_context_text($input['fingerprint']??'',64));
    if(!preg_match('/^[0-9a-f]{64}$/',$fingerprint)){
        throw new RuntimeException('Tracky federated Agent context fingerprint must be SHA-256.');
    }

    $agentState=strtolower(tracky_v278_agent_context_text($input['agent_state']??'',40));
    if(!in_array($agentState,['current','reconciling','stale','failed'],true)){
        throw new RuntimeException('Tracky federated Agent context state is unsupported.');
    }
    $physicalState=strtolower(tracky_v278_agent_context_text($input['physical_state']??'',40));
    if(!in_array($physicalState,['present','unknown','uncertain','departing','in_transit','arriving','offline','temporary_context'],true)){
        throw new RuntimeException('Tracky federated Agent physical state is unsupported.');
    }
    if(isset($input['authority_assignment'])&&(string)$input['authority_assignment']!=='local_only'){
        throw new RuntimeException('Tracky federated Agent context cannot change physical authority assignment.');
    }
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only']){
        throw new RuntimeException('Tracky federated Agent context Cloud projection must remain read-only.');
    }
    if(!empty($input['context_mutation_authority'])||!empty($input['site_authority_mutation'])){
        throw new RuntimeException('Tracky Cloud cannot receive federated Agent context mutation authority.');
    }

    $localSite=tracky_v278_agent_context_uuid($input['local_site_id']??'','local site id');
    $authority=is_array($input['authority']??null)?$input['authority']:[];
    $authoritySite=tracky_v278_agent_context_uuid($authority['site_id']??'','authority site id',true);
    $authorityDevice=tracky_v278_agent_context_uuid($authority['device_id']??'','authority device id',true);
    $authorityEpoch=max(0,(int)($authority['epoch']??0));
    if(($authoritySite===''||$authorityDevice===''||$authorityEpoch<1)
      &&$agentState==='current'&&$physicalState!=='unknown'){
        throw new RuntimeException('Current Tracky federated Agent context requires explicit physical authority.');
    }

    $currentSite=null;
    if(is_array($input['current_site']??null)){
        $currentSite=[
          'site_id'=>tracky_v278_agent_context_uuid($input['current_site']['site_id']??'','current site id'),
          'label'=>tracky_v278_agent_context_text($input['current_site']['label']??'',120),
          'member_ref'=>tracky_v278_agent_context_text($input['current_site']['member_ref']??'',320),
          'location_ref'=>tracky_v278_agent_context_text($input['current_site']['location_ref']??'',320),
          'confidence'=>tracky_v278_agent_context_confidence($input['current_site']['confidence']??0),
          'observed_at'=>max(0,(int)($input['current_site']['observed_at']??0)),
          'why'=>tracky_v278_agent_context_text($input['current_site']['why']??'',80),
        ];
    }

    $focus=null;
    if(is_array($input['focus_identity']??null)){
        $focus=[
          'canonical_identity_id'=>tracky_v278_agent_context_uuid($input['focus_identity']['canonical_identity_id']??'','focus identity id'),
          'entity_type'=>tracky_v278_agent_context_text($input['focus_identity']['entity_type']??'',40),
          'aliases'=>array_values(array_filter(array_map(
            static fn($v)=>tracky_v278_agent_context_text($v,120),
            array_slice(is_array($input['focus_identity']['aliases']??null)?$input['focus_identity']['aliases']:[],0,8)
          ),static fn($v)=>$v!=='')),
          'member_site_ids'=>array_values(array_map(
            static fn($v)=>tracky_v278_agent_context_uuid($v,'focus member site id'),
            array_slice(is_array($input['focus_identity']['member_site_ids']??null)?$input['focus_identity']['member_site_ids']:[],0,64)
          )),
        ];
    }

    $transition=null;
    if(is_array($input['active_mobile_transition']??null)){
        $t=$input['active_mobile_transition'];
        $transition=[
          'transition_id'=>tracky_v278_agent_context_text($t['transition_id']??'',160),
          'subject_kind'=>tracky_v278_agent_context_text($t['subject_kind']??'',40),
          'subject_id'=>tracky_v278_agent_context_text($t['subject_id']??'',160),
          'source_site_id'=>tracky_v278_agent_context_uuid($t['source_site_id']??'','transition source site id'),
          'destination_site_id'=>tracky_v278_agent_context_uuid($t['destination_site_id']??'','transition destination site id',true),
          'state'=>tracky_v278_agent_context_text($t['state']??'',40),
          'confidence'=>tracky_v278_agent_context_confidence($t['confidence']??0),
          'temporary_context'=>is_array($t['temporary_context']??null)?[
            'id'=>tracky_v278_agent_context_text($t['temporary_context']['id']??'',160),
            'label'=>tracky_v278_agent_context_text($t['temporary_context']['label']??'',160),
            'confidence'=>tracky_v278_agent_context_confidence($t['temporary_context']['confidence']??0),
            'durable_site'=>false,'site_authority'=>false,
          ]:null,
          'offline_since'=>$t['offline_since']??null,
        ];
    }

    $conflicts=[];
    foreach(array_slice(is_array($input['location_conflicts']??null)?$input['location_conflicts']:[],0,8) as $item){
        if(!is_array($item))continue;
        $conflicts[]=[
          'site_id'=>tracky_v278_agent_context_uuid($item['site_id']??'','conflict site id'),
          'member_ref'=>tracky_v278_agent_context_text($item['member_ref']??'',320),
          'location_ref'=>tracky_v278_agent_context_text($item['location_ref']??'',320),
          'confidence'=>tracky_v278_agent_context_confidence($item['confidence']??0),
          'observed_at'=>max(0,(int)($item['observed_at']??0)),
          'why'=>tracky_v278_agent_context_text($item['why']??'',80),
        ];
    }

    $changes=[];
    foreach(array_slice(is_array($input['changed_elsewhere']??null)?$input['changed_elsewhere']:[],0,12) as $item){
        if(!is_array($item))continue;
        $changes[]=[
          'site_id'=>tracky_v278_agent_context_uuid($item['site_id']??'','changed site id'),
          'label'=>tracky_v278_agent_context_text($item['label']??'',120),
          'from_revision'=>max(0,(int)($item['from_revision']??0)),
          'to_revision'=>max(0,(int)($item['to_revision']??0)),
          'freshness'=>tracky_v278_agent_context_text($item['freshness']??'unknown',40),
          'sync_status'=>tracky_v278_agent_context_text($item['sync_status']??'unknown',40),
          'recent_changes'=>array_values(array_filter(array_map(
            static fn($v)=>tracky_v278_agent_context_text($v,240),
            array_slice(is_array($item['recent_changes']??null)?$item['recent_changes']:[],0,5)
          ),static fn($v)=>$v!==''))
        ];
    }

    $sites=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,32) as $item){
        if(is_array($item))$sites[]=tracky_v278_agent_context_site_summary($item);
    }

    $siteRevisions=[];
    foreach((array)($input['site_revisions']??[]) as $siteId=>$revisionValue){
        $siteRevisions[tracky_v278_agent_context_uuid($siteId,'site revision key')]=max(0,(int)$revisionValue);
    }

    $syncVisibility=null;
    if(is_array($input['federation_sync_visibility']??null)){
        $sv=$input['federation_sync_visibility'];
        $state=strtolower(tracky_v278_agent_context_text($sv['state']??'unknown',24));
        if(!in_array($state,['unknown','current','suspect','partitioned','reconciling','stale','failed'],true))$state='unknown';
        $alerts=[];
        foreach(array_slice(is_array($sv['alerts']??null)?$sv['alerts']:[],0,16) as $item){
            if(!is_array($item))continue;
            $alerts[]=[
              'site_id'=>tracky_v278_agent_context_uuid($item['site_id']??'','sync visibility alert site id'),
              'label'=>tracky_v278_agent_context_text($item['label']??'',120),
              'status'=>tracky_v278_agent_context_text($item['status']??'unknown',24),
              'severity'=>tracky_v278_agent_context_text($item['severity']??'degraded',24),
              'message'=>tracky_v278_agent_context_text($item['message']??'',500),
              'stale_age_ms'=>max(0,(int)($item['stale_age_ms']??0)),
              'revision_gap'=>max(0,(int)($item['revision_gap']??0)),
              'retry_count'=>max(0,(int)($item['retry_count']??0)),
              'last_error'=>tracky_v278_agent_context_text($item['last_error']??'',240),
            ];
        }
        $syncVisibility=[
          'protocol'=>tracky_v278_agent_context_text($sv['protocol']??'',100),
          'state'=>$state,
          'summary'=>tracky_v278_agent_context_text($sv['summary']??'',1000),
          'alerts'=>$alerts,
          'cloud_can_mark_destination_current'=>false,
          'no_remote_authority_promotion'=>true,
        ];
    }

    $accessOperations=null;
    if(is_array($input['federation_access_operations']??null)){
        $fa=$input['federation_access_operations'];
        $accessOperations=[
          'protocol'=>tracky_v278_agent_context_text($fa['protocol']??'',100),
          'policy_revision'=>max(0,(int)($fa['policy_revision']??0)),
          'revocation_epoch'=>max(0,(int)($fa['revocation_epoch']??0)),
          'active_revocations'=>max(0,(int)($fa['active_revocations']??0)),
          'stale_grants_suppressed'=>max(0,(int)($fa['stale_grants_suppressed']??0)),
          'summary'=>tracky_v278_agent_context_text($fa['summary']??'',1000),
          'revocation_wins'=>true,
          'cloud_read_only'=>true,
        ];
    }

    $explain=is_array($input['explainability']??null)?$input['explainability']:[];
    $out=[
      'protocol'=>VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278,
      'schema_version'=>1,'revision'=>$revision,'fingerprint'=>$fingerprint,
      'generated_at'=>max(0,(int)($input['generated_at']??0)),
      'agent_state'=>$agentState,'physical_state'=>$physicalState,'local_site_id'=>$localSite,
      'current_site'=>$currentSite,'location_conflicts'=>$conflicts,
      'authority'=>[
        'site_id'=>$authoritySite,'device_id'=>$authorityDevice,'epoch'=>$authorityEpoch,
        'basis'=>tracky_v278_agent_context_text($authority['basis']??'',80),
      ],
      'focus_identity'=>$focus,'active_mobile_transition'=>$transition,
      'changed_elsewhere'=>$changes,'sites'=>$sites,'site_revisions'=>$siteRevisions,
      'federation_sync_visibility'=>$syncVisibility,
      'federation_access_operations'=>$accessOperations,
      'explainability'=>[
        'location_candidate_count'=>max(0,(int)($explain['location_candidate_count']??0)),
        'current_location_selected'=>!empty($explain['current_location_selected']),
        'conflict_count'=>max(0,(int)($explain['conflict_count']??0)),
        'reconciliation_state'=>tracky_v278_agent_context_text($explain['reconciliation_state']??'current',40),
        'no_location_invention'=>!empty($explain['no_location_invention']),
        'focus_selection'=>tracky_v278_agent_context_text($explain['focus_selection']??'',80),
        'mobile_transition_binding'=>tracky_v278_agent_context_text($explain['mobile_transition_binding']??'',80),
      ],
      'semantic_only'=>true,'summary_only'=>true,'authority_assignment'=>'local_only',
      'cloud_read_only'=>true,'context_mutation_authority'=>false,'site_authority_mutation'=>false,
    ];
    $semantic=$out;
    unset($semantic['generated_at'],$semantic['summary_only']);
    $out['semantic_hash']=hash('sha256',tracky_cloud_v270_json($semantic));
    return $out;
}

function tracky_v278_agent_context_authority(PDO $pdo,int $userId,string $reportingSiteId,string $siteId): array
{
    $report=tracky_v278_report($pdo,$userId,$reportingSiteId);
    $topology=is_array($report['topology']??null)?$report['topology']:[];
    foreach((array)($topology['sites']??[]) as $site){
        if(!is_array($site)||($site['id']??'')!==$siteId)continue;
        return [
          'device_id'=>(string)($site['authority_device_id']??''),
          'epoch'=>(int)($site['authority_epoch']??0),
          'status'=>(string)($site['status']??'active'),
        ];
    }
    throw new RuntimeException('Tracky federated Agent context authority site is not present in the topology mirror.');
}

function tracky_v278_agent_context_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    if(!tracky_v278_agent_context_schema_ready($pdo))tracky_v278_agent_context_ensure_schema($pdo);
    $context=tracky_v278_agent_context_normalize($input);

    if($context['authority']['site_id']!==''){
        $authority=tracky_v278_agent_context_authority(
            $pdo,$userId,$reportingSiteId,$context['authority']['site_id']
        );
        if($authority['status']!=='active'
          ||$authority['device_id']!==$context['authority']['device_id']
          ||$authority['epoch']!==$context['authority']['epoch']){
            throw new RuntimeException('Tracky federated Agent context authority does not match the current topology mirror.');
        }
    }

    $q=$pdo->prepare('SELECT revision,source_fingerprint,semantic_hash FROM tracky_cloud_federated_agent_context WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&$context['revision']<(int)$prior['revision']){
        return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    }
    if($prior&&$context['revision']===(int)$prior['revision']){
        if(!hash_equals((string)$prior['semantic_hash'],$context['semantic_hash'])
          ||!hash_equals((string)$prior['source_fingerprint'],$context['fingerprint'])){
            throw new RuntimeException('Tracky federated Agent context revision conflicts with the existing Cloud mirror.');
        }
        return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
    }

    $json=tracky_cloud_v270_json($context);
    $focus=$context['focus_identity']['canonical_identity_id']??null;
    $authoritySite=$context['authority']['site_id']!==''?$context['authority']['site_id']:null;
    $authorityDevice=$context['authority']['device_id']!==''?$context['authority']['device_id']:null;
    $currentSite=is_array($context['current_site']??null)?($context['current_site']['site_id']??null):null;

    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federated_agent_context(
      user_id,reporting_site_id,local_site_uuid,revision,source_fingerprint,semantic_hash,
      agent_state,physical_state,focus_identity_uuid,authority_site_uuid,authority_device_uuid,
      authority_epoch,current_site_uuid,context_json,generated_at_ms
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      local_site_uuid=VALUES(local_site_uuid),revision=VALUES(revision),
      source_fingerprint=VALUES(source_fingerprint),semantic_hash=VALUES(semantic_hash),
      agent_state=VALUES(agent_state),physical_state=VALUES(physical_state),
      focus_identity_uuid=VALUES(focus_identity_uuid),authority_site_uuid=VALUES(authority_site_uuid),
      authority_device_uuid=VALUES(authority_device_uuid),authority_epoch=VALUES(authority_epoch),
      current_site_uuid=VALUES(current_site_uuid),context_json=VALUES(context_json),
      generated_at_ms=VALUES(generated_at_ms),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([
      $userId,$reportingSiteId,$context['local_site_id'],$context['revision'],$context['fingerprint'],
      $context['semantic_hash'],$context['agent_state'],$context['physical_state'],$focus,
      $authoritySite,$authorityDevice,$context['authority']['epoch'],$currentSite,$json,$context['generated_at']
    ]);

    $history=$pdo->prepare("INSERT IGNORE INTO tracky_cloud_federated_agent_context_history(
      user_id,reporting_site_id,revision,source_fingerprint,semantic_hash,agent_state,
      physical_state,context_json,generated_at_ms
    ) VALUES (?,?,?,?,?,?,?,?,?)");
    $history->execute([
      $userId,$reportingSiteId,$context['revision'],$context['fingerprint'],$context['semantic_hash'],
      $context['agent_state'],$context['physical_state'],$json,$context['generated_at']
    ]);

    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}

function tracky_v278_agent_context_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278];
    if(!tracky_v278_agent_context_schema_ready($pdo))tracky_v278_agent_context_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT reporting_site_id,context_json,updated_at FROM tracky_cloud_federated_agent_context WHERE user_id=? ORDER BY updated_at DESC,reporting_site_id');
    $q->execute([$userId]);
    $snapshots=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['context_json']??''),true);
        if(!is_array($decoded))continue;
        $snapshots[]=[
          'reporting_site_id'=>(string)$row['reporting_site_id'],
          'updated_at'=>(string)$row['updated_at'],
          'context'=>$decoded,
        ];
    }
    return [
      'available'=>!empty($snapshots),
      'protocol'=>VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278,
      'snapshots'=>$snapshots,
      'preferred_context'=>$snapshots[0]['context']??null,
      'derived_only'=>true,'cloud_role'=>'mirror_only','cloud_read_only'=>true,
      'context_mutation_authority'=>false,'site_authority_mutation'=>false,
    ];
}

function tracky_v278_agent_context_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278,
      'derived_only'=>true,'cloud_role'=>'mirror_only','cloud_read_only'=>true,
      'context_mutation_authority'=>false,'site_authority_mutation'=>false,
      'no_location_invention'=>true,
      'states'=>['current','reconciling','stale','failed'],
    ];
}
