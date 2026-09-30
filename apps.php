<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/system-apps-v110.php';
require_login();
require_permission('account.access');
$pdo=db();$user=current_user();
if(!$pdo||!$user)throw new RuntimeException('Apps are unavailable.');
if(!vp3_system_apps_schema_ready_v110($pdo))vp3_system_apps_ensure_schema_v110($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('system_apps_error','Session expired. Refresh and try again.');redirect(url('/apps.php'));}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='acquire'){
            vp3_system_apps_acquire_v100((int)$user['id'],(string)($_POST['app_key']??''),'self_service',null,$pdo);
            flash('system_apps_notice','App added to your account.');
        }elseif($action==='install'){
            $result=vp3_system_apps_install_v110((int)$user['id'],(string)($_POST['app_key']??''),null,$pdo);
            flash('system_apps_notice',!empty($result['changed'])?'App installed on HomeServer.':'HomeServer app is already current.');
        }elseif($action==='reconcile'){
            $result=vp3_system_apps_reconcile_v110((int)$user['id'],null,$pdo);
            flash('system_apps_notice','HomeServer app status refreshed for '.number_format((int)$result['count']).' owned app'.((int)$result['count']===1?'':'s').'.');
        }else throw new RuntimeException('Unsupported Apps action.');
    }catch(Throwable $e){flash('system_apps_error',$e->getMessage());}
    redirect(url('/apps.php'));
}
$catalog=vp3_system_apps_catalog_v110($user,$pdo);
$notice=flash('system_apps_notice');$error=flash('system_apps_error');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7"><title><?=e(system_agent_name())?> | Apps</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>"><link rel="stylesheet" href="<?=e(url('/system-apps-v100.css?v=2'))?>"></head>
<body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='apps';require __DIR__.'/includes/workspace-sidebar-v82.php';?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main system-apps-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Apps';$memberHeaderSubtitle='VP3 system apps for your HomeServer';$memberHeaderActiveKey='apps';require __DIR__.'/includes/member-header.php';?>
<section class="system-apps-canvas"><div class="system-apps-shell">
<header class="system-apps-hero"><div><span>VP3 SYSTEM APPS</span><h1>Your apps.</h1><p>Choose VP3 system apps for your account, then install them on your HomeServer. Cloud owns entitlement; HomeServer owns installation and runtime state.</p></div>
<div class="system-apps-counts"><strong><?=number_format((int)$catalog['counts']['owned'])?></strong><span>Owned</span><strong><?=number_format((int)$catalog['counts']['installed'])?></strong><span>Installed</span><strong><?=number_format((int)$catalog['counts']['updates'])?></strong><span>Updates</span></div></header>
<?php if($notice):?><div class="system-apps-alert success"><?=e($notice)?></div><?php endif;?><?php if($error):?><div class="system-apps-alert error"><?=e($error)?></div><?php endif;?>
<div class="system-apps-toolbar"><div class="system-apps-tabs"><button class="active" type="button" data-app-filter="all">All</button><button type="button" data-app-filter="owned">Owned</button><button type="button" data-app-filter="installed">Installed</button><button type="button" data-app-filter="available">Available</button></div>
<?php if((int)$catalog['counts']['owned']>0):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reconcile"><button class="button secondary" type="submit">Refresh HomeServer</button></form><?php endif;?></div>
<section class="system-apps-grid">
<?php foreach($catalog['apps'] as $app):$hs=(array)($app['homeserver']??[]);$state=$app['owned']?(!empty($hs['installed'])?'installed':'owned'):($app['eligible']?'available':'unavailable');?>
<article class="system-app-card" data-app-state="<?=e($state)?>">
<div class="system-app-card-top"><div class="system-app-icon">VP3</div><div><span class="system-app-category"><?=e((string)$app['category'])?></span><h2><?=e((string)$app['name'])?></h2></div>
<span class="system-app-badge <?=!empty($hs['installed'])?'installed':($app['owned']?'owned':'available')?>"><?=!empty($hs['installed'])?'Installed':($app['owned']?'Owned':($app['eligible']?'Available':'Unavailable'))?></span></div>
<p><?=e((string)$app['description'])?></p>
<div class="system-app-meta"><span>Catalog <strong><?=e((string)$app['current_version'])?></strong></span><span>HomeServer <strong><?=e(!empty($hs['installed'])?(string)($hs['installed_version']??$hs['state']):ucfirst((string)($hs['state']??'not synced')))?></strong></span></div>
<?php if(!empty($hs['error'])):?><div class="system-app-error"><?=e((string)$hs['error'])?></div><?php endif;?>
<div class="system-app-actions">
<?php if($app['owned']):?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="install"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button primary" type="submit"><?=!empty($hs['update_available'])?'Update':(!empty($hs['installed'])?'Reinstall / Verify':'Install on HomeServer')?></button></form>
<span><?=!empty($hs['current'])?'Current · '.e((string)$hs['state']):(!empty($hs['installed'])?'HomeServer update or verification available':'Owned · ready to install')?></span>
<?php elseif($app['eligible']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acquire"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button primary" type="submit">Add to My Apps</button></form><span>Included for this account</span>
<?php else:?><button class="button secondary" type="button" disabled>Not available</button><?php endif;?>
</div></article>
<?php endforeach;?>
</section>
</div></section></main></div>
<script>document.addEventListener('click',e=>{const b=e.target.closest('[data-app-filter]');if(!b)return;document.querySelectorAll('[data-app-filter]').forEach(x=>x.classList.toggle('active',x===b));const f=b.dataset.appFilter;document.querySelectorAll('[data-app-state]').forEach(x=>x.hidden=f!=='all'&&x.dataset.appState!==f);});</script>
</body></html>
