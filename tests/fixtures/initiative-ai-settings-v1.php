<?php
declare(strict_types=1);

// Load the real provider configuration before image fixtures can install their
// transport-only provider stubs. Every credential and path is isolated test data.
$initiativeAiOwnRoot = !defined('STONEFELLOW_ROOT');
$initiativeAiFixtureRoot = $initiativeAiOwnRoot
    ? sys_get_temp_dir().'/vp3-initiative-ai-'.bin2hex(random_bytes(8)) : (string)STONEFELLOW_ROOT;
if ($initiativeAiOwnRoot) define('STONEFELLOW_ROOT', $initiativeAiFixtureRoot);
$initiativeAiOwnPrivate = !is_dir($initiativeAiFixtureRoot.'/private');
if ($initiativeAiOwnPrivate) mkdir($initiativeAiFixtureRoot.'/private', 0700, true);
$initiativeAiFixtureKeyPath = $initiativeAiFixtureRoot.'/private/ai-key.php';
if (file_exists($initiativeAiFixtureKeyPath)) throw new LogicException('The AI fixture must not overwrite an existing provider key.');
$initiativeAiFixtureKey = random_bytes(32);
file_put_contents($initiativeAiFixtureKeyPath, '<?php return '.var_export(base64_encode($initiativeAiFixtureKey), true).';');
register_shutdown_function(static function () use ($initiativeAiFixtureRoot, $initiativeAiFixtureKeyPath, $initiativeAiOwnRoot, $initiativeAiOwnPrivate): void {
    if (!is_dir($initiativeAiFixtureRoot)) return;
    if (!$initiativeAiOwnRoot) {
        if (is_file($initiativeAiFixtureKeyPath)) unlink($initiativeAiFixtureKeyPath);
        if ($initiativeAiOwnPrivate && is_dir($initiativeAiFixtureRoot.'/private')
            && count(scandir($initiativeAiFixtureRoot.'/private')) === 2) rmdir($initiativeAiFixtureRoot.'/private');
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($initiativeAiFixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($initiativeAiFixtureRoot);
});
require_once dirname(__DIR__, 2).'/includes/ai-settings.php';
require_once dirname(__DIR__, 2).'/includes/ai-runtime-v100.php';
$GLOBALS['imageSettings']['ai_active_provider'] = 'openai';
$GLOBALS['imageSettings']['ai_openai_enabled'] = '1';
$GLOBALS['imageSettings']['ai_openai_api_key'] = ai_encrypt_secret_with_key('fixture-initiative-key', $initiativeAiFixtureKey);

/** Isolate these worker scenarios by starting after the day's brief assessment. */
function initiative_fixture_morning_brief_reviewed(array $user,int $agent):void
{
    require_once dirname(__DIR__,2).'/includes/agent-morning-brief-v1.php';
    $day=vp3_morning_brief_local_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
    vp3_initiative_in_scope_v1($user,$agent,static fn()=>agent_brain_v122_upsert_system_memory(
        $user,'initiative_sweep','morning-brief:'.$day,'Prior daily brief fixture assessment',['local_day'=>$day]));
}
