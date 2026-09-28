<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 3 — cross-site federation relay.
 *
 * Cloud transports already-validated authoritative site fragments. It never
 * assigns authority, resolves semantic conflicts, or rewrites source fragments.
 */
const VP3_TRACKY_FEDERATION_SYNC_V278='vp3-tracky-federation-sync-v278-20260927';
const VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278='physical_federation_sync.v1';

function tracky_v278_sync_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federation_relay_state'):false;
}

function tracky_v278_sync_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_relay_state (
      user_id INT UNSIGNED NOT NULL,
      destination_site_uuid CHAR(36) NOT NULL,
      source_site_uuid CHAR(36) NOT NULL,
      last_delivered_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_delivered_fingerprint CHAR(64) NOT NULL DEFAULT '',
      delivery_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_delivered_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,destination_site_uuid,source_site_uuid),
      INDEX idx_tracky_federation_relay_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_federation_relay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_sync_uuid(mixed $value,string $label): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky federation sync '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_sync_text(mixed $value,int $max=160): string
{
    return mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
}

function tracky_v278_sync_normalize(array $input): array
{
    $allowed=['protocol','schema_version','available','local_site_id','received_cursors','max_envelopes','authority_assignment'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky federation sync request contains unsupported field: '.(string)$key);
    }
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278){
        throw new RuntimeException('Tracky federation sync protocol is unsupported.');
    }
    if((string)($input['authority_assignment']??'local_only')!=='local_only'){
        throw new RuntimeException('Tracky federation authority must remain local.');
    }
    $available=!empty($input['available']);
    $site=$available?tracky_v278_sync_uuid($input['local_site_id']??'','local site id'):'';
    $cursors=is_array($input['received_cursors']??null)?array_values($input['received_cursors']):[];
    if(count($cursors)>128)throw new RuntimeException('Tracky federation receive cursor list exceeds the limit.');
    $normalized=[];$seen=[];
    foreach($cursors as $cursor){
        if(!is_array($cursor))throw new RuntimeException('Tracky federation receive cursor is invalid.');
        $siteId=tracky_v278_sync_uuid($cursor['site_id']??'','cursor site id');
        if(isset($seen[$siteId]))throw new RuntimeException('Tracky federation receive cursor is duplicated.');
        $seen[$siteId]=true;
        $normalized[]=[
          'site_id'=>$siteId,
          'revision'=>max(0,(int)($cursor['revision']??0)),
          'fingerprint'=>tracky_v278_sync_text($cursor['fingerprint']??'',128),
          'authority_epoch'=>max(0,(int)($cursor['authority_epoch']??0)),
        ];
    }
    return [
      'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,
      'schema_version'=>1,
      'available'=>$available,
      'local_site_id'=>$site,
      'received_cursors'=>$normalized,
      'max_envelopes'=>max(1,min(64,(int)($input['max_envelopes']??32))),
      'authority_assignment'=>'local_only',
    ];
}

function tracky_v278_sync_topology_site(array $topology,string $siteId): ?array
{
    foreach((array)($topology['sites']??[]) as $site){
        if(is_array($site)&&($site['id']??'')===$siteId)return $site;
    }
    return null;
}

function tracky_v278_sync_peer_allowed(array $topology,string $left,string $right): bool
{
    if($left===$right)return true;
    foreach((array)($topology['relationships']??[]) as $rel){
        if(!is_array($rel))continue;
        $type=(string)($rel['type']??'');
        if(!in_array($type,['peers_with','bridges_to'],true))continue;
        $subject=(string)($rel['subject_id']??'');
        $object=(string)($rel['object_id']??'');
        if(($subject===$left&&$object===$right)||($subject===$right&&$object===$left))return true;
    }
    return false;
}

function tracky_v278_sync_authority_matches(PDO $pdo,int $userId,array $fragmentRow,array $fragment): bool
{
    $reportingSite=(string)($fragmentRow['reporting_site_id']??'');
    if($reportingSite==='')return false;
    $report=tracky_v278_report($pdo,$userId,$reportingSite);
    $topology=is_array($report['topology']??null)?$report['topology']:[];
    $site=tracky_v278_sync_topology_site($topology,(string)($fragment['site_id']??''));
    if(!$site)return false;
    return (string)($site['authority_device_id']??'')===(string)($fragment['authority_device_id']??'')
        && (int)($site['authority_epoch']??0)===(int)($fragment['authority_epoch']??0)
        && (string)($site['status']??'active')==='active';
}

