<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 2 — read-only federated physical world mirror.
 * Cloud accepts site-scoped semantic fragments only after validating the
 * Section 1 topology authority device + epoch for that site.
 */
const VP3_TRACKY_FEDERATED_WORLD_V278='vp3-tracky-federated-world-v278-20260927';
const VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278='physical_federated_world.v1';

function tracky_v278_world_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federated_world_fragments'):false;
}

function tracky_v278_world_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federated_world_fragments (
      user_id INT UNSIGNED NOT NULL,
      site_uuid CHAR(36) NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      authority_device_uuid CHAR(36) NOT NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL,
      topology_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      world_revision BIGINT UNSIGNED NOT NULL,
      observed_at VARCHAR(64) NOT NULL DEFAULT '',
      entity_count INT UNSIGNED NOT NULL DEFAULT 0,
      relation_count INT UNSIGNED NOT NULL DEFAULT 0,
      fragment_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_uuid),
      INDEX idx_tracky_federated_world_reporter (user_id,reporting_site_id),
      INDEX idx_tracky_federated_world_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_federated_world_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_world_uuid(mixed $value,string $label): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky federated world '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_world_text(mixed $value,int $max=160,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky federated world '.$label.' is required.');
    return $text;
}

function tracky_v278_world_token(mixed $value,string $label,string $fallback=''): string
{
    $text=strtolower(tracky_v278_world_text($value?:$fallback,80,true,$label));
    if(!preg_match('/^[a-z][a-z0-9_.:-]{1,79}$/',$text))throw new RuntimeException('Tracky federated world '.$label.' is invalid.');
    return $text;
}

function tracky_v278_world_local_id(mixed $value,string $label): string
{
    $text=tracky_v278_world_text($value,160,true,$label);
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/',$text))throw new RuntimeException('Tracky federated world '.$label.' is invalid.');
    return $text;
}

function tracky_v278_world_ref(string $siteId,string $localId): string
{
    return 'site:'.$siteId.'::'.rawurlencode($localId);
}

function tracky_v278_world_entity(string $siteId,array $input): array
{
    $allowed=['local_id','ref','type','label','state','confidence','observed_at'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky federated world entity contains unsupported field: '.(string)$key);
    }
    $local=tracky_v278_world_local_id($input['local_id']??'','entity local id');
    $state=strtolower(tracky_v278_world_text($input['state']??'unknown',30));
    if(!in_array($state,['observed','inferred','last-known','user-confirmed','contradicted','expired','unknown'],true))$state='unknown';
    $confidence=is_numeric($input['confidence']??null)?max(0.0,min(1.0,(float)$input['confidence'])):0.0;
    return [
      'local_id'=>$local,'ref'=>tracky_v278_world_ref($siteId,$local),
      'type'=>tracky_v278_world_token($input['type']??'entity','entity type','entity'),
      'label'=>tracky_v278_world_text($input['label']??'',160),
      'state'=>$state,'confidence'=>$confidence,
      'observed_at'=>max(0,(int)($input['observed_at']??0)),
    ];
}

function tracky_v278_world_relation(string $siteId,array $input): array
{
    $allowed=['subject_local_id','subject_ref','predicate','object_local_id','object_ref','value','confidence','temporal_state','source_event_id','sequence','as_of'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky federated world relation contains unsupported field: '.(string)$key);
    }
    $subject=tracky_v278_world_local_id($input['subject_local_id']??'','relation subject');
    $object=tracky_v278_world_text($input['object_local_id']??'',160);
    if($object!=='')$object=tracky_v278_world_local_id($object,'relation object');
    $temporal=strtolower(tracky_v278_world_text($input['temporal_state']??'current',30));
    if(!in_array($temporal,['current','last_seen','historical','inferred','predicted','unknown'],true))$temporal='unknown';
    $value=is_array($input['value']??null)?$input['value']:[];
    tracky_cloud_v270_assert_governed_value($value,'federated_world.relation.value');
    return [
      'subject_local_id'=>$subject,'subject_ref'=>tracky_v278_world_ref($siteId,$subject),
      'predicate'=>tracky_v278_world_token($input['predicate']??'related_to','relation predicate','related_to'),
      'object_local_id'=>$object,'object_ref'=>$object!==''?tracky_v278_world_ref($siteId,$object):'',
      'value'=>$value,
      'confidence'=>is_numeric($input['confidence']??null)?max(0.0,min(1.0,(float)$input['confidence'])):0.0,
      'temporal_state'=>$temporal,
      'source_event_id'=>tracky_v278_world_text($input['source_event_id']??'',160),
      'sequence'=>max(0,(int)($input['sequence']??0)),
      'as_of'=>max(0,(int)($input['as_of']??0)),
    ];
}

