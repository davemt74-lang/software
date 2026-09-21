<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

vp3_public_header(
    'Chrome Extension — VP3 Browser Companion',
    'Bring VP3 Browser Companion into Chrome for page-aware Agent help, research, notifications, explicit memory, and approved browser actions.',
    ['active' => 'product', 'canonical' => '/chrome-extension.php']
);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">VP3 Browser Companion</div>
  <h1>Bring your VP3 Agent with you across the web.</h1>
  <p>The VP3 Chrome Extension turns the current browser context into a bounded Agent surface for research, collaboration, notifications, memory, and approved actions—without creating a second browser-side brain.</p>
</section>

<main>
  <section class="vp3-section">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">Context when you need it</div>
        <h2>Your current page can become temporary Agent context.</h2>
        <p>Browser Companion can connect the page you are viewing to VP3 Agent Now, research, Teams, Knowledge, workflows, and the existing cognitive runtime.</p>
        <p>VP3 keeps authority on the server. The extension is a sensor and interaction surface, not a separate memory, permission, or execution system.</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Page-aware Agent</b><span>Use the current page or an explicit selection as bounded context for Agent help and relevant suggestions.</span></div>
        <div class="vp3-about-point"><b>Research + capture</b><span>Save useful browser findings into VP3 research and Knowledge workflows with source context attached.</span></div>
        <div class="vp3-about-point"><b>Teams + sharing</b><span>Share selected browser content into authorized Team conversations without copying it through unrelated tools.</span></div>
        <div class="vp3-about-point"><b>Notifications + Agent Voice</b><span>Receive eligible VP3 notifications and voice updates through the same canonical delivery and permission system.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">Memory + action boundaries</div>
        <h2>Useful browser intelligence without silent autonomy.</h2>
        <p>Browser Companion reuses the same VP3 authority boundaries that govern the main Agent.</p>
      </div>
      <div class="vp3-about-grid">
        <div class="vp3-about-points">
          <div class="vp3-about-point"><b>Explicit browser memory</b><span>Browsing alone does not become memory. Cross-web memory uses explicit VP3 object references and user approval.</span></div>
          <div class="vp3-about-point"><b>Approved actions</b><span>Browser execution and web interaction stay behind the existing VP3 review, approval, and verification boundaries.</span></div>
          <div class="vp3-about-point"><b>Transaction continuity</b><span>Track approved purchases, bookings, applications, and other transactions over time without storing raw browsing history.</span></div>
          <div class="vp3-about-point"><b>Shared cognitive loop</b><span>Browser signals feed the same Agent Now, Brain, planning, calibration, and learning architecture used across VP3.</span></div>
        </div>
        <article class="vp3-card">
          <div class="vp3-kicker">One VP3 Agent</div>
          <h2>The browser is another surface—not another AI silo.</h2>
          <p>Research findings, transaction exceptions, notifications, contextual suggestions, and approved browser work flow back through VP3's existing cognitive and execution systems.</p>
          <p>Your VP3 account, permissions, Teams, memory rules, action boundaries, and Agent Voice settings remain authoritative.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-cta-box">
        <div><h2>See Browser Companion working with VP3 Agent Now.</h2><p>Walk through page-aware context, research capture, notifications, memory, and approved browser actions.</p></div>
        <a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book Demo →</a>
      </div>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
