<?php
declare(strict_types=1);

require_once __DIR__.'/profile-agent-responses-v1.php';

/** Query-only adapters for the existing private Profile completed-period reader. */
const VP3_PROFILE_RESPONSE_ANALYTICS_V1='vp3.profile-response-analytics.v1';

function vp3_profile_response_analytics_catalog_v1(): array
{
    return [
        'owner_requests_submitted'=>['count','Questions submitted to the owner','Distinct durable owner requests first submitted in this period; retries and re-submissions of the same visitor message count once. Records before owner-request tracking are not included.'],
        'knowledge_gap_requests'=>['count','Questions with a knowledge gap','First submissions whose original reason proves the allowed knowledge was insufficient. User responses, service failures and quota limits are separate.'],
        'user_response_requests'=>['count','Questions sent directly to the owner','First submissions made under User responses, with no automated answer.'],
        'service_unavailable_requests'=>['count','Questions submitted after service failure','First submissions with a verified service_unavailable reason; these are not knowledge gaps.'],
        'quota_exhausted_requests'=>['count','Questions submitted after an allowance limit','First submissions with a verified quota_exhausted reason; these are not knowledge gaps.'],
        'unknown_reason_requests'=>['count','Questions with an unknown original reason','Recorded requests whose original reason was not preserved; later reasons are never substituted for the original reason.'],
        'owner_requests_answered'=>['count','Submitted questions answered by the owner','Distinct recorded requests answered by a verified owner reply in this period, including requests submitted earlier. One reply may answer several questions.'],
        'owner_response_messages'=>['count','Owner replies answering submitted questions','Distinct verified owner messages answering one or more durable owner requests in this period.'],
        'median_owner_response_seconds'=>['seconds','Median owner response time','Median time from first durable submission to the verified owner reply, for requests answered in this period. Includes any time delegated back to the Agent; unanswered requests are not timing samples.'],
        'p90_owner_response_seconds'=>['seconds','90th percentile owner response time','Nearest-rank 90th percentile of first-submission-to-owner-reply times for verified requests answered in this period; unanswered requests are not timing samples.'],
    ];
}

function vp3_profile_response_analytics_reasons_v1(): array
{
    return ['knowledge_gap'=>'Knowledge gap','user_responses'=>'User responses','service_unavailable'=>'Service unavailable','quota_exhausted'=>'Allowance limit','unknown'=>'Original reason unknown'];
}

function vp3_profile_response_analytics_empty_v1(string $reason='storage_unconfigured'): array
{
    $side=['coverage'=>'unavailable','reason'=>$reason,'reason_coverage'=>'unavailable','unknown_reason_count'=>null,
        'response_sample_size'=>null,'questions'=>[],'topics'=>[],'questions_truncated'=>false,'topics_truncated'=>false];
    $metrics=[];
    foreach(vp3_profile_response_analytics_catalog_v1() as $key=>[$unit,$label,$definition])
        $metrics[]=vp3_profile_analytics_metric_v1($key,'owner_responses',$label,$unit,$definition,
            vp3_profile_analytics_point_v1(null,'unavailable',$reason),vp3_profile_analytics_point_v1(null,'unavailable',$reason));
    return ['activity'=>['contract'=>VP3_PROFILE_RESPONSE_ANALYTICS_V1,'coverage'=>'unavailable','reason'=>$reason,
        'record_scope'=>'recorded_owner_requests','metrics'=>$metrics,'current'=>$side,'previous'=>$side],
        'backlog'=>['contract'=>VP3_PROFILE_RESPONSE_ANALYTICS_V1,'coverage'=>'unavailable','reason'=>$reason,
            'waiting_count'=>null,'observed_waiting_count'=>null,'delegated_count'=>null,'observed_delegated_count'=>null,
            'oldest_wait_seconds'=>null,'median_wait_seconds'=>null,'unknown_count'=>null,'reasons'=>[],
            'oldest'=>[],'questions'=>[],'topics'=>[],'questions_truncated'=>false,'topics_truncated'=>false,'oldest_truncated'=>false,
            'knowledge'=>['coverage'=>'unavailable','reason'=>$reason,'candidates_truncated'=>false]],'evidence'=>[]];
}

function vp3_profile_response_analytics_text_v1(mixed $text,int $limit=240): string
{
    if(!is_string($text)||!mb_check_encoding($text,'UTF-8'))return '';
    return trim(mb_substr(trim((string)preg_replace('/[\p{Cc}\p{Cf}\p{Z}]+/u',' ',strip_tags($text))),0,$limit,'UTF-8'));
}

