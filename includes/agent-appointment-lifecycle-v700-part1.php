<?php
declare(strict_types=1);

function agent_appointment_lifecycle_schema_ready_v700(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'agent_scheduling_intake_questions',
        'agent_scheduling_intake_answers',
        'agent_scheduling_lifecycle_events',
        'agent_scheduling_automation_deliveries',
        'agent_scheduling_agent_briefs',
        'agent_scheduling_followups',
    ] as $table)if(!table_exists($table))return false;
    return column_exists('agent_scheduling_bookings','lifecycle_status')
        && column_exists('agent_scheduling_bookings','rescheduled_at')
        && column_exists('agent_scheduling_bookings','no_show_at')
        && column_exists('agent_scheduling_event_types','reminder_24h_enabled')
        && column_exists('agent_scheduling_event_types','reminder_soon_minutes')
        && column_exists('agent_scheduling_automation_deliveries','occurrence_key');
}

