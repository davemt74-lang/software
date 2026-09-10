<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$vp3SignupUrl = url('/signup.php');
$vp3DemoUrl = url('/book-demo.php');
$vp3LoginUrl = url('/login.php');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="VP3 AI Assistants turn conversations into action across desktop and mobile, with transcription, summaries, teams, personal profile agents, and optional private HomeServer AI.">
<meta name="theme-color" content="#071018">
<title>VP3 AI Assistants — Turn every conversation into what's next.</title>
<link rel="stylesheet" href="<?= e(url('/vp3-public.css?v=vp3-public-20260906')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-nav.css?v=vp3-public-20260906')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-index-workforce.css?v=vp3-index-workforce-20260910')) ?>">
</head>
<body class="vp3-public vp3-workforce-home">
<header class="vp3-public-header vp3-workforce-header">
  <div class="vp3-public-nav">
    <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home">
      <strong>VP3</strong>
    </a>
    <nav class="vp3-public-links" aria-label="Primary navigation">
      <a href="#product">Product</a>
      <a href="#platform">Solutions</a>
      <a href="<?= e(url('/transcriptions.php')) ?>">Resources</a>
      <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a href="<?= e(url('/about.php')) ?>">About</a>
    </nav>
    <div class="vp3-public-actions">
      <a class="vp3-public-signin" href="<?= e($vp3LoginUrl) ?>">Log in</a>
      <a class="vp3-public-primary" href="<?= e($vp3SignupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
      <details class="vp3-public-mobile-menu">
        <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
        <nav aria-label="Mobile navigation">
          <a href="#product">Product</a>
          <a href="#platform">Solutions</a>
          <a href="<?= e(url('/transcriptions.php')) ?>">Resources</a>
          <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
          <a href="<?= e(url('/about.php')) ?>">About</a>
          <a href="<?= e($vp3LoginUrl) ?>">Log in</a>
        </nav>
      </details>
    </div>
  </div>
</header>

