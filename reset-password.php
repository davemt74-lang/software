<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

$token = strtolower(trim((string)($_GET['token'] ?? $_POST['token'] ?? '')));
$record = password_reset_token_record($token);
$error = '';
$complete = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$record) {
        $error = 'This reset link is invalid or has expired.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (strlen($password) < 12) {
            $error = 'Use a password with at least 12 characters.';
        } elseif (strlen($password) > 4096) {
            $error = 'Your password is too long.';
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'The password confirmation does not match.';
        } elseif (!password_reset_complete($token, $password)) {
            $error = 'This reset link is invalid or has expired.';
        } else {
            $complete = true;
            $record = null;
        }
    }
}

vp3_public_header('Choose a new password — VP3', 'Choose a new password for your VP3 account.', ['active'=>'login','compact'=>true,'body_class'=>'vp3-auth-page','robots'=>'noindex,nofollow']);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">VP3 account security</div>
      <h1>A fresh key to your private workspace.</h1>
      <p>Your assistant, transcriptions, knowledge, projects, profile, and team data remain connected to the same account.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <?php if ($complete): ?>
        <div class="vp3-kicker">Password updated</div>
        <h1>You’re ready to sign in.</h1>
        <p class="vp3-auth-intro">Your old reset link has been invalidated.</p>
        <div class="vp3-alert success">Your VP3 password was changed successfully.</div>
        <a class="vp3-btn primary full" href="<?= e(url('/login.php')) ?>">Sign in to VP3 →</a>
      <?php elseif (!$record): ?>
        <div class="vp3-kicker">Reset link unavailable</div>
        <h1>Request a new link.</h1>
        <p class="vp3-auth-intro">This link is invalid, expired, or has already been used.</p>
        <a class="vp3-btn primary full" href="<?= e(url('/forgot-password.php')) ?>">Request another reset link →</a>
      <?php else: ?>
        <div class="vp3-kicker">Choose a new password</div>
        <h1>Protect your VP3 account.</h1>
        <p class="vp3-auth-intro">Use at least 12 characters.</p>
        <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="vp3-auth-form" method="post" action="<?= e(url('/reset-password.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="vp3-field"><label for="password">New password</label><div class="vp3-password-wrap"><input id="password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required><button class="vp3-password-toggle" type="button" data-password-toggle="password">Show</button></div></div>
          <div class="vp3-field"><label for="password_confirmation">Confirm password</label><div class="vp3-password-wrap"><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required><button class="vp3-password-toggle" type="button" data-password-toggle="password_confirmation">Show</button></div></div>
          <button class="vp3-btn primary full" type="submit">Update password →</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
<?php vp3_public_footer(); ?>
