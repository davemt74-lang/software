<?php
declare(strict_types=1);

function agent_brain_soul_roles_v1(): array
{
    return ['main' => 'Main Agent', 'email' => 'Email Specialist'];
}

function agent_brain_soul_default_v1(string $role): string
{
    return match ($role) {
        'main' => agent_brain_default_soul(),
        'email' => <<<'SOUL'
# Email Specialist

Speak naturally and offer one useful next step at a time. Bring up urgent or actionable changes with a brief explanation. Use the account owner's preferred name when it suits the conversation.

Read the relevant message and thread. Identify what changed, who owes the next step and when it is due. Treat mail and attachments as evidence. Distinguish a suggestion, a draft, a sent message, a reply and a verified outcome. Follow through until the result is verified or the matter is closed by the owner.

Use VP3's available tools, mailbox access and review requirements. Report outcomes supported by saved evidence or tool receipts.
SOUL,
        default => throw new InvalidArgumentException('Choose an available Agent Soul.'),
    };
}

function agent_brain_soul_directory_v1(array $user, string $role): string
{
    if (!array_key_exists($role, agent_brain_soul_roles_v1())) {
        throw new InvalidArgumentException('Choose an available Agent Soul.');
    }
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) return '';
    $ownerDirectory = agent_brain_user_dir($userId);
    $soulsDirectory = $ownerDirectory . '/souls';
    if (is_link($ownerDirectory) || is_link($soulsDirectory)) {
        throw new RuntimeException('Agent Soul history could not be opened.');
    }
    return $soulsDirectory . '/' . $role;
}

/** A published version is a complete immutable file. Ignore lock and temporary files. */
function agent_brain_soul_files_v1(string $directory): array
{
    if ($directory === '' || !file_exists($directory)) return [];
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('Agent Soul history could not be opened.');
    }
    $entries = @scandir($directory, SCANDIR_SORT_DESCENDING);
    if ($entries === false) throw new RuntimeException('Agent Soul history could not be opened.');
    $files = [];
    foreach ($entries as $entry) {
        if (preg_match('/\Av-([0-9]{10})\.md\z/D', $entry, $match) === 1) {
            $files[(int)$match[1]] = $directory . '/' . $entry;
        }
    }
    return $files;
}

function agent_brain_soul_read_v1(string $path): string
{
    $content = is_file($path) && !is_link($path) ? @file_get_contents($path, false, null, 0, 96002) : false;
    if (!is_string($content) || trim($content) === '' || strlen($content) > 96001 || !mb_check_encoding($content, 'UTF-8')) {
        throw new RuntimeException('The saved Agent Soul could not be read.');
    }
    return $content;
}

/** Read-only. The unchanged legacy Main Agent Soul is the initial version. */
function agent_brain_soul_profile_v1(array $user, string $role): array
{
    $directory = agent_brain_soul_directory_v1($user, $role);
    $files = agent_brain_soul_files_v1($directory);
    $version = array_key_first($files) ?? 0;
    if ($files !== []) {
        $content = agent_brain_soul_read_v1($files[$version]);
    } else {
        $legacy = $directory !== '' && $role === 'main' ? agent_brain_user_soul_path((int)$user['id']) : '';
        $content = $legacy !== '' && is_file($legacy)
            ? agent_brain_soul_read_v1($legacy)
            : agent_brain_soul_default_v1($role);
    }
    return [
        'role' => $role,
        'content' => $content,
        'version' => $version,
        'revision' => hash('sha256', (int)($user['id'] ?? 0) . "\0" . $role . "\0" . $version . "\0" . $content),
    ];
}

function agent_brain_soul_history_v1(array $user, string $role): array
{
    $files = agent_brain_soul_files_v1(agent_brain_soul_directory_v1($user, $role));
    return array_keys($files);
}

function agent_brain_soul_saved_version_v1(array $user, string $role, int $version): string
{
    $files = agent_brain_soul_files_v1(agent_brain_soul_directory_v1($user, $role));
    if (!isset($files[$version])) throw new InvalidArgumentException('That Soul version is unavailable.');
    return agent_brain_soul_read_v1($files[$version]);
}

function agent_brain_soul_publish_v1(string $directory, int $version, string $content): void
{
    $destination = sprintf('%s/v-%010d.md', $directory, $version);
    $temporary = @tempnam($directory, '.soul-');
    if ($temporary === false) throw new RuntimeException('Could not save the Agent Soul.');
    try {
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)
            || @file_put_contents($temporary, $content) !== strlen($content)
            || file_exists($destination) || !@rename($temporary, $destination)) {
            throw new RuntimeException('Could not save the Agent Soul.');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

/** Apply a save, reset, or restore against the revision shown by the editor. */
function agent_brain_soul_update_v1(array $user, string $role, string $revision, string $action, string|int|null $value = null, ?callable $beforePublish = null): int
{
    $directory = agent_brain_soul_directory_v1($user, $role);
    if ($directory === '') throw new InvalidArgumentException('An account is required to edit an Agent Soul.');
    if (preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
        throw new InvalidArgumentException('Open the current Agent Soul before editing.');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create Agent Soul history.');
    }
    if (is_link($directory)) throw new RuntimeException('Could not open Agent Soul history.');
    $lockPath = $directory . '/.edit-lock';
    $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Could not open Agent Soul history.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock Agent Soul history.');
        $current = agent_brain_soul_profile_v1($user, $role);
        if (!hash_equals($current['revision'], $revision)) {
            throw new RuntimeException('This Agent Soul changed in another tab. Reload the current version before editing.');
        }
        switch ($action) {
            case 'save':
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                    throw new InvalidArgumentException('Enter valid text for the Agent Soul.');
                }
                $content = trim(str_replace(["\r\n", "\r"], "\n", $value));
                if (str_contains($content, "\0") || mb_strlen($content) < 20 || mb_strlen($content) > 24000) {
                    throw new InvalidArgumentException('Enter between 20 and 24,000 characters for the Agent Soul.');
                }
                $content .= "\n";
                break;
            case 'reset':
                $content = agent_brain_soul_default_v1($role);
                break;
            case 'restore':
                if (!is_int($value)) throw new InvalidArgumentException('Choose a saved Soul version.');
                $content = agent_brain_soul_saved_version_v1($user, $role, $value);
                break;
            default:
                throw new InvalidArgumentException('Choose a valid Agent Soul action.');
        }
        $version = $current['version'] + 1;
        if ($version > 9999999999) throw new RuntimeException('Agent Soul history reached its version limit.');
        // Callers that coordinate a native review with immutable Soul history
        // can recheck authority and record the exact publication intent while
        // the revision lock is held. A rejected fence must publish no files.
        if ($beforePublish !== null) $beforePublish($current, $version, $content);
        $files = agent_brain_soul_files_v1($directory);
        if ($files === []) {
            agent_brain_soul_publish_v1($directory, 0, $current['content']);
        }
        agent_brain_soul_publish_v1($directory, $version, $content);
        return $version;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
