<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';

if (is_logged_in()) redirect(login_destination());
$error = flash('error');
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (login_attempt($email, $password)) redirect(login_destination());
        $error = 'Invalid email or password, or too many recent attempts.';
    }
}

vp3_public_header('Sign in — VP3', 'Sign in to your VP3 personal AI assistant.', ['active'=>'login','compact'=>true,'body_class'=>'vp3-auth-page']);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">Your life. Your assistant.</div>
      <h1>Pick up where you left off.</h1>
      <p>Return to your conversations, transcriptions, second brain, personal URL, projects, contacts, and team activity.</p>
      <div class="vp3-auth-points">
        <div class="vp3-auth-point"><b>One connected context</b>Your assistant carries forward the work, knowledge, and decisions that matter.</div>
        <div class="vp3-auth-point"><b>Capture to action</b>Turn recordings and conversations into summaries, knowledge, and next steps.</div>
        <div class="vp3-auth-point"><b>Private by design</b>Your personal workspace stays permission-aware and under your control.</div>
      </div>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Welcome back</div>
      <h1>Sign in to VP3.</h1>
      <p class="vp3-auth-intro">Open your personal assistant and continue your work.</p>
      <?php if (!db_ready()): ?><div class="vp3-alert">The database is not configured yet. Sign-in will be available after setup is complete.</div><?php endif; ?>
      <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e((string)$error) ?></div><?php endif; ?>
      <form class="vp3-auth-form" method="post" action="<?= e(url('/login.php')) ?>">
        <?= csrf_field() ?>
        <div class="vp3-field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="username" required placeholder="you@example.com" value="<?= e($email) ?>"></div>
        <div class="vp3-field"><label for="password">Password</label><div class="vp3-password-wrap"><input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password"><button class="vp3-password-toggle" type="button" data-password-toggle="password">Show</button></div></div>
        <div class="vp3-form-row"><span>Secure session</span><a class="vp3-text-link" href="<?= e(url('/forgot-password.php')) ?>">Forgot password?</a></div>
        <button class="vp3-btn primary full" type="submit">Sign in →</button>
      </form>
      <div class="vp3-auth-foot">New to VP3? <a class="vp3-text-link" href="<?= e(url('/signup.php')) ?>">Create an account</a></div>
      <div class="vp3-legal-mini">By continuing, you agree to the <a class="vp3-text-link" href="<?= e(url('/terms.php')) ?>">Terms</a> and <a class="vp3-text-link" href="<?= e(url('/privacy.php')) ?>">Privacy Policy</a>.</div>
    </div>
  </section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
<?php vp3_public_footer(); ?>
