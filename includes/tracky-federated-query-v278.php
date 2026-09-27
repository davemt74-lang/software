<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 8 — governed federated query + semantic history.
 * Cloud answers only from its read-only mirrors and always acts on behalf of
 * an explicit destination site. It never becomes physical or policy authority.
 */
const VP3_TRACKY_FEDERATED_QUERY_V278='vp3-tracky-federated-query-v278-20260927';
const VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278='physical_federated_query.v1';

function tracky_v278_query_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federated_world_history')&&table_exists('tracky_cloud_federated_query_audit'):false;
}

function tracky_v278_query_intents(): array
{
    return ['current_state','where_is','last_seen','history','what_changed','explain'];
}

function tracky_v278_query_history_intent(string $intent): bool
{
    return in_array($intent,['last_seen','history','what_changed','explain'],true);
}

function tracky_v278_query_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    tracky_v278_world_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federated_query_audit (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      query_id VARCHAR(128) NOT NULL,
      intent VARCHAR(40) NOT NULL,
      destination_site_uuid CHAR(36) NOT NULL,
      source_site_uuid CHAR(36) NOT NULL,
      target_ref VARCHAR(320) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL,
      denied_reason VARCHAR(80) NOT NULL DEFAULT '',
      result_fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      INDEX idx_tracky_query_audit_user_created (user_id,created_at),
      INDEX idx_tracky_query_audit_sites (user_id,destination_site_uuid,source_site_uuid),
      CONSTRAINT fk_tracky_query_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_query_text(mixed $value,int $max=180): string
{
    return mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
}

function tracky_v278_query_timestamp_ms(mixed $value): int
{
    if(is_int($value)||is_float($value))return max(0,(int)$value);
    $text=trim((string)($value??''));
    if($text==='')return 0;
    if(is_numeric($text))return max(0,(int)((float)$text));
    try{$dt=new DateTimeImmutable($text);}catch(Throwable){return 0;}
    return max(0,(int)round(((float)$dt->format('U.u'))*1000));
}

function tracky_v278_query_site_ref(string $value): ?array
{
    $value=tracky_v278_query_text($value,320);
    if(!preg_match('/^site:([0-9a-f-]{36})::(.+)$/i',$value,$m))return null;
    try{$site=tracky_v278_world_uuid($m[1],'target site id');}
    catch(Throwable){return null;}
    $local=rawurldecode((string)$m[2]);
    if($local==='')return null;
    return ['site_id'=>$site,'local_id'=>mb_strimwidth($local,0,160,''),'ref'=>$value];
}

function tracky_v278_query_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federated_query');
    $intent=strtolower(tracky_v278_query_text($input['intent']??'current_state',40));
    if(!in_array($intent,tracky_v278_query_intents(),true))throw new RuntimeException('Tracky federated query intent is unsupported.');
    $destination=tracky_v278_world_uuid($input['destination_site_id']??'','destination site id');
    $source=tracky_v278_world_uuid($input['site_id']??$input['source_site_id']??$destination,'source site id');
    $target=tracky_v278_query_text($input['target_ref']??'',320);
    $parsed=$target!==''?tracky_v278_query_site_ref($target):null;
    if($target!==''&&!$parsed)throw new RuntimeException('Tracky federated query target must be site-qualified.');
    if($parsed&&$parsed['site_id']!==$source)throw new RuntimeException('Tracky federated query target belongs to a different source site.');
    $since=max(0,(int)($input['since_ms']??0));
    $until=max(0,(int)($input['until_ms']??0));
    if($since>0&&$until>0&&$since>$until)throw new RuntimeException('Tracky federated query time range is invalid.');
    $normalized=[
      'protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'schema_version'=>1,
      'query_id'=>tracky_v278_query_text($input['query_id']??'',128),
      'intent'=>$intent,'destination_site_id'=>$destination,'site_id'=>$source,
      'target_ref'=>$target,'target_local_id'=>$parsed['local_id']??'',
      'since_ms'=>$since,'until_ms'=>$until,'limit'=>max(1,min(100,(int)($input['limit']??50))),
      'semantic_only'=>true,'read_only'=>true,
    ];
    if($normalized['query_id']===''){
        $normalized['query_id']='fq:'.substr(hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,24);
    }
    return $normalized;
}

