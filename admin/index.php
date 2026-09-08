<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

$pdo = db();
function admin_dashboard_scalar(?PDO $pdo,string $sql,array $params=[]): int
{
    if(!$pdo)return 0;
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}catch(Throwable $e){return 0;}
}
function admin_dashboard_rows(?PDO $pdo,string $sql,array $params=[]): array
{
    if(!$pdo)return [];
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
}

$metrics=[
    'users'=>0,'new_today'=>0,'new_7d'=>0,'new_30d'=>0,'active_30d'=>0,
    'subscriptions'=>0,'ai_tokens_30d'=>0,'ai_requests_30d'=>0,'chats_7d'=>0,
    'messages'=>0,'unread'=>0,'crm_new'=>0,'knowledge'=>0,'shows'=>0,'posts'=>0,
];
$popularPackage=['name'=>'No package data','count'=>0];
$packageMix=[];$recentUsers=[];$signupTrend=[];

if($pdo){
    $metrics['users']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM users');
    $metrics['new_today']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM users WHERE created_at>=CURRENT_DATE()');
    $metrics['new_7d']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)');
    $metrics['new_30d']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');
    $metrics['active_30d']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM users WHERE is_active=1 AND last_login_at IS NOT NULL AND last_login_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');

    if(table_exists('contact_messages')){
        $metrics['messages']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM contact_messages');
        $metrics['unread']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM contact_messages WHERE is_read=0');
    }
    if(table_exists('knowledge_items'))$metrics['knowledge']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM knowledge_items');
    if(table_exists('chat_conversations'))$metrics['chats_7d']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM chat_conversations WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)');
    if(table_exists('shows'))$metrics['shows']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM shows WHERE show_date>=CURRENT_DATE()');
    if(table_exists('artist_posts'))$metrics['posts']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM artist_posts');
    if(function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready())$metrics['crm_new']=admin_dashboard_scalar($pdo,"SELECT COUNT(*) FROM crm_leads WHERE stage='new'");

    if(subscription_schema_ready($pdo)){
        $activeWhere="us.status IN ('active','trialing') AND us.starts_at<=NOW() AND (us.ends_at IS NULL OR us.ends_at>NOW())";
        $metrics['subscriptions']=admin_dashboard_scalar($pdo,"SELECT COUNT(DISTINCT us.user_id) FROM user_subscriptions us WHERE {$activeWhere}");
        $packageMix=admin_dashboard_rows($pdo,"SELECT p.id,p.name,p.slug,COUNT(DISTINCT us.user_id) total FROM user_subscriptions us JOIN subscription_packages p ON p.id=us.package_id WHERE {$activeWhere} GROUP BY p.id,p.name,p.slug ORDER BY total DESC,p.name ASC LIMIT 6");
        if($packageMix)$popularPackage=['name'=>(string)$packageMix[0]['name'],'count'=>(int)$packageMix[0]['total']];
        if(table_exists('ai_usage_ledger')){
            $metrics['ai_tokens_30d']=admin_dashboard_scalar($pdo,'SELECT COALESCE(SUM(total_tokens),0) FROM ai_usage_ledger WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');
            $metrics['ai_requests_30d']=admin_dashboard_scalar($pdo,'SELECT COUNT(*) FROM ai_usage_ledger WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');
        }
    }

    $recentUsers=admin_dashboard_rows($pdo,'SELECT id,display_name,email,is_active,last_login_at,created_at FROM users ORDER BY created_at DESC,id DESC LIMIT 7');
    foreach($recentUsers as &$row){
        $row['package_name']='No package';
        if(subscription_schema_ready($pdo)){
            $sub=subscription_current_for_user_id((int)$row['id'],$pdo);
            if($sub)$row['package_name']=(string)($sub['package_name']??'No package');
        }
    }unset($row);

    $trendRows=admin_dashboard_rows($pdo,'SELECT DATE(created_at) day,COUNT(*) total FROM users WHERE created_at>=DATE_SUB(CURRENT_DATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)');
    $trendMap=[];foreach($trendRows as $row)$trendMap[(string)$row['day']]=(int)$row['total'];
    for($i=6;$i>=0;$i--){$day=date('Y-m-d',strtotime('-'.$i.' days'));$signupTrend[]=['day'=>$day,'label'=>date('D',strtotime($day)),'total'=>$trendMap[$day]??0];}
}
$maxTrend=max(1,...array_map(static fn(array $row): int=>(int)$row['total'],$signupTrend?:[['total'=>0]]));
$maxPackage=max(1,...array_map(static fn(array $row): int=>(int)$row['total'],$packageMix?:[['total'=>0]]));
$activationRate=$metrics['users']>0?(int)round(($metrics['active_30d']/$metrics['users'])*100):0;
$packageCoverage=$metrics['users']>0?(int)round(($metrics['subscriptions']/$metrics['users'])*100):0;

