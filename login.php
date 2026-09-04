<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(login_destination());
}

$error = flash('error');
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (login_attempt($email, $password)) {
            redirect(login_destination());
        }
        $error = 'Invalid email or password, or too many recent attempts.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Sign in to Stonefellow.">
<title>Sign in — Stonefellow</title>
<link rel="stylesheet" href="<?= e(url('/auth.css?v=180')) ?>">
</head>
<body>
<header class="site-header">
  <div class="wrap nav">
    <a class="brand" href="<?= e(url('/index.php')) ?>" aria-label="Stonefellow home"><span class="brand-mark"><span>S</span></span><span>Stonefellow</span></a>
    <nav class="nav-links" aria-label="Primary navigation">
      <a href="<?= e(url('/index.php#product')) ?>">Product</a>
      <a href="<?= e(url('/index.php#roles')) ?>">Solutions</a>
      <a href="<?= e(url('/index.php#resources')) ?>">Resources</a>
      <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a href="<?= e(url('/book-demo.php')) ?>">Book a demo</a>
    </nav>
    <div class="nav-actions">
      <a class="nav-login" href="<?= e(url('/login.php')) ?>">Sign in</a>
      <a class="btn btn-primary" href="<?= e(url('/signup.php')) ?>">Create account</a>
      <button class="menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="mobileMenu" aria-expanded="false">☰</button>
    </div>
  </div>
  <div class="mobile-menu-overlay" id="mobileMenuOverlay" aria-hidden="true"></div>
  <aside class="mobile-menu" id="mobileMenu" aria-hidden="true">
    <div class="mobile-menu-head"><a class="brand" href="<?= e(url('/index.php')) ?>"><span class="brand-mark"><span>S</span></span><span>Stonefellow</span></a><button class="mobile-menu-close" id="menuClose" aria-label="Close menu">×</button></div>
    <nav class="mobile-menu-links" aria-label="Mobile navigation">
      <a href="<?= e(url('/index.php#product')) ?>">Product <span>→</span></a>
      <a href="<?= e(url('/index.php#roles')) ?>">Solutions <span>→</span></a>
      <a href="<?= e(url('/pricing.php')) ?>">Pricing <span>→</span></a>
      <a href="<?= e(url('/book-demo.php')) ?>">Book a demo <span>→</span></a>
    </nav>
    <div class="mobile-menu-actions"><a class="btn btn-light" href="<?= e(url('/signup.php')) ?>">Create account</a><a class="btn btn-secondary" href="<?= e(url('/login.php')) ?>">Sign in</a></div>
  </aside>
</header>
<main class="auth-shell">
  <section class="auth-visual">
    <div class="eyebrow">AI Studio Assistant</div>
    <h1>Pick up where the session left off.</h1>
    <p>Return to your active projects, stems, notes, rights, releases and team activity in one workspace.</p>
    <div class="auth-points">
      <div class="auth-point"><b>Your latest project first</b>Return to the most recent work without hunting through folders.</div>
      <div class="auth-point"><b>One operational context</b>Keep stems, release tasks and team decisions connected.</div>
      <div class="auth-point"><b>No creative generation</b>Stonefellow works with the music and assets you provide.</div>
    </div>
    <div class="studio-card" aria-hidden="true">
      <div class="studio-top"><b>STONEFELLOW · ACTIVE PROJECT</b><span>AI STUDIO ASSISTANT ONLINE</span></div>
      <div class="studio-grid">
        <div class="studio-side"><div class="active">Project</div><div>Stems</div><div>Sessions</div><div>Rights</div><div>Releases</div><div>Team</div></div>
        <div class="studio-tracks">
          <div class="track"><span>Vocal</span><i class="wave"></i></div><div class="track"><span>Drums</span><i class="wave" style="background:linear-gradient(90deg,#4f8cff 0 74%,#242936 74%)"></i></div><div class="track"><span>Bass</span><i class="wave" style="background:linear-gradient(90deg,#49c98d 0 57%,#242936 57%)"></i></div><div class="track"><span>Guitar</span><i class="wave" style="background:linear-gradient(90deg,#d69b44 0 84%,#242936 84%)"></i></div><div class="track"><span>Keys</span><i class="wave" style="background:linear-gradient(90deg,#d66384 0 68%,#242936 68%)"></i></div><div class="track"><span>FX</span><i class="wave" style="background:linear-gradient(90deg,#58b3b8 0 49%,#242936 49%)"></i></div>
        </div>
        <div class="studio-aside"><b style="color:#fff">Assistant tasks</b><div class="studio-task">Review split sheet</div><div class="studio-task">Confirm master version</div><div class="studio-task">Prepare metadata</div><div class="studio-task">Release due Friday</div></div>
      </div>
    </div>
  </section>
  <section class="auth-form-side">
    <div class="auth-card">
      <div class="eyebrow">Welcome back</div>
      <h2>Sign in to Stonefellow</h2>
      <p class="auth-intro">Access your studio assistant and continue your current workflow.</p>
      <?php if (!db_ready()): ?><div class="db-warning">The database is not configured yet. Sign-in will be available after setup is complete.</div><br><?php endif; ?>
      <?php if ($error): ?><div class="auth-error" role="alert"><?= e((string)$error) ?></div><br><?php endif; ?>
      <form class="auth-form" method="post" action="<?= e(url('/login.php')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="username" required placeholder="you@example.com" value="<?= e($email) ?>"></div>
        <div class="field"><label for="password">Password</label><div class="password-wrap"><input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password"><button class="show-pass" type="button" data-password-toggle="password">Show</button></div></div>
        <div class="form-row"><span class="remember">Secure session</span><a class="text-link" href="<?= e(url('/contact.php')) ?>">Need help signing in?</a></div>
        <button class="btn btn-primary" type="submit">Sign in →</button>
      </form>
      <div class="auth-foot">New to Stonefellow? <a class="text-link" href="<?= e(url('/signup.php')) ?>">Create an account</a></div>
      <div class="legal-links">By continuing, you agree to the <a href="<?= e(url('/terms.php')) ?>">Terms</a> and <a href="<?= e(url('/privacy.php')) ?>">Privacy Policy</a>.</div>
    </div>
  </section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
</body>
</html>
