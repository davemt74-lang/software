<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_RELEASE_V124='vp3-campaigns-rewards-release-v124-20260923';

function campaigns_rewards_release_manifest_v124(): array
{
    return [
      'release'=>'Campaigns & Rewards V1.24','build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V124,
      'scope'=>'Journey Operations, Live Monitoring & Recovery','plugin_key'=>'campaigns_rewards',
      'schema'=>['campaign_journey_instances'],
      'authority'=>[
        'instance'=>'campaign_journey_instances','release'=>'campaign_journey_versions','execution'=>'campaign_deliveries',
        'audit'=>'campaign_activity_events','incidents'=>'derived runtime state + Agent recommendations'
      ],
      'invariants'=>[
        'migration_required'=>true,'new_tables'=>1,'no_second_delivery_queue'=>true,'no_second_event_ledger'=>true,
        'instances_pin_journey_version'=>true,'pause_resume_cancel_human_only'=>true,'skip_retry_human_only'=>true,
        'cancel_suppresses_pending_only'=>true,'sent_history_immutable'=>true,'dead_letter_recovery_governed'=>true,
        'incident_detection_read_only'=>true,'agent_cannot_pause_cancel_skip_retry'=>true,
        'release_health_comparison_descriptive'=>true,'transcription_saved_source_fix_included'=>true,
      ]
    ];
}

function campaigns_rewards_release_readiness_v124(?PDO $pdo=null): array
{
    $pdo??=db();$domain=function_exists('vp3_cognitive_domain_registry_v2600')?(vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[]):[];
    $checks=[
      'v123_ready'=>function_exists('campaigns_rewards_release_readiness_v123'),
      'schema'=>$pdo&&campaigns_rewards_journey_operations_schema_ready_v124($pdo),
      'instances'=>function_exists('campaigns_rewards_journey_instance_start_v124'),
      'controls'=>function_exists('campaigns_rewards_instance_control_v124')&&function_exists('campaigns_rewards_instance_skip_delivery_v124'),
      'incidents'=>function_exists('campaigns_rewards_journey_incidents_v124'),
      'runtime'=>function_exists('campaigns_rewards_dispatch_due_v124'),
      'cognitive_domain'=>($domain['implementation_status']??'')==='integrated-v1.24',
    ];
    return ['build'=>VP3_CAMPAIGNS_REWARDS_RELEASE_V124,'ready'=>!in_array(false,$checks,true),'checks'=>$checks,'authority'=>'diagnostic_only'];
}
