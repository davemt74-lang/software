<?php
declare(strict_types=1);

/** Project only the latest exact saved run for an already visible inventory entry. */
function vp3_initiative_office_entry_projections_v1(PDO $pdo, array $scope, array $items, DateTimeImmutable $now,
    ?array &$classified = null, ?array &$verifiedSources = null): array
{
    if (!$items) return $items;
    require_once __DIR__.'/agent-job-engine-v1900.php';
    if (!agent_job_engine_schema_ready_v1900($pdo)) return $items;
    require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
    require_once __DIR__.'/agent-initiative-office-entry-source-v1.php';
    require_once __DIR__.'/agent-initiative-task-reports-v1.php';

    // The owner/source-kind/source-key index bounds lookups to this exact entry.
    $runs = "SELECT id,goal,status,lease_expires_at FROM agent_workflow_runs
        WHERE owner_user_id=? AND source_kind='agent_initiative' AND source_key=? AND CAST(COALESCE(agent_id,0) AS CHAR)=?
        AND workflow_type='initiative' AND capability_key='initiative.prepare' AND execution_target='cloud'";
    $run = $pdo->prepare($runs.' ORDER BY id DESC LIMIT 1');
    $revision = "JSON_EXTRACT(CASE WHEN JSON_VALID(goal) THEN goal ELSE '{}' END,?)";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $revision = "JSON_UNQUOTE($revision)";
    $history = $pdo->prepare($runs." AND $revision=? AND id<>? ORDER BY id DESC LIMIT 8");
    $receipt = $pdo->prepare("SELECT a.result_json,c.result_json AS receipt_json,a.completed_at
        FROM agent_workflow_actions a JOIN agent_workflow_receipts c
            ON c.run_id=a.run_id AND c.action_id=a.id AND c.owner_user_id=a.owner_user_id
            AND c.attempt_no=a.attempt_count AND c.executor='cloud' AND c.status='completed'
        WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1 AND a.action_key='initiative.prepare'
            AND a.capability_key='initiative.prepare' AND a.execution_target='cloud' AND a.status='completed'
        ORDER BY c.id DESC LIMIT 1");
    $policy = null;
    foreach ($items as &$item) {
        $ref = $item['kind'] === 'task_list' ? 'task-list:'.substr($item['id'], 5) : $item['id'];
        $tool = match ($item['kind']) {
            'task'=>'tasks.read', 'task_list'=>'tasks.lists.read', 'reminder'=>'reminders.read',
            default=>null,
        };
        if ($tool === null) continue;
        $classifiedRunId = null;
        $source = null;
        $projection = vp3_initiative_office_entry_projection_v1($pdo, $scope, $ref, $tool, $now, $run, $history, $receipt, $policy,
            $classifiedRunId, $classified !== null, $source);
        if ($projection !== null) $item['office_report'] = $projection;
        if ($classified !== null && $classifiedRunId !== null) $classified[$ref] = $classifiedRunId;
        if ($verifiedSources !== null && ($projection['state'] ?? null) === 'ready') $verifiedSources[$item['id']] = $source;
    }
    unset($item);
    return $items;
}

/** Resolve the current classified identity for the exact sources represented in a bounded report batch. */
function vp3_initiative_office_entry_classified_runs_v1(PDO $pdo, array $scope, array $rows, int $now): array
{
    $items = [];
    foreach ($rows as $row) {
        $goal = json_decode((string)($row['goal'] ?? ''), true);
        $ref = $goal['primary'] ?? null;
        if (($goal['office_entry'] ?? null) !== true || isset($goal['office_agent_task']) || !is_string($ref)
            || preg_match('/\A(task|task-list|reminder):([1-9][0-9]{0,17})\z/D', $ref, $parts) !== 1) continue;
        $items[$ref] = $parts[1] === 'task-list' ? ['id'=>'list:'.$parts[2], 'kind'=>'task_list'] : ['id'=>$ref, 'kind'=>$parts[1]];
    }
    $classified = [];
    if ($items) vp3_initiative_office_entry_projections_v1($pdo, $scope, $items, new DateTimeImmutable('@'.$now), $classified);
    return $classified;
}

function vp3_initiative_office_entry_run_projection_v1(PDOStatement $query, array $scope, string $key): ?array
{
    $query->execute([(int)$scope['user']['id'], $key, (string)$scope['agent_id']]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_initiative_office_entry_run_state_v1(array $run, int $now, string $complete): ?string
{
    return match ($run['status']) {
        'queued','approved','paused'=>'queued',
        'executing'=>agent_job_utc_timestamp_v1900($run['lease_expires_at']) > $now ? 'running' : 'queued',
        'completed'=>$complete,
        'failed'=>'failed',
        default=>null,
    };
}

function vp3_initiative_office_entry_receipt_projection_v1(PDOStatement $query, array $scope, array $run, array $goal): ?array
{
    $owner = (int)$scope['user']['id'];
    $query->execute([(int)$run['id'], $owner]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_string($row['result_json']) || $row['result_json'] !== $row['receipt_json']) return null;
    $completed = agent_job_utc_timestamp_v1900($row['completed_at']);
    $result = json_decode($row['result_json'], true);
    $work = $result['initiative_tool_result'] ?? null;
    $output = $work['output'] ?? null;
    if ($completed < 1 || $completed > time() || ($result['effect_state'] ?? null) !== 'private_draft_ready'
        || !is_string($work['draft'] ?? null) || $work['draft'] !== ($result['initiative_draft'] ?? null)
        || ($work['tool_receipt']['changes_applied'] ?? null) !== false
        || ($output['type'] ?? null) !== 'initiative_capability_read' || ($output['version'] ?? null) !== 1
        || ($output['status'] ?? null) !== 'read_complete' || ($output['tool'] ?? null) !== $goal['tool']
        || ($output['owner_user_id'] ?? null) !== $owner || ($output['agent_id'] ?? null) !== $scope['agent_id']
        || ($output['conversation_id'] ?? null) !== $goal['binding']['conversation_id']
        || ($output['primary'] ?? null) !== $goal['primary'] || ($output['source_revisions'] ?? null) !== $goal['sources']
        || !is_int($output['record_count'] ?? null) || $output['record_count'] < 1) return null;
    foreach (['read_fingerprint','coverage_fingerprint'] as $field) {
        if (!is_string($output[$field] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $output[$field]) !== 1) return null;
    }
    return $result;
}

function vp3_initiative_office_entry_summary_projection_v1(array $work): array
{
    $draft = $work['draft'];
    $output = $work['output'];
    $suffix = '';
    $details = [];
    foreach (['next_step'=>'Next step', 'followup_question'=>'Question for you'] as $field=>$label) {
        if (!is_string($output[$field] ?? null) || trim($output[$field]) === '') continue;
        $suffix .= "\n\n$label: ".$output[$field];
        $details[$field] = vp3_agent_activity_text_v1($output[$field], 400);
    }
    if ($suffix !== '' && str_ends_with($draft, $suffix) && strlen($draft) > strlen($suffix)) {
        $draft = substr($draft, 0, -strlen($suffix));
    } else {
        $details = [];
    }
    $steps = $output['action_plan'] ?? null;
    if (is_array($steps) && array_is_list($steps)) {
        $steps = array_map(static fn(mixed $step): string => is_string($step) ? vp3_agent_activity_text_v1($step, 240) : '', array_slice($steps, 0, 4));
        $details['action_plan'] = array_values(array_filter($steps, static fn(string $step): bool => $step !== ''));
    }
    if (is_bool($output['owner_attention'] ?? null)) $details['owner_attention'] = $output['owner_attention'];
    return ['summary'=>vp3_agent_activity_text_v1($draft, 1600)] + $details;
}

function vp3_initiative_office_entry_policy_current_v1(array $scope, array $binding, ?array &$policy): bool
{
    require_once __DIR__.'/agent-initiative-v1.php';
    $policy ??= vp3_initiative_policy_v1($scope['user'], $scope['agent_id']);
    return ($policy['enabled'] ?? false) === true && $policy === $binding;
}

function vp3_initiative_office_entry_child_projection_v1(PDOStatement $runQuery, PDOStatement $receiptQuery,
    array $scope, array $parent, array $goal, string $title, int $now, ?array &$policy): ?array
{
    $parentId = (int)$parent['id'];
    $child = vp3_initiative_office_entry_run_projection_v1($runQuery, $scope, vp3_initiative_office_agent_task_key_v1($parentId));
    if ($child === null) return null;
    $childGoal = json_decode((string)$child['goal'], true);
    $relation = $childGoal['office_agent_task'] ?? null;
    if (($childGoal['office_entry'] ?? null) !== true || ($childGoal['report'] ?? null) !== false
        || isset($childGoal['followthrough']) || isset($childGoal['followup'])
        || ($relation['report_run_id'] ?? null) !== $parentId || ($relation['title'] ?? null) !== $title) return null;
    foreach (['binding','primary','sources','tool'] as $field) {
        if (($childGoal[$field] ?? null) !== $goal[$field]) return null;
    }
    $state = vp3_initiative_office_entry_run_state_v1($child, $now, 'completed');
    if ($state === null) return null;
    if (in_array($state, ['queued','running'], true) && !vp3_initiative_office_entry_policy_current_v1($scope, $goal['binding'], $policy)) {
        $state = 'stale';
    }
    $task = ['title'=>vp3_agent_activity_text_v1($title, 160), 'state'=>$state];
    if ($state !== 'completed') return $task;
    $result = vp3_initiative_office_entry_receipt_projection_v1($receiptQuery, $scope, $child, $childGoal);
    if ($result === null) return array_replace($task, ['state'=>'failed']);
    $draft = vp3_initiative_office_entry_summary_projection_v1($result['initiative_tool_result'])['summary'];
    return $draft !== '' ? $task+['result'=>$draft] : array_replace($task, ['state'=>'failed']);
}

function vp3_initiative_office_entry_goal_projection_v1(array $scope, array $run, string $ref, string $tool): ?array
{
    $goal = json_decode((string)$run['goal'], true);
    $binding = $goal['binding'] ?? null;
    $sources = $goal['sources'] ?? null;
    $cid = $binding['conversation_id'] ?? null;
    $owner = (int)$scope['user']['id'];
    if (($goal['office_entry'] ?? null) !== true || ($goal['report'] ?? null) !== true || isset($goal['office_agent_task'])
        || isset($goal['followthrough']) || isset($goal['followup'])
        || ($goal['tool'] ?? null) !== $tool || ($goal['primary'] ?? null) !== $ref
        || !is_array($binding) || ($binding['agent_id'] ?? 0) !== $scope['agent_id']
        || ($binding['owner_user_id'] ?? ($scope['agent_id'] === 0 ? $owner : null)) !== $owner
        || !is_int($cid) || $cid < 1 || !is_array($sources) || array_keys($sources) !== [$ref]
        || !is_string($sources[$ref]) || preg_match('/\A[a-f0-9]{64}\z/D', $sources[$ref]) !== 1) return null;
    return $goal;
}

function vp3_initiative_office_entry_select_projection_v1(PDO $pdo, array $scope, array $run, array $goal, string $ref,
    string $revision, DateTimeImmutable $now, ?array &$policy): ?array
{
    if ($goal['sources'][$ref] !== $revision) return null;
    $state = vp3_initiative_office_entry_run_state_v1($run, $now->getTimestamp(), 'ready');
    if ($state === null) return null;
    $binding = $goal['binding'];
    $cid = $binding['conversation_id'];
    if (!vp3_agent_chat_conversation_v380($pdo, $cid, (int)$scope['user']['id'], $scope['agent'])) return null;
    if (in_array($state, ['queued','running'], true) && !vp3_initiative_office_entry_policy_current_v1($scope, $binding, $policy)) {
        return null;
    }
    return [$run, $goal, ['verification_only'=>true, 'conversation_id'=>$cid] + $scope, $state];
}

function vp3_initiative_office_entry_projection_v1(PDO $pdo, array $scope, string $ref, string $tool, DateTimeImmutable $now,
    PDOStatement $runQuery, PDOStatement $historyQuery, PDOStatement $receiptQuery, ?array &$policy, ?int &$classifiedRunId,
    bool $classificationOnly, ?array &$source = null): ?array
{
    $key = vp3_initiative_office_entry_key_v1(['agent_id'=>$scope['agent_id']], $ref);
    $run = vp3_initiative_office_entry_run_projection_v1($runQuery, $scope, $key);
    if ($run === null || ($goal = vp3_initiative_office_entry_goal_projection_v1($scope, $run, $ref, $tool)) === null) return null;

    // The viewing Chat can authorize the current source once. Every historical
    // result still verifies its own saved Chat before any text can be displayed.
    $cid = $scope['conversation_id'] ?: $goal['binding']['conversation_id'];
    $owner = (int)$scope['user']['id'];
    $readScope = ['verification_only'=>true, 'conversation_id'=>$cid] + $scope;
    $readBinding = ['agent_id'=>$scope['agent_id'], 'owner_user_id'=>$owner, 'conversation_id'=>$cid];
    try {
        $source = vp3_initiative_office_entry_source_v1($pdo, $readScope, $readBinding, $ref);
    } catch (RuntimeException $error) {
        if (in_array($error->getCode(), [403,404,409], true)) return ['state'=>'stale'];
        if ($error->getCode() === 503) return null;
        throw $error;
    }
    $revision = $source['revision'] ?? null;
    if (($source['tool'] ?? null) !== $tool || !is_string($revision)) return ['state'=>'stale'];
    $selected = vp3_initiative_office_entry_select_projection_v1($pdo, $scope, $run, $goal, $ref, $revision, $now, $policy);
    $reassessing = ($goal['office_reassessment'] ?? null) === true
        && in_array($selected[3] ?? null, ['queued','running'], true);
    if ($selected === null || $reassessing) {
        $path = '$.sources.'.json_encode($ref, JSON_THROW_ON_ERROR);
        $historyQuery->execute([$owner, $key, (string)$scope['agent_id'], $path, $revision, (int)$run['id']]);
        foreach ($historyQuery->fetchAll(PDO::FETCH_ASSOC) as $previous) {
            $previousGoal = vp3_initiative_office_entry_goal_projection_v1($scope, $previous, $ref, $tool);
            if ($previousGoal === null) continue;
            $candidate = vp3_initiative_office_entry_select_projection_v1($pdo, $scope, $previous, $previousGoal, $ref, $revision, $now, $policy);
            if ($candidate !== null && (!$reassessing || $candidate[3] === 'ready')) {
                $selected = $candidate;
                break;
            }
        }
    }
    if ($selected === null) return ['state'=>'stale'];
    [$run, $goal, $original, $state] = $selected;
    if ($state !== 'ready') return ['state'=>$state];

    $result = vp3_initiative_office_entry_receipt_projection_v1($receiptQuery, $scope, $run, $goal);
    if ($result === null) return ['state'=>'failed'];
    $work = $result['initiative_tool_result'];
    $disposition = $work['output']['office_disposition'] ?? null;
    if (in_array($disposition, ['review','note','ignore'], true)) $classifiedRunId = (int)$run['id'];
    if ($classificationOnly || $disposition === 'ignore') return null;
    $summary = vp3_initiative_office_entry_summary_projection_v1($work);
    if ($summary['summary'] === '') return ['state'=>'failed'];
    $projection = ['state'=>'ready'] + $summary;
    if (($source['limited'] ?? false) === true) $projection['limited'] = true;
    if ($disposition === 'note') return array_intersect_key($projection, ['state'=>true,'summary'=>true,'limited'=>true])
        + ['office_disposition'=>'note'];
    $taskTitle = $work['output']['agent_task_title'] ?? null;
    if (is_string($taskTitle) && trim($taskTitle) !== '') {
        $task = vp3_initiative_office_entry_child_projection_v1($runQuery, $receiptQuery, $scope, $run, $goal, $taskTitle, $now->getTimestamp(), $policy);
        if ($task !== null) $projection['agent_task'] = $task;
    }
    $savedMessage = $result['initiative_message_id'] ?? null;
    $messageId = is_int($savedMessage) || is_string($savedMessage)
        ? filter_var($savedMessage, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
    if (($result['initiative_delivery'] ?? null) === 'delivered' && $messageId !== false
        && vp3_initiative_task_report_message_v1($pdo, $original, $messageId, (int)$run['id'], $work)) {
        $cid = $original['conversation_id'];
        $projection['chat_action'] = ['kind'=>'chat', 'label'=>'View in Chat', 'conversation_id'=>$cid, 'message_id'=>$messageId,
            'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'], $cid).'&message_id='.$messageId];
    }
    return $projection;
}
