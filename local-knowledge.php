<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!is_logged_in()) redirect(url('/login.php'));
$user = current_user();
if (!$user) redirect(url('/login.php'));
if (!personal_capability_has_v242('personal_knowledge.access', $user)) {
    http_response_code(403);
    exit('Personal Knowledge access is unavailable for this account.');
}
$canManage = personal_capability_has_v242('personal_knowledge.manage', $user);
$profileUrl = member_navigation_profile_url($user);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Local Knowledge | <?= e(system_agent_name()) ?></title>
  <link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
  <link rel="stylesheet" href="<?= e(url('/homeserver-knowledge-v062.css?v=20260912')) ?>">
</head>
<body>
<div class="chat-app local-knowledge-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='knowledge';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main local-knowledge-main">
    <?php
    $memberHeaderUser=$user;
    $memberHeaderTitle='Local Knowledge';
    $memberHeaderSubtitle='Private HomeServer folders and collections';
    $memberHeaderActionParts=[];
    $memberHeaderActionParts[]='<a href="'.e(url('/knowledge.php')).'">My Knowledge</a>';
    $memberHeaderActionParts[]='<a href="'.e(url('/settings-homeserver.php')).'">HomeServer Settings</a>';
    if($profileUrl!=='')$memberHeaderActionParts[]='<a href="'.e($profileUrl).'">View Profile</a>';
    $memberHeaderActions=implode('', $memberHeaderActionParts);
    require __DIR__.'/includes/member-header.php';
    ?>

    <section class="local-knowledge-canvas"
      data-local-knowledge
      data-api="<?= e(url('/api/homeserver-knowledge-v062.php')) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-can-manage="<?= $canManage ? '1' : '0' ?>">
      <div class="local-knowledge-wrap">
        <div class="local-knowledge-hero">
          <div>
            <small>HomeServer v0.62</small>
            <h1>Your files stay on your HomeServer.</h1>
            <p>VP3 can use approved Local Knowledge collections without receiving the native folder location. Choosing a folder always happens on the paired HomeServer machine.</p>
          </div>
          <div class="local-knowledge-state" id="lkState" data-state="loading">
            <span></span><strong id="lkStateLabel">Checking HomeServer…</strong>
          </div>
        </div>

        <div class="local-knowledge-alert" id="lkAlert" hidden></div>

        <section class="local-knowledge-privacy" aria-label="Privacy boundary">
          <div><strong>Local folder picker</strong><span>Runs on HomeServer</span></div>
          <div><strong>Native folder location</strong><span>Never sent to VP3 Cloud</span></div>
          <div><strong>Index</strong><span>Stored on HomeServer</span></div>
          <div><strong>Cloud duplicate</strong><span>Not created by this workspace</span></div>
        </section>

        <div class="local-knowledge-grid">
          <section class="local-knowledge-card local-knowledge-card-wide">
            <div class="local-knowledge-card-head">
              <div><small>Collections</small><h2>Available Knowledge</h2><p>Only collections permitted for the VP3 pairing are shown.</p></div>
              <button class="lk-button quiet" id="lkRefresh" type="button">Refresh</button>
            </div>
            <div class="local-knowledge-collections" id="lkCollections"><div class="lk-loading">Loading collections…</div></div>
          </section>

          <section class="local-knowledge-card">
            <div class="local-knowledge-card-head">
              <div><small>Mapped folders</small><h2>Local Sources</h2><p>Safe mapping labels and indexing status only.</p></div>
            </div>
            <div class="local-knowledge-mappings" id="lkMappings"><div class="lk-loading">Loading local sources…</div></div>
          </section>

          <section class="local-knowledge-card" id="lkMapCard">
            <div class="local-knowledge-card-head">
              <div><small>Add source</small><h2>Choose a HomeServer Folder</h2><p>The folder dialog opens locally on HomeServer. VP3 never receives the selected location.</p></div>
            </div>
            <?php if($canManage): ?>
            <form id="lkMapForm" class="lk-form">
              <label><span>Collection</span><select id="lkMapCollection" name="collection_key" required><option value="">Loading…</option></select></label>
              <label><span>Display label <em>optional</em></span><input name="label" maxlength="200" placeholder="Projects, Travel, Work notes…"></label>
              <label><span>Scan interval</span><select name="scan_interval_seconds"><option value="120">Every 2 minutes</option><option value="300">Every 5 minutes</option><option value="900">Every 15 minutes</option><option value="3600">Every hour</option></select></label>
              <label class="lk-check"><input type="checkbox" name="recursive" checked><span>Include subfolders</span></label>
              <label><span>Ignore patterns <em>optional, one per line</em></span><textarea name="excludes" rows="3" placeholder="node_modules&#10;.git&#10;*.tmp"></textarea></label>
              <button class="lk-button primary" type="submit">Choose folder on HomeServer</button>
              <small class="lk-form-note">Keep this page open while the HomeServer folder picker is active.</small>
            </form>
            <?php else: ?>
            <div class="lk-readonly">This account can view Local Knowledge but cannot change folder mappings.</div>
            <?php endif; ?>
          </section>

          <section class="local-knowledge-card local-knowledge-card-wide" id="lkWriteCard">
            <div class="local-knowledge-card-head">
              <div><small>Private write</small><h2>Save Text to HomeServer Knowledge</h2><p>VP3 forwards this text through the paired relay to the selected HomeServer collection. This workspace does not create a Cloud copy.</p></div>
            </div>
            <?php if($canManage): ?>
            <form id="lkWriteForm" class="lk-form lk-write-form">
              <label><span>Collection</span><select id="lkWriteCollection" name="collection_key" required><option value="">Loading…</option></select></label>
              <label><span>Title</span><input name="title" maxlength="240" required placeholder="What should your private Agent know?"></label>
              <label><span>Type</span><select name="kind"><option value="summary">Summary</option><option value="note">Note</option><option value="reference">Reference</option><option value="preference">Preference</option></select></label>
              <label class="lk-wide-field"><span>Knowledge text</span><textarea name="content" maxlength="250000" rows="7" required placeholder="Private notes, facts, context or reference material…"></textarea></label>
              <div class="lk-wide-field lk-form-actions"><button class="lk-button primary" type="submit">Save to HomeServer</button><span id="lkWriteResult"></span></div>
            </form>
            <?php else: ?>
            <div class="lk-readonly">This account can view Local Knowledge but cannot add private HomeServer items.</div>
            <?php endif; ?>
          </section>
        </div>

        <section class="local-knowledge-permission" id="lkPermission" hidden>
          <div><small>One-time permission</small><h2>Approve Knowledge write access on HomeServer</h2><p>Folder mapping, unmapping and private writes require <code>knowledge.write</code>. The approval happens locally and keeps your existing VP3 permissions intact.</p></div>
          <?php if($canManage): ?><div class="lk-permission-actions"><button class="lk-button primary" id="lkRequestPermission" type="button">Request approval</button><div id="lkApproval" hidden><span>HomeServer code</span><strong id="lkApprovalCode">—</strong><button class="lk-button" id="lkCheckPermission" type="button">Check approval</button></div></div><?php endif; ?>
        </section>

        <div class="local-knowledge-footnote"><strong>Privacy boundary:</strong> VP3 receives collection names, safe source labels, indexing counts and action results. It does not receive the selected native folder location or full indexed documents from the folder-mapping contract.</div>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
<script src="<?= e(url('/homeserver-knowledge-v062.js?v=20260912')) ?>"></script>
</body>
</html>
