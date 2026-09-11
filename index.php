<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$signupUrl = url('/signup.php');
$demoUrl = url('/book-demo.php');
$loginUrl = url('/login.php');
$pricingUrl = url('/pricing.php');
$aboutUrl = url('/about.php');
$transcriptionsUrl = url('/transcriptions.php');
$teamsUrl = url('/teams.php');
$homeServerUrl = '#homeserver';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="VP3 AI Assistants bring transcription, AI summaries, teams, a personal profile agent, and self-hosted HomeServer AI into one private workspace.">
<meta name="theme-color" content="#0b0d0f">
<title>VP3 AI Assistants — Turn Every Conversation Into What’s Next</title>
<link rel="stylesheet" href="<?= e(url('/vp3-index-ai-assistants.css?v=20260910-2')) ?>">
</head>
<body>
<header class="site-header">
  <a class="brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home">VP3</a>
  <nav class="desktop-nav" aria-label="Primary navigation">
    <a href="#features">Product</a>
    <a href="#platform">Solutions</a>
    <a href="#homeserver">HomeServer</a>
    <a href="<?= e($pricingUrl) ?>">Pricing</a>
    <a href="<?= e($aboutUrl) ?>">About</a>
  </nav>
  <div class="header-actions">
    <a class="signin" href="<?= e($loginUrl) ?>">Log in</a>
    <a class="button button-light button-small" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
    <details class="mobile-menu">
      <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
      <nav aria-label="Mobile navigation">
        <a href="#features">Product</a>
        <a href="#platform">Solutions</a>
        <a href="#homeserver">HomeServer</a>
        <a href="<?= e($pricingUrl) ?>">Pricing</a>
        <a href="<?= e($aboutUrl) ?>">About</a>
        <a href="<?= e($loginUrl) ?>">Log in</a>
      </nav>
    </details>
  </div>
</header>

