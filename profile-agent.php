<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) redirect(url('/login.php'));
if (!personal_capability_has_v242('profile_agent.access', $user)) {
    http_response_code(403);
    exit('Profile Agent access is unavailable for this account.');
}
if (!profile_agent_schema_ready($pdo) || !user_agent_system_schema_ready_v236($pdo) || !personal_capability_schema_ready_v242($pdo)) {
    redirect(url('/upgrade.php'));
}

personal_profile_migrate_legacy_artist_v242($pdo, $user);
$profile = profile_for_user($pdo, (int)$user['id'], true) ?: [];
if (empty($profile['username'])) $profile = profile_migrate_artist_identity($pdo, $user);
$profileUrl = !empty($profile['username']) ? profile_public_url((string)$profile['username']) : '';
$profileChatAllowed = personal_capability_has_v242('profile_chat.access', $user);
$personalKnowledgeAllowed = personal_capability_has_v242('personal_knowledge.access', $user);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f8fa">
<title>Profile Agent | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/profile-agent-portal.css?v=profile-owner-v242-20260905')) ?>">
</head>
<body>
<div class="chat-app profile-agent-app">
  <aside class="chat-sidebar profile-agent-sidebar" id="chatSidebar">
    <div class="profile-agent-sidebar-head">
      <a class="profile-agent-sidebar-brand" href="<?= e(url('/')) ?>"><?= e(system_agent_name()) ?></a>
      <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close Profile Agent menu">×</button>
    </div>
    <div class="profile-agent-sidebar-service">
      <span>Profile Agent</span>
      <div class="profile-agent-service-status" id="profileAgentServiceStatus" aria-live="polite">Checking service…</div>
    </div>
    <nav class="profile-agent-sidebar-nav" aria-label="Profile Agent sections">
      <button type="button" data-pa-tab="inbox" class="active"><span>01</span><strong>Inbox</strong></button>
      <button type="button" data-pa-tab="visitors"><span>02</span><strong>Visitors</strong></button>
      <button type="button" data-pa-tab="agent"><span>03</span><strong>Agent</strong></button>
      <button type="button" data-pa-tab="knowledge"><span>04</span><strong>Knowledge Access</strong></button>
      <button type="button" data-pa-tab="profile"><span>05</span><strong>Profile Settings</strong></button>
      <button type="button" data-pa-tab="analytics"><span>06</span><strong>Analytics</strong></button>
    </nav>
    <div class="profile-agent-sidebar-footer">
      <?php if ($profileUrl !== ''): ?><a href="<?= e($profileUrl) ?>" target="_blank" rel="noopener">View Profile ↗</a><?php endif; ?>
      <?php if ($personalKnowledgeAllowed): ?><a href="<?= e(url('/knowledge.php')) ?>">My Knowledge</a><?php endif; ?>
      <a href="<?= e(url('/account.php')) ?>">My Account</a>
      <?php if (has_permission('chat.access', $user)): ?><a href="<?= e(url('/chat.php')) ?>">Main Feed</a><?php endif; ?>
    </div>
  </aside>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main profile-agent-main">
    <?php
      $memberHeaderUser = $user;
      $memberHeaderTitle = 'Profile Agent';
      $memberHeaderSubtitle = 'Your profile, visitor conversations and customer service agent';
      $memberHeaderClass = 'profile-agent-topbar';
      $memberHeaderActions = $profileUrl !== ''
          ? '<a class="profile-agent-view-profile" href="' . e($profileUrl) . '" target="_blank" rel="noopener">View Profile ↗</a>'
          : '';
      require __DIR__ . '/includes/member-header.php';
    ?>

    <section class="profile-agent-portal" id="profileAgentPortal">
      <div class="profile-agent-metrics" id="profileAgentMetrics" aria-label="Profile Agent metrics"></div>
      <div class="profile-agent-notice" id="profileAgentNotice" role="status" aria-live="polite"></div>

      <section class="profile-agent-view active" data-pa-view="inbox">
        <div class="profile-agent-inbox-layout">
          <div class="profile-agent-inbox-list">
            <div class="profile-agent-section-head">
              <div><span>Inbox</span><h2>Visitor conversations</h2></div>
              <button type="button" id="profileAgentRefresh">Refresh</button>
            </div>
            <div id="profileAgentAttention"></div>
            <div id="profileAgentConversations"></div>
          </div>
          <aside class="profile-agent-thread" id="profileAgentThread">
            <div class="profile-agent-thread-empty">
              <strong>Select a conversation</strong>
              <span>Open a visitor thread to review messages and reply as the profile owner.</span>
            </div>
          </aside>
        </div>
      </section>

      <section class="profile-agent-view" data-pa-view="visitors">
        <div class="profile-agent-panel">
          <div class="profile-agent-section-head"><div><span>Visitors</span><h2>Recent profile activity</h2></div></div>
          <div class="profile-agent-visitor-list" id="profileAgentVisitors"></div>
        </div>
      </section>

      <section class="profile-agent-view" data-pa-view="agent">
        <div class="profile-agent-panel" id="profileAgentSettings"></div>
      </section>

      <section class="profile-agent-view" data-pa-view="knowledge">
        <div class="profile-agent-panel" id="profileAgentKnowledge"></div>
      </section>

      <section class="profile-agent-view" data-pa-view="profile">
        <div class="profile-agent-panel" id="profileAgentProfileSettings"></div>
        <div class="profile-agent-panel" style="margin-top:16px">
          <form class="profile-agent-card" id="paPersonalProfileDetails">
            <h2>Profile details</h2>
            <p>The artist/profile fields that previously lived only under Admin → Profile now belong to your personal profile. They are isolated to your account.</p>
            <div class="profile-agent-panel-grid">
              <label class="profile-agent-field"><span>Tagline</span><input name="tagline" maxlength="255" value="<?= e((string)($profile['tagline'] ?? '')) ?>"></label>
              <label class="profile-agent-field"><span>Genre</span><input name="genre" maxlength="190" value="<?= e((string)($profile['genre'] ?? '')) ?>"></label>
              <label class="profile-agent-field"><span>Focus</span><input name="focus" maxlength="255" value="<?= e((string)($profile['focus'] ?? '')) ?>"></label>
              <label class="profile-agent-field"><span>Contact email</span><input type="email" name="contact_email" maxlength="190" value="<?= e((string)($profile['contact_email'] ?? '')) ?>"></label>
            </div>
            <label class="profile-agent-field"><span>Bio subhead</span><textarea name="bio_subhead" maxlength="500"><?= e((string)($profile['bio_subhead'] ?? '')) ?></textarea></label>
            <label class="profile-agent-field"><span>Full profile bio</span><textarea name="artist_bio" maxlength="12000" style="min-height:180px"><?= e((string)($profile['artist_bio'] ?? '')) ?></textarea></label>
            <label class="profile-agent-field"><span>Player description</span><textarea name="player_description" maxlength="500"><?= e((string)($profile['player_description'] ?? '')) ?></textarea></label>
            <div class="profile-agent-panel-grid">
              <label class="profile-agent-field"><span>TIDAL</span><input type="url" name="tidal_url" maxlength="500" value="<?= e((string)($profile['tidal_url'] ?? '')) ?>"></label>
              <label class="profile-agent-field"><span>Facebook</span><input type="url" name="facebook_url" maxlength="500" value="<?= e((string)($profile['facebook_url'] ?? '')) ?>"></label>
            </div>
            <div class="profile-agent-form-actions">
              <button class="primary" type="submit">Save Profile Details</button>
              <a class="profile-agent-view-profile" href="<?= e($profileUrl !== '' ? $profileUrl . '?preview=1' : '#') ?>" target="_blank" rel="noopener">Preview profile ↗</a>
            </div>
          </form>
        </div>
      </section>

      <section class="profile-agent-view" data-pa-view="analytics">
        <div class="profile-agent-panel" id="profileAgentAnalytics"></div>
      </section>
    </section>
  </main>
</div>
<script>window.PROFILE_AGENT_PORTAL=<?= json_encode([
  'endpoint'=>url('/api/profile-agent.php'),
  'csrf'=>csrf_token(),
  'profileUrl'=>$profileUrl,
  'profileChatAllowed'=>$profileChatAllowed,
  'initialTab'=>(string)($_GET['tab'] ?? ''),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
<script src="<?= e(url('/profile-agent-portal.js?v=profile-owner-v242-20260905')) ?>"></script>
<script src="<?= e(url('/profile-personal-settings-v242.js?v=profile-owner-v242-20260905')) ?>"></script>
</body>
</html>
