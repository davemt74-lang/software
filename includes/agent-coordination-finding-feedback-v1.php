<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-work-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';

/** Read the exact posted question and its verified reply for a currently authorized finding. */
function vp3_coordination_finding_feedback_v1(PDO $pdo,array $user,array $binding,array $item,bool $answeredOnly=false): ?array
{
    $cid = (int)($binding['conversation_id'] ?? 0);
    if ($cid < 1 || vp3_participant_scope_v1() !== null) return null;
    $ref = $item['ref'];
    $agent = vp3_initiative_binding_agent_v1($binding);
    $key = $item['material']['key'] ?? $ref;
    $id = vp3_coordination_scan_id_v1($user,$binding,$key);
    $latest = null;
    foreach (vp3_coordination_runs_v1($pdo,$user,$binding,$id,12) as $run) {
        $goal = json_decode((string)$run['goal'],true);
        if (($goal['primary'] ?? null) !== $ref || ($goal['coordination_route']['specialist'] ?? null) !== $item['specialist']
            || ($goal['coordination_route']['action'] ?? null) !== 'ask'
            || isset($goal['followthrough']) || isset($goal['coordination_work'])) continue;
        $result = vp3_coordination_verified_run_v1($pdo,$user,$run);
        if (($result['initiative_tool_result']['output']['state'] ?? null) !== 'needs_owner') continue;
        $posted = vp3_coordination_chat_projection_v1($pdo,$user,$binding,$run,$result);
        if ($posted === null || $posted['message_id'] <= ($latest['message_id'] ?? 0)) continue;
        $latest = $posted + ['run_id'=>(int)$run['id'],'goal'=>$goal,'result'=>$result];
    }
    if ($latest === null) return null;
    $exact = ($latest['goal']['sources'][$ref] ?? null) === $item['revision'];
    $responseRevision = $item['response_revision'] ?? null;
    $planning = $item['specialist'] === 'planning' ? ($item['material'] ?? null) : null;
    $samePlanning = $planning !== null
        && ($latest['goal']['coordination_material']['revision'] ?? null) === vp3_coordination_source_hash_v1([$planning['revision'],null])
        && !array_diff($planning['related_refs'],array_keys($latest['result']['initiative_tool_result']['output']['evidence_revisions']));
    $sameResponse = $responseRevision !== null && ($latest['goal']['coordination_response_revision'] ?? null) === $responseRevision;
    if (!$exact && !$sameResponse && !$samePlanning) return null;

    try {
        if ($exact && $planning !== null) {
            // The appointment alone cannot authorize a link to a two-record conflict.
            try {
                vp3_coordination_result_evidence_v1($pdo,$user,$binding,$latest['goal'],$latest['result']['initiative_tool_result']);
            } catch (Vp3InitiativeSourcesChangedV1 $error) {
                if (!$samePlanning) return null;
                $exact = false;
            }
        }
        $principal = vp3_initiative_principal_v1($pdo,$user,$agent);
        $origin = vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$latest['message_id']);
    } catch (RuntimeException|JsonException $error) {
        if ($error instanceof PDOException) throw $error;
        return null;
    }
    if ((int)$origin['run']['id'] !== $latest['run_id']) return null;
    $state = $origin['state'];
    if ($state['state'] === 'stale' && ($answeredOnly || !$exact)) return null;
    if ($state['state'] !== 'open') {
        require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
        if (!vp3_initiative_engagement_reply_verified_v1($pdo,$user,$cid,$latest['message_id'],$state)) return null;
    } elseif ($answeredOnly || !$exact) {
        return null;
    }
    return ['state'=>$state['state'],'message_id'=>$latest['message_id'],'href'=>$exact ? $latest['url'] : null];
}
