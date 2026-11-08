<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/agent-development-v1.php';

function db(): PDO { return $GLOBALS['development_test_pdo']; }
function site_config(string $key, mixed $default = null): mixed { return $key === 'base_url' ? 'https://app.example.test' : $default; }

function development_check(bool $pass, string $description): void
{
    if (!$pass) throw new RuntimeException($description);
    $GLOBALS['development_test_checks'] = ($GLOBALS['development_test_checks'] ?? 0) + 1;
    echo 'PASS '.$description.PHP_EOL;
}

function development_reject(callable $operation, int $status, string $description): void
{
    try { $operation(); }
    catch (DomainException $error) {
        development_check($error->getCode() === $status, $description.' returned '.$error->getCode());
        return;
    }
    throw new RuntimeException($description.' was accepted');
}

function development_fixture(): PDO
{
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    $dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
    if ($dsn === '') {
        $pdo = new PDO('sqlite::memory:', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $engine = '';
    } else {
        if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('The native development fixture needs a MySQL DSN.');
        $login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
        $password = (string)getenv('VP3_TEST_MYSQL_PASS');
        $server = new PDO($dsn, $login, $password, $options);
        $name = 'vp3_development_fixture_'.bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        register_shutdown_function(static fn() => $server->exec('DROP DATABASE IF EXISTS `'.$name.'`'));
        $dsn = rtrim((string)preg_replace('/;dbname=[^;]+/', '', $dsn), ';').';dbname='.$name;
        $pdo = new PDO($dsn, $login, $password, $options + [PDO::ATTR_EMULATE_PREPARES=>false]);
        $engine = ' ENGINE=InnoDB';
    }
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role VARCHAR(30) NOT NULL, is_active INT NOT NULL)'.$engine);
    $pdo->exec('CREATE TABLE user_account_types (user_id INT UNSIGNED NOT NULL, role VARCHAR(30) NOT NULL, assigned_explicitly_at DATETIME NULL, PRIMARY KEY (user_id,role))'.$engine);
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL)'.$engine);
    $pdo->exec("INSERT INTO users VALUES (1,'admin',1),(2,'admin',1),(3,'artist',1)");
    $pdo->exec("INSERT INTO user_account_types VALUES (3,'admin',NULL)");
    $pdo->exec('INSERT INTO chat_conversations VALUES (11,1),(12,1),(21,2)');
    return $pdo;
}

$pdo = $GLOBALS['development_test_pdo'] = development_fixture();
$owner = ['id'=>1, 'role'=>'admin'];
$other = ['id'=>2, 'role'=>'admin'];
$imposter = ['id'=>3, 'role'=>'admin', 'roles'=>['admin']];
$base = str_repeat('a', 40);
$head = str_repeat('b', 40);
$artifact = str_repeat('c', 64);
$token = str_repeat('t', 48);
$origin = 'https://isolated-preview.example.net';
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$base);
putenv('VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN');
putenv('VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN');

development_check(!vp3_dev_schema_ready_v1($pdo), 'historical installs fail readiness before the migration');
vp3_dev_install_v1($pdo);
vp3_dev_install_v1($pdo);
development_check(vp3_dev_schema_ready_v1($pdo), 'the real installer is idempotent');
development_check(vp3_dev_control_v1($pdo, 1) === ['mode'=>'off', 'epoch'=>0], 'development defaults to off');
development_reject(fn() => vp3_dev_set_mode_v1($pdo, $imposter, 'outline', 0), 403, 'persisted role authority rejects a stale admin principal');
development_reject(fn() => vp3_dev_set_mode_v1($pdo, $owner, 'publish', 0), 422, 'an unknown level cannot create publication authority');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'Add a private preview.', 0, 11), 409, 'off prevents development work');
vp3_dev_set_mode_v1($pdo, $owner, 'outline', 0);
vp3_dev_set_mode_v1($pdo, $owner, 'outline', 1);
development_check(vp3_dev_control_v1($pdo, 1) === ['mode'=>'outline', 'epoch'=>1]
    && vp3_dev_control_v1($pdo, 2) === ['mode'=>'off', 'epoch'=>0], 'levels are owner-scoped and an unchanged level preserves its epoch');
