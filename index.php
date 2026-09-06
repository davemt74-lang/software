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
<meta name="description" content="VP3 is your personal AI assistant for transcriptions, AI summaries, knowledge, teams, and a private personal URL.">
<meta name="theme-color" content="#f7f9fc">
<title>VP3 — Capture. Understand. Take Action.</title>
<link rel="stylesheet" href="<?= e(url('/vp3-public.css?v=vp3-public-20260906')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-public-nav.css?v=vp3-public-20260906')) ?>">
<link rel="stylesheet" href="<?= e(url('/vp3-home.css?v=vp3-home-20260906-how-it-works')) ?>">
</head>
<body class="vp3-home">
<header class="vp3-public-header vp3-home-header">
  <div class="vp3-public-nav">
    <a class="vp3-public-brand" href="<?= e(url('/index.php')) ?>" aria-label="VP3 home">
      <span class="vp3-public-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong>
    </a>
    <nav class="vp3-public-links" aria-label="Primary navigation">
      <a href="<?= e(url('/index.php#transcriptions')) ?>">Transcriptions</a>
      <a href="<?= e(url('/index.php#teams')) ?>">Teams</a>
      <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
      <a href="<?= e(url('/about.php')) ?>">About</a>
    </nav>
    <div class="vp3-public-actions">
      <a class="vp3-public-signin" href="<?= e($vp3LoginUrl) ?>">Sign in</a>
      <a class="vp3-public-primary" href="<?= e($vp3DemoUrl) ?>">BOOK DEMO</a>
      <details class="vp3-public-mobile-menu">
        <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
        <nav aria-label="Mobile navigation">
          <a href="<?= e(url('/index.php#transcriptions')) ?>">Transcriptions</a>
          <a href="<?= e(url('/index.php#teams')) ?>">Teams</a>
          <a href="<?= e(url('/pricing.php')) ?>">Pricing</a>
          <a href="<?= e(url('/about.php')) ?>">About</a>
          <a href="<?= e($vp3DemoUrl) ?>">Book a Demo</a>
          <a href="<?= e(url('/contact.php')) ?>">Contact</a>
        </nav>
      </details>
    </div>
  </div>
</header>

