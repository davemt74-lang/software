<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-v1.php';
require_once __DIR__.'/agent-email-triage-assessment-v1.php';
require_once __DIR__.'/agent-initiative-model-v1.php';
require_once __DIR__.'/agent-coordination-gmail-material-v1.php';

const VP3_GMAIL_THREAD_ANALYSIS_PROTOCOL_V1 = 'vp3.gmail-thread-analysis.v1';

final class AgentGmailAnalysisSourceV1 extends RuntimeException
{
    public function __construct(public readonly string $reason, int $code)
    {
        parent::__construct('Gmail analysis source unavailable.',$code);
    }
}

/** Read a bounded, chronological conversation sample for a private Gmail finding. */
function agent_connection_gmail_thread_analysis_v1(PDO $pdo, array $user, string $accessToken, string $threadId,
    string $triggerMessageId, ?callable $http, callable $claimCurrent, ?callable $model = null, ?string $senderAttention = null): array
{
    if ($pdo->inTransaction() || (int)($user['id'] ?? 0)<1
        || agent_connection_gmail_id_v1($threadId)==='' || agent_connection_gmail_id_v1($triggerMessageId)==='') {
        throw new InvalidArgumentException('Invalid Gmail thread analysis request.');
    }
    $current = static function () use ($claimCurrent): void {
        if ($claimCurrent()!==true) throw new RuntimeException('Gmail analysis access changed.',403);
    };
    $current();
    $metadata = agent_connection_gmail_thread_v1($accessToken,$threadId,$http);
    $eligible = [];
    foreach ($metadata as $message) {
        if (!array_intersect(['TRASH','SPAM','DRAFT'],$message['labels'])) $eligible[$message['message_id']] = $message;
    }
    if (!isset($eligible[$triggerMessageId])) throw new AgentGmailAnalysisSourceV1('gmail_message_unavailable',404);
    uasort($eligible,static fn(array $left,array $right): int => $left['received_at_ms']<=>$right['received_at_ms']);

    // Include the exact arriving message, the latest owner reply, and recent context.
    $selected = [$triggerMessageId=>true];
    $latest = array_reverse($eligible,true);
    foreach ($latest as $id=>$message) {
        if (in_array('SENT',$message['labels'],true)) {
            $selected[$id] = true;
            break;
        }
    }
    foreach ($latest as $id=>$message) {
        if (count($selected)>=4) break;
        $selected[$id] = true;
    }
    $sample = $sources = [];
    $subject = '';
    $material = [];
    foreach (array_intersect_key($eligible,$selected) as $message) {
        $id = $message['message_id'];
        $current();
        try {
            $raw = agent_connection_gmail_get_v1($accessToken,$id,true,$http);
        } catch (AgentConnectionGmailExceptionV1 $error) {
            if ($id!==$triggerMessageId && $error->getCode()===404) continue;
            throw $error;
        }
        $read = agent_connection_gmail_message_v1($raw);
        if ($read['thread_id']!==$threadId) throw new RuntimeException('Gmail returned a message from a different thread.');
        if (array_intersect(['TRASH','SPAM','DRAFT'],$read['labels'])) {
            if ($id===$triggerMessageId) throw new AgentGmailAnalysisSourceV1('gmail_message_unavailable',404);
            continue;
        }
        $ref = count($sample)+1;
        $body = agent_email_triage_assessment_text_v1($raw);
        if ($id===$triggerMessageId) {
            $subject = mb_substr($read['subject'],0,240);
            if (!in_array('SENT',$read['labels'],true)) {
                $reference = agent_email_triage_assessment_reference_v1($raw,intdiv($read['received_at_ms'],1000),null);
                $material = vp3_coordination_gmail_observation_v1($body,$subject,$reference);
            }
        }
        $sources[$ref] = $id;
        $sample[] = [
            'ref'=>$ref,
            'direction'=>in_array('SENT',$read['labels'],true)?'sent_by_owner':'received',
            'from'=>mb_substr($read['from'],0,180),
            'subject'=>mb_substr($read['subject'],0,240),
            'gmail_important'=>in_array('IMPORTANT',$read['labels'],true),
            'received_at'=>$read['received_at_ms']>0?gmdate('c',intdiv($read['received_at_ms'],1000)):null,
            'body_excerpt'=>mb_substr($body,0,2000),
            'excerpt_truncated'=>mb_strlen($body)>2000,
        ];
    }

    $current();
    $triggerRef = array_search($triggerMessageId,$sources,true);
    if ($triggerRef===false || $sample[$triggerRef-1]['body_excerpt']==='') {
        throw new AgentGmailAnalysisSourceV1('gmail_no_readable_body',422);
    }
    $input = ['trigger_ref'=>$triggerRef,'partial_thread'=>count($sample)<count($eligible),'messages'=>$sample];
    if (in_array($senderAttention,['act_soon','review','read_later','informational'],true)) {
        $input['owner_sender_attention'] = $senderAttention;
    }
    $prompt = json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    $answer = ($model??'agent_connection_gmail_thread_infer_v1')($pdo,$user,$prompt,$current);
    $current();
    if (trim($answer)==='') throw new Vp3InitiativeModelDiagnosticV1('response_empty');
    if (strlen($answer)>6000) throw new Vp3InitiativeModelDiagnosticV1('response_too_large');
    try {
        $finding = agent_connection_gmail_thread_result_v1($answer,$sources)+['subject'=>$subject];
        if ($material) $finding['material_observation'] = $material;
        return $finding;
    } catch (InvalidArgumentException|JsonException $error) {
        throw new Vp3InitiativeModelDiagnosticV1('invalid_response',null,$error);
    }
}

