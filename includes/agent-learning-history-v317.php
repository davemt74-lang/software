<?php
declare(strict_types=1);

/**
 * Read-only Agent Brain learning-history projection.
 *
 * Reuses the canonical agent_proactive_events ledger and v313 outcome-factor
 * math. No second learner, outcome table, or recommendation identity exists.
 */
const VP3_AGENT_LEARNING_HISTORY_V317='agent-brain-learning-history-v317-20260907';
const VP3_AGENT_LEARNING_HISTORY_WINDOW_DAYS_V317=60;
const VP3_AGENT_LEARNING_HISTORY_FETCH_LIMIT_V317=600;

function agent_learning_history_v317_context(string $json): array
{
    if(function_exists('agent_action_v313_feedback_context'))return agent_action_v313_feedback_context($json);
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:[];
}

function agent_learning_history_v317_outcome(array $row): string
{
    $eventType=(string)($row['event_type']??'');
    $context=is_array($row['context']??null)?$row['context']:agent_learning_history_v317_context((string)($row['context_json']??''));
    if(function_exists('agent_action_v313_normalize_outcome')){
        return agent_action_v313_normalize_outcome((string)($context['outcome']??$context['result']??''),$eventType);
    }
    if($eventType==='dismissed')return 'ignored';
    if($eventType==='acted')return 'acted';
    return '';
}

/** Reproduce the v313 source-weight snapshot using only rows known at $atTs. */
function agent_learning_history_v317_factor_snapshot(array $rows,int $atTs): array
{
    $feedback=[
        'successful'=>0,'resolved'=>0,'unsuccessful'=>0,'ignored'=>0,'acted_unresolved'=>0,
    ];
    if($atTs<1)return $feedback+['factor'=>1.0];
    $cutoff=$atTs-(VP3_AGENT_LEARNING_HISTORY_WINDOW_DAYS_V317*86400);
    $eligible=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $eventType=(string)($row['event_type']??'');
        if(!in_array($eventType,['acted','dismissed'],true))continue;
        $ts=strtotime((string)($row['created_at']??''))?:0;
        if($ts<$cutoff||$ts>$atTs)continue;
        $eligible[]=$row;
    }
    usort($eligible,static fn(array $a,array $b):int=>(int)($b['id']??0)<=>(int)($a['id']??0));

    $finalized=[];$unresolved=[];
    foreach($eligible as $row){
        $hash=(string)($row['suggestion_hash']??'');
        $outcome=agent_learning_history_v317_outcome($row);
        if($outcome==='acted'){
            if($hash!==''&&(isset($finalized[$hash])||isset($unresolved[$hash])))continue;
            $feedback['acted_unresolved']++;
            if($hash!=='')$unresolved[$hash]=true;
            continue;
        }
        if(!array_key_exists($outcome,$feedback))continue;
        $feedback[$outcome]++;
        if($hash!=='')$finalized[$hash]=true;
    }
    $factor=function_exists('agent_action_v124_outcome_factor')
        ? agent_action_v124_outcome_factor($feedback)
        : 1.0;
    $feedback['factor']=round((float)$factor,4);
    return $feedback;
}

