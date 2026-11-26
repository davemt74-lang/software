<?php
declare(strict_types=1);

// Operator follow-up review uses the existing saved Transcription Intelligence.
// It cannot place calls, run a model, create a task, or send a second Chat report.
require_once __DIR__.'/agent-operator-history-v1.php';
require_once __DIR__.'/agent-operator-objectives-v1.php';

const VP3_OPERATOR_FOLLOWUP_WINDOW_V1=604800;
const VP3_OPERATOR_FOLLOWUP_LIMIT_V1=8;

function vp3_operator_followup_text_v1(mixed $value,int $limit): string
{
    if (!is_string($value)) return '';
    $text=preg_replace('/[\p{Cc}\p{Cf}]|\s+/u',' ',strip_tags($value))??'';
    return mb_substr(trim($text),0,$limit);
}

/** Select only current, open canonical suggestions. No text is interpreted as authority. */
function vp3_operator_followup_items_v1(array $job,array $saved): array
{
    $session=(int)$job['session_id'];$hash=(string)$job['transcript_hash'];
    if ($session<1 || !preg_match('/^[a-f0-9]{64}$/D',$hash)
        || ($saved['session_id']??null)!==$session || ($saved['fresh']??null)!==true
        || ($saved['source_hash']??null)!==$hash) return [];
    $out=[];$seen=[];
    foreach (is_array($saved['research_candidates']??null)?$saved['research_candidates']:[] as $item) {
        if (!is_array($item) || ($item['kind']??null)!=='followup'
            || ($item['session_id']??null)!==$session || ($item['source_hash']??null)!==$hash
            || !in_array($item['review_state']??null,['unreviewed','accepted'],true)) continue;
        $plugin=$item['plugin']??null;$section=$item['section']??null;$id=$item['item_id']??null;
        $revision=$item['item_revision']??null;
        if (!is_string($plugin)||!preg_match('/^[a-z0-9_-]{1,64}$/D',$plugin)
            || !is_string($section)||!preg_match('/^[a-z0-9_-]{1,64}$/D',$section)
            || !is_string($id)||!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$id)
            || !is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)) continue;
        $text=vp3_operator_followup_text_v1($item['question']??null,300);
        $next=vp3_operator_followup_text_v1($item['next_step']??null,280);
        if ($text==='') continue;
        $key=hash('sha256',$plugin.':'.$section.':'.$id);
        if (isset($seen[$key])) continue;
        $seen[$key]=true;
        // The canonical item supplies the evidence page, never a navigation
        // destination. Reconstruct the trusted local path and exact item query.
        $params=['session'=>$session,'plugin'=>$plugin,'section'=>$section,'item'=>$id];
        $source=$item['source_url']??'';
        if (is_string($source)) {
            $query=parse_url($source,PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query,$parts);
                if (is_string($parts['page']??null)&&preg_match('/^[1-9][0-9]{0,5}$/D',$parts['page'])
                    && (int)$parts['page']<=(int)($saved['page_count']??0)) $params['page']=(int)$parts['page'];
            }
        }
        $path=parse_url(url('/artist-listening-evidence.php'),PHP_URL_PATH);
        if (!is_string($path)||!str_starts_with($path,'/')||str_starts_with($path,'//')) continue;
        $out[]=['key'=>$key,'session_id'=>$session,'text'=>$text,'next_step'=>$next,
            'review_state'=>$item['review_state'],'source_url'=>$path.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),
            'revision'=>$revision];
    }
    // Owner-reviewed, still-untracked items come first. Keep candidate scope bounded.
    usort($out,static fn(array $a,array $b):int=>($b['review_state']==='accepted')<=>($a['review_state']==='accepted'));
    return array_slice($out,0,3);
}

/**
 * Only reports actually delivered into this exact owner Chat are considered.
 * A different Agent, discarded source, stale analysis, or removed delivery
 * cannot produce a candidate. The injectable reader is a server-test seam only.
 */