function tracky_v278_world_fragment(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federated_world.fragment');
    $allowed=['protocol','schema_version','site_id','authority_device_id','authority_epoch','topology_revision','revision','observed_at','entities','relations','context','fingerprint','semantic_only','identity_scope'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky federated world fragment contains unsupported field: '.(string)$key);
    }
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278)throw new RuntimeException('Tracky federated world fragment protocol is unsupported.');
    if(empty($input['semantic_only'])||(string)($input['identity_scope']??'')!=='site_local')throw new RuntimeException('Tracky federated world fragment must remain semantic-only and site-local.');
    $siteId=tracky_v278_world_uuid($input['site_id']??'','site id');
    $entities=is_array($input['entities']??null)?array_values($input['entities']):[];
    $relations=is_array($input['relations']??null)?array_values($input['relations']):[];
    if(count($entities)>512||count($relations)>1024)throw new RuntimeException('Tracky federated world fragment exceeds Cloud limits.');
    $out=[
      'protocol'=>VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278,'schema_version'=>1,
      'site_id'=>$siteId,
      'authority_device_id'=>tracky_v278_world_uuid($input['authority_device_id']??'','authority device id'),
      'authority_epoch'=>max(0,(int)($input['authority_epoch']??0)),
      'topology_revision'=>max(0,(int)($input['topology_revision']??0)),
      'revision'=>max(0,(int)($input['revision']??0)),
      'observed_at'=>tracky_v278_world_text($input['observed_at']??'',64),
      'entities'=>[],'relations'=>[],
      'context'=>is_array($input['context']??null)?$input['context']:[],
      'semantic_only'=>true,'identity_scope'=>'site_local',
    ];
    if($out['authority_epoch']<1||$out['revision']<1)throw new RuntimeException('Tracky federated world authority epoch and revision are required.');
    tracky_cloud_v270_assert_governed_value($out['context'],'federated_world.context');
    foreach($entities as $entity){if(!is_array($entity))throw new RuntimeException('Tracky federated world entity is invalid.');$out['entities'][]=tracky_v278_world_entity($siteId,$entity);}
    foreach($relations as $relation){if(!is_array($relation))throw new RuntimeException('Tracky federated world relation is invalid.');$out['relations'][]=tracky_v278_world_relation($siteId,$relation);}
    $ids=array_fill_keys(array_column($out['entities'],'local_id'),true);
    if(count($ids)!==count($out['entities']))throw new RuntimeException('Tracky federated world contains duplicate local entity ids.');
    foreach($out['relations'] as $relation){
        if(!isset($ids[$relation['subject_local_id']]))throw new RuntimeException('Tracky federated world relation subject is not present in the site entity set.');
        if($relation['object_local_id']!==''&&!isset($ids[$relation['object_local_id']]))throw new RuntimeException('Tracky federated world relation object is not present in the site entity set.');
    }
    $out['fingerprint']=hash('sha256',tracky_cloud_v270_json($out));
    return $out;
}

function tracky_v278_world_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federated_world');
    $allowed=['protocol','schema_version','site_count','sites','entities','relations','generated_at','identity_scope','cross_site_identity_links','semantic_only','summary_only','cloud_read_only','authority_assignment','origin_scope'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky federated world projection contains unsupported field: '.(string)$key);
    }
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278)throw new RuntimeException('Tracky federated world protocol is unsupported.');
    if(empty($input['semantic_only'])||empty($input['summary_only'])||empty($input['cloud_read_only']))throw new RuntimeException('Tracky Cloud accepts semantic read-only federated world summaries only.');
    if((string)($input['authority_assignment']??'')!=='local_only')throw new RuntimeException('Tracky federated world authority must remain local.');
    if((string)($input['identity_scope']??'')!=='site_local')throw new RuntimeException('Tracky federated world identity scope must remain site-local.');
    if(!empty($input['cross_site_identity_links']))throw new RuntimeException('Cross-site identity links are deferred to V2.78 Section 5.');
    $sites=is_array($input['sites']??null)?array_values($input['sites']):[];
    if(count($sites)>128)throw new RuntimeException('Tracky federated world site batch exceeds the Cloud limit.');
    return [
      'protocol'=>VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278,'schema_version'=>1,
      'sites'=>array_map(static fn($site)=>tracky_v278_world_fragment((array)$site),$sites),
      'identity_scope'=>'site_local','cross_site_identity_links'=>[],
      'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'local_only',
    ];
}

