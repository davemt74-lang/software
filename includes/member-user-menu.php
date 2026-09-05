<?php
declare(strict_types=1);

$memberMenuUser = $memberMenuUser ?? current_user();
if (!$memberMenuUser) return;

$memberMenuLinks = member_navigation_menu_links($memberMenuUser);
$memberMenuRoleSummary = implode(' · ', user_role_labels($memberMenuUser));
?>
<div class="chat-top-menu member-profile-menu" id="chatProfileMenu">
  <button
    type="button"
    class="chat-top-avatar"
    id="chatProfileButton"
    aria-label="User menu"
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
    class="chat-top-dropdown chat-profile-dropdown"
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
      <div>
        <strong><?= e((string)($memberMenuUser['display_name'] ?? '')) ?></strong>
        <?php if ($memberMenuRoleSummary !== ''): ?><small><?= e($memberMenuRoleSummary) ?></small><?php endif; ?>
      </div>
    </div>

    <nav class="chat-profile-links" aria-label="User menu">
      <?php foreach ($memberMenuLinks as $memberMenuLink): ?>
        <a<?= !empty($memberMenuLink['danger']) ? ' class="logout"' : '' ?> href="<?= e((string)$memberMenuLink['url']) ?>">
          <span><?= e((string)$memberMenuLink['label']) ?></span><span>↗</span>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
