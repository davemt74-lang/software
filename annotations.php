<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

vp3_public_header(
    'Annotations — VP3',
    'Capture web highlights, screenshots, notes, and source context with VP3, then organize, discuss, and reuse them across research and Agent workflows.',
    ['active' => 'services', 'canonical' => '/annotations.php', 'body_class' => 'vp3-marketing-page vp3-feature-detail-page']
);
?>
<section class="vp3-public-hero vp3-marketing-hero">
  <div class="vp3-kicker">VP3 Annotations</div>
  <h1>Capture what matters. Keep the source attached.</h1>
  <p>VP3 Annotations turns browser research into durable, source-linked context: highlights, screenshots, notes, source identity, discussion, research organization, and Agent follow-through stay connected instead of becoming a pile of copied fragments.</p>
  <div class="vp3-marketing-hero-actions">
    <a class="vp3-btn primary" href="<?= e(url('/chrome-extension.php')) ?>">Explore Browser Companion →</a>
    <a class="vp3-btn" href="<?= e(url('/book-demo.php')) ?>">Book a demo</a>
  </div>
</section>

<main id="main-content">
  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-detail-path">
        <div><span>01</span><strong>Capture</strong><p>Select text, add a note, or save a screenshot while working in the browser.</p></div>
        <div><span>02</span><strong>Preserve context</strong><p>Keep source identity and captured context attached to the annotation.</p></div>
        <div><span>03</span><strong>Organize + discuss</strong><p>Save, comment, share, and add approved annotations to research.</p></div>
        <div><span>04</span><strong>Reuse with the Agent</strong><p>Bring approved source material into summaries, questions, research, and next actions.</p></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">Source-linked capture</div>
        <h2>An annotation is more useful when you can still see where it came from.</h2>
        <p>Browser Companion can capture selected text, screenshots, notes, and source details while you are working on the web. The saved item remains tied to source identity and captured context so later readers are not left with an orphaned quote.</p>
        <p>Opening or browsing a page is not the same as explicitly saving it. VP3 treats capture as an intentional research action.</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Highlights + quotes</b><span>Capture selected source material without losing the page it came from.</span></div>
        <div class="vp3-about-point"><b>Screenshots</b><span>Keep visual evidence with the annotation when the important context is not purely text.</span></div>
        <div class="vp3-about-point"><b>Notes + commentary</b><span>Add your own interpretation separately from the captured source.</span></div>
        <div class="vp3-about-point"><b>Source context</b><span>Keep source identity and captured context available for later verification and review.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">Research + collaboration</div>
        <h2>Move from isolated clipping to a reusable research object.</h2>
        <p>Annotations can become part of the broader VP3 research and collaboration loop instead of living only inside the browser extension.</p>
      </div>
      <div class="vp3-marketing-story-grid">
        <article class="vp3-marketing-story"><span class="vp3-marketing-story-index">01 · RESEARCH</span><h3>Save and organize</h3><p>Add approved annotations to research so useful source material can be found and reused later.</p></article>
        <article class="vp3-marketing-story"><span class="vp3-marketing-story-index">02 · DISCUSS</span><h3>Comments stay attached</h3><p>Discussion belongs with the annotation and its source context instead of being separated into another channel.</p></article>
        <article class="vp3-marketing-story"><span class="vp3-marketing-story-index">03 · SHARE</span><h3>Use the right visibility</h3><p>Share or publish annotations through the VP3 visibility and Team boundaries that apply to the item.</p></article>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap vp3-about-grid">
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Ask VP3</b><span>Hand an annotation into Agent context when you want help understanding, comparing, or acting on it.</span></div>
        <div class="vp3-about-point"><b>AI Summary</b><span>Use captured material alongside conversations and other approved sources when a broader summary is needed.</span></div>
        <div class="vp3-about-point"><b>Teams</b><span>Bring relevant source-linked findings into collaborative workflows without rebuilding them as plain chat messages.</span></div>
        <div class="vp3-about-point"><b>Research continuity</b><span>Keep useful browser findings available to later work without turning ordinary browsing into silent memory.</span></div>
      </div>
      <article class="vp3-card">
        <div class="vp3-kicker">Agent follow-through</div>
        <h2>The capture can become context without becoming a second browser brain.</h2>
        <p>Annotations flow back into VP3's existing Agent, research, Team, and cognitive systems. Browser Companion remains the capture surface; VP3 remains the authority for identity, permissions, approved memory, and durable workflows.</p>
        <p>That keeps the Agent useful across the web while preserving the difference between what you viewed and what you deliberately saved.</p>
      </article>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap"><div class="vp3-cta-box vp3-marketing-cta">
      <div><h2>Capture annotations directly from Chrome.</h2><p>Use VP3 Browser Companion to bring source-linked research into the same Agent workspace.</p></div>
      <div class="vp3-marketing-cta-actions"><a class="vp3-btn primary" href="<?= e(url('/chrome-extension.php')) ?>">Browser Companion →</a><a class="vp3-btn" href="<?= e(url('/services.php')) ?>">All services</a></div>
    </div></div>
  </section>
</main>
<?php vp3_public_footer(); ?>
