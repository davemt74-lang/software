<?php
declare(strict_types=1);
require __DIR__ . '/../includes/agent-workflow-runs-v1400.php';

function wf_assert(bool $value,string $message): void { if(!$value){fwrite(STDERR,$message."\n");exit(1);} }

wf_assert(agent_workflow_allowed_transition_v1400('queued','planning'),'queued -> planning must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('planning','approval_pending'),'planning -> approval_pending must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('approval_pending','approved'),'approval_pending -> approved must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('approved','executing'),'approved -> executing must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('executing','completed'),'executing -> completed must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('executing','failed'),'executing -> failed must be allowed');
wf_assert(agent_workflow_allowed_transition_v1400('failed','queued'),'failed -> queued retry must be allowed');
wf_assert(!agent_workflow_allowed_transition_v1400('completed','executing'),'completed workflows must be terminal');
wf_assert(agent_workflow_type_v1400(['source'=>'calendar_conflict'])==='calendar_conflict_resolution','calendar conflicts need the conflict workflow');
wf_assert(agent_workflow_type_v1400(['source'=>'calendar_upcoming'])==='calendar_commitment_prep','upcoming calendar items need prep workflow');
wf_assert(agent_workflow_execution_target_v1400(['source'=>'homeserver_private'])==='homeserver','HomeServer source should route to HomeServer target');
wf_assert(agent_workflow_execution_target_v1400(['source'=>'calendar_upcoming'])==='cloud','Calendar source should remain Cloud-authoritative');
$safe=agent_workflow_public_json_v1400('{"result":"ok","access_token":"secret","reasoning":"private","count":2}');
wf_assert(isset($safe['result'])&&isset($safe['count']),'safe structured result fields should survive');
wf_assert(!isset($safe['access_token'])&&!isset($safe['reasoning']),'secret/reasoning-like result keys must be filtered');

echo "Agent Workflow Runs v14.00 helpers: OK\n";
