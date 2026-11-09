<?php
declare(strict_types=1);

// Load the same production includes as the Cloud CLI worker, without
// entering its host database loop or a Chat request that supplies extra modules.
$includes = dirname(__DIR__).'/includes/';
foreach (['bootstrap', 'agent-job-worker-v1900', 'agent-worker-cloud-v1910', 'cloud-mission-schedules-v1', 'agent-initiative-v1'] as $module) {
    require_once $includes.$module.'.php';
}

function initiative_test_check(bool $passed, string $name): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }
    $GLOBALS['initiative_test_checks']++;
    echo "PASS $name\n";
}

function initiative_test_json(array $jobs): string
{
    return json_encode(['jobs' => $jobs], JSON_THROW_ON_ERROR);
}

function initiative_test_reject(string $answer, array $evidence, string $name,array $tools=['work.prepare_private']): void
{
    try {
        vp3_initiative_plan_v1($answer, $evidence,$tools);
    } catch (InvalidArgumentException | JsonException) {
        initiative_test_check(true, $name);
        return;
    }
    throw new RuntimeException('Expected an invalid plan: '.$name);
}

$GLOBALS['initiative_test_checks'] = 0;
initiative_test_check(function_exists('vp3_agent_chat_principal_v380') && function_exists('vp3_agent_chat_conversation_v380'),
    'the production Cloud worker includes load the canonical Initiative Chat boundary');

$calendar = 'calendar:event:42';
$transcript = 'transcript:123';
$mission = 'mission:123e4567-e89b-42d3-a456-426614174000';
$evidence = [
    $calendar => [
        'ref' => $calendar, 'revision' => hash('sha256', 'calendar 42 revision 1'),
        'title' => 'Album release review', 'detail' => 'Upcoming meeting', 'url' => '/calendar.php?event=42',
    ],
    $transcript => [
        'ref' => $transcript, 'revision' => hash('sha256', 'transcript 123 revision 1'),
        'title' => 'Previous release review', 'detail' => 'Review requested on the master', 'url' => '/transcriptions.php?session=123',
    ],
    $mission => [
        'ref' => $mission, 'revision' => hash('sha256', 'mission revision 1'),
        'title' => 'Studio setup', 'detail' => 'Open work remains', 'url' => '/agent-workflows.php',
    ],
];
$meetingJob = [
    'tool' => 'work.prepare_private', 'primary' => $calendar, 'refs' => [$calendar, $transcript],
    'focus' => 'Prepare review questions using the prior transcript.', 'report' => false,
];
$missionJob = [
    'tool' => 'work.prepare_private', 'primary' => $mission, 'refs' => [$mission],
    'focus' => 'Summarize the outstanding studio setup work.', 'report' => true,
];
$transcriptJob = [
    'tool' => 'work.prepare_private', 'primary' => $transcript, 'refs' => [$transcript],
    'focus' => 'Collect questions to clarify in the next discussion.', 'report' => false,
];

initiative_test_check(vp3_initiative_plan_v1('{"jobs":[]}', []) === [], 'an empty plan is valid without evidence');
initiative_test_check(vp3_initiative_plan_v1('{"jobs":[]}', $evidence) === [], 'available evidence does not require the Agent to prepare anything');
initiative_test_check(
    vp3_initiative_plan_v1(initiative_test_json([$meetingJob]), $evidence) === [$meetingJob],
    'a private preparation can connect a calendar event to a supplied transcript'
);
initiative_test_check(
    vp3_initiative_plan_v1(initiative_test_json([$meetingJob, $missionJob]), $evidence) === [$meetingJob, $missionJob],
    'two independent preparations retain their own refs and at most one reporting flag'
);
initiative_test_check(
    count(vp3_initiative_plan_v1(initiative_test_json([$meetingJob, $missionJob, $transcriptJob]), $evidence)) === 3,
    'three preparations with distinct primary references are allowed'
);

foreach ([
    '{' => 'malformed JSON is rejected',
    '[]' => 'the top-level plan must be an object',
    '{}' => 'the jobs field is required',
    '{"jobs":null}' => 'jobs cannot be null',
    '{"jobs":"[]"}' => 'jobs must be a JSON array',
    '{"jobs":[[]]}' => 'each job must be a JSON object',
    '{"jobs":[],"status":"completed"}' => 'the plan cannot carry a fabricated completion status',
    '{"jobs":[],"instructions":"send email"}' => 'extra instructions at the plan level are rejected',
] as $answer => $name) {
    initiative_test_reject($answer, $evidence, $name);
}
initiative_test_reject(
    json_encode(['jobs' => (object) [$meetingJob]], JSON_THROW_ON_ERROR),
    $evidence,
    'a numeric-key JSON object is not a job array'
);
initiative_test_reject(
    initiative_test_json([array_replace($meetingJob, ['refs' => (object) [$calendar]])]),
    $evidence,
    'a numeric-key JSON object is not a reference array'
);

foreach (array_keys($meetingJob) as $required) {
    $missing = $meetingJob;
    unset($missing[$required]);
    initiative_test_reject(initiative_test_json([$missing]), $evidence, "a preparation requires $required");
}