development_reject(fn() => vp3_dev_set_mode_v1($pdo, $owner, 'code_stage', 0), 409, 'a stale settings page cannot change the level');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'Add a private preview.', 0, 11), 409, 'stale requests cannot start development');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'Add a private preview.', 1, 21), 404, 'another administrator’s Chat cannot be attached');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'API key: fake-credential-for-fixture', 1, 11), 422, 'credentials are rejected before saving or invoking the model');
development_check(vp3_dev_list_v1($pdo, 1) === [], 'invalid submissions create no revision');

$draft = ['summary'=>'Propose a private preview.', 'plan'=>['Render a named example.'], 'checks'=>['The preview opens.']];
$generate = static fn(string $input): array => ['ok'=>true, 'answer'=>json_encode($draft, JSON_THROW_ON_ERROR)];
$proposal = vp3_dev_create_v1($pdo, $owner, "  Handle hello@example.org\r\nwith a fixture.  ", 1, 11,
    static function (string $input) use ($generate): array {
        development_check(json_decode($input, true, 8, JSON_THROW_ON_ERROR) === [
            'request'=>"Handle sender@example.test\nwith a fixture.", 'repository'=>'davemt74-lang/software',
        ], 'outline inference receives only the sanitized explicit request and repository name');
        return $generate($input);
    });
development_check($proposal['status'] === 'proposed' && $proposal['plan'] === $draft['plan'] && $proposal['preview_url'] === ''
    && !vp3_dev_runner_ready_v1() && vp3_dev_claim_v1($pdo) === null, 'outline mode works without a runner and grants no build lease');
development_check(vp3_dev_revision_v1($pdo, 2, $proposal['id']) === null && vp3_dev_list_v1($pdo, 1, 12) === []
    && count(vp3_dev_list_v1($pdo, 1, 11)) === 1, 'revision lookups are owner and Chat scoped');
$invalidDraft = vp3_dev_create_v1($pdo, $owner, 'Test malformed model output.', 1, 11,
    static fn() => ['ok'=>true, 'answer'=>'{"summary":"Fake deployment","checks":[],"preview_url":"https://untrusted.example.net"}']);
development_check($invalidDraft['status'] === 'failed' && $invalidDraft['preview_url'] === '', 'model output cannot mint a preview');
$interrupted = vp3_dev_create_v1($pdo, $owner, 'Change the mode while planning.', 1, 11,
    static function (string $input) use ($pdo, $owner, $generate): array {
        vp3_dev_set_mode_v1($pdo, $owner, 'code_stage', 1);
        return $generate($input);
    });
development_check($interrupted['status'] === 'cancelled' && $interrupted['plan'] === [], 'late inference cannot overwrite a revision after the level changes');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'Run code before setup.', 2, 11), 503, 'code mode waits for a configured runner and preview origin');

putenv('VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN='.$token);
foreach (['http://isolated-preview.example.net','https://isolated-preview.example.net/path','https://isolated-preview.example.net:99999',
    'https://127.0.0.1','https://app.example.test','https://staging.app.example.test','https://example.test'] as $unsafeOrigin) {
    putenv('VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN='.$unsafeOrigin);
    development_check(!vp3_dev_runner_ready_v1(), 'runner rejects unsafe or production-related preview origin '.$unsafeOrigin);
}
putenv('VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN='.$origin);
development_check(vp3_dev_runner_ready_v1(), 'a separate HTTPS preview origin permits runner setup');

$queued = vp3_dev_create_v1($pdo, $owner, 'Build the candidate.', 2, 11,
    static function (): never { throw new LogicException('The request handler must not call a coding model.'); });
development_check($queued['status'] === 'queued' && $queued['base_sha'] === $base && $queued['head_sha'] === ''
    && !vp3_dev_stage_current_v1($queued), 'a new build is queued without a candidate or preview');
