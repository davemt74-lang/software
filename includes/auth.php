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
        // SELECT * keeps this compatible with installations that have not run upgrade.php yet.
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
    if ($email === '' || $password === '') {
        return false;
    }

    $now = time();
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts = array_values(array_filter(
        $attempts,
        static fn($time): bool => is_int($time) && $time > ($now - 900)
    ));

    if (count($attempts) >= 5) {
        $_SESSION['login_attempts'] = $attempts;
        return false;
    }

    $pdo = db();
    if (!$pdo) {
        return false;
    }

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
            error_log('Stonefellow last login update failed: ' . $e->getMessage());
        }
    }

    return true;
}

function login_destination(): string
{
    $user = current_user();

    if (!$user) {
        return url('/index.php');
    }

    /*
     * Agent Chat is the authenticated home screen. Account-type workspaces
     * remain available from the profile menu/sidebar after login.
     */
    if (has_permission('chat.access', $user)) {
        return url('/chat.php');
    }

    if (
        user_has_role('producer', $user) &&
        has_permission('producer.access', $user)
    ) {
        return url('/admin/producer-tracks.php');
    }

    if (has_permission('admin.access', $user)) {
        return url('/admin/index.php');
    }

    if (has_permission('investor.access', $user)) {
        return url('/investor.php');
    }

    if (has_permission('account.access', $user)) {
        return url('/account.php');
    }

    return url('/chat.php');
}

function redirect_logged_in_public_page(): void
{
    if (!is_logged_in()) {
        return;
    }

    redirect(login_destination());
}


function logout_user(): void
{
    $user = current_user();
    if ($user && function_exists('agent_activity_v101_logout')) {
        agent_activity_v101_logout($user);
    }
    unset($_SESSION['user_id']);
    reset_current_user_cache();
    session_regenerate_id(true);
}
