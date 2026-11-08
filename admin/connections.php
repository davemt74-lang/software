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
require_once dirname(__DIR__).'/includes/agent-connection-youtube-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-health-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-quota-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-consent-v1.php';

$pdo = db();
$schemaReady = $pdo && agent_connections_schema_ready_v1($pdo);
$healthReady = $pdo && agent_connection_health_schema_ready_v1($pdo);
$adminPath = url('/admin/connections.php');
$statusLabels = ['connected'=>'Connected', 'reauthorize'=>'Needs reconnect', 'disconnected'=>'Disconnected'];
$status = is_string($_GET['status'] ?? null) && isset($statusLabels[$_GET['status']]) ? $_GET['status'] : '';
$memberId = filter_var($_GET['member'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$filters = array_filter(['status'=>$status, 'member'=>$memberId]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $actor = current_user();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $audit = ['provider'=>str_ends_with($action, '_youtube') ? 'youtube' : 'gmail'];
    $auditStatus = 'success';
    $flashType = 'notice';
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
            case 'save_youtube':
                $apiKey = is_string($_POST['api_key'] ?? null) ? trim($_POST['api_key']) : '';
                if (!agent_connection_youtube_valid_api_key_v1($apiKey)) {
                    throw new DomainException('Enter a valid YouTube Data API key.');
                }
                save_setting('connections_youtube_api_key', ai_encrypt_secret($apiKey));
                $notice = 'YouTube API key saved.';
                break;
            case 'clear_youtube':
                $encryptedKey = (string)setting('connections_youtube_api_key', '');
                $revision = is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '';
                if ($encryptedKey === '' || !hash_equals(hash('sha256', $encryptedKey), $revision)) {
                    throw new DomainException('The saved YouTube key has changed. Refresh and try again.');
                }
                $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key=? AND BINARY setting_value=?');
                $delete->execute(['connections_youtube_api_key', $encryptedKey]);
                if ($delete->rowCount() !== 1) {
                    throw new DomainException('The saved YouTube key has changed. Refresh and try again.');
                }
                $notice = 'Saved YouTube API key removed.';
                break;
            case 'enable_youtube':
                $youtubeConfig = agent_connection_youtube_config_v1();
                if ($youtubeConfig['api_key'] === '' || !function_exists('curl_init')) {
                    throw new DomainException('YouTube needs a readable API key and PHP cURL before it can be enabled.');
                }
                save_setting('connections_youtube_enabled', '1');
                $notice = 'YouTube enabled.';
                break;
            case 'pause_youtube':
                save_setting('connections_youtube_enabled', '0');
                $notice = 'YouTube search paused for all VP3 accounts.';
                break;
            case 'test_connection':
                if (!$healthReady) {
                    throw new DomainException('Run Database Upgrade before testing connections.');
                }
                $provider = $_POST['provider'] ?? null;
                if (!in_array($provider, ['gmail', 'youtube'], true)) {
                    throw new DomainException('Choose a supported connection to test.');
                }
                $test = agent_connection_health_test_platform_v1($pdo, $provider);
                $notice = $test['message'];
                $audit = ['provider'=>$provider, 'health_status'=>$test['status']];
                if (!in_array($test['status'], ['healthy', 'setup_ready'], true)) {
                    $auditStatus = 'error';
                    $flashType = 'error';
                }
                break;
            default:
                throw new DomainException('Choose a supported Connections action.');
        }
        if (in_array($action, ['save_gmail', 'clear_gmail', 'enable_gmail', 'pause_gmail', 'save_youtube', 'clear_youtube', 'enable_youtube', 'pause_youtube'], true)) {
            agent_connection_health_clear_platform_v1($pdo, $audit['provider']);
        }
        agent_tool_log($actor, 'connections.admin.'.$action, '', $auditStatus, $audit);
        flash($flashType, $notice);
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

$youtubeConfig = agent_connection_youtube_config_v1();
$youtubeSource = $youtubeConfig['source'];
$youtubeKeyReady = $youtubeConfig['api_key'] !== '';
unset($youtubeConfig);
$youtubeEnabled = agent_connection_youtube_enabled_v1();
$youtubeUsage = null;
$youtubeEnabledMembers = null;
if ($schemaReady) {
    try {
        $youtubeUsage = agent_connection_youtube_usage_v1($pdo);
        if (agent_connection_youtube_consent_ready_v1($pdo)) {
            $members = $pdo->prepare('SELECT COUNT(*) FROM agent_connection_youtube_consent_v1 WHERE policy_version=?');
            $members->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
            $youtubeEnabledMembers = (int)$members->fetchColumn();
        }
    } catch (PDOException) {
        // Gmail settings remain accessible if YouTube usage storage is unavailable.
    }
}
$youtubeStatus = match (true) {
    $youtubeUsage === null || $youtubeEnabledMembers === null => 'Database upgrade required',
    !$youtubeEnabled => 'Paused',
    !$youtubeKeyReady => 'API key required',
    !function_exists('curl_init') => 'PHP cURL required',
    default => 'Available for Chat',
};
$youtubeSavedKey = $youtubeSource === 'saved' ? (string)setting('connections_youtube_api_key', '') : '';
$youtubeKeyRevision = $youtubeSavedKey !== '' ? hash('sha256', $youtubeSavedKey) : '';
unset($youtubeSavedKey);

$healthLabels = [
    'healthy'=>'Healthy',
    'setup_ready'=>'Setup ready',
    'needs_attention'=>'Needs attention',
    'unchecked'=>'Untested',
    'unavailable'=>'Unavailable',
];
$gmailHealth = $pdo ? agent_connection_health_platform_v1($pdo, 'gmail') : null;
$youtubeHealth = $pdo ? agent_connection_health_platform_v1($pdo, 'youtube') : null;
$gmailGrantHealth = $schemaReady ? agent_connection_health_gmail_counts_v1($pdo) : null;

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
  <?php if (!$schemaReady || !$healthReady || $youtubeUsage === null || $youtubeEnabledMembers === null): ?><div class="notice" role="status">Run <a href="<?= e(url('/upgrade.php')) ?>">Database Upgrade</a> to finish installing Connections storage.</div><?php endif; ?>
  <nav class="actions" aria-label="Connection providers">
    <a class="button" href="#connections-provider-title">Gmail</a>
    <a class="button" href="#youtube-provider-title">YouTube</a>
  </nav>
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
      <?php if ($gmailHealth): ?>
      <dt>Latest setup test</dt>
      <dd><strong><?= e($healthLabels[$gmailHealth['status']] ?? 'Unavailable') ?></strong> · <?= e($gmailHealth['message']) ?>
        <?php if ($gmailHealth['checked_at']): ?><small> (<?= e($gmailHealth['checked_at']) ?> UTC)</small><?php endif; ?>
      </dd>
      <?php endif; ?>
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
    <form method="post" action="<?= e($adminPath) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><input type="hidden" name="provider" value="gmail">
      <button class="button" type="submit" <?= $schemaReady && $healthReady ? '' : 'disabled' ?>>Test Gmail setup</button>
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

  <section class="panel" aria-labelledby="youtube-provider-title">
    <div class="admin-section-heading"><div>
      <h2 id="youtube-provider-title">YouTube provider</h2>
      <p>Set up public YouTube search for VP3 Chat.</p>
    </div><span class="status"><?= e($youtubeStatus) ?></span></div>
    <dl>
      <dt>Credential source</dt><dd><?= e(match ($youtubeSource) {'saved'=>'Saved in VP3', 'config'=>'Server configuration', default=>'None'}) ?></dd>
      <dt>API key</dt><dd><?= $youtubeKeyReady ? 'Configured' : 'Missing or unreadable' ?></dd>
      <?php if ($youtubeHealth): ?>
      <dt>Latest connection test</dt>
      <dd><strong><?= e($healthLabels[$youtubeHealth['status']] ?? 'Unavailable') ?></strong> · <?= e($youtubeHealth['message']) ?>
        <?php if ($youtubeHealth['checked_at']): ?><small> (<?= e($youtubeHealth['checked_at']) ?> UTC)</small><?php endif; ?>
      </dd>
      <?php endif; ?>
    </dl>
    <p>In Google Cloud, enable <a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com">YouTube Data API v3</a>, then create a server API key in <a href="https://console.cloud.google.com/apis/credentials">Credentials</a>. Restrict the key to YouTube Data API v3.</p>
    <form method="post" action="<?= e($adminPath) ?>" class="admin-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_youtube">
      <label>YouTube Data API key<input type="password" name="api_key" maxlength="128" autocomplete="new-password" required></label>
      <div class="actions"><button class="button primary" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Save YouTube API key</button></div>
    </form>
    <div class="actions">
      <form method="post" action="<?= e($adminPath) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><input type="hidden" name="provider" value="youtube">
        <button class="button" type="submit" <?= $schemaReady && $healthReady ? '' : 'disabled' ?>>Test YouTube connection</button>
      </form>
      <?php if ($youtubeEnabled): ?>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pause_youtube"><button class="button" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Pause YouTube</button></form>
      <?php else: ?>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="enable_youtube"><button class="button primary" type="submit" <?= $schemaReady && $youtubeKeyReady && function_exists('curl_init') ? '' : 'disabled' ?>>Enable YouTube</button></form>
      <?php endif; ?>
    </div>
    <?php if ($youtubeKeyRevision !== '' && $schemaReady): ?>
    <details class="connection-clear"><summary>Remove saved API key</summary>
      <p>VP3 will use the server-configured YouTube key if one is available.</p>
      <form method="post" action="<?= e($adminPath) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="clear_youtube"><input type="hidden" name="revision" value="<?= e($youtubeKeyRevision) ?>"><button class="button danger" type="submit">Remove saved YouTube API key</button></form>
    </details>
    <?php endif; ?>
  </section>

  <section class="panel" aria-labelledby="youtube-usage-title">
    <h2 id="youtube-usage-title">YouTube usage</h2>
    <?php if ($youtubeUsage === null): ?>
      <p>Search usage is unavailable.</p>
    <?php else: ?>
      <div class="admin-kpi-grid">
        <?php if ($youtubeEnabledMembers !== null): ?><div class="admin-kpi"><span class="admin-kpi-head">Members with YouTube enabled</span><strong><?= $youtubeEnabledMembers ?></strong></div><?php endif; ?>
        <?php foreach (['searches_today'=>'Successful searches today', 'users_today'=>'Users today', 'searches_30d'=>'Successful searches in 30 days', 'users_30d'=>'Users in 30 days'] as $key=>$label): ?>
        <div class="admin-kpi"><span class="admin-kpi-head"><?= e($label) ?></span><strong><?= (int)$youtubeUsage[$key] ?></strong></div>
        <?php endforeach; ?>
      </div>
      <p>VP3 search quota today · <?= (int)$youtubeUsage['attempts_today'] ?> of <?= (int)$youtubeUsage['daily_limit'] ?> requests · <?= (int)$youtubeUsage['member_limit'] ?> per member per day</p>
      <p>Usage dates follow Pacific Time.</p>
    <?php endif; ?>
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
      <?php if (!empty($gmailGrantHealth['available'])): ?>
      <p>Connected account health · <?= (int)$gmailGrantHealth['healthy'] ?> healthy · <?= (int)$gmailGrantHealth['needs_attention'] ?> need attention · <?= (int)$gmailGrantHealth['unchecked'] ?> untested</p>
      <?php endif; ?>
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
