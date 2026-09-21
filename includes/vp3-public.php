<?php
declare(strict_types=1);
require_once __DIR__.'/vp3-funnel.php';

function vp3_public_brand(): string
{
    return '<span class="vp3-public-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong>';
}

function vp3_public_link(string $path): string
{
    return function_exists('vp3_funnel_url') ? vp3_funnel_url($path) : url($path);
}

function vp3_public_mega_nav(string $active = ''): void
{
    $activeClass = static fn(string $key): string => $active === $key ? ' is-active' : '';
    ?>
    <nav class="mega-nav vp3-public-mega-nav" aria-label="Primary navigation">
      <details class="mega-item<?= e($activeClass('product')) ?>">
        <summary>Product</summary>
        <div class="mega-panel">
          <div class="mega-intro">
            <span class="mega-kicker">Product</span>
            <h2>Your AI identity, workspace, and agent.</h2>
            <p>VP3 connects your assistant, public profile, personal URL, private knowledge, workflows, and optional HomeServer into one system.</p>
            <a class="mega-intro-link" href="<?= e(url('/product.php')) ?>">Explore the VP3 platform <span aria-hidden="true">→</span></a>
          </div>
          <div class="mega-columns cols-2">
            <div class="mega-column">
              <span class="mega-column-label">Your assistant</span>
              <a class="mega-link" href="<?= e(url('/ai-assistant.php')) ?>"><strong>AI Assistant</strong><small>Your main agent for conversations, knowledge, planning, workflows, and follow-through.</small></a>
              <a class="mega-link" href="<?= e(url('/profile-agent-overview.php')) ?>"><strong>Profile Agent</strong><small>A public-facing agent that can represent you within the permissions you set.</small></a>
              <a class="mega-link" href="<?= e(url('/chrome-extension.php')) ?>"><strong>Chrome Extension</strong><small>Bring VP3 Browser Companion into Chrome for page-aware Agent help, research, notifications, and approved browser actions.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Your presence</span>
              <a class="mega-link" href="<?= e(url('/personal-url.php')) ?>"><strong>Personal URL</strong><small>A shareable VP3 destination for people, customers, booking, products, and collaborators.</small></a>
              <a class="mega-link" href="<?= e(url('/homeserver.php')) ?>"><strong>HomeServer</strong><small>Pair VP3 Cloud with private local knowledge, tools, models, skills, and compute.</small></a>
            </div>
          </div>
        </div>
      </details>

      <details class="mega-item<?= e($activeClass('services')) ?>">
        <summary>Services</summary>
        <div class="mega-panel">
          <div class="mega-intro">
            <span class="mega-kicker">Services</span>
            <h2>From conversation to coordinated action.</h2>
            <p>Capture, understand, schedule, sell, collaborate, and let your Agent keep the context connected across the work.</p>
            <a class="mega-intro-link" href="<?= e(url('/services.php')) ?>">See VP3 services <span aria-hidden="true">→</span></a>
          </div>
          <div class="mega-columns">
            <div class="mega-column">
              <span class="mega-column-label">Capture + understand</span>
              <a class="mega-link" href="<?= e(url('/transcriptions.php')) ?>"><strong>Transcription</strong><small>Record and organize meetings, calls, interviews, voice notes, and other conversations.</small></a>
              <a class="mega-link" href="<?= e(url('/ai-summary.php')) ?>"><strong>AI Summary</strong><small>Turn long conversations into decisions, action items, questions, and reusable knowledge.</small></a>
              <a class="mega-link" href="<?= e(url('/annotations.php')) ?>"><strong>Annotations</strong><small>Capture highlights, screenshots, notes, and source context from the web, then organize and reuse them in VP3 research.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Coordinate</span>
              <a class="mega-link" href="<?= e(url('/teams.php')) ?>"><strong>Teams</strong><small>Shared workspaces, conversations, permissions, context, and collaborative Agent workflows.</small></a>
              <a class="mega-link" href="<?= e(url('/video-meetings.php')) ?>"><strong>Meetings</strong><small>Video meetings with transcripts, Meeting Intelligence, action items, follow-through, and connected Agent context.</small></a>
              <a class="mega-link" href="<?= e(url('/calendar-service.php')) ?>"><strong>Calendar</strong><small>Availability, calendar intelligence, sync, scheduling context, and Agent-managed coordination.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Book + sell</span>
              <a class="mega-link" href="<?= e(url('/booking.php')) ?>"><strong>Booking — free or paid</strong><small>Public scheduling, appointment lifecycle, payments, reminders, and follow-up.</small></a>
              <a class="mega-link" href="<?= e(url('/ecommerce.php')) ?>"><strong>Ecommerce</strong><small>Public products, checkout, paid orders, fulfillment, refunds, and Agent commerce.</small></a>
              <a class="mega-link" href="<?= e(url('/agent-analytics.php')) ?>"><strong>Agent Analytics</strong><small>See profile visits, booking and product intent, conversions, attributed revenue, traffic sources, and Agent-identified opportunities.</small></a>
            </div>
          </div>
        </div>
      </details>

      <details class="mega-item<?= e($activeClass('homeserver')) ?>">
        <summary>HomeServer</summary>
        <div class="mega-panel">
          <div class="mega-intro">
            <span class="mega-kicker">HomeServer</span>
            <h2>Use the Cloud, self-host, or connect both.</h2>
            <p>Keep VP3 convenient in the Cloud while moving private knowledge, tools, models, and local capabilities onto hardware you control.</p>
            <a class="mega-intro-link" href="<?= e(url('/homeserver.php')) ?>">Explore HomeServer <span aria-hidden="true">→</span></a>
          </div>
          <div class="mega-columns">
            <div class="mega-column">
              <span class="mega-column-label">Deployment</span>
              <a class="mega-link" href="<?= e(url('/cloud-vs-self-hosted.php')) ?>"><strong>Cloud vs. self-hosted</strong><small>Choose Cloud convenience, local privacy, or a paired hybrid setup.</small></a>
              <a class="mega-link" href="<?= e(url('/paired-devices.php')) ?>"><strong>Paired devices</strong><small>Connect approved front ends to your private HomeServer capability platform.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Models + compute</span>
              <a class="mega-link" href="<?= e(url('/openrouter.php')) ?>"><strong>OpenRouter</strong><small>Use provider and model choice while keeping HomeServer available for private execution.</small></a>
              <a class="mega-link" href="<?= e(url('/model-choice.php')) ?>"><strong>Model choice</strong><small>Route work based on privacy, capability, availability, latency, and cost.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Private capabilities</span>
              <a class="mega-link" href="<?= e(url('/local-knowledge-overview.php')) ?>"><strong>Local Knowledge</strong><small>Keep native local files and private collections authoritative on HomeServer.</small></a>
              <a class="mega-link" href="<?= e(url('/tools-skills.php')) ?>"><strong>Tools + skills</strong><small>Give paired Agents approved local capabilities without blanket machine access.</small></a>
            </div>
          </div>
        </div>
      </details>

      <details class="mega-item<?= e($activeClass('pricing')) ?>">
        <summary>Pricing</summary>
        <div class="mega-panel mega-panel-pricing">
          <div class="mega-intro">
            <span class="mega-kicker">Pricing</span>
            <h2>Use VP3 on the cadence that fits.</h2>
            <p>Account access, usage, and AI capacity can be matched to how often and how deeply you use your Agent.</p>
            <a class="mega-intro-link" href="<?= e(url('/pricing.php')) ?>">View pricing <span aria-hidden="true">→</span></a>
          </div>
          <div class="mega-columns cols-4">
            <a class="mega-price" href="<?= e(url('/pricing-monthly.php')) ?>"><strong>Monthly</strong><small>A simple recurring plan for regular VP3 use.</small><b>View monthly →</b></a>
            <a class="mega-price" href="<?= e(url('/pricing-weekly.php')) ?>"><strong>Weekly</strong><small>Short-cycle access when usage is project-based or changing.</small><b>View weekly →</b></a>
            <a class="mega-price" href="<?= e(url('/pricing-yearly.php')) ?>"><strong>Yearly</strong><small>Longer-term access for people building VP3 into their workflow.</small><b>View yearly →</b></a>
            <a class="mega-price" href="<?= e(url('/token-packages.php')) ?>"><strong>Token packages</strong><small>Add AI capacity for heavier conversations and workflows.</small><b>View tokens →</b></a>
          </div>
        </div>
      </details>

      <details class="mega-item<?= e($activeClass('about')) ?>">
        <summary>About</summary>
        <div class="mega-panel mega-panel-about">
          <div class="mega-intro">
            <span class="mega-kicker">About VP3</span>
            <h2>Private, useful AI built around the person.</h2>
            <p>Learn why VP3 is being built, how the pieces work together, and how to reach the team.</p>
            <a class="mega-intro-link" href="<?= e(url('/about.php')) ?>">About VP3 <span aria-hidden="true">→</span></a>
          </div>
          <div class="mega-columns">
            <div class="mega-column">
              <span class="mega-column-label">Company</span>
              <a class="mega-link" href="<?= e(url('/about-team.php')) ?>"><strong>Team</strong><small>The product effort and people behind VP3.</small></a>
              <a class="mega-link" href="<?= e(url('/mission.php')) ?>"><strong>Mission</strong><small>Why user-controlled AI, identity, knowledge, and capability matter.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Stories</span>
              <a class="mega-link" href="<?= e(url('/case-studies.php')) ?>"><strong>Case studies</strong><small>See how VP3 capabilities combine across real product workflows.</small></a>
              <a class="mega-link" href="<?= e(url('/testimonials.php')) ?>"><strong>Testimonials</strong><small>Verified feedback belongs here as it becomes available.</small></a>
            </div>
            <div class="mega-column">
              <span class="mega-column-label">Connect</span>
              <a class="mega-link" href="<?= e(url('/contact.php')) ?>"><strong>Contact us</strong><small>Questions, partnerships, demos, support, and general inquiries.</small></a>
              <a class="mega-link" href="<?= e(url('/social.php')) ?>"><strong>Social links</strong><small>Verified public VP3 channels and destinations.</small></a>
            </div>
          </div>
        </div>
      </details>
    </nav>
    <?php
}

