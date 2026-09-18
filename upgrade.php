<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
require_once __DIR__ . '/includes/artist-listening.php';
require_once __DIR__ . '/includes/artist-listening-transcript.php';
require_once __DIR__ . '/includes/studio-participants.php';
require_once __DIR__ . '/includes/studio-voice-profile.php';
require_once __DIR__ . '/includes/onboarding-intelligence.php';
require_once __DIR__ . '/includes/user-calendar-v1300.php';
require_once __DIR__ . '/includes/agent-work-control-v173.php';
require_once __DIR__ . '/includes/agent-work-dependencies-v174.php';
require_once __DIR__ . '/includes/agent-objective-verification-v176.php';
require_once __DIR__ . '/includes/agent-objective-memory-v177.php';
require_once __DIR__ . '/includes/agent-goal-strategy-v1710.php';
require_once __DIR__ . '/includes/agent-goal-planning-v1711.php';
require_once __DIR__ . '/includes/agent-goal-review-v1714.php';
require_once __DIR__ . '/includes/video-meetings-agenda-v18140.php';
require_once __DIR__ . '/includes/video-meetings-actions-v18150.php';
require_once __DIR__ . '/includes/video-meetings-followthrough-intelligence-v18160.php';
require_once __DIR__ . '/includes/video-meetings-outcome-learning-v18170.php';
require_once __DIR__ . '/includes/video-meetings-adaptive-planning-v18180.php';
require_once __DIR__ . '/includes/video-meetings-plan-action-handoff-v18190.php';
require_once __DIR__ . '/includes/video-meetings-followthrough-verification-v18200.php';
require_once __DIR__ . '/includes/video-meetings-cross-meeting-continuity-v18210.php';
require_once __DIR__ . '/includes/video-meetings-closure-recurring-continuity-v18220.php';
require_permission('users.manage');

function vp3_upgrade_complete(): bool
{
    return access_schema_ready()
        && subscription_schema_ready()
        && subscription_entitlements_v340_schema_ready()
        && workspace_team_v350_schema_ready()
        && ai_usage_accounting_v032_schema_ready()
        && column_exists('ai_execution_ledger','runtime_version')
        && column_exists('ai_execution_ledger','requested_route')
        && column_exists('ai_execution_ledger','attempted_route')
        && column_exists('ai_execution_ledger','actual_route')
        && column_exists('ai_execution_ledger','route_reason')
        && column_exists('ai_execution_ledger','fallback_reason')
        && vp3_radar_schema_ready()
        && vp3_agent_referral_schema_ready()
        && subscription_self_service_schema_ready()
        && billing_schema_ready()
        && token_pack_schema_ready()
        && password_reset_schema_ready()
        && vp3_extension_schema_ready_v2000()
        && chat_settings_schema_ready_v237()
        && permission_v105_playlist_permission_ready()
        && personal_capability_seeded_v242()
        && personal_capability_schema_ready_v242()
        && vp3_plugin_schema_ready_v320()
        && vp3_plugin_lifecycle_v360_ready()
        && vp3_social_schema_ready_v320()
        && vp3_human_messaging_v370_ready()
        && vp3_browser_share_schema_ready_v2010()
        && vp3_browser_share_media_schema_ready_v2040()
        && vp3_browser_source_feed_schema_ready_v2050()
        && vp3_research_schema_ready_v2060()
        && vp3_live_room_schema_ready_v2070()
        && vp3_browser_trust_schema_ready_v2080()
        && vp3_search_schema_ready_v2090()
        && midi_v217_schema_ready()
        && (string)setting('midi_permissions_seed_v217','') === '1'
        && artist_listening_v172_schema_ready()
        && artist_listening_v237_schema_ready()
        && studio_participants_schema_ready()
        && studio_voice_profile_schema_ready()
        && user_agent_system_schema_ready_v236()
        && vp3_user_agent_lifecycle_schema_ready_v390()
        && vp3_agent_memory_scope_schema_ready_v410()
        && agent_scheduling_schema_ready_v430()
        && agent_calendar_sync_schema_ready_v500()
        && user_calendar_schema_ready_v1300()
        && agent_team_scheduling_schema_ready_v600()
        && agent_appointment_lifecycle_schema_ready_v700()
        && video_meeting_schema_ready_v1800()
        && video_meeting_transcription_schema_ready_v1800()
        && video_meeting_intelligence_schema_ready_v1820()
        && video_meeting_agenda_schema_ready_v18140()
        && video_meeting_action_schema_ready_v18150()
        && video_meeting_followthrough_intelligence_schema_ready_v18160()
        && video_meeting_outcome_learning_schema_ready_v18170()
        && video_meeting_adaptive_planning_schema_ready_v18180()
        && video_meeting_plan_action_schema_ready_v18190()
        && video_meeting_followthrough_verification_schema_ready_v18200()
        && video_meeting_continuity_schema_ready_v18210()
        && video_meeting_closure_schema_ready_v18220()
        && video_meeting_manual_schema_ready_v1830()
        && agent_commerce_schema_ready_v800()
        && agent_paid_appointments_schema_ready_v800()
        && agent_work_control_schema_ready_v173()
        && agent_work_dependencies_schema_ready_v174()
        && agent_objective_verification_schema_ready_v176()
        && agent_objective_memory_schema_ready_v177()
        && agent_goal_strategy_schema_ready_v1710()
        && agent_goal_planning_schema_ready_v1711()
        && agent_goal_review_schema_ready_v1714()
        && vp3_cognitive_schema_ready_v500()
        && table_exists('homeserver_connections')
        && table_exists('homeserver_releases')
        && table_exists('homeserver_chat_sessions')
        && table_exists('agent_compute_preferences')
        && table_exists('agent_compute_overrides')
        && onboarding_intelligence_schema_ready()
        && user_data_usage_schema_ready_v236()
        && shared_knowledge_index_schema_ready_v236()
        && profile_agent_schema_ready()
        && crm_v180_schema_ready()
        && artist_workspace_v181_schema_ready()
        && music_workspace_release_schema_v330_ready()
        && music_workspace_resources_v330_schema_ready()
        && artist_media_v182_schema_ready()
        && artist_posts_v183_schema_ready()
        && artist_shows_v184_schema_ready()
        && artist_music_v185_schema_ready();
}

