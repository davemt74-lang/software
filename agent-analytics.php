<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

vp3_public_header(
    'Agent Analytics — VP3',
    'Connect profile visits, booking and product intent, verified conversions, attributed revenue, traffic sources, trends, and Agent opportunities.',
    ['active' => 'services', 'canonical' => '/agent-analytics.php', 'body_class' => 'vp3-marketing-page vp3-feature-detail-page']
);
?>
<section class="vp3-public-hero vp3-marketing-hero">
  <div class="vp3-kicker">VP3 Agent Analytics</div>
  <h1>See what attention is turning into.</h1>
  <p>Agent Analytics connects public Profile activity to booking and product intent, verified outcomes, per-currency revenue, source attribution, period comparisons, top performers, and conversion opportunities—then gives the Agent useful signals for what to do next.</p>
  <div class="vp3-marketing-hero-actions">
    <a class="vp3-btn primary" href="<?= e(url('/profile-agent-overview.php')) ?>">Explore Profile Agent →</a>
    <a class="vp3-btn" href="<?= e(url('/book-demo.php')) ?>">Book a demo</a>
  </div>
</section>

<main id="main-content">
  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">From attention to outcome</div>
        <h2>Measure the full public conversion path, not just page views.</h2>
        <p>VP3 links high-level Profile activity to the canonical booking and commerce outcomes that matter to the owner.</p>
      </div>
      <div class="vp3-metric-grid">
        <article><span>01</span><strong>Profile visits</strong><p>See public Profile activity as the top of the conversion path.</p></article>
        <article><span>02</span><strong>Booking intent</strong><p>Track measurable interest in public appointment offers.</p></article>
        <article><span>03</span><strong>Product intent</strong><p>Understand which products and offers are attracting action.</p></article>
        <article><span>04</span><strong>Verified conversions</strong><p>Count confirmed booking and product outcomes instead of assuming intent equals success.</p></article>
        <article><span>05</span><strong>Attributed revenue</strong><p>Report outcome value in separate per-currency buckets without combining unlike currencies.</p></article>
        <article class="accent"><span>06</span><strong>Agent opportunities</strong><p>Surface strong performers, conversion gaps, and areas that deserve attention.</p></article>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">Performance intelligence</div>
        <h2>See what changed, where outcomes came from, and what is working.</h2>
        <p>Agent Analytics can compare current and previous periods, identify leading booking or product targets, show attributed traffic sources, and connect conversions to revenue while keeping booking and commerce records authoritative.</p>
        <p>That gives owners a clearer answer to a practical question: what is turning public attention into real business activity?</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Period comparisons</b><span>Compare views, intent, conversions, conversion rates, and revenue across current and previous windows.</span></div>
        <div class="vp3-about-point"><b>Top targets</b><span>See which booking offers and products are producing the strongest measured outcomes.</span></div>
        <div class="vp3-about-point"><b>Traffic sources</b><span>Review attributed campaigns, UTM sources, referrers, and direct activity where attribution is available.</span></div>
        <div class="vp3-about-point"><b>Opportunity signals</b><span>Flag intent with no conversions, below-average conversion, and strong performers worth featuring more prominently.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">Connected outcomes</div>
        <h2>Analytics stays attached to Booking, Ecommerce, and Profile Agent.</h2>
      </div>
      <div class="vp3-marketing-related vp3-related-three">
        <a href="<?= e(url('/booking.php')) ?>"><strong>Booking</strong><span>Intent → confirmed appointment → paid outcome →</span></a>
        <a href="<?= e(url('/ecommerce.php')) ?>"><strong>Ecommerce</strong><span>Product interest → order → payment → refund state →</span></a>
        <a href="<?= e(url('/profile-agent-overview.php')) ?>"><strong>Profile Agent</strong><span>Public interaction → offer discovery → outcome →</span></a>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap vp3-marketing-why">
      <div><div class="vp3-kicker">Privacy + authority</div><h2>Use behavioral signals without making visitor identity the product.</h2></div>
      <p>Agent Analytics is owner-facing intelligence over VP3's existing Profile event and outcome systems. Reporting focuses on visits, intent, outcomes, sources, rates, and revenue while canonical Booking and Commerce state remains authoritative. The goal is better decisions and Agent follow-through—not a separate identity surveillance layer.</p>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap"><div class="vp3-cta-box vp3-marketing-cta">
      <div><h2>Connect your public Profile to measurable outcomes.</h2><p>See how Profile Agent, Booking, Ecommerce, and Agent Analytics work together.</p></div>
      <div class="vp3-marketing-cta-actions"><a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book a demo →</a><a class="vp3-btn" href="<?= e(url('/services.php')) ?>">All services</a></div>
    </div></div>
  </section>
</main>
<?php vp3_public_footer(); ?>