/** Conservative wording groups: case, punctuation and whitespace only, never guessed intent. */
function vp3_profile_response_analytics_words_v1(string $question,int $textLimit=8000,int $wordLimit=48): array
{
    $normal=mb_strtolower(vp3_profile_response_analytics_text_v1($question,$textLimit),'UTF-8');
    $normal=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$normal));
    $stop=array_fill_keys(explode(' ','a an and are as at be been but by can could did do does for from had has have how i if in is it its me my of on or our please tell that the their them there these they this to us was we were what when where which who why will with would you your'),true);
    $words=[];
    foreach(explode(' ',$normal) as $word)if(mb_strlen($word)>=3&&mb_strlen($word)<=80&&!isset($stop[$word])&&!preg_match('/\A[0-9]+\z/D',$word))$words[$word]=true;
    return ['key'=>hash('sha256',$normal),'words'=>array_slice(array_keys($words),0,$wordLimit)];
}

function vp3_profile_response_analytics_groups_v1(array $rows): array
{
    $groups=[];$topics=[];
    foreach($rows as $row){
        $words=vp3_profile_response_analytics_words_v1($row['question']);
        foreach($words['words'] as $word)$topics[$word]=($topics[$word]??0)+1;
        $key=$words['key'];
        if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'question_excerpt'=>vp3_profile_response_analytics_text_v1($row['question']),
            'count'=>0,'conversation_count'=>0,'samples'=>[],'_conversations'=>[]];
        $groups[$key]['count']++;$groups[$key]['_conversations'][$row['conversation_id']]=true;
        if(count($groups[$key]['samples'])<3)$groups[$key]['samples'][]=array_intersect_key($row,array_flip(['request_id','conversation_id','visitor_message_id']));
    }
    $groups=array_values($groups);
    foreach($groups as &$group){$group['conversation_count']=count($group['_conversations']);unset($group['_conversations']);}unset($group);
    usort($groups,static fn(array $a,array $b):int=>($b['count']<=>$a['count'])?:strcmp($a['key'],$b['key']));
    $topicRows=[];foreach($topics as $keyword=>$count)$topicRows[]=['keyword'=>$keyword,'count'=>$count];
    usort($topicRows,static fn(array $a,array $b):int=>($b['count']<=>$a['count'])?:strcmp($a['keyword'],$b['keyword']));
    return ['questions'=>array_slice($groups,0,10),'topics'=>array_slice($topicRows,0,10),
        'questions_truncated'=>count($groups)>10,'topics_truncated'=>count($topicRows)>10];
}

/** Exact native owner reply operation, including a reply that covers earlier submitted questions. */
function vp3_profile_response_analytics_reply_v1(array $request,array $history,array $turns,array $operations,int $owner): ?array
{
    $mid=(int)($request['reply_message_id']??0);$cid=(int)$request['conversation_id'];$question=(int)$request['visitor_message_id'];
    $message=$history['messages'][$mid]??null;$meta=$message?vp3_profile_analytics_json_v1($message['context_json']??null):null;
    $marker=$meta['profile_handoff']??null;$turn=$turns[(int)($marker['turn_id']??0)]??null;
    if(!$message||$message['sender_type']!=='owner'||(int)($message['sender_user_id']??0)!==$owner||(int)$message['conversation_id']!==$cid
        ||$mid<=$question||!is_array($marker)||($marker['contract']??null)!=='vp3.profile-handoff.v1'||!$turn
        ||(int)$turn['conversation_id']!==$cid||(int)$turn['visitor_message_id']<$question
        ||!is_string($marker['owner_request_id']??null))return null;
    $matches=$operations[$marker['owner_request_id']]??[];
    if(count($matches)!==1)return null;$op=$matches[0];
    $at=vp3_profile_analytics_epoch_v1($op['created_at_ms']??null);
    if($op['operation']!=='reply'||(int)$op['message_id']!==$mid||(int)$op['turn_id']!==(int)$turn['id']
        ||$at===null||$at<(int)$request['created_at_ms'])return null;
    return ['message_id'=>$mid,'at'=>$at,'request_id'=>$op['request_id']];
}