foreach ([
    ['tool' => 'reminders.create'],
    ['tool' => 'work.prepare_private '],
    ['primary' => 42],
    ['primary' => 'calendar:event:99'],
    ['refs' => [$calendar, 'transcript:999']],
    ['refs' => [$transcript]],
    ['refs' => [$calendar, $calendar]],
    ['refs' => []],
    ['refs' => $calendar],
    ['refs' => [$calendar, 123]],
    ['focus' => '   '],
    ['focus' => ['not', 'text']],
    ['report' => 'true'],
    ['report' => 1],
    ['action' => 'send_email'],
    ['revision' => hash('sha256', 'invented revision')],
] as $replacement) {
    $field = array_key_first($replacement);
    initiative_test_reject(
        initiative_test_json([array_replace($meetingJob, $replacement)]),
        $evidence,
        'rejects invalid or unapproved job field '.json_encode([$field => $replacement[$field]], JSON_THROW_ON_ERROR)
    );
}

initiative_test_reject(
    initiative_test_json([$meetingJob, array_replace($meetingJob, ['refs' => [$calendar]])]),
    $evidence,
    'a primary reference cannot be reused for a second preparation'
);
initiative_test_check(
    count(vp3_initiative_plan_v1(
    initiative_test_json([array_replace($meetingJob, ['report' => true]), $missionJob]),
    $evidence))===2,
    'independent reportable outcomes share the configured work budget and delivery cooldown'
);
$otherTranscript = 'transcript:124';
$evidence[$otherTranscript] = [
    'ref' => $otherTranscript, 'revision' => hash('sha256', 'transcript 124 revision 1'),
    'title' => 'A fourth possible task', 'detail' => 'Additional available evidence', 'url' => '/transcriptions.php?session=124',
];
$fourthJob = array_replace($transcriptJob, ['primary' => $otherTranscript, 'refs' => [$otherTranscript]]);
initiative_test_reject(
    initiative_test_json([$meetingJob, $missionJob, $transcriptJob, $fourthJob]),
    $evidence,
    'four otherwise valid preparations exceed the per-plan limit'
);
putenv('VP3_INITIATIVE_MAX_JOBS=4');
try{
    initiative_test_check(count(vp3_initiative_plan_v1(initiative_test_json([$meetingJob,$missionJob,$transcriptJob,$fourthJob]),$evidence))===4,
        'an explicitly configured work budget admits four independent outcomes');
}finally{putenv('VP3_INITIATIVE_MAX_JOBS');}
initiative_test_check(count(vp3_initiative_plan_v1(initiative_test_json([$meetingJob,array_replace($meetingJob,['tool'=>'knowledge.search'])]),
    $evidence,['work.prepare_private','knowledge.search']))===2,'distinct authorized specialist tools can advance the same primary situation');
$topicRef='topic-interest:'.hash('sha256','wood-fired oven heat management');
$topicJob=['tool'=>'topic.research','primary'=>$topicRef,'refs'=>[$topicRef,$calendar],
    'focus'=>'Find a reliable oven temperature guide','report'=>true];
initiative_test_check(vp3_initiative_plan_v1(initiative_test_json([$topicJob]),$evidence+[$topicRef=>['ref'=>$topicRef]],
    ['topic.research'])===[array_replace($topicJob,['refs'=>[$topicRef]])],
    'General research normalizes assignments to their primary topic so unrelated sources cannot create duplicate work');
$checkIn=array_replace($meetingJob,['tool'=>'chat.check_in','report'=>true]);
initiative_test_check(vp3_initiative_plan_v1(initiative_test_json([$checkIn,$missionJob]),$evidence,
    ['chat.check_in','work.prepare_private'])===[$checkIn,$missionJob],
    'one authorized conversational check-in can accompany independent private work within the same budget');
initiative_test_reject(initiative_test_json([array_replace($checkIn,['report'=>false])]),$evidence,
    'a conversational check-in must be reportable rather than a hidden greeting draft',['chat.check_in']);
initiative_test_reject(initiative_test_json([$checkIn,array_replace($checkIn,['primary'=>$mission,'refs'=>[$mission]])]),$evidence,
    'one assessment cannot create multiple conversational interruptions even for different situations',['chat.check_in']);
foreach ([125, 126] as $sessionId) {
    $ref = 'transcript:'.$sessionId;
    $evidence[$ref] = [
        'ref' => $ref, 'revision' => hash('sha256', $ref),
        'title' => 'Another relevant transcript', 'detail' => 'Additional owned evidence', 'url' => '/transcriptions.php?session='.$sessionId,
    ];
}
initiative_test_reject(
    initiative_test_json([array_replace($meetingJob, ['refs' => array_keys($evidence)])]),
    $evidence,
    'six distinct owned references exceed the per-job limit'
);
initiative_test_reject(
    initiative_test_json([array_replace($meetingJob, ['focus' => str_repeat('x', 301)])]),
    $evidence,
    'an overlong focus is rejected rather than silently truncated'
);

echo 'AGENT_INITIATIVE_V1=PASS ('.$GLOBALS['initiative_test_checks']." parser cases)\n";
