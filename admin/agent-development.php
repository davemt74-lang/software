<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/agent-development-v1.php';
require_login();

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$actor = current_user();
if (!$actor || !vp3_dev_is_admin_v1($actor)) {
    http_response_code(403);
    exit('Admin account required.');
}

$page = url('/admin/agent-development.php');
$pdo = db();
$owner = (int)$actor['id'];
$ready = $pdo instanceof PDO && vp3_dev_schema_ready_v1($pdo);
$modes = [
    'off' => 'Off',
    'outline' => 'Outline and propose',
    'code_stage' => 'Full code & stage for launch',
];
$statuses = [
    'outlining' => 'Outlining',
    'proposed' => 'Proposal ready',
    'queued' => 'Queued for the coding worker',
    'building' => 'Building and testing',
    'staged' => 'Staged for review',
    'approved' => 'Approved for launch',
    'cancelled' => 'Cancelled',
    'failed' => 'Needs attention',
];
$inProgress = ['outlining', 'queued', 'building'];
$cancelable = [...$inProgress, 'staged'];
$error = '';
$request = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '';
    $epoch = filter_var($_POST['epoch'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf()) {
            throw new DomainException('Session expired. Refresh and try again.');
        }
        if (!$ready) {
            throw new DomainException('Run Database Upgrade to use Agent Development.');
        }

        switch ($action) {
            case 'set_mode':
                $mode = $_POST['mode'] ?? null;
                if (!is_string($mode) || !isset($modes[$mode]) || $epoch === false) {
                    throw new DomainException('Choose a valid automation level.');
                }
                vp3_dev_set_mode_v1($pdo, $actor, $mode, $epoch);
                flash('notice', 'Automation level saved.');
                redirect($page);
            case 'create':
                $request = vp3_dev_text_v1($_POST['request'] ?? null, 1500);
                if ($epoch === false) {
                    throw new DomainException('Refresh the automation level and try again.');
                }
                $created = vp3_dev_create_v1($pdo, $actor, $request, $epoch);
                redirect($page . '?revision=' . rawurlencode((string)$created['id']));
            case 'approve':
                $head = is_string($_POST['head_sha'] ?? null) ? $_POST['head_sha'] : '';
                $artifact = is_string($_POST['artifact_sha'] ?? null) ? $_POST['artifact_sha'] : '';
                vp3_dev_approve_v1($pdo, $actor, $id, $head, $artifact);
                flash('notice', 'The exact staged candidate is approved for launch.');
                redirect($page . '?revision=' . rawurlencode($id));
            case 'cancel':
                vp3_dev_cancel_v1($pdo, $actor, $id);
                flash('notice', 'Revision cancelled.');
                redirect($page . '?revision=' . rawurlencode($id));
            default:
                throw new DomainException('Choose an available development action.');
        }
    } catch (DomainException | InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Agent Development request failed: ' . get_class($e));
        $error = 'The request could not be completed. Please try again.';
    }
}

$control = $ready ? vp3_dev_control_v1($pdo, $owner) : ['mode'=>'off', 'epoch'=>0];
$mode = $control['mode'];
$revisions = $ready ? vp3_dev_list_v1($pdo, $owner) : [];
$selectedId = is_string($_GET['revision'] ?? null) ? $_GET['revision'] : '';
$selected = $ready && $selectedId !== '' ? vp3_dev_revision_v1($pdo, $owner, $selectedId) : null;
$stageCurrent = $selected && vp3_dev_stage_current_v1($selected);
$runnerReady = vp3_dev_runner_ready_v1();

$adminTitle = 'Agent Development';
$adminActive = 'agent-development';
require __DIR__ . '/_header.php';
?>

