<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) redirect(url('/login.php'));
if (!profile_agent_schema_ready($pdo)) redirect(url('/upgrade.php'));

$contacts = function_exists('profile_visitor_contact_list_v243')
    ? profile_visitor_contact_list_v243($pdo, (int)$user['id'], 250)
    : [];

$totalContacts = count($contacts);
$repeatContacts = 0;
$engagedContacts = 0;
$memberContacts = 0;
$activeContacts = 0;
$totalConversations = 0;
$now = time();
foreach ($contacts as $contact) {
    if (!empty($contact['repeat_visitor'])) $repeatContacts++;
    if ((int)($contact['conversation_count'] ?? 0) > 0) $engagedContacts++;
    if (!empty($contact['signed_in'])) $memberContacts++;
    $lastSeen = strtotime((string)($contact['last_seen_at'] ?? ''));
    if ($lastSeen !== false && $lastSeen >= $now - 300) $activeContacts++;
    $totalConversations += (int)($contact['conversation_count'] ?? 0);
}

function contacts_date_label(string $value): string
{
    if ($value === '') return '—';
    $ts = strtotime($value);
    if ($ts === false) return '—';
    $delta = time() - $ts;
    if ($delta < 60) return 'Just now';
    if ($delta < 3600) return max(1, (int)floor($delta / 60)) . 'm ago';
    if ($delta < 86400) return max(1, (int)floor($delta / 3600)) . 'h ago';
    if ($delta < 604800) return max(1, (int)floor($delta / 86400)) . 'd ago';
    return date('M j, Y', $ts);
}

