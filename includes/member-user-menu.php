<?php
declare(strict_types=1);

$memberMenuUser = $memberMenuUser ?? current_user();
if (!$memberMenuUser) return;

$memberMenuRoleSummary = implode(' · ', user_role_labels($memberMenuUser));
?>
<div
  class="chat-top-menu member-profile-menu"
  id="chatProfileMenu"
  data-agent-voice-menu-config
  data-user-id="<?= (int)($memberMenuUser['id'] ?? 0) ?>"
  data-agent-endpoint="<?= e(url('/api/user-agent-system-v236.php')) ?>"
  data-voice-endpoint="<?= e(url('/api/studio-voice-profile.php')) ?>"
  data-chat-settings-endpoint="<?= e(url('/api/chat-settings-v237.php')) ?>"
  data-voice-profile-url="<?= e(url('/voice-profile.php')) ?>"
  data-profile-agent-url="<?= e(url('/profile-agent.php')) ?>"
  data-account-agents-url="<?= e(url('/account.php#agents-data')) ?>"
  data-csrf="<?= e(csrf_token()) ?>"
>
  <button
    type="button"
    class="chat-top-avatar"
    id="chatProfileButton"
    aria-label="Agent and voice controls"
    aria-expanded="false"
    aria-controls="chatProfileDropdown"
  >
    <?php if (user_avatar_url($memberMenuUser) !== ''): ?>
      <img src="<?= e(user_avatar_url($memberMenuUser)) ?>" alt="">
    <?php else: ?>
      <?= e(user_initials($memberMenuUser)) ?>
    <?php endif; ?>
  </button>

  <div
    class="chat-top-dropdown chat-profile-dropdown vp3-agent-voice-menu"
    id="chatProfileDropdown"
    hidden
  >
    <div class="chat-profile-summary">
      <span class="chat-avatar">
        <?php if (user_avatar_url($memberMenuUser) !== ''): ?>
          <img src="<?= e(user_avatar_url($memberMenuUser)) ?>" alt="">
        <?php else: ?>
          <span><?= e(user_initials($memberMenuUser)) ?></span>
        <?php endif; ?>
      </span>
      <div class="chat-profile-summary-copy">
        <strong><?= e((string)($memberMenuUser['display_name'] ?? '')) ?></strong>
        <?php if ($memberMenuRoleSummary !== ''): ?><small><?= e($memberMenuRoleSummary) ?></small><?php endif; ?>
      </div>
    </div>

    <section class="vp3-agent-voice-dashboard" data-vp3-agent-voice-dashboard aria-label="Agent and voice controls">
      <header class="vp3-agent-voice-head">
        <div><small>Agent + Voice</small><strong>Your personal agent</strong></div>
        <span class="vp3-agent-voice-provider" data-vp3-voice-provider>ElevenLabs</span>
      </header>
      <div class="vp3-agent-voice-status" data-vp3-agent-voice-status role="status" aria-live="polite">Loading agent and voice settings…</div>
      <div class="vp3-agent-voice-body" data-vp3-agent-voice-body hidden>
        <label class="vp3-agent-voice-field" data-vp3-agent-selector-wrap hidden><span>Agent</span><select data-vp3-agent-selector aria-label="Choose agent"></select></label>
        <label class="vp3-agent-voice-field" data-vp3-agent-name-wrap><span>Agent name</span><div class="vp3-agent-name-row"><input type="text" maxlength="190" data-vp3-agent-name autocomplete="off" aria-label="Agent name"><button type="button" data-vp3-save-agent-name>Save</button></div></label>
        <label class="vp3-agent-voice-field"><span>Voice source</span><select data-vp3-agent-voice-source aria-label="Agent voice source"><option value="default">VP3 default voice</option><option value="clone">My ElevenLabs voice clone</option></select></label>
        <div class="vp3-agent-voice-clone-card"><div><small>Voice clone</small><strong data-vp3-clone-state>Checking ElevenLabs…</strong><span data-vp3-clone-detail>Your existing Voice Profile remains the source of truth.</span></div><a data-vp3-manage-voice href="<?= e(url('/voice-profile.php')) ?>">Manage clone</a></div>
        <label class="vp3-agent-voice-toggle-row"><span><strong>Agent Voice</strong><small>Speak proactive and Profile Agent responses aloud.</small></span><input type="checkbox" data-vp3-global-agent-voice aria-label="Agent Voice"><i aria-hidden="true"></i></label>
        <div class="vp3-agent-voice-actions"><a href="<?= e(url('/profile-agent.php')) ?>">Open My Agent</a><a href="<?= e(url('/account.php#agents-data')) ?>">Agent settings</a></div>
      </div>
    </section>
  </div>
</div>