function agent_learning_history_v317_rows(PDO $pdo,array $user,int $limit=80): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!table_exists('agent_proactive_events'))return [];
    $fetch=VP3_AGENT_LEARNING_HISTORY_FETCH_LIMIT_V317;
    try{
        $stmt=$pdo->prepare(
            "SELECT * FROM (
                SELECT id,suggestion_hash,surface,event_type,title,prompt,source_kind,context_json,created_at
                FROM agent_proactive_events
                WHERE user_id=?
                ORDER BY id DESC
                LIMIT {$fetch}
             ) recent
             ORDER BY id ASC"
        );
        $stmt->execute([$uid]);
        $events=$stmt->fetchAll()?:[];
    }catch(Throwable $e){
        return [];
    }

    $identity=[];$open=[];$sourceRows=[];$history=[];
    foreach($events as $event){
        if(!is_array($event))continue;
        $hash=strtolower(trim((string)($event['suggestion_hash']??'')));
        if(!preg_match('/^[a-f0-9]{40}$/',$hash))continue;
        $context=agent_learning_history_v317_context((string)($event['context_json']??''));
        $event['context']=$context;
        $title=trim((string)($event['title']??''));
        $prompt=trim((string)($event['prompt']??''));
        $source=trim((string)($event['source_kind']??''));
        $surface=trim((string)($event['surface']??''));
        $created=(string)($event['created_at']??'');
        $ts=strtotime($created)?:0;

        $prior=$identity[$hash]??['title'=>'','prompt'=>'','source'=>''];
        if($title!=='')$prior['title']=$title;
        if($prompt!=='')$prior['prompt']=$prompt;
        if($source!=='')$prior['source']=$source;
        $identity[$hash]=$prior;
        if($title==='')$title=(string)$prior['title'];
        if($prompt==='')$prompt=(string)$prior['prompt'];
        if($source==='')$source=(string)$prior['source'];
        $event['source_kind']=$source;

        $eventType=(string)($event['event_type']??'');
        $outcome=agent_learning_history_v317_outcome($event);
        if($eventType==='shown'){
            $open[$hash]=[
                'hash'=>$hash,'title'=>$title,'prompt'=>$prompt,'source'=>$source,
                'shown_at'=>$created,'shown_surface'=>$surface,'stage'=>'shown','acted_at'=>'',
                'score'=>is_numeric($context['score']??null)?round((float)$context['score'],4):null,
            ];
        }elseif($eventType==='acted'&&$outcome==='acted'){
            if(!isset($open[$hash])){
                $open[$hash]=[
                    'hash'=>$hash,'title'=>$title,'prompt'=>$prompt,'source'=>$source,
                    'shown_at'=>'','shown_surface'=>'','stage'=>'acted','acted_at'=>$created,
                    'score'=>is_numeric($context['score']??null)?round((float)$context['score'],4):null,
                ];
            }else{
                $open[$hash]['stage']='acted';
                $open[$hash]['acted_at']=$created;
                if((string)$open[$hash]['source']===''&&$source!=='')$open[$hash]['source']=$source;
            }
        }

        $isFinal=in_array($outcome,['successful','resolved','unsuccessful','ignored'],true);
        if($isFinal){
            $before=agent_learning_history_v317_factor_snapshot($source!==''?($sourceRows[$source]??[]):[],$ts);
            if($source!=='')$sourceRows[$source][]=$event;
            $after=agent_learning_history_v317_factor_snapshot($source!==''?($sourceRows[$source]??[]):[],$ts);
            $exposure=$open[$hash]??[
                'title'=>$title,'prompt'=>$prompt,'source'=>$source,'shown_at'=>'','shown_surface'=>'',
                'stage'=>'historical','acted_at'=>'','score'=>null,
            ];
            $sourceLabel=trim((string)($context['source_label']??''));
            $history[]=[
                'id'=>(int)($event['id']??0),
                'hash'=>$hash,
                'title'=>trim((string)($exposure['title']??''))?:($title!==''?$title:'Agent Brain recommendation'),
                'source'=>$source!==''?$source:(string)($exposure['source']??'agent_brain'),
                'source_label'=>$sourceLabel,
                'shown_at'=>(string)($exposure['shown_at']??''),
                'shown_surface'=>(string)($exposure['shown_surface']??''),
                'acted_at'=>(string)($exposure['acted_at']??''),
                'outcome'=>$outcome,
                'outcome_at'=>$created,
                'outcome_surface'=>$surface,
                'automatic'=>!empty($context['automatic']),
                'trigger'=>trim((string)($context['trigger']??'')),
                'task_status'=>trim((string)($context['task_status']??'')),
                'conversion_event_name'=>trim((string)($context['conversion_event_name']??'')),
                'source_url'=>trim((string)($context['source_url']??'')),
                'score_at_surface'=>$exposure['score']??null,
                'factor_before'=>(float)($before['factor']??1),
                'factor_after'=>(float)($after['factor']??1),
                'factor_delta'=>round((float)($after['factor']??1)-(float)($before['factor']??1),4),
                'build'=>VP3_AGENT_LEARNING_HISTORY_V317,
            ];
            unset($open[$hash]);
            continue;
        }

        if($source!=='')$sourceRows[$source][]=$event;
    }

    // Surface still-open recommendation cycles too, so the audit answers what
    // the Brain showed even when a final result has not been recorded yet.
    foreach($open as $exposure){
        if(!is_array($exposure))continue;
        $source=(string)($exposure['source']??'agent_brain');
        $snapshot=agent_learning_history_v317_factor_snapshot($source!==''?($sourceRows[$source]??[]):[],time());
        $history[]=[
            'id'=>0,'hash'=>(string)($exposure['hash']??''),
            'title'=>trim((string)($exposure['title']??''))?:'Agent Brain recommendation',
            'source'=>$source,'source_label'=>'',
            'shown_at'=>(string)($exposure['shown_at']??''),
            'shown_surface'=>(string)($exposure['shown_surface']??''),
            'acted_at'=>(string)($exposure['acted_at']??''),
            'outcome'=>(string)($exposure['stage']??'shown')==='acted'?'acted':'awaiting',
            'outcome_at'=>(string)($exposure['acted_at']??''),
            'outcome_surface'=>'','automatic'=>false,'trigger'=>'','task_status'=>'','conversion_event_name'=>'','source_url'=>'',
            'score_at_surface'=>$exposure['score']??null,
            'factor_before'=>(float)($snapshot['factor']??1),
            'factor_after'=>(float)($snapshot['factor']??1),
            'factor_delta'=>0.0,
            'build'=>VP3_AGENT_LEARNING_HISTORY_V317,
        ];
    }

    usort($history,static function(array $a,array $b):int{
        $aTime=strtotime((string)($a['outcome_at']??$a['shown_at']??''))?:0;
        $bTime=strtotime((string)($b['outcome_at']??$b['shown_at']??''))?:0;
        return $bTime<=>$aTime ?: ((int)($b['id']??0)<=>(int)($a['id']??0));
    });
    return array_slice($history,0,max(1,min(160,$limit)));
}