function vp3_operator_followup_queue_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $refs=null,?callable $read=null,?callable $objectiveRead=null): array
{
    $empty=['coverage'=>'unavailable','reports'=>[]];
    $cid=(int)($scope['conversation_id']??0);$agent=(int)($scope['agent_id']??-1);
    if ($cid<1||$agent<0||!is_array($scope['user']??null)||!is_array($scope['principal']??null))
        return array_replace($empty,['coverage'=>'unconfigured']);
    try {
        if ((function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)
            || (function_exists('knowledge_retrieval_v162_owner_session')&&!knowledge_retrieval_v162_owner_session($scope['user'],$scope['principal'])))
            return array_replace($empty,['coverage'=>'unconfigured']);
        if (!vp3_phone_workspace_available_v1($pdo,$scope['user'])) return array_replace($empty,['coverage'=>'unconfigured']);
        $phone=vp3_phone_workspace_scope_v1($pdo,$scope['user'],['agent_id'=>$agent,'conversation_id'=>$cid]);
        $user=$phone['user'];$owner=$phone['owner_user_id'];
        if (!vp3_phone_package_v100($pdo,$owner)['voice']||!has_permission('artist_listening.access',$user))
            return array_replace($empty,['coverage'=>'unconfigured']);
        if (!vp3_phone_recording_schema_ready_v1($pdo)) return $empty;
        $sql="SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND conversation_id=?
            AND state='ready' AND session_id>0 AND report_message_id>0 AND analysis_hash<>'' AND created_epoch>? AND created_epoch<=?";
        $args=[$owner,$cid,$now->getTimestamp()-VP3_OPERATOR_FOLLOWUP_WINDOW_V1,$now->getTimestamp()];
        if ($agent>0) {$sql.=' AND agent_id=?';$args[]=$agent;}
        if ($refs!==null) {
            if (!$refs||count($refs)>24) return ['coverage'=>'ready','reports'=>[]];
            foreach ($refs as $ref) if (!is_string($ref)||!preg_match('/^followup:[a-f0-9]{32}$/D',$ref)) return $empty;
            $sql.=' AND public_id IN ('.implode(',',array_fill(0,count($refs),'?')).')';
            foreach ($refs as $ref) $args[]=substr($ref,9);
        }
        $limit=$refs===null?VP3_OPERATOR_FOLLOWUP_LIMIT_V1+1:24;
        $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT '.$limit);$q->execute($args);
        $jobs=$q->fetchAll(PDO::FETCH_ASSOC);
        $coverage=$refs===null&&count($jobs)>VP3_OPERATOR_FOLLOWUP_LIMIT_V1?'limited':'ready';
        if ($refs===null) $jobs=array_slice($jobs,0,VP3_OPERATOR_FOLLOWUP_LIMIT_V1);
        if (!$jobs) return ['coverage'=>$coverage,'reports'=>[]];
        if ($read===null) {
            require_once __DIR__.'/transcript-agent-v1.php';
            $read=static fn(int $session):array=>vp3_transcript_intelligence_v1($pdo,$user,$scope['principal'],$session,'research_candidates');
        }
        $reports=[];$seenQuestions=[];
        foreach ($jobs as $job) {
            try {
                if (!preg_match('/^[a-f0-9]{32}$/D',(string)$job['public_id'])
                    || !preg_match('/^[a-f0-9]{64}$/D',(string)$job['analysis_hash'])) continue;
                $source=vp3_phone_recording_call_v1($pdo,$job);
                $managedPolicy=null;
                if(($source['transport_kind']??'')==='managed_callback'){
                    require_once __DIR__.'/agent-operator-managed-intelligence-v1.php';
                    $managedPolicy=vp3_managed_intelligence_policy_v1($pdo,$phone,$job,$source);
                    if(empty($managedPolicy['analyze'])||!vp3_managed_intelligence_continuity_v1($pdo,$phone,$source,$now->getTimestamp()))continue;
                }
                $evidence=vp3_operator_recording_evidence_v1($pdo,$user,$agent,$job,$source);
                if (!$evidence||!$evidence['transcript_saved']||!$evidence['report_saved']) continue;
                $saved=$read((int)$job['session_id']);
                if (!is_array($saved)||empty($saved['fresh'])||($saved['source_hash']??null)!==$job['transcript_hash']) continue;
                if($managedPolicy!==null)$saved=vp3_managed_intelligence_candidates_v1($saved,$managedPolicy);
                $items=vp3_operator_followup_items_v1($job,$saved);
                $agenda=vp3_operator_objectives_for_job_v1($pdo,$phone,$job,$objectiveRead);
                $agendaItems=[];
                foreach($agenda['questions'] as $question){
                    if($question['state']==='unverified')continue;
                    $identity=$agenda['agenda_fingerprint'].':'.$question['key'];
                    $normalized=vp3_operator_objectives_key_v1($question['question']);
                    $items=array_values(array_filter($items,static fn($i):bool=>vp3_operator_objectives_key_v1($i['text'])!==$normalized));
                    if(isset($seenQuestions[$identity]))continue;$seenQuestions[$identity]=true;
                    if($question['state']==='answer_reviewed')continue;
                    $agendaItems[]=['key'=>hash('sha256','call-agenda:'.$identity),'session_id'=>(int)$job['session_id'],'text'=>$question['question'],
                        'next_step'=>'Review the original call question and Q&A evidence before following up.',
                        'review_state'=>$question['state']==='open'?$question['review_state']:'unreviewed',
                        'source_url'=>$question['source_url']?:$agenda['transcript_url'],'revision'=>$question['revision']];
                }
                $items=array_slice(array_merge($agendaItems,$items),0,3);
                if($agenda['coverage']==='partial')$coverage='limited';
                if (!$items) continue;
                $reports[]=['ref'=>'followup:'.$job['public_id'],'call_id'=>(string)$job['call_public_id'],
                    'created_at'=>gmdate(DATE_ATOM,(int)$job['created_epoch']),'items'=>$items];
            } catch (Throwable $error) {
                // A failed reader is not evidence of an empty review queue.
                if ($error instanceof PDOException && $pdo->inTransaction()) throw $error;
                if (!in_array((int)$error->getCode(),[403,404,409],true)) $coverage='limited';
            }
        }
        return ['coverage'=>$coverage,'reports'=>$reports];
    } catch (VP3PhoneWorkspaceException $error) {
        return $error->httpStatus===403?array_replace($empty,['coverage'=>'unconfigured']):$empty;
    } catch (Throwable $error) {
        if ($error instanceof PDOException && $pdo->inTransaction()) throw $error;
        return $empty;
    }
}

/** Existing proactive coordination sees only a count and revision, never call speech. */
function vp3_coordination_operator_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now,?callable $read=null,?callable $objectiveRead=null): array
{
    $review=vp3_operator_followup_queue_v1($pdo,$scope,$now,$refs,$read,$objectiveRead);
    $items=[];
    foreach ($review['reports'] as $report) {
        $ref='operator:'.$report['ref'];$count=count($report['items']);
        $items[$ref]=vp3_coordination_source_item_v1($ref,'operator','Phone conversation follow-up',
            $count===1?'One saved call finding awaits review.':$count.' saved call findings await review.',
            'Review the original Operator or Transcription report.','','','open','normal',
            array_map(static fn(array $item):array=>[$item['key'],$item['revision'],$item['review_state']],$report['items']));
        $items[$ref]['trigger']='followup_review';
        // Native Transcription owns any existing Chat follow-up. Never queue a
        // duplicate, or pass phone report content to an unrelated model job.
        $items[$ref]['history_only']=true;
    }
    return ['items'=>$items,'coverage'=>$review['coverage']];
}
