<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 6 — Agent Brain & Memory continuity.
 *
 * Cloud agent_memory_items remain VP3 Cloud-authoritative. HomeServer
 * agent_memory records remain HomeServer-authoritative. This layer projects one
 * logical Agent Brain without copying either native store into the other.
 */
const VP3_HOMESERVER_AGENT_BRAIN_MEMORY_V245='vp3-homeserver-agent-brain-memory-v245-20260926';

function homeserver_agent_brain_memory_v245_text(mixed $value,int $max=6000): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_agent_brain_memory_v245_matches(string $query,string $text): bool
{
    $query=trim($query);if($query==='')return true;
    $hay=mb_strtolower($text);
    foreach(preg_split('/\s+/u',mb_strtolower($query))?:[] as $term){
        $term=trim($term);if($term!==''&&!str_contains($hay,$term))return false;
    }
    return true;
}

function homeserver_agent_brain_memory_v245_revision(array $row): string
{
    $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
    $payload=[
      'memory_type'=>(string)($row['memory_type']??''),
      'subject'=>(string)($row['subject']??''),
      'memory_text'=>(string)($row['memory_text']??''),
      'occurrence_count'=>(int)($row['occurrence_count']??0),
      'confidence'=>round((float)($row['confidence']??0),6),
      'last_seen_at'=>(string)($row['last_seen_at']??''),
      'metadata'=>$meta,
      'is_active'=>(int)($row['is_active']??1),
    ];
    return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}');
}

