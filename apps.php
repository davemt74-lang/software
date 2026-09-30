<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/system-apps-v100.php';
require_login();
require_permission('account.access');
$pdo=db();$user=current_user();
if(!$pdo||!$user)throw new RuntimeException('Apps are unavailable.');
if(!vp3_system_apps_schema_ready_v100($pdo))vp3_system_apps_ensure_schema_v100($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('system_apps_error','Session expired. Refresh and try again.');redirect(url('/apps.php'));}
    try{
        if((string)($_POST['action']??'')!=='acquire')throw new RuntimeException('Unsupported Apps action.');
        vp3_system_apps_acquire_v100((int)$user['id'],(string)($_POST['app_key']??''),'self_service',null,$pdo);
        flash('system_apps_notice','App added to your account.');
    }catch(Throwable $e){flash('system_apps_error',$e->getMessage());}
    redirect(url('/apps.php'));
}
$catalog=vp3_system_apps_catalog_v100($user,$pdo);
$notice=flash('system_apps_notice');$error=flash('system_apps_error');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7"><title><?=e(system_agent_name())?> | Apps</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>"><link rel="stylesheet" href="<?=e(url('/system-apps-v100.css?v=1'))?>"></head>
<body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='apps';require __DIR__.'/includes/workspace-sidebar-v82.php';?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main system-apps-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Apps';$memberHeaderSubtitle='VP3 system apps for your HomeServer';$memberHeaderActiveKey='apps';require __DIR__.'/includes/member-header.php';?>
<section class="system-apps-canvas"><div class="system-apps-shell">
<header class="system-apps-hero"><div><span>VP3 SYSTEM APPS</span><h1>Your apps.</h1><p>Choose VP3 system apps for your account. Ownership lives in VP3 Cloud; installation and runtime remain authoritative on your HomeServer.</p></div>
<div class="system-apps-counts"><strong><?=number_format((int)$catalog['counts']['owned'])?></strong><span>Owned</span><strong><?=number_format((int)$catalog['counts']['available'])?></strong><span>Available</span></div></header>
<?php if($notice):?><div class="system-apps-alert success"><?=e($notice)?></div><?php endif;?><?php if($error):?><div class="system-apps-alert error"><?=e($error)?></div><?php endif;?>
<div class="system-apps-tabs"><button class="active" type="button" data-app-filter="all">All</button><button type="button" data-app-filter="owned">Owned</button><button type="button" data-app-filter="available">Available</button></div>
<section class="system-apps-grid">
<?php foreach($catalog['apps'] as $app):?>
<article class="system-app-card" data-app-state="<?=e($app['owned']?'owned':($app['eligible']?'available':'unavailable'))?>">
<div class="system-app-card-top"><div class="system-app-icon">VP3</div><div><span class="system-app-category"><?=e((string)$app['category'])?></span><h2><?=e((string)$app['name'])?></h2></div><span class="system-app-badge <?= $app['owned']?'owned':'available' ?>"><?= $app['owned']?'Owned':($app['eligible']?'Available':'Unavailable') ?></span></div>
<p><?=e((string)$app['description'])?></p>
<div class="system-app-meta"><span>Version <strong><?=e((string)$app['current_version'])?></strong></span><span>Runtime <strong>HomeServer</strong></span></div>
<div class="system-app-actions">
<?php if($app['owned']):?><button class="button secondary" type="button" disabled>Owned</button><span>Install controls are added in the next section.</span>
<?php elseif($app['eligible']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acquire"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button primary" type="submit">Add to My Apps</button></form><span>Included for this account</span>
<?php else:?><button class="button secondary" type="button" disabled>Not available</button><?php endif;?>
</div></article>
<?php endforeach;?>
</section>
</div></section></main></div>
<script>document.addEventListener('click',e=>{const b=e.target.closest('[data-app-filter]');if(!b)return;document.querySelectorAll('[data-app-filter]').forEach(x=>x.classList.toggle('active',x===b));const f=b.dataset.appFilter;document.querySelectorAll('[data-app-state]').forEach(x=>x.hidden=f!=='all'&&x.dataset.appState!==f);});</script>
</body></html>