function contacts_stage_label(string $stage): string
{
    return match ($stage) {
        'member_engaged' => 'Member engaged',
        'guest_engaged' => 'Guest engaged',
        'returning_visitor' => 'Returning',
        default => 'New visitor',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f7f8">
<title><?= e(system_agent_name()) ?> | My Contacts</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/contacts.css?v=profile-contact-crm-20260905')) ?>">
</head>
<body class="contacts-page">
<div class="chat-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'contacts';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main contacts-main">
    <header class="contacts-topbar">
      <div class="contacts-topbar-left">
        <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button>
        <div class="contacts-topbar-title"><strong>My Contacts</strong><span>Visitors + conversations + relationships</span></div>
      </div>
      <div class="contacts-topbar-actions">
        <a class="contacts-button" href="<?= e(url('/profile-agent.php')) ?>">Profile Agent</a>
        <span class="contacts-avatar" aria-label="<?= e((string)$user['display_name']) ?>">
          <?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><?= e(user_initials($user)) ?><?php endif; ?>
        </span>
      </div>
    </header>

    <section class="contacts-canvas">
      <div class="contacts-inner">
        <section class="contacts-hero">
          <div>
            <span class="contacts-eyebrow">Personal relationship CRM</span>
            <h1>My Contacts</h1>
            <p>A living list of people and guest browsers interacting with your public profile. Repeat visits and Profile Agent conversations stay attached to the same owner-scoped contact whenever the same browser returns.</p>
          </div>
          <div class="contacts-hero-actions">
            <a class="contacts-button" href="<?= e(url('/profile-agent.php?tab=visitors')) ?>">Visitor activity</a>
            <a class="contacts-button primary" href="<?= e(url('/chat.php')) ?>">Open Agent Chat</a>
          </div>
        </section>

        <section class="contacts-metrics" aria-label="Contact metrics">
          <article class="contacts-metric"><span>Total contacts</span><strong><?= $totalContacts ?></strong><small>Guest browsers + known members</small></article>
          <article class="contacts-metric"><span>Active now</span><strong><?= $activeContacts ?></strong><small>Seen in the last 5 minutes</small></article>
          <article class="contacts-metric"><span>Returning</span><strong><?= $repeatContacts ?></strong><small>Two or more 30-minute visit sessions</small></article>
          <article class="contacts-metric"><span>Engaged</span><strong><?= $engagedContacts ?></strong><small>At least one Profile Agent chat</small></article>
          <article class="contacts-metric"><span>Known members</span><strong><?= $memberContacts ?></strong><small>Signed-in Stonefellow accounts</small></article>
          <article class="contacts-metric"><span>Conversations</span><strong><?= $totalConversations ?></strong><small>Profile Agent conversations</small></article>
        </section>

        <section class="contacts-toolbar" aria-label="Contact filters">
          <label class="contacts-search"><span aria-hidden="true">⌕</span><input id="contactsSearch" type="search" placeholder="Search contacts, stages, relationships…" autocomplete="off"></label>
          <div class="contacts-filters" role="group" aria-label="Filter contacts">
            <button class="contacts-filter active" type="button" data-contact-filter="all">All</button>
            <button class="contacts-filter" type="button" data-contact-filter="new_visitor">New</button>
            <button class="contacts-filter" type="button" data-contact-filter="returning_visitor">Returning</button>
            <button class="contacts-filter" type="button" data-contact-filter="engaged">Engaged</button>
            <button class="contacts-filter" type="button" data-contact-filter="member">Members</button>
          </div>
        </section>

        <section class="contacts-board" aria-label="Contacts">
          <div class="contacts-board-head" aria-hidden="true">
            <span>Contact</span><span>Stage</span><span>Visits</span><span>Chats</span><span>Messages</span><span>First seen</span><span>Last activity</span>
          </div>
          <div id="contactsRows">
            <?php foreach ($contacts as $contact):
              $contactRef = trim((string)($contact['contact_ref'] ?? ''));
              $label = trim((string)($contact['visitor_label'] ?? ''));
              if ($label === '') $label = $contactRef !== '' ? 'Guest ' . substr($contactRef, 2) : 'Guest visitor';
              $stage = (string)($contact['stage'] ?? 'new_visitor');
              $relationship = trim((string)($contact['relationship_scope'] ?? 'none'));
              $searchText = strtolower(trim($label . ' ' . $contactRef . ' ' . $stage . ' ' . $relationship));
              $lastActivity = (string)($contact['conversation_last_at'] ?? '');
              $lastSeen = (string)($contact['last_seen_at'] ?? '');
              if ($lastActivity === '' || (strtotime($lastSeen) !== false && strtotime($lastActivity) < strtotime($lastSeen))) $lastActivity = $lastSeen;
            ?>
            <article class="contacts-row" data-contact-row data-stage="<?= e($stage) ?>" data-member="<?= !empty($contact['signed_in']) ? '1' : '0' ?>" data-search="<?= e($searchText) ?>">
              <div class="contacts-person">
                <span class="contacts-person-avatar">
                  <?php if (!empty($contact['avatar_url'])): ?><img src="<?= e((string)$contact['avatar_url']) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($label,0,1))) ?><?php endif; ?>
                </span>
                <div class="contacts-person-copy">
                  <strong><?= e($label) ?></strong>
                  <small><?= e($contactRef !== '' ? $contactRef : (!empty($contact['signed_in']) ? 'Signed-in member' : 'Guest')) ?><?php if ($relationship !== '' && $relationship !== 'none'): ?> · <?= e(str_replace('_',' ',$relationship)) ?><?php endif; ?></small>
                </div>
              </div>
              <div class="contacts-cell"><span class="contacts-stage <?= e($stage) ?>"><?= e(contacts_stage_label($stage)) ?></span></div>
              <div class="contacts-cell"><strong><?= (int)($contact['visit_count'] ?? 0) ?></strong><small><?= (int)($contact['page_view_count'] ?? 0) ?> page views</small></div>
              <div class="contacts-cell"><strong><?= (int)($contact['conversation_count'] ?? 0) ?></strong></div>
              <div class="contacts-cell"><strong><?= (int)($contact['visitor_message_count'] ?? 0) ?></strong></div>
              <div class="contacts-cell"><?= e(contacts_date_label((string)($contact['first_seen_at'] ?? ''))) ?></div>
              <div class="contacts-cell"><?= e(contacts_date_label($lastActivity)) ?></div>
            </article>
            <?php endforeach; ?>
          </div>
          <div class="contacts-empty<?= $contacts ? ' contacts-hidden' : '' ?>" id="contactsEmpty">
            <strong><?= $contacts ? 'No contacts match this filter.' : 'No contacts yet.' ?></strong>
            <span><?= $contacts ? 'Try a different search or contact stage.' : 'Profile visits and Profile Agent conversations will begin building this list automatically.' ?></span>
          </div>
        </section>

        <section class="contacts-privacy">
          <span aria-hidden="true">◉</span>
          <div><strong>Privacy-first guest continuity</strong><p>Anonymous contacts use a random first-party browser identifier. Stonefellow stores only an owner-scoped hash and does not use IP address or browser fingerprinting to identify guests. If that browser later signs in, its existing profile interaction history can become associated with the signed-in member without exposing the anonymous token.</p></div>
        </section>
      </div>
    </section>
  </main>
</div>
<script>
(() => {
  'use strict';
  const rows=[...document.querySelectorAll('[data-contact-row]')];
  const search=document.getElementById('contactsSearch');
  const filters=[...document.querySelectorAll('[data-contact-filter]')];
  const empty=document.getElementById('contactsEmpty');
  let active='all';
  function apply(){
    const q=String(search?.value||'').trim().toLowerCase();
    let shown=0;
    for(const row of rows){
      const stage=String(row.dataset.stage||'');
      const member=row.dataset.member==='1';
      const matchesFilter=active==='all'||active===stage||(active==='engaged'&&['guest_engaged','member_engaged'].includes(stage))||(active==='member'&&member);
      const matchesSearch=!q||String(row.dataset.search||'').includes(q);
      row.classList.toggle('contacts-hidden',!(matchesFilter&&matchesSearch));
      if(matchesFilter&&matchesSearch)shown++;
    }
    empty?.classList.toggle('contacts-hidden',shown>0);
  }
  search?.addEventListener('input',apply);
  for(const filter of filters)filter.addEventListener('click',()=>{
    active=String(filter.dataset.contactFilter||'all');
    for(const button of filters)button.classList.toggle('active',button===filter);
    apply();
  });
  const sidebar=document.getElementById('chatSidebar');
  const backdrop=document.getElementById('chatSidebarBackdrop');
  const open=document.getElementById('openChatSidebar');
  const close=document.getElementById('closeChatSidebar');
  const setSidebar=value=>{document.body.classList.toggle('chat-sidebar-open',value);sidebar?.classList.toggle('open',value);backdrop?.classList.toggle('open',value);};
  open?.addEventListener('click',()=>setSidebar(true));
  close?.addEventListener('click',()=>setSidebar(false));
  backdrop?.addEventListener('click',()=>setSidebar(false));
})();
</script>
</body>
</html>
