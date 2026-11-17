<?php
declare(strict_types=1);

/** Compare exact saved work and deadlines. Generated owner questions keep their existing decision proof. */
function vp3_coordination_tasks_material_v1(array $source, array $report, array $item): ?array
{
    $ref = $item['ref'] ?? null;
    $trigger = $item['trigger'] ?? null;
    $record = $source['record'] ?? null;
    if (!is_string($ref) || preg_match('/\Aoffice:(task|task-list|reminder):[1-9][0-9]{0,17}\z/D', $ref, $match) !== 1
        || 'office:'.($source['ref'] ?? '') !== $ref || !is_array($record) || !empty($source['limited'])
        || !in_array($trigger, ['overdue','blocked','due_soon','completed'], true)) return null;

    $taskFacts = static function (array $task): array {
        return array_intersect_key($task, array_flip(['id','title','text','status','due_at','assigned_to_someone_else','review_state']));
    };
    $facts = match ($match[1]) {
        'task'=>$taskFacts($record),
        'task-list'=>['title'=>$record['title'],'status'=>$record['status'],'progress'=>$record['progress'],
            'items'=>array_column(array_map($taskFacts, $record['items']), null, 'id')],
        'reminder'=>array_intersect_key($record, array_flip(['title','timezone','start_local','recurrence','delivery','status',
            'next_delivery_at','condition','pending_occurrences'])),
    };
    if ($match[1] === 'task-list') {
        if (count($facts['items']) !== ($record['progress']['total'] ?? null)) return null;
        ksort($facts['items'], SORT_NUMERIC);
    }
    if ($trigger === 'completed') {
        $result = $report['agent_task']['result'] ?? null;
        if (($report['agent_task']['state'] ?? null) !== 'completed' || !is_string($result) || trim($result) === '') return null;
        $facts['result'] = trim($result);
    }

    return ['key'=>vp3_coordination_source_hash_v1(['tasks-material-v1',$ref]),
        'revision'=>vp3_coordination_source_hash_v1(['tasks-material-v1',$trigger,$facts]),
        'related_refs'=>[$ref]];
}
