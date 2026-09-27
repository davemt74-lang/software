<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 4 — Cloud mobile transition mirror/relay.
 *
 * Source HomeServer remains authoritative. Cloud stores governed semantic
 * transition snapshots and relays them read-only to the explicit destination.
 */
const VP3_TRACKY_MOBILE_TRANSITION_V278='vp3-tracky-mobile-transition-v278-20260927';
const VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278='physical_mobile_transition.v1';

function tracky_v278_mobile_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_mobile_transitions'):false;
}

function tracky_v278_mobile_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_mobile_transitions (
      user_id INT UNSIGNED NOT NULL,
      transition_id VARCHAR(160) NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      source_site_uuid CHAR(36) NOT NULL,
      destination_site_uuid CHAR(36) NULL,
      subject_kind VARCHAR(40) NOT NULL,
      subject_id VARCHAR(160) NOT NULL,
      state VARCHAR(40) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      source_authority_device_uuid CHAR(36) NOT NULL,
      source_authority_epoch BIGINT UNSIGNED NOT NULL,
      confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
      fingerprint CHAR(64) NOT NULL,
      transition_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,transition_id),
      INDEX idx_tracky_mobile_destination (user_id,destination_site_uuid,state,updated_at),
      INDEX idx_tracky_mobile_source (user_id,source_site_uuid,state,updated_at),
      INDEX idx_tracky_mobile_subject (user_id,subject_kind,subject_id,updated_at),
      CONSTRAINT fk_tracky_mobile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_mobile_transition_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      transition_id VARCHAR(160) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      state VARCHAR(40) NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_tracky_mobile_history (user_id,transition_id,revision),
      INDEX idx_tracky_mobile_history_recent (user_id,transition_id,revision),
      CONSTRAINT fk_tracky_mobile_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_mobile_uuid(mixed $value,string $label): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky mobile transition '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_mobile_text(mixed $value,int $max=160,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky mobile transition '.$label.' is required.');
    return $text;
}

function tracky_v278_mobile_id(mixed $value,string $label): string
{
    $text=tracky_v278_mobile_text($value,160,true,$label);
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,159}$/',$text)){
        throw new RuntimeException('Tracky mobile transition '.$label.' is invalid.');
    }
    return $text;
}

function tracky_v278_mobile_confidence(mixed $value): float
{
    return is_numeric($value)?max(0.0,min(1.0,(float)$value)):0.0;
}

function tracky_v278_mobile_temporary_context(mixed $value): ?array
{
    if($value===null||$value===[])return null;
    if(!is_array($value))throw new RuntimeException('Tracky mobile transition temporary context is invalid.');
    tracky_cloud_v270_assert_governed_value($value,'mobile_transition.temporary_context');
    if(!empty($value['site_authority'])||!empty($value['durable_site'])){
        throw new RuntimeException('Temporary mobile context cannot become a site authority or durable site.');
    }
    return [
      'id'=>tracky_v278_mobile_id($value['id']??'temporary-context','temporary context id'),
      'label'=>tracky_v278_mobile_text($value['label']??'Temporary context',160),
      'observed_at'=>max(0,(int)($value['observed_at']??0)),
      'confidence'=>tracky_v278_mobile_confidence($value['confidence']??0),
      'durable_site'=>false,'site_authority'=>false,
    ];
}

