<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations v23.20 — deterministic proactive opportunity detection.
 *
 * Reuses Cognitive Runtime observations. No model calls, no new persistence
 * layer, no execution authority.
 */
const VP3_COGNITIVE_OPPORTUNITIES_V2320='vp3-cognitive-opportunities-v2320-20260921';
const VP3_COGNITIVE_OPPORTUNITIES_SOURCE_V2320='cognitive_opportunity_v2320';
const VP3_COGNITIVE_OPPORTUNITIES_MAX_ANCHORS_V2320=16;
const VP3_COGNITIVE_OPPORTUNITIES_MAX_EDGES_V2320=12;
const VP3_COGNITIVE_OPPORTUNITIES_MAX_DETECTIONS_V2320=8;
const VP3_COGNITIVE_OPPORTUNITIES_MIN_RELATION_CONFIDENCE_V2320=0.80;

function vp3_cognitive_opportunity_ref_v2320(array $candidate): ?array
{
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    try{return vp3_cognitive_validate_object_ref_v500($ref,true);}catch(Throwable $e){return null;}
}

function vp3_cognitive_opportunity_type_group_v2320(string $type): string
{
    $type=mb_strtolower(vp3_cognitive_id_v500($type,80));
    if(str_contains($type,'research'))return 'research';
    if(str_contains($type,'knowledge'))return 'knowledge';
    if(str_contains($type,'contact')||str_contains($type,'crm'))return 'contact';
    if(str_contains($type,'profile'))return 'profile';
    if(str_contains($type,'transcript'))return 'transcription';
    if(str_contains($type,'meeting'))return 'meeting';
    if(str_contains($type,'calendar')||str_contains($type,'booking'))return 'calendar';
    return '';
}

function vp3_cognitive_opportunity_pattern_v2320(string $cardType,string $targetType): ?array
{
    $cardType=vp3_cognitive_id_v500($cardType,80);
    $targetGroup=vp3_cognitive_opportunity_type_group_v2320($targetType);

    if(in_array($cardType,['meeting','meeting_brief'],true)
        && in_array($targetGroup,['research','knowledge','contact','profile','transcription'],true)){
        return [
            'id'=>'prepare_with_context',
            'title'=>'Use related context before this meeting',
            'reason'=>'VP3 has an upcoming meeting and explicitly related authorized context. Reviewing the connected evidence together may improve preparation.',
            'urgency'=>0.62,'impact'=>0.72,'goal_relevance'=>0.68,'ttl_hours'=>72,
        ];
    }

    if(in_array($cardType,['goal','workflow'],true)
        && in_array($targetGroup,['research','knowledge'],true)){
        return [
            'id'=>'apply_supporting_evidence',
            'title'=>'Apply related evidence to active work',
            'reason'=>'VP3 has active work with explicitly related Research or Knowledge evidence. Reviewing that evidence may reveal a useful next step.',
            'urgency'=>0.42,'impact'=>0.74,'goal_relevance'=>0.86,'ttl_hours'=>168,
        ];
    }

    if(in_array($cardType,['goal','workflow'],true)
        && in_array($targetGroup,['meeting','calendar'],true)){
        return [
            'id'=>'coordinate_work_commitment',
            'title'=>'Coordinate active work with the related commitment',
            'reason'=>'VP3 has active work and an explicitly related meeting or calendar commitment. Reviewing them together may improve sequencing or preparation.',
            'urgency'=>0.58,'impact'=>0.70,'goal_relevance'=>0.78,'ttl_hours'=>120,
        ];
    }

    return null;
}

