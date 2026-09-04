<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('ai.manage');

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

seed_permission_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/ai.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');

    try {
        if ($action === 'save') {
            $systemAgentName = trim(preg_replace('/\s+/u', ' ', (string)($_POST['system_agent_name'] ?? '')) ?? '');
            if ($systemAgentName === '') {
                throw new RuntimeException('Enter a system agent name.');
            }
            if (mb_strlen($systemAgentName) > 80) {
                throw new RuntimeException('System agent name must be 80 characters or fewer.');
            }

            $activeProvider = trim((string)($_POST['active_provider'] ?? 'local'));
            if (!ai_valid_provider($activeProvider)) {
                throw new RuntimeException('Select a valid active chat provider.');
            }

            $openaiModel = trim((string)($_POST['openai_model'] ?? 'gpt-5.6-luna'));
            $anthropicModel = trim((string)($_POST['anthropic_model'] ?? 'claude-haiku-4-5'));

            if (!ai_valid_model('openai', $openaiModel)) {
                throw new RuntimeException('Select a valid OpenAI model.');
            }
            if (!ai_valid_model('anthropic', $anthropicModel)) {
                throw new RuntimeException('Select a valid Claude model.');
            }

            save_setting('system_agent_name', $systemAgentName);
            save_setting('ai_active_provider', $activeProvider);
            save_setting('ai_openai_enabled', isset($_POST['openai_enabled']) ? '1' : '0');
            save_setting('ai_openai_model', $openaiModel);
            save_setting('ai_anthropic_enabled', isset($_POST['anthropic_enabled']) ? '1' : '0');
            save_setting('ai_anthropic_model', $anthropicModel);

            $openaiKey = trim((string)($_POST['openai_api_key'] ?? ''));
            $anthropicKey = trim((string)($_POST['anthropic_api_key'] ?? ''));

            if (!empty($_POST['remove_openai_key'])) {
                save_setting('ai_openai_api_key', '');
            } elseif ($openaiKey !== '') {
                save_setting('ai_openai_api_key', ai_encrypt_secret($openaiKey));
            }

            if (!empty($_POST['remove_anthropic_key'])) {
                save_setting('ai_anthropic_api_key', '');
            } elseif ($anthropicKey !== '') {
                save_setting('ai_anthropic_api_key', ai_encrypt_secret($anthropicKey));
            }

            flash('notice', 'AI and system agent settings saved.');
            redirect(url('/admin/ai.php'));
        }

        if ($action === 'test') {
            $provider = trim((string)($_POST['provider'] ?? ''));
            $result = ai_test_provider($provider);

            if ($result['ok'] ?? false) {
                flash(
                    'notice',
                    ($provider === 'openai' ? 'OpenAI' : 'Claude') .
                    ' connected successfully using ' .
                    (string)($result['model'] ?? ai_provider_model($provider)) .
                    '.'
                );
            } else {
                flash(
                    'error',
                    ($provider === 'openai' ? 'OpenAI' : 'Claude') .
                    ' test failed: ' .
                    (string)($result['error'] ?? 'Unknown provider error.')
                );
            }

            redirect(url('/admin/ai.php'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(url('/admin/ai.php'));
    }
}

$catalog = ai_model_catalog();
$activeProvider = ai_active_provider();

$adminTitle = 'AI / API Settings';
$adminActive = 'ai';
require __DIR__ . '/_header.php';
?>
<div class="ai-admin-page">
<div class="panel ai-overview-panel">
  <div class="ai-settings-heading">
    <div>
      <span class="status">Stonefellow Chat</span>
      <h2>System Agent & AI Provider</h2>
      <p class="muted">Set the universal system agent identity and choose which model generates Agent Chat responses. Database and knowledge-base permissions are filtered before context is sent to the provider.</p>
    </div>

    <div class="ai-provider-status">
      <span>Active</span>
      <strong><?= e(ai_provider_labels()[$activeProvider] ?? 'Local Retrieval Only') ?></strong>
    </div>
  </div>

  <form class="grid-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="field full">
      <label for="system_agent_name">System Agent Name</label>
      <input id="system_agent_name" name="system_agent_name" maxlength="80" required value="<?= e(system_agent_name()) ?>">
      <small>This is the universal AI name shown to users who have not created a named personal agent. User-owned agents are powered by this system but keep their own names.</small>
    </div>

    <div class="field full">
      <label for="active_provider">Active Chat Provider</label>
      <select id="active_provider" name="active_provider">
        <?php foreach (ai_provider_labels() as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $activeProvider === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small>If the selected provider is disabled, unavailable, or returns an error, Agent Chat safely falls back to Stonefellow's local database/knowledge retrieval response.</small>
    </div>

    <section class="ai-provider-card field full">
      <div class="ai-provider-card-head">
        <div>
          <span class="ai-provider-mark">O</span>
          <div>
            <h3>OpenAI</h3>
            <p>Responses API</p>
          </div>
        </div>

        <label class="ai-toggle">
          <input type="checkbox" name="openai_enabled" value="1" <?= ai_provider_enabled('openai') ? 'checked' : '' ?>>
          <span></span>
          <strong>Enabled</strong>
        </label>
      </div>

      <div class="ai-provider-fields">
        <div class="field">
          <label for="openai_model">Model</label>
          <select id="openai_model" name="openai_model">
            <?php foreach ($catalog['openai'] as $model => $label): ?>
              <option value="<?= e($model) ?>" <?= ai_provider_model('openai') === $model ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="openai_api_key">OpenAI API Key</label>
          <input id="openai_api_key" name="openai_api_key" type="password" autocomplete="new-password" placeholder="<?= ai_provider_has_saved_key('openai') ? 'Saved key ••••••' . e(ai_key_suffix('openai')) : 'sk-…' ?>">
          <small><?= ai_provider_has_saved_key('openai') ? 'An encrypted API key is saved. Leave blank to keep it.' : 'No OpenAI API key is saved.' ?></small>
        </div>
      </div>

      <?php if (ai_provider_has_saved_key('openai')): ?>
        <label class="admin-inline-check ai-remove-key"><input type="checkbox" name="remove_openai_key" value="1"> Remove saved OpenAI key</label>
      <?php endif; ?>

      <div class="ai-provider-actions">
        <span class="ai-ready-state <?= ai_provider_ready('openai') ? 'ready' : '' ?>">
          <?= ai_provider_ready('openai') ? 'Ready' : 'Not ready' ?>
        </span>
      </div>
    </section>

    <section class="ai-provider-card field full">
      <div class="ai-provider-card-head">
        <div>
          <span class="ai-provider-mark">C</span>
          <div>
            <h3>Claude / Anthropic</h3>
            <p>Messages API · Claude Code-compatible Anthropic API credential</p>
          </div>
        </div>

        <label class="ai-toggle">
          <input type="checkbox" name="anthropic_enabled" value="1" <?= ai_provider_enabled('anthropic') ? 'checked' : '' ?>>
          <span></span>
          <strong>Enabled</strong>
        </label>
      </div>

      <div class="ai-provider-fields">
        <div class="field">
          <label for="anthropic_model">Model</label>
          <select id="anthropic_model" name="anthropic_model">
            <?php foreach ($catalog['anthropic'] as $model => $label): ?>
              <option value="<?= e($model) ?>" <?= ai_provider_model('anthropic') === $model ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="anthropic_api_key">Anthropic API Key</label>
          <input id="anthropic_api_key" name="anthropic_api_key" type="password" autocomplete="new-password" placeholder="<?= ai_provider_has_saved_key('anthropic') ? 'Saved key ••••••' . e(ai_key_suffix('anthropic')) : 'sk-ant-…' ?>">
          <small><?= ai_provider_has_saved_key('anthropic') ? 'An encrypted API key is saved. Leave blank to keep it.' : 'No Anthropic API key is saved.' ?></small>
        </div>
      </div>

      <?php if (ai_provider_has_saved_key('anthropic')): ?>
        <label class="admin-inline-check ai-remove-key"><input type="checkbox" name="remove_anthropic_key" value="1"> Remove saved Claude / Anthropic key</label>
      <?php endif; ?>

      <div class="ai-provider-actions">
        <span class="ai-ready-state <?= ai_provider_ready('anthropic') ? 'ready' : '' ?>">
          <?= ai_provider_ready('anthropic') ? 'Ready' : 'Not ready' ?>
        </span>
      </div>
    </section>

    <div class="field full actions">
      <button class="btn primary" type="submit">Save AI Settings</button>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Connection Tests</h2>
  <p class="muted">Save your settings first, then test the enabled provider. A test makes one small API request.</p>

  <div class="actions">
    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <input type="hidden" name="provider" value="openai">
      <button class="btn" type="submit" <?= !ai_provider_ready('openai') ? 'disabled' : '' ?>>Test OpenAI</button>
    </form>

    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <input type="hidden" name="provider" value="anthropic">
      <button class="btn" type="submit" <?= !ai_provider_ready('anthropic') ? 'disabled' : '' ?>>Test Claude</button>
    </form>
  </div>
</div>

<div class="panel">
  <h2>Credential Security</h2>
  <p class="muted">API keys are encrypted before being stored in the database. The local encryption key is created at <code>/private/ai-key.php</code> the first time a credential is saved. Back up that file with your private site configuration; without it, saved API keys cannot be decrypted.</p>
</div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
