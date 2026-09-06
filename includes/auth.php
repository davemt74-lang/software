<?php
declare(strict_types=1);

function reset_current_user_cache(): void
{
    unset($GLOBALS['__stonefellow_current_user']);
}

function current_user(): ?array
{
    if (array_key_exists('__stonefellow_current_user', $GLOBALS)) {
        $cached = $GLOBALS['__stonefellow_current_user'];
        return is_array($cached) ? $cached : null;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        $GLOBALS['__stonefellow_current_user'] = null;
        return null;
    }

    $pdo = db();
    if (!$pdo) {
        $GLOBALS['__stonefellow_current_user'] = null;
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['user_id']);
            $GLOBALS['__stonefellow_current_user'] = null;
            return null;
        }

        if (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1) {
            unset($_SESSION['user_id']);
            $GLOBALS['__stonefellow_current_user'] = null;
            return null;
        }

        $primaryRole = (string)$user['role'];
        $current = [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'display_name' => (string)$user['display_name'],
            'role' => $primaryRole,
            'roles' => user_account_types_for_user_id((int)$user['id'], $primaryRole),
            'avatar_path' => (string)($user['avatar_path'] ?? ''),
            'is_active' => array_key_exists('is_active', $user) ? (int)$user['is_active'] : 1,
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
        $GLOBALS['__stonefellow_current_user'] = $current;
        return $current;
    } catch (Throwable $e) {
        $GLOBALS['__stonefellow_current_user'] = null;
        return null;
    }
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please sign in to continue.');
        redirect(url('/login.php'));
    }
}

function login_attempt(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') return false;

    $now = time();
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > ($now - 900)));
    if (count($attempts) >= 5) {
        $_SESSION['login_attempts'] = $attempts;
        return false;
    }

    $pdo = db();
    if (!$pdo) return false;

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $inactive = $user && array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1;

    if (!$user || $inactive || !password_verify($password, (string)$user['password_hash'])) {
        $attempts[] = $now;
        $_SESSION['login_attempts'] = $attempts;
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['login_attempts'] = [];
    reset_current_user_cache();

    if (column_exists('users', 'last_login_at')) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
            $stmt->execute([(int)$user['id']]);
        } catch (Throwable $e) {
            error_log('VP3 last login update failed: ' . $e->getMessage());
        }
    }
    return true;
}

function login_destination(): string
{
    $user = current_user();
    if (!$user) return url('/index.php');
    if (has_permission('chat.access', $user)) return url('/chat.php');
    if (user_has_role('producer', $user) && has_permission('producer.access', $user)) return url('/admin/producer-tracks.php');
    if (has_permission('admin.access', $user)) return url('/admin/index.php');
    if (has_permission('investor.access', $user)) return url('/investor.php');
    if (has_permission('account.access', $user)) return url('/account.php');
    return url('/chat.php');
}

function redirect_logged_in_public_page(): void
{
    if (is_logged_in()) redirect(login_destination());
}

function logout_user(): void
{
    $user = current_user();
    if ($user && function_exists('agent_activity_v101_logout')) agent_activity_v101_logout($user);
    unset($_SESSION['user_id']);
    reset_current_user_cache();
    session_regenerate_id(true);
}

function password_reset_schema_ready(): bool
{
    return table_exists('password_reset_tokens');
}

function password_reset_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database unavailable.');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            INDEX idx_password_reset_user (user_id, created_at),
            INDEX idx_password_reset_expiry (expires_at, used_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function password_reset_request(string $email): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !password_reset_schema_ready()) return;

    $now = time();
    $attempts = $_SESSION['password_reset_attempts'] ?? [];
    $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > ($now - 3600)));
    if (count($attempts) >= 5) {
        $_SESSION['password_reset_attempts'] = $attempts;
        return;
    }
    $attempts[] = $now;
    $_SESSION['password_reset_attempts'] = $attempts;

    $pdo = db();
    if (!$pdo) return;
    $stmt = $pdo->prepare('SELECT id,email,is_active FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1)) return;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    try {
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,request_ip) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE),?)'
        );
        $insert->execute([(int)$user['id'], $hash, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('VP3 password reset token creation failed: ' . $e->getMessage());
        return;
    }

    $sender = (string)setting('contact_email', (string)site_config('email', ''));
    $enabled = (bool)site_config('send_password_reset_email', (bool)site_config('send_contact_email', false));
    if (!$enabled || !filter_var($sender, FILTER_VALIDATE_EMAIL)) return;

    $resetUrl = url('/reset-password.php?token=' . rawurlencode($token));
    $subject = 'VP3 password reset';
    $body = "A password reset was requested for your VP3 account.\n\nReset your password within 60 minutes:\n{$resetUrl}\n\nIf you did not request this, you can ignore this email.";
    $headers = ['From: ' . $sender, 'Reply-To: ' . $sender, 'Content-Type: text/plain; charset=UTF-8'];
    @mail((string)$user['email'], $subject, $body, implode("\r\n", $headers));
}

function password_reset_token_record(string $token): ?array
{
    if (!password_reset_schema_ready() || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $pdo = db();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare(
            'SELECT prt.id,prt.user_id,prt.expires_at,u.email
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id=prt.user_id
             WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>NOW() AND u.is_active=1
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function password_reset_complete(string $token, string $password): bool
{
    if (strlen($password) < 12 || strlen($password) > 4096) return false;
    $record = password_reset_token_record($token);
    if (!$record) return false;
    $pdo = db();
    if (!$pdo) return false;
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?')->execute([
            password_hash($password, PASSWORD_DEFAULT),
            (int)$record['user_id'],
        ]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$record['user_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('VP3 password reset failed: ' . $e->getMessage());
        return false;
    }
}