$error = '';
$complete = vp3_upgrade_complete();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        try {
            ensure_access_schema();
            subscription_ensure_schema();
            subscription_entitlements_v340_ensure_schema();
            artist_workspace_v104_ensure_schema();
            workspace_team_v350_ensure_schema();
            ai_usage_accounting_v032_ensure_schema();
            vp3_radar_ensure_schema();
            vp3_agent_referral_ensure_schema();
            subscription_self_service_ensure_schema();
            billing_ensure_schema();
            token_pack_ensure_schema();
            password_reset_ensure_schema();
            vp3_extension_ensure_schema_v2000();
            chat_settings_ensure_schema_v237();
            permission_v105_seed_playlist_permission();
            personal_capability_seed_v242();
            vp3_plugin_ensure_schema_v320();
            vp3_social_ensure_schema_v320();
            vp3_human_messaging_v370_ensure_schema();
            vp3_browser_share_ensure_schema_v2010();
            vp3_browser_share_media_ensure_schema_v2040();
            vp3_browser_source_feed_ensure_schema_v2050();
            vp3_research_ensure_schema_v2060();
            vp3_live_room_ensure_schema_v2070();
            vp3_browser_trust_ensure_schema_v2080();
            vp3_search_ensure_schema_v2090();
            midi_v217_ensure_schema();
            artist_listening_v172_ensure_schema();
            artist_listening_v237_ensure_schema();
            studio_participants_ensure_schema();
            studio_voice_profile_ensure_schema();
            user_agent_system_ensure_schema_v236();
            vp3_user_agent_lifecycle_ensure_schema_v390();
            vp3_agent_memory_scope_ensure_schema_v410();
            agent_scheduling_ensure_schema_v430();
            agent_calendar_sync_ensure_schema_v500();
            user_calendar_ensure_schema_v1300();
            agent_team_scheduling_ensure_schema_v600();
            agent_appointment_lifecycle_ensure_schema_v700();
            agent_commerce_ensure_schema_v800();
            agent_paid_appointments_ensure_schema_v800();

            $pdo = db();
            if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
            agent_work_control_ensure_schema_v173($pdo);
            agent_work_dependencies_ensure_schema_v174($pdo);
            agent_objective_verification_ensure_schema_v176($pdo);
            agent_objective_memory_ensure_schema_v177($pdo);
            agent_goal_strategy_ensure_schema_v1710($pdo);
            agent_goal_planning_ensure_schema_v1711($pdo);
            agent_goal_review_ensure_schema_v1714($pdo);
            vp3_cognitive_ensure_schema_v500($pdo);
            homeserver_vp3_ensure_schema($pdo);
            if (!homeserver_agent_v018_ensure_schema($pdo)) throw new RuntimeException('HomeServer Agent chat schema could not be installed.');
            agent_compute_v020_ensure_schema($pdo);
            agent_compute_v023_ensure_schema($pdo);

            onboarding_intelligence_ensure_schema();
            user_data_usage_ensure_schema_v236();
            shared_knowledge_index_ensure_schema_v236();
            profile_agent_ensure_schema();
            personal_capability_ensure_schema_v242();
            crm_v180_ensure_schema();
            video_meeting_ensure_schema_v1800($pdo);
            video_meeting_transcription_ensure_schema_v1800($pdo);
            video_meeting_intelligence_ensure_schema_v1820($pdo);
            video_meeting_agenda_ensure_schema_v18140($pdo);
            video_meeting_action_ensure_schema_v18150($pdo);
            video_meeting_followthrough_intelligence_ensure_schema_v18160($pdo);
            video_meeting_outcome_learning_ensure_schema_v18170($pdo);
            video_meeting_adaptive_planning_ensure_schema_v18180($pdo);
            video_meeting_plan_action_ensure_schema_v18190($pdo);
            video_meeting_followthrough_verification_ensure_schema_v18200($pdo);
            video_meeting_continuity_ensure_schema_v18210($pdo);
            video_meeting_closure_ensure_schema_v18220($pdo);
            video_meeting_manual_ensure_schema_v1830($pdo);
            artist_workspace_v181_ensure_schema();
            vp3_plugin_migrate_legacy_v360($pdo);
            vp3_human_messaging_v370_migrate_legacy($pdo);
            music_workspace_release_schema_v330_ensure($pdo);
            music_workspace_resources_v330_ensure_schema($pdo);
            artist_media_v182_ensure_schema();
            artist_posts_v183_ensure_schema();
            artist_shows_v184_ensure_schema();
            artist_music_v185_ensure_schema();
            $complete = vp3_upgrade_complete();

            if ($complete) {
                flash('notice', 'VP3 database upgrade complete: subscriptions, composable product entitlements, canonical plugin lifecycle, canonical human messaging, Browser Companion device authentication + Browser Share backend + private rich media + Live Rooms/Cloak Mode + Source Change Intelligence + Claims + Moderation + Search & Discovery, durable Agent retirement, Agent-scoped Brain memory, native Agent Scheduling, unified User Calendar, external calendar synchronization, Team Scheduling, Appointment Lifecycle + Automation, Video Meetings + Meeting Intelligence + Meeting Agenda orchestration + Meeting Action execution + Follow-Through Intelligence + Meeting Outcome Learning + Adaptive Meeting Planning + Plan-to-Action Handoff + Follow-Through Verification & Closure + Cross-Meeting Continuity + Meeting Closure & Recurring Continuity + manual Calendar meetings, Paid Appointments, Agent Work Control + Dependencies, Objective Verification + Adaptive Replanning, Objective Memory + Outcome Learning, Goals + Strategy + Adaptive Roadmaps + Forecast Review Learning, VP3 Cognitive Runtime Core v5.00, canonical Agent runtime routing and route-attributed AI accounting, social relationships, Team invitation/lifecycle collaboration, workspace-owned Music resources, Knowledge, Profile Agent, HomeServer Agent continuity, Agent Radar, CRM, transcriptions, and Music/Studio capabilities are ready.');
                redirect(url('/admin/users.php'));
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

vp3_public_header('Database Upgrade — VP3', 'Upgrade the VP3 database and application capabilities.', ['compact' => true]);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">System maintenance</div>
      <h1>Keep VP3 capabilities current.</h1>
      <p>The upgrade process adds the current subscription, composable entitlement, plugin lifecycle, social, canonical human messaging, billing, AI, HomeServer, collaboration, Browser Companion device authentication + Browser Share backend + private rich media + Live Rooms/Cloak Mode + Source Change Intelligence + Claims + Moderation + Search & Discovery, scheduling, unified User Calendar, calendar sync, Team Scheduling, Appointment Lifecycle + Automation, Video Meetings + Meeting Intelligence + Meeting Agenda orchestration + Meeting Action execution + Follow-Through Intelligence + Meeting Outcome Learning + Adaptive Meeting Planning + Plan-to-Action Handoff + Follow-Through Verification & Closure + Cross-Meeting Continuity + Meeting Closure & Recurring Continuity + manual Calendar meetings, Paid Appointments, Agent Work Control + Dependencies, Objective Verification + Adaptive Replanning, Objective Memory + Outcome Learning, Goals + Strategy + Adaptive Roadmaps + Forecast Review Learning, analytics, CRM and Studio schema without replacing existing user content.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Database</div>
      <h1>VP3 Database Upgrade</h1>
      <?php if ($complete): ?>
        <div class="vp3-alert success">The current VP3 schema is installed and ready.</div>
        <p class="vp3-auth-intro">Subscription packages, composable add-on entitlements, canonical opt-in plugins, social relationships, canonical human messaging, Browser Companion device authentication + Browser Share backend + private rich media + Live Rooms/Cloak Mode + Source Change Intelligence + Claims + Moderation + Search & Discovery, durable Agent history, Agent-scoped Brain memory, native Agent Scheduling, unified User Calendar, external calendar synchronization, round-robin and collective Team Scheduling, Appointment Lifecycle + Automation, Video Meetings + Meeting Intelligence + Meeting Agenda orchestration + Meeting Action execution + Follow-Through Intelligence + Meeting Outcome Learning + Adaptive Meeting Planning + Plan-to-Action Handoff + Follow-Through Verification & Closure + Cross-Meeting Continuity + Meeting Closure & Recurring Continuity + manual Calendar meetings, Commerce + Paid Scheduling, Agent Work Control + Dependencies, Objective Verification + Adaptive Replanning, Objective Memory + Outcome Learning, Goals + Strategy + Adaptive Roadmaps + Forecast Review Learning, canonical Agent runtime routing, Team invitation and membership lifecycle, workspace-owned Music resources, AI quota and route-attributed execution accounting, private Knowledge, Profile Agent, HomeServer Agent continuity, Agent Radar, CRM, voice identity, transcriptions and Music/Studio capabilities are available.</p>
        <a class="vp3-btn primary" href="<?= e(url('/admin/users.php')) ?>">Manage Users →</a>
      <?php else: ?>
        <p class="vp3-auth-intro">Run the current schema upgrade while preserving existing content and access. Existing accounts, package assignments, team memberships, token balances, music content, plugin preferences, Agent identities, Agent-scoped Brain memories, schedules, canonical bookings, lifecycle history, intake answers, automation deliveries, commerce provider connections, products, orders, payment/refund lineage, calendar events, calendar connections, Team scheduling pools, video meeting records/invitations/transcripts/intelligence notes/objectives/agendas/action execution history/follow-through monitors/aggregate outcome-learning patterns/follow-through planning metadata/plan-to-action handoff snapshots/intended-outcome verification closures/cross-meeting continuity lineage/immutable meeting closure snapshots/next-meeting handoffs/access settings, durable Agent work controls/dependencies, objective verification history, learned objective patterns, durable goals, goal-objective strategy links, advisory goal roadmaps/milestones, goal forecast review snapshots and conversations are preserved, including Team membership history, legacy Team direct messages and existing add-on grants.</p>
        <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <button class="vp3-btn primary" type="submit">Run Upgrade →</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>