$adminTitle='Dashboard';$adminActive='dashboard';
require __DIR__.'/_header.php';
?>
<?php if(!access_schema_ready()&&has_permission('users.manage')): ?><div class="notice error">The user-role and media-visibility upgrade has not been installed yet. <a href="<?= e(url('/upgrade.php')) ?>">Run the upgrade →</a></div><?php endif; ?>

<section class="admin-dashboard-hero">
  <div><span class="eyebrow">Operating Center</span><h2>VP3 platform overview</h2><p>User growth, package adoption, AI activity and operational attention from the systems already running in VP3.</p></div>
  <div class="actions"><a class="btn" href="<?= e(url('/admin/users.php')) ?>">Users</a><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Packages</a><a class="btn primary" href="<?= e(url('/admin/ai-data-usage-v236.php')) ?>">AI Usage</a></div>
</section>

<section class="admin-kpi-grid" aria-label="Key SaaS metrics">
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Total users</span><span>All time</span></div><strong><?= number_format($metrics['users']) ?></strong><small><span class="positive">+<?= number_format($metrics['new_7d']) ?></span> in the last 7 days · <?= number_format($metrics['new_today']) ?> today</small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Active users</span><span>30 days</span></div><strong><?= number_format($metrics['active_30d']) ?></strong><small><?= $activationRate ?>% of total users logged in during the last 30 days</small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Popular package</span><span>Current</span></div><strong style="font-size:1.15rem!important;line-height:1.25!important"><?= e($popularPackage['name']) ?></strong><small><?= number_format((int)$popularPackage['count']) ?> active account<?= (int)$popularPackage['count']===1?'':'s' ?> · <?= $packageCoverage ?>% package coverage overall</small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>AI usage</span><span>30 days</span></div><strong><?= number_format($metrics['ai_tokens_30d']) ?></strong><small><?= number_format($metrics['ai_requests_30d']) ?> recorded AI request<?= $metrics['ai_requests_30d']===1?'':'s' ?></small></article>
</section>

<div class="admin-dashboard-grid">
  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>New user trend</h3><p><?= number_format($metrics['new_30d']) ?> new users in the last 30 days</p></div><a class="btn button-small" href="<?= e(url('/admin/users.php')) ?>">View users</a></div>
    <div class="admin-trend-bars" aria-label="Seven day signup trend">
      <?php foreach($signupTrend as $day): $height=max(4,(int)round(((int)$day['total']/$maxTrend)*100)); ?><div class="admin-trend-day" title="<?= e($day['day']) ?> · <?= (int)$day['total'] ?> signup<?= (int)$day['total']===1?'':'s' ?>"><div class="admin-trend-bar-wrap"><span class="admin-trend-bar" style="height:<?= $height ?>%"></span></div><small><?= e($day['label']) ?><br><?= (int)$day['total'] ?></small></div><?php endforeach; ?>
    </div>
  </section>

  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>Needs attention</h3><p>Items that may require an Admin decision.</p></div></div>
    <div class="admin-attention-list">
      <a class="admin-attention-row" href="<?= e(url('/admin/messages.php')) ?>"><div><strong>Unread messages</strong><small>Contact submissions waiting for review</small></div><span class="admin-attention-badge"><?= number_format($metrics['unread']) ?></span></a>
      <?php if($adminCrmVisible): ?><a class="admin-attention-row" href="<?= e(url('/admin/crm.php')) ?>"><div><strong>New CRM leads</strong><small>Leads still in the new stage</small></div><span class="admin-attention-badge"><?= number_format($metrics['crm_new']) ?></span></a><?php endif; ?>
      <a class="admin-attention-row" href="<?= e(url('/admin/ai-data-usage-v236.php')) ?>"><div><strong>AI activity</strong><small><?= number_format($metrics['ai_requests_30d']) ?> requests recorded in 30 days</small></div><span class="admin-attention-badge">→</span></a>
      <a class="admin-attention-row" href="<?= e(url('/admin/site-settings.php')) ?>"><div><strong>Platform settings</strong><small>Brand, site and operating configuration</small></div><span class="admin-attention-badge">→</span></a>
    </div>
  </section>