function tracky_v278_sync_build_relay(PDO $pdo,int $userId,string $reportingSiteId,array $request): array
{
    tracky_v278_sync_ensure_schema($pdo);
    $request=tracky_v278_sync_normalize($request);
    if(!$request['available']){
        return [
          'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,'schema_version'=>1,
          'available'=>false,'destination_site_id'=>'','topology_revision'=>0,
          'envelopes'=>[],'cloud_role'=>'relay_only','authority_assignment'=>'local_only',
        ];
    }

    $topologyReport=tracky_v278_report($pdo,$userId,$reportingSiteId);
    $topology=is_array($topologyReport['topology']??null)?$topologyReport['topology']:[];
    $destination=$request['local_site_id'];
    $destinationSite=tracky_v278_sync_topology_site($topology,$destination);
    if(!$destinationSite)throw new RuntimeException('Tracky federation local site is not present in the HomeServer topology mirror.');
    if((string)($destinationSite['status']??'active')!=='active')throw new RuntimeException('Tracky federation local site is not active.');

    $cursorMap=[];
    foreach($request['received_cursors'] as $cursor)$cursorMap[$cursor['site_id']]=$cursor;
    $q=$pdo->prepare('SELECT * FROM tracky_cloud_federated_world_fragments WHERE user_id=? ORDER BY site_uuid');
    $q->execute([$userId]);$rows=$q->fetchAll()?:[];
    $envelopes=[];$remoteCursors=[];$max=$request['max_envelopes'];

    foreach($rows as $row){
        if(count($envelopes)>=$max)break;
        $source=(string)($row['site_uuid']??'');
        if($source===''||$source===$destination)continue;
        if(!tracky_v278_sync_peer_allowed($topology,$source,$destination))continue;
        $revision=(int)($row['world_revision']??0);
        $cursorRevision=(int)($cursorMap[$source]['revision']??0);

        $fragment=json_decode((string)($row['fragment_json']??''),true);
        if(!is_array($fragment))continue;
        if((string)($fragment['site_id']??'')!==$source)continue;
        if(!tracky_v278_sync_authority_matches($pdo,$userId,$row,$fragment))continue;
        if(!function_exists('tracky_v278_policy_decision')||!function_exists('tracky_v278_policy_filter_world_fragment'))continue;
        $policyDecision=tracky_v278_policy_decision($pdo,$userId,$source,$destination,'semantic_world_read');
        if(empty($policyDecision['allowed']))continue;
        $fragment=tracky_v278_policy_filter_world_fragment($pdo,$userId,$source,$destination,$fragment);
        if(!is_array($fragment))continue;

        $fingerprint=(string)($fragment['fingerprint']??'');
        if($fingerprint==='')continue;
        $sourceTopology=tracky_v278_report($pdo,$userId,(string)$row['reporting_site_id']);
        $sourceTopologyRevision=(int)($sourceTopology['revision']??($sourceTopology['topology']['revision']??0));
        $authorityEpoch=(int)($fragment['authority_epoch']??0);
        $remoteCursors[]=[
          'site_id'=>$source,
          'revision'=>$revision,
          'fingerprint'=>$fingerprint,
          'authority_epoch'=>$authorityEpoch,
        ];
        if($revision<=$cursorRevision)continue;
        $envelope=[
          'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,
          'schema_version'=>1,
          'envelope_id'=>'fed:'.$source.':'.$authorityEpoch.':'.$revision.':'.substr($fingerprint,0,24),
          'source_site_id'=>$source,
          'destination_site_id'=>$destination,
          'source_authority_device_id'=>(string)($fragment['authority_device_id']??''),
          'source_authority_epoch'=>$authorityEpoch,
          'source_world_revision'=>$revision,
          'source_fingerprint'=>$fingerprint,
          'topology_revision'=>$sourceTopologyRevision,
          'policy'=>[
            'protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278,
            'scope'=>'semantic_world_read',
            'grant_revision'=>(int)($policyDecision['grant_revision']??0),
            'policy_revision'=>(int)($policyDecision['policy_revision']??0),
            'revocation_epoch'=>(int)($policyDecision['revocation_epoch']??0),
            'world_projection'=>'non_person_v1',
          ],
          'emitted_at'=>gmdate(DATE_ATOM),
          'fragment'=>$fragment,
        ];
        $envelopes[]=$envelope;

        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_relay_state
          (user_id,destination_site_uuid,source_site_uuid,last_delivered_revision,last_delivered_fingerprint,delivery_count,last_delivered_at)
          VALUES (?,?,?,?,?,1,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE
            last_delivered_revision=GREATEST(last_delivered_revision,VALUES(last_delivered_revision)),
            last_delivered_fingerprint=IF(VALUES(last_delivered_revision)>=last_delivered_revision,VALUES(last_delivered_fingerprint),last_delivered_fingerprint),
            delivery_count=delivery_count+1,last_delivered_at=UTC_TIMESTAMP(),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,$destination,$source,$revision,$fingerprint]);
    }

    return [
      'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,
      'schema_version'=>1,
      'available'=>true,
      'destination_site_id'=>$destination,
      'topology_revision'=>(int)($topologyReport['revision']??($topology['revision']??0)),
      'emitted_at'=>gmdate(DATE_ATOM),
      'envelopes'=>$envelopes,
      'reconciliation'=>tracky_v278_reconciliation_payload($remoteCursors),
      'cloud_role'=>'relay_only',
      'authority_assignment'=>'local_only',
    ];
}

function tracky_v278_sync_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278];
    tracky_v278_sync_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT * FROM tracky_cloud_federation_relay_state WHERE user_id=? ORDER BY destination_site_uuid,source_site_uuid');
    $q->execute([$userId]);
    return [
      'available'=>true,
      'protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,
      'relay_state'=>$q->fetchAll()?:[],
      'cloud_role'=>'relay_only',
      'authority_assignment'=>'local_only',
      'conflict_resolution'=>'origin_authority_or_quarantine',
    ];
}

function tracky_v278_sync_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278,
      'transport'=>'existing_tracky_cloud_sync','cloud_role'=>'relay_only',
      'authority_assignment'=>'local_only','world_mutation_authority'=>false,
      'same_revision_conflicts'=>'quarantine','topology_ahead'=>'hold',
      'cross_site_identity_linking'=>false,
      'reconciliation_protocol'=>VP3_TRACKY_FEDERATION_RECONCILIATION_PROTOCOL_V278,
      'partition_recovery'=>'authoritative_full_semantic_snapshot',
      'destination_decides_freshness'=>true,
    ];
}
