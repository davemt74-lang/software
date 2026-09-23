<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.40 — Goal & Task Continuity release gate.
 */
const VP3_COGNITIVE_RELEASE_V2440='vp3-cognitive-continuity-release-v2440-20260922';

function vp3_cognitive_release_manifest_v2440(): array
{
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2440,
        'release_phase'=>'v24.40',
        'previous_release'=>'v24.30',
        'scope'=>'goal_and_task_continuity',
        'continuity_authority'=>'cognitive_continuity_v2440_projection',
        'durable_authorities'=>[
            'goals'=>'agent_goals',
            'workflows'=>'agent_workflow_runs',
            'accepted_plans'=>'cognitive_plan_runs_v560',
            'meeting_commitments'=>'video_meeting_commitment_command_v18230',
            'browser_followthrough'=>'browser_transaction_continuity_v2260',
            'live_session'=>'agent_live_sessions_v2370',
        ],
        'invariants'=>[
            'continuity_is_projection_only'=>true,
            'second_goal_store'=>false,
            'second_task_store'=>false,
            'second_workflow_queue'=>false,
            'continuity_grants_execution_authority'=>false,
            'continuity_grants_approval_authority'=>false,
            'named_agent_workflows_are_agent_scoped'=>true,
            'owner_global_goals_system_agent_only'=>true,
            'owner_global_meeting_commitments_system_agent_only'=>true,
            'owner_global_browser_followthrough_system_agent_only'=>true,
            'accepted_cognitive_plans_namespace_scoped'=>true,
            'open_work_is_bounded'=>true,
            'working_context_includes_continuity'=>true,
            'continue_resumes_existing_authority'=>true,
            'waiting_approval_requests_approval'=>true,
            'waiting_user_requests_user_decision'=>true,
            'live_session_receives_canonical_goal_task_refs'=>true,
            'observable_turn_metadata_contains_continuity_refs'=>true,
            'agent_brain_open_work_uses_same_projection'=>true,
            'model_reasoning_persisted'=>false,
        ],
    ];
}

function vp3_cognitive_release_readiness_v2440(?PDO $pdo=null): array
{
    $pdo=$pdo?:db();
    $checks=[
        'v2430_ready'=>function_exists('vp3_cognitive_release_readiness_v2430'),
        'continuity_snapshot_loaded'=>function_exists('vp3_cognitive_continuity_snapshot_v2440'),
        'continuity_resume_loaded'=>function_exists('vp3_cognitive_continuity_resume_v2440'),
        'continuity_context_loaded'=>function_exists('vp3_cognitive_continuity_context_item_v2440'),
        'turn_orchestration_preserved'=>function_exists('vp3_cognitive_turn_prepare_v2430'),
        'working_context_preserved'=>function_exists('vp3_cognitive_context_assemble_v2420'),
        'live_session_preserved'=>function_exists('vp3_live_session_note_action_v2370'),
        'workflow_authority_preserved'=>function_exists('agent_workflow_row_v1400'),
        'goal_authority_preserved'=>function_exists('agent_goal_state_v1710'),
        'plan_authority_preserved'=>function_exists('vp3_cognitive_orchestration_run_v560'),
    ];
    return [
        'build'=>VP3_COGNITIVE_RELEASE_V2440,
        'ready'=>!in_array(false,$checks,true),
        'checks'=>$checks,
        'authority'=>'diagnostic_only',
    ];
}