function homeserver_agent_brain_memory_v245_cloud_items(array $user,string $query='',int $limit=80): array
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!table_exists('agent_memory_items'))return [];
    $limit=max(1,min(200,$limit));
    $agentId=function_exists('vp3_agent_memory_scope_current_v410')
      ? vp3_agent_memory_scope_current_v410($user) : 0;
    if(function_exists('vp3_agent_memory_scope_sql_v410')){
        [$scope,$params]=vp3_agent_memory_scope_sql_v410($agentId,'m');
    }else{$scope='1=1';$params=[];}
    try{
        $stmt=$pdo->prepare(
          "SELECT m.id,m.user_agent_id,m.memory_type,m.subject,m.memory_text,
                  m.occurrence_count,m.confidence,m.first_seen_at,m.last_seen_at,
                  m.is_active,m.metadata_json
           FROM agent_memory_items m
           WHERE m.user_id=? AND {$scope} AND m.is_active=1
           ORDER BY m.last_seen_at DESC,m.id DESC LIMIT 300"
        );
        $stmt->execute(array_merge([$uid],$params));
        $out=[];
        foreach($stmt->fetchAll()?:[] as $row){
            $id=(int)($row['id']??0);if($id<1)continue;
            $type=homeserver_agent_brain_memory_v245_text($row['memory_type']??'memory',40)?:'memory';
            $subject=homeserver_agent_brain_memory_v245_text($row['subject']??ucfirst($type),240)?:ucfirst($type);
            $text=homeserver_agent_brain_memory_v245_text($row['memory_text']??'',6000);
            if($text===''||!homeserver_agent_brain_memory_v245_matches($query,$type.' '.$subject.' '.$text))continue;
            $key='agent_memory_item:'.$id;
            $canonical=homeserver_federated_v240_canonical_id('vp3_cloud','memory',$key);
            $revision=homeserver_agent_brain_memory_v245_revision($row);
            $confidence=max(0.0,min(1.0,(float)($row['confidence']??0.5)));
            $effective=function_exists('agent_memory_v123_effective_confidence')
              ? agent_memory_v123_effective_confidence($row) : $confidence;
            $item=[
              'id'=>$id,
              'agent_id'=>max(0,(int)($row['user_agent_id']??0)),
              'memory_key'=>$subject,
              'subject'=>$subject,
              'content'=>$text,
              'memory_type'=>$type,
              'confidence'=>$confidence,
              'effective_confidence'=>max(0.0,min(1.0,(float)$effective)),
              'occurrence_count'=>max(1,(int)($row['occurrence_count']??1)),
              'first_seen_at'=>$row['first_seen_at']??null,
              'updated_at'=>$row['last_seen_at']??null,
              'authority_source'=>'vp3_cloud',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'federation_version'=>'2.4',
              'mirror_only'=>false,
              'mutation_route'=>'cloud_native_agent_brain',
              'allowed_mutations'=>[],
            ];
            homeserver_federated_v240_observe($uid,[
              'authority_source'=>'vp3_cloud',
              'dataset'=>'memory',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'title'=>$subject,
              'content'=>'type: '.$type.' · confidence: '.number_format($confidence,2).' · occurrences: '.$item['occurrence_count'],
              'updated_at'=>$item['updated_at'],
            ],'vp3_cloud');
            $out[]=$item;if(count($out)>=$limit)break;
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function homeserver_agent_brain_memory_v245_homeserver_items(int $userId,string $query='',int $limit=80): array
{
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return [];
    $limit=max(1,min(50,$limit));
    try{
        $run=homeserver_execution_v230_execute($userId,'tool.execute',[
          'tool_key'=>'memory.list',
          'arguments'=>['query'=>homeserver_agent_brain_memory_v245_text($query,240),'limit'=>$limit],
        ]);
        $rows=function_exists('homeserver_reads_v231_rows')
          ? homeserver_reads_v231_rows($run['result']??$run)
          : [];
        $out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $canonical=strtolower(trim((string)($row['canonical_id']??'')));
            $key=homeserver_agent_brain_memory_v245_text($row['authority_key']??'',180);
            $revision=strtolower(trim((string)($row['record_revision']??'')));
            if((string)($row['authority_source']??'')!=='homeserver')continue;
            if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical)||!str_starts_with($key,'agent_memory:'))continue;
            if(!preg_match('/^[0-9a-f]{64}$/',$revision))continue;
            $expected=homeserver_federated_v240_canonical_id('homeserver','memory',$key);
            if(!hash_equals($expected,$canonical))continue;
            $content=homeserver_agent_brain_memory_v245_text($row['content']??'',6000);
            $memoryKey=homeserver_agent_brain_memory_v245_text($row['memory_key']??'',160);
            $type=homeserver_agent_brain_memory_v245_text($row['memory_type']??'memory',40)?:'memory';
            $item=[
              'id'=>0,
              'agent_id'=>max(0,(int)($row['agent_id']??0)),
              'memory_key'=>$memoryKey?:null,
              'subject'=>$memoryKey?:ucfirst($type),
              'content'=>$content,
              'memory_type'=>$type,
              'importance'=>max(0.0,min(1.0,(float)($row['importance']??0.5))),
              'confidence'=>max(0.0,min(1.0,(float)($row['confidence']??0.5))),
              'entity_type'=>homeserver_agent_brain_memory_v245_text($row['entity_type']??'',160)?:null,
              'entity_key'=>homeserver_agent_brain_memory_v245_text($row['entity_key']??'',240)?:null,
              'reinforcement_count'=>max(0,(int)($row['reinforcement_count']??0)),
              'updated_at'=>$row['updated_at']??null,
              'authority_source'=>'homeserver',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'federation_version'=>'2.4',
              'mirror_only'=>true,
              'mutation_route'=>'homeserver_approval',
              'allowed_mutations'=>['update','delete'],
            ];
            homeserver_federated_v240_observe($userId,[
              'authority_source'=>'homeserver',
              'dataset'=>'memory',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'title'=>(string)$item['subject'],
              'content'=>'type: '.$type.' · confidence: '.number_format((float)$item['confidence'],2).' · importance: '.number_format((float)$item['importance'],2),
              'updated_at'=>$item['updated_at'],
            ],'vp3_cloud');
            $out[]=$item;if(count($out)>=$limit)break;
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function homeserver_agent_brain_memory_v245_unified(array $user,string $query='',int $limit=120): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(250,$limit));
    $items=[];$seen=[];$counts=['vp3_cloud'=>0,'homeserver'=>0];
    foreach(homeserver_agent_brain_memory_v245_cloud_items($user,$query,$limit) as $item){
        $canonical=(string)$item['canonical_id'];if(isset($seen[$canonical]))continue;
        $seen[$canonical]=true;$items[]=$item;$counts['vp3_cloud']++;if(count($items)>=$limit)break;
    }
    if(count($items)<$limit){
        foreach(homeserver_agent_brain_memory_v245_homeserver_items($uid,$query,min(50,$limit-count($items))) as $item){
            $canonical=(string)$item['canonical_id'];if(isset($seen[$canonical]))continue;
            $seen[$canonical]=true;$items[]=$item;$counts['homeserver']++;if(count($items)>=$limit)break;
        }
    }
    return [
      'version'=>'2.4',
      'dataset'=>'memory',
      'items'=>$items,
      'count'=>count($items),
      'sources'=>$counts,
      'authority_rules'=>[
        'cloud_native'=>'agent_memory_items',
        'homeserver_native'=>'agent_memory',
        'native_source_remains_authoritative'=>true,
        'remote_records_are_mirrors'=>true,
        'canonical_mutations'=>true,
        'optimistic_concurrency'=>true,
        'idempotent_mutations'=>true,
        'homeserver_memory_content_persisted'=>false,
        'no_cross_database_id_writes'=>true,
      ],
    ];
}

