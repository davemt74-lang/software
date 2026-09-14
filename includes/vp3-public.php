<?php
declare(strict_types=1);

function vp3_public_brand(): string
{
    return '<span class="vp3-public-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong>';
}

function vp3_public_link(string $path): string
{
    return function_exists('vp3_funnel_url') ? vp3_funnel_url($path) : url($path);
}

function vp3_public_header(string $title, string $description = '', array $options = []): void
{
    $bodyClass = trim((string)($options['body_class'] ?? ''));
    $active = trim((string)($options['active'] ?? ''));
    $compact = !empty($options['compact']);
    $robots = trim((string)($options['robots'] ?? ''));
    $canonicalPath = trim((string)($options['canonical'] ?? ''));
    $skipLink = !empty($options['skip_link']);
    $canonicalUrl = $canonicalPath !== '' ? url($canonicalPath) : '';
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
<?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= e($canonicalUrl) ?>"><?php endif; ?>
<meta name="theme-color" content="#0b0c0e">
<meta property="og:type" content="website">
<meta property="og:site_name" content="VP3">
<meta property="og:title" content="<?= e($title) ?>">
<?php if ($description !== ''): ?><meta property="og:description" content="<?= e($description) ?>"><?php endif; ?>
<?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= e($canonicalUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($title) ?>">
<?php if ($description !== ''): ?><meta name="twitter:description" content="<?= e($description) ?>"><?php endif; ?>
<title><?= e($title) ?></title>
<link rel="sitemap" type="application/xml" href="<?= e(url('/sitemap.php')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public.css?v=vp3-public-20260914-index')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-nav.css?v=vp3-public-20260914-index')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-marketing-pages.css?v=20260914-index')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-accessibility.css?v=20260914-1')) ?>">
</head>
<body class="vp3-public<?= $bodyClass !== '' ? ' ' . e($bodyClass) : '' ?>">
<?php if ($skipLink): ?><a class="vp3-skip-link" href="#main-content">Skip to main content</a><?php endif; ?>
<header class="vp3-public-header">
  <div class="vp3-public-nav">
    <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home"><?= vp3_public_brand() ?></a>
    <?php if (!$compact): ?>
    <nav class="vp3-public-links" aria-label="Primary navigation">
      <a<?= $active === 'product' ? ' class="active" aria-current="page"' : '' ?> href="<?= e(url('/product.php')) ?>">Product</a>
      <a<?= $active === 'services' || $active === 'transcriptions' || $active === 'teams' ? ' class="active" aria-current="page"' : '' ?> href="<?= e(url('/services.php')) ?>">Services</a>
      <a<?= $active === 'homeserver' ? ' class="active" aria-current="page"' : '' ?> href="<?= e(url('/homeserver.php')) ?>">HomeServer</a>
      <a<?= $active === 'pricing' ? ' class="active" aria-current="page"' : '' ?> href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a<?= $active === 'about' ? ' class="active" aria-current="page"' : '' ?> href="<?= e(url('/about.php')) ?>">About</a>
    </nav>
    <?php endif; ?>
    <div class="vp3-public-actions">
      <?php if ($publicUser): ?>
        <a class="vp3-public-primary" href="<?= e($openUrl) ?>">Open VP3 <span aria-hidden="true">→</span></a>
      <?php elseif ($active === 'login'): ?>
        <a class="vp3-public-secondary" href="<?= e(vp3_public_link('/signup.php')) ?>">Create account</a>
      <?php elseif ($active === 'signup'): ?>
        <a class="vp3-public-secondary" href="<?= e(vp3_public_link('/login.php')) ?>">Log in</a>
      <?php else: ?>
        <a class="vp3-public-signin" href="<?= e(vp3_public_link('/login.php')) ?>">Log in</a>
        <a class="vp3-public-primary" href="<?= e(vp3_public_link('/signup.php')) ?>">Get VP3 <span aria-hidden="true">→</span></a>
      <?php endif; ?>
      <?php if (!$compact): ?>
      <details class="vp3-public-mobile-menu">
        <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
        <nav aria-label="Mobile navigation">
          <a href="<?= e(url('/product.php')) ?>">Product</a>
          <a href="<?= e(url('/services.php')) ?>">Services</a>
          <a href="<?= e(url('/homeserver.php')) ?>">HomeServer</a>
          <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
          <a href="<?= e(url('/about.php')) ?>">About</a>
          <a href="<?= e(vp3_public_link('/book-demo.php')) ?>">Book a Demo</a>
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
      <a href="<?= e(url('/product.php')) ?>">Product</a>
      <a href="<?= e(url('/services.php')) ?>">Services</a>
      <a href="<?= e(url('/homeserver.php')) ?>">HomeServer</a>
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