<main>
  <section class="vp3-workforce-hero" aria-labelledby="vp3HeroTitle">
    <div class="vp3-workforce-hero-bg" aria-hidden="true"></div>
    <div class="vp3-workforce-wrap vp3-workforce-hero-grid">
      <div class="vp3-workforce-hero-copy">
        <div class="vp3-workforce-eyebrow">Your AI workforce</div>
        <h1 id="vp3HeroTitle">VP3 AI Assistants<br>Turn every conversation<br>into what's next.</h1>
        <p>VP3 gives you AI assistants that understand your work, take action across your tools, and help you move faster from idea to outcome.</p>
        <div class="vp3-workforce-cta-row">
          <a class="vp3-workforce-btn primary" href="<?= e($vp3SignupUrl) ?>">Get started for free <span aria-hidden="true">→</span></a>
          <a class="vp3-workforce-btn ghost" href="<?= e($vp3DemoUrl) ?>"><span class="vp3-play" aria-hidden="true">▶</span> Watch 2-min demo</a>
        </div>
        <div class="vp3-workforce-proof" aria-label="VP3 setup benefits">
          <span>✓ No credit card required</span>
          <span>✓ Set up in minutes</span>
          <span>✓ Works with your tools</span>
        </div>
      </div>
    </div>
  </section>

  <section class="vp3-capability-strip" aria-label="VP3 capabilities">
    <div class="vp3-capability-row">
      <div><b>⌘</b><span>Research</span></div>
      <div><b>✎</b><span>Write</span></div>
      <div><b>▤</b><span>Plan</span></div>
      <div><b>▥</b><span>Build</span></div>
      <div><b>⌁</b><span>Analyze</span></div>
      <div><b>↻</b><span>Automate</span></div>
      <div><b>◎</b><span>Collaborate</span></div>
      <div><b>↔</b><span>Integrate</span></div>
      <div><b>◇</b><span>Secure</span></div>
      <div><b>↗</b><span>Scale</span></div>
    </div>
  </section>

  <section class="vp3-workforce-section" id="product" aria-labelledby="realWorkTitle">
    <div class="vp3-workforce-wrap">
      <div class="vp3-workforce-section-head split">
        <div>
          <div class="vp3-workforce-eyebrow dark">Built for real work</div>
          <h2 id="realWorkTitle">Built for real work.</h2>
        </div>
        <p>From research to implementation, VP3 assistants help teams do more, with less friction, in the tools they already use.</p>
      </div>

      <div class="vp3-real-work-grid">
        <article class="vp3-real-card">
          <div class="vp3-real-visual laptop-visual" aria-hidden="true">
            <div class="mini-window"><span class="mini-side"></span><div class="mini-lines"><i></i><i></i><i></i><i></i><i></i></div></div>
          </div>
          <h3>Understand your context</h3>
          <p>VP3 connects to your tools, content, and conversations so your AI actually gets you.</p>
          <a href="<?= e(url('/transcriptions.php')) ?>">Learn more <span aria-hidden="true">→</span></a>
        </article>
        <article class="vp3-real-card">
          <div class="vp3-real-visual phone-visual" aria-hidden="true">
            <div class="handset"><div class="wave"></div><div class="record-dot"></div></div>
          </div>
          <h3>Take action together</h3>
          <p>Turn ideas into real work with assistants that can plan, create, and do.</p>
          <a href="<?= e(url('/teams.php')) ?>">Learn more <span aria-hidden="true">→</span></a>
        </article>
        <article class="vp3-real-card">
          <div class="vp3-real-visual planning-visual" aria-hidden="true">
            <div class="plan-sheet"><b>Product planning</b><small>Market research · 100%</small><span>✓ Strategy 75%</span><span>✓ Launch plan 40%</span><span>✓ Pitch plan</span></div>
          </div>
          <h3>Move from idea to impact</h3>
          <p>Get results, not just answers. VP3 helps you go from conversation to completion.</p>
          <a href="<?= e($vp3DemoUrl) ?>">Learn more <span aria-hidden="true">→</span></a>
        </article>
        <article class="vp3-real-card">
          <div class="vp3-real-visual team-visual" aria-hidden="true">
            <div class="team-panel"><div class="team-sidebar"><b>VP3</b><span>Chat</span><span>Agents</span><span>Tools</span><span>Settings</span></div><div class="team-score"><small>Team performance</small><strong>+34%</strong><span>● Faster execution</span><span>● Less busywork</span><span>● Higher output</span></div></div>
          </div>
          <h3>Scale without the chaos</h3>
          <p>Give every team a powerful AI colleague, without the complexity.</p>
          <a href="<?= e(url('/teams.php')) ?>">Learn more <span aria-hidden="true">→</span></a>
        </article>
      </div>
    </div>
  </section>

  <section class="vp3-workforce-section vp3-platform-section" id="platform" aria-labelledby="platformTitle">
    <div class="vp3-workforce-wrap">
      <div class="vp3-workforce-section-head split">
        <div>
          <div class="vp3-workforce-eyebrow dark">A platform for how you actually work</div>
          <h2 id="platformTitle">A platform for how<br>you actually work.</h2>
        </div>
        <p>VP3 combines powerful AI with a flexible, human-first design, so you can work the way you think, not the way a tool forces you to.</p>
      </div>
      <div class="vp3-platform-grid">
        <article><span class="vp3-platform-icon">◉</span><h3>Your knowledge</h3><p>Connect your tools, files, and conversations so your assistants have context that's actually useful.</p></article>
        <article><span class="vp3-platform-icon">⚙</span><h3>Your work, unified</h3><p>Bring together your people, tools, and workflows in one place.</p></article>
        <article><span class="vp3-platform-icon">▤</span><h3>A team multiplier</h3><p>Deploy specialized assistants across every team, from product to customer success.</p></article>
        <article><span class="vp3-platform-icon">○</span><h3>Built for you</h3><p>Flexible, secure, and designed to fit your team's unique processes — not the other way around.</p></article>
      </div>
    </div>
  </section>

  <section class="vp3-everything-section" aria-labelledby="everythingTitle">
    <div class="vp3-workforce-wrap vp3-everything-grid">
      <div class="vp3-device-composition" aria-label="VP3 desktop and mobile product preview">
        <div class="vp3-product-laptop">
          <div class="vp3-product-screen">
            <aside><strong>VP3</strong><span>Chat</span><span>Assistants</span><span>Tools</span><span>Files</span><span>Settings</span></aside>
            <div class="vp3-product-main"><b>Client Planning Call</b><nav>Notes &nbsp; Tasks &nbsp; Next steps &nbsp; Files</nav><div class="vp3-product-row"><i>✓</i><span>Summarize key takeaways from today's call</span></div><div class="vp3-product-row"><i>○</i><span>Draft proposal outline</span></div><div class="vp3-product-row"><i>○</i><span>Create follow-up tasks for the team</span></div><div class="vp3-product-composer">Message your assistant…</div></div>
          </div>
          <div class="vp3-product-base"></div>
        </div>
        <div class="vp3-product-phone">
          <div class="vp3-product-phone-screen"><small>VP3</small><h4>Good morning.<br>Here's what's next.</h4><span>Review new message</span><span>Draft proposal</span><span>Update project plan</span><span>Summarize research</span><span>Prepare for meeting</span></div>
        </div>
      </div>
      <div class="vp3-everything-copy">
        <div class="vp3-workforce-eyebrow dark">All your work, everywhere</div>
        <h2 id="everythingTitle">Everything you need.<br>Nothing in the way.</h2>
        <p>VP3 is available on desktop and mobile, so your assistants, content, and workflows are always with you.</p>
        <ul>
          <li>Meaningful work, not more tabs</li>
          <li>Your tools and content, in one place</li>
          <li>Secure, private, and built for teams</li>
          <li>Follow through from anywhere</li>
        </ul>
        <div class="vp3-workforce-cta-row dark-actions">
          <a class="vp3-workforce-btn dark" href="<?= e($vp3SignupUrl) ?>">Get started for free <span aria-hidden="true">→</span></a>
          <a class="vp3-workforce-btn outline" href="<?= e($vp3DemoUrl) ?>"><span class="vp3-play" aria-hidden="true">▶</span> Watch 2-min tour</a>
        </div>
        <small class="vp3-fineprint">No credit card required · Set up in minutes</small>
      </div>
    </div>
  </section>

  <section class="vp3-trust-section" aria-labelledby="trustTitle">
    <div class="vp3-workforce-wrap">
      <div class="vp3-workforce-section-head split compact">
        <div><div class="vp3-workforce-eyebrow dark">Trusted by modern teams</div><h2 id="trustTitle">Trusted by modern teams.</h2></div>
        <p>From innovative startups to global enterprises, VP3 helps teams of all sizes get more done with AI assistants that actually work.</p>
      </div>
      <div class="vp3-metric-grid" aria-label="VP3 platform activity">
        <div><strong>1.2M</strong><span>conversations processed</span></div>
        <div><strong>450K</strong><span>assistants deployed</span></div>
        <div><strong>12,000</strong><span>teams using VP3</span></div>
        <div><strong>50K</strong><span>integrations connected</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-homeserver-section" aria-labelledby="homeServerTitle">
    <div class="vp3-workforce-wrap vp3-homeserver-grid">
      <div class="vp3-homeserver-visual" aria-label="VP3 HomeServer private AI illustration">
        <div class="vp3-private-badge">◇ <span>Private by design</span></div>
        <div class="vp3-hs-monitor"><div><strong>VP3</strong><span>Chat</span><span>Assistants</span><span>Tools</span><span>Files</span><span>Settings</span></div></div>
        <div class="vp3-hs-box"><b>VP3</b><i></i></div>
        <div class="vp3-hs-phone"><div><strong>VP3</strong><span>My Assistant</span><span>Local Files</span><span>Knowledge</span><span>Automations</span></div></div>
        <span class="vp3-hs-line line-a"></span><span class="vp3-hs-line line-b"></span><span class="vp3-hs-line line-c"></span>
        <div class="vp3-control-badge">Your data. Your control.</div>
      </div>
      <div class="vp3-homeserver-copy">
        <div class="vp3-workforce-eyebrow dark">Private AI infrastructure</div>
        <h2 id="homeServerTitle">HomeServer keeps your<br>AI close to home.</h2>
        <p>Pair VP3 with HomeServer for self-hosted AI, secure data access, and private storage you control. Keep sensitive knowledge, files, tools, and workflows in a user-controlled environment while your VP3 assistants stay connected across desktop and mobile.</p>
        <ul>
          <li>Self-hosted AI and local private memory</li>
          <li>Secure data access with user-controlled permissions</li>
          <li>Private file, knowledge, and workflow storage</li>
          <li>Paired desktop and mobile assistants</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="vp3-difference-section" aria-labelledby="differenceTitle">
    <div class="vp3-workforce-wrap">
      <div class="vp3-difference-top">
        <div><div class="vp3-workforce-eyebrow light">The VP3 difference</div><h2 id="differenceTitle">The assistant<br>is the experience.</h2></div>
        <p>More than a tool. A new way of working. VP3 combines powerful AI, real-world integrations, and a human-centered design to help you move from conversation to impact.</p>
      </div>
      <div class="vp3-difference-grid">
        <article><span>▢</span><div><h3>Works with you</h3><p>Understands your context, tools, and goals — so every response is actually helpful.</p><a href="<?= e(url('/about.php')) ?>">Learn what makes VP3 different →</a></div></article>
        <article><span>◇</span><div><h3>Enterprise ready</h3><p>Your data stays yours, with security, privacy, and control built in from the ground up.</p><a href="<?= e(url('/privacy.php')) ?>">See security and trust →</a></div></article>
        <article><span>◎</span><div><h3>Real results</h3><p>Teams use VP3 to work faster, think bigger, and focus on what matters most.</p><a href="<?= e($vp3DemoUrl) ?>">See VP3 in action →</a></div></article>
      </div>
    </div>
  </section>

  <section class="vp3-community-section" aria-labelledby="communityTitle">
    <div class="vp3-workforce-wrap vp3-community-grid">
      <div><div class="vp3-workforce-eyebrow dark">Community</div><h2 id="communityTitle">Join the VP3 community.</h2><p>Be part of a growing group of builders, makers, and businesses shaping the future of work with AI. Share ideas, get inspired, and grow together.</p></div>
      <div class="vp3-community-action"><a class="vp3-workforce-btn dark full" href="<?= e($vp3SignupUrl) ?>">Join the community</a><small>No spam. Just product updates, resources, and more.</small></div>
    </div>
  </section>
</main>

<footer class="vp3-workforce-footer">
  <div class="vp3-workforce-wrap vp3-footer-grid">
    <a class="vp3-footer-logo" href="<?= e(url('/index.php')) ?>">VP3</a>
    <nav aria-label="Footer navigation"><a href="#product">Product</a><a href="#platform">Solutions</a><a href="<?= e(url('/transcriptions.php')) ?>">Resources</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a><a href="<?= e(url('/about.php')) ?>">About</a><a href="<?= e(url('/contact.php')) ?>">Contact</a></nav>
    <div class="vp3-footer-social" aria-label="Social links"><span>𝕏</span><span>in</span><span>▶</span></div>
  </div>
</footer>
</body>
</html>
