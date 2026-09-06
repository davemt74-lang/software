<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/artist-listening.php';
require_once __DIR__ . '/includes/artist-listening-transcript.php';
require_once __DIR__ . '/includes/studio-participants.php';
require_once __DIR__ . '/includes/studio-voice-profile.php';
require_permission('users.manage');

function stonefellow_upgrade_complete(): bool
{
    return access_schema_ready()
        && password_reset_schema_ready()
        && chat_settings_schema_ready_v237()
        && permission_v105_playlist_permission_ready()
        && personal_capability_seeded_v242()
        && personal_capability_schema_ready_v242()
        && midi_v217_schema_ready()
        && (string)setting('midi_permissions_seed_v217','') === '1'
        && artist_listening_v172_schema_ready()
        && artist_listening_v237_schema_ready()
        && studio_participants_schema_ready()
        && studio_voice_profile_schema_ready()
        && user_agent_system_schema_ready_v236()
        && user_data_usage_schema_ready_v236()
        && shared_knowledge_index_schema_ready_v236()
        && profile_agent_schema_ready()
        && crm_v180_schema_ready()
        && artist_workspace_v181_schema_ready()
        && artist_media_v182_schema_ready()
        && artist_posts_v183_schema_ready()
        && artist_shows_v184_schema_ready()
        && artist_music_v185_schema_ready();
}

$error='';$complete=stonefellow_upgrade_complete();
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else try{
        ensure_access_schema();
        password_reset_ensure_schema();
        chat_settings_ensure_schema_v237();
        permission_v105_seed_playlist_permission();
        personal_capability_seed_v242();
        midi_v217_ensure_schema();
        artist_listening_v172_ensure_schema();artist_listening_v237_ensure_schema();studio_participants_ensure_schema();studio_voice_profile_ensure_schema();
        user_agent_system_ensure_schema_v236();user_data_usage_ensure_schema_v236();shared_knowledge_index_ensure_schema_v236();profile_agent_ensure_schema();
        personal_capability_ensure_schema_v242();
        crm_v180_ensure_schema();artist_workspace_v181_ensure_schema();artist_media_v182_ensure_schema();artist_posts_v183_ensure_schema();artist_shows_v184_ensure_schema();artist_music_v185_ensure_schema();
        $complete=stonefellow_upgrade_complete();
        if($complete){flash('notice','VP3 database upgrade complete: password recovery, Agent Brain, Knowledge, Profile Agent, Voice Profile, transcriptions, shared knowledge and Studio features are ready.');redirect(url('/admin/users.php'));}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$pageTitle='VP3 | Upgrade';$pageDescription='Upgrade the VP3 database and application features.';$activePage='';require __DIR__.'/includes/header.php';
?>
<main><section class="section" style="padding-top:130px;min-height:70vh"><div class="wrap" style="max-width:720px"><div class="contact-card"><p class="section-kicker">Database</p><h2 style="margin-top:0">VP3 Database Upgrade</h2>
<?php if($complete): ?><p style="color:#5f6f84;line-height:1.7">The current schema is installed, including password recovery, role-configurable personal Agent Brain, private per-user Knowledge, Profile Agent/Profile Chat, voice identity, transcriptions and shared/system knowledge.</p><a class="btn primary" href="<?= e(url('/admin/users.php')) ?>">Manage Users</a>
<?php else: ?><p style="color:#6b788a;line-height:1.7">This upgrade adds the current VP3 account and assistant capabilities while preserving existing content and permissions.</p><?php if($error): ?><p style="color:#a74840"><?= e($error) ?></p><?php endif; ?><form method="post"><?= csrf_field() ?><button class="form-submit" type="submit">Run Upgrade</button></form><?php endif; ?>
</div></div></section></main><?php require __DIR__.'/includes/footer.php'; ?>