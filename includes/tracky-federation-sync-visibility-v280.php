<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 5 — Cloud reconciliation/sync visibility mirror.
 * Cloud stores HomeServer-reported semantic operational state. It never
 * independently declares a remote site current or changes authority.
 */
const VP3_TRACKY_SYNC_VISIBILITY_V280='vp3-tracky-sync-visibility-v280-20260928';
const VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280='physical_federation_sync_visibility.v1';

function tracky_v280_syncv_text(mixed $value,int $max=200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_syncv_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federation_sync_visibility'):false;
}

function tracky_v280_syncv_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_sync_visibility (
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      local_site_uuid CHAR(36) NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      semantic_hash CHAR(64) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,reporting_site_id),
      INDEX idx_tracky_syncv_user_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_syncv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v280_syncv_site(array $row): array
{
    $status=strtolower(tracky_v280_syncv_text($row['status']??'unknown',24));
    if(!in_array($status,['unknown','current','suspect','partitioned','reconciling','stale','failed'],true))$status='unknown';
    $site=tracky_v278_world_uuid($row['site_id']??'','sync visibility site id');
    $localCursor=is_array($row['local_cursor']??null)?$row['local_cursor']:[];
    $remoteCursor=is_array($row['remote_cursor']??null)?$row['remote_cursor']:[];
    $catchup=is_array($row['catch_up']??null)?$row['catch_up']:[];
    $localRev=max(0,(int)($localCursor['revision']??0));
    $remoteRev=max(0,(int)($remoteCursor['revision']??0));
    $localEpoch=max(0,(int)($localCursor['authority_epoch']??0));
    $remoteEpoch=max(0,(int)($remoteCursor['authority_epoch']??0));
    $localFp=tracky_v280_syncv_text($localCursor['fingerprint']??'',128);
    $remoteFp=tracky_v280_syncv_text($remoteCursor['fingerprint']??'',128);
    $fingerprintConflict=$localRev>0&&$localRev===$remoteRev&&$localFp!==''&&$remoteFp!==''&&!hash_equals($localFp,$remoteFp);
    $epochMismatch=$localEpoch>0&&$remoteEpoch>0&&$localEpoch!==$remoteEpoch;
    $fresh=!empty($row['fresh']);
    if($status!=='current'&&empty($row['is_local']))$fresh=false;
    return [
      'site_id'=>$site,'label'=>tracky_v280_syncv_text($row['label']??$site,160),
      'is_local'=>!empty($row['is_local']),'status'=>$status,'fresh'=>$fresh,
      'reconciliation_required'=>!empty($row['reconciliation_required']),
      'stale_since'=>max(0,(int)($row['stale_since']??0)),'stale_age_ms'=>max(0,(int)($row['stale_age_ms']??0)),
      'partitioned_at'=>max(0,(int)($row['partitioned_at']??0)),'partition_age_ms'=>max(0,(int)($row['partition_age_ms']??0)),
      'reconciling_since'=>max(0,(int)($row['reconciling_since']??0)),'reconciling_age_ms'=>max(0,(int)($row['reconciling_age_ms']??0)),
      'last_contact_at'=>max(0,(int)($row['last_contact_at']??0)),
      'contact_age_ms'=>isset($row['contact_age_ms'])?max(0,(int)$row['contact_age_ms']):null,
      'last_reconciled_at'=>max(0,(int)($row['last_reconciled_at']??0)),
      'retry_count'=>max(0,(int)($row['retry_count']??0)),'next_retry_at'=>max(0,(int)($row['next_retry_at']??0)),
      'retry_due_in_ms'=>max(0,(int)($row['retry_due_in_ms']??0)),
      'last_error'=>tracky_v280_syncv_text($row['last_error']??'',240),
      'local_cursor'=>['revision'=>$localRev,'fingerprint'=>$localFp,'authority_epoch'=>$localEpoch],
      'remote_cursor'=>['revision'=>$remoteRev,'fingerprint'=>$remoteFp,'authority_epoch'=>$remoteEpoch],
      'revision_gap'=>max(0,$remoteRev-$localRev),
      'authority_epoch_mismatch'=>$epochMismatch,
      'fingerprint_conflict'=>$fingerprintConflict,
      'catch_up'=>[
        'applied_revision'=>max(0,(int)($catchup['applied_revision']??$localRev)),
        'target_revision'=>max(0,(int)($catchup['target_revision']??max($localRev,$remoteRev))),
        'remaining_revisions'=>max(0,(int)($catchup['remaining_revisions']??max(0,$remoteRev-$localRev))),
        'progress'=>max(0.0,min(1.0,(float)($catchup['progress']??0))),
      ],
      'conflict_code'=>tracky_v280_syncv_text($row['conflict_code']??'',120),
      'message'=>tracky_v280_syncv_text($row['message']??'',500),
      'authority_assignment'=>'origin_only','remote_authority_promotion'=>false,
    ];
}