function vp3_public_mobile_nav(): void
{
    ?>
    <details class="mobile-menu vp3-public-mobile-mega">
      <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
      <nav class="mega-mobile-nav" aria-label="Mobile navigation">
        <details class="mobile-nav-group"><summary>Product</summary><div class="mobile-nav-links">
          <a href="<?= e(url('/ai-assistant.php')) ?>"><strong>AI Assistant</strong><small>Your main VP3 agent and workspace.</small></a>
          <a href="<?= e(url('/personal-url.php')) ?>"><strong>Personal URL</strong><small>Your shareable VP3 destination.</small></a>
          <a href="<?= e(url('/profile-agent-overview.php')) ?>"><strong>Profile Agent</strong><small>An agent on your public profile.</small></a>
          <a href="<?= e(url('/chrome-extension.php')) ?>"><strong>Chrome Extension</strong><small>VP3 Browser Companion across the web.</small></a>
          <a href="<?= e(url('/homeserver.php')) ?>"><strong>HomeServer</strong><small>Private local knowledge and capabilities.</small></a>
        </div></details>
        <details class="mobile-nav-group"><summary>Services</summary><div class="mobile-nav-links">
          <a href="<?= e(url('/transcriptions.php')) ?>"><strong>Transcription</strong><small>Capture and organize conversations.</small></a>
          <a href="<?= e(url('/ai-summary.php')) ?>"><strong>AI Summary</strong><small>Decisions, actions, and reusable knowledge.</small></a>
          <a href="<?= e(url('/annotations.php')) ?>"><strong>Annotations</strong><small>Capture web highlights, screenshots, notes, and source context.</small></a>
          <a href="<?= e(url('/teams.php')) ?>"><strong>Teams</strong><small>Shared context and collaboration.</small></a>
          <a href="<?= e(url('/video-meetings.php')) ?>"><strong>Meetings</strong><small>Video meetings, intelligence, and follow-through.</small></a>
          <a href="<?= e(url('/calendar-service.php')) ?>"><strong>Calendar</strong><small>Availability and calendar intelligence.</small></a>
          <a href="<?= e(url('/booking.php')) ?>"><strong>Booking</strong><small>Free and paid appointment workflows.</small></a>
          <a href="<?= e(url('/ecommerce.php')) ?>"><strong>Ecommerce</strong><small>Products, orders, and fulfillment.</small></a>
          <a href="<?= e(url('/agent-analytics.php')) ?>"><strong>Agent Analytics</strong><small>Visits, intent, conversions, revenue, sources, and opportunities.</small></a>
        </div></details>
        <details class="mobile-nav-group"><summary>HomeServer</summary><div class="mobile-nav-links">
          <a href="<?= e(url('/cloud-vs-self-hosted.php')) ?>"><strong>Cloud vs. self-hosted</strong><small>Cloud, local, or paired hybrid operation.</small></a>
          <a href="<?= e(url('/paired-devices.php')) ?>"><strong>Paired devices</strong><small>Authorized front ends and capabilities.</small></a>
          <a href="<?= e(url('/model-choice.php')) ?>"><strong>Model choice</strong><small>Cloud and local model routing.</small></a>
          <a href="<?= e(url('/local-knowledge-overview.php')) ?>"><strong>Local Knowledge</strong><small>Private files stay locally authoritative.</small></a>
          <a href="<?= e(url('/tools-skills.php')) ?>"><strong>Tools + skills</strong><small>Approved local capabilities.</small></a>
        </div></details>
        <details class="mobile-nav-group"><summary>Pricing</summary><div class="mobile-nav-links">
          <a href="<?= e(url('/pricing-monthly.php')) ?>"><strong>Monthly</strong><small>Recurring monthly access.</small></a>
          <a href="<?= e(url('/pricing-weekly.php')) ?>"><strong>Weekly</strong><small>Short-cycle project access.</small></a>
          <a href="<?= e(url('/pricing-yearly.php')) ?>"><strong>Yearly</strong><small>Long-term access.</small></a>
          <a href="<?= e(url('/token-packages.php')) ?>"><strong>Token packages</strong><small>Add AI usage capacity.</small></a>
        </div></details>
        <details class="mobile-nav-group"><summary>About</summary><div class="mobile-nav-links">
          <a href="<?= e(url('/about-team.php')) ?>"><strong>Team</strong><small>The people and product effort behind VP3.</small></a>
          <a href="<?= e(url('/mission.php')) ?>"><strong>Mission</strong><small>Why VP3 exists.</small></a>
          <a href="<?= e(url('/case-studies.php')) ?>"><strong>Case studies</strong><small>Connected workflow examples.</small></a>
          <a href="<?= e(url('/testimonials.php')) ?>"><strong>Testimonials</strong><small>Verified feedback as it becomes available.</small></a>
          <a href="<?= e(url('/contact.php')) ?>"><strong>Contact us</strong><small>Demos, support, and partnerships.</small></a>
        </div></details>
        <a class="mobile-nav-direct" href="<?= e(url('/pricing.php')) ?>">Pricing</a>
        <div class="mobile-nav-actions"><a href="<?= e(vp3_public_link('/login.php')) ?>">Log in</a><a href="<?= e(vp3_public_link('/signup.php')) ?>">Get VP3 →</a></div>
      </nav>
    </details>
    <?php
}