function tracky_v278_mobile_transition(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'mobile_transition');
    $allowed=[
      'transition_id','subject_kind','subject_id','subject_scope','source_site_id','destination_site_id',
      'state','previous_state','resume_state','state_reason','confidence','destination_confidence',
      'temporary_context','evidence','revision','identity_linking','authority_scope','origin_role',
      'fingerprint','started_at','state_changed_at','updated_at','arrived_at','canceled_at','offline_since',
      'source_authority_device_id','source_authority_epoch'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky mobile transition contains unsupported field: '.(string)$key);
    }

    $states=['departing','in_transit','arriving','arrived','uncertain','offline','temporary_context','canceled'];
    $subjectKind=strtolower(tracky_v278_mobile_text($input['subject_kind']??'mobile_device',40));
    if(!in_array($subjectKind,['mobile_device','explicit_continuity_subject'],true)){
        throw new RuntimeException('Tracky mobile transition subject kind is unsupported.');
    }
    $subjectId=$subjectKind==='mobile_device'
        ?tracky_v278_mobile_uuid($input['subject_id']??'','mobile device id')
        :tracky_v278_mobile_id($input['subject_id']??'','continuity subject id');
    $subjectScope=$subjectKind==='mobile_device'?'stable_mobile_device':'explicit_continuity_subject';
    if(isset($input['subject_scope'])&&(string)$input['subject_scope']!==$subjectScope){
        throw new RuntimeException('Tracky mobile transition subject scope is inconsistent.');
    }

    $state=strtolower(tracky_v278_mobile_text($input['state']??'',40));
    if(!in_array($state,$states,true))throw new RuntimeException('Tracky mobile transition state is unsupported.');

    $source=tracky_v278_mobile_uuid($input['source_site_id']??'','source site id');
    $destination=tracky_v278_mobile_text($input['destination_site_id']??'',64);
    if($destination!=='')$destination=tracky_v278_mobile_uuid($destination,'destination site id');
    if($destination!==''&&$destination===$source)throw new RuntimeException('Tracky mobile transition destination must differ from source.');

    if(!empty($input['identity_linking']))throw new RuntimeException('Cross-site identity linking is deferred to V2.78 Section 5.');
    if(isset($input['authority_scope'])&&(string)$input['authority_scope']!=='source_site'){
        throw new RuntimeException('Tracky mobile transition authority must remain at the source site.');
    }
    if(isset($input['origin_role'])&&(string)$input['origin_role']!=='local_authority'){
        throw new RuntimeException('Tracky Cloud accepts only source-site authoritative transition uploads.');
    }

    $evidence=is_array($input['evidence']??null)?array_values($input['evidence']):[];
    if(count($evidence)>256)throw new RuntimeException('Tracky mobile transition evidence exceeds the limit.');
    tracky_cloud_v270_assert_governed_value($evidence,'mobile_transition.evidence');

    $authorityDevice=tracky_v278_mobile_uuid($input['source_authority_device_id']??'','source authority device id');
    $authorityEpoch=max(0,(int)($input['source_authority_epoch']??0));
    if($authorityEpoch<1)throw new RuntimeException('Tracky mobile transition source authority epoch is required.');

    $out=[
      'transition_id'=>tracky_v278_mobile_id($input['transition_id']??'','transition id'),
      'subject_kind'=>$subjectKind,'subject_id'=>$subjectId,'subject_scope'=>$subjectScope,
      'source_site_id'=>$source,'destination_site_id'=>$destination,'state'=>$state,
      'previous_state'=>tracky_v278_mobile_text($input['previous_state']??'',40),
      'resume_state'=>tracky_v278_mobile_text($input['resume_state']??'',40),
      'state_reason'=>tracky_v278_mobile_text($input['state_reason']??'',200),
      'confidence'=>tracky_v278_mobile_confidence($input['confidence']??0),
      'destination_confidence'=>tracky_v278_mobile_confidence($input['destination_confidence']??0),
      'temporary_context'=>tracky_v278_mobile_temporary_context($input['temporary_context']??null),
      'evidence'=>$evidence,'revision'=>max(1,(int)($input['revision']??1)),
      'identity_linking'=>false,'authority_scope'=>'source_site','origin_role'=>'local_authority',
      'started_at'=>max(0,(int)($input['started_at']??0)),
      'state_changed_at'=>max(0,(int)($input['state_changed_at']??0)),
      'updated_at'=>max(0,(int)($input['updated_at']??0)),
      'arrived_at'=>isset($input['arrived_at'])&&$input['arrived_at']!==null?(int)$input['arrived_at']:null,
      'canceled_at'=>isset($input['canceled_at'])&&$input['canceled_at']!==null?(int)$input['canceled_at']:null,
      'offline_since'=>isset($input['offline_since'])&&$input['offline_since']!==null?(int)$input['offline_since']:null,
      'source_authority_device_id'=>$authorityDevice,'source_authority_epoch'=>$authorityEpoch,
    ];
    $out['fingerprint']=hash('sha256',tracky_cloud_v270_json($out));
    return $out;
}

