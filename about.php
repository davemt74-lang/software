<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();
vp3_public_header('About — VP3', 'VP3 is a private personal AI assistant for capture, understanding, knowledge, and action.', ['active'=>'about']);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">A more capable you</div>
  <h1>An assistant built around your context.</h1>
  <p>VP3 connects conversations, transcriptions, summaries, knowledge, projects, people, and tools so your assistant can help you move from capture to action without losing the thread.</p>
</section>
<main>
<section class="vp3-section"><div class="vp3-wrap vp3-about-grid">
  <article class="vp3-card">
    <div class="vp3-kicker">Why VP3</div>
    <h2>Most software stores information. VP3 helps you use it.</h2>
    <p>Your assistant is designed to retain useful context, understand what you are working on, connect related information, and surface the next action when it matters.</p>
    <p>The goal is not more dashboards. It is one assistant that can work across your private knowledge, conversations, recordings, relationships, projects, and approved tools.</p>
  </article>
  <div class="vp3-about-points">
    <div class="vp3-about-point"><b>Capture</b><span>Record conversations, save ideas, collect notes, files, profile activity, and project context.</span></div>
    <div class="vp3-about-point"><b>Understand</b><span>Transcribe, summarize, connect knowledge, retain memory, and recognize what a project still needs.</span></div>
    <div class="vp3-about-point"><b>Take action</b><span>Use tools, skills, workflows, and proactive engagement through the main Agent Chat surface.</span></div>
    <div class="vp3-about-point"><b>Stay in control</b><span>Keep private knowledge private, choose what your public profile can expose, and manage access deliberately.</span></div>
  </div>
</div></section>
<section class="vp3-section soft"><div class="vp3-wrap"><div class="vp3-cta-box"><div><h2>Your life. Your assistant.</h2><p>Start with your own private VP3 workspace and build from there.</p></div><a class="vp3-btn primary" href="<?= e(url('/signup.php')) ?>">Get Started →</a></div></div></section>
</main>
<?php vp3_public_footer(); ?>
