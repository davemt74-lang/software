<?php
declare(strict_types=1);

function vp3_public_brand(): string
{
    return '<span class="vp3-public-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong>';
}

function vp3_public_header(string $title, string $description = '', array $options = []): void
{
    $bodyClass = trim((string)($options['body_class'] ?? ''));
    $active = trim((string)($options['active'] ?? ''));
    $compact = !empty($options['compact']);
    $robots = trim((string)($options['robots'] ?? ''));
    $publicUser = current_user();
    $openUrl = $publicUser ? login_destination() : '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>"><?php endif; ?>
<?php if ($robots !== ''): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<meta name="theme-color" content="#f7f9fc">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(url('/vp3-public.css?v=vp3-public-20260906')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-nav.css?v=vp3-public-20260906')) ?>">
</head>
<body class="vp3-public<?= $bodyClass !== '' ? ' ' . e($bodyClass) : '' ?>">
<header class="vp3-public-header<?= $compact ? ' compact' : '' ?>">
  <div class="vp3-public-nav">
    <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home"><?= vp3_public_brand() ?></a>
    <?php if (!$compact): ?>
    <nav class="vp3-public-links" aria-label="Primary navigation">
      <a<?= $active === 'features' ? ' class="active"' : '' ?> href="<?= e(url('/index.php#features')) ?>">Features</a>
      <a<?= $active === 'transcriptions' ? ' class="active"' : '' ?> href="<?= e(url('/index.php#transcriptions')) ?>">Transcriptions</a>
      <a<?= $active === 'teams' ? ' class="active"' : '' ?> href="<?= e(url('/index.php#teams')) ?>">Teams</a>
      <a<?= $active === 'pricing' ? ' class="active"' : '' ?> href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a<?= $active === 'about' ? ' class="active"' : '' ?> href="<?= e(url('/about.php')) ?>">About</a>
    </nav>
    <?php endif; ?>
    <div class="vp3-public-actions">
      <?php if ($publicUser): ?>
        <a class="vp3-public-primary" href="<?= e($openUrl) ?>">Open VP3 <span aria-hidden="true">→</span></a>
      <?php elseif ($active === 'login'): ?>
        <a class="vp3-public-secondary" href="<?= e(url('/signup.php')) ?>">Create account</a>
      <?php elseif ($active === 'signup'): ?>
        <a class="vp3-public-secondary" href="<?= e(url('/login.php')) ?>">Sign in</a>
      <?php else: ?>
        <a class="vp3-public-signin" href="<?= e(url('/login.php')) ?>">Sign in</a>
        <a class="vp3-public-primary" href="<?= e(url('/signup.php')) ?>">Get Started <span aria-hidden="true">→</span></a>
      <?php endif; ?>
      <?php if (!$compact): ?>
      <details class="vp3-public-mobile-menu">
        <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
        <nav aria-label="Mobile navigation">
          <a href="<?= e(url('/index.php#features')) ?>">Features</a>
          <a href="<?= e(url('/index.php#transcriptions')) ?>">Transcriptions</a>
          <a href="<?= e(url('/index.php#teams')) ?>">Teams</a>
          <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
          <a href="<?= e(url('/about.php')) ?>">About</a>
          <a href="<?= e(url('/book-demo.php')) ?>">Book a Demo</a>
          <a href="<?= e(url('/contact.php')) ?>">Contact</a>
        </nav>
      </details>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php
}

function vp3_public_footer(): void
{
    ?>
<footer class="vp3-public-footer">
  <div class="vp3-public-footer-inner">
    <div class="vp3-public-footer-brand">
      <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>"><?= vp3_public_brand() ?></a>
      <span>A Private Future. On Your Terms.</span>
    </div>
    <nav class="vp3-public-footer-links" aria-label="Footer navigation">
      <a href="<?= e(url('/index.php#features')) ?>">Features</a>
      <a href="<?= e(url('/index.php#transcriptions')) ?>">Transcriptions</a>
      <a href="<?= e(url('/index.php#teams')) ?>">Teams</a>
      <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a href="<?= e(url('/about.php')) ?>">About</a>
      <a href="<?= e(url('/contact.php')) ?>">Contact</a>
      <a href="<?= e(url('/privacy.php')) ?>">Privacy</a>
      <a href="<?= e(url('/terms.php')) ?>">Terms</a>
    </nav>
  </div>
</footer>
</body>
</html>
<?php
}