<main>
  <section class="hero" aria-labelledby="hero-title">
    <img class="hero-image" src="<?= e(url('/assets/home/hero-home-office.webp')) ?>" alt="Professional using VP3 from a warmly lit home office">
    <div class="hero-shade" aria-hidden="true"></div>
    <div class="hero-content wrap">
      <p class="eyebrow light">Your AI workforce</p>
      <h1 id="hero-title"><span>VP3 AI Assistants</span>Turn every conversation<br>into what’s next.</h1>
      <p class="hero-copy">Transcribe on desktop and mobile, get useful AI summaries, work with your team, and give your personal profile an AI agent — with private knowledge and HomeServer when you want AI on your own hardware.</p>
      <div class="hero-actions">
        <a class="button button-light" href="<?= e($signupUrl) ?>">Get started <span aria-hidden="true">→</span></a>
        <a class="button button-ghost" href="<?= e($demoUrl) ?>"><span class="play" aria-hidden="true">▶</span> Book a demo</a>
      </div>
      <div class="hero-notes" aria-label="VP3 product highlights">
        <span>✓ Desktop + mobile</span>
        <span>✓ Personal + team workspaces</span>
        <span>✓ Self-hosted AI option</span>
      </div>
    </div>
  </section>

  <section class="capability-strip" aria-label="VP3 capabilities">
    <div class="capability-track">
      <a href="<?= e($transcriptionsUrl) ?>"><span aria-hidden="true">≋</span>Transcription</a>
      <a href="#features"><span aria-hidden="true">▯</span>Mobile capture</a>
      <a href="#features"><span aria-hidden="true">▤</span>AI summaries</a>
      <a href="<?= e($teamsUrl) ?>"><span aria-hidden="true">◎</span>Teams</a>
      <a href="#platform"><span aria-hidden="true">♙</span>Profile agent</a>
      <a href="#platform"><span aria-hidden="true">↗</span>Personal link</a>
      <a href="#platform"><span aria-hidden="true">◇</span>Knowledge</a>
      <a href="#homeserver"><span aria-hidden="true">▣</span>HomeServer</a>
      <a href="#homeserver"><span aria-hidden="true">⌾</span>Private data</a>
      <a href="#platform"><span aria-hidden="true">✓</span>Action items</a>
    </div>
  </section>

  <section class="section" id="features" aria-labelledby="work-title">
    <div class="wrap">
      <div class="section-heading split-heading">
        <div>
          <p class="eyebrow">Built for real work</p>
          <h2 id="work-title">Built for real work.</h2>
        </div>
        <p>From a quick voice note to a long meeting, VP3 captures what matters, keeps the context, and helps turn the conversation into useful next steps.</p>
      </div>

      <div class="feature-grid">
        <article class="feature-card">
          <img src="<?= e(url('/assets/home/feature-transcription.webp')) ?>" alt="VP3 transcription workspace on a laptop" loading="lazy">
          <div class="card-copy">
            <p class="card-label">Desktop</p>
            <h3>Transcription, organized.</h3>
            <p>Record meetings, calls, interviews, and ideas. Keep the transcript searchable and connected to the work that follows.</p>
            <a href="<?= e($transcriptionsUrl) ?>">Explore transcription <span aria-hidden="true">→</span></a>
          </div>
        </article>
        <article class="feature-card">
          <img src="<?= e(url('/assets/home/feature-mobile.webp')) ?>" alt="VP3 mobile voice capture interface" loading="lazy">
          <div class="card-copy">
            <p class="card-label">Mobile</p>
            <h3>Capture anywhere.</h3>
            <p>Use your phone for voice notes and conversations, then keep the transcript, summary, and actions with you across devices.</p>
            <a href="#everything">See mobile workflow <span aria-hidden="true">→</span></a>
          </div>
        </article>
        <article class="feature-card">
          <img src="<?= e(url('/assets/home/feature-ai-summaries.webp')) ?>" alt="VP3 AI summary and product planning interface" loading="lazy">
          <div class="card-copy">
            <p class="card-label">AI</p>
            <h3>Summaries that matter.</h3>
            <p>Turn long conversations into key points, decisions, action items, open questions, and reusable project knowledge.</p>
            <a href="#platform">See what VP3 understands <span aria-hidden="true">→</span></a>
          </div>
        </article>
        <article class="feature-card">
          <img src="<?= e(url('/assets/home/feature-teams.webp')) ?>" alt="VP3 team workspace dashboard" loading="lazy">
          <div class="card-copy">
            <p class="card-label">Teams</p>
            <h3>Work together, better.</h3>
            <p>Share the right conversations and context with your team while keeping personal knowledge and permissions under control.</p>
            <a href="<?= e($teamsUrl) ?>">Explore teams <span aria-hidden="true">→</span></a>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="section section-soft" id="platform" aria-labelledby="platform-title">
    <div class="wrap">
      <div class="section-heading split-heading">
        <div>
          <p class="eyebrow">More than a chatbot</p>
          <h2 id="platform-title">A platform for how<br>you actually work.</h2>
        </div>
        <p>VP3 connects conversations, files, people, assistants, and private knowledge so the AI has useful context without turning your work into another pile of disconnected tools.</p>
      </div>
      <div class="value-grid">
        <article><span class="value-icon" aria-hidden="true">◉</span><h3>Your knowledge</h3><p>Keep transcripts, files, notes, and conversations connected so your assistants can work from context you control.</p></article>
        <article><span class="value-icon" aria-hidden="true">⌘</span><h3>Your work, unified</h3><p>Bring meetings, summaries, tasks, team conversations, and AI assistance into one workspace instead of another tab.</p></article>
        <article><span class="value-icon" aria-hidden="true">♙</span><h3>Your profile agent</h3><p>Share a personal VP3 link with an AI agent that can represent you, answer permitted questions, and help people connect with you.</p></article>
        <article><span class="value-icon" aria-hidden="true">▣</span><h3>Built for private AI</h3><p>Pair VP3 with HomeServer for local models, private tools, and secure access to knowledge that stays under your control.</p></article>
      </div>
    </div>
  </section>

  <section class="everything" id="everything" aria-labelledby="everything-title">
    <div class="wrap everything-grid">
      <div class="device-art">
        <img src="<?= e(url('/assets/home/devices-everything-you-need.webp')) ?>" alt="VP3 desktop and mobile apps shown together" loading="lazy">
      </div>
      <div class="everything-copy">
        <p class="eyebrow">All your work. Everywhere.</p>
        <h2 id="everything-title">Everything you need.<br>Nothing in the way.</h2>
        <p>VP3 stays with you from capture to follow-through. Start on your desktop, continue on your phone, and let the same assistant carry the context forward.</p>
        <ul class="check-list">
          <li><span>✓</span><div><b>Before</b><p>Prepare with your knowledge, people, and goals in context.</p></div></li>
          <li><span>✓</span><div><b>During</b><p>Capture voice and conversations and turn them into searchable text.</p></div></li>
          <li><span>✓</span><div><b>After</b><p>Summarize, organize, assign, and continue the work with your AI assistants.</p></div></li>
        </ul>
        <div class="inline-actions">
          <a class="button button-dark" href="<?= e($signupUrl) ?>">Get started <span aria-hidden="true">→</span></a>
          <a class="text-link" href="<?= e($demoUrl) ?>">See VP3 in action <span aria-hidden="true">→</span></a>
        </div>
      </div>
    </div>
  </section>

  <section class="proof" aria-label="VP3 platform highlights">
    <div class="wrap">
      <div class="proof-heading">
        <div><p class="eyebrow">One platform</p><h2>Built for how AI should work.</h2></div>
        <p>Use VP3 as an individual, bring it to a team, or pair it with HomeServer when private local compute and storage matter.</p>
      </div>
      <div class="proof-grid">
        <article><strong>Desktop + mobile</strong><span>Capture and continue anywhere</span></article>
        <article><strong>Personal profile agent</strong><span>Your link, your context, your rules</span></article>
        <article><strong>Self-hosted AI</strong><span>Local models and private tools with HomeServer</span></article>
        <article><strong>User-controlled data</strong><span>Private access, storage, and permissions</span></article>
      </div>
    </div>
  </section>

  <section class="homeserver" id="homeserver" aria-labelledby="homeserver-title">
    <div class="wrap homeserver-grid">
      <div class="homeserver-visual" aria-label="VP3 HomeServer private AI diagram">
        <div class="hs-badge">Private by design</div>
        <div class="hs-monitor"><span>VP3</span><i></i><i></i><i></i><i></i></div>
        <div class="hs-node"><b>VP3</b><small>HomeServer</small><em></em></div>
        <div class="hs-phone"><span>VP3</span><i>My assistant</i><i>Local files</i><i>Knowledge</i><i>Automations</i></div>
        <svg class="hs-lines" viewBox="0 0 620 360" role="presentation" aria-hidden="true"><path d="M205 180H300M370 180H465M335 100V150M335 215V285"/></svg>
        <div class="hs-data">Your data. Your control.</div>
      </div>
      <div class="homeserver-copy">
        <p class="eyebrow">Private AI infrastructure</p>
        <h2 id="homeserver-title">HomeServer keeps your<br>AI close to home.</h2>
        <p>Pair VP3 with HomeServer for self-hosted AI, secure data access, and private storage you control. Keep sensitive files, knowledge, tools, and workflows in a user-controlled environment while your VP3 assistants stay available across desktop and mobile.</p>
        <ul class="simple-list">
          <li><span>▣</span>Self-hosted AI and local private memory</li>
          <li><span>⌑</span>Secure access with user-controlled permissions</li>
          <li><span>□</span>Private file, knowledge, and workflow storage</li>
          <li><span>▱</span>Paired desktop and mobile assistants</li>
        </ul>
        <a class="button button-dark" href="<?= e($homeServerUrl) ?>">Explore HomeServer <span aria-hidden="true">→</span></a>
      </div>
    </div>
  </section>

  <section class="dark-section" aria-labelledby="experience-title">
    <div class="wrap">
      <div class="dark-heading">
        <div><p class="eyebrow light">The VP3 difference</p><h2 id="experience-title">The assistant<br>is the experience.</h2></div>
        <p>VP3 is designed around assistants that understand context, stay connected to the work, and operate within the access you give them.</p>
      </div>
      <div class="dark-grid">
        <article><span>01 · Individual</span><h3>An assistant that works with you.</h3><p>Capture thoughts and conversations, build useful personal knowledge, and give your profile an agent that can help people reach you.</p><a href="<?= e($signupUrl) ?>">Start with VP3 <b>→</b></a></article>
        <article><span>02 · Teams</span><h3>Shared context without the chaos.</h3><p>Bring assistants into team workspaces, preserve the important decisions, and move from conversation to coordinated action.</p><a href="<?= e($teamsUrl) ?>">Explore teams <b>→</b></a></article>
        <article><span>03 · Private AI</span><h3>Your intelligence on your terms.</h3><p>Pair with HomeServer to keep private knowledge, models, tools, and storage under your control while VP3 stays useful everywhere.</p><a href="<?= e($homeServerUrl) ?>">Explore HomeServer <b>→</b></a></article>
      </div>
    </div>
  </section>

  <section class="final-cta">
    <div class="wrap final-cta-grid">
      <div>
        <p class="eyebrow">Ready when you are</p>
        <h2>Put a VP3 assistant to work.</h2>
        <p>Create your account, capture your first conversation, and start building an AI workspace around the way you actually work.</p>
      </div>
      <div class="final-actions">
        <a class="button button-dark" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
        <a class="button button-outline" href="<?= e($demoUrl) ?>">Book a demo</a>
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <div class="wrap footer-grid">
    <div class="footer-brand"><strong>VP3</strong><span>AI assistants for real work.</span></div>
    <nav aria-label="Footer navigation">
      <a href="#features">Product</a>
      <a href="<?= e($teamsUrl) ?>">Teams</a>
      <a href="#homeserver">HomeServer</a>
      <a href="<?= e($pricingUrl) ?>">Pricing</a>
      <a href="<?= e($aboutUrl) ?>">About</a>
      <a href="<?= e(url('/privacy.php')) ?>">Privacy</a>
      <a href="<?= e(url('/terms.php')) ?>">Terms</a>
      <a href="<?= e(url('/contact.php')) ?>">Contact</a>
    </nav>
  </div>
</footer>
</body>
</html>