<main>
  <section class="vp3-hero" aria-labelledby="vp3HeroTitle">
    <div class="vp3-mountain-layer" aria-hidden="true"></div>
    <div class="vp3-hero-inner">
      <div class="vp3-kicker">Your life. Your assistant.</div>
      <h1 id="vp3HeroTitle">Capture. Understand.<br>Take Action.</h1>
      <p class="vp3-hero-copy">Your personal AI assistant with a private URL, transcriptions, AI summaries, and a connected knowledge base — so nothing gets lost and you can move forward faster.</p>

      <div class="vp3-downloads" id="downloads" aria-label="VP3 account and demo actions">
        <a class="vp3-store-badge" href="<?= e($vp3SignupUrl) ?>" aria-label="Create your VP3 account">
          <span><strong>Create account</strong></span>
        </a>
        <a class="vp3-store-badge" href="<?= e($vp3DemoUrl) ?>" aria-label="Book a VP3 demo">
          <span><strong>Book demo</strong></span>
        </a>
      </div>
      <a class="vp3-browser-link" href="<?= e($vp3LoginUrl) ?>">Already have an account? Sign in <span aria-hidden="true">→</span></a>

      <div class="vp3-device-stage" aria-label="VP3 desktop and mobile assistant interface preview">
        <div class="vp3-laptop">
          <div class="vp3-laptop-camera"></div>
          <div class="vp3-laptop-screen">
            <aside class="vp3-demo-sidebar">
              <div class="vp3-demo-brand"><span class="vp3-mini-mark"></span><b>VP3</b></div>
              <nav aria-label="Product preview navigation">
                <span class="active">＋ <b>New Chat</b></span>
                <span>◎ <b>Profile Agent</b></span>
                <span>• <b>My Contacts</b></span>
                <span>◆ <b>My Knowledge</b></span>
                <span>• <b>My Transcriptions</b></span>
                <span>▶ <b>Player</b></span>
                <span>♥ <b>Saved Songs</b></span>
                <span>ρ <b>My Playlists</b></span>
              </nav>
              <div class="vp3-demo-chats">
                <small>CHATS</small>
                <span class="active">Recording results <em>Sep 5</em></span>
                <span>hello <em>Sep 3</em></span>
                <span>Upcoming ideas <em>Sep 3</em></span>
              </div>
              <div class="vp3-demo-settings">⚙ <b>Chat Settings</b><em>ONLINE</em></div>
            </aside>

            <div class="vp3-demo-main">
              <div class="vp3-demo-topline">
                <div class="vp3-demo-search">⌕ Search your conversations…</div>
                <div class="vp3-demo-actions">＋ ◫ ♙ ♧ <span>D</span></div>
              </div>
              <div class="vp3-demo-chat">
                <div class="vp3-runtime">LIVE RUNTIME · assistant-memory · transcription-summary</div>
                <article class="vp3-message">
                  <div class="vp3-agent-avatar">V</div>
                  <div><b>VP3</b><p>New recording saved.</p>
                    <div class="vp3-recording-card"><strong>Recording 3</strong><small>Sep 6 · 2:44 PM · 0:19</small><p>We captured the conversation and saved the transcript to your workspace.</p><div class="vp3-audio-line"><span>▶</span><b>0:00</b><i></i><span>◖</span></div></div>
                  </div>
                </article>
                <article class="vp3-message">
                  <div class="vp3-agent-avatar">V</div>
                  <div><b>VP3</b><p>Good morning, Dave. I’ve been keeping an eye on things. Here are the highest-value items to move first:</p>
                    <ul class="vp3-demo-list"><li>Finish the active project plan</li><li>Review recent transcription summary</li><li>Follow up on the profile conversation</li><li>Use your knowledge to complete the next step</li></ul>
                  </div>
                </article>
              </div>
              <div class="vp3-demo-composer">Message VP3… <span>◉ ◫ ↑</span></div>
            </div>
          </div>
          <div class="vp3-laptop-base"></div>
        </div>

        <div class="vp3-phone" aria-label="VP3 mobile interface preview">
          <div class="vp3-phone-notch"></div>
          <div class="vp3-phone-screen">
            <div class="vp3-phone-top"><span>☰</span><b><i class="vp3-mini-mark"></i> VP3</b><span>⌕</span></div>
            <h3>Good morning, Dave.</h3>
            <p>What would you like to work on today?</p>
            <div class="vp3-phone-card"><b>Start a new chat</b><small>Ask anything, get unstuck</small></div>
            <div class="vp3-phone-card"><b>Record audio</b><small>Transcribe and summarize</small></div>
            <div class="vp3-phone-card"><b>Review recent activity</b><small>See what changed</small></div>
            <div class="vp3-phone-card"><b>Search your knowledge</b><small>Find notes, ideas, and people</small></div>
            <div class="vp3-phone-nav"><span>⌂<small>Home</small></span><span>≋<small>Transcribe</small></span><span>◫<small>Projects</small></span><span>◇<small>Knowledge</small></span></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="vp3-features vp3-how-it-works" id="features" aria-labelledby="vp3HowTitle">
    <div class="vp3-section-head">
      <div class="vp3-kicker">How it works</div>
      <h2 id="vp3HowTitle">From recording to action in four steps.</h2>
      <p>Capture the conversation once. VP3 turns it into searchable context and a clear next step.</p>
    </div>

    <div class="vp3-feature-grid vp3-step-grid">
      <article class="vp3-feature-card vp3-step-card">
        <span class="vp3-step-number">01</span>
        <h3>Record</h3>
        <p>Capture conversations, meetings, ideas, or voice notes directly into your VP3 workspace.</p>
      </article>
      <article class="vp3-feature-card vp3-step-card" id="transcriptions">
        <span class="vp3-step-number">02</span>
        <h3>Transcribe</h3>
        <p>Turn your recording into accurate, searchable text you can review, save, and reuse.</p>
      </article>
      <article class="vp3-feature-card vp3-step-card">
        <span class="vp3-step-number">03</span>
        <h3>AI Analysis</h3>
        <p>Let VP3 identify the key ideas, decisions, questions, opportunities, and next steps in the conversation.</p>
      </article>
      <article class="vp3-feature-card vp3-step-card">
        <span class="vp3-step-number">04</span>
        <h3>Summary or Action Plan</h3>
        <p>Receive a clear summary or practical action plan that helps you move forward.</p>
      </article>
    </div>
  </section>

  <section class="vp3-results" id="teams" aria-labelledby="vp3ResultsTitle">
    <div class="vp3-results-bg" aria-hidden="true"></div>
    <div class="vp3-results-content">
      <div class="vp3-kicker">From conversations to action</div>
      <h2 id="vp3ResultsTitle">Turn Your Thoughts<br>Into Results.</h2>
      <p>Capture ideas, organize your knowledge, collaborate with your team, and take action — all in one private, personal AI assistant.</p>
      <a class="vp3-get-started dark" href="<?= e($vp3SignupUrl) ?>">Get Started <span aria-hidden="true">→</span></a>
    </div>
  </section>

  <section class="vp3-download-strip" id="vp3-download-note">
    <div class="vp3-kicker">Ready to get started</div>
    <div class="vp3-downloads" aria-label="VP3 account and demo actions">
      <a class="vp3-store-badge" href="<?= e($vp3SignupUrl) ?>" aria-label="Create your VP3 account"><span><strong>Create account</strong></span></a>
      <a class="vp3-store-badge" href="<?= e($vp3DemoUrl) ?>" aria-label="Book a VP3 demo"><span><strong>Book demo</strong></span></a>
    </div>
    <a class="vp3-browser-link" href="<?= e($vp3LoginUrl) ?>">Already have an account? Sign in <span aria-hidden="true">→</span></a>
  </section>
</main>

<footer class="vp3-footer">
  <div class="vp3-footer-brand"><span class="vp3-brand-mark small" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong><span>A Private Future. On Your Terms.</span></div>
  <nav class="vp3-footer-links" aria-label="VP3 footer navigation">
    <a href="#features">Features</a><a href="#transcriptions">Transcriptions</a><a href="#teams">Teams</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a><a href="<?= e(url('/about.php')) ?>">About</a>
    <span class="vp3-footer-divider"></span><a href="<?= e(url('/privacy.php')) ?>">Privacy</a><a href="<?= e(url('/terms.php')) ?>">Terms</a><a href="<?= e(url('/contact.php')) ?>">Contact</a>
  </nav>
</footer>
</body>
</html>