function tracky_v280_syncv_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federation_sync_visibility');
    if((string)($input['protocol']??'')!==VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280){
        throw new RuntimeException('Tracky federation sync visibility protocol is unsupported.');
    }
    if(($input['authority_assignment']??'origin_only')!=='origin_only'){
        throw new RuntimeException('Tracky sync visibility cannot change origin-site authority.');
    }
    if(!empty($input['cloud_can_mark_destination_current'])||!empty($input['authority_mutation'])){
        throw new RuntimeException('Tracky Cloud cannot mark a destination current or mutate federation authority.');
    }
    if(isset($input['read_only'])&&!$input['read_only'])throw new RuntimeException('Tracky sync visibility must remain read-only.');
    $local=tracky_v278_world_uuid($input['local_site_id']??'','sync visibility local site id');
    $sites=[];$seen=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,128) as $row){
        if(!is_array($row))continue;
        $normalized=tracky_v280_syncv_site($row);
        if(isset($seen[$normalized['site_id']]))throw new RuntimeException('Tracky sync visibility site is duplicated.');
        $seen[$normalized['site_id']]=true;$sites[]=$normalized;
    }
    $runs=[];
    foreach(array_slice(is_array($input['reconciliation_runs']??null)?$input['reconciliation_runs']:[],0,100) as $row){
        if(!is_array($row))continue;
        $siteId=tracky_v278_world_uuid($row['site_id']??'','reconciliation run site id');
        $runs[]=[
          'reconciliation_id'=>tracky_v280_syncv_text($row['reconciliation_id']??'',180),
          'site_id'=>$siteId,'site_label'=>tracky_v280_syncv_text($row['site_label']??$siteId,160),
          'status'=>strtolower(tracky_v280_syncv_text($row['status']??'unknown',30)),
          'request_mode'=>strtolower(tracky_v280_syncv_text($row['request_mode']??'unknown',30)),
          'reason'=>tracky_v280_syncv_text($row['reason']??'',160),
          'local_revision'=>max(0,(int)($row['local_revision']??0)),
          'remote_revision'=>max(0,(int)($row['remote_revision']??0)),
          'applied_revision'=>max(0,(int)($row['applied_revision']??0)),
          'authority_epoch'=>max(0,(int)($row['authority_epoch']??0)),
          'started_at'=>max(0,(int)($row['started_at']??0)),
          'completed_at'=>max(0,(int)($row['completed_at']??0)),
          'fingerprint_conflict'=>!empty($row['fingerprint_conflict']),
          'authority_epoch_changed'=>!empty($row['authority_epoch_changed']),
          'immutable'=>true,
        ];
    }
    $alerts=[];
    $agent=is_array($input['agent_context']??null)?$input['agent_context']:[];
    foreach(array_slice(is_array($agent['alerts']??null)?$agent['alerts']:[],0,16) as $row){
        if(!is_array($row))continue;
        $alerts[]=[
          'site_id'=>tracky_v278_world_uuid($row['site_id']??'','sync alert site id'),
          'label'=>tracky_v280_syncv_text($row['label']??'',160),
          'status'=>strtolower(tracky_v280_syncv_text($row['status']??'unknown',24)),
          'severity'=>strtolower(tracky_v280_syncv_text($row['severity']??'degraded',24)),
          'message'=>tracky_v280_syncv_text($row['message']??'',500),
          'stale_age_ms'=>max(0,(int)($row['stale_age_ms']??0)),
          'revision_gap'=>max(0,(int)($row['revision_gap']??0)),
          'retry_count'=>max(0,(int)($row['retry_count']??0)),
          'last_error'=>tracky_v280_syncv_text($row['last_error']??'',240),
        ];
    }
    return [
      'protocol'=>VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??0)),'local_site_id'=>$local,
      'overall_state'=>strtolower(tracky_v280_syncv_text($input['overall_state']??'unknown',24)),
      'counts'=>[
        'sites'=>count($sites),
        'current'=>count(array_filter($sites,static fn($s)=>$s['status']==='current')),
        'stale'=>count(array_filter($sites,static fn($s)=>in_array($s['status'],['stale','suspect','unknown'],true))),
        'partitioned'=>count(array_filter($sites,static fn($s)=>$s['status']==='partitioned')),
        'reconciling'=>count(array_filter($sites,static fn($s)=>$s['status']==='reconciling')),
        'failed'=>count(array_filter($sites,static fn($s)=>$s['status']==='failed')),
      ],
      'sites'=>$sites,'reconciliation_runs'=>$runs,
      'transition_sync_hints'=>array_slice(is_array($input['transition_sync_hints']??null)?$input['transition_sync_hints']:[],0,64),
      'agent_context'=>[
        'state'=>strtolower(tracky_v280_syncv_text($agent['state']??'unknown',24)),
        'alerts'=>$alerts,'summary'=>tracky_v280_syncv_text($agent['summary']??'',1000),
        'no_remote_authority_promotion'=>true,'remote_freshness_requires_origin_confirmation'=>true,
      ],
      'read_only'=>true,'semantic_only'=>true,'authority_assignment'=>'origin_only',
      'cloud_role'=>'relay_and_mirror_only','cloud_can_mark_destination_current'=>false,
      'summary_only'=>true,'cloud_read_only'=>true,'authority_mutation'=>false,
    ];
}

