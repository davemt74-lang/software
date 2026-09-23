<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-commitment-protection-v2540.php';

function v2540_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$now=1700000000;
v2540_assert(vp3_cognitive_commitment_deadline_state_v2540($now-1,$now)==='overdue','past commitments are overdue');
v2540_assert(vp3_cognitive_commitment_deadline_state_v2540($now+3600,$now)==='urgent','six-hour window is urgent');
v2540_assert(vp3_cognitive_commitment_deadline_state_v2540($now+43200,$now)==='due_soon','one-day window is due soon');
v2540_assert(vp3_cognitive_commitment_deadline_state_v2540($now+172800,$now)==='scheduled','later commitment is scheduled');

$meeting=vp3_cognitive_commitment_strength_v2540('meeting',true,1.0);
$goal=vp3_cognitive_commitment_strength_v2540('goal',true,1.0);
$memory=vp3_cognitive_commitment_strength_v2540('memory_commitment',true,1.0);
$inferred=vp3_cognitive_commitment_strength_v2540('memory_task',false,1.0);
v2540_assert($meeting>$memory&&$memory>$goal&&$goal>$inferred,'source-aware commitment strength is deterministic');

$commitments=[
    [
        'key'=>'goal:1','source_kind'=>'goal','executor'=>'cloud','due_ts'=>$now+1200,
        'expected_seconds'=>1800,'strength'=>0.82,'at_risk'=>false,'verified_complete'=>false,
    ],
    [
        'key'=>'meeting:2','source_kind'=>'meeting','executor'=>'cloud','due_ts'=>$now+7200,
        'expected_seconds'=>900,'strength'=>1.0,'at_risk'=>false,'verified_complete'=>false,
    ],
    [
        'key'=>'memory:x','source_kind'=>'memory_commitment','executor'=>'external','due_ts'=>$now+600,
        'expected_seconds'=>0,'strength'=>0.9,'at_risk'=>false,'verified_complete'=>false,
    ],
];
$capacity=[
    'executors'=>[
        'cloud'=>['max'=>1,'active'=>0,'free'=>1],
        'homeserver'=>['max'=>0,'active'=>0,'free'=>0],
    ],
];
$sim=vp3_cognitive_commitment_apply_capacity_conflicts_v2540($commitments,$capacity,$now);
v2540_assert(($sim[0]['conflict_code']??'')==='capacity_deadline_conflict','capacity simulation detects an impossible protected deadline');
v2540_assert(empty($sim[1]['conflict_code']),'later commitment remains feasible after lane simulation');
v2540_assert(!isset($sim[2]['conflict_code']),'external commitment is not assigned worker-capacity conflict');

$noCapacity=[[
    'key'=>'goal:9','source_kind'=>'goal','executor'=>'homeserver','due_ts'=>$now+3600,
    'expected_seconds'=>600,'strength'=>0.82,'at_risk'=>false,'verified_complete'=>false,
]];
$sim2=vp3_cognitive_commitment_apply_capacity_conflicts_v2540($noCapacity,$capacity,$now);
v2540_assert(($sim2[0]['conflict_code']??'')==='capacity_unavailable','unavailable executor is surfaced without switching executors');
v2540_assert(($sim2[0]['executor']??'')==='homeserver','capacity conflict simulation preserves executor');

echo "Cognitive Commitment Protection v25.40 deterministic runtime: PASS\n";
