<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$vp3BrowserUrl = url('/login.php');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="VP3 is your personal AI assistant for transcriptions, AI summaries, knowledge, teams, and a private personal URL.">
<meta name="theme-color" content="#f7f9fc">
<title>VP3 — Capture. Understand. Take Action.</title>
<link rel="stylesheet" href="<?= e(url('/vp3-home.css?v=vp3-home-20260906')) ?>">
</head>
<body class="vp3-home">
<header class="vp3-topbar">
  <a class="vp3-brand" href="<?= e(url('/')) ?>" aria-label="VP3 home">
    <span class="vp3-brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    <strong>VP3</strong>
  </a>
  <div class="vp3-topbar-actions">
    <a class="vp3-get-started" href="<?= e($vp3BrowserUrl) ?>">Get Started <span aria-hidden="true">→</span></a>
    <a class="vp3-account" href="<?= e(url('/login.php')) ?>" aria-label="Sign in">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21c.8-4.2 3.3-6.3 7.5-6.3s6.7 2.1 7.5 6.3"/></svg>
    </a>
  </div>
</header>

<main>
  <section class="vp3-hero" aria-labelledby="vp3HeroTitle">
    <div class="vp3-mountain-layer" aria-hidden="true"></div>
    <div class="vp3-hero-inner">
      <div class="vp3-kicker">Your life. Your assistant.</div>
      <h1 id="vp3HeroTitle">Capture. Understand.<br>Take Action.</h1>
      <p class="vp3-hero-copy">Your personal AI assistant with a private URL, transcriptions, AI summaries, and a connected knowledge base — so nothing gets lost and you can move forward faster.</p>

      <div class="vp3-downloads" id="downloads" aria-label="VP3 app downloads">
        <a class="vp3-store-badge" href="#vp3-download-note" aria-label="VP3 for iPhone and iPad">
          <span class="vp3-store-symbol apple" aria-hidden="true">●</span>
          <span><small>Download on the</small><strong>App Store</strong></span>
        </a>
        <a class="vp3-store-badge" href="#vp3-download-note" aria-label="VP3 for Android">
          <span class="vp3-store-symbol play" aria-hidden="true">▶</span>
          <span><small>GET IT ON</small><strong>Google Play</strong></span>
        </a>
      </div>
      <a class="vp3-browser-link" href="<?= e($vp3BrowserUrl) ?>">Or use it in your browser <span aria-hidden="true">→</span></a>

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

  <section class="vp3-features" id="features" aria-labelledby="vp3FeaturesTitle">
    <div class="vp3-section-head">
      <div class="vp3-kicker">Built for what matters</div>
      <h2 id="vp3FeaturesTitle">Everything you need. All in one place.</h2>
      <p>A private, capable workspace for your thoughts, content, people, and next steps.</p>
    </div>

    <div class="vp3-feature-grid">
      <article class="vp3-feature-card"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.1 0l2.1-2.1a5 5 0 0 0-7.1-7.1L11 5"/><path d="M14 11a5 5 0 0 0-7.1 0l-2.1 2.1a5 5 0 0 0 7.1 7.1L13 19"/></svg></span><h3>Personal URL</h3><p>Share your public profile, portfolio, or agent. You control what’s public and what stays private.</p></article>
      <article class="vp3-feature-card" id="transcriptions"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><path d="M3 12v2M7 8v10M11 4v16M15 7v10M19 10v4M23 12v1"/></svg></span><h3>Transcriptions</h3><p>Turn audio and video into searchable text with accurate transcripts and AI-assisted context.</p></article>
      <article class="vp3-feature-card"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/></svg></span><h3>AI Summaries</h3><p>Get concise summaries, key decisions, action items, and next steps from your conversations.</p></article>
      <article class="vp3-feature-card" id="teams"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2"/><path d="M3 20c.6-4 2.6-6 6-6s5.4 2 6 6M14 15c3.7 0 5.8 1.7 6.5 5"/></svg></span><h3>Teams &amp; Team Management</h3><p>Collaborate with your team, share knowledge, manage access, and keep work aligned.</p></article>
      <article class="vp3-feature-card"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4zM4 12l8 4 8-4M4 17l8 4 8-4"/></svg></span><h3>Second Brain</h3><p>Keep notes, files, ideas, memories, and relationships in one connected knowledge system that grows with you.</p></article>
      <article class="vp3-feature-card"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/></svg></span><h3>Profile Chat Widget</h3><p>Add your agent to your profile so visitors can ask questions, connect, and start useful conversations.</p></article>
      <article class="vp3-feature-card" id="security"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg></span><h3>Secure &amp; Private</h3><p>Your data stays yours. Built around private knowledge, permission-aware access, and user control.</p></article>
      <article class="vp3-feature-card"><span class="vp3-feature-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="13" height="10" rx="1"/><rect x="14" y="10" width="7" height="10" rx="1"/><path d="M8 18h4"/></svg></span><h3>Works Everywhere</h3><p>Use VP3 on desktop, mobile, and the web so your assistant and knowledge stay close wherever you work.</p></article>
    </div>
  </section>

  <section class="vp3-results" aria-labelledby="vp3ResultsTitle">
    <div class="vp3-results-bg" aria-hidden="true"></div>
    <div class="vp3-results-content">
      <div class="vp3-kicker">From conversations to action</div>
      <h2 id="vp3ResultsTitle">Turn Your Thoughts<br>Into Results.</h2>
      <p>Capture ideas, organize your knowledge, collaborate with your team, and take action — all in one private, personal AI assistant.</p>
      <a class="vp3-get-started dark" href="<?= e($vp3BrowserUrl) ?>">Get Started <span aria-hidden="true">→</span></a>
    </div>
  </section>

  <section class="vp3-download-strip" id="vp3-download-note">
    <div class="vp3-kicker">Available on all your devices</div>
    <div class="vp3-downloads">
      <a class="vp3-store-badge" href="<?= e($vp3BrowserUrl) ?>"><span class="vp3-store-symbol apple" aria-hidden="true">●</span><span><small>Download on the</small><strong>App Store</strong></span></a>
      <a class="vp3-store-badge" href="<?= e($vp3BrowserUrl) ?>"><span class="vp3-store-symbol play" aria-hidden="true">▶</span><span><small>GET IT ON</small><strong>Google Play</strong></span></a>
    </div>
    <a class="vp3-browser-link" href="<?= e($vp3BrowserUrl) ?>">Or use it in your browser <span aria-hidden="true">→</span></a>
  </section>
</main>

<footer class="vp3-footer">
  <div class="vp3-footer-brand"><span class="vp3-brand-mark small" aria-hidden="true"><i></i><i></i><i></i><i></i></span><strong>VP3</strong><span>A Private Future. On Your Terms.</span></div>
  <nav class="vp3-footer-links" aria-label="VP3 footer navigation">
    <a href="#features">Features</a><a href="#transcriptions">Transcriptions</a><a href="#teams">Teams</a><a href="#security">Security</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a><a href="<?= e(url('/about.php')) ?>">About</a>
    <span class="vp3-footer-divider"></span><a href="<?= e(url('/privacy.php')) ?>">Privacy</a><a href="<?= e(url('/terms.php')) ?>">Terms</a><a href="<?= e(url('/contact.php')) ?>">Contact</a>
  </nav>
</footer>
</body>
</html>