development_reject(fn() => vp3_dev_create_v1($pdo, $owner, 'Duplicate concurrent build.', 2, 11), 409, 'a second active revision cannot stack work');
$job = vp3_dev_claim_v1($pdo);
development_check($job !== null && $job['id'] === $queued['id'] && $job['base_sha'] === $base && vp3_dev_claim_v1($pdo) === null
    && vp3_dev_revision_v1($pdo, 1, $job['id'])['status'] === 'building', 'the runner claims exactly one bound lease and the public state shows building');
$storedLease = vp3_dev_row_v1($pdo, 1, $job['id'])['lease_hash'];
development_check($storedLease === hash('sha256', $job['lease']) && !array_key_exists('lease_hash', vp3_dev_revision_v1($pdo, 1, $job['id'])), 'only the hashed lease is persisted and the public revision omits it');
$receipt = ['head_sha'=>$head, 'artifact_sha'=>$artifact, 'preview_url'=>$origin.'/revisions/'.$head.'/',
    'summary'=>'Isolated candidate verified.', 'checks'=>['PHP lint passed.', 'Browser smoke passed.']];
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], str_repeat('e', 64), $receipt), 409, 'wrong lease cannot stage code');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], [...$receipt, 'head_sha'=>$base]), 422, 'the unchanged base cannot be a staged candidate');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], [...$receipt, 'artifact_sha'=>'unverified']), 422, 'staging requires a valid artifact digest');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], [...$receipt, 'preview_url'=>$origin.'/revisions/'.str_repeat('d', 40).'/']), 422, 'preview URL must match the exact candidate');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], [...$receipt, 'checks'=>[]]), 422, 'staging requires verification checks');
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.str_repeat('d', 40));
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'a build cannot finish against an advanced base');
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$base);
development_check(vp3_dev_revision_v1($pdo, 1, $job['id'])['status'] === 'building', 'invalid receipts leave the build unverified');
vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt);
$staged = vp3_dev_revision_v1($pdo, 1, $job['id']);
development_check($staged['status'] === 'staged' && vp3_dev_stage_current_v1($staged) && $staged['checks'] === $receipt['checks']
    && $staged['head_sha'] === $head && $staged['artifact_sha'] === $artifact, 'the bound verification receipt enables its exact preview');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'the successful finish receipt consumes the lease');
development_reject(fn() => vp3_dev_approve_v1($pdo, $other, $job['id'], $head, $artifact), 409, 'a different administrator cannot approve this candidate');
development_reject(fn() => vp3_dev_approve_v1($pdo, $owner, $job['id'], str_repeat('d', 40), $artifact), 409, 'approval requires the reviewed head');
development_reject(fn() => vp3_dev_approve_v1($pdo, $owner, $job['id'], $head, str_repeat('d', 64)), 409, 'approval requires the reviewed artifact');
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.str_repeat('d', 40));
development_check(!vp3_dev_stage_current_v1($staged), 'a preview tied to an old base is no longer current');
development_reject(fn() => vp3_dev_approve_v1($pdo, $owner, $job['id'], $head, $artifact), 409, 'approval fails after the base advances');
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$base);
$expiry = $pdo->prepare('UPDATE agent_development_revisions_v1 SET stage_expires_at=? WHERE id=?');
$expiry->execute([time() - 1, $job['id']]);
development_check(!vp3_dev_stage_current_v1(vp3_dev_revision_v1($pdo, 1, $job['id'])), 'an expired preview is no longer current');
development_reject(fn() => vp3_dev_approve_v1($pdo, $owner, $job['id'], $head, $artifact), 409, 'approval requires a preview that has not expired');
$expiry->execute([$staged['stage_expires_at'], $job['id']]);
vp3_dev_approve_v1($pdo, $owner, $job['id'], $head, $artifact);
development_check(vp3_dev_revision_v1($pdo, 1, $job['id'])['status'] === 'approved' && vp3_dev_claim_v1($pdo) === null,
    'explicit approval records the candidate without enqueuing publication');

