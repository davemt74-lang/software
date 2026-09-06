<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';

if (is_logged_in()) redirect(login_destination());

$error = '';
$displayName = trim((string)($_POST['display_name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $error = 'Your account could not be created. Please try again.';
    } elseif ((string)($_POST['accept_terms'] ?? '') !== '1') {
        $error = 'Please agree to the Terms of Service and Privacy Policy.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            $error = 'Enter your name using 120 characters or fewer.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 12) {
            $error = 'Use a password with at least 12 characters.';
        } elseif (strlen($password) > 4096) {
            $error = 'Your password is too long.';
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'The password confirmation does not match.';
        } else {
            $pdo = db();
            if (!$pdo) {
                $error = 'Account registration is unavailable until the database is configured.';
            } else {
                try {
                    if (!subscription_schema_ready($pdo)) subscription_ensure_schema($pdo);
                    $exists = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                    $exists->execute([$email]);
                    if ($exists->fetchColumn()) throw new RuntimeException('An account with that email already exists.');
                    $pdo->beginTransaction();
                    // users.role remains a compatibility identity column while packages become
                    // the public account type and commercial permission source.
                    $insert = $pdo->prepare("INSERT INTO users (email,password_hash,display_name,role,is_active,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW())");
                    $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $displayName, 'fan']);
                    $userId = (int)$pdo->lastInsertId();
                    if (table_exists('user_account_types')) {
                        $type = $pdo->prepare('INSERT IGNORE INTO user_account_types (user_id,role) VALUES (?,?)');
                        $type->execute([$userId, 'fan']);
                    }
                    $pdo->commit();
                    subscription_assign_default_trial($userId);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['subscription_onboarding'] = 1;
                    reset_current_user_cache();
                    $trial = subscription_current_for_user_id($userId);
                    $tokens = $trial ? subscription_package_allowance($trial) : 0;
                    flash('notice', $tokens > 0
                        ? 'Welcome to VP3. Your Free Trial includes ' . number_format($tokens) . ' AI tokens.'
                        : 'Welcome to VP3. Your Free Trial is ready.');
                    redirect(login_destination());
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('VP3 public signup failed: ' . $e->getMessage());
                    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Your account could not be created. Please try again.';
                }
            }
        }
    }
}

vp3_public_header('Create account — VP3', 'Create your VP3 personal AI assistant account.', ['active'=>'signup','compact'=>true,'body_class'=>'vp3-auth-page']);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">Capture. Understand. Take action.</div>
      <h1>Build an assistant around your life and work.</h1>
      <p>Create your account and start with a Free Trial. Your assistant will guide setup and recommend the right package as you use VP3.</p>
      <div class="vp3-auth-points">
        <div class="vp3-auth-point"><b>Start free</b>No package decision is required before you can explore VP3.</div>
        <div class="vp3-auth-point"><b>Included AI tokens</b>Your Free Trial includes a limited AI token balance so you can use the assistant immediately.</div>
        <div class="vp3-auth-point"><b>Guided onboarding</b>Your assistant helps configure the features included in your package and explains upgrades only when relevant.</div>
      </div>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Create your account</div>
      <h1>Get started.</h1>
      <p class="vp3-auth-intro">Create one VP3 account. Your Free Trial is assigned automatically.</p>
      <?php if (!db_ready()): ?><div class="vp3-alert">Account registration is unavailable until the database is configured.</div><?php endif; ?>
      <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form class="vp3-auth-form" method="post" action="<?= e(url('/signup.php')) ?>">
        <?= csrf_field() ?>
        <div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <div class="vp3-field"><label for="display_name">Full name</label><input id="display_name" name="display_name" maxlength="120" autocomplete="name" required placeholder="Your name" value="<?= e($displayName) ?>"></div>
        <div class="vp3-field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required placeholder="you@example.com" value="<?= e($email) ?>"></div>
        <div class="vp3-field"><label for="password">Create password</label><div class="vp3-password-wrap"><input id="password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required placeholder="12+ characters"><button class="vp3-password-toggle" type="button" data-password-toggle="password">Show</button></div><p class="vp3-form-note">Use at least 12 characters.</p></div>
        <div class="vp3-field"><label for="password_confirmation">Confirm password</label><div class="vp3-password-wrap"><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required placeholder="Repeat your password"><button class="vp3-password-toggle" type="button" data-password-toggle="password_confirmation">Show</button></div></div>
        <label class="vp3-check"><input type="checkbox" name="accept_terms" value="1" required><span>I agree to the <a class="vp3-text-link" href="<?= e(url('/terms.php')) ?>">Terms of Service</a> and <a class="vp3-text-link" href="<?= e(url('/privacy.php')) ?>">Privacy Policy</a>.</span></label>
        <button class="vp3-btn primary full" type="submit">Create account →</button>
      </form>
      <div class="vp3-auth-foot">Already have an account? <a class="vp3-text-link" href="<?= e(url('/login.php')) ?>">Sign in</a></div>
    </div>
  </section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
<?php vp3_public_footer(); ?>