<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

vp3_public_header(
    'Meetings — VP3',
    'Run VP3 video meetings with transcripts, Meeting Intelligence, action items, follow-through, and connected Agent context.',
    ['active' => 'services', 'canonical' => '/video-meetings.php']
);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">Meet, capture, follow through.</div>
  <h1>Meetings that stay connected to the work.</h1>
  <p>VP3 Meetings brings video, transcripts, Agent context, Meeting Intelligence, decisions, action items, and follow-through into the same workspace instead of leaving the conversation behind when the call ends.</p>
</section>

<main>
  <section class="vp3-section">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">Before, during, and after</div>
        <h2>The meeting becomes part of the cognitive workflow.</h2>
        <p>Schedule a VP3 meeting, invite members or guests, keep it connected to Calendar, and capture the transcript when transcription is enabled.</p>
        <p>Meeting Intelligence can then help organize objectives, summaries, decisions, commitments, follow-up, and continuity into the work that happens next.</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>Video meetings</b><span>Run browser-based meeting rooms tied to VP3 identity, scheduling, invitations, and permissions.</span></div>
        <div class="vp3-about-point"><b>Live transcript</b><span>Keep the source conversation searchable and available for review instead of relying on memory alone.</span></div>
        <div class="vp3-about-point"><b>Meeting Intelligence</b><span>Surface decisions, objectives, actions, open questions, follow-through, and relevant Agent context.</span></div>
        <div class="vp3-about-point"><b>Cross-meeting continuity</b><span>Carry verified commitments and unresolved work forward without turning meeting notes into a disconnected archive.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">Connected follow-through</div>
        <h2>What happens after the call matters.</h2>
        <p>VP3 connects the meeting to the same Agent, Calendar, work controls, and cognitive systems you already use.</p>
      </div>
      <div class="vp3-about-grid">
        <div class="vp3-about-points">
          <div class="vp3-about-point"><b>Agenda + objectives</b><span>Give the meeting a purpose and keep important objectives visible through the conversation.</span></div>
          <div class="vp3-about-point"><b>Action items</b><span>Turn commitments into explicit follow-up instead of burying them in a transcript or summary.</span></div>
          <div class="vp3-about-point"><b>Outcome verification</b><span>Keep handoffs separate from completion and verify outcomes through the authoritative VP3 system.</span></div>
          <div class="vp3-about-point"><b>Agent context</b><span>Let relevant meeting state feed Agent Now and the broader cognitive loop without creating a separate meeting brain.</span></div>
        </div>
        <article class="vp3-card">
          <div class="vp3-kicker">Your source stays attached</div>
          <h2>Intelligence without losing the conversation.</h2>
          <p>VP3 keeps meeting intelligence tied to the underlying meeting, transcript, participants, calendar context, and verified follow-through rather than treating an AI summary as the source of truth.</p>
          <p>Meeting access and follow-up continue to respect the permissions and ownership of the VP3 workspace.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-cta-box">
        <div><h2>See VP3 Meetings in the full Agent workflow.</h2><p>Walk through scheduling, the meeting room, transcription, intelligence, and follow-through.</p></div>
        <a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book Demo →</a>
      </div>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
