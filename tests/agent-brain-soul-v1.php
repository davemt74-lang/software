<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/vp3-soul-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Could not make the Soul test directory.');
define('STONEFELLOW_ROOT', $root);
require_once dirname(__DIR__) . '/includes/agent-brain-v82.php';

function soul_check_v1(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

function soul_reject_v1(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException|InvalidArgumentException) {
        echo 'PASS ' . $label . PHP_EOL;
        return;
    }
    throw new RuntimeException($label);
}

$owner = ['id' => 81];
$other = ['id' => 82];
$legacy = "My private Main Agent notes from before version history.\n";
$email = "Speak up when a verified deadline changes.\n";
$change = "Group new mail and offer one action at a time.\n";
$removeTree = static function (string $directory): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
};

try {
    $emailDefault = agent_brain_soul_profile_v1($owner, 'email');
    soul_check_v1($emailDefault['version'] === 0 && str_contains($emailDefault['content'], '# Email Specialist')
        && !is_dir($root . '/private'), 'Email default is packaged and inspection creates no private files');

    mkdir(agent_brain_user_dir($owner['id']), 0700, true);
    file_put_contents(agent_brain_user_soul_path($owner['id']), $legacy);
    $mainDefault = agent_brain_soul_profile_v1($owner, 'main');
    soul_check_v1($mainDefault['version'] === 0 && $mainDefault['content'] === $legacy
        && agent_brain_soul_history_v1($owner, 'main') === [], 'Main Agent starts from the exact existing private Soul');

    soul_check_v1(agent_brain_soul_update_v1($owner, 'email', $emailDefault['revision'], 'save', $email) === 1
        && agent_brain_soul_saved_version_v1($owner, 'email', 0) === $emailDefault['content']
        && agent_brain_soul($owner) === $legacy && agent_brain_soul_profile_v1($other, 'email')['content'] === $emailDefault['content'],
        'Email save preserves its baseline, Main Agent and the other account');

    $first = agent_brain_soul_profile_v1($owner, 'email');
    soul_check_v1(agent_brain_soul_update_v1($owner, 'email', $first['revision'], 'save', $change) === 2,
        'an edit appends the next version');
    soul_reject_v1(static fn() => agent_brain_soul_update_v1($owner, 'email', $first['revision'], 'save', $email),
        'a stale editor cannot overwrite the current version');
    soul_reject_v1(static fn() => agent_brain_soul_update_v1($other, 'email', $first['revision'], 'restore', 1),
        'an active revision is bound to its account');
    soul_reject_v1(static fn() => agent_brain_soul_update_v1($owner, 'email', $mainDefault['revision'], 'save', $email),
        'a revision from another role cannot change Email');
    soul_reject_v1(static fn() => agent_brain_soul_saved_version_v1($other, 'email', 1),
        'an account cannot read another account version using its number');
    soul_reject_v1(static fn() => agent_brain_soul_profile_v1($owner, '../../email'),
        'only registered role names can select a Soul');

    $second = agent_brain_soul_profile_v1($owner, 'email');
    soul_check_v1(agent_brain_soul_update_v1($owner, 'email', $second['revision'], 'restore', 1) === 3
        && agent_brain_soul_profile_v1($owner, 'email')['content'] === $email
        && agent_brain_soul_saved_version_v1($owner, 'email', 2) === $change,
        'restore creates a new active version and preserves the edit it replaced');
    $third = agent_brain_soul_profile_v1($owner, 'email');
    require_once dirname(__DIR__) . '/includes/agent-connection-email-chat-v1.php';
    $writingInput = [];
    $provenance = [];
    $draft = agent_connection_email_chat_reply_body_v1(new PDO('sqlite::memory:'),$owner,0,1,str_repeat('a',32),
        ['mode'=>'draft','instruction'=>'Confirm the deadline.'],['subject'=>'Delivery','body'=>'Can you confirm by Tuesday?'],
        static fn(): bool => true,static function(string $prompt) use (&$writingInput): array {
            $writingInput = json_decode($prompt,true,8,JSON_THROW_ON_ERROR);
            return ['ok'=>true,'answer'=>'{"body":"I can confirm by Tuesday."}'];
        },$provenance);
    soul_check_v1($draft === 'I can confirm by Tuesday.' && $writingInput['email_soul'] === $email
        && $provenance === ['soul_version'=>3,'soul_revision'=>$third['revision']],
        'the next Email draft uses the restored role text and records its exact revision');
    soul_check_v1(agent_brain_soul_update_v1($owner, 'email', $third['revision'], 'reset') === 4
        && agent_brain_soul_profile_v1($owner, 'email')['content'] === $emailDefault['content']
        && agent_brain_soul_history_v1($owner, 'email') === [4,3,2,1,0],
        'reset publishes the packaged default and retains every prior version');
    if (DIRECTORY_SEPARATOR === '/') {
        soul_check_v1((fileperms(agent_brain_soul_directory_v1($owner, 'email')) & 0777) === 0700
            && (fileperms(agent_brain_soul_directory_v1($owner, 'email') . '/v-0000000004.md') & 0777) === 0600,
            'private directories and published Soul versions restrict filesystem access');
    }

    agent_brain_save_soul($owner, $change);
    $main = agent_brain_soul_profile_v1($owner, 'main');
    soul_check_v1($main['version'] === 1 && $main['content'] === $change && agent_brain_soul($owner) === $change
        && agent_brain_soul_saved_version_v1($owner, 'main', 0) === $legacy
        && file_get_contents(agent_brain_user_soul_path($owner['id'])) === $legacy
        && file_get_contents(agent_brain_ensure_user_soul($owner)) === $change,
        'Main Agent compatibility readers use the active version and preserve the original file');
    if (function_exists('pcntl_fork')) {
        $pids = [];
        for ($index = 1; $index <= 2; $index++) {
            $pid = pcntl_fork();
            if ($pid < 0) throw new RuntimeException('Could not fork Soul fixture.');
            if ($pid === 0) {
                try {
                    agent_brain_soul_update_v1($owner, 'main', $main['revision'], 'save', 'Concurrent preference number ' . $index);
                } catch (RuntimeException $e) {
                    exit(str_contains($e->getMessage(), 'changed in another tab') ? 2 : 1);
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        $results = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $results[] = pcntl_wexitstatus($status);
        }
        sort($results);
        soul_check_v1($results === [0,2] && agent_brain_soul_history_v1($owner, 'main') === [2,1,0],
            'two concurrent edits based on one version publish exactly one update');
    }
    $active = agent_brain_soul_profile_v1($owner, 'main');
    soul_check_v1(agent_brain_soul_update_v1($owner, 'main', $active['revision'], 'save', $active['content']) === $active['version'] + 1,
        'saving the same text still records the requested version');
    echo 'AGENT_BRAIN_SOUL_V1=PASS' . PHP_EOL;
} finally {
    $removeTree($root);
}
