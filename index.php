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
$productUrl = '#platform';
$servicesUrl = '#features';
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
</head>
<body>
<header class="site-header">
  <a class="brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home">VP3</a>

  <nav class="desktop-nav mega-nav" aria-label="Primary navigation">
    <details class="mega-item">
      <summary>Product</summary>
      <div class="mega-panel">
        <div class="mega-intro">
          <span class="mega-kicker">Product</span>
          <h2>Your AI identity, workspace, and agent.</h2>
          <p>VP3 connects your assistant, public profile, personal URL, private knowledge, and optional HomeServer into one system.</p>
          <a class="mega-intro-link" href="<?= e($productUrl) ?>">Explore the VP3 platform <span aria-hidden="true">→</span></a>
        </div>
        <div class="mega-columns cols-2">
          <div class="mega-column">
            <span class="mega-column-label">Your assistant</span>
            <a class="mega-link" href="<?= e($productUrl) ?>"><strong>AI Assistant</strong><small>Your primary agent for conversations, knowledge, tasks, workflows, and follow-through.</small></a>
            <a class="mega-link" href="<?= e($productUrl) ?>"><strong>Profile Agent</strong><small>An AI agent on your public profile that can represent you within the permissions you set.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Your presence</span>
            <a class="mega-link" href="<?= e($productUrl) ?>"><strong>Personal URL</strong><small>A shareable VP3 profile and agent destination for people, customers, and collaborators.</small></a>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>HomeServer</strong><small>Pair your VP3 account with private local knowledge, tools, models, and compute.</small></a>
          </div>
        </div>
      </div>
    </details>

    <details class="mega-item">
      <summary>Services</summary>
      <div class="mega-panel">
        <div class="mega-intro">
          <span class="mega-kicker">Services</span>
          <h2>From conversation to coordinated action.</h2>
          <p>Capture, understand, schedule, sell, collaborate, and let your Agent keep the context connected across the work.</p>
          <a class="mega-intro-link" href="<?= e($servicesUrl) ?>">See VP3 services <span aria-hidden="true">→</span></a>
        </div>
        <div class="mega-columns">
          <div class="mega-column">
            <span class="mega-column-label">Capture + understand</span>
            <a class="mega-link" href="<?= e($transcriptionsUrl) ?>"><strong>Transcription</strong><small>Record and organize meetings, calls, interviews, voice notes, and other conversations.</small></a>
            <a class="mega-link" href="#features"><strong>AI Summary</strong><small>Turn long conversations into decisions, action items, questions, and reusable knowledge.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Coordinate</span>
            <a class="mega-link" href="<?= e($teamsUrl) ?>"><strong>Teams</strong><small>Shared workspaces, conversations, permissions, context, and collaborative Agent workflows.</small></a>
            <a class="mega-link" href="#platform"><strong>Calendar</strong><small>Availability, calendar intelligence, sync, scheduling context, and Agent-managed coordination.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Book + sell</span>
            <a class="mega-link" href="#platform"><strong>Booking — free or paid</strong><small>Public scheduling, appointment lifecycle, deposits, paid appointments, reminders, and follow-up.</small></a>
            <a class="mega-link" href="#platform"><strong>Ecommerce</strong><small>Public products, checkout, paid orders, fulfillment, refunds, seller alerts, and Agent commerce.</small></a>
          </div>
        </div>
      </div>
    </details>

    <details class="mega-item">
      <summary>HomeServer</summary>
      <div class="mega-panel">
        <div class="mega-intro">
          <span class="mega-kicker">HomeServer</span>
          <h2>Use the Cloud, self-host, or connect both.</h2>
          <p>Keep VP3 convenient in the Cloud while moving private knowledge, tools, models, and local capabilities onto hardware you control.</p>
          <a class="mega-intro-link" href="<?= e($homeServerUrl) ?>">Explore HomeServer <span aria-hidden="true">→</span></a>
        </div>
        <div class="mega-columns">
          <div class="mega-column">
            <span class="mega-column-label">Deployment</span>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>Cloud vs. self-hosted</strong><small>Choose Cloud convenience, local privacy, or a paired hybrid setup with clear authority boundaries.</small></a>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>Paired devices</strong><small>Connect approved VP3 front ends to your private HomeServer capability platform.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Models + compute</span>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>OpenRouter</strong><small>Use provider/model choice through VP3 while keeping HomeServer available for local/private execution.</small></a>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>Model choice</strong><small>Route work to Cloud or local models based on privacy, capability, availability, and cost.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Private capabilities</span>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>Local Knowledge</strong><small>Keep native local files and private collections authoritative on HomeServer.</small></a>
            <a class="mega-link" href="<?= e($homeServerUrl) ?>"><strong>Tools + skills</strong><small>Give paired Agents approved local capabilities without exposing private filesystem paths to VP3 Cloud.</small></a>
          </div>
        </div>
      </div>
    </details>

    <details class="mega-item">
      <summary>Pricing</summary>
      <div class="mega-panel mega-panel-pricing">
        <div class="mega-intro">
          <span class="mega-kicker">Pricing</span>
          <h2>Use VP3 on the cadence that fits.</h2>
          <p>Account access, usage, and AI capacity can be matched to how often and how deeply you use your Agent.</p>
          <a class="mega-intro-link" href="<?= e($pricingUrl) ?>">View pricing <span aria-hidden="true">→</span></a>
        </div>
        <div class="mega-columns cols-4">
          <a class="mega-price" href="<?= e($pricingUrl) ?>"><strong>Monthly</strong><small>A simple recurring plan for regular VP3 use.</small><b>View monthly →</b></a>
          <a class="mega-price" href="<?= e($pricingUrl) ?>"><strong>Weekly</strong><small>Short-cycle access when your usage is project-based or changing.</small><b>View weekly →</b></a>
          <a class="mega-price" href="<?= e($pricingUrl) ?>"><strong>Yearly</strong><small>Longer-term access for individuals and teams committed to VP3.</small><b>View yearly →</b></a>
          <a class="mega-price" href="<?= e($pricingUrl) ?>"><strong>Token packages</strong><small>Add AI capacity for heavier conversations, workflows, and model usage.</small><b>View tokens →</b></a>
        </div>
      </div>
    </details>

    <details class="mega-item">
      <summary>About</summary>
      <div class="mega-panel mega-panel-about">
        <div class="mega-intro">
          <span class="mega-kicker">About VP3</span>
          <h2>Private, useful AI built around the person.</h2>
          <p>Learn who is building VP3, why we are building it, how people use it, and how to reach us.</p>
          <a class="mega-intro-link" href="<?= e($aboutUrl) ?>">About VP3 <span aria-hidden="true">→</span></a>
        </div>
        <div class="mega-columns">
          <div class="mega-column">
            <span class="mega-column-label">Company</span>
            <a class="mega-link" href="<?= e($aboutUrl) ?>"><strong>Team</strong><small>Meet the people building VP3.</small></a>
            <a class="mega-link" href="<?= e($aboutUrl) ?>"><strong>Mission</strong><small>Why user-controlled AI, identity, knowledge, and capability matter.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Stories</span>
            <a class="mega-link" href="<?= e($aboutUrl) ?>"><strong>Case studies</strong><small>See how VP3 can fit real personal, team, scheduling, and commerce workflows.</small></a>
            <a class="mega-link" href="<?= e($aboutUrl) ?>"><strong>Testimonials</strong><small>Feedback and experiences from people using the platform.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Connect</span>
            <a class="mega-link" href="<?= e($contactUrl) ?>"><strong>Contact us</strong><small>Questions, partnerships, demos, support, and general inquiries.</small></a>
            <a class="mega-link" href="<?= e($aboutUrl) ?>#social"><strong>Social links</strong><small>Follow VP3 and the team across our public channels.</small></a>
          </div>
        </div>
      </div>
    </details>
  </nav>

  <div class="header-actions">
    <a class="signin" href="<?= e($loginUrl) ?>">Log in</a>
    <a class="button button-light button-small" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
    <details class="mobile-menu">
      <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
      <nav class="mega-mobile-nav" aria-label="Mobile navigation">
        <details class="mobile-nav-group">
          <summary>Product</summary>
          <div class="mobile-nav-links">
            <a href="#platform"><strong>AI Assistant</strong><small>Your main VP3 agent and workspace.</small></a>
            <a href="#platform"><strong>Personal URL</strong><small>Your shareable VP3 destination.</small></a>
            <a href="#platform"><strong>Profile Agent</strong><small>An AI agent on your public profile.</small></a>
            <a href="#homeserver"><strong>HomeServer</strong><small>Private local knowledge and capabilities.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>Services</summary>
          <div class="mobile-nav-links">
            <a href="<?= e($transcriptionsUrl) ?>"><strong>Transcription</strong><small>Capture and organize conversations.</small></a>
            <a href="#features"><strong>AI Summary</strong><small>Decisions, actions, and reusable knowledge.</small></a>
            <a href="<?= e($teamsUrl) ?>"><strong>Teams</strong><small>Shared context and collaborative workspaces.</small></a>
            <a href="#platform"><strong>Calendar</strong><small>Availability and calendar intelligence.</small></a>
            <a href="#platform"><strong>Booking</strong><small>Free and paid appointment workflows.</small></a>
            <a href="#platform"><strong>Ecommerce</strong><small>Products, orders, checkout, and fulfillment.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>HomeServer</summary>
          <div class="mobile-nav-links">
            <a href="#homeserver"><strong>Cloud vs. self-hosted</strong><small>Cloud, local, or paired hybrid operation.</small></a>
            <a href="#homeserver"><strong>OpenRouter</strong><small>Provider and model choice.</small></a>
            <a href="#homeserver"><strong>Local Knowledge</strong><small>Private files stay locally authoritative.</small></a>
            <a href="#homeserver"><strong>Tools + skills</strong><small>Approved local capabilities for paired Agents.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>Pricing</summary>
          <div class="mobile-nav-links">
            <a href="<?= e($pricingUrl) ?>"><strong>Monthly</strong><small>Recurring monthly access.</small></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Weekly</strong><small>Short-cycle project access.</small></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Yearly</strong><small>Long-term individual or team access.</small></a>
            <a href="<?= e($pricingUrl) ?>"><strong>Token packages</strong><small>Add AI usage capacity.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>About</summary>
          <div class="mobile-nav-links">
            <a href="<?= e($aboutUrl) ?>"><strong>Team</strong><small>Meet the people building VP3.</small></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Mission</strong><small>Why VP3 exists.</small></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Case studies</strong><small>Real workflow examples.</small></a>
            <a href="<?= e($aboutUrl) ?>"><strong>Testimonials</strong><small>What people say about VP3.</small></a>
            <a href="<?= e($contactUrl) ?>"><strong>Contact us</strong><small>Demos, partnerships, and questions.</small></a>
            <a href="<?= e($aboutUrl) ?>#social"><strong>Social links</strong><small>Follow VP3 and the team.</small></a>
          </div>
        </details>
        <div class="mobile-nav-actions">
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
<script>
(() => {
  const desktopNav = document.querySelector('.mega-nav');
  if (desktopNav) {
    const items = [...desktopNav.querySelectorAll('.mega-item')];
    items.forEach((item) => item.addEventListener('toggle', () => {
      if (!item.open) return;
      items.forEach((other) => {
        if (other !== item) other.removeAttribute('open');
      });
    }));
    document.addEventListener('click', (event) => {
      if (!desktopNav.contains(event.target)) items.forEach((item) => item.removeAttribute('open'));
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') items.forEach((item) => item.removeAttribute('open'));
    });
  }

  const mobileMenu = document.querySelector('.mobile-menu');
  if (mobileMenu) {
    const groups = [...mobileMenu.querySelectorAll('.mobile-nav-group')];
    groups.forEach((group) => group.addEventListener('toggle', () => {
      if (!group.open) return;
      groups.forEach((other) => {
        if (other !== group) other.removeAttribute('open');
      });
    }));
    mobileMenu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
      mobileMenu.removeAttribute('open');
    }));
  }
})();
</script>
</body>
</html>
