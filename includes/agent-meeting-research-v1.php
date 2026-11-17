<?php
declare(strict_types=1);

require_once __DIR__.'/agent-meeting-research-link-v1.php';
require_once __DIR__.'/agent-initiative-transcript-research-v1.php';
require_once __DIR__.'/agent-initiative-tools-v1.php';

/** Select a saved question. The request text never becomes a public query. */
function vp3_meeting_research_plan_v1(string $query): ?array
{
    $pattern='~\A(?:(?:can|could|would) you\s+)?/?research\s+(.+?)\s+from\s+'
        .'(?:(?:(?:our|the|my)\s+)?(?:last|previous)\s+meeting|(this)\s+meeting|(?:the\s+)?meeting\s+#([1-9][0-9]{0,17}))'
        .'(?:\s+for\s+agenda(?:\s+item)?\s+#([1-9][0-9]{0,17}))?'
        .'(?:\s+and\s+(?:bring|link|attach)\s+(?:it|the\s+(?:result|findings))\s+(?:back\s+)?to\s+(?:that|the|this|same)\s+follow[- ]?up)?[.!?]?\z~iuD';
    if (strlen($query)>1600 || !preg_match($pattern,trim($query),$match,PREG_UNMATCHED_AS_NULL)) return null;
    return ['question'=>trim($match[1]," \t\r\n\"'"),'current'=>$match[2]!==null,
        'meeting_id'=>$match[3]===null?null:(int)$match[3],'agenda_id'=>$match[4]===null?null:(int)$match[4]];
}

function vp3_meeting_research_words_v1(string $text): array
{
    preg_match_all('/[\p{L}\p{N}]+/u',mb_strtolower($text),$words);
    return array_diff(array_unique($words[0]),['the','a','an','this','that','open','saved','question','suggestion','follow','up','about','on','and','or']);
}

function vp3_meeting_research_source_v1(array $goal): ?array
{
    $ref = $goal['primary'] ?? null;
    if (($goal['tool'] ?? null)!=='topic.research' || ($goal['report'] ?? null)!==true
        || !is_string($ref) || !preg_match('/\Atranscript-research:([1-9][0-9]{0,17}):[a-f0-9]{64}\z/D',$ref,$id)
        || !is_array($goal['sources'] ?? null) || array_keys($goal['sources'])!==[$ref]
        || !is_string($goal['sources'][$ref]) || !preg_match('/\A[a-f0-9]{64}\z/D',$goal['sources'][$ref])) return null;
    return ['source_ref'=>$ref,'source_revision'=>$goal['sources'][$ref],'transcript_session_id'=>(int)$id[1]];
}

function vp3_meeting_research_saved_link_v1(PDO $pdo,int $owner,int $runId): ?array
{
    $query=$pdo->prepare("SELECT metadata_json FROM agent_workflow_events
        WHERE run_id=? AND owner_user_id=? AND event_type='meeting_research_linked' ORDER BY id DESC LIMIT 1");
    $query->execute([$runId,$owner]);
    $link=json_decode((string)$query->fetchColumn(),true);
    return is_array($link)&&($link['version']??null)===1 ? $link : null;
}

/** A run may already be leased or reported. Append provenance without changing its goal or its receipt. */
function vp3_meeting_research_attach_v1(PDO $pdo,int $runId,array $goal,array $target,string $actor): void
{
    $source=vp3_meeting_research_source_v1($goal);
    $binding=$goal['binding']??[];
    if (!$pdo->inTransaction() || $runId<1 || $source===null
        || $source['transcript_session_id']!==$target['transcript_session_id']
        || ($binding['agent_id']??0)!==$target['agent_id'] || ($binding['conversation_id']??0)!==$target['conversation_id']
        || ($binding['owner_user_id']??$target['owner_user_id'])!==$target['owner_user_id']) {
        throw new LogicException('Meeting research must share the exact Research workflow transaction and source.');
    }
    $owner=$target['owner_user_id'];
    $link=['version'=>1,'source'=>$source,'target'=>$target];
    $prior=vp3_meeting_research_saved_link_v1($pdo,$owner,$runId);
    if ($link===$prior || ($target['agenda_item_id']===null && isset($prior['target']['agenda_item_id']))) return;
    agent_workflow_event_v1400($pdo,$owner,$runId,'meeting_research_linked','','',$actor,
        'Research linked to the existing meeting follow-up.',$link);
}

function vp3_meeting_research_projection_v1(PDO $pdo,int $ownerId,int $agentId,int $conversationId,int $runId,array $goal): ?array
{
    $source=vp3_meeting_research_source_v1($goal);
    $binding=$goal['binding']??null;
    if ($source===null || !is_array($binding) || $ownerId<1 || $conversationId<1 || $runId<1
        || ($binding['owner_user_id']??$ownerId)!==$ownerId || ($binding['agent_id']??0)!==$agentId
        || ($binding['conversation_id']??null)!==$conversationId || vp3_participant_scope_v1()!==null) return null;
    try {
        $link=vp3_meeting_research_saved_link_v1($pdo,$ownerId,$runId);
        if (($link['source']??null)!==$source || !is_array($link['target']??null)
            || ($link['target']['transcript_session_id']??null)!==$source['transcript_session_id']) return null;
        $scope=vp3_initiative_tools_scope_v1($pdo,['id'=>$ownerId],$binding);
        $coverage=[];
        $evidence=vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),
            [$source['source_ref']],$coverage);
        if (($evidence[$source['source_ref']]['revision']??null)!==$source['source_revision']) return null;
        return vp3_meeting_research_target_current_v1($pdo,$scope,$link['target']);
    } catch (RuntimeException $error) {
        return null;
    }
}

