<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_permission('ai.manage');
require_once dirname(__DIR__).'/includes/agent-image-provider-v1.php';
require_once dirname(__DIR__).'/includes/ai-service-policy-v1.php';
require_once __DIR__.'/_ai-service-controls-v1.php';
header('Cache-Control: no-store');

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$scope = $_GET['scope'] ?? 'site';
$service = $_GET['service'] ?? 'conversation';
$catalog = array_filter(vp3_ai_service_catalog_v1(), static fn(array $entry): bool => $entry['wired']);
$back = url('/admin/ai.php#ai-service-routes');
if (!in_array($scope, ['site', 'package', 'user'], true) || !is_string($service) || !isset($catalog[$service])) {
    flash('error', 'Select an available service and rule scope.');
    redirect($back);
}

$scopeId = 0;
$scopeLabel = 'Site';
$query = ['scope' => $scope];
if ($scope !== 'site') {
    require_permission('users.manage');
    $scopeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $isPackage = $scope === 'package';
    $back = url($isPackage ? '/admin/packages.php' : '/admin/users.php');
    if ($scopeId === false) {
        flash('error', 'Select an account or package.');
        redirect($back);
    }
    $target = $pdo->prepare($isPackage ? 'SELECT name FROM subscription_packages WHERE id=?' : 'SELECT display_name FROM users WHERE id=?');
    $target->execute([$scopeId]);
    $name = $target->fetchColumn();
    if ($name === false) {
        flash('error', 'The selected account or package was not found.');
        redirect($back);
    }
    $scopeLabel = ($isPackage ? 'Package · ' : 'Account · ').$name;
    $back = url(($isPackage ? '/admin/packages.php?edit=' : '/admin/users.php?edit=').$scopeId.($isPackage ? '' : '#subscription-panel'));
    $query['id'] = $scopeId;
}
$formUrl = url('/admin/ai-service-rules.php?'.http_build_query($query + ['service' => $service]));

$states = ['inherit' => 'Inherit', 'allow' => 'Allow', 'block' => 'Block'];
$stateFields = ['enabled' => 'Service'];
if ($service === 'initiative') $stateFields['background_enabled'] = 'Background use';
$limitFields = [
    'job_tokens' => 'Tokens per request', 'daily_tokens' => 'Tokens per day', 'monthly_tokens' => 'Tokens per month',
];
if ($service === 'initiative') {
    $limitFields += ['background_daily_tokens' => 'Background tokens per day', 'background_monthly_tokens' => 'Background tokens per month'];
}
$rule = vp3_ai_service_read_rule_v1($pdo, $scope, $scopeId, $service);
$models = in_array($service, VP3_AI_CODING_SERVICES_V1, true)
    ? vp3_ai_service_coding_models_v1(vp3_ai_service_config_v1($pdo))
    : vp3_ai_service_models_v1($pdo, $service);
// Retain rules for previously registered models until an admin changes the selection.
foreach ($rule['allowed_models'] ?? [] as $value) {
    [$provider, $model] = explode(':', $value, 2);
    $models[$provider][$model] ??= 'Unavailable model';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect($formUrl);
    }
    try {
        // Preserve settings that this service editor does not currently expose.
        foreach ($stateFields as $key => $label) {
            $value = vp3_admin_ai_service_post_string_v1($key);
            if (!isset($states[$value])) throw new InvalidArgumentException('Choose an access rule for '.$label.'.');
            if ($value === 'inherit') unset($rule[$key]);
            else $rule[$key] = $value === 'allow';
        }
        foreach ($limitFields as $key => $label) {
            $value = vp3_admin_ai_service_post_string_v1($key);
            if ($value === '') {
                unset($rule[$key]);
                continue;
            }
            $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000_000]]);
            if ($amount === false) throw new InvalidArgumentException('Enter a whole number between 0 and 1,000,000,000 for '.$label.'.');
            $rule[$key] = $amount;
        }
        $modelRule = vp3_admin_ai_service_post_string_v1('model_rule');
        if ($modelRule === 'inherit') unset($rule['allowed_models']);
        elseif ($modelRule === 'selected') {
            $selected = $_POST['allowed_models'] ?? [];
            if (!is_array($selected) || !array_is_list($selected)) throw new InvalidArgumentException('Select valid models.');
            foreach ($selected as $value) {
                [$provider, $model] = is_string($value) ? array_pad(explode(':', $value, 2), 2, '') : ['', ''];
                if (!isset($models[$provider][$model])) throw new InvalidArgumentException('Select models listed for this service.');
            }
            $rule['allowed_models'] = $selected;
        } else throw new InvalidArgumentException('Choose a model access rule.');

        vp3_ai_service_save_rule_v1($pdo, $scope, $scopeId, $service, $rule, (int)current_user()['id']);
        flash('notice', 'AI service rules saved.');
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        flash('error', 'The AI service rules could not be saved.');
    }
    redirect($formUrl);
}