function tracky_v278_world_topology_authority(PDO $pdo,int $userId,string $reportingSiteId,string $siteUuid): array
{
    $topology=tracky_v278_report($pdo,$userId,$reportingSiteId);
    $sites=is_array($topology['topology']['sites']??null)?$topology['topology']['sites']:[];
    foreach($sites as $site){
        if(is_array($site)&&($site['id']??'')===$siteUuid){
            return ['device_id'=>(string)($site['authority_device_id']??''),'epoch'=>(int)($site['authority_epoch']??0)];
        }
    }
    throw new RuntimeException('Tracky federated world site is not present in the current topology mirror.');
}

function tracky_v278_world_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    tracky_v278_world_ensure_schema($pdo);
    $projection=tracky_v278_world_normalize($input);
    $changed=0;$stale=0;$accepted=0;
    foreach($projection['sites'] as $fragment){
        $authority=tracky_v278_world_topology_authority($pdo,$userId,$reportingSiteId,$fragment['site_id']);
        if($authority['device_id']!==$fragment['authority_device_id']||$authority['epoch']!==$fragment['authority_epoch']){
            throw new RuntimeException('Tracky federated world fragment authority does not match current topology authority.');
        }
        $json=tracky_cloud_v270_json($fragment);
        $fingerprint=hash('sha256',$json);
        $q=$pdo->prepare('SELECT world_revision,fingerprint,fragment_json FROM tracky_cloud_federated_world_fragments WHERE user_id=? AND site_uuid=? LIMIT 1');
        $q->execute([$userId,$fragment['site_id']]);$prior=$q->fetch();
        if($prior&&$fragment['revision']<(int)$prior['world_revision']){$stale++;continue;}
        if($prior&&$fragment['revision']===(int)$prior['world_revision']){
            if(!hash_equals((string)$prior['fingerprint'],$fingerprint))throw new RuntimeException('Tracky federated world revision conflicts with the existing site world.');
            $accepted++;continue;
        }
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federated_world_fragments
          (user_id,site_uuid,reporting_site_id,authority_device_uuid,authority_epoch,topology_revision,world_revision,observed_at,entity_count,relation_count,fragment_json,fingerprint)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            reporting_site_id=VALUES(reporting_site_id),authority_device_uuid=VALUES(authority_device_uuid),
            authority_epoch=VALUES(authority_epoch),topology_revision=VALUES(topology_revision),
            world_revision=VALUES(world_revision),observed_at=VALUES(observed_at),
            entity_count=VALUES(entity_count),relation_count=VALUES(relation_count),
            fragment_json=VALUES(fragment_json),fingerprint=VALUES(fingerprint),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,$fragment['site_id'],$reportingSiteId,$fragment['authority_device_id'],$fragment['authority_epoch'],$fragment['topology_revision'],$fragment['revision'],$fragment['observed_at'],count($fragment['entities']),count($fragment['relations']),$json,$fingerprint]);
        $accepted++;$changed++;
    }
    return ['accepted'=>true,'sites'=>$accepted,'changed'=>$changed,'stale'=>$stale];
}

function tracky_v278_world_report(PDO $pdo,int $userId,?string $siteUuid=null): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278];
    tracky_v278_world_ensure_schema($pdo);
    if($siteUuid!==null&&trim($siteUuid)!==''){
        $siteUuid=tracky_v278_world_uuid($siteUuid,'site id');
        $q=$pdo->prepare('SELECT * FROM tracky_cloud_federated_world_fragments WHERE user_id=? AND site_uuid=? LIMIT 1');$q->execute([$userId,$siteUuid]);
    }else{
        $q=$pdo->prepare('SELECT * FROM tracky_cloud_federated_world_fragments WHERE user_id=? ORDER BY site_uuid');$q->execute([$userId]);
    }
    $rows=$q->fetchAll()?:[];$sites=[];$entities=[];$relations=[];
    foreach($rows as $row){
        $decoded=json_decode((string)($row['fragment_json']??''),true);
        if(!is_array($decoded))continue;
        $sites[]=$decoded;
        foreach((array)($decoded['entities']??[]) as $entity)if(is_array($entity))$entities[]=$entity+['site_id'=>(string)$decoded['site_id']];
        foreach((array)($decoded['relations']??[]) as $relation)if(is_array($relation))$relations[]=$relation+['site_id'=>(string)$decoded['site_id']];
    }
    return [
      'available'=>!empty($sites),'protocol'=>VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278,'schema_version'=>1,
      'site_count'=>count($sites),'sites'=>$sites,'entities'=>$entities,'relations'=>$relations,
      'identity_scope'=>'site_local','cross_site_identity_links'=>[],
      'semantic_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'local_only',
    ];
}

function tracky_v278_world_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_FEDERATED_WORLD_PROTOCOL_V278,
      'semantic_only'=>true,'identity_scope'=>'site_local','cross_site_identity_linking'=>false,
      'cloud_read_only'=>true,'authority_assignment'=>'local_only','world_mutation_authority'=>false,
    ];
}
