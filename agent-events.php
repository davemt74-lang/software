<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-event-infrastructure-v1920.php';
require_once __DIR__.'/includes/agent-event-routes-v1920.php';
$user=current_user();if(!$user||!has_permission('account.access',$user))redirect(url('/login.php'));
$pdo=db();$ready=$pdo&&agent_event_schema_ready_v1920($pdo);$notice=agent_event_text_v1920($_GET['notice']??'',220);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'&&$ready){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{try{agent_event_dispatch_v1920($pdo,$user,(int)($_POST['event_id']??0),true);$notice='Event replay dispatched safely.';}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Event replay failed.';}}
}
$events=$ready?agent_event_recent_v1920($pdo,$user,50):[];
require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Agent Events — VP3','Verified webhooks and trusted internal events.',['compact'=>true]);
?>
<style>
.vp3-event-shell{max-width:1180px;margin:0 auto;padding:42px 22px 80px}.vp3-event-head{display:flex;gap:24px;justify-content:space-between;align-items:end;margin-bottom:24px}.vp3-event-head h1{margin:.2rem 0;font-size:clamp(2rem,4vw,3.5rem)}.vp3-event-grid{display:grid;gap:12px}.vp3-event-card{border:1px solid rgba(127,127,127,.24);border-radius:18px;padding:18px;background:rgba(255,255,255,.03)}.vp3-event-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.vp3-event-pill{font-size:.78rem;padding:5px 9px;border:1px solid rgba(127,127,127,.28);border-radius:999px}.vp3-event-meta{opacity:.7;font-size:.88rem;margin-top:9px}.vp3-event-payload{margin:12px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;max-height:180px;overflow:auto}.vp3-event-actions{margin-left:auto}.vp3-event-empty{padding:32px;border:1px dashed rgba(127,127,127,.35);border-radius:18px}.vp3-event-alert{padding:12px 14px;border-radius:12px;margin:0 0 16px;background:rgba(127,127,127,.12)}@media(max-width:720px){.vp3-event-head{align-items:start;flex-direction:column}.vp3-event-actions{margin-left:0;width:100%}}
</style>
<main class="vp3-event-shell">
  <div class="vp3-event-head"><div><div class="vp3-kicker">Phase 19.2</div><h1>Agent Events</h1><p>Verified external events and trusted internal signals. Events can inform Agent Brain and create durable Phase 19 jobs; they never execute tools directly.</p></div><a class="vp3-btn" href="<?= e(url('/agent-workflows.php')) ?>">Agent Workflows →</a></div>
  <?php if($notice!==''): ?><div class="vp3-event-alert"><?= e($notice) ?></div><?php endif; ?>
  <?php if($error!==''): ?><div class="vp3-event-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if(!$ready): ?><div class="vp3-event-empty"><h2>Event infrastructure needs installation.</h2><p>Run the Phase 19.2 database upgrade to enable durable webhook and internal-event intake.</p><?php if(has_permission('users.manage',$user)): ?><a class="vp3-btn primary" href="<?= e(url('/agent-event-infrastructure-upgrade-v1920.php')) ?>">Install Phase 19.2 →</a><?php endif; ?></div>
  <?php elseif(!$events): ?><div class="vp3-event-empty"><h2>No events yet.</h2><p>Verified webhook or trusted internal events will appear here after intake.</p></div>
  <?php else: ?><section class="vp3-event-grid" aria-label="Recent Agent events">
    <?php foreach($events as $event): $payload=agent_event_json_v1920($event['payload']??[]); ?>
    <article class="vp3-event-card">
      <div class="vp3-event-row"><strong><?= e((string)$event['event_type']) ?></strong><span class="vp3-event-pill"><?= e((string)$event['source']) ?></span><span class="vp3-event-pill"><?= e((string)$event['verification_status']) ?></span><span class="vp3-event-pill"><?= e((string)$event['processing_status']) ?></span>
        <form class="vp3-event-actions" method="post"><?= csrf_field() ?><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="vp3-btn" type="submit"<?= (string)$event['processing_status']==='processing'?' disabled':'' ?>>Replay</button></form>
      </div>
      <div class="vp3-event-meta">Received <?= e((string)$event['received_at']) ?> · Event <?= e((string)$event['event_uuid']) ?><?php if((int)$event['linked_run_id']>0): ?> · <a href="<?= e(url('/agent-workflows.php?run='.(int)$event['linked_run_id'])) ?>">Workflow #<?= (int)$event['linked_run_id'] ?></a><?php endif; ?><?php if((int)$event['replay_count']>0): ?> · Replayed <?= (int)$event['replay_count'] ?>×<?php endif; ?></div>
      <?php if($payload!=='{}'): ?><pre class="vp3-event-payload"><?= e($payload) ?></pre><?php endif; ?>
      <?php if((string)$event['last_error_message']!==''): ?><div class="vp3-event-meta">Last error: <?= e((string)$event['last_error_message']) ?></div><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </section><?php endif; ?>
</main>
<?php vp3_public_footer(); ?>
