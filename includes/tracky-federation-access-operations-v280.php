<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 6 — Federation permissions & consent operations mirror.
 * Cloud explains mirrored origin-site policy. It cannot grant, revoke, change
 * recognition consent, transfer authority, or resurrect access from stale data.
 */
const VP3_TRACKY_ACCESS_OPERATIONS_V280='vp3-tracky-access-operations-v280-20260928';
const VP3_TRACKY_ACCESS_OPERATIONS_PROTOCOL_V280='physical_federation_access_operations.v1';

function tracky_v280_access_categories(): array
{
    return [
      'observation'=>['remote_observation'],
      'retention'=>['history_query'],
      'identification'=>['person_recognition','voice_matching','identity_linking'],
      'cloud_sync'=>['semantic_world_read'],
      'agent_use'=>['agent_context_read'],
      'federation_sharing'=>['semantic_world_read','identity_continuity_read'],
    ];
}

function tracky_v280_access_text(mixed $value,int $max=240): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_access_revocations(PDO $pdo,int $userId): array
{
    tracky_v278_policy_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT revocation_json FROM tracky_cloud_federation_policy_revocations WHERE user_id=? ORDER BY revocation_epoch DESC,revision DESC');
    $q->execute([$userId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $v=json_decode((string)($row['revocation_json']??''),true);
        if(!is_array($v))continue;
        $key=tracky_v280_access_text($v['revocation_key']??$v['key']??'',420);
        if($key==='')continue;
        $out[$key]=[
          'key'=>$key,'revision'=>max(0,(int)($v['revision']??0)),
          'revocation_epoch'=>max(0,(int)($v['revocation_epoch']??0)),
          'reason'=>tracky_v280_access_text($v['reason']??'',200),
          'at'=>max(0,(int)($v['revoked_at_ms']??$v['at']??0)),
        ];
    }
    return $out;
}

function tracky_v280_access_effective_grant(array $row,array $revocations): array
{
    $source=(string)($row['source_site_id']??'');
    $dest=(string)($row['destination_site_id']??'');
    $scope=strtolower(tracky_v280_access_text($row['scope']??'',80));
    $tomb=$revocations['grant:'.$source.'|'.$dest.'|'.$scope]??null;
    $revision=max(0,(int)($row['revision']??0));
    $raw=strtolower(tracky_v280_access_text($row['status']??'revoked',30));
    $suppressed=is_array($tomb)&&(int)$tomb['revision']>=$revision;
    $status=$suppressed?'revoked':($raw==='granted'?'granted':'revoked');
    return [
      'source_site_id'=>$source,'destination_site_id'=>$dest,'scope'=>$scope,
      'status'=>$status,'revision'=>$revision,'reason'=>tracky_v280_access_text($row['reason']??'',200),
      'revocation_epoch'=>(int)($tomb['revocation_epoch']??0),
      'revocation_reason'=>tracky_v280_access_text($tomb['reason']??'',200),
      'effective_allowed'=>$status==='granted',
      'stale_grant_suppressed'=>$suppressed&&$raw==='granted',
    ];
}

function tracky_v280_access_effective_consent(array $row,array $revocations,int $nowMs): array
{
    $site=(string)($row['site_id']??'');
    $identity=(string)($row['canonical_identity_id']??'');
    $scope=strtolower(tracky_v280_access_text($row['scope']??'',80));
    $tomb=$revocations['consent:'.$site.'|'.$identity.'|'.$scope]??null;
    $revision=max(0,(int)($row['revision']??0));
    $raw=strtolower(tracky_v280_access_text($row['status']??'pending',30));
    $expires=max(0,(int)($row['expires_at_ms']??0));
    $suppressed=is_array($tomb)&&(int)$tomb['revision']>=$revision;
    $status=$raw;
    if($suppressed)$status='revoked';
    elseif($expires>0&&$nowMs>=$expires&&$raw==='granted')$status='expired';
    elseif(!in_array($status,['pending','granted','denied','revoked'],true))$status='pending';
    return [
      'site_id'=>$site,'canonical_identity_id'=>$identity,'scope'=>$scope,'status'=>$status,
      'revision'=>$revision,'source'=>tracky_v280_access_text($row['source']??'user',80),
      'reason'=>tracky_v280_access_text($row['reason']??'',200),'expires_at_ms'=>$expires,
      'revocation_epoch'=>(int)($tomb['revocation_epoch']??0),
      'revocation_reason'=>tracky_v280_access_text($tomb['reason']??'',200),
      'effective_allowed'=>$status==='granted',
      'stale_grant_suppressed'=>$suppressed&&$raw==='granted',
    ];
}

function tracky_v280_access_category_state(array $rows): string
{
    if(!$rows)return 'denied';
    $allowed=count(array_filter($rows,static fn($r)=>!empty($r['effective_allowed'])));
    if($allowed===count($rows))return 'allowed';
    if($allowed===0)return count(array_filter($rows,static fn($r)=>($r['status']??'')==='revoked'))?'revoked':'denied';
    return 'limited';
}

function tracky_v280_access_history(PDO $pdo,int $userId,int $limit=250): array
{
    tracky_v278_policy_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT id,governing_site_uuid,event_type,revision,revocation_epoch,snapshot_json,created_at FROM tracky_cloud_federation_policy_history WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(1000,$limit)));
    $q->execute([$userId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $snapshot=json_decode((string)($row['snapshot_json']??''),true);
        $out[]=[
          'event_id'=>'cloud-policy-history:'.(int)$row['id'],
          'governing_site_id'=>(string)$row['governing_site_uuid'],
          'event_type'=>(string)$row['event_type'],
          'revision'=>(int)$row['revision'],'revocation_epoch'=>(int)$row['revocation_epoch'],
          'detail'=>is_array($snapshot)?$snapshot:[],
          'occurred_at'=>(string)$row['created_at'],'immutable'=>true,
        ];
    }
    return $out;
}

