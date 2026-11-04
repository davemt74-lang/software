<?php
declare(strict_types=1);

$root = (string)getenv('VP3_PA3_ROOT');
require $root.'/tests/fixtures/profile-calls-api-pa3c-bootstrap.php';
require_once $root.'/includes/agent-compute-v020.php';
require_once $root.'/includes/agent-compute-v023.php';

function ai_active_provider(): string { return 'local'; }

function vp3_profile_call_livekit_assistance_configured_pa3c(): bool
{
    return getenv('VP3_PROFILE_CALL_AGENT_ENABLED') === '1'
        && getenv('VP3_PROFILE_CALL_AGENT_URL') === 'wss://fixture.livekit.cloud'
        && getenv('VP3_PROFILE_CALL_AGENT_NAME') === 'fixture-profile-agent';
}

function vp3_profile_call_livekit_dispatch_assistant_pa3c(
    array $binding, string $workerName, array $metadata, bool $replace=false,
    ?int $deadline=null, ?callable $http=null
): void {
    if (db()->inTransaction() || session_status() === PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Agent dispatch must release SQL and browser session locks.');
    }
    file_put_contents((string)getenv('VP3_PA3C_ASSISTANCE_DISPATCHES'), json_encode([
        'binding'=>$binding,'name'=>$workerName,'metadata'=>$metadata,'replace'=>$replace,
    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

$GLOBALS['pa3cProviderHook'] = static function(string $operation, array $binding, array $options): void {
    if ($operation === 'token') {
        // Provider observation still has to run before this simulated join is
        // accepted as a verified human connection by the production engine.
        $GLOBALS['pa3cRooms'][$binding['room_name']][$options['role']] = true;
    }
};