function vp3_cognitive_opportunity_stored_edges_v2320(
    PDO $pdo,array $user,string $namespace,array $anchor
): array {
    if(!table_exists('cognitive_relationships_v500'))return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $workspace=max(0,(int)($anchor['workspace_id']??0));
    $stmt=$pdo->prepare(
        "SELECT * FROM cognitive_relationships_v500
         WHERE owner_user_id=?
           AND confirmation_state IN ('deterministic','user_confirmed')
           AND confidence>=?
           AND (valid_until IS NULL OR valid_until>=UTC_TIMESTAMP())
           AND (
             (left_type=? AND left_id=? AND left_scope=? AND left_workspace_id=?)
             OR
             (right_type=? AND right_id=? AND right_scope=? AND right_workspace_id=?)
           )
         ORDER BY confidence DESC,updated_at DESC,id DESC
         LIMIT ".VP3_COGNITIVE_OPPORTUNITIES_MAX_EDGES_V2320
    );
    $stmt->execute([
        $uid,VP3_COGNITIVE_OPPORTUNITIES_MIN_RELATION_CONFIDENCE_V2320,
        $anchor['type'],$anchor['id'],$anchor['scope'],$workspace,
        $anchor['type'],$anchor['id'],$anchor['scope'],$workspace,
    ]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $anchorIsLeft=(string)$row['left_type']===(string)$anchor['type']
            &&(string)$row['left_id']===(string)$anchor['id']
            &&(string)$row['left_scope']===(string)$anchor['scope']
            &&(int)$row['left_workspace_id']===$workspace;
        $prefix=$anchorIsLeft?'right_':'left_';
        $target=[
            'type'=>(string)$row[$prefix.'type'],
            'id'=>(string)$row[$prefix.'id'],
            'scope'=>(string)$row[$prefix.'scope'],
        ];
        $targetWorkspace=(int)$row[$prefix.'workspace_id'];
        if($targetWorkspace>0)$target['workspace_id']=$targetWorkspace;
        try{
            $target=vp3_cognitive_validate_object_ref_v500($target,true);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$target,'read'))continue;
            $out[]=[
                'relation'=>vp3_cognitive_id_v500($row['relation_type']??'relates_to',60),
                'object_ref'=>$target,
                'provenance'=>vp3_cognitive_text_v500($row['provenance']??'cognitive_relationships_v500',120),
                'confidence'=>max(0,min(1,(float)($row['confidence']??0))),
                'confirmation_state'=>(string)($row['confirmation_state']??'deterministic'),
            ];
        }catch(Throwable $e){}
    }
    return $out;
}

function vp3_cognitive_opportunity_edges_v2320(
    PDO $pdo,array $user,string $namespace,array $anchor
): array {
    $edges=vp3_cognitive_opportunity_stored_edges_v2320($pdo,$user,$namespace,$anchor);
    try{
        foreach(vp3_cognitive_relationships_for_ref_v500($pdo,$user,$namespace,$anchor) as $edge){
            if(!is_array($edge))continue;
            $confirmation=(string)($edge['confirmation_state']??'');
            $confidence=(float)($edge['confidence']??0);
            if(!in_array($confirmation,['deterministic','user_confirmed'],true)
                ||$confidence<VP3_COGNITIVE_OPPORTUNITIES_MIN_RELATION_CONFIDENCE_V2320)continue;
            $edges[]=$edge;
        }
    }catch(Throwable $e){}

    $out=[];$seen=[];
    foreach($edges as $edge){
        $target=is_array($edge['object_ref']??null)?$edge['object_ref']:[];
        try{$target=vp3_cognitive_validate_object_ref_v500($target,true);}catch(Throwable $e){continue;}
        $key=vp3_cognitive_json_v500([$target['type'],$target['id'],$target['scope'],$target['workspace_id']??0,$edge['relation']??'']);
        if(isset($seen[$key]))continue;
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$target,'read'))continue;
        $seen[$key]=true;
        $edge['object_ref']=$target;
        $out[]=$edge;
        if(count($out)>=VP3_COGNITIVE_OPPORTUNITIES_MAX_EDGES_V2320)break;
    }
    return $out;
}