function tracky_v280_syncv_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if(!tracky_v280_syncv_schema_ready($pdo))tracky_v280_syncv_ensure_schema($pdo);
    $snapshot=tracky_v280_syncv_normalize($input);
    if($snapshot['local_site_id']!==$reportingSiteId){
        throw new RuntimeException('Tracky sync visibility local site must match the uploader federation site.');
    }
    $json=tracky_cloud_v270_json($snapshot);
    $hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federation_sync_visibility WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms']){
        return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    }
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky sync visibility timestamp conflicts with the existing Cloud mirror.');
    }
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_sync_visibility(
      user_id,reporting_site_id,local_site_uuid,generated_at_ms,semantic_hash,snapshot_json
    ) VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),
      semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$hash,$json]);
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}

function tracky_v280_syncv_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280];
    tracky_v280_syncv_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federation_sync_visibility WHERE user_id=? ORDER BY updated_at DESC,reporting_site_id');
    $q->execute([$userId]);$snapshots=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['snapshot_json']??''),true);
        if(!is_array($decoded))continue;
        $snapshots[]=['reporting_site_id'=>(string)$row['reporting_site_id'],'updated_at'=>(string)$row['updated_at'],'visibility'=>$decoded];
    }
    return [
      'available'=>!empty($snapshots),'protocol'=>VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280,
      'snapshots'=>$snapshots,'preferred_visibility'=>$snapshots[0]['visibility']??null,
      'preferred_reporting_site_id'=>$snapshots[0]['reporting_site_id']??'',
      'cloud_role'=>'mirror_only','cloud_read_only'=>true,'cloud_can_mark_destination_current'=>false,
      'authority_mutation'=>false,
    ];
}

function tracky_v280_syncv_annotate_dashboard(array $dashboard,array $visibility): array
{
    $selected=strtolower(tracky_v280_syncv_text($dashboard['selected_site']['site_id']??'',64));
    $site=null;
    foreach((array)($visibility['sites']??[]) as $row){
        if(is_array($row)&&($row['site_id']??'')===$selected){$site=$row;break;}
    }
    $freshness=[
      'site_id'=>$selected,'status'=>(string)($site['status']??'unknown'),'fresh'=>!empty($site['fresh']),
      'stale_age_ms'=>(int)($site['stale_age_ms']??0),
      'reconciliation_required'=>$site?(!empty($site['reconciliation_required'])):true,
      'revision_gap'=>(int)($site['revision_gap']??0),
      'authority_epoch_mismatch'=>!empty($site['authority_epoch_mismatch']),
      'fingerprint_conflict'=>!empty($site['fingerprint_conflict']),
      'message'=>(string)($site['message']??'Cloud has no origin-confirmed federation freshness for this site.'),
      'reported_by_home_server'=>true,'cloud_decision'=>false,
    ];
    $dashboard['federation_freshness']=$freshness;
    if(!isset($dashboard['selected_site'])||!is_array($dashboard['selected_site']))$dashboard['selected_site']=[];
    $dashboard['selected_site']['federation_freshness']=$freshness;
    foreach(['rooms','people','objects','world_devices'] as $key){
        if(!is_array($dashboard[$key]??null))continue;
        foreach($dashboard[$key] as &$item)if(is_array($item))$item['federation_freshness']=$freshness;
        unset($item);
    }
    if(!isset($dashboard['agent_context'])||!is_array($dashboard['agent_context']))$dashboard['agent_context']=[];
    $dashboard['agent_context']['sync_state']=$freshness['status'];
    $dashboard['agent_context']['sync_message']=$freshness['message'];
    $dashboard['agent_context']['remote_freshness_verified']=$freshness['fresh'];
    $dashboard['agent_context']['no_remote_authority_promotion']=true;
    return $dashboard;
}

function tracky_v280_syncv_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_SYNC_VISIBILITY_PROTOCOL_V280,
      'peer_states'=>['unknown','current','suspect','partitioned','reconciling','stale','failed'],
      'revision_gap_visibility'=>true,'authority_epoch_visibility'=>true,
      'fingerprint_conflict_visibility'=>true,'retry_visibility'=>true,
      'immutable_reconciliation_history'=>true,'physical_world_freshness_annotations'=>true,
      'transition_sync_annotations'=>true,'agent_context'=>true,
      'read_only'=>true,'authority_mutation'=>false,'cloud_can_mark_destination_current'=>false,
      'cloud_role'=>'mirror_only',
    ];
}
