<?php
declare(strict_types=1);

function vp3_admin_ai_service_post_string_v1(string $field): string
{
    if (!array_key_exists($field, $_POST)) return '';
    if (!is_string($_POST[$field])) throw new InvalidArgumentException('Invalid AI service form value.');
    return trim($_POST[$field]);
}

function vp3_admin_ai_service_route_choice_v1(string $position): ?array
{
    $selected = vp3_admin_ai_service_post_string_v1($position.'_model');
    if ($selected === '') return null;
    [$provider, $model] = array_pad(explode(':', $selected, 2), 2, '');
    return ['provider' => $provider, 'model' => $model];
}

function vp3_admin_ai_service_provider_registry_v1(PDO $pdo, string $provider): void
{
    $catalog = vp3_ai_service_catalog_v1();
    $choices = [];
    foreach ($catalog as $service => $spec) {
        foreach (vp3_ai_service_models_v1($pdo, $service)[$provider] ?? [] as $id => $label) {
            $choices[$id]['label'] = $label;
            $choices[$id]['services'][] = $spec['label'];
        }
    }
    $registered = [];
    foreach (vp3_ai_service_registered_models_v1($pdo) as $model) {
        if ($model['provider'] === $provider) $registered[$model['model']] = true;
    }
    $saveForm = 'ai-model-save-'.$provider;
    $commandForm = 'ai-model-command-'.$provider;
    ?>
    <details id="<?= e('provider-models-'.$provider) ?>" <?= ($_GET['models'] ?? '') === $provider ? 'open' : '' ?>>
      <summary>Model registry · <?= count($choices) ?> models</summary>
      <p class="muted">Test model checks whether the saved installation key can retrieve a model from the enabled provider. Save credential changes first.</p>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Model</th><th>Services</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($choices as $id => $choice): ?>
            <tr>
              <td><strong><?= e($choice['label']) ?></strong><br><code><?= e((string)$id) ?></code></td>
              <td><?= e(implode(', ', $choice['services'])) ?></td>
              <td>
                <button class="btn" type="submit" name="test_model" value="<?= e((string)$id) ?>" form="<?= e($commandForm) ?>">Test model</button>
                <?php if (isset($registered[$id])): ?>
                  <button class="btn" type="submit" name="remove_model" value="<?= e((string)$id) ?>" form="<?= e($commandForm) ?>">Remove model</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="ai-provider-fields">
        <label class="field">Exact API model ID<input name="model" maxlength="120" required form="<?= e($saveForm) ?>" autocomplete="off"></label>
        <label class="field">Display name<input name="label" maxlength="120" required form="<?= e($saveForm) ?>"></label>
      </div>
      <fieldset>
        <legend>Eligible service routes</legend>
        <div class="admin-check-grid">
          <?php foreach ($catalog as $id => $service): if (!in_array($provider, $service['providers'], true)) continue; ?>
            <label><input type="checkbox" name="services[]" value="<?= e($id) ?>" form="<?= e($saveForm) ?>"> <?= e($service['label']) ?><?= $service['wired'] ? '' : ' · planned' ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <p class="muted">Enter an existing ID to update its display name and service eligibility.</p>
      <button class="btn" type="submit" form="<?= e($saveForm) ?>">Save model</button>
    </details>
    <?php
}

function vp3_admin_ai_service_model_options_v1(array $models, array $selected): void
{
    foreach ($models as $provider => $options) {
        if ($options === []) continue;
        ?>
        <optgroup label="<?= e(ai_provider_labels()[$provider] ?? $provider) ?>">
          <?php foreach ($options as $model => $label): $value = $provider.':'.$model; ?>
            <option value="<?= e($value) ?>" <?= in_array($value, $selected, true) ? 'selected' : '' ?>><?= e($label.' · '.$model) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php
    }
}

function vp3_admin_ai_service_routes_v1(PDO $pdo): void
{
    $catalog = vp3_ai_service_catalog_v1();
    $planned = array_column(array_filter($catalog, static fn(array $service): bool => !$service['wired']), 'label');
    ?>
    <section id="ai-service-routes" class="panel" aria-labelledby="ai-service-routes-title">
      <h2 id="ai-service-routes-title">AI service routes</h2>
      <p class="muted">Choose the primary and fallback models for each available service. A route can inherit the existing setup.</p>
      <p class="muted">Primary and fallback models use the same account funding source. VP3 Cloud is the default. Members who choose My API keys in AI Providers need a saved key for each provider their routes use.</p>
      <?php foreach ($catalog as $id => $service):
          if (!$service['wired']) continue;
          $route = vp3_ai_service_read_route_v1($pdo, $id);
          $models = vp3_ai_service_models_v1($pdo, $id);
          ?>
        <details id="ai-service-route-<?= e($id) ?>" class="ai-provider-card" <?= ($_GET['route'] ?? 'conversation') === $id ? 'open' : '' ?>>
          <summary><strong><?= e($service['label']) ?></strong></summary>
          <form method="post" class="grid-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_service_route">
            <input type="hidden" name="service" value="<?= e($id) ?>">
            <?php foreach (['primary' => 'Primary', 'fallback' => 'Fallback'] as $key => $label):
                $choice = $route[$key] ?? null;
                $selected = $choice === null ? [] : [$choice['provider'].':'.$choice['model']];
                ?>
              <label class="field"><?= e($label) ?> model
                <select name="<?= e($key.'_model') ?>">
                  <option value=""><?= $key === 'primary' ? 'Use existing setup' : 'No fallback' ?></option>
                  <?php vp3_admin_ai_service_model_options_v1($models, $selected); ?>
                </select>
              </label>
            <?php endforeach; ?>
            <div class="field full actions">
              <button class="btn primary" type="submit">Save route</button>
              <a class="btn" href="<?= e(url('/admin/ai-service-rules.php?scope=site&service='.rawurlencode($id))) ?>">Site rules</a>
            </div>
          </form>
        </details>
      <?php endforeach; ?>
      <?php if ($planned): ?><p class="muted">Planned services · <?= e(implode(', ', $planned)) ?></p><?php endif; ?>
    </section>
    <?php
}