</div>

<div class="admin-dashboard-grid">
  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>Recent users</h3><p>Newest accounts and current package assignment.</p></div><a class="btn button-small" href="<?= e(url('/admin/users.php?new=1')) ?>">+ Add user</a></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Package</th><th>Created</th><th>Last login</th><th></th></tr></thead><tbody>
      <?php foreach($recentUsers as $row): ?><tr><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)$row['package_name']) ?></td><td><?= e(date('M j, Y',strtotime((string)$row['created_at']))) ?></td><td><?= $row['last_login_at']?e(date('M j',strtotime((string)$row['last_login_at']))):'Never' ?></td><td><a class="btn button-small" href="<?= e(url('/admin/users.php?edit='.(int)$row['id'])) ?>">Manage</a></td></tr><?php endforeach; ?>
      <?php if(!$recentUsers): ?><tr><td colspan="5">No users yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>Package adoption</h3><p><?= number_format($metrics['subscriptions']) ?> users currently have an active/trial package.</p></div><a class="btn button-small" href="<?= e(url('/admin/packages.php')) ?>">Manage</a></div>
    <div class="admin-package-list">
      <?php foreach($packageMix as $row): $width=max(4,(int)round(((int)$row['total']/$maxPackage)*100)); ?><div class="admin-package-row"><div><strong><?= e((string)$row['name']) ?></strong><small><?= number_format((int)$row['total']) ?> user<?= (int)$row['total']===1?'':'s' ?></small></div><div class="admin-package-meter" aria-label="<?= $width ?> percent of top package"><span style="width:<?= $width ?>%"></span></div></div><?php endforeach; ?>
      <?php if(!$packageMix): ?><div class="admin-activity-row"><div><strong>No package assignments yet</strong><small>Package adoption will appear here as users are assigned plans.</small></div></div><?php endif; ?>
    </div>
  </section>
</div>

<section class="admin-dashboard-card">
  <div class="admin-dashboard-card-head"><div><h3>Platform pulse</h3><p>Useful operating totals from the existing VP3 modules.</p></div></div>
  <div class="admin-kpi-grid" style="margin-bottom:0!important">
    <article class="admin-kpi"><div class="admin-kpi-head"><span>Chats</span><span>7 days</span></div><strong><?= number_format($metrics['chats_7d']) ?></strong><small>New Agent Chat conversations</small></article>
    <article class="admin-kpi"><div class="admin-kpi-head"><span>Knowledge</span><span>Total</span></div><strong><?= number_format($metrics['knowledge']) ?></strong><small>Knowledge items available to the platform</small></article>
    <article class="admin-kpi"><div class="admin-kpi-head"><span>Upcoming shows</span><span>Publishing</span></div><strong><?= number_format($metrics['shows']) ?></strong><small><?= number_format($metrics['posts']) ?> published artist post<?= $metrics['posts']===1?'':'s' ?></small></article>
    <article class="admin-kpi"><div class="admin-kpi-head"><span>Messages</span><span>Total</span></div><strong><?= number_format($metrics['messages']) ?></strong><small><?= number_format($metrics['unread']) ?> currently unread</small></article>
  </div>
</section>

<?php require __DIR__.'/_footer.php'; ?>