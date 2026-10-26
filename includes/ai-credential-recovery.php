<?php
declare(strict_types=1);

/** Administrator-only recovery; caller enforces ai.manage and POST CSRF. */
function ai_recovery_labels(): array
{
    return ['ai_openai_api_key'=>'OpenAI', 'ai_anthropic_api_key'=>'Claude / Anthropic',
        'ai_elevenlabs_api_key'=>'ElevenLabs', 'hosting_cpanel_api_token'=>'cPanel'];
}

function ai_recovery_saved(PDO $pdo, bool $lock = false): array
{
    $names = array_keys(ai_recovery_labels());
    $sql = 'SELECT setting_key,setting_value FROM settings WHERE setting_key IN (?,?,?,?)';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql); $stmt->execute($names);
    $saved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (trim((string)$row['setting_value']) !== '') $saved[$row['setting_key']] = $row['setting_value'];
    }
    ksort($saved);
    return $saved;
}

function ai_recovery_account_secrets(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT encrypted_key FROM user_llm_credentials')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // An installation predating account BYOK may not have this table.
        if (($e->errorInfo[0] ?? '') === '42S02'
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($e->getMessage(), 'no such table: user_llm_credentials'))) return [];
        throw $e;
    }
}

function ai_recovery_parse_key(string $file): string
{
    // Accept only our generated return-literal format. Never require/eval uploads.
    if (strlen($file) > 1024 || !preg_match('~\A\s*<\?php\s+return\s+([\x27\x22])([A-Za-z0-9+/]{43}=)\1\s*;\s*\z~D', $file, $match)) {
        throw new RuntimeException('Select the original ai-key.php file from this site’s backup.');
    }
    $key = base64_decode($match[2], true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('The recovery file is not a valid encryption key.');
    return $key;
}

function ai_recovery_write_private(string $path, string $bytes): void
{
    $old = umask(0077);
    try { $handle = fopen($path, 'x'); } finally { umask($old); }
    if ($handle === false) throw new RuntimeException('Could not create the private recovery file. Check this site’s private folder permissions.');
    try {
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Could not safely save credential recovery.');
        }
    } catch (Throwable $e) { fclose($handle); unlink($path); throw $e; }
    fclose($handle);
}

function ai_recovery_lock(): mixed
{
    $dir = ai_private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not open private recovery storage.');
    $old = umask(0077);
    try { $lock = fopen($dir.'/.ai-credential-recovery.lock', 'c'); } finally { umask($old); }
    if (!$lock) throw new RuntimeException('Could not open private recovery storage.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Credential recovery is already running. Retry shortly.');
    }
    return $lock;
}

function ai_recovery_publish(string $staged): void
{
    if (!function_exists('link')) throw new RuntimeException('The server needs PHP link support to publish the recovered key safely. Recovery data was retained.');
    $path = ai_master_key_file();
    // link is atomic and refuses to overwrite an existing installation key.
    if (!@link($staged, $path)) {
        if (!is_file($path) || file_get_contents($path) !== file_get_contents($staged)) {
            throw new RuntimeException('The installation key changed during recovery. No existing key was replaced.');
        }
    }
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
}

