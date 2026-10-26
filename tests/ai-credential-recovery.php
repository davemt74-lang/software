<?php
declare(strict_types=1);

// Fresh PHP processes exercise filesystem recovery and real transactional SQL.
if (count($argv) === 1) {
    $total = 0;
    foreach (['restore','reconnect','accounts','rollback','publish','uncommitted','crash'] as $case) {
        $root = sys_get_temp_dir().'/vp3-credential-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        try {
            $run = static function (string $mode) use ($root): array {
                $command = [PHP_BINARY];
                if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
                if (ini_get('disable_functions')) array_push($command, '-d', 'disable_functions='.ini_get('disable_functions'));
                array_push($command, __FILE__, $mode, $root);
                $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes);
                $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                return [proc_close($process), $out, $err];
            };
            [$code,$out,$err] = $run($case);
            if ($case === 'crash') {
                if ($code !== 73) throw new RuntimeException('Crash fixture did not reach committed DB state: '.$err);
                [$code,$out,$err] = $run('resume');
            }
            if ($code !== 0) throw new RuntimeException($case.': '.$err.$out);
            $total += (int)$out;
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($root);
        }
    }
    echo 'AI_CREDENTIAL_RECOVERY=PASS ('.$total." assertions; real SQL/filesystem; post-commit crash resumed)\n";
    exit;
}

define('STONEFELLOW_ROOT', $argv[2]);
require dirname(__DIR__).'/includes/ai-settings.php';
require dirname(__DIR__).'/includes/ai-credential-recovery.php';
$checks = 0;
function check(bool $condition, string $why): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($why); }
function rejects(callable $f, string $why): void { try { $f(); } catch (RuntimeException $e) { check(true,$why); return; } throw new RuntimeException($why); }
function setting(string $name, mixed $fallback = ''): mixed {
    $stmt = $GLOBALS['pdo']->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $stmt->execute([$name]);
    $value = $stmt->fetchColumn(); return $value === false ? $fallback : $value;
}
function db(): PDO { return $GLOBALS['pdo']; }
class RecoveryPDO extends PDO {
    public string $mode = '';
    public function commit(): bool {
        $ok = parent::commit();
        if ($this->mode === 'crash') exit(73);
        if ($this->mode === 'publish') file_put_contents(ai_master_key_file(), 'unrelated concurrent file');
        return $ok;
    }
}
$pdo = new RecoveryPDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.db', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$case = $argv[1];
$plain = ['ai_openai_api_key'=>'fixture-openai', 'ai_anthropic_api_key'=>'fixture-claude',
    'ai_elevenlabs_api_key'=>'fixture-elevenlabs', 'hosting_cpanel_api_token'=>'fixture-cpanel'];