function tracky_v278_mobile_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'mobile_transitions');
    $allowed=[
      'protocol','schema_version','generated_at','transitions','active_subjects','boundaries',
      'identity_linking','semantic_only','summary_only','cloud_read_only','authority_assignment',
      'person_object_identity_linking','temporary_context_site_authority','origin_scope'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky mobile transition projection contains unsupported field: '.(string)$key);
    }
    if((string)($input['protocol']??'')!==VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278){
        throw new RuntimeException('Tracky mobile transition protocol is unsupported.');
    }
    if(!empty($input['identity_linking'])||!empty($input['person_object_identity_linking'])){
        throw new RuntimeException('Cross-site identity linking is deferred to V2.78 Section 5.');
    }
    if(!empty($input['temporary_context_site_authority'])){
        throw new RuntimeException('Temporary mobile context cannot grant site authority.');
    }
    if((string)($input['authority_assignment']??'source_site')!=='source_site'){
        throw new RuntimeException('Tracky mobile transition authority must remain at the source site.');
    }

    $transitions=is_array($input['transitions']??null)?array_values($input['transitions']):[];
    if(count($transitions)>256)throw new RuntimeException('Tracky mobile transition projection exceeds the limit.');
    $normalized=[];
    foreach($transitions as $item){
        if(!is_array($item))throw new RuntimeException('Tracky mobile transition item is invalid.');
        $normalized[]=tracky_v278_mobile_transition($item);
    }
    return [
      'protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278,'schema_version'=>1,
      'transitions'=>$normalized,'identity_linking'=>false,'semantic_only'=>true,
      'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'source_site',
      'person_object_identity_linking'=>false,'temporary_context_site_authority'=>false,
    ];
}

function tracky_v278_mobile_authority(PDO $pdo,int $userId,string $reportingSiteId,string $sourceSite): array
{
    $report=tracky_v278_report($pdo,$userId,$reportingSiteId);
    $topology=is_array($report['topology']??null)?$report['topology']:[];
    foreach((array)($topology['sites']??[]) as $site){
        if(!is_array($site)||($site['id']??'')!==$sourceSite)continue;
        return [
          'device_id'=>(string)($site['authority_device_id']??''),
          'epoch'=>(int)($site['authority_epoch']??0),
          'status'=>(string)($site['status']??'active'),
        ];
    }
    throw new RuntimeException('Tracky mobile transition source site is not present in the topology mirror.');
}

function tracky_v278_mobile_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    tracky_v278_mobile_ensure_schema($pdo);
    $projection=tracky_v278_mobile_normalize($input);
    $changed=0;$stale=0;$idempotent=0;

    foreach($projection['transitions'] as $transition){
        $authority=tracky_v278_mobile_authority($pdo,$userId,$reportingSiteId,$transition['source_site_id']);
        if($authority['status']!=='active'
          ||$authority['device_id']!==$transition['source_authority_device_id']
          ||$authority['epoch']!==$transition['source_authority_epoch']){
            throw new RuntimeException('Tracky mobile transition source authority does not match the current topology mirror.');
        }

        $json=tracky_cloud_v270_json($transition);
        $fingerprint=hash('sha256',$json);
        $q=$pdo->prepare('SELECT revision,fingerprint FROM tracky_cloud_mobile_transitions WHERE user_id=? AND transition_id=? LIMIT 1');
        $q->execute([$userId,$transition['transition_id']]);
        $prior=$q->fetch();

        if($prior&&$transition['revision']<(int)$prior['revision']){$stale++;continue;}
        if($prior&&$transition['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['fingerprint'],$fingerprint)){
                throw new RuntimeException('Tracky mobile transition revision conflicts with the existing Cloud mirror.');
            }
            $idempotent++;continue;
        }

        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_mobile_transitions(
          user_id,transition_id,reporting_site_id,source_site_uuid,destination_site_uuid,
          subject_kind,subject_id,state,revision,source_authority_device_uuid,source_authority_epoch,
          confidence,fingerprint,transition_json
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          reporting_site_id=VALUES(reporting_site_id),source_site_uuid=VALUES(source_site_uuid),
          destination_site_uuid=VALUES(destination_site_uuid),subject_kind=VALUES(subject_kind),
          subject_id=VALUES(subject_id),state=VALUES(state),revision=VALUES(revision),
          source_authority_device_uuid=VALUES(source_authority_device_uuid),
          source_authority_epoch=VALUES(source_authority_epoch),confidence=VALUES(confidence),
          fingerprint=VALUES(fingerprint),transition_json=VALUES(transition_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
          $userId,$transition['transition_id'],$reportingSiteId,$transition['source_site_id'],
          $transition['destination_site_id']!==''?$transition['destination_site_id']:null,
          $transition['subject_kind'],$transition['subject_id'],$transition['state'],$transition['revision'],
          $transition['source_authority_device_id'],$transition['source_authority_epoch'],
          $transition['confidence'],$fingerprint,$json
        ]);

        $history=$pdo->prepare("INSERT IGNORE INTO tracky_cloud_mobile_transition_history
          (user_id,transition_id,revision,state,fingerprint,snapshot_json)
          VALUES (?,?,?,?,?,?)");
        $history->execute([$userId,$transition['transition_id'],$transition['revision'],$transition['state'],$fingerprint,$json]);
        $changed++;
    }

    return ['accepted'=>true,'changed'=>$changed,'stale'=>$stale,'idempotent'=>$idempotent];
}