function ai_recovery_restore(PDO $pdo, string $uploaded): void
{
    $key = ai_recovery_parse_key($uploaded);
    $lock = ai_recovery_lock();
    $stage = ai_private_dir().'/.ai-restore-'.bin2hex(random_bytes(8)).'.php';
    try {
        if (file_exists(ai_master_key_file()) || is_link(ai_master_key_file())) throw new RuntimeException('An installation key already exists. It was not replaced.');
        if (is_file(ai_private_dir().'/.ai-credential-recovery.json')) throw new RuntimeException('Finish the pending reconnection first.');
        $secrets = array_merge(array_values(ai_recovery_saved($pdo)), ai_recovery_account_secrets($pdo));
        if (!$secrets) throw new RuntimeException('No saved credentials are available to validate this backup key.');
        foreach ($secrets as $secret) {
            if (ai_decrypt_secret_with_key((string)$secret, $key) === '') throw new RuntimeException('This backup key cannot unlock all of this site’s saved credentials. Nothing was changed.');
        }
        ai_recovery_write_private($stage, "<?php\nreturn ".var_export(base64_encode($key), true).";\n");
        ai_recovery_publish($stage);
    } finally {
        if (is_file($stage)) unlink($stage);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Finish a committed reconnection after a process crash or filesystem error. */
function ai_recovery_finish_pending(PDO $pdo): bool
{
    $journal = ai_private_dir().'/.ai-credential-recovery.json';
    if (!is_file($journal)) return false;
    $pending = json_decode((string)file_get_contents($journal), true, 16, JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $stmt->execute(['ai_credential_recovery_snapshot_'.(string)($pending['id'] ?? '')]);
    $snapshot = json_decode((string)$stmt->fetchColumn(), true);
    $stage = ai_private_dir().'/.ai-credential-recovery-key.php';
    if (($snapshot['id'] ?? '') === ($pending['id'] ?? null)) {
        // Committed DB credentials and staged key must still match exactly.
        $key = ai_recovery_parse_key((string)file_get_contents($stage));
        foreach (ai_recovery_saved($pdo) as $secret) {
            if (ai_decrypt_secret_with_key($secret, $key) === '') throw new RuntimeException('Saved credentials changed while recovery was pending. Recovery data was retained.');
        }
        ai_recovery_publish($stage);
        unlink($journal); unlink($stage);
        return true;
    }
    // No commit: original DB values remain; remove only our uncommitted stage.
    unlink($journal);
    if (is_file($stage)) unlink($stage);
    return false;
}

function ai_recovery_reconnect(PDO $pdo, array $input, bool $confirmed): void
{
    if (!$confirmed) throw new RuntimeException('Confirm that the original encryption file cannot be recovered.');
    if ($pdo->inTransaction()) throw new RuntimeException('Finish the current database operation before recovery.');
    $lock = ai_recovery_lock();
    $stage = ai_private_dir().'/.ai-credential-recovery-key.php';
    $journal = ai_private_dir().'/.ai-credential-recovery.json';
    try {
        if (ai_recovery_finish_pending($pdo)) return;
        if (!function_exists('link')) throw new RuntimeException('The server needs PHP link support for credential recovery. No saved credentials were changed.');
        if (file_exists(ai_master_key_file()) || is_link(ai_master_key_file())) throw new RuntimeException('Reconnection is available only when the installation encryption file is missing.');
        if (ai_recovery_account_secrets($pdo)) throw new RuntimeException('Personal account keys also use the missing file. Restore the original file to preserve those accounts; site reconnection has not changed anything.');
        $saved = ai_recovery_saved($pdo);
        if (!$saved) throw new RuntimeException('No saved credentials require recovery.');
        $key = random_bytes(32); $replacement = [];
        foreach ($saved as $name => $cipher) {
            $plain = trim((string)($input[$name] ?? ''));
            if ($plain === '' || strlen($plain) > 4000 || preg_match('/[\x00-\x20\x7f]/', $plain)) throw new RuntimeException('Enter the existing '.ai_recovery_labels()[$name].' API key to reconnect all saved services together.');
            $replacement[$name] = ai_encrypt_secret_with_key($plain, $key);
        }
        $id = bin2hex(random_bytes(16));
        // Staged key + journal are durable before the transaction begins.
        if (is_file($stage)) unlink($stage); // Uncommitted orphan, protected by recovery lock.
        ai_recovery_write_private($stage, "<?php\nreturn ".var_export(base64_encode($key), true).";\n");
        ai_recovery_write_private($journal, json_encode(['id'=>$id], JSON_THROW_ON_ERROR));
        $pdo->beginTransaction();
        try {
            if (ai_recovery_saved($pdo, true) !== $saved || ai_recovery_account_secrets($pdo)) throw new RuntimeException('Saved credentials changed. Reload and retry recovery.');
            // Preserve original ciphertext for later recovery; never store plaintext.
            $snapshot = json_encode(['id'=>$id, 'created_at'=>gmdate('c'), 'credentials'=>$saved], JSON_THROW_ON_ERROR);
            $stmt = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)');
            $stmt->execute(['ai_credential_recovery_snapshot_'.$id, $snapshot]);
            $stmt = $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?');
            foreach ($replacement as $name=>$value) $stmt->execute([$value,$name]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A lost connection during COMMIT has an uncertain outcome. Keep
            // the staged key: the next request checks the durable DB snapshot
            // to distinguish committed recovery from an uncommitted attempt.
            throw $e;
        }
        ai_recovery_finish_pending($pdo);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
