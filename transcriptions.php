<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();
vp3_public_header(
    'Transcriptions — VP3',
    'Record or upload conversations, turn them into searchable transcripts, and use VP3 AI to generate summaries, decisions, opportunities, and action plans.',
    ['active' => 'transcriptions']
);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">Capture once. Use it everywhere.</div>
  <h1>Turn every conversation into useful context.</h1>
  <p>Record or upload audio and video, create a searchable transcript, and let VP3 turn what was said into summaries, decisions, opportunities, and next actions.</p>
</section>
<main>
  <section class="vp3-section">
    <div class="vp3-wrap vp3-about-grid">
      <article class="vp3-card">
        <div class="vp3-kicker">From audio to understanding</div>
        <h2>Your recording becomes part of your working knowledge.</h2>
        <p>VP3 keeps the original conversation connected to its transcript and AI analysis so you can review what was actually said, search it later, and continue working from the same context.</p>
        <p>Use it for meetings, interviews, voice notes, planning sessions, customer conversations, project reviews, or any recording you do not want to lose.</p>
      </article>
      <div class="vp3-about-points">
        <div class="vp3-about-point"><b>1. Record or upload</b><span>Capture a live conversation or bring in an existing audio or video file.</span></div>
        <div class="vp3-about-point"><b>2. Transcribe</b><span>Convert the recording into accurate, searchable text that stays tied to the source.</span></div>
        <div class="vp3-about-point"><b>3. Analyze with AI</b><span>Identify key ideas, decisions, questions, opportunities, people, and next steps.</span></div>
        <div class="vp3-about-point"><b>4. Use the result</b><span>Receive a concise summary or practical action plan and keep it available to your VP3 assistant.</span></div>
      </div>
    </div>
  </section>

  <section class="vp3-section soft">
    <div class="vp3-wrap">
      <div class="vp3-section-head">
        <div class="vp3-kicker">More than transcription</div>
        <h2>Move from raw conversation to usable work.</h2>
        <p>The transcript is the source. VP3 helps turn it into something you can act on.</p>
      </div>
      <div class="vp3-about-grid">
        <div class="vp3-about-points">
          <div class="vp3-about-point"><b>Searchable history</b><span>Find names, topics, decisions, or exact moments without replaying the entire recording.</span></div>
          <div class="vp3-about-point"><b>AI summaries</b><span>Reduce long conversations into the most important information without losing the original source.</span></div>
          <div class="vp3-about-point"><b>Action plans</b><span>Turn commitments, follow-ups, open questions, and opportunities into clear next steps.</span></div>
          <div class="vp3-about-point"><b>Connected knowledge</b><span>Keep useful transcript context available to your private VP3 knowledge and Agent Chat workflows.</span></div>
        </div>
        <article class="vp3-card">
          <div class="vp3-kicker">Keep the source attached</div>
          <h2>Review the transcript, then keep moving.</h2>
          <p>AI output should never replace the source conversation. VP3 keeps the transcript available so you can verify details, copy text, revisit context, and use the result in future work.</p>
          <p>Your transcript remains part of your private workspace and follows the permissions of the account or team that owns it.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="vp3-section">
    <div class="vp3-wrap">
      <div class="vp3-cta-box">
        <div><h2>See VP3 transcription in your workflow.</h2><p>Book a demo to walk through recording, transcription, AI analysis, and follow-up.</p></div>
        <a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Book Demo →</a>
      </div>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