$adminTitle = 'AI service rules';
$adminActive = match ($scope) { 'package' => 'packages', 'user' => 'users', default => 'ai' };
require __DIR__.'/_header.php';
?>
<section class="admin-section-heading">
  <div>
    <span class="eyebrow"><?= e($scopeLabel) ?></span>
    <h2>AI service rules</h2>
    <p>Site, package and account rules combine. Every applicable restriction is enforced and the lowest token limit applies. VP3 Cloud usage also counts toward the package's AI allowance.</p>
  </div>
  <a class="btn" href="<?= e($back) ?>">Back to <?= $scope === 'package' ? 'package' : ($scope === 'user' ? 'account' : 'AI settings') ?></a>
</section>
<nav class="actions" aria-label="AI services">
  <?php foreach ($catalog as $id => $entry): ?>
    <a class="btn<?= $id === $service ? ' primary' : '' ?>" <?= $id === $service ? 'aria-current="page"' : '' ?> href="<?= e(url('/admin/ai-service-rules.php?'.http_build_query($query + ['service' => $id]))) ?>"><?= e($entry['label']) ?></a>
  <?php endforeach; ?>
</nav>
<section class="panel" aria-labelledby="service-rules-heading">
  <h2 id="service-rules-heading"><?= e($catalog[$service]['label']) ?></h2>
  <form method="post" action="<?= e($formUrl) ?>" class="grid-form">
    <?= csrf_field() ?>
    <?php foreach ($stateFields as $key => $label):
        $selected = isset($rule[$key]) ? ($rule[$key] ? 'allow' : 'block') : 'inherit';
        ?>
      <label class="field"><?= e($label) ?>
        <select name="<?= e($key) ?>">
          <?php foreach ($states as $value => $option): ?>
            <option value="<?= e($value) ?>" <?= $selected === $value ? 'selected' : '' ?>><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <label class="field">Model access
      <select name="model_rule">
        <option value="inherit" <?= isset($rule['allowed_models']) ? '' : 'selected' ?>>Inherit</option>
        <option value="selected" <?= isset($rule['allowed_models']) ? 'selected' : '' ?>>Allow selected models only</option>
      </select>
    </label>
    <label class="field full">Allowed models
      <select name="allowed_models[]" multiple size="7" aria-describedby="ai-model-rule-help">
        <?php vp3_admin_ai_service_model_options_v1($models, $rule['allowed_models'] ?? []); ?>
      </select>
      <small id="ai-model-rule-help">Used with “Allow selected models only.” An empty selection blocks every model.</small>
    </label>
    <fieldset class="field full" aria-describedby="ai-service-limit-help">
      <legend>Token limits</legend>
      <p class="muted" id="ai-service-limit-help">Leave a limit blank to inherit it. Enter zero to block usage for that limit.</p>
      <div class="form-row">
        <?php foreach ($limitFields as $key => $label): ?>
          <label class="field"><?= e($label) ?>
            <input type="number" name="<?= e($key) ?>" min="0" max="1000000000" step="1" value="<?= e((string)($rule[$key] ?? '')) ?>">
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="field full actions">
      <button class="btn primary" type="submit">Save rules</button>
    </div>
  </form>
</section>
<?php require __DIR__.'/_footer.php'; ?>