function tracky_v280_access_sync_map(PDO $pdo,int $userId): array
{
    $report=function_exists('tracky_v280_syncv_report')?tracky_v280_syncv_report($pdo,$userId):[];
    $out=[];
    foreach((array)($report['snapshots']??[]) as $snapshot){
        $visibility=is_array($snapshot['visibility']??null)?$snapshot['visibility']:[];
        foreach((array)($visibility['sites']??[]) as $row){
            if(!is_array($row))continue;
            $site=(string)($row['site_id']??'');if($site==='')continue;
            $prior=$out[$site]??null;
            if($prior===null||!empty($row['fresh'])||empty($prior['fresh']))$out[$site]=$row;
        }
    }
    return $out;
}

function tracky_v280_access_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_ACCESS_OPERATIONS_PROTOCOL_V280];
    tracky_v278_policy_ensure_schema($pdo);
    $policy=tracky_v278_policy_report($pdo,$userId);
    $revocations=tracky_v280_access_revocations($pdo,$userId);
    $history=tracky_v280_access_history($pdo,$userId,250);
    $operations=function_exists('tracky_v280_operations_report')?tracky_v280_operations_report($pdo,$userId,null):[];
    $ops=is_array($operations['operations']??null)?$operations['operations']:[];
    $labels=[];
    foreach((array)($ops['sites']??[]) as $row)if(is_array($row)&&!empty($row['id']))$labels[(string)$row['id']]=(string)($row['label']??$row['id']);
    $sync=tracky_v280_access_sync_map($pdo,$userId);
    $nowMs=(int)floor(microtime(true)*1000);

    $grants=[];
    foreach((array)($policy['grants']??[]) as $row)if(is_array($row))$grants[]=tracky_v280_access_effective_grant($row,$revocations);
    $consents=[];
    foreach((array)($policy['consents']??[]) as $row)if(is_array($row))$consents[]=tracky_v280_access_effective_consent($row,$revocations,$nowMs);

    $stateQ=$pdo->prepare('SELECT governing_site_uuid,revision,revocation_epoch FROM tracky_cloud_federation_policy_state WHERE user_id=? ORDER BY governing_site_uuid');
    $stateQ->execute([$userId]);$stateRows=[];
    foreach($stateQ->fetchAll()?:[] as $row)$stateRows[(string)$row['governing_site_uuid']]=['revision'=>(int)$row['revision'],'revocation_epoch'=>(int)$row['revocation_epoch']];

    $views=[];
    foreach((array)($policy['sites']??[]) as $sitePolicy){
        if(!is_array($sitePolicy))continue;
        $source=(string)($sitePolicy['site_id']??'');if($source==='')continue;
        $peerIds=array_values(array_unique(array_filter(array_map(static fn($g)=>($g['source_site_id']??'')===$source?(string)($g['destination_site_id']??''):'',$grants))));
        foreach((array)($sitePolicy['allowed_peer_sites']??[]) as $id)if(is_string($id)&&$id!==''&&!in_array($id,$peerIds,true))$peerIds[]=$id;
        sort($peerIds);
        $peers=[];
        foreach($peerIds as $dest){
            $rows=array_values(array_filter($grants,static fn($g)=>($g['source_site_id']??'')===$source&&($g['destination_site_id']??'')===$dest));
            $categories=[];
            foreach(tracky_v280_access_categories() as $category=>$scopes){
                $scopeRows=[];
                foreach($scopes as $scope){
                    $match=null;foreach($rows as $candidate)if(($candidate['scope']??'')===$scope){$match=$candidate;break;}
                    $scopeRows[]=$match?:[
                      'source_site_id'=>$source,'destination_site_id'=>$dest,'scope'=>$scope,'status'=>'not_granted',
                      'revision'=>0,'effective_allowed'=>false,'stale_grant_suppressed'=>false,'revocation_epoch'=>0,
                    ];
                }
                $categories[$category]=['state'=>tracky_v280_access_category_state($scopeRows),'scopes'=>$scopeRows];
            }
            $syncRow=$sync[$dest]??[];
            $peers[]=[
              'site_id'=>$dest,'label'=>$labels[$dest]??$dest,
              'policy_peer_allowed'=>in_array($dest,(array)($sitePolicy['allowed_peer_sites']??[]),true),
              'site_policy_mode'=>(string)($sitePolicy['mode']??'private'),
              'federation_enabled'=>!empty($sitePolicy['allow_federation']),
              'remote_observation_enabled'=>!empty($sitePolicy['allow_remote_observation']),
              'categories'=>$categories,
              'sync'=>[
                'status'=>(string)($syncRow['status']??'unknown'),'fresh'=>!empty($syncRow['fresh']),
                'stale_age_ms'=>(int)($syncRow['stale_age_ms']??0),
                'reconciliation_required'=>!empty($syncRow['reconciliation_required']),
              ],
              'revocation_protection'=>[
                'stale_remote_grant_can_restore_access'=>false,'revocation_wins'=>true,
                'remote_policy_freshness_required'=>empty($syncRow['fresh']),
              ],
            ];
        }
        $siteConsents=array_values(array_filter($consents,static fn($c)=>($c['site_id']??'')===$source));
        $suppressed=array_values(array_filter([...$rows??[],...$siteConsents],static fn($r)=>!empty($r['stale_grant_suppressed'])));
        $state=$stateRows[$source]??['revision'=>0,'revocation_epoch'=>0];
        $views[]=[
          'source_site_id'=>$source,'source_label'=>$labels[$source]??$source,
          'policy_revision'=>(int)$state['revision'],'revocation_epoch'=>(int)$state['revocation_epoch'],
          'local_policy'=>$sitePolicy,'peers'=>$peers,'consents'=>$siteConsents,
          'history'=>array_values(array_filter($history,static fn($h)=>($h['governing_site_id']??'')===$source)),
          'agent_context'=>[
            'policy_revision'=>(int)$state['revision'],'revocation_epoch'=>(int)$state['revocation_epoch'],
            'stale_grants_suppressed'=>count(array_filter([...$grants,...$consents],static fn($r)=>!empty($r['stale_grant_suppressed']))),
            'revocation_wins'=>true,
          ],
        ];
    }
    $suppressed=count(array_filter([...$grants,...$consents],static fn($r)=>!empty($r['stale_grant_suppressed'])));
    return [
      'available'=>!empty($views),'protocol'=>VP3_TRACKY_ACCESS_OPERATIONS_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>$nowMs,'source_views'=>$views,'grants'=>$grants,'consents'=>$consents,
      'revocations'=>array_values($revocations),'history'=>$history,
      'counts'=>[
        'source_sites'=>count($views),'grants_allowed'=>count(array_filter($grants,static fn($g)=>!empty($g['effective_allowed']))),
        'grants_revoked'=>count(array_filter($grants,static fn($g)=>($g['status']??'')==='revoked')),
        'consents_allowed'=>count(array_filter($consents,static fn($c)=>($c['status']??'')==='granted')),
        'consents_revoked'=>count(array_filter($consents,static fn($c)=>in_array(($c['status']??''),['revoked','denied','expired'],true))),
        'stale_grants_suppressed'=>$suppressed,
      ],
      'operations'=>['cloud_read_only'=>true,'cloud_can_grant'=>false,'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false],
      'boundaries'=>['deny-by-default','revocation-always-wins','stale-grant-never-resurrects-access','site-local-policy-authority','cloud-mirror-only','cross-site-identity-merge-disabled'],
    ];
}

function tracky_v280_access_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_ACCESS_OPERATIONS_PROTOCOL_V280,
      'categories'=>array_keys(tracky_v280_access_categories()),
      'permission_states'=>['allowed','denied','limited','revoked'],
      'consent_states'=>['allowed','denied','limited','expired','revoked','pending'],
      'revocation_wins'=>true,'stale_remote_grant_can_restore_access'=>false,
      'cloud_read_only'=>true,'cloud_can_grant'=>false,'cloud_can_revoke'=>false,
      'cloud_can_change_consent'=>false,'authority_mutation'=>false,'cross_site_identity_merge'=>false,
    ];
}