/** Attach native transcript provenance when the ordinary Initiative assessment queues this Research run. */
function vp3_meeting_research_link_new_run_v1(PDO $pdo,array $user,int $runId,array $goal): void
{
    $source=vp3_meeting_research_source_v1($goal);
    if ($source===null || !$pdo->inTransaction() || !table_exists('video_meeting_transcription_links')) return;
    $scope=vp3_initiative_tools_scope_v1($pdo,$user,$goal['binding']);
    $target=vp3_meeting_research_target_v1($pdo,$scope,$source['transcript_session_id']);
    if ($target!==null) vp3_meeting_research_attach_v1($pdo,$runId,$goal,$target,'system');
}

function vp3_meeting_research_run_v1(PDO $pdo,int $owner,string $hash): ?array
{
    $query=$pdo->prepare("SELECT id,status,goal FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?
        AND source_kind='agent_initiative' AND capability_key='initiative.prepare'");
    $query->execute([$owner,hash('sha256','initiative.prepare|'.$hash)]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Bind an exact question and optional native agenda item to the ordinary Research worker. */
function vp3_meeting_research_chat_v1(PDO $pdo,array $user,array $principal,int $cid,array $plan,?callable $authority=null): array
{
    $reply=static fn(string $answer,string $status,string $reason='',int $id=0): array=>['handled'=>true,'answer'=>$answer,
        'actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('topic.research','prepare',$status,$reason,$id)];
    if ($cid<1 || vp3_participant_scope_v1()!==null || !knowledge_retrieval_v162_owner_session($user,$principal)) {
        return $reply('Use your private Agent Chat to research a meeting follow-up.','blocked','scope_restricted');
    }
    $agent=(int)$principal['agent_id'];
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
    $owner=(int)$live['id'];
    $binding=vp3_initiative_policy_v1($live,$agent);
    if (($binding['enabled']??false)!==true || ($binding['conversation_id']??null)!==$cid) {
        return $reply('Open the private Chat linked to Initiative or enable Initiative here to run meeting research in the background.','blocked','setup_required');
    }
    $grant=vp3_transcript_research_grant_v1($pdo,$owner);
    if ($grant==='') return $reply('Enable Transcript Research for this account to use a saved meeting question for research.','blocked','setup_required');

    $scope=vp3_initiative_tools_scope_v1($pdo,$live,$binding);
    $meetingId=$plan['meeting_id'];
    $priorRecording=null;
    if ($plan['current']) {
        require_once __DIR__.'/agent-meeting-action-sources-v1.php';
        $meeting=vp3_meeting_action_source_v1($pdo,$live,$agent,$cid);
        $meetingId=$meeting['source']['meeting_id']??null;
        if ($meetingId===null) return $reply('Which meeting should I use? Say “from our last meeting” or give me the meeting number.','clarification_required','invalid_plan');
        $recording=$meeting['source']['access']['recording_id']??null;
        if (is_int($recording) && $recording>0) $priorRecording=$recording;
    }
    $session=vp3_meeting_research_session_v1($pdo,$scope,$meetingId)??$priorRecording;
    if ($session===null) return $reply('I couldn’t verify a recording for that meeting in this Agent.','blocked','scope_restricted');
    $target=vp3_meeting_research_target_v1($pdo,$scope,$session,$plan['agenda_id']);
    if ($target===null) return $reply('I couldn’t verify that follow-up for this meeting. Choose the exact open agenda item.','blocked','scope_restricted');

    $words=vp3_meeting_research_words_v1($plan['question']);
    $coverage=[];
    $sources=vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),
        null,$coverage,$session,static fn(array $item): bool=>!array_diff($words,vp3_meeting_research_words_v1($item['question'])));
    if (count($sources)!==1) {
        if (($coverage['transcript_research']??null)==='unavailable') return $reply('I couldn’t check the saved meeting questions. Try again once the recording is available.','blocked','provider_unavailable');
        return $reply('Which saved question should I research? Give me its wording from the meeting’s Q&A or Follow-up tab.','clarification_required','invalid_plan');
    }
    $ref=array_key_first($sources);
    $source=$sources[$ref];
    $revisions=[$ref=>$source['revision']];
    $hash=hash('sha256',json_encode([$binding,'topic.research',$ref,$revisions],JSON_THROW_ON_ERROR));
    $goal=['binding'=>$binding,'sources'=>$revisions,'canonical_sources'=>[],'capability_sources'=>[],
        'tool'=>'topic.research','primary'=>$ref,'focus'=>'Check whether public sources can answer this saved transcript question.','report'=>true,
        'transcript_research_queue_key'=>vp3_initiative_transcript_research_queue_key_v1($binding,$source,$grant)];

    $saved=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$live,$owner,$binding,$scope,$target,$revisions,$hash,$goal,$authority): ?array {
        vp3_initiative_owner_lock_v1($pdo,$live);
        if ($authority) $authority();
        vp3_initiative_access_v1($pdo,$live,$binding);
        vp3_initiative_current_sources_v1($pdo,$live,$revisions,!empty($binding['screen_enabled']),[],(int)$scope['agent_id']);
        if (vp3_meeting_research_target_current_v1($pdo,$scope,$target)===null) throw new RuntimeException('The meeting follow-up changed. Choose its current source.',409);
        $run=vp3_meeting_research_run_v1($pdo,$owner,$hash);
        if ($run===null && vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$goal,$hash)) {
            $run=vp3_meeting_research_run_v1($pdo,$owner,$hash);
            if ($run===null) throw new RuntimeException('The saved Research run could not be verified.',503);
            $run['new']=true;
        }
        if ($run===null) return null;
        $savedGoal=json_decode((string)$run['goal'],true,16,JSON_THROW_ON_ERROR);
        if (($savedGoal['binding']??null)!==$binding || vp3_meeting_research_source_v1($savedGoal)!==vp3_meeting_research_source_v1($goal)) {
            throw new RuntimeException('The saved Research run changed.',409);
        }
        vp3_meeting_research_attach_v1($pdo,(int)$run['id'],$savedGoal,$target,'user');
        return $run;
    });
    if ($saved===null) return $reply('Research is already assigned to an equivalent question from another recording. This exact meeting follow-up is still open.','blocked','scope_restricted');
    $answer=match (true) {
        !empty($saved['new'])=>'I queued Research for that exact meeting question and linked it to the follow-up.',
        $saved['status']==='completed'=>'I linked the existing Research run to the meeting follow-up. You can open its saved result from this Chat when available.',
        in_array($saved['status'],['failed','cancelled','paused'],true)=>'I linked the existing Research run. Its current status is '.$saved['status'].'. The meeting follow-up is still open.',
        default=>'That exact question is already assigned to Research. I linked the existing run to the meeting follow-up.',
    };
    return $reply($answer,'read_complete','',(int)$saved['id']);
}
