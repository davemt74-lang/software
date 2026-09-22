<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();
vp3_funnel_capture_public_source('index');

$signupUrl = vp3_funnel_url('/signup.php');
$demoUrl = vp3_funnel_url('/book-demo.php');
$loginUrl = url('/login.php');
$pricingUrl = url('/pricing.php');
$aboutUrl = url('/about.php');
$contactUrl = url('/contact.php');
$transcriptionsUrl = url('/transcriptions.php');
$teamsUrl = url('/teams.php');
$homeServerUrl = url('/homeserver.php');
$productUrl = url('/product.php');
$servicesUrl = url('/services.php');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="VP3 connects Browser Companion, annotations, meetings, transcription, teams, booking, ecommerce, Agent Analytics, and HomeServer through one AI Agent and cognitive runtime.">
<meta name="theme-color" content="#0b0d0f">
<link rel="canonical" href="<?= e(url('/index.php')) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="VP3">
<meta property="og:title" content="VP3 AI Assistants — From Signal to Outcome">
<meta property="og:description" content="One VP3 Agent across the browser, meetings, profile, teams, scheduling, commerce, analytics, and private HomeServer capabilities.">
<meta property="og:url" content="<?= e(url('/index.php')) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="VP3 AI Assistants — From Signal to Outcome">
<meta name="twitter:description" content="One VP3 Agent across the browser, meetings, profile, teams, scheduling, commerce, analytics, and HomeServer.">
<title>VP3 AI Assistants — From Signal to Outcome</title>
<link rel="stylesheet" href="<?= e(url('/vp3-index-ai-assistants.css?v=20260921-3')) ?>">
<script defer src="<?= e(url('/vp3-public-funnel.js?v=20260921-1')) ?>" data-vp3-funnel-endpoint="<?= e(url('/api/public-funnel-event.php')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('/vp3-index-mega-menu.css?v=20260914-2')) ?>">
</head>
<body>
<header class="site-header">
  <a class="brand vp3-home-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home"><?= vp3_public_brand() ?></a>

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
            <a class="mega-link" href="<?= e(url('/ai-assistant.php')) ?>"><strong>AI Assistant</strong><small>Your primary agent for conversations, knowledge, tasks, workflows, and follow-through.</small></a>
            <a class="mega-link" href="<?= e(url('/profile-agent-overview.php')) ?>"><strong>Profile Agent</strong><small>An AI agent on your public profile that can represent you within the permissions you set.</small></a>
            <a class="mega-link" href="<?= e(url('/chrome-extension.php')) ?>"><strong>Chrome Extension</strong><small>Bring VP3 Browser Companion into Chrome for page-aware Agent help, research, notifications, and approved browser actions.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Your presence</span>
            <a class="mega-link" href="<?= e(url('/personal-url.php')) ?>"><strong>Personal URL</strong><small>A shareable VP3 profile and agent destination for people, customers, and collaborators.</small></a>
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
            <a class="mega-link" href="<?= e(url('/ai-summary.php')) ?>"><strong>AI Summary</strong><small>Turn long conversations into decisions, action items, questions, and reusable knowledge.</small></a>
            <a class="mega-link" href="<?= e(url('/annotations.php')) ?>"><strong>Annotations</strong><small>Capture highlights, screenshots, notes, and source context from the web, then organize and reuse them in VP3 research.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Coordinate</span>
            <a class="mega-link" href="<?= e($teamsUrl) ?>"><strong>Teams</strong><small>Shared workspaces, conversations, permissions, context, and collaborative Agent workflows.</small></a>
            <a class="mega-link" href="<?= e(url('/video-meetings.php')) ?>"><strong>Meetings</strong><small>Video meetings with transcripts, Meeting Intelligence, action items, follow-through, and connected Agent context.</small></a>
            <a class="mega-link" href="<?= e(url('/calendar-service.php')) ?>"><strong>Calendar</strong><small>Availability, calendar intelligence, sync, scheduling context, and Agent-managed coordination.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Book + sell</span>
            <a class="mega-link" href="<?= e(url('/booking.php')) ?>"><strong>Booking — free or paid</strong><small>Public scheduling, appointment lifecycle, deposits, paid appointments, reminders, and follow-up.</small></a>
            <a class="mega-link" href="<?= e(url('/ecommerce.php')) ?>"><strong>Ecommerce</strong><small>Public products, checkout, paid orders, fulfillment, refunds, seller alerts, and Agent commerce.</small></a>
            <a class="mega-link" href="<?= e(url('/agent-analytics.php')) ?>"><strong>Agent Analytics</strong><small>See profile visits, booking and product intent, conversions, attributed revenue, traffic sources, and Agent-identified opportunities.</small></a>
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
            <a class="mega-link" href="<?= e(url('/cloud-vs-self-hosted.php')) ?>"><strong>Cloud vs. self-hosted</strong><small>Choose Cloud convenience, local privacy, or a paired hybrid setup with clear authority boundaries.</small></a>
            <a class="mega-link" href="<?= e(url('/paired-devices.php')) ?>"><strong>Paired devices</strong><small>Connect approved VP3 front ends to your private HomeServer capability platform.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Models + compute</span>
            <a class="mega-link" href="<?= e(url('/openrouter.php')) ?>"><strong>OpenRouter</strong><small>Use provider/model choice through VP3 while keeping HomeServer available for local/private execution.</small></a>
            <a class="mega-link" href="<?= e(url('/model-choice.php')) ?>"><strong>Model choice</strong><small>Route work to Cloud or local models based on privacy, capability, availability, and cost.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Private capabilities</span>
            <a class="mega-link" href="<?= e(url('/local-knowledge-overview.php')) ?>"><strong>Local Knowledge</strong><small>Keep native local files and private collections authoritative on HomeServer.</small></a>
            <a class="mega-link" href="<?= e(url('/tools-skills.php')) ?>"><strong>Tools + skills</strong><small>Give paired Agents approved local capabilities without exposing private filesystem paths to VP3 Cloud.</small></a>
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
          <a class="mega-price" href="<?= e(url('/pricing-monthly.php')) ?>"><strong>Monthly</strong><small>A simple recurring plan for regular VP3 use.</small><b>View monthly →</b></a>
          <a class="mega-price" href="<?= e(url('/pricing-weekly.php')) ?>"><strong>Weekly</strong><small>Short-cycle access when your usage is project-based or changing.</small><b>View weekly →</b></a>
          <a class="mega-price" href="<?= e(url('/pricing-yearly.php')) ?>"><strong>Yearly</strong><small>Longer-term access for individuals and teams committed to VP3.</small><b>View yearly →</b></a>
          <a class="mega-price" href="<?= e(url('/token-packages.php')) ?>"><strong>Token packages</strong><small>Add AI capacity for heavier conversations, workflows, and model usage.</small><b>View tokens →</b></a>
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
            <a class="mega-link" href="<?= e(url('/about-team.php')) ?>"><strong>Team</strong><small>Meet the people building VP3.</small></a>
            <a class="mega-link" href="<?= e(url('/mission.php')) ?>"><strong>Mission</strong><small>Why user-controlled AI, identity, knowledge, and capability matter.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Stories</span>
            <a class="mega-link" href="<?= e(url('/case-studies.php')) ?>"><strong>Case studies</strong><small>See how VP3 can fit real personal, team, scheduling, and commerce workflows.</small></a>
            <a class="mega-link" href="<?= e(url('/testimonials.php')) ?>"><strong>Testimonials</strong><small>Feedback and experiences from people using the platform.</small></a>
          </div>
          <div class="mega-column">
            <span class="mega-column-label">Connect</span>
            <a class="mega-link" href="<?= e($contactUrl) ?>"><strong>Contact us</strong><small>Questions, partnerships, demos, support, and general inquiries.</small></a>
            <a class="mega-link" href="<?= e(url('/social.php')) ?>"><strong>Social links</strong><small>Follow VP3 and the team across our public channels.</small></a>
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
            <a href="<?= e(url('/ai-assistant.php')) ?>"><strong>AI Assistant</strong><small>Your main VP3 agent and workspace.</small></a>
            <a href="<?= e(url('/personal-url.php')) ?>"><strong>Personal URL</strong><small>Your shareable VP3 destination.</small></a>
            <a href="<?= e(url('/profile-agent-overview.php')) ?>"><strong>Profile Agent</strong><small>An AI agent on your public profile.</small></a>
            <a href="<?= e(url('/chrome-extension.php')) ?>"><strong>Chrome Extension</strong><small>VP3 Browser Companion across the web.</small></a>
            <a href="<?= e(url('/homeserver.php')) ?>"><strong>HomeServer</strong><small>Private local knowledge and capabilities.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>Services</summary>
          <div class="mobile-nav-links">
            <a href="<?= e($transcriptionsUrl) ?>"><strong>Transcription</strong><small>Capture and organize conversations.</small></a>
            <a href="<?= e(url('/ai-summary.php')) ?>"><strong>AI Summary</strong><small>Decisions, actions, and reusable knowledge.</small></a>
            <a href="<?= e(url('/annotations.php')) ?>"><strong>Annotations</strong><small>Capture web highlights, screenshots, notes, and source context.</small></a>
            <a href="<?= e($teamsUrl) ?>"><strong>Teams</strong><small>Shared context and collaborative workspaces.</small></a>
            <a href="<?= e(url('/video-meetings.php')) ?>"><strong>Meetings</strong><small>Video meetings, intelligence, and follow-through.</small></a>
            <a href="<?= e(url('/calendar-service.php')) ?>"><strong>Calendar</strong><small>Availability and calendar intelligence.</small></a>
            <a href="<?= e(url('/booking.php')) ?>"><strong>Booking</strong><small>Free and paid appointment workflows.</small></a>
            <a href="<?= e(url('/ecommerce.php')) ?>"><strong>Ecommerce</strong><small>Products, orders, checkout, and fulfillment.</small></a>
            <a href="<?= e(url('/agent-analytics.php')) ?>"><strong>Agent Analytics</strong><small>Visits, intent, conversions, revenue, sources, and opportunities.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>HomeServer</summary>
          <div class="mobile-nav-links">
            <a href="<?= e(url('/cloud-vs-self-hosted.php')) ?>"><strong>Cloud vs. self-hosted</strong><small>Cloud, local, or paired hybrid operation.</small></a>
            <a href="<?= e(url('/openrouter.php')) ?>"><strong>OpenRouter</strong><small>Provider and model choice.</small></a>
            <a href="<?= e(url('/local-knowledge-overview.php')) ?>"><strong>Local Knowledge</strong><small>Private files stay locally authoritative.</small></a>
            <a href="<?= e(url('/tools-skills.php')) ?>"><strong>Tools + skills</strong><small>Approved local capabilities for paired Agents.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>Pricing</summary>
          <div class="mobile-nav-links">
            <a href="<?= e(url('/pricing-monthly.php')) ?>"><strong>Monthly</strong><small>Recurring monthly access.</small></a>
            <a href="<?= e(url('/pricing-weekly.php')) ?>"><strong>Weekly</strong><small>Short-cycle project access.</small></a>
            <a href="<?= e(url('/pricing-yearly.php')) ?>"><strong>Yearly</strong><small>Long-term individual or team access.</small></a>
            <a href="<?= e(url('/token-packages.php')) ?>"><strong>Token packages</strong><small>Add AI usage capacity.</small></a>
          </div>
        </details>
        <details class="mobile-nav-group">
          <summary>About</summary>
          <div class="mobile-nav-links">
            <a href="<?= e(url('/about-team.php')) ?>"><strong>Team</strong><small>Meet the people building VP3.</small></a>
            <a href="<?= e(url('/mission.php')) ?>"><strong>Mission</strong><small>Why VP3 exists.</small></a>
            <a href="<?= e(url('/case-studies.php')) ?>"><strong>Case studies</strong><small>Real workflow examples.</small></a>
            <a href="<?= e(url('/testimonials.php')) ?>"><strong>Testimonials</strong><small>What people say about VP3.</small></a>
            <a href="<?= e($contactUrl) ?>"><strong>Contact us</strong><small>Demos, partnerships, and questions.</small></a>
            <a href="<?= e(url('/social.php')) ?>"><strong>Social links</strong><small>Follow VP3 and the team.</small></a>
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
    <img class="hero-image" src="<?= e(url('/assets/home/vp3-main-header_bg.png')) ?>" alt="VP3 AI Assistants workspace">
    <div class="hero-shade" aria-hidden="true"></div>
    <div class="hero-content wrap">
      <p class="eyebrow light">One Agent. Every surface.</p>
      <h1 id="hero-title"><span>VP3 AI Assistants</span>From signal to outcome.</h1>
      <p class="hero-copy">Bring browser research, meetings, conversations, teams, scheduling, commerce, analytics, and private HomeServer capabilities into one Agent system that can keep context connected and help move the work forward.</p>
      <div class="hero-actions">
        <a class="button button-light" data-vp3-cta="home_hero_get_vp3" data-vp3-target="signup" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a>
        <a class="button button-ghost" data-vp3-cta="home_hero_book_demo" data-vp3-target="demo" href="<?= e($demoUrl) ?>"><span class="play" aria-hidden="true">▶</span> Book a demo</a>
      </div>
      <div class="hero-notes" aria-label="VP3 product highlights"><span>✓ Browser + meetings + profile</span><span>✓ One cognitive runtime</span><span>✓ Cloud + HomeServer</span></div>
    </div>
  </section>

  <section class="capability-strip" aria-label="VP3 capabilities"><div class="capability-track">
    <a data-vp3-cta="home_capability" data-vp3-target="chrome_extension" href="<?= e(url('/chrome-extension.php')) ?>"><span aria-hidden="true">⌘</span>Browser Companion</a>
    <a data-vp3-cta="home_capability" data-vp3-target="annotations" href="<?= e(url('/annotations.php')) ?>"><span aria-hidden="true">✎</span>Annotations</a>
    <a data-vp3-cta="home_capability" data-vp3-target="meetings" href="<?= e(url('/video-meetings.php')) ?>"><span aria-hidden="true">◉</span>Meetings</a>
    <a data-vp3-cta="home_capability" data-vp3-target="transcription" href="<?= e($transcriptionsUrl) ?>"><span aria-hidden="true">≋</span>Transcription</a>
    <a data-vp3-cta="home_capability" data-vp3-target="teams" href="<?= e($teamsUrl) ?>"><span aria-hidden="true">◎</span>Teams</a>
    <a data-vp3-cta="home_capability" data-vp3-target="calendar" href="<?= e(url('/calendar-service.php')) ?>"><span aria-hidden="true">□</span>Calendar</a>
    <a data-vp3-cta="home_capability" data-vp3-target="booking" href="<?= e(url('/booking.php')) ?>"><span aria-hidden="true">↗</span>Booking</a>
    <a data-vp3-cta="home_capability" data-vp3-target="ecommerce" href="<?= e(url('/ecommerce.php')) ?>"><span aria-hidden="true">◇</span>Ecommerce</a>
    <a data-vp3-cta="home_capability" data-vp3-target="agent_analytics" href="<?= e(url('/agent-analytics.php')) ?>"><span aria-hidden="true">▤</span>Agent Analytics</a>
    <a data-vp3-cta="home_capability" data-vp3-target="homeserver" href="<?= e($homeServerUrl) ?>"><span aria-hidden="true">▣</span>HomeServer</a>
  </div></section>

  <section class="section home-loop" aria-labelledby="loop-title"><div class="wrap">
    <div class="section-heading split-heading"><div><p class="eyebrow">The VP3 operating loop</p><h2 id="loop-title">One Agent from capture to follow-through.</h2></div><p>VP3 does not treat every feature as a new AI silo. Each surface can contribute approved signals to the same Agent and cognitive runtime while the underlying application records stay authoritative.</p></div>
    <div class="home-lifecycle-grid">
      <a class="home-lifecycle-card" data-vp3-cta="home_lifecycle" data-vp3-target="capture" href="<?= e(url('/annotations.php')) ?>"><span>01</span><h3>Capture</h3><p>Browser research, annotations, conversations, voice notes, and meeting transcripts.</p></a>
      <a class="home-lifecycle-card" data-vp3-cta="home_lifecycle" data-vp3-target="understand" href="<?= e(url('/ai-summary.php')) ?>"><span>02</span><h3>Understand</h3><p>Summaries, decisions, questions, knowledge, and source-linked context.</p></a>
      <a class="home-lifecycle-card" data-vp3-cta="home_lifecycle" data-vp3-target="coordinate" href="<?= e(url('/video-meetings.php')) ?>"><span>03</span><h3>Coordinate</h3><p>Teams, meetings, calendar context, commitments, and shared follow-through.</p></a>
      <a class="home-lifecycle-card" data-vp3-cta="home_lifecycle" data-vp3-target="sell" href="<?= e(url('/booking.php')) ?>"><span>04</span><h3>Sell</h3><p>Public booking, products, checkout, appointments, fulfillment, and customer lifecycle.</p></a>
      <a class="home-lifecycle-card" data-vp3-cta="home_lifecycle" data-vp3-target="measure" href="<?= e(url('/agent-analytics.php')) ?>"><span>05</span><h3>Measure</h3><p>Visits, intent, verified conversions, attributed revenue, sources, and opportunities.</p></a>
      <a class="home-lifecycle-card accent" data-vp3-cta="home_lifecycle" data-vp3-target="agent_followthrough" href="<?= e(url('/ai-assistant.php')) ?>"><span>06</span><h3>Agent follows through</h3><p>Relevant signals return to Agent Now, workflows, reminders, suggestions, and next actions.</p></a>
    </div>
    <div class="home-loop-action"><a class="text-link" data-vp3-cta="home_services_overview" data-vp3-target="services" href="<?= e($servicesUrl) ?>">Explore all VP3 services <span aria-hidden="true">→</span></a></div>
  </div></section>

  <section class="section section-soft" aria-labelledby="surfaces-title"><div class="wrap">
    <div class="section-heading split-heading"><div><p class="eyebrow">One Agent across VP3</p><h2 id="surfaces-title">The surface changes. The Agent does not start over.</h2></div><p>Use VP3 where the work happens, with explicit permissions and one connected cognitive system instead of separate assistants that forget each other.</p></div>
    <div class="home-surface-grid">
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="browser" href="<?= e(url('/chrome-extension.php')) ?>"><span>Browser</span><h3>Research and act across the web.</h3><p>Page-aware Agent help, annotations, explicit memory, Teams sharing, notifications, and approved browser actions.</p><b>Browser Companion →</b></a>
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="meetings" href="<?= e(url('/video-meetings.php')) ?>"><span>Meetings</span><h3>Keep the conversation connected.</h3><p>Video meetings, transcripts, Meeting Intelligence, decisions, commitments, and verified follow-through.</p><b>VP3 Meetings →</b></a>
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="profile" href="<?= e(url('/profile-agent-overview.php')) ?>"><span>Profile</span><h3>Put an Agent on your public presence.</h3><p>Let visitors discover you, ask permitted questions, book time, and find public products from one personal destination.</p><b>Profile Agent →</b></a>
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="teams" href="<?= e($teamsUrl) ?>"><span>Teams</span><h3>Shared context with boundaries.</h3><p>Collaborate around conversations, research, projects, meetings, and Agent workflows without flattening private and team context.</p><b>VP3 Teams →</b></a>
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="commerce" href="<?= e(url('/ecommerce.php')) ?>"><span>Booking + commerce</span><h3>Turn public interest into real outcomes.</h3><p>Schedule free or paid appointments, sell products, manage orders, and keep the lifecycle connected to the relationship.</p><b>Book + sell →</b></a>
      <a class="home-surface-card" data-vp3-cta="home_surface" data-vp3-target="homeserver" href="<?= e($homeServerUrl) ?>"><span>HomeServer</span><h3>Keep private capabilities under your control.</h3><p>Pair Cloud convenience with local knowledge, tools, skills, models, and compute on hardware you control.</p><b>Explore HomeServer →</b></a>
    </div>
  </div></section>

  <section class="home-journey" aria-labelledby="journey-title"><div class="wrap">
    <div class="home-journey-head"><div><p class="eyebrow light">A connected outcome</p><h2 id="journey-title">Research can become a meeting. A meeting can become a booking. Analytics can become the next action.</h2></div><p>VP3 keeps each system authoritative while giving the Agent enough approved context to understand what changed and what deserves attention next.</p></div>
    <div class="home-journey-steps">
      <a data-vp3-cta="home_journey" data-vp3-target="annotations" href="<?= e(url('/annotations.php')) ?>"><span>01</span><strong>Capture research</strong><small>Source-linked annotations</small></a>
      <a data-vp3-cta="home_journey" data-vp3-target="meetings" href="<?= e(url('/video-meetings.php')) ?>"><span>02</span><strong>Meet + decide</strong><small>Transcript and commitments</small></a>
      <a data-vp3-cta="home_journey" data-vp3-target="booking" href="<?= e(url('/booking.php')) ?>"><span>03</span><strong>Book + coordinate</strong><small>Calendar and appointment state</small></a>
      <a data-vp3-cta="home_journey" data-vp3-target="ecommerce" href="<?= e(url('/ecommerce.php')) ?>"><span>04</span><strong>Sell + fulfill</strong><small>Orders and lifecycle</small></a>
      <a data-vp3-cta="home_journey" data-vp3-target="analytics" href="<?= e(url('/agent-analytics.php')) ?>"><span>05</span><strong>Measure outcomes</strong><small>Conversion and revenue intelligence</small></a>
      <a data-vp3-cta="home_journey" data-vp3-target="agent" href="<?= e(url('/ai-assistant.php')) ?>"><span>06</span><strong>Agent follows through</strong><small>Next action in context</small></a>
    </div>
  </div></section>

  <section class="everything" id="everything" aria-labelledby="everything-title"><div class="wrap everything-grid">
    <div class="device-art"><img src="<?= e(url('/assets/home/devices-everything-you-need.webp')) ?>" alt="VP3 desktop and mobile experiences shown together" loading="lazy"></div>
    <div class="everything-copy"><p class="eyebrow">One identity across devices</p><h2 id="everything-title">Start where the work happens. Keep the context.</h2><p>Use VP3 on the web, desktop, mobile, and in Chrome. The same account, permissions, Agent, and approved context can carry the work forward without rebuilding your workflow on every surface.</p>
      <ul class="check-list"><li><span>✓</span><div><b>Before</b><p>Prepare with people, goals, research, calendar context, and prior commitments.</p></div></li><li><span>✓</span><div><b>During</b><p>Capture meetings, conversations, browser findings, annotations, and decisions.</p></div></li><li><span>✓</span><div><b>After</b><p>Follow through with tasks, booking, commerce, analytics, reminders, and Agent suggestions.</p></div></li></ul>
      <div class="inline-actions"><a class="button button-dark" data-vp3-cta="home_devices_get_vp3" data-vp3-target="signup" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a><a class="text-link" data-vp3-cta="home_devices_demo" data-vp3-target="demo" href="<?= e($demoUrl) ?>">See VP3 in action <span aria-hidden="true">→</span></a></div>
    </div>
  </div></section>

  <section class="homeserver" id="homeserver" aria-labelledby="homeserver-title"><div class="wrap homeserver-grid">
    <div class="homeserver-visual" aria-label="VP3 HomeServer private AI diagram"><div class="hs-badge">Private by design</div><div class="hs-monitor"><span>VP3</span><i></i><i></i><i></i><i></i></div><div class="hs-node"><b>VP3</b><small>HomeServer</small><em></em></div><div class="hs-phone"><span>VP3</span><i>My Agent</i><i>Local files</i><i>Knowledge</i><i>Tools</i></div><svg class="hs-lines" viewBox="0 0 620 360" role="presentation" aria-hidden="true"><path d="M205 180H300M370 180H465M335 100V150M335 215V285"/></svg><div class="hs-data">Your data. Your control.</div></div>
    <div class="homeserver-copy"><p class="eyebrow">Cloud + self-hosted capability</p><h2 id="homeserver-title">HomeServer gives the same Agent a private local side.</h2><p>Use VP3 Cloud for connected public and account experiences, then pair HomeServer when files, knowledge, tools, models, or execution should stay on hardware you control.</p><ul class="simple-list"><li><span>▣</span>Local knowledge and private collections</li><li><span>⌑</span>Approved tools and skills</li><li><span>□</span>Local or private model execution</li><li><span>▱</span>Explicit paired-device authority</li></ul><a class="button button-dark" data-vp3-cta="home_homeserver" data-vp3-target="homeserver" href="<?= e($homeServerUrl) ?>">Explore HomeServer <span aria-hidden="true">→</span></a></div>
  </div></section>

  <section class="home-choice" aria-labelledby="choice-title"><div class="wrap home-choice-grid">
    <div><p class="eyebrow">Start with what you need</p><h2 id="choice-title">Use one service or connect the whole loop.</h2><p>VP3 can start with transcription, browser research, meetings, booking, commerce, or a public Profile Agent. The system becomes more useful as you intentionally connect the parts that fit your work.</p></div>
    <div class="home-choice-actions"><a class="button button-dark" data-vp3-cta="home_choice_services" data-vp3-target="services" href="<?= e($servicesUrl) ?>">Explore services →</a><a class="button button-outline" data-vp3-cta="home_choice_pricing" data-vp3-target="pricing" href="<?= e($pricingUrl) ?>">View pricing</a></div>
  </div></section>

  <section class="final-cta"><div class="wrap final-cta-grid"><div><p class="eyebrow">Ready when you are</p><h2>Put one VP3 Agent across the work.</h2><p>Create your account or walk through the full browser-to-meeting-to-outcome workflow in a demo.</p></div><div class="final-actions"><a class="button button-dark" data-vp3-cta="home_final_get_vp3" data-vp3-target="signup" href="<?= e($signupUrl) ?>">Get VP3 <span aria-hidden="true">→</span></a><a class="button button-outline" data-vp3-cta="home_final_demo" data-vp3-target="demo" href="<?= e($demoUrl) ?>">Book a demo</a></div></div></section>
</main>

<footer class="footer">
  <div class="wrap footer-grid">
    <div class="footer-brand"><?= vp3_public_brand() ?><span>AI assistants for real work.</span></div>
    <nav aria-label="Footer navigation">
      <a href="<?= e($productUrl) ?>">Product</a>
      <a href="<?= e($teamsUrl) ?>">Teams</a>
      <a href="<?= e($homeServerUrl) ?>">HomeServer</a>
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
