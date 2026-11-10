<?php
declare(strict_types=1);

// The canonical scope fixture supplies real policy memory, named Agents and
// source permissions. Only provider transport and host capabilities are mocked.
ob_start();
try { require __DIR__.'/agent-initiative-scope-v1.php'; }
finally { ob_end_clean(); }
require_once dirname(__DIR__).'/includes/agent-initiative-canvas-policy-v1.php';

function personal_knowledge_available(?array $user = null): bool { return !empty($GLOBALS['initiativeScreenAccess']); }
function canvas_setting_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['canvasSettingChecks'] = ($GLOBALS['canvasSettingChecks'] ?? 0) + 1;
}
function canvas_setting_reject(int $code, callable $work): void
{
    try { $work(); }
    catch (Throwable $error) { canvas_setting_check($error->getCode() === $code, 'Expected '.$code.', got '.$error->getCode().' '.$error->getMessage()); return; }
    throw new RuntimeException('Expected '.$code);
}

$GLOBALS['initiativeScreenAccess'] = true;
$GLOBALS['imageDenied'] = false;
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
if (!table_exists('settings')) $pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_name TEXT,sort_order INTEGER);
    CREATE TABLE IF NOT EXISTS knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_id INTEGER);
    CREATE TABLE IF NOT EXISTS ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,created_at TEXT)');
vp3_coding_canvas_schema_v1($pdo);
vp3_ai_service_usage_ensure_schema_v1($pdo);
$target = ['provider'=>'openai', 'model'=>'canvas-settings-fixture'];
vp3_ai_service_save_model_v1($pdo, $target['provider'], $target['model'], 'Fixture coder', ['code_build','code_repair']);
foreach (['code_build','code_repair'] as $service) vp3_ai_service_save_route_v1($pdo, $service, $target, null);
scope_command($one, 17, 170, 'on');

$changes = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$initial = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
canvas_setting_check(!$initial['enabled'] && $initial['available'] && $initial['can_enable'] && $initial['initiative_on'], 'Auto Build defaults off for the exact named Agent.');
canvas_setting_check((int)$pdo->query('SELECT total_changes()')->fetchColumn() === $changes, 'Reading Auto Build settings writes nothing.');
canvas_setting_check(!str_contains(json_encode($initial, JSON_THROW_ON_ERROR), 'fixture-image-secret'), 'Settings contain no provider key.');
$old = vp3_initiative_policy_v1($one, 17);
$enabled = vp3_initiative_canvas_setting_v1($pdo, $one, 17, true, $initial['revision']);
$policy = vp3_initiative_policy_v1($one, 17);
canvas_setting_check($enabled['enabled'] && $policy['allow_auto_drafts'] === true && $enabled['revision'] !== $initial['revision'], 'Enabling rotates the canonical policy revision.');
unset($policy['allow_auto_drafts'], $policy['revision']);
unset($old['allow_auto_drafts'], $old['revision']);
canvas_setting_check($old === $policy, 'Auto Build does not expand source access, move the policy Chat, or enable Initiative.');
canvas_setting_reject(409, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17, false, $initial['revision']));
canvas_setting_check(vp3_initiative_canvas_setting_v1($pdo, $one, 17)['enabled'], 'A stale form cannot overwrite newer settings.');

$otherAgent = vp3_initiative_canvas_setting_v1($pdo, $one, 18);
canvas_setting_check(!$otherAgent['enabled'] && $otherAgent['agent_id'] === 18, 'Another owned Agent retains an independent preference.');
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $two, 17));
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 19));
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant'=>8];
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17));
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17, false, $enabled['revision']));
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);

scope_command($one, 0, 142, 'on');
$system = vp3_initiative_canvas_setting_v1($pdo, $one, 0);
canvas_setting_check($system['available'] && !$system['enabled'] && $system['agent_id'] === 0, 'The default System Agent has its own off-by-default control.');
$system = vp3_initiative_canvas_setting_v1($pdo, $one, 0, true, $system['revision']);
canvas_setting_check($system['enabled'] && vp3_initiative_canvas_setting_v1($pdo, $one, 17)['enabled'], 'System and named Agent preferences use independent policy memory.');

$missing = vp3_initiative_canvas_setting_v1($pdo, $one, 7);
canvas_setting_check(!$missing['available'] && !$missing['enabled'], 'An Agent without a saved policy cannot implicitly gain Initiative permissions.');
canvas_setting_reject(409, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 7, true, str_repeat('a',32)));

scope_command($one, 17, 170, 'off');
$paused = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
canvas_setting_check(!$paused['initiative_on'] && $paused['enabled'], 'Pausing Initiative retains Auto Build preference without granting execution.');
$disabled = vp3_initiative_canvas_setting_v1($pdo, $one, 17, false, $paused['revision']);
canvas_setting_check(!$disabled['enabled'] && !$disabled['initiative_on'], 'Changing Auto Build while paused leaves Initiative paused.');
$enabled = vp3_initiative_canvas_setting_v1($pdo, $one, 17, true, $disabled['revision']);
$GLOBALS['noImageEntitlement'] = true;
$blocked = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
canvas_setting_check($blocked['available'] && $blocked['enabled'] && !$blocked['can_enable'], 'A lost AI entitlement still permits the existing preference to be turned off.');
$disabled = vp3_initiative_canvas_setting_v1($pdo, $one, 17, false, $blocked['revision']);
canvas_setting_check(!$disabled['enabled'], 'Authorized opt-out does not require an AI entitlement or available model.');
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17, true, $disabled['revision']));
$GLOBALS['noImageEntitlement'] = false;

vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'code_build', ['background_enabled'=>false]);
$backgroundBlocked = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
canvas_setting_check(!$backgroundBlocked['can_enable'], 'A foreground-eligible model does not bypass the Admin background coding rule.');
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17, true, $backgroundBlocked['revision']));
canvas_setting_check(vp3_coding_canvas_model_resolve_v1($pdo, $one) === $target + ['funding'=>'system'], 'Background opt-in restrictions do not change the selected foreground coding model.');
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'code_build', []);

$before = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
$calls = 0;
canvas_setting_reject(403, function () use ($pdo, $one, $before, &$calls): void {
    vp3_initiative_canvas_setting_v1($pdo, $one, 17, true, $before['revision'], static function () use (&$calls): void {
        if (++$calls === 2) $GLOBALS['imageDenied'] = true;
    });
});
$GLOBALS['imageDenied'] = false;
$after = vp3_initiative_canvas_setting_v1($pdo, $one, 17);
canvas_setting_check(!$after['enabled'] && $after['revision'] === $before['revision'] && !$pdo->inTransaction(), 'Revoked access during a save leaves the preference unchanged and releases its transaction.');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
canvas_setting_reject(403, fn()=>vp3_initiative_canvas_setting_v1($pdo, $one, 17));
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
echo 'INITIATIVE_CANVAS_SETTINGS_V1=PASS ('.$GLOBALS['canvasSettingChecks'].' cases)'.PHP_EOL;