function tracky_v278_query_access(PDO $pdo,int $userId,array $query): array
{
    $source=$query['site_id'];$destination=$query['destination_site_id'];
    if($source===$destination)return ['allowed'=>true,'reason'=>'local_site','world'=>['allowed'=>true,'reason'=>'local_site'],'history'=>null];
    $world=tracky_v278_policy_decision($pdo,$userId,$source,$destination,'semantic_world_read');
    if(empty($world['allowed']))return ['allowed'=>false,'reason'=>(string)($world['reason']??'semantic_world_read_denied')];
    $history=null;
    if(tracky_v278_query_history_intent((string)$query['intent'])){
        $history=tracky_v278_policy_decision($pdo,$userId,$source,$destination,'history_query');
        if(empty($history['allowed']))return ['allowed'=>false,'reason'=>(string)($history['reason']??'history_query_denied')];
    }
    return [
      'allowed'=>true,'reason'=>$history?'explicit_history_grant':'explicit_world_grant',
      'world'=>$world,'history'=>$history,
    ];
}

function tracky_v278_query_current_fragment(PDO $pdo,int $userId,array $query): ?array
{
    $report=tracky_v278_world_report($pdo,$userId,(string)$query['site_id']);
    $fragment=$report['sites'][0]??null;
    if(!is_array($fragment))return null;
    if($query['site_id']===$query['destination_site_id'])return $fragment;
    return tracky_v278_policy_filter_world_fragment($pdo,$userId,(string)$query['site_id'],(string)$query['destination_site_id'],$fragment);
}

