<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();
vp3_public_header(
    'Teams — VP3',
    'Give your team a shared VP3 workspace for knowledge, conversations, permissions, collaboration, and AI-assisted work.',
    ['active' => 'teams']
);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">Shared context. Clear access.</div>
  <h1>Give your team one shared AI workspace.</h1>
  <p>Bring conversations, knowledge, people, projects, and permissions together so your team can work from the same context without giving up control of private information.</p>
</section>
<main>
  <section class="vp3-section">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">Team workspace</div>
        <h2>Shared knowledge without flattening permissions.</h2>
        <p>VP3 Teams is designed around deliberate access. Members can work from shared context while personal knowledge and restricted information remain separated according to the permissions you set.</p>
        <p>The result is one place for team context, without turning every user account into the same unrestricted workspace.</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Members &amp; roles</b><span>Add people to the workspace and control who can manage users, settings, billing, content, and team resources.</span></div>
        <div class="vp3-about-point"><b>Shared knowledge</b><span>Make approved notes, files, transcripts, project context, and other knowledge available to the people who need it.</span></div>
        <div class="vp3-about-point"><b>Permission-aware AI</b><span>Let the assistant use team context only when the current user is allowed to access that information.</span></div>
        <div class="vp3-about-point"><b>One working history</b><span>Keep important conversations, decisions, tasks, and project context connected instead of scattered across separate tools.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">Built for collaboration</div>
        <h2>Your team’s assistant should understand the work.</h2>
        <p>VP3 gives the assistant useful team context while keeping access boundaries visible and enforceable.</p>
      </div>
      <div class="vp3-about-grid">
        <div class="vp3-about-points">
          <div class="vp3-about-point"><b>Shared transcripts</b><span>Keep meetings and recorded conversations available to the right team members with the source attached.</span></div>
          <div class="vp3-about-point"><b>Project continuity</b><span>Carry decisions, follow-ups, files, and open questions forward instead of restarting context in every new conversation.</span></div>
          <div class="vp3-about-point"><b>Collaborative knowledge</b><span>Build a reusable team knowledge layer that can support Agent Chat and approved workflows.</span></div>
          <div class="vp3-about-point"><b>Controlled administration</b><span>Use roles and permissions to separate everyday collaboration from account, billing, and administrative authority.</span></div>
        </div>
        <article class="vp3-card">
          <div class="vp3-kicker">One assistant, scoped correctly</div>
          <h2>Useful to the team. Private by design.</h2>
          <p>VP3 is not built around copying every person’s private context into a company-wide pool. Team knowledge is intentionally shared, and personal knowledge remains personal unless the owner grants access.</p>
          <p>This lets teams collaborate with AI while preserving the distinction between individual, shared, and administrative information.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-cta-box">
        <div><h2>See how VP3 works for a team.</h2><p>Book a demo to walk through members, shared knowledge, permissions, transcripts, and Agent Chat.</p></div>
        <a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book Demo →</a>
      </div>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
