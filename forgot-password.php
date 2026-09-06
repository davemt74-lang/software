<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } else {
        password_reset_request($email);
        $sent = true;
    }
}

vp3_public_header('Reset password — VP3', 'Request a secure VP3 password reset link.', ['active'=>'login','compact'=>true,'body_class'=>'vp3-auth-page']);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">Secure account recovery</div>
      <h1>Get back to your assistant.</h1>
      <p>Request a one-time reset link and return to your conversations, transcriptions, knowledge, projects, and team activity.</p>
      <div class="vp3-auth-points">
        <div class="vp3-auth-point"><b>One-time link</b>Reset tokens expire after 60 minutes and can only be used once.</div>
        <div class="vp3-auth-point"><b>No account disclosure</b>The recovery response is the same whether an email exists or not.</div>
        <div class="vp3-auth-point"><b>Private by design</b>Your existing knowledge and assistant history stay attached to your account.</div>
      </div>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Password recovery</div>
      <h1>Reset your password.</h1>
      <p class="vp3-auth-intro">Enter the email associated with your VP3 account.</p>
      <?php if ($sent): ?>
        <div class="vp3-alert success">If an active VP3 account matches that email and email delivery is configured, a reset link has been sent. Check your inbox and spam folder.</div>
        <a class="vp3-btn full" href="<?= e(url('/login.php')) ?>">Back to sign in</a>
      <?php else: ?>
        <?php if (!password_reset_schema_ready()): ?><div class="vp3-alert">Password recovery needs the current database upgrade before reset links can be issued.</div><?php endif; ?>
        <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="vp3-auth-form" method="post" action="<?= e(url('/forgot-password.php')) ?>">
          <?= csrf_field() ?>
          <div class="vp3-field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required placeholder="you@example.com" value="<?= e($email) ?>"></div>
          <button class="vp3-btn primary full" type="submit">Send reset link →</button>
        </form>
        <div class="vp3-auth-foot">Remembered it? <a class="vp3-text-link" href="<?= e(url('/login.php')) ?>">Sign in</a></div>
      <?php endif; ?>
    </div>
  </section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
<?php vp3_public_footer(); ?>
