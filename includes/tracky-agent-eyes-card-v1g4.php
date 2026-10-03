<?php /** Shared Tracky/device-settings status surface. */ ?>
<section class="<?= e($eyesCardClass??'card') ?>" data-agent-eyes-experience data-endpoint="<?= e(url('/api/tracky-agent-eyes-v1g4.php'.(!empty($eyesCardSite)?'?site='.rawurlencode($eyesCardSite):''))) ?>">
<h2>Agent Eyes</h2><p class="muted">Recent HomeServer scene summaries, connection and sharing status.</p>
<div data-eyes-list role="status" aria-live="polite"><p>Checking scene status…</p></div>
<div class="top-actions"><button type="button" class="button secondary" data-eyes-refresh>Refresh status</button><a class="button secondary" href="<?= e(url('/settings-homeserver.php')) ?>">HomeServer connection</a><a class="button secondary" href="<?= e(url('/tracky.php')) ?>">Physical awareness</a></div>
<a class="button secondary" href="<?= e(url('/api/tracky-agent-eyes-v1g4.php?report=1'.(!empty($eyesCardSite)?'&site='.rawurlencode($eyesCardSite):''))) ?>">Download redacted Cloud report</a>
<p class="muted">Complete the installed acceptance checklist in HomeServer Tracky. Cloud cannot independently verify camera exercises.</p>
<p class="muted">Camera, model review, sharing and recovery controls remain in HomeServer → Tracky. Refreshing this view never captures an image.</p>
</section>
