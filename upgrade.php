<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
require_once __DIR__ . '/includes/artist-listening.php';
require_once __DIR__ . '/includes/artist-listening-transcript.php';
require_once __DIR__ . '/includes/studio-participants.php';
require_once __DIR__ . '/includes/studio-voice-profile.php';
require_once __DIR__ . '/includes/onboarding-intelligence.php';
require_permission('users.manage');

function vp3_upgrade_complete(): bool
{
    return access_schema_ready()
        && subscription_schema_ready()
        && ai_usage_accounting_v032_schema_ready()
        && vp3_radar_schema_ready()
        && vp3_agent_referral_schema_ready()
        && subscription_self_service_schema_ready()
        && billing_schema_ready()
        && token_pack_schema_ready()
        && password_reset_schema_ready()
        && chat_settings_schema_ready_v237()
        && permission_v105_playlist_permission_ready()
        && personal_capability_seeded_v242()
        && personal_capability_schema_ready_v242()
        && vp3_plugin_schema_ready_v320()
        && vp3_social_schema_ready_v320()
        && midi_v217_schema_ready()
        && (string)setting('midi_permissions_seed_v217','') === '1'
        && artist_listening_v172_schema_ready()
        && artist_listening_v237_schema_ready()
        && studio_participants_schema_ready()
        && studio_voice_profile_schema_ready()
        && user_agent_system_schema_ready_v236()
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
            ai_usage_accounting_v032_ensure_schema();
            vp3_radar_ensure_schema();
            vp3_agent_referral_ensure_schema();
            subscription_self_service_ensure_schema();
            billing_ensure_schema();
            token_pack_ensure_schema();
            password_reset_ensure_schema();
            chat_settings_ensure_schema_v237();
            permission_v105_seed_playlist_permission();
            personal_capability_seed_v242();
            vp3_plugin_ensure_schema_v320();
            vp3_social_ensure_schema_v320();
            midi_v217_ensure_schema();
            artist_listening_v172_ensure_schema();
            artist_listening_v237_ensure_schema();
            studio_participants_ensure_schema();
            studio_voice_profile_ensure_schema();
            user_agent_system_ensure_schema_v236();

            // Cloud-site schema upgrades are canonical here. Any new cloud
            // migration must be represented in this upgrade path and in the
            // completion check above so one Run Upgrade action installs the
            // full current VP3 cloud schema.
            $pdo = db();
            if (!$pdo) {
                throw new RuntimeException('Database connection is unavailable.');
            }
            homeserver_vp3_ensure_schema($pdo);
            if (!homeserver_agent_v018_ensure_schema($pdo)) {
                throw new RuntimeException('HomeServer Agent chat schema could not be installed.');
            }
            agent_compute_v020_ensure_schema($pdo);
            agent_compute_v023_ensure_schema($pdo);

            onboarding_intelligence_ensure_schema();
            user_data_usage_ensure_schema_v236();
            shared_knowledge_index_ensure_schema_v236();
            profile_agent_ensure_schema();
            personal_capability_ensure_schema_v242();
            crm_v180_ensure_schema();
            artist_workspace_v181_ensure_schema();
            music_workspace_release_schema_v330_ensure($pdo);
            music_workspace_resources_v330_ensure_schema($pdo);
            artist_media_v182_ensure_schema();
            artist_posts_v183_ensure_schema();
            artist_shows_v184_ensure_schema();
            artist_music_v185_ensure_schema();
            $complete = vp3_upgrade_complete();

            if ($complete) {
                flash('notice', 'VP3 database upgrade complete: subscriptions, plugins, social relationships, human messaging, Team collaboration, workspace-owned Music resources, AI execution accounting, Agent Brain, Knowledge, Profile Agent, HomeServer Agent continuity, Agent Radar, CRM, transcriptions, and Music/Studio capabilities are ready.');
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
      <p>The upgrade process adds the current subscription, plugin, social, human messaging, billing, AI, HomeServer, collaboration, analytics, CRM and Studio schema without replacing existing user content.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Database</div>
      <h1>VP3 Database Upgrade</h1>
      <?php if ($complete): ?>
        <div class="vp3-alert success">The current VP3 schema is installed and ready.</div>
        <p class="vp3-auth-intro">Subscription packages, opt-in plugins, social relationships, human messaging, Team workspaces, workspace-owned Music resources, AI quota and execution accounting, Agent Brain, private Knowledge, Profile Agent, HomeServer Agent continuity, Agent Radar, CRM, voice identity, transcriptions and Music/Studio capabilities are available.</p>
        <a class="vp3-btn primary" href="<?= e(url('/admin/users.php')) ?>">Manage Users →</a>
      <?php else: ?>
        <p class="vp3-auth-intro">Run the current schema upgrade while preserving existing content and access. Existing accounts, package assignments, team memberships, token balances, music content and onboarding progress are preserved.</p>
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