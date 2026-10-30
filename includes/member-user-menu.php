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
  data-tts-endpoint="<?= e(url('/api/agent-voice-v117.php')) ?>"
  data-chat-settings-endpoint="<?= e(url('/api/chat-settings-v237.php')) ?>"
  data-voice-profile-url="<?= e(url('/voice-profile.php')) ?>"
  data-profile-agent-url="<?= e(url('/profile-agent.php')) ?>"
  data-account-agents-url="<?= e(url('/account.php#agents-data')) ?>"
  data-phone-endpoint="<?= e(url('/api/phone-cloud-v100.php')) ?>"
  data-phone-register-url="<?= e(url('/phone.php')) ?>"
  data-pricing-url="<?= e(url('/pricing.php')) ?>"
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
      <?php /* data-vp3-global-agent-voice: one canonical upper toggle */ ?>
      <?= member_agent_voice_toggle_html($memberMenuUser) ?>
    </div>

    <section class="vp3-agent-voice-dashboard" data-vp3-agent-voice-dashboard aria-label="Agent and voice controls">
      <header class="vp3-agent-voice-head">
        <div><small>Agent + Voice</small><strong>Your personal agent</strong></div>
        <span class="vp3-agent-voice-provider" data-vp3-voice-provider>ElevenLabs</span>
      </header>
      <div class="vp3-agent-voice-status" data-vp3-agent-voice-status role="status" aria-live="polite">Loading agent and voice settings…</div>
      <div class="vp3-agent-voice-body" data-vp3-agent-voice-body hidden>
        <label class="vp3-agent-voice-field" data-vp3-agent-selector-wrap hidden><span>Default agent</span><select data-vp3-agent-selector aria-label="Choose agent"></select></label>
        <label class="vp3-agent-voice-field" data-vp3-agent-name-wrap><span>Agent name</span><div class="vp3-agent-name-row"><input type="text" maxlength="190" data-vp3-agent-name autocomplete="off" aria-label="Agent name"><button type="button" data-vp3-save-agent-name>Save</button></div></label>
        <label class="vp3-agent-voice-field"><span>Voice source</span><select data-vp3-agent-voice-source aria-label="Agent voice source"><option value="default">VP3 default voice</option><option value="clone">My ElevenLabs voice clone</option></select></label>
        <label class="vp3-agent-voice-field"><span>ElevenLabs system voice</span><select data-vp3-system-voice aria-label="ElevenLabs system voice"><option value="">VP3 configured default</option></select><small data-vp3-system-voice-status role="status"></small></label>
        <div class="vp3-agent-voice-clone-card"><div><small>Voice clone</small><strong data-vp3-clone-state>Checking ElevenLabs…</strong><span data-vp3-clone-detail>Your existing Voice Profile remains the source of truth.</span></div><a data-vp3-manage-voice href="<?= e(url('/voice-profile.php')) ?>">Manage clone</a></div>
        <div class="vp3-agent-phone-card" data-vp3-agent-phone-card><div><small>Agent phone number</small><strong data-vp3-agent-phone-label>Checking phone package…</strong><span data-vp3-agent-phone-detail>Calls and texts are managed with your agent.</span></div><a data-vp3-agent-phone-link href="<?= e(url('/phone.php')) ?>">Register number</a></div>
        <div class="vp3-agent-voice-actions"><a href="<?= e(url('/profile-agent.php')) ?>">Open My Agent</a><a href="<?= e(url('/account.php#agents-data')) ?>">Agent settings</a></div>
      </div>
    </section>
  </div>
</div>