/** Use the authorized Cloud route and the existing background usage meter. */
function agent_connection_gmail_thread_infer_v1(PDO $pdo, array $user, string $prompt, Closure $current): string
{
    $protocol = VP3_GMAIL_THREAD_ANALYSIS_PROTOCOL_V1;
    $before = static function () use ($pdo,$user,$current): bool {
        $current();
        $health = vp3_initiative_inference_health_v1($pdo,$user,0,true);
        $cloud = ($health['route']??null)==='vp3_cloud' || !empty($health['cloud_fallback_allowed']);
        if (empty($health['ready']) || !$cloud) throw vp3_initiative_inference_blocked_v1($health);
        $current();
        return true;
    };
    $dispatched = false;
    $dispatch = static function () use ($before,&$dispatched): bool {
        if ($dispatched || !$before()) return false;
        $dispatched = true;
        return true;
    };
    $before();
    $service = ['service'=>'initiative','background'=>true,'stage'=>$protocol];
    $result = vp3_ai_with_budget_v1(25,static fn()=>ai_v100_with_service_context($service,
        static fn()=>ai_generate_chat_response($prompt,[],[],$user,'main_ai',[
            'surface'=>'tool_json','protocol'=>$protocol,'before_gmail_analysis'=>$before,
            'before_gmail_analysis_dispatch'=>$dispatch,
        ])));
    if (empty($result['ok']) || !is_string($result['answer']??null)) {
        throw vp3_initiative_provider_failure_v1($result);
    }
    return $result['answer'];
}

function agent_connection_gmail_thread_result_v1(string $answer, array $sources): array
{
    $fields = ['category','priority','state','summary','importance_reason','next_step','followup_question','evidence_ref'];
    $result = vp3_tool_json_object_v1($answer,[...$fields,'handoff','handoff_question'],6000)
        + ['handoff'=>'none','handoff_question'=>''];
    if (array_diff($fields,array_keys($result))
        || !in_array($result['category']??null,['action_required','opportunity','decision','routine','newsletter','automated','likely_junk'],true)
        || !in_array($result['priority']??null,['high','normal','low'],true)
        || !in_array($result['state']??null,['open','resolved','informational'],true)
        || !in_array($result['handoff'],['none','needs_attention','agent_work'],true)
        || !is_int($result['evidence_ref']??null) || !isset($sources[$result['evidence_ref']])) {
        throw new InvalidArgumentException('Invalid Gmail thread finding.');
    }
    foreach (['summary'=>600,'importance_reason'=>320,'next_step'=>320,'followup_question'=>320,'handoff_question'=>240] as $field=>$limit) {
        if (!is_string($result[$field]??null)) throw new InvalidArgumentException('Invalid Gmail finding text.');
        $value = trim($result[$field]);
        if (mb_strlen($value)>$limit || preg_match('~[\p{Cc}\p{Cf}]|<[^>]+>|(?:https?://|www\.|\b[^\s@]+@[^\s@]+\.[a-z]{2,})~iu',$value)
            || ($value==='' && in_array($field,['summary','importance_reason'],true))) {
            throw new InvalidArgumentException('Invalid Gmail finding text.');
        }
        $result[$field] = $value;
    }
    if ($result['state']!=='open' && ($result['next_step']!=='' || $result['followup_question']!=='')) {
        throw new InvalidArgumentException('An inactive Gmail finding cannot require follow-up.');
    }
    $handoff = $result['handoff'];
    $question = $result['handoff_question'];
    if (($handoff!=='none' && $result['state']!=='open')
        || ($handoff==='needs_attention' && $result['next_step']==='' && $result['followup_question']==='')
        || ($handoff==='agent_work' ? $question==='' : $question!=='')) {
        throw new InvalidArgumentException('Invalid Gmail finding handoff.');
    }
    $result['evidence_message_id'] = $sources[$result['evidence_ref']];
    unset($result['evidence_ref']);
    return $result;
}
