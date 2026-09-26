<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$include=file_get_contents($root.'/includes/homeserver-tasks-calendar-v243.php');
$api=file_get_contents($root.'/api/homeserver-tasks-calendar-v243.php');
$shared=file_get_contents($root.'/includes/homeserver-shared-agent-v210.php');
$federated=file_get_contents($root.'/includes/homeserver-federated-data-v240.php');
$calendar=file_get_contents($root.'/includes/user-calendar-v1300.php');
$localExecution=file_get_contents($root.'/includes/homeserver-local-execution-v230.php');
$governed=file_get_contents($root.'/includes/homeserver-governed-actions-v233.php');

$checks=[
  'Section 4 include exists'=>is_string($include)&&str_contains($include,'VP3_HOMESERVER_TASK_CALENDAR_V243'),
  'Cloud tasks use canonical Agent Brain storage'=>str_contains($include,"memory_type IN ('task','commitment')")&&str_contains($include,"'vp3_cloud','tasks'"),
  'HomeServer task reads use tools'=>str_contains($include,"'tasks.list'"),
  'HomeServer calendar reads use tools'=>str_contains($include,"'calendar.list'"),
  'Writes use governed actions'=>str_contains($include,'homeserver_governed_v233_request'),
  'No remote native-table writes'=>!str_contains($include,'INSERT INTO local_calendar_events')&&!str_contains($include,'UPDATE local_calendar_events'),
  'Calendar is a federated dataset'=>str_contains($federated,"'calendar'"),
  'Shared Agent contains calendar'=>str_contains($shared,"'calendar'"),
  'User Calendar includes HomeServer projection'=>str_contains($calendar,'homeserver_task_calendar_v243_homeserver_calendar_items'),
  'Task/calendar domains routed'=>str_contains($localExecution,"str_starts_with($tool,'tasks.')")&&str_contains($localExecution,"str_starts_with($tool,'calendar.')"),
  'Governed catalog includes task update/delete'=>str_contains($governed,"'tasks.update'")&&str_contains($governed,"'tasks.delete'"),
  'Governed catalog includes calendar writes'=>str_contains($governed,"'calendar.create'")&&str_contains($governed,"'calendar.delete'"),
  'API is CSRF protected'=>str_contains($api,'hash_equals(csrf_token(),$csrf)'),
  'API exposes unified datasets'=>str_contains($api,'homeserver_task_calendar_v243_unified_tasks')&&str_contains($api,'homeserver_task_calendar_v243_unified_calendar'),
];
foreach($checks as $name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}
echo "HomeServer v2.4 Section 4 cloud task/calendar contract: PASS\n";