function agent_learning_history_v317_state(array $user,int $limit=80): array
{
    $pdo=db();$uid=(int)($user['id']??0);
    $empty=[
        'build'=>VP3_AGENT_LEARNING_HISTORY_V317,
        'rows'=>[],
        'summary'=>['learned'=>0,'successful'=>0,'resolved'=>0,'unsuccessful'=>0,'ignored'=>0,'open'=>0,'sources'=>[]],
    ];
    if(!$pdo||$uid<1||!table_exists('agent_proactive_events'))return $empty;

    $rows=agent_learning_history_v317_rows($pdo,$user,$limit);
    $summary=['learned'=>0,'successful'=>0,'resolved'=>0,'unsuccessful'=>0,'ignored'=>0,'open'=>0,'sources'=>[]];
    $sources=[];
    foreach($rows as $row){
        $outcome=(string)($row['outcome']??'');
        if(in_array($outcome,['successful','resolved','unsuccessful','ignored'],true)){
            $summary['learned']++;
            $summary[$outcome]++;
        }else{
            $summary['open']++;
        }
        $source=trim((string)($row['source']??''));
        if($source!=='')$sources[$source]=true;
    }

    foreach(array_keys($sources) as $source){
        $feedback=function_exists('agent_action_v124_source_feedback')
            ? agent_action_v124_source_feedback($uid,$source)
            : [];
        $factor=function_exists('agent_action_v124_outcome_factor')
            ? agent_action_v124_outcome_factor($feedback)
            : 1.0;
        $summary['sources'][]=[
            'source'=>$source,
            'factor'=>round((float)$factor,4),
            'successful'=>max(0,(int)($feedback['successful']??0)),
            'resolved'=>max(0,(int)($feedback['resolved']??0)),
            'unsuccessful'=>max(0,(int)($feedback['unsuccessful']??0)),
            'ignored'=>max(0,(int)($feedback['ignored']??0)),
            'acted_unresolved'=>max(0,(int)($feedback['acted_unresolved']??0)),
        ];
    }
    usort($summary['sources'],static function(array $a,array $b):int{
        $evidenceA=(int)$a['successful']+(int)$a['resolved']+(int)$a['unsuccessful']+(int)$a['ignored']+(int)$a['acted_unresolved'];
        $evidenceB=(int)$b['successful']+(int)$b['resolved']+(int)$b['unsuccessful']+(int)$b['ignored']+(int)$b['acted_unresolved'];
        return $evidenceB<=>$evidenceA ?: abs((float)$b['factor']-1)<=>abs((float)$a['factor']-1);
    });
    $summary['sources']=array_slice($summary['sources'],0,12);

    return ['build'=>VP3_AGENT_LEARNING_HISTORY_V317,'rows'=>$rows,'summary'=>$summary];
}