$pending = vp3_dev_create_v1($pdo, $owner, 'Cancel the active lease.', 2, 11);
$job = vp3_dev_claim_v1($pdo);
development_reject(fn() => vp3_dev_cancel_v1($pdo, $other, $job['id']), 409, 'another administrator cannot cancel the revision');
vp3_dev_cancel_v1($pdo, $owner, $job['id']);
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'a cancelled build cannot report a staged candidate');
development_check(vp3_dev_revision_v1($pdo, 1, $pending['id'])['status'] === 'cancelled', 'cancellation is durable');

vp3_dev_create_v1($pdo, $owner, 'Report an isolated failure.', 2, 11);
$job = vp3_dev_claim_v1($pdo);
vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], null);
$failed = vp3_dev_revision_v1($pdo, 1, $job['id']);
development_check($failed['status'] === 'failed' && $failed['preview_url'] === '' && $failed['stage_expires_at'] === 0,
    'a failed worker result records only the failure and enables no preview');

vp3_dev_create_v1($pdo, $owner, 'Test expired lease.', 2, 11);
$job = vp3_dev_claim_v1($pdo);
$pdo->prepare('UPDATE agent_development_revisions_v1 SET lease_expires_at=? WHERE id=?')->execute([time() - 1, $job['id']]);
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'expired leases cannot stage code');
development_check(vp3_dev_revision_v1($pdo, 1, $job['id'])['status'] === 'failed', 'expired builds render as failed');

$stale = vp3_dev_create_v1($pdo, $owner, 'Stage at the current epoch.', 2, 11);
$job = vp3_dev_claim_v1($pdo);
vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt);
vp3_dev_set_mode_v1($pdo, $owner, 'outline', 2);
vp3_dev_set_mode_v1($pdo, $owner, 'code_stage', 3);
development_reject(fn() => vp3_dev_approve_v1($pdo, $owner, $stale['id'], $head, $artifact), 409, 'changing levels and returning cannot revive an old approval epoch');

$stale = vp3_dev_create_v1($pdo, $owner, 'Queue an obsolete base.', 4, 11);
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.str_repeat('d', 40));
development_check(vp3_dev_claim_v1($pdo) === null && vp3_dev_revision_v1($pdo, 1, $stale['id'])['status'] === 'cancelled', 'the runner discards work pinned to an obsolete base');
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$base);

$obsolete = vp3_dev_create_v1($pdo, $owner, 'Start a build before the next release.', 4, 11);
$job = vp3_dev_claim_v1($pdo);
$nextBase = str_repeat('d', 40);
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$nextBase);
$fresh = vp3_dev_create_v1($pdo, $owner, 'Queue work on the new release.', 4, 11);
development_check($fresh['status'] === 'queued' && $fresh['base_sha'] === $nextBase
    && vp3_dev_revision_v1($pdo, 1, $obsolete['id'])['status'] === 'cancelled', 'obsolete in-flight work cannot block a fresh request after a release');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'a replaced build cannot return a late result');
vp3_dev_cancel_v1($pdo, $owner, $fresh['id']);
putenv('VP3_AGENT_DEVELOPMENT_BASE_SHA='.$base);

$demoted = vp3_dev_create_v1($pdo, $owner, 'Remove admin before claim.', 4, 11);
$pdo->exec("UPDATE users SET role='artist' WHERE id=1");
development_check(vp3_dev_claim_v1($pdo) === null && vp3_dev_revision_v1($pdo, 1, $demoted['id'])['status'] === 'cancelled', 'the worker discards queued work when the administrator is demoted');
$pdo->exec("UPDATE users SET role='admin' WHERE id=1");
vp3_dev_create_v1($pdo, $owner, 'Remove admin after claim.', 4, 11);
$job = vp3_dev_claim_v1($pdo);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
development_reject(fn() => vp3_dev_finish_v1($pdo, $job['id'], $job['lease'], $receipt), 409, 'worker revalidates the owner before accepting a result');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
vp3_dev_set_mode_v1($pdo, $owner, 'off', 4);
development_check(vp3_dev_revision_v1($pdo, 1, $job['id'])['status'] === 'cancelled' && vp3_dev_claim_v1($pdo) === null, 'switching off cancels in-flight coding work');

printf("%d Agent Development checks passed (%s).\n", $GLOBALS['development_test_checks'], $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