function vp3_cognitive_opportunity_existing_v2320(
    PDO $pdo,int $uid,string $namespace,string $observationKey
): ?array {
    $stmt=$pdo->prepare(
        "SELECT id,state,valid_until,reason FROM cognitive_observations_v500
         WHERE owner_user_id=? AND agent_namespace=? AND observation_key=? AND source=? LIMIT 1"
    );
    $stmt->execute([$uid,$namespace,$observationKey,VP3_COGNITIVE_OPPORTUNITIES_SOURCE_V2320]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cognitive_opportunity_store_v2320(
    PDO $pdo,array $user,string $namespace,array $anchor,array $target,array $edge,array $pattern
): string {
    $uid=(int)($user['id']??0);
    $identity=[
        $pattern['id'],
        $anchor['type'],$anchor['id'],$anchor['scope'],$anchor['workspace_id']??0,
        $target['type'],$target['id'],$target['scope'],$target['workspace_id']??0,
        (string)($edge['relation']??'relates_to'),
    ];
    $observationKey='opp2320:'.substr(hash('sha256',vp3_cognitive_json_v500($identity)),0,48);
    $existing=vp3_cognitive_opportunity_existing_v2320($pdo,$uid,$namespace,$observationKey);
    $refresh=false;
    if(is_array($existing)){
        $validTs=strtotime((string)($existing['valid_until']??''))?:0;
        $refresh=(string)($existing['state']??'')!=='active'
            ||$validTs<time()+21600
            ||(string)($existing['reason']??'')!==(string)$pattern['reason'];
    }else $refresh=true;

    if(!$refresh)return $observationKey;

    $truth=(string)($edge['confirmation_state']??'')==='user_confirmed'?'user_confirmed':'database_fact';
    $confidence=max(
        VP3_COGNITIVE_OPPORTUNITIES_MIN_RELATION_CONFIDENCE_V2320,
        min(.99,(float)($edge['confidence']??.85))
    );
    vp3_cognitive_observation_store_v500($pdo,$user,$namespace,[
        'observation_id'=>$observationKey,
        'category'=>'opportunity',
        'title'=>(string)$pattern['title'],
        'reason'=>(string)$pattern['reason'],
        'evidence_refs'=>[
            [
                'truth_type'=>'database_fact',
                'object_ref'=>$anchor,
                'statement'=>'Current authorized VP3 work or commitment.',
                'occurred_at'=>gmdate(DATE_ATOM),
            ],
            [
                'truth_type'=>$truth,
                'object_ref'=>$target,
                'statement'=>'Explicitly related authorized VP3 context.',
                'occurred_at'=>gmdate(DATE_ATOM),
            ],
        ],
        'confidence'=>$confidence,
        'novelty'=>0.68,
        'urgency'=>(float)$pattern['urgency'],
        'impact'=>(float)$pattern['impact'],
        'goal_relevance'=>(float)$pattern['goal_relevance'],
        'valid_until'=>gmdate(DATE_ATOM,time()+((int)$pattern['ttl_hours']*3600)),
        'proposed_action_ids'=>[],
        'proposed_cards'=>[],
        'presentation_recommendation'=>'brief',
        'voice_safe_summary'=>'',
        'source'=>VP3_COGNITIVE_OPPORTUNITIES_SOURCE_V2320,
        'source_event_uuid'=>'',
    ]);
    return $observationKey;
}

function vp3_cognitive_opportunity_resolve_stale_v2320(
    PDO $pdo,array $user,string $namespace,array $activeKeys
): void {
    $uid=(int)($user['id']??0);if($uid<1)return;
    $params=[$uid,$namespace,VP3_COGNITIVE_OPPORTUNITIES_SOURCE_V2320];
    $sql="UPDATE cognitive_observations_v500
          SET state='resolved',updated_at=UTC_TIMESTAMP()
          WHERE owner_user_id=? AND agent_namespace=? AND source=? AND state='active'";
    if($activeKeys){
        $placeholders=implode(',',array_fill(0,count($activeKeys),'?'));
        $sql.=" AND observation_key NOT IN ({$placeholders})";
        foreach($activeKeys as $key)$params[]=$key;
    }
    $pdo->prepare($sql)->execute($params);
}

function vp3_cognitive_opportunity_sync_v2320(
    PDO $pdo,array $user,string $namespace,array $candidates
): array {
    if(!vp3_cognitive_schema_ready_v500($pdo))return ['detected'=>0,'keys'=>[]];
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $active=[];$detected=0;$anchors=0;

    foreach($candidates as $candidate){
        if(!is_array($candidate))continue;
        $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
        $cardType=vp3_cognitive_id_v500($request['card_type']??'',80);
        if(!in_array($cardType,['meeting','meeting_brief','goal','workflow'],true))continue;
        if(++$anchors>VP3_COGNITIVE_OPPORTUNITIES_MAX_ANCHORS_V2320)break;
        $anchor=vp3_cognitive_opportunity_ref_v2320($candidate);
        if(!$anchor)continue;

        foreach(vp3_cognitive_opportunity_edges_v2320($pdo,$user,$namespace,$anchor) as $edge){
            $target=is_array($edge['object_ref']??null)?$edge['object_ref']:[];
            $pattern=vp3_cognitive_opportunity_pattern_v2320($cardType,(string)($target['type']??''));
            if(!$pattern)continue;
            try{
                $key=vp3_cognitive_opportunity_store_v2320($pdo,$user,$namespace,$anchor,$target,$edge,$pattern);
                $active[$key]=true;$detected++;
            }catch(Throwable $e){}
            if($detected>=VP3_COGNITIVE_OPPORTUNITIES_MAX_DETECTIONS_V2320)break 2;
        }
    }

    vp3_cognitive_opportunity_resolve_stale_v2320($pdo,$user,$namespace,array_keys($active));
    return ['detected'=>$detected,'keys'=>array_keys($active)];
}