function vp3_public_header(string $title, string $description = '', array $options = []): void
{
    $bodyClass = trim((string)($options['body_class'] ?? ''));
    $active = trim((string)($options['active'] ?? ''));
    if ($active === 'transcriptions' || $active === 'teams') $active = 'services';
    $compact = !empty($options['compact']);
    $robots = trim((string)($options['robots'] ?? ''));
    $canonicalPath = trim((string)($options['canonical'] ?? ''));
    $skipLink = !empty($options['skip_link']);
    $canonicalUrl = $canonicalPath !== '' ? url($canonicalPath) : '';
    $publicUser = current_user();
    $openUrl = $publicUser ? login_destination() : '';

    if (!$publicUser) vp3_funnel_capture_public_source();
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
<link rel="stylesheet" href="<?= e(url('/vp3-public.css?v=vp3-public-20260915-editorial')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-index-mega-menu.css?v=20260915-public')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-nav.css?v=vp3-public-20260914-index')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-marketing-pages.css?v=20260921-services')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-accessibility.css?v=20260914-1')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-editorial.css?v=20260915-1')) ?>">
</head>
<body class="vp3-public<?= $bodyClass !== '' ? ' ' . e($bodyClass) : '' ?>">
<?php if ($skipLink): ?><a class="vp3-skip-link" href="#main-content">Skip to main content</a><?php endif; ?>
<header class="vp3-public-header<?= $compact ? ' compact' : '' ?>">
  <div class="vp3-public-nav">
    <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home"><?= vp3_public_brand() ?></a>
    <?php if (!$compact): ?>
      <?php vp3_public_mega_nav($active); ?>
      <div class="vp3-public-actions">
        <?php if ($publicUser): ?>
          <a class="vp3-public-primary" href="<?= e($openUrl) ?>">Open VP3 <span aria-hidden="true">→</span></a>
        <?php else: ?>
          <a class="vp3-public-signin" href="<?= e(vp3_public_link('/login.php')) ?>">Log in</a>
          <a class="vp3-public-primary" href="<?= e(vp3_public_link('/signup.php')) ?>">Get VP3 <span aria-hidden="true">→</span></a>
        <?php endif; ?>
        <?php vp3_public_mobile_nav(); ?>
      </div>
    <?php endif; ?>
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