<style>
.dev-admin{display:grid;gap:16px}
.dev-admin .admin-section-heading{margin-bottom:0!important}
.dev-admin h3{font-size:1rem}
.dev-admin p{line-height:1.55}
.dev-admin .muted{margin:0}
.dev-admin-controls,.dev-admin-revisions{display:grid;gap:16px;align-items:start}
.dev-admin-controls{grid-template-columns:minmax(0,1fr) minmax(0,1.25fr)}
.dev-admin-revisions{grid-template-columns:minmax(0,1fr) minmax(0,1.6fr)}
.dev-admin-form{display:grid;gap:12px}
.dev-admin-form .button{justify-self:start}
.dev-admin-form textarea{min-height:100px}
.dev-admin-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px}
.dev-admin-list{list-style:none;padding:0;margin:0;display:grid;gap:8px}
.dev-admin-list a{display:grid;gap:6px;color:inherit;text-decoration:none;border:1px solid var(--admin-line);border-radius:7px;padding:10px;overflow-wrap:anywhere}
.dev-admin-list a:hover,.dev-admin-list a[aria-current="page"]{background:#f3f5f8;border-color:#b7c2ce}
.dev-admin-details{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:7px 14px}
.dev-admin-details dt{font-weight:700}
.dev-admin-details dd{margin:0;overflow-wrap:anywhere}
.dev-admin-copy{white-space:pre-wrap;overflow-wrap:anywhere}
.dev-admin ul:not(.dev-admin-list),.dev-admin ol{padding-left:20px}
.dev-admin li{overflow-wrap:anywhere}
@media(max-width:840px){.dev-admin-controls,.dev-admin-revisions{grid-template-columns:1fr}}
@media(max-width:480px){.dev-admin-details{grid-template-columns:1fr;gap:3px}.dev-admin-details dd{margin-bottom:7px}}
</style>

<div class="dev-admin">
  <section class="admin-section-heading">
    <div><span class="eyebrow">AI &amp; Platform</span><h2>Agent Development</h2>
      <p>Choose what the coding Agent can do, inspect revisions, and review a tested candidate in staging.</p></div>
    <a class="button" href="<?= e(url('/chat.php')) ?>">Open Agent Chat</a>
  </section>

  <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$ready): ?>
    <div class="notice">Run <a href="<?= e(url('/upgrade.php')) ?>">Database Upgrade</a> to enable Agent Development.</div>
  <?php else: ?>
    <div class="dev-admin-controls">
      <section class="admin-card" aria-labelledby="dev-level-heading">
        <h3 id="dev-level-heading">Automation level</h3>
        <form class="dev-admin-form" method="post" action="<?= e($page) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_mode">
          <input type="hidden" name="epoch" value="<?= (int)$control['epoch'] ?>">
          <div class="field"><label for="dev-mode">Level</label>
            <select id="dev-mode" name="mode">
              <?php foreach ($modes as $value => $label): ?><option value="<?= e($value) ?>"<?= $mode === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
          <button class="button primary" type="submit">Save level</button>
        </form>
        <ul>
          <li><strong>Off</strong> stops new work and revokes active development leases.</li>
          <li><strong>Outline and propose</strong> prepares a plan and checks for review.</li>
          <li><strong>Full code &amp; stage for launch</strong> sends work to the isolated coding worker. Review the verified preview and approve the exact candidate before release.</li>
        </ul>
        <p class="muted"><?= $runnerReady ? 'Coding worker settings are configured.' : 'Full code mode requires a configured coding worker and private preview origin.' ?></p>
        <details>
          <summary>Worker setup</summary>
          <p>Set <code>VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN</code> to a secret of at least 43 URL-safe characters. Use the same token on the isolated worker.</p>
          <p>Set <code>VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN</code> to the worker’s separate, private HTTPS staging origin. VP3 pins code work to the commit in <code>VP3_RELEASE.json</code> or the exact SHA configured in <code>VP3_AGENT_DEVELOPMENT_BASE_SHA</code>.</p>
          <p>On shared hosting, the private site configuration can supply these values. <a href="https://github.com/davemt74-lang/software/blob/main/docs/agent-development-runner-v1.md" target="_blank" rel="noopener noreferrer">Open the worker setup guide</a>.</p>
        </details>
      </section>

      <section class="admin-card" aria-labelledby="dev-request-heading">
        <h3 id="dev-request-heading">New revision</h3>
        <?php if ($mode === 'off'): ?>
          <p class="muted">Choose an active automation level to start a revision.</p>
        <?php elseif ($mode === 'code_stage' && !$runnerReady): ?>
          <p class="muted">Configure the coding worker and preview origin to queue code work. Outline and propose is available with the site’s configured model.</p>
        <?php else: ?>
          <p>You can also start a revision in your private Agent Chat with <code>/develop</code> followed by your request.</p>
          <form class="dev-admin-form" method="post" action="<?= e($page) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="epoch" value="<?= (int)$control['epoch'] ?>">
            <div class="field"><label for="dev-request">Development request</label>
              <textarea id="dev-request" name="request" maxlength="1500" required aria-describedby="dev-request-help"><?= e($request) ?></textarea></div>
            <p class="muted" id="dev-request-help">Describe the behavior you expect and how to reproduce the issue. Use test examples with synthetic data.</p>
            <button class="button primary" type="submit"><?= $mode === 'outline' ? 'Create proposal' : 'Queue code and stage' ?></button>
          </form>
        <?php endif; ?>
      </section>
    </div>

    <div class="dev-admin-revisions">
      <section class="admin-card" aria-labelledby="dev-history-heading">
        <h3 id="dev-history-heading">Revisions</h3>
        <?php if (!$revisions): ?><p class="muted">Revisions appear here after you submit a development request.</p><?php endif; ?>
        <ul class="dev-admin-list">
          <?php foreach ($revisions as $revision):
              $revisionId = (string)$revision['id'];
              $revisionHref = $page . '?revision=' . rawurlencode($revisionId);
          ?>
            <li><a href="<?= e($revisionHref) ?>"<?= $selected && $selectedId === $revisionId ? ' aria-current="page"' : '' ?>>
              <strong><?= e(mb_strimwidth($revision['request'], 0, 92, '…', 'UTF-8')) ?></strong>
              <small><?= e($statuses[$revision['status']] ?? 'Status unavailable') ?> · <?= e($modes[$revision['mode']] ?? '') ?></small>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="admin-card" aria-labelledby="dev-review-heading">
        <h3 id="dev-review-heading">Revision review</h3>
        <?php if (!$selected): ?>
          <p class="muted"><?= $selectedId === '' ? 'Select a revision to see the plan, checks, and staging details.' : 'The selected revision is unavailable.' ?></p>
        <?php else:
            $status = $selected['status'];
            $revisionHref = $page . '?revision=' . rawurlencode((string)$selected['id']);
        ?>
          <dl class="dev-admin-details">
            <dt>Status</dt><dd><?= e($statuses[$status] ?? 'Status unavailable') ?></dd>
            <dt>Level</dt><dd><?= e($modes[$selected['mode']] ?? '') ?></dd>
            <dt>Revision</dt><dd><code><?= e($selected['id']) ?></code></dd>
            <?php if (!empty($selected['base_sha'])): ?><dt>Base commit</dt><dd><code><?= e($selected['base_sha']) ?></code></dd><?php endif; ?>
            <?php if (!empty($selected['updated_at'])): ?><dt>Updated</dt><dd><?= e($selected['updated_at']) ?></dd><?php endif; ?>
            <?php if (!empty($selected['head_sha'])): ?><dt>Candidate</dt><dd><code><?= e($selected['head_sha']) ?></code></dd><?php endif; ?>
            <?php if (!empty($selected['artifact_sha'])): ?><dt>Artifact SHA-256</dt><dd><code><?= e($selected['artifact_sha']) ?></code></dd><?php endif; ?>
            <?php if ($stageCurrent && !empty($selected['stage_expires_at'])): ?><dt>Preview expires</dt><dd><?= e(gmdate('Y-m-d H:i T', (int)$selected['stage_expires_at'])) ?></dd><?php endif; ?>
          </dl>
          <h4>Request</h4><p class="dev-admin-copy"><?= e($selected['request']) ?></p>
          <?php if (!empty($selected['summary'])): ?><h4>Summary</h4><p class="dev-admin-copy"><?= e($selected['summary']) ?></p><?php endif; ?>
          <?php if (!empty($selected['plan'])): ?><h4>Plan</h4><ol><?php foreach ($selected['plan'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ol><?php endif; ?>
          <?php if (!empty($selected['checks'])): ?><h4><?= $selected['mode'] === 'outline' ? 'Proposed checks' : 'Verification checks' ?></h4><ul><?php foreach ($selected['checks'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if (in_array($status, ['staged', 'approved'], true) && !$stageCurrent): ?><p class="muted">This candidate’s verified preview is unavailable or expired.</p><?php endif; ?>

          <div class="dev-admin-actions">
            <?php if ($stageCurrent): ?><a class="button primary" href="<?= e($selected['preview_url']) ?>" target="_blank" rel="noopener noreferrer">Open verified preview</a><?php endif; ?>
            <?php if ($stageCurrent && $status === 'staged' && $mode === 'code_stage' && (int)$selected['epoch'] === (int)$control['epoch']): ?>
              <form method="post" action="<?= e($revisionHref) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="revision" value="<?= e($selected['id']) ?>">
                <input type="hidden" name="head_sha" value="<?= e($selected['head_sha']) ?>">
                <input type="hidden" name="artifact_sha" value="<?= e($selected['artifact_sha']) ?>">
                <button class="button" type="submit">Approve exact candidate for launch</button>
              </form>
            <?php endif; ?>
            <?php if (in_array($status, $inProgress, true)): ?>
              <a class="button" href="<?= e($revisionHref) ?>">Refresh revision</a>
            <?php endif; ?>
            <?php if (in_array($status, $cancelable, true)): ?>
              <form method="post" action="<?= e($revisionHref) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="revision" value="<?= e($selected['id']) ?>">
                <button class="button danger" type="submit">Cancel revision</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
