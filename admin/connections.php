<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (!user_has_role('admin')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once dirname(__DIR__).'/includes/agent-connections-admin-v1.php';

$pdo = db();
$schemaReady = $pdo && agent_connections_schema_ready_v1($pdo);
$adminPath = url('/admin/connections.php');
$statusLabels = ['connected'=>'Connected', 'reauthorize'=>'Needs reconnect', 'disconnected'=>'Disconnected'];
$status = is_string($_GET['status'] ?? null) && isset($statusLabels[$_GET['status']]) ? $_GET['status'] : '';
$memberId = filter_var($_GET['member'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$filters = array_filter(['status'=>$status, 'member'=>$memberId]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $actor = current_user();
    $action = $_POST['action'] ?? '';
    $audit = ['provider'=>'gmail'];
    try {
        if (!verify_csrf()) {
            throw new DomainException('Session expired. Refresh and try again.');
        }
        if (!$schemaReady) {
            throw new DomainException('Run Database Upgrade before managing connections.');
        }
        switch ($action) {
            case 'save_gmail':
                $clientId = is_string($_POST['client_id'] ?? null) ? trim($_POST['client_id']) : '';
                $secret = is_string($_POST['client_secret'] ?? null) ? trim($_POST['client_secret']) : '';
                $callback = is_string($_POST['redirect_uri'] ?? null) ? trim($_POST['redirect_uri']) : '';
                agent_connections_admin_save_gmail_v1($pdo, $clientId, $secret, $callback);
                $notice = 'Gmail settings saved.';
                break;
            case 'clear_gmail':
                agent_connections_admin_save_gmail_v1($pdo, '', '', '', true);
                $notice = 'Saved OAuth credentials removed. Gmail uses server credentials if configured.';
                break;
            case 'enable_gmail':
                $oauthConfig = agent_connection_gmail_config_v1();
                if (empty($oauthConfig['client_id']) || empty($oauthConfig['client_secret']) || !function_exists('curl_init')) {
                    throw new DomainException('Gmail needs a readable OAuth client and PHP cURL before it can be enabled.');
                }
                try {
                    agent_connections_callback_v1();
                } catch (InvalidArgumentException|RuntimeException $e) {
                    throw new DomainException('Set a valid HTTPS OAuth callback URL before enabling Gmail.');
                }
                save_setting('connections_gmail_enabled', '1');
                $notice = 'Gmail enabled.';
                break;
            case 'pause_gmail':
                save_setting('connections_gmail_enabled', '0');
                $notice = 'Gmail paused for all VP3 accounts.';
                break;
            case 'disconnect_gmail':
                $connectionId = filter_var($_POST['connection_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                $revision = is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '';
                $owner = $pdo->prepare("SELECT owner_user_id,authorization_key FROM agent_connections_v1 WHERE id=? AND provider='gmail'");
                $owner->execute([$connectionId ?: 0]);
                $target = $owner->fetch();
                if (!$target || !hash_equals(hash('sha256', $target['authorization_key']), $revision)
                    || !agent_connections_disconnect_v1((int)$target['owner_user_id'], (int)$connectionId, 'gmail', $target['authorization_key'])) {
                    throw new DomainException('The selected connection has changed or is unavailable. Refresh and try again.');
                }
                $audit += ['connection_id'=>(int)$connectionId, 'target_user_id'=>(int)$target['owner_user_id']];
                $notice = 'Gmail account disconnected from VP3.';
                break;
            default:
                throw new DomainException('Choose a supported Connections action.');
        }
        agent_tool_log($actor, 'connections.admin.'.$action, '', 'success', $audit);
        flash('notice', $notice);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Admin Connections settings update failed ('.get_class($e).').');
        flash('error', 'The Connections change could not be saved. Check server readiness and try again.');
    }
    redirect($adminPath.($filters ? '?'.http_build_query($filters) : ''));
}

$oauthConfig = agent_connection_gmail_config_v1();
$source = $oauthConfig['source'] ?? 'none';
$clientId = (string)($oauthConfig['client_id'] ?? '');
$clientReference = str_replace('.apps.googleusercontent.com', '', $clientId);
$maskedClient = strlen($clientReference) > 10 ? substr($clientReference, 0, 4).'…'.substr($clientReference, -4) : ($clientReference === '' ? 'Not configured' : 'Configured');
$credentialsReady = $clientId !== '' && !empty($oauthConfig['client_secret']);
unset($oauthConfig);
$savedCallback = (string)setting('connections_gmail_redirect_uri', '');
$callback = '';
try {
    $callback = agent_connections_callback_v1();
} catch (InvalidArgumentException|RuntimeException $e) {
    // Configuration can be completed on this page even when the callback is unset.
}
$enabled = agent_connection_gmail_enabled_v1();
$providerStatus = match (true) {
    !$schemaReady => 'Database upgrade required',
    !$enabled => 'Paused',
    !$credentialsReady => 'OAuth credentials required',
    $callback === '' => 'HTTPS callback required',
    !function_exists('curl_init') => 'PHP cURL required',
    default => 'Available for user connections',
};

$counts = array_fill_keys(array_keys($statusLabels), 0);
$accounts = [];
$nextPage = '';
$inventoryUnavailable = false;
if ($schemaReady) {
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM agent_connections_v1 WHERE provider='gmail' GROUP BY status");
        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['total'];
            }
        }
        $sql = "SELECT id,owner_user_id,account_email,status,read_enabled,send_enabled,followups_enabled,updated_at,authorization_key
                FROM agent_connections_v1 WHERE provider='gmail'";
        $params = [];
        foreach (['status'=>$status, 'owner_user_id'=>$memberId, 'id'=>$before] as $column=>$value) {
            if ($value) {
                $sql .= ' AND '.$column.($column === 'id' ? '<?' : '=?');
                $params[] = $value;
            }
        }
        $stmt = $pdo->prepare($sql.' ORDER BY id DESC LIMIT 41');
        $stmt->execute($params);
        $accounts = $stmt->fetchAll();
        if (count($accounts) > 40) {
            array_pop($accounts);
            $nextPage = $adminPath.'?'.http_build_query($filters + ['before'=>(int)end($accounts)['id']]);
        }
    } catch (Throwable $e) {
        $inventoryUnavailable = true;
    }
}

$adminTitle = 'Connections';
$adminActive = 'connections';
require __DIR__.'/_header.php';
?>
<style>
.admin-connections { display:grid; gap:14px; }
.admin-connections .panel { margin:0; min-width:0; }
.admin-connections dl { display:grid; grid-template-columns:minmax(8rem, 1fr) minmax(0, 2fr); gap:9px 14px; }
.admin-connections dt { font-weight:700; }
.admin-connections dd { margin:0; overflow-wrap:anywhere; }
.admin-connections .connection-account { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; border-top:1px solid #e8ebee; padding:12px 0; }
.admin-connections .connection-account small { display:block; overflow-wrap:anywhere; }
.admin-connections .connection-account summary { cursor:pointer; }
.admin-connections .connection-account form { margin-top:8px; }
.admin-connections .connection-clear { margin-top:14px; }
@media(max-width:520px) { .admin-connections dl { grid-template-columns:1fr; gap:3px; } .admin-connections dd { margin-bottom:8px; } }
</style>
<div class="admin-connections">
  <?php if (!$schemaReady): ?><div class="notice" role="status">Run <a href="<?= e(url('/upgrade.php')) ?>">Database Upgrade</a> to activate Connections storage.</div><?php endif; ?>
  <section class="panel" aria-labelledby="connections-provider-title">
    <div class="admin-section-heading"><div>
      <h2 id="connections-provider-title">Gmail provider</h2>
      <p>Set up the OAuth application each VP3 user can authorize with their own Gmail account.</p>
    </div><span class="status"><?= e($providerStatus) ?></span></div>
    <dl>
      <dt>Credential source</dt><dd><?= e(match ($source) {'saved'=>'Saved in VP3', 'config'=>'Server configuration', default=>'None'}) ?></dd>
      <dt>OAuth client</dt><dd><?= e($maskedClient) ?></dd>
      <dt>Client secret</dt><dd><?= $credentialsReady ? 'Configured' : 'Missing or unreadable' ?></dd>
      <dt>Authorized redirect URI</dt><dd><?= $callback === '' ? 'Set an HTTPS callback below.' : '<code>'.e($callback).'</code>' ?></dd>
      <dt>Google permissions</dt><dd>Account identity, Gmail read, and Gmail send. Each user controls their Agent’s access in VP3.</dd>
    </dl>
    <p>Enable the Gmail API in Google Cloud. Create a web application OAuth client and add the exact redirect URI above to its authorized redirects.</p>
    <p>Use approved Google test users during setup. Public rollout with restricted Gmail read access requires Google OAuth verification and a security assessment when the server handles restricted data. <a href="https://developers.google.com/workspace/gmail/api/auth/scopes">Google’s Gmail scope requirements</a></p>
    <form method="post" action="<?= e($adminPath) ?>" class="admin-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_gmail">
      <div class="form-row">
        <label>Google OAuth client ID<input name="client_id" maxlength="255" spellcheck="false" autocapitalize="off" placeholder="Leave blank to keep the current client"></label>
        <label>Google OAuth client secret<input type="password" name="client_secret" maxlength="512" autocomplete="new-password" placeholder="Leave blank to keep the current secret"></label>
      </div>
      <label>HTTPS callback override<input type="url" name="redirect_uri" maxlength="500" value="<?= e($savedCallback) ?>" placeholder="Use the default server callback" spellcheck="false" autocapitalize="off"></label>
      <div class="actions"><button class="button primary" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Save Gmail settings</button></div>
    </form>
    <?php if ($source === 'saved' && $schemaReady): ?>
    <details class="connection-clear"><summary>Remove saved OAuth credentials</summary>
      <p>Disconnect existing Gmail accounts first. VP3 can then use the OAuth application configured on the server.</p>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="clear_gmail"><button class="button danger" type="submit">Remove saved credentials</button></form>
    </details>
    <?php endif; ?>
  </section>

  <section class="panel" aria-labelledby="connections-availability-title">
    <h2 id="connections-availability-title">Gmail availability</h2>
    <p>Pausing blocks new authorizations and Agent Gmail operations for every account. Existing connections can still be disconnected.</p>
    <div class="actions">
      <?php if ($enabled): ?>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pause_gmail"><button class="button" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Pause Gmail</button></form>
      <?php else: ?>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="enable_gmail"><button class="button primary" type="submit" <?= $schemaReady && $credentialsReady && $callback !== '' ? '' : 'disabled' ?>>Enable Gmail</button></form>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($schemaReady): ?>
  <section class="panel" aria-labelledby="connections-accounts-title">
    <h2 id="connections-accounts-title">Gmail accounts</h2>
    <?php if ($inventoryUnavailable): ?>
      <div class="notice error" role="status">The connection inventory is temporarily unavailable.</div>
    <?php else: ?>
      <div class="admin-kpi-grid">
        <?php foreach ($statusLabels as $key=>$label): ?><div class="admin-kpi"><span class="admin-kpi-head"><?= e($label) ?></span><strong><?= (int)$counts[$key] ?></strong></div><?php endforeach; ?>
      </div>
      <form method="get" action="<?= e($adminPath) ?>" class="admin-form">
        <div class="form-row">
          <label>VP3 member ID<input type="number" name="member" min="1" value="<?= $memberId ?: '' ?>"></label>
          <label>Connection status<select name="status"><option value="">All statuses</option><?php foreach ($statusLabels as $key=>$label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="actions"><button class="button" type="submit">Filter accounts</button><?php if ($filters || $before): ?><a class="button" href="<?= e($adminPath) ?>">Clear filters</a><?php endif; ?></div>
      </form>
      <?php if (!$accounts): ?><p>No matching Gmail accounts.</p><?php endif; ?>
      <?php foreach ($accounts as $account):
          $permissions = [];
          foreach (['read'=>'Read', 'send'=>'Reviewed send', 'followups'=>'Reply follow-ups'] as $key=>$label) {
              if (!empty($account[$key.'_enabled'])) $permissions[] = $label;
          }
          $ownerId = (int)$account['owner_user_id'];
      ?>
      <div class="connection-account">
        <div>
          <strong><?= e(agent_connections_admin_mask_mailbox_v1((string)$account['account_email'])) ?></strong>
          <small><a href="<?= e(url('/admin/users.php?edit='.$ownerId)) ?>">Member #<?= $ownerId ?></a> · Connection #<?= (int)$account['id'] ?> · <?= e($statusLabels[$account['status']] ?? 'Unavailable') ?></small>
          <small><?= e($permissions ? implode(' · ', $permissions) : 'No Agent permissions') ?> · Updated <?= e($account['updated_at']) ?></small>
        </div>
        <?php if ($account['status'] !== 'disconnected'): ?>
        <details><summary>Disconnect</summary>
          <p>Remove this VP3 account’s Gmail access.</p>
          <form method="post" action="<?= e($adminPath.($filters ? '?'.http_build_query($filters) : '')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="disconnect_gmail"><input type="hidden" name="connection_id" value="<?= (int)$account['id'] ?>">
            <input type="hidden" name="revision" value="<?= e(hash('sha256', $account['authorization_key'])) ?>">
            <button class="button danger" type="submit">Disconnect this account</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if ($nextPage !== ''): ?><a class="button" href="<?= e($nextPage) ?>">Next accounts</a><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__.'/_footer.php'; ?>