/** No current public enablement is required to report the owner's durable private history. */
function vp3_profile_response_analytics_v1(PDO $pdo,int $owner,array $window,array $history,int $now): array
{
    $out=vp3_profile_response_analytics_empty_v1();
    if(!vp3_profile_analytics_tables_v1($pdo,['profile_agent_owner_requests_v1','profile_agent_handoff_turns_pa3','profile_agent_handoff_operations_pa3']))return $out;
    $requests=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_owner_requests_v1 WHERE owner_user_id=? ORDER BY created_at_ms DESC,id DESC LIMIT 10001',[$owner],true);
    $truncated=count($requests)>10000;$requests=array_slice($requests,0,10000);
    $turnRows=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $operationRows=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? ORDER BY request_id',[$owner],true);
    $turns=[];$byMessage=[];$ops=[];$receipts=[];
    foreach($turnRows as $turn)if(vp3_profile_analytics_turn_valid_v1($turn,$history,$owner)){
        $turns[(int)$turn['id']]=$turn;$byMessage[(int)$turn['visitor_message_id']][]=$turn;
    }
    foreach($operationRows as $op)$ops[(string)$op['request_id']][]=$op;
    foreach($history['messages'] as $message)if($message['sender_type']==='receipt'){
        $ctx=vp3_profile_analytics_json_v1($message['context_json']??null);
        if(($ctx['contract']??null)===VP3_PROFILE_RESPONSE_V1&&is_int($ctx['request_id']??null))$receipts[$ctx['request_id']][]=['row'=>$message,'context'=>$ctx];
    }
    $catalog=vp3_profile_response_analytics_catalog_v1();$reasonKeys=['knowledge_gap'=>'knowledge_gap_requests','user_responses'=>'user_response_requests',
        'service_unavailable'=>'service_unavailable_requests','quota_exhausted'=>'quota_exhausted_requests','unknown'=>'unknown_reason_requests'];
    $counts=[];$gaps=[];$answeredGaps=[];$samples=[];$replyIds=[];$questions=[];$allQuestions=[];
    foreach(['current','previous'] as $side){$counts[$side]=array_fill_keys(array_keys($catalog),0);$gaps[$side]=$answeredGaps[$side]=$truncated;
        $samples[$side]=$replyIds[$side]=$questions[$side]=$allQuestions[$side]=[];}
    $waiting=[];$delegated=0;$waitingReasons=[];$unverified=0;$liveGap=$truncated;$liveQuestions=[];$seen=[];
    $mark=static function(array &$values,?int $at)use($window):void{
        if($at===null){$values['current']=$values['previous']=true;return;}
        $side=vp3_profile_analytics_window_key_v1($at,$window);if($side!==null)$values[$side]=true;
    };
    foreach($requests as $request){
        $id=(int)$request['id'];$cid=(int)$request['conversation_id'];$mid=(int)$request['visitor_message_id'];
        $created=vp3_profile_analytics_epoch_v1($request['created_at_ms']??null);$updated=vp3_profile_analytics_epoch_v1($request['updated_at_ms']??null);
        $status=(string)$request['status'];$reason=(string)$request['reason'];$turnList=$byMessage[$mid]??[];
        $conversation=$history['conversations'][$cid]??null;$message=$history['messages'][$mid]??null;
        $current=[];$original=[];
        foreach($receipts[$id]??[] as $receipt){
            $ctx=$receipt['context'];$row=$receipt['row'];
            if((int)$row['conversation_id']!==$cid||(int)$row['id']<=$mid||!empty($row['sender_user_id'])
                ||($ctx['visitor_message_id']??null)!==$mid||!is_int($ctx['revision']??null)||$ctx['revision']<1)continue;
            if((int)$row['id']===(int)$request['receipt_message_id'])$current[]=$receipt;
            if($ctx['revision']===1)$original[]=$receipt;
        }
        $turnAt=count($turnList)===1?vp3_profile_analytics_utc_v1($turnList[0]['created_at']??null):null;
        $valid=$created!==null&&$updated!==null&&$created<=$updated&&$updated<=$now&&count($turnList)===1&&$turnAt!==null&&$created>=$turnAt
            &&$conversation&&isset($history['valid'][$cid])&&$message&&(int)$message['conversation_id']===$cid
            &&(int)$request['profile_agent_id']===(int)$conversation['profile_agent_id']
            &&(int)$turnList[0]['conversation_id']===$cid&&$message['sender_type']==='visitor'
            &&(int)($message['sender_user_id']??0)===(int)($conversation['visitor_user_id']??0)
            &&in_array($status,['waiting','answered','resolved','delegated'],true)&&isset($reasonKeys[$reason])&&$reason!=='unknown'
            &&count($current)===1&&!isset($seen[$mid])&&vp3_profile_response_analytics_text_v1($message['message']??'',8000)!=='';
        if($valid){
            $ctx=$current[0]['context'];$receiptRevision=$ctx['revision'];
            $valid=(int)$request['revision']===$receiptRevision+($status==='waiting'?0:1)
                &&(int)$current[0]['row']['id']>$mid&&empty($current[0]['row']['sender_user_id'])
                &&(!isset($ctx['reason'])||$ctx['reason']===$reason)
                &&(!isset($ctx['submitted_at_ms'])||(is_int($ctx['submitted_at_ms'])&&vp3_profile_analytics_epoch_v1($ctx['submitted_at_ms'])!==null
                    &&$ctx['submitted_at_ms']>=$created&&$ctx['submitted_at_ms']<=$updated))
                &&($status!=='waiting'||in_array($conversation['status'],['open','owner_joined'],true));
        }
        if(!$valid){$mark($gaps,$created);if($status==='answered')$mark($answeredGaps,vp3_profile_analytics_epoch_v1($request['settled_at_ms']??null));
            if(in_array($status,['waiting','delegated'],true)||!in_array($status,['answered','resolved'],true)){$unverified++;$liveGap=true;}continue;}
        $seen[$mid]=true;$question=['request_id'=>$id,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'question'=>(string)$message['message']];
        $originalReason='unknown';
        if(count($original)===1){
            $first=$original[0]['context'];$recorded=$first['reason']??null;
            if(is_string($recorded)&&isset($reasonKeys[$recorded])&&$recorded!=='unknown'&&($first['submitted_at_ms']??null)===$created)$originalReason=$recorded;
            elseif($current[0]['context']['revision']===1&&!array_key_exists('reason',$first)&&!array_key_exists('submitted_at_ms',$first))$originalReason=$reason;
        }
        $side=vp3_profile_analytics_window_key_v1($created,$window);
        if($side!==null){
            $counts[$side]['owner_requests_submitted']++;$counts[$side][$reasonKeys[$originalReason]]++;$allQuestions[$side][]=$question;
            if($originalReason==='knowledge_gap')$questions[$side][]=$question;
            $out['evidence'][]=['owner_request',$id,$cid,$mid,$created,$originalReason,hash('sha256',$question['question'])];
        }
        if($status==='answered'){
            $settled=vp3_profile_analytics_epoch_v1($request['settled_at_ms']??null);
            $reply=vp3_profile_response_analytics_reply_v1($request,$history,$turns,$ops,$owner);
            if(!$reply||$reply['at']>$now||$settled===null||$settled<$created||$settled>$now){$mark($answeredGaps,$reply['at']??$settled);}
            else{
                $replySide=vp3_profile_analytics_window_key_v1($reply['at'],$window);
                if($replySide!==null){$counts[$replySide]['owner_requests_answered']++;$replyIds[$replySide][$reply['message_id']]=true;
                    $samples[$replySide][]=($reply['at']-$created)/1000;
                    $out['evidence'][]=['owner_request_reply',$id,$reply['message_id'],$reply['at'],$created,$reply['request_id']];}
            }
        }
        if($status==='waiting'){
            $waitAt=$current[0]['context']['submitted_at_ms']??$updated;
            // Waiting ledger updates are written only when this exact request
            // is first submitted or reopened. They are not owner reply clocks.
            $waiting[]=$question+['reason'=>$reason,'first_submitted_at_utc_ms'=>$created,'waiting_since_utc_ms'=>$waitAt,
                'wait_seconds'=>($now-$waitAt)/1000,'question_excerpt'=>vp3_profile_response_analytics_text_v1($question['question'])];
            $waitingReasons[$reason]=($waitingReasons[$reason]??0)+1;
            if($reason==='knowledge_gap')$liveQuestions[]=$question;
        }elseif($status==='delegated'&&in_array($conversation['status'],['open','owner_joined'],true)
            &&in_array($turnList[0]['state'],['agent_ready','agent_running'],true))$delegated++;
    }
    $activity=['contract'=>VP3_PROFILE_RESPONSE_ANALYTICS_V1,'coverage'=>'complete','reason'=>'complete','record_scope'=>'recorded_owner_requests','metrics'=>[]];
    foreach(['current','previous'] as $side){
        sort($samples[$side],SORT_NUMERIC);$n=count($samples[$side]);
        $counts[$side]['owner_response_messages']=count($replyIds[$side]);
        $counts[$side]['median_owner_response_seconds']=vp3_profile_analytics_median_v1($samples[$side]);
        $counts[$side]['p90_owner_response_seconds']=$n?$samples[$side][(int)ceil($n*.9)-1]:null;
        $reasonGap=$counts[$side]['unknown_reason_requests']>0;$partial=$gaps[$side]||$answeredGaps[$side]||$reasonGap;
        $activity[$side]=['coverage'=>$partial?'partial':'complete','reason'=>$partial?'source_proof_incomplete':'complete',
            'reason_coverage'=>$gaps[$side]||$reasonGap?'partial':'complete','unknown_reason_count'=>$counts[$side]['unknown_reason_requests'],'response_sample_size'=>$n]
            +vp3_profile_response_analytics_groups_v1($questions[$side]);
        $topicData=vp3_profile_response_analytics_groups_v1($allQuestions[$side]);$activity[$side]['topics']=$topicData['topics'];$activity[$side]['topics_truncated']=$topicData['topics_truncated'];
        if($partial){$activity['coverage']='partial';$activity['reason']='source_proof_incomplete';}
    }
    foreach($catalog as $key=>[$unit,$label,$definition]){
        $points=[];
        foreach(['current','previous'] as $side){
            $replyMetric=in_array($key,['owner_requests_answered','owner_response_messages','median_owner_response_seconds','p90_owner_response_seconds'],true);
            $reasonMetric=in_array($key,['knowledge_gap_requests','user_response_requests','service_unavailable_requests','quota_exhausted_requests'],true);
            $partial=($replyMetric?$answeredGaps[$side]:$gaps[$side])||($reasonMetric&&$counts[$side]['unknown_reason_requests']>0);
            $points[$side]=vp3_profile_analytics_point_v1($counts[$side][$key],$partial?'partial':'complete',$partial?'source_proof_incomplete':'complete');
        }
        $activity['metrics'][]=vp3_profile_analytics_metric_v1($key,'owner_responses',$label,$unit,$definition,$points['current'],$points['previous']);
    }
    usort($waiting,static fn(array $a,array $b):int=>[$a['waiting_since_utc_ms'],$a['request_id']]<=>[$b['waiting_since_utc_ms'],$b['request_id']]);
    $oldest=array_slice($waiting,0,10);foreach($oldest as &$row)unset($row['question']);unset($row);
    $reasonRows=[];foreach(vp3_profile_response_analytics_reasons_v1() as $reason=>$label)if($reason!=='unknown')$reasonRows[]=['reason'=>$reason,'label'=>$label,'count'=>$waitingReasons[$reason]??0];
    $backlog=['contract'=>VP3_PROFILE_RESPONSE_ANALYTICS_V1,'coverage'=>$liveGap?'partial':'complete','reason'=>$liveGap?'source_proof_incomplete':'complete',
        'waiting_count'=>$liveGap?null:count($waiting),'observed_waiting_count'=>$liveGap?count($waiting):null,
        'delegated_count'=>$liveGap?null:$delegated,'observed_delegated_count'=>$liveGap?$delegated:null,
        'oldest_wait_seconds'=>$waiting?$waiting[0]['wait_seconds']:null,'median_wait_seconds'=>vp3_profile_analytics_median_v1(array_column($waiting,'wait_seconds')),
        'unknown_count'=>$unverified,'reasons'=>$reasonRows,'oldest'=>$oldest,'oldest_truncated'=>count($waiting)>10]
        +vp3_profile_response_analytics_groups_v1($liveQuestions);
    $backlog['knowledge']=['coverage'=>'unavailable','reason'=>'authority_unavailable','candidates_truncated'=>false];
    return ['activity'=>$activity,'backlog'=>$backlog,'evidence'=>$out['evidence']];
}

