<?php
declare(strict_types=1);

/** Hydrate delivered research from the matching workflow and completed worker receipt. */
function vp3_initiative_research_canvas_history_v1(PDO $pdo, int $ownerId, int $agentId, int $conversationId, array $messages): array
{
    $private = $ownerId > 0 && $agentId >= 0 && $conversationId > 0
        && (!function_exists('vp3_participant_scope_v1') || vp3_participant_scope_v1() === null);
    $positions = [];
    foreach ($messages as $index => $message) {
        $raw = $message['context_json'] ?? null;
        if (!is_string($raw) || (!str_contains($raw, '"initiative_output"') && !str_contains($raw, '"research_canvas_v1"')
            && !str_contains($raw, '\\u'))) continue;
        $context = json_decode($raw, true);
        if (!is_array($context)) continue;
        if (array_key_exists('research_canvas_v1', $context)) {
            unset($context['research_canvas_v1']);
            $messages[$index]['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $runId = $context['initiative_run_id'] ?? null;
        $work = $context['initiative_work'] ?? null;
        if (!$private || ($message['role'] ?? '') !== 'assistant' || (int)($message['id'] ?? 0) < 1
            || ($context['source'] ?? null) !== 'agent_initiative' || !is_int($runId) || $runId < 1
            || !in_array($context['initiative_output']['type'] ?? null, ['initiative_topic_research','initiative_artist_research'], true)
            || ($work['owner_user_id'] ?? null) !== $ownerId || ($work['agent_id'] ?? null) !== $agentId
            || ($work['conversation_id'] ?? null) !== $conversationId || ($work['run_id'] ?? null) !== $runId) continue;
        $positions[$index] = [$runId, $context];
    }
    if (!$positions) return $messages;

    $ids = array_values(array_unique(array_column($positions, 0)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $query = $pdo->prepare("SELECT r.id,r.goal,a.result_json,p.result_json AS receipt_json
            FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
                AND a.sequence_no=1 AND a.capability_key='initiative.prepare' AND a.status='completed'
            JOIN agent_workflow_receipts p ON p.run_id=r.id AND p.action_id=a.id AND p.owner_user_id=r.owner_user_id
                AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
            WHERE r.owner_user_id=? AND COALESCE(r.agent_id,0)=? AND r.workflow_type='initiative'
                AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
                AND r.status='completed' AND r.id IN ($placeholders)");
        foreach ([$ownerId, $agentId, ...$ids] as $index => $id) $query->bindValue($index + 1, $id, PDO::PARAM_INT);
        $query->execute();
        $records = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    } catch (PDOException $error) {
        return $messages;
    }

    foreach ($positions as $index => [$runId, $context]) {
        if (!isset($records[$runId])) continue;
        $record = $records[$runId];
        $goal = json_decode((string)$record['goal'], true);
        $result = json_decode((string)$record['result_json'], true);
        $receipt = json_decode((string)$record['receipt_json'], true);
        if (!is_array($goal) || !is_array($result) || $result !== $receipt) continue;
        $binding = $goal['binding'] ?? null;
        $messageId = (int)$messages[$index]['id'];
        if (($binding['owner_user_id'] ?? ($agentId === 0 ? $ownerId : null)) !== $ownerId
            || ($binding['agent_id'] ?? 0) !== $agentId || ($binding['conversation_id'] ?? null) !== $conversationId
            || ($goal['report'] ?? null) !== true || ($result['initiative_delivery'] ?? null) !== 'delivered'
            || !in_array($result['initiative_message_id'] ?? null, [$messageId, (string)$messageId], true)) continue;
        $card = vp3_initiative_research_canvas_card_v1($goal, $result, $context, $messages[$index]['message'] ?? null);
        if ($card === null) continue;
        $context['research_canvas_v1'] = [
            'owner_user_id'=>$ownerId, 'agent_id'=>$agentId, 'conversation_id'=>$conversationId, 'run_id'=>$runId,
            ...$card,
        ];
        $messages[$index]['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $messages;
}

function vp3_initiative_research_canvas_card_v1(array $goal, array $result, array $context, mixed $message): ?array
{
    $tool = $goal['tool'] ?? null;
    $kind = match ($tool) {
        'artist.research'=>'artist',
        'topic.research'=>'topic',
        default=>null,
    };
    if ($kind === null) return null;
    $toolResult = $result['initiative_tool_result'] ?? null;
    $output = $toolResult['output'] ?? null;
    $primary = $goal['primary'] ?? null;
    if (!is_array($toolResult) || !is_array($output) || !is_string($primary)
        || ($output['type'] ?? null) !== 'initiative_'.$kind.'_research' || ($output['source_ref'] ?? null) !== $primary
        || ($output['source_revision'] ?? null) !== ($goal['sources'][$primary] ?? null)
        || $output !== ($context['initiative_output'] ?? null)
        || !is_string($message) || $message !== ($result['initiative_draft'] ?? null) || $message !== ($toolResult['draft'] ?? null)) return null;

    require_once __DIR__.'/agent-artist-research-v1.php';
    $verified = vp3_initiative_research_tool_result_v1($toolResult);
    if ($verified === null || $verified['tool_receipt'] !== ($context['tool_receipt'] ?? null)) return null;

    foreach (is_array($context['sources'] ?? null) ? $context['sources'] : [] as $source) {
        if (!is_array($source)) continue;
        $title = $source['title'] ?? null;
        if (($source['source'] ?? null) !== $primary || !is_string($title) || !vp3_topic_research_subject_valid_v1($title)) continue;
        $titleMatches = str_starts_with($primary, 'task-research:') ? $title === ($output['public_topic'] ?? null)
            : $primary === $kind.'-interest:'.hash('sha256', mb_strtolower($title));
        if (!$titleMatches) continue;
        return [
            'kind'=>$kind, 'title'=>$title, 'status'=>$output['status'], 'checked_at'=>$output['checked_at'],
            'sources'=>array_map(static fn(array $row): array=>array_intersect_key($row, [
                'source'=>true, 'title'=>true, 'url'=>true, 'coverage'=>true,
            ]), $verified['sources']),
        ];
    }
    return null;
}
