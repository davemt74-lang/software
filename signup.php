<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(login_destination());
}

$error = '';
$displayName = trim((string)($_POST['display_name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$roleInterest = trim((string)($_POST['role_interest'] ?? 'artist'));
$allowedRoleInterests = ['artist', 'producer', 'supervisor', 'manager'];
if (!in_array($roleInterest, $allowedRoleInterests, true)) {
    $roleInterest = 'artist';
}

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
                    $exists = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                    $exists->execute([$email]);
                    if ($exists->fetchColumn()) {
                        throw new RuntimeException('An account with that email already exists.');
                    }

                    $pdo->beginTransaction();
                    $insert = $pdo->prepare(
                        "INSERT INTO users (email,password_hash,display_name,role,is_active,created_at,updated_at)
                         VALUES (?,?,?,?,1,NOW(),NOW())"
                    );
                    $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $displayName, 'fan']);
                    $userId = (int)$pdo->lastInsertId();

                    if (table_exists('user_account_types')) {
                        $type = $pdo->prepare('INSERT INTO user_account_types (user_id,role) VALUES (?,?)');
                        $type->execute([$userId, 'fan']);
                    }
                    $pdo->commit();

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['signup_role_interest'] = $roleInterest;
                    reset_current_user_cache();
                    flash('notice', 'Welcome to Stonefellow. Your account is ready.');
                    redirect(login_destination());
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Stonefellow public signup failed: ' . $e->getMessage());
                    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Your account could not be created. Please try again.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Create a Stonefellow account."><title>Create account — Stonefellow</title>
<link rel="stylesheet" href="<?= e(url('/auth.css?v=180')) ?>">
</head>
<body>
<header class="site-header">
  <div class="wrap nav">
    <a class="brand" href="<?= e(url('/index.php')) ?>" aria-label="Stonefellow home"><span class="brand-mark"><span>S</span></span><span>Stonefellow</span></a>
    <nav class="nav-links" aria-label="Primary navigation"><a href="<?= e(url('/index.php#product')) ?>">Product</a><a href="<?= e(url('/index.php#roles')) ?>">Solutions</a><a href="<?= e(url('/index.php#resources')) ?>">Resources</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a><a href="<?= e(url('/book-demo.php')) ?>">Book a demo</a></nav>
    <div class="nav-actions"><a class="nav-login" href="<?= e(url('/login.php')) ?>">Sign in</a><a class="btn btn-primary" href="<?= e(url('/signup.php')) ?>">Create account</a><button class="menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="mobileMenu" aria-expanded="false">☰</button></div>
  </div>
  <div class="mobile-menu-overlay" id="mobileMenuOverlay" aria-hidden="true"></div>
  <aside class="mobile-menu" id="mobileMenu" aria-hidden="true"><div class="mobile-menu-head"><a class="brand" href="<?= e(url('/index.php')) ?>"><span class="brand-mark"><span>S</span></span><span>Stonefellow</span></a><button class="mobile-menu-close" id="menuClose" aria-label="Close menu">×</button></div><nav class="mobile-menu-links" aria-label="Mobile navigation"><a href="<?= e(url('/index.php#product')) ?>">Product <span>→</span></a><a href="<?= e(url('/index.php#roles')) ?>">Solutions <span>→</span></a><a href="<?= e(url('/pricing.php')) ?>">Pricing <span>→</span></a><a href="<?= e(url('/book-demo.php')) ?>">Book a demo <span>→</span></a></nav><div class="mobile-menu-actions"><a class="btn btn-light" href="<?= e(url('/signup.php')) ?>">Create account</a><a class="btn btn-secondary" href="<?= e(url('/login.php')) ?>">Sign in</a></div></aside>
</header>
<main class="auth-shell">
  <section class="auth-visual">
    <div class="eyebrow">Start your workspace</div>
    <h1>Build your music operations around the way you work.</h1>
    <p>Create your Stonefellow account and start organizing the real stems, sessions, releases and business workflows already in your ecosystem.</p>
    <div class="auth-points"><div class="auth-point"><b>Producer workflows</b>Manage stems, versions, notes, approvals and collaborators.</div><div class="auth-point"><b>Business workflows</b>Coordinate publishing, rights, releases and legal tasks.</div><div class="auth-point"><b>Role-aware onboarding</b>Tell Stonefellow how you work without granting elevated permissions at signup.</div></div>
    <div class="studio-card" aria-hidden="true"><div class="studio-top"><b>STONEFELLOW · ACTIVE PROJECT</b><span>AI STUDIO ASSISTANT ONLINE</span></div><div class="studio-grid"><div class="studio-side"><div class="active">Project</div><div>Stems</div><div>Sessions</div><div>Rights</div><div>Releases</div><div>Team</div></div><div class="studio-tracks"><div class="track"><span>Vocal</span><i class="wave"></i></div><div class="track"><span>Drums</span><i class="wave" style="background:linear-gradient(90deg,#4f8cff 0 74%,#242936 74%)"></i></div><div class="track"><span>Bass</span><i class="wave" style="background:linear-gradient(90deg,#49c98d 0 57%,#242936 57%)"></i></div><div class="track"><span>Guitar</span><i class="wave" style="background:linear-gradient(90deg,#d69b44 0 84%,#242936 84%)"></i></div><div class="track"><span>Keys</span><i class="wave" style="background:linear-gradient(90deg,#d66384 0 68%,#242936 68%)"></i></div><div class="track"><span>FX</span><i class="wave" style="background:linear-gradient(90deg,#58b3b8 0 49%,#242936 49%)"></i></div></div><div class="studio-aside"><b style="color:#fff">Assistant tasks</b><div class="studio-task">Review split sheet</div><div class="studio-task">Confirm master version</div><div class="studio-task">Prepare metadata</div><div class="studio-task">Release due Friday</div></div></div></div>
  </section>
  <section class="auth-form-side"><div class="auth-card"><div class="eyebrow">Create your account</div><h2>Start with how you work.</h2><p class="auth-intro">Your selection personalizes onboarding. Account permissions are assigned separately for security.</p>
    <?php if (!db_ready()): ?><div class="db-warning">Account registration is unavailable until the database is configured.</div><br><?php endif; ?>
    <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><br><?php endif; ?>
    <form class="auth-form" method="post" action="<?= e(url('/signup.php')) ?>">
      <?= csrf_field() ?>
      <div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label>How do you work?</label><div class="account-types">
        <?php foreach (['artist'=>'Artist','producer'=>'Producer','supervisor'=>'Music Supervisor','manager'=>'Manager'] as $value=>$label): ?><div class="account-choice"><input id="role-<?= e($value) ?>" type="radio" name="role_interest" value="<?= e($value) ?>" <?= $roleInterest === $value ? 'checked' : '' ?>><label for="role-<?= e($value) ?>"><?= e($label) ?></label></div><?php endforeach; ?>
      </div></div>
      <div class="field"><label for="display_name">Full name</label><input id="display_name" name="display_name" maxlength="120" autocomplete="name" required placeholder="Your name" value="<?= e($displayName) ?>"></div>
      <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required placeholder="you@example.com" value="<?= e($email) ?>"></div>
      <div class="field"><label for="password">Create password</label><div class="password-wrap"><input id="password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required placeholder="12+ characters"><button class="show-pass" type="button" data-password-toggle="password">Show</button></div><p class="form-note">Use at least 12 characters.</p></div>
      <div class="field"><label for="password_confirmation">Confirm password</label><div class="password-wrap"><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required placeholder="Repeat your password"><button class="show-pass" type="button" data-password-toggle="password_confirmation">Show</button></div></div>
      <label class="remember"><input type="checkbox" name="accept_terms" value="1" required> I agree to the <a class="text-link" href="<?= e(url('/terms.php')) ?>">Terms</a> and <a class="text-link" href="<?= e(url('/privacy.php')) ?>">Privacy Policy</a></label>
      <button class="btn btn-primary" type="submit">Create my Stonefellow account →</button>
    </form>
    <div class="auth-foot">Already have an account? <a class="text-link" href="<?= e(url('/login.php')) ?>">Sign in</a></div><div class="terms">Stonefellow AI assists with operations around your existing music. It does not generate creative output.</div>
  </div></section>
</main>
<script src="<?= e(url('/auth.js?v=180')) ?>"></script>
</body></html>