function tracky_v278_mobile_build_relay(PDO $pdo,int $userId,array $federationRequest): array
{
    tracky_v278_mobile_ensure_schema($pdo);
    $request=tracky_v278_sync_normalize($federationRequest);
    if(!$request['available']){
        return [
          'protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278,'schema_version'=>1,
          'transitions'=>[],'identity_linking'=>false,'semantic_only'=>true,
          'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'source_site',
          'person_object_identity_linking'=>false,'temporary_context_site_authority'=>false,
        ];
    }

    $destination=$request['local_site_id'];
    $q=$pdo->prepare("SELECT * FROM tracky_cloud_mobile_transitions
      WHERE user_id=? AND destination_site_uuid=?
      ORDER BY updated_at DESC,transition_id
      LIMIT 128");
    $q->execute([$userId,$destination]);
    $transitions=[];

    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['transition_json']??''),true);
        if(!is_array($decoded))continue;
        $reportingSite=(string)($row['reporting_site_id']??'');
        if($reportingSite==='')continue;
        $authority=tracky_v278_mobile_authority($pdo,$userId,$reportingSite,(string)$row['source_site_uuid']);
        if($authority['status']!=='active'
          ||$authority['device_id']!==(string)$row['source_authority_device_uuid']
          ||$authority['epoch']!==(int)$row['source_authority_epoch']){
            continue;
        }
        $decoded['origin_role']='local_authority';
        $decoded['authority_scope']='source_site';
        $decoded['identity_linking']=false;
        $transitions[]=$decoded;
    }

    return [
      'protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278,'schema_version'=>1,
      'transitions'=>$transitions,'identity_linking'=>false,'semantic_only'=>true,
      'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'source_site',
      'person_object_identity_linking'=>false,'temporary_context_site_authority'=>false,
      'destination_site_id'=>$destination,'cloud_role'=>'mirror_relay_only',
    ];
}

function tracky_v278_mobile_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278];
    tracky_v278_mobile_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT * FROM tracky_cloud_mobile_transitions WHERE user_id=? ORDER BY updated_at DESC,transition_id');
    $q->execute([$userId]);
    $rows=$q->fetchAll()?:[];
    $items=[];
    foreach($rows as $row){
        $decoded=json_decode((string)($row['transition_json']??''),true);
        if(is_array($decoded))$items[]=$decoded;
    }
    return [
      'available'=>!empty($items),'protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278,
      'transitions'=>$items,'identity_linking'=>false,'cloud_read_only'=>true,
      'authority_assignment'=>'source_site','cloud_role'=>'mirror_relay_only',
      'person_object_identity_linking'=>false,'temporary_context_site_authority'=>false,
    ];
}

function tracky_v278_mobile_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278,
      'cloud_role'=>'mirror_relay_only','authority_assignment'=>'source_site',
      'world_mutation_authority'=>false,'person_object_identity_linking'=>false,
      'temporary_context_site_authority'=>false,
      'states'=>['departing','in_transit','arriving','arrived','uncertain','offline','temporary_context','canceled'],
    ];
}