function tracky_v278_query_history(PDO $pdo,int $userId,array $query): array
{
    tracky_v278_query_ensure_schema($pdo);
    $q=$pdo->prepare("SELECT site_uuid,world_revision,fingerprint,authority_device_uuid,authority_epoch,
      topology_revision,observed_at,fragment_json,created_at
      FROM tracky_cloud_federated_world_history
      WHERE user_id=? AND site_uuid=?
      ORDER BY world_revision ASC,created_at ASC LIMIT 500");
    $q->execute([$userId,$query['site_id']]);
    $out=[];
    foreach($q->fetchAll()?:[] as $row){
        $fragment=json_decode((string)($row['fragment_json']??''),true);
        if(!is_array($fragment))continue;
        if($query['site_id']!==$query['destination_site_id']){
            $fragment=tracky_v278_policy_filter_world_fragment($pdo,$userId,(string)$query['site_id'],(string)$query['destination_site_id'],$fragment);
            if(!is_array($fragment))continue;
        }
        $observed=tracky_v278_query_timestamp_ms($fragment['observed_at']??0);
        if($query['since_ms']>0&&$observed<$query['since_ms'])continue;
        if($query['until_ms']>0&&$observed>$query['until_ms'])continue;
        $out[]=[
          'snapshot_id'=>'history:'.$query['site_id'].':'.(int)$row['world_revision'].':'.substr((string)$row['fingerprint'],0,16),
          'site_id'=>(string)$row['site_uuid'],'world_revision'=>(int)$row['world_revision'],
          'authority_device_id'=>(string)$row['authority_device_uuid'],'authority_epoch'=>(int)$row['authority_epoch'],
          'topology_revision'=>(int)$row['topology_revision'],'observed_at_ms'=>$observed,
          'fingerprint'=>(string)$row['fingerprint'],'fragment'=>$fragment,
        ];
    }
    return array_slice($out,-(int)$query['limit']);
}

function tracky_v278_query_entity(?array $fragment,string $localId): ?array
{
    if(!$fragment||$localId==='')return null;
    foreach((array)($fragment['entities']??[]) as $entity){
        if(is_array($entity)&&(string)($entity['local_id']??'')===$localId)return $entity;
    }
    return null;
}

function tracky_v278_query_relations(?array $fragment,string $localId): array
{
    if(!$fragment||$localId==='')return [];
    $out=[];
    foreach((array)($fragment['relations']??[]) as $relation){
        if(!is_array($relation))continue;
        if(in_array($localId,[(string)($relation['subject_local_id']??''),(string)($relation['object_local_id']??'')],true))$out[]=$relation;
        if(count($out)>=100)break;
    }
    return $out;
}

function tracky_v278_query_location(?array $fragment,string $localId): ?array
{
    $allowed=['located_in','located_on','present_in','moving_between'];$best=null;
    foreach(tracky_v278_query_relations($fragment,$localId) as $relation){
        if((string)($relation['subject_local_id']??'')!==$localId)continue;
        if(!in_array((string)($relation['predicate']??''),$allowed,true))continue;
        if($best===null||(int)($relation['as_of']??0)>(int)($best['as_of']??0))$best=$relation;
    }
    if(!$best)return null;
    return [
      'predicate'=>(string)($best['predicate']??''),'object_local_id'=>(string)($best['object_local_id']??''),
      'object_ref'=>(string)($best['object_ref']??''),'confidence'=>(float)($best['confidence']??0),
      'temporal_state'=>(string)($best['temporal_state']??'unknown'),
      'source_event_id'=>(string)($best['source_event_id']??''),'as_of'=>(int)($best['as_of']??0),
    ];
}

function tracky_v278_query_evidence(array $snapshot,array $access): array
{
    return [
      'site_id'=>$snapshot['site_id'],'world_revision'=>$snapshot['world_revision'],
      'authority_device_id'=>$snapshot['authority_device_id'],'authority_epoch'=>$snapshot['authority_epoch'],
      'observed_at_ms'=>$snapshot['observed_at_ms'],'fingerprint'=>$snapshot['fingerprint'],
      'policy_basis'=>$access['reason'],
    ];
}

function tracky_v278_query_diff(array $first,array $last): array
{
    $before=[];$after=[];
    foreach((array)($first['entities']??[]) as $item)if(is_array($item)&&!empty($item['local_id']))$before[(string)$item['local_id']]=$item;
    foreach((array)($last['entities']??[]) as $item)if(is_array($item)&&!empty($item['local_id']))$after[(string)$item['local_id']]=$item;
    $added=[];$removed=[];$changed=[];
    foreach($after as $id=>$item){
        if(!isset($before[$id]))$added[]=['local_id'=>$id,'type'=>$item['type']??'entity','label'=>$item['label']??''];
        elseif(json_encode($before[$id])!==json_encode($item))$changed[]=['local_id'=>$id,'before'=>$before[$id],'after'=>$item];
    }
    foreach($before as $id=>$item)if(!isset($after[$id]))$removed[]=['local_id'=>$id,'type'=>$item['type']??'entity','label'=>$item['label']??''];
    return ['added'=>array_slice($added,0,50),'removed'=>array_slice($removed,0,50),'changed'=>array_slice($changed,0,50)];
}

function tracky_v278_query_audit_write(PDO $pdo,int $userId,array $query,array $result): void
{
    $compact=[
      'protocol'=>$result['protocol'],'query_id'=>$result['query_id'],'intent'=>$result['intent'],
      'status'=>$result['status'],'denied'=>$result['denied'],'confidence'=>$result['confidence'],
    ];
    $fingerprint=hash('sha256',json_encode($compact,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federated_query_audit
      (user_id,query_id,intent,destination_site_uuid,source_site_uuid,target_ref,status,denied_reason,result_fingerprint)
      VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
      $userId,$query['query_id'],$query['intent'],$query['destination_site_id'],$query['site_id'],
      $query['target_ref'],$result['status'],(string)($result['denied'][0]['reason']??''),$fingerprint,
    ]);
    $pdo->prepare("DELETE FROM tracky_cloud_federated_query_audit
      WHERE user_id=? AND id NOT IN (
        SELECT id FROM (
          SELECT id FROM tracky_cloud_federated_query_audit WHERE user_id=? ORDER BY id DESC LIMIT 500
        ) q
      )")->execute([$userId,$userId]);
}

function tracky_v278_query_execute(PDO $pdo,int $userId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    tracky_v278_query_ensure_schema($pdo);
    $query=tracky_v278_query_normalize($input);
    $access=tracky_v278_query_access($pdo,$userId,$query);
    if(empty($access['allowed'])){
        $result=[
          'protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'schema_version'=>1,
          'query_id'=>$query['query_id'],'intent'=>$query['intent'],'status'=>'denied',
          'results'=>[],'denied'=>[['site_id'=>$query['site_id'],'reason'=>$access['reason']]],
          'confidence'=>0.0,'uncertainty'=>['policy_limited'],
          'explainability'=>[
            'destination_site_id'=>$query['destination_site_id'],'source_site_id'=>$query['site_id'],
            'history_permission_required'=>tracky_v278_query_history_intent($query['intent']),
            'no_location_invention'=>true,'person_world_federation'=>false,'authority_mutation'=>false,
          ],
          'semantic_only'=>true,'read_only'=>true,'cloud_role'=>'mirror_query_only',
        ];
        tracky_v278_query_audit_write($pdo,$userId,$query,$result);
        return $result;
    }
    if($query['site_id']!==$query['destination_site_id']&&str_starts_with(strtolower((string)$query['target_local_id']),'person:')){
        $result=[
          'protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'schema_version'=>1,
          'query_id'=>$query['query_id'],'intent'=>$query['intent'],'status'=>'denied',
          'results'=>[],'denied'=>[['site_id'=>$query['site_id'],'reason'=>'person_query_requires_identity_continuity']],
          'confidence'=>0.0,'uncertainty'=>['policy_limited'],
          'explainability'=>[
            'destination_site_id'=>$query['destination_site_id'],'source_site_id'=>$query['site_id'],
            'history_permission_required'=>tracky_v278_query_history_intent($query['intent']),
            'no_location_invention'=>true,'person_world_federation'=>false,'authority_mutation'=>false,
          ],
          'semantic_only'=>true,'read_only'=>true,'cloud_role'=>'mirror_query_only',
        ];
        tracky_v278_query_audit_write($pdo,$userId,$query,$result);
        return $result;
    }

    $current=tracky_v278_query_current_fragment($pdo,$userId,$query);
    $history=tracky_v278_query_history_intent($query['intent'])
      ?tracky_v278_query_history($pdo,$userId,$query):[];
    $localId=(string)$query['target_local_id'];$data=null;

    if($query['intent']==='current_state'){
        $data=$current?['fragment'=>$current]:null;
    }elseif($query['intent']==='where_is'){
        $entity=tracky_v278_query_entity($current,$localId);
        $data=$entity?['entity'=>$entity,'location'=>tracky_v278_query_location($current,$localId)]:null;
    }elseif($query['intent']==='last_seen'){
        $found=null;
        foreach(array_reverse($history) as $snapshot){
            if(tracky_v278_query_entity($snapshot['fragment'],$localId)){$found=$snapshot;break;}
        }
        $data=$found?[
          'entity'=>tracky_v278_query_entity($found['fragment'],$localId),
          'location'=>tracky_v278_query_location($found['fragment'],$localId),
          'snapshot'=>tracky_v278_query_evidence($found,$access),
        ]:null;
    }elseif($query['intent']==='history'){
        $snapshots=[];
        foreach($history as $snapshot){
            $entity=$localId!==''?tracky_v278_query_entity($snapshot['fragment'],$localId):null;
            if($localId!==''&&!$entity)continue;
            $snapshots[]=tracky_v278_query_evidence($snapshot,$access)+[
              'entity'=>$entity,'relations'=>$localId!==''?tracky_v278_query_relations($snapshot['fragment'],$localId):[],
              'fragment'=>$localId===''?$snapshot['fragment']:null,
            ];
        }
        $data=['snapshots'=>array_slice($snapshots,-(int)$query['limit'])];
    }elseif($query['intent']==='what_changed'){
        if(count($history)>=2){
            $first=$history[0];$last=$history[count($history)-1];
            $data=[
              'from'=>tracky_v278_query_evidence($first,$access),
              'to'=>tracky_v278_query_evidence($last,$access),
              'changes'=>tracky_v278_query_diff($first['fragment'],$last['fragment']),
            ];
        }else{
            $data=['from'=>$history?tracky_v278_query_evidence($history[0],$access):null,'to'=>null,'changes'=>['added'=>[],'removed'=>[],'changed'=>[]]];
        }
    }elseif($query['intent']==='explain'){
        $latest=$history?end($history):null;
        $data=[
          'current_entity'=>$localId!==''?tracky_v278_query_entity($current,$localId):null,
          'current_relations'=>$localId!==''?tracky_v278_query_relations($current,$localId):[],
          'provenance'=>$latest?[tracky_v278_query_evidence($latest,$access)]:[],
          'policy'=>['reason'=>$access['reason'],'world'=>$access['world']??null,'history'=>$access['history']??null],
        ];
    }

    $result=[
      'protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'schema_version'=>1,
      'query_id'=>$query['query_id'],'intent'=>$query['intent'],'status'=>$data!==null?'ok':'unknown',
      'results'=>[['site_id'=>$query['site_id'],'access'=>$data!==null?'allowed':'no_evidence','data'=>$data]],
      'denied'=>[],'confidence'=>$data!==null?1.0:0.0,'uncertainty'=>$data===null?['no_matching_evidence']:[],
      'explainability'=>[
        'destination_site_id'=>$query['destination_site_id'],'source_site_id'=>$query['site_id'],
        'history_permission_required'=>tracky_v278_query_history_intent($query['intent']),
        'no_location_invention'=>true,'person_world_federation'=>false,'authority_mutation'=>false,
      ],
      'semantic_only'=>true,'read_only'=>true,'cloud_role'=>'mirror_query_only',
    ];
    tracky_v278_query_audit_write($pdo,$userId,$query,$result);
    return $result;
}

function tracky_v278_query_audit(PDO $pdo,int $userId,int $limit=50): array
{
    if($userId<1)return ['protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'queries'=>[]];
    tracky_v278_query_ensure_schema($pdo);
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT query_id,intent,destination_site_uuid,source_site_uuid,target_ref,status,denied_reason,result_fingerprint,created_at
      FROM tracky_cloud_federated_query_audit WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
    $q->execute([$userId]);
    return [
      'protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,'queries'=>$q->fetchAll()?:[],
      'semantic_only'=>true,'read_only'=>true,'cloud_role'=>'mirror_query_only',
    ];
}

function tracky_v278_query_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278,
      'intents'=>tracky_v278_query_intents(),'current_world_scope'=>'semantic_world_read',
      'history_scope'=>'history_query','deny_by_default'=>true,'destination_site_required'=>true,
      'site_qualified_refs'=>true,'person_world_federation'=>false,'raw_perception'=>false,
      'authority_mutation'=>false,'cloud_role'=>'mirror_query_only','query_audit_retention'=>500,
      'boundaries'=>[
        'query-read-only','history-deny-by-default','site-qualified-references',
        'person-query-via-identity-continuity','no-location-invention',
        'raw-perception-never-queryable','cloud-mirror-only',
      ],
    ];
}