/** Existing policy precedence, evaluated from current locking reads, without touching indexes. */
function vp3_profile_response_analytics_note_allowed_v1(array $principal,int $owner,int $id,array $policies,array $rules): bool
{
    $policy=$policies[(string)$id]??$policies['*']??['owner_agents_allowed'=>1,'stonefellow_shared'=>0];
    if(($principal['kind']??null)==='user_agent'&&(int)($principal['owner_user_id']??0)===$owner){
        $rule=$rules[(string)$id]??$rules['*']??'inherit';
        if($rule==='deny')return false;if($rule==='allow')return true;
        return !empty($policy['owner_agents_allowed']);
    }
    // A system Chat uses the canonical shared-data gate even for its owner's
    // notes; being able to open My Knowledge is not an Agent sharing grant.
    return ($principal['kind']??null)==='system'&&(int)($principal['viewer_user_id']??0)===$owner&&!empty($policy['stonefellow_shared']);
}

/** Suggestions are lexical review links, never edits, policy grants, or generated answers. */
function vp3_profile_response_analytics_suggestions_v1(PDO $pdo,array $scope,array $backlog): array
{
    if(($backlog['coverage']??null)==='unavailable')return $backlog;
    try{$scope=vp3_profile_analytics_authority_current_v1($pdo,$scope,true,['personal_knowledge.access']);}
    catch(RuntimeException $e){if($e->getCode()!==403)throw $e;return $backlog;}
    if(!vp3_profile_analytics_tables_v1($pdo,['knowledge_items','user_data_policies','user_agent_data_rules','artist_transcript_folders_v177'])){
        $backlog['knowledge']['reason']='storage_unconfigured';return $backlog;
    }
    $owner=(int)$scope['user']['id'];$principal=$scope['principal'];$policies=[];$rules=[];
    foreach(vp3_profile_analytics_rows_v1($pdo,"SELECT resource_id,owner_agents_allowed,stonefellow_shared FROM user_data_policies WHERE owner_user_id=? AND resource_type='knowledge' ORDER BY resource_id",[$owner],true) as $row)$policies[(string)$row['resource_id']]=$row;
    if($scope['agent_id']>0)foreach(vp3_profile_analytics_rows_v1($pdo,"SELECT resource_id,access_mode FROM user_agent_data_rules WHERE agent_id=? AND resource_type='knowledge' ORDER BY resource_id",[$scope['agent_id']],true) as $row)$rules[(string)$row['resource_id']]=(string)$row['access_mode'];
    $notes=[];$truncated=false;
    if($backlog['questions']){
        $ids=vp3_profile_analytics_rows_v1($pdo,"SELECT id FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note' ORDER BY id DESC LIMIT 501",[$owner],true);
        $truncated=count($ids)>500;$permitted=[];
        foreach(array_slice($ids,0,500) as $row)if(vp3_profile_response_analytics_note_allowed_v1($principal,$owner,(int)$row['id'],$policies,$rules))$permitted[]=(int)$row['id'];
        foreach(array_chunk($permitted,100) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $rows=vp3_profile_analytics_rows_v1($pdo,"SELECT k.id,k.title,k.folder_id,SUBSTR(k.content_text,1,32000) AS content_text FROM knowledge_items k
                LEFT JOIN artist_transcript_folders_v177 f ON f.id=k.folder_id AND f.created_by_user_id=k.created_by_user_id
                WHERE k.created_by_user_id=? AND k.knowledge_scope='personal' AND k.file_type='personal_note' AND k.id IN ($marks)
                  AND (k.folder_id IS NULL OR k.folder_id=0 OR f.id IS NOT NULL) ORDER BY k.id",[$owner,...$chunk],true);
            foreach($rows as $row){$row['title']=vp3_profile_response_analytics_text_v1($row['title'],190);if($row['title']==='')continue;
                $row['_title']=vp3_profile_response_analytics_words_v1($row['title'])['words'];
                $row['_words']=vp3_profile_response_analytics_words_v1($row['title'].' '.$row['content_text'],32000,512)['words'];$notes[]=$row;}
        }
    }
    foreach($backlog['questions'] as &$group){
        $terms=vp3_profile_response_analytics_words_v1($group['question_excerpt'])['words'];$matches=[];
        foreach($notes as $note){$shared=array_values(array_intersect($terms,$note['_words']));
            if(count($shared)<2)continue;
            $score=count($shared)+2*count(array_intersect($terms,$note['_title']));$id=(int)$note['id'];$folder=max(0,(int)($note['folder_id']??0));
            $matches[]=['knowledge_id'=>$id,'title'=>$note['title'],'folder_id'=>$folder,
                'url'=>function_exists('url')?url('/knowledge.php?folder='.$folder.'&edit='.$id):'/knowledge.php?folder='.$folder.'&edit='.$id,
                'matched_terms'=>array_slice($shared,0,6),'_score'=>$score];
        }
        usort($matches,static fn(array $a,array $b):int=>($b['_score']<=>$a['_score'])?:($a['knowledge_id']<=>$b['knowledge_id']));
        $group['suggestions']=array_slice($matches,0,3);foreach($group['suggestions'] as &$suggestion)unset($suggestion['_score']);unset($suggestion);
    }unset($group);
    $backlog['knowledge']=['coverage'=>$truncated?'partial':'complete','reason'=>$truncated?'source_proof_incomplete':'complete','candidates_truncated'=>$truncated];
    return $backlog;
}