if ($case !== 'resume') {
    mkdir(ai_private_dir(),0700);
    $pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE user_llm_credentials(encrypted_key TEXT)');
    $old = random_bytes(32); $saved = [];
    $stmt = $pdo->prepare('INSERT INTO settings VALUES(?,?)');
    foreach ($plain as $name=>$value) { $saved[$name] = ai_encrypt_secret_with_key($value,$old); $stmt->execute([$name,$saved[$name]]); }
    $stmt->execute(['ai_active_provider','anthropic']); $stmt->execute(['ai_anthropic_model','claude-haiku-4-5']);
    $stmt->execute(['ai_elevenlabs_voice_id','unchanged-voice']);
}
$backup = isset($old) ? "<?php\nreturn '".base64_encode($old)."';\n" : '';
switch ($case) {
    case 'restore':
        $account = ai_encrypt_secret_with_key('fixture-personal',$old);
        $pdo->prepare('INSERT INTO user_llm_credentials VALUES(?)')->execute([$account]);
        rejects(fn()=>ai_recovery_restore($pdo,'<?php file_put_contents("'.STONEFELLOW_ROOT.'/executed","bad"); return "bad";'),'Executable PHP upload rejected');
        check(!is_file(STONEFELLOW_ROOT.'/executed'),'Uploaded PHP never executed');
        rejects(fn()=>ai_recovery_restore($pdo,"<?php return '".base64_encode(random_bytes(32))."';"),'Wrong backup key rejected');
        ksort($saved);
        check(!file_exists(ai_master_key_file()) && ai_recovery_saved($pdo) === $saved,'Wrong key leaves file absent and ciphertext unchanged');
        ai_recovery_restore($pdo,$backup);
        $actual = ai_recovery_saved($pdo); ksort($saved); check($actual === $saved,'Backup restore leaves ciphertext exactly unchanged');
        check(ai_provider_api_key('anthropic') === $plain['ai_anthropic_api_key'],'Saved Claude key usable again');
        check(ai_decrypt_secret(setting('ai_elevenlabs_api_key')) === $plain['ai_elevenlabs_api_key'],'Saved ElevenLabs key usable again');
        check(ai_decrypt_secret($account) === 'fixture-personal','Personal account restored too');
        rejects(fn()=>ai_recovery_restore($pdo,$backup),'Existing installation key not overwritten');
        check((fileperms(ai_master_key_file()) & 0777) === 0600,'Restored key owner-readable only');
        break;
    case 'accounts':
        $pdo->prepare('INSERT INTO user_llm_credentials VALUES(?)')->execute([ai_encrypt_secret_with_key('personal',$old)]);
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,true),'Cannot strand account credentials');
        check(!is_file(ai_master_key_file()),'Account protection creates no replacement key');
        ksort($saved); check(ai_recovery_saved($pdo) === $saved,'Account protection retains all database values');
        break;
    case 'rollback':
        $pdo->exec("CREATE TRIGGER fail_update BEFORE UPDATE ON settings WHEN NEW.setting_key='ai_elevenlabs_api_key' BEGIN SELECT RAISE(ABORT,'fixture transaction failure'); END");
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,true),'Database failure propagated');
        ksort($saved); check(ai_recovery_saved($pdo) === $saved,'All original credentials survive failed transaction');
        check(!is_file(ai_master_key_file()) && !is_file(ai_private_dir().'/.ai-credential-recovery-key.php') && !is_file(ai_private_dir().'/.ai-credential-recovery.json'),'Failed transaction leaves no installation key or pending operation');
        check((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'ai_credential_recovery_snapshot_%'")->fetchColumn()===0,'Snapshot also rolled back');
        break;
    case 'publish':
        $pdo->mode = 'publish';
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,true),'Concurrent key prevents publication');
        check(file_get_contents(ai_master_key_file())==='unrelated concurrent file','Concurrent file never overwritten');
        check(is_file(ai_private_dir().'/.ai-credential-recovery.json'),'Committed recovery retained for retry');
        unlink(ai_master_key_file()); $pdo->mode = '';
        ai_recovery_reconnect($pdo,[],true);
        check(ai_provider_api_key('anthropic') === $plain['ai_anthropic_api_key'],'Retry resumes committed operation without re-entry');
        break;
    case 'uncommitted':
        ai_recovery_write_private(ai_private_dir().'/.ai-credential-recovery-key.php',$backup);
        ai_recovery_write_private(ai_private_dir().'/.ai-credential-recovery.json',json_encode(['id'=>'uncommitted']));
        ai_recovery_reconnect($pdo,$plain,true);
        check(ai_provider_api_key('anthropic') === $plain['ai_anthropic_api_key'],'Uncommitted interrupted operation can be retried');
        break;
    case 'crash':
        $pdo->mode = 'crash'; ai_recovery_reconnect($pdo,$plain,true); throw new RuntimeException('Expected process crash');
    case 'resume':
        check(!is_file(ai_master_key_file()) && is_file(ai_private_dir().'/.ai-credential-recovery.json'),'Post-commit crash retains pending recovery');
        ai_recovery_reconnect($pdo,[],true);
        foreach ($plain as $name=>$value) check(ai_decrypt_secret(setting($name)) === $value,'Resumed saved credential '.$name);
        break;
    case 'reconnect':
        rejects(fn()=>ai_master_key(true),'Ordinary saves cannot silently rotate a lost key');
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,false),'Explicit reconnection consent required');
        $partial = $plain; unset($partial['ai_elevenlabs_api_key']);
        rejects(fn()=>ai_recovery_reconnect($pdo,$partial,true),'All existing site credentials required together');
        ksort($saved); check(ai_recovery_saved($pdo) === $saved && !is_file(ai_master_key_file()),'Rejected input does not change saved keys');
        $lock = ai_recovery_lock();
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,true),'Parallel recovery rejected');
        flock($lock,LOCK_UN); fclose($lock);
        ai_recovery_reconnect($pdo,$plain,true);
        foreach ($plain as $name=>$value) check(ai_decrypt_secret(setting($name)) === $value,'Reconnected '.$name);
        $snapshot = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'ai_credential_recovery_snapshot_%'")->fetchColumn(),true);
        check($snapshot['credentials'] === $saved,'Original encrypted database values retained exactly');
        check(setting('ai_active_provider')==='anthropic' && setting('ai_anthropic_model')==='claude-haiku-4-5' && setting('ai_elevenlabs_voice_id')==='unchanged-voice','Provider model and voice choices retained');
        $rows = json_encode($pdo->query('SELECT * FROM settings')->fetchAll(PDO::FETCH_ASSOC));
        foreach ($plain as $value) check(!str_contains($rows,$value),'Plaintext key not stored in DB or snapshot');
        check(!is_file(ai_private_dir().'/.ai-credential-recovery.json') && !is_file(ai_private_dir().'/.ai-credential-recovery-key.php'),'Completed staging cleaned');
        rejects(fn()=>ai_recovery_reconnect($pdo,$plain,true),'Working installation key not replaced');
        // Repeated loss can reconnect without destroying the earlier snapshot.
        unlink(ai_master_key_file()); ai_recovery_reconnect($pdo,$plain,true);
        check((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'ai_credential_recovery_snapshot_%'")->fetchColumn()===2,'Recovery history survives a later key loss');
        break;
}
$page = file_get_contents(dirname(__DIR__).'/admin/ai.php');
check(strpos($page,"require_permission('ai.manage')") < strpos($page,"if (\$action === 'restore_credentials')"),'Recovery action behind admin permission gate');
check(strpos($page,'if (!verify_csrf())') < strpos($page,"if (\$action === 'reconnect_credentials')"),'Recovery action behind POST CSRF gate');
echo $checks;