function homeserver_agent_brain_memory_v245_known_homeserver(int $userId,string $canonicalId): ?array
{
    $pdo=db();if(!$pdo||$userId<1)return null;$canonicalId=strtolower(trim($canonicalId));
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonicalId))return null;
    $s=$pdo->prepare("SELECT authority_source,authority_key,record_hash,tombstoned
      FROM homeserver_federated_records
      WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud'
      ORDER BY last_seen_at DESC LIMIT 1");
    $s->execute([$userId,$canonicalId]);$row=$s->fetch();
    if(!$row||(string)$row['authority_source']!=='homeserver'
      ||!str_starts_with((string)$row['authority_key'],'agent_memory:')
      ||!empty($row['tombstoned']))return null;
    $expected=homeserver_federated_v240_canonical_id('homeserver','memory',(string)$row['authority_key']);
    return hash_equals($expected,$canonicalId)?$row:null;
}

function homeserver_agent_brain_memory_v245_request_homeserver(
    int $userId,string $action,array $payload
): array {
    $action=strtolower(trim($action));
    if(!in_array($action,['create','update','delete'],true))throw new RuntimeException('Unsupported HomeServer memory action.');
    $tool=$action==='create'?'memory.write':'memory.'.$action;
    $arguments=[];
    if($action==='create'){
        $content=$payload['content']??null;
        if(!is_string($content)||trim($content)==='')throw new RuntimeException('Memory content is required.');
        if(strlen($content)>50000)throw new RuntimeException('Memory content exceeds 50,000 characters.');
        $arguments['content']=$content;
        foreach(['memory_key','memory_type','entity_type','entity_key'] as $key){
            if(array_key_exists($key,$payload))$arguments[$key]=$payload[$key];
        }
        foreach(['importance','confidence'] as $key){
            if(array_key_exists($key,$payload))$arguments[$key]=(float)$payload[$key];
        }
        $mutation=trim((string)($payload['mutation_id']??''));
        $arguments['mutation_id']=preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$mutation)
          ?$mutation:bin2hex(random_bytes(16));
    }else{
        $canonical=strtolower(trim((string)($payload['canonical_id']??'')));
        if(!homeserver_agent_brain_memory_v245_known_homeserver($userId,$canonical)){
            throw new RuntimeException('That memory is not a writable HomeServer-native record.');
        }
        $mutation=trim((string)($payload['mutation_id']??''));
        $revision=strtolower(trim((string)($payload['expected_revision']??'')));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$mutation))throw new RuntimeException('A valid mutation_id is required.');
        if(!preg_match('/^[0-9a-f]{64}$/',$revision))throw new RuntimeException('A valid expected_revision is required.');
        $arguments=[
          'canonical_id'=>$canonical,'mutation_id'=>$mutation,'expected_revision'=>$revision,
        ];
        if($action==='update'){
            foreach(['content','memory_key','memory_type','entity_type','entity_key','importance','confidence'] as $key){
                if(array_key_exists($key,$payload))$arguments[$key]=$payload[$key];
            }
            if(count($arguments)===3)throw new RuntimeException('Memory update requires at least one changed field.');
        }
    }
    if(!function_exists('homeserver_governed_v233_request'))throw new RuntimeException('HomeServer governed actions are unavailable.');
    return homeserver_governed_v233_request($userId,$tool,$arguments);
}
