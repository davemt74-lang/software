<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

vp3_public_header(
    'Services — VP3',
    'Capture, understand, coordinate, sell, measure, and let your VP3 Agent follow through across one connected system.',
    ['active' => 'services', 'canonical' => '/services.php', 'body_class' => 'vp3-marketing-page vp3-services-page']
);
?>
<section class="vp3-public-hero vp3-marketing-hero">
  <div class="vp3-kicker">VP3 Services</div>
  <h1>From signal to outcome, in one Agent system.</h1>
  <p>VP3 connects capture, understanding, collaboration, meetings, scheduling, commerce, analytics, and Agent follow-through so every service can contribute to the same approved context instead of creating another disconnected tool.</p>
  <div class="vp3-marketing-hero-actions">
    <a class="vp3-btn primary" data-vp3-cta="services_get_vp3" data-vp3-target="signup" href="<?= e(url('/signup.php')) ?>">Get VP3 →</a>
    <a class="vp3-btn" data-vp3-cta="services_book_demo" data-vp3-target="demo" href="<?= e(url('/book-demo.php')) ?>">Book a demo</a>
  </div>
</section>

<main id="main-content">
  <section class="vp3-section vp3-services-loop-section">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">The VP3 operating loop</div>
        <h2>Capture → Understand → Coordinate → Sell → Measure → Agent follows through.</h2>
        <p>Each stage can stand on its own, but the larger advantage is continuity: the same identity, permissions, cognitive runtime, and authoritative application records stay connected as work moves forward.</p>
      </div>
      <div class="vp3-service-flow" aria-label="VP3 service flow">
        <div class="vp3-service-stage"><span>01</span><strong>Capture</strong><small>Conversations, meetings, browser research, source context.</small></div>
        <div class="vp3-service-stage"><span>02</span><strong>Understand</strong><small>Summaries, decisions, questions, structured knowledge.</small></div>
        <div class="vp3-service-stage"><span>03</span><strong>Coordinate</strong><small>Teams, meetings, calendars, shared context.</small></div>
        <div class="vp3-service-stage"><span>04</span><strong>Sell</strong><small>Booking, products, checkout, customer lifecycle.</small></div>
        <div class="vp3-service-stage"><span>05</span><strong>Measure</strong><small>Intent, conversions, revenue, sources, opportunities.</small></div>
        <div class="vp3-service-stage accent"><span>06</span><strong>Agent follows through</strong><small>Relevant signals return to Agent Now, workflows, reminders, and next actions.</small></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-service-group">
        <header class="vp3-service-group-head">
          <div><div class="vp3-kicker">Capture + understand</div><h2>Turn raw conversations and web research into usable context.</h2></div>
          <p>Keep the source attached while VP3 helps organize what matters.</p>
        </header>
        <div class="vp3-service-list">
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="transcription" href="<?= e(url('/transcriptions.php')) ?>"><span class="vp3-service-number">01</span><div><h3>Transcription</h3><p>Record and organize meetings, calls, interviews, voice notes, and other conversations.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="ai_summary" href="<?= e(url('/ai-summary.php')) ?>"><span class="vp3-service-number">02</span><div><h3>AI Summary</h3><p>Turn long conversations into decisions, action items, questions, and reusable knowledge.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="annotations" href="<?= e(url('/annotations.php')) ?>"><span class="vp3-service-number">03</span><div><h3>Annotations</h3><p>Capture highlights, screenshots, notes, and source context from the web and reuse them across research and Agent workflows.</p></div><b>Explore →</b></a>
        </div>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-service-group">
        <header class="vp3-service-group-head">
          <div><div class="vp3-kicker">Coordinate</div><h2>Keep people, meetings, and time connected to the same work.</h2></div>
          <p>Collaboration stays tied to the context that created it.</p>
        </header>
        <div class="vp3-service-list">
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="teams" href="<?= e(url('/teams.php')) ?>"><span class="vp3-service-number">04</span><div><h3>Teams</h3><p>Shared workspaces, conversations, permissions, context, and collaborative Agent workflows.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="meetings" href="<?= e(url('/video-meetings.php')) ?>"><span class="vp3-service-number">05</span><div><h3>Meetings</h3><p>Video meetings with transcripts, Meeting Intelligence, action items, commitments, and connected follow-through.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="calendar" href="<?= e(url('/calendar-service.php')) ?>"><span class="vp3-service-number">06</span><div><h3>Calendar</h3><p>Availability, calendar intelligence, sync, scheduling context, and Agent-managed coordination.</p></div><b>Explore →</b></a>
        </div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-service-group">
        <header class="vp3-service-group-head">
          <div><div class="vp3-kicker">Book + sell</div><h2>Connect public demand to real outcomes—and measure what happens.</h2></div>
          <p>Bookings, orders, and conversion intelligence use authoritative lifecycle records rather than disconnected marketing counters.</p>
        </header>
        <div class="vp3-service-list">
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="booking" href="<?= e(url('/booking.php')) ?>"><span class="vp3-service-number">07</span><div><h3>Booking — free or paid</h3><p>Public scheduling, appointment lifecycle, deposits, paid appointments, reminders, preparation, and follow-up.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="ecommerce" href="<?= e(url('/ecommerce.php')) ?>"><span class="vp3-service-number">08</span><div><h3>Ecommerce</h3><p>Public products, checkout, paid orders, fulfillment, refunds, seller alerts, and Agent commerce.</p></div><b>Explore →</b></a>
          <a class="vp3-service-row" data-vp3-cta="service_discovery" data-vp3-target="agent_analytics" href="<?= e(url('/agent-analytics.php')) ?>"><span class="vp3-service-number">09</span><div><h3>Agent Analytics</h3><p>See profile visits, booking and product intent, verified conversions, attributed revenue, traffic sources, trends, and Agent-identified opportunities.</p></div><b>Explore →</b></a>
        </div>
      </div>
    </div>
  </section>

  <section class="vp3-section vp3-agent-throughline-section">
    <div class="vp3-wrap vp3-marketing-why">
      <div><div class="vp3-kicker">One cognitive throughline</div><h2>The Agent should not start over at every feature boundary.</h2></div>
      <p>A transcript can become Knowledge. An annotation can inform research. A meeting can create commitments. A booking can trigger preparation. A sale can become a customer lifecycle event. Analytics can surface the next opportunity. VP3 feeds those approved signals back through the same Agent and cognitive runtime so follow-through stays connected to the authoritative system that produced each event.</p>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap"><div class="vp3-cta-box vp3-marketing-cta">
      <div><h2>See the full VP3 workflow connected end to end.</h2><p>Walk through capture, meetings, booking, commerce, analytics, and Agent follow-through in one demo.</p></div>
      <div class="vp3-marketing-cta-actions"><a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book a demo →</a><a class="vp3-btn" href="<?= e(url('/signup.php')) ?>">Get VP3</a></div>
    </div></div>
  </section>
</main>
<?php vp3_public_footer(); ?>
