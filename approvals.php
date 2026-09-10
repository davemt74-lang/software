<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$user = current_user();
if (!$user) redirect(url('/login.php'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f7f8">
<title><?= e(system_agent_name()) ?> | Approvals</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/approvals-v028.css?v=20260908')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-policy-v035.css?v=agent-policy-v035-20260909')) ?>">
</head>
<body class="approvals-page">
<div class="chat-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'approvals';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main approvals-main">
    <?php
      $memberHeaderUser = $user;
      $memberHeaderTitle = 'Approvals';
      $memberHeaderSubtitle = 'Review actions that require your permission';
      $memberHeaderActions = '<button class="approvals-button" type="button" id="approvalsRefresh">Refresh</button>';
      require __DIR__ . '/includes/member-header.php';
    ?>

    <section class="approvals-canvas" id="approvalsApp"
      data-endpoint="<?= e(url('/api/homeserver-approvals-v028.php')) ?>"
      data-csrf="<?= e(csrf_token()) ?>">
      <div class="approvals-inner">
        <section class="approvals-status-card" aria-live="polite">
          <div class="approvals-status-copy">
            <span class="approvals-eyebrow">HomeServer authority</span>
            <h1 id="approvalsStatusTitle">Checking approvals…</h1>
            <p id="approvalsStatusDetail">VP3 can review only action requests created by this VP3 pairing. HomeServer remains the execution authority.</p>
          </div>
          <span class="approvals-state" id="approvalsState">Checking</span>
        </section>

        <section class="approvals-policy-card agent-policy-panel" id="approvalsPolicy" aria-labelledby="approvalsPolicyTitle">
          <div class="agent-policy-heading">
            <div>
              <span class="approvals-eyebrow">Effective Agent policy</span>
              <h2 id="approvalsPolicyTitle">What VP3 can do through HomeServer</h2>
              <p>Policy is controlled locally by HomeServer. VP3 can display the effective rules and review its own approval requests, but it cannot silently loosen them.</p>
            </div>
            <span class="agent-policy-owner-badge">Managed on HomeServer</span>
          </div>
          <div class="agent-policy-summary" id="approvalsPolicySummary" aria-live="polite"><span>Checking effective policy…</span></div>
          <div class="agent-policy-list compact" id="approvalsPolicyList"><div class="agent-policy-empty">Effective tool policy will appear when HomeServer is connected.</div></div>
        </section>

        <section class="approvals-permission" id="approvalsPermission" hidden>
          <div>
            <span class="approvals-eyebrow">One-time permission upgrade</span>
            <h2>Enable HomeServer approval review</h2>
            <p>Approval federation was added after your original pairing. Request the additional <code>approvals.review</code> permission, then approve the code in your local HomeServer Control Center.</p>
          </div>
          <div class="approvals-permission-actions">
            <button class="approvals-button primary" type="button" id="approvalsUpgrade">Request permission</button>
            <div class="approvals-code-wrap" id="approvalsCodeWrap" hidden>
              <small>HomeServer approval code</small>
              <strong id="approvalsCode">—</strong>
              <button class="approvals-button primary" type="button" id="approvalsCheckPermission">Check approval</button>
            </div>
          </div>
        </section>

        <section class="approvals-toolbar" aria-label="Approval filters">
          <div class="approvals-tabs" role="group" aria-label="Approval status">
            <button class="approvals-tab active" type="button" data-approval-status="pending">Pending</button>
            <button class="approvals-tab" type="button" data-approval-status="executed">Approved</button>
            <button class="approvals-tab" type="button" data-approval-status="denied">Denied</button>
            <button class="approvals-tab" type="button" data-approval-status="failed">Failed</button>
          </div>
          <span class="approvals-count" id="approvalsCount">0 requests</span>
        </section>

        <section class="approvals-list" id="approvalsList" aria-live="polite">
          <div class="approvals-empty">Loading HomeServer approvals…</div>
        </section>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/approvals-v028.js?v=agent-policy-v035-20260909')) ?>" defer></script>
</body>
</html>
