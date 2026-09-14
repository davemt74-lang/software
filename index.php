<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$signupUrl = url('/signup.php');
$demoUrl = url('/book-demo.php');
$loginUrl = url('/login.php');
$pricingUrl = url('/pricing.php');
$aboutUrl = url('/about.php');
$contactUrl = url('/contact.php');
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
<link rel="stylesheet" href="<?= e(url('/vp3-index-mega-menu.css?v=20260914-1')) ?>">
<script src="<?= e(url('/vp3-index-mega-menu.js?v=20260914-1')) ?>" defer></script>
</head>
<body>
<header class="site-header">
  <a class="brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home">VP3</a>
  <nav class="desktop-nav mega-nav" aria-label="Primary navigation">
    <details>
      <summary>Products</summary>
      <div class="mega-panel">
        <div class="mega-panel-head"><div><span class="mega-panel-kicker">VP3 products</span><strong>Your AI workspace, identity, and private infrastructure.</strong></div><p>Start in the Cloud, add a public AI presence, or pair HomeServer when you want private local capability.</p></div>
        <div class="mega-grid">
          <a class="mega-link" href="#features"><span class="mega-link-icon">✦</span><strong>AI Assistant</strong><small>Capture conversations, keep context, and move work forward with an assistant that understands your workspace.</small></a>
          <a class="mega-link" href="#platform"><span class="mega-link-icon">↗</span><strong>Personal URL</strong><small>Your shareable VP3 presence for people, work, booking, products, and your public Agent.</small></a>
          <a class="mega-link" href="#platform"><span class="mega-link-icon">◎</span><strong>Profile Agent</strong><small>An AI Agent on your public profile that can answer permitted questions and help people connect with you.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">▣</span><strong>HomeServer</strong><small>Pair private knowledge, local models, tools, skills, and user-controlled storage with VP3 Cloud.</small></a>
        </div>
        <div class="mega-panel-footer"><span>One VP3 account can connect your public identity, Cloud workspace, and private HomeServer.</span><a href="<?= e($signupUrl) ?>">Create your VP3 account →</a></div>
      </div>
    </details>

    <details>
      <summary>Services</summary>
      <div class="mega-panel">
        <div class="mega-panel-head"><div><span class="mega-panel-kicker">Services</span><strong>Tools that turn conversations into real work.</strong></div><p>Use VP3 for capture, understanding, collaboration, scheduling, paid services, and commerce.</p></div>
        <div class="mega-grid cols-3">
          <a class="mega-link" href="<?= e($transcriptionsUrl) ?>"><span class="mega-link-icon">≋</span><strong>Transcription</strong><small>Record meetings, calls, interviews, and ideas and keep the transcript searchable.</small></a>
          <a class="mega-link" href="#features"><span class="mega-link-icon">▤</span><strong>AI Summary</strong><small>Turn long conversations into decisions, key points, action items, questions, and reusable knowledge.</small></a>
          <a class="mega-link" href="<?= e($teamsUrl) ?>"><span class="mega-link-icon">◌</span><strong>Teams</strong><small>Share the right context with teammates while preserving account, data, and permission boundaries.</small></a>
          <a class="mega-link" href="#everything"><span class="mega-link-icon">□</span><strong>Calendar</strong><small>Keep schedules and Agent-aware calendar workflows connected to the rest of your VP3 workspace.</small></a>
          <a class="mega-link" href="#everything"><span class="mega-link-icon">✓</span><strong>Booking · Free + Paid</strong><small>Offer public appointment types, availability, lifecycle management, and paid or unpaid booking flows.</small></a>
          <a class="mega-link" href="#platform"><span class="mega-link-icon">◇</span><strong>E-commerce</strong><small>Publish products and let VP3 connect commerce, customer activity, Agent workflows, and follow-through.</small></a>
        </div>
      </div>
    </details>

    <details>
      <summary>HomeServer</summary>
      <div class="mega-panel">
        <div class="mega-panel-head"><div><span class="mega-panel-kicker">Private AI infrastructure</span><strong>Cloud convenience. Self-hosted control.</strong></div><p>VP3 HomeServer is the private capability layer for knowledge, tools, models, and authorized paired applications.</p></div>
        <div class="mega-grid cols-3">
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">☁</span><strong>Cloud vs Self-hosted</strong><small>Use VP3 Cloud where it makes sense and keep sensitive capability local when privacy or control matters.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">↔</span><strong>OpenRouter + Model Choice</strong><small>Choose the model/provider path that fits the task instead of locking your workspace to one AI vendor.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">▦</span><strong>Local Knowledge</strong><small>Keep private files and indexed knowledge on your own machine while exposing only authorized capability.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">⌘</span><strong>Private Tools + Skills</strong><small>Give paired Agents access to local tools, skills, workflows, and services through explicit permissions.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">◫</span><strong>Paired Apps + Devices</strong><small>Connect authorized VP3 front ends through the same private HomeServer capability platform.</small></a>
          <a class="mega-link" href="#homeserver"><span class="mega-link-icon">◉</span><strong>Privacy + Control</strong><small>Keep native paths, private data, local approvals, and sensitive execution under your control.</small></a>
        </div>
        <div class="mega-panel-footer"><span>HomeServer complements VP3 Cloud; it does not create a second user identity or disconnected Agent system.</span><a href="#homeserver">Explore HomeServer →</a></div>
      </div>
    </details>

    <details>
      <summary>Pricing</summary>
      <div class="mega-panel">
        <div class="mega-panel-head"><div><span class="mega-panel-kicker">Flexible access</span><strong>Choose the way you want to use VP3.</strong></div><p>Compare recurring access and AI usage options, then choose the cadence that fits your work.</p></div>
        <div class="mega-grid">
          <a class="mega-link" href="<?= e($pricingUrl) ?>"><span class="mega-link-icon">M</span><strong>Monthly</strong><small>Simple month-to-month VP3 access for individual and ongoing use.</small></a>
          <a class="mega-link" href="<?= e($pricingUrl) ?>"><span class="mega-link-icon">W</span><strong>Weekly</strong><small>Shorter-duration access for projects, events, temporary teams, and focused work.</small></a>
          <a class="mega-link" href="<?= e($pricingUrl) ?>"><span class="mega-link-icon">Y</span><strong>Yearly</strong><small>Long-term access for people and teams building VP3 into their regular workflow.</small></a>
          <a class="mega-link" href="<?= e($pricingUrl) ?>"><span class="mega-link-icon">AI</span><strong>Token Packages</strong><small>Add AI usage when you need more model capacity without changing the rest of your workspace.</small></a>
        </div>
        <div class="mega-panel-footer"><span>See plans, AI usage, and available billing options in one place.</span><a href="<?= e($pricingUrl) ?>">View pricing →</a></div>
      </div>
    </details>

    <details>
      <summary>About</summary>
      <div class="mega-panel">
        <div class="mega-panel-head"><div><span class="mega-panel-kicker">About VP3</span><strong>Why we are building personal AI differently.</strong></div><p>Meet the people and ideas behind VP3, see how it is used, and find the right way to reach us.</p></div>
        <div class="mega-grid cols-3">
          <a class="mega-link" href="<?= e($aboutUrl) ?>"><span class="mega-link-icon">◎</span><strong>Team</strong><small>Meet the people building VP3 and the experience behind the product.</small></a>
          <a class="mega-link" href="<?= e($aboutUrl) ?>"><span class="mega-link-icon">✦</span><strong>Mission</strong><small>Our approach to useful AI, user-controlled knowledge, private capability, and real-world work.</small></a>
          <a class="mega-link" href="<?= e($aboutUrl) ?>"><span class="mega-link-icon">▤</span><strong>Case Studies</strong><small>See practical ways individuals, teams, and operators can put VP3 to work.</small></a>
          <a class="mega-link" href="<?= e($aboutUrl) ?>"><span class="mega-link-icon">“</span><strong>Testimonials</strong><small>Hear from people using VP3 workflows, Agents, and private AI capability.</small></a>
          <a class="mega-link" href="<?= e($contactUrl) ?>"><span class="mega-link-icon">✉</span><strong>Contact Us</strong><small>Questions, partnerships, demos, support, or something you want to build with VP3.</small></a>
          <a class="mega-link" href="<?= e($contactUrl) ?>"><span class="mega-link-icon">#</span><strong>Social Links</strong><small>Find VP3 around the web and follow product, company, and community updates.</small></a>
        </div>
      </div>
    </details>
  </nav>
  <div class="header-actions">
    <a class="signin" href="<?= e($loginUrl) ?>">Log in</a>
    <a class="button button-light button-small" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
    <details class="mobile-menu">
      <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
      <nav class="mobile-mega-nav" aria-label="Mobile navigation">
        <details class="mobile-mega-section">
          <summary>Products</summary>
          <div class="mobile-mega-list">
            <a href="#features"><strong>AI Assistant</strong><span>Context-aware help for real work.</span></a>
            <a href="#platform"><strong>Personal URL</strong><span>Your shareable VP3 presence.</span></a>
            <a href="#platform"><strong>Profile Agent</strong><span>Your public AI Agent.</span></a>
            <a href="#homeserver"><strong>HomeServer</strong><span>Private local capability.</span></a>
          </div>
        </details>
        <details class="mobile-mega-section">
          <summary>Services</summary>
          <div class="mobile-mega-list">
            <a href="<?= e($transcriptionsUrl) ?>"><strong>Transcription</strong><span>Searchable conversation capture.</span></a>
            <a href="#features"><strong>AI Summary</strong><span>Decisions, actions, and insights.</span></a>
            <a href="<?= e($teamsUrl) ?>"><strong>Teams</strong><span>Shared context and collaboration.</span></a>
            <a href="#everything"><strong>Calendar</strong><span>Agent-aware scheduling.</span></a>
            <a href="#everything"><strong>Booking</strong><span>Paid and unpaid appointments.</span></a>
            <a href="#platform"><strong>E-commerce</strong><span>Products and Agent commerce.</span></a>
          </div>
        </details>
        <details class="mobile-mega-section">
          <summary>HomeServer</summary>
          <div class="mobile-mega-list">
            <a href="#homeserver"><strong>Cloud vs Self-hosted</strong><span>Use the right boundary for each job.</span></a>
            <a href="#homeserver"><strong>OpenRouter</strong><span>Model and provider choice.</span></a>
            <a href="#homeserver"><strong>Local Knowledge</strong><span>Private files stay local.</span></a>
            <a href="#homeserver"><strong>Tools + Skills</strong><span>Authorized local capability.</span></a>
            <a href="#homeserver"><strong>Paired Apps</strong><span>One private capability platform.</span></a>
            <a href="#homeserver"><strong>Privacy + Control</strong><span>Your data, permissions, and approvals.</span></a>
          </div>
        </details>
        <details class="mobile-mega-section">
          <summary>Pricing</summary>
          <div class="mobile-mega-list">
            <a href="<?= e($pricingUrl) ?>"><strong>Monthly</strong><span>Month-to-month access.</span></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Weekly</strong><span>Short-term project access.</span></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Yearly</strong><span>Long-term VP3 access.</span></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Token Packages</strong><span>Add AI usage when needed.</span></a>
          </div>
        </details>
        <details class="mobile-mega-section">
          <summary>About</summary>
          <div class="mobile-mega-list">
            <a href="<?= e($aboutUrl) ?>"><strong>Team</strong><span>People behind VP3.</span></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Mission</strong><span>Why we are building VP3.</span></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Case Studies</strong><span>VP3 in real workflows.</span></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Testimonials</strong><span>What users are saying.</span></a>
            <a href="<?= e($contactUrl) ?>"><strong>Contact Us</strong><span>Talk with VP3.</span></a>
            <a href="<?= e($contactUrl) ?>"><strong>Social Links</strong><span>Find VP3 around the web.</span></a>
          </div>
        </details>
        <div class="mobile-menu-actions">
          <a href="<?= e($loginUrl) ?>">Log in</a>
          <a href="<?= e($signupUrl) ?>">Get VP3 →</a>
        </div>
      </nav>
    </details>
  </div>
</header>

<main>
  <section class="hero" aria-labelledby="hero-title">
    <img class="hero-image" src="<?= e(url('/assets/home/vp3-main-header_bg.png')) ?>" alt="VP3 AI Assistants home office hero">
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
      <a href="<?= e($contactUrl) ?>">Contact</a>
    </nav>
  </div>
</footer>
</body>
</html